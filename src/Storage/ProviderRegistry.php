<?php
namespace ArunPrakash\MediaOffload\Storage;

if (!defined('ABSPATH')) {
  exit;
}

final class ProviderRegistry
{
  /**
   * @var array<string, ProviderProfile>|null
   */
  private ?array $profiles = null;

  /**
   * @return array<string, ProviderProfile>
   */
  public function all(): array
  {
    if (is_array($this->profiles)) {
      return $this->profiles;
    }

    $this->profiles = [
      's3' => new AmazonS3ProviderProfile(),
      's3_compatible' => new S3CompatibleProviderProfile(),
      'r2' => new CloudflareR2ProviderProfile(),
      'local' => new LocalProviderProfile(),
    ];

    return $this->profiles;
  }

  public function get(string $provider): ProviderProfile
  {
    $profiles = $this->all();

    return $profiles[$provider] ?? $profiles['s3'];
  }

  /**
   * @return array<string>
   */
  public function keys(): array
  {
    return array_keys($this->all());
  }
}
