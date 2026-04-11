<?php
namespace Nimbus\Plugin\Pro\Bootstrap;

use Nimbus\Plugin\Bootstrap\Container;
use Nimbus\Plugin\Cache\PurgeManager;
use Nimbus\Plugin\Cli\Command;
use Nimbus\Plugin\Extension\Contracts\ProExtension;
use Nimbus\Plugin\Extension\Registry;
use Nimbus\Plugin\Jobs\BulkJobManager;
use Nimbus\Plugin\License\LicenseManager;
use Nimbus\Plugin\License\LicenseRefresher;
use Nimbus\Plugin\License\UpdateChecker;

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
  }
}
