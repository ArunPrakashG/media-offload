<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\License\LicenseManager;
use Nimbus\Plugin\Media\UploadHandler;
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
  private string $pageHook = '';

  public function __construct(
    private Settings $settings,
    private Logger $logger,
    private Statistics $statistics,
    private UploadHandler $uploadHandler,
    private OnboardingManager $onboardingManager,
  ) {
    $this->licenseManager = new LicenseManager();
    $this->providerCatalog = new ProviderCatalog();
    $this->fieldRenderer = new FieldRenderer($settings, $this->providerCatalog);
    $this->settingsRegistrar = new SettingsRegistrar($settings, $this->fieldRenderer);
    $this->pageRenderer = new PageRenderer($settings, $logger, $statistics, $uploadHandler, $this->settingsRegistrar, $this->licenseManager, $onboardingManager);
    $this->actionsController = new ActionsController($settings, $logger, $uploadHandler, $this->licenseManager, $onboardingManager);
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
    add_action('admin_notices', [$this, 'render_deactivation_hint']);
    add_filter('plugin_action_links_nimbus/nimbus.php', [$this, 'add_plugin_action_links']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION, [$this, 'handle_toggle_integration']);
    add_action('wp_ajax_' . NIMBUS_AJAX_TEST_CONNECTION, [$this, 'handle_test_connection']);
    add_action('wp_ajax_' . NIMBUS_AJAX_ONBOARDING_SAVE_STEP, [$this, 'handle_save_onboarding_step']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_CLEAR_LOGS, [$this, 'handle_clear_logs']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_RESUME_MIGRATION, [$this, 'handle_resume_migration']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES, [$this, 'handle_run_url_updates']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING, [$this, 'handle_complete_onboarding']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION, [$this, 'handle_rewrite_integration']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_EXPORT_CONFIG, [$this, 'handle_export_config']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_IMPORT_CONFIG, [$this, 'handle_import_config']);
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
      'assetRoots' => $this->statistics->get_attachment_roots(),
      'ajaxUrl' => admin_url('admin-ajax.php'),
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

  public function handle_export_config(): void
  {
    $this->actionsController->handleExportConfig();
  }

  public function handle_import_config(): void
  {
    $this->actionsController->handleImportConfig();
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
    // Show a warning if the plugin was re-activated after being deactivated
    // while DB-rewritten CDN URLs were still in place.
    $reactivationCount = (int) get_transient('nimbus_reactivation_url_warning');
    if ($reactivationCount > 0 && current_user_can('manage_options')) {
      delete_transient('nimbus_reactivation_url_warning');
      $toolsUrl = add_query_arg(['page' => NIMBUS_SLUG, 'tab' => 'tools'], admin_url('upload.php'));
      printf(
        '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
        esc_html__('Nimbus – URL integrity check recommended', NIMBUS_TEXT_DOMAIN),
        sprintf(
          /* translators: %1$s: file count, %2$s: Tools link */
          esc_html__('Nimbus was previously deactivated while %1$s media file(s) had CDN URLs written to the database. Those URLs may have been served broken while the plugin was inactive. Verify and resync them from %2$s.', NIMBUS_TEXT_DOMAIN),
          '<strong>' . esc_html(number_format_i18n($reactivationCount)) . '</strong>',
          '<a href="' . esc_url($toolsUrl) . '">' . esc_html__('Nimbus → Tools', NIMBUS_TEXT_DOMAIN) . '</a>'
        )
      );
    }

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

  /**
   * Notice shown on the Plugins screen when Nimbus has rewritten attachment URLs
   * into the database.  Deactivating without reverting first will leave broken
   * CDN URLs in post_content once the plugin is gone.
   */
  public function render_deactivation_hint(): void
  {
    if (!current_user_can('activate_plugins')) {
      return;
    }

    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'plugins') {
      return;
    }

    $offloaded = (int) get_transient('nimbus_offloaded_count_cache');
    if ($offloaded === 0) {
      global $wpdb;
      $offloaded = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        NIMBUS_META_KEY
      ));
      if ($offloaded > 0) {
        set_transient('nimbus_offloaded_count_cache', $offloaded, HOUR_IN_SECONDS);
      }
    }

    if ($offloaded === 0) {
      return;
    }

    $toolsUrl = add_query_arg(['page' => NIMBUS_SLUG, 'tab' => 'tools'], admin_url('upload.php'));
    ?>
    <div class="notice notice-warning nimbus-deactivation-hint">
      <p>
        <strong><?php esc_html_e('Nimbus – Before you deactivate', NIMBUS_TEXT_DOMAIN); ?></strong><br>
        <?php
        printf(
          /* translators: %1$s: offloaded file count, %2$s: Tools link */
          esc_html__('%1$s media file(s) have their URLs stored as CDN paths in the database. Deactivating Nimbus without reverting these first will break image delivery site-wide. Go to %2$s to revert all URLs back to local paths before deactivating.', NIMBUS_TEXT_DOMAIN),
          '<strong>' . esc_html(number_format_i18n($offloaded)) . '</strong>',
          '<a href="' . esc_url($toolsUrl) . '">' . esc_html__('Nimbus → Tools → Revert URLs', NIMBUS_TEXT_DOMAIN) . '</a>'
        );
        ?>
      </p>
    </div>
    <?php
  }

  /**
   * Adds a "⚠ Revert URLs first" action link next to Nimbus on the Plugins screen
   * when the DB contains CDN-rewritten attachments.
   *
   * @param array<string> $links Existing action links.
   * @return array<string>
   */
  public function add_plugin_action_links(array $links): array
  {
    $settingsUrl = add_query_arg(['page' => NIMBUS_SLUG], admin_url('upload.php'));
    array_unshift($links, '<a href="' . esc_url($settingsUrl) . '">' . esc_html__('Settings', NIMBUS_TEXT_DOMAIN) . '</a>');

    $offloaded = (int) get_transient('nimbus_offloaded_count_cache');
    if ($offloaded === 0) {
      global $wpdb;
      $offloaded = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        NIMBUS_META_KEY
      ));
    }

    if ($offloaded > 0) {
      $toolsUrl = add_query_arg(['page' => NIMBUS_SLUG, 'tab' => 'tools'], admin_url('upload.php'));
      $links[] = '<a href="' . esc_url($toolsUrl) . '" style="color:#d63638;font-weight:600;">'
        . esc_html__('⚠ Revert URLs before deactivating', NIMBUS_TEXT_DOMAIN)
        . '</a>';
    }

    return $links;
  }
}
