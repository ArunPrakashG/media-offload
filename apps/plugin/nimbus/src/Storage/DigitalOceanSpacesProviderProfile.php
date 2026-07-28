<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * DigitalOcean Spaces provider profile.
 *
 * Spaces is fully S3-compatible. The endpoint format is:
 *   https://{region}.digitaloceanspaces.com
 *
 * CDN endpoints follow:
 *   https://{bucket}.{region}.cdn.digitaloceanspaces.com
 *
 * Uses the generic S3StorageAdapter (adapterType = 's3') with path-style
 * endpoint disabled (DO Spaces requires virtual-hosted style).
 */
final class DigitalOceanSpacesProviderProfile extends AbstractProviderProfile implements ProviderProfile
{
  private const ENDPOINT_PATTERN = 'https://{region}.digitaloceanspaces.com';

  public function key(): string
  {
    return 'digitalocean_spaces';
  }

  public function adapterType(): string
  {
    return 's3';
  }

  public function label(): string
  {
    return __('DigitalOcean Spaces', NIMBUS_TEXT_DOMAIN);
  }

  public function title(): string
  {
    return __('DigitalOcean Spaces', NIMBUS_TEXT_DOMAIN);
  }

  public function summary(): string
  {
    return __('DigitalOcean Spaces Object Storage — S3-compatible. Enter your Space name as the bucket and select the datacenter region. Optionally enable the built-in Spaces CDN from your DigitalOcean control panel and enter the CDN URL here.', NIMBUS_TEXT_DOMAIN);
  }

  public function requiredFields(): array
  {
    return [
      'bucket' => __('Space Name (bucket)', NIMBUS_TEXT_DOMAIN),
      'region' => __('Region (e.g. nyc3, ams3, sgp1)', NIMBUS_TEXT_DOMAIN),
      'access_key' => __('Spaces Access Key', NIMBUS_TEXT_DOMAIN),
      'secret_key' => __('Spaces Secret Key', NIMBUS_TEXT_DOMAIN),
    ];
  }

  public function fieldHints(): array
  {
    return [
      'bucket'        => __('Your DigitalOcean Space name (used as the bucket).', NIMBUS_TEXT_DOMAIN),
      'region'        => __('Spaces region slug, e.g. nyc3, ams3, sgp1, fra1, blr1. Found in your Space URL.', NIMBUS_TEXT_DOMAIN),
      'access_key'    => __('Spaces access key from the DigitalOcean control panel → API → Spaces Keys.', NIMBUS_TEXT_DOMAIN),
      'secret_key'    => __('Spaces secret key paired with the access key above.', NIMBUS_TEXT_DOMAIN),
      'endpoint'      => __('Leave empty to use the auto-built Spaces endpoint, or enter a Spaces CDN URL for edge delivery.', NIMBUS_TEXT_DOMAIN),
      'session_token' => '',
      'r2_account_id' => '',
    ];
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();

    $region = strtolower(trim((string) ($values['region'] ?? '')));
    $values['region'] = $region;

    // Build the Spaces endpoint from the region if not explicitly overridden.
    if (($values['endpoint'] ?? '') === '' && $region !== '') {
      $values['endpoint'] = str_replace('{region}', $region, self::ENDPOINT_PATTERN);
    }

    // Spaces uses virtual-hosted style URLs by default.
    $values['use_path_style_endpoint'] = false;

    return $values;
  }

  public function hasValidCredentials(array $values): bool
  {
    $values = $this->normalize($values);

    return $values['bucket'] !== ''
      && $values['region'] !== ''
      && $values['access_key'] !== ''
      && $values['secret_key'] !== '';
  }
}
