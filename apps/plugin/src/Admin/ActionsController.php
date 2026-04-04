<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Bootstrap\DataMigration;
use Nimbus\Plugin\Integration\IntegrationDiscovery;
use Nimbus\Plugin\License\LicenseClient;
use Nimbus\Plugin\License\LicenseManager;
use Nimbus\Plugin\Onboarding\OnboardingManager;
use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Media\UploadHandler;
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
    private LicenseManager $licenseManager,
    private OnboardingManager $onboardingManager,
    private IntegrationDiscovery $integrationDiscovery,
  ) {
  }

  public function handleTestConnection(): void
  {
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['nonce'])) : '';

    if (!$this->isValidNonce($nonce, NIMBUS_AJAX_TEST_CONNECTION)) {
      wp_send_json_error(['message' => __('Security check failed.', NIMBUS_TEXT_DOMAIN)], 403);
    }

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Permission denied.', NIMBUS_TEXT_DOMAIN)], 403);
    }

    $factory = new StorageAdapterFactory($this->settings, $this->logger);

    try {
      $adapter = $factory->getAdapter();
      if (method_exists($adapter, 'testConnection')) {
        if ($adapter->testConnection()) {
          wp_send_json_success([
            'message' => __('Connection successful! Write access verified.', NIMBUS_TEXT_DOMAIN),
            'details' => $this->connectionDetails(),
            'hints' => $this->connectionHints(),
          ]);
        }

        wp_send_json_error([
          'message' => __('Connection failed. Check logs for details.', NIMBUS_TEXT_DOMAIN),
          'details' => $this->connectionDetails(),
          'hints' => $this->connectionHints(),
        ]);
      }

      wp_send_json_error([
        'message' => __('Adapter does not support connection testing.', NIMBUS_TEXT_DOMAIN),
        'details' => $this->connectionDetails(),
      ]);
    } catch (\Throwable $exception) {
      wp_send_json_error([
        'message' => sprintf(__('Connection failed: %s', NIMBUS_TEXT_DOMAIN), $exception->getMessage()),
        'details' => $this->connectionDetails(),
        'hints' => $this->connectionHints($exception),
      ]);
    }
  }

  public function handleClearLogs(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_CLEAR_LOGS);
    $this->logger->clear_entries();

    wp_safe_redirect(admin_url('upload.php?page=' . NIMBUS_SLUG . '&tab=logs&nimbus_logs_cleared=1'));
    exit;
  }

  public function handleResumeMigration(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_RESUME_MIGRATION);

    $before = DataMigration::status();
    $after = DataMigration::resume();

    if (empty($before['needs_migration'])) {
      pushAdminNotice(__('No unfinished activation recovery tasks were found.', NIMBUS_TEXT_DOMAIN), 'info');
      $this->redirectToTab('overview');
    }

    if (empty($after['needs_migration'])) {
      pushAdminNotice(__('Activation recovery completed successfully.', NIMBUS_TEXT_DOMAIN), 'success');
      $this->redirectToTab('overview');
    }

    if (!empty($after['scheduled'])) {
      pushAdminNotice(__('A recovery batch finished and more activation cleanup has been scheduled. You can click Resume again or let WordPress cron continue in the background.', NIMBUS_TEXT_DOMAIN), 'warning');
      $this->redirectToTab('overview');
    }

    pushAdminNotice(__('A recovery batch ran, but more activation cleanup still appears to be pending. Click Resume again to continue.', NIMBUS_TEXT_DOMAIN), 'warning');
    $this->redirectToTab('overview');
  }

  public function handleRunUrlUpdates(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES);

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
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING);

    $provider = isset($_POST['provider']) ? sanitize_key((string) wp_unslash($_POST['provider'])) : $this->settings->provider();
    $licenseKey = isset($_POST['license_key']) ? sanitize_text_field((string) wp_unslash($_POST['license_key'])) : '';
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

    $normalized = $this->settings->sanitize(array_merge($this->settings->all(), $values));
    $missing = $provider !== 'local' ? $this->settings->missing_required_credentials($normalized, $provider) : [];

    if ($licenseKey === '') {
      pushAdminNotice(__('Add your license key before finishing onboarding.', NIMBUS_TEXT_DOMAIN), 'error');
      $this->redirectToTab('onboarding', ['onboarding_step' => 'account']);
    }

    if ($provider !== 'local' && !empty($missing)) {
      pushAdminNotice(sprintf(__('Please complete the storage step before continuing: %s', NIMBUS_TEXT_DOMAIN), implode(', ', $missing)), 'error');
      $this->redirectToTab('onboarding', ['onboarding_step' => 'storage']);
    }

    $activeConflicts = array_filter($this->onboardingManager->state()['conflicts'] ?? [], static fn (array $plugin): bool => !empty($plugin['active']));
    if (!empty($activeConflicts) && !$conflictAcknowledged) {
      pushAdminNotice(__('Please review the active plugin conflicts and confirm you understand them before finishing setup.', NIMBUS_TEXT_DOMAIN), 'error');
      $this->redirectToTab('onboarding', ['onboarding_step' => 'review']);
    }

    $this->settings->update($normalized);
    $this->licenseManager->update($licenseKey, [
      'status' => $licenseKey !== '' ? 'active-pending-validation' : 'inactive',
      'plan' => 'monthly',
      'last_validated_at' => 0,
    ]);
    $this->onboardingManager->markCompleted();

    if (!empty($activeConflicts)) {
      pushAdminNotice(__('Setup complete, but one or more other media offload plugins are still active. Review the integration warnings in Tools before enabling aggressive URL rewriting.', NIMBUS_TEXT_DOMAIN), 'warning');
    } else {
      pushAdminNotice(__('Setup complete. Your dashboard is now unlocked and your storage preferences have been saved.', NIMBUS_TEXT_DOMAIN), 'success');
    }

    $this->redirectToTab('overview');
  }

  public function handleRewriteIntegration(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION);

    $integration = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';
    if ($integration === '' || !$this->integrationDiscovery->canRewrite($integration)) {
      pushAdminNotice(__('This integration does not yet support automated URL rewriting.', NIMBUS_TEXT_DOMAIN), 'warning');
      $this->redirectToTab('integrations');
    }

    if (!$this->settings->has_valid_credentials()) {
      pushAdminNotice(__('Configure storage credentials before rewriting external asset URLs.', NIMBUS_TEXT_DOMAIN), 'error');
      $this->redirectToTab('integrations');
    }

    $definition = $this->integrationDiscovery->definition($integration);
    $label = (string) ($definition['label'] ?? ucwords(str_replace('_', ' ', $integration)));
    $result = $this->integrationDiscovery->runRewrite($integration);
    if (($result['errors'] ?? 0) > 0) {
      pushAdminNotice(sprintf(__('%1$s rewrite finished with warnings. Tables: %2$d, Updated rows: %3$d, Errors: %4$d.', NIMBUS_TEXT_DOMAIN), $label, (int) ($result['tables'] ?? 0), (int) ($result['updated_rows'] ?? 0), (int) ($result['errors'] ?? 0)), 'warning');
    } else {
      pushAdminNotice(sprintf(__('%1$s rewrite complete. Tables: %2$d, Updated rows: %3$d.', NIMBUS_TEXT_DOMAIN), $label, (int) ($result['tables'] ?? 0), (int) ($result['updated_rows'] ?? 0)), 'success');
    }

    $this->redirectToTab('integrations');
  }

  public function handleToggleIntegration(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN));
    }

    $this->verifyAdminNonce(NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION);

    $key = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';
    if ($key === '') {
      $this->redirectToTab('integrations');
      return;
    }

    $disabled = (array) get_option(NIMBUS_DISABLED_INTEGRATIONS_KEY, []);
    $disabled = array_map('strval', $disabled);

    if (in_array($key, $disabled, true)) {
      $disabled = array_values(array_filter($disabled, static fn ($k) => $k !== $key));
      pushAdminNotice(sprintf(__('Integration "%s" enabled.', NIMBUS_TEXT_DOMAIN), $key), 'success');
    } else {
      $disabled[] = $key;
      pushAdminNotice(sprintf(__('Integration "%s" disabled.', NIMBUS_TEXT_DOMAIN), $key), 'success');
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
    $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['_wpnonce'])) : '';

    if (!$this->isValidNonce($nonce, ...$actions)) {
      wp_die(esc_html__('Security check failed.', NIMBUS_TEXT_DOMAIN));
    }
  }

  private function connectionDetails(): array
  {
    $config = $this->settings->adapter_configuration();
    $details = [
      [
        'label' => __('Provider', NIMBUS_TEXT_DOMAIN),
        'value' => $this->settings->provider_profile()->label(),
      ],
      [
        'label' => __('Bucket', NIMBUS_TEXT_DOMAIN),
        'value' => $config['bucket'] !== '' ? $config['bucket'] : __('Not set', NIMBUS_TEXT_DOMAIN),
      ],
    ];

    if (!empty($config['region'])) {
      $details[] = [
        'label' => __('Region', NIMBUS_TEXT_DOMAIN),
        'value' => (string) $config['region'],
      ];
    }

    if (!empty($config['endpoint'])) {
      $details[] = [
        'label' => __('Endpoint', NIMBUS_TEXT_DOMAIN),
        'value' => (string) $config['endpoint'],
      ];
    }

    $details[] = [
      'label' => __('Path style', NIMBUS_TEXT_DOMAIN),
      'value' => !empty($config['use_path_style_endpoint']) ? __('Enabled', NIMBUS_TEXT_DOMAIN) : __('Disabled', NIMBUS_TEXT_DOMAIN),
    ];

    return $details;
  }

  private function connectionHints(?\Throwable $exception = null): array
  {
    $provider = $this->settings->provider();
    $hints = [];

    if ($provider === 'r2') {
      $hints[] = __('For Cloudflare R2, keep region set to auto and use the account endpoint without appending the bucket name.', NIMBUS_TEXT_DOMAIN);
      $hints[] = __('R2 typically requires path-style requests and API-token-derived access keys.', NIMBUS_TEXT_DOMAIN);
    } elseif ($provider === 's3_compatible') {
      $hints[] = __('For S3-compatible storage, confirm the custom endpoint is correct and includes the protocol.', NIMBUS_TEXT_DOMAIN);
      $hints[] = __('If requests are signed incorrectly, try enabling path-style URLs when your provider recommends it.', NIMBUS_TEXT_DOMAIN);
    } else {
      $hints[] = __('For Amazon S3, confirm the region exactly matches the bucket region and that the IAM credentials allow list, read, and write access.', NIMBUS_TEXT_DOMAIN);
    }

    if ($exception !== null && stripos($exception->getMessage(), 'signature') !== false) {
      $hints[] = __('A signature error usually means the endpoint, region, or path-style setting does not match the provider requirements.', NIMBUS_TEXT_DOMAIN);
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

  // ── License management ─────────────────────────────────────────────────────

  /**
   * Handle admin-post activation of a license key.
   *
   * 1. Verifies nonce + permissions.
   * 2. Calls the backend /v1/licenses/activate for the current domain.
   * 3. On success, persists token + metadata in WordPress options.
   * 4. On max_activations_reached, redirects back with an error notice;
   *    the License tab will show the active-sites list so the user can
   *    deactivate one and retry.
   */
  public function handleLicenseActivate(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN), 403);
    }

    $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
    if (!wp_verify_nonce($nonce, NIMBUS_ADMIN_ACTION_LICENSE_ACTIVATE)) {
      wp_die(esc_html__('Security check failed.', NIMBUS_TEXT_DOMAIN), 403);
    }

    $key     = isset($_POST['license_key']) ? sanitize_text_field((string) wp_unslash($_POST['license_key'])) : '';
    $key     = trim($key);
    $tabUrl  = add_query_arg(['page' => NIMBUS_SLUG, 'tab' => 'license'], admin_url('admin.php'));

    if ($key === '') {
      pushAdminNotice(__('Please enter a license key.', NIMBUS_TEXT_DOMAIN), 'error');
      wp_safe_redirect($tabUrl);
      exit;
    }

    $domain  = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';
    $product = 'nimbus'; // must match the product slug in the backend DB

    $client = new LicenseClient();
    $result = $client->activate($key, $domain, $product);

    if (is_wp_error($result)) {
      $code = $result->get_error_code();

      if ($code === 'max_activations_reached') {
        // Store the key as "pending" so the License tab can show the sites list.
        $this->licenseManager->update($key, ['status' => 'pending']);
        pushAdminNotice(
          __('This license has reached its activation limit. Visit the License tab to deactivate an existing site and re-activate here.', NIMBUS_TEXT_DOMAIN),
          'warning'
        );
      } else {
        pushAdminNotice(
          sprintf(
            /* translators: %s: error message */
            __('License activation failed: %s', NIMBUS_TEXT_DOMAIN),
            $result->get_error_message()
          ),
          'error'
        );
      }

      wp_safe_redirect($tabUrl);
      exit;
    }

    // Persist the issued token + metadata.
    $this->licenseManager->update($key, [
      'status'           => 'active',
      'tier'             => sanitize_key((string) ($result['tier'] ?? '')),
      'token'            => sanitize_text_field((string) ($result['token'] ?? '')),
      'token_expires_at' => strtotime((string) ($result['expires_at'] ?? '')) ?: 0,
      'last_validated_at' => time(),
    ]);

    pushAdminNotice(__('License activated successfully.', NIMBUS_TEXT_DOMAIN), 'success');
    wp_safe_redirect($tabUrl);
    exit;
  }

  /**
   * Register a free-tier license directly from within the plugin.
   *
   * No license key is needed from the user. The handler uses the site's admin
   * email and home URL domain to call the public backend registration endpoint,
   * which is idempotent — repeated calls return the same free license.
   */
  public function handleLicenseActivateFree(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN), 403);
    }

    $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
    if (!wp_verify_nonce($nonce, NIMBUS_ADMIN_ACTION_LICENSE_ACTIVATE_FREE)) {
      wp_die(esc_html__('Security check failed.', NIMBUS_TEXT_DOMAIN), 403);
    }

    $tabUrl = add_query_arg(['page' => NIMBUS_SLUG, 'tab' => 'license'], admin_url('admin.php'));
    $email  = (string) get_option('admin_email', '');
    $domain = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';

    if ($email === '' || $domain === '') {
      pushAdminNotice(__('Could not determine site email or domain. Please enter a license key manually.', NIMBUS_TEXT_DOMAIN), 'error');
      wp_safe_redirect($tabUrl);
      exit;
    }

    $client = new LicenseClient();
    $result = $client->registerFree($email, $domain);

    if (is_wp_error($result)) {
      pushAdminNotice(
        sprintf(
          /* translators: %s: error message */
          __('Free tier registration failed: %s', NIMBUS_TEXT_DOMAIN),
          $result->get_error_message()
        ),
        'error'
      );
      wp_safe_redirect($tabUrl);
      exit;
    }

    $key = sanitize_text_field((string) ($result['key'] ?? ''));

    $this->licenseManager->update($key, [
      'status'            => 'active',
      'tier'              => 'free',
      'token'             => sanitize_text_field((string) ($result['token'] ?? '')),
      'token_expires_at'  => 0,   // free tier tokens don't expire
      'last_validated_at' => time(),
    ]);

    pushAdminNotice(__('Free tier activated. You can upgrade to a paid plan at any time.', NIMBUS_TEXT_DOMAIN), 'success');

    // If coming from the onboarding wizard, advance to the storage step.
    // Otherwise, stay on the License tab.
    if (!$this->onboardingManager->isCompleted()) {
      $this->redirectToTab('onboarding', ['onboarding_step' => 'storage']);
    }

    wp_safe_redirect($tabUrl);
    exit;
  }

  /**
   * Handle deactivation of a specific domain from a license.
   *
   * The License tab renders a form with `domain` (the domain to deactivate)
   * and `license_key` (the current key). This handler calls the backend
   * /v1/licenses/deactivate to free up that slot.
   */
  public function handleLicenseDeactivateSite(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', NIMBUS_TEXT_DOMAIN), 403);
    }

    $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
    if (!wp_verify_nonce($nonce, NIMBUS_ADMIN_ACTION_LICENSE_DEACTIVATE_SITE)) {
      wp_die(esc_html__('Security check failed.', NIMBUS_TEXT_DOMAIN), 403);
    }

    $key    = isset($_POST['license_key']) ? sanitize_text_field((string) wp_unslash($_POST['license_key'])) : '';
    $domain = isset($_POST['domain']) ? sanitize_text_field((string) wp_unslash($_POST['domain'])) : '';
    $tabUrl = add_query_arg(['page' => NIMBUS_SLUG, 'tab' => 'license'], admin_url('admin.php'));

    if ($key === '' || $domain === '') {
      wp_safe_redirect($tabUrl);
      exit;
    }

    $token = $this->licenseManager->token();
    if (empty($token)) {
      pushAdminNotice(
        __('No activation token found. Please re-activate your license first, then try again.', NIMBUS_TEXT_DOMAIN),
        'error'
      );
      wp_safe_redirect($tabUrl);
      exit;
    }

    $client = new LicenseClient();
    $result = $client->deactivate($token, $domain);

    if (is_wp_error($result)) {
      pushAdminNotice(
        sprintf(
          /* translators: %s: error message */
          __('Could not deactivate site: %s', NIMBUS_TEXT_DOMAIN),
          $result->get_error_message()
        ),
        'error'
      );
      wp_safe_redirect($tabUrl);
      exit;
    }

    // If the deactivated domain is the current site, clear local license data.
    $currentDomain = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';
    if ($domain === $currentDomain) {
      $this->licenseManager->clear();
      pushAdminNotice(__('This site has been deactivated from the license.', NIMBUS_TEXT_DOMAIN), 'info');
    } else {
      pushAdminNotice(
        sprintf(
          /* translators: %s: domain name */
          __('Site "%s" deactivated from license.', NIMBUS_TEXT_DOMAIN),
          esc_html($domain)
        ),
        'success'
      );
    }

    wp_safe_redirect($tabUrl);
    exit;
  }
}
