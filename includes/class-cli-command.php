<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

class CLI_Command
{
  private Upload_Handler $handler;

  private Settings $settings;

  private Storage_Adapter_Factory $factory;

  private Logger $logger;

  public function __construct(Upload_Handler $handler, Settings $settings, Storage_Adapter_Factory $factory, Logger $logger)
  {
    $this->handler = $handler;
    $this->settings = $settings;
    $this->factory = $factory;
    $this->logger = $logger;
  }

  public function register(): void
  {
    if (!class_exists('\\WP_CLI')) {
      return;
    }
    call_user_func(['\WP_CLI', 'add_command'], 'pt-media-offload config', [$this, 'config'], [
      'shortdesc' => 'Show or update media-offload plugin settings from WP-CLI.',
    ]);
    call_user_func(['\WP_CLI', 'add_command'], 'pt-media-offload deploy', [$this, 'deploy'], [
      'shortdesc' => 'Deploy year-based uploads (YYYY/...) from wp-content/uploads to configured object storage.',
      'synopsis' => [
        [
          'type' => 'assoc',
          'name' => 'parallel',
          'description' => 'Number of month-based subdirectories handled per batch.',
          'optional' => true,
          'default' => 1,
        ],
        [
          'type' => 'flag',
          'name' => 'force',
          'description' => 'Re-upload objects even if they already exist remotely.',
          'optional' => true,
        ],
      ],
    ]);
    call_user_func(['\WP_CLI', 'add_command'], 'pt-media-offload rewrite', [$this, 'rewrite'], [
      'shortdesc' => 'Rewrite uploads URLs to storage domain after remote verification.',
      'synopsis' => [
        [
          'type' => 'flag',
          'name' => 'all-attachments',
          'description' => 'Process all attachments, including those already marked as offloaded (default is pending-only).',
          'optional' => true,
        ],
        [
          'type' => 'assoc',
          'name' => 'attachment-batch-size',
          'description' => 'Number of attachment IDs processed per batch (lower this if container memory is tight).',
          'optional' => true,
          'default' => 500,
        ],
        [
          'type' => 'assoc',
          'name' => 'verify-chunk-size',
          'description' => 'Number of object keys to verify per batch.',
          'optional' => true,
          'default' => 2000,
        ],
        [
          'type' => 'assoc',
          'name' => 'verify-concurrency',
          'description' => 'Concurrent fallback existence checks per chunk when bulk listing is insufficient.',
          'optional' => true,
          'default' => 20,
        ],
        [
          'type' => 'flag',
          'name' => 'skip-verify',
          'description' => 'Skip remote object verification and rewrite directly (unsafe).',
          'optional' => true,
        ],
        [
          'type' => 'flag',
          'name' => 'log-checked',
          'description' => 'Log every checked object key and status during verification.',
          'optional' => true,
        ],
        [
          'type' => 'flag',
          'name' => 'dry-run',
          'description' => 'Verify everything and preview rewrite, without changing DB.',
          'optional' => true,
        ],
        [
          'type' => 'flag',
          'name' => 'force',
          'description' => 'Delete local upload files after successful rewrite.',
          'optional' => true,
        ],
      ],
    ]);
    call_user_func(['\WP_CLI', 'add_command'], 'pt-media-offload revert', [$this, 'revert'], [
      'shortdesc' => 'Restore offloaded uploads from storage and rewrite DB URLs back to local uploads domain.',
      'synopsis' => [
        [
          'type' => 'assoc',
          'name' => 'batch-size',
          'description' => 'Number of attachments to restore per batch.',
          'optional' => true,
          'default' => 100,
        ],
      ],
    ]);

    call_user_func(['\WP_CLI', 'add_command'], 'pt-media-offload process-pending', [$this, 'process_pending'], [
      'shortdesc' => 'Process pending media offloads (useful for nightly/off-hours batch runs).',
      'synopsis' => [
        [
          'type' => 'assoc',
          'name' => 'hours',
          'description' => 'Only process attachments queued within the last N hours.',
          'optional' => true,
          'default' => 24,
        ],
        [
          'type' => 'assoc',
          'name' => 'limit',
          'description' => 'Maximum number of pending attachments to process in one run.',
          'optional' => true,
          'default' => 500,
        ],
        [
          'type' => 'assoc',
          'name' => 'worker-token',
          'description' => 'Optional worker token for claim-based parallel runs (auto-generated when omitted).',
          'optional' => true,
        ],
      ],
    ]);
  }

  public function config(array $args, array $assoc_args): void
  {
    $string_map = [
      'provider' => 'provider',
      'bucket' => 'bucket',
      'r2-account-id' => 'r2_account_id',
      'region' => 'region',
      'access-key' => 'access_key',
      'secret-key' => 'secret_key',
      'session-token' => 'session_token',
      'prefix' => 'prefix',
      'endpoint' => 'endpoint',
      'custom-domain' => 'custom_domain',
      'storage-class' => 'storage_class',
      'visibility' => 'visibility',
      'cloudflare-zone-id' => 'cloudflare_zone_id',
      'cloudflare-api-token' => 'cloudflare_api_token',
      'off-hours-batch-time' => 'off_hours_batch_time',
      'allowed-path-patterns' => 'allowed_path_patterns',
      'ignored-path-patterns' => 'ignored_path_patterns',
    ];

    $integer_map = [
      'off-hours-worker-count' => 'off_hours_worker_count',
      'off-hours-worker-batch-size' => 'off_hours_worker_batch_size',
      'off-hours-claim-ttl-seconds' => 'off_hours_claim_ttl_seconds',
    ];

    $boolean_map = [
      'enabled' => 'enabled',
      'serve-from-storage' => 'serve_from_storage',
      'remove-local-copies' => 'remove_local_copies',
      'purge-cloudflare-cache-on-delete' => 'purge_cloudflare_cache_on_delete',
      'off-hours-batch-enabled' => 'off_hours_batch_enabled',
      'use-path-style-endpoint' => 'use_path_style_endpoint',
    ];

    $updates = [];
    foreach ($string_map as $cli_key => $setting_key) {
      if (array_key_exists($cli_key, $assoc_args)) {
        $updates[$setting_key] = trim((string) $assoc_args[$cli_key]);
      }
    }

    foreach ($integer_map as $cli_key => $setting_key) {
      if (array_key_exists($cli_key, $assoc_args)) {
        $updates[$setting_key] = (int) $assoc_args[$cli_key];
      }
    }

    foreach ($boolean_map as $cli_key => $setting_key) {
      if (array_key_exists($cli_key, $assoc_args)) {
        $updates[$setting_key] = $this->parse_cli_bool((string) $assoc_args[$cli_key], $cli_key);
      }
    }

    $show = isset($assoc_args['show']) || empty($assoc_args) || (count($assoc_args) === 1 && isset($assoc_args['test-connection']));
    $test_connection = isset($assoc_args['test-connection']);

    if (!empty($updates)) {
      $this->settings->update($updates);
      $this->factory->reset();
      $this->handler->refresh_schedule_state();

      call_user_func(['\WP_CLI', 'success'], 'Media Offload settings updated.');
    }

    if ($show || !empty($updates)) {
      $this->render_config_table();
    }

    if ($test_connection) {
      if (!$this->settings->has_valid_credentials()) {
        call_user_func(['\WP_CLI', 'warning'], 'Connection test skipped: required provider credentials are still incomplete.');
        return;
      }

      $adapter = $this->get_adapter_or_fail();
      if (!$adapter) {
        return;
      }

      if (!method_exists($adapter, 'test_connection')) {
        call_user_func(['\WP_CLI', 'warning'], 'Current adapter does not support connection testing.');
        return;
      }

      try {
        $adapter->test_connection();
        call_user_func(['\WP_CLI', 'success'], 'Connection test successful.');
      } catch (\Throwable $exception) {
        call_user_func(['\WP_CLI', 'warning'], 'Connection test failed: ' . $exception->getMessage());
      }
    }

    if (empty($updates) && !$show && !$test_connection) {
      call_user_func(['\WP_CLI', 'log'], 'No changes requested. Use --show to print current settings or pass configuration flags to update them.');
    }
  }

  public function process_pending(array $args, array $assoc_args): void
  {
    if (!$this->settings->is_enabled()) {
      call_user_func(['\WP_CLI', 'error'], 'Media offload is disabled. Enable it in settings before processing pending items.');
      return;
    }

    if (!$this->settings->has_valid_credentials()) {
      call_user_func(['\WP_CLI', 'error'], 'Invalid or missing storage credentials. Please configure the plugin first.');
      return;
    }

    $hours = max(1, (int) ($assoc_args['hours'] ?? 24));
    $limit = max(1, (int) ($assoc_args['limit'] ?? 500));
    $worker_token = trim((string) ($assoc_args['worker-token'] ?? ''));

    if ($worker_token === '') {
      $worker_token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ptmo-worker-', true);
    }

    call_user_func(['\WP_CLI', 'log'], sprintf(
      'Processing pending offloads from last %d hour(s), limit %d, worker token %s.',
      $hours,
      $limit,
      $worker_token
    ));

    $result = $this->handler->process_pending_offloads($hours, $limit, $worker_token);

    if (($result['total'] ?? 0) === 0) {
      call_user_func(['\WP_CLI', 'success'], 'No pending attachments found for the selected window.');
      return;
    }

    $message = sprintf(
      'Pending processing complete. Total: %d, Success: %d, Failed: %d, Skipped: %d.',
      (int) ($result['total'] ?? 0),
      (int) ($result['success'] ?? 0),
      (int) ($result['failed'] ?? 0),
      (int) ($result['skipped'] ?? 0)
    );

    if ((int) ($result['failed'] ?? 0) > 0) {
      call_user_func(['\WP_CLI', 'warning'], $message);
      return;
    }

    call_user_func(['\WP_CLI', 'success'], $message);
  }

  public function deploy(array $args, array $assoc_args): void
  {
    if (!$this->settings->has_valid_credentials()) {
      call_user_func(['\WP_CLI', 'error'], 'Invalid or missing storage credentials. Please configure the plugin first.');
      return;
    }

    $adapter = $this->get_adapter_or_fail();
    if (!$adapter) {
      return;
    }

    $uploads = wp_upload_dir();
    $uploads_dir = $uploads['basedir'] ?? '';
    if (empty($uploads_dir) || !is_dir($uploads_dir)) {
      call_user_func(['\WP_CLI', 'error'], 'Uploads directory not found.');
      return;
    }

    $parallel = max(1, (int) ($assoc_args['parallel'] ?? 1));
    $force_upload = isset($assoc_args['force']);
    $prefix = (string) $this->settings->get('prefix', '');

    $grouped_files = $this->collect_upload_files_grouped($uploads_dir);
    if (empty($grouped_files)) {
      call_user_func(['\WP_CLI', 'success'], 'No files found under uploads directory.');
      return;
    }

    $total_files = 0;
    foreach ($grouped_files as $files) {
      $total_files += count($files);
    }

    call_user_func(['\WP_CLI', 'log'], sprintf(
      'Found %d files across %d groups. Processing %d group(s) per batch.',
      $total_files,
      count($grouped_files),
      $parallel
    ));

    $progress = \WP_CLI\Utils\make_progress_bar('Deploying uploads', $total_files);
    $uploaded_count = 0;
    $skipped_count = 0;
    $failed_count = 0;
    $verification_failures = 0;

    $group_names = array_keys($grouped_files);
    foreach (array_chunk($group_names, $parallel) as $group_batch) {
      call_user_func(['\WP_CLI', 'log'], sprintf('Processing group batch: %s', implode(', ', $group_batch)));

      foreach ($group_batch as $group_name) {
        $files = $grouped_files[$group_name];

        foreach ($files as $entry) {
          $absolute = $entry['absolute'];
          $relative = $entry['relative'];
          $key = build_storage_key($relative, $prefix);

          if (!$force_upload && $adapter->exists($key)) {
            $skipped_count++;
            $progress->tick();
            continue;
          }

          $mime = wp_check_filetype($absolute);
          $content_type = !empty($mime['type']) ? $mime['type'] : 'application/octet-stream';

          try {
            $adapter->upload($absolute, $key, ['content_type' => $content_type]);
            $uploaded_count++;
          } catch (\Throwable $exception) {
            $failed_count++;
            call_user_func(['\WP_CLI', 'warning'], sprintf(
              'Upload failed for %s: %s',
              $relative,
              $exception->getMessage()
            ));
          }

          $progress->tick();
        }

        $missing = $this->verify_uploaded_group($files, $adapter, $prefix);
        if (!empty($missing)) {
          $verification_failures += count($missing);
          call_user_func(['\WP_CLI', 'warning'], sprintf(
            'Verification failed for group %s (%d missing object(s)).',
            $group_name,
            count($missing)
          ));
        } else {
          call_user_func(['\WP_CLI', 'log'], sprintf('Verification passed for group %s.', $group_name));
        }
      }
    }

    $progress->finish();

    if ($verification_failures > 0 || $failed_count > 0) {
      call_user_func(['\WP_CLI', 'warning'], sprintf(
        'Deploy finished with issues. Uploaded: %d, Skipped: %d, Failed uploads: %d, Verification misses: %d.',
        $uploaded_count,
        $skipped_count,
        $failed_count,
        $verification_failures
      ));
      return;
    }

    call_user_func(['\WP_CLI', 'success'], sprintf(
      'Deploy complete. Uploaded: %d, Skipped: %d. Verification passed.',
      $uploaded_count,
      $skipped_count
    ));
  }

  public function rewrite(array $args, array $assoc_args): void
  {
    if (!$this->settings->has_valid_credentials()) {
      call_user_func(['\WP_CLI', 'error'], 'Invalid or missing storage credentials. Please configure the plugin first.');
      return;
    }

    $adapter = $this->get_adapter_or_fail();
    if (!$adapter) {
      return;
    }

    $uploads = wp_upload_dir();
    $uploads_dir = $uploads['basedir'] ?? '';
    $local_base = trailingslashit($uploads['baseurl'] ?? '');

    if (empty($uploads_dir) || !is_dir($uploads_dir) || empty($local_base)) {
      call_user_func(['\WP_CLI', 'error'], 'Unable to resolve uploads directory/base URL.');
      return;
    }

    $remote_base = $this->resolve_remote_base_url($adapter);
    if (empty($remote_base)) {
      call_user_func(['\WP_CLI', 'error'], 'Unable to resolve remote storage base URL.');
      return;
    }

    call_user_func(['\WP_CLI', 'log'], sprintf('Local base:  %s', $local_base));
    call_user_func(['\WP_CLI', 'log'], sprintf('Remote base: %s', $remote_base));

    $affected_post_ids = $this->collect_affected_post_ids_for_url_rewrite($local_base);
    if (!empty($affected_post_ids)) {
      call_user_func(['\WP_CLI', 'log'], sprintf(
        'Detected %d affected post/page cache entr%s for targeted purge after rewrite.',
        count($affected_post_ids),
        count($affected_post_ids) === 1 ? 'y' : 'ies'
      ));
    }

    $pending_only = !isset($assoc_args['all-attachments']);

    $total_attachments = $this->count_total_attachments($pending_only);
    if ($total_attachments <= 0) {
      $scope_message = $pending_only
        ? 'No pending attachments found to rewrite. Use --all-attachments to force a full pass.'
        : 'No attachments found to rewrite.';
      call_user_func(['\WP_CLI', 'success'], $scope_message);
      return;
    }

    $skip_verify = isset($assoc_args['skip-verify']);
    $log_checked = isset($assoc_args['log-checked']);
    $dry_run = isset($assoc_args['dry-run']);
    $force_delete_local = isset($assoc_args['force']);
    $attachment_batch_size = max(100, (int) ($assoc_args['attachment-batch-size'] ?? 500));

    $provider = strtolower((string) $this->settings->get('provider', 's3'));
    $is_r2 = $provider === 'r2';
    $default_verify_chunk_size = $is_r2 ? 5000 : 2000;
    $default_verify_concurrency = $is_r2 ? 64 : 20;

    $verify_chunk_size = max(100, (int) ($assoc_args['verify-chunk-size'] ?? $default_verify_chunk_size));
    $verify_concurrency = max(1, (int) ($assoc_args['verify-concurrency'] ?? $default_verify_concurrency));

    if ($is_r2 && !isset($assoc_args['verify-chunk-size']) && !isset($assoc_args['verify-concurrency'])) {
      call_user_func(['\WP_CLI', 'log'], sprintf(
        'R2 auto-tuning enabled: verify-chunk-size=%d, verify-concurrency=%d.',
        $verify_chunk_size,
        $verify_concurrency
      ));
    }

    if ($skip_verify) {
      call_user_func(['\WP_CLI', 'warning'], 'Skipping remote verification because --skip-verify was provided. Proceed with caution.');
    }

    if ($pending_only) {
      call_user_func(['\WP_CLI', 'log'], 'Pending-only mode: skipping attachments already marked with offload metadata. Use --all-attachments to override.');
    }

    call_user_func(['\WP_CLI', 'log'], sprintf(
      'Processing %d attachments in batches of %d.',
      $total_attachments,
      $attachment_batch_size
    ));

    $verified_attachment_count = 0;
    $missing_attachment_count = 0;
    $missing_samples = [];

    if (!$skip_verify) {
      call_user_func(['\WP_CLI', 'log'], sprintf(
        'Verifying remote objects using key chunk size %d and concurrency %d.',
        $verify_chunk_size,
        $verify_concurrency
      ));

      $verify_progress = \WP_CLI\Utils\make_progress_bar('Verifying attachment manifests', $total_attachments);

      $this->process_attachment_batches($attachment_batch_size, function (array $attachment_ids) use (
        $uploads_dir,
        $adapter,
        $verify_chunk_size,
        $log_checked,
        $verify_concurrency,
        &$verified_attachment_count,
        &$missing_attachment_count,
        &$missing_samples,
        $verify_progress
      ) {
        $batch = $this->build_batch_manifests($attachment_ids, $uploads_dir);
        $verify_progress->tick($batch['processed']);

        if (empty($batch['manifests'])) {
          return true;
        }

        $existing_lookup = $this->build_existing_key_lookup(
          $batch['required_keys'],
          $adapter,
          $verify_chunk_size,
          $log_checked,
          $verify_concurrency,
          false
        );

        foreach ($batch['manifests'] as $manifest) {
          $missing_for_attachment = [];
          foreach ($manifest['keys'] as $key) {
            if (!isset($existing_lookup[$key])) {
              $missing_for_attachment[] = $key;
            }
          }

          if (!empty($missing_for_attachment)) {
            $missing_attachment_count++;

            if (count($missing_samples) < 3) {
              $missing_samples[] = [
                'attachment_id' => (int) $manifest['attachment_id'],
                'missing_count' => count($missing_for_attachment),
                'first_key' => (string) $missing_for_attachment[0],
              ];
            }

            if ($log_checked) {
              call_user_func(['\WP_CLI', 'warning'], sprintf(
                '[verify:attachment-missing] attachment=%d missing=%d first=%s',
                (int) $manifest['attachment_id'],
                count($missing_for_attachment),
                (string) $missing_for_attachment[0]
              ));
            }
          } else {
            $verified_attachment_count++;
          }
        }

        // Stop early once we know rewrite must abort.
        return $missing_attachment_count === 0;
      }, $pending_only);

      $verify_progress->finish();

      if ($missing_attachment_count > 0) {
        foreach ($missing_samples as $sample) {
          call_user_func(['\WP_CLI', 'warning'], sprintf(
            'Attachment %d is missing %d object(s). Example key: %s',
            (int) $sample['attachment_id'],
            (int) $sample['missing_count'],
            (string) $sample['first_key']
          ));
        }

        call_user_func(['\WP_CLI', 'error'], sprintf(
          'Rewrite aborted: %d attachment(s) have missing remote objects. Run deploy first.',
          $missing_attachment_count
        ));
        return;
      }
    } elseif ($dry_run) {
      $dry_run_progress = \WP_CLI\Utils\make_progress_bar('Scanning eligible manifests', $total_attachments);

      $this->process_attachment_batches($attachment_batch_size, function (array $attachment_ids) use (
        $uploads_dir,
        &$verified_attachment_count,
        $dry_run_progress
      ) {
        $batch = $this->build_batch_manifests($attachment_ids, $uploads_dir);
        $verified_attachment_count += count($batch['manifests']);
        $dry_run_progress->tick($batch['processed']);
        return true;
      }, $pending_only);

      $dry_run_progress->finish();
    }

    if ($dry_run) {
      if ($skip_verify) {
        call_user_func(['\WP_CLI', 'success'], sprintf(
          'Dry run successful. Verification skipped by flag. %d attachment(s) eligible for rewrite. DB rewrite not executed.',
          $verified_attachment_count
        ));
      } else {
        call_user_func(['\WP_CLI', 'success'], sprintf(
          'Dry run successful. %d attachments verified. DB rewrite not executed.',
          $verified_attachment_count
        ));
      }
      return;
    }

    $search_replace = sprintf(
      'search-replace %s %s --all-tables --precise --recurse-objects --skip-columns=guid',
      escapeshellarg($local_base),
      escapeshellarg($remote_base)
    );
    call_user_func(['\WP_CLI', 'runcommand'], $search_replace);

    $smartslider_rewrite = $this->apply_smartslider_url_rewrites($local_base, $remote_base, true);
    if (($smartslider_rewrite['tables'] ?? 0) > 0) {
      call_user_func(['\WP_CLI', 'log'], sprintf(
        'Smart Slider URL rewrite pass complete. Tables: %d, Columns: %d, Updated rows: %d.',
        (int) ($smartslider_rewrite['tables'] ?? 0),
        (int) ($smartslider_rewrite['columns'] ?? 0),
        (int) ($smartslider_rewrite['updated_rows'] ?? 0)
      ));

      if ((int) ($smartslider_rewrite['errors'] ?? 0) > 0) {
        call_user_func(['\WP_CLI', 'warning'], sprintf(
          'Smart Slider URL rewrite pass finished with %d warning(s).',
          (int) ($smartslider_rewrite['errors'] ?? 0)
        ));
      }
    }

    $updated_meta = 0;
    $meta_failures = 0;
    $meta_failure_samples = 0;
    $deleted_local_files = 0;
    $eligible_attachment_count = 0;
    $force_skip_verify_warning_logged = false;
    $meta_progress = \WP_CLI\Utils\make_progress_bar('Persisting offload metadata', $total_attachments);

    $this->process_attachment_batches($attachment_batch_size, function (array $attachment_ids) use (
      $uploads_dir,
      &$updated_meta,
      &$meta_failures,
      &$meta_failure_samples,
      &$deleted_local_files,
      &$eligible_attachment_count,
      &$force_skip_verify_warning_logged,
      $force_delete_local,
      $skip_verify,
      $meta_progress
    ) {
      foreach ($attachment_ids as $attachment_id) {
        $manifest = $this->build_attachment_manifest((int) $attachment_id, $uploads_dir);
        if (!$manifest) {
          $meta_progress->tick();
          continue;
        }

        $eligible_attachment_count++;

        try {
          $this->persist_offload_metadata((int) $manifest['attachment_id'], $manifest['offload_meta']);
          $updated_meta++;
        } catch (\Throwable $exception) {
          $meta_failures++;
          if ($meta_failure_samples < 5) {
            $meta_failure_samples++;
            call_user_func(['\WP_CLI', 'warning'], sprintf(
              'Metadata update failed for attachment %d: %s',
              (int) ($manifest['attachment_id'] ?? 0),
              $exception->getMessage()
            ));
          }
        }

        if ($force_delete_local) {
          if ($skip_verify && !$force_skip_verify_warning_logged) {
            call_user_func(['\WP_CLI', 'warning'], 'Deleting local files with --force while --skip-verify is enabled.');
            $force_skip_verify_warning_logged = true;
          }

          foreach ($manifest['local_files'] as $file_path) {
            if (file_exists($file_path) && @unlink($file_path)) {
              $deleted_local_files++;
            }
          }
        }

        $meta_progress->tick();
      }

      return true;
    }, $pending_only);

    $meta_progress->finish();

    $cache_purge_result = $this->purge_cf_cache_for_posts($affected_post_ids, 'Media Offload rewrite');
    if (($cache_purge_result['eligible'] ?? 0) > 0) {
      call_user_func(['\WP_CLI', 'log'], sprintf(
        'CF cache purge after rewrite: purged %d, failed %d, skipped %d.',
        (int) ($cache_purge_result['purged'] ?? 0),
        (int) ($cache_purge_result['failed'] ?? 0),
        (int) ($cache_purge_result['skipped'] ?? 0)
      ));
    } elseif (($cache_purge_result['requested'] ?? 0) > 0 && !empty($cache_purge_result['message'])) {
      call_user_func(['\WP_CLI', 'warning'], (string) $cache_purge_result['message']);
    }

    if ($meta_failures > 0) {
      call_user_func(['\WP_CLI', 'warning'], sprintf(
        'Metadata updates completed with %d failure(s). Review PHP error log for details.',
        $meta_failures
      ));
    }

    call_user_func(['\WP_CLI', 'success'], sprintf(
      'Rewrite complete. Verified: %d attachments, Metadata updated: %d, Metadata failures: %d, Local files deleted: %d.',
      $eligible_attachment_count,
      $updated_meta,
      $meta_failures,
      $deleted_local_files
    ));
  }

  public function revert(array $args, array $assoc_args): void
  {
    if (!$this->settings->has_valid_credentials()) {
      call_user_func(['\WP_CLI', 'error'], 'Invalid or missing storage credentials. Please configure the plugin first.');
      return;
    }

    $adapter = $this->get_adapter_or_fail();
    if (!$adapter) {
      return;
    }

    $uploads = wp_upload_dir();
    $local_base = trailingslashit($uploads['baseurl'] ?? '');
    if (empty($local_base)) {
      call_user_func(['\WP_CLI', 'error'], 'Unable to resolve local uploads base URL.');
      return;
    }

    $remote_base = $this->resolve_remote_base_url($adapter);
    if (empty($remote_base)) {
      call_user_func(['\WP_CLI', 'error'], 'Unable to resolve remote storage base URL.');
      return;
    }

    $batch_size = max(1, (int) ($assoc_args['batch-size'] ?? 100));

    $query = new \WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => PT_MEDIA_OFFLOAD_META_KEY,
          'compare' => 'EXISTS',
        ],
      ],
    ]);

    $attachment_ids = $query->posts;
    $total = is_array($attachment_ids) ? count($attachment_ids) : 0;

    $restored_count = 0;
    $failed_count = 0;

    if ($total > 0) {
      call_user_func(['\WP_CLI', 'log'], sprintf('Found %d offloaded attachments to restore.', $total));

      $preflight = $this->preflight_revert_capacity_check($attachment_ids, $adapter, $uploads['basedir'] ?? '');
      if (!$preflight['ok']) {
        call_user_func(['\WP_CLI', 'error'], $preflight['message']);
        return;
      }

      call_user_func(['\WP_CLI', 'log'], sprintf(
        'Preflight OK. Bucket allocated for restore: %s across %d object(s). Local free space: %s (required incl. 10%% buffer: %s).',
        size_format($preflight['restore_bytes']),
        $preflight['object_count'],
        size_format($preflight['local_free_bytes']),
        size_format($preflight['required_bytes_with_buffer'])
      ));

      $progress = \WP_CLI\Utils\make_progress_bar('Restoring media to local uploads', $total);
      $chunks = array_chunk($attachment_ids, $batch_size);

      foreach ($chunks as $chunk) {
        foreach ($chunk as $attachment_id) {
          try {
            if ($this->handler->download_media((int) $attachment_id)) {
              $restored_count++;
            } else {
              $failed_count++;
            }
          } catch (\Throwable $exception) {
            $failed_count++;
            call_user_func(['\WP_CLI', 'warning'], sprintf(
              'Failed to restore attachment %d: %s',
              (int) $attachment_id,
              $exception->getMessage()
            ));
          }

          $progress->tick();
        }

        if (function_exists('wp_cache_flush')) {
          wp_cache_flush();
        }
      }

      $progress->finish();
    } else {
      call_user_func(['\WP_CLI', 'log'], 'No offloaded attachment metadata found. Proceeding with DB rewrite only.');
    }

    call_user_func(['\WP_CLI', 'log'], sprintf('Rewriting DB URLs from %s to %s', $remote_base, $local_base));
    $affected_post_ids = $this->collect_affected_post_ids_for_url_rewrite($remote_base);
    if (!empty($affected_post_ids)) {
      call_user_func(['\WP_CLI', 'log'], sprintf(
        'Detected %d affected post/page cache entr%s for targeted purge after revert.',
        count($affected_post_ids),
        count($affected_post_ids) === 1 ? 'y' : 'ies'
      ));
    }

    $search_replace = sprintf(
      'search-replace %s %s --all-tables --precise --recurse-objects --skip-columns=guid',
      escapeshellarg($remote_base),
      escapeshellarg($local_base)
    );
    call_user_func(['\WP_CLI', 'runcommand'], $search_replace);

    $smartslider_revert = $this->apply_smartslider_url_rewrites($remote_base, $local_base, false);
    if (($smartslider_revert['tables'] ?? 0) > 0) {
      call_user_func(['\WP_CLI', 'log'], sprintf(
        'Smart Slider URL revert pass complete. Tables: %d, Columns: %d, Updated rows: %d.',
        (int) ($smartslider_revert['tables'] ?? 0),
        (int) ($smartslider_revert['columns'] ?? 0),
        (int) ($smartslider_revert['updated_rows'] ?? 0)
      ));

      if ((int) ($smartslider_revert['errors'] ?? 0) > 0) {
        call_user_func(['\WP_CLI', 'warning'], sprintf(
          'Smart Slider URL revert pass finished with %d warning(s).',
          (int) ($smartslider_revert['errors'] ?? 0)
        ));
      }
    }

    $cache_purge_result = $this->purge_cf_cache_for_posts($affected_post_ids, 'Media Offload revert');
    if (($cache_purge_result['eligible'] ?? 0) > 0) {
      call_user_func(['\WP_CLI', 'log'], sprintf(
        'CF cache purge after revert: purged %d, failed %d, skipped %d.',
        (int) ($cache_purge_result['purged'] ?? 0),
        (int) ($cache_purge_result['failed'] ?? 0),
        (int) ($cache_purge_result['skipped'] ?? 0)
      ));
    } elseif (($cache_purge_result['requested'] ?? 0) > 0 && !empty($cache_purge_result['message'])) {
      call_user_func(['\WP_CLI', 'warning'], (string) $cache_purge_result['message']);
    }

    if ($failed_count > 0) {
      call_user_func(['\WP_CLI', 'warning'], sprintf(
        'Revert completed with issues. Restored: %d, Failed: %d. DB rewrite executed.',
        $restored_count,
        $failed_count
      ));
      return;
    }

    call_user_func(['\WP_CLI', 'success'], sprintf(
      'Revert complete. Restored: %d attachment(s). DB rewrite executed.',
      $restored_count
    ));
  }

  private function preflight_revert_capacity_check(array $attachment_ids, Storage_Adapter $adapter, string $uploads_dir): array
  {
    if (empty($uploads_dir) || !is_dir($uploads_dir)) {
      return [
        'ok' => false,
        'message' => 'Preflight failed: uploads directory is invalid or missing.',
      ];
    }

    $keys = $this->collect_offloaded_keys($attachment_ids);
    if (empty($keys)) {
      return [
        'ok' => true,
        'message' => 'No offloaded keys found.',
        'restore_bytes' => 0,
        'object_count' => 0,
        'local_free_bytes' => (int) (disk_free_space($uploads_dir) ?: 0),
        'required_bytes_with_buffer' => 0,
      ];
    }

    call_user_func(['\WP_CLI', 'log'], sprintf('Preflight: checking bucket allocated size for %d object(s)...', count($keys)));
    $size_progress = \WP_CLI\Utils\make_progress_bar('Checking object sizes', count($keys));

    $restore_bytes = 0;
    $missing_keys = [];

    foreach ($keys as $key) {
      try {
        $restore_bytes += max(0, $adapter->get_object_size($key));
      } catch (\Throwable $exception) {
        $missing_keys[] = $key;
      }

      $size_progress->tick();
    }

    $size_progress->finish();

    if (!empty($missing_keys)) {
      return [
        'ok' => false,
        'message' => sprintf(
          'Preflight failed: %d object(s) are missing or inaccessible in bucket. Example key: %s',
          count($missing_keys),
          $missing_keys[0]
        ),
      ];
    }

    $local_free = disk_free_space($uploads_dir);
    if ($local_free === false) {
      return [
        'ok' => false,
        'message' => 'Preflight failed: unable to determine local free disk space.',
      ];
    }

    $required_with_buffer = (int) ceil($restore_bytes * 1.10);
    if ((int) $local_free < $required_with_buffer) {
      return [
        'ok' => false,
        'message' => sprintf(
          'Preflight failed: insufficient server space. Required %s (includes 10%% buffer), available %s.',
          size_format($required_with_buffer),
          size_format((int) $local_free)
        ),
      ];
    }

    return [
      'ok' => true,
      'message' => 'Preflight checks passed.',
      'restore_bytes' => (int) $restore_bytes,
      'object_count' => count($keys),
      'local_free_bytes' => (int) $local_free,
      'required_bytes_with_buffer' => $required_with_buffer,
    ];
  }

  private function collect_offloaded_keys(array $attachment_ids): array
  {
    $keys = [];

    foreach ($attachment_ids as $attachment_id) {
      $meta = get_post_meta((int) $attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
      if (!is_array($meta) || empty($meta['key'])) {
        continue;
      }

      $keys[] = (string) $meta['key'];

      if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
        foreach ($meta['sizes'] as $size_meta) {
          if (is_array($size_meta) && !empty($size_meta['key'])) {
            $keys[] = (string) $size_meta['key'];
          }
        }
      }
    }

    return array_values(array_unique($keys));
  }

  public function download(array $args, array $assoc_args): void
  {
    if (!$this->settings->is_enabled()) {
      call_user_func(['\WP_CLI', 'error'], 'Media offload is disabled.');
      return;
    }

    if (!$this->settings->has_valid_credentials()) {
      call_user_func(['\WP_CLI', 'error'], 'Invalid or missing S3 credentials.');
      return;
    }

    $id = $assoc_args['id'] ?? null;
    $all = $assoc_args['all'] ?? false;
    $batch_size = (int) ($assoc_args['batch-size'] ?? 100);

    if ($id) {
        if ($this->handler->download_media((int) $id)) {
            call_user_func(['\WP_CLI', 'success'], "Successfully downloaded attachment ID $id.");
        } else {
            call_user_func(['\WP_CLI', 'error'], "Failed to download attachment ID $id (or it was not offloaded).");
        }
        return;
    }

    if (!$all) {
        call_user_func(['\WP_CLI', 'error'], 'Please specify --id=<id> or --all.');
        return;
    }

    // Query for offloaded items
    $query_args = [
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => PT_MEDIA_OFFLOAD_META_KEY,
          'compare' => 'EXISTS',
        ],
      ],
    ];

    call_user_func(['\WP_CLI', 'log'], 'Calculating total attachments to download...');
    $query = new \WP_Query($query_args);
    $total = $query->found_posts;
    $all_ids = $query->posts;

    if ($total === 0) {
      call_user_func(['\WP_CLI', 'success'], 'No offloaded attachments found.');
      return;
    }

    call_user_func(['\WP_CLI', 'log'], sprintf('Found %d attachments to download.', $total));

    $progress = \WP_CLI\Utils\make_progress_bar('Downloading media', $total);
    $success_count = 0;
    $error_count = 0;

    $chunks = array_chunk($all_ids, $batch_size);

    foreach ($chunks as $chunk) {
      foreach ($chunk as $attachment_id) {
        try {
          if ($this->handler->download_media($attachment_id)) {
            $success_count++;
          } else {
             // Maybe log warning?
          }
        } catch (\Exception $e) {
          $error_count++;
        }
        $progress->tick();
      }
      
      // Free up memory
      if (function_exists('wp_cache_flush')) {
          wp_cache_flush();
      }
    }

    $progress->finish();
    call_user_func(['\WP_CLI', 'success'], sprintf('Downloaded %d attachments. Errors: %d.', $success_count, $error_count));
  }

  public function replace_urls(array $args, array $assoc_args): void
  {
    // Backward-compatible alias:
    // --run means execute; default remains dry-run for replace-urls only.
    if (!isset($assoc_args['run'])) {
      $assoc_args['dry-run'] = true;
    }

    unset($assoc_args['run']);
    $this->rewrite($args, $assoc_args);
  }

  public function offload_single(array $args, array $assoc_args): void
  {
    if (empty($args[0])) {
      call_user_func(['\WP_CLI', 'error'], 'Please provide an attachment ID.');
      return;
    }

    $attachment_id = (int) $args[0];
    if ($this->handler->offload_via_cli($attachment_id)) {
      call_user_func(['\WP_CLI', 'success'], "Successfully offloaded attachment ID $attachment_id.");
    } else {
      call_user_func(['\WP_CLI', 'error'], "Failed to offload attachment ID $attachment_id.");
    }
  }

  public function sync(array $args, array $assoc_args): void
  {
    if (!$this->settings->is_enabled()) {
      call_user_func(['\WP_CLI', 'error'], 'Media offload is disabled. Enable it in settings before syncing.');
      return;
    }

    $batch_size = (int) ($assoc_args['batch-size'] ?? 100);
    $force = (bool) ($assoc_args['force'] ?? false);

    // Count total attachments
    $query_args = [
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => -1,
      'fields' => 'ids',
    ];

    if (!$force) {
      // Exclude already offloaded items
      $query_args['meta_query'] = [
        [
          'key' => PT_MEDIA_OFFLOAD_META_KEY,
          'compare' => 'NOT EXISTS',
        ],
      ];
    }

    call_user_func(['\WP_CLI', 'log'], 'Calculating total attachments to sync...');
    $query = new \WP_Query($query_args);
    $all_ids = is_array($query->posts) ? $this->handler->filter_eligible_attachment_ids(array_map('intval', $query->posts)) : [];
    $total = count($all_ids);

    if ($total === 0) {
      call_user_func(['\WP_CLI', 'success'], 'No attachments found to sync.');
      return;
    }

    call_user_func(['\WP_CLI', 'log'], sprintf('Found %d attachments to sync.', $total));

    $progress = \WP_CLI\Utils\make_progress_bar('Offloading media', $total);
    $success_count = 0;
    $error_count = 0;

    // Process in chunks to save memory
    $chunks = array_chunk($all_ids, $batch_size);

    foreach ($chunks as $chunk) {
      foreach ($chunk as $attachment_id) {
        try {
          if ($this->handler->offload_via_cli($attachment_id)) {
            $success_count++;
          } else {
            // offload_via_cli returns false if it skipped or failed gracefully
            // We can consider checking if it was a real error or just a skip
          }
        } catch (\Exception $e) {
          $error_count++;
          call_user_func(['\WP_CLI', 'warning'], sprintf('Failed to offload ID %d: %s', $attachment_id, $e->getMessage()));
        }
        $progress->tick();
      }
      
      // Clear object cache to free memory
      if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
      }
    }

    $progress->finish();

    call_user_func(['\WP_CLI', 'success'], sprintf(
      'Sync complete. Success: %d, Failed/Skipped: %d.', 
      $success_count, 
      $total - $success_count
    ));
  }

  private function get_adapter_or_fail(): ?Storage_Adapter
  {
    try {
      return $this->factory->get_adapter();
    } catch (\Throwable $exception) {
      call_user_func(['\WP_CLI', 'error'], 'Failed to initialize storage adapter: ' . $exception->getMessage());
      return null;
    }
  }

  private function parse_cli_bool(string $value, string $option_name): bool
  {
    $normalized = strtolower(trim($value));

    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
      return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
      return false;
    }

    call_user_func(['\WP_CLI', 'error'], sprintf(
      'Invalid boolean value for --%s. Use one of: 1, 0, true, false, yes, no, on, off.',
      $option_name
    ));

    return false;
  }

  private function render_config_table(): void
  {
    $settings = $this->settings->all();
    $effective = $this->settings->adapter_configuration();

    $rows = [
      ['setting' => 'provider', 'value' => (string) ($settings['provider'] ?? 's3')],
      ['setting' => 'enabled', 'value' => $this->format_bool((bool) ($settings['enabled'] ?? false))],
      ['setting' => 'bucket', 'value' => (string) ($settings['bucket'] ?? '')],
      ['setting' => 'r2_account_id', 'value' => (string) ($settings['r2_account_id'] ?? '')],
      ['setting' => 'region', 'value' => (string) ($effective['region'] ?? '')],
      ['setting' => 'endpoint', 'value' => (string) ($effective['endpoint'] ?? '')],
      ['setting' => 'access_key', 'value' => $this->mask_secret((string) ($settings['access_key'] ?? ''))],
      ['setting' => 'secret_key', 'value' => $this->mask_secret((string) ($settings['secret_key'] ?? ''))],
      ['setting' => 'session_token', 'value' => $this->mask_secret((string) ($effective['session_token'] ?? ''))],
      ['setting' => 'prefix', 'value' => (string) ($settings['prefix'] ?? '')],
      ['setting' => 'custom_domain', 'value' => (string) ($settings['custom_domain'] ?? '')],
      ['setting' => 'use_path_style_endpoint', 'value' => $this->format_bool((bool) ($effective['use_path_style_endpoint'] ?? false))],
      ['setting' => 'serve_from_storage', 'value' => $this->format_bool((bool) ($settings['serve_from_storage'] ?? false))],
      ['setting' => 'remove_local_copies', 'value' => $this->format_bool((bool) ($settings['remove_local_copies'] ?? false))],
      ['setting' => 'purge_cloudflare_cache_on_delete', 'value' => $this->format_bool((bool) ($settings['purge_cloudflare_cache_on_delete'] ?? false))],
      ['setting' => 'cloudflare_zone_id', 'value' => (string) ($settings['cloudflare_zone_id'] ?? '')],
      ['setting' => 'cloudflare_api_token', 'value' => $this->mask_secret((string) ($settings['cloudflare_api_token'] ?? ''))],
      ['setting' => 'allowed_path_patterns', 'value' => $this->format_pattern_list((string) ($settings['allowed_path_patterns'] ?? ''))],
      ['setting' => 'ignored_path_patterns', 'value' => $this->format_pattern_list((string) ($settings['ignored_path_patterns'] ?? ''))],
      ['setting' => 'off_hours_batch_enabled', 'value' => $this->format_bool((bool) ($settings['off_hours_batch_enabled'] ?? false))],
      ['setting' => 'off_hours_batch_time', 'value' => (string) ($settings['off_hours_batch_time'] ?? '')],
      ['setting' => 'off_hours_worker_count', 'value' => (string) ($settings['off_hours_worker_count'] ?? '')],
      ['setting' => 'off_hours_worker_batch_size', 'value' => (string) ($settings['off_hours_worker_batch_size'] ?? '')],
      ['setting' => 'off_hours_claim_ttl_seconds', 'value' => (string) ($settings['off_hours_claim_ttl_seconds'] ?? '')],
    ];

    \WP_CLI\Utils\format_items('table', $rows, ['setting', 'value']);
  }

  private function mask_secret(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    if (strlen($value) <= 8) {
      return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
  }

  private function format_bool(bool $value): string
  {
    return $value ? 'yes' : 'no';
  }

  private function format_pattern_list(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    return implode(', ', preg_split('/\r\n|\r|\n/', $value) ?: []);
  }

  private function count_total_attachments(bool $pending_only = false): int
  {
    global $wpdb;

    if ($wpdb instanceof \wpdb) {
      $count = 0;
      $last_id = 0;
      $batch_size = 1000;

      while (true) {
        if ($pending_only) {
          $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID
              AND pm.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND p.ID > %d
               AND pm.meta_id IS NULL
             ORDER BY p.ID ASC
             LIMIT %d",
            PT_MEDIA_OFFLOAD_META_KEY,
            'attachment',
            'inherit',
            $last_id,
            $batch_size
          ));
        } else {
          $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
            'attachment',
            'inherit',
            $last_id,
            $batch_size
          ));
        }

        if (empty($ids)) {
          break;
        }

        $batch_ids = array_map('intval', $ids);
        $count += count($this->handler->filter_eligible_attachment_ids($batch_ids));
        $last_id = (int) end($batch_ids);

        if (count($batch_ids) < $batch_size) {
          break;
        }
      }

      return max(0, $count);
    }

    $query_args = [
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => 1,
      'fields' => 'ids',
      'no_found_rows' => false,
    ];

    if ($pending_only) {
      $query_args['meta_query'] = [
        [
          'key' => PT_MEDIA_OFFLOAD_META_KEY,
          'compare' => 'NOT EXISTS',
        ],
      ];
    }

    $query = new \WP_Query($query_args);

    $ids = is_array($query->posts) ? array_map('intval', $query->posts) : [];
    return max(0, count($this->handler->filter_eligible_attachment_ids($ids)));
  }

  private function process_attachment_batches(int $batch_size, callable $handler, bool $pending_only = false): void
  {
    global $wpdb;

    $batch_size = max(50, $batch_size);

    if ($wpdb instanceof \wpdb) {
      $last_id = 0;

      while (true) {
        if ($pending_only) {
          $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID
              AND pm.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND p.ID > %d
               AND pm.meta_id IS NULL
             ORDER BY p.ID ASC
             LIMIT %d",
            PT_MEDIA_OFFLOAD_META_KEY,
            'attachment',
            'inherit',
            $last_id,
            $batch_size
          ));
        } else {
          $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
            'attachment',
            'inherit',
            $last_id,
            $batch_size
          ));
        }

        if (empty($ids)) {
          break;
        }

        $batch_ids = $this->handler->filter_eligible_attachment_ids(array_map('intval', $ids));
        $should_continue = $handler($batch_ids);

        $last_id = (int) end($ids);
        if ($should_continue === false) {
          break;
        }

        if (function_exists('wp_cache_flush')) {
          wp_cache_flush();
        }
      }

      return;
    }

    $page = 1;
    while (true) {
      $query_args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => $batch_size,
        'paged' => $page,
        'fields' => 'ids',
        'no_found_rows' => true,
      ];

      if ($pending_only) {
        $query_args['meta_query'] = [
          [
            'key' => PT_MEDIA_OFFLOAD_META_KEY,
            'compare' => 'NOT EXISTS',
          ],
        ];
      }

      $query = new \WP_Query($query_args);

      $ids = is_array($query->posts) ? $this->handler->filter_eligible_attachment_ids(array_map('intval', $query->posts)) : [];
      if (empty($ids)) {
        break;
      }

      $should_continue = $handler($ids);
      if ($should_continue === false) {
        break;
      }

      if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
      }

      if (count($ids) < $batch_size) {
        break;
      }

      $page++;
    }
  }

  private function collect_affected_post_ids_for_url_rewrite(string $search): array
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
      return [];
    }

    $search = trim($search);
    if ($search === '') {
      return [];
    }

    $like = '%' . $wpdb->esc_like($search) . '%';
    $post_table = $wpdb->posts;
    $postmeta_table = $wpdb->postmeta;
    $comments_table = $wpdb->comments;

    $sql = $wpdb->prepare(
      "SELECT DISTINCT affected.post_id
       FROM (
         SELECT p.ID AS post_id
         FROM {$post_table} p
         WHERE p.post_type IN ('post', 'page')
           AND p.post_status NOT IN ('trash', 'auto-draft')
           AND (
             p.post_content LIKE %s
             OR p.post_excerpt LIKE %s
             OR p.post_content_filtered LIKE %s
           )

         UNION

         SELECT pm.post_id AS post_id
         FROM {$postmeta_table} pm
         INNER JOIN {$post_table} p ON p.ID = pm.post_id
         WHERE p.post_type IN ('post', 'page')
           AND p.post_status NOT IN ('trash', 'auto-draft')
           AND pm.meta_value LIKE %s

         UNION

         SELECT c.comment_post_ID AS post_id
         FROM {$comments_table} c
         INNER JOIN {$post_table} p ON p.ID = c.comment_post_ID
         WHERE p.post_type IN ('post', 'page')
           AND p.post_status NOT IN ('trash', 'auto-draft')
           AND c.comment_content LIKE %s
       ) AS affected
       WHERE affected.post_id > 0
       ORDER BY affected.post_id ASC",
      $like,
      $like,
      $like,
      $like,
      $like
    );

    $ids = $wpdb->get_col($sql);
    if (!is_array($ids)) {
      return [];
    }

    return array_values(array_unique(array_map('intval', $ids)));
  }

  private function purge_cf_cache_for_posts(array $post_ids, string $context): array
  {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));

    if (empty($post_ids)) {
      return [
        'requested' => 0,
        'eligible' => 0,
        'purged' => 0,
        'failed' => 0,
        'skipped' => 0,
        'message' => '',
      ];
    }

    if (!class_exists('\\CF_Cache_Tags_Plugin') || !method_exists('\\CF_Cache_Tags_Plugin', 'get_instance')) {
      return [
        'requested' => count($post_ids),
        'eligible' => 0,
        'purged' => 0,
        'failed' => 0,
        'skipped' => count($post_ids),
        'message' => 'CF Cache Tags plugin not active; targeted post cache purge skipped.',
      ];
    }

    $plugin = \CF_Cache_Tags_Plugin::get_instance();
    $logic = is_object($plugin) && isset($plugin->logic) ? $plugin->logic : null;

    if (!is_object($logic) || !method_exists($logic, 'get_tags_for_post') || !method_exists($logic, 'manual_purge_tags')) {
      return [
        'requested' => count($post_ids),
        'eligible' => 0,
        'purged' => 0,
        'failed' => 0,
        'skipped' => count($post_ids),
        'message' => 'CF Cache Tags logic is unavailable; targeted post cache purge skipped.',
      ];
    }

    $eligible = 0;
    $purged = 0;
    $failed = 0;
    $skipped = 0;
    $taggable = 0;
    $tags_to_purge = [];

    foreach ($post_ids as $post_id) {
      $post = get_post($post_id);
      if (!$post instanceof \WP_Post) {
        $skipped++;
        continue;
      }

      if (!in_array($post->post_type, ['post', 'page'], true)) {
        $skipped++;
        continue;
      }

      try {
        $tags = $logic->get_tags_for_post($post_id);
        if (empty($tags) || !is_array($tags)) {
          $skipped++;
          continue;
        }

        $eligible++;
        $taggable++;

        foreach ($tags as $tag) {
          $tag = is_string($tag) ? trim($tag) : '';
          if ($tag !== '') {
            $tags_to_purge[$tag] = true;
          }
        }
      } catch (\Throwable $exception) {
        $failed++;
        $this->logger->warning('Failed to purge CF cache for post {post_id} after {context}: {error}', [
          'post_id' => $post_id,
          'context' => $context,
          'error' => $exception->getMessage(),
        ]);
      }
    }

    if (!empty($tags_to_purge)) {
      try {
        $purge_ok = (bool) $logic->manual_purge_tags(array_keys($tags_to_purge), sprintf('%s (%d posts)', $context, $taggable));
        if ($purge_ok) {
          $purged = $taggable;
        } else {
          $failed += $taggable;
        }
      } catch (\Throwable $exception) {
        $failed += $taggable;
        $this->logger->warning('Failed to execute CF cache purge after {context}: {error}', [
          'context' => $context,
          'error' => $exception->getMessage(),
        ]);
      }
    }

    return [
      'requested' => count($post_ids),
      'eligible' => $eligible,
      'purged' => $purged,
      'failed' => $failed,
      'skipped' => $skipped,
      'message' => '',
    ];
  }

  private function build_batch_manifests(array $attachment_ids, string $uploads_dir): array
  {
    $manifests = [];
    $required_keys = [];
    $processed = 0;

    foreach ($attachment_ids as $attachment_id) {
      $processed++;
      $manifest = $this->build_attachment_manifest((int) $attachment_id, $uploads_dir);
      if (!$manifest) {
        continue;
      }

      $manifests[] = $manifest;
      foreach ($manifest['keys'] as $key) {
        $required_keys[$key] = true;
      }
    }

    return [
      'processed' => $processed,
      'manifests' => $manifests,
      'required_keys' => array_keys($required_keys),
    ];
  }

  private function collect_upload_files_grouped(string $uploads_dir): array
  {
    $uploads_dir = rtrim(str_replace('\\', '/', $uploads_dir), '/');
    $groups = [];

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($uploads_dir, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
      if (!$item instanceof \SplFileInfo || !$item->isFile() || $item->isLink()) {
        continue;
      }

      $absolute = str_replace('\\', '/', $item->getPathname());
      $relative = ltrim(str_replace(trailingslashit($uploads_dir), '', $absolute), '/');
      if ($relative === '') {
        continue;
      }

      // Only process WordPress year-based uploads paths and skip all other
      // plugin/system directories that may exist under uploads.
      if (!$this->is_yearly_upload_path($relative)) {
        continue;
      }

      if (!$this->settings->should_offload_relative_path($relative)) {
        continue;
      }

      $group = $this->resolve_month_group($relative);
      if (!isset($groups[$group])) {
        $groups[$group] = [];
      }

      $groups[$group][] = [
        'absolute' => $absolute,
        'relative' => $relative,
      ];
    }

    ksort($groups);
    return $groups;
  }

  private function resolve_month_group(string $relative_path): string
  {
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');

    if (preg_match('#^(sites/\d+/)?(\d{4}/\d{2})(/|$)#', $relative_path, $matches)) {
      $site_prefix = $matches[1] ?? '';
      return rtrim($site_prefix . $matches[2], '/');
    }

    if (preg_match('#^(sites/\d+/)?(\d{4})(/|$)#', $relative_path, $matches)) {
      $site_prefix = $matches[1] ?? '';
      return rtrim($site_prefix . $matches[2], '/');
    }

    return '_misc';
  }

  private function is_yearly_upload_path(string $relative_path): bool
  {
    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');

    // Standard WordPress uploads: YYYY/... or YYYY/MM/...
    // Multisite uploads: sites/<blog_id>/YYYY/... or sites/<blog_id>/YYYY/MM/...
    return (bool) preg_match('#^(\d{4}|sites/\d+/\d{4})(/|$)#', $relative_path);
  }

  private function verify_uploaded_group(array $files, Storage_Adapter $adapter, string $prefix): array
  {
    $missing = [];
    $keys = [];
    $key_to_relative = [];

    foreach ($files as $entry) {
      $key = build_storage_key($entry['relative'], $prefix);
      $keys[] = $key;
      $key_to_relative[$key] = $entry['relative'];
    }

    $existing_lookup = $this->build_existing_key_lookup($keys, $adapter, 1000);
    foreach ($key_to_relative as $key => $relative) {
      if (!isset($existing_lookup[$key])) {
        $missing[] = $relative;
      }
    }

    return $missing;
  }

  private function build_existing_key_lookup(
    array $keys,
    Storage_Adapter $adapter,
    int $chunk_size = 2000,
    bool $log_checked = false,
    int $concurrency = 20,
    bool $show_progress = true
  ): array
  {
    $normalized = [];
    foreach ($keys as $key) {
      $normalized_key = ltrim((string) $key, '/');
      if ($normalized_key === '') {
        continue;
      }

      $normalized[$normalized_key] = true;
    }

    if (empty($normalized)) {
      return [];
    }

    $all_keys = array_keys($normalized);
    $chunk_size = max(100, $chunk_size);
    $progress = $show_progress
      ? \WP_CLI\Utils\make_progress_bar('Verifying remote objects', count($all_keys))
      : null;
    $existing_lookup = [];
    $chunk_index = 0;
    $concurrency = max(1, $concurrency);

    foreach (array_chunk($all_keys, $chunk_size) as $chunk) {
      $chunk_index++;
      $existing_keys = $adapter->existing_keys($chunk, [
        'concurrency' => $concurrency,
      ]);

      $chunk_existing_lookup = array_fill_keys($existing_keys, true);
      foreach ($existing_keys as $existing_key) {
        $existing_lookup[(string) $existing_key] = true;
      }

      $found_count = count($chunk_existing_lookup);
      $missing_count = count($chunk) - $found_count;
      if ($show_progress || $log_checked) {
        call_user_func(['\WP_CLI', 'log'], sprintf(
          'Verification chunk %d: checked=%d found=%d missing=%d',
          $chunk_index,
          count($chunk),
          $found_count,
          max(0, $missing_count)
        ));
      }

      if ($log_checked) {
        foreach ($chunk as $checked_key) {
          $status = isset($chunk_existing_lookup[$checked_key]) ? 'FOUND' : 'MISSING';
          call_user_func(['\WP_CLI', 'log'], sprintf('[verify:%s] %s', $status, $checked_key));
        }
      }

      if ($progress) {
        $progress->tick(count($chunk));
      }
    }

    if ($progress) {
      $progress->finish();
    }

    return $existing_lookup;
  }

  private function resolve_remote_base_url(Storage_Adapter $adapter): string
  {
    $probe_name = 'pt-media-offload-url-probe';
    $probe_key = build_storage_key($probe_name, (string) $this->settings->get('prefix', ''));

    try {
      $probe_url = $adapter->get_url($probe_key);
    } catch (\Throwable $exception) {
      $this->logger->warning('Failed to build remote base URL: {error}', [
        'error' => $exception->getMessage(),
      ]);
      return '';
    }

    $position = strrpos($probe_url, $probe_name);
    if ($position === false) {
      return '';
    }

    return trailingslashit(substr($probe_url, 0, $position));
  }

  private function apply_smartslider_url_rewrites(string $from_base, string $to_base, bool $rewrite_upload_placeholder): array
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
      return [
        'tables' => 0,
        'columns' => 0,
        'updated_rows' => 0,
        'errors' => 1,
      ];
    }

    $pairs = $this->build_smartslider_replacement_pairs($from_base, $to_base, $rewrite_upload_placeholder);
    if (empty($pairs)) {
      return [
        'tables' => 0,
        'columns' => 0,
        'updated_rows' => 0,
        'errors' => 0,
      ];
    }

    $tables = $this->get_smartslider_tables();
    if (empty($tables)) {
      return [
        'tables' => 0,
        'columns' => 0,
        'updated_rows' => 0,
        'errors' => 0,
      ];
    }

    $touched_columns = 0;
    $updated_rows = 0;
    $errors = 0;

    foreach ($tables as $table) {
      $text_columns = $this->get_text_columns_for_table($table);
      foreach ($text_columns as $column) {
        $touched_columns++;

        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $column_sql = '`' . str_replace('`', '``', $column) . '`';

        foreach ($pairs as $pair) {
          $from = (string) ($pair['from'] ?? '');
          $to = (string) ($pair['to'] ?? '');

          if ($from === '' || $from === $to) {
            continue;
          }

          $sql = $wpdb->prepare(
            "UPDATE {$table_sql} SET {$column_sql} = REPLACE({$column_sql}, %s, %s) WHERE {$column_sql} LIKE %s",
            $from,
            $to,
            '%' . $wpdb->esc_like($from) . '%'
          );

          $result = $wpdb->query($sql);
          if ($result === false) {
            $errors++;
            continue;
          }

          $updated_rows += (int) $result;
        }
      }
    }

    return [
      'tables' => count($tables),
      'columns' => $touched_columns,
      'updated_rows' => $updated_rows,
      'errors' => $errors,
    ];
  }

  private function build_smartslider_replacement_pairs(string $from_base, string $to_base, bool $rewrite_upload_placeholder): array
  {
    $from_base = trailingslashit($from_base);
    $to_base = trailingslashit($to_base);

    $pairs = [
      ['from' => $from_base, 'to' => $to_base],
      ['from' => untrailingslashit($from_base), 'to' => untrailingslashit($to_base)],
      ['from' => str_replace('/', '\\/', $from_base), 'to' => str_replace('/', '\\/', $to_base)],
      ['from' => str_replace('/', '\\/', untrailingslashit($from_base)), 'to' => str_replace('/', '\\/', untrailingslashit($to_base))],
      ['from' => rawurlencode($from_base), 'to' => rawurlencode($to_base)],
      ['from' => rawurlencode(untrailingslashit($from_base)), 'to' => rawurlencode(untrailingslashit($to_base))],
    ];

    if ($rewrite_upload_placeholder) {
      $pairs[] = ['from' => '$upload$/', 'to' => $to_base];
    }

    $deduped = [];
    $seen = [];

    foreach ($pairs as $pair) {
      $from = (string) ($pair['from'] ?? '');
      $to = (string) ($pair['to'] ?? '');
      if ($from === '' || $from === $to) {
        continue;
      }

      $key = $from . "\n" . $to;
      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = true;
      $deduped[] = [
        'from' => $from,
        'to' => $to,
      ];
    }

    return $deduped;
  }

  private function get_smartslider_tables(): array
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
      return [];
    }

    $prefix = (string) $wpdb->prefix;
    $pattern = $prefix . 'nextend2_%';
    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
    if (!is_array($tables)) {
      return [];
    }

    $smartslider_tables = [];
    foreach ($tables as $table) {
      $table_name = (string) $table;
      if (preg_match('/nextend2_(smartslider3_|image_storage|section_storage)/i', $table_name)) {
        $smartslider_tables[] = $table_name;
      }
    }

    sort($smartslider_tables);
    return array_values(array_unique($smartslider_tables));
  }

  private function get_text_columns_for_table(string $table): array
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb || $table === '') {
      return [];
    }

    $table_sql = '`' . str_replace('`', '``', $table) . '`';
    $columns = $wpdb->get_results("DESCRIBE {$table_sql}", ARRAY_A);
    if (!is_array($columns)) {
      return [];
    }

    $text_columns = [];
    foreach ($columns as $column) {
      $field = (string) ($column['Field'] ?? '');
      $type = strtolower((string) ($column['Type'] ?? ''));
      if ($field === '') {
        continue;
      }

      if (preg_match('/(char|text)/', $type)) {
        $text_columns[] = $field;
      }
    }

    return array_values(array_unique($text_columns));
  }

  private function build_attachment_manifest(int $attachment_id, string $uploads_dir): ?array
  {
    if (!$this->handler->is_attachment_eligible_for_offload($attachment_id)) {
      return null;
    }

    $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (!is_string($attached_file) || $attached_file === '') {
      return null;
    }

    $attached_file = ltrim(str_replace('\\', '/', $attached_file), '/');
    $prefix = (string) $this->settings->get('prefix', '');
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata)) {
      $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
      if (!is_array($metadata)) {
        $metadata = [];
      }
    }

    $main_key = build_storage_key($attached_file, $prefix);
    $offload_meta = [
      'key' => $main_key,
      'sizes' => [],
    ];

    $keys = [$main_key];
    $local_files = [path_join($uploads_dir, $attached_file)];
    $base_dir = dirname($attached_file);

    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
      foreach ($metadata['sizes'] as $size => $size_data) {
        if (empty($size_data['file'])) {
          continue;
        }

        $size_relative = ($base_dir && $base_dir !== '.')
          ? trailingslashit($base_dir) . ltrim((string) $size_data['file'], '/')
          : ltrim((string) $size_data['file'], '/');
        $size_relative = ltrim(str_replace('\\', '/', $size_relative), '/');

        $size_key = build_storage_key($size_relative, $prefix);
        $offload_meta['sizes'][$size] = ['key' => $size_key];
        $keys[] = $size_key;
        $local_files[] = path_join($uploads_dir, $size_relative);
      }
    }

    return [
      'attachment_id' => $attachment_id,
      'offload_meta' => $offload_meta,
      'keys' => array_values(array_unique($keys)),
      'local_files' => array_values(array_unique($local_files)),
    ];
  }

  private function persist_offload_metadata(int $attachment_id, array $offload_meta): void
  {
    $this->safe_update_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, $offload_meta);

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata)) {
      $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
      if (!is_array($metadata)) {
        $metadata = [];
      }
    }

    $metadata['pt_media_offload'] = $offload_meta;

    if (!empty($offload_meta['sizes']) && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
      foreach ($offload_meta['sizes'] as $size => $size_meta) {
        if (!isset($metadata['sizes'][$size]) || !is_array($metadata['sizes'][$size])) {
          continue;
        }

        $metadata['sizes'][$size]['pt_media_offload'] = $size_meta;
      }
    }

    $this->safe_update_post_meta($attachment_id, '_wp_attachment_metadata', $metadata);
  }

  private function safe_update_post_meta(int $post_id, string $meta_key, $meta_value): void
  {
    try {
      update_post_meta($post_id, $meta_key, $meta_value);
      return;
    } catch (\Throwable $exception) {
      $this->logger->warning('update_post_meta failed for post {post_id} key {meta_key}: {error}. Falling back to direct DB upsert.', [
        'post_id' => $post_id,
        'meta_key' => $meta_key,
        'error' => $exception->getMessage(),
      ]);
    }

    $this->upsert_post_meta_direct($post_id, $meta_key, $meta_value);
  }

  private function upsert_post_meta_direct(int $post_id, string $meta_key, $meta_value): void
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
      throw new \RuntimeException('WordPress DB object is unavailable for metadata fallback.');
    }

    $table = $wpdb->postmeta;
    $serialized_value = maybe_serialize($meta_value);

    $meta_id = $wpdb->get_var($wpdb->prepare(
      "SELECT meta_id FROM {$table} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
      $post_id,
      $meta_key
    ));

    if ($meta_id !== null) {
      $updated = $wpdb->update(
        $table,
        ['meta_value' => $serialized_value],
        ['meta_id' => (int) $meta_id],
        ['%s'],
        ['%d']
      );

      if ($updated === false) {
        throw new \RuntimeException(sprintf('Failed direct metadata update for post %d key %s', $post_id, $meta_key));
      }
    } else {
      $inserted = $wpdb->insert(
        $table,
        [
          'post_id' => $post_id,
          'meta_key' => $meta_key,
          'meta_value' => $serialized_value,
        ],
        ['%d', '%s', '%s']
      );

      if ($inserted === false) {
        throw new \RuntimeException(sprintf('Failed direct metadata insert for post %d key %s', $post_id, $meta_key));
      }
    }

    try {
      if (function_exists('clean_post_cache')) {
        clean_post_cache($post_id);
      }
    } catch (\Throwable $ignored) {
      // Ignore cache cleanup failures during DB fallback mode.
    }
  }
}
