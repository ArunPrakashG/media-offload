<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;

if (!defined('ABSPATH')) {
  exit;
}

final class SettingsRegistrar
{
  private const SETTINGS_PAGE = NIMBUS_SLUG;

  private const SECTION_GROUPS = [
    'settings' => [
      'nimbus_connection',
      'nimbus_delivery',
      'nimbus_off_hours',
    ],
    'advanced' => [
      'nimbus_path_filters',
      'nimbus_cache_purging',
    ],
    'logs' => [
      'nimbus_logging'
    ]
  ];

  public function __construct(
    private Settings $settings,
    private FieldRenderer $fieldRenderer,
  ) {
  }

  public function registerSettings(): void
  {
    register_setting(NIMBUS_SETTINGS_GROUP, NIMBUS_OPTION_KEY, [$this, 'sanitizeSettings']);

    add_settings_section(
      'nimbus_connection',
      __('Storage Connection', NIMBUS_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Configure the object storage provider used for offloading media uploads.', NIMBUS_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_enabled',
      __('Enable Offloading', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      ['key' => 'enabled', 'label' => __('Activate media offloading workflow.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_field(
      'nimbus_provider',
      __('Provider', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderProviderField'],
      self::SETTINGS_PAGE,
      'nimbus_connection'
    );

    add_settings_field(
      'nimbus_bucket',
      __('Bucket', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      ['key' => 'bucket', 'placeholder' => 'my-media-bucket', 'required' => true]
    );

    add_settings_field(
      'nimbus_r2_account_id',
      __('Account ID', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'r2_account_id',
        'placeholder' => '2648c6dbb6901794166cdf02b48b8aa2',
        'description' => __('Used by some providers to build the storage endpoint (e.g. Cloudflare R2). Optional if a Custom Endpoint is provided directly.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_region',
      __('Region', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'region',
        'required' => true,
        'placeholder' => 'ap-south-1 / auto',
        'description' => __('Region for your storage bucket.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_access_key',
      __('Access Key', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'access_key',
        'required' => true,
        'description' => __('Access Key ID for your storage provider.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_secret_key',
      __('Secret Key', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'secret_key',
        'required' => true,
        'description' => __('Secret Access Key for your storage provider.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_session_token',
      __('Session Token (optional)', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'session_token',
        'description' => __('Optional temporary session token. Leave empty for permanent access keys.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_prefix',
      __('Path Prefix', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      ['key' => 'prefix', 'placeholder' => 'media/uploads']
    );

    add_settings_field(
      'nimbus_endpoint',
      __('Custom Endpoint', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'endpoint',
        'placeholder' => 'https://<account_id>.r2.cloudflarestorage.com',
        'description' => __('Custom endpoint URL for your storage provider.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_path_style',
      __('Use Path Style', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      ['key' => 'use_path_style_endpoint', 'label' => __('Enable for providers that require path-style URLs.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_section(
      'nimbus_delivery',
      __('Delivery', NIMBUS_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Control how WordPress serves media once uploaded.', NIMBUS_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_serve_from_storage',
      __('Serve From Storage', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_delivery',
      ['key' => 'serve_from_storage', 'label' => __('Rewrite media URLs to point at the object storage.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_field(
      'nimbus_custom_domain',
      __('Custom Domain', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_delivery',
      ['key' => 'custom_domain', 'placeholder' => 'https://cdn.example.com']
    );

    add_settings_field(
      'nimbus_remove_local',
      __('Remove Local Copies', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_delivery',
      ['key' => 'remove_local_copies', 'label' => __('Delete local files after successful offload (use caution).', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_section(
      'nimbus_cache_purging',
      __('Cache Purging', NIMBUS_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Automatically purge CDN and server-side page caches when media is offloaded or URLs are rewritten. Configure the integrations that apply to your stack.', NIMBUS_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_purge_cloudflare_cache',
      __('Enable Cloudflare Cache Purging', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      ['key' => 'purge_cloudflare_cache_on_delete', 'label' => __('Allow the built-in Cloudflare integration to purge exact URLs on remote deletes and perform a full purge after rewrite completion events.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_field(
      'nimbus_cloudflare_zone_id',
      __('Cloudflare Zone ID', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'cloudflare_zone_id',
        'placeholder' => 'your_zone_id',
        'description' => __('Required only for Cloudflare purge. Find this in Cloudflare dashboard → Overview.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_cloudflare_api_token',
      __('Cloudflare API Token', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'cloudflare_api_token',
        'description' => __('Required only for Cloudflare purge. Token must include Zone:Cache Purge permission.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_purge_litespeed_cache',
      __('Enable LiteSpeed Cache Purging', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      ['key' => 'purge_litespeed_cache', 'label' => __('Trigger LiteSpeed Cache purges when rewrite or remote-delete events request them.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_field(
      'nimbus_purge_wp_fastest_cache',
      __('Enable WP Fastest Cache Purging', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      ['key' => 'purge_wp_fastest_cache', 'label' => __('Trigger WP Fastest Cache purges when full rewrite purges are requested.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_field(
      'nimbus_purge_proxy_cache',
      __('Enable Proxy / Nginx Cache Purging', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      ['key' => 'purge_proxy_cache', 'label' => __('Use custom PURGE/GET/POST endpoints for Nginx or reverse-proxy cache invalidation.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_field(
      'nimbus_proxy_cache_purge_method',
      __('Proxy Purge Method', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderSelectField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'proxy_cache_purge_method',
        'options' => [
          'PURGE' => 'PURGE',
          'GET' => 'GET',
          'POST' => 'POST',
        ],
        'description' => __('HTTP method used when calling your proxy purge endpoints.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_proxy_cache_purge_urls',
      __('Proxy Purge URL Templates', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextareaField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'proxy_cache_purge_urls',
        'rows' => 4,
        'placeholder' => "https://cache.example.com/purge?url={url_encoded}\nhttps://origin.example.com{path}",
        'description' => __('One endpoint template per line for exact-URL purges. Supported placeholders: {url}, {url_encoded}, {scheme}, {host}, {path}, {path_encoded}.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_proxy_cache_purge_all_urls',
      __('Proxy Full Purge Endpoints', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextareaField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'proxy_cache_purge_all_urls',
        'rows' => 3,
        'placeholder' => "https://cache.example.com/purge-all",
        'description' => __('Optional endpoints to call when a full-cache purge is requested after bulk rewrites or integration rewrites.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_section(
      'nimbus_path_filters',
      __('Path Filters', NIMBUS_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Limit which uploads paths are eligible for offload. Patterns are relative to the uploads root and support wildcards plus {year}/{month}/{day} placeholders.', NIMBUS_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_allowed_paths',
      __('Allowed Paths', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderPathPillField'],
      self::SETTINGS_PAGE,
      'nimbus_path_filters',
      [
        'key' => 'allowed_path_patterns',
        'description' => __('Optional allow-list. Leave empty to allow all uploads paths. Example: {year}/** offloads only standard year folders.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_ignored_paths',
      __('Ignored Paths', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderPathPillField'],
      self::SETTINGS_PAGE,
      'nimbus_path_filters',
      [
        'key' => 'ignored_path_patterns',
        'description' => __('Optional ignore list. Matching files are always kept out of offload even if they match Allowed Paths.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_section(
      'nimbus_off_hours',
      __('Off-hours Batch Offload', NIMBUS_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Queue uploads during the day and offload them in one nightly run.', NIMBUS_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_off_hours_enabled',
      __('Enable Off-hours Batch', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      ['key' => 'off_hours_batch_enabled', 'label' => __('Run media offload only during off-hours batch window.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_field(
      'nimbus_off_hours_time',
      __('Batch Time', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTimeField'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      [
        'key' => 'off_hours_batch_time',
        'description' => __('Daily local-site time to run the off-hours batch (example: 03:00).', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_off_hours_workers',
      __('Worker Count', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      [
        'key' => 'off_hours_worker_count',
        'min' => 1,
        'max' => 8,
        'step' => 1,
        'description' => __('Number of queue workers to dispatch per off-hours run. Increase carefully based on R2 rate limits.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_off_hours_worker_batch_size',
      __('Per-worker Batch Size', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      [
        'key' => 'off_hours_worker_batch_size',
        'min' => 10,
        'max' => 1000,
        'step' => 10,
        'description' => __('Maximum queue items a worker handles per run.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_off_hours_claim_ttl',
      __('Claim TTL (seconds)', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      [
        'key' => 'off_hours_claim_ttl_seconds',
        'min' => 60,
        'max' => 86400,
        'step' => 60,
        'description' => __('How long a worker claim is considered valid before another worker can recover it.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_section(
      'nimbus_logging',
      __('Logging', NIMBUS_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Control how many log entries are retained, which levels are stored, and whether entries are mirrored to the PHP error log.', NIMBUS_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_logging_enabled',
      __('Enable Logging', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_logging',
      ['key' => 'logging_enabled', 'label' => __('Store Nimbus operational logs in WordPress for diagnostics and audit trails.', NIMBUS_TEXT_DOMAIN)]
    );

    add_settings_field(
      'nimbus_log_level',
      __('Minimum Log Level', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderSelectField'],
      self::SETTINGS_PAGE,
      'nimbus_logging',
      [
        'key' => 'log_level',
        'options' => [
          'debug' => __('Debug', NIMBUS_TEXT_DOMAIN),
          'info' => __('Info', NIMBUS_TEXT_DOMAIN),
          'notice' => __('Notice', NIMBUS_TEXT_DOMAIN),
          'warning' => __('Warning', NIMBUS_TEXT_DOMAIN),
          'error' => __('Error', NIMBUS_TEXT_DOMAIN),
          'critical' => __('Critical', NIMBUS_TEXT_DOMAIN),
        ],
        'description' => __('Only messages at or above this severity are stored.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_log_max_entries',
      __('Retained Entries', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'nimbus_logging',
      [
        'key' => 'log_max_entries',
        'min' => 100,
        'max' => 10000,
        'step' => 100,
        'description' => __('Maximum number of in-plugin log entries to retain before older records are discarded.', NIMBUS_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'nimbus_log_to_error_log',
      __('Mirror To PHP Error Log', NIMBUS_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_logging',
      ['key' => 'log_to_error_log', 'label' => __('Also send stored log messages to the server PHP error log.', NIMBUS_TEXT_DOMAIN)]
    );
  }

  public function getSectionIdsForTab(string $tab): array
  {
    return self::SECTION_GROUPS[$tab] ?? [];
  }

  public function renderSections(array $sectionIds): void
  {
    global $wp_settings_sections, $wp_settings_fields;

    foreach ($sectionIds as $sectionId) {
      if (empty($wp_settings_sections[self::SETTINGS_PAGE][$sectionId])) {
        continue;
      }

      $section = $wp_settings_sections[self::SETTINGS_PAGE][$sectionId];

      echo '<section class="nmb-card nmb-settings-section-card nmb-settings-section-card--' . esc_attr($sectionId) . '">';

      if (!empty($section['title']) || !empty($section['callback'])) {
        echo '<div class="nmb-settings-section-header">';

        if (!empty($section['title'])) {
          printf('<h2>%s</h2>', esc_html($section['title']));
        }

        if (!empty($section['callback'])) {
          echo '<div class="nmb-settings-section-description">';
          call_user_func($section['callback'], $section);
          echo '</div>';
        }

        echo '</div>';
      }

      if (!empty($wp_settings_fields[self::SETTINGS_PAGE][$sectionId])) {
        echo '<div class="nmb-settings-section-body">';
        echo '<table class="form-table" role="presentation">';
        do_settings_fields(self::SETTINGS_PAGE, $sectionId);
        echo '</table>';
        echo '</div>';
      }

      echo '</section>';
    }
  }

  public function sanitizeSettings($input): array
  {
    if (!is_array($input)) {
      $input = [];
    }

    $checkboxes = ['enabled', 'off_hours_batch_enabled', 'use_path_style_endpoint', 'remove_local_copies', 'serve_from_storage', 'purge_cloudflare_cache_on_delete', 'purge_litespeed_cache', 'purge_wp_fastest_cache', 'purge_proxy_cache', 'logging_enabled', 'log_to_error_log'];
    foreach ($checkboxes as $key) {
      if (!isset($input[$key])) {
        $input[$key] = false;
      }
    }

    $current = $this->settings->all();
    $merged = array_merge($current, $input);
    $sanitized = $this->settings->sanitize($merged);

    if ($sanitized['enabled'] && $sanitized['provider'] !== 'local') {
      $missing = $this->settings->missing_required_credentials($sanitized, $sanitized['provider']);

      if (!empty($missing)) {
        $sanitized['enabled'] = false;
        add_settings_error(
          NIMBUS_SETTINGS_GROUP,
          'nimbus_missing_creds',
          sprintf(__('Cannot enable offloading. Missing required fields: %s', NIMBUS_TEXT_DOMAIN), implode(', ', $missing)),
          'error'
        );
      }
    }

    if (!empty($sanitized['purge_cloudflare_cache_on_delete'])) {
      if (empty($sanitized['cloudflare_zone_id']) || empty($sanitized['cloudflare_api_token'])) {
        $sanitized['purge_cloudflare_cache_on_delete'] = false;
        add_settings_error(
          NIMBUS_SETTINGS_GROUP,
          'nimbus_missing_cf_purge',
          __('Cloudflare purge was disabled because Zone ID or API token is missing.', NIMBUS_TEXT_DOMAIN),
          'error'
        );
      }
    }

    add_settings_error(NIMBUS_SETTINGS_GROUP, 'nimbus_saved', __('Media offload settings saved.', NIMBUS_TEXT_DOMAIN), 'updated');

    return $sanitized;
  }
}
