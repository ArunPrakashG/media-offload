<?php
namespace ArunPrakash\MediaOffload\Cache;

use ArunPrakash\MediaOffload\Config\Settings;
use ArunPrakash\MediaOffload\Support\Events;
use ArunPrakash\MediaOffload\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class PurgeManager
{
  public function __construct(
    private Settings $settings,
    private Logger $logger,
  ) {
  }

  public function register(): void
  {
    add_action(Events::REMOTE_DELETE_COMPLETED, [$this, 'handleRemoteDeleteCompleted'], 10, 1);
    add_action(Events::REWRITE_COMPLETED, [$this, 'handleRewriteCompleted'], 10, 1);
    add_action(Events::INTEGRATION_REWRITE_COMPLETED, [$this, 'handleIntegrationRewriteCompleted'], 10, 1);
  }

  /**
   * @param array<string, mixed> $payload
   */
  public function handleRemoteDeleteCompleted(array $payload): void
  {
    if (!apply_filters('media_offload_should_purge_cache_on_remote_delete', true, $payload)) {
      return;
    }

    $urls = array_values(array_unique(array_filter(
      array_map('strval', (array) ($payload['urls'] ?? [])),
      static fn (string $url): bool => $url !== ''
    )));

    if (empty($urls)) {
      return;
    }

    $this->requestPurge([
      'reason' => 'remote_delete_completed',
      'scope' => 'attachment',
      'urls' => $urls,
      'context' => $payload,
    ]);
  }

  /**
   * @param array<string, mixed> $payload
   */
  public function handleRewriteCompleted(array $payload): void
  {
    if (($payload['kind'] ?? '') === 'integration') {
      return;
    }

    if (array_key_exists('auto_purge', $payload) && !$payload['auto_purge']) {
      return;
    }

    if (!apply_filters('media_offload_should_purge_cache_on_rewrite', true, $payload)) {
      return;
    }

    if ($this->changedCount($payload) < 1) {
      return;
    }

    $this->requestPurge([
      'reason' => 'rewrite_completed',
      'scope' => (string) ($payload['scope'] ?? 'rewrite'),
      'purge_all' => true,
      'context' => $payload,
    ]);
  }

  /**
   * @param array<string, mixed> $payload
   */
  public function handleIntegrationRewriteCompleted(array $payload): void
  {
    if (array_key_exists('auto_purge', $payload) && !$payload['auto_purge']) {
      return;
    }

    if (!apply_filters('media_offload_should_purge_cache_on_integration_rewrite', true, $payload)) {
      return;
    }

    if ($this->changedCount($payload) < 1) {
      return;
    }

    $this->requestPurge([
      'reason' => 'integration_rewrite_completed',
      'scope' => 'integration',
      'integration' => (string) ($payload['integration'] ?? ''),
      'purge_all' => true,
      'context' => $payload,
    ]);
  }

  /**
   * @param array<string, mixed> $request
   * @return array<string, mixed>
   */
  public function requestPurge(array $request): array
  {
    $request = $this->normalizeRequest($request);
    Events::dispatch(Events::CACHE_PURGE_REQUESTED, $request);

    $integrations = $this->integrations();
    $integrationIds = array_map(static fn (PurgeIntegration $integration): string => $integration->id(), $integrations);

    Events::dispatch(Events::CACHE_PURGE_STARTED, $request + [
      'integrations' => $integrationIds,
    ]);

    $results = [];
    $failures = [];

    foreach ($integrations as $integration) {
      try {
        $result = $integration->purge($request);
        if (!empty($result['handled'])) {
          $results[$integration->id()] = $result;
        }
      } catch (\Throwable $exception) {
        $failures[$integration->id()] = $exception->getMessage();
        $this->logger->warning('Cache purge integration {integration} failed: {error}', [
          'integration' => $integration->id(),
          'error' => $exception->getMessage(),
        ]);
      }
    }

    $summary = $request + [
      'integrations' => $integrationIds,
      'results' => $results,
      'failures' => $failures,
    ];

    if (!empty($failures) && empty($results)) {
      Events::dispatch(Events::CACHE_PURGE_FAILED, $summary);
    } else {
      Events::dispatch(Events::CACHE_PURGE_COMPLETED, $summary);
    }

    return $summary;
  }

  /**
   * @return array<int, PurgeIntegration>
   */
  private function integrations(): array
  {
    $integrations = apply_filters('media_offload_cache_purge_integrations', [
      new CloudflareCachePurgeIntegration($this->settings, $this->logger),
      new LiteSpeedCachePurgeIntegration($this->settings, $this->logger),
      new WpFastestCachePurgeIntegration($this->settings, $this->logger),
      new ProxyCachePurgeIntegration($this->settings, $this->logger),
    ], $this);

    if (!is_array($integrations)) {
      return [];
    }

    return array_values(array_filter($integrations, static fn ($integration): bool => $integration instanceof PurgeIntegration));
  }

  /**
   * @param array<string, mixed> $request
   * @return array<string, mixed>
   */
  private function normalizeRequest(array $request): array
  {
    $urls = array_values(array_unique(array_filter(
      array_map('strval', (array) ($request['urls'] ?? [])),
      static fn (string $url): bool => $url !== ''
    )));

    return [
      'id' => (string) ($request['id'] ?? wp_generate_uuid4()),
      'reason' => (string) ($request['reason'] ?? 'manual'),
      'scope' => (string) ($request['scope'] ?? 'generic'),
      'integration' => (string) ($request['integration'] ?? ''),
      'purge_all' => !empty($request['purge_all']),
      'urls' => $urls,
      'context' => is_array($request['context'] ?? null) ? $request['context'] : [],
      'requested_at' => (int) ($request['requested_at'] ?? time()),
    ];
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function changedCount(array $payload): int
  {
    foreach (['updated_rows', 'posts_updated', 'success', 'processed'] as $key) {
      if (isset($payload[$key])) {
        return max(0, (int) $payload[$key]);
      }
    }

    return 0;
  }
}