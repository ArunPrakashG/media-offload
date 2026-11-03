<?php
namespace Nimbus\Plugin\Storage;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Support\Logger;
use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

final class StorageAdapterFactory
{
  private ProviderRegistry $providerRegistry;

  private ?StorageAdapter $adapter = null;

  public function __construct(
    private Settings $settings,
    private Logger $logger,
  ) {
    $this->providerRegistry = new ProviderRegistry();
  }

  public function getAdapter(): StorageAdapter
  {
    if ($this->adapter instanceof StorageAdapter) {
      return $this->adapter;
    }

    $this->adapter = $this->fromConfiguration($this->settings->adapterConfiguration());

    return $this->adapter;
  }

  /**
   * Build an uncached adapter for an explicit configuration.
   *
   * @param array<string, mixed> $configuration
   */
  public function fromConfiguration(array $configuration): StorageAdapter
  {
    $provider      = (string) ($configuration['provider'] ?? 's3');
    $adapterType   = $this->providerRegistry->get($provider)->adapterType();

    /**
     * Filters the map of storage adapter factory callables.
     *
     * Each entry must be a callable with the signature:
     *   function ( array $configuration ): StorageAdapter
     *
     * The key must match the string returned by ProviderProfile::adapterType().
     * Built-in adapter types are 's3' and 'local'.
     *
     * Example — register a custom Azure Blob adapter:
     *
     *     add_filter( NIMBUS_FILTER_ADAPTER_FACTORIES, function ( array $factories ): array {
     *         $factories['azure'] = fn( array $cfg ) => new AzureBlobStorageAdapter( $cfg );
     *         return $factories;
     *     } );
     *
     * @since 0.1.4
     *
     * @param array<string, callable(array): StorageAdapter> $factories
     */
    $factories = (array) apply_filters(NIMBUS_FILTER_ADAPTER_FACTORIES, [
      's3'    => fn(array $cfg) => new S3StorageAdapter($cfg, $this->logger),
      'local' => fn(array $cfg) => new LocalStorageAdapter($cfg, $this->logger),
    ]);

    $factory = $factories[$adapterType] ?? null;

    if (!is_callable($factory)) {
      throw new RuntimeException(
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message, never echoed directly; caught and logged or wrapped by callers.
        sprintf('Unsupported storage adapter type "%s" for provider "%s".', $adapterType, $provider)
      );
    }

    return $factory($configuration);
  }

  public function get_adapter(): StorageAdapter
  {
    return $this->getAdapter();
  }

  public function reset(): void
  {
    $this->adapter = null;
  }
}