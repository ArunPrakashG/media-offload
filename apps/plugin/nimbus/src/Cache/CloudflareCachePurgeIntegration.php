<?php
namespace Nimbus\Plugin\Cache;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Support\Logger;
use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

final class CloudflareCachePurgeIntegration implements PurgeIntegration
{
  public function __construct(
    private Settings $settings,
    private Logger $logger,
  ) {
  }

  public function id(): string
  {
    return 'cloudflare';
  }

  public function purge(array $request): array
  {
    if (!$this->settings->should_purge_cloudflare_cache_on_delete()) {
      return [
        'handled' => false,
        'integration' => $this->id(),
        'reason' => 'disabled',
      ];
    }

    if (!$this->settings->has_cloudflare_purge_credentials()) {
      throw new RuntimeException('Cloudflare cache purge is enabled but Zone ID or API token is missing.');
    }

    $urls = array_values(array_unique(array_filter(
      array_map('strval', (array) ($request['urls'] ?? [])),
      static fn (string $url): bool => $url !== ''
    )));
    $purgeAll = !empty($request['purge_all']);

    if (!$purgeAll && empty($urls)) {
      return [
        'handled' => false,
        'integration' => $this->id(),
        'reason' => 'no-targets',
      ];
    }

    $zoneId = trim((string) $this->settings->get('cloudflare_zone_id', ''));
    $apiToken = trim((string) $this->settings->get('cloudflare_api_token', ''));
    $endpoint = sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', rawurlencode($zoneId));

    $body = $purgeAll
      ? ['purge_everything' => true]
      : ['files' => $urls];

    $response = wp_remote_post($endpoint, [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Bearer ' . $apiToken,
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
      // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message, never echoed directly; caught and logged or wrapped by callers.
      throw new RuntimeException($response->get_error_message());
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $payload = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || empty($payload['success'])) {
      // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message, never echoed directly; caught and logged or wrapped by callers.
      throw new RuntimeException($this->extractCloudflareError($payload, $status));
    }

    $this->logger->info('Cloudflare cache purge completed. Purge all: {purge_all}, URLs: {count}', [
      'purge_all' => $purgeAll ? 'yes' : 'no',
      'count' => count($urls),
    ]);

    return [
      'handled' => true,
      'integration' => $this->id(),
      'purge_all' => $purgeAll,
      'purged_urls' => count($urls),
    ];
  }

  /**
   * @param array<string, mixed>|null $payload
   */
  private function extractCloudflareError(?array $payload, int $status): string
  {
    if (is_array($payload) && !empty($payload['errors']) && is_array($payload['errors'])) {
      $messages = [];
      foreach ($payload['errors'] as $error) {
        if (is_array($error) && !empty($error['message'])) {
          $messages[] = (string) $error['message'];
        }
      }

      if (!empty($messages)) {
        return sprintf('Cloudflare API returned HTTP %d: %s', $status, implode('; ', $messages));
      }
    }

    return sprintf('Cloudflare API returned HTTP %d.', $status);
  }
}