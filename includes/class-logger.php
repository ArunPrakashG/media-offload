<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

class Logger
{
  private const MAX_ENTRIES = 2000;

  public function info(string $message, array $context = []): void
  {
    $this->log('INFO', $message, $context);
  }

  public function warning(string $message, array $context = []): void
  {
    $this->log('WARNING', $message, $context);
  }

  public function error(string $message, array $context = []): void
  {
    $this->log('ERROR', $message, $context);
    if (!empty($context['admin_notice'])) {
      push_admin_notice($message, 'error');
    }
  }

  public function get_entries(int $limit = 500): array
  {
    $limit = max(1, $limit);
    $entries = get_option($this->get_log_option_key(), []);
    if (!is_array($entries)) {
      return [];
    }

    $entries = array_slice($entries, -$limit);
    return array_reverse($entries);
  }

  public function clear_entries(): void
  {
    delete_option($this->get_log_option_key());
  }

  private function log(string $level, string $message, array $context = []): void
  {
    if (!is_array($context)) {
      $context = [];
    }

    $interpolated = $this->interpolate($message, $context);

    $entries = get_option($this->get_log_option_key(), []);
    if (!is_array($entries)) {
      $entries = [];
    }

    $entries[] = [
      'timestamp' => time(),
      'datetime' => function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'),
      'level' => $level,
      'message' => $interpolated,
      'context' => $this->normalize_context($context),
    ];

    if (count($entries) > self::MAX_ENTRIES) {
      $entries = array_slice($entries, -self::MAX_ENTRIES);
    }

    update_option($this->get_log_option_key(), $entries, false);

    if (function_exists('error_log')) {
      error_log('[PT Media Offload][' . $level . '] ' . $interpolated);
    }
    do_action('pt_media_offload_log', $level, $message, $context);
  }

  private function normalize_context(array $context): array
  {
    $normalized = [];

    foreach ($context as $key => $value) {
      if (is_scalar($value) || $value === null) {
        $normalized[$key] = $value;
        continue;
      }

      if (function_exists('wp_json_encode')) {
        $normalized[$key] = wp_json_encode($value);
      } else {
        $normalized[$key] = json_encode($value);
      }
    }

    return $normalized;
  }

  private function get_log_option_key(): string
  {
    if (defined('PT_MEDIA_OFFLOAD_LOG_OPTION_KEY')) {
      return (string) PT_MEDIA_OFFLOAD_LOG_OPTION_KEY;
    }

    return 'pt_media_offload_logs';
  }

  private function interpolate(string $message, array $context): string
  {
    if (empty($context)) {
      return $message;
    }

    $replace = [];
    foreach ($context as $key => $value) {
      if (is_scalar($value)) {
        $replace['{' . $key . '}'] = (string) $value;
      }
    }

    return strtr($message, $replace);
  }
}
