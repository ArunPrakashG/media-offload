<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

final class AmazonS3ProviderProfile extends AbstractProviderProfile implements ProviderProfile
{
  public function key(): string
  {
    return 's3';
  }

  public function adapterType(): string
  {
    return 's3';
  }

  public function label(): string
  {
    return __('Amazon S3', 'usenimbus-media-offload');
  }

  public function title(): string
  {
    return __('Amazon S3', 'usenimbus-media-offload');
  }

  public function summary(): string
  {
    return __('Use this when your media is stored directly in Amazon S3 with a standard AWS region and optional CDN/custom domain on top.', 'usenimbus-media-offload');
  }

  public function requiredFields(): array
  {
    return [
      'bucket' => __('Bucket', 'usenimbus-media-offload'),
      'region' => __('Region', 'usenimbus-media-offload'),
      'access_key' => __('Access Key', 'usenimbus-media-offload'),
      'secret_key' => __('Secret Key', 'usenimbus-media-offload'),
    ];
  }

  public function fieldHints(): array
  {
    return [
      'bucket'        => __('The S3 bucket where your media files will be stored.', 'usenimbus-media-offload'),
      'region'        => __('AWS region code, e.g. us-east-1, eu-west-2, ap-southeast-1.', 'usenimbus-media-offload'),
      'access_key'    => __('AWS IAM Access Key ID.', 'usenimbus-media-offload'),
      'secret_key'    => __('AWS IAM Secret Access Key.', 'usenimbus-media-offload'),
      'session_token' => __('Optional — only needed for temporary STS credentials. Leave empty for standard IAM keys.', 'usenimbus-media-offload'),
      'endpoint'      => __('Leave empty to use the standard AWS S3 endpoint. Override only for VPC or custom configurations.', 'usenimbus-media-offload'),
      'r2_account_id' => '',
    ];
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();

    if (strtolower((string) $values['region']) === 'auto' && !$this->endpointLooksLikeR2((string) $values['endpoint'])) {
      $values['region'] = '';
    }

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
      && $values['access_key'] !== ''
      && $values['secret_key'] !== '';
  }
}
