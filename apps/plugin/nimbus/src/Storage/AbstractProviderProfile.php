<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

abstract class AbstractProviderProfile implements ProviderProfile
{
  use ProviderProfileHelpers;

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

  public function fieldHints(): array
  {
    return [];
  }

  public function fieldLabels(): array
  {
    return [];
  }
}
