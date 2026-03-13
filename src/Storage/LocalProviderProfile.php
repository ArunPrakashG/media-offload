<?php
namespace ArunPrakash\MediaOffload\Storage;

if (!defined('ABSPATH')) {
  exit;
}

final class LocalProviderProfile extends AbstractProviderProfile
{
  public function key(): string
  {
    return 'local';
  }

  public function adapterType(): string
  {
    return 'local';
  }

  public function label(): string
  {
    return __('Local (debug only)', MEDIA_OFFLOAD_TEXT_DOMAIN);
  }

  public function title(): string
  {
    return __('Local / Debug Mode', MEDIA_OFFLOAD_TEXT_DOMAIN);
  }

  public function summary(): string
  {
    return __('Use local mode only for debugging or dry runs. Remote credentials and delivery features are not required.', MEDIA_OFFLOAD_TEXT_DOMAIN);
  }

  public function requiredFields(): array
  {
    return [
      'provider' => __('Provider', MEDIA_OFFLOAD_TEXT_DOMAIN),
    ];
  }

  public function missingCredentialLabels(array $values): array
  {
    return [];
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();
    $values['session_token'] = '';

    return $values;
  }

  public function hasValidCredentials(array $values): bool
  {
    return true;
  }
}
