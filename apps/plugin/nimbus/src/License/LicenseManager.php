<?php
namespace Nimbus\Plugin\License;

if (!defined('ABSPATH')) {
  exit;
}

final class LicenseManager
{
  private const DEFAULTS = [
    'key'               => '',
    'status'            => 'inactive',
    'tier'              => '',          // personal | pro | agency
    'billing_cycle'     => 'monthly',
    'subscription_id'   => '',
    'token'             => '',
    'token_expires_at'  => 0,
    'last_validated_at' => 0,
  ];

  public function all(): array
  {
    $stored = get_option(NIMBUS_LICENSE_OPTION_KEY, []);
    return wp_parse_args(is_array($stored) ? $stored : [], self::DEFAULTS);
  }

  public function key(): string
  {
    return trim((string) ($this->all()['key'] ?? ''));
  }

  public function hasKey(): bool
  {
    return $this->key() !== '';
  }

  public function status(): string
  {
    return (string) ($this->all()['status'] ?? 'inactive');
  }

  public function tier(): string
  {
    return (string) ($this->all()['tier'] ?? '');
  }

  public function token(): string
  {
    return (string) ($this->all()['token'] ?? '');
  }

  public function isActive(): bool
  {
    if ($this->status() !== 'active') {
      return false;
    }

    // Require a signed, non-expired token — prevents wp_options manipulation
    // (e.g. setting status="active" with no token, or with an expired token).
    $token = $this->token();
    if ($token === '') {
      return false;
    }
    if (!$this->verifyTokenSignature($token)) {
      return false;
    }
    $claims = $this->tokenClaims($token);
    if ($claims === null) {
      return false;
    }
    // Expired beyond the server-defined grace window → treat as inactive.
    // The backend embeds the hard deadline as the "gau" (grace-until) claim,
    // which equals exp + grace_period_days. The plugin trusts this value
    // because it is part of the signed payload and cannot be forged.
    $deadline = isset($claims['gau']) && (int) $claims['gau'] > 0
      ? (int) $claims['gau']
      : (int) ($claims['exp'] ?? 0); // fall back to exp for legacy tokens
    if ($deadline > 0 && time() > $deadline) {
      return false;
    }
    return true;
  }

  /**
   * Verify the Ed25519 signature on the stored token.
   *
   * This is how professional WordPress plugins prevent DB-level forgery:
   * the token payload is signed by the backend private key; the plugin
   * verifies it against the embedded public key before trusting any claims.
   * Even a site admin who edits wp_options directly cannot forge a valid
   * signature without the backend private key.
   *
   * Requires the PHP sodium extension (bundled in PHP 7.2+ and WordPress itself
   * ships a polyfill via `sodium_compat` for hosts that lack the native ext).
   */
  public function isPublicKeyConfigured(): bool
  {
    $key = defined('NIMBUS_LICENSE_PUBLIC_KEY') ? NIMBUS_LICENSE_PUBLIC_KEY : '';
    return $key !== '';
  }

  private function verifyTokenSignature(string $token): bool
  {
    $pubKeyB64 = defined('NIMBUS_LICENSE_PUBLIC_KEY') ? NIMBUS_LICENSE_PUBLIC_KEY : '';
    if ($pubKeyB64 === '') {
      // NIMBUS_LICENSE_PUBLIC_KEY is not defined — signature verification is
      // disabled. An admin notice is shown until the key is configured.
      return true;
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
      return false;
    }

    // Decode base64url payload and signature.
    $decodeB64url = static function (string $input): string|false {
      $b64 = strtr($input, '-_', '+/');
      $pad = strlen($b64) % 4;
      if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
      }
      return base64_decode($b64, strict: true);
    };

    $payload = $decodeB64url($parts[0]);
    $sig     = $decodeB64url($parts[1]);

    if ($payload === false || $sig === false) {
      return false;
    }

    // Guard against wrong-length signatures before calling libsodium —
    // sodium_crypto_sign_verify_detached() throws SodiumException if the
    // signature is not exactly SODIUM_CRYPTO_SIGN_BYTES (64) bytes.
    if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
      return false;
    }

    // Decode the server public key (standard base64, not url-safe).
    $pk = base64_decode($pubKeyB64, strict: true);
    if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
      return false;
    }

    return sodium_crypto_sign_verify_detached($sig, $payload, $pk);
  }

  /**
   * Decode the features list from the stored activation token without a network
   * call. The token signature is verified against the embedded Ed25519 public
   * key before any claims are trusted.
   *
   * Three additional hardening checks are applied:
   *  1. Token expiry (exp claim) — returns [] once past the 7-day grace window.
   *  2. Domain binding (domain claim) — returns [] when the token was issued
   *     for a different hostname, preventing cross-site token reuse.
   *  3. Signature forgery — the Ed25519 check already catches any tampered
   *     payload, so DB-level injection of fake feature lists is blocked.
   *
   * @return string[]
   */
  public function features(): array
  {
    $token = $this->token();
    if ($token === '') {
      return [];
    }

    // Verify signature BEFORE decoding claims — prevents DB-level forgery.
    if (!$this->verifyTokenSignature($token)) {
      return [];
    }

    $claims = $this->tokenClaims($token);
    if ($claims === null) {
      return [];
    }

    // Enforce the server-defined grace deadline.
    // gau (grace-until) = exp + server-configured grace period.
    // The plugin never hardcodes this duration — it reads it from the signed token.
    $deadline = isset($claims['gau']) && (int) $claims['gau'] > 0
      ? (int) $claims['gau']
      : (int) ($claims['exp'] ?? 0); // fall back to exp for legacy tokens
    if ($deadline > 0 && time() > $deadline) {
      return [];
    }

    // Enforce domain binding — prevents token theft / cross-site reuse.
    // The backend stores the normalized hostname in the token, so we apply
    // the same normalization (strip www., lowercase) before comparing.
    if (isset($claims['domain']) && $claims['domain'] !== '') {
      $siteDomain = self::normalizeDomain((string) home_url());
      if ($siteDomain !== $claims['domain']) {
        return [];
      }
    }

    if (!isset($claims['features']) || !is_array($claims['features'])) {
      return [];
    }

    return array_map('strval', $claims['features']);
  }

  /**
   * Returns true when the stored token has passed its exp claim, meaning the
   * server-side grace period has started. Used by admin notices and the
   * background refresher to decide when to call the API.
   */
  public function needsRenewal(): bool
  {
    if ($this->status() !== 'active') {
      return false;
    }
    $token = $this->token();
    if ($token === '') {
      return false;
    }
    $claims = $this->tokenClaims($token);
    if ($claims === null || !isset($claims['exp']) || (int) $claims['exp'] <= 0) {
      return false; // perpetual or malformed — no renewal needed
    }
    // Show renewal notice once the token has passed its exp (grace window started).
    return time() > (int) $claims['exp'];
  }

  /**
   * Check whether the stored token grants a specific feature.
   *
   * Feature keys mirror the backend TierFeatures map:
   *   offload, db_rewrite, multisite, wpcli, cdn, cache_purge, priority_support
   */
  public function hasFeature(string $feature): bool
  {
    return in_array($feature, $this->features(), strict: true);
  }

  // ── Private helpers ─────────────────────────────────────────────────────────

  /**
   * Decode the base64url JSON payload from a token without verifying the
   * signature. Only call this after verifyTokenSignature() returns true.
   *
   * @return array<string, mixed>|null The decoded claims, or null on failure.
   */
  private function tokenClaims(string $token): ?array
  {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
      return null;
    }
    $b64 = strtr($parts[0], '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
      $b64 .= str_repeat('=', 4 - $pad);
    }
    $json = base64_decode($b64, strict: true);
    if ($json === false) {
      return null;
    }
    $claims = json_decode($json, associative: true);
    return is_array($claims) ? $claims : null;
  }

  /**
   * Normalize a URL or hostname to a canonical lowercase hostname, matching
   * the backend's NormalizeDomain() function:
   *   - extracts the host portion of the URL
   *   - lowercases it
   *   - strips the "www." prefix
   *   - strips a trailing "." (FQDN dot)
   */
  private static function normalizeDomain(string $url): string
  {
    $host = wp_parse_url($url, PHP_URL_HOST);
    if ($host === null || $host === false || $host === '') {
      // $url was already a bare hostname.
      $host = $url;
    }
    $host = strtolower((string) $host);
    if (str_starts_with($host, 'www.')) {
      $host = substr($host, 4);
    }
    return rtrim($host, '.');
  }

  /**
   * Persist a full set of license data returned by the backend.
   *
   * @param string $key  License key.
   * @param array  $meta Associative array of fields to merge.
   * @return array The saved payload.
   */
  public function update(string $key, array $meta = []): array
  {
    $payload = wp_parse_args($meta, array_merge(self::DEFAULTS, $this->all()));
    $payload['key']               = sanitize_text_field($key);
    $payload['status']            = sanitize_key((string) ($payload['status'] ?? 'inactive')) ?: 'inactive';
    $payload['tier']              = sanitize_key((string) ($payload['tier'] ?? '')) ?: '';
    $payload['billing_cycle']     = sanitize_key((string) ($payload['billing_cycle'] ?? 'monthly')) ?: 'monthly';
    $payload['subscription_id']   = sanitize_text_field((string) ($payload['subscription_id'] ?? ''));
    $payload['token']             = sanitize_text_field((string) ($payload['token'] ?? ''));
    $payload['token_expires_at']  = max(0, (int) ($payload['token_expires_at'] ?? 0));
    $payload['last_validated_at'] = max(0, (int) ($payload['last_validated_at'] ?? 0));

    update_option(NIMBUS_LICENSE_OPTION_KEY, $payload, false);

    return $payload;
  }

  /** Remove all stored license data and revert to inactive state. */
  public function clear(): void
  {
    update_option(NIMBUS_LICENSE_OPTION_KEY, self::DEFAULTS, false);
  }
}

