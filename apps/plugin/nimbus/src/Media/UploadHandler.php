<?php
namespace Nimbus\Plugin\Media;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Storage\StorageAdapter;
use Nimbus\Plugin\Storage\StorageAdapterFactory;
use Nimbus\Plugin\Support\Events;
use Nimbus\Plugin\Support\Logger;
use RuntimeException;

use function Nimbus\Plugin\Support\buildStorageKey;

if (!defined('ABSPATH')) {
  exit;
}

final class UploadHandler
{
  private const NIGHTLY_BATCH_LOCK_TRANSIENT = 'nimbus_nightly_batch_lock';

  /**
   * Flat worker cap for the nightly off-hours batch. This used to scale with
   * license tier; now that nightly batching is unconditionally available,
   * everyone gets the same (generous) ceiling.
   */
  private const NIGHTLY_WORKER_CAP = 8;

  private bool $nightlyBatchEnabled = false;

  public function __construct(
    private Settings $settings,
    private StorageAdapterFactory $factory,
    private Logger $logger,
    private AttachmentRepository $repo,
  ) {
  }

  /**
   * Expose the underlying storage adapter (e.g. for admin tooling such as
   * the Create Bucket REST endpoint). The adapter is constructed lazily by
   * StorageAdapterFactory so this causes no overhead when not called.
   */
  public function getAdapter(): \Nimbus\Plugin\Storage\StorageAdapter
  {
    return $this->factory->getAdapter();
  }

  public function register(): void
  {
    add_filter('wp_generate_attachment_metadata', [$this, 'filter_attachment_metadata'], 20, 2);
    add_filter('wp_update_attachment_metadata', [$this, 'filter_attachment_metadata'], 20, 2);
    add_filter('wp_get_attachment_metadata', [$this, 'inject_remote_metadata'], 20, 2);
    add_action('delete_attachment', [$this, 'delete_remote_files'], 20, 1);
    add_action(NIMBUS_CLEANUP_HOOK, [$this, 'cleanup_local_files'], 20, 1);
    // Async single-attachment offload: fires as soon as WP-Cron runs after upload.
    add_action(NIMBUS_OFFLOAD_JOB_HOOK, [$this, 'run_offload_job'], 20, 1);
    // Extend WordPress's filename uniqueness check to cover offloaded files
    // whose local copy may have been removed (e.g. "Remove local copies" enabled).
    add_filter('wp_unique_filename', [$this, 'ensure_unique_filename_for_offloaded'], 10, 6);
  }

  /**
   * Register nightly batch hooks and set up the initial cron schedule.
   * Only called for paid-tier licenses that include the nightly_batch feature.
   */
  public function registerNightlyBatch(): void
  {
    $this->nightlyBatchEnabled = true;
    add_action(NIMBUS_NIGHTLY_BATCH_HOOK, [$this, 'run_nightly_pending_batch'], 20, 0);
    add_action(NIMBUS_NIGHTLY_WORKER_HOOK, [$this, 'run_nightly_worker'], 20, 4);

    if ($this->settings->should_process_off_hours_batch()) {
      $this->ensure_nightly_schedule();
    } else {
      $this->clear_nightly_schedule();
    }
  }

  public function sync_ignore_state(int $attachment_id, bool $ignored): void
  {
    if ($ignored) {
      $this->repo->setIgnored($attachment_id);
      $this->clear_pending_state($attachment_id);
      $this->clear_missing_source_marker($attachment_id);
      $this->clear_cleanup_schedule($attachment_id);
      $this->reconcile_nightly_batch_schedule();
      return;
    }

    $this->repo->clearIgnored($attachment_id);

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

    $meta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
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

    if ($this->is_attachment_excluded_by_path_rules($attachment_id)) {
      return false;
    }

    // Selective offload rules (MIME type + file size) are available when
    // selective offload rules are enabled via the nimbus/upload/selective_offload_enabled filter,
    if (apply_filters('nimbus/upload/selective_offload_enabled', false)) {
      if ($this->is_attachment_excluded_by_size_rules($attachment_id)) {
        return false;
      }

      if ($this->is_attachment_excluded_by_mime_rules($attachment_id)) {
        return false;
      }
    }

    return true;
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

    // If already fully offloaded and no new local sizes pending, just annotate metadata
    // and return — the DB URL is already correct.
    $meta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
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

    if ($this->nightlyBatchEnabled && $this->settings->should_process_off_hours_batch()) {
      // Nightly batch is active and the off-hours setting is enabled: the
      // off-hours worker will offload this attachment (and rewrite its DB URL)
      // at the scheduled time. Do not queue an immediate async job to avoid
      // double-processing.
      return $metadata;
    }

    // No nightly batch — schedule an immediate async WP-Cron job so the
    // attachment is offloaded and its DB URL rewritten as soon as possible.
    if (!wp_next_scheduled(NIMBUS_OFFLOAD_JOB_HOOK, [$attachment_id])) {
      wp_schedule_single_event(time(), NIMBUS_OFFLOAD_JOB_HOOK, [$attachment_id]);
    }

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

      // Default lookback is 48 h (2 days) so any upload from the previous
      // day is guaranteed to be caught even if the cron ran slightly late.
      $hours = max(1, (int) apply_filters('nimbus_nightly_hours', 48));
      $workerBatchSize = max(1, (int) apply_filters('nimbus_nightly_limit', $this->settings->off_hours_worker_batch_size()));
      $workerCount = $this->settings->off_hours_worker_count();
      $workerCount = min($workerCount, self::NIGHTLY_WORKER_CAP);

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

    $lock = 'nimbus_worker_lock_' . md5($run_token . '|' . $worker_index);
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
        $existingMeta = get_post_meta($attachmentId, NIMBUS_META_KEY, true);
        $existingSizes = is_array($existingMeta['sizes'] ?? null) ? $existingMeta['sizes'] : [];

        if (!empty($existingMeta['key']) && !$this->has_pending_local_sizes($file, $metadata, $existingSizes)) {
          $this->clear_pending_state($attachmentId);
          $skipped++;
          continue;
        }

        if ($this->offload_via_cli($attachmentId)) {
          $this->rewrite_attachment_url_in_db($attachmentId);
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
    $hook = NIMBUS_NIGHTLY_BATCH_HOOK;

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
    $this->unschedule_all_events_for_hook(NIMBUS_NIGHTLY_BATCH_HOOK);
    $this->unschedule_all_events_for_hook(NIMBUS_NIGHTLY_WORKER_HOOK);
  }

  public function filter_attachment_url(string $url, int $attachment_id): string
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id) || !$this->should_serve_from_storage()) {
      return $url;
    }

    $meta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
    if (empty($meta['key'])) {
      $metadata = wp_get_attachment_metadata($attachment_id);
      if (isset($metadata['nimbus']['key'])) {
        $meta = $metadata['nimbus'];
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

    $meta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
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

  /**
   * WP-Cron callback for NIMBUS_OFFLOAD_JOB_HOOK.
   *
   * Runs after the upload request has finished.  Offloads the attachment to
   * cloud storage and, on success, rewrites its URL in the database so that
   * WordPress natively returns the CDN URL for every query \u2014 no runtime
   * filter interception needed.
   */
  public function run_offload_job(int $attachment_id): void
  {
    if (!$this->should_offload() || !$this->is_attachment_eligible_for_offload($attachment_id)) {
      $this->clear_pending_state($attachment_id);
      return;
    }

    $success = $this->offload_via_cli($attachment_id);
    if (!$success) {
      // offload_via_cli already logged and dispatched OFFLOAD_FAILED.
      return;
    }

    $this->rewrite_attachment_url_in_db($attachment_id);
  }

  /**
   * After a successful offload, rewrite the stored file path / URL in the
   * database so WordPress returns the CDN URL directly without filter
   * interception.
   *
   * - `_wp_attached_file`          \u2192 relative CDN path (stored as-is by WP convention)
   * - `_wp_attachment_metadata`    \u2192 `file` key updated to remote path
   * - `post_content` / `post_excerpt` in posts \u2192 local upload URL replaced with CDN URL
   */
  public function rewrite_attachment_url_in_db(int $attachment_id): void
  {
    $meta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
    if (empty($meta['key']) || !is_string($meta['key'])) {
      return;
    }

    try {
      $remoteUrl = $this->factory->getAdapter()->getUrl($meta['key']);
    } catch (\Throwable $e) {
      $this->logger->warning('rewrite_attachment_url_in_db: could not resolve CDN URL for {id}: {msg}', [
        'id' => $attachment_id,
        'msg' => $e->getMessage(),
      ]);
      return;
    }

    // Capture local URL before we overwrite _wp_attached_file.
    $localPath = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
    $uploads   = wp_upload_dir();
    $localUrl  = trailingslashit($uploads['baseurl']) . ltrim($localPath, '/');

    // 1. Update _wp_attached_file to the remote key (CDN-relative path).
    update_post_meta($attachment_id, '_wp_attached_file', $meta['key']);

    // 2. Update the file key inside _wp_attachment_metadata.
    $wpMeta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
    if (is_array($wpMeta)) {
      $wpMeta['file'] = $meta['key'];

      // Update per-size file paths to their remote keys too.
      if (!empty($meta['sizes']) && is_array($meta['sizes']) && !empty($wpMeta['sizes'])) {
        foreach ($meta['sizes'] as $sizeName => $sizeMeta) {
          if (!empty($sizeMeta['key']) && isset($wpMeta['sizes'][$sizeName])) {
            $wpMeta['sizes'][$sizeName]['file'] = basename($sizeMeta['key']);
          }
        }
      }

      update_post_meta($attachment_id, '_wp_attachment_metadata', $wpMeta);
    }

    // 3. Replace local upload URL with CDN URL in post_content / post_excerpt.
    if ($localUrl !== '' && $localUrl !== $remoteUrl) {
      $this->replace_url_in_post_content($localUrl, $remoteUrl);
    }

    $this->logger->info('DB URL rewrite complete for attachment {id}. Remote: {url}', [
      'id'  => $attachment_id,
      'url' => $remoteUrl,
    ]);
  }

  /**
   * Revert a DB-rewritten attachment back to its original local URL.
   * Called when the attachment is restored to local storage via download_media().
   */
  public function revert_attachment_url_in_db(int $attachment_id, string $remoteUrl, string $localUrl): void
  {
    if ($remoteUrl === '' || $localUrl === '' || $remoteUrl === $localUrl) {
      return;
    }

    // Restore _wp_attached_file to local relative path.
    $uploads      = wp_upload_dir();
    $relativeLocal = ltrim(str_replace(trailingslashit($uploads['baseurl']), '', $localUrl), '/');
    update_post_meta($attachment_id, '_wp_attached_file', $relativeLocal);

    // Restore file key in _wp_attachment_metadata.
    $wpMeta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
    if (is_array($wpMeta)) {
      $wpMeta['file'] = $relativeLocal;
      update_post_meta($attachment_id, '_wp_attachment_metadata', $wpMeta);
    }

    // Replace CDN URL back to local URL in post content.
    $this->replace_url_in_post_content($remoteUrl, $localUrl);

    $this->logger->info('DB URL revert complete for attachment {id}. Local: {url}', [
      'id'  => $attachment_id,
      'url' => $localUrl,
    ]);
  }

  /**
   * Low-level helper: search-and-replace a URL in wp_posts (post_content,
   * post_excerpt, guid) and relevant postmeta columns.
   *
   * Uses direct wpdb queries scoped to affected rows for performance.
   */
  private function replace_url_in_post_content(string $fromUrl, string $toUrl): void
  {
    if ($fromUrl === '' || $toUrl === '' || $fromUrl === $toUrl) {
      return;
    }

    global $wpdb;

    // Build all URL variant pairs (http, https, protocol-relative, slash-escaped JSON).
    $pairs = $this->build_url_replacement_pairs($fromUrl, $toUrl);

    foreach ($pairs as [$from, $to]) {
      if ($from === '' || $from === $to) {
        continue;
      }

      // post_content
      // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
      $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
        $from,
        $to,
        '%' . $wpdb->esc_like($from) . '%'
      ));

      // post_excerpt
      $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->posts} SET post_excerpt = REPLACE(post_excerpt, %s, %s) WHERE post_excerpt LIKE %s",
        $from,
        $to,
        '%' . $wpdb->esc_like($from) . '%'
      ));
      // phpcs:enable
    }
  }

  /**
   * Build URL variant pairs covering http/https, protocol-relative, and
   * JSON-escaped slash variants so a single call handles all encodings.
   *
   * @return array<array{0: string, 1: string}>
   */
  private function build_url_replacement_pairs(string $from, string $to): array
  {
    $from  = untrailingslashit($from);
    $to    = untrailingslashit($to);
    $fromS = trailingslashit($from);
    $toS   = trailingslashit($to);

    $pairs = [
      [$from, $to],
      [$fromS, $toS],
      [str_replace('/', '\\/', $from), str_replace('/', '\\/', $to)],
      [str_replace('/', '\\/', $fromS), str_replace('/', '\\/', $toS)],
      [rawurlencode($from), rawurlencode($to)],
    ];

    // Ensure both http and https variants are covered regardless of the
    // scheme stored in the DB.
    foreach (['http', 'https'] as $scheme) {
      $fromVariant  = preg_replace('#^https?://#', $scheme . '://', $from)  ?? $from;
      $toVariant    = preg_replace('#^https?://#', $scheme . '://', $to)    ?? $to;
      $fromVariantS = trailingslashit($fromVariant);
      $toVariantS   = trailingslashit($toVariant);
      $pairs[]      = [$fromVariant, $toVariant];
      $pairs[]      = [$fromVariantS, $toVariantS];
    }

    // Deduplicate.
    $seen = [];
    $unique = [];
    foreach ($pairs as $pair) {
      $key = $pair[0] . "\x00" . $pair[1];
      if (!isset($seen[$key]) && $pair[0] !== $pair[1] && $pair[0] !== '') {
        $seen[$key] = true;
        $unique[] = $pair;
      }
    }

    return $unique;
  }

  public function delete_remote_files(int $attachment_id): void
  {
    // Cancel any pending async offload job for this attachment.
    $scheduled = wp_next_scheduled(NIMBUS_OFFLOAD_JOB_HOOK, [$attachment_id]);
    if ($scheduled) {
      wp_unschedule_event($scheduled, NIMBUS_OFFLOAD_JOB_HOOK, [$attachment_id]);
    }

    $meta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
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

      if ($this->settings->has_valid_credentials() && $this->settings->should_delete_remote_on_wp_delete()) {
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

      delete_post_meta($attachment_id, NIMBUS_META_KEY);
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

    $meta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
    if (empty($meta['key'])) {
      return;
    }

    $attachedFile = get_attached_file($attachment_id);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Plain writability check on the attachment's own uploads-dir path; WP_Filesystem would require credential prompts inappropriate for same-server file cleanup.
    if (is_string($attachedFile) && $attachedFile !== '' && file_exists($attachedFile) && is_writable($attachedFile)) {
      wp_delete_file($attachedFile);
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
      // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Plain writability check on an image-size variant path in the uploads dir; WP_Filesystem would require credential prompts inappropriate for same-server file cleanup.
      if (file_exists($sizePath) && is_writable($sizePath)) {
        wp_delete_file($sizePath);
      }
    }
  }

  public function download_media(int $attachment_id): bool
  {
    $meta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
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

    delete_post_meta($attachment_id, NIMBUS_META_KEY);

    if (is_array($metadata)) {
      unset($metadata['nimbus']);
      if (isset($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size => $data) {
          if (isset($data['nimbus'])) {
            unset($metadata['sizes'][$size]['nimbus']);
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
      return ['status' => 'unknown', 'label' => __('Unknown', 'arun-prakash-media-offload'), 'reason' => __('Attachment could not be found.', 'arun-prakash-media-offload')];
    }

    $offloadMeta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
    $pendingAt = (int) get_post_meta($attachment_id, NIMBUS_PENDING_AT_META_KEY, true);
    $claimedBy = (string) get_post_meta($attachment_id, NIMBUS_CLAIMED_BY_META_KEY, true);
    $missingSourceAt = (int) get_post_meta($attachment_id, NIMBUS_MISSING_SOURCE_AT_META_KEY, true);

    if ($this->is_ignored_attachment($attachment_id)) {
      return ['status' => 'ignored', 'label' => __('Ignored', 'arun-prakash-media-offload'), 'reason' => __('This attachment is marked to stay local-only.', 'arun-prakash-media-offload')];
    }

    if (!empty($offloadMeta['key'])) {
      return ['status' => 'offloaded', 'label' => __('Offloaded', 'arun-prakash-media-offload'), 'reason' => __('Remote object metadata is present for this attachment.', 'arun-prakash-media-offload')];
    }

    if ($pendingAt > 0 && $claimedBy !== '') {
      return ['status' => 'processing', 'label' => __('Processing', 'arun-prakash-media-offload'), 'reason' => __('This attachment is currently claimed by a background worker.', 'arun-prakash-media-offload')];
    }

    if ($pendingAt > 0) {
      return ['status' => 'pending', 'label' => __('Pending', 'arun-prakash-media-offload'), 'reason' => __('This attachment is queued for a future offload.', 'arun-prakash-media-offload')];
    }

    if ($missingSourceAt > 0) {
      return ['status' => 'failed', 'label' => __('Needs attention', 'arun-prakash-media-offload'), 'reason' => __('The expected local source file is missing, so the attachment could not be offloaded.', 'arun-prakash-media-offload')];
    }

    return ['status' => 'local', 'label' => __('Local only', 'arun-prakash-media-offload'), 'reason' => __('This attachment is still being served from local storage.', 'arun-prakash-media-offload')];
  }

  private function dispatch_nightly_workers(string $run_token, int $worker_count, int $hours, int $limit): void
  {
    for ($i = 1; $i <= max(1, $worker_count); $i++) {
      $args = [$i, $run_token, $hours, $limit];
      if (wp_next_scheduled(NIMBUS_NIGHTLY_WORKER_HOOK, $args)) {
        continue;
      }

      wp_schedule_single_event(time() + 1, NIMBUS_NIGHTLY_WORKER_HOOK, $args);
    }

    $this->logger->info('Dispatched nightly workers. Count: {count}, Run: {run}', ['count' => $worker_count, 'run' => $run_token]);
  }

  private function schedule_next_single_nightly_event(): void
  {
    if (!$this->settings->should_process_off_hours_batch() || wp_next_scheduled(NIMBUS_NIGHTLY_BATCH_HOOK)) {
      return;
    }

    [$hour, $minute] = $this->resolve_configured_batch_time();
    wp_schedule_single_event($this->resolve_next_nightly_timestamp($hour, $minute), NIMBUS_NIGHTLY_BATCH_HOOK);
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
    $existingOffloadMeta = get_post_meta($attachment_id, NIMBUS_META_KEY, true);
    $existingSizes = is_array($existingOffloadMeta['sizes'] ?? null) ? $existingOffloadMeta['sizes'] : [];

    $relativePath = function_exists('wp_relative_upload_path') ? wp_relative_upload_path($file) : false;
    if (!$relativePath) {
      $relativePath = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $file), '/');
    }

    $key = buildStorageKey($relativePath, (string) $this->settings->get('prefix', ''));
    $uploadedKey = $key;
    $fileSizeBytes = file_exists($file) ? (int) filesize($file) : 0;

    if (file_exists($file)) {
      if (($existingOffloadMeta['key'] ?? '') !== $key) {
        $key = $this->dedup_key($adapter, $key);
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

    $offloadMeta = ['key' => $uploadedKey, 'sizes' => [], 'file_size_bytes' => $fileSizeBytes];
    if (!empty($metadata['sizes'])) {
      $offloadMeta['sizes'] = $this->offload_sizes($attachment_id, $file, $metadata, $adapter, $existingSizes);
    }

    $this->repo->setOffloadMeta($attachment_id, $offloadMeta);
    if ($this->settings->should_remove_local()) {
      $this->schedule_local_cleanup($attachment_id);
    }

    return $offloadMeta;
  }

  /**
   * Extends WordPress's filename uniqueness check to cover offloaded
   * attachments whose local copies may have been removed.
   *
   * WordPress's built-in wp_unique_filename() only checks the local
   * filesystem. When "Remove local copies" is enabled, the local file is
   * deleted after a successful offload. A subsequent upload of a file with
   * the same name then bypasses the conflict check — WordPress assigns the
   * same filename and the new upload physically overwrites the old local path.
   *
   * This filter fires after WordPress has ensured the proposed filename is
   * unique on disk. We additionally query the postmeta table for any existing
   * attachment whose _wp_attached_file matches the relative path the new file
   * would receive. The check covers both the pre-rewrite state (plain
   * relative path, e.g. "2026/04/123.jpeg") and the post-rewrite state
   * (storage key with optional prefix, e.g. "my-prefix/2026/04/123.jpeg"),
   * since rewrite_attachment_url_in_db overwrites _wp_attached_file with the
   * full storage key.
   *
   * @param string       $filename      WordPress-proposed unique filename.
   * @param string       $ext           File extension WITH leading dot (e.g. ".jpg").
   * @param string       $dir           Absolute filesystem path to the upload directory.
   * @param callable|null $callback     Unused; passed through from WordPress.
   * @param string[]     $alt_filenames Unused; alternate filenames WordPress checked.
   * @param int|string   $number        Counter WordPress appended ('' if none, int otherwise).
   */
  public function ensure_unique_filename_for_offloaded(
    string $filename,
    string $ext,
    string $dir,
    $callback,
    array $alt_filenames,
    $number
  ): string {
    // The DB lookup is only necessary when local copies are removed.
    // If local files are kept, WordPress's own filesystem check in
    // wp_unique_filename() is sufficient — no DB query needed.
    if (!$this->should_offload() || !$this->settings->should_remove_local()) {
      return $filename;
    }

    $uploads     = wp_upload_dir();
    $relativeDir = ltrim(str_replace(trailingslashit($uploads['basedir']), '', trailingslashit($dir)), '/');

    if (!$this->db_has_attachment_at_path($relativeDir . $filename)) {
      return $filename;
    }

    // DB collision found. WordPress may have already appended "-N" to avoid a
    // disk conflict (stored in $number). We use $number to strip only that exact
    // suffix — not any digits that are part of the original filename (e.g.
    // "photo-2022.jpeg" must not be stripped to "photo").
    //
    // NOTE: WordPress passes $ext WITH a leading dot (e.g. ".jpg"), so we must
    // NOT add another dot. Use $ext directly as the extension-with-dot.
    //
    // Then we continue incrementing from N+1 so we never re-check the already-
    // known collision and minimise DB round-trips.
    $ext_with_dot = $ext; // already includes the leading dot (e.g. ".jpg")
    $base         = $ext !== '' ? substr($filename, 0, -strlen($ext)) : $filename;

    $number_int = (int) $number;
    if ($number !== '' && $number_int > 0 && str_ends_with($base, '-' . $number_int)) {
      // WordPress appended "-{$number_int}"; strip it so we increment from the
      // true root (e.g. "123-1" → "123", counter starts at 2).
      $base_root = substr($base, 0, -strlen('-' . $number_int));
      $counter   = $number_int + 1;
    } else {
      // WordPress did not append a counter (local file was already gone);
      // use the full base as-is and start from 1.
      $base_root = $base;
      $counter   = 1;
    }

    do {
      $candidate = $base_root . '-' . $counter . $ext_with_dot;
      $counter++;
    } while (
      file_exists(trailingslashit($dir) . $candidate) ||
      $this->db_has_attachment_at_path($relativeDir . $candidate)
    );

    return $candidate;
  }

  /**
   * Check whether any existing attachment in postmeta already uses the given
   * relative upload path as its _wp_attached_file value.
   *
   * This covers two states:
   *  - Pre-rewrite / no prefix: _wp_attached_file = "2026/04/123.jpeg"
   *  - Post-rewrite with prefix: _wp_attached_file = "my-prefix/2026/04/123.jpeg"
   */
  private function db_has_attachment_at_path(string $relativePath): bool
  {
    global $wpdb;

    $prefix     = trim((string) $this->settings->get('prefix', ''), '/');
    $storageKey = $prefix !== '' ? $prefix . '/' . $relativePath : $relativePath;

    if ($relativePath === $storageKey) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Path-collision check must reflect the current DB state at upload time; not cacheable.
      $result = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_wp_attached_file' AND meta_value = %s
         LIMIT 1",
        $relativePath
      ));
    } else {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Path-collision check must reflect the current DB state at upload time; not cacheable.
      $result = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_wp_attached_file' AND meta_value IN (%s, %s)
         LIMIT 1",
        $relativePath,
        $storageKey
      ));
    }

    return $result !== null;
  }

  /**
   * Returns $key unchanged if it does not exist in the bucket, or a new key
   * with a random 8-hex-char suffix inserted before the extension to avoid
   * overwriting an unrelated file that happens to share the same path.
   */
  private function dedup_key(StorageAdapter $adapter, string $key): string
  {
    if (!$adapter->exists($key)) {
      return $key;
    }

    $ext  = pathinfo($key, PATHINFO_EXTENSION);
    $base = $ext !== '' ? substr($key, 0, -(strlen($ext) + 1)) : $key;

    for ($attempt = 0; $attempt < 10; $attempt++) {
      $suffix    = bin2hex(random_bytes(4)); // 8 hex chars
      $candidate = $ext !== '' ? "{$base}-{$suffix}.{$ext}" : "{$base}-{$suffix}";
      if (!$adapter->exists($candidate)) {
        $this->logger->info('Remote key collision on "{key}", using "{candidate}" instead.', [
          'key'       => $key,
          'candidate' => $candidate,
        ]);
        return $candidate;
      }
    }

    // Fallback for the pathological case where 10 random suffixes all collide.
    $suffix   = time() . '-' . bin2hex(random_bytes(4));
    $fallback = $ext !== '' ? "{$base}-{$suffix}.{$ext}" : "{$base}-{$suffix}";
    $this->logger->warning('Key dedup exhausted retries for "{key}", falling back to "{fallback}".', [
      'key'      => $key,
      'fallback' => $fallback,
    ]);
    return $fallback;
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
        $metadata['sizes'][$size]['nimbus'] = $existing_sizes[$size];
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
      $sizeKey = $this->dedup_key($adapter, $sizeKey);

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

      $results[$size] = ['key' => $uploadedSizeKey, 'file_size_bytes' => (int) filesize($sizePath)];
      $metadata['sizes'][$size]['nimbus'] = ['key' => $uploadedSizeKey];
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
    $delay = max(30, (int) apply_filters('nimbus_cleanup_delay', 180));
    $this->clear_cleanup_schedule($attachment_id);
    wp_schedule_single_event(time() + $delay, NIMBUS_CLEANUP_HOOK, [$attachment_id]);
  }

  private function clear_cleanup_schedule(int $attachment_id): void
  {
    if (function_exists('wp_clear_scheduled_hook')) {
      wp_clear_scheduled_hook(NIMBUS_CLEANUP_HOOK, [$attachment_id]);
      return;
    }

    $nextCleanup = wp_next_scheduled(NIMBUS_CLEANUP_HOOK, [$attachment_id]);
    if ($nextCleanup) {
      wp_unschedule_event($nextCleanup, NIMBUS_CLEANUP_HOOK, [$attachment_id]);
    }
  }

  private function mark_pending_offload(int $attachment_id): void
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      $this->clear_pending_state($attachment_id);
      return;
    }

    $this->repo->markPending($attachment_id);
    $this->ensure_nightly_schedule();
  }

  private function clear_pending_state(int $attachment_id): void
  {
    $this->repo->clearPendingState($attachment_id);
  }

  private function claim_pending_attachment_ids(int $hours, int $limit, string $claim_owner, int $claim_ttl): array
  {
    $threshold = time() - ($hours * HOUR_IN_SECONDS);
    $missingSourceRetryBefore = time() - $this->missing_source_retry_cooldown_seconds();
    $candidateLimit = max($limit * 4, $limit);

    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Offload status is only tracked via postmeta; there is no faster native alternative for finding pending attachments.
    $query = new \WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => $candidateLimit,
      'fields' => 'ids',
      'meta_key' => NIMBUS_PENDING_AT_META_KEY,
      'orderby' => 'meta_value_num',
      'order' => 'ASC',
      'meta_query' => [
        'relation' => 'AND',
        ['key' => NIMBUS_PENDING_AT_META_KEY, 'compare' => 'EXISTS'],
        ['key' => NIMBUS_PENDING_AT_META_KEY, 'value' => $threshold, 'type' => 'NUMERIC', 'compare' => '>='],
        [
          'relation' => 'OR',
          ['key' => NIMBUS_MISSING_SOURCE_AT_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => NIMBUS_MISSING_SOURCE_AT_META_KEY, 'value' => $missingSourceRetryBefore, 'type' => 'NUMERIC', 'compare' => '<'],
        ],
        [
          'relation' => 'OR',
          ['key' => NIMBUS_IGNORE_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => NIMBUS_IGNORE_META_KEY, 'value' => '1', 'compare' => '!='],
        ],
      ],
    ]);
    // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query

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
    return $this->repo->claim($attachment_id, $claim_owner, $claim_ttl);
  }

  private function release_pending_claim(int $attachment_id, string $claim_owner): void
  {
    $owner = $this->repo->getClaimedBy($attachment_id);
    if ($owner === '' || $owner === $claim_owner) {
      $this->repo->releaseClaim($attachment_id);
    }
  }

  private function has_pending_queue_items(): bool
  {
    $missingSourceRetryBefore = time() - $this->missing_source_retry_cooldown_seconds();

    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Offload status is only tracked via postmeta; there is no faster native alternative for finding pending attachments.
    $query = new \WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => 250,
      'fields' => 'ids',
      'no_found_rows' => true,
      'meta_query' => [
        'relation' => 'AND',
        ['key' => NIMBUS_PENDING_AT_META_KEY, 'compare' => 'EXISTS'],
        [
          'relation' => 'OR',
          ['key' => NIMBUS_MISSING_SOURCE_AT_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => NIMBUS_MISSING_SOURCE_AT_META_KEY, 'value' => $missingSourceRetryBefore, 'type' => 'NUMERIC', 'compare' => '<'],
        ],
        [
          'relation' => 'OR',
          ['key' => NIMBUS_IGNORE_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => NIMBUS_IGNORE_META_KEY, 'value' => '1', 'compare' => '!='],
        ],
      ],
    ]);
    // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

    $candidateIds = is_array($query->posts) ? array_map('intval', $query->posts) : [];
    return !empty($this->filter_eligible_attachment_ids($candidateIds));
  }

  private function reconcile_nightly_batch_schedule(): void
  {
    if (!$this->nightlyBatchEnabled) {
      return;
    }

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

    $metadata['nimbus'] = $offload_meta;
    if (!empty($offload_meta['sizes']) && !empty($metadata['sizes'])) {
      foreach ($offload_meta['sizes'] as $size => $sizeMeta) {
        if (!empty($metadata['sizes'][$size])) {
          $metadata['sizes'][$size]['nimbus'] = $sizeMeta;
        }
      }
    }
  }

  private function mark_missing_source(int $attachment_id): void
  {
    $now = time();
    $previous = $this->repo->getMissingSourceAt($attachment_id);
    $this->repo->markMissingSource($attachment_id);

    $cooldown = max(300, (int) apply_filters('nimbus_missing_source_log_cooldown_seconds', 6 * HOUR_IN_SECONDS));
    if ($previous > 0 && ($now - $previous) < $cooldown) {
      return;
    }

    $this->logger->warning('Attachment file missing for ID {id}', ['id' => $attachment_id]);
  }

  private function clear_missing_source_marker(int $attachment_id): void
  {
    $this->repo->clearMissingSource($attachment_id);
  }

  private function missing_source_retry_cooldown_seconds(): int
  {
    return max(300, (int) apply_filters('nimbus_missing_source_retry_cooldown_seconds', 12 * HOUR_IN_SECONDS));
  }

  private function is_attachment_excluded_by_size_rules(int $attachment_id): bool
  {
    $minSizeKb = $this->settings->offload_min_size_kb();
    if ($minSizeKb <= 0) {
      return false;
    }

    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
      return false; // can't determine size; let it through
    }

    return (filesize($file) / 1024) < $minSizeKb;
  }

  private function is_attachment_excluded_by_mime_rules(int $attachment_id): bool
  {
    $allowed = $this->settings->offload_allowed_mime_types();
    if (empty($allowed)) {
      return false; // empty allowlist = all MIME types permitted
    }

    $mime = (string) get_post_mime_type($attachment_id);
    foreach ($allowed as $pattern) {
      // Support wildcards like "image/*" or exact types like "video/mp4".
      $regex = '#^' . str_replace('\*', '[^/]*', preg_quote($pattern, '#')) . '$#i';
      if (preg_match($regex, $mime)) {
        return false; // in allowed list
      }
    }

    return true; // not in allowed list — exclude from offloading
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
    return (bool) get_post_meta($attachment_id, NIMBUS_IGNORE_META_KEY, true);
  }
}
