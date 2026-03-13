<?php
namespace ArunPrakash\MediaOffload\Bootstrap;

use ArunPrakash\MediaOffload\Cache\PurgeManager;
use ArunPrakash\MediaOffload\Jobs\BulkJobManager;
use ArunPrakash\MediaOffload\Integration\DatabaseIntegrationRewriter;
use ArunPrakash\MediaOffload\Integration\IntegrationDiscovery;
use ArunPrakash\MediaOffload\Integration\SmartSliderAssetRewriter;
use ArunPrakash\MediaOffload\License\LicenseManager;
use ArunPrakash\MediaOffload\Cli\Command;
use ArunPrakash\MediaOffload\Config\Settings;
use ArunPrakash\MediaOffload\Delivery\UrlRewriter;
use ArunPrakash\MediaOffload\Media\UploadHandler;
use ArunPrakash\MediaOffload\Onboarding\ConflictDetector;
use ArunPrakash\MediaOffload\Onboarding\OnboardingManager;
use ArunPrakash\MediaOffload\Rest\ApiController;
use ArunPrakash\MediaOffload\Admin\SettingsUi;
use ArunPrakash\MediaOffload\Stats\Statistics;
use ArunPrakash\MediaOffload\Storage\StorageAdapterFactory;
use ArunPrakash\MediaOffload\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class Plugin
{
  private static ?self $instance = null;

  private Container $container;

  private bool $booted = false;

  private function __construct(private string $pluginFile, ?Container $container = null)
  {
    $this->container = $container ?? new Container();
  }

  public static function boot(string $pluginFile): self
  {
    if (!self::$instance instanceof self) {
      self::$instance = new self($pluginFile);
    }

    self::$instance->bootInternal();

    return self::$instance;
  }

  public function onInit(): void
  {
    if ($this->settings()->is_enabled()) {
      $this->urlRewriter()->register();
    }
  }

  public function onPluginsLoaded(): void
  {
    $this->purgeManager()->register();
    $this->uploadHandler()->register();
    $this->cliCommand()->register();
    $this->bulkJobManager()->register();
    $this->apiController()->register();
  }

  private function bootInternal(): void
  {
    if ($this->booted) {
      return;
    }

    DataMigration::register();
    DataMigration::run();

    add_action('init', [$this, 'onInit']);
    add_action('plugins_loaded', [$this, 'onPluginsLoaded'], 20);

    $this->settingsUi()->register();

    $this->booted = true;
  }

  private function logger(): Logger
  {
    return $this->container->get('logger', static fn (Container $container): Logger => new Logger());
  }

  private function settings(): Settings
  {
    return $this->container->get('settings', fn (Container $container): Settings => new Settings($this->logger()));
  }

  private function adapterFactory(): StorageAdapterFactory
  {
    return $this->container->get('adapter_factory', fn (Container $container): StorageAdapterFactory => new StorageAdapterFactory($this->settings(), $this->logger()));
  }

  private function statistics(): Statistics
  {
    return $this->container->get('statistics', fn (Container $container): Statistics => new Statistics($this->adapterFactory()));
  }

  private function uploadHandler(): UploadHandler
  {
    return $this->container->get('upload_handler', fn (Container $container): UploadHandler => new UploadHandler($this->settings(), $this->adapterFactory(), $this->logger()));
  }

  private function urlRewriter(): UrlRewriter
  {
    return $this->container->get('url_rewriter', fn (Container $container): UrlRewriter => new UrlRewriter($this->settings(), $this->adapterFactory(), $this->logger()));
  }

  private function settingsUi(): SettingsUi
  {
    return $this->container->get('settings_ui', fn (Container $container): SettingsUi => new SettingsUi($this->settings(), $this->logger(), $this->statistics(), $this->uploadHandler()));
  }

  private function cliCommand(): Command
  {
    return $this->container->get('cli_command', fn (Container $container): Command => new Command($this->uploadHandler(), $this->settings(), $this->adapterFactory(), $this->logger()));
  }

  private function smartSliderAssetRewriter(): SmartSliderAssetRewriter
  {
    return $this->container->get('smartslider_asset_rewriter', fn (Container $container): SmartSliderAssetRewriter => new SmartSliderAssetRewriter($this->settings(), $this->logger()));
  }

  private function integrationDiscovery(): IntegrationDiscovery
  {
    return $this->container->get('integration_discovery', fn (Container $container): IntegrationDiscovery => new IntegrationDiscovery($this->smartSliderAssetRewriter(), $this->databaseIntegrationRewriter()));
  }

  private function databaseIntegrationRewriter(): DatabaseIntegrationRewriter
  {
    return $this->container->get('database_integration_rewriter', fn (Container $container): DatabaseIntegrationRewriter => new DatabaseIntegrationRewriter($this->settings(), $this->logger()));
  }

  private function licenseManager(): LicenseManager
  {
    return $this->container->get('license_manager', fn (Container $container): LicenseManager => new LicenseManager());
  }

  private function onboardingManager(): OnboardingManager
  {
    return $this->container->get('onboarding_manager', fn (Container $container): OnboardingManager => new OnboardingManager($this->settings(), $this->licenseManager(), new ConflictDetector()));
  }

  private function bulkJobManager(): BulkJobManager
  {
    return $this->container->get('bulk_job_manager', fn (Container $container): BulkJobManager => new BulkJobManager(
      $this->settings(),
      $this->uploadHandler(),
      $this->logger(),
    ));
  }

  private function purgeManager(): PurgeManager
  {
    return $this->container->get('purge_manager', fn (Container $container): PurgeManager => new PurgeManager(
      $this->settings(),
      $this->logger(),
    ));
  }

  private function apiController(): ApiController
  {
    return $this->container->get('api_controller', fn (Container $container): ApiController => new ApiController(
      $this->settings(),
      $this->statistics(),
      $this->uploadHandler(),
      $this->bulkJobManager(),
      $this->integrationDiscovery(),
      $this->licenseManager(),
      $this->onboardingManager(),
      $this->logger(),
    ));
  }
}
