<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Utility helpers for ProviderProfile implementations.
 *
 * This trait contains shared, provider-agnostic helpers that both the built-in
 * AbstractProviderProfile base class and any third-party provider class can
 * use independently.
 *
 * Third-party providers that prefer not to extend AbstractProviderProfile can
 * simply add `use ProviderProfileHelpers;` alongside `implements ProviderProfile`:
 *
 *     final class MyCustomProviderProfile implements ProviderProfile
 *     {
 *         use ProviderProfileHelpers;
 *         // ...
 *     }
 */
trait ProviderProfileHelpers
{
  /**
   * Sanitise and normalise the set of fields that every cloud provider shares.
   *
   * Call this inside your `normalize()` implementation to avoid repeating the
   * same boilerplate for every field.
   *
   * @param array<string, mixed> $values
   * @return array<string, mixed>
   */
  protected function normalizeCommon(array $values): array
  {
    $values['bucket']               = trim((string) ($values['bucket'] ?? ''));
    $values['r2_account_id']        = trim((string) ($values['r2_account_id'] ?? ''));
    $values['region']               = trim((string) ($values['region'] ?? ''));
    $values['access_key']           = trim((string) ($values['access_key'] ?? ''));
    $values['secret_key']           = trim((string) ($values['secret_key'] ?? ''));
    $values['session_token']        = trim((string) ($values['session_token'] ?? ''));
    $values['prefix']               = trim((string) ($values['prefix'] ?? ''));
    $values['endpoint']             = rtrim(trim((string) ($values['endpoint'] ?? '')), '/');
    $values['custom_domain']        = sanitize_text_field((string) ($values['custom_domain'] ?? ''));
    $values['use_path_style_endpoint'] = !empty($values['use_path_style_endpoint']);
    $values['storage_class']        = trim((string) ($values['storage_class'] ?? 'STANDARD'));
    $values['visibility']           = trim((string) ($values['visibility'] ?? 'public-read'));

    return $values;
  }

  /**
   * Return true when an endpoint URL points to Cloudflare R2.
   */
  protected function endpointLooksLikeR2(string $endpoint): bool
  {
    if ($endpoint === '') {
      return false;
    }

    $host = (string) wp_parse_url($endpoint, PHP_URL_HOST);
    if ($host === '') {
      return false;
    }

    return strpos(strtolower($host), 'r2.cloudflarestorage.com') !== false;
  }

  /**
   * Build the canonical R2 bucket endpoint from a Cloudflare account ID.
   */
  protected function buildR2Endpoint(string $accountId): string
  {
    return 'https://' . trim($accountId) . '.r2.cloudflarestorage.com';
  }
}
