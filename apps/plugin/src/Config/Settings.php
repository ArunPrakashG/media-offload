<?php
namespace Nimbus\Plugin\Config;

use Nimbus\Plugin\Storage\ProviderProfile;
use Nimbus\Plugin\Storage\ProviderRegistry;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class Settings
{
  private ProviderRegistry $providerRegistry;

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
    'purge_litespeed_cache' => false,
    'purge_wp_fastest_cache' => false,
    'purge_proxy_cache' => false,
    'cloudflare_zone_id' => '',
    'cloudflare_api_token' => '',
    'proxy_cache_purge_method' => 'PURGE',
    'proxy_cache_purge_urls' => '',
    'proxy_cache_purge_all_urls' => '',
    'logging_enabled' => true,
    'log_level' => 'info',
    'log_max_entries' => 2000,
    'log_to_error_log' => true,
    'allowed_path_patterns' => '',
    'ignored_path_patterns' => '',
    'offload_min_size_kb' => 0,
    'offload_allowed_mime_types' => '',
    'storage_class' => 'STANDARD',
    'visibility' => 'public-read',
  ];

  public function __construct(private Logger $logger)
  {
    $this->providerRegistry = new ProviderRegistry();
  }

  public function all(): array
  {
    return wp_parse_args(get_option(NIMBUS_OPTION_KEY, []), $this->defaults);
  }

  public function is_enabled(): bool
  {
    return $this->isEnabled();
  }

  public function has_valid_credentials(): bool
  {
    return $this->hasValidCredentials();
  }

  public function should_process_off_hours_batch(): bool
  {
    return $this->shouldProcessOffHoursBatch();
  }

  public function off_hours_batch_time(): string
  {
    return $this->offHoursBatchTime();
  }

  public function off_hours_worker_count(): int
  {
    return $this->offHoursWorkerCount();
  }

  public function off_hours_worker_batch_size(): int
  {
    return $this->offHoursWorkerBatchSize();
  }

  public function off_hours_claim_ttl_seconds(): int
  {
    return $this->offHoursClaimTtlSeconds();
  }

  public function should_remove_local(): bool
  {
    return $this->shouldRemoveLocal();
  }

  public function should_serve_from_storage(): bool
  {
    return $this->shouldServeFromStorage();
  }

  public function should_purge_cloudflare_cache_on_delete(): bool
  {
    return $this->shouldPurgeCloudflareCacheOnDelete();
  }

  public function has_cloudflare_purge_credentials(): bool
  {
    return $this->hasCloudflarePurgeCredentials();
  }

  public function should_purge_litespeed_cache(): bool
  {
    return $this->shouldPurgeLiteSpeedCache();
  }

  public function should_purge_wp_fastest_cache(): bool
  {
    return $this->shouldPurgeWpFastestCache();
  }

  public function should_purge_proxy_cache(): bool
  {
    return $this->shouldPurgeProxyCache();
  }

  /**
   * @return array<int, string>
   */
  public function proxy_cache_purge_urls(): array
  {
    return $this->proxyCachePurgeUrls();
  }

  /**
   * @return array<int, string>
   */
  public function proxy_cache_purge_all_urls(): array
  {
    return $this->proxyCachePurgeAllUrls();
  }

  public function proxy_cache_purge_method(): string
  {
    return $this->proxyCachePurgeMethod();
  }

  /**
   * Minimum file size in KB to offload. 0 = no minimum (all sizes allowed).
   * Requires the selective_offload license feature.
   */
  public function offload_min_size_kb(): int
  {
    return max(0, (int) $this->get('offload_min_size_kb', 0));
  }

  /**
   * Comma-separated list of allowed MIME type patterns (e.g. "image/*,video/*").
   * Empty = all MIME types are allowed.
   * Requires the selective_offload license feature.
   *
   * @return list<string>
   */
  public function offload_allowed_mime_types(): array
  {
    $raw = trim((string) $this->get('offload_allowed_mime_types', ''));
    if ($raw === '') {
      return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
  }

  public function should_offload_relative_path(string $relativePath): bool
  {
    return $this->shouldOffloadRelativePath($relativePath);
  }

  public function adapter_configuration(): array
  {
    return $this->adapterConfiguration();
  }

  public function normalize_provider_settings(array $values): array
  {
    return $this->normalizeProviderSettings($values);
  }

  public function get(string $key, mixed $default = null): mixed
  {
    $settings = $this->all();

    return $settings[$key] ?? $default;
  }

  public function update(array $values): void
  {
    $current = $this->all();
    $sanitized = $this->sanitize(array_merge($current, $values));
    update_option(NIMBUS_OPTION_KEY, $sanitized, false);
  }

  public function isEnabled(): bool
  {
    return (bool) $this->get('enabled', true);
  }

  public function provider(): string
  {
    return $this->sanitizeProvider((string) $this->get('provider', 's3'));
  }

  public function provider_profile(?string $provider = null): ProviderProfile
  {
    return $this->providerProfile($provider);
  }

  public function providerProfile(?string $provider = null): ProviderProfile
  {
    return $this->providerRegistry->get($this->sanitizeProvider((string) ($provider ?: $this->provider())));
  }

  public function availableProviderKeys(): array
  {
    return $this->providerRegistry->keys();
  }

  public function available_provider_keys(): array
  {
    return $this->availableProviderKeys();
  }

  public function missingRequiredCredentials(?array $values = null, ?string $provider = null): array
  {
    $values = is_array($values) ? $values : $this->all();
    $provider = $provider ?: (string) ($values['provider'] ?? $this->provider());

    return $this->providerProfile($provider)->missingCredentialLabels($values);
  }

  public function missing_required_credentials(?array $values = null, ?string $provider = null): array
  {
    return $this->missingRequiredCredentials($values, $provider);
  }

  public function shouldProcessOffHoursBatch(): bool
  {
    return $this->isEnabled() && (bool) $this->get('off_hours_batch_enabled', true);
  }

  public function offHoursBatchTime(): string
  {
    return $this->sanitizeTime((string) $this->get('off_hours_batch_time', '03:00'));
  }

  public function offHoursWorkerCount(): int
  {
    return max(1, min(8, (int) $this->get('off_hours_worker_count', 1)));
  }

  public function offHoursWorkerBatchSize(): int
  {
    return max(10, min(1000, (int) $this->get('off_hours_worker_batch_size', 100)));
  }

  public function offHoursClaimTtlSeconds(): int
  {
    return max(60, min(86400, (int) $this->get('off_hours_claim_ttl_seconds', 1800)));
  }

  public function shouldRemoveLocal(): bool
  {
    return $this->hasValidCredentials() && (bool) $this->get('remove_local_copies', false);
  }

  public function shouldServeFromStorage(): bool
  {
    return $this->hasValidCredentials() && (bool) $this->get('serve_from_storage', true);
  }

  public function shouldPurgeCloudflareCacheOnDelete(): bool
  {
    return (bool) $this->get('purge_cloudflare_cache_on_delete', false);
  }

  public function shouldPurgeLiteSpeedCache(): bool
  {
    return (bool) $this->get('purge_litespeed_cache', false);
  }

  public function shouldPurgeWpFastestCache(): bool
  {
    return (bool) $this->get('purge_wp_fastest_cache', false);
  }

  public function shouldPurgeProxyCache(): bool
  {
    return (bool) $this->get('purge_proxy_cache', false);
  }

  /**
   * @return array<int, string>
   */
  public function proxyCachePurgeUrls(): array
  {
    return $this->parseLineList((string) $this->get('proxy_cache_purge_urls', ''));
  }

  /**
   * @return array<int, string>
   */
  public function proxyCachePurgeAllUrls(): array
  {
    return $this->parseLineList((string) $this->get('proxy_cache_purge_all_urls', ''));
  }

  public function proxyCachePurgeMethod(): string
  {
    $method = strtoupper((string) $this->get('proxy_cache_purge_method', 'PURGE'));

    return in_array($method, ['PURGE', 'GET', 'POST'], true) ? $method : 'PURGE';
  }

  public function hasCloudflarePurgeCredentials(): bool
  {
    return trim((string) $this->get('cloudflare_zone_id')) !== ''
      && trim((string) $this->get('cloudflare_api_token')) !== '';
  }

  public function hasValidCredentials(): bool
  {
    return $this->providerProfile()->hasValidCredentials($this->all());
  }

  public function allowedPathPatterns(): array
  {
    return $this->parsePathPatterns((string) $this->get('allowed_path_patterns', ''));
  }

  public function allowed_path_patterns(): array
  {
    return $this->allowedPathPatterns();
  }

  public function ignoredPathPatterns(): array
  {
    return $this->parsePathPatterns((string) $this->get('ignored_path_patterns', ''));
  }

  public function ignored_path_patterns(): array
  {
    return $this->ignoredPathPatterns();
  }

  public function shouldOffloadRelativePath(string $relativePath): bool
  {
    $relativePath = $this->normalizeRelativePath($relativePath);
    if ($relativePath === '') {
      return true;
    }

    if ($this->matchesPathPatterns($relativePath, $this->ignoredPathPatterns())) {
      return false;
    }

    $allowedPatterns = $this->allowedPathPatterns();
    if (empty($allowedPatterns)) {
      return true;
    }

    return $this->matchesPathPatterns($relativePath, $allowedPatterns);
  }

  public function adapterConfiguration(): array
  {
    $normalized = $this->normalizeProviderSettings([
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

  public function normalizeProviderSettings(array $values): array
  {
    $provider = $this->sanitizeProvider((string) ($values['provider'] ?? 's3'));
    $values['provider'] = $provider;

    return $this->providerProfile($provider)->normalize($values);
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
        case 'purge_litespeed_cache':
        case 'purge_wp_fastest_cache':
        case 'purge_proxy_cache':
        case 'logging_enabled':
        case 'log_to_error_log':
          $clean[$key] = (bool) $value;
          break;
        case 'off_hours_batch_time':
          $clean[$key] = $this->sanitizeTime((string) $value);
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
        case 'log_max_entries':
          $clean[$key] = max(100, min(10000, (int) $value));
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
          $clean[$key] = $this->sanitizePathPatterns($value);
          break;
        case 'offload_min_size_kb':
          $clean[$key] = max(0, (int) $value);
          break;
        case 'offload_allowed_mime_types':
          $clean[$key] = $this->sanitizeLineList($value);
          break;
        case 'proxy_cache_purge_urls':
        case 'proxy_cache_purge_all_urls':
          $clean[$key] = $this->sanitizeLineList($value);
          break;
        case 'access_key':
        case 'secret_key':
        case 'session_token':
        case 'cloudflare_api_token':
          $clean[$key] = trim((string) $value);
          break;
        case 'provider':
          $clean[$key] = $this->sanitizeProvider((string) $value);
          break;
        case 'log_level':
          $clean[$key] = in_array((string) $value, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true) ? (string) $value : 'info';
          break;
        case 'proxy_cache_purge_method':
          $method = strtoupper((string) $value);
          $clean[$key] = in_array($method, ['PURGE', 'GET', 'POST'], true) ? $method : 'PURGE';
          break;
        default:
          $clean[$key] = $value;
      }
    }

    return $this->normalizeProviderSettings($clean);
  }

  public function sanitize_settings(array $values): array
  {
    return $this->sanitize($values);
  }

  private function sanitizeTime(string $value): string
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

  private function sanitizeProvider(string $provider): string
  {
    return in_array($provider, $this->availableProviderKeys(), true) ? $provider : 's3';
  }

  private function sanitizePathPatterns(mixed $value): string
  {
    $rawPatterns = is_array($value) ? $value : (preg_split('/[\r\n,]+/', (string) $value) ?: []);
    $patterns = [];

    foreach ($rawPatterns as $pattern) {
      $pattern = sanitize_text_field((string) $pattern);
      $pattern = $this->normalizeRelativePath($pattern);
      if ($pattern !== '') {
        $patterns[$pattern] = true;
      }
    }

    return implode("\n", array_keys($patterns));
  }

  private function sanitizeLineList(mixed $value): string
  {
    $rawItems = is_array($value) ? $value : (preg_split('/\r\n|\r|\n/', (string) $value) ?: []);
    $items = [];

    foreach ($rawItems as $item) {
      $item = trim((string) $item);
      if ($item === '') {
        continue;
      }

      $item = preg_replace('/\s+/', ' ', $item) ?? $item;
      $items[$item] = true;
    }

    return implode("\n", array_keys($items));
  }

  private function parsePathPatterns(string $value): array
  {
    $value = trim($value);
    if ($value === '') {
      return [];
    }

    $patterns = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $normalized = [];
    foreach ($patterns as $pattern) {
      $pattern = $this->normalizeRelativePath($pattern);
      if ($pattern !== '') {
        $normalized[$pattern] = true;
      }
    }

    return array_keys($normalized);
  }

  /**
   * @return array<int, string>
   */
  private function parseLineList(string $value): array
  {
    $value = trim($value);
    if ($value === '') {
      return [];
    }

    $items = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $normalized = [];
    foreach ($items as $item) {
      $item = trim((string) $item);
      if ($item !== '') {
        $normalized[$item] = true;
      }
    }

    return array_keys($normalized);
  }

  private function matchesPathPatterns(string $relativePath, array $patterns): bool
  {
    foreach ($patterns as $pattern) {
      if ($this->matchesPathPattern($relativePath, (string) $pattern)) {
        return true;
      }
    }

    return false;
  }

  private function matchesPathPattern(string $relativePath, string $pattern): bool
  {
    $relativePath = $this->normalizeRelativePath($relativePath);
    $pattern = $this->normalizeRelativePath($pattern);

    if ($relativePath === '' || $pattern === '') {
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

    return (bool) preg_match('#^' . $regex . '$#', $relativePath);
  }

  private function normalizeRelativePath(string $path): string
  {
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;

    return ltrim($path, '/');
  }
}