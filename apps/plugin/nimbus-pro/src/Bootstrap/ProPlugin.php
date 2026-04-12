<?php
namespace Nimbus\Plugin\Pro\Bootstrap;

use Nimbus\Plugin\Bootstrap\Container;
use Nimbus\Plugin\Cache\PurgeManager;
use Nimbus\Plugin\Cli\Command;
use Nimbus\Plugin\Extension\Contracts\ProExtension;
use Nimbus\Plugin\Extension\Registry;
use Nimbus\Plugin\Integration\WooCommerceIntegration;
use Nimbus\Plugin\Jobs\BulkJobManager;
use Nimbus\Plugin\Jobs\JobTable;
use Nimbus\Plugin\License\LicenseManager;
use Nimbus\Plugin\License\LicenseRefresher;
use Nimbus\Plugin\License\UpdateChecker;
use Nimbus\Plugin\Admin\NetworkAdminPage;
use Nimbus\Plugin\Integration\DatabaseIntegrationRewriter;
use Nimbus\Plugin\Integration\IntegrationDiscovery;
use Nimbus\Plugin\Integration\SmartSliderAssetRewriter;
use Nimbus\Plugin\Rest\ApiController;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * The single entry-point for all Nimbus Pro features.
 *
 * This class is registered with the free plugin's Extension Registry from
 * nimbus-pro.php and is booted at plugins_loaded priority 25. All pro classes
 * are resolved lazily through the shared service Container so that they can
 * reference free plugin services (Settings, Logger, LicenseManager, etc.).
 */
final class ProPlugin implements ProExtension
{
  public function register(Registry $registry, Container $container): void
  {
    // Nothing to do here — we already registered ourselves in nimbus-pro.php
    // via the nimbus/extensions/register action. Use this hook for any early
    // Container factory registrations that must happen before boot().
  }

  public function boot(Container $container, LicenseManager $lm): void
  {
    // ── Shared lazy factories for integration classes ──────────────────────────
    $smartSlider = fn (Container $c): SmartSliderAssetRewriter => new SmartSliderAssetRewriter($c->get('settings'), $c->get('logger'));
    $dbRewriter  = fn (Container $c): DatabaseIntegrationRewriter => new DatabaseIntegrationRewriter($c->get('settings'), $c->get('logger'));
    $discovery   = fn (Container $c): IntegrationDiscovery => new IntegrationDiscovery(
      $c->get('smartslider_asset_rewriter', $smartSlider),
      $c->get('database_integration_rewriter', $dbRewriter),
    );

    // ── REST API ──────────────────────────────────────────────────────────────
    // All Nimbus REST endpoints are a pro feature. Registered unconditionally
    // when the pro plugin is active; individual handlers enforce their own
    // feature/licence checks internally.
    $container->get('api_controller', fn (Container $c): ApiController => new ApiController(
      $c->get('settings'),
      $c->get('statistics'),
      $c->get('upload_handler'),
      $c->get('integration_discovery', $discovery),
      $lm,
      $c->get('onboarding_manager'),
      $c->get('logger'),
    ))->register();

    // ── Integration discovery filters ─────────────────────────────────────────
    // Supplies integration data to the free plugin's admin UI and form handlers.
    add_filter('nimbus/integrations/summary', function (array $default) use ($container, $discovery): array {
      return $container->get('integration_discovery', $discovery)->summary();
    });

    add_filter('nimbus/integrations/can_rewrite', function (bool $default, string $key) use ($container, $discovery): bool {
      return $container->get('integration_discovery', $discovery)->canRewrite($key);
    }, 10, 2);

    add_filter('nimbus/integrations/definition', function ($default, string $key) use ($container, $discovery): ?array {
      return $container->get('integration_discovery', $discovery)->definition($key);
    }, 10, 2);

    add_filter('nimbus/integrations/run_rewrite', function (array $default, string $key) use ($container, $discovery): array {
      return $container->get('integration_discovery', $discovery)->runRewrite($key);
    }, 10, 2);

    // ── Jobs table ─────────────────────────────────────────────────────────────
    // Creates/upgrades wp_nimbus_jobs on every boot (dbDelta is idempotent).
    JobTable::ensureTable();

    // ── Update checker ────────────────────────────────────────────────────────
    // Hooks into the WordPress update transient to inject Nimbus Pro update info.
    $container->get('update_checker', static function (Container $c) use ($lm): UpdateChecker {
      $checker = new UpdateChecker($lm);
      return $checker;
    })->register();

    // ── License refresh cron ──────────────────────────────────────────────────
    // Schedule the cron if not already queued; the free plugin fires the hook
    // and delegates to this action.
    if (!wp_next_scheduled(NIMBUS_LICENSE_REFRESH_HOOK)) {
      wp_schedule_event(time(), 'twicedaily', NIMBUS_LICENSE_REFRESH_HOOK);
    }
    add_action('nimbus/license/refresh', static function () use ($container, $lm): void {
      $container->get('license_refresher', static fn (Container $c) => new LicenseRefresher($lm))
        ->maybeRefresh();
    });

    // ── Feature activations (license-gated) ───────────────────────────────────
    if ($lm->isActive() && $lm->hasFeature('cache_purge')) {
      $container->get('purge_manager', fn (Container $c): PurgeManager => new PurgeManager(
        $c->get('settings'),
        $c->get('logger'),
      ))->register();
    }

    if ($lm->isActive() && $lm->hasFeature('nightly_batch')) {
      $container->get('upload_handler')->registerNightlyBatch();
    }

    if ($lm->isActive() && $lm->hasFeature('wpcli')) {
      $container->get('cli_command', fn (Container $c): Command => new Command(
        $c->get('upload_handler'),
        $c->get('settings'),
        $c->get('adapter_factory'),
        $c->get('logger'),
      ))->register();
    }

    if ($lm->isActive() && $lm->hasFeature('bulk_migration')) {
      $container->get('bulk_job_manager', fn (Container $c): BulkJobManager => new BulkJobManager(
        $c->get('settings'),
        $c->get('upload_handler'),
        $c->get('logger'),
        $lm,
      ))->register();
    }

    // ── Selective offload filter ───────────────────────────────────────────────
    // Tells the free plugin's UploadHandler to apply MIME/size exclusion rules.
    if ($lm->isActive() && $lm->hasFeature('selective_offload')) {
      add_filter('nimbus/upload/selective_offload_enabled', '__return_true');
    }

    // ── Runtime service filters ────────────────────────────────────────────────
    // Admin page and REST API obtain BulkJobManager via these filters so they
    // don't need to know about the pro plugin at compile-time.
    add_filter('nimbus/bulk_job_manager', function () use ($container, $lm): ?BulkJobManager {
      if (!$lm->isActive() || !$lm->hasFeature('bulk_migration')) {
        return null;
      }
      return $container->get('bulk_job_manager', fn (Container $c): BulkJobManager => new BulkJobManager(
        $c->get('settings'),
        $c->get('upload_handler'),
        $c->get('logger'),
        $lm,
      ));
    });

    add_filter('nimbus/jobs/state', function () use ($container, $lm): ?array {
      if (!$lm->isActive() || !$lm->hasFeature('bulk_migration')) {
        return null;
      }
      return $container->get('bulk_job_manager', fn (Container $c): BulkJobManager => new BulkJobManager(
        $c->get('settings'),
        $c->get('upload_handler'),
        $c->get('logger'),
        $lm,
      ))->state();
    });

    add_filter('nimbus/purge_manager', function () use ($container, $lm): ?PurgeManager {
      if (!$lm->isActive() || !$lm->hasFeature('cache_purge')) {
        return null;
      }
      return $container->get('purge_manager', fn (Container $c): PurgeManager => new PurgeManager(
        $c->get('settings'),
        $c->get('logger'),
      ));
    });

    // ── Multisite Network Admin Panel ─────────────────────────────────────────
    // Registers a top-level Network Admin menu with per-site offload stats.
    // Gated on the `network_admin` feature (Agency plan and above) so that
    // Personal and Pro license holders on multisite don't see the panel.
    if ($lm->isActive() && is_multisite() && $lm->hasFeature('network_admin')) {
      $container->get('network_admin_page', fn (Container $c): NetworkAdminPage => new NetworkAdminPage(
        $c->get('settings'),
        $lm,
      ))->register();
    }

    // ── WooCommerce protected file delivery ───────────────────────────────────
    // Replaces proxied WooCommerce download URLs with provider-signed URLs so
    // buyers download directly from storage, bypassing the web server.
    if ($lm->isActive()) {
      add_action('woocommerce_loaded', static function () use ($container, $lm): void {
        $container->get('woocommerce_integration', fn (Container $c): WooCommerceIntegration => new WooCommerceIntegration(
          $c->get('upload_handler'),
          $c->get('settings'),
          $c->get('logger'),
        ))->register();
      });
    }

    // ── Pro status reporter ───────────────────────────────────────────────────
    // The free plugin reads this filter on the License tab to show whether the
    // pro add-on is detected and which features are currently active.
    add_filter(NIMBUS_FILTER_PRO_STATUS, static function (array $status) use ($lm): array {
      return [
        'loaded'   => true,
        'version'  => NIMBUS_PRO_VERSION,
        'features' => $lm->features(),
      ];
    });
  }
}
