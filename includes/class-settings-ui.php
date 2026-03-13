<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

class Settings_UI
{
  private const MISSING_SOURCE_AT_META_KEY = '_pt_media_offload_missing_source_at';

  private Settings $settings;

  private Logger $logger;

  private Statistics $statistics;

  private Upload_Handler $upload_handler;

  private string $page_hook = '';

  public function __construct(Settings $settings, Logger $logger, Statistics $statistics, Upload_Handler $upload_handler)
  {
    $this->settings = $settings;
    $this->logger = $logger;
    $this->statistics = $statistics;
    $this->upload_handler = $upload_handler;
  }

  public function register(): void
  {
    add_action('admin_menu', [$this, 'add_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_notices', [$this, 'render_admin_notices']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_ajax_pt_media_offload_test_connection', [$this, 'handle_test_connection']);
    add_action('admin_post_pt_media_offload_clear_logs', [$this, 'handle_clear_logs']);
    add_action('admin_post_pt_media_offload_run_url_updates', [$this, 'handle_run_url_updates']);
  }

  public function enqueue_assets($hook): void
  {
    if (!$this->page_hook || $hook !== $this->page_hook) {
      return;
    }
    wp_enqueue_style('pt-media-offload-admin', PT_MEDIA_OFFLOAD_URL . 'assets/css/admin.css', [], PT_MEDIA_OFFLOAD_VERSION);
    wp_enqueue_script('pt-media-offload-admin', PT_MEDIA_OFFLOAD_URL . 'assets/js/admin.js', ['jquery'], PT_MEDIA_OFFLOAD_VERSION, true);
    wp_localize_script('pt-media-offload-admin', 'pt_media_offload_vars', [
      'nonce' => wp_create_nonce('pt_media_offload_test_connection'),
      'strings' => [
        'showAdvanced' => __('Show advanced connection fields', 'pt-media-offload'),
        'hideAdvanced' => __('Hide advanced connection fields', 'pt-media-offload'),
      ],
      'providers' => [
        's3' => [
          'title' => __('Amazon S3 / Compatible', 'pt-media-offload'),
          'summary' => __('Use this for AWS S3 or any S3-compatible storage that expects a standard region and optional custom endpoint.', 'pt-media-offload'),
          'required' => [
            __('Bucket', 'pt-media-offload'),
            __('Region', 'pt-media-offload'),
            __('Access Key', 'pt-media-offload'),
            __('Secret Key', 'pt-media-offload'),
          ],
        ],
        'r2' => [
          'title' => __('Cloudflare R2', 'pt-media-offload'),
          'summary' => __('R2 only needs your bucket, account ID, access key, and secret. The plugin will auto-use region=auto, derive the endpoint, and force path-style signing.', 'pt-media-offload'),
          'required' => [
            __('Bucket', 'pt-media-offload'),
            __('R2 Account ID', 'pt-media-offload'),
            __('Access Key', 'pt-media-offload'),
            __('Secret Key', 'pt-media-offload'),
          ],
        ],
        'local' => [
          'title' => __('Local / Debug Mode', 'pt-media-offload'),
          'summary' => __('Use local mode only for debugging. Remote storage credentials and delivery options are hidden because they are not needed.', 'pt-media-offload'),
          'required' => [
            __('Provider', 'pt-media-offload'),
          ],
        ],
      ],
    ]);
  }

  public function handle_test_connection(): void
  {
    check_ajax_referer('pt_media_offload_test_connection', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Permission denied.']);
    }

    $factory = new Storage_Adapter_Factory($this->settings, $this->logger);

    try {
      $adapter = $factory->get_adapter();
      if (method_exists($adapter, 'test_connection')) {
        if ($adapter->test_connection()) {
          wp_send_json_success(['message' => 'Connection successful! Write access verified.']);
        } else {
          wp_send_json_error(['message' => 'Connection failed. Check logs for details.']);
        }
      } else {
        wp_send_json_error(['message' => 'Adapter does not support connection testing.']);
      }
    } catch (\Exception $e) {
      wp_send_json_error(['message' => 'Connection failed: ' . $e->getMessage()]);
    }
  }

  public function add_menu(): void
  {
    $hook = add_media_page(
      __('Media Offload', 'pt-media-offload'),
      __('Media Offload', 'pt-media-offload'),
      'manage_options',
      'pt-media-offload',
      [$this, 'render_page']
    );

    if ($hook) {
      $this->page_hook = $hook;
    }
  }

  public function register_settings(): void
  {
    register_setting('pt_media_offload', PT_MEDIA_OFFLOAD_OPTION_KEY, [$this, 'sanitize_settings']);

    add_settings_section(
      'pt_media_offload_connection',
      __('Storage Connection', 'pt-media-offload'),
      function () {
        echo '<p>' . esc_html__('Configure the object storage provider used for offloading media uploads.', 'pt-media-offload') . '</p>';
      },
      'pt-media-offload'
    );

    add_settings_field(
      'pt_media_offload_enabled',
      __('Enable Offloading', 'pt-media-offload'),
      [$this, 'render_toggle'],
      'pt-media-offload',
      'pt_media_offload_connection',
      ['key' => 'enabled', 'label' => __('Activate media offloading workflow.', 'pt-media-offload')]
    );

    add_settings_field(
      'pt_media_offload_provider',
      __('Provider', 'pt-media-offload'),
      [$this, 'render_provider_field'],
      'pt-media-offload',
      'pt_media_offload_connection'
    );

    add_settings_field(
      'pt_media_offload_bucket',
      __('Bucket', 'pt-media-offload'),
      [$this, 'render_text_field'],
      'pt-media-offload',
      'pt_media_offload_connection',
      ['key' => 'bucket', 'placeholder' => 'my-media-bucket', 'required' => true]
    );

    add_settings_field(
      'pt_media_offload_r2_account_id',
      __('R2 Account ID', 'pt-media-offload'),
      [$this, 'render_text_field'],
      'pt-media-offload',
      'pt_media_offload_connection',
      [
        'key' => 'r2_account_id',
        'placeholder' => '2648c6dbb6901794166cdf02b48b8aa2',
        'description' => __('For Cloudflare R2, enter the account ID used to build the endpoint. Optional if Custom Endpoint is provided.', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_region',
      __('Region', 'pt-media-offload'),
      [$this, 'render_text_field'],
      'pt-media-offload',
      'pt_media_offload_connection',
      [
        'key' => 'region',
        'required' => true,
        'placeholder' => 'ap-south-1 / auto',
        'description' => __('Use your AWS region for S3. For Cloudflare R2 use "auto".', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_access_key',
      __('Access Key', 'pt-media-offload'),
      [$this, 'render_text_field'],
      'pt-media-offload',
      'pt_media_offload_connection',
      [
        'key' => 'access_key',
        'required' => true,
        'description' => __('For R2, use the Access Key ID from your Cloudflare R2 API token.', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_secret_key',
      __('Secret Key', 'pt-media-offload'),
      [$this, 'render_password_field'],
      'pt-media-offload',
      'pt_media_offload_connection',
      [
        'key' => 'secret_key',
        'required' => true,
        'description' => __('For R2, use the Secret Access Key from your Cloudflare R2 API token.', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_session_token',
      __('Session Token (optional)', 'pt-media-offload'),
      [$this, 'render_password_field'],
      'pt-media-offload',
      'pt_media_offload_connection',
      [
        'key' => 'session_token',
        'description' => __('Use only for temporary/session S3 credentials. Leave empty for Cloudflare R2 API keys.', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_prefix',
      __('Path Prefix', 'pt-media-offload'),
      [$this, 'render_text_field'],
      'pt-media-offload',
      'pt_media_offload_connection',
      ['key' => 'prefix', 'placeholder' => 'media/uploads']
    );

    add_settings_field(
      'pt_media_offload_endpoint',
      __('Custom Endpoint', 'pt-media-offload'),
      [$this, 'render_text_field'],
      'pt-media-offload',
      'pt_media_offload_connection',
      [
        'key' => 'endpoint',
        'placeholder' => 'https://<account_id>.r2.cloudflarestorage.com',
        'description' => __('Optional endpoint for S3-compatible storage. For R2 use the account endpoint (without bucket path).', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_path_style',
      __('Use Path Style', 'pt-media-offload'),
      [$this, 'render_toggle'],
      'pt-media-offload',
      'pt_media_offload_connection',
      ['key' => 'use_path_style_endpoint', 'label' => __('Enable for providers that require path-style URLs.', 'pt-media-offload')]
    );

    add_settings_section(
      'pt_media_offload_delivery',
      __('Delivery', 'pt-media-offload'),
      function () {
        echo '<p>' . esc_html__('Control how WordPress serves media once uploaded.', 'pt-media-offload') . '</p>';
      },
      'pt-media-offload'
    );

    add_settings_field(
      'pt_media_offload_serve_from_storage',
      __('Serve From Storage', 'pt-media-offload'),
      [$this, 'render_toggle'],
      'pt-media-offload',
      'pt_media_offload_delivery',
      ['key' => 'serve_from_storage', 'label' => __('Rewrite media URLs to point at the object storage.', 'pt-media-offload')]
    );

    add_settings_field(
      'pt_media_offload_custom_domain',
      __('Custom Domain', 'pt-media-offload'),
      [$this, 'render_text_field'],
      'pt-media-offload',
      'pt_media_offload_delivery',
      ['key' => 'custom_domain', 'placeholder' => 'https://cdn.example.com']
    );

    add_settings_field(
      'pt_media_offload_remove_local',
      __('Remove Local Copies', 'pt-media-offload'),
      [$this, 'render_toggle'],
      'pt-media-offload',
      'pt_media_offload_delivery',
      ['key' => 'remove_local_copies', 'label' => __('Delete local files after successful offload (use caution).', 'pt-media-offload')]
    );

    add_settings_field(
      'pt_media_offload_purge_cloudflare_cache',
      __('Purge Cloudflare Cache on Delete', 'pt-media-offload'),
      [$this, 'render_toggle'],
      'pt-media-offload',
      'pt_media_offload_delivery',
      ['key' => 'purge_cloudflare_cache_on_delete', 'label' => __('Purge CDN URLs from Cloudflare when media attachments are deleted.', 'pt-media-offload')]
    );

    add_settings_field(
      'pt_media_offload_cloudflare_zone_id',
      __('Cloudflare Zone ID', 'pt-media-offload'),
      [$this, 'render_text_field'],
      'pt-media-offload',
      'pt_media_offload_delivery',
      [
        'key' => 'cloudflare_zone_id',
        'placeholder' => 'your_zone_id',
        'description' => __('Required only for Cloudflare purge. Find this in Cloudflare dashboard → Overview.', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_cloudflare_api_token',
      __('Cloudflare API Token', 'pt-media-offload'),
      [$this, 'render_password_field'],
      'pt-media-offload',
      'pt_media_offload_delivery',
      [
        'key' => 'cloudflare_api_token',
        'description' => __('Required only for Cloudflare purge. Token must include Zone:Cache Purge permission.', 'pt-media-offload'),
      ]
    );

    add_settings_section(
      'pt_media_offload_path_filters',
      __('Path Filters', 'pt-media-offload'),
      function () {
        echo '<p>' . esc_html__('Limit which uploads paths are eligible for offload. Patterns are relative to the uploads root and support wildcards plus {year}/{month}/{day} placeholders.', 'pt-media-offload') . '</p>';
      },
      'pt-media-offload'
    );

    add_settings_field(
      'pt_media_offload_allowed_paths',
      __('Allowed Paths', 'pt-media-offload'),
      [$this, 'render_textarea_field'],
      'pt-media-offload',
      'pt_media_offload_path_filters',
      [
        'key' => 'allowed_path_patterns',
        'rows' => 4,
        'placeholder' => "{year}/**\nsites/*/{year}/**",
        'description' => __('Optional allow-list. One pattern per line. Leave empty to allow all uploads paths. Example: {year}/** will only offload standard year folders.', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_ignored_paths',
      __('Ignored Paths', 'pt-media-offload'),
      [$this, 'render_textarea_field'],
      'pt-media-offload',
      'pt_media_offload_path_filters',
      [
        'key' => 'ignored_path_patterns',
        'rows' => 4,
        'placeholder' => "cache/**\nsmush/**",
        'description' => __('Optional ignore list. Matching files are always kept out of sync/offload even if they match Allowed Paths.', 'pt-media-offload'),
      ]
    );

    add_settings_section(
      'pt_media_offload_off_hours',
      __('Off-hours Batch Offload', 'pt-media-offload'),
      function () {
        echo '<p>' . esc_html__('Queue uploads during the day and offload them in one nightly run.', 'pt-media-offload') . '</p>';
      },
      'pt-media-offload'
    );

    add_settings_field(
      'pt_media_offload_off_hours_enabled',
      __('Enable Off-hours Batch', 'pt-media-offload'),
      [$this, 'render_toggle'],
      'pt-media-offload',
      'pt_media_offload_off_hours',
      ['key' => 'off_hours_batch_enabled', 'label' => __('Run media offload only during off-hours batch window.', 'pt-media-offload')]
    );

    add_settings_field(
      'pt_media_offload_off_hours_time',
      __('Batch Time', 'pt-media-offload'),
      [$this, 'render_time_field'],
      'pt-media-offload',
      'pt_media_offload_off_hours',
      [
        'key' => 'off_hours_batch_time',
        'description' => __('Daily local-site time to run the off-hours batch (example: 03:00).', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_off_hours_workers',
      __('Worker Count', 'pt-media-offload'),
      [$this, 'render_number_field'],
      'pt-media-offload',
      'pt_media_offload_off_hours',
      [
        'key' => 'off_hours_worker_count',
        'min' => 1,
        'max' => 8,
        'step' => 1,
        'description' => __('Number of queue workers to dispatch per off-hours run. Increase carefully based on R2 rate limits.', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_off_hours_worker_batch_size',
      __('Per-worker Batch Size', 'pt-media-offload'),
      [$this, 'render_number_field'],
      'pt-media-offload',
      'pt_media_offload_off_hours',
      [
        'key' => 'off_hours_worker_batch_size',
        'min' => 10,
        'max' => 1000,
        'step' => 10,
        'description' => __('Maximum queue items a worker handles per run.', 'pt-media-offload'),
      ]
    );

    add_settings_field(
      'pt_media_offload_off_hours_claim_ttl',
      __('Claim TTL (seconds)', 'pt-media-offload'),
      [$this, 'render_number_field'],
      'pt-media-offload',
      'pt_media_offload_off_hours',
      [
        'key' => 'off_hours_claim_ttl_seconds',
        'min' => 60,
        'max' => 86400,
        'step' => 60,
        'description' => __('How long a worker claim is considered valid before another worker can recover it.', 'pt-media-offload'),
      ]
    );
  }

  public function sanitize_settings($input)
  {
    if (!is_array($input)) {
      $input = [];
    }

    // Handle checkboxes which are not sent when unchecked
    $checkboxes = ['enabled', 'off_hours_batch_enabled', 'use_path_style_endpoint', 'remove_local_copies', 'serve_from_storage', 'purge_cloudflare_cache_on_delete'];
    foreach ($checkboxes as $key) {
      if (!isset($input[$key])) {
        $input[$key] = false;
      }
    }

    // Merge with current settings to preserve any values not in the form
    $current = $this->settings->all();
    $merged = array_merge($current, $input);

    // Sanitize and return - WordPress will handle the update_option call
    $sanitized = $this->settings->sanitize($merged);

    // Validation: If enabled and using S3/R2, ensure required fields are present.
    if ($sanitized['enabled'] && in_array($sanitized['provider'], ['s3', 'r2'], true)) {
      $missing = [];
      if (empty($sanitized['bucket'])) $missing[] = __('Bucket', 'pt-media-offload');
      if (empty($sanitized['access_key'])) $missing[] = __('Access Key', 'pt-media-offload');
      if (empty($sanitized['secret_key'])) $missing[] = __('Secret Key', 'pt-media-offload');

      if ($sanitized['provider'] === 's3' && empty($sanitized['region'])) {
        $missing[] = __('Region', 'pt-media-offload');
      }

      if ($sanitized['provider'] === 'r2' && empty($sanitized['r2_account_id']) && empty($sanitized['endpoint'])) {
        $missing[] = __('R2 Account ID or Custom Endpoint', 'pt-media-offload');
      }

      if (!empty($missing)) {
        $sanitized['enabled'] = false;
        add_settings_error(
          'pt_media_offload',
          'pt_media_offload_missing_creds',
          sprintf(__('Cannot enable offloading. Missing required fields: %s', 'pt-media-offload'), implode(', ', $missing)),
          'error'
        );
      }
    }

    if (!empty($sanitized['purge_cloudflare_cache_on_delete'])) {
      if (empty($sanitized['cloudflare_zone_id']) || empty($sanitized['cloudflare_api_token'])) {
        $sanitized['purge_cloudflare_cache_on_delete'] = false;
        add_settings_error(
          'pt_media_offload',
          'pt_media_offload_missing_cf_purge',
          __('Cloudflare purge was disabled because Zone ID or API token is missing.', 'pt-media-offload'),
          'error'
        );
      }
    }

    add_settings_error('pt_media_offload', 'pt_media_offload_saved', __('Media offload settings saved.', 'pt-media-offload'), 'updated');
    return $sanitized;
  }

  public function render_page(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    if (isset($_GET['ptmo_logs_cleared']) && $_GET['ptmo_logs_cleared'] === '1') {
      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Plugin logs cleared.', 'pt-media-offload') . '</p></div>';
    }

    $active_tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'dashboard';
    ?>
    <div class="wrap pt-media-offload-settings">
      <h1><?php esc_html_e('Media Offload', 'pt-media-offload'); ?></h1>
      
      <h2 class="nav-tab-wrapper">
        <a href="?page=pt-media-offload&tab=dashboard" class="nav-tab <?php echo $active_tab == 'dashboard' ? 'nav-tab-active' : ''; ?>">Dashboard</a>
        <a href="?page=pt-media-offload&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
        <a href="?page=pt-media-offload&tab=url-updates" class="nav-tab <?php echo $active_tab == 'url-updates' ? 'nav-tab-active' : ''; ?>">URL Updates</a>
        <a href="?page=pt-media-offload&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
      </h2>

      <?php if ($active_tab == 'dashboard'): ?>
        <?php $this->render_dashboard(); ?>
      <?php elseif ($active_tab == 'url-updates'): ?>
        <?php $this->render_url_updates_tab(); ?>
      <?php elseif ($active_tab == 'logs'): ?>
        <?php $this->render_logs(); ?>
      <?php else: ?>
        <?php $this->render_settings_tab(); ?>
      <?php endif; ?>
    </div>
    <?php
  }

  private function render_settings_tab(): void
  {
    ?>
    <div class="pt-mo-settings-shell">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Provider-aware setup', 'pt-media-offload'); ?></p>
          <h2><?php esc_html_e('Only the fields you need are shown', 'pt-media-offload'); ?></h2>
          <p><?php esc_html_e('Switch providers and this screen will show only the required connection fields. Optional endpoint and signing fields stay tucked away unless you actually need them.', 'pt-media-offload'); ?></p>
          <div id="pt-mo-provider-summary" class="pt-mo-provider-summary" aria-live="polite">
            <h3 id="pt-mo-provider-summary-title"><?php esc_html_e('Storage provider', 'pt-media-offload'); ?></h3>
            <p id="pt-mo-provider-summary-copy"><?php esc_html_e('Choose a provider to see the required fields.', 'pt-media-offload'); ?></p>
            <div id="pt-mo-provider-required-fields" class="pt-mo-chip-list"></div>
          </div>
        </div>

        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('CLI quick configure', 'pt-media-offload'); ?></p>
          <h2><?php esc_html_e('Repeatable setup on production', 'pt-media-offload'); ?></h2>
          <p><?php esc_html_e('Use the new WP-CLI config command when you want a reliable, copy-pasteable setup for cron workers and manual runs.', 'pt-media-offload'); ?></p>
          <div class="pt-mo-code-list">
            <code>wp pt-media-offload config --show</code>
            <code>wp pt-media-offload config --provider=r2 --bucket=your-bucket --r2-account-id=your-account-id --access-key=your-access-key --secret-key=your-secret-key --enabled=1 --serve-from-storage=1 --test-connection</code>
          </div>
        </div>
      </div>

      <form method="post" action="options.php" autocomplete="off" class="pt-mo-settings-form">
        <?php
        settings_fields('pt_media_offload');
        do_settings_sections('pt-media-offload');
        submit_button(__('Save Changes', 'pt-media-offload'));
        ?>
      </form>
    </div>
    <?php
  }

  public function handle_clear_logs(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'pt-media-offload'));
    }

    check_admin_referer('pt_media_offload_clear_logs');
    $this->logger->clear_entries();

    wp_safe_redirect(admin_url('upload.php?page=pt-media-offload&tab=logs&ptmo_logs_cleared=1'));
    exit;
  }

  public function handle_run_url_updates(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Permission denied.', 'pt-media-offload'));
    }

    check_admin_referer('pt_media_offload_run_url_updates');

    $mode = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : 'single';
    if (!in_array($mode, ['single', 'full'], true)) {
      $mode = 'single';
    }

    if (!$this->settings->is_enabled()) {
      $this->redirect_to_url_updates([
        'ptmo_url_update' => 'error',
        'ptmo_url_update_reason' => 'disabled',
      ]);
    }

    if (!$this->settings->has_valid_credentials()) {
      $this->redirect_to_url_updates([
        'ptmo_url_update' => 'error',
        'ptmo_url_update_reason' => 'credentials',
      ]);
    }

    if (!$this->can_connect_storage()) {
      $this->redirect_to_url_updates([
        'ptmo_url_update' => 'error',
        'ptmo_url_update_reason' => 'connection',
      ]);
    }

    $pending_before = $this->count_pending_url_updates();
    if ($pending_before <= 0) {
      $this->redirect_to_url_updates([
        'ptmo_url_update' => '1',
        'ptmo_url_update_mode' => $mode,
        'ptmo_url_update_status' => 'info',
        'ptmo_url_update_processed' => 0,
        'ptmo_url_update_success' => 0,
        'ptmo_url_update_failed' => 0,
        'ptmo_url_update_remaining' => 0,
      ]);
    }

    $result = $this->run_url_updates($mode === 'single' ? 1 : 0);
    $remaining = $this->count_pending_url_updates();
    $status = $result['failed'] > 0 ? 'warning' : 'success';

    $this->redirect_to_url_updates([
      'ptmo_url_update' => '1',
      'ptmo_url_update_mode' => $mode,
      'ptmo_url_update_status' => $status,
      'ptmo_url_update_processed' => $result['processed'],
      'ptmo_url_update_success' => $result['success'],
      'ptmo_url_update_failed' => $result['failed'],
      'ptmo_url_update_remaining' => $remaining,
    ]);
  }

  private function render_dashboard(): void
  {
    $stats = $this->statistics->get_stats();
    $usage = $this->statistics->get_disk_usage_estimate();
    $remote_stats = $this->statistics->get_remote_stats();
    
    $s3_size = isset($remote_stats['size_bytes']) ? $remote_stats['size_bytes'] : $usage['offloaded_size'];
    $s3_cost = isset($remote_stats['estimated_cost']) ? $remote_stats['estimated_cost'] : ($s3_size / 1073741824 * 0.023);
    $s3_count = isset($remote_stats['object_count']) ? $remote_stats['object_count'] : $stats['offloaded'];
    ?>
    <div class="pt-mo-dashboard">
      <div class="pt-mo-grid">
        <!-- Stats Cards -->
        <div class="pt-mo-card">
          <h3>Total Media</h3>
          <div class="pt-mo-stat"><?php echo number_format($stats['total']); ?></div>
          <div class="pt-mo-substat">Attachments in Library</div>
        </div>
        <div class="pt-mo-card">
          <h3>Offloaded</h3>
          <div class="pt-mo-stat"><?php echo number_format($stats['offloaded']); ?></div>
          <div class="pt-mo-progress">
            <div class="pt-mo-bar" style="width: <?php echo $stats['percent']; ?>%"></div>
          </div>
          <div class="pt-mo-substat"><?php echo $stats['percent']; ?>% Complete</div>
        </div>
        <div class="pt-mo-card">
          <h3>S3 Storage</h3>
          <div class="pt-mo-stat"><?php echo size_format($s3_size); ?></div>
          <div class="pt-mo-substat"><?php echo number_format($s3_count); ?> objects</div>
        </div>
        <div class="pt-mo-card">
          <h3>Est. Monthly Cost</h3>
          <div class="pt-mo-stat">$<?php echo number_format($s3_cost, 2); ?></div>
          <div class="pt-mo-substat">Based on $0.023/GB</div>
        </div>
      </div>

      <div class="pt-mo-grid-2">
        <!-- Daily Uploads Chart (Simple CSS Bar Chart) -->
        <div class="pt-mo-card">
          <h3>Uploads (Last 7 Days)</h3>
          <div class="pt-mo-chart">
            <?php if (empty($stats['daily'])): ?>
              <p>No uploads in the last 7 days.</p>
            <?php else: ?>
              <?php 
                $max = max(array_column($stats['daily'], 'count')); 
                foreach ($stats['daily'] as $day):
                  $height = ($day->count / $max) * 100;
              ?>
                <div class="pt-mo-bar-group" title="<?php echo $day->date . ': ' . $day->count; ?>">
                  <div class="pt-mo-chart-bar" style="height: <?php echo $height; ?>%"></div>
                  <div class="pt-mo-chart-label"><?php echo date('M d', strtotime($day->date)); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- System Status -->
        <div class="pt-mo-card">
          <h3>System Status</h3>
          <ul class="pt-mo-status-list">
            <li>
              <span class="dashicons dashicons-yes-alt status-ok"></span>
              <strong>Plugin Enabled:</strong> <?php echo $this->settings->is_enabled() ? 'Yes' : 'No'; ?>
            </li>
            <li>
              <span class="dashicons dashicons-cloud status-ok"></span>
              <strong>Provider:</strong> <?php echo strtoupper($this->settings->provider()); ?>
            </li>
            <li>
              <span class="dashicons dashicons-database status-ok"></span>
              <strong>Bucket:</strong> <?php echo esc_html($this->settings->get('bucket')); ?>
            </li>
            <li>
              <span class="dashicons dashicons-admin-links status-ok"></span>
              <strong>Rewrite URLs:</strong> <?php echo $this->settings->should_serve_from_storage() ? 'Yes' : 'No'; ?>
            </li>
          </ul>
          <div class="pt-mo-actions">
             <button type="button" id="pt-mo-test-connection" class="button button-secondary">Test Connection</button>
             <span id="pt-mo-connection-status" style="margin-left: 10px;"></span>
          </div>
        </div>
      </div>
    </div>
    <?php
  }

  private function render_logs(): void
  {
    $entries = $this->logger->get_entries(500);
    ?>
    <div class="pt-mo-card">
      <h3><?php esc_html_e('Plugin Logs', 'pt-media-offload'); ?></h3>
      <p><?php esc_html_e('Shows recent activity emitted by this plugin.', 'pt-media-offload'); ?></p>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 12px 0;">
        <?php wp_nonce_field('pt_media_offload_clear_logs'); ?>
        <input type="hidden" name="action" value="pt_media_offload_clear_logs" />
        <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Clear all plugin logs?', 'pt-media-offload')); ?>');">
          <?php esc_html_e('Clear Logs', 'pt-media-offload'); ?>
        </button>
      </form>

      <?php if (empty($entries)): ?>
        <p><?php esc_html_e('No log entries yet.', 'pt-media-offload'); ?></p>
      <?php else: ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th style="width:180px;"><?php esc_html_e('Time', 'pt-media-offload'); ?></th>
              <th style="width:90px;"><?php esc_html_e('Level', 'pt-media-offload'); ?></th>
              <th><?php esc_html_e('Message', 'pt-media-offload'); ?></th>
              <th><?php esc_html_e('Context', 'pt-media-offload'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entries as $entry): ?>
              <tr>
                <td><?php echo esc_html((string) ($entry['datetime'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($entry['level'] ?? 'INFO')); ?></td>
                <td><?php echo esc_html((string) ($entry['message'] ?? '')); ?></td>
                <td><code><?php echo esc_html(wp_json_encode($entry['context'] ?? [])); ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
  }

  private function render_url_updates_tab(): void
  {
    $pending = $this->count_pending_url_updates();
    $stats = $this->statistics->get_stats();
    $is_enabled = $this->settings->is_enabled();
    $has_credentials = $this->settings->has_valid_credentials();
    ?>
    <div class="pt-mo-dashboard">
      <?php if (isset($_GET['ptmo_url_update']) && (string) $_GET['ptmo_url_update'] === 'error'): ?>
        <?php
        $reason = isset($_GET['ptmo_url_update_reason']) ? sanitize_key((string) wp_unslash($_GET['ptmo_url_update_reason'])) : '';
        $message = __('URL update could not be started.', 'pt-media-offload');

        if ($reason === 'disabled') {
          $message = __('Media offload is currently disabled. Enable it first in Settings.', 'pt-media-offload');
        } elseif ($reason === 'credentials') {
          $message = __('Missing or invalid storage credentials. Please verify your settings first.', 'pt-media-offload');
        } elseif ($reason === 'connection') {
          $message = __('Storage connection test failed (authentication/signing). Verify provider, endpoint, region, and keys before retrying.', 'pt-media-offload');
        }
        ?>
        <div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
      <?php elseif (isset($_GET['ptmo_url_update']) && (string) $_GET['ptmo_url_update'] === '1'): ?>
        <?php
        $status = isset($_GET['ptmo_url_update_status']) ? sanitize_key((string) wp_unslash($_GET['ptmo_url_update_status'])) : 'success';
        if (!in_array($status, ['success', 'warning', 'info'], true)) {
          $status = 'success';
        }

        $mode = isset($_GET['ptmo_url_update_mode']) ? sanitize_key((string) wp_unslash($_GET['ptmo_url_update_mode'])) : 'single';
        $processed = isset($_GET['ptmo_url_update_processed']) ? max(0, (int) $_GET['ptmo_url_update_processed']) : 0;
        $success = isset($_GET['ptmo_url_update_success']) ? max(0, (int) $_GET['ptmo_url_update_success']) : 0;
        $failed = isset($_GET['ptmo_url_update_failed']) ? max(0, (int) $_GET['ptmo_url_update_failed']) : 0;
        $remaining = isset($_GET['ptmo_url_update_remaining']) ? max(0, (int) $_GET['ptmo_url_update_remaining']) : $pending;

        $mode_label = $mode === 'full' ? __('full run', 'pt-media-offload') : __('single update', 'pt-media-offload');
        $notice_class = 'notice notice-' . ($status === 'warning' ? 'warning' : ($status === 'info' ? 'info' : 'success'));
        ?>
        <div class="<?php echo esc_attr($notice_class); ?>">
          <p>
            <?php
            echo esc_html(sprintf(
              __('URL update %1$s finished. Processed: %2$d, Success: %3$d, Failed: %4$d, Remaining pending: %5$d.', 'pt-media-offload'),
              $mode_label,
              $processed,
              $success,
              $failed,
              $remaining
            ));
            ?>
          </p>
        </div>
      <?php endif; ?>

      <div class="pt-mo-grid">
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Pending URL Updates', 'pt-media-offload'); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format($pending); ?></div>
          <div class="pt-mo-substat"><?php esc_html_e('Attachments waiting for URL/offload metadata update', 'pt-media-offload'); ?></div>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Offloaded Attachments', 'pt-media-offload'); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format((int) ($stats['offloaded'] ?? 0)); ?></div>
          <div class="pt-mo-substat"><?php esc_html_e('Already linked with offload metadata', 'pt-media-offload'); ?></div>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Plugin Status', 'pt-media-offload'); ?></h3>
          <div class="pt-mo-stat"><?php echo $is_enabled ? esc_html__('Enabled', 'pt-media-offload') : esc_html__('Disabled', 'pt-media-offload'); ?></div>
          <div class="pt-mo-substat"><?php echo $has_credentials ? esc_html__('Credentials configured', 'pt-media-offload') : esc_html__('Credentials missing', 'pt-media-offload'); ?></div>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Total Attachments', 'pt-media-offload'); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format((int) ($stats['total'] ?? 0)); ?></div>
          <div class="pt-mo-substat"><?php esc_html_e('In media library', 'pt-media-offload'); ?></div>
        </div>
      </div>

      <div class="pt-mo-card">
        <h3><?php esc_html_e('Run URL Updates', 'pt-media-offload'); ?></h3>
        <p><?php esc_html_e('Use one-by-one for safer/manual progression, or full run to process all pending attachments in one go from this admin panel.', 'pt-media-offload'); ?></p>
        <p><em><?php esc_html_e('Tip: attachments marked “Ignore from Media Offload” and files outside your allowed path filters are skipped automatically here.', 'pt-media-offload'); ?></em></p>

        <?php if (!$is_enabled): ?>
          <p><strong><?php esc_html_e('Media offload is disabled.', 'pt-media-offload'); ?></strong></p>
        <?php endif; ?>

        <?php if (!$has_credentials): ?>
          <p><strong><?php esc_html_e('Valid storage credentials are required before URL updates can run.', 'pt-media-offload'); ?></strong></p>
        <?php endif; ?>

        <div style="display:flex; gap:12px; flex-wrap:wrap;">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('pt_media_offload_run_url_updates'); ?>
            <input type="hidden" name="action" value="pt_media_offload_run_url_updates" />
            <input type="hidden" name="mode" value="single" />
            <button type="submit" class="button button-secondary" <?php disabled(!$is_enabled || !$has_credentials || $pending <= 0); ?>>
              <?php esc_html_e('Update One Entry', 'pt-media-offload'); ?>
            </button>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Run full URL update for all pending entries now?', 'pt-media-offload')); ?>');">
            <?php wp_nonce_field('pt_media_offload_run_url_updates'); ?>
            <input type="hidden" name="action" value="pt_media_offload_run_url_updates" />
            <input type="hidden" name="mode" value="full" />
            <button type="submit" class="button button-primary" <?php disabled(!$is_enabled || !$has_credentials || $pending <= 0); ?>>
              <?php esc_html_e('Update All Pending Entries', 'pt-media-offload'); ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php
  }

  private function render_status(array $settings): void
  {
    $provider = esc_html($settings['provider']);
    $bucket = esc_html($settings['bucket']);
    $region = esc_html($settings['region']);

    echo '<h2>' . esc_html__('Connection Status', 'pt-media-offload') . '</h2>';
    echo '<table class="widefat striped status-table">';
    echo '<tbody>';
    echo '<tr><th>' . esc_html__('Enabled', 'pt-media-offload') . '</th><td>' . ($settings['enabled'] ? '&#9989;' : '&#10060;') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Provider', 'pt-media-offload') . '</th><td>' . $provider . '</td></tr>';
    echo '<tr><th>' . esc_html__('Bucket', 'pt-media-offload') . '</th><td>' . ($bucket ?: '&mdash;') . '</td></tr>';
    echo '<tr><th>' . esc_html__('Region', 'pt-media-offload') . '</th><td>' . ($region ?: '&mdash;') . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
  }

  public function render_admin_notices(): void
  {
    $notices = pop_admin_notices();
    if (empty($notices)) {
      return;
    }

    foreach ($notices as $notice) {
      $class = 'notice notice-' . esc_attr($notice['type']);
      printf('<div class="%1$s"><p>%2$s</p></div>', $class, esc_html($notice['message']));
    }
  }

  public function render_toggle(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $label = $args['label'] ?? '';
    $checked = !empty($settings[$key]);
    printf('<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>', esc_attr(PT_MEDIA_OFFLOAD_OPTION_KEY), esc_attr($key), checked($checked, true, false), esc_html($label));
  }

  public function render_provider_field(): void
  {
    $settings = $this->settings->all();
    $provider = $settings['provider'];
    ?>
    <div class="pt-mo-provider-picker">
      <select id="pt-mo-provider-select" name="<?php echo esc_attr(PT_MEDIA_OFFLOAD_OPTION_KEY); ?>[provider]">
        <option value="s3" <?php selected($provider, 's3'); ?>><?php esc_html_e('Amazon S3 / Compatible', 'pt-media-offload'); ?></option>
        <option value="r2" <?php selected($provider, 'r2'); ?>><?php esc_html_e('Cloudflare R2', 'pt-media-offload'); ?></option>
        <option value="local" <?php selected($provider, 'local'); ?>><?php esc_html_e('Local (debug only)', 'pt-media-offload'); ?></option>
      </select>
      <button type="button" class="button-link pt-mo-advanced-toggle" id="pt-mo-toggle-advanced" aria-expanded="false">
        <?php esc_html_e('Show advanced connection fields', 'pt-media-offload'); ?>
      </button>
    </div>
    <p class="description"><?php esc_html_e('The form below now focuses on required fields first. Provider-specific extras stay hidden until you need them.', 'pt-media-offload'); ?></p>
    <?php
  }

  public function render_text_field(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? '';
    $placeholder = $args['placeholder'] ?? '';
    $description = $args['description'] ?? '';
    $required = !empty($args['required']) ? 'required' : '';
    
    printf(
      '<input type="text" name="%1$s[%2$s]" value="%3$s" class="regular-text" placeholder="%4$s" %5$s />', 
      esc_attr(PT_MEDIA_OFFLOAD_OPTION_KEY), 
      esc_attr($key), 
      esc_attr($value), 
      esc_attr($placeholder),
      $required
    );
    
    if ($description) {
      printf('<p class="description">%s</p>', esc_html($description));
    }
  }

  public function render_time_field(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? '03:00';
    $description = $args['description'] ?? '';

    printf(
      '<input type="time" name="%1$s[%2$s]" value="%3$s" step="60" />',
      esc_attr(PT_MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      esc_attr($value)
    );

    if ($description) {
      printf('<p class="description">%s</p>', esc_html($description));
    }
  }

  public function render_number_field(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? ($args['default'] ?? '');
    $description = $args['description'] ?? '';
    $min = isset($args['min']) ? (int) $args['min'] : 0;
    $max = isset($args['max']) ? (int) $args['max'] : 999999;
    $step = isset($args['step']) ? (int) $args['step'] : 1;

    printf(
      '<input type="number" name="%1$s[%2$s]" value="%3$s" min="%4$d" max="%5$d" step="%6$d" class="small-text" />',
      esc_attr(PT_MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      esc_attr((string) $value),
      $min,
      $max,
      $step
    );

    if ($description) {
      printf('<p class="description">%s</p>', esc_html($description));
    }
  }

  public function render_textarea_field(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = (string) ($settings[$key] ?? '');
    $placeholder = (string) ($args['placeholder'] ?? '');
    $rows = isset($args['rows']) ? max(2, (int) $args['rows']) : 4;

    printf(
      '<textarea name="%1$s[%2$s]" rows="%3$d" class="large-text code" placeholder="%4$s">%5$s</textarea>',
      esc_attr(PT_MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      $rows,
      esc_attr($placeholder),
      esc_textarea($value)
    );

    if (!empty($args['description'])) {
      printf('<p class="description">%s</p>', esc_html($args['description']));
    }
  }

  public function render_password_field(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? '';
    $required = !empty($args['required']) ? 'required' : '';

    printf(
      '<input type="password" name="%1$s[%2$s]" value="%3$s" class="regular-text" autocomplete="off" %4$s />',
      esc_attr(PT_MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      esc_attr($value),
      $required
    );

    if (!empty($args['description'])) {
      printf('<p class="description">%s</p>', esc_html($args['description']));
    }
  }

  private function run_url_updates(int $limit = 0): array
  {
    $batch_size = max(1, (int) apply_filters('pt_media_offload_admin_url_update_batch_size', 100));
    if ($limit === 1) {
      $batch_size = 1;
    }

    $processed = 0;
    $success = 0;
    $failed = 0;
    $remaining = $limit > 0 ? $limit : PHP_INT_MAX;
    $last_id = 0;

    while ($remaining > 0) {
      $take = $limit > 0 ? min($batch_size, $remaining) : $batch_size;
      $ids = $this->fetch_pending_url_update_ids($take, $last_id);
      if (empty($ids)) {
        break;
      }

      foreach ($ids as $attachment_id) {
        $processed++;
        if ($this->upload_handler->offload_via_cli((int) $attachment_id)) {
          $success++;
        } else {
          $failed++;
        }
      }

      $last_id = (int) end($ids);
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

  private function count_pending_url_updates(): int
  {
    $count = 0;
    $after_id = 0;
    $batch_size = 500;

    while (true) {
      $candidate_ids = $this->fetch_candidate_pending_url_update_ids($batch_size, $after_id);
      if (empty($candidate_ids)) {
        break;
      }

      $count += count($this->upload_handler->filter_eligible_attachment_ids($candidate_ids));
      $after_id = (int) end($candidate_ids);

      if (count($candidate_ids) < $batch_size) {
        break;
      }
    }

    return $count;
  }

  private function fetch_pending_url_update_ids(int $limit, int $after_id = 0): array
  {
    $limit = max(1, $limit);
    $after_id = max(0, $after_id);
    $eligible = [];
    $cursor = $after_id;
    $candidate_batch_size = max(50, $limit * 4);

    while (count($eligible) < $limit) {
      $candidate_ids = $this->fetch_candidate_pending_url_update_ids($candidate_batch_size, $cursor);
      if (empty($candidate_ids)) {
        break;
      }

      foreach ($this->upload_handler->filter_eligible_attachment_ids($candidate_ids) as $attachment_id) {
        $eligible[] = (int) $attachment_id;
        if (count($eligible) >= $limit) {
          break;
        }
      }

      $cursor = (int) end($candidate_ids);
      if (count($candidate_ids) < $candidate_batch_size) {
        break;
      }
    }

    return array_slice(array_values(array_unique($eligible)), 0, $limit);
  }

  private function fetch_candidate_pending_url_update_ids(int $limit, int $after_id = 0): array
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return [];
    }

    $limit = max(1, $limit);
    $after_id = max(0, $after_id);
    $missing_source_retry_before = $this->missing_source_retry_before_timestamp();

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
      PT_MEDIA_OFFLOAD_META_KEY,
      self::MISSING_SOURCE_AT_META_KEY,
      PT_MEDIA_OFFLOAD_IGNORE_META_KEY,
      'attachment',
      'inherit',
      $after_id,
      $missing_source_retry_before,
      $limit
    ));

    if (!is_array($ids)) {
      return [];
    }

    return array_map('intval', $ids);
  }

  private function redirect_to_url_updates(array $query_args = []): void
  {
    $url = add_query_arg($query_args, admin_url('upload.php?page=pt-media-offload&tab=url-updates'));
    wp_safe_redirect($url);
    exit;
  }

  private function can_connect_storage(): bool
  {
    try {
      $factory = new Storage_Adapter_Factory($this->settings, $this->logger);
      $adapter = $factory->get_adapter();

      if (method_exists($adapter, 'test_connection')) {
        return (bool) $adapter->test_connection();
      }

      return true;
    } catch (\Throwable $exception) {
      $this->logger->warning('Admin URL update blocked by failed storage connection test: {error}', [
        'error' => $exception->getMessage(),
      ]);
      return false;
    }
  }

  private function missing_source_retry_before_timestamp(): int
  {
    $cooldown = max(300, (int) apply_filters('pt_media_offload_missing_source_retry_cooldown_seconds', 12 * HOUR_IN_SECONDS));
    return time() - $cooldown;
  }
}
