<?php
/**
 * Autoloader for Nimbus Pro.
 *
 * Handles two namespaces:
 *   Nimbus\Plugin\Pro\  → nimbus-pro/src/          (classes unique to the pro plugin)
 *   Nimbus\Plugin\      → nimbus-pro/src/           (pro classes that share the free plugin namespace)
 *
 * The free plugin's autoloader already handles Nimbus\Plugin\ classes from
 * nimbus/src/. This loader handles only classes that live in nimbus-pro/src/,
 * such as Jobs\BulkJobManager, Cache\PurgeManager, Cli\Command, and the two
 * License helpers (LicenseRefresher, UpdateChecker).
 */

if (!defined('ABSPATH')) {
  exit;
}

spl_autoload_register(static function (string $class): void {
  // Nimbus\Plugin\Pro\ namespace — unique to the pro plugin.
  $proPrefix = 'Nimbus\\Plugin\\Pro\\';
  if (strncmp($class, $proPrefix, strlen($proPrefix)) === 0) {
    $relative = substr($class, strlen($proPrefix));
    $file = NIMBUS_PRO_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
      require_once $file;
    }
    return;
  }

  // Nimbus\Plugin\ namespace — classes that moved here from the free plugin.
  $freePrefix = 'Nimbus\\Plugin\\';
  if (strncmp($class, $freePrefix, strlen($freePrefix)) === 0) {
    $relative = substr($class, strlen($freePrefix));
    $file = NIMBUS_PRO_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
      require_once $file;
    }
  }
});
