<?php
namespace Pathanamthitta\MediaOffload;

use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

class Upload_Handler
{
  private const PENDING_OFFLOAD_AT_META_KEY = '_pt_media_offload_pending_at';
  private const PENDING_CLAIMED_BY_META_KEY = '_pt_media_offload_claimed_by';
  private const PENDING_CLAIMED_AT_META_KEY = '_pt_media_offload_claimed_at';
  private const MISSING_SOURCE_AT_META_KEY = '_pt_media_offload_missing_source_at';
  private const NIGHTLY_BATCH_LOCK_TRANSIENT = 'pt_media_offload_nightly_batch_lock';

  private Settings $settings;

  private Storage_Adapter_Factory $factory;

  private Logger $logger;

  public function __construct(Settings $settings, Storage_Adapter_Factory $factory, Logger $logger)
  {
    $this->settings = $settings;
    $this->factory = $factory;
    $this->logger = $logger;
  }

  public function register(): void
  {
    add_filter('wp_generate_attachment_metadata', [$this, 'filter_attachment_metadata'], 20, 2);
    add_filter('wp_update_attachment_metadata', [$this, 'filter_attachment_metadata'], 20, 2);
    add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 20, 2);
    add_filter('wp_get_attachment_metadata', [$this, 'inject_remote_metadata'], 20, 2);
    add_action('delete_attachment', [$this, 'delete_remote_files'], 20, 1);
    add_action('pt_media_offload_cleanup_attachment', [$this, 'cleanup_local_files'], 20, 1);
    add_action('pt_media_offload_nightly_batch', [$this, 'run_nightly_pending_batch'], 20, 0);
    add_action('pt_media_offload_nightly_worker', [$this, 'run_nightly_worker'], 20, 4);

    if ($this->settings->should_process_off_hours_batch()) {
      $this->ensure_nightly_schedule();
    } else {
      $this->clear_nightly_schedule();
    }
  }

  public function sync_ignore_state(int $attachment_id, bool $ignored): void
  {
    if ($ignored) {
      update_post_meta($attachment_id, PT_MEDIA_OFFLOAD_IGNORE_META_KEY, '1');
      $this->clear_pending_state($attachment_id);
      $this->clear_missing_source_marker($attachment_id);

      if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('pt_media_offload_cleanup_attachment', [$attachment_id]);
      } else {
        $next_cleanup = wp_next_scheduled('pt_media_offload_cleanup_attachment', [$attachment_id]);
        if ($next_cleanup) {
          wp_unschedule_event($next_cleanup, 'pt_media_offload_cleanup_attachment', [$attachment_id]);
        }
      }

      $this->reconcile_nightly_batch_schedule();
      return;
    }

    delete_post_meta($attachment_id, PT_MEDIA_OFFLOAD_IGNORE_META_KEY);

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

    $meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
    if (!empty($meta['key'])) {
      $file = $this->resolve_attachment_file_path($attachment_id, $metadata);
      $existing_sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];

      if (!$this->has_pending_local_sizes($file, $metadata, $existing_sizes)) {
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
    if ($attachment_id <= 0) {
      return false;
    }

    if ($this->is_ignored_attachment($attachment_id)) {
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

    $meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
    if (!empty($meta['key'])) {
      $file = $this->resolve_attachment_file_path($attachment_id, $metadata);
      $existing_sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];

      if (!$this->has_pending_local_sizes($file, $metadata, $existing_sizes)) {
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

      $hours = (int) apply_filters('pt_media_offload_nightly_hours', 24);
      $worker_batch_size = (int) apply_filters('pt_media_offload_nightly_limit', $this->settings->off_hours_worker_batch_size());
      $worker_count = $this->settings->off_hours_worker_count();

      if ($hours < 1) {
        $hours = 1;
      }

      if ($worker_batch_size < 1) {
        $worker_batch_size = 1;
      }

      if ($worker_count <= 1) {
        $result = $this->process_pending_offloads($hours, $worker_batch_size, 'nightly-main');

        $this->logger->info(
          'Nightly batch finished. Total: {total}, Success: {success}, Failed: {failed}, Skipped: {skipped}',
          [
            'total' => $result['total'],
            'success' => $result['success'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
          ]
        );
      } else {
        $run_token = wp_generate_uuid4();
        $this->dispatch_nightly_workers($run_token, $worker_count, $hours, $worker_batch_size);
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

    $lock = 'pt_media_offload_worker_lock_' . md5($run_token . '|' . $worker_index);
    if (get_transient($lock)) {
      $this->logger->warning('Nightly worker {worker} skipped: run lock already active.', [
        'worker' => $worker_index,
      ]);
      return;
    }

    set_transient($lock, 1, 30 * MINUTE_IN_SECONDS);

    try {
      $result = $this->process_pending_offloads($hours, $limit, $run_token . '-w' . $worker_index);
      $this->logger->info(
        'Nightly worker {worker} complete. Total: {total}, Success: {success}, Failed: {failed}, Skipped: {skipped}',
        [
          'worker' => $worker_index,
          'total' => $result['total'],
          'success' => $result['success'],
          'failed' => $result['failed'],
          'skipped' => $result['skipped'],
        ]
      );
    } finally {
      delete_transient($lock);
    }
  }

  public function process_pending_offloads(int $hours = 24, int $limit = 500, string $claim_owner = ''): array
  {
    if (!$this->should_offload()) {
      return [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
      ];
    }

    if ($hours < 1) {
      $hours = 1;
    }

    if ($limit < 1) {
      $limit = 1;
    }

    if ($claim_owner === '') {
      $claim_owner = wp_generate_uuid4();
    }

    $claim_ttl = $this->settings->off_hours_claim_ttl_seconds();
    $attachment_ids = $this->claim_pending_attachment_ids($hours, $limit, $claim_owner, $claim_ttl);
    $total = count($attachment_ids);

    if ($total === 0) {
      return [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
      ];
    }

    $success = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($attachment_ids as $attachment_id) {
      try {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
          $skipped++;
          continue;
        }

        $file = $this->resolve_attachment_file_path($attachment_id, $metadata);
        $existing_meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
        $existing_sizes = is_array($existing_meta['sizes'] ?? null) ? $existing_meta['sizes'] : [];

        if (!empty($existing_meta['key']) && !$this->has_pending_local_sizes($file, $metadata, $existing_sizes)) {
          $this->clear_pending_state($attachment_id);
          $skipped++;
          continue;
        }

        if ($this->offload_via_cli($attachment_id)) {
          $success++;
        } else {
          $failed++;
        }
      } finally {
        $this->release_pending_claim($attachment_id, $claim_owner);
      }
    }

    return [
      'total' => $total,
      'success' => $success,
      'failed' => $failed,
      'skipped' => $skipped,
    ];
  }

  private function dispatch_nightly_workers(string $run_token, int $worker_count, int $hours, int $limit): void
  {
    $worker_count = max(1, $worker_count);

    for ($i = 1; $i <= $worker_count; $i++) {
      $args = [$i, $run_token, $hours, $limit];

      if (wp_next_scheduled('pt_media_offload_nightly_worker', $args)) {
        continue;
      }

      wp_schedule_single_event(time() + 1, 'pt_media_offload_nightly_worker', $args);
    }

    $this->logger->info('Dispatched nightly workers. Count: {count}, Run: {run}', [
      'count' => $worker_count,
      'run' => $run_token,
    ]);
  }

  public function ensure_nightly_schedule(): void
  {
    $hook = 'pt_media_offload_nightly_batch';

    if (!$this->settings->should_process_off_hours_batch()) {
      $this->clear_nightly_schedule();
      return;
    }

    [$hour, $minute] = $this->resolve_configured_batch_time();

    if (function_exists('wp_get_scheduled_event')) {
      $event = wp_get_scheduled_event($hook);

      if ($event) {
        $is_single = empty($event->schedule);

        $timezone = function_exists('wp_timezone')
          ? wp_timezone()
          : new \DateTimeZone((string) (get_option('timezone_string') ?: 'UTC'));

        $event_dt = (new \DateTimeImmutable('@' . (int) $event->timestamp))->setTimezone($timezone);
        $same_time = ((int) $event_dt->format('H') === $hour) && ((int) $event_dt->format('i') === $minute);

        if ($is_single && $same_time) {
          return;
        }

        $this->clear_nightly_schedule();
        $this->schedule_next_single_nightly_event();
        return;
      }
    }

    $next = wp_next_scheduled($hook);
    if ($next) {
      $timezone = function_exists('wp_timezone')
        ? wp_timezone()
        : new \DateTimeZone((string) (get_option('timezone_string') ?: 'UTC'));

      $next_dt = (new \DateTimeImmutable('@' . $next))->setTimezone($timezone);
      if ((int) $next_dt->format('H') === $hour && (int) $next_dt->format('i') === $minute) {
        return;
      }

      $this->clear_nightly_schedule();
    }

    $this->schedule_next_single_nightly_event();
  }

  public function clear_nightly_schedule(): void
  {
    $hook = 'pt_media_offload_nightly_batch';
    $worker_hook = 'pt_media_offload_nightly_worker';

    $this->unschedule_all_events_for_hook($hook);
    $this->unschedule_all_events_for_hook($worker_hook);
  }

  private function schedule_next_single_nightly_event(): void
  {
    if (!$this->settings->should_process_off_hours_batch()) {
      return;
    }

    $hook = 'pt_media_offload_nightly_batch';
    if (wp_next_scheduled($hook)) {
      return;
    }

    [$hour, $minute] = $this->resolve_configured_batch_time();
    $timestamp = $this->resolve_next_nightly_timestamp($hour, $minute);

    wp_schedule_single_event($timestamp, $hook);
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

  public function filter_attachment_url(string $url, int $attachment_id): string
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      return $url;
    }

    if (!$this->should_serve_from_storage()) {
      return $url;
    }

    $meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
    if (empty($meta['key'])) {
      $metadata = wp_get_attachment_metadata($attachment_id);
      if (isset($metadata['pt_media_offload']['key'])) {
        $meta = $metadata['pt_media_offload'];
      }
    }

    if (!empty($meta['key'])) {
      try {
        $adapter = $this->factory->get_adapter();
        $url = $adapter->get_url($meta['key']);
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
    if (!is_array($metadata)) {
      return $metadata;
    }

    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      return $metadata;
    }

    $meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
    $this->apply_offload_meta_to_metadata($metadata, $meta);

    return $metadata;
  }

  public function offload_via_cli(int $attachment_id): bool
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      $this->clear_pending_state($attachment_id);
      $this->clear_missing_source_marker($attachment_id);
      return false;
    }

    if (!$this->should_offload()) {
      return false;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata)) {
      return false;
    }

    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
      $this->mark_missing_source($attachment_id);
      $this->clear_pending_state($attachment_id);
      return false;
    }

    $this->clear_missing_source_marker($attachment_id);

    try {
      $offload_meta = $this->offload_media($attachment_id, $file, $metadata);
      $this->apply_offload_meta_to_metadata($metadata, $offload_meta);
    } catch (RuntimeException $exception) {
      $this->logger->error('Failed to offload attachment {id} via CLI: {message}', [
        'id' => $attachment_id,
        'message' => $exception->getMessage(),
      ]);
      return false;
    }

    update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);
    $this->clear_pending_state($attachment_id);
    $this->clear_missing_source_marker($attachment_id);

    return true;
  }

  public function delete_remote_files(int $attachment_id): void
  {
    $meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
    $keys_to_purge = [];

    if (!empty($meta['key']) && is_string($meta['key'])) {
      $keys_to_purge[] = $meta['key'];

      if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
        foreach ($meta['sizes'] as $size_meta) {
          if (!empty($size_meta['key']) && is_string($size_meta['key'])) {
            $keys_to_purge[] = $size_meta['key'];
          }
        }
      }

      if ($this->settings->has_valid_credentials()) {
        try {
          $adapter = $this->factory->get_adapter();
          $adapter->delete($meta['key']);
          if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_meta) {
              if (!empty($size_meta['key'])) {
                $adapter->delete($size_meta['key']);
              }
            }
          }
        } catch (RuntimeException $exception) {
          $this->logger->warning('Failed to delete remote files for attachment {id}: {message}', [
            'id' => $attachment_id,
            'message' => $exception->getMessage(),
          ]);
        }

        $this->maybe_purge_cloudflare_cache($keys_to_purge, $attachment_id);
      }

      delete_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY);
    }

    $this->clear_pending_state($attachment_id);

    if (function_exists('wp_clear_scheduled_hook')) {
      wp_clear_scheduled_hook('pt_media_offload_cleanup_attachment', [$attachment_id]);
    } else {
      $next_cleanup = wp_next_scheduled('pt_media_offload_cleanup_attachment', [$attachment_id]);
      if ($next_cleanup) {
        wp_unschedule_event($next_cleanup, 'pt_media_offload_cleanup_attachment', [$attachment_id]);
      }
    }

    $this->reconcile_nightly_batch_schedule();
  }

  public function cleanup_local_files(int $attachment_id): void
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      return;
    }

    if (!$this->settings->should_remove_local()) {
      return;
    }

    $meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
    if (empty($meta['key'])) {
      return;
    }

    $attached_file = get_attached_file($attachment_id);
    if (is_string($attached_file) && $attached_file !== '' && file_exists($attached_file)) {
      @unlink($attached_file);
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata) || empty($metadata['sizes'])) {
      return;
    }

    $base_dir = is_string($attached_file) && $attached_file !== ''
      ? dirname($attached_file)
      : '';

    foreach ($metadata['sizes'] as $size_data) {
      if (empty($size_data['file']) || $base_dir === '') {
        continue;
      }

      $size_path = function_exists('path_join')
        ? path_join($base_dir, $size_data['file'])
        : trailingslashit($base_dir) . ltrim($size_data['file'], '/');

      if (file_exists($size_path)) {
        @unlink($size_path);
      }
    }
  }

  public function download_media(int $attachment_id): bool
  {
    $meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
    if (empty($meta['key'])) {
      return false; // Not offloaded
    }

    try {
      $adapter = $this->factory->get_adapter();
    } catch (RuntimeException $e) {
      $this->logger->error('Storage adapter not available: {message}', ['message' => $e->getMessage()]);
      return false;
    }

    $uploads = wp_upload_dir();
    $metadata = wp_get_attachment_metadata($attachment_id);
    
    // Determine local path for main file
    $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (!$attached_file) {
        $this->logger->error('No _wp_attached_file meta for ID {id}', ['id' => $attachment_id]);
        return false;
    }
    
    $local_path = path_join($uploads['basedir'], $attached_file);
    $local_dir = dirname($local_path);
    
    if (!file_exists($local_dir)) {
        wp_mkdir_p($local_dir);
    }

    try {
        $adapter->download($meta['key'], $local_path);
    } catch (RuntimeException $e) {
        $this->logger->error('Failed to download main file for ID {id}', ['id' => $attachment_id]);
        return false;
    }

    // Download sizes
    if (!empty($meta['sizes']) && !empty($metadata['sizes'])) {
        foreach ($meta['sizes'] as $size => $size_meta) {
            if (empty($size_meta['key']) || empty($metadata['sizes'][$size]['file'])) {
                continue;
            }
            
            $size_file = $metadata['sizes'][$size]['file'];
            $size_path = path_join($local_dir, $size_file);
            
            try {
                $adapter->download($size_meta['key'], $size_path);
            } catch (RuntimeException $e) {
                $this->logger->warning('Failed to download size {size} for ID {id}', ['size' => $size, 'id' => $attachment_id]);
            }
        }
    }
    
    // Remove offload metadata to mark as local
    delete_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY);
    
    // Clean up metadata
    if (isset($metadata['pt_media_offload'])) {
        unset($metadata['pt_media_offload']);
    }
    if (isset($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size => $data) {
            if (isset($data['pt_media_offload'])) {
                unset($metadata['sizes'][$size]['pt_media_offload']);
            }
        }
    }
    update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);

    return true;
  }

  private function offload_media(int $attachment_id, string $file, array &$metadata): array
  {
    $this->logger->info('Starting offload for ID {id}. File: {file}', ['id' => $attachment_id, 'file' => $file]);
    $adapter = $this->factory->get_adapter();
    $uploads = wp_upload_dir();
    $existing_offload_meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
    $existing_sizes = is_array($existing_offload_meta['sizes'] ?? null) ? $existing_offload_meta['sizes'] : [];

    $relative_path = function_exists('wp_relative_upload_path') ? wp_relative_upload_path($file) : false;
    if (!$relative_path) {
      $relative_path = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $file), '/');
    }

    $key = build_storage_key($relative_path, $this->settings->get('prefix', ''));
    $uploaded_key = $key;
    
    // Only upload main file if it exists
    if (file_exists($file)) {
      if (($existing_offload_meta['key'] ?? '') !== $key) {
        $uploaded_key = $adapter->upload($file, $key, ['content_type' => get_post_mime_type($attachment_id)]);
      } elseif (!empty($existing_offload_meta['key']) && is_string($existing_offload_meta['key'])) {
        $uploaded_key = $existing_offload_meta['key'];
      }
    } else {
        $this->logger->info('Main file missing, skipping upload: {file}', ['file' => $file]);
        if (!empty($existing_offload_meta['key']) && is_string($existing_offload_meta['key'])) {
          $uploaded_key = $existing_offload_meta['key'];
        }
    }

    $offload_meta = [
      'key' => $uploaded_key,
      'sizes' => [],
    ];

    // Offload sizes BEFORE deleting main file (just in case logic depends on it, though it shouldn't)
    if (!empty($metadata['sizes'])) {
      $offload_meta['sizes'] = $this->offload_sizes($attachment_id, $file, $metadata, $adapter, $existing_sizes);
    }

    update_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, $offload_meta);

    if ($this->settings->should_remove_local()) {
      $this->schedule_local_cleanup($attachment_id);
    }

    return $offload_meta;
  }

  private function offload_sizes(int $attachment_id, string $base_file, array &$metadata, Storage_Adapter $adapter, array $existing_sizes = []): array
  {
    $results = [];
    $base_dir = dirname($base_file);
    $this->logger->info('Offloading sizes for ID {id}. Base dir: {dir}', ['id' => $attachment_id, 'dir' => $base_dir]);

    foreach ($metadata['sizes'] as $size => $data) {
      if (empty($data['file'])) {
        continue;
      }

      $size_path = function_exists('path_join')
        ? path_join($base_dir, $data['file'])
        : trailingslashit($base_dir) . ltrim($data['file'], '/');

      if (!empty($existing_sizes[$size]['key'])) {
        $results[$size] = $existing_sizes[$size];
        $metadata['sizes'][$size]['pt_media_offload'] = $existing_sizes[$size];
        continue;
      }
      
      if (!file_exists($size_path)) {
        $this->logger->warning('Size file missing: {path}', ['path' => $size_path]);
        continue;
      }

      $relative_path = function_exists('wp_relative_upload_path') ? wp_relative_upload_path($size_path) : false;
      if (!$relative_path) {
        $uploads = wp_upload_dir();
        $relative_path = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $size_path), '/');
      }

      $size_key = build_storage_key($relative_path, $this->settings->get('prefix', ''));

      try {
        $uploaded_size_key = $adapter->upload($size_path, $size_key, [
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

      $results[$size] = ['key' => $uploaded_size_key];
      $metadata['sizes'][$size]['pt_media_offload'] = ['key' => $uploaded_size_key];
    }

    return $results;
  }

  private function has_pending_local_sizes(string $base_file, array $metadata, array $existing_sizes = []): bool
  {
    if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
      return false;
    }

    $base_dir = dirname($base_file);

    foreach ($metadata['sizes'] as $size => $data) {
      if (empty($data['file'])) {
        continue;
      }

      $size_path = function_exists('path_join')
        ? path_join($base_dir, $data['file'])
        : trailingslashit($base_dir) . ltrim($data['file'], '/');

      if (!file_exists($size_path)) {
        continue;
      }

      // Size exists locally without known remote key, so it must be offloaded.
      if (empty($existing_sizes[$size]['key'])) {
        return true;
      }

    }

    return false;
  }

  private function schedule_local_cleanup(int $attachment_id): void
  {
    $delay = (int) apply_filters('pt_media_offload_cleanup_delay', 180);
    if ($delay < 30) {
      $delay = 30;
    }

    if (function_exists('wp_clear_scheduled_hook')) {
      wp_clear_scheduled_hook('pt_media_offload_cleanup_attachment', [$attachment_id]);
    } else {
      $next_cleanup = wp_next_scheduled('pt_media_offload_cleanup_attachment', [$attachment_id]);
      if ($next_cleanup) {
        wp_unschedule_event($next_cleanup, 'pt_media_offload_cleanup_attachment', [$attachment_id]);
      }
    }

    wp_schedule_single_event(time() + $delay, 'pt_media_offload_cleanup_attachment', [$attachment_id]);
  }

  private function mark_pending_offload(int $attachment_id): void
  {
    if (!$this->is_attachment_eligible_for_offload($attachment_id)) {
      $this->clear_pending_state($attachment_id);
      return;
    }

    if ((int) get_post_meta($attachment_id, self::PENDING_OFFLOAD_AT_META_KEY, true) <= 0) {
      update_post_meta($attachment_id, self::PENDING_OFFLOAD_AT_META_KEY, time());
    }

    $this->ensure_nightly_schedule();
  }

  private function clear_pending_state(int $attachment_id): void
  {
    delete_post_meta($attachment_id, self::PENDING_OFFLOAD_AT_META_KEY);
    delete_post_meta($attachment_id, self::PENDING_CLAIMED_BY_META_KEY);
    delete_post_meta($attachment_id, self::PENDING_CLAIMED_AT_META_KEY);
  }

  private function claim_pending_attachment_ids(int $hours, int $limit, string $claim_owner, int $claim_ttl): array
  {
    $threshold = time() - ($hours * HOUR_IN_SECONDS);
    $missing_source_retry_before = time() - $this->missing_source_retry_cooldown_seconds();
    $candidate_limit = max($limit * 4, $limit);

    $query = new \WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => $candidate_limit,
      'fields' => 'ids',
      'meta_key' => self::PENDING_OFFLOAD_AT_META_KEY,
      'orderby' => 'meta_value_num',
      'order' => 'ASC',
      'meta_query' => [
        'relation' => 'AND',
        [
          'key' => self::PENDING_OFFLOAD_AT_META_KEY,
          'compare' => 'EXISTS',
        ],
        [
          'key' => self::PENDING_OFFLOAD_AT_META_KEY,
          'value' => $threshold,
          'type' => 'NUMERIC',
          'compare' => '>=',
        ],
        [
          'relation' => 'OR',
          [
            'key' => self::MISSING_SOURCE_AT_META_KEY,
            'compare' => 'NOT EXISTS',
          ],
          [
            'key' => self::MISSING_SOURCE_AT_META_KEY,
            'value' => $missing_source_retry_before,
            'type' => 'NUMERIC',
            'compare' => '<',
          ],
        ],
        [
          'relation' => 'OR',
          [
            'key' => PT_MEDIA_OFFLOAD_IGNORE_META_KEY,
            'compare' => 'NOT EXISTS',
          ],
          [
            'key' => PT_MEDIA_OFFLOAD_IGNORE_META_KEY,
            'value' => '1',
            'compare' => '!=',
          ],
        ],
      ],
    ]);

    $candidate_ids = is_array($query->posts) ? array_map('intval', $query->posts) : [];
    if (empty($candidate_ids)) {
      return [];
    }

    $candidate_ids = $this->filter_eligible_attachment_ids($candidate_ids);
    if (empty($candidate_ids)) {
      return [];
    }

    $claimed = [];
    foreach ($candidate_ids as $attachment_id) {
      if (count($claimed) >= $limit) {
        break;
      }

      if ($this->try_claim_pending_attachment($attachment_id, $claim_owner, $claim_ttl)) {
        $claimed[] = $attachment_id;
      }
    }

    return $claimed;
  }

  private function try_claim_pending_attachment(int $attachment_id, string $claim_owner, int $claim_ttl): bool
  {
    $current_owner = (string) get_post_meta($attachment_id, self::PENDING_CLAIMED_BY_META_KEY, true);
    $current_claimed_at = (int) get_post_meta($attachment_id, self::PENDING_CLAIMED_AT_META_KEY, true);

    if ($current_owner !== '' && $current_owner !== $claim_owner) {
      $is_fresh_claim = $current_claimed_at > 0 && (time() - $current_claimed_at) < $claim_ttl;
      if ($is_fresh_claim) {
        return false;
      }

      delete_post_meta($attachment_id, self::PENDING_CLAIMED_BY_META_KEY);
      delete_post_meta($attachment_id, self::PENDING_CLAIMED_AT_META_KEY);
    }

    if (!add_post_meta($attachment_id, self::PENDING_CLAIMED_BY_META_KEY, $claim_owner, true)) {
      $owner = (string) get_post_meta($attachment_id, self::PENDING_CLAIMED_BY_META_KEY, true);
      if ($owner !== $claim_owner) {
        return false;
      }
    }

    update_post_meta($attachment_id, self::PENDING_CLAIMED_AT_META_KEY, time());
    return true;
  }

  private function release_pending_claim(int $attachment_id, string $claim_owner): void
  {
    $owner = (string) get_post_meta($attachment_id, self::PENDING_CLAIMED_BY_META_KEY, true);
    if ($owner === '' || $owner === $claim_owner) {
      delete_post_meta($attachment_id, self::PENDING_CLAIMED_BY_META_KEY);
      delete_post_meta($attachment_id, self::PENDING_CLAIMED_AT_META_KEY);
    }
  }

  private function has_pending_queue_items(): bool
  {
    $missing_source_retry_before = time() - $this->missing_source_retry_cooldown_seconds();

    $query = new \WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => 250,
      'fields' => 'ids',
      'no_found_rows' => true,
      'meta_query' => [
        'relation' => 'AND',
        [
          'key' => self::PENDING_OFFLOAD_AT_META_KEY,
          'compare' => 'EXISTS',
        ],
        [
          'relation' => 'OR',
          [
            'key' => self::MISSING_SOURCE_AT_META_KEY,
            'compare' => 'NOT EXISTS',
          ],
          [
            'key' => self::MISSING_SOURCE_AT_META_KEY,
            'value' => $missing_source_retry_before,
            'type' => 'NUMERIC',
            'compare' => '<',
          ],
        ],
        [
          'relation' => 'OR',
          [
            'key' => PT_MEDIA_OFFLOAD_IGNORE_META_KEY,
            'compare' => 'NOT EXISTS',
          ],
          [
            'key' => PT_MEDIA_OFFLOAD_IGNORE_META_KEY,
            'value' => '1',
            'compare' => '!=',
          ],
        ],
      ],
    ]);

    $candidate_ids = is_array($query->posts) ? array_map('intval', $query->posts) : [];
    return !empty($this->filter_eligible_attachment_ids($candidate_ids));
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
    $timezone = function_exists('wp_timezone')
      ? wp_timezone()
      : new \DateTimeZone((string) (get_option('timezone_string') ?: 'UTC'));

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
      return function_exists('path_join')
        ? path_join($uploads['basedir'], $metadata['file'])
        : trailingslashit($uploads['basedir']) . ltrim($metadata['file'], '/');
    }

    return '';
  }

  private function resolve_attachment_relative_path(int $attachment_id, ?array $metadata = null): string
  {
    $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (is_string($attached_file) && $attached_file !== '') {
      return ltrim(str_replace('\\', '/', $attached_file), '/');
    }

    if (is_array($metadata) && !empty($metadata['file']) && is_string($metadata['file'])) {
      return ltrim(str_replace('\\', '/', $metadata['file']), '/');
    }

    $file = get_attached_file($attachment_id);
    if (!is_string($file) || $file === '') {
      return '';
    }

    $relative = function_exists('wp_relative_upload_path') ? wp_relative_upload_path($file) : false;
    if (is_string($relative) && $relative !== '') {
      return ltrim(str_replace('\\', '/', $relative), '/');
    }

    return '';
  }

  private function maybe_purge_cloudflare_cache(array $keys, int $attachment_id): void
  {
    if (!$this->settings->should_purge_cloudflare_cache_on_delete()) {
      return;
    }

    if (!$this->settings->has_cloudflare_purge_credentials()) {
      $this->logger->warning('Cloudflare purge enabled but credentials are missing. Skipping cache purge for ID {id}.', [
        'id' => $attachment_id,
      ]);
      return;
    }

    $urls = $this->resolve_urls_for_keys($keys);
    if (empty($urls)) {
      return;
    }

    $zone_id = trim((string) $this->settings->get('cloudflare_zone_id', ''));
    $api_token = trim((string) $this->settings->get('cloudflare_api_token', ''));
    $endpoint = sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', rawurlencode($zone_id));

    $response = wp_remote_post($endpoint, [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Bearer ' . $api_token,
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode([
        'files' => array_values($urls),
      ]),
    ]);

    if (is_wp_error($response)) {
      $this->logger->warning('Cloudflare purge failed for attachment {id}: {error}', [
        'id' => $attachment_id,
        'error' => $response->get_error_message(),
      ]);
      return;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($status >= 200 && $status < 300 && !empty($body['success'])) {
      $this->logger->info('Purged Cloudflare cache for attachment {id}. URL count: {count}', [
        'id' => $attachment_id,
        'count' => count($urls),
      ]);
      return;
    }

    $error_message = 'Unknown Cloudflare API response';
    if (is_array($body) && !empty($body['errors']) && is_array($body['errors'])) {
      $messages = [];
      foreach ($body['errors'] as $error) {
        if (is_array($error) && !empty($error['message'])) {
          $messages[] = (string) $error['message'];
        }
      }
      if (!empty($messages)) {
        $error_message = implode('; ', $messages);
      }
    }

    $this->logger->warning('Cloudflare purge request failed for attachment {id}. HTTP {status}: {error}', [
      'id' => $attachment_id,
      'status' => $status,
      'error' => $error_message,
    ]);
  }

  private function resolve_urls_for_keys(array $keys): array
  {
    $keys = array_values(array_unique(array_filter($keys, static function ($value) {
      return is_string($value) && $value !== '';
    })));

    if (empty($keys)) {
      return [];
    }

    try {
      $adapter = $this->factory->get_adapter();
    } catch (RuntimeException $exception) {
      $this->logger->warning('Cannot initialize storage adapter for cache purge URL resolution: {message}', [
        'message' => $exception->getMessage(),
      ]);
      return [];
    }

    $urls = [];
    foreach ($keys as $key) {
      try {
        $url = $adapter->get_url($key);
      } catch (RuntimeException $exception) {
        $this->logger->warning('Failed to resolve URL for cache purge key {key}: {message}', [
          'key' => $key,
          'message' => $exception->getMessage(),
        ]);
        continue;
      }

      if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
        $urls[$url] = true;
      }
    }

    return array_keys($urls);
  }

  private function should_offload(): bool
  {
    if (!$this->settings->is_enabled()) {
      return false;
    }

    if (!$this->settings->has_valid_credentials()) {
      return false;
    }

    return true;
  }

  private function should_serve_from_storage(): bool
  {
    if (!$this->should_offload()) {
      return false;
    }

    return $this->settings->should_serve_from_storage();
  }

  private function apply_offload_meta_to_metadata(array &$metadata, $offload_meta): void
  {
    if (!is_array($offload_meta) || empty($offload_meta['key'])) {
      return;
    }

    $metadata['pt_media_offload'] = $offload_meta;

    if (!empty($offload_meta['sizes']) && !empty($metadata['sizes'])) {
      foreach ($offload_meta['sizes'] as $size => $size_meta) {
        if (!empty($metadata['sizes'][$size])) {
          $metadata['sizes'][$size]['pt_media_offload'] = $size_meta;
        }
      }
    }
  }

  private function mark_missing_source(int $attachment_id): void
  {
    $now = time();
    $previous = (int) get_post_meta($attachment_id, self::MISSING_SOURCE_AT_META_KEY, true);
    update_post_meta($attachment_id, self::MISSING_SOURCE_AT_META_KEY, $now);

    $cooldown = max(300, (int) apply_filters('pt_media_offload_missing_source_log_cooldown_seconds', 6 * HOUR_IN_SECONDS));
    if ($previous > 0 && ($now - $previous) < $cooldown) {
      return;
    }

    $this->logger->warning('Attachment file missing for ID {id}', ['id' => $attachment_id]);
  }

  private function clear_missing_source_marker(int $attachment_id): void
  {
    delete_post_meta($attachment_id, self::MISSING_SOURCE_AT_META_KEY);
  }

  private function missing_source_retry_cooldown_seconds(): int
  {
    return max(300, (int) apply_filters('pt_media_offload_missing_source_retry_cooldown_seconds', 12 * HOUR_IN_SECONDS));
  }

  private function is_attachment_excluded_by_path_rules(int $attachment_id, ?array $metadata = null): bool
  {
    $relative_path = $this->resolve_attachment_relative_path($attachment_id, $metadata);
    if ($relative_path === '') {
      return false;
    }

    return !$this->settings->should_offload_relative_path($relative_path);
  }

  private function is_ignored_attachment(int $attachment_id): bool
  {
    return (bool) get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_IGNORE_META_KEY, true);
  }
}
