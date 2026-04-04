<?php
namespace Nimbus\Plugin\Cache;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Support\Events;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class PurgeManager
{
  private const DEFAULT_BATCH_SIZE = 5;

  private const DEFAULT_SCHEDULE_DELAY = 2;

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
    add_action(NIMBUS_CACHE_PURGE_HOOK, [$this, 'processScheduledPurges']);
  }

  /**
   * @param array<string, mixed> $payload
   */
  public function handleRemoteDeleteCompleted(array $payload): void
  {
    if (!apply_filters('nimbus_should_purge_cache_on_remote_delete', true, $payload)) {
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

    if (!apply_filters('nimbus_should_purge_cache_on_rewrite', true, $payload)) {
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

    if (!apply_filters('nimbus_should_purge_cache_on_integration_rewrite', true, $payload)) {
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

    $defaultAsync = !(wp_doing_cron() && doing_action(NIMBUS_CACHE_PURGE_HOOK));
    $async = (bool) apply_filters('nimbus_cache_purge_async', $defaultAsync, $request, $this);

    Events::dispatch(Events::CACHE_PURGE_REQUESTED, $request + [
      'mode' => $async ? 'queued' : 'inline',
    ]);

    if ($async) {
      return $this->enqueuePurge($request);
    }

    return $this->executePurge($request);
  }

  public function processScheduledPurges(): void
  {
    $batchSize = max(1, (int) apply_filters('nimbus_cache_purge_batch_size', self::DEFAULT_BATCH_SIZE, $this));
    $processed = 0;

    while ($processed < $batchSize) {
      $request = $this->dequeueNextRequest();
      if ($request === null) {
        break;
      }

      $this->executePurge($request + [
        'mode' => 'background',
      ]);

      $processed++;
    }

    if ($processed > 0) {
      $this->logger->info('Processed queued cache purge batch. Requests: {count}', [
        'count' => $processed,
        'source' => 'cache',
        'event' => 'cache_purge_batch',
        'code' => 'cache_purge_batch_processed',
      ]);
    }

    if ($this->hasQueuedRequests()) {
      $this->scheduleWorker();
    }
  }

  /**
   * @param array<string, mixed> $request
   * @return array<string, mixed>
   */
  private function enqueuePurge(array $request): array
  {
    $queue = $this->loadQueue();

    if (!empty($request['purge_all'])) {
      $existingFullPurge = $this->findQueuedFullPurge($queue);
      if (is_array($existingFullPurge)) {
        $this->scheduleWorker();

        return $request + [
          'scheduled' => true,
          'queue_size' => count($queue),
          'coalesced_into' => (string) ($existingFullPurge['id'] ?? ''),
        ];
      }

      $queue = [$request + ['queued_at' => time()]];
    } else {
      $existingFullPurge = $this->findQueuedFullPurge($queue);
      if (is_array($existingFullPurge)) {
        $this->scheduleWorker();

        return $request + [
          'scheduled' => true,
          'queue_size' => count($queue),
          'covered_by_purge_all' => true,
          'coalesced_into' => (string) ($existingFullPurge['id'] ?? ''),
        ];
      }

      $queue[] = $request + ['queued_at' => time()];
    }

    $this->saveQueue($queue);
    $this->scheduleWorker();

    $this->logger->info('Queued cache purge request for background processing. Reason: {reason}, Queue size: {queue_size}', [
      'reason' => (string) ($request['reason'] ?? 'manual'),
      'queue_size' => count($queue),
      'source' => 'cache',
      'event' => 'cache_purge_queue',
      'code' => 'cache_purge_queued',
    ]);

    return $request + [
      'scheduled' => true,
      'queue_size' => count($queue),
    ];
  }

  /**
   * @param array<string, mixed> $request
   * @return array<string, mixed>
   */
  private function executePurge(array $request): array
  {

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
   * @return array<int, array<string, mixed>>
   */
  private function loadQueue(): array
  {
    $queue = get_option(NIMBUS_CACHE_PURGE_QUEUE_OPTION_KEY, []);
    if (!is_array($queue)) {
      return [];
    }

    $normalized = [];
    foreach ($queue as $item) {
      if (!is_array($item)) {
        continue;
      }

      $normalized[] = $this->normalizeRequest($item) + [
        'queued_at' => (int) ($item['queued_at'] ?? time()),
      ];
    }

    return array_values($normalized);
  }

  /**
   * @param array<int, array<string, mixed>> $queue
   */
  private function saveQueue(array $queue): void
  {
    update_option(NIMBUS_CACHE_PURGE_QUEUE_OPTION_KEY, array_values($queue), false);
  }

  /**
   * @param array<int, array<string, mixed>> $queue
   * @return array<string, mixed>|null
   */
  private function findQueuedFullPurge(array $queue): ?array
  {
    foreach ($queue as $item) {
      if (!empty($item['purge_all'])) {
        return $item;
      }
    }

    return null;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function dequeueNextRequest(): ?array
  {
    $queue = $this->loadQueue();
    if (empty($queue)) {
      return null;
    }

    $next = array_shift($queue);
    $this->saveQueue($queue);

    return is_array($next) ? $next : null;
  }

  private function hasQueuedRequests(): bool
  {
    return !empty($this->loadQueue());
  }

  private function scheduleWorker(): void
  {
    if (wp_next_scheduled(NIMBUS_CACHE_PURGE_HOOK)) {
      return;
    }

    wp_schedule_single_event(time() + self::DEFAULT_SCHEDULE_DELAY, NIMBUS_CACHE_PURGE_HOOK);
  }

  /**
   * @return array<int, PurgeIntegration>
   */
  private function integrations(): array
  {
    $integrations = apply_filters('nimbus_cache_purge_integrations', [
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