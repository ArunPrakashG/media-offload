<?php
namespace ArunPrakash\MediaOffload\Storage;

if (!defined('ABSPATH')) {
  exit;
}

abstract class AbstractProviderProfile implements ProviderProfile
{
  public function missingCredentialLabels(array $values): array
  {
    $values = $this->normalize($values);
    $missing = [];

    foreach ($this->requiredFields() as $field => $label) {
      if (trim((string) ($values[$field] ?? '')) === '') {
        $missing[] = $label;
      }
    }

    return $missing;
  }

  protected function normalizeCommon(array $values): array
  {
    $values['bucket'] = trim((string) ($values['bucket'] ?? ''));
    $values['r2_account_id'] = trim((string) ($values['r2_account_id'] ?? ''));
    $values['region'] = trim((string) ($values['region'] ?? ''));
    $values['access_key'] = trim((string) ($values['access_key'] ?? ''));
    $values['secret_key'] = trim((string) ($values['secret_key'] ?? ''));
    $values['session_token'] = trim((string) ($values['session_token'] ?? ''));
    $values['prefix'] = trim((string) ($values['prefix'] ?? ''));
    $values['endpoint'] = rtrim(trim((string) ($values['endpoint'] ?? '')), '/');
    $values['custom_domain'] = sanitize_text_field((string) ($values['custom_domain'] ?? ''));
    $values['use_path_style_endpoint'] = !empty($values['use_path_style_endpoint']);
    $values['storage_class'] = trim((string) ($values['storage_class'] ?? 'STANDARD'));
    $values['visibility'] = trim((string) ($values['visibility'] ?? 'public-read'));

    return $values;
  }

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

  protected function buildR2Endpoint(string $accountId): string
  {
    return 'https://' . trim($accountId) . '.r2.cloudflarestorage.com';
  }
}
