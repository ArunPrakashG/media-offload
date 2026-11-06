<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

final class S3CompatibleProviderProfile extends AbstractProviderProfile implements ProviderProfile
{
  public function key(): string
  {
    return 's3_compatible';
  }

  public function adapterType(): string
  {
    return 's3';
  }

  public function label(): string
  {
    return __('S3-compatible', 'usenimbus-media-offload');
  }

  public function title(): string
  {
    return __('S3-compatible storage', 'usenimbus-media-offload');
  }

  public function summary(): string
  {
    return __('Use this for providers that expose an S3-compatible API via a custom endpoint. Region is typically still required unless your provider documents otherwise.', 'usenimbus-media-offload');
  }

  public function requiredFields(): array
  {
    return [
      'bucket' => __('Bucket', 'usenimbus-media-offload'),
      'region' => __('Region', 'usenimbus-media-offload'),
      'endpoint' => __('Custom Endpoint', 'usenimbus-media-offload'),
      'access_key' => __('Access Key', 'usenimbus-media-offload'),
      'secret_key' => __('Secret Key', 'usenimbus-media-offload'),
    ];
  }

  public function fieldHints(): array
  {
    return [
      'bucket'        => __('The bucket name on your S3-compatible storage provider.', 'usenimbus-media-offload'),
      'region'        => __('Region required by your provider. Some providers accept any string; check your provider\'s documentation.', 'usenimbus-media-offload'),
      'access_key'    => __('Access Key ID for your S3-compatible storage provider.', 'usenimbus-media-offload'),
      'secret_key'    => __('Secret Access Key paired with the access key above.', 'usenimbus-media-offload'),
      'endpoint'      => __('Full base URL of your provider\'s S3-compatible API, e.g. https://s3.example.com.', 'usenimbus-media-offload'),
      'session_token' => '',
      'r2_account_id' => '',
    ];
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();

    if ($this->endpointLooksLikeR2((string) $values['endpoint'])) {
      if ($values['region'] === '' || strtolower((string) $values['region']) === 'us-east-1') {
        $values['region'] = 'auto';
      }

      $values['use_path_style_endpoint'] = true;
      $values['session_token'] = '';
    }

    return $values;
  }

  public function hasValidCredentials(array $values): bool
  {
    $values = $this->normalize($values);

    return $values['bucket'] !== ''
      && $values['region'] !== ''
      && $values['endpoint'] !== ''
      && $values['access_key'] !== ''
      && $values['secret_key'] !== '';
  }
}
