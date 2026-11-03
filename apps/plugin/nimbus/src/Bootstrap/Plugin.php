<?php
namespace Nimbus\Plugin\Bootstrap;

use Nimbus\Plugin\Admin\NetworkAdminPage;
use Nimbus\Plugin\Cache\PurgeManager;
use Nimbus\Plugin\Cli\Command;
use Nimbus\Plugin\Config\CredentialVault;
use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Delivery\UrlRewriter;
use Nimbus\Plugin\Integration\DatabaseIntegrationRewriter;
use Nimbus\Plugin\Integration\IntegrationDiscovery;
use Nimbus\Plugin\Integration\SmartSliderAssetRewriter;
use Nimbus\Plugin\Integration\WooCommerceIntegration;
use Nimbus\Plugin\Jobs\BulkJobManager;
use Nimbus\Plugin\Jobs\JobTable;
use Nimbus\Plugin\Media\AttachmentRepository;
use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Admin\SettingsUi;
use Nimbus\Plugin\Onboarding\ConflictDetector;
use Nimbus\Plugin\Onboarding\OnboardingManager;
use Nimbus\Plugin\Rest\ApiController;
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

  public function onPluginsLoaded(): void
  {
    $this->uploadHandler()->register();
  }

  /**
   * Boot every feature that used to be gated behind the Nimbus Pro add-on.
   * Now that Nimbus is a single, fully-free plugin, everything below is
   * registered unconditionally. Runs at plugins_loaded priority 25.
   */
  public function bootFeatures(): void
  {
    // ── REST API ────────────────────────────────────────────────────────────
    $this->container->get('api_controller', fn (Container $c): ApiController => new ApiController(
      $this->settings(),
      $this->statistics(),
      $this->uploadHandler(),
      $this->integrationDiscovery(),
      $this->onboardingManager(),
      $this->logger(),
    ))->register();

    // ── Integration discovery filters ──────────────────────────────────────
    add_filter('nimbus/integrations/summary', function (array $default): array {
      return $this->integrationDiscovery()->summary();
    });

    add_filter('nimbus/integrations/can_rewrite', function (bool $default, string $key): bool {
      return $this->integrationDiscovery()->canRewrite($key);
    }, 10, 2);

    add_filter('nimbus/integrations/definition', function ($default, string $key): ?array {
      return $this->integrationDiscovery()->definition($key);
    }, 10, 2);

    add_filter('nimbus/integrations/run_rewrite', function (array $default, string $key): array {
      return $this->integrationDiscovery()->runRewrite($key);
    }, 10, 2);

    // ── Jobs table ──────────────────────────────────────────────────────────
    JobTable::ensureTable();

    // ── Bulk job manager ────────────────────────────────────────────────────
    add_filter('nimbus/bulk_job_manager', function (): BulkJobManager {
      return $this->bulkJobManager();
    });

    add_filter('nimbus/jobs/state', function (): array {
      return $this->bulkJobManager()->state();
    });

    $this->bulkJobManager()->register();

    // ── Cache purge ─────────────────────────────────────────────────────────
    add_filter('nimbus/purge_manager', function (): PurgeManager {
      return $this->purgeManager();
    });

    $this->purgeManager()->register();

    // ── WP-CLI ──────────────────────────────────────────────────────────────
    $this->container->get('cli_command', fn (Container $c): Command => new Command(
      $this->uploadHandler(),
      $this->settings(),
      $this->adapterFactory(),
      $this->logger(),
    ))->register();

    // ── Selective offload filter ────────────────────────────────────────────
    add_filter('nimbus/upload/selective_offload_enabled', '__return_true');

    // ── Nightly batch offload ───────────────────────────────────────────────
    $this->uploadHandler()->registerNightlyBatch();

    // ── Multisite Network Admin panel ───────────────────────────────────────
    if (is_multisite()) {
      $this->container->get('network_admin_page', fn (Container $c): NetworkAdminPage => new NetworkAdminPage(
        $this->settings(),
      ))->register();
    }

    // ── WooCommerce protected file delivery ─────────────────────────────────
    add_action('woocommerce_loaded', function (): void {
      $this->container->get('woocommerce_integration', fn (Container $c): WooCommerceIntegration => new WooCommerceIntegration(
        $this->uploadHandler(),
        $this->settings(),
        $this->logger(),
      ))->register();
    });
  }

  private function bootInternal(): void
  {
    if ($this->booted) {
      return;
    }

    CredentialVault::register();
    DataMigration::register();
    DataMigration::run();

    add_action('init', [$this, 'onInit']);
    add_action('plugins_loaded', [$this, 'onPluginsLoaded'], 20);

    $this->settingsUi()->register();

    // Boot every previously-pro feature, unconditionally, after the free
    // plugin has initialised (priority 20).
    add_action('plugins_loaded', [$this, 'bootFeatures'], 25);

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
    return $this->container->get('upload_handler', fn (Container $container): UploadHandler => new UploadHandler($this->settings(), $this->adapterFactory(), $this->logger(), new AttachmentRepository()));
  }

  private function urlRewriter(): UrlRewriter
  {
    return $this->container->get('url_rewriter', fn (Container $container): UrlRewriter => new UrlRewriter($this->settings(), $this->adapterFactory(), $this->logger()));
  }

  private function settingsUi(): SettingsUi
  {
    return $this->container->get('settings_ui', fn (Container $container): SettingsUi => new SettingsUi($this->settings(), $this->logger(), $this->statistics(), $this->uploadHandler(), $this->onboardingManager()));
  }

  private function onboardingManager(): OnboardingManager
  {
    return $this->container->get('onboarding_manager', fn (Container $container): OnboardingManager => new OnboardingManager($this->settings(), new ConflictDetector()));
  }

  private function smartSliderAssetRewriter(): SmartSliderAssetRewriter
  {
    return $this->container->get('smartslider_asset_rewriter', fn (Container $c): SmartSliderAssetRewriter => new SmartSliderAssetRewriter($this->settings(), $this->logger()));
  }

  private function databaseIntegrationRewriter(): DatabaseIntegrationRewriter
  {
    return $this->container->get('database_integration_rewriter', fn (Container $c): DatabaseIntegrationRewriter => new DatabaseIntegrationRewriter($this->settings(), $this->logger()));
  }

  private function integrationDiscovery(): IntegrationDiscovery
  {
    return $this->container->get('integration_discovery', fn (Container $c): IntegrationDiscovery => new IntegrationDiscovery(
      $this->smartSliderAssetRewriter(),
      $this->databaseIntegrationRewriter(),
    ));
  }

  private function bulkJobManager(): BulkJobManager
  {
    return $this->container->get('bulk_job_manager', fn (Container $c): BulkJobManager => new BulkJobManager(
      $this->settings(),
      $this->uploadHandler(),
      $this->logger(),
    ));
  }

  private function purgeManager(): PurgeManager
  {
    return $this->container->get('purge_manager', fn (Container $c): PurgeManager => new PurgeManager(
      $this->settings(),
      $this->logger(),
    ));
  }
}
