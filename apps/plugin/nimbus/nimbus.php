<?php
/**
 * Plugin Name: Arun Prakash Media Offload
 * Plugin URI: https://usenimbus.dev
 * Description: Nimbus offloads WordPress media to Amazon S3, Cloudflare R2, and S3-compatible storage with automatic URL rewriting.
 * Version: 0.3.4
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Arun Prakash
 * Author URI: https://arunprakashg.com
 * Text Domain: arun-prakash-media-offload
 * License: MIT
 * License URI: https://opensource.org/license/mit
 */

if (!defined('ABSPATH')) {
  exit;
}

// Copies installed before the rename live in another folder (usually plugins/nimbus)
// and share these constants, settings and hooks. Stay idle while one is active.
$nimbus_active_plugins = (array) get_option('active_plugins', []);
if (is_multisite()) {
  $nimbus_active_plugins = array_merge($nimbus_active_plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
}
foreach ($nimbus_active_plugins as $nimbus_active_plugin) {
  if (!is_string($nimbus_active_plugin) || basename($nimbus_active_plugin) !== 'nimbus.php' || $nimbus_active_plugin === plugin_basename(__FILE__) || !is_readable(WP_PLUGIN_DIR . '/' . $nimbus_active_plugin)) {
    continue;
  }
  $nimbus_other_copy = get_file_data(WP_PLUGIN_DIR . '/' . $nimbus_active_plugin, ['uri' => 'Plugin URI']);
  if (defined('NIMBUS_FILE') || $nimbus_other_copy['uri'] === 'https://usenimbus.dev') {
    $nimbus_waiting_notice = static function (): void {
      if (!current_user_can('activate_plugins')) {
        return;
      }
      printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html__('Arun Prakash Media Offload is waiting for another copy of this plugin (Nimbus or Nimbus Media Offload) that is still active. Deactivate that copy on the Plugins screen and this one takes over with the same settings. Then remove the old plugin folder over SFTP: its Delete button would also erase the settings both copies share.', 'arun-prakash-media-offload')
      );
    };
    add_action('admin_notices', $nimbus_waiting_notice);
    add_action('network_admin_notices', $nimbus_waiting_notice);
    return;
  }
}
unset($nimbus_active_plugins, $nimbus_active_plugin);

define('NIMBUS_VERSION', '0.3.4');
define('NIMBUS_FILE', __FILE__);
define('NIMBUS_PATH', plugin_dir_path(__FILE__));
define('NIMBUS_URL', plugin_dir_url(__FILE__));
define('NIMBUS_TEXT_DOMAIN', 'arun-prakash-media-offload');
define('NIMBUS_SLUG', 'nimbus');
define('NIMBUS_SETTINGS_GROUP', 'nimbus');
define('NIMBUS_SCRIPT_HANDLE', 'nimbus-admin');
define('NIMBUS_SCRIPT_OBJECT', 'nimbusAdmin');
define('NIMBUS_AJAX_TEST_CONNECTION', 'nimbus_test_connection');
define('NIMBUS_AJAX_ONBOARDING_SAVE_STEP', 'nimbus_onboarding_save_step');
define('NIMBUS_AJAX_QUICK_CONNECT', 'nimbus_quick_connect');
define('NIMBUS_ADMIN_ACTION_CLEAR_LOGS', 'nimbus_clear_logs');
define('NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES', 'nimbus_run_url_updates');
define('NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING', 'nimbus_complete_onboarding');
define('NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION', 'nimbus_rewrite_integration');
define('NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION', 'nimbus_toggle_integration');
define('NIMBUS_ADMIN_ACTION_RESUME_MIGRATION', 'nimbus_resume_migration');
define('NIMBUS_DISABLED_INTEGRATIONS_KEY', 'nimbus_disabled_integrations');
define('NIMBUS_ADMIN_ACTION_ATTACHMENT_OFFLOAD', 'nimbus_attachment_offload');
define('NIMBUS_ADMIN_ACTION_ATTACHMENT_RESTORE', 'nimbus_attachment_restore');
define('NIMBUS_ADMIN_ACTION_ATTACHMENT_RETRY', 'nimbus_attachment_retry');
define('NIMBUS_ADMIN_ACTION_EXPORT_CONFIG', 'nimbus_export_config');
define('NIMBUS_ADMIN_ACTION_IMPORT_CONFIG', 'nimbus_import_config');
// Extension point filter names — use these constants in add_filter() calls.
define('NIMBUS_FILTER_PROVIDER_PROFILES', 'nimbus_provider_profiles');
define('NIMBUS_FILTER_ADAPTER_FACTORIES', 'nimbus_storage_adapter_factories');
define('NIMBUS_REST_NAMESPACE', 'nimbus/v1');
define('NIMBUS_ONBOARDING_OPTION_KEY', 'nimbus_onboarding');
define('NIMBUS_ONBOARDING_REDIRECT_OPTION', 'nimbus_onboarding_redirect');
define('NIMBUS_BULK_JOB_OPTION_KEY', 'nimbus_bulk_job');
define('NIMBUS_BULK_JOB_LAST_OPTION_KEY', 'nimbus_last_bulk_job');
define('NIMBUS_OPTION_KEY', 'nimbus_settings');
define('NIMBUS_META_KEY', '_nimbus');
define('NIMBUS_IGNORE_META_KEY', '_nimbus_ignore');
define('NIMBUS_LOG_OPTION_KEY', 'nimbus_logs');
define('NIMBUS_ADMIN_NOTICES_OPTION_KEY', 'nimbus_admin_notices');
define('NIMBUS_DATA_VERSION_OPTION_KEY', 'nimbus_data_version');
define('NIMBUS_CACHE_PURGE_QUEUE_OPTION_KEY', 'nimbus_cache_purge_queue');
define('NIMBUS_PENDING_AT_META_KEY', '_nimbus_pending_at');
define('NIMBUS_CLAIMED_BY_META_KEY', '_nimbus_claimed_by');
define('NIMBUS_CLAIMED_AT_META_KEY', '_nimbus_claimed_at');
define('NIMBUS_MISSING_SOURCE_AT_META_KEY', '_nimbus_missing_source_at');
define('NIMBUS_NIGHTLY_BATCH_HOOK', 'nimbus_nightly_batch');
define('NIMBUS_NIGHTLY_WORKER_HOOK', 'nimbus_nightly_worker');
define('NIMBUS_CLEANUP_HOOK', 'nimbus_cleanup_attachment');
define('NIMBUS_CACHE_PURGE_HOOK', 'nimbus_cache_purge_worker');
// Async single-attachment offload job fired immediately after upload metadata is saved.
define('NIMBUS_OFFLOAD_JOB_HOOK', 'nimbus_offload_attachment');

if (file_exists(NIMBUS_PATH . 'vendor/autoload.php')) {
  require_once NIMBUS_PATH . 'vendor/autoload.php';
}

require_once NIMBUS_PATH . 'src/autoload.php';

register_activation_hook(__FILE__, ['Nimbus\\Plugin\\Bootstrap\\Lifecycle', 'activate']);
register_deactivation_hook(__FILE__, ['Nimbus\\Plugin\\Bootstrap\\Lifecycle', 'deactivate']);

Nimbus\Plugin\Bootstrap\Plugin::boot(NIMBUS_FILE);
