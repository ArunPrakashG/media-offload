<?php
namespace ArunPrakash\MediaOffload\Admin;

use ArunPrakash\MediaOffload\Config\Settings;

if (!defined('ABSPATH')) {
  exit;
}

final class SettingsRegistrar
{
  private const SETTINGS_PAGE = MEDIA_OFFLOAD_SLUG;

  private const SECTION_GROUPS = [
    'settings' => [
      'media_offload_connection',
      'media_offload_delivery',
      'media_offload_path_filters',
      'media_offload_off_hours'
    ],
    'logs' => [
      'media_offload_logging'
    ]
  ];

  public function __construct(
    private Settings $settings,
    private FieldRenderer $fieldRenderer,
  ) {
  }

  public function registerSettings(): void
  {
    register_setting(MEDIA_OFFLOAD_SETTINGS_GROUP, MEDIA_OFFLOAD_OPTION_KEY, [$this, 'sanitizeSettings']);

    add_settings_section(
      'media_offload_connection',
      __('Storage Connection', MEDIA_OFFLOAD_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Configure the object storage provider used for offloading media uploads.', MEDIA_OFFLOAD_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'media_offload_enabled',
      __('Enable Offloading', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      ['key' => 'enabled', 'label' => __('Activate media offloading workflow.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_provider',
      __('Provider', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderProviderField'],
      self::SETTINGS_PAGE,
      'media_offload_connection'
    );

    add_settings_field(
      'media_offload_bucket',
      __('Bucket', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      ['key' => 'bucket', 'placeholder' => 'my-media-bucket', 'required' => true]
    );

    add_settings_field(
      'media_offload_r2_account_id',
      __('R2 Account ID', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      [
        'key' => 'r2_account_id',
        'placeholder' => '2648c6dbb6901794166cdf02b48b8aa2',
        'description' => __('For Cloudflare R2, enter the account ID used to build the endpoint. Optional if Custom Endpoint is provided.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_region',
      __('Region', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      [
        'key' => 'region',
        'required' => true,
        'placeholder' => 'ap-south-1 / auto',
        'description' => __('Use your AWS region for S3. For Cloudflare R2 use "auto".', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_access_key',
      __('Access Key', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      [
        'key' => 'access_key',
        'required' => true,
        'description' => __('For R2, use the Access Key ID from your Cloudflare R2 API token.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_secret_key',
      __('Secret Key', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      [
        'key' => 'secret_key',
        'required' => true,
        'description' => __('For R2, use the Secret Access Key from your Cloudflare R2 API token.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_session_token',
      __('Session Token (optional)', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      [
        'key' => 'session_token',
        'description' => __('Use only for temporary/session S3 credentials. Leave empty for Cloudflare R2 API keys.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_prefix',
      __('Path Prefix', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      ['key' => 'prefix', 'placeholder' => 'media/uploads']
    );

    add_settings_field(
      'media_offload_endpoint',
      __('Custom Endpoint', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      [
        'key' => 'endpoint',
        'placeholder' => 'https://<account_id>.r2.cloudflarestorage.com',
        'description' => __('Optional endpoint for S3-compatible storage. For R2 use the account endpoint (without bucket path).', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_path_style',
      __('Use Path Style', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_connection',
      ['key' => 'use_path_style_endpoint', 'label' => __('Enable for providers that require path-style URLs.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_section(
      'media_offload_delivery',
      __('Delivery', MEDIA_OFFLOAD_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Control how WordPress serves media once uploaded.', MEDIA_OFFLOAD_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'media_offload_serve_from_storage',
      __('Serve From Storage', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      ['key' => 'serve_from_storage', 'label' => __('Rewrite media URLs to point at the object storage.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_custom_domain',
      __('Custom Domain', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      ['key' => 'custom_domain', 'placeholder' => 'https://cdn.example.com']
    );

    add_settings_field(
      'media_offload_remove_local',
      __('Remove Local Copies', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      ['key' => 'remove_local_copies', 'label' => __('Delete local files after successful offload (use caution).', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_purge_cloudflare_cache',
      __('Enable Cloudflare Cache Purging', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      ['key' => 'purge_cloudflare_cache_on_delete', 'label' => __('Allow the built-in Cloudflare integration to purge exact URLs on remote deletes and perform a full purge after rewrite completion events.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_cloudflare_zone_id',
      __('Cloudflare Zone ID', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      [
        'key' => 'cloudflare_zone_id',
        'placeholder' => 'your_zone_id',
        'description' => __('Required only for Cloudflare purge. Find this in Cloudflare dashboard → Overview.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_cloudflare_api_token',
      __('Cloudflare API Token', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      [
        'key' => 'cloudflare_api_token',
        'description' => __('Required only for Cloudflare purge. Token must include Zone:Cache Purge permission.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_purge_litespeed_cache',
      __('Enable LiteSpeed Cache Purging', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      ['key' => 'purge_litespeed_cache', 'label' => __('Trigger LiteSpeed Cache purges when rewrite or remote-delete events request them.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_purge_wp_fastest_cache',
      __('Enable WP Fastest Cache Purging', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      ['key' => 'purge_wp_fastest_cache', 'label' => __('Trigger WP Fastest Cache purges when full rewrite purges are requested.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_purge_proxy_cache',
      __('Enable Proxy / Nginx Cache Purging', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      ['key' => 'purge_proxy_cache', 'label' => __('Use custom PURGE/GET/POST endpoints for Nginx or reverse-proxy cache invalidation.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_proxy_cache_purge_method',
      __('Proxy Purge Method', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderSelectField'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      [
        'key' => 'proxy_cache_purge_method',
        'options' => [
          'PURGE' => 'PURGE',
          'GET' => 'GET',
          'POST' => 'POST',
        ],
        'description' => __('HTTP method used when calling your proxy purge endpoints.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_proxy_cache_purge_urls',
      __('Proxy Purge URL Templates', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextareaField'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      [
        'key' => 'proxy_cache_purge_urls',
        'rows' => 4,
        'placeholder' => "https://cache.example.com/purge?url={url_encoded}\nhttps://origin.example.com{path}",
        'description' => __('One endpoint template per line for exact-URL purges. Supported placeholders: {url}, {url_encoded}, {scheme}, {host}, {path}, {path_encoded}.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_proxy_cache_purge_all_urls',
      __('Proxy Full Purge Endpoints', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextareaField'],
      self::SETTINGS_PAGE,
      'media_offload_delivery',
      [
        'key' => 'proxy_cache_purge_all_urls',
        'rows' => 3,
        'placeholder' => "https://cache.example.com/purge-all",
        'description' => __('Optional endpoints to call when a full-cache purge is requested after bulk rewrites or integration rewrites.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_section(
      'media_offload_path_filters',
      __('Path Filters', MEDIA_OFFLOAD_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Limit which uploads paths are eligible for offload. Patterns are relative to the uploads root and support wildcards plus {year}/{month}/{day} placeholders.', MEDIA_OFFLOAD_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'media_offload_allowed_paths',
      __('Allowed Paths', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextareaField'],
      self::SETTINGS_PAGE,
      'media_offload_path_filters',
      [
        'key' => 'allowed_path_patterns',
        'rows' => 4,
        'placeholder' => "{year}/**\nsites/*/{year}/**",
        'description' => __('Optional allow-list. One pattern per line. Leave empty to allow all uploads paths. Example: {year}/** will only offload standard year folders.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_ignored_paths',
      __('Ignored Paths', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTextareaField'],
      self::SETTINGS_PAGE,
      'media_offload_path_filters',
      [
        'key' => 'ignored_path_patterns',
        'rows' => 4,
        'placeholder' => "cache/**\nsmush/**",
        'description' => __('Optional ignore list. Matching files are always kept out of sync/offload even if they match Allowed Paths.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_section(
      'media_offload_off_hours',
      __('Off-hours Batch Offload', MEDIA_OFFLOAD_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Queue uploads during the day and offload them in one nightly run.', MEDIA_OFFLOAD_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'media_offload_off_hours_enabled',
      __('Enable Off-hours Batch', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_off_hours',
      ['key' => 'off_hours_batch_enabled', 'label' => __('Run media offload only during off-hours batch window.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_off_hours_time',
      __('Batch Time', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderTimeField'],
      self::SETTINGS_PAGE,
      'media_offload_off_hours',
      [
        'key' => 'off_hours_batch_time',
        'description' => __('Daily local-site time to run the off-hours batch (example: 03:00).', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_off_hours_workers',
      __('Worker Count', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'media_offload_off_hours',
      [
        'key' => 'off_hours_worker_count',
        'min' => 1,
        'max' => 8,
        'step' => 1,
        'description' => __('Number of queue workers to dispatch per off-hours run. Increase carefully based on R2 rate limits.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_off_hours_worker_batch_size',
      __('Per-worker Batch Size', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'media_offload_off_hours',
      [
        'key' => 'off_hours_worker_batch_size',
        'min' => 10,
        'max' => 1000,
        'step' => 10,
        'description' => __('Maximum queue items a worker handles per run.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_off_hours_claim_ttl',
      __('Claim TTL (seconds)', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'media_offload_off_hours',
      [
        'key' => 'off_hours_claim_ttl_seconds',
        'min' => 60,
        'max' => 86400,
        'step' => 60,
        'description' => __('How long a worker claim is considered valid before another worker can recover it.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_section(
      'media_offload_logging',
      __('Logging', MEDIA_OFFLOAD_TEXT_DOMAIN),
      static function () {
        echo '<p>' . esc_html__('Control how many log entries are retained, which levels are stored, and whether entries are mirrored to the PHP error log.', MEDIA_OFFLOAD_TEXT_DOMAIN) . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'media_offload_logging_enabled',
      __('Enable Logging', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_logging',
      ['key' => 'logging_enabled', 'label' => __('Store Media Offload operational logs in WordPress for diagnostics and audit trails.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
    );

    add_settings_field(
      'media_offload_log_level',
      __('Minimum Log Level', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderSelectField'],
      self::SETTINGS_PAGE,
      'media_offload_logging',
      [
        'key' => 'log_level',
        'options' => [
          'debug' => __('Debug', MEDIA_OFFLOAD_TEXT_DOMAIN),
          'info' => __('Info', MEDIA_OFFLOAD_TEXT_DOMAIN),
          'notice' => __('Notice', MEDIA_OFFLOAD_TEXT_DOMAIN),
          'warning' => __('Warning', MEDIA_OFFLOAD_TEXT_DOMAIN),
          'error' => __('Error', MEDIA_OFFLOAD_TEXT_DOMAIN),
          'critical' => __('Critical', MEDIA_OFFLOAD_TEXT_DOMAIN),
        ],
        'description' => __('Only messages at or above this severity are stored.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_log_max_entries',
      __('Retained Entries', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'media_offload_logging',
      [
        'key' => 'log_max_entries',
        'min' => 100,
        'max' => 10000,
        'step' => 100,
        'description' => __('Maximum number of in-plugin log entries to retain before older records are discarded.', MEDIA_OFFLOAD_TEXT_DOMAIN),
      ]
    );

    add_settings_field(
      'media_offload_log_to_error_log',
      __('Mirror To PHP Error Log', MEDIA_OFFLOAD_TEXT_DOMAIN),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'media_offload_logging',
      ['key' => 'log_to_error_log', 'label' => __('Also send stored log messages to the server PHP error log.', MEDIA_OFFLOAD_TEXT_DOMAIN)]
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

      echo '<section class="pt-mo-card pt-mo-settings-section-card">';

      if (!empty($section['title'])) {
        printf('<h2>%s</h2>', esc_html($section['title']));
      }

      if (!empty($section['callback'])) {
        call_user_func($section['callback'], $section);
      }

      if (!empty($wp_settings_fields[self::SETTINGS_PAGE][$sectionId])) {
        echo '<table class="form-table" role="presentation">';
        do_settings_fields(self::SETTINGS_PAGE, $sectionId);
        echo '</table>';
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
          MEDIA_OFFLOAD_SETTINGS_GROUP,
          'media_offload_missing_creds',
          sprintf(__('Cannot enable offloading. Missing required fields: %s', MEDIA_OFFLOAD_TEXT_DOMAIN), implode(', ', $missing)),
          'error'
        );
      }
    }

    if (!empty($sanitized['purge_cloudflare_cache_on_delete'])) {
      if (empty($sanitized['cloudflare_zone_id']) || empty($sanitized['cloudflare_api_token'])) {
        $sanitized['purge_cloudflare_cache_on_delete'] = false;
        add_settings_error(
          MEDIA_OFFLOAD_SETTINGS_GROUP,
          'media_offload_missing_cf_purge',
          __('Cloudflare purge was disabled because Zone ID or API token is missing.', MEDIA_OFFLOAD_TEXT_DOMAIN),
          'error'
        );
      }
    }

    add_settings_error(MEDIA_OFFLOAD_SETTINGS_GROUP, 'media_offload_saved', __('Media offload settings saved.', MEDIA_OFFLOAD_TEXT_DOMAIN), 'updated');

    return $sanitized;
  }
}
