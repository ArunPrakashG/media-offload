<?php
namespace Pathanamthitta\MediaOffload;

use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Build a storage key from relative path and prefix.
 */
function build_storage_key(string $relative_path, string $prefix = ''): string
{
  $normalized = ltrim(str_replace('\\', '/', $relative_path), '/');
  $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

  if ($normalized !== '') {
    $parts = explode('/', $normalized);
    $parts = array_map(__NAMESPACE__ . '\\normalize_storage_key_segment', $parts);
    $parts = array_values(array_filter($parts, static function (string $segment): bool {
      return $segment !== '';
    }));
    $normalized = implode('/', $parts);
  }

  $prefix = trim($prefix, '/');
  return $prefix !== '' ? $prefix . '/' . $normalized : $normalized;
}

/**
 * Normalize a storage key path segment for broad S3-compatible signing support.
 */
function normalize_storage_key_segment(string $segment): string
{
  if ($segment === '') {
    return '';
  }

  // Some S3-compatible providers can reject Unicode multiplication symbols
  // during request canonicalization, causing SignatureDoesNotMatch.
  return str_replace(["\u{00D7}", "\u{2715}", "\u{2716}", "\u{2717}"], 'x', $segment);
}

/**
 * Ensure directory exists before attempting to write files locally.
 */
function ensure_directory(string $path): void
{
  $directory = dirname($path);
  if (!is_dir($directory) && !wp_mkdir_p($directory)) {
    throw new RuntimeException('Failed to create directory: ' . $directory);
  }
}

/**
 * Store transient admin notice.
 */
function push_admin_notice(string $message, string $type = 'error'): void
{
  $type = in_array($type, ['error', 'warning', 'success', 'info'], true) ? $type : 'error';
  $notices = get_option('pt_media_offload_admin_notices', []);
  if (!is_array($notices)) {
    $notices = [];
  }
  $notices[] = [
    'message' => wp_kses_post($message),
    'type' => $type,
    'timestamp' => time(),
  ];
  update_option('pt_media_offload_admin_notices', $notices, false);
}

/**
 * Fetch and clear stored admin notices.
 */
function pop_admin_notices(): array
{
  $notices = get_option('pt_media_offload_admin_notices', []);
  if (!is_array($notices)) {
    $notices = [];
  }
  delete_option('pt_media_offload_admin_notices');
  return $notices;
}

/**
 * Determine if the current request should run in verbose/debug mode.
 */
function is_debug(): bool
{
  return (bool) apply_filters('pt_media_offload_debug', defined('WP_DEBUG') && WP_DEBUG);
}
