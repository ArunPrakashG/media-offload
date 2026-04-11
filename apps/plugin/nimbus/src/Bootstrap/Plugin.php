<?php
namespace Nimbus\Plugin\Bootstrap;

use Nimbus\Plugin\Extension\Registry;
use Nimbus\Plugin\Integration\DatabaseIntegrationRewriter;
use Nimbus\Plugin\Integration\IntegrationDiscovery;
use Nimbus\Plugin\Integration\SmartSliderAssetRewriter;
use Nimbus\Plugin\License\LicenseManager;
use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Delivery\UrlRewriter;
use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Onboarding\ConflictDetector;
use Nimbus\Plugin\Onboarding\OnboardingManager;
use Nimbus\Plugin\Rest\ApiController;
use Nimbus\Plugin\Admin\SettingsUi;
use Nimbus\Plugin\Stats\Statistics;
use Nimbus\Plugin\Storage\StorageAdapterFactory;
use Nimbus\Plugin\Support\Logger;

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

  /**
   * Called by the NIMBUS_LICENSE_REFRESH_HOOK cron.
   * The actual refresh is delegated to whichever pro extension provides it.
   */
  public function onLicenseRefresh(): void
  {
    do_action('nimbus/license/refresh');
  }

  public function onPluginsLoaded(): void
  {
    $this->uploadHandler()->register();
    $this->apiController()->register();
  }

  /**
   * Boot all registered pro extensions.
   * Runs at plugins_loaded priority 25, after pro extensions have registered
   * themselves at priority 0–20 via the nimbus/extensions/register action.
   */
  public function bootExtensions(): void
  {
    $this->extensionRegistry()->bootAll($this->container, $this->licenseManager());
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

    // Allow pro and third-party extensions to register themselves.
    // Fired at plugins_loaded priority 0 so all plugin files are loaded by then.
    $registry = $this->extensionRegistry();
    add_action('plugins_loaded', static function () use ($registry): void {
      do_action('nimbus/extensions/register', $registry);
    }, 0);

    // Boot registered extensions after the free plugin has initialised (priority 20).
    add_action('plugins_loaded', [$this, 'bootExtensions'], 25);

    // The cron action always fires; pro extension optionally listens via nimbus/license/refresh.
    add_action(NIMBUS_LICENSE_REFRESH_HOOK, [$this, 'onLicenseRefresh']);

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
    return $this->container->get('upload_handler', fn (Container $container): UploadHandler => new UploadHandler($this->settings(), $this->adapterFactory(), $this->logger(), $this->licenseManager()));
  }

  private function urlRewriter(): UrlRewriter
  {
    return $this->container->get('url_rewriter', fn (Container $container): UrlRewriter => new UrlRewriter($this->settings(), $this->adapterFactory(), $this->logger()));
  }

  private function settingsUi(): SettingsUi
  {
    return $this->container->get('settings_ui', fn (Container $container): SettingsUi => new SettingsUi($this->settings(), $this->logger(), $this->statistics(), $this->uploadHandler()));
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

  private function extensionRegistry(): Registry
  {
    return $this->container->get('extension_registry', static fn (Container $container): Registry => new Registry());
  }

  private function apiController(): ApiController
  {
    return $this->container->get('api_controller', fn (Container $container): ApiController => new ApiController(
      $this->settings(),
      $this->statistics(),
      $this->uploadHandler(),
      $this->integrationDiscovery(),
      $this->licenseManager(),
      $this->onboardingManager(),
      $this->logger(),
    ));
  }
}
