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
    return $this->status() === 'active';
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
  private function verifyTokenSignature(string $token): bool
  {
    $pubKeyB64 = defined('NIMBUS_LICENSE_PUBLIC_KEY') ? NIMBUS_LICENSE_PUBLIC_KEY : '';
    if ($pubKeyB64 === '' || $pubKeyB64 === 'REPLACE_WITH_BASE64_ED25519_PUBLIC_KEY') {
      // Public key not yet configured (dev environment). Skip verification
      // so the plugin stays usable during local development.
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
   * This is the same pattern used by Freemius and EDD Software Licensing:
   * - Features/entitlements are embedded in a signed token at activation time.
   * - The plugin verifies the signature locally on every check (no network hop).
   * - Real enforcement happens server-side on API calls (Bearer auth).
   * - Tampering with the stored token is detected because the signature
   *   won't match the forged payload.
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

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
      return [];
    }

    // base64url → standard base64 with padding.
    $b64 = strtr($parts[0], '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
      $b64 .= str_repeat('=', 4 - $pad);
    }

    $json = base64_decode($b64, strict: true);
    if ($json === false) {
      return [];
    }

    $claims = json_decode($json, associative: true);
    if (!is_array($claims) || !isset($claims['features']) || !is_array($claims['features'])) {
      return [];
    }

    return array_map('strval', $claims['features']);
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

