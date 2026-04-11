<?php
/**
 * Plugin Name: Nimbus Pro
 * Plugin URI:  https://getnimbus.io
 * Description: Premium add-on for Nimbus – unlocks bulk migration, cache purge, WP-CLI, automatic updates, and more.
 * Version: 0.1.0
 * Author: Arun Prakash
 * Text Domain: nimbus-pro
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Requires Plugins: nimbus
 */

if (!defined('ABSPATH')) {
  exit;
}

// Refuse to load if the free Nimbus plugin is not active.
if (!defined('NIMBUS_VERSION')) {
  add_action('admin_notices', static function (): void {
    echo '<div class="notice notice-error"><p><strong>Nimbus Pro</strong> requires the free <strong>Nimbus</strong> plugin to be installed and active.</p></div>';
  });
  return;
}

define('NIMBUS_PRO_VERSION', '0.1.0');
define('NIMBUS_PRO_FILE', __FILE__);
define('NIMBUS_PRO_PATH', plugin_dir_path(__FILE__));

// Register the pro autoloader for Nimbus\Plugin\ classes that live in this package.
require_once NIMBUS_PRO_PATH . 'src/autoload.php';

// Self-register with the free plugin's extension registry.
// The free plugin fires `nimbus/extensions/register` at plugins_loaded priority 0
// (all plugin files are loaded by then), so this hook fires before bootAll() at priority 25.
add_action('nimbus/extensions/register', static function (\Nimbus\Plugin\Extension\Registry $registry): void {
  $registry->register(new \Nimbus\Plugin\Pro\Bootstrap\ProPlugin());
});
