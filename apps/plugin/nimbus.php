<?php
/**
 * Plugin Name: Nimbus
 * Description: Nimbus offloads WordPress media to Amazon S3, Cloudflare R2, and S3-compatible storage with automatic URL rewriting.
 * Version: 0.1.4
 * Author: Arun Prakash
 * Text Domain: nimbus
 */

if (!defined('ABSPATH')) {
  exit;
}

define('NIMBUS_VERSION', '0.1.4');
define('NIMBUS_FILE', __FILE__);
define('NIMBUS_PATH', plugin_dir_path(__FILE__));
define('NIMBUS_URL', plugin_dir_url(__FILE__));
define('NIMBUS_TEXT_DOMAIN', 'nimbus');
define('NIMBUS_SLUG', 'nimbus');
define('NIMBUS_SETTINGS_GROUP', 'nimbus');
define('NIMBUS_SCRIPT_HANDLE', 'nimbus-admin');
define('NIMBUS_SCRIPT_OBJECT', 'nimbusAdmin');
define('NIMBUS_AJAX_TEST_CONNECTION', 'nimbus_test_connection');
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
define('NIMBUS_REST_NAMESPACE', 'nimbus/v1');
define('NIMBUS_LICENSE_OPTION_KEY', 'nimbus_license');
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

// ── License backend ────────────────────────────────────────────────────────
// Override in wp-config.php for staging / local dev.
if (!defined('NIMBUS_BACKEND_URL')) {
  define('NIMBUS_BACKEND_URL', 'https://api.usenimbus.dev');
}

// Ed25519 public key (base64, standard encoding) used to verify activation
// tokens offline. Bundled here so the plugin works out of the box.
// Override via wp-config.php only when pointing at a staging/custom backend.
if (!defined('NIMBUS_LICENSE_PUBLIC_KEY')) {
  define('NIMBUS_LICENSE_PUBLIC_KEY', 'YVhjWaVmotVf2zQMC/NxlkFMElG8OVmrEUGY5c367gc=');
}

// Admin-post action constants for license management.
define('NIMBUS_ADMIN_ACTION_LICENSE_ACTIVATE', 'nimbus_license_activate');
define('NIMBUS_ADMIN_ACTION_LICENSE_ACTIVATE_FREE', 'nimbus_license_activate_free');
define('NIMBUS_ADMIN_ACTION_LICENSE_DEACTIVATE_SITE', 'nimbus_license_deactivate_site');

if (file_exists(NIMBUS_PATH . 'vendor/autoload.php')) {
  require_once NIMBUS_PATH . 'vendor/autoload.php';
}

require_once NIMBUS_PATH . 'src/autoload.php';

register_activation_hook(__FILE__, ['Nimbus\\Plugin\\Bootstrap\\Lifecycle', 'activate']);
register_deactivation_hook(__FILE__, ['Nimbus\\Plugin\\Bootstrap\\Lifecycle', 'deactivate']);

Nimbus\Plugin\Bootstrap\Plugin::boot(NIMBUS_FILE);
