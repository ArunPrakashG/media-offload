<?php
namespace Nimbus\Plugin\Storage;

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

  /**
   * Per-field hint text shown below each input on the settings page.
   * Keys are field names (e.g. 'bucket', 'region', 'access_key').
   * An empty string hides the hint; a missing key leaves the generic default.
   *
   * @return array<string, string>
   */
  public function fieldHints(): array;

  /**
   * Per-field label overrides for this provider. Keys are field names,
   * values are the translated label string to display.
   *
   * @return array<string, string>
   */
  public function fieldLabels(): array;

  public function normalize(array $values): array;

  public function hasValidCredentials(array $values): bool;
}
