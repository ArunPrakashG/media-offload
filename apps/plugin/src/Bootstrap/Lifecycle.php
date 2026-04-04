<?php
namespace Nimbus\Plugin\Bootstrap;

if (!defined('ABSPATH')) {
  exit;
}

final class Lifecycle
{
  public static function activate(): void
  {
    if (get_option(NIMBUS_ONBOARDING_OPTION_KEY, null) === null) {
      add_option(NIMBUS_ONBOARDING_OPTION_KEY, [
        'completed' => false,
        'completed_at' => 0,
      ], '', false);
    }

    add_option(NIMBUS_ONBOARDING_REDIRECT_OPTION, '1', '', false);

    DataMigration::run();

    do_action('nimbus_activate');
  }

  public static function deactivate(): void
  {
    do_action('nimbus_deactivate');

    $hook = NIMBUS_NIGHTLY_BATCH_HOOK;

    if (function_exists('wp_clear_scheduled_hook')) {
      wp_clear_scheduled_hook($hook);
      wp_clear_scheduled_hook(NIMBUS_NIGHTLY_WORKER_HOOK);
      wp_clear_scheduled_hook(NIMBUS_CLEANUP_HOOK);
      wp_clear_scheduled_hook(NIMBUS_CACHE_PURGE_HOOK);
      wp_clear_scheduled_hook('nimbus_data_migration_batch');
      delete_option(NIMBUS_CACHE_PURGE_QUEUE_OPTION_KEY);
      return;
    }

    $next = wp_next_scheduled($hook);
    while ($next) {
      wp_unschedule_event($next, $hook);
      $next = wp_next_scheduled($hook);
    }

    $migrationNext = wp_next_scheduled('nimbus_data_migration_batch');
    while ($migrationNext) {
      wp_unschedule_event($migrationNext, 'nimbus_data_migration_batch');
      $migrationNext = wp_next_scheduled('nimbus_data_migration_batch');
    }

    $purgeNext = wp_next_scheduled(NIMBUS_CACHE_PURGE_HOOK);
    while ($purgeNext) {
      wp_unschedule_event($purgeNext, NIMBUS_CACHE_PURGE_HOOK);
      $purgeNext = wp_next_scheduled(NIMBUS_CACHE_PURGE_HOOK);
    }

    delete_option(NIMBUS_CACHE_PURGE_QUEUE_OPTION_KEY);
  }
}
