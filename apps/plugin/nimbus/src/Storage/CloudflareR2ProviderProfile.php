<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

final class CloudflareR2ProviderProfile extends AbstractProviderProfile implements ProviderProfile
{
  public function key(): string
  {
    return 'r2';
  }

  public function adapterType(): string
  {
    return 's3';
  }

  public function label(): string
  {
    return __('Cloudflare R2', 'plume-media-offload');
  }

  public function title(): string
  {
    return __('Cloudflare R2', 'plume-media-offload');
  }

  public function summary(): string
  {
    return __('R2 uses account-scoped endpoints, region=auto, and path-style signing. Provide your bucket and either an account ID or a custom R2 endpoint.', 'plume-media-offload');
  }

  public function requiredFields(): array
  {
    return [
      'bucket' => __('Bucket', 'plume-media-offload'),
      'access_key' => __('Access Key', 'plume-media-offload'),
      'secret_key' => __('Secret Key', 'plume-media-offload'),
    ];
  }

  public function fieldHints(): array
  {
    return [
      'bucket'       => __('Your Cloudflare R2 bucket name.', 'plume-media-offload'),
      'r2_account_id' => __('Your Cloudflare account ID (found in the dashboard → Overview). Used to auto-build the R2 endpoint when no custom endpoint is set.', 'plume-media-offload'),
      'access_key'   => __('Access Key ID from your R2 API token — create one under R2 → Manage API tokens.', 'plume-media-offload'),
      'secret_key'   => __('Secret Access Key from your R2 API token, paired with the Access Key ID above.', 'plume-media-offload'),
      'endpoint'     => __('Leave empty to auto-build from Account ID, or enter a full custom R2 endpoint URL.', 'plume-media-offload'),
      'session_token' => '',
    ];
  }

  public function fieldLabels(): array
  {
    return [
      'r2_account_id' => __('R2 Account ID', 'plume-media-offload'),
    ];
  }

  public function missingCredentialLabels(array $values): array
  {
    $values = $this->normalize($values);
    $missing = parent::missingCredentialLabels($values);

    if ($values['r2_account_id'] === '' && $values['endpoint'] === '') {
      $missing[] = __('R2 Account ID or Custom Endpoint', 'plume-media-offload');
    }

    return $missing;
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();

    if ($values['region'] === '') {
      $values['region'] = 'auto';
    }

    if ($values['endpoint'] === '' && $values['r2_account_id'] !== '') {
      $values['endpoint'] = $this->buildR2Endpoint((string) $values['r2_account_id']);
    }

    $values['use_path_style_endpoint'] = true;
    $values['session_token'] = '';

    return $values;
  }

  public function hasValidCredentials(array $values): bool
  {
    $values = $this->normalize($values);
    $hasEndpointOrAccount = $values['endpoint'] !== '' || $values['r2_account_id'] !== '';

    return $values['bucket'] !== ''
      && $values['access_key'] !== ''
      && $values['secret_key'] !== ''
      && $hasEndpointOrAccount;
  }
}
