<?php
namespace Nimbus\Plugin\License;

use WP_Error;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * HTTP client for the Nimbus license backend.
 *
 * ## Security model
 *
 * **Activate** — The request is HMAC-SHA256 signed using the license key as the
 * shared secret (headers X-Nimbus-Timestamp, X-Nimbus-Nonce, X-Nimbus-Signature).
 * The backend verifies the signature *and* a timestamp/nonce pair to prevent
 * replay attacks. This ensures only someone who physically holds the key can
 * call the endpoint.
 *
 * **Deactivate / Activations list / Status** — These endpoints accept an
 * `Authorization: Bearer <token>` header where the token is the Ed25519-signed
 * activation certificate returned by the backend on successful activation. The
 * raw license key is never sent again; the token embeds the license UUID instead.
 *
 * **HTTPS enforcement** — Connections to non-localhost backends over plain HTTP
 * are rejected at the PHP level. Override `NIMBUS_BACKEND_URL` *and* define
 * `NIMBUS_ALLOW_HTTP=true` only in local development.
 *
 * All methods return an associative array on success or a WP_Error on failure.
 */
final class LicenseClient
{
  // ── Public API ─────────────────────────────────────────────────────────────

  /**
   * Activate a license key for the current domain.
   *
   * The request is HMAC-signed with the license key to prove possession.
   *
   * @return array{token:string,expires_at:string,tier:string,features:list<string>}|WP_Error
   */
  public function activate(string $key, string $domain, string $product): array|WP_Error
  {
    if ($err = $this->enforceHttps()) {
      return $err;
    }

    $body = (string) wp_json_encode([
      'key'            => $key,
      'domain'         => $domain,
      'product'        => $product,
      'plugin_version' => defined('NIMBUS_VERSION') ? NIMBUS_VERSION : '0.0.0',
    ]);

    return $this->postHmac('/v1/licenses/activate', $body, $key);
  }

  /**
   * Deactivate a specific domain from the license.
   *
   * Requires the Ed25519 activation token (no raw key is sent).
   *
   * @return array|WP_Error
   */
  public function deactivate(string $token, string $domain): array|WP_Error
  {
    if ($err = $this->enforceHttps()) {
      return $err;
    }

    $body = (string) wp_json_encode(['domain' => $domain]);
    return $this->postBearer('/v1/licenses/deactivate', $body, $token);
  }

  /**
   * Fetch the current license status.
   *
   * Requires the Ed25519 activation token.
   *
   * @return array{tier:string,status:string,max_activations:int,expires_at:string|null}|WP_Error
   */
  public function status(string $token): array|WP_Error
  {
    if ($err = $this->enforceHttps()) {
      return $err;
    }

    return $this->getBearer($this->baseUrl() . '/v1/licenses/status', $token);
  }

  /**
   * List all domains currently activated for the license.
   *
   * Requires the Ed25519 activation token.
   *
   * @return array{tier:string,max_activations:int,activations:list<array{domain:string,activated_at:string,last_seen_at:string}>}|WP_Error
   */
  public function listSites(string $token): array|WP_Error
  {
    if ($err = $this->enforceHttps()) {
      return $err;
    }

    return $this->getBearer($this->baseUrl() . '/v1/licenses/activations', $token);
  }

  /**
   * Register (or retrieve) a free-tier license directly from the plugin.
   *
   * This endpoint is public on the backend — no HMAC or Bearer token
   * required. The backend rate-limits by IP and is idempotent (same
   * email + product always returns the same free license key + token).
   *
   * @param string $email  The site admin email (get_option('admin_email')).
   * @param string $domain The site's hostname (wp_parse_url(home_url(), PHP_URL_HOST)).
   * @return array{key:string,token:string,expires_at:string|null,tier:string,features:list<string>}|WP_Error
   */
  public function registerFree(string $email, string $domain): array|WP_Error
  {
    if ($err = $this->enforceHttps()) {
      return $err;
    }

    $body = (string) wp_json_encode([
      'email'          => $email,
      'domain'         => $domain,
      'product'        => 'nimbus',
      'plugin_version' => defined('NIMBUS_VERSION') ? NIMBUS_VERSION : '0.0.0',
    ]);

    $response = wp_remote_post(
      $this->baseUrl() . '/v1/licenses/register-free',
      [
        'timeout'     => 15,
        'sslverify'   => true,
        'headers'     => [
          'Content-Type' => 'application/json',
          'Accept'       => 'application/json',
        ],
        'body'        => $body,
        'data_format' => 'body',
      ]
    );

    return $this->parseResponse($response);
  }

  // ── Internal transport helpers ──────────────────────────────────────────────

  /**
   * POST with HMAC-SHA256 signing (used for /activate).
   *
   * Adds X-Nimbus-Timestamp, X-Nimbus-Nonce, X-Nimbus-Signature headers so
   * the backend can verify the caller possesses the license key.
   */
  private function postHmac(string $path, string $body, string $key): array|WP_Error
  {
    $headers = array_merge(
      ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
      $this->signRequest('POST', $path, $body, $key)
    );

    $response = wp_remote_post(
      $this->baseUrl() . $path,
      [
        'timeout'     => 15,
        'sslverify'   => true,
        'headers'     => $headers,
        'body'        => $body,
        'data_format' => 'body',
      ]
    );

    return $this->parseResponse($response);
  }

  /**
   * POST with Bearer activation token (used for /deactivate).
   */
  private function postBearer(string $path, string $body, string $token): array|WP_Error
  {
    $response = wp_remote_post(
      $this->baseUrl() . $path,
      [
        'timeout'     => 15,
        'sslverify'   => true,
        'headers'     => [
          'Content-Type'  => 'application/json',
          'Accept'        => 'application/json',
          'Authorization' => 'Bearer ' . $token,
        ],
        'body'        => $body,
        'data_format' => 'body',
      ]
    );

    return $this->parseResponse($response);
  }

  /**
   * GET with Bearer activation token (used for /activations, /status).
   */
  private function getBearer(string $url, string $token): array|WP_Error
  {
    $response = wp_remote_get($url, [
      'timeout'   => 15,
      'sslverify' => true,
      'headers'   => [
        'Accept'        => 'application/json',
        'Authorization' => 'Bearer ' . $token,
      ],
    ]);

    return $this->parseResponse($response);
  }

  // ── HMAC signing ────────────────────────────────────────────────────────────

  /**
   * Build the three HMAC authentication headers for a request.
   *
   * Canonical string (each part separated by a newline):
   *   NIMBUS-HMAC-V1
   *   <METHOD>             e.g. POST
   *   <PATH>               e.g. /v1/licenses/activate  (no query string)
   *   <UNIX_TIMESTAMP>
   *   <NONCE>              32-char lowercase hex (128 random bits)
   *   <HEX(SHA256(body))>
   *
   * The HMAC key is the raw license key string.
   *
   * @return array<string,string>
   */
  private function signRequest(string $method, string $path, string $body, string $key): array
  {
    $timestamp = (string) time();
    $nonce     = bin2hex(random_bytes(16));   // 32-char hex, 128 bits of entropy
    $bodyHash  = hash('sha256', $body);       // lowercase hex

    $canonical = implode("\n", [
      'NIMBUS-HMAC-V1',
      strtoupper($method),
      $path,
      $timestamp,
      $nonce,
      $bodyHash,
    ]);

    $signature = hash_hmac('sha256', $canonical, $key);

    return [
      'X-Nimbus-Timestamp' => $timestamp,
      'X-Nimbus-Nonce'     => $nonce,
      'X-Nimbus-Signature' => $signature,
    ];
  }

  // ── URL helpers ─────────────────────────────────────────────────────────────

  private function baseUrl(): string
  {
    return defined('NIMBUS_BACKEND_URL')
      ? rtrim((string) NIMBUS_BACKEND_URL, '/')
      : 'https://api.usenimbus.dev';
  }

  /**
   * Ensure the backend URL uses HTTPS, unless explicitly allowed otherwise.
   *
   * In local development, define `NIMBUS_ALLOW_HTTP` as true in wp-config.php
   * to bypass this check.
   *
   * @return WP_Error|null  Returns WP_Error to short-circuit the caller; null means OK.
   */
  private function enforceHttps(): ?WP_Error
  {
    $url  = $this->baseUrl();
    $host = (string) parse_url($url, PHP_URL_HOST);

    $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
      || str_ends_with($host, '.local')
      || str_ends_with($host, '.test');

    $allowHttp = defined('NIMBUS_ALLOW_HTTP') && (bool) constant('NIMBUS_ALLOW_HTTP');

    if (!$isLocalhost && !$allowHttp && strncmp($url, 'https://', 8) !== 0) {
      return new WP_Error(
        'insecure_url',
        __('NIMBUS_BACKEND_URL must use HTTPS. Define NIMBUS_ALLOW_HTTP in wp-config.php to bypass this for local development only.', 'nimbus')
      );
    }

    return null;
  }

  // ── Response parsing ────────────────────────────────────────────────────────

  /**
   * Parse a WP HTTP response into an array or WP_Error.
   *
   * @param array|WP_Error $response
   * @return array|WP_Error
   */
  private function parseResponse(mixed $response): array|WP_Error
  {
    if (is_wp_error($response)) {
      return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $raw  = wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    if (!is_int($code)) {
      return new WP_Error('bad_response', __('Unexpected response from license server.', 'nimbus'));
    }

    if ($code >= 200 && $code < 300) {
      return is_array($data) ? $data : [];
    }

    // Error response: extract structured error payload when available.
    $message = is_array($data) && isset($data['error'])
      ? (string) $data['error']
      : sprintf(__('License server returned status %d.', 'nimbus'), $code);

    $errorCode = is_array($data) && isset($data['code'])
      ? sanitize_key((string) $data['code'])
      : 'license_error';

    return new WP_Error($errorCode, $message, ['http_status' => $code]);
  }
}
