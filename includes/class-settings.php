<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

class Settings
{
  private Logger $logger;

  private array $defaults = [
    'enabled' => true,
    'off_hours_batch_enabled' => true,
    'off_hours_batch_time' => '03:00',
    'off_hours_worker_count' => 1,
    'off_hours_worker_batch_size' => 100,
    'off_hours_claim_ttl_seconds' => 1800,
    'provider' => 's3',
    'bucket' => '',
    'r2_account_id' => '',
    'region' => '',
    'access_key' => '',
    'secret_key' => '',
    'session_token' => '',
    'prefix' => '',
    'endpoint' => '',
    'use_path_style_endpoint' => false,
    'remove_local_copies' => false,
    'serve_from_storage' => true,
    'custom_domain' => '',
    'purge_cloudflare_cache_on_delete' => false,
    'cloudflare_zone_id' => '',
    'cloudflare_api_token' => '',
    'allowed_path_patterns' => '',
    'ignored_path_patterns' => '',
    'storage_class' => 'STANDARD',
    'visibility' => 'public-read',
  ];

  public function __construct(Logger $logger)
  {
    $this->logger = $logger;
  }

  public function all(): array
  {
    return wp_parse_args(get_option(PT_MEDIA_OFFLOAD_OPTION_KEY, []), $this->defaults);
  }

  public function get(string $key, $default = null)
  {
    $settings = $this->all();
    return $settings[$key] ?? $default;
  }

  public function update(array $values): void
  {
    $current = $this->all();
    $sanitized = $this->sanitize(array_merge($current, $values));
    update_option(PT_MEDIA_OFFLOAD_OPTION_KEY, $sanitized, false);
  }

  public function is_enabled(): bool
  {
    return (bool) $this->get('enabled', true);
  }

  public function provider(): string
  {
    return (string) $this->get('provider', 's3');
  }

  public function required_fields_for_provider(?string $provider = null): array
  {
    $provider = $provider ?: $this->provider();

    switch ($provider) {
      case 'r2':
        return ['bucket', 'access_key', 'secret_key'];
      case 'local':
        return [];
      case 's3':
      default:
        return ['bucket', 'region', 'access_key', 'secret_key'];
    }
  }

  public function should_process_off_hours_batch(): bool
  {
    if (!$this->is_enabled()) {
      return false;
    }

    return (bool) $this->get('off_hours_batch_enabled', true);
  }

  public function off_hours_batch_time(): string
  {
    return $this->sanitize_time((string) $this->get('off_hours_batch_time', '03:00'));
  }

  public function off_hours_worker_count(): int
  {
    $value = (int) $this->get('off_hours_worker_count', 1);
    return max(1, min(8, $value));
  }

  public function off_hours_worker_batch_size(): int
  {
    $value = (int) $this->get('off_hours_worker_batch_size', 100);
    return max(10, min(1000, $value));
  }

  public function off_hours_claim_ttl_seconds(): int
  {
    $value = (int) $this->get('off_hours_claim_ttl_seconds', 1800);
    return max(60, min(86400, $value));
  }

  public function should_remove_local(): bool
  {
    if (!$this->has_valid_credentials()) {
      return false;
    }
    return (bool) $this->get('remove_local_copies', false);
  }

  public function should_serve_from_storage(): bool
  {
    if (!$this->has_valid_credentials()) {
      return false;
    }
    return (bool) $this->get('serve_from_storage', true);
  }

  public function should_purge_cloudflare_cache_on_delete(): bool
  {
    return (bool) $this->get('purge_cloudflare_cache_on_delete', false);
  }

  public function has_cloudflare_purge_credentials(): bool
  {
    return trim((string) $this->get('cloudflare_zone_id')) !== ''
      && trim((string) $this->get('cloudflare_api_token')) !== '';
  }

  public function has_valid_credentials(): bool
  {
    if ($this->provider() === 's3') {
      return $this->get('bucket') && $this->get('region') && $this->get('access_key') && $this->get('secret_key');
    }

    if ($this->provider() === 'r2') {
      $has_endpoint_or_account = (bool) $this->get('endpoint') || (bool) $this->get('r2_account_id');
      return $this->get('bucket') && $this->get('access_key') && $this->get('secret_key') && $has_endpoint_or_account;
    }

    return true;
  }

  public function allowed_path_patterns(): array
  {
    return $this->parse_path_patterns((string) $this->get('allowed_path_patterns', ''));
  }

  public function ignored_path_patterns(): array
  {
    return $this->parse_path_patterns((string) $this->get('ignored_path_patterns', ''));
  }

  public function should_offload_relative_path(string $relative_path): bool
  {
    $relative_path = $this->normalize_relative_path($relative_path);
    if ($relative_path === '') {
      return true;
    }

    if ($this->matches_path_patterns($relative_path, $this->ignored_path_patterns())) {
      return false;
    }

    $allowed_patterns = $this->allowed_path_patterns();
    if (empty($allowed_patterns)) {
      return true;
    }

    return $this->matches_path_patterns($relative_path, $allowed_patterns);
  }

  public function adapter_configuration(): array
  {
    $normalized = $this->normalize_provider_settings([
      'provider' => $this->provider(),
      'bucket' => $this->get('bucket'),
      'r2_account_id' => $this->get('r2_account_id'),
      'region' => $this->get('region'),
      'access_key' => $this->get('access_key'),
      'secret_key' => $this->get('secret_key'),
      'session_token' => $this->get('session_token'),
      'prefix' => $this->get('prefix'),
      'endpoint' => $this->get('endpoint'),
      'use_path_style_endpoint' => $this->get('use_path_style_endpoint', false),
      'storage_class' => $this->get('storage_class', 'STANDARD'),
      'visibility' => $this->get('visibility', 'public-read'),
      'custom_domain' => $this->get('custom_domain'),
    ]);

    return [
      'provider' => $normalized['provider'],
      'bucket' => $normalized['bucket'],
      'r2_account_id' => $normalized['r2_account_id'],
      'region' => $normalized['region'],
      'access_key' => $normalized['access_key'],
      'secret_key' => $normalized['secret_key'],
      'session_token' => $normalized['session_token'],
      'prefix' => $normalized['prefix'],
      'endpoint' => $normalized['endpoint'],
      'use_path_style_endpoint' => (bool) $normalized['use_path_style_endpoint'],
      'storage_class' => $normalized['storage_class'],
      'visibility' => $normalized['visibility'],
      'custom_domain' => $normalized['custom_domain'],
    ];
  }

  public function normalize_provider_settings(array $values): array
  {
    $provider = in_array(($values['provider'] ?? 's3'), ['s3', 'r2', 'local'], true)
      ? (string) $values['provider']
      : 's3';

    $values['provider'] = $provider;
    $values['region'] = trim((string) ($values['region'] ?? ''));
    $values['endpoint'] = rtrim(trim((string) ($values['endpoint'] ?? '')), '/');
    $values['r2_account_id'] = trim((string) ($values['r2_account_id'] ?? ''));
    $values['session_token'] = trim((string) ($values['session_token'] ?? ''));
    $values['use_path_style_endpoint'] = !empty($values['use_path_style_endpoint']);

    if ($provider === 'r2') {
      if ($values['region'] === '') {
        $values['region'] = 'auto';
      }

      if ($values['endpoint'] === '' && $values['r2_account_id'] !== '') {
        $values['endpoint'] = $this->build_r2_endpoint($values['r2_account_id']);
      }

      $values['use_path_style_endpoint'] = true;
      $values['session_token'] = '';
    }

    if ($provider === 's3' && strtolower($values['region']) === 'auto' && !$this->endpoint_looks_like_r2($values['endpoint'])) {
      $values['region'] = '';
    }

    if ($this->endpoint_looks_like_r2($values['endpoint'])) {
      if ($values['region'] === '' || strtolower($values['region']) === 'us-east-1') {
        $values['region'] = 'auto';
      }

      $values['use_path_style_endpoint'] = true;
      $values['session_token'] = '';
    }

    return $values;
  }

  public function sanitize(array $values): array
  {
    $clean = [];
    foreach ($this->defaults as $key => $default) {
      if (!array_key_exists($key, $values)) {
        $clean[$key] = $default;
        continue;
      }

      $value = $values[$key];
      switch ($key) {
        case 'enabled':
        case 'off_hours_batch_enabled':
        case 'use_path_style_endpoint':
        case 'remove_local_copies':
        case 'serve_from_storage':
        case 'purge_cloudflare_cache_on_delete':
          $clean[$key] = (bool) $value;
          break;
        case 'off_hours_batch_time':
          $clean[$key] = $this->sanitize_time((string) $value);
          break;
        case 'off_hours_worker_count':
          $clean[$key] = max(1, min(8, (int) $value));
          break;
        case 'off_hours_worker_batch_size':
          $clean[$key] = max(10, min(1000, (int) $value));
          break;
        case 'off_hours_claim_ttl_seconds':
          $clean[$key] = max(60, min(86400, (int) $value));
          break;
        case 'custom_domain':
        case 'endpoint':
          $clean[$key] = sanitize_text_field((string) $value);
          break;
        case 'bucket':
        case 'r2_account_id':
        case 'region':
        case 'prefix':
        case 'storage_class':
        case 'visibility':
        case 'cloudflare_zone_id':
          $clean[$key] = trim((string) $value);
          break;
        case 'allowed_path_patterns':
        case 'ignored_path_patterns':
          $clean[$key] = $this->sanitize_path_patterns($value);
          break;
        case 'access_key':
        case 'secret_key':
        case 'session_token':
        case 'cloudflare_api_token':
          $clean[$key] = trim((string) $value);
          break;
        case 'provider':
          $clean[$key] = in_array($value, ['s3', 'r2', 'local'], true) ? $value : 's3';
          break;
        default:
          $clean[$key] = $value;
      }
    }

    return $this->normalize_provider_settings($clean);
  }

  private function sanitize_time(string $value): string
  {
    $value = trim($value);

    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
      return '03:00';
    }

    $hour = (int) $matches[1];
    $minute = (int) $matches[2];

    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
      return '03:00';
    }

    return sprintf('%02d:%02d', $hour, $minute);
  }

  private function build_r2_endpoint(string $account_id): string
  {
    return 'https://' . trim($account_id) . '.r2.cloudflarestorage.com';
  }

  private function endpoint_looks_like_r2(string $endpoint): bool
  {
    if ($endpoint === '') {
      return false;
    }

    $host = (string) wp_parse_url($endpoint, PHP_URL_HOST);
    if ($host === '') {
      return false;
    }

    return strpos(strtolower($host), 'r2.cloudflarestorage.com') !== false;
  }

  private function sanitize_path_patterns($value): string
  {
    if (is_array($value)) {
      $raw_patterns = $value;
    } else {
      $raw_patterns = preg_split('/[\r\n,]+/', (string) $value) ?: [];
    }

    $patterns = [];
    foreach ($raw_patterns as $pattern) {
      $pattern = sanitize_text_field((string) $pattern);
      $pattern = $this->normalize_relative_path($pattern);
      if ($pattern === '') {
        continue;
      }

      $patterns[$pattern] = true;
    }

    return implode("\n", array_keys($patterns));
  }

  private function parse_path_patterns(string $value): array
  {
    $value = trim($value);
    if ($value === '') {
      return [];
    }

    $patterns = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $normalized = [];
    foreach ($patterns as $pattern) {
      $pattern = $this->normalize_relative_path($pattern);
      if ($pattern === '') {
        continue;
      }

      $normalized[$pattern] = true;
    }

    return array_keys($normalized);
  }

  private function matches_path_patterns(string $relative_path, array $patterns): bool
  {
    foreach ($patterns as $pattern) {
      if ($this->matches_path_pattern($relative_path, (string) $pattern)) {
        return true;
      }
    }

    return false;
  }

  private function matches_path_pattern(string $relative_path, string $pattern): bool
  {
    $relative_path = $this->normalize_relative_path($relative_path);
    $pattern = $this->normalize_relative_path($pattern);

    if ($relative_path === '' || $pattern === '') {
      return false;
    }

    $regex = preg_quote($pattern, '#');
    $regex = strtr($regex, [
      '\*\*' => '.*',
      '\*' => '[^/]*',
      '\?' => '[^/]',
      '\{year\}' => '\\d{4}',
      '\{month\}' => '\\d{2}',
      '\{day\}' => '\\d{2}',
    ]);

    return (bool) preg_match('#^' . $regex . '$#', $relative_path);
  }

  private function normalize_relative_path(string $path): string
  {
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return ltrim($path, '/');
  }
}
