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
      __('Storage Connection', 'arun-prakash-media-offload'),
      static function () {
        echo '<p>' . esc_html__('Configure the object storage provider used for offloading media uploads.', 'arun-prakash-media-offload') . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_enabled',
      __('Enable Offloading', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      ['key' => 'enabled', 'label' => __('Activate media offloading workflow.', 'arun-prakash-media-offload')]
    );

    add_settings_field(
      'nimbus_provider',
      __('Provider', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderProviderField'],
      self::SETTINGS_PAGE,
      'nimbus_connection'
    );

    add_settings_field(
      'nimbus_bucket',
      __('Bucket', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      ['key' => 'bucket', 'placeholder' => 'my-media-bucket', 'required' => true]
    );

    add_settings_field(
      'nimbus_r2_account_id',
      __('Account ID', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'r2_account_id',
        'placeholder' => '2648c6dbb6901794166cdf02b48b8aa2',
        'description' => __('Used by some providers to build the storage endpoint (e.g. Cloudflare R2). Optional if a Custom Endpoint is provided directly.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_region',
      __('Region', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'region',
        'required' => true,
        'placeholder' => 'ap-south-1 / auto',
        'description' => __('Region for your storage bucket.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_access_key',
      __('Access Key', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'access_key',
        'required' => true,
        'description' => __('Access Key ID for your storage provider.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_secret_key',
      __('Secret Key', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'secret_key',
        'required' => true,
        'description' => __('Secret Access Key for your storage provider.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_session_token',
      __('Session Token (optional)', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'session_token',
        'description' => __('Optional temporary session token. Leave empty for permanent access keys.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_prefix',
      __('Path Prefix', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      ['key' => 'prefix', 'placeholder' => 'media/uploads']
    );

    add_settings_field(
      'nimbus_endpoint',
      __('Custom Endpoint', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      [
        'key' => 'endpoint',
        'placeholder' => 'https://<account_id>.r2.cloudflarestorage.com',
        'description' => __('Custom endpoint URL for your storage provider.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_path_style',
      __('Use Path Style', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_connection',
      ['key' => 'use_path_style_endpoint', 'label' => __('Enable for providers that require path-style URLs.', 'arun-prakash-media-offload')]
    );

    add_settings_section(
      'nimbus_delivery',
      __('Delivery', 'arun-prakash-media-offload'),
      static function () {
        echo '<p>' . esc_html__('Control how WordPress serves media once uploaded.', 'arun-prakash-media-offload') . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_serve_from_storage',
      __('Serve From Storage', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_delivery',
      ['key' => 'serve_from_storage', 'label' => __('Rewrite media URLs to point at the object storage.', 'arun-prakash-media-offload')]
    );

    add_settings_field(
      'nimbus_custom_domain',
      __('Custom Domain', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_delivery',
      ['key' => 'custom_domain', 'placeholder' => 'https://cdn.example.com']
    );

    add_settings_field(
      'nimbus_remove_local',
      __('Remove Local Copies', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_delivery',
      ['key' => 'remove_local_copies', 'label' => __('Delete local files after successful offload (use caution).', 'arun-prakash-media-offload')]
    );

    add_settings_section(
      'nimbus_cache_purging',
      __('Cache Purging', 'arun-prakash-media-offload'),
      static function () {
        echo '<p>' . esc_html__('Automatically purge CDN and server-side page caches when media is offloaded or URLs are rewritten. Configure the integrations that apply to your stack.', 'arun-prakash-media-offload') . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_purge_cloudflare_cache',
      __('Enable Cloudflare Cache Purging', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      ['key' => 'purge_cloudflare_cache_on_delete', 'label' => __('Allow the built-in Cloudflare integration to purge exact URLs on remote deletes and perform a full purge after rewrite completion events.', 'arun-prakash-media-offload')]
    );

    add_settings_field(
      'nimbus_cloudflare_zone_id',
      __('Cloudflare Zone ID', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'cloudflare_zone_id',
        'placeholder' => 'your_zone_id',
        'description' => __('Required only for Cloudflare purge. Find this in Cloudflare dashboard → Overview.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_cloudflare_api_token',
      __('Cloudflare API Token', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderPasswordField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'cloudflare_api_token',
        'description' => __('Required only for Cloudflare purge. Token must include Zone:Cache Purge permission.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_purge_litespeed_cache',
      __('Enable LiteSpeed Cache Purging', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      ['key' => 'purge_litespeed_cache', 'label' => __('Trigger LiteSpeed Cache purges when rewrite or remote-delete events request them.', 'arun-prakash-media-offload')]
    );

    add_settings_field(
      'nimbus_purge_wp_fastest_cache',
      __('Enable WP Fastest Cache Purging', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      ['key' => 'purge_wp_fastest_cache', 'label' => __('Trigger WP Fastest Cache purges when full rewrite purges are requested.', 'arun-prakash-media-offload')]
    );

    add_settings_field(
      'nimbus_purge_proxy_cache',
      __('Enable Proxy / Nginx Cache Purging', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      ['key' => 'purge_proxy_cache', 'label' => __('Use custom PURGE/GET/POST endpoints for Nginx or reverse-proxy cache invalidation.', 'arun-prakash-media-offload')]
    );

    add_settings_field(
      'nimbus_proxy_cache_purge_method',
      __('Proxy Purge Method', 'arun-prakash-media-offload'),
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
        'description' => __('HTTP method used when calling your proxy purge endpoints.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_proxy_cache_purge_urls',
      __('Proxy Purge URL Templates', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextareaField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'proxy_cache_purge_urls',
        'rows' => 4,
        'placeholder' => "https://cache.example.com/purge?url={url_encoded}\nhttps://origin.example.com{path}",
        'description' => __('One endpoint template per line for exact-URL purges. Supported placeholders: {url}, {url_encoded}, {scheme}, {host}, {path}, {path_encoded}.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_proxy_cache_purge_all_urls',
      __('Proxy Full Purge Endpoints', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTextareaField'],
      self::SETTINGS_PAGE,
      'nimbus_cache_purging',
      [
        'key' => 'proxy_cache_purge_all_urls',
        'rows' => 3,
        'placeholder' => "https://cache.example.com/purge-all",
        'description' => __('Optional endpoints to call when a full-cache purge is requested after bulk rewrites or integration rewrites.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_section(
      'nimbus_path_filters',
      __('Path Filters', 'arun-prakash-media-offload'),
      static function () {
        echo '<p>' . esc_html__('Limit which uploads paths are eligible for offload. Patterns are relative to the uploads root and support wildcards plus {year}/{month}/{day} placeholders.', 'arun-prakash-media-offload') . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_allowed_paths',
      __('Allowed Paths', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderPathPillField'],
      self::SETTINGS_PAGE,
      'nimbus_path_filters',
      [
        'key' => 'allowed_path_patterns',
        'description' => __('Optional allow-list. Leave empty to allow all uploads paths. Example: {year}/** offloads only standard year folders.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_ignored_paths',
      __('Ignored Paths', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderPathPillField'],
      self::SETTINGS_PAGE,
      'nimbus_path_filters',
      [
        'key' => 'ignored_path_patterns',
        'description' => __('Optional ignore list. Matching files are always kept out of offload even if they match Allowed Paths.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_section(
      'nimbus_off_hours',
      __('Off-hours Batch Offload', 'arun-prakash-media-offload'),
      static function () {
        echo '<p>' . esc_html__('Queue uploads during the day and offload them in one nightly run.', 'arun-prakash-media-offload') . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_off_hours_enabled',
      __('Enable Off-hours Batch', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      ['key' => 'off_hours_batch_enabled', 'label' => __('Run media offload only during off-hours batch window.', 'arun-prakash-media-offload')]
    );

    add_settings_field(
      'nimbus_off_hours_time',
      __('Batch Time', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderTimeField'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      [
        'key' => 'off_hours_batch_time',
        'description' => __('Daily local-site time to run the off-hours batch (example: 03:00).', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_off_hours_workers',
      __('Worker Count', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      [
        'key' => 'off_hours_worker_count',
        'min' => 1,
        'max' => 8,
        'step' => 1,
        'description' => __('Number of queue workers to dispatch per off-hours run. Increase carefully based on R2 rate limits.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_off_hours_worker_batch_size',
      __('Per-worker Batch Size', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      [
        'key' => 'off_hours_worker_batch_size',
        'min' => 10,
        'max' => 1000,
        'step' => 10,
        'description' => __('Maximum queue items a worker handles per run.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_off_hours_claim_ttl',
      __('Claim TTL (seconds)', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'nimbus_off_hours',
      [
        'key' => 'off_hours_claim_ttl_seconds',
        'min' => 60,
        'max' => 86400,
        'step' => 60,
        'description' => __('How long a worker claim is considered valid before another worker can recover it.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_section(
      'nimbus_logging',
      __('Logging', 'arun-prakash-media-offload'),
      static function () {
        echo '<p>' . esc_html__('Control how many log entries are retained, which levels are stored, and whether entries are mirrored to the PHP error log.', 'arun-prakash-media-offload') . '</p>';
      },
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'nimbus_logging_enabled',
      __('Enable Logging', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_logging',
      ['key' => 'logging_enabled', 'label' => __('Store Nimbus operational logs in WordPress for diagnostics and audit trails.', 'arun-prakash-media-offload')]
    );

    add_settings_field(
      'nimbus_log_level',
      __('Minimum Log Level', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderSelectField'],
      self::SETTINGS_PAGE,
      'nimbus_logging',
      [
        'key' => 'log_level',
        'options' => [
          'debug' => __('Debug', 'arun-prakash-media-offload'),
          'info' => __('Info', 'arun-prakash-media-offload'),
          'notice' => __('Notice', 'arun-prakash-media-offload'),
          'warning' => __('Warning', 'arun-prakash-media-offload'),
          'error' => __('Error', 'arun-prakash-media-offload'),
          'critical' => __('Critical', 'arun-prakash-media-offload'),
        ],
        'description' => __('Only messages at or above this severity are stored.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_log_max_entries',
      __('Retained Entries', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderNumberField'],
      self::SETTINGS_PAGE,
      'nimbus_logging',
      [
        'key' => 'log_max_entries',
        'min' => 100,
        'max' => 10000,
        'step' => 100,
        'description' => __('Maximum number of in-plugin log entries to retain before older records are discarded.', 'arun-prakash-media-offload'),
      ]
    );

    add_settings_field(
      'nimbus_log_to_error_log',
      __('Mirror To PHP Error Log', 'arun-prakash-media-offload'),
      [$this->fieldRenderer, 'renderToggle'],
      self::SETTINGS_PAGE,
      'nimbus_logging',
      ['key' => 'log_to_error_log', 'label' => __('Also send stored log messages to the server PHP error log.', 'arun-prakash-media-offload')]
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
          /* translators: %s: comma-separated list of missing required setting names */
          sprintf(__('Cannot enable offloading. Missing required fields: %s', 'arun-prakash-media-offload'), implode(', ', $missing)),
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
          __('Cloudflare purge was disabled because Zone ID or API token is missing.', 'arun-prakash-media-offload'),
          'error'
        );
      }
    }

    add_settings_error(NIMBUS_SETTINGS_GROUP, 'nimbus_saved', __('Media offload settings saved.', 'arun-prakash-media-offload'), 'updated');

    return $sanitized;
  }
}
