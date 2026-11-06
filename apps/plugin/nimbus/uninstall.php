<?php
/**
 * Uninstall handler for the Plume plugin.
 *
 * Runs when a site administrator deletes the plugin from the Plugins screen.
 * This file is called directly by WordPress and must guard against direct
 * invocation itself.
 *
 * What is removed:
 * - All nimbus_* options (settings, license, logs, admin notices, onboarding,
 *   bulk job state, cache purge queue, data version, disabled integrations,
 *   and the legacy deactivation flag).
 * - The onboarding redirect transient.
 * - All _nimbus* postmeta keys (offload records, pending/claimed markers,
 *   missing-source markers, and the ignore flag).
 * - Scheduled cron events registered by the plugin.
 *
 * What is intentionally NOT removed:
 * - Files that were already uploaded to the remote bucket — those belong to
 *   the user's storage account, not to WordPress.
 * - The actual attachment posts themselves (they are WordPress content).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

// Wrapped in a closure so none of these loop variables leak into the global
// scope that this file executes in (WordPress include()s uninstall.php directly).
(static function (): void {
  // ── Option keys ──────────────────────────────────────────────────────────
  $nimbusOptionKeys = [
    'nimbus_settings',
    'nimbus_license',
    'nimbus_logs',
    'nimbus_admin_notices',
    'nimbus_onboarding',
    'nimbus_bulk_job',
    'nimbus_last_bulk_job',
    'nimbus_cache_purge_queue',
    'nimbus_data_version',
    'nimbus_disabled_integrations',
    'nimbus_deactivated_with_offloaded_urls',
    // Legacy keys written by earlier plugin versions (pre-rename).
    'pt_media_offload_settings',
    'pt_media_offload_logs',
    'pt_media_offload_admin_notices',
  ];

  foreach ($nimbusOptionKeys as $nimbusOptionKey) {
    delete_option($nimbusOptionKey);
  }

  // ── Transients ───────────────────────────────────────────────────────────
  delete_transient('nimbus_onboarding_redirect');
  delete_transient('nimbus_reactivation_url_warning');
  delete_transient('nimbus_offloaded_count_cache');

  // ── Cron events ──────────────────────────────────────────────────────────
  $nimbusCronHooks = [
    'nimbus_nightly_batch',
    'nimbus_nightly_worker',
    'nimbus_cleanup_attachment',
    'nimbus_cache_purge_worker',
    'nimbus_license_refresh',
    'nimbus_offload_attachment',
    'nimbus_rest_process_pending',
    'nimbus_rest_process_single',
    'nimbus_data_migration_batch',
  ];

  foreach ($nimbusCronHooks as $nimbusCronHook) {
    wp_clear_scheduled_hook($nimbusCronHook);
  }

  // ── Postmeta ─────────────────────────────────────────────────────────────
  // Remove offload tracking metadata from the postmeta table.
  // We use a LIKE query so that future sub-keys (e.g. _nimbus_foo) are also
  // caught without having to enumerate every constant.
  global $wpdb;

  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot cleanup on plugin uninstall; nothing to cache.
  $wpdb->query(
    $wpdb->prepare(
      "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
      $wpdb->esc_like('_nimbus') . '%'
    )
  );
})();
