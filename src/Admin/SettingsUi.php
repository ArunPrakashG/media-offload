<?php
namespace ArunPrakash\MediaOffload\Admin;

use ArunPrakash\MediaOffload\Config\Settings;
use ArunPrakash\MediaOffload\Jobs\BulkJobManager;
use ArunPrakash\MediaOffload\Integration\IntegrationDiscovery;
use ArunPrakash\MediaOffload\Integration\DatabaseIntegrationRewriter;
use ArunPrakash\MediaOffload\Integration\SmartSliderAssetRewriter;
use ArunPrakash\MediaOffload\License\LicenseManager;
use ArunPrakash\MediaOffload\Media\UploadHandler;
use ArunPrakash\MediaOffload\Onboarding\ConflictDetector;
use ArunPrakash\MediaOffload\Onboarding\OnboardingManager;
use ArunPrakash\MediaOffload\Stats\Statistics;
use ArunPrakash\MediaOffload\Support\Logger;

use function ArunPrakash\MediaOffload\Support\popAdminNotices;

if (!defined('ABSPATH')) {
  exit;
}

final class SettingsUi
{
  private ProviderCatalog $providerCatalog;
  private FieldRenderer $fieldRenderer;
  private SettingsRegistrar $settingsRegistrar;
  private PageRenderer $pageRenderer;
  private ActionsController $actionsController;
  private MediaLibraryController $mediaLibraryController;
  private LicenseManager $licenseManager;
  private ConflictDetector $conflictDetector;
  private OnboardingManager $onboardingManager;
  private SmartSliderAssetRewriter $smartSliderAssetRewriter;
  private DatabaseIntegrationRewriter $databaseIntegrationRewriter;
  private IntegrationDiscovery $integrationDiscovery;
  private BulkJobManager $bulkJobManager;
  private string $pageHook = '';

  public function __construct(
    private Settings $settings,
    private Logger $logger,
    private Statistics $statistics,
    private UploadHandler $uploadHandler,
  ) {
    $this->licenseManager = new LicenseManager();
    $this->conflictDetector = new ConflictDetector();
    $this->onboardingManager = new OnboardingManager($settings, $this->licenseManager, $this->conflictDetector);
    $this->bulkJobManager = new BulkJobManager($settings, $uploadHandler, $logger);
    $this->smartSliderAssetRewriter = new SmartSliderAssetRewriter($settings, $logger);
    $this->databaseIntegrationRewriter = new DatabaseIntegrationRewriter($settings, $logger);
    $this->integrationDiscovery = new IntegrationDiscovery($this->smartSliderAssetRewriter, $this->databaseIntegrationRewriter);
    $this->providerCatalog = new ProviderCatalog();
    $this->fieldRenderer = new FieldRenderer($settings, $this->providerCatalog);
    $this->settingsRegistrar = new SettingsRegistrar($settings, $this->fieldRenderer);
    $this->pageRenderer = new PageRenderer($settings, $logger, $statistics, $uploadHandler, $this->settingsRegistrar, $this->licenseManager, $this->onboardingManager, $this->integrationDiscovery, $this->bulkJobManager);
    $this->actionsController = new ActionsController($settings, $logger, $uploadHandler, $this->licenseManager, $this->onboardingManager, $this->integrationDiscovery);
    $this->mediaLibraryController = new MediaLibraryController($settings, $uploadHandler, $logger);
  }

  public function register(): void
  {
    add_action('admin_menu', [$this, 'add_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_init', [$this, 'handle_onboarding_redirect'], 5);
    add_action('admin_notices', [$this, 'render_admin_notices']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_ajax_' . MEDIA_OFFLOAD_AJAX_TEST_CONNECTION, [$this, 'handle_test_connection']);
    add_action('admin_post_' . MEDIA_OFFLOAD_ADMIN_ACTION_CLEAR_LOGS, [$this, 'handle_clear_logs']);
    add_action('admin_post_' . MEDIA_OFFLOAD_ADMIN_ACTION_RESUME_MIGRATION, [$this, 'handle_resume_migration']);
    add_action('admin_post_' . MEDIA_OFFLOAD_ADMIN_ACTION_RUN_URL_UPDATES, [$this, 'handle_run_url_updates']);
    add_action('admin_post_' . MEDIA_OFFLOAD_ADMIN_ACTION_COMPLETE_ONBOARDING, [$this, 'handle_complete_onboarding']);
    add_action('admin_post_' . MEDIA_OFFLOAD_ADMIN_ACTION_REWRITE_INTEGRATION, [$this, 'handle_rewrite_integration']);
    $this->mediaLibraryController->register();
  }

  public function enqueue_assets($hook): void
  {
    if (!$this->pageHook || $hook !== $this->pageHook) {
      return;
    }

    $css_version = file_exists(MEDIA_OFFLOAD_PATH . 'assets/css/admin.css') ? filemtime(MEDIA_OFFLOAD_PATH . 'assets/css/admin.css') : MEDIA_OFFLOAD_VERSION;
    $js_version = file_exists(MEDIA_OFFLOAD_PATH . 'assets/js/admin.js') ? filemtime(MEDIA_OFFLOAD_PATH . 'assets/js/admin.js') : MEDIA_OFFLOAD_VERSION;

    wp_enqueue_style(MEDIA_OFFLOAD_SCRIPT_HANDLE, MEDIA_OFFLOAD_URL . 'assets/css/admin.css', [], $css_version);
    wp_enqueue_script(MEDIA_OFFLOAD_SCRIPT_HANDLE, MEDIA_OFFLOAD_URL . 'assets/js/admin.js', ['jquery'], $js_version, true);

    $scriptData = [
      'nonce' => wp_create_nonce(MEDIA_OFFLOAD_AJAX_TEST_CONNECTION),
      'restNonce' => wp_create_nonce('wp_rest'),
      'restBase' => untrailingslashit(rest_url(MEDIA_OFFLOAD_REST_NAMESPACE)),
      'optionKey' => MEDIA_OFFLOAD_OPTION_KEY,
      'actions' => ['testConnection' => MEDIA_OFFLOAD_AJAX_TEST_CONNECTION],
      'restNamespace' => MEDIA_OFFLOAD_REST_NAMESPACE,
      'strings' => [
        'showAdvanced' => __('Show advanced connection fields', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'hideAdvanced' => __('Hide advanced connection fields', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'testing' => __('Testing…', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'testingMessage' => __('Testing connection…', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'testConnection' => __('Test Connection', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'serverError' => __('Server error occurred.', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'detailsHeading' => __('Connection details', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'hintsHeading' => __('Suggested checks', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'jobStartError' => __('Unable to start the requested bulk job.', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'jobPauseError' => __('Unable to pause the current bulk job.', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'jobResumeError' => __('Unable to resume the current bulk job.', MEDIA_OFFLOAD_TEXT_DOMAIN),
        'jobRetryError' => __('Unable to retry failed items right now.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ],
      'providers' => $this->providerCatalog->scriptData(),
    ];

    wp_localize_script(MEDIA_OFFLOAD_SCRIPT_HANDLE, MEDIA_OFFLOAD_SCRIPT_OBJECT, $scriptData);
  }

  public function handle_test_connection(): void
  {
    $this->actionsController->handleTestConnection();
  }

  public function add_menu(): void
  {
    $hook = add_media_page(
      __('Media Offload', MEDIA_OFFLOAD_TEXT_DOMAIN),
      __('Media Offload', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'manage_options',
      MEDIA_OFFLOAD_SLUG,
      [$this, 'render_page']
    );

    if ($hook) {
      $this->pageHook = $hook;
    }
  }

  public function register_settings(): void
  {
    $this->settingsRegistrar->registerSettings();
  }

  public function sanitize_settings($input)
  {
    return $this->settingsRegistrar->sanitizeSettings($input);
  }

  public function render_page(): void
  {
    $this->pageRenderer->renderPage();
  }

  public function handle_clear_logs(): void
  {
    $this->actionsController->handleClearLogs();
  }

  public function handle_run_url_updates(): void
  {
    $this->actionsController->handleRunUrlUpdates();
  }

  public function handle_resume_migration(): void
  {
    $this->actionsController->handleResumeMigration();
  }

  public function handle_complete_onboarding(): void
  {
    $this->actionsController->handleCompleteOnboarding();
  }

  public function handle_rewrite_integration(): void
  {
    $this->actionsController->handleRewriteIntegration();
  }

  public function handle_onboarding_redirect(): void
  {
    $this->onboardingManager->handleActivationRedirect();
  }

  public function render_admin_notices(): void
  {
    $notices = popAdminNotices();
    if (empty($notices)) {
      return;
    }

    foreach ($notices as $notice) {
      $class = 'notice notice-' . esc_attr($notice['type']);
      printf('<div class="%1$s"><p>%2$s</p></div>', $class, esc_html($notice['message']));
    }
  }
}
