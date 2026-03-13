<?php

if (!defined('ABSPATH')) {
  exit;
}

require_once MEDIA_OFFLOAD_PATH . 'src/Support/helpers.php';

spl_autoload_register(static function (string $class): void {
  $prefix = 'ArunPrakash\\MediaOffload\\';

  if (strpos($class, $prefix) !== 0) {
    return;
  }

  $relative_class = substr($class, strlen($prefix));
  if ($relative_class === '') {
    return;
  }

  $path = MEDIA_OFFLOAD_PATH . 'src/' . str_replace('\\', '/', $relative_class) . '.php';
  if (file_exists($path)) {
    require_once $path;
  }
});
