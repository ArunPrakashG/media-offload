<?php
namespace Nimbus\Plugin\Support;

if (!defined('ABSPATH')) {
  exit;
}

final class Logger
{
  private const DEFAULT_MAX_ENTRIES = 2000;

  private const LEVEL_WEIGHTS = [
    'debug' => 100,
    'info' => 200,
    'notice' => 250,
    'warning' => 300,
    'error' => 400,
    'critical' => 500,
  ];

  private const RESERVED_CONTEXT_KEYS = [
    'source',
    'channel',
    'event',
    'code',
    'admin_notice',
  ];

  public function get_entries(int $limit = 500): array
  {
    return $this->getEntries($limit);
  }

  public function clear_entries(): void
  {
    $this->clearEntries();
  }

  public function info(string $message, array $context = []): void
  {
    $this->record('info', $message, $context);
  }

  public function debug(string $message, array $context = []): void
  {
    $this->record('debug', $message, $context);
  }

  public function notice(string $message, array $context = []): void
  {
    $this->record('notice', $message, $context);
  }

  public function warning(string $message, array $context = []): void
  {
    $this->record('warning', $message, $context);
  }

  public function error(string $message, array $context = []): void
  {
    $this->record('error', $message, $context);

    if (!empty($context['admin_notice'])) {
      pushAdminNotice($message, 'error');
    }
  }

  public function critical(string $message, array $context = []): void
  {
    $this->record('critical', $message, $context);

    if (!empty($context['admin_notice'])) {
      pushAdminNotice($message, 'error');
    }
  }

  public function getEntries(int $limit = 500): array
  {
    return $this->queryEntries(['limit' => $limit]);
  }

  public function clearEntries(): void
  {
    delete_option(NIMBUS_LOG_OPTION_KEY);
  }

  public function queryEntries(array $filters = []): array
  {
    $entries = get_option(NIMBUS_LOG_OPTION_KEY, []);
    if (!is_array($entries)) {
      return [];
    }

    $rawLevel = isset($filters['level']) ? trim((string) $filters['level']) : '';
    $level    = $rawLevel !== '' ? $this->normalizeLevel($rawLevel) : '';
    $source = isset($filters['source']) ? sanitize_key((string) $filters['source']) : '';
    $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
    $limit = max(1, (int) ($filters['limit'] ?? 500));

    $normalized = array_map(fn ($entry): array => $this->normalizeStoredEntry($entry), $entries);

    $filtered = array_values(array_filter($normalized, function (array $entry) use ($level, $source, $search): bool {
      if ($level !== '' && ($entry['level_key'] ?? '') !== $level) {
        return false;
      }

      if ($source !== '' && ($entry['source'] ?? '') !== $source) {
        return false;
      }

      if ($search !== '') {
        $haystack = strtolower($entry['message'] . ' ' . wp_json_encode($entry['context'] ?? []));
        if (!str_contains($haystack, strtolower($search))) {
          return false;
        }
      }

      return true;
    }));

    $filtered = array_slice($filtered, -$limit);

    return array_reverse($filtered);
  }

  public function stats(): array
  {
    $entries = $this->queryEntries(['limit' => PHP_INT_MAX]);
    $byLevel = [];
    $bySource = [];

    foreach ($entries as $entry) {
      $level = (string) ($entry['level_key'] ?? 'info');
      $source = (string) ($entry['source'] ?? 'plugin');
      $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
      $bySource[$source] = ($bySource[$source] ?? 0) + 1;
    }

    arsort($byLevel);
    arsort($bySource);

    return [
      'total' => count($entries),
      'by_level' => $byLevel,
      'by_source' => $bySource,
      'latest' => $entries[0] ?? null,
    ];
  }

  private function record(string $level, string $message, array $context = []): void
  {
    $context = is_array($context) ? $context : [];
    $level = $this->normalizeLevel($level);

    if (!$this->shouldStoreLevel($level)) {
      return;
    }

    $interpolated = $this->interpolate($message, $context);

    $entries = get_option(NIMBUS_LOG_OPTION_KEY, []);
    if (!is_array($entries)) {
      $entries = [];
    }

    $entry = [
      'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('nimbus-', true),
      'timestamp' => time(),
      'datetime' => function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'),
      'level' => strtoupper($level),
      'level_key' => $level,
      'message' => $interpolated,
      'template' => $message,
      'source' => $this->resolveSource($context),
      'event' => isset($context['event']) ? sanitize_key((string) $context['event']) : '',
      'code' => isset($context['code']) ? sanitize_key((string) $context['code']) : '',
      'context' => $this->normalizeContext($context),
    ];

    $entries[] = $entry;

    $maxEntries = $this->maxEntries();
    if (count($entries) > $maxEntries) {
      $entries = array_slice($entries, -$maxEntries);
    }

    update_option(NIMBUS_LOG_OPTION_KEY, $entries, false);

    if ($this->shouldMirrorToPhpErrorLog() && function_exists('error_log')) {
      // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional, user-configurable "mirror log entries to the PHP error log" feature (Settings > Logging), not leftover debug code.
      error_log('[Plume][' . strtoupper($level) . '][' . $entry['source'] . '] ' . $interpolated);
    }

    do_action('nimbus_log', strtoupper($level), $message, $context);
    do_action('nimbus_log_entry', $entry, $message, $context);
  }

  private function normalizeContext(array $context): array
  {
    $normalized = [];

    foreach ($context as $key => $value) {
      if (in_array((string) $key, self::RESERVED_CONTEXT_KEYS, true)) {
        continue;
      }

      $normalized[$key] = $this->normalizeValue($value);
    }

    return $normalized;
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

  private function normalizeStoredEntry(mixed $entry): array
  {
    if (!is_array($entry)) {
      return [
        'id' => '',
        'timestamp' => 0,
        'datetime' => '',
        'level' => 'INFO',
        'level_key' => 'info',
        'message' => '',
        'template' => '',
        'source' => 'plugin',
        'event' => '',
        'code' => '',
        'context' => [],
      ];
    }

    $level = isset($entry['level_key'])
      ? $this->normalizeLevel((string) $entry['level_key'])
      : $this->normalizeLevel((string) ($entry['level'] ?? 'info'));

    return [
      'id' => (string) ($entry['id'] ?? ''),
      'timestamp' => (int) ($entry['timestamp'] ?? 0),
      'datetime' => (string) ($entry['datetime'] ?? ''),
      'level' => strtoupper($level),
      'level_key' => $level,
      'message' => (string) ($entry['message'] ?? ''),
      'template' => (string) ($entry['template'] ?? ''),
      'source' => sanitize_key((string) ($entry['source'] ?? 'plugin')) ?: 'plugin',
      'event' => sanitize_key((string) ($entry['event'] ?? '')),
      'code' => sanitize_key((string) ($entry['code'] ?? '')),
      'context' => is_array($entry['context'] ?? null) ? $entry['context'] : [],
    ];
  }

  private function normalizeLevel(string $level): string
  {
    $level = strtolower(trim($level));

    return array_key_exists($level, self::LEVEL_WEIGHTS) ? $level : 'info';
  }

  private function shouldStoreLevel(string $level): bool
  {
    $config = $this->config();
    if (empty($config['logging_enabled'])) {
      return false;
    }

    $minimumLevel = $this->normalizeLevel((string) ($config['log_level'] ?? 'info'));

    return self::LEVEL_WEIGHTS[$level] >= self::LEVEL_WEIGHTS[$minimumLevel];
  }

  private function maxEntries(): int
  {
    $config = $this->config();

    return max(100, min(10000, (int) ($config['log_max_entries'] ?? self::DEFAULT_MAX_ENTRIES)));
  }

  private function shouldMirrorToPhpErrorLog(): bool
  {
    $config = $this->config();

    return !empty($config['log_to_error_log']);
  }

  private function config(): array
  {
    $settings = get_option(NIMBUS_OPTION_KEY, []);
    if (!is_array($settings)) {
      $settings = [];
    }

    return wp_parse_args($settings, [
      'logging_enabled' => true,
      'log_level' => 'info',
      'log_max_entries' => self::DEFAULT_MAX_ENTRIES,
      'log_to_error_log' => true,
    ]);
  }

  private function resolveSource(array $context): string
  {
    $source = (string) ($context['source'] ?? $context['channel'] ?? '');
    $source = sanitize_key($source);
    if ($source !== '') {
      return $source;
    }

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Intentional: infers the calling class name to auto-tag log entries by source when the caller didn't pass one explicitly. Not leftover debug code.
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
      $class = (string) ($frame['class'] ?? '');
      if ($class === '' || $class === self::class) {
        continue;
      }

      $short = $class;
      if (str_contains($short, '\\')) {
        $parts = explode('\\', $short);
        $short = (string) end($parts);
      }

      $short = preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short;
      $short = sanitize_key(strtolower($short));
      if ($short !== '') {
        return $short;
      }
    }

    return 'plugin';
  }

  private function normalizeValue(mixed $value): mixed
  {
    if (is_scalar($value) || $value === null) {
      return $value;
    }

    if ($value instanceof \Throwable) {
      return [
        'type' => get_class($value),
        'message' => $value->getMessage(),
      ];
    }

    if ($value instanceof \WP_Error) {
      return [
        'code' => $value->get_error_code(),
        'message' => $value->get_error_message(),
      ];
    }

    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $itemKey => $itemValue) {
        $normalized[$itemKey] = $this->normalizeValue($itemValue);
      }

      return $normalized;
    }

    if ($value instanceof \JsonSerializable) {
      return $this->normalizeValue($value->jsonSerialize());
    }

    if ($value instanceof \Stringable) {
      return (string) $value;
    }

    return function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
  }
}