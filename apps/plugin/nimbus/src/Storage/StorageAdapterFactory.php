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

    $configuration = $this->settings->adapterConfiguration();
    $provider = $configuration['provider'] ?? 's3';
    $adapterType = $this->providerRegistry->get((string) $provider)->adapterType();

    $this->adapter = match ($adapterType) {
      's3' => new S3StorageAdapter($configuration, $this->logger),
      'local' => new LocalStorageAdapter($configuration, $this->logger),
      default => throw new RuntimeException(sprintf('Unsupported storage provider: %s', $provider)),
    };

    return $this->adapter;
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