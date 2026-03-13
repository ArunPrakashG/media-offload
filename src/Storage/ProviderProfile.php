<?php
namespace ArunPrakash\MediaOffload\Storage;

if (!defined('ABSPATH')) {
  exit;
}

interface ProviderProfile
{
  public function key(): string;

  public function adapterType(): string;

  public function label(): string;

  public function title(): string;

  public function summary(): string;

  /**
   * @return array<string, string>
   */
  public function requiredFields(): array;

  /**
   * @return array<int, string>
   */
  public function missingCredentialLabels(array $values): array;

  public function normalize(array $values): array;

  public function hasValidCredentials(array $values): bool;
}
