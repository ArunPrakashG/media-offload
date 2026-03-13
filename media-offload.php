<?php
/**
 * Plugin Name: Media Offload
 * Description: Offloads WordPress media uploads to Amazon S3, Cloudflare R2, and S3-compatible storage with URL rewriting.
 * Version: 0.1.4
 * Author: Arun Prakash
 * Text Domain: media-offload
 */

if (!defined('ABSPATH')) {
  exit;
}

define('MEDIA_OFFLOAD_VERSION', '0.1.4');
define('MEDIA_OFFLOAD_FILE', __FILE__);
define('MEDIA_OFFLOAD_PATH', plugin_dir_path(__FILE__));
define('MEDIA_OFFLOAD_URL', plugin_dir_url(__FILE__));
define('MEDIA_OFFLOAD_TEXT_DOMAIN', 'media-offload');
define('MEDIA_OFFLOAD_SLUG', 'media-offload');
define('MEDIA_OFFLOAD_SETTINGS_GROUP', 'media_offload');
define('MEDIA_OFFLOAD_SCRIPT_HANDLE', 'media-offload-admin');
define('MEDIA_OFFLOAD_SCRIPT_OBJECT', 'mediaOffloadAdmin');
define('MEDIA_OFFLOAD_AJAX_TEST_CONNECTION', 'media_offload_test_connection');
define('MEDIA_OFFLOAD_ADMIN_ACTION_CLEAR_LOGS', 'media_offload_clear_logs');
define('MEDIA_OFFLOAD_ADMIN_ACTION_RUN_URL_UPDATES', 'media_offload_run_url_updates');
define('MEDIA_OFFLOAD_ADMIN_ACTION_COMPLETE_ONBOARDING', 'media_offload_complete_onboarding');
define('MEDIA_OFFLOAD_ADMIN_ACTION_REWRITE_INTEGRATION', 'media_offload_rewrite_integration');
define('MEDIA_OFFLOAD_ADMIN_ACTION_RESUME_MIGRATION', 'media_offload_resume_migration');
define('MEDIA_OFFLOAD_ADMIN_ACTION_ATTACHMENT_OFFLOAD', 'media_offload_attachment_offload');
define('MEDIA_OFFLOAD_ADMIN_ACTION_ATTACHMENT_RESTORE', 'media_offload_attachment_restore');
define('MEDIA_OFFLOAD_ADMIN_ACTION_ATTACHMENT_RETRY', 'media_offload_attachment_retry');
define('MEDIA_OFFLOAD_REST_NAMESPACE', 'media-offload/v1');
define('MEDIA_OFFLOAD_LICENSE_OPTION_KEY', 'media_offload_license');
define('MEDIA_OFFLOAD_ONBOARDING_OPTION_KEY', 'media_offload_onboarding');
define('MEDIA_OFFLOAD_ONBOARDING_REDIRECT_OPTION', 'media_offload_onboarding_redirect');
define('MEDIA_OFFLOAD_BULK_JOB_OPTION_KEY', 'media_offload_bulk_job');
define('MEDIA_OFFLOAD_BULK_JOB_LAST_OPTION_KEY', 'media_offload_last_bulk_job');
define('MEDIA_OFFLOAD_OPTION_KEY', 'media_offload_settings');
define('MEDIA_OFFLOAD_META_KEY', '_media_offload');
define('MEDIA_OFFLOAD_IGNORE_META_KEY', '_media_offload_ignore');
define('MEDIA_OFFLOAD_LOG_OPTION_KEY', 'media_offload_logs');
define('MEDIA_OFFLOAD_ADMIN_NOTICES_OPTION_KEY', 'media_offload_admin_notices');
define('MEDIA_OFFLOAD_DATA_VERSION_OPTION_KEY', 'media_offload_data_version');
define('MEDIA_OFFLOAD_PENDING_AT_META_KEY', '_media_offload_pending_at');
define('MEDIA_OFFLOAD_CLAIMED_BY_META_KEY', '_media_offload_claimed_by');
define('MEDIA_OFFLOAD_CLAIMED_AT_META_KEY', '_media_offload_claimed_at');
define('MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY', '_media_offload_missing_source_at');
define('MEDIA_OFFLOAD_NIGHTLY_BATCH_HOOK', 'media_offload_nightly_batch');
define('MEDIA_OFFLOAD_NIGHTLY_WORKER_HOOK', 'media_offload_nightly_worker');
define('MEDIA_OFFLOAD_CLEANUP_HOOK', 'media_offload_cleanup_attachment');

if (file_exists(MEDIA_OFFLOAD_PATH . 'vendor/autoload.php')) {
  require_once MEDIA_OFFLOAD_PATH . 'vendor/autoload.php';
}

require_once MEDIA_OFFLOAD_PATH . 'src/autoload.php';

register_activation_hook(__FILE__, ['ArunPrakash\\MediaOffload\\Bootstrap\\Lifecycle', 'activate']);
register_deactivation_hook(__FILE__, ['ArunPrakash\\MediaOffload\\Bootstrap\\Lifecycle', 'deactivate']);

ArunPrakash\MediaOffload\Bootstrap\Plugin::boot(MEDIA_OFFLOAD_FILE);
