<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Integration\IntegrationDiscovery;
use Nimbus\Plugin\Integration\DatabaseIntegrationRewriter;
use Nimbus\Plugin\Integration\SmartSliderAssetRewriter;
use Nimbus\Plugin\License\LicenseManager;
use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Onboarding\ConflictDetector;
use Nimbus\Plugin\Onboarding\OnboardingManager;
use Nimbus\Plugin\Stats\Statistics;
use Nimbus\Plugin\Support\Logger;

use function Nimbus\Plugin\Support\popAdminNotices;

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
    $this->smartSliderAssetRewriter = new SmartSliderAssetRewriter($settings, $logger);
    $this->databaseIntegrationRewriter = new DatabaseIntegrationRewriter($settings, $logger);
    $this->integrationDiscovery = new IntegrationDiscovery($this->smartSliderAssetRewriter, $this->databaseIntegrationRewriter);
    $this->providerCatalog = new ProviderCatalog();
    $this->fieldRenderer = new FieldRenderer($settings, $this->providerCatalog);
    $this->settingsRegistrar = new SettingsRegistrar($settings, $this->fieldRenderer);
    $this->pageRenderer = new PageRenderer($settings, $logger, $statistics, $uploadHandler, $this->settingsRegistrar, $this->licenseManager, $this->onboardingManager, $this->integrationDiscovery);
    $this->actionsController = new ActionsController($settings, $logger, $uploadHandler, $this->licenseManager, $this->onboardingManager, $this->integrationDiscovery);
    $this->mediaLibraryController = new MediaLibraryController($settings, $uploadHandler, $logger);
  }

  public function register(): void
  {
    add_action('admin_menu', [$this, 'add_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_init', [$this, 'handle_onboarding_redirect'], 5);
    add_action('admin_notices', [$this, 'render_admin_notices']);
    add_action('admin_notices', [$this, 'render_license_banner']);
    add_action('admin_notices', [$this, 'render_pubkey_warning']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION, [$this, 'handle_toggle_integration']);
    add_action('wp_ajax_' . NIMBUS_AJAX_TEST_CONNECTION, [$this, 'handle_test_connection']);
    add_action('wp_ajax_' . NIMBUS_AJAX_ONBOARDING_SAVE_STEP, [$this, 'handle_save_onboarding_step']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_CLEAR_LOGS, [$this, 'handle_clear_logs']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_RESUME_MIGRATION, [$this, 'handle_resume_migration']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES, [$this, 'handle_run_url_updates']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING, [$this, 'handle_complete_onboarding']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION, [$this, 'handle_rewrite_integration']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_LICENSE_ACTIVATE, [$this, 'handle_license_activate']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_LICENSE_ACTIVATE_FREE, [$this, 'handle_license_activate_free']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_LICENSE_DEACTIVATE_SITE, [$this, 'handle_license_deactivate_site']);
    $this->mediaLibraryController->register();
  }

  public function enqueue_assets($hook): void
  {
    if (!$this->pageHook || $hook !== $this->pageHook) {
      return;
    }

    $tokens_version = file_exists(NIMBUS_PATH . 'assets/css/tokens.css') ? filemtime(NIMBUS_PATH . 'assets/css/tokens.css') : NIMBUS_VERSION;
    $css_version = file_exists(NIMBUS_PATH . 'assets/css/admin.css') ? filemtime(NIMBUS_PATH . 'assets/css/admin.css') : NIMBUS_VERSION;
    $js_version = file_exists(NIMBUS_PATH . 'assets/js/admin.js') ? filemtime(NIMBUS_PATH . 'assets/js/admin.js') : NIMBUS_VERSION;

    wp_enqueue_style(NIMBUS_SCRIPT_HANDLE . '-tokens', NIMBUS_URL . 'assets/css/tokens.css', [], $tokens_version);
    wp_enqueue_style(NIMBUS_SCRIPT_HANDLE, NIMBUS_URL . 'assets/css/admin.css', [NIMBUS_SCRIPT_HANDLE . '-tokens'], $css_version);
    wp_enqueue_script(NIMBUS_SCRIPT_HANDLE, NIMBUS_URL . 'assets/js/admin.js', ['jquery'], $js_version, true);

    $scriptData = [
      'nonce' => wp_create_nonce(NIMBUS_AJAX_TEST_CONNECTION),
      'restNonce' => wp_create_nonce('wp_rest'),
      'restBase' => add_query_arg('rest_route', '/' . trim(NIMBUS_REST_NAMESPACE, '/'), home_url('/')),
      'optionKey' => NIMBUS_OPTION_KEY,
      'actions' => ['testConnection' => NIMBUS_AJAX_TEST_CONNECTION],
      'restNamespace' => NIMBUS_REST_NAMESPACE,
      'strings' => [
        'showAdvanced' => __('Show advanced connection fields', NIMBUS_TEXT_DOMAIN),
        'hideAdvanced' => __('Hide advanced connection fields', NIMBUS_TEXT_DOMAIN),
        'noExtraCredentials' => __('No extra credentials required', NIMBUS_TEXT_DOMAIN),
        'providerNoBucket' => __('No bucket required in local mode', NIMBUS_TEXT_DOMAIN),
        'providerBucketNotSet' => __('Bucket not set', NIMBUS_TEXT_DOMAIN),
        'deliveryLocalDebug' => __('Local debug', NIMBUS_TEXT_DOMAIN),
        'deliveryRemote' => __('Remote', NIMBUS_TEXT_DOMAIN),
        'deliveryLocal' => __('Local', NIMBUS_TEXT_DOMAIN),
        'deliveryNoCustomDomain' => __('No custom domain configured', NIMBUS_TEXT_DOMAIN),
        'rulesTemplate' => __('Allowed: %1$d · Ignored: %2$d', NIMBUS_TEXT_DOMAIN),
        'batchOn' => __('On', NIMBUS_TEXT_DOMAIN),
        'batchOff' => __('Off', NIMBUS_TEXT_DOMAIN),
        'batchNoSchedule' => __('No schedule set', NIMBUS_TEXT_DOMAIN),
        'shellLocalDebugMode' => __('Local debug mode', NIMBUS_TEXT_DOMAIN),
        'shellServingStorage' => __('Serving from storage', NIMBUS_TEXT_DOMAIN),
        'shellServingLocal' => __('Serving from local URLs', NIMBUS_TEXT_DOMAIN),
        'testing' => __('Testing…', NIMBUS_TEXT_DOMAIN),
        'testingMessage' => __('Testing connection…', NIMBUS_TEXT_DOMAIN),
        'testConnection' => __('Test Connection', NIMBUS_TEXT_DOMAIN),
        'serverError' => __('Server error occurred.', NIMBUS_TEXT_DOMAIN),
        'detailsHeading' => __('Connection details', NIMBUS_TEXT_DOMAIN),
        'hintsHeading' => __('Suggested checks', NIMBUS_TEXT_DOMAIN),
        'jobStartError' => __('Unable to start the requested bulk job.', NIMBUS_TEXT_DOMAIN),
        'jobPauseError' => __('Unable to pause the current bulk job.', NIMBUS_TEXT_DOMAIN),
        'jobResumeError' => __('Unable to resume the current bulk job.', NIMBUS_TEXT_DOMAIN),
        'jobRetryError' => __('Unable to retry failed items right now.', NIMBUS_TEXT_DOMAIN),
      ],
      'providers' => $this->providerCatalog->scriptData(),
      'onboarding' => [
        'saveStepAction' => NIMBUS_AJAX_ONBOARDING_SAVE_STEP,
        'saveStepNonce'  => wp_create_nonce(NIMBUS_AJAX_ONBOARDING_SAVE_STEP),
      ],
    ];

    wp_localize_script(NIMBUS_SCRIPT_HANDLE, NIMBUS_SCRIPT_OBJECT, $scriptData);
  }

  public function handle_test_connection(): void
  {
    $this->actionsController->handleTestConnection();
  }

  public function handle_save_onboarding_step(): void
  {
    $this->actionsController->handleSaveOnboardingStep();
  }

  public function add_menu(): void
  {
    $hook = add_media_page(
      __('Nimbus', NIMBUS_TEXT_DOMAIN),
      __('Nimbus', NIMBUS_TEXT_DOMAIN),
      'manage_options',
      NIMBUS_SLUG,
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

  public function handle_toggle_integration(): void
  {
    $this->actionsController->handleToggleIntegration();
  }

  public function handle_license_activate(): void
  {
    $this->actionsController->handleLicenseActivate();
  }

  public function handle_license_activate_free(): void
  {
    $this->actionsController->handleLicenseActivateFree();
  }

  public function handle_license_deactivate_site(): void
  {
    $this->actionsController->handleLicenseDeactivateSite();
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

  public function render_pubkey_warning(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    if ($this->licenseManager->isPublicKeyConfigured()) {
      return;
    }

    ?>
    <div class="notice notice-error">
      <p>
        <strong><?php esc_html_e('Nimbus – Configuration error', NIMBUS_TEXT_DOMAIN); ?></strong>
        <?php esc_html_e('NIMBUS_LICENSE_PUBLIC_KEY is not defined. License token signatures cannot be verified. Define it in wp-config.php before going live.', NIMBUS_TEXT_DOMAIN); ?>
      </p>
    </div>
    <?php
  }

  public function render_license_banner(): void
  {
    // Don't show if already on the Nimbus admin page (overlay handles it there).
    $screen = get_current_screen();
    if ($screen && str_contains((string) ($screen->id ?? ''), NIMBUS_SLUG)) {
      return;
    }

    if ($this->licenseManager->hasKey() && $this->licenseManager->status() === 'active') {
      return;
    }

    $dismissed = get_transient('mo_license_banner_dismissed_' . get_current_user_id());
    if ($dismissed) {
      return;
    }

    $licenseUrl = add_query_arg(['page' => NIMBUS_SLUG, 'tab' => 'license'], admin_url('upload.php'));
    ?>
    <div class="notice notice-warning mo-license-notice" data-mo-dismiss-nonce="<?php echo esc_attr(wp_create_nonce('mo_dismiss_license_banner')); ?>">
      <p>
        <strong><?php esc_html_e('Nimbus – License required', NIMBUS_TEXT_DOMAIN); ?></strong>
        <?php esc_html_e('This plugin requires a valid license key to operate. Please activate your license to enable offloading.', NIMBUS_TEXT_DOMAIN); ?>
        &nbsp;<a href="<?php echo esc_url($licenseUrl); ?>"><?php esc_html_e('Activate license →', NIMBUS_TEXT_DOMAIN); ?></a>
      </p>
      <button type="button" class="notice-dismiss" data-mo-dismiss-banner></button>
    </div>
    <script>
    (function () {
      var banner = document.querySelector('.mo-license-notice');
      if (!banner) return;
      var btn = banner.querySelector('[data-mo-dismiss-banner]');
      if (!btn) return;
      btn.addEventListener('click', function () {
        banner.remove();
        var nonce = banner.dataset.moDismissNonce || '';
        if (!nonce) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxurl);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('action=mo_dismiss_license_banner&nonce=' + encodeURIComponent(nonce));
      });
    })();
    </script>
    <?php
  }
}
