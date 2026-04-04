<?php
namespace Nimbus\Plugin\Rest;

use Nimbus\Plugin\Jobs\BulkJobManager;
use Nimbus\Plugin\Integration\IntegrationDiscovery;
use Nimbus\Plugin\License\LicenseManager;
use Nimbus\Plugin\Onboarding\OnboardingManager;
use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Stats\Statistics;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class ApiController
{
  private const PROCESS_PENDING_HOOK = 'nimbus_rest_process_pending';
  private const PROCESS_SINGLE_HOOK = 'nimbus_rest_process_single';

  public function __construct(
    private Settings $settings,
    private Statistics $statistics,
    private UploadHandler $uploadHandler,
    private BulkJobManager $bulkJobManager,
    private IntegrationDiscovery $integrationDiscovery,
    private LicenseManager $licenseManager,
    private OnboardingManager $onboardingManager,
    private Logger $logger,
  ) {
  }

  public function register(): void
  {
    add_action('rest_api_init', [$this, 'registerRoutes']);
    add_action(self::PROCESS_PENDING_HOOK, [$this, 'processPendingScheduled'], 10, 2);
    add_action(self::PROCESS_SINGLE_HOOK, [$this, 'processSingleScheduled'], 10, 1);
  }

  public function registerRoutes(): void
  {
    register_rest_route(NIMBUS_REST_NAMESPACE, '/status', [
      'methods' => 'GET',
      'callback' => [$this, 'getStatus'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/statistics', [
      'methods' => 'GET',
      'callback' => [$this, 'getStatistics'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/integrations', [
      'methods' => 'GET',
      'callback' => [$this, 'getIntegrations'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs', [
      'methods' => 'GET',
      'callback' => [$this, 'getJobs'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/start', [
      'methods' => 'POST',
      'callback' => [$this, 'postStartJob'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/pause', [
      'methods' => 'POST',
      'callback' => [$this, 'postPauseJob'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/resume', [
      'methods' => 'POST',
      'callback' => [$this, 'postResumeJob'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/retry-failed', [
      'methods' => 'POST',
      'callback' => [$this, 'postRetryFailedJob'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/offload/pending', [
      'methods' => 'POST',
      'callback' => [$this, 'postPendingOffload'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/offload/item/(?P<id>\d+)', [
      'methods' => 'POST',
      'callback' => [$this, 'postSingleOffload'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/integrations/(?P<key>[a-z0-9_-]+)/rewrite', [
      'methods' => 'POST',
      'callback' => [$this, 'postIntegrationRewrite'],
      'permission_callback' => [$this, 'canManage'],
    ]);
  }

  public function canManage(): bool
  {
    return current_user_can('manage_options');
  }

  public function getStatus(): \WP_REST_Response
  {
    return new \WP_REST_Response([
      'plugin' => [
        'version' => NIMBUS_VERSION,
        'enabled' => $this->settings->is_enabled(),
        'provider' => $this->settings->provider(),
        'bucket' => (string) $this->settings->get('bucket', ''),
        'serve_from_storage' => $this->settings->should_serve_from_storage(),
        'credentials_ready' => $this->settings->has_valid_credentials(),
      ],
      'license' => $this->licenseManager->all(),
      'onboarding' => $this->onboardingManager->state(),
      'jobs' => $this->bulkJobManager->state(),
      'integrations' => $this->integrationDiscovery->summary(),
      'statistics' => $this->statistics->get_deep_stats(),
    ]);
  }

  public function getStatistics(): \WP_REST_Response
  {
    return new \WP_REST_Response($this->statistics->get_deep_stats());
  }

  public function getIntegrations(): \WP_REST_Response
  {
    return new \WP_REST_Response($this->integrationDiscovery->summary());
  }

  public function getJobs(): \WP_REST_Response
  {
    return new \WP_REST_Response($this->bulkJobManager->state());
  }

  public function postStartJob(\WP_REST_Request $request)
  {
    $result = $this->bulkJobManager->start((string) $request->get_param('type'), [
      'batch_size' => (int) $request->get_param('batch_size'),
    ]);

    if (is_wp_error($result)) {
      return $result;
    }

    return new \WP_REST_Response($result, 202);
  }

  public function postPauseJob()
  {
    $result = $this->bulkJobManager->pause();

    if (is_wp_error($result)) {
      return $result;
    }

    return new \WP_REST_Response($result);
  }

  public function postResumeJob()
  {
    $result = $this->bulkJobManager->resume();

    if (is_wp_error($result)) {
      return $result;
    }

    return new \WP_REST_Response($result, 202);
  }

  public function postRetryFailedJob()
  {
    $result = $this->bulkJobManager->retryFailed();

    if (is_wp_error($result)) {
      return $result;
    }

    return new \WP_REST_Response($result, 202);
  }

  public function postPendingOffload(\WP_REST_Request $request)
  {
    $hours = max(1, (int) $request->get_param('hours'));
    $limit = max(1, min(1000, (int) $request->get_param('limit')));
    $background = $this->toBool($request->get_param('background'), true);

    if (!$this->settings->is_enabled() || !$this->settings->has_valid_credentials()) {
      return new \WP_Error('nimbus_not_ready', __('Storage is not configured well enough to process offloads.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    if ($background) {
      wp_schedule_single_event(time() + 1, self::PROCESS_PENDING_HOOK, [$hours, $limit]);

      return new \WP_REST_Response([
        'scheduled' => true,
        'hours' => $hours,
        'limit' => $limit,
      ], 202);
    }

    return new \WP_REST_Response([
      'scheduled' => false,
      'result' => $this->uploadHandler->process_pending_offloads($hours, $limit, 'rest-api'),
    ]);
  }

  public function postSingleOffload(\WP_REST_Request $request)
  {
    $attachmentId = max(0, (int) $request['id']);
    $background = $this->toBool($request->get_param('background'), false);

    if ($attachmentId <= 0) {
      return new \WP_Error('nimbus_invalid_attachment', __('Attachment ID is required.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    if ($background) {
      wp_schedule_single_event(time() + 1, self::PROCESS_SINGLE_HOOK, [$attachmentId]);

      return new \WP_REST_Response([
        'scheduled' => true,
        'attachment_id' => $attachmentId,
      ], 202);
    }

    return new \WP_REST_Response([
      'scheduled' => false,
      'attachment_id' => $attachmentId,
      'success' => $this->uploadHandler->offload_via_cli($attachmentId),
    ]);
  }

  public function postIntegrationRewrite(\WP_REST_Request $request)
  {
    $key = sanitize_key((string) $request['key']);

    if ($key === '' || !$this->integrationDiscovery->canRewrite($key)) {
      return new \WP_Error('nimbus_integration_unsupported', __('Rewrite automation is not available for this integration.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    if (!$this->settings->has_valid_credentials()) {
      return new \WP_Error('nimbus_credentials_missing', __('Configure storage credentials before rewriting integration asset URLs.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    return new \WP_REST_Response([
      'integration' => $key,
      'result' => $this->integrationDiscovery->runRewrite($key),
    ]);
  }

  public function processPendingScheduled(int $hours, int $limit): void
  {
    try {
      $this->uploadHandler->process_pending_offloads(max(1, $hours), max(1, $limit), 'rest-scheduled');
    } catch (\Throwable $exception) {
      $this->logger->warning('Scheduled REST pending offload failed: {error}', [
        'error' => $exception->getMessage(),
      ]);
    }
  }

  public function processSingleScheduled(int $attachmentId): void
  {
    try {
      $this->uploadHandler->offload_via_cli(max(0, $attachmentId));
    } catch (\Throwable $exception) {
      $this->logger->warning('Scheduled REST single offload failed: {error}', [
        'error' => $exception->getMessage(),
      ]);
    }
  }

  private function toBool($value, bool $default): bool
  {
    if ($value === null) {
      return $default;
    }

    if (is_bool($value)) {
      return $value;
    }

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
  }
}
