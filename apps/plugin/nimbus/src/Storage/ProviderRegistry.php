<?php
namespace Nimbus\Plugin\Storage;

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

    $defaults = [
      's3'                  => new AmazonS3ProviderProfile(),
      'r2'                  => new CloudflareR2ProviderProfile(),
      'digitalocean_spaces' => new DigitalOceanSpacesProviderProfile(),
      'wasabi'              => new WasabiProviderProfile(),
      'backblaze_b2'        => new BackblazeB2ProviderProfile(),
      'minio'               => new MinioProviderProfile(),
      's3_compatible'       => new S3CompatibleProviderProfile(),
      'gcs'                 => new GcsProviderProfile(),
      'local'               => new LocalProviderProfile(),
    ];

    /**
     * Filters the registered storage provider profiles.
     *
     * Extensions (e.g. premium add-ons) should hook here to register
     * additional providers or replace built-in ones.
     *
     * The filter receives and must return an associative array whose keys
     * are provider slugs (lowercase, underscore-separated) and values are
     * objects implementing Nimbus\Plugin\Storage\ProviderProfile.
     *
     * Example — register a custom Azure Blob provider:
     *
     *     add_filter( NIMBUS_FILTER_PROVIDER_PROFILES, function ( array $profiles ): array {
     *         $profiles['azure_blob'] = new AzureBlobProviderProfile();
     *         return $profiles;
     *     } );
     *
     * @since 0.1.4
     *
     * @param array<string, ProviderProfile> $profiles Keyed by provider slug.
     */
    $this->profiles = (array) apply_filters(NIMBUS_FILTER_PROVIDER_PROFILES, $defaults);

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
