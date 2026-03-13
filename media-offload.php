<?php
/**
 * Plugin Name: Arun Prakash Media Offload
 * Description: Offloads WordPress media uploads to Amazon S3, Cloudflare R2, and S3-compatible storage with URL rewriting.
 * Version: 0.1.3
 * Author: Arun Prakash
 * Text Domain: pt-media-offload
 */

if (!defined('ABSPATH')) {
  exit;
}

define('PT_MEDIA_OFFLOAD_VERSION', '0.1.3');
define('PT_MEDIA_OFFLOAD_FILE', __FILE__);
define('PT_MEDIA_OFFLOAD_PATH', plugin_dir_path(__FILE__));
define('PT_MEDIA_OFFLOAD_URL', plugin_dir_url(__FILE__));

define('PT_MEDIA_OFFLOAD_OPTION_KEY', 'pt_media_offload_settings');
define('PT_MEDIA_OFFLOAD_META_KEY', '_pt_media_offload');
define('PT_MEDIA_OFFLOAD_IGNORE_META_KEY', '_pt_media_offload_ignore');
define('PT_MEDIA_OFFLOAD_LOG_OPTION_KEY', 'pt_media_offload_logs');

register_deactivation_hook(__FILE__, static function (): void {
  $hook = 'pt_media_offload_nightly_batch';

  if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook($hook);
    return;
  }

  $next = wp_next_scheduled($hook);
  while ($next) {
    wp_unschedule_event($next, $hook);
    $next = wp_next_scheduled($hook);
  }
});

if (file_exists(PT_MEDIA_OFFLOAD_PATH . 'vendor/autoload.php')) {
    require_once PT_MEDIA_OFFLOAD_PATH . 'vendor/autoload.php';
}

require_once PT_MEDIA_OFFLOAD_PATH . 'includes/functions-helpers.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-logger.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-statistics.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-settings.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-settings-ui.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-storage-adapter.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-s3-storage-adapter.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-local-storage-adapter.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-storage-adapter-factory.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-upload-handler.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-url-rewriter.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-cli-command.php';
require_once PT_MEDIA_OFFLOAD_PATH . 'includes/class-plugin.php';

Pathanamthitta\MediaOffload\Plugin::instance()->boot();
