<?php
namespace Pathanamthitta\MediaOffload;

use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

class Storage_Adapter_Factory
{
  private Settings $settings;

  private Logger $logger;

  private ?Storage_Adapter $adapter = null;

  public function __construct(Settings $settings, Logger $logger)
  {
    $this->settings = $settings;
    $this->logger = $logger;
  }

  public function get_adapter(): Storage_Adapter
  {
    if ($this->adapter instanceof Storage_Adapter) {
      return $this->adapter;
    }

    $configuration = $this->settings->adapter_configuration();
    $provider = $configuration['provider'] ?? 's3';

    switch ($provider) {
      case 's3':
      case 'r2':
        $this->adapter = new S3_Storage_Adapter($configuration, $this->logger);
        break;
      case 'local':
        $this->adapter = new Local_Storage_Adapter($configuration, $this->logger);
        break;
      default:
        throw new RuntimeException(sprintf('Unsupported storage provider: %s', $provider));
    }

    return $this->adapter;
  }

  public function reset(): void
  {
    $this->adapter = null;
  }
}
