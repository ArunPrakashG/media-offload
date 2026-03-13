<?php
namespace ArunPrakash\MediaOffload\Storage;

if (!defined('ABSPATH')) {
  exit;
}

final class AmazonS3ProviderProfile extends AbstractProviderProfile
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
    return __('Amazon S3', MEDIA_OFFLOAD_TEXT_DOMAIN);
  }

  public function title(): string
  {
    return __('Amazon S3', MEDIA_OFFLOAD_TEXT_DOMAIN);
  }

  public function summary(): string
  {
    return __('Use this when your media is stored directly in Amazon S3 with a standard AWS region and optional CDN/custom domain on top.', MEDIA_OFFLOAD_TEXT_DOMAIN);
  }

  public function requiredFields(): array
  {
    return [
      'bucket' => __('Bucket', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'region' => __('Region', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'access_key' => __('Access Key', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'secret_key' => __('Secret Key', MEDIA_OFFLOAD_TEXT_DOMAIN),
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
