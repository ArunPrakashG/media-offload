<?php
namespace Nimbus\Plugin\Cache;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class WpFastestCachePurgeIntegration implements PurgeIntegration
{
  public function __construct(
    private Settings $settings,
    private Logger $logger,
  ) {
  }

  public function id(): string
  {
    return 'wp_fastest_cache';
  }

  public function purge(array $request): array
  {
    if (!$this->settings->should_purge_wp_fastest_cache()) {
      return [
        'handled' => false,
        'integration' => $this->id(),
        'reason' => 'disabled',
      ];
    }

    $urls = array_values(array_unique(array_filter(
      array_map('strval', (array) ($request['urls'] ?? [])),
      static fn (string $url): bool => $url !== ''
    )));
    $purgeAll = !empty($request['purge_all']);

    if ($purgeAll) {
      if (!$this->purgeAll()) {
        return [
          'handled' => false,
          'integration' => $this->id(),
          'reason' => 'plugin-unavailable',
        ];
      }

      $this->logger->info('WP Fastest Cache purge completed. Purge all: {purge_all}, URLs: {count}', [
        'purge_all' => 'yes',
        'count' => count($urls),
        'source' => 'cache',
        'event' => 'cache_purge',
        'code' => 'wp_fastest_cache_purge_all',
      ]);

      return [
        'handled' => true,
        'integration' => $this->id(),
        'purge_all' => true,
        'purged_urls' => 0,
      ];
    }

    if (empty($urls)) {
      return [
        'handled' => false,
        'integration' => $this->id(),
        'reason' => 'no-targets',
      ];
    }

    $purged = 0;
    foreach ($urls as $url) {
      if ($this->purgeUrl($url)) {
        $purged++;
      }
    }

    if ($purged < 1) {
      return [
        'handled' => false,
        'integration' => $this->id(),
        'reason' => 'targeted-purge-unavailable',
      ];
    }

    $this->logger->info('WP Fastest Cache purge completed. Purge all: {purge_all}, URLs: {count}', [
      'purge_all' => 'no',
      'count' => $purged,
      'source' => 'cache',
      'event' => 'cache_purge',
      'code' => 'wp_fastest_cache_purge_url',
    ]);

    return [
      'handled' => true,
      'integration' => $this->id(),
      'purge_all' => false,
      'purged_urls' => $purged,
    ];
  }

  private function purgeAll(): bool
  {
    if (function_exists('wpfc_clear_all_cache')) {
      wpfc_clear_all_cache();
      return true;
    }

    $instance = $this->resolveInstance();
    foreach ([
      ['deleteCache', []],
      ['deleteCache', [true]],
      ['deleteCacheAndFiles', []],
      ['deleteCacheAndFiles', [true]],
      ['clearAllCache', []],
    ] as [$method, $args]) {
      if ($instance && method_exists($instance, $method) && $this->invoke($instance, $method, $args)) {
        return true;
      }
    }

    if (has_action('wpfc_clear_all_cache')) {
      do_action('wpfc_clear_all_cache');
      return true;
    }

    return false;
  }

  private function purgeUrl(string $url): bool
  {
    $instance = $this->resolveInstance();
    foreach ([
      ['deleteCacheByUrl', [$url]],
      ['clearCacheByUrl', [$url]],
      ['purgeUrl', [$url]],
      ['deleteUrlCache', [$url]],
    ] as [$method, $args]) {
      if ($instance && method_exists($instance, $method) && $this->invoke($instance, $method, $args)) {
        return true;
      }
    }

    if (has_action('wpfc_clear_cache_by_url')) {
      do_action('wpfc_clear_cache_by_url', $url);
      return true;
    }

    return false;
  }

  private function resolveInstance(): ?object
  {
    foreach (['wp_fastest_cache', 'wpFastestCache'] as $key) {
      if (isset($GLOBALS[$key]) && is_object($GLOBALS[$key])) {
        return $GLOBALS[$key];
      }
    }

    return null;
  }

  /**
   * @param array<int, mixed> $args
   */
  private function invoke(object $instance, string $method, array $args): bool
  {
    try {
      $instance->{$method}(...$args);
      return true;
    } catch (\Throwable) {
      return false;
    }
  }
}