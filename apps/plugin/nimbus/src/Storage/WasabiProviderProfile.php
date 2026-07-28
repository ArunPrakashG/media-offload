<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Wasabi provider profile.
 *
 * Wasabi Hot Cloud Storage is an S3-compatible service with a single flat
 * storage fee and no egress charges.
 *
 * Endpoint format: https://s3.{region}.wasabisys.com
 * Example regions:  us-east-1, us-east-2, us-central-1, us-west-1,
 *                   eu-central-1, eu-west-1, eu-west-2, ap-northeast-1,
 *                   ap-northeast-2, ap-southeast-1, ca-central-1
 *
 * Wasabi uses virtual-hosted style URLs; path-style is NOT recommended.
 */
final class WasabiProviderProfile extends AbstractProviderProfile implements ProviderProfile
{
  private const ENDPOINT_PATTERN = 'https://s3.{region}.wasabisys.com';

  public function key(): string
  {
    return 'wasabi';
  }

  public function adapterType(): string
  {
    return 's3';
  }

  public function label(): string
  {
    return __('Wasabi', 'nimbus');
  }

  public function title(): string
  {
    return __('Wasabi Hot Cloud Storage', 'nimbus');
  }

  public function summary(): string
  {
    return __('Wasabi is an S3-compatible cloud storage service with no egress fees. Select the region matching your Wasabi bucket and enter the access/secret keys from your Wasabi console.', 'nimbus');
  }

  public function requiredFields(): array
  {
    return [
      'bucket'     => __('Bucket', 'nimbus'),
      'region'     => __('Region (e.g. us-east-1, eu-central-1)', 'nimbus'),
      'access_key' => __('Wasabi Access Key', 'nimbus'),
      'secret_key' => __('Wasabi Secret Key', 'nimbus'),
    ];
  }

  public function fieldHints(): array
  {
    return [
      'bucket'        => __('Your Wasabi bucket name.', 'nimbus'),
      'region'        => __('Wasabi region code, e.g. us-east-1, us-east-2, us-central-1, eu-central-1, ap-northeast-1.', 'nimbus'),
      'access_key'    => __('Access Key from the Wasabi console (IAM → Access Keys).', 'nimbus'),
      'secret_key'    => __('Secret Key paired with the access key above from the Wasabi console.', 'nimbus'),
      'session_token' => '',
      'endpoint'      => __('Leave empty — Wasabi endpoints are built automatically from the region.', 'nimbus'),
      'r2_account_id' => '',
    ];
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();

    $region = strtolower(trim((string) ($values['region'] ?? '')));
    $values['region'] = $region;

    // Build the Wasabi endpoint from the region when not explicitly overridden.
    if (($values['endpoint'] ?? '') === '' && $region !== '') {
      $values['endpoint'] = str_replace('{region}', $region, self::ENDPOINT_PATTERN);
    }

    // Wasabi uses virtual-hosted style.
    $values['use_path_style_endpoint'] = false;

    // No STS session tokens on Wasabi.
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
