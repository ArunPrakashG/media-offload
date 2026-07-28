<?php
namespace Nimbus\Plugin\Cache;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Support\Logger;
use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

final class ProxyCachePurgeIntegration implements PurgeIntegration
{
  public function __construct(
    private Settings $settings,
    private Logger $logger,
  ) {
  }

  public function id(): string
  {
    return 'proxy_cache';
  }

  public function purge(array $request): array
  {
    if (!$this->settings->should_purge_proxy_cache()) {
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
    $method = $this->settings->proxy_cache_purge_method();

    $targets = $purgeAll
      ? $this->buildFullPurgeTargets()
      : $this->buildUrlTargets($urls);

    if (empty($targets)) {
      return [
        'handled' => false,
        'integration' => $this->id(),
        'reason' => 'no-targets',
      ];
    }

    $count = 0;

    foreach ($targets as $target) {
      $args = [
        'method' => $method,
        'timeout' => 15,
      ];

      $args = apply_filters('nimbus_proxy_cache_purge_request_args', $args, $target, $request, $this);

      $response = wp_remote_request($target, $args);
      if (is_wp_error($response)) {
        throw new RuntimeException($response->get_error_message());
      }

      $status = (int) wp_remote_retrieve_response_code($response);
      if ($status >= 400) {
        throw new RuntimeException(sprintf('Proxy cache purge endpoint returned HTTP %d for %s', $status, $target));
      }

      $count++;
    }

    $this->logger->info('Proxy cache purge completed. Purge all: {purge_all}, Requests: {count}', [
      'purge_all' => $purgeAll ? 'yes' : 'no',
      'count' => $count,
      'source' => 'cache',
      'event' => 'cache_purge',
      'code' => 'proxy_cache_purge',
    ]);

    return [
      'handled' => true,
      'integration' => $this->id(),
      'purge_all' => $purgeAll,
      'purged_urls' => $purgeAll ? 0 : count($urls),
      'requests' => $count,
    ];
  }

  /**
   * @param array<int, string> $urls
   * @return array<int, string>
   */
  private function buildUrlTargets(array $urls): array
  {
    if (empty($urls)) {
      return [];
    }

    $templates = $this->settings->proxy_cache_purge_urls();
    if (empty($templates)) {
      return [];
    }

    $targets = [];
    foreach ($urls as $url) {
      foreach ($templates as $template) {
        $targets[] = $this->interpolateTemplate($template, $url);
      }
    }

    return array_values(array_unique(array_filter($targets)));
  }

  /**
   * @return array<int, string>
   */
  private function buildFullPurgeTargets(): array
  {
    return array_values(array_unique(array_filter($this->settings->proxy_cache_purge_all_urls())));
  }

  private function interpolateTemplate(string $template, string $url): string
  {
    $parts = wp_parse_url($url);
    $path = (string) ($parts['path'] ?? '/');
    $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';

    $replacements = [
      '{url}' => $url,
      '{url_encoded}' => rawurlencode($url),
      '{scheme}' => (string) ($parts['scheme'] ?? 'https'),
      '{host}' => (string) ($parts['host'] ?? ''),
      '{path}' => $path . $query,
      '{path_encoded}' => rawurlencode($path . $query),
    ];

    if (str_contains($template, '{')) {
      return strtr($template, $replacements);
    }

    return trailingslashit($template) . ltrim($path, '/');
  }
}