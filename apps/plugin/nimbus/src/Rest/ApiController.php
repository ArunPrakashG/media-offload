<?php
namespace Nimbus\Plugin\Rest;

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
      'methods'             => \WP_REST_Server::READABLE,
      'callback'            => [$this, 'getStatus'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/statistics', [
      'methods'             => \WP_REST_Server::READABLE,
      'callback'            => [$this, 'getStatistics'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/integrations', [
      'methods'             => \WP_REST_Server::READABLE,
      'callback'            => [$this, 'getIntegrations'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs', [
      'methods'             => \WP_REST_Server::READABLE,
      'callback'            => [$this, 'getJobs'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/start', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postStartJob'],
      'permission_callback' => [$this, 'canManage'],
      'args'                => [
        'type' => [
          'type'              => 'string',
          'required'          => true,
          'sanitize_callback' => 'sanitize_key',
          'description'       => __('The type of bulk job to start.', NIMBUS_TEXT_DOMAIN),
        ],
        'batch_size' => [
          'type'              => 'integer',
          'default'           => 50,
          'minimum'           => 1,
          'maximum'           => 1000,
          'sanitize_callback' => 'absint',
          'description'       => __('Number of attachments to process per worker batch.', NIMBUS_TEXT_DOMAIN),
        ],
      ],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/pause', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postPauseJob'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/resume', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postResumeJob'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/retry-failed', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postRetryFailedJob'],
      'permission_callback' => [$this, 'canManage'],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/offload/pending', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postPendingOffload'],
      'permission_callback' => [$this, 'canManage'],
      'args'                => [
        'hours' => [
          'type'              => 'integer',
          'default'           => 24,
          'minimum'           => 1,
          'sanitize_callback' => 'absint',
          'description'       => __('Process attachments uploaded within this many hours.', NIMBUS_TEXT_DOMAIN),
        ],
        'limit' => [
          'type'              => 'integer',
          'default'           => 100,
          'minimum'           => 1,
          'maximum'           => 1000,
          'sanitize_callback' => 'absint',
          'description'       => __('Maximum number of attachments to process in one request.', NIMBUS_TEXT_DOMAIN),
        ],
        'background' => [
          'type'        => 'boolean',
          'default'     => true,
          'description' => __('When true the offload is queued as a scheduled event and the request returns immediately.', NIMBUS_TEXT_DOMAIN),
        ],
      ],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/offload/item/(?P<id>\d+)', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postSingleOffload'],
      'permission_callback' => [$this, 'canManage'],
      'args'                => [
        'id' => [
          'type'              => 'integer',
          'required'          => true,
          'minimum'           => 1,
          'sanitize_callback' => 'absint',
          'description'       => __('Attachment post ID to offload.', NIMBUS_TEXT_DOMAIN),
        ],
        'background' => [
          'type'        => 'boolean',
          'default'     => false,
          'description' => __('When true the offload is queued as a scheduled event.', NIMBUS_TEXT_DOMAIN),
        ],
      ],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/integrations/(?P<key>[a-z0-9_-]+)/rewrite', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postIntegrationRewrite'],
      'permission_callback' => [$this, 'canManage'],
      'args'                => [
        'key' => [
          'type'              => 'string',
          'required'          => true,
          'sanitize_callback' => 'sanitize_key',
          'description'       => __('Integration slug whose asset URLs should be rewritten.', NIMBUS_TEXT_DOMAIN),
        ],
      ],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/storage/create-bucket', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postCreateBucket'],
      'permission_callback' => [$this, 'canManage'],
      'args'                => [
        'name' => [
          'type'              => 'string',
          'required'          => true,
          'sanitize_callback' => 'sanitize_text_field',
          'description'       => __('Bucket name to create.', NIMBUS_TEXT_DOMAIN),
        ],
        'region' => [
          'type'              => 'string',
          'default'           => '',
          'sanitize_callback' => 'sanitize_text_field',
          'description'       => __('Storage region for the new bucket.', NIMBUS_TEXT_DOMAIN),
        ],
      ],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/storage/presign', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postPresign'],
      'permission_callback' => [$this, 'canManage'],
      'args'                => [
        'attachment_id' => [
          'type'              => 'integer',
          'required'          => true,
          'minimum'           => 1,
          'sanitize_callback' => 'absint',
          'description'       => __('Attachment post ID to generate a presigned URL for.', NIMBUS_TEXT_DOMAIN),
        ],
        'ttl' => [
          'type'              => 'integer',
          'default'           => 3600,
          'minimum'           => 60,
          'maximum'           => 86400,
          'sanitize_callback' => 'absint',
          'description'       => __('Signed URL lifetime in seconds (60–86400).', NIMBUS_TEXT_DOMAIN),
        ],
      ],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/cache/purge', [
      'methods'             => \WP_REST_Server::CREATABLE,
      'callback'            => [$this, 'postCachePurge'],
      'permission_callback' => [$this, 'canManage'],
      'args'                => [
        'urls' => [
          'type'        => 'array',
          'default'     => [],
          'items'       => ['type' => 'string', 'format' => 'uri'],
          'description' => __('Specific URLs to purge. When empty and purge_all is true, all cached URLs are purged.', NIMBUS_TEXT_DOMAIN),
        ],
        'purge_all' => [
          'type'        => 'boolean',
          'default'     => false,
          'description' => __('When true, purge all cached URLs regardless of the urls list.', NIMBUS_TEXT_DOMAIN),
        ],
        'reason' => [
          'type'              => 'string',
          'default'           => 'manual_api',
          'sanitize_callback' => 'sanitize_key',
          'description'       => __('Reason code recorded in the purge log.', NIMBUS_TEXT_DOMAIN),
        ],
      ],
    ]);

    register_rest_route(NIMBUS_REST_NAMESPACE, '/jobs/stream', [
      'methods'             => \WP_REST_Server::READABLE,
      'callback'            => [$this, 'getJobsStream'],
      'permission_callback' => [$this, 'canManage'],
      'args'                => [
        'timeout' => [
          'type'              => 'integer',
          'default'           => 30,
          'minimum'           => 5,
          'maximum'           => 55,
          'sanitize_callback' => 'absint',
          'description'       => __('Maximum number of seconds to hold the SSE connection open.', NIMBUS_TEXT_DOMAIN),
        ],
      ],
    ]);
  }

  public function canManage(): bool
  {
    return current_user_can('manage_options');
  }

  public function getStatus(): \WP_REST_Response
  {
    $licenseData = $this->licenseManager->all();
    unset($licenseData['token'], $licenseData['key']);

    return rest_ensure_response([
      'plugin' => [
        'version' => NIMBUS_VERSION,
        'enabled' => $this->settings->is_enabled(),
        'provider' => $this->settings->provider(),
        'bucket' => (string) $this->settings->get('bucket', ''),
        'serve_from_storage' => $this->settings->should_serve_from_storage(),
        'credentials_ready' => $this->settings->has_valid_credentials(),
      ],
      'license' => $licenseData,
      'onboarding' => $this->onboardingManager->state(),
      'jobs' => apply_filters('nimbus/jobs/state', null),
      'integrations' => $this->integrationDiscovery->summary(),
      'statistics' => $this->licenseManager->hasFeature('storage_analytics')
        ? $this->statistics->get_deep_stats()
        : array_merge($this->statistics->get_stats(), ['_upgrade_required' => 'storage_analytics']),
    ]);
  }

  public function getStatistics(): \WP_REST_Response
  {
    if (!$this->licenseManager->hasFeature('storage_analytics')) {
      return rest_ensure_response(
        array_merge($this->statistics->get_stats(), ['_upgrade_required' => 'storage_analytics'])
      );
    }

    return rest_ensure_response($this->statistics->get_deep_stats());
  }

  public function getIntegrations(): \WP_REST_Response
  {
    return rest_ensure_response($this->integrationDiscovery->summary());
  }

  public function getJobs(): \WP_REST_Response
  {
    return rest_ensure_response(apply_filters('nimbus/jobs/state', null));
  }

  public function postStartJob(\WP_REST_Request $request)
  {
    $bulkJobManager = apply_filters('nimbus/bulk_job_manager', null);
    if ($bulkJobManager === null) {
      return new \WP_Error('nimbus_pro_required', __('Bulk jobs require Nimbus Pro.', NIMBUS_TEXT_DOMAIN), ['status' => 403]);
    }

    $result = $bulkJobManager->start((string) $request->get_param('type'), [
      'batch_size' => (int) $request->get_param('batch_size'),
    ]);

    if (is_wp_error($result)) {
      return $result;
    }

    return rest_ensure_response(new \WP_REST_Response($result, 202));
  }

  public function postPauseJob()
  {
    $bulkJobManager = apply_filters('nimbus/bulk_job_manager', null);
    if ($bulkJobManager === null) {
      return new \WP_Error('nimbus_pro_required', __('Bulk jobs require Nimbus Pro.', NIMBUS_TEXT_DOMAIN), ['status' => 403]);
    }

    $result = $bulkJobManager->pause();

    if (is_wp_error($result)) {
      return $result;
    }

    return rest_ensure_response($result);
  }

  public function postResumeJob()
  {
    $bulkJobManager = apply_filters('nimbus/bulk_job_manager', null);
    if ($bulkJobManager === null) {
      return new \WP_Error('nimbus_pro_required', __('Bulk jobs require Nimbus Pro.', NIMBUS_TEXT_DOMAIN), ['status' => 403]);
    }

    $result = $bulkJobManager->resume();

    if (is_wp_error($result)) {
      return $result;
    }

    return rest_ensure_response(new \WP_REST_Response($result, 202));
  }

  public function postRetryFailedJob()
  {
    $bulkJobManager = apply_filters('nimbus/bulk_job_manager', null);
    if ($bulkJobManager === null) {
      return new \WP_Error('nimbus_pro_required', __('Bulk jobs require Nimbus Pro.', NIMBUS_TEXT_DOMAIN), ['status' => 403]);
    }

    $result = $bulkJobManager->retryFailed();

    if (is_wp_error($result)) {
      return $result;
    }

    return rest_ensure_response(new \WP_REST_Response($result, 202));
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

      return rest_ensure_response(new \WP_REST_Response([
        'scheduled' => true,
        'hours' => $hours,
        'limit' => $limit,
      ], 202));
    }

    return rest_ensure_response([
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

      return rest_ensure_response(new \WP_REST_Response([
        'scheduled' => true,
        'attachment_id' => $attachmentId,
      ], 202));
    }

    return rest_ensure_response([
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

    return rest_ensure_response([
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

  public function postCreateBucket(\WP_REST_Request $request)
  {
    $adapter = $this->settings->has_valid_credentials()
      ? $this->uploadHandler->getAdapter()
      : null;

    if ($adapter === null) {
      return new \WP_Error('nimbus_credentials_missing', __('Configure storage credentials before creating a bucket.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    if (!method_exists($adapter, 'createBucket')) {
      return new \WP_Error('nimbus_unsupported', __('The active storage provider does not support creating buckets via API.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    $name = sanitize_text_field((string) ($request->get_param('name') ?: ''));
    $region = sanitize_text_field((string) ($request->get_param('region') ?: ''));

    if ($name === '') {
      return new \WP_Error('nimbus_invalid', __('Bucket name is required.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    try {
      $adapter->createBucket($name, $region);
    } catch (\Throwable $e) {
      return new \WP_Error('nimbus_create_bucket_failed', $e->getMessage(), ['status' => 502]);
    }

    return rest_ensure_response(new \WP_REST_Response(['created' => true, 'name' => $name], 201));
  }

  public function postPresign(\WP_REST_Request $request)
  {
    $adapter = $this->settings->has_valid_credentials()
      ? $this->uploadHandler->getAdapter()
      : null;

    if ($adapter === null) {
      return new \WP_Error('nimbus_credentials_missing', __('Configure storage credentials before generating signed URLs.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    if (!method_exists($adapter, 'presign')) {
      return new \WP_Error('nimbus_unsupported', __('The active storage provider does not support signed URLs.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    $attachmentId = (int) ($request->get_param('attachment_id') ?? 0);
    $ttl = min(86400, max(60, (int) ($request->get_param('ttl') ?? 3600)));

    if ($attachmentId <= 0) {
      return new \WP_Error('nimbus_invalid', __('attachment_id is required.', NIMBUS_TEXT_DOMAIN), ['status' => 400]);
    }

    $meta = get_post_meta($attachmentId, NIMBUS_META_KEY, true);
    if (!is_array($meta) || empty($meta['key'])) {
      return new \WP_Error('nimbus_not_offloaded', __('This attachment has not been offloaded.', NIMBUS_TEXT_DOMAIN), ['status' => 404]);
    }

    try {
      $url = $adapter->presign((string) $meta['key'], $ttl);
    } catch (\Throwable $e) {
      return new \WP_Error('nimbus_presign_failed', $e->getMessage(), ['status' => 502]);
    }

    return rest_ensure_response(['url' => $url, 'expires_in' => $ttl]);
  }

  public function postCachePurge(\WP_REST_Request $request)
  {
    $purgeManager = apply_filters('nimbus/purge_manager', null);
    if ($purgeManager === null) {
      return new \WP_Error('nimbus_pro_required', __('Cache purge requires Nimbus Pro with the cache_purge feature enabled.', NIMBUS_TEXT_DOMAIN), ['status' => 403]);
    }

    $urls = array_values(array_filter(array_map('esc_url_raw', (array) ($request->get_param('urls') ?? [])), static fn (string $u): bool => $u !== ''));
    $purgeAll = $this->toBool($request->get_param('purge_all'), empty($urls));
    $reason = sanitize_key((string) ($request->get_param('reason') ?: 'manual_api'));

    $result = $purgeManager->requestPurge([
      'reason' => $reason,
      'scope' => 'manual',
      'purge_all' => $purgeAll,
      'urls' => $urls,
    ]);

    return rest_ensure_response(new \WP_REST_Response($result, 202));
  }

  public function getJobsStream(\WP_REST_Request $request): void
  {
    while (@ob_end_flush()) {
    }

    @ini_set('zlib.output_compression', '0');

    if (function_exists('apache_setenv')) {
      @apache_setenv('no-gzip', '1');
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    $maxRuntime = min(55, max(5, (int) ($request->get_param('timeout') ?: 30)));
    $start = time();
    $done = false;

    while (!$done && (time() - $start) < $maxRuntime) {
      $state = apply_filters('nimbus/jobs/state', null);
      echo 'data: ' . wp_json_encode(['state' => $state, 'ts' => time()]) . "\n\n";

      if (ob_get_level() > 0) {
        ob_flush();
      }
      flush();

      $jobStatus = (string) ($state['current']['status'] ?? '');
      $done = $state === null || $jobStatus === '' || in_array($jobStatus, ['completed', 'completed_with_errors', 'failed'], true);

      if (!$done) {
        sleep(2);
      }
    }

    echo 'data: ' . wp_json_encode(['done' => true, 'ts' => time()]) . "\n\n";

    if (ob_get_level() > 0) {
      ob_flush();
    }
    flush();
    exit;
  }
}
