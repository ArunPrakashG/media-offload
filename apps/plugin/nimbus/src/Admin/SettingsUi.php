<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;
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
  private const RESTORE_DOCS_URL = 'https://usenimbus.dev/docs/guides/restoring-files/';
  private const DONATE_URL = 'https://buymeacoffee.com/arunprakashg';

  private ProviderCatalog $providerCatalog;
  private FieldRenderer $fieldRenderer;
  private SettingsRegistrar $settingsRegistrar;
  private PageRenderer $pageRenderer;
  private ActionsController $actionsController;
  private MediaLibraryController $mediaLibraryController;
  private string $pageHook = '';

  public function __construct(
    private Settings $settings,
    private Logger $logger,
    private Statistics $statistics,
    private UploadHandler $uploadHandler,
    private OnboardingManager $onboardingManager,
  ) {
    $this->providerCatalog = new ProviderCatalog();
    $this->fieldRenderer = new FieldRenderer($settings, $this->providerCatalog);
    $this->settingsRegistrar = new SettingsRegistrar($settings, $this->fieldRenderer);
    $this->pageRenderer = new PageRenderer($settings, $logger, $statistics, $uploadHandler, $this->settingsRegistrar, $onboardingManager);
    $this->actionsController = new ActionsController($settings, $logger, $uploadHandler, $onboardingManager);
    $this->mediaLibraryController = new MediaLibraryController($settings, $uploadHandler, $logger);
  }

  public function register(): void
  {
    add_action('admin_menu', [$this, 'add_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_init', [$this, 'handle_onboarding_redirect'], 5);
    add_action('admin_notices', [$this, 'render_admin_notices']);
    add_action('admin_notices', [$this, 'render_deactivation_hint']);
    add_filter('plugin_action_links_' . plugin_basename(NIMBUS_FILE), [$this, 'add_plugin_action_links']);
    add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION, [$this, 'handle_toggle_integration']);
    add_action('wp_ajax_' . NIMBUS_AJAX_TEST_CONNECTION, [$this, 'handle_test_connection']);
    add_action('wp_ajax_' . NIMBUS_AJAX_ONBOARDING_SAVE_STEP, [$this, 'handle_save_onboarding_step']);
    add_action('wp_ajax_' . NIMBUS_AJAX_QUICK_CONNECT, [$this, 'handle_quick_connect']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_CLEAR_LOGS, [$this, 'handle_clear_logs']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_RESUME_MIGRATION, [$this, 'handle_resume_migration']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES, [$this, 'handle_run_url_updates']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING, [$this, 'handle_complete_onboarding']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION, [$this, 'handle_rewrite_integration']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_EXPORT_CONFIG, [$this, 'handle_export_config']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_IMPORT_CONFIG, [$this, 'handle_import_config']);
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
        'showAdvanced' => __('Show advanced connection fields', 'usenimbus-media-offload'),
        'hideAdvanced' => __('Hide advanced connection fields', 'usenimbus-media-offload'),
        'noExtraCredentials' => __('No extra credentials required', 'usenimbus-media-offload'),
        'providerNoBucket' => __('No bucket required in local mode', 'usenimbus-media-offload'),
        'providerBucketNotSet' => __('Bucket not set', 'usenimbus-media-offload'),
        'deliveryLocalDebug' => __('Local debug', 'usenimbus-media-offload'),
        'deliveryRemote' => __('Remote', 'usenimbus-media-offload'),
        'deliveryLocal' => __('Local', 'usenimbus-media-offload'),
        'deliveryNoCustomDomain' => __('No custom domain configured', 'usenimbus-media-offload'),
        /* translators: this string is used as a client-side JS template; 1: allowed-pattern count, 2: ignored-pattern count */
        'rulesTemplate' => __('Allowed: %1$d · Ignored: %2$d', 'usenimbus-media-offload'),
        'batchOn' => __('On', 'usenimbus-media-offload'),
        'batchOff' => __('Off', 'usenimbus-media-offload'),
        'batchNoSchedule' => __('No schedule set', 'usenimbus-media-offload'),
        'shellLocalDebugMode' => __('Local debug mode', 'usenimbus-media-offload'),
        'shellServingStorage' => __('Serving from storage', 'usenimbus-media-offload'),
        'shellServingLocal' => __('Serving from local URLs', 'usenimbus-media-offload'),
        'testing' => __('Testing…', 'usenimbus-media-offload'),
        'testingMessage' => __('Testing connection…', 'usenimbus-media-offload'),
        'testConnection' => __('Test Connection', 'usenimbus-media-offload'),
        'serverError' => __('Server error occurred.', 'usenimbus-media-offload'),
        'detailsHeading' => __('Connection details', 'usenimbus-media-offload'),
        'hintsHeading' => __('Suggested checks', 'usenimbus-media-offload'),
        'jobStartError' => __('Unable to start the requested bulk job.', 'usenimbus-media-offload'),
        'jobPauseError' => __('Unable to pause the current bulk job.', 'usenimbus-media-offload'),
        'jobResumeError' => __('Unable to resume the current bulk job.', 'usenimbus-media-offload'),
        'jobRetryError' => __('Unable to retry failed items right now.', 'usenimbus-media-offload'),
      ],
      'providers' => $this->providerCatalog->scriptData(),
      'assetRoots' => $this->statistics->get_attachment_roots(),
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'onboarding' => [
        'saveStepAction' => NIMBUS_AJAX_ONBOARDING_SAVE_STEP,
        'saveStepNonce'  => wp_create_nonce(NIMBUS_AJAX_ONBOARDING_SAVE_STEP),
        'quickConnectAction' => NIMBUS_AJAX_QUICK_CONNECT,
        'quickConnectNonce' => wp_create_nonce(NIMBUS_AJAX_QUICK_CONNECT),
      ],
    ];

    wp_localize_script(NIMBUS_SCRIPT_HANDLE, NIMBUS_SCRIPT_OBJECT, $scriptData);
  }

  public function handle_test_connection(): void
  {
    $this->actionsController->handleTestConnection();
  }

  public function handle_quick_connect(): void
  {
    $this->actionsController->handleQuickConnect();
  }

  public function handle_save_onboarding_step(): void
  {
    $this->actionsController->handleSaveOnboardingStep();
  }

  public function add_menu(): void
  {
    // The cloud glyph from design/brand/glyph.svg, inline so it follows the menu text color.
    $glyph = '<svg class="nmb-menu-glyph" viewBox="6 6 20 20" width="13" height="13" aria-hidden="true" style="vertical-align:-1px;margin-right:6px"><g fill="currentColor" transform="translate(0 1)"><circle cx="11.5" cy="16.5" r="4.5"/><circle cx="18.5" cy="13" r="6.5"/><path d="M7 16.5H21.75A3.25 3.25 0 0 1 21.75 23H7Z"/></g></svg>';

    $hook = add_media_page(
      __('Nimbus', 'usenimbus-media-offload'),
      $glyph . esc_html__('Nimbus', 'usenimbus-media-offload'),
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
        esc_html__('Nimbus – URL integrity check recommended', 'usenimbus-media-offload'),
        sprintf(
          /* translators: %1$s: file count, %2$s: Tools link */
          esc_html__('Nimbus was previously deactivated while %1$s media file(s) had CDN URLs written to the database. Those URLs may have been served broken while the plugin was inactive. Verify and resync them from %2$s.', 'usenimbus-media-offload'),
          '<strong>' . esc_html(number_format_i18n($reactivationCount)) . '</strong>',
          '<a href="' . esc_url($toolsUrl) . '">' . esc_html__('Nimbus → Tools', 'usenimbus-media-offload') . '</a>'
        )
      );
    }

    $notices = popAdminNotices();
    if (empty($notices)) {
      return;
    }

    foreach ($notices as $notice) {
      $class = 'notice notice-' . esc_attr($notice['type']);
      printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($notice['message']));
    }
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
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Result is cached via the transient above/below; this only runs on a cache miss.
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
        <strong><?php esc_html_e('Nimbus – Before you deactivate', 'usenimbus-media-offload'); ?></strong><br>
        <?php
        printf(
          /* translators: %1$s: offloaded file count, %2$s: Tools link, %3$s: WP-CLI command, %4$s: docs link */
          esc_html__('%1$s media file(s) are offloaded to your bucket. Deactivating Nimbus without restoring them can break images across the site. First run Restore local copies from %2$s, then run %3$s to put post content back on local URLs. See %4$s.', 'usenimbus-media-offload'),
          '<strong>' . esc_html(number_format_i18n($offloaded)) . '</strong>',
          '<a href="' . esc_url($toolsUrl) . '">' . esc_html__('Nimbus → Tools', 'usenimbus-media-offload') . '</a>',
          '<code>wp nimbus revert</code>',
          '<a href="' . esc_url(self::RESTORE_DOCS_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Restoring files', 'usenimbus-media-offload') . '</a>'
        );
        ?>
      </p>
    </div>
    <?php
  }

  /**
   * Adds a "⚠ Restore files first" action link next to Nimbus on the Plugins screen
   * when the DB contains CDN-rewritten attachments.
   *
   * @param array<string> $links Existing action links.
   * @return array<string>
   */
  /**
   * Adds Docs and Donate links under the plugin description on the Plugins screen.
   *
   * @param array<string> $links Existing row meta links.
   * @return array<string>
   */
  public function add_plugin_row_meta(array $links, string $file): array
  {
    if ($file !== plugin_basename(NIMBUS_FILE)) {
      return $links;
    }

    $links[] = '<a href="https://usenimbus.dev/docs/" target="_blank" rel="noopener noreferrer">' . esc_html__('Docs', 'usenimbus-media-offload') . '</a>';
    $links[] = '<a href="' . esc_url(self::DONATE_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Buy me a coffee', 'usenimbus-media-offload') . '</a>';

    return $links;
  }

  public function add_plugin_action_links(array $links): array
  {
    $settingsUrl = add_query_arg(['page' => NIMBUS_SLUG], admin_url('upload.php'));
    array_unshift($links, '<a href="' . esc_url($settingsUrl) . '">' . esc_html__('Settings', 'usenimbus-media-offload') . '</a>');

    $offloaded = (int) get_transient('nimbus_offloaded_count_cache');
    if ($offloaded === 0) {
      global $wpdb;
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Result is cached via the transient above/below; this only runs on a cache miss.
      $offloaded = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        NIMBUS_META_KEY
      ));
      if ($offloaded > 0) {
        set_transient('nimbus_offloaded_count_cache', $offloaded, HOUR_IN_SECONDS);
      }
    }

    if ($offloaded > 0) {
      $toolsUrl = add_query_arg(['page' => NIMBUS_SLUG, 'tab' => 'tools'], admin_url('upload.php'));
      $links[] = '<a href="' . esc_url($toolsUrl) . '" style="color:#d63638;font-weight:600;">'
        . esc_html__('⚠ Restore files before deactivating', 'usenimbus-media-offload')
        . '</a>';
    }

    return $links;
  }
}
