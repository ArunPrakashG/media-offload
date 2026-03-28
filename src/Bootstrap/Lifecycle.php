<?php
namespace ArunPrakash\MediaOffload\Bootstrap;

if (!defined('ABSPATH')) {
  exit;
}

final class Lifecycle
{
  public static function activate(): void
  {
    if (get_option(MEDIA_OFFLOAD_ONBOARDING_OPTION_KEY, null) === null) {
      add_option(MEDIA_OFFLOAD_ONBOARDING_OPTION_KEY, [
        'completed' => false,
        'completed_at' => 0,
      ], '', false);
    }

    add_option(MEDIA_OFFLOAD_ONBOARDING_REDIRECT_OPTION, '1', '', false);

    DataMigration::run();

    do_action('media_offload_activate');
  }

  public static function deactivate(): void
  {
    do_action('media_offload_deactivate');

    $hook = MEDIA_OFFLOAD_NIGHTLY_BATCH_HOOK;

    if (function_exists('wp_clear_scheduled_hook')) {
      wp_clear_scheduled_hook($hook);
      wp_clear_scheduled_hook(MEDIA_OFFLOAD_NIGHTLY_WORKER_HOOK);
      wp_clear_scheduled_hook(MEDIA_OFFLOAD_CLEANUP_HOOK);
      wp_clear_scheduled_hook(MEDIA_OFFLOAD_CACHE_PURGE_HOOK);
      wp_clear_scheduled_hook('media_offload_data_migration_batch');
      delete_option(MEDIA_OFFLOAD_CACHE_PURGE_QUEUE_OPTION_KEY);
      return;
    }

    $next = wp_next_scheduled($hook);
    while ($next) {
      wp_unschedule_event($next, $hook);
      $next = wp_next_scheduled($hook);
    }

    $migrationNext = wp_next_scheduled('media_offload_data_migration_batch');
    while ($migrationNext) {
      wp_unschedule_event($migrationNext, 'media_offload_data_migration_batch');
      $migrationNext = wp_next_scheduled('media_offload_data_migration_batch');
    }

    $purgeNext = wp_next_scheduled(MEDIA_OFFLOAD_CACHE_PURGE_HOOK);
    while ($purgeNext) {
      wp_unschedule_event($purgeNext, MEDIA_OFFLOAD_CACHE_PURGE_HOOK);
      $purgeNext = wp_next_scheduled(MEDIA_OFFLOAD_CACHE_PURGE_HOOK);
    }

    delete_option(MEDIA_OFFLOAD_CACHE_PURGE_QUEUE_OPTION_KEY);
  }
}
