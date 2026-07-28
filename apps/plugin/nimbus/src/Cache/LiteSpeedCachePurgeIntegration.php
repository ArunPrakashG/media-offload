<?php
namespace Nimbus\Plugin\Cache;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class LiteSpeedCachePurgeIntegration implements PurgeIntegration
{
  public function __construct(
    private Settings $settings,
    private Logger $logger,
  ) {
  }

  public function id(): string
  {
    return 'litespeed';
  }

  public function purge(array $request): array
  {
    if (!$this->settings->should_purge_litespeed_cache()) {
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

      $this->logger->info('LiteSpeed cache purge completed. Purge all: {purge_all}, URLs: {count}', [
        'purge_all' => 'yes',
        'count' => count($urls),
        'source' => 'cache',
        'event' => 'cache_purge',
        'code' => 'litespeed_purge_all',
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

    $this->logger->info('LiteSpeed cache purge completed. Purge all: {purge_all}, URLs: {count}', [
      'purge_all' => 'no',
      'count' => $purged,
      'source' => 'cache',
      'event' => 'cache_purge',
      'code' => 'litespeed_purge_url',
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
    if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
      \LiteSpeed_Cache_API::purge_all();
      return true;
    }

    if (has_action('litespeed_purge_all')) {
      do_action('litespeed_purge_all');
      return true;
    }

    return false;
  }

  private function purgeUrl(string $url): bool
  {
    if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_url')) {
      \LiteSpeed_Cache_API::purge_url($url);
      return true;
    }

    if (has_action('litespeed_purge_url')) {
      do_action('litespeed_purge_url', $url);
      return true;
    }

    return false;
  }
}