<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Backblaze B2 provider profile.
 *
 * Backblaze B2 Cloud Storage exposes an S3-compatible API.
 *
 * Endpoint format: https://s3.{region}.backblazeb2.com
 * Example regions:  us-west-004, eu-central-003, us-east-005
 *
 * B2 uses virtual-hosted style URLs; path-style is not required.
 * Credentials are B2 Application Keys (keyID + applicationKey), NOT
 * account ID + master key.
 */
final class BackblazeB2ProviderProfile extends AbstractProviderProfile implements ProviderProfile
{
  private const ENDPOINT_PATTERN = 'https://s3.{region}.backblazeb2.com';

  public function key(): string
  {
    return 'backblaze_b2';
  }

  public function adapterType(): string
  {
    return 's3';
  }

  public function label(): string
  {
    return __('Backblaze B2', NIMBUS_TEXT_DOMAIN);
  }

  public function title(): string
  {
    return __('Backblaze B2 Cloud Storage', NIMBUS_TEXT_DOMAIN);
  }

  public function summary(): string
  {
    return __('Backblaze B2 S3-compatible cloud storage. Enter the B2 region (e.g. us-west-004), bucket name, and your Application Key ID + Application Key from the Backblaze console.', NIMBUS_TEXT_DOMAIN);
  }

  public function requiredFields(): array
  {
    return [
      'bucket'     => __('Bucket', NIMBUS_TEXT_DOMAIN),
      'region'     => __('Region (e.g. us-west-004, eu-central-003)', NIMBUS_TEXT_DOMAIN),
      'access_key' => __('Application Key ID', NIMBUS_TEXT_DOMAIN),
      'secret_key' => __('Application Key', NIMBUS_TEXT_DOMAIN),
    ];
  }

  public function fieldHints(): array
  {
    return [
      'bucket'        => __('Your Backblaze B2 bucket name.', NIMBUS_TEXT_DOMAIN),
      'region'        => __('B2 region code visible in your bucket endpoint URL, e.g. us-west-004, eu-central-003.', NIMBUS_TEXT_DOMAIN),
      'access_key'    => __('Application Key ID — create one under App Keys in the Backblaze console (not your account ID).', NIMBUS_TEXT_DOMAIN),
      'secret_key'    => __('Application Key shown once at creation time, paired with the Key ID above.', NIMBUS_TEXT_DOMAIN),
      'session_token' => '',
      'endpoint'      => __('Leave empty — B2 endpoints are built automatically from the region.', NIMBUS_TEXT_DOMAIN),
      'r2_account_id' => '',
    ];
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();

    $region = strtolower(trim((string) ($values['region'] ?? '')));
    $values['region'] = $region;

    // Build the B2 S3-compatible endpoint from the region when not overridden.
    if (($values['endpoint'] ?? '') === '' && $region !== '') {
      $values['endpoint'] = str_replace('{region}', $region, self::ENDPOINT_PATTERN);
    }

    // B2 works with virtual-hosted style URLs (default).
    $values['use_path_style_endpoint'] = false;

    // No STS session tokens on B2.
    $values['session_token'] = '';

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
