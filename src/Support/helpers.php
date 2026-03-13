<?php
namespace ArunPrakash\MediaOffload\Support;

use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

function buildStorageKey(string $relativePath, string $prefix = ''): string
{
  $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
  $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

  if ($normalized !== '') {
    $parts = explode('/', $normalized);
    $parts = array_map(__NAMESPACE__ . '\\normalizeStorageKeySegment', $parts);
    $parts = array_values(array_filter($parts, static fn (string $segment): bool => $segment !== ''));
    $normalized = implode('/', $parts);
  }

  $prefix = trim($prefix, '/');

  return $prefix !== '' ? $prefix . '/' . $normalized : $normalized;
}

function normalizeStorageKeySegment(string $segment): string
{
  if ($segment === '') {
    return '';
  }

  return str_replace(["\u{00D7}", "\u{2715}", "\u{2716}", "\u{2717}"], 'x', $segment);
}

function ensureDirectory(string $path): void
{
  $directory = dirname($path);
  if (!is_dir($directory) && !wp_mkdir_p($directory)) {
    throw new RuntimeException('Failed to create directory: ' . $directory);
  }
}

function pushAdminNotice(string $message, string $type = 'error'): void
{
  $type = in_array($type, ['error', 'warning', 'success', 'info'], true) ? $type : 'error';
  $notices = get_option(MEDIA_OFFLOAD_ADMIN_NOTICES_OPTION_KEY, []);
  if (!is_array($notices)) {
    $notices = [];
  }

  $notices[] = [
    'message' => wp_kses_post($message),
    'type' => $type,
    'timestamp' => time(),
  ];

  update_option(MEDIA_OFFLOAD_ADMIN_NOTICES_OPTION_KEY, $notices, false);
}

function popAdminNotices(): array
{
  $notices = get_option(MEDIA_OFFLOAD_ADMIN_NOTICES_OPTION_KEY, []);
  if (!is_array($notices)) {
    $notices = [];
  }

  delete_option(MEDIA_OFFLOAD_ADMIN_NOTICES_OPTION_KEY);

  return $notices;
}

function isDebug(): bool
{
  return (bool) apply_filters('media_offload_debug', defined('WP_DEBUG') && WP_DEBUG);
}