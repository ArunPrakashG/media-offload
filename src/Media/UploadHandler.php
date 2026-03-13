<?php
namespace ArunPrakash\MediaOffload\Media;

use ArunPrakash\MediaOffload\Config\Settings;
use ArunPrakash\MediaOffload\Storage\StorageAdapter;
use ArunPrakash\MediaOffload\Storage\StorageAdapterFactory;
use ArunPrakash\MediaOffload\Support\Events;
use ArunPrakash\MediaOffload\Support\Logger;
use RuntimeException;

use function ArunPrakash\MediaOffload\Support\buildStorageKey;

if (!defined('ABSPATH')) {
  exit;
}

final class UploadHandler
{
  private const NIGHTLY_BATCH_LOCK_TRANSIENT = 'media_offload_nightly_batch_lock';

  public function __construct(
    private Settings $settings,
    private StorageAdapterFactory $factory,
    private Logger $logger,
  ) {
  }

  public function register(): void
  {
    add_filter('wp_generate_attachment_metadata', [$this, 'filter_attachment_metadata'], 20, 2);
    add_filter('wp_update_attachment_metadata', [$this, 'filter_attachment_metadata'], 20, 2);
    add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 20, 2);
    add_filter('wp_get_attachment_metadata', [$this, 'inject_remote_metadata'], 20, 2);
    add_action('delete_attachment', [$this, 'delete_remote_files'], 20, 1);
    add_action(MEDIA_OFFLOAD_CLEANUP_HOOK, [$this, 'cleanup_local_files'], 20, 1);
    add_action(MEDIA_OFFLOAD_NIGHTLY_BATCH_HOOK, [$this, 'run_nightly_pending_batch'], 20, 0);
    add_action(MEDIA_OFFLOAD_NIGHTLY_WORKER_HOOK, [$this, 'run_nightly_worker'], 20, 4);

    if ($this->settings->should_process_off_hours_batch()) {
      $this->ensure_nightly_schedule();
    } else {
      $this->clear_nightly_schedule();
    }
  }

  public function sync_ignore_state(int $attachment_id, bool $ignored): void
  {
    if ($ignored) {
      update_post_meta($attachment_id, MEDIA_OFFLOAD_IGNORE_META_KEY, '1');
      $this->clear_pending_state($attachment_id);
      $this->clear_missing_source_marker($attachment_id);
      $this->clear_cleanup_schedule($attachment_id);
      $this->reconcile_nightly_batch_schedule();
      return;
    }

    delete_post_meta($attachment_id, MEDIA_OFFLOAD_IGNORE_META_KEY);

    if (!$this->should_offload()) {
      $this->reconcile_nightly_batch_schedule();
      return;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata)) {
      $this->reconcile_nightly_batch_schedule();
      return;
    }

    if ($this->is_attachment_excluded_by_path_rules($attachment_id, $metadata)) {
      $this->clear_pending_state($attachment_id);
      $this->clear_missing_source_marker($attachment_id);
      $this->reconcile_nightly_batch_schedule();
      return;
    }

    $meta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    if (!empty($meta['key'])) {
      $file = $this->resolve_attachment_file_path($attachment_id, $metadata);
      $existingSizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];

      if (!$this->has_pending_local_sizes($file, $metadata, $existingSizes)) {
        $this->clear_pending_state($attachment_id);
        $this->reconcile_nightly_batch_schedule();
        return;
      }
    }

    $this->mark_pending_offload($attachment_id);
    $this->reconcile_nightly_batch_schedule();
  }

  public function is_attachment_eligible_for_offload(int $attachment_id): bool
  {
    if ($attachment_id <= 0 || $this->is_ignored_attachment($attachment_id)) {
      return false;
    }

    return !$this->is_attachment_excluded_by_path_rules($attachment_id);
  }

  public function filter_eligible_attachment_ids(array $attachment_ids): array
  {
    $eligible = [];
    foreach ($attachment_ids as $attachment_id) {
      $attachment_id = (int) $attachment_id;
      if ($attachment_id <= 0) {
        continue;
      }

      if ($this->is_attachment_eligible_for_offload($attachment_id)) {
        $eligible[] = $attachment_id;
        continue;
      }

      $this->clear_pending_state($attachment_id);
      $this->clear_missing_source_marker($attachment_id);
    }

    return array_values(array_unique($eligible));
  }

  public function refresh_schedule_state(): void
  {
    $this->reconcile_nightly_batch_schedule();
  }

  public function filter_attachment_metadata($metadata, int $attachment_id)
  {
    if (!$this->should_offload() || !is_array($metadata)) {
      return $metadata;
    }

    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      $this->clear_pending_state($attachment_id);
      $this->clear_missing_source_marker($attachment_id);
      return $metadata;
    }

    $meta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    if (!empty($meta['key'])) {
      $file = $this->resolve_attachment_file_path($attachment_id, $metadata);
      $existingSizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];

      if (!$this->has_pending_local_sizes($file, $metadata, $existingSizes)) {
        $this->apply_offload_meta_to_metadata($metadata, $meta);
        $this->clear_pending_state($attachment_id);
        return $metadata;
      }
    }

    $this->mark_pending_offload($attachment_id);

    return $metadata;
  }

  public function run_nightly_pending_batch(): void
  {
    if (!$this->settings->should_process_off_hours_batch()) {
      $this->clear_nightly_schedule();
      return;
    }

    $this->logger->info('Nightly batch cron invoked at {time}.', [
      'time' => function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'),
    ]);

    if (get_transient(self::NIGHTLY_BATCH_LOCK_TRANSIENT)) {
      $this->logger->warning('Nightly batch skipped: another batch run is already in progress.');
      return;
    }

    set_transient(self::NIGHTLY_BATCH_LOCK_TRANSIENT, 1, 30 * MINUTE_IN_SECONDS);

    try {
      if (!$this->should_offload()) {
        $this->logger->warning('Nightly batch skipped: plugin disabled or credentials are not configured.');
        return;
      }

      $hours = max(1, (int) apply_filters('media_offload_nightly_hours', 24));
      $workerBatchSize = max(1, (int) apply_filters('media_offload_nightly_limit', $this->settings->off_hours_worker_batch_size()));
      $workerCount = $this->settings->off_hours_worker_count();

      if ($workerCount <= 1) {
        $result = $this->process_pending_offloads($hours, $workerBatchSize, 'nightly-main');
        $this->logger->info('Nightly batch finished. Total: {total}, Success: {success}, Failed: {failed}, Skipped: {skipped}', $result);
      } else {
        $this->dispatch_nightly_workers(wp_generate_uuid4(), $workerCount, $hours, $workerBatchSize);
      }
    } finally {
      delete_transient(self::NIGHTLY_BATCH_LOCK_TRANSIENT);
      $this->reconcile_nightly_batch_schedule();
    }
  }

  public function run_nightly_worker(int $worker_index, string $run_token, int $hours = 24, int $limit = 100): void
  {
    if (!$this->settings->should_process_off_hours_batch() || !$this->should_offload()) {
      return;
    }

    $worker_index = max(1, $worker_index);
    $hours = max(1, $hours);
    $limit = max(1, $limit);

    $lock = 'media_offload_worker_lock_' . md5($run_token . '|' . $worker_index);
    if (get_transient($lock)) {
      $this->logger->warning('Nightly worker {worker} skipped: run lock already active.', ['worker' => $worker_index]);
      return;
    }

    set_transient($lock, 1, 30 * MINUTE_IN_SECONDS);

    try {
      $result = $this->process_pending_offloads($hours, $limit, $run_token . '-w' . $worker_index);
      $this->logger->info('Nightly worker {worker} complete. Total: {total}, Success: {success}, Failed: {failed}, Skipped: {skipped}', [
        'worker' => $worker_index,
        'total' => $result['total'],
        'success' => $result['success'],
        'failed' => $result['failed'],
        'skipped' => $result['skipped'],
      ]);
    } finally {
      delete_transient($lock);
    }
  }

  public function process_pending_offloads(int $hours = 24, int $limit = 500, string $claim_owner = ''): array
  {
    if (!$this->should_offload()) {
      return ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $hours = max(1, $hours);
    $limit = max(1, $limit);
    $claim_owner = $claim_owner !== '' ? $claim_owner : wp_generate_uuid4();
    $claimTtl = $this->settings->off_hours_claim_ttl_seconds();
    $attachmentIds = $this->claim_pending_attachment_ids($hours, $limit, $claim_owner, $claimTtl);

    if (empty($attachmentIds)) {
      return ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $success = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($attachmentIds as $attachmentId) {
      try {
        $metadata = wp_get_attachment_metadata($attachmentId);
        if (!is_array($metadata)) {
          $skipped++;
          continue;
        }

        $file = $this->resolve_attachment_file_path($attachmentId, $metadata);
        $existingMeta = get_post_meta($attachmentId, MEDIA_OFFLOAD_META_KEY, true);
        $existingSizes = is_array($existingMeta['sizes'] ?? null) ? $existingMeta['sizes'] : [];

        if (!empty($existingMeta['key']) && !$this->has_pending_local_sizes($file, $metadata, $existingSizes)) {
          $this->clear_pending_state($attachmentId);
          $skipped++;
          continue;
        }

        if ($this->offload_via_cli($attachmentId)) {
          $success++;
        } else {
          $failed++;
        }
      } finally {
        $this->release_pending_claim($attachmentId, $claim_owner);
      }
    }

    return ['total' => count($attachmentIds), 'success' => $success, 'failed' => $failed, 'skipped' => $skipped];
  }

  public function ensure_nightly_schedule(): void
  {
    $hook = MEDIA_OFFLOAD_NIGHTLY_BATCH_HOOK;

    if (!$this->settings->should_process_off_hours_batch()) {
      $this->clear_nightly_schedule();
      return;
    }

    [$hour, $minute] = $this->resolve_configured_batch_time();

    if (function_exists('wp_get_scheduled_event')) {
      $event = wp_get_scheduled_event($hook);
      if ($event) {
        $isSingle = empty($event->schedule);
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone((string) (get_option('timezone_string') ?: 'UTC'));
        $eventDt = (new \DateTimeImmutable('@' . (int) $event->timestamp))->setTimezone($timezone);
        $sameTime = ((int) $eventDt->format('H') === $hour) && ((int) $eventDt->format('i') === $minute);

        if ($isSingle && $sameTime) {
          return;
        }

        $this->clear_nightly_schedule();
        $this->schedule_next_single_nightly_event();
        return;
      }
    }

    $next = wp_next_scheduled($hook);
    if ($next) {
      $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone((string) (get_option('timezone_string') ?: 'UTC'));
      $nextDt = (new \DateTimeImmutable('@' . $next))->setTimezone($timezone);
      if ((int) $nextDt->format('H') === $hour && (int) $nextDt->format('i') === $minute) {
        return;
      }

      $this->clear_nightly_schedule();
    }

    $this->schedule_next_single_nightly_event();
  }

  public function clear_nightly_schedule(): void
  {
    $this->unschedule_all_events_for_hook(MEDIA_OFFLOAD_NIGHTLY_BATCH_HOOK);
    $this->unschedule_all_events_for_hook(MEDIA_OFFLOAD_NIGHTLY_WORKER_HOOK);
  }

  public function filter_attachment_url(string $url, int $attachment_id): string
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id) || !$this->should_serve_from_storage()) {
      return $url;
    }

    $meta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    if (empty($meta['key'])) {
      $metadata = wp_get_attachment_metadata($attachment_id);
      if (isset($metadata['media_offload']['key'])) {
        $meta = $metadata['media_offload'];
      }
    }

    if (!empty($meta['key'])) {
      try {
        $url = $this->factory->getAdapter()->getUrl($meta['key']);
      } catch (RuntimeException $exception) {
        $this->logger->error('Failed to generate remote URL for attachment {id}: {message}', [
          'id' => $attachment_id,
          'message' => $exception->getMessage(),
        ]);
      }
    }

    return $url;
  }

  public function inject_remote_metadata($metadata, int $attachment_id)
  {
    if (!is_array($metadata) || !$this->is_attachment_eligible_for_offload($attachment_id)) {
      return $metadata;
    }

    $meta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    $this->apply_offload_meta_to_metadata($metadata, $meta);

    return $metadata;
  }

  public function offload_via_cli(int $attachment_id): bool
  {
    $eventContext = [
      'attachment_id' => $attachment_id,
      'source' => 'upload_handler',
      'scope' => 'attachment',
      'provider' => $this->settings->provider(),
    ];

    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      $this->clear_pending_state($attachment_id);
      $this->clear_missing_source_marker($attachment_id);
      Events::dispatch(Events::OFFLOAD_FAILED, $eventContext + [
        'error' => 'Attachment is not eligible for offload.',
      ]);
      return false;
    }

    if (!$this->should_offload()) {
      Events::dispatch(Events::OFFLOAD_FAILED, $eventContext + [
        'error' => 'Offload is disabled or credentials are invalid.',
      ]);
      return false;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata)) {
      Events::dispatch(Events::OFFLOAD_FAILED, $eventContext + [
        'error' => 'Attachment metadata could not be loaded.',
      ]);
      return false;
    }

    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
      $this->mark_missing_source($attachment_id);
      $this->clear_pending_state($attachment_id);
      Events::dispatch(Events::OFFLOAD_FAILED, $eventContext + [
        'error' => 'Attachment source file is missing.',
      ]);
      return false;
    }

    $this->clear_missing_source_marker($attachment_id);
    Events::dispatch(Events::OFFLOAD_STARTED, $eventContext + [
      'file' => $file,
    ]);

    try {
      $offloadMeta = $this->offload_media($attachment_id, $file, $metadata);
      $this->apply_offload_meta_to_metadata($metadata, $offloadMeta);
    } catch (RuntimeException $exception) {
      $this->logger->error('Failed to offload attachment {id} via CLI: {message}', [
        'id' => $attachment_id,
        'message' => $exception->getMessage(),
      ]);
      Events::dispatch(Events::OFFLOAD_FAILED, $eventContext + [
        'file' => $file,
        'error' => $exception->getMessage(),
      ]);
      return false;
    }

    update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);
    $this->clear_pending_state($attachment_id);
    $this->clear_missing_source_marker($attachment_id);

    $keys = $this->collectRemoteKeys($offloadMeta);
    Events::dispatch(Events::OFFLOAD_COMPLETED, $eventContext + [
      'file' => $file,
      'keys' => $keys,
      'urls' => $this->resolve_urls_for_keys($keys),
      'offload_meta' => $offloadMeta,
    ]);

    return true;
  }

  public function delete_remote_files(int $attachment_id): void
  {
    $meta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    $keysToPurge = [];

    if (!empty($meta['key']) && is_string($meta['key'])) {
      $keysToPurge[] = $meta['key'];
      if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
        foreach ($meta['sizes'] as $sizeMeta) {
          if (!empty($sizeMeta['key']) && is_string($sizeMeta['key'])) {
            $keysToPurge[] = $sizeMeta['key'];
          }
        }
      }

      $eventContext = [
        'attachment_id' => $attachment_id,
        'source' => 'upload_handler',
        'scope' => 'attachment',
        'provider' => $this->settings->provider(),
        'keys' => $keysToPurge,
        'urls' => $this->resolve_urls_for_keys($keysToPurge),
      ];

      $deleteFailed = false;
      $didAttemptRemoteDelete = false;

      if ($this->settings->has_valid_credentials()) {
        $didAttemptRemoteDelete = true;
        Events::dispatch(Events::REMOTE_DELETE_STARTED, $eventContext);

        try {
          $adapter = $this->factory->getAdapter();
          $adapter->delete($meta['key']);
          if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeMeta) {
              if (!empty($sizeMeta['key'])) {
                $adapter->delete($sizeMeta['key']);
              }
            }
          }
        } catch (RuntimeException $exception) {
          $deleteFailed = true;
          $this->logger->warning('Failed to delete remote files for attachment {id}: {message}', [
            'id' => $attachment_id,
            'message' => $exception->getMessage(),
          ]);
          Events::dispatch(Events::REMOTE_DELETE_FAILED, $eventContext + [
            'error' => $exception->getMessage(),
          ]);
        }
      }

      if ($didAttemptRemoteDelete && !$deleteFailed) {
        Events::dispatch(Events::REMOTE_DELETE_COMPLETED, $eventContext);
      }

      delete_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY);
    }

    $this->clear_pending_state($attachment_id);
    $this->clear_cleanup_schedule($attachment_id);
    $this->reconcile_nightly_batch_schedule();
  }

  public function cleanup_local_files(int $attachment_id): void
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id) || !$this->settings->should_remove_local()) {
      return;
    }

    $meta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    if (empty($meta['key'])) {
      return;
    }

    $attachedFile = get_attached_file($attachment_id);
    if (is_string($attachedFile) && $attachedFile !== '' && file_exists($attachedFile)) {
      @unlink($attachedFile);
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata) || empty($metadata['sizes'])) {
      return;
    }

    $baseDir = is_string($attachedFile) && $attachedFile !== '' ? dirname($attachedFile) : '';
    foreach ($metadata['sizes'] as $sizeData) {
      if (empty($sizeData['file']) || $baseDir === '') {
        continue;
      }

      $sizePath = function_exists('path_join') ? path_join($baseDir, $sizeData['file']) : trailingslashit($baseDir) . ltrim($sizeData['file'], '/');
      if (file_exists($sizePath)) {
        @unlink($sizePath);
      }
    }
  }

  public function download_media(int $attachment_id): bool
  {
    $meta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    if (empty($meta['key'])) {
      return false;
    }

    $eventContext = [
      'attachment_id' => $attachment_id,
      'source' => 'upload_handler',
      'scope' => 'attachment',
      'provider' => $this->settings->provider(),
      'keys' => $this->collectRemoteKeys($meta),
      'urls' => $this->resolve_urls_for_keys($this->collectRemoteKeys($meta)),
    ];

    Events::dispatch(Events::RESTORE_STARTED, $eventContext);

    try {
      $adapter = $this->factory->getAdapter();
    } catch (RuntimeException $exception) {
      $this->logger->error('Storage adapter not available: {message}', ['message' => $exception->getMessage()]);
      Events::dispatch(Events::RESTORE_FAILED, $eventContext + [
        'error' => $exception->getMessage(),
      ]);
      return false;
    }

    $uploads = wp_upload_dir();
    $metadata = wp_get_attachment_metadata($attachment_id);
    $attachedFile = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (!$attachedFile) {
      $this->logger->error('No _wp_attached_file meta for ID {id}', ['id' => $attachment_id]);
      Events::dispatch(Events::RESTORE_FAILED, $eventContext + [
        'error' => 'Attachment local path metadata is missing.',
      ]);
      return false;
    }

    $localPath = path_join($uploads['basedir'], $attachedFile);
    $localDir = dirname($localPath);
    if (!file_exists($localDir)) {
      wp_mkdir_p($localDir);
    }

    try {
      $adapter->download($meta['key'], $localPath);
    } catch (RuntimeException $exception) {
      $this->logger->error('Failed to download main file for ID {id}', ['id' => $attachment_id]);
      Events::dispatch(Events::RESTORE_FAILED, $eventContext + [
        'error' => $exception->getMessage(),
      ]);
      return false;
    }

    if (!empty($meta['sizes']) && !empty($metadata['sizes'])) {
      foreach ($meta['sizes'] as $size => $sizeMeta) {
        if (empty($sizeMeta['key']) || empty($metadata['sizes'][$size]['file'])) {
          continue;
        }

        $sizePath = path_join($localDir, $metadata['sizes'][$size]['file']);
        try {
          $adapter->download($sizeMeta['key'], $sizePath);
        } catch (RuntimeException $exception) {
          $this->logger->warning('Failed to download size {size} for ID {id}', ['size' => $size, 'id' => $attachment_id]);
        }
      }
    }

    delete_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY);

    if (is_array($metadata)) {
      unset($metadata['media_offload']);
      if (isset($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size => $data) {
          if (isset($data['media_offload'])) {
            unset($metadata['sizes'][$size]['media_offload']);
          }
        }
      }
      update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);
    }

    Events::dispatch(Events::RESTORE_COMPLETED, $eventContext + [
      'local_path' => $localPath,
    ]);

    return true;
  }

  public function get_attachment_status(int $attachment_id): array
  {
    if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
      return ['status' => 'unknown', 'label' => __('Unknown', MEDIA_OFFLOAD_TEXT_DOMAIN), 'reason' => __('Attachment could not be found.', MEDIA_OFFLOAD_TEXT_DOMAIN)];
    }

    $offloadMeta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    $pendingAt = (int) get_post_meta($attachment_id, MEDIA_OFFLOAD_PENDING_AT_META_KEY, true);
    $claimedBy = (string) get_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_BY_META_KEY, true);
    $missingSourceAt = (int) get_post_meta($attachment_id, MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY, true);

    if ($this->is_ignored_attachment($attachment_id)) {
      return ['status' => 'ignored', 'label' => __('Ignored', MEDIA_OFFLOAD_TEXT_DOMAIN), 'reason' => __('This attachment is marked to stay local-only.', MEDIA_OFFLOAD_TEXT_DOMAIN)];
    }

    if (!empty($offloadMeta['key'])) {
      return ['status' => 'offloaded', 'label' => __('Offloaded', MEDIA_OFFLOAD_TEXT_DOMAIN), 'reason' => __('Remote object metadata is present for this attachment.', MEDIA_OFFLOAD_TEXT_DOMAIN)];
    }

    if ($pendingAt > 0 && $claimedBy !== '') {
      return ['status' => 'processing', 'label' => __('Processing', MEDIA_OFFLOAD_TEXT_DOMAIN), 'reason' => __('This attachment is currently claimed by a background worker.', MEDIA_OFFLOAD_TEXT_DOMAIN)];
    }

    if ($pendingAt > 0) {
      return ['status' => 'pending', 'label' => __('Pending', MEDIA_OFFLOAD_TEXT_DOMAIN), 'reason' => __('This attachment is queued for a future offload.', MEDIA_OFFLOAD_TEXT_DOMAIN)];
    }

    if ($missingSourceAt > 0) {
      return ['status' => 'failed', 'label' => __('Needs attention', MEDIA_OFFLOAD_TEXT_DOMAIN), 'reason' => __('The expected local source file is missing, so the attachment could not be offloaded.', MEDIA_OFFLOAD_TEXT_DOMAIN)];
    }

    return ['status' => 'local', 'label' => __('Local only', MEDIA_OFFLOAD_TEXT_DOMAIN), 'reason' => __('This attachment is still being served from local storage.', MEDIA_OFFLOAD_TEXT_DOMAIN)];
  }

  private function dispatch_nightly_workers(string $run_token, int $worker_count, int $hours, int $limit): void
  {
    for ($i = 1; $i <= max(1, $worker_count); $i++) {
      $args = [$i, $run_token, $hours, $limit];
      if (wp_next_scheduled(MEDIA_OFFLOAD_NIGHTLY_WORKER_HOOK, $args)) {
        continue;
      }

      wp_schedule_single_event(time() + 1, MEDIA_OFFLOAD_NIGHTLY_WORKER_HOOK, $args);
    }

    $this->logger->info('Dispatched nightly workers. Count: {count}, Run: {run}', ['count' => $worker_count, 'run' => $run_token]);
  }

  private function schedule_next_single_nightly_event(): void
  {
    if (!$this->settings->should_process_off_hours_batch() || wp_next_scheduled(MEDIA_OFFLOAD_NIGHTLY_BATCH_HOOK)) {
      return;
    }

    [$hour, $minute] = $this->resolve_configured_batch_time();
    wp_schedule_single_event($this->resolve_next_nightly_timestamp($hour, $minute), MEDIA_OFFLOAD_NIGHTLY_BATCH_HOOK);
  }

  private function unschedule_all_events_for_hook(string $hook): void
  {
    $crons = _get_cron_array();
    if (empty($crons) || !is_array($crons)) {
      return;
    }

    foreach ($crons as $timestamp => $cron) {
      if (empty($cron[$hook]) || !is_array($cron[$hook])) {
        continue;
      }

      foreach ($cron[$hook] as $event) {
        $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : [];
        wp_unschedule_event((int) $timestamp, $hook, $args);
      }
    }
  }

  private function offload_media(int $attachment_id, string $file, array &$metadata): array
  {
    $this->logger->info('Starting offload for ID {id}. File: {file}', ['id' => $attachment_id, 'file' => $file]);
    $adapter = $this->factory->getAdapter();
    $uploads = wp_upload_dir();
    $existingOffloadMeta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
    $existingSizes = is_array($existingOffloadMeta['sizes'] ?? null) ? $existingOffloadMeta['sizes'] : [];

    $relativePath = function_exists('wp_relative_upload_path') ? wp_relative_upload_path($file) : false;
    if (!$relativePath) {
      $relativePath = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $file), '/');
    }

    $key = buildStorageKey($relativePath, (string) $this->settings->get('prefix', ''));
    $uploadedKey = $key;

    if (file_exists($file)) {
      if (($existingOffloadMeta['key'] ?? '') !== $key) {
        $uploadedKey = $adapter->upload($file, $key, ['content_type' => get_post_mime_type($attachment_id)]);
      } elseif (!empty($existingOffloadMeta['key']) && is_string($existingOffloadMeta['key'])) {
        $uploadedKey = $existingOffloadMeta['key'];
      }
    } else {
      $this->logger->info('Main file missing, skipping upload: {file}', ['file' => $file]);
      if (!empty($existingOffloadMeta['key']) && is_string($existingOffloadMeta['key'])) {
        $uploadedKey = $existingOffloadMeta['key'];
      }
    }

    $offloadMeta = ['key' => $uploadedKey, 'sizes' => []];
    if (!empty($metadata['sizes'])) {
      $offloadMeta['sizes'] = $this->offload_sizes($attachment_id, $file, $metadata, $adapter, $existingSizes);
    }

    update_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, $offloadMeta);
    if ($this->settings->should_remove_local()) {
      $this->schedule_local_cleanup($attachment_id);
    }

    return $offloadMeta;
  }

  private function offload_sizes(int $attachment_id, string $base_file, array &$metadata, StorageAdapter $adapter, array $existing_sizes = []): array
  {
    $results = [];
    $baseDir = dirname($base_file);
    $this->logger->info('Offloading sizes for ID {id}. Base dir: {dir}', ['id' => $attachment_id, 'dir' => $baseDir]);

    foreach ($metadata['sizes'] as $size => $data) {
      if (empty($data['file'])) {
        continue;
      }

      $sizePath = function_exists('path_join') ? path_join($baseDir, $data['file']) : trailingslashit($baseDir) . ltrim($data['file'], '/');

      if (!empty($existing_sizes[$size]['key'])) {
        $results[$size] = $existing_sizes[$size];
        $metadata['sizes'][$size]['media_offload'] = $existing_sizes[$size];
        continue;
      }

      if (!file_exists($sizePath)) {
        $this->logger->warning('Size file missing: {path}', ['path' => $sizePath]);
        continue;
      }

      $relativePath = function_exists('wp_relative_upload_path') ? wp_relative_upload_path($sizePath) : false;
      if (!$relativePath) {
        $uploads = wp_upload_dir();
        $relativePath = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $sizePath), '/');
      }

      $sizeKey = buildStorageKey($relativePath, (string) $this->settings->get('prefix', ''));

      try {
        $uploadedSizeKey = $adapter->upload($sizePath, $sizeKey, [
          'content_type' => $data['mime-type'] ?? $data['mime_type'] ?? get_post_mime_type($attachment_id) ?? 'image/jpeg',
        ]);
      } catch (RuntimeException $exception) {
        $this->logger->warning('Failed to offload {size} size for attachment {id}: {message}', [
          'size' => $size,
          'id' => $attachment_id,
          'message' => $exception->getMessage(),
        ]);
        continue;
      }

      $results[$size] = ['key' => $uploadedSizeKey];
      $metadata['sizes'][$size]['media_offload'] = ['key' => $uploadedSizeKey];
    }

    return $results;
  }

  private function has_pending_local_sizes(string $base_file, array $metadata, array $existing_sizes = []): bool
  {
    if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
      return false;
    }

    $baseDir = dirname($base_file);
    foreach ($metadata['sizes'] as $size => $data) {
      if (empty($data['file'])) {
        continue;
      }

      $sizePath = function_exists('path_join') ? path_join($baseDir, $data['file']) : trailingslashit($baseDir) . ltrim($data['file'], '/');
      if (!file_exists($sizePath)) {
        continue;
      }

      if (empty($existing_sizes[$size]['key'])) {
        return true;
      }
    }

    return false;
  }

  private function schedule_local_cleanup(int $attachment_id): void
  {
    $delay = max(30, (int) apply_filters('media_offload_cleanup_delay', 180));
    $this->clear_cleanup_schedule($attachment_id);
    wp_schedule_single_event(time() + $delay, MEDIA_OFFLOAD_CLEANUP_HOOK, [$attachment_id]);
  }

  private function clear_cleanup_schedule(int $attachment_id): void
  {
    if (function_exists('wp_clear_scheduled_hook')) {
      wp_clear_scheduled_hook(MEDIA_OFFLOAD_CLEANUP_HOOK, [$attachment_id]);
      return;
    }

    $nextCleanup = wp_next_scheduled(MEDIA_OFFLOAD_CLEANUP_HOOK, [$attachment_id]);
    if ($nextCleanup) {
      wp_unschedule_event($nextCleanup, MEDIA_OFFLOAD_CLEANUP_HOOK, [$attachment_id]);
    }
  }

  private function mark_pending_offload(int $attachment_id): void
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      $this->clear_pending_state($attachment_id);
      return;
    }

    if ((int) get_post_meta($attachment_id, MEDIA_OFFLOAD_PENDING_AT_META_KEY, true) <= 0) {
      update_post_meta($attachment_id, MEDIA_OFFLOAD_PENDING_AT_META_KEY, time());
    }

    $this->ensure_nightly_schedule();
  }

  private function clear_pending_state(int $attachment_id): void
  {
    delete_post_meta($attachment_id, MEDIA_OFFLOAD_PENDING_AT_META_KEY);
    delete_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_BY_META_KEY);
    delete_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_AT_META_KEY);
  }

  private function claim_pending_attachment_ids(int $hours, int $limit, string $claim_owner, int $claim_ttl): array
  {
    $threshold = time() - ($hours * HOUR_IN_SECONDS);
    $missingSourceRetryBefore = time() - $this->missing_source_retry_cooldown_seconds();
    $candidateLimit = max($limit * 4, $limit);

    $query = new \WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => $candidateLimit,
      'fields' => 'ids',
      'meta_key' => MEDIA_OFFLOAD_PENDING_AT_META_KEY,
      'orderby' => 'meta_value_num',
      'order' => 'ASC',
      'meta_query' => [
        'relation' => 'AND',
        ['key' => MEDIA_OFFLOAD_PENDING_AT_META_KEY, 'compare' => 'EXISTS'],
        ['key' => MEDIA_OFFLOAD_PENDING_AT_META_KEY, 'value' => $threshold, 'type' => 'NUMERIC', 'compare' => '>='],
        [
          'relation' => 'OR',
          ['key' => MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY, 'value' => $missingSourceRetryBefore, 'type' => 'NUMERIC', 'compare' => '<'],
        ],
        [
          'relation' => 'OR',
          ['key' => MEDIA_OFFLOAD_IGNORE_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => MEDIA_OFFLOAD_IGNORE_META_KEY, 'value' => '1', 'compare' => '!='],
        ],
      ],
    ]);

    $candidateIds = is_array($query->posts) ? array_map('intval', $query->posts) : [];
    if (empty($candidateIds)) {
      return [];
    }

    $candidateIds = $this->filter_eligible_attachment_ids($candidateIds);
    if (empty($candidateIds)) {
      return [];
    }

    $claimed = [];
    foreach ($candidateIds as $attachmentId) {
      if (count($claimed) >= $limit) {
        break;
      }

      if ($this->try_claim_pending_attachment($attachmentId, $claim_owner, $claim_ttl)) {
        $claimed[] = $attachmentId;
      }
    }

    return $claimed;
  }

  private function try_claim_pending_attachment(int $attachment_id, string $claim_owner, int $claim_ttl): bool
  {
    $currentOwner = (string) get_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_BY_META_KEY, true);
    $currentClaimedAt = (int) get_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_AT_META_KEY, true);

    if ($currentOwner !== '' && $currentOwner !== $claim_owner) {
      $isFreshClaim = $currentClaimedAt > 0 && (time() - $currentClaimedAt) < $claim_ttl;
      if ($isFreshClaim) {
        return false;
      }

      delete_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_BY_META_KEY);
      delete_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_AT_META_KEY);
    }

    if (!add_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_BY_META_KEY, $claim_owner, true)) {
      $owner = (string) get_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_BY_META_KEY, true);
      if ($owner !== $claim_owner) {
        return false;
      }
    }

    update_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_AT_META_KEY, time());
    return true;
  }

  private function release_pending_claim(int $attachment_id, string $claim_owner): void
  {
    $owner = (string) get_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_BY_META_KEY, true);
    if ($owner === '' || $owner === $claim_owner) {
      delete_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_BY_META_KEY);
      delete_post_meta($attachment_id, MEDIA_OFFLOAD_CLAIMED_AT_META_KEY);
    }
  }

  private function has_pending_queue_items(): bool
  {
    $missingSourceRetryBefore = time() - $this->missing_source_retry_cooldown_seconds();

    $query = new \WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => 250,
      'fields' => 'ids',
      'no_found_rows' => true,
      'meta_query' => [
        'relation' => 'AND',
        ['key' => MEDIA_OFFLOAD_PENDING_AT_META_KEY, 'compare' => 'EXISTS'],
        [
          'relation' => 'OR',
          ['key' => MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY, 'value' => $missingSourceRetryBefore, 'type' => 'NUMERIC', 'compare' => '<'],
        ],
        [
          'relation' => 'OR',
          ['key' => MEDIA_OFFLOAD_IGNORE_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => MEDIA_OFFLOAD_IGNORE_META_KEY, 'value' => '1', 'compare' => '!='],
        ],
      ],
    ]);

    $candidateIds = is_array($query->posts) ? array_map('intval', $query->posts) : [];
    return !empty($this->filter_eligible_attachment_ids($candidateIds));
  }

  private function reconcile_nightly_batch_schedule(): void
  {
    if (!$this->settings->should_process_off_hours_batch()) {
      $this->clear_nightly_schedule();
      return;
    }

    if ($this->has_pending_queue_items()) {
      $this->ensure_nightly_schedule();
      return;
    }

    $this->clear_nightly_schedule();
  }

  private function resolve_configured_batch_time(): array
  {
    $time = $this->settings->off_hours_batch_time();
    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
      return [3, 0];
    }

    return [(int) $matches[1], (int) $matches[2]];
  }

  private function resolve_next_nightly_timestamp(int $hour, int $minute = 0): int
  {
    $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone((string) (get_option('timezone_string') ?: 'UTC'));
    $now = new \DateTimeImmutable('now', $timezone);
    $next = $now->setTime($hour, $minute, 0);

    if ($next <= $now) {
      $next = $next->modify('+1 day');
    }

    return $next->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
  }

  private function resolve_attachment_file_path(int $attachment_id, array $metadata): string
  {
    $file = get_attached_file($attachment_id);
    if (is_string($file) && $file !== '') {
      return $file;
    }

    if (!empty($metadata['file']) && is_string($metadata['file'])) {
      $uploads = wp_upload_dir();
      return function_exists('path_join') ? path_join($uploads['basedir'], $metadata['file']) : trailingslashit($uploads['basedir']) . ltrim($metadata['file'], '/');
    }

    return '';
  }

  private function resolve_attachment_relative_path(int $attachment_id, ?array $metadata = null): string
  {
    $attachedFile = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (is_string($attachedFile) && $attachedFile !== '') {
      return ltrim(str_replace('\\', '/', $attachedFile), '/');
    }

    if (is_array($metadata) && !empty($metadata['file']) && is_string($metadata['file'])) {
      return ltrim(str_replace('\\', '/', $metadata['file']), '/');
    }

    $file = get_attached_file($attachment_id);
    if (!is_string($file) || $file === '') {
      return '';
    }

    $relative = function_exists('wp_relative_upload_path') ? wp_relative_upload_path($file) : false;
    return is_string($relative) && $relative !== '' ? ltrim(str_replace('\\', '/', $relative), '/') : '';
  }

  private function resolve_urls_for_keys(array $keys): array
  {
    $keys = array_values(array_unique(array_filter($keys, static fn ($value): bool => is_string($value) && $value !== '')));
    if (empty($keys)) {
      return [];
    }

    try {
      $adapter = $this->factory->getAdapter();
    } catch (RuntimeException $exception) {
      $this->logger->warning('Cannot initialize storage adapter for cache purge URL resolution: {message}', ['message' => $exception->getMessage()]);
      return [];
    }

    $urls = [];
    foreach ($keys as $key) {
      try {
        $url = $adapter->getUrl($key);
      } catch (RuntimeException $exception) {
        $this->logger->warning('Failed to resolve URL for cache purge key {key}: {message}', ['key' => $key, 'message' => $exception->getMessage()]);
        continue;
      }

      if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
        $urls[$url] = true;
      }
    }

    return array_keys($urls);
  }

  /**
   * @param array<string, mixed> $offloadMeta
   * @return array<int, string>
   */
  private function collectRemoteKeys(array $offloadMeta): array
  {
    $keys = [];

    if (!empty($offloadMeta['key']) && is_string($offloadMeta['key'])) {
      $keys[] = $offloadMeta['key'];
    }

    if (!empty($offloadMeta['sizes']) && is_array($offloadMeta['sizes'])) {
      foreach ($offloadMeta['sizes'] as $sizeMeta) {
        if (is_array($sizeMeta) && !empty($sizeMeta['key']) && is_string($sizeMeta['key'])) {
          $keys[] = $sizeMeta['key'];
        }
      }
    }

    return array_values(array_unique($keys));
  }

  private function should_offload(): bool
  {
    return $this->settings->is_enabled() && $this->settings->has_valid_credentials();
  }

  private function should_serve_from_storage(): bool
  {
    return $this->should_offload() && $this->settings->should_serve_from_storage();
  }

  private function apply_offload_meta_to_metadata(array &$metadata, $offload_meta): void
  {
    if (!is_array($offload_meta) || empty($offload_meta['key'])) {
      return;
    }

    $metadata['media_offload'] = $offload_meta;
    if (!empty($offload_meta['sizes']) && !empty($metadata['sizes'])) {
      foreach ($offload_meta['sizes'] as $size => $sizeMeta) {
        if (!empty($metadata['sizes'][$size])) {
          $metadata['sizes'][$size]['media_offload'] = $sizeMeta;
        }
      }
    }
  }

  private function mark_missing_source(int $attachment_id): void
  {
    $now = time();
    $previous = (int) get_post_meta($attachment_id, MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY, true);
    update_post_meta($attachment_id, MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY, $now);

    $cooldown = max(300, (int) apply_filters('media_offload_missing_source_log_cooldown_seconds', 6 * HOUR_IN_SECONDS));
    if ($previous > 0 && ($now - $previous) < $cooldown) {
      return;
    }

    $this->logger->warning('Attachment file missing for ID {id}', ['id' => $attachment_id]);
  }

  private function clear_missing_source_marker(int $attachment_id): void
  {
    delete_post_meta($attachment_id, MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY);
  }

  private function missing_source_retry_cooldown_seconds(): int
  {
    return max(300, (int) apply_filters('media_offload_missing_source_retry_cooldown_seconds', 12 * HOUR_IN_SECONDS));
  }

  private function is_attachment_excluded_by_path_rules(int $attachment_id, ?array $metadata = null): bool
  {
    $relativePath = $this->resolve_attachment_relative_path($attachment_id, $metadata);
    if ($relativePath === '') {
      return false;
    }

    return !$this->settings->should_offload_relative_path($relativePath);
  }

  private function is_ignored_attachment(int $attachment_id): bool
  {
    return (bool) get_post_meta($attachment_id, MEDIA_OFFLOAD_IGNORE_META_KEY, true);
  }
}
