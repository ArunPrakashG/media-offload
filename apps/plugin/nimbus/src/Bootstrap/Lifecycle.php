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

    // If the plugin was previously deactivated while DB-rewritten URLs existed,
    // queue an admin notice so the admin knows to verify URL integrity.
    $prevOffloaded = get_option('nimbus_deactivated_with_offloaded_urls', 0);
    if ((int) $prevOffloaded > 0) {
      set_transient('nimbus_reactivation_url_warning', (int) $prevOffloaded, DAY_IN_SECONDS);
      delete_option('nimbus_deactivated_with_offloaded_urls');
    }

    DataMigration::run();

    do_action('nimbus_activate');
  }

  public static function deactivate(): void
  {
    do_action('nimbus_deactivate');

    if (function_exists('wp_clear_scheduled_hook')) {
      wp_clear_scheduled_hook(NIMBUS_NIGHTLY_BATCH_HOOK);
      wp_clear_scheduled_hook(NIMBUS_NIGHTLY_WORKER_HOOK);
      wp_clear_scheduled_hook(NIMBUS_CLEANUP_HOOK);
      wp_clear_scheduled_hook(NIMBUS_CACHE_PURGE_HOOK);
      wp_clear_scheduled_hook(NIMBUS_LICENSE_REFRESH_HOOK);
      wp_clear_scheduled_hook(NIMBUS_OFFLOAD_JOB_HOOK);
      wp_clear_scheduled_hook('nimbus_data_migration_batch');
      delete_option(NIMBUS_CACHE_PURGE_QUEUE_OPTION_KEY);

      // Record that the plugin was deactivated while DB-rewritten URLs exist so
      // the re-activation notice can warn the admin to check URL integrity.
      global $wpdb;
      $offloaded = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        NIMBUS_META_KEY
      ));
      if ($offloaded > 0) {
        update_option('nimbus_deactivated_with_offloaded_urls', $offloaded, false);
      }

      return;
    }

    $hook = NIMBUS_NIGHTLY_BATCH_HOOK;
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
