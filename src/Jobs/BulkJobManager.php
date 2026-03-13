<?php
namespace ArunPrakash\MediaOffload\Jobs;

use ArunPrakash\MediaOffload\Config\Settings;
use ArunPrakash\MediaOffload\Media\UploadHandler;
use ArunPrakash\MediaOffload\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class BulkJobManager
{
  private const CRON_HOOK = 'media_offload_bulk_job_tick';
  private const LOCK_TRANSIENT_PREFIX = 'media_offload_bulk_job_lock_';

  private const CURRENT_JOB_DEFAULTS = [
    'id' => '',
    'type' => '',
    'label' => '',
    'status' => 'idle',
    'batch_size' => 25,
    'created_at' => 0,
    'started_at' => 0,
    'updated_at' => 0,
    'finished_at' => 0,
    'total_items' => 0,
    'processed_items' => 0,
    'success_count' => 0,
    'failed_count' => 0,
    'skipped_count' => 0,
    'cursor' => 0,
    'percent_complete' => 0,
    'failed_ids' => [],
    'retry_ids' => [],
    'last_message' => '',
  ];

  private const JOB_TYPES = [
    'offload_existing' => 'Offload existing media',
    'restore_local' => 'Restore local copies',
    'retry_failed' => 'Retry failed items',
  ];

  public function __construct(
    private Settings $settings,
    private UploadHandler $uploadHandler,
    private Logger $logger,
  ) {
  }

  public function register(): void
  {
    add_action(self::CRON_HOOK, [$this, 'processScheduledJob'], 10, 1);
  }

  public function currentJob(): array
  {
    $stored = get_option(MEDIA_OFFLOAD_BULK_JOB_OPTION_KEY, []);
    return $this->normalizeJob(is_array($stored) ? $stored : []);
  }

  public function lastJob(): array
  {
    $stored = get_option(MEDIA_OFFLOAD_BULK_JOB_LAST_OPTION_KEY, []);
    return $this->normalizeJob(is_array($stored) ? $stored : []);
  }

  public function state(): array
  {
    $current = $this->currentJob();
    $last = $this->lastJob();

    return [
      'current' => $current,
      'last' => $last,
      'has_active_job' => $this->isActiveStatus((string) ($current['status'] ?? '')),
      'retry_available' => !empty($this->retrySourceJob()['failed_ids']),
      'available_job_types' => self::JOB_TYPES,
    ];
  }

  /**
   * @return array<string, mixed>|\WP_Error
   */
  public function start(string $type, array $args = [])
  {
    $type = sanitize_key($type);
    if (!isset(self::JOB_TYPES[$type])) {
      return new \WP_Error('media_offload_invalid_job_type', __('Unsupported bulk job type.', MEDIA_OFFLOAD_TEXT_DOMAIN), ['status' => 400]);
    }

    $current = $this->currentJob();
    if ($this->isActiveStatus((string) ($current['status'] ?? ''))) {
      return new \WP_Error('media_offload_job_active', __('A bulk media job is already running. Pause or wait for it to finish first.', MEDIA_OFFLOAD_TEXT_DOMAIN), ['status' => 409]);
    }

    if ($type === 'offload_existing' || $type === 'retry_failed') {
      if (!$this->settings->is_enabled() || !$this->settings->has_valid_credentials()) {
        return new \WP_Error('media_offload_not_ready', __('Storage credentials and enabled offloading are required before starting an offload job.', MEDIA_OFFLOAD_TEXT_DOMAIN), ['status' => 400]);
      }
    }

    if ($type === 'restore_local' && !$this->settings->has_valid_credentials()) {
      return new \WP_Error('media_offload_restore_not_ready', __('Storage credentials are required before restoring local copies from remote storage.', MEDIA_OFFLOAD_TEXT_DOMAIN), ['status' => 400]);
    }

    $batchSize = max(1, min(100, (int) ($args['batch_size'] ?? 25)));
    $retryIds = [];
    $totalItems = 0;

    if ($type === 'offload_existing') {
      $totalItems = $this->countOffloadCandidates();
    } elseif ($type === 'restore_local') {
      $totalItems = $this->countRestoreCandidates();
    } else {
      $source = $this->retrySourceJob();
      $retryIds = array_values(array_unique(array_map('intval', (array) ($source['failed_ids'] ?? []))));
      $totalItems = count($retryIds);

      if ($totalItems < 1) {
        return new \WP_Error('media_offload_no_failed_items', __('There are no failed items available to retry.', MEDIA_OFFLOAD_TEXT_DOMAIN), ['status' => 400]);
      }
    }

    $job = $this->normalizeJob([
      'id' => wp_generate_uuid4(),
      'type' => $type,
      'label' => self::JOB_TYPES[$type],
      'status' => $totalItems > 0 ? 'running' : 'completed',
      'batch_size' => $batchSize,
      'created_at' => time(),
      'started_at' => time(),
      'updated_at' => time(),
      'finished_at' => $totalItems > 0 ? 0 : time(),
      'total_items' => $totalItems,
      'processed_items' => 0,
      'success_count' => 0,
      'failed_count' => 0,
      'skipped_count' => 0,
      'cursor' => 0,
      'percent_complete' => $totalItems > 0 ? 0 : 100,
      'failed_ids' => [],
      'retry_ids' => $retryIds,
      'last_message' => $totalItems > 0 ? __('Bulk media job queued.', MEDIA_OFFLOAD_TEXT_DOMAIN) : __('No matching attachments were found for this bulk job.', MEDIA_OFFLOAD_TEXT_DOMAIN),
    ]);

    $this->persistCurrentJob($job);
    if ($job['status'] === 'running') {
      $this->scheduleNextTick((string) $job['id']);
    } else {
      $this->persistLastJob($job);
    }

    return $job;
  }

  /**
   * @return array<string, mixed>|\WP_Error
   */
  public function pause()
  {
    $job = $this->currentJob();
    if (($job['status'] ?? '') !== 'running') {
      return new \WP_Error('media_offload_job_not_running', __('There is no running bulk job to pause.', MEDIA_OFFLOAD_TEXT_DOMAIN), ['status' => 400]);
    }

    $job['status'] = 'paused';
    $job['updated_at'] = time();
    $job['last_message'] = __('Bulk media job paused.', MEDIA_OFFLOAD_TEXT_DOMAIN);
    $this->persistCurrentJob($job);

    return $job;
  }

  /**
   * @return array<string, mixed>|\WP_Error
   */
  public function resume()
  {
    $job = $this->currentJob();
    if (($job['status'] ?? '') !== 'paused') {
      return new \WP_Error('media_offload_job_not_paused', __('There is no paused bulk job to resume.', MEDIA_OFFLOAD_TEXT_DOMAIN), ['status' => 400]);
    }

    $job['status'] = 'running';
    $job['updated_at'] = time();
    $job['last_message'] = __('Bulk media job resumed.', MEDIA_OFFLOAD_TEXT_DOMAIN);
    $this->persistCurrentJob($job);
    $this->scheduleNextTick((string) $job['id']);

    return $job;
  }

  /**
   * @return array<string, mixed>|\WP_Error
   */
  public function retryFailed()
  {
    return $this->start('retry_failed');
  }

  public function processScheduledJob(string $jobId = ''): void
  {
    $job = $this->currentJob();
    if (($job['id'] ?? '') === '') {
      return;
    }

    if ($jobId !== '' && $jobId !== (string) ($job['id'] ?? '')) {
      return;
    }

    if (($job['status'] ?? '') !== 'running') {
      return;
    }

    $lockKey = self::LOCK_TRANSIENT_PREFIX . md5((string) $job['id']);
    if (get_transient($lockKey)) {
      return;
    }

    set_transient($lockKey, 1, 5 * MINUTE_IN_SECONDS);

    try {
      $batchSize = max(1, (int) ($job['batch_size'] ?? 25));
      $cursor = max(0, (int) ($job['cursor'] ?? 0));
      $ids = $this->nextCandidateIds((string) ($job['type'] ?? ''), $cursor, $batchSize, $job);

      if (empty($ids)) {
        $this->finalizeJob($job);
        return;
      }

      foreach ($ids as $attachmentId) {
        $result = $this->processItem((string) ($job['type'] ?? ''), (int) $attachmentId);
        $job['processed_items'] = (int) $job['processed_items'] + 1;

        if ($result === 'success') {
          $job['success_count'] = (int) $job['success_count'] + 1;
        } elseif ($result === 'failed') {
          $job['failed_count'] = (int) $job['failed_count'] + 1;
          $job['failed_ids'][] = (int) $attachmentId;
        } else {
          $job['skipped_count'] = (int) $job['skipped_count'] + 1;
        }
      }

      $job['failed_ids'] = array_values(array_unique(array_map('intval', (array) $job['failed_ids'])));
      $job['cursor'] = $cursor + count($ids);
      $job['updated_at'] = time();
      $job['percent_complete'] = $this->calculatePercent((int) $job['processed_items'], (int) $job['total_items']);
      $job['last_message'] = sprintf(
        __('Processed %1$d of %2$d attachments.', MEDIA_OFFLOAD_TEXT_DOMAIN),
        (int) $job['processed_items'],
        (int) $job['total_items']
      );

      $this->persistCurrentJob($job);

      if ((int) $job['processed_items'] >= (int) $job['total_items']) {
        $this->finalizeJob($job);
        return;
      }

      if (($job['status'] ?? '') === 'running') {
        $this->scheduleNextTick((string) $job['id']);
      }
    } catch (\Throwable $exception) {
      $job['status'] = 'failed';
      $job['updated_at'] = time();
      $job['finished_at'] = time();
      $job['last_message'] = sprintf(__('Bulk media job failed: %s', MEDIA_OFFLOAD_TEXT_DOMAIN), $exception->getMessage());
      $this->persistCurrentJob($job);
      $this->persistLastJob($job);
      $this->logger->error('Bulk media job {job} failed: {error}', [
        'job' => (string) ($job['id'] ?? ''),
        'error' => $exception->getMessage(),
      ]);
    } finally {
      delete_transient($lockKey);
    }
  }

  private function processItem(string $type, int $attachmentId): string
  {
    if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
      return 'skipped';
    }

    if ($type === 'restore_local') {
      return $this->uploadHandler->download_media($attachmentId) ? 'success' : 'failed';
    }

    return $this->uploadHandler->offload_via_cli($attachmentId) ? 'success' : 'failed';
  }

  private function nextCandidateIds(string $type, int $cursor, int $limit, array $job): array
  {
    if ($type === 'restore_local') {
      return $this->fetchRestoreCandidates($cursor, $limit);
    }

    if ($type === 'retry_failed') {
      return array_slice(array_values(array_map('intval', (array) ($job['retry_ids'] ?? []))), $cursor, $limit);
    }

    return $this->fetchOffloadCandidates($cursor, $limit);
  }

  private function fetchOffloadCandidates(int $afterId, int $limit): array
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return [];
    }

    $eligible = [];
    $cursor = max(0, $afterId);
    $candidateBatchSize = max(50, $limit * 4);

    while (count($eligible) < $limit) {
      $candidateIds = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm
           ON pm.post_id = p.ID
          AND pm.meta_key = %s
         LEFT JOIN {$wpdb->postmeta} im
           ON im.post_id = p.ID
          AND im.meta_key = %s
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND p.ID > %d
           AND pm.meta_id IS NULL
           AND (im.meta_id IS NULL OR im.meta_value != '1')
         ORDER BY p.ID ASC
         LIMIT %d",
        MEDIA_OFFLOAD_META_KEY,
        MEDIA_OFFLOAD_IGNORE_META_KEY,
        'attachment',
        'inherit',
        $cursor,
        $candidateBatchSize
      ));

      if (!is_array($candidateIds) || empty($candidateIds)) {
        break;
      }

      foreach ($this->uploadHandler->filter_eligible_attachment_ids(array_map('intval', $candidateIds)) as $attachmentId) {
        $eligible[] = (int) $attachmentId;
        if (count($eligible) >= $limit) {
          break;
        }
      }

      $cursor = (int) end($candidateIds);
      if (count($candidateIds) < $candidateBatchSize) {
        break;
      }
    }

    return array_slice(array_values(array_unique($eligible)), 0, $limit);
  }

  private function fetchRestoreCandidates(int $afterId, int $limit): array
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return [];
    }

    $ids = $wpdb->get_col($wpdb->prepare(
      "SELECT post_id
       FROM {$wpdb->postmeta}
       WHERE meta_key = %s
         AND post_id > %d
       ORDER BY post_id ASC
       LIMIT %d",
      MEDIA_OFFLOAD_META_KEY,
      max(0, $afterId),
      max(1, $limit)
    ));

    if (!is_array($ids)) {
      return [];
    }

    return array_values(array_filter(array_map('intval', $ids), static function (int $attachmentId): bool {
      return $attachmentId > 0 && get_post_type($attachmentId) === 'attachment';
    }));
  }

  private function countOffloadCandidates(): int
  {
    $count = 0;
    $cursor = 0;
    $batchSize = 500;

    while (true) {
      $ids = $this->fetchOffloadCandidates($cursor, $batchSize);
      if (empty($ids)) {
        break;
      }

      $count += count($ids);
      $cursor = (int) end($ids);

      if (count($ids) < $batchSize) {
        break;
      }
    }

    return $count;
  }

  private function countRestoreCandidates(): int
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(DISTINCT post_id)
       FROM {$wpdb->postmeta}
       WHERE meta_key = %s",
      MEDIA_OFFLOAD_META_KEY
    ));
  }

  private function finalizeJob(array $job): void
  {
    $job['updated_at'] = time();
    $job['finished_at'] = time();
    $job['percent_complete'] = 100;

    if ((int) $job['failed_count'] > 0) {
      $job['status'] = 'completed_with_errors';
      $job['last_message'] = sprintf(
        __('Bulk media job finished with %d failed items.', MEDIA_OFFLOAD_TEXT_DOMAIN),
        (int) $job['failed_count']
      );
    } else {
      $job['status'] = 'completed';
      $job['last_message'] = __('Bulk media job completed.', MEDIA_OFFLOAD_TEXT_DOMAIN);
    }

    $this->persistCurrentJob($job);
    $this->persistLastJob($job);
  }

  private function scheduleNextTick(string $jobId): void
  {
    if ($jobId === '') {
      return;
    }

    wp_schedule_single_event(time() + 2, self::CRON_HOOK, [$jobId]);
  }

  private function retrySourceJob(): array
  {
    $current = $this->currentJob();
    if (!$this->isActiveStatus((string) ($current['status'] ?? '')) && !empty($current['failed_ids'])) {
      return $current;
    }

    return $this->lastJob();
  }

  private function isActiveStatus(string $status): bool
  {
    return in_array($status, ['queued', 'running', 'paused'], true);
  }

  private function persistCurrentJob(array $job): void
  {
    update_option(MEDIA_OFFLOAD_BULK_JOB_OPTION_KEY, $this->normalizeJob($job), false);
  }

  private function persistLastJob(array $job): void
  {
    update_option(MEDIA_OFFLOAD_BULK_JOB_LAST_OPTION_KEY, $this->normalizeJob($job), false);
  }

  private function calculatePercent(int $processed, int $total): int
  {
    if ($total <= 0) {
      return 100;
    }

    return max(0, min(100, (int) round(($processed / $total) * 100)));
  }

  private function normalizeJob(array $job): array
  {
    $normalized = wp_parse_args($job, self::CURRENT_JOB_DEFAULTS);
    $normalized['failed_ids'] = array_values(array_unique(array_map('intval', (array) ($normalized['failed_ids'] ?? []))));
    $normalized['retry_ids'] = array_values(array_unique(array_map('intval', (array) ($normalized['retry_ids'] ?? []))));
    $normalized['batch_size'] = max(1, (int) ($normalized['batch_size'] ?? 25));
    $normalized['total_items'] = max(0, (int) ($normalized['total_items'] ?? 0));
    $normalized['processed_items'] = max(0, (int) ($normalized['processed_items'] ?? 0));
    $normalized['success_count'] = max(0, (int) ($normalized['success_count'] ?? 0));
    $normalized['failed_count'] = max(0, (int) ($normalized['failed_count'] ?? 0));
    $normalized['skipped_count'] = max(0, (int) ($normalized['skipped_count'] ?? 0));
    $normalized['cursor'] = max(0, (int) ($normalized['cursor'] ?? 0));
    $normalized['created_at'] = max(0, (int) ($normalized['created_at'] ?? 0));
    $normalized['started_at'] = max(0, (int) ($normalized['started_at'] ?? 0));
    $normalized['updated_at'] = max(0, (int) ($normalized['updated_at'] ?? 0));
    $normalized['finished_at'] = max(0, (int) ($normalized['finished_at'] ?? 0));
    $normalized['percent_complete'] = max(0, min(100, (int) ($normalized['percent_complete'] ?? 0)));
    $normalized['status_label'] = $this->statusLabel((string) ($normalized['status'] ?? 'idle'));
    $normalized['type_label'] = $normalized['label'] ?: (self::JOB_TYPES[(string) ($normalized['type'] ?? '')] ?? '');

    return $normalized;
  }

  private function statusLabel(string $status): string
  {
    return match ($status) {
      'running' => __('Running', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'paused' => __('Paused', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'completed' => __('Completed', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'completed_with_errors' => __('Completed with errors', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'failed' => __('Failed', MEDIA_OFFLOAD_TEXT_DOMAIN),
      default => __('Idle', MEDIA_OFFLOAD_TEXT_DOMAIN),
    };
  }
}
