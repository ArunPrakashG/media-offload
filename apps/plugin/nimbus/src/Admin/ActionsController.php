<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Bootstrap\DataMigration;
use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Onboarding\OnboardingManager;
use Nimbus\Plugin\Storage\StorageAdapterFactory;
use Nimbus\Plugin\Support\Events;
use Nimbus\Plugin\Support\Logger;

use function Nimbus\Plugin\Support\pushAdminNotice;

if (!defined('ABSPATH')) {
  exit;
}

final class ActionsController
{
  private const MISSING_SOURCE_AT_META_KEY = NIMBUS_MISSING_SOURCE_AT_META_KEY;

  public function __construct(
    private Settings $settings,
    private Logger $logger,
    private UploadHandler $uploadHandler,
    private OnboardingManager $onboardingManager,
  ) {
  }

  public function handleTestConnection(): void
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Nonce verified via verifyAdminNonce()/isValidNonce() helper above; sniff cannot trace across the method call.
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['nonce'])) : '';

    if (!$this->isValidNonce($nonce, NIMBUS_AJAX_TEST_CONNECTION)) {
      wp_send_json_error(['message' => __('Security check failed.', 'nimbus')], 403);
    }

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', 'nimbus')], 403);
    }

    $factory = new StorageAdapterFactory($this->settings, $this->logger);

    try {
      $adapter = $factory->getAdapter();
      if (method_exists($adapter, 'testConnection')) {
        if ($adapter->testConnection()) {
          wp_send_json_success([
            'message' => __('Connection successful! Write access verified.', 'nimbus'),
            'details' => $this->connectionDetails(),
            'hints' => $this->connectionHints(),
          ]);
        }

        wp_send_json_error([
          'message' => __('Connection failed. Check logs for details.', 'nimbus'),
          'details' => $this->connectionDetails(),
          'hints' => $this->connectionHints(),
        ]);
      }

      wp_send_json_error([
        'message' => __('Adapter does not support connection testing.', 'nimbus'),
        'details' => $this->connectionDetails(),
      ]);
    } catch (\Throwable $exception) {
      wp_send_json_error([
        /* translators: %s: error message from the storage provider */
        'message' => sprintf(__('Connection failed: %s', 'nimbus'), $exception->getMessage()),
        'details' => $this->connectionDetails(),
        'hints' => $this->connectionHints($exception),
      ]);
    }
  }

  public function handleClearLogs(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_CLEAR_LOGS);
    $this->logger->clear_entries();

    wp_safe_redirect(admin_url('upload.php?page=' . NIMBUS_SLUG . '&tab=logs&nimbus_logs_cleared=1'));
    exit;
  }

  public function handleResumeMigration(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_RESUME_MIGRATION);

    $before = DataMigration::status();
    $after = DataMigration::resume();

    if (empty($before['needs_migration'])) {
      pushAdminNotice(__('No unfinished activation recovery tasks were found.', 'nimbus'), 'info');
      $this->redirectToTab('overview');
    }

    if (empty($after['needs_migration'])) {
      pushAdminNotice(__('Activation recovery completed successfully.', 'nimbus'), 'success');
      $this->redirectToTab('overview');
    }

    if (!empty($after['scheduled'])) {
      pushAdminNotice(__('A recovery batch finished and more activation cleanup has been scheduled. You can click Resume again or let WordPress cron continue in the background.', 'nimbus'), 'warning');
      $this->redirectToTab('overview');
    }

    pushAdminNotice(__('A recovery batch ran, but more activation cleanup still appears to be pending. Click Resume again to continue.', 'nimbus'), 'warning');
    $this->redirectToTab('overview');
  }

  public function handleRunUrlUpdates(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES);

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Nonce verified via verifyAdminNonce()/isValidNonce() helper above; sniff cannot trace across the method call.
    $mode = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : 'single';
    if (!in_array($mode, ['single', 'full'], true)) {
      $mode = 'single';
    }

    if (!$this->settings->is_enabled()) {
      $this->redirectToUrlUpdates([
        'ptmo_url_update' => 'error',
        'ptmo_url_update_reason' => 'disabled',
      ]);
    }

    if (!$this->settings->has_valid_credentials()) {
      $this->redirectToUrlUpdates([
        'ptmo_url_update' => 'error',
        'ptmo_url_update_reason' => 'credentials',
      ]);
    }

    if (!$this->canConnectStorage()) {
      $this->redirectToUrlUpdates([
        'ptmo_url_update' => 'error',
        'ptmo_url_update_reason' => 'connection',
      ]);
    }

    $pendingBefore = $this->countPendingUrlUpdates();
    if ($pendingBefore <= 0) {
      $this->redirectToUrlUpdates([
        'ptmo_url_update' => '1',
        'ptmo_url_update_mode' => $mode,
        'ptmo_url_update_status' => 'info',
        'ptmo_url_update_processed' => 0,
        'ptmo_url_update_success' => 0,
        'ptmo_url_update_failed' => 0,
        'ptmo_url_update_remaining' => 0,
      ]);
    }

    $context = [
      'source' => 'admin',
      'scope' => 'attachment_sync',
      'operation' => 'url_updates',
      'mode' => $mode,
      'auto_purge' => false,
      'pending_before' => $pendingBefore,
    ];

    Events::dispatch(Events::REWRITE_STARTED, $context);

    try {
      $result = $this->runUrlUpdates($mode === 'single' ? 1 : 0);
      $remaining = $this->countPendingUrlUpdates();
      $status = $result['failed'] > 0 ? 'warning' : 'success';
    } catch (\Throwable $exception) {
      Events::dispatch(Events::REWRITE_FAILED, $context + [
        'error' => $exception->getMessage(),
      ]);
      throw $exception;
    }

    Events::dispatch(Events::REWRITE_COMPLETED, $context + $result + [
      'remaining' => $remaining,
      'status' => $status,
    ]);

    $this->redirectToUrlUpdates([
      'ptmo_url_update' => '1',
      'ptmo_url_update_mode' => $mode,
      'ptmo_url_update_status' => $status,
      'ptmo_url_update_processed' => $result['processed'],
      'ptmo_url_update_success' => $result['success'],
      'ptmo_url_update_failed' => $result['failed'],
      'ptmo_url_update_remaining' => $remaining,
    ]);
  }

  public function handleCompleteOnboarding(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING);

    // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Nonce verified via verifyAdminNonce()/isValidNonce() helper above; sniff cannot trace across the method call.
    $provider = isset($_POST['provider']) ? sanitize_key((string) wp_unslash($_POST['provider'])) : $this->settings->provider();
    $conflictAcknowledged = !empty($_POST['conflict_acknowledged']);
    $values = [
      'enabled' => !empty($_POST['enabled']),
      'serve_from_storage' => !empty($_POST['serve_from_storage']),
      'provider' => $provider,
      'bucket' => isset($_POST['bucket']) ? sanitize_text_field((string) wp_unslash($_POST['bucket'])) : '',
      'r2_account_id' => isset($_POST['r2_account_id']) ? sanitize_text_field((string) wp_unslash($_POST['r2_account_id'])) : '',
      'region' => isset($_POST['region']) ? sanitize_text_field((string) wp_unslash($_POST['region'])) : '',
      'access_key' => isset($_POST['access_key']) ? sanitize_text_field((string) wp_unslash($_POST['access_key'])) : '',
      'secret_key' => isset($_POST['secret_key']) ? sanitize_text_field((string) wp_unslash($_POST['secret_key'])) : '',
      'session_token' => isset($_POST['session_token']) ? sanitize_text_field((string) wp_unslash($_POST['session_token'])) : '',
      'prefix' => isset($_POST['prefix']) ? sanitize_text_field((string) wp_unslash($_POST['prefix'])) : '',
      'endpoint' => isset($_POST['endpoint']) ? esc_url_raw((string) wp_unslash($_POST['endpoint'])) : '',
      'custom_domain' => isset($_POST['custom_domain']) ? sanitize_text_field((string) wp_unslash($_POST['custom_domain'])) : '',
      'use_path_style_endpoint' => !empty($_POST['use_path_style_endpoint']),
    ];
    // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

    $normalized = $this->settings->sanitize(array_merge($this->settings->all(), $values));
    $missing = $provider !== 'local' ? $this->settings->missing_required_credentials($normalized, $provider) : [];

    if ($provider !== 'local' && !empty($missing)) {
      /* translators: %s: comma-separated list of missing required setting names */
      pushAdminNotice(sprintf(__('Please complete the storage step before continuing: %s', 'nimbus'), implode(', ', $missing)), 'error');
      $this->redirectToTab('onboarding', ['onboarding_step' => 'storage']);
    }

    $activeConflicts = array_filter($this->onboardingManager->state()['conflicts'] ?? [], static fn (array $plugin): bool => !empty($plugin['active']));
    if (!empty($activeConflicts) && !$conflictAcknowledged) {
      pushAdminNotice(__('Please review the active plugin conflicts and confirm you understand them before finishing setup.', 'nimbus'), 'error');
      $this->redirectToTab('onboarding', ['onboarding_step' => 'review']);
    }

    $this->settings->update($normalized);
    $this->onboardingManager->markCompleted();

    if (!empty($activeConflicts)) {
      pushAdminNotice(__('Setup complete, but one or more other media offload plugins are still active. Review the integration warnings in Tools before enabling aggressive URL rewriting.', 'nimbus'), 'warning');
    } else {
      pushAdminNotice(__('Setup complete. Your dashboard is now unlocked and your storage preferences have been saved.', 'nimbus'), 'success');
    }

    $this->redirectToTab('overview');
  }

  public function handleSaveOnboardingStep(): void
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Permission denied.'], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, NIMBUS_AJAX_ONBOARDING_SAVE_STEP)) {
      wp_send_json_error(['message' => 'Invalid nonce.'], 403);
    }

    $allowed = ['account', 'storage', 'delivery', 'review'];
    $step = isset($_POST['step']) ? sanitize_key((string) wp_unslash($_POST['step'])) : '';

    if (!in_array($step, $allowed, true)) {
      wp_send_json_error(['message' => 'Invalid step.'], 400);
    }

    $this->onboardingManager->saveStep($step);
    wp_send_json_success();
  }

  public function handleRewriteIntegration(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION);

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Nonce verified via verifyAdminNonce()/isValidNonce() helper above; sniff cannot trace across the method call.
    $integration = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';
    if ($integration === '' || !(bool) apply_filters('nimbus/integrations/can_rewrite', false, $integration)) {
      pushAdminNotice(__('This integration does not yet support automated URL rewriting.', 'nimbus'), 'warning');
      $this->redirectToTab('integrations');
    }

    if (!$this->settings->has_valid_credentials()) {
      pushAdminNotice(__('Configure storage credentials before rewriting external asset URLs.', 'nimbus'), 'error');
      $this->redirectToTab('integrations');
    }

    $definition = (array) apply_filters('nimbus/integrations/definition', null, $integration);
    $label = (string) ($definition['label'] ?? ucwords(str_replace('_', ' ', $integration)));
    $result = (array) apply_filters('nimbus/integrations/run_rewrite', [], $integration);
    if (($result['errors'] ?? 0) > 0) {
      /* translators: 1: integration name, 2: table count, 3: updated row count, 4: error count */
      pushAdminNotice(sprintf(__('%1$s rewrite finished with warnings. Tables: %2$d, Updated rows: %3$d, Errors: %4$d.', 'nimbus'), $label, (int) ($result['tables'] ?? 0), (int) ($result['updated_rows'] ?? 0), (int) ($result['errors'] ?? 0)), 'warning');
    } else {
      /* translators: 1: integration name, 2: table count, 3: updated row count */
      pushAdminNotice(sprintf(__('%1$s rewrite complete. Tables: %2$d, Updated rows: %3$d.', 'nimbus'), $label, (int) ($result['tables'] ?? 0), (int) ($result['updated_rows'] ?? 0)), 'success');
    }

    $this->redirectToTab('integrations');
  }

  public function handleToggleIntegration(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION);

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Nonce verified via verifyAdminNonce()/isValidNonce() helper above; sniff cannot trace across the method call.
    $key = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';
    if ($key === '') {
      $this->redirectToTab('integrations');
      return;
    }

    $disabled = (array) get_option(NIMBUS_DISABLED_INTEGRATIONS_KEY, []);
    $disabled = array_map('strval', $disabled);

    if (in_array($key, $disabled, true)) {
      $disabled = array_values(array_filter($disabled, static fn ($k) => $k !== $key));
      /* translators: %s: integration name */
      pushAdminNotice(sprintf(__('Integration "%s" enabled.', 'nimbus'), $key), 'success');
    } else {
      $disabled[] = $key;
      /* translators: %s: integration name */
      pushAdminNotice(sprintf(__('Integration "%s" disabled.', 'nimbus'), $key), 'success');
    }

    update_option(NIMBUS_DISABLED_INTEGRATIONS_KEY, $disabled, false);
    $this->redirectToTab('integrations');
  }

  private function runUrlUpdates(int $limit = 0): array
  {
    $batchSize = max(1, (int) apply_filters('nimbus_admin_url_update_batch_size', 100));
    if ($limit === 1) {
      $batchSize = 1;
    }

    $processed = 0;
    $success = 0;
    $failed = 0;
    $remaining = $limit > 0 ? $limit : PHP_INT_MAX;
    $lastId = 0;

    while ($remaining > 0) {
      $take = $limit > 0 ? min($batchSize, $remaining) : $batchSize;
      $ids = $this->fetchPendingUrlUpdateIds($take, $lastId);
      if (empty($ids)) {
        break;
      }

      foreach ($ids as $attachmentId) {
        $processed++;
        if ($this->uploadHandler->offload_via_cli((int) $attachmentId)) {
          $success++;
        } else {
          $failed++;
        }
      }

      $lastId = (int) end($ids);
      if ($limit > 0) {
        $remaining -= count($ids);
      }

      if (count($ids) < $take) {
        break;
      }
    }

    return [
      'processed' => $processed,
      'success' => $success,
      'failed' => $failed,
    ];
  }

  private function countPendingUrlUpdates(): int
  {
    $count = 0;
    $afterId = 0;
    $batchSize = 500;

    while (true) {
      $candidateIds = $this->fetchCandidatePendingUrlUpdateIds($batchSize, $afterId);
      if (empty($candidateIds)) {
        break;
      }

      $count += count($this->uploadHandler->filter_eligible_attachment_ids($candidateIds));
      $afterId = (int) end($candidateIds);

      if (count($candidateIds) < $batchSize) {
        break;
      }
    }

    return $count;
  }

  private function fetchPendingUrlUpdateIds(int $limit, int $afterId = 0): array
  {
    $limit = max(1, $limit);
    $afterId = max(0, $afterId);
    $eligible = [];
    $cursor = $afterId;
    $candidateBatchSize = max(50, $limit * 4);

    while (count($eligible) < $limit) {
      $candidateIds = $this->fetchCandidatePendingUrlUpdateIds($candidateBatchSize, $cursor);
      if (empty($candidateIds)) {
        break;
      }

      foreach ($this->uploadHandler->filter_eligible_attachment_ids($candidateIds) as $attachmentId) {
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

  private function fetchCandidatePendingUrlUpdateIds(int $limit, int $afterId = 0): array
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return [];
    }

    $limit = max(1, $limit);
    $afterId = max(0, $afterId);
    $missingSourceRetryBefore = $this->missingSourceRetryBeforeTimestamp();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Candidate list for a one-shot admin bulk action; must be a live read of current attachment state, not cached.
    $ids = $wpdb->get_col($wpdb->prepare(
      "SELECT p.ID
       FROM {$wpdb->posts} p
       LEFT JOIN {$wpdb->postmeta} pm
         ON pm.post_id = p.ID
        AND pm.meta_key = %s
       LEFT JOIN {$wpdb->postmeta} msm
         ON msm.post_id = p.ID
        AND msm.meta_key = %s
       LEFT JOIN {$wpdb->postmeta} im
         ON im.post_id = p.ID
        AND im.meta_key = %s
       WHERE p.post_type = %s
         AND p.post_status = %s
         AND p.ID > %d
         AND pm.meta_id IS NULL
         AND (im.meta_id IS NULL OR im.meta_value != '1')
         AND (msm.meta_id IS NULL OR CAST(msm.meta_value AS UNSIGNED) < %d)
       ORDER BY p.ID ASC
       LIMIT %d",
      NIMBUS_META_KEY,
      self::MISSING_SOURCE_AT_META_KEY,
      NIMBUS_IGNORE_META_KEY,
      'attachment',
      'inherit',
      $afterId,
      $missingSourceRetryBefore,
      $limit
    ));

    if (!is_array($ids)) {
      return [];
    }

    return array_map('intval', $ids);
  }

  private function redirectToUrlUpdates(array $queryArgs = []): void
  {
    $url = add_query_arg($queryArgs, admin_url('upload.php?page=' . NIMBUS_SLUG . '&tab=tools'));
    wp_safe_redirect($url);
    exit;
  }

  private function redirectToTab(string $tab, array $queryArgs = []): void
  {
    $url = add_query_arg(array_merge([
      'page' => NIMBUS_SLUG,
      'tab' => $tab,
    ], $queryArgs), admin_url('upload.php'));

    wp_safe_redirect($url);
    exit;
  }

  private function isValidNonce(string $nonce, string ...$actions): bool
  {
    foreach ($actions as $action) {
      if ($nonce !== '' && wp_verify_nonce($nonce, $action)) {
        return true;
      }
    }

    return false;
  }

  private function verifyAdminNonce(string ...$actions): void
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- This read is itself part of the nonce-verification helper; wp_verify_nonce() is called immediately below via isValidNonce().
    $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['_wpnonce'])) : '';

    if (!$this->isValidNonce($nonce, ...$actions)) {
      wp_die(esc_html__('Security check failed.', 'nimbus'));
    }
  }

  private function connectionDetails(): array
  {
    $config = $this->settings->adapter_configuration();
    $details = [
      [
        'label' => __('Provider', 'nimbus'),
        'value' => $this->settings->provider_profile()->label(),
      ],
      [
        'label' => __('Bucket', 'nimbus'),
        'value' => $config['bucket'] !== '' ? $config['bucket'] : __('Not set', 'nimbus'),
      ],
    ];

    if (!empty($config['region'])) {
      $details[] = [
        'label' => __('Region', 'nimbus'),
        'value' => (string) $config['region'],
      ];
    }

    if (!empty($config['endpoint'])) {
      $details[] = [
        'label' => __('Endpoint', 'nimbus'),
        'value' => (string) $config['endpoint'],
      ];
    }

    $details[] = [
      'label' => __('Path style', 'nimbus'),
      'value' => !empty($config['use_path_style_endpoint']) ? __('Enabled', 'nimbus') : __('Disabled', 'nimbus'),
    ];

    return $details;
  }

  private function connectionHints(?\Throwable $exception = null): array
  {
    $provider = $this->settings->provider();
    $hints = [];

    if ($provider === 'r2') {
      $hints[] = __('For Cloudflare R2, keep region set to auto and use the account endpoint without appending the bucket name.', 'nimbus');
      $hints[] = __('R2 typically requires path-style requests and API-token-derived access keys.', 'nimbus');
    } elseif ($provider === 's3_compatible') {
      $hints[] = __('For S3-compatible storage, confirm the custom endpoint is correct and includes the protocol.', 'nimbus');
      $hints[] = __('If requests are signed incorrectly, try enabling path-style URLs when your provider recommends it.', 'nimbus');
    } else {
      $hints[] = __('For Amazon S3, confirm the region exactly matches the bucket region and that the IAM credentials allow list, read, and write access.', 'nimbus');
    }

    if ($exception !== null && stripos($exception->getMessage(), 'signature') !== false) {
      $hints[] = __('A signature error usually means the endpoint, region, or path-style setting does not match the provider requirements.', 'nimbus');
    }

    return array_values(array_unique($hints));
  }

  private function canConnectStorage(): bool
  {
    try {
      $factory = new StorageAdapterFactory($this->settings, $this->logger);
      $adapter = $factory->getAdapter();

      if (method_exists($adapter, 'testConnection')) {
        return (bool) $adapter->testConnection();
      }

      return true;
    } catch (\Throwable $exception) {
      $this->logger->warning('Admin URL update blocked by failed storage connection test: {error}', [
        'error' => $exception->getMessage(),
      ]);
      return false;
    }
  }

  private function missingSourceRetryBeforeTimestamp(): int
  {
    $cooldown = max(300, (int) apply_filters('nimbus_missing_source_retry_cooldown_seconds', 12 * HOUR_IN_SECONDS));
    return time() - $cooldown;
  }

  // ── Config export ──────────────────────────────────────────────────────────

  /**
   * Keys included in a config export. Sensitive credential keys are included
   * but the user is warned. Internal-only keys (logging, batch tuning) are
   * deliberately excluded to keep the portable config concise.
   */
  private const EXPORT_KEYS = [
    'provider', 'bucket', 'r2_account_id', 'region', 'access_key', 'secret_key',
    'session_token', 'prefix', 'endpoint', 'use_path_style_endpoint',
    'remove_local_copies', 'serve_from_storage', 'custom_domain',
    'purge_cloudflare_cache_on_delete', 'purge_litespeed_cache',
    'purge_wp_fastest_cache', 'purge_proxy_cache',
    'cloudflare_zone_id', 'cloudflare_api_token',
    'proxy_cache_purge_method', 'proxy_cache_purge_urls', 'proxy_cache_purge_all_urls',
    'allowed_path_patterns', 'ignored_path_patterns',
    'offload_min_size_kb', 'offload_allowed_mime_types',
    'storage_class', 'visibility', 'delete_remote_on_wp_delete',
    'enabled',
  ];

  /**
   * Keys that carry credential data. The export file notes these are present
   * so importers can decide whether to trust or strip them.
   */
  private const CREDENTIAL_KEYS = ['access_key', 'secret_key', 'session_token', 'cloudflare_api_token', 'r2_account_id'];

  public function handleExportConfig(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_EXPORT_CONFIG);

    $all = $this->settings->all();
    $export = [];
    foreach (self::EXPORT_KEYS as $key) {
      if (array_key_exists($key, $all)) {
        $export[$key] = $all[$key];
      }
    }

    $hasCredentials = array_reduce(
      self::CREDENTIAL_KEYS,
      static fn (bool $carry, string $key): bool => $carry || ($export[$key] ?? '') !== '',
      false
    );

    $payload = [
      'version'         => '1.0',
      'plugin'          => 'nimbus',
      'exported_at'     => gmdate('c'),
      'has_credentials' => $hasCredentials,
      'settings'        => $export,
    ];

    $filename = 'nimbus-config-' . gmdate('Ymd-His') . '.json';
    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
      wp_die(esc_html__('Failed to encode configuration.', 'nimbus'));
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download
    exit;
  }

  public function handleImportConfig(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_IMPORT_CONFIG);

    // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Nonce verified via verifyAdminNonce()/isValidNonce() helper above; sniff cannot trace across the method call.
    $redirectTab = isset($_POST['redirect_tab']) && $_POST['redirect_tab'] === 'settings' ? 'settings' : 'tools';

    if (empty($_FILES['nimbus_config_file']['tmp_name'])) {
      pushAdminNotice(__('No file was uploaded. Please choose a JSON configuration file.', 'nimbus'), 'error');
      $this->redirectToTab($redirectTab);
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only ['name'] (extension checked against an allow-list below) and ['tmp_name'] (a PHP-generated path, not user-controlled) are used; content is size-capped and JSON-decoded before use.
    $file = $_FILES['nimbus_config_file'];
    // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

    // Validate file type by extension and MIME.
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'json') {
      pushAdminNotice(__('Invalid file type. Only .json files are accepted.', 'nimbus'), 'error');
      $this->redirectToTab($redirectTab);
    }

    $raw = file_get_contents((string) $file['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    if ($raw === false || strlen($raw) > 512 * 1024) {
      pushAdminNotice(__('Could not read the uploaded file or file exceeds the 512 KB limit.', 'nimbus'), 'error');
      $this->redirectToTab($redirectTab);
    }

    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
      pushAdminNotice(__('Invalid JSON: could not parse the configuration file.', 'nimbus'), 'error');
      $this->redirectToTab($redirectTab);
    }

    if (($payload['plugin'] ?? '') !== 'nimbus') {
      pushAdminNotice(__('This file does not appear to be a Nimbus configuration export.', 'nimbus'), 'error');
      $this->redirectToTab($redirectTab);
    }

    $incoming = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];

    // Filter to allowed keys only — never trust arbitrary keys from the file.
    $filtered = array_intersect_key($incoming, array_flip(self::EXPORT_KEYS));

    // Sanitize via the existing settings sanitizer.
    $normalized = $this->settings->sanitize(array_merge($this->settings->all(), $filtered));

    $this->settings->update($normalized);

    pushAdminNotice(__('Configuration imported successfully. Review the settings below and save if needed.', 'nimbus'), 'success');
    $this->redirectToTab($redirectTab);
  }
}

