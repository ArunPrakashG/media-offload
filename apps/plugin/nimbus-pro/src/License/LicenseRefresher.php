<?php
namespace Nimbus\Plugin\License;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Handles periodic background token refresh for active paid licenses.
 *
 * Registered as a WP-Cron event (twice daily) in Plugin::bootInternal().
 * On each run it:
 *   1. Skips if the license is not active or has no key.
 *   2. Skips if it was last validated within the past 12 hours (debounce).
 *   3. Skips if the token is still within its exp window (not yet in grace).
 *   4. Re-calls /v1/licenses/activate to obtain a fresh signed token.
 *   5. On success: stores the new token + updates last_validated_at.
 *   6. On hard errors (revoked, expired, not found): marks the license inactive.
 *
 * The refresh window is server-defined via the "gau" claim in the signed token
 * (grace-until = exp + TOKEN_GRACE_PERIOD_DAYS). The plugin never hardcodes
 * grace duration; it reads it from the token and starts refreshing once exp
 * is crossed (i.e. when the grace window begins).
 *
 * Why this matters:
 *   Without periodic revalidation a revoked or cancelled license continues to
 *   work for the entire grace window. This cron job limits that window to at
 *   most 12-24 hours from the time of revocation.
 */
final class LicenseRefresher
{
  /** Minimum time between API calls (prevents hammering on every cron tick). */
  private const MIN_INTERVAL_SECONDS = 12 * 3600; // 12 hours

  /** Error codes returned by the backend that indicate a hard license failure. */
  private const HARD_FAILURE_CODES = [
    'key_not_found',
    'key_expired',
    'key_revoked',
    'max_activations_reached',
  ];

  public function __construct(private LicenseManager $licenseManager) {}

  /**
   * Run the refresh logic. Safe to call on every cron tick — internal gates
   * prevent unnecessary API calls.
   */
  public function maybeRefresh(): void
  {
    $data = $this->licenseManager->all();

    if ($data['status'] !== 'active') {
      return;
    }

    $key   = trim((string) ($data['key']   ?? ''));
    $token = trim((string) ($data['token'] ?? ''));

    if ($key === '') {
      return;
    }

    // Debounce: skip if last validated recently.
    $lastValidated = (int) ($data['last_validated_at'] ?? 0);
    if ($lastValidated > 0 && time() - $lastValidated < self::MIN_INTERVAL_SECONDS) {
      return;
    }

    // Only refresh when the token is approaching expiry (or already in the
    // grace window). If it still has ample time left, skip the API call.
    if ($token !== '' && !$this->tokenNeedsRefresh($token)) {
      return;
    }

    $domain = wp_parse_url((string) home_url(), PHP_URL_HOST) ?: '';
    if ($domain === '') {
      return;
    }

    $client = new LicenseClient();
    $result = $client->activate($key, $domain, 'nimbus');

    if (is_wp_error($result)) {
      $code = $result->get_error_code();
      if (in_array($code, self::HARD_FAILURE_CODES, strict: true)) {
        // License no longer valid — disable features immediately.
        $this->licenseManager->update($key, ['status' => 'inactive']);
      }
      // Soft errors (network timeout, etc.) are ignored: the grace window
      // keeps the site functional until the next successful refresh.
      return;
    }

    // Success — store the refreshed token.
    $this->licenseManager->update($key, [
      'status'            => 'active',
      'tier'              => sanitize_key((string) ($result['tier'] ?? $data['tier'] ?? '')),
      'token'             => sanitize_text_field((string) ($result['token'] ?? '')),
      'token_expires_at'  => strtotime((string) ($result['expires_at'] ?? '')) ?: 0,
      'last_validated_at' => time(),
    ]);
  }

  /**
   * Returns true when the token has passed its exp claim, meaning the
   * server-defined grace window has started and we should refresh.
   *
   * The grace period duration itself is encoded in the "gau" claim (grace-until)
   * by the backend. The plugin does not need to know the grace duration — it
   * simply starts refreshing once exp is crossed, which is exactly when the
   * user-visible "renewing…" behaviour should begin.
   */
  private function tokenNeedsRefresh(string $token): bool
  {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
      return true; // malformed — always refresh
    }

    $b64 = strtr($parts[0], '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
      $b64 .= str_repeat('=', 4 - $pad);
    }

    $json   = base64_decode($b64, strict: true);
    $claims = $json !== false ? json_decode($json, associative: true) : null;
    $exp    = is_array($claims) && isset($claims['exp']) ? (int) $claims['exp'] : 0;

    if ($exp <= 0) {
      return false; // no expiry set — perpetual token, no refresh needed
    }

    // Start refreshing once the token has passed exp (grace window has begun).
    return time() > $exp;
  }
}
