<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * MinIO provider profile.
 *
 * MinIO is a high-performance, self-hosted S3-compatible object storage server.
 * It is deployed on-premises or on any cloud infrastructure.
 *
 * MinIO requires:
 *   - A custom endpoint pointing at your MinIO server (e.g. https://minio.example.com)
 *   - A bucket name
 *   - An access key (MinIO root user or policy-scoped access key)
 *   - A secret key
 *   - A region string (defaults to "us-east-1" when not set)
 *   - Path-style endpoint requests (always required for MinIO)
 */
final class MinioProviderProfile extends AbstractProviderProfile implements ProviderProfile
{
  public function key(): string
  {
    return 'minio';
  }

  public function adapterType(): string
  {
    return 's3';
  }

  public function label(): string
  {
    return __('MinIO', 'usenimbus-media-offload');
  }

  public function title(): string
  {
    return __('MinIO', 'usenimbus-media-offload');
  }

  public function summary(): string
  {
    return __('Self-hosted S3-compatible object storage. Enter the full URL of your MinIO server as the custom endpoint. Path-style requests are enabled automatically.', 'usenimbus-media-offload');
  }

  public function requiredFields(): array
  {
    return [
      'bucket'   => __('Bucket', 'usenimbus-media-offload'),
      'endpoint' => __('MinIO Endpoint URL', 'usenimbus-media-offload'),
      'access_key' => __('Access Key', 'usenimbus-media-offload'),
      'secret_key' => __('Secret Key', 'usenimbus-media-offload'),
    ];
  }

  public function fieldHints(): array
  {
    return [
      'bucket'        => __('The bucket name on your MinIO server.', 'usenimbus-media-offload'),
      'endpoint'      => __('Full URL of your MinIO server, e.g. https://minio.example.com. Required — MinIO does not use a shared cloud endpoint.', 'usenimbus-media-offload'),
      'access_key'    => __('MinIO access key (root user or a policy-scoped key from the MinIO console).', 'usenimbus-media-offload'),
      'secret_key'    => __('MinIO secret key paired with the access key above.', 'usenimbus-media-offload'),
      'region'        => __('MinIO does not enforce regions — leave as us-east-1 or any value your server accepts.', 'usenimbus-media-offload'),
      'session_token' => '',
      'r2_account_id' => '',
    ];
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();

    // MinIO always needs path-style requests.
    $values['use_path_style_endpoint'] = true;

    // Default region when not set.
    if (trim((string) ($values['region'] ?? '')) === '') {
      $values['region'] = 'us-east-1';
    }

    // No STS session tokens on MinIO.
    $values['session_token'] = '';

    return $values;
  }

  public function hasValidCredentials(array $values): bool
  {
    $values = $this->normalize($values);

    return $values['bucket'] !== ''
      && $values['endpoint'] !== ''
      && $values['access_key'] !== ''
      && $values['secret_key'] !== '';
  }
}
