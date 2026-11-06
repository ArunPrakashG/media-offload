<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Bootstrap\DataMigration;
use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Onboarding\OnboardingManager;
use Nimbus\Plugin\Stats\Statistics;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class PageRenderer
{
  private const TAB_ALIASES = [
    'dashboard' => 'overview',
    'setup' => 'onboarding',
    'storage' => 'settings',
    'delivery' => 'settings',
    'url-updates' => 'tools',
  ];

  public function __construct(
    private Settings $settings,
    private Logger $logger,
    private Statistics $statistics,
    private UploadHandler $uploadHandler,
    private SettingsRegistrar $settingsRegistrar,
    private OnboardingManager $onboardingManager,
  ) {
  }

  public function renderPage(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only GET parameter used for display/navigation only (tab, filter, or self-generated redirect notice) -- no state-changing action is performed, so no nonce applies.
    if (isset($_GET['nimbus_logs_cleared']) && $_GET['nimbus_logs_cleared'] === '1') {
      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Plugin logs cleared.', 'arun-prakash-media-offload') . '</p></div>';
    }

    $onboarding = $this->onboardingManager->state();
    if (empty($onboarding['completed'])) {
      ?>
      <div class="wrap nimbus-settings nimbus-settings--onboarding">
        <?php $this->renderOnboardingTab(); ?>
      </div>
      <?php
      return;
    }

    $activeTab = $this->resolveActiveTab();
    ?>
    <div class="wrap nimbus-settings">
      <?php $this->renderBrandHeader(); ?>

      <?php settings_errors(NIMBUS_SETTINGS_GROUP); ?>

      <nav class="nav-tab-wrapper nmb-shell-tabs" aria-label="<?php esc_attr_e('Nimbus navigation', 'arun-prakash-media-offload'); ?>">
        <a href="<?php echo esc_url($this->adminTabUrl('overview')); ?>" class="nav-tab <?php echo $activeTab === 'overview' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Overview', 'arun-prakash-media-offload'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('settings')); ?>" class="nav-tab <?php echo $activeTab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Settings', 'arun-prakash-media-offload'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('advanced')); ?>" class="nav-tab <?php echo $activeTab === 'advanced' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Advanced', 'arun-prakash-media-offload'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>" class="nav-tab <?php echo $activeTab === 'integrations' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Integrations', 'arun-prakash-media-offload'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('tools')); ?>" class="nav-tab <?php echo $activeTab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Tools', 'arun-prakash-media-offload'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('logs')); ?>" class="nav-tab <?php echo $activeTab === 'logs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Logs', 'arun-prakash-media-offload'); ?></a>
      </nav>

      <?php if ($activeTab === 'overview') : ?>
        <?php $this->renderOverviewTab(); ?>
      <?php elseif ($activeTab === 'settings') : ?>
        <?php $this->renderSettingsTab(); ?>
      <?php elseif ($activeTab === 'advanced') : ?>
        <?php $this->renderAdvancedTab(); ?>
      <?php elseif ($activeTab === 'integrations') : ?>
        <?php $this->renderIntegrationsTab(); ?>
      <?php elseif ($activeTab === 'tools') : ?>
        <?php $this->renderToolsTab(); ?>
      <?php elseif ($activeTab === 'logs') : ?>
        <?php $this->renderLogsTab(); ?>
      <?php else : ?>
        <?php $this->renderOverviewTab(); ?>
      <?php endif; ?>
    </div>
    <?php
  }

  private function resolveActiveTab(): string
  {
    if (!(bool) ($this->onboardingManager->state()['completed'] ?? true)) {
      return 'onboarding';
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only GET parameter used for display/navigation only (tab, filter, or self-generated redirect notice) -- no state-changing action is performed, so no nonce applies.
    $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'overview';
    $tab = self::TAB_ALIASES[$tab] ?? $tab;

    if (!in_array($tab, ['overview', 'settings', 'advanced', 'integrations', 'tools', 'logs'], true)) {
      return 'overview';
    }

    return $tab;
  }

  private function renderOnboardingTab(): void
  {
    $settings = $this->settings->all();
    $state = $this->onboardingManager->state();
    $stepKeys = ['storage', 'delivery', 'review'];
    $stepLabels = [
      'storage' => __('Storage', 'arun-prakash-media-offload'),
      'delivery' => __('Delivery', 'arun-prakash-media-offload'),
      'review' => __('Review', 'arun-prakash-media-offload'),
    ];

    // If a step was explicitly saved (user progressed mid-wizard and navigated
    // away), resume from that saved step rather than starting over.
    $savedStep = !empty($state['current_step']) ? (string) $state['current_step'] : '';
    $defaultStep = 'storage';
    if ($savedStep !== '' && in_array($savedStep, $stepKeys, true)) {
      $defaultStep = $savedStep;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only GET parameter used for display/navigation only (tab, filter, or self-generated redirect notice) -- no state-changing action is performed, so no nonce applies.
    $requestedStep = isset($_GET['onboarding_step']) ? sanitize_key((string) wp_unslash($_GET['onboarding_step'])) : $defaultStep;

    $currentStepIndex = array_search($requestedStep, $stepKeys, true);
    if ($currentStepIndex === false) {
      $currentStepIndex = array_search($defaultStep, $stepKeys, true);
    }

    $savedProvider  = (string) ($settings['provider'] ?? 's3');
    $isLocalDefault = $savedProvider === 'local';
    $isR2Default    = $savedProvider === 'r2';
    $isS3CDefault   = in_array($savedProvider, ['s3_compatible', 'minio'], true);
    // Start on quick connect unless storage is already set up or the provider has no quick connect.
    $quickMode = !$isLocalDefault && (string) ($settings['access_key'] ?? '') === '' ? 'quick' : 'manual';

    // Picker order follows the mockup. Local is a debug mode and only shows when already chosen.
    $pickerKeys = array_values(array_filter(
      ['s3', 'r2', 'gcs', 'backblaze_b2', 'digitalocean_spaces', 'wasabi', 'minio', 's3_compatible', 'local'],
      fn(string $key): bool => in_array($key, $this->settings->available_provider_keys(), true) && ($key !== 'local' || $isLocalDefault)
    ));
    ?>
    <div id="nmb-onboarding-wizard" class="nmb-wizard" data-current-step="<?php echo esc_attr((string) $currentStepIndex); ?>">
      <header class="nmb-wizard-header">
        <div class="nmb-brand">
          <?php $this->renderMark(); ?>
          <h1><?php esc_html_e('Set up Nimbus', 'arun-prakash-media-offload'); ?></h1>
        </div>
        <ol class="nmb-stepper" aria-label="<?php esc_attr_e('Setup steps', 'arun-prakash-media-offload'); ?>">
          <?php foreach ($stepKeys as $index => $stepKey) : ?>
            <li class="nmb-onboarding-step-item" data-step-item="<?php echo esc_attr($stepKey); ?>">
              <button type="button"><span class="nmb-stepper__n"><?php echo esc_html((string) ($index + 1)); ?></span><?php echo esc_html($stepLabels[$stepKey]); ?></button>
            </li>
          <?php endforeach; ?>
        </ol>
      </header>
      <hr class="wp-header-end" />

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nmb-onboarding-form nmb-onboarding-main nmb-card">
        <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING); ?>" />

        <section class="nmb-onboarding-step" data-step="storage" aria-labelledby="nmb-step-storage" data-quick-mode="<?php echo esc_attr($quickMode); ?>">
          <fieldset class="nmb-provider-picker">
            <legend id="nmb-step-storage"><?php esc_html_e('Where should your media live?', 'arun-prakash-media-offload'); ?></legend>
            <div class="nmb-provider-grid">
              <?php foreach ($pickerKeys as $providerKey) : ?>
                <label class="nmb-provider-option<?php echo $providerKey === 's3_compatible' ? ' nmb-provider-option--other' : ''; ?>">
                  <input type="radio" name="provider" value="<?php echo esc_attr($providerKey); ?>" <?php checked($savedProvider, $providerKey); ?> />
                  <?php $this->renderProviderIcon($providerKey); ?>
                  <span><?php echo esc_html($this->providerShortLabel($providerKey)); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <?php (new QuickConnectPanel($this->settings, $this->logger))->render($savedProvider); ?>

          <div class="nmb-wizard-fields" data-manual-fields>
            <div data-provider-group="bucket-location"<?php if ($isLocalDefault) echo ' hidden'; ?>>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Bucket', 'arun-prakash-media-offload'); ?></span>
                <input type="text" name="bucket" value="<?php echo esc_attr((string) ($settings['bucket'] ?? '')); ?>" placeholder="my-media-bucket" autocomplete="off" spellcheck="false"<?php if ($isLocalDefault) echo ' disabled'; ?> />
              </label>
              <label class="nmb-onboarding-field" data-hide-for="r2">
                <span><?php esc_html_e('Region', 'arun-prakash-media-offload'); ?></span>
                <input type="text" id="nimbus-region" name="region" value="<?php echo esc_attr((string) ($settings['region'] ?? '')); ?>" placeholder="us-east-1" autocomplete="off" spellcheck="false"<?php if ($isLocalDefault) echo ' disabled'; ?> />
              </label>
            </div>

            <div data-provider-group="r2-extra"<?php if (! $isR2Default) echo ' hidden'; ?>>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Account ID', 'arun-prakash-media-offload'); ?></span>
                <input type="text" name="r2_account_id" value="<?php echo esc_attr((string) ($settings['r2_account_id'] ?? '')); ?>" autocomplete="off" spellcheck="false"<?php if (! $isR2Default) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('Cloudflare dashboard, right sidebar of the home page.', 'arun-prakash-media-offload'); ?></small>
              </label>
            </div>

            <div data-provider-group="s3c-endpoint"<?php if (! $isS3CDefault) echo ' hidden'; ?>>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Endpoint', 'arun-prakash-media-offload'); ?></span>
                <input type="url" name="endpoint" value="<?php echo esc_attr((string) ($settings['endpoint'] ?? '')); ?>" placeholder="https://s3.example.com" autocomplete="off" spellcheck="false"<?php if (! $isS3CDefault) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('The S3 API base URL from your provider.', 'arun-prakash-media-offload'); ?></small>
              </label>
            </div>

            <div data-provider-group="credentials"<?php if ($isLocalDefault) echo ' hidden'; ?>>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Access key', 'arun-prakash-media-offload'); ?></span>
                <input type="text" name="access_key" value="<?php echo esc_attr((string) ($settings['access_key'] ?? '')); ?>" autocomplete="off" spellcheck="false"<?php if ($isLocalDefault) echo ' disabled'; ?> />
              </label>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Secret key', 'arun-prakash-media-offload'); ?></span>
                <input type="password" name="secret_key" value="<?php echo esc_attr((string) ($settings['secret_key'] ?? '')); ?>" autocomplete="new-password"<?php if ($isLocalDefault) echo ' disabled'; ?> />
              </label>
            </div>

            <div data-provider-group="session"<?php if ($isR2Default || $isLocalDefault) echo ' hidden'; ?>>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Session token', 'arun-prakash-media-offload'); ?> <em class="nmb-optional"><?php esc_html_e('optional', 'arun-prakash-media-offload'); ?></em></span>
                <input type="password" name="session_token" value="<?php echo esc_attr((string) ($settings['session_token'] ?? '')); ?>" autocomplete="new-password"<?php if ($isR2Default || $isLocalDefault) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('Only for temporary AWS STS credentials.', 'arun-prakash-media-offload'); ?></small>
              </label>
            </div>

            <div data-provider-group="prefix"<?php if ($isLocalDefault) echo ' hidden'; ?>>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Path prefix', 'arun-prakash-media-offload'); ?> <em class="nmb-optional"><?php esc_html_e('optional', 'arun-prakash-media-offload'); ?></em></span>
                <input type="text" name="prefix" value="<?php echo esc_attr((string) ($settings['prefix'] ?? '')); ?>" placeholder="wp-media" autocomplete="off" spellcheck="false"<?php if ($isLocalDefault) echo ' disabled'; ?> />
              </label>
            </div>
          </div>

          <div class="nmb-onboarding-actions">
            <p class="nmb-wizard-status" id="nmb-wizard-status" role="status"></p>
            <button type="button" class="button" data-wizard-test data-manual-only><?php esc_html_e('Test connection', 'arun-prakash-media-offload'); ?></button>
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to delivery', 'arun-prakash-media-offload'); ?></button>
          </div>
        </section>

        <section class="nmb-onboarding-step" data-step="delivery" aria-labelledby="nmb-step-delivery" hidden>
          <h2 id="nmb-step-delivery"><?php esc_html_e('How should visitors get your media?', 'arun-prakash-media-offload'); ?></h2>

          <div class="nmb-onboarding-preference-grid">
            <label class="nmb-onboarding-choice-card">
              <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?> />
              <div>
                <strong><?php esc_html_e('Turn on offloading', 'arun-prakash-media-offload'); ?></strong>
                <p><?php esc_html_e('New uploads are copied to your bucket once setup is done.', 'arun-prakash-media-offload'); ?></p>
              </div>
            </label>

            <label class="nmb-onboarding-choice-card">
              <input type="checkbox" name="serve_from_storage" value="1" <?php checked(!empty($settings['serve_from_storage'])); ?> />
              <div>
                <strong><?php esc_html_e('Serve media from storage', 'arun-prakash-media-offload'); ?></strong>
                <p><?php esc_html_e('Rewrite media URLs so visitors load files from your bucket or CDN.', 'arun-prakash-media-offload'); ?></p>
              </div>
            </label>

            <label class="nmb-onboarding-choice-card">
              <input type="checkbox" name="use_path_style_endpoint" value="1" <?php checked(!empty($settings['use_path_style_endpoint'])); ?> />
              <div>
                <strong><?php esc_html_e('Use path-style requests', 'arun-prakash-media-offload'); ?></strong>
                <p><?php esc_html_e('Needed by R2 and most S3-compatible services. Not needed for Amazon S3.', 'arun-prakash-media-offload'); ?></p>
              </div>
            </label>
          </div>

          <label class="nmb-onboarding-field">
            <span><?php esc_html_e('Custom domain', 'arun-prakash-media-offload'); ?> <em class="nmb-optional"><?php esc_html_e('optional', 'arun-prakash-media-offload'); ?></em></span>
            <?php
              $siteDomain = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'example.com';
              $cdnPlaceholder = 'https://media.' . $siteDomain;
            ?>
            <input type="url" name="custom_domain" value="<?php echo esc_attr((string) ($settings['custom_domain'] ?? '')); ?>" placeholder="<?php echo esc_attr($cdnPlaceholder); ?>" autocomplete="off" spellcheck="false" />
          </label>

          <div class="nmb-onboarding-actions">
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to review', 'arun-prakash-media-offload'); ?></button>
          </div>
        </section>

        <section class="nmb-onboarding-step" data-step="review" aria-labelledby="nmb-step-review" hidden>
          <h2 id="nmb-step-review"><?php esc_html_e('Review and finish', 'arun-prakash-media-offload'); ?></h2>

          <h3><?php esc_html_e('Other media plugins', 'arun-prakash-media-offload'); ?></h3>
          <?php if (!empty($state['conflicts'])) : ?>
            <ul class="nmb-status-list">
              <?php foreach ($state['conflicts'] as $plugin) : ?>
                <li>
                  <span class="dashicons <?php echo !empty($plugin['active']) ? 'dashicons-warning status-error' : 'dashicons-info status-warning'; ?>"></span>
                  <div>
                    <strong><?php echo esc_html((string) ($plugin['name'] ?? '')); ?></strong>
                    <div class="nmb-muted"><?php echo esc_html((string) ($plugin['reason'] ?? '')); ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else : ?>
            <p class="nmb-state nmb-state--ok"><?php esc_html_e('No other media offload plugins found.', 'arun-prakash-media-offload'); ?></p>
          <?php endif; ?>

          <?php if (!empty($state['has_active_conflicts'])) : ?>
            <label class="nmb-onboarding-checkbox-note">
              <input type="checkbox" name="conflict_acknowledged" value="1" />
              <span><?php esc_html_e('I understand another media offload plugin is active and I will review the warnings after setup.', 'arun-prakash-media-offload'); ?></span>
            </label>
          <?php endif; ?>

          <p class="nmb-muted"><?php esc_html_e('You can change every choice later from Settings.', 'arun-prakash-media-offload'); ?></p>

          <div class="nmb-onboarding-actions">
            <button type="submit" class="button button-primary"><?php esc_html_e('Finish setup', 'arun-prakash-media-offload'); ?></button>
          </div>
        </section>
      </form>

      <p class="nmb-wizard-import">
        <button type="button" class="nmb-link" data-quick-toggle data-label-quick="<?php esc_attr_e('Use quick connect', 'arun-prakash-media-offload'); ?>" data-label-manual="<?php esc_attr_e('Enter keys by hand', 'arun-prakash-media-offload'); ?>"><?php $quickMode === 'quick' ? esc_html_e('Enter keys by hand', 'arun-prakash-media-offload') : esc_html_e('Use quick connect', 'arun-prakash-media-offload'); ?></button>
        <label for="nmb-onboarding-config-file" class="nmb-link"><?php esc_html_e('Import settings from a Nimbus .json export', 'arun-prakash-media-offload'); ?></label>
        <input type="file" id="nmb-onboarding-config-file" accept=".json,application/json" class="screen-reader-text" data-config-import />
        <span class="nmb-config-import-hint nmb-muted" role="status"></span>
      </p>
    </div>
    <?php
  }

  private function renderOverviewTab(): void
  {
    $migration = DataMigration::status();
    ?>
    <div class="nmb-settings-shell">
      <?php if (!empty($migration['needs_migration'])) : ?>
        <?php $this->renderActivationRecoveryPanel($migration); ?>
      <?php endif; ?>

      <?php $this->renderDashboard(); ?>
    </div>
    <?php
  }

  private function renderSettingsTab(): void
  {
    $settings = $this->settings->all();
    $providerKey = (string) ($settings['provider'] ?? 's3');
    $providerLabel = $this->settings->provider_profile($providerKey)->label();
    $isLocalProvider = $providerKey === 'local';
    $deliveryLabel = $isLocalProvider
      ? __('Local debug', 'arun-prakash-media-offload')
      : (!empty($settings['serve_from_storage']) ? __('Remote', 'arun-prakash-media-offload') : __('Local', 'arun-prakash-media-offload'));
    $providerSubstat = $isLocalProvider
      ? __('No bucket required in local mode', 'arun-prakash-media-offload')
      : (string) ($settings['bucket'] ?? __('Bucket not set', 'arun-prakash-media-offload'));
    ?>
    <div class="nmb-settings-shell">
      <?php $this->renderSummaryStrip([
        [
          'label' => __('Provider', 'arun-prakash-media-offload'),
          'value' => $providerLabel,
          'value_id' => 'nmb-settings-provider-label',
          'substat' => $providerSubstat,
          'substat_id' => 'nmb-settings-provider-substat',
        ],
        [
          'label' => __('Delivery', 'arun-prakash-media-offload'),
          'value' => $deliveryLabel,
          'value_id' => 'nmb-settings-delivery-label',
          'substat' => !empty($settings['custom_domain']) ? (string) $settings['custom_domain'] : __('No custom domain configured', 'arun-prakash-media-offload'),
          'substat_id' => 'nmb-settings-delivery-substat',
        ],
        [
          'label' => __('Off-hours batch', 'arun-prakash-media-offload'),
          'value' => !empty($settings['off_hours_batch_enabled']) ? __('On', 'arun-prakash-media-offload') : __('Off', 'arun-prakash-media-offload'),
          'value_id' => 'nmb-settings-batch-label',
          'substat' => !empty($settings['off_hours_batch_time']) ? (string) $settings['off_hours_batch_time'] : __('No schedule set', 'arun-prakash-media-offload'),
          'substat_id' => 'nmb-settings-batch-substat',
        ],
      ], 'nmb-settings-summary-strip'); ?>

      <form method="post" action="options.php" autocomplete="off" class="nmb-settings-form">
        <?php settings_fields(NIMBUS_SETTINGS_GROUP); ?>

        <div class="nmb-settings-main">
            <section id="settings-core" class="nmb-settings-cluster">
              <div class="nmb-settings-cluster-header">
                <p class="nmb-eyebrow"><?php esc_html_e('Core setup', 'arun-prakash-media-offload'); ?></p>
                <h2><?php esc_html_e('Connection and delivery', 'arun-prakash-media-offload'); ?></h2>
                <p><?php esc_html_e('Everything required to connect a provider, define the bucket, and decide how media URLs are served.', 'arun-prakash-media-offload'); ?></p>
              </div>
              <?php $this->settingsRegistrar->renderSections(['nimbus_connection', 'nimbus_delivery']); ?>
            </section>

            <section id="settings-automation" class="nmb-settings-cluster">
              <div class="nmb-settings-cluster-header">
                <p class="nmb-eyebrow"><?php esc_html_e('Automation', 'arun-prakash-media-offload'); ?></p>
                <h2><?php esc_html_e('Scheduling', 'arun-prakash-media-offload'); ?></h2>
                <p><?php esc_html_e('Configure the off-hours batch window for background offloading. Path filters and cache purging can be found on the Advanced tab.', 'arun-prakash-media-offload'); ?></p>
              </div>
              <?php $this->settingsRegistrar->renderSections(['nimbus_off_hours']); ?>
            </section>

            <div class="nmb-settings-submit">
              <?php submit_button(__('Save Settings', 'arun-prakash-media-offload')); ?>
            </div>
          </div>
      </form>

      <div class="nmb-secondary-surface-grid">
        <div class="nmb-card">
          <h3><?php esc_html_e('Integration rewrites', 'arun-prakash-media-offload'); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('Plugin-specific asset roots and rewrite actions are now grouped with operational tools, where you can test and execute them without cluttering configuration.', 'arun-prakash-media-offload'); ?></p>
          <div class="nmb-actions nmb-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>"><?php esc_html_e('Manage integrations', 'arun-prakash-media-offload'); ?></a>
          </div>
        </div>
      </div>
    </div>
    <?php
  }

  private function renderAdvancedTab(): void
  {
    ?>
    <div class="nmb-settings-shell">
      <form method="post" action="options.php" autocomplete="off" class="nmb-settings-form">
        <?php settings_fields(NIMBUS_SETTINGS_GROUP); ?>

        <div class="nmb-settings-main">
          <section id="advanced-path-filters" class="nmb-settings-cluster">
            <div class="nmb-settings-cluster-header">
              <p class="nmb-eyebrow"><?php esc_html_e('Offload rules', 'arun-prakash-media-offload'); ?></p>
              <h2><?php esc_html_e('Path filters', 'arun-prakash-media-offload'); ?></h2>
              <p><?php esc_html_e('Limit which uploads paths are eligible for offload using allow and ignore pattern lists.', 'arun-prakash-media-offload'); ?></p>
            </div>
            <?php $this->settingsRegistrar->renderSections(['nimbus_path_filters']); ?>
          </section>

          <section id="advanced-cache-purging" class="nmb-settings-cluster">
            <div class="nmb-settings-cluster-header">
              <p class="nmb-eyebrow"><?php esc_html_e('Delivery', 'arun-prakash-media-offload'); ?></p>
              <h2><?php esc_html_e('Cache purging', 'arun-prakash-media-offload'); ?></h2>
              <p><?php esc_html_e('Automatically invalidate CDN and server-side page caches when media is offloaded or URLs are rewritten.', 'arun-prakash-media-offload'); ?></p>
            </div>
            <?php $this->settingsRegistrar->renderSections(['nimbus_cache_purging']); ?>
          </section>

          <div class="nmb-settings-submit">
            <?php submit_button(__('Save Advanced Settings', 'arun-prakash-media-offload')); ?>
          </div>
        </div>
      </form>
    </div>
    <?php
  }

  private function renderToolsTab(): void
  {
    $migration = DataMigration::status();
    ?>
    <div class="nmb-settings-shell">
      <div class="nmb-panel-toolbar nmb-card" style="display:flex;align-items:center;gap:12px;padding:12px 16px;margin-bottom:4px;">
        <strong><?php esc_html_e('Connectivity', 'arun-prakash-media-offload'); ?></strong>
        <button type="button" id="nmb-test-connection" class="button button-secondary"><?php esc_html_e('Test Connection', 'arun-prakash-media-offload'); ?></button>
        <span id="nmb-connection-status"></span>
      </div>
      <div id="mo-connection-results" class="nmb-connection-results" hidden></div>

      <?php $this->renderBulkJobsPanel(); ?>
      <?php if (!empty($migration['needs_migration'])) : ?>
        <?php $this->renderActivationRecoveryPanel($migration); ?>
      <?php endif; ?>
      <?php $this->renderAttachmentInspector(); ?>
      <?php $this->renderUrlUpdatesPanel(); ?>
      <?php $this->renderConfigPortPanel(); ?>
    </div>
    <?php
  }

  private function renderBulkJobsPanel(): void
  {
    // BulkJobManager is registered unconditionally in Bootstrap\Plugin::bootFeatures().
    $bulkJobManager = apply_filters('nimbus/bulk_job_manager', null);
    if ($bulkJobManager === null) {
      return;
    }

    $state = $bulkJobManager->state();
    $job = !empty($state['current']['id']) ? $state['current'] : $state['last'];
    $hasJob = !empty($job['id']);
    ?>
    <section class="nmb-card nmb-bulk-job-shell" id="nmb-bulk-job-app">
      <div class="nmb-bulk-job-header">
        <div>
          <h3><?php esc_html_e('Bulk media jobs', 'arun-prakash-media-offload'); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('This panel polls the current queue state and lets you start, pause, resume, or retry background jobs.', 'arun-prakash-media-offload'); ?></p>
        </div>
        <span id="nmb-job-status-badge" class="nmb-inline-badge <?php echo $hasJob ? esc_attr($this->jobBadgeClass((string) ($job['status'] ?? 'idle'))) : ''; ?>"><?php echo esc_html($hasJob ? (string) ($job['status_label'] ?? __('Idle', 'arun-prakash-media-offload')) : __('Idle', 'arun-prakash-media-offload')); ?></span>
      </div>

      <div class="nmb-bulk-job-grid">
        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Current job', 'arun-prakash-media-offload'); ?></h3>
          <div class="nmb-stat" id="nmb-job-label"><?php echo esc_html($hasJob ? (string) ($job['type_label'] ?? __('No active job', 'arun-prakash-media-offload')) : __('No active job', 'arun-prakash-media-offload')); ?></div>
          <div class="nmb-substat" id="nmb-job-message"><?php echo esc_html($hasJob ? (string) ($job['last_message'] ?? '') : __('Choose a bulk task to begin.', 'arun-prakash-media-offload')); ?></div>
          <div class="nmb-progress nmb-bulk-job-progress"><div id="nmb-job-progress-bar" class="nmb-bar" style="width: <?php echo esc_attr((string) ($job['percent_complete'] ?? 0)); ?>%"></div></div>
          <div class="nmb-substat" id="nmb-job-progress-text"><?php /* translators: %1$d: job completion percentage */ echo esc_html(sprintf(__('%1$d%% complete', 'arun-prakash-media-offload'), (int) ($job['percent_complete'] ?? 0))); ?></div>
        </div>

        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Queue state', 'arun-prakash-media-offload'); ?></h3>
          <ul class="nmb-status-list">
            <li><strong><?php esc_html_e('Processed', 'arun-prakash-media-offload'); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-processed"><?php echo number_format((int) ($job['processed_items'] ?? 0)); ?></span> / <span id="nmb-job-total"><?php echo number_format((int) ($job['total_items'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Succeeded', 'arun-prakash-media-offload'); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-success"><?php echo number_format((int) ($job['success_count'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Failed', 'arun-prakash-media-offload'); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-failed"><?php echo number_format((int) ($job['failed_count'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Skipped', 'arun-prakash-media-offload'); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-skipped"><?php echo number_format((int) ($job['skipped_count'] ?? 0)); ?></span></li>
          </ul>
        </div>

        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Actions', 'arun-prakash-media-offload'); ?></h3>
          <div class="nmb-bulk-job-actions">
            <button type="button" class="button button-primary" data-job-action="start" data-job-type="offload_existing"><?php esc_html_e('Offload existing library', 'arun-prakash-media-offload'); ?></button>
            <button type="button" class="button" data-job-action="start" data-job-type="restore_local"><?php esc_html_e('Restore local copies', 'arun-prakash-media-offload'); ?></button>
            <button type="button" class="button" data-job-action="pause"><?php esc_html_e('Pause', 'arun-prakash-media-offload'); ?></button>
            <button type="button" class="button" data-job-action="resume"><?php esc_html_e('Resume', 'arun-prakash-media-offload'); ?></button>
            <button type="button" class="button" data-job-action="retry-failed"><?php esc_html_e('Retry failed items', 'arun-prakash-media-offload'); ?></button>
          </div>
          <p class="nmb-muted"><?php esc_html_e('Pause/resume works on the current job. Retry failed items uses the failed subset from the most recent completed job.', 'arun-prakash-media-offload'); ?></p>
        </div>
      </div>
    </section>
    <?php
  }

  private function renderActivationRecoveryPanel(array $migration): void
  {
    $legacyOptions = (array) ($migration['legacy_options'] ?? []);
    $legacyMetaKeys = (array) ($migration['legacy_meta_keys'] ?? []);
    $hasAttachmentPayloads = !empty($migration['has_legacy_attachment_payloads']);
    ?>
    <section class="nmb-card nmb-activation-recovery-shell">
      <div class="nmb-bulk-job-header">
        <div>
          <h3><?php esc_html_e('Activation recovery', 'arun-prakash-media-offload'); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('An earlier activation/upgrade migration did not finish. Resume it here in small batches instead of forcing the entire site through one heavy request.', 'arun-prakash-media-offload'); ?></p>
        </div>
        <span class="nmb-inline-badge is-warning"><?php esc_html_e('Recovery needed', 'arun-prakash-media-offload'); ?></span>
      </div>

      <ul class="nmb-status-list">
        <li>
          <span class="dashicons <?php echo !empty($migration['scheduled']) ? 'dashicons-clock status-warning' : 'dashicons-update status-warning'; ?>"></span>
          <strong><?php esc_html_e('Background follow-up', 'arun-prakash-media-offload'); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo !empty($migration['scheduled']) ? esc_html__('Scheduled', 'arun-prakash-media-offload') : esc_html__('Not scheduled yet', 'arun-prakash-media-offload'); ?>
        </li>
        <li>
          <span class="dashicons dashicons-database"></span>
          <strong><?php esc_html_e('Legacy metadata keys', 'arun-prakash-media-offload'); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo number_format(count($legacyMetaKeys)); ?>
        </li>
        <li>
          <span class="dashicons dashicons-admin-settings"></span>
          <strong><?php esc_html_e('Legacy options', 'arun-prakash-media-offload'); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo number_format(count($legacyOptions)); ?>
        </li>
        <li>
          <span class="dashicons dashicons-format-image"></span>
          <strong><?php esc_html_e('Attachment metadata payloads', 'arun-prakash-media-offload'); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo $hasAttachmentPayloads ? esc_html__('Still pending', 'arun-prakash-media-offload') : esc_html__('Clear', 'arun-prakash-media-offload'); ?>
        </li>
      </ul>

      <div class="nmb-actions nmb-actions-left">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RESUME_MIGRATION); ?>
          <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RESUME_MIGRATION); ?>" />
          <button type="submit" class="button button-primary"><?php esc_html_e('Resume activation recovery', 'arun-prakash-media-offload'); ?></button>
        </form>
      </div>
    </section>
    <?php
  }

  private function renderAttachmentInspector(): void
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only GET parameter used for display/navigation only (tab, filter, or self-generated redirect notice) -- no state-changing action is performed, so no nonce applies.
    $attachmentId = isset($_GET['attachment_id']) ? max(0, (int) $_GET['attachment_id']) : 0;
    if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
      return;
    }

    $status = $this->uploadHandler->get_attachment_status($attachmentId);
    ?>
    <section class="nmb-card nmb-attachment-inspector">
      <h3><?php esc_html_e('Attachment status', 'arun-prakash-media-offload'); ?></h3>
      <ul class="nmb-status-list">
        <li><strong><?php esc_html_e('Attachment ID', 'arun-prakash-media-offload'); ?></strong><span class="nmb-spacer"></span><?php echo number_format($attachmentId); ?></li>
        <li><strong><?php esc_html_e('Status', 'arun-prakash-media-offload'); ?></strong><span class="nmb-spacer"></span><span class="nmb-inline-badge <?php echo esc_attr($this->jobBadgeClass((string) ($status['status'] ?? 'local'))); ?>"><?php echo esc_html((string) ($status['label'] ?? __('Unknown', 'arun-prakash-media-offload'))); ?></span></li>
      </ul>
      <?php if (!empty($status['reason'])) : ?>
        <p class="nmb-muted"><?php echo esc_html((string) $status['reason']); ?></p>
      <?php endif; ?>
    </section>
    <?php
  }

  private function renderIntegrationsTab(): void
  {
    $summary = $this->integrationSummary();
    $items = $summary['items'] ?? [];
    ?>
    <div class="nmb-settings-shell">
      <div class="nmb-card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
          <div>
            <h3 style="margin:0 0 4px;"><?php esc_html_e('Plugin integrations', 'arun-prakash-media-offload'); ?></h3>
            <p class="nmb-muted" style="margin:0;"><?php esc_html_e('Nimbus detects active plugins that store assets outside the standard wp-content/uploads path. Enable or disable each integration below.', 'arun-prakash-media-offload'); ?></p>
          </div>
          <span class="nmb-inline-badge">
            <?php echo number_format((int) ($summary['detected'] ?? 0)); ?> <?php esc_html_e('detected', 'arun-prakash-media-offload'); ?>
          </span>
        </div>
      </div>

      <div class="nmb-card" style="padding:0;overflow:hidden;">
        <table class="nmb-integrations-table widefat" style="border:none;">
          <thead>
            <tr>
              <th><?php esc_html_e('Plugin', 'arun-prakash-media-offload'); ?></th>
              <th><?php esc_html_e('State', 'arun-prakash-media-offload'); ?></th>
              <th><?php esc_html_e('Asset paths', 'arun-prakash-media-offload'); ?></th>
              <th><?php esc_html_e('Coverage', 'arun-prakash-media-offload'); ?></th>
              <th><?php esc_html_e('Actions', 'arun-prakash-media-offload'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item) : ?>
              <?php
              $isEnabled = !empty($item['enabled']);
              $isActive  = !empty($item['plugin_active']);
              $isInstalled = !empty($item['plugin_installed']);
              $itemKey   = (string) ($item['key'] ?? '');
              // Skip plugins that are not installed at all — nothing actionable to show
              if (!$isInstalled && !$isActive) {
                continue;
              }
              if ($isEnabled && $isActive) {
                $stateClass = 'is-active';
                $stateLabel = __('Active', 'arun-prakash-media-offload');
              } elseif (!$isEnabled) {
                $stateClass = 'is-disabled';
                $stateLabel = __('Disabled', 'arun-prakash-media-offload');
              } elseif ($isInstalled) {
                $stateClass = 'is-installed';
                $stateLabel = __('Installed', 'arun-prakash-media-offload');
              } else {
                $stateClass = 'is-not-installed';
                $stateLabel = __('Not installed', 'arun-prakash-media-offload');
              }
              ?>
              <tr>
                <td>
                  <div class="nmb-int-label"><?php echo esc_html((string) ($item['label'] ?? '')); ?></div>
                  <?php if (!empty($item['notes'])) : ?>
                    <div class="nmb-int-notes"><?php echo esc_html((string) $item['notes']); ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="nmb-int-state-badge <?php echo esc_attr($stateClass); ?>"><?php echo esc_html($stateLabel); ?></span>
                </td>
                <td>
                  <?php if (!empty($item['directories'])) : ?>
                    <div class="nmb-int-dirs">
                      <?php foreach ((array) $item['directories'] as $dir) : ?>
                        <code><?php echo esc_html((string) $dir); ?></code>
                      <?php endforeach; ?>
                    </div>
                  <?php else : ?>
                    <span class="nmb-muted">&mdash;</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($item['rewrite_supported'])) : ?>
                    <span class="nmb-int-state-badge is-active"><?php esc_html_e('Rewrite supported', 'arun-prakash-media-offload'); ?></span>
                  <?php else : ?>
                    <span class="nmb-muted"><?php esc_html_e('Detection only', 'arun-prakash-media-offload'); ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                      <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION); ?>
                      <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION); ?>" />
                      <input type="hidden" name="integration" value="<?php echo esc_attr($itemKey); ?>" />
                      <button type="submit" class="button button-small">
                        <?php echo $isEnabled ? esc_html__('Disable', 'arun-prakash-media-offload') : esc_html__('Enable', 'arun-prakash-media-offload'); ?>
                      </button>
                    </form>
                    <?php if (!empty($item['rewrite_supported']) && !empty($item['rewrite_action_label'])) : ?>
                      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION); ?>" />
                        <input type="hidden" name="integration" value="<?php echo esc_attr($itemKey); ?>" />
                        <button type="submit" class="button button-small button-primary" <?php disabled(empty($item['rewrite_ready'])); ?>>
                          <?php echo esc_html((string) ($item['rewrite_action_label'] ?? __('Run rewrite', 'arun-prakash-media-offload'))); ?>
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($items)) : ?>
              <tr>
                <td colspan="5" style="text-align:center;color:var(--mo-text-soft);padding:24px;">
                  <?php esc_html_e('No integrations detected. Install a supported plugin to see it listed here.', 'arun-prakash-media-offload'); ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
  }

  private function renderDashboard(): void
  {
    $stats = $this->statistics->get_stats();
    $deepStats = $this->statistics->get_deep_stats();
    $usage = $deepStats['usage'] ?? $this->statistics->get_disk_usage_estimate();
    $remoteStats = $deepStats['remote'] ?? $this->statistics->get_remote_stats();
    $costComparison = $deepStats['cost_comparison'] ?? [];
    $onboarding = $this->onboardingManager->state();
    $integrations = $this->integrationSummary();
    $pendingUrls = $this->countPendingUrlUpdates();

    $storageSize = isset($remoteStats['size_bytes']) ? $remoteStats['size_bytes'] : $usage['offloaded_size'];
    $storageCount = isset($remoteStats['object_count']) ? $remoteStats['object_count'] : $stats['offloaded'];
    $localOnly = (int) ($stats['pending'] ?? 0);

    $currentProvider = $this->settings->provider();
    $currentScenario = null;
    foreach ($costComparison['scenarios'] ?? [] as $scenario) {
      if (($scenario['provider_key'] ?? '') === $currentProvider) {
        $currentScenario = $scenario;
        break;
      }
    }
    $storageCost = $currentScenario !== null && $currentScenario['estimated_monthly_total'] !== null
      ? $currentScenario['estimated_monthly_total']
      : ($storageSize / 1073741824 * 0.023);
    $providerLabel = $this->settings->provider_profile($currentProvider)->label();

    // Seven days ending today, including days without uploads.
    $uploadsByDate = [];
    foreach ((array) ($stats['daily'] ?? []) as $day) {
      $uploadsByDate[(string) $day->date] = (int) $day->count;
    }
    $today = current_time('timestamp');
    $days = [];
    for ($offset = 6; $offset >= 0; $offset--) {
      $timestamp = $today - ($offset * DAY_IN_SECONDS);
      $date = gmdate('Y-m-d', $timestamp);
      $days[] = [
        'label' => $offset === 0 ? __('Today', 'arun-prakash-media-offload') : wp_date('D', $timestamp),
        'date' => $date,
        'count' => $uploadsByDate[$date] ?? 0,
        'today' => $offset === 0,
      ];
    }
    $maxUploads = max(1, max(array_column($days, 'count')));

    $credentialsOk = $currentProvider === 'local' || $this->settings->has_valid_credentials();
    $servingRemote = $this->settings->should_serve_from_storage();
    $hasConflicts = !empty($onboarding['has_active_conflicts']);
    $detectedIntegrations = array_values(array_filter((array) ($integrations['items'] ?? []), static function (array $item): bool {
      return !empty($item['plugin_installed']) || !empty($item['directories']) || !empty($item['table_count']);
    }));
    ?>
    <div class="nmb-dashboard">
      <div class="nmb-stats-bar">
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Library assets', 'arun-prakash-media-offload'); ?></span>
          <span class="nmb-stat-cell__value"><?php echo esc_html(number_format_i18n((int) $stats['total'])); ?></span>
          <span class="nmb-stat-cell__sub"><?php esc_html_e('Attachments in WordPress', 'arun-prakash-media-offload'); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Offloaded', 'arun-prakash-media-offload'); ?></span>
          <span class="nmb-stat-cell__value"><?php echo esc_html(number_format_i18n((int) $stats['offloaded'])); ?></span>
          <span class="nmb-stat-cell__sub"><?php /* translators: %s: percentage of attachments linked to remote storage */ echo esc_html(sprintf(__('%s%% linked to storage', 'arun-prakash-media-offload'), number_format_i18n((float) $stats['percent']))); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Remote storage', 'arun-prakash-media-offload'); ?></span>
          <span class="nmb-stat-cell__value"><?php echo esc_html(size_format($storageSize) ?: '0 B'); ?></span>
          <span class="nmb-stat-cell__sub"><?php /* translators: %s: number of remote objects tracked */ echo esc_html(sprintf(_n('%s object tracked', '%s objects tracked', (int) $storageCount, 'arun-prakash-media-offload'), number_format_i18n((int) $storageCount))); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Est. monthly cost', 'arun-prakash-media-offload'); ?></span>
          <span class="nmb-stat-cell__value">$<?php echo esc_html(number_format((float) $storageCost, 2)); ?></span>
          <span class="nmb-stat-cell__sub"><?php /* translators: %s: storage provider name */ echo esc_html(sprintf(__('%s estimate', 'arun-prakash-media-offload'), $providerLabel)); ?></span>
        </div>
      </div>

      <div class="nmb-overview-grid">
        <section class="nmb-card nmb-library-card" aria-labelledby="nmb-library-title">
          <div class="nmb-card-head">
            <div>
              <h2 id="nmb-library-title"><?php esc_html_e('Library in storage', 'arun-prakash-media-offload'); ?></h2>
              <p class="nmb-muted">
                <?php if ($localOnly > 0) : ?>
                  <?php /* translators: %s: number of files */ echo esc_html(sprintf(_n('%s file is still served from this server.', '%s files are still served from this server.', $localOnly, 'arun-prakash-media-offload'), number_format_i18n($localOnly))); ?>
                <?php else : ?>
                  <?php esc_html_e('Every file is in storage.', 'arun-prakash-media-offload'); ?>
                <?php endif; ?>
              </p>
            </div>
            <?php if ($localOnly > 0 && $currentProvider !== 'local') : ?>
              <a class="button button-primary" href="<?php echo esc_url($this->adminTabUrl('tools', 'nmb-bulk-job-app')); ?>">
                <?php /* translators: %s: number of files */ echo esc_html(sprintf(_n('Offload %s file', 'Offload %s files', $localOnly, 'arun-prakash-media-offload'), number_format_i18n($localOnly))); ?>
              </a>
            <?php endif; ?>
          </div>

          <div class="nmb-meter" role="progressbar" aria-label="<?php esc_attr_e('Share of the library in storage', 'arun-prakash-media-offload'); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) (int) $stats['percent']); ?>">
            <span class="nmb-meter__fill" style="width:<?php echo esc_attr((string) (float) $stats['percent']); ?>%"></span>
          </div>

          <h3 class="nmb-chart-title"><?php esc_html_e('Uploads, last 7 days', 'arun-prakash-media-offload'); ?></h3>
          <ol class="nmb-chart">
            <?php foreach ($days as $day) : ?>
              <li class="nmb-bar-group<?php echo $day['today'] ? ' is-today' : ''; ?>" title="<?php echo esc_attr($day['date'] . ': ' . $day['count']); ?>">
                <span class="nmb-chart-bar" style="height:<?php echo esc_attr((string) max(4, (int) round($day['count'] / $maxUploads * 100))); ?>%"></span>
                <span class="nmb-chart-label"><?php echo esc_html($day['label']); ?><span class="screen-reader-text">: <?php /* translators: %s: number of uploads */ echo esc_html(sprintf(_n('%s upload', '%s uploads', $day['count'], 'arun-prakash-media-offload'), number_format_i18n($day['count']))); ?></span></span>
              </li>
            <?php endforeach; ?>
          </ol>
        </section>

        <section class="nmb-card nmb-health-card" id="overview-setup-health" aria-labelledby="nmb-health-title">
          <h2 id="nmb-health-title"><?php esc_html_e('Setup health', 'arun-prakash-media-offload'); ?></h2>
          <dl class="nmb-rows">
            <div>
              <dt><?php esc_html_e('Credentials', 'arun-prakash-media-offload'); ?></dt>
              <dd class="nmb-state <?php echo $credentialsOk ? 'nmb-state--ok' : 'nmb-state--error'; ?>"><?php echo $credentialsOk ? esc_html__('Configured', 'arun-prakash-media-offload') : esc_html__('Needs attention', 'arun-prakash-media-offload'); ?></dd>
            </div>
            <div>
              <dt><?php esc_html_e('Delivery', 'arun-prakash-media-offload'); ?></dt>
              <dd class="nmb-state <?php echo $servingRemote ? 'nmb-state--ok' : ''; ?>"><?php echo $servingRemote ? esc_html__('Serving from storage', 'arun-prakash-media-offload') : esc_html__('Serving local URLs', 'arun-prakash-media-offload'); ?></dd>
            </div>
            <div>
              <dt><?php esc_html_e('Pending URL updates', 'arun-prakash-media-offload'); ?></dt>
              <dd class="nmb-num"><?php echo esc_html(number_format_i18n($pendingUrls)); ?></dd>
            </div>
            <div>
              <dt><?php esc_html_e('Plugin conflicts', 'arun-prakash-media-offload'); ?></dt>
              <dd class="nmb-state <?php echo $hasConflicts ? 'nmb-state--error' : 'nmb-state--ok'; ?>"><?php echo $hasConflicts ? esc_html__('Review required', 'arun-prakash-media-offload') : esc_html__('None', 'arun-prakash-media-offload'); ?></dd>
            </div>
            <div>
              <dt><?php esc_html_e('Integrations', 'arun-prakash-media-offload'); ?></dt>
              <dd class="nmb-integration-logos">
                <?php foreach ($detectedIntegrations as $item) :
                  $key = sanitize_key((string) ($item['key'] ?? ''));
                  if (!in_array($key, ['woocommerce', 'elementor'], true)) {
                    continue;
                  }
                  ?>
                  <img src="<?php echo esc_url(NIMBUS_URL . 'assets/img/logos/' . $key . '.svg'); ?>" width="18" height="18" alt="" />
                <?php endforeach; ?>
                <?php /* translators: %s: number of detected integrations */ echo esc_html(sprintf(__('%s detected', 'arun-prakash-media-offload'), number_format_i18n(count($detectedIntegrations)))); ?>
              </dd>
            </div>
          </dl>
          <?php if ($hasConflicts) : ?>
            <a class="nmb-link" href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>"><?php esc_html_e('Review conflicts', 'arun-prakash-media-offload'); ?></a>
          <?php elseif (!$credentialsOk) : ?>
            <a class="nmb-link" href="<?php echo esc_url($this->adminTabUrl('settings', 'settings-core')); ?>"><?php esc_html_e('Add credentials', 'arun-prakash-media-offload'); ?></a>
          <?php endif; ?>
        </section>
      </div>

      <?php if (!empty($costComparison['scenarios'])) : ?>
      <section class="nmb-card nmb-card--flush nmb-cost-card" aria-labelledby="nmb-cost-title">
        <div class="nmb-cost-card-header">
          <h2 id="nmb-cost-title"><?php esc_html_e('Provider cost comparison', 'arun-prakash-media-offload'); ?></h2>
          <p class="nmb-muted"><?php echo esc_html(sprintf(
            /* translators: 1: GB stored, 2: number of files, 3: GB egress estimate */
            __('%1$s GB stored · %2$s files · est. %3$s GB monthly egress', 'arun-prakash-media-offload'),
            number_format_i18n((float) ($costComparison['storage_gb'] ?? 0), 1),
            number_format_i18n((int) ($costComparison['offloaded_count'] ?? 0)),
            number_format_i18n((float) ($costComparison['egress_gb_est'] ?? 0), 1)
          )); ?></p>
        </div>

        <div class="nmb-cost-table-wrap">
          <table class="nmb-cost-table">
            <thead>
              <tr>
                <th scope="col"><?php esc_html_e('Provider', 'arun-prakash-media-offload'); ?></th>
                <th scope="col"><?php esc_html_e('Storage', 'arun-prakash-media-offload'); ?></th>
                <th scope="col"><?php esc_html_e('Egress', 'arun-prakash-media-offload'); ?></th>
                <th scope="col" class="nmb-cost-table__num"><?php esc_html_e('Est. monthly', 'arun-prakash-media-offload'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($costComparison['scenarios'] as $scenario) :
                $scenarioKey = (string) ($scenario['provider_key'] ?? '');
                $isCurrent = $scenarioKey === $currentProvider;
              ?>
                <tr class="<?php echo $isCurrent ? 'nmb-cost-table__row--current' : ''; ?>">
                  <th scope="row" class="nmb-cost-table__provider">
                    <?php $this->renderProviderIcon($scenarioKey); ?>
                    <?php if (!empty($scenario['pricing_url'])) : ?>
                      <a href="<?php echo esc_url($scenario['pricing_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($scenario['label']); ?></a>
                    <?php else : ?>
                      <?php echo esc_html($scenario['label']); ?>
                    <?php endif; ?>
                    <?php if ($isCurrent) : ?>
                      <span class="nmb-badge nmb-badge--active"><?php esc_html_e('Active', 'arun-prakash-media-offload'); ?></span>
                    <?php endif; ?>
                  </th>
                  <?php if ($scenario['estimated_monthly_total'] === null) : ?>
                    <td colspan="3" class="nmb-muted"><?php esc_html_e('Custom pricing, varies by vendor', 'arun-prakash-media-offload'); ?></td>
                  <?php else : ?>
                    <td>
                      <?php if ((float) $scenario['flat_monthly'] > 0) : ?>
                        <?php /* translators: %s: flat monthly fee */ echo esc_html(sprintf(__('$%s base', 'arun-prakash-media-offload'), number_format((float) $scenario['flat_monthly'], 2))); ?>
                      <?php else : ?>
                        $<?php echo esc_html(number_format((float) $scenario['storage_cost'], 2)); ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ((float) $scenario['egress_cost'] === 0.0) : ?>
                        <?php echo (float) $scenario['flat_monthly'] > 0 ? esc_html__('Included', 'arun-prakash-media-offload') : esc_html__('Free', 'arun-prakash-media-offload'); ?>
                      <?php else : ?>
                        $<?php echo esc_html(number_format((float) $scenario['egress_cost'], 2)); ?>
                      <?php endif; ?>
                    </td>
                    <td class="nmb-cost-table__num nmb-cost-table__total">$<?php echo esc_html(number_format((float) $scenario['estimated_monthly_total'], 2)); ?></td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="nmb-muted nmb-cost-disclaimer"><?php esc_html_e('Estimates only. Check current pricing with each provider.', 'arun-prakash-media-offload'); ?></p>
      </section>
      <?php endif; ?>
    </div>
    <?php
  }

  private function renderLogsTab(): void
  {
    $settings       = $this->settings->all();
    $stats          = $this->logger->stats();
    // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only GET parameter used for display/navigation only (tab, filter, or self-generated redirect notice) -- no state-changing action is performed, so no nonce applies.
    $selectedLevel  = isset($_GET['log_level'])  ? sanitize_key((string) wp_unslash($_GET['log_level']))          : '';
    $selectedSource = isset($_GET['log_source']) ? sanitize_key((string) wp_unslash($_GET['log_source']))         : '';
    $search         = isset($_GET['log_search']) ? sanitize_text_field((string) wp_unslash($_GET['log_search']))  : '';
    $limit          = isset($_GET['log_limit'])  ? max(25, min(1000, (int) $_GET['log_limit']))                   : 200;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing
    $entries        = $this->logger->queryEntries([
      'level'  => $selectedLevel,
      'source' => $selectedSource,
      'search' => $search,
      'limit'  => $limit,
    ]);
    $sources      = array_keys((array) ($stats['by_source'] ?? []));
    $total        = (int) ($stats['total'] ?? 0);
    $errorCount   = (int) (($stats['by_level']['error'] ?? 0) + ($stats['by_level']['critical'] ?? 0));
    $warningCount = (int) ($stats['by_level']['warning'] ?? 0);
    $loggerOn     = !empty($settings['logging_enabled']);
    $minLevel     = strtoupper((string) ($settings['log_level'] ?? 'INFO'));
    ?>
    <div class="nmb-settings-shell" id="logs-panel">

      <!-- ─── Page header: chips + actions ────────────────────── -->
      <div class="nmb-log-header">
        <div class="nmb-log-header-chips">
          <span class="nmb-log-chip">
            <strong class="nmb-log-chip-value"><?php echo esc_html(number_format($total)); ?></strong>
            <span class="nmb-log-chip-label"><?php esc_html_e('entries', 'arun-prakash-media-offload'); ?></span>
          </span>
          <?php if ($warningCount > 0) : ?>
          <span class="nmb-log-chip is-warn">
            <strong class="nmb-log-chip-value"><?php echo esc_html(number_format($warningCount)); ?></strong>
            <span class="nmb-log-chip-label"><?php esc_html_e('warnings', 'arun-prakash-media-offload'); ?></span>
          </span>
          <?php endif; ?>
          <?php if ($errorCount > 0) : ?>
          <span class="nmb-log-chip is-error">
            <strong class="nmb-log-chip-value"><?php echo esc_html(number_format($errorCount)); ?></strong>
            <span class="nmb-log-chip-label"><?php esc_html_e('errors', 'arun-prakash-media-offload'); ?></span>
          </span>
          <?php endif; ?>
          <span class="nmb-log-chip <?php echo $loggerOn ? 'is-ok' : 'is-muted'; ?>">
            <?php echo $loggerOn ? esc_html__('Logging on', 'arun-prakash-media-offload') : esc_html__('Logging off', 'arun-prakash-media-offload'); ?>
            <span class="nmb-log-chip-label">· <?php echo esc_html($minLevel); ?></span>
          </span>
        </div>
        <div class="nmb-log-header-actions">
          <button type="button"
                  class="button button-secondary nmb-log-settings-toggle"
                  aria-expanded="false"
                  aria-controls="nmb-log-settings-panel">
            <?php esc_html_e('Logging settings', 'arun-prakash-media-offload'); ?>
          </button>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_CLEAR_LOGS); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_CLEAR_LOGS); ?>" />
            <button type="submit"
                    class="button button-link nmb-log-clear-btn"
                    onclick="return confirm('<?php echo esc_js(__('Delete all plugin log entries? This cannot be undone.', 'arun-prakash-media-offload')); ?>');">
              <?php esc_html_e('Clear logs', 'arun-prakash-media-offload'); ?>
            </button>
          </form>
        </div>
      </div>

      <!-- ─── Collapsible logging settings ────────────────────── -->
      <div class="nmb-card nmb-log-settings-panel" id="nmb-log-settings-panel" hidden>
        <form method="post" action="options.php" autocomplete="off" class="nmb-settings-form">
          <?php
          settings_fields(NIMBUS_SETTINGS_GROUP);
          $this->settingsRegistrar->renderSections($this->settingsRegistrar->getSectionIdsForTab('logs'));
          ?>
          <div class="nmb-settings-submit">
            <?php submit_button(__('Save Logging Settings', 'arun-prakash-media-offload')); ?>
          </div>
        </form>
      </div>

      <!-- ─── Filter toolbar ───────────────────────────────────── -->
      <section class="nmb-card nmb-log-filter-card">
        <form method="get" class="nmb-log-filter-bar">
          <input type="hidden" name="page" value="<?php echo esc_attr(NIMBUS_SLUG); ?>" />
          <input type="hidden" name="tab" value="logs" />
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Level', 'arun-prakash-media-offload'); ?></span>
            <select name="log_level">
              <option value=""><?php esc_html_e('All levels', 'arun-prakash-media-offload'); ?></option>
              <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) : ?>
                <option value="<?php echo esc_attr($level); ?>" <?php selected($selectedLevel, $level); ?>><?php echo esc_html(strtoupper($level)); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Source', 'arun-prakash-media-offload'); ?></span>
            <select name="log_source">
              <option value=""><?php esc_html_e('All sources', 'arun-prakash-media-offload'); ?></option>
              <?php foreach ($sources as $src) : ?>
                <option value="<?php echo esc_attr((string) $src); ?>" <?php selected($selectedSource, (string) $src); ?>><?php echo esc_html((string) $src); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="nmb-log-filter-item nmb-log-filter-item--search">
            <span><?php esc_html_e('Search', 'arun-prakash-media-offload'); ?></span>
            <input type="search" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('message, event, code…', 'arun-prakash-media-offload'); ?>" />
          </label>
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Rows', 'arun-prakash-media-offload'); ?></span>
            <select name="log_limit">
              <?php foreach ([50, 100, 200, 500, 1000] as $option) : ?>
                <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($limit, $option); ?>><?php echo esc_html((string) $option); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="nmb-log-filter-item nmb-log-filter-btns">
            <span aria-hidden="true"></span>
            <div>
              <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'arun-prakash-media-offload'); ?></button>
              <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('logs')); ?>"><?php esc_html_e('Reset', 'arun-prakash-media-offload'); ?></a>
            </div>
          </div>
        </form>
      </section>

      <!-- ─── Log table ────────────────────────────────────────── -->
      <section class="nmb-card nmb-log-viewer-card">
        <?php if (empty($entries)) : ?>
          <p class="nmb-muted"><?php esc_html_e('No log entries match the current filters.', 'arun-prakash-media-offload'); ?></p>
        <?php else : ?>
          <div class="nmb-log-table-wrap">
            <table class="widefat striped nmb-log-table">
              <thead>
                <tr>
                  <th style="width:155px;"><?php esc_html_e('Time', 'arun-prakash-media-offload'); ?></th>
                  <th style="width:90px;"><?php esc_html_e('Level', 'arun-prakash-media-offload'); ?></th>
                  <th style="width:120px;"><?php esc_html_e('Source', 'arun-prakash-media-offload'); ?></th>
                  <th style="width:160px;"><?php esc_html_e('Event / code', 'arun-prakash-media-offload'); ?></th>
                  <th><?php esc_html_e('Message', 'arun-prakash-media-offload'); ?></th>
                  <th style="width:200px;"><?php esc_html_e('Context', 'arun-prakash-media-offload'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($entries as $entry) : ?>
                  <tr>
                    <td class="nmb-muted" style="white-space:nowrap;"><?php echo esc_html((string) ($entry['datetime'] ?? '')); ?></td>
                    <td><span class="nmb-log-level-badge is-<?php echo esc_attr((string) ($entry['level_key'] ?? 'info')); ?>"><?php echo esc_html((string) ($entry['level'] ?? 'INFO')); ?></span></td>
                    <td><code><?php echo esc_html((string) ($entry['source'] ?? 'plugin')); ?></code></td>
                    <td>
                      <?php if (!empty($entry['event'])) : ?>
                        <code><?php echo esc_html((string) $entry['event']); ?></code>
                      <?php endif; ?>
                      <?php if (!empty($entry['code'])) : ?>
                        <div class="nmb-muted"><code><?php echo esc_html((string) $entry['code']); ?></code></div>
                      <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) ($entry['message'] ?? '')); ?></td>
                    <td>
                      <?php if (!empty($entry['context'])) : ?>
                        <details class="nmb-log-context-details">
                          <summary><?php esc_html_e('View context', 'arun-prakash-media-offload'); ?></summary>
                          <pre><?php echo esc_html(wp_json_encode($entry['context'] ?? [], JSON_PRETTY_PRINT)); ?></pre>
                        </details>
                      <?php else : ?>
                        <span class="nmb-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    </div>
    <?php
  }

  private function renderUrlUpdatesPanel(): void
  {
    $pending = $this->countPendingUrlUpdates();
    $stats = $this->statistics->get_stats();
    $isEnabled = $this->settings->is_enabled();
    $hasCredentials = $this->settings->has_valid_credentials();
    ?>
    <div class="nmb-dashboard">
      <?php
      // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only GET parameters used for display only (a self-generated redirect result notice) -- no state-changing action is performed here, so no nonce applies.
      ?>
      <?php if (isset($_GET['ptmo_url_update']) && (string) $_GET['ptmo_url_update'] === 'error') : ?>
        <?php
        $reason = isset($_GET['ptmo_url_update_reason']) ? sanitize_key((string) wp_unslash($_GET['ptmo_url_update_reason'])) : '';
        $message = __('URL update could not be started.', 'arun-prakash-media-offload');

        if ($reason === 'disabled') {
          $message = __('Media offload is currently disabled. Enable it first in Settings.', 'arun-prakash-media-offload');
        } elseif ($reason === 'credentials') {
          $message = __('Missing or invalid storage credentials. Please verify your settings first.', 'arun-prakash-media-offload');
        } elseif ($reason === 'connection') {
          $message = __('Storage connection test failed (authentication/signing). Verify provider, endpoint, region, and keys before retrying.', 'arun-prakash-media-offload');
        }
        ?>
        <div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
      <?php elseif (isset($_GET['ptmo_url_update']) && (string) $_GET['ptmo_url_update'] === '1') : ?>
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
      // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

        $modeLabel = $mode === 'full' ? __('full run', 'arun-prakash-media-offload') : __('single update', 'arun-prakash-media-offload');
        $noticeClass = 'notice notice-' . ($status === 'warning' ? 'warning' : ($status === 'info' ? 'info' : 'success'));
        ?>
        <div class="<?php echo esc_attr($noticeClass); ?>">
          <p>
            <?php
            echo esc_html(sprintf(
              /* translators: 1: run mode label, 2: processed count, 3: success count, 4: failed count, 5: remaining pending count */
              __('URL update %1$s finished. Processed: %2$d, Success: %3$d, Failed: %4$d, Remaining pending: %5$d.', 'arun-prakash-media-offload'),
              $modeLabel,
              $processed,
              $success,
              $failed,
              $remaining
            ));
            ?>
          </p>
        </div>
      <?php endif; ?>

      <?php $this->renderSummaryStrip([
        [
          'label' => __('Pending URL updates', 'arun-prakash-media-offload'),
          'value' => number_format($pending),
          'substat' => __('Attachments waiting for URL/offload metadata update', 'arun-prakash-media-offload'),
        ],
        [
          'label' => __('Offloaded attachments', 'arun-prakash-media-offload'),
          'value' => number_format((int) ($stats['offloaded'] ?? 0)),
          'substat' => __('Already linked with offload metadata', 'arun-prakash-media-offload'),
        ],
        [
          'label' => __('Plugin status', 'arun-prakash-media-offload'),
          'value' => $isEnabled ? __('Enabled', 'arun-prakash-media-offload') : __('Disabled', 'arun-prakash-media-offload'),
          'substat' => $hasCredentials ? __('Credentials configured', 'arun-prakash-media-offload') : __('Credentials missing', 'arun-prakash-media-offload'),
        ],
        [
          'label' => __('Total attachments', 'arun-prakash-media-offload'),
          'value' => number_format((int) ($stats['total'] ?? 0)),
          'substat' => __('In media library', 'arun-prakash-media-offload'),
        ],
      ], 'nmb-url-update-summary-strip'); ?>

      <div class="nmb-card">
        <h3><?php esc_html_e('Run URL Updates', 'arun-prakash-media-offload'); ?></h3>
        <p><?php esc_html_e('Use one-by-one for safer/manual progression, or full run to process all pending attachments in one go from this admin panel.', 'arun-prakash-media-offload'); ?></p>
        <p><em><?php esc_html_e('Tip: attachments marked “Ignore from Nimbus” and files outside your allowed path filters are skipped automatically here.', 'arun-prakash-media-offload'); ?></em></p>

        <?php if (!$isEnabled) : ?>
          <p><strong><?php esc_html_e('Media offload is disabled.', 'arun-prakash-media-offload'); ?></strong></p>
        <?php endif; ?>

        <?php if (!$hasCredentials) : ?>
          <p><strong><?php esc_html_e('Valid storage credentials are required before URL updates can run.', 'arun-prakash-media-offload'); ?></strong></p>
        <?php endif; ?>

        <div class="nmb-inline-actions">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>" />
            <input type="hidden" name="mode" value="single" />
            <button type="submit" class="button button-secondary" <?php disabled(!$isEnabled || !$hasCredentials || $pending <= 0); ?>>
              <?php esc_html_e('Update One Entry', 'arun-prakash-media-offload'); ?>
            </button>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Run full URL update for all pending entries now?', 'arun-prakash-media-offload')); ?>');">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>" />
            <input type="hidden" name="mode" value="full" />
            <button type="submit" class="button button-primary" <?php disabled(!$isEnabled || !$hasCredentials || $pending <= 0); ?>>
              <?php esc_html_e('Update All Pending Entries', 'arun-prakash-media-offload'); ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php
  }

  private function renderConfigPortPanel(): void
  {
    ?>
    <section class="nmb-card" id="nmb-config-port-panel">
      <h3><?php esc_html_e('Configuration backup', 'arun-prakash-media-offload'); ?></h3>
      <p class="nmb-muted"><?php esc_html_e('Export your current settings as a portable JSON file, or import a previously exported file to restore them on another site. Credentials are included in the export — handle the file like a password.', 'arun-prakash-media-offload'); ?></p>

      <div class="nmb-config-port-grid">

        <!-- Export form -->
        <div class="nmb-config-port-block">
          <h4><?php esc_html_e('Export configuration', 'arun-prakash-media-offload'); ?></h4>
          <p class="nmb-muted"><?php esc_html_e('Downloads a .json file with all current provider, bucket, and delivery settings.', 'arun-prakash-media-offload'); ?></p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_EXPORT_CONFIG); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_EXPORT_CONFIG); ?>" />
            <button type="submit" class="button button-secondary"><?php esc_html_e('Download config.json', 'arun-prakash-media-offload'); ?></button>
          </form>
        </div>

        <!-- Import form -->
        <div class="nmb-config-port-block">
          <h4><?php esc_html_e('Import configuration', 'arun-prakash-media-offload'); ?></h4>
          <p class="nmb-muted"><?php esc_html_e('Upload a Nimbus JSON export to overwrite the current settings immediately. Existing settings will be replaced.', 'arun-prakash-media-offload'); ?></p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_IMPORT_CONFIG); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_IMPORT_CONFIG); ?>" />
            <input type="hidden" name="redirect_tab" value="tools" />
            <div class="nmb-config-port-file-row">
              <input type="file" name="nimbus_config_file" accept=".json,application/json" required />
              <button type="submit" class="button button-primary"><?php esc_html_e('Import', 'arun-prakash-media-offload'); ?></button>
            </div>
          </form>
        </div>

      </div>
    </section>
    <?php
  }

  /**
   * @param array<int, array{label:string, value:string, substat?:string, value_id?:string, substat_id?:string}> $items
   */
  private function renderSummaryStrip(array $items, string $className = ''): void
  {
    $classes = trim('nmb-card nmb-summary-strip ' . $className);
    ?>
    <section class="<?php echo esc_attr($classes); ?>">
      <div class="nmb-summary-strip__grid">
        <?php foreach ($items as $item) : ?>
          <div class="nmb-summary-item">
            <span class="nmb-summary-item__label"><?php echo esc_html((string) ($item['label'] ?? '')); ?></span>
            <strong<?php echo !empty($item['value_id']) ? ' id="' . esc_attr((string) $item['value_id']) . '"' : ''; ?>><?php echo esc_html((string) ($item['value'] ?? '')); ?></strong>
            <?php if (!empty($item['substat'])) : ?>
              <span class="nmb-summary-item__meta"<?php echo !empty($item['substat_id']) ? ' id="' . esc_attr((string) $item['substat_id']) . '"' : ''; ?>><?php echo esc_html((string) $item['substat']); ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
  }

  private function adminTabUrl(string $tab, string $fragment = ''): string
  {
    $url = add_query_arg([
      'page' => NIMBUS_SLUG,
      'tab' => $tab,
    ], admin_url('upload.php'));

    if ($fragment !== '') {
      $url .= '#' . sanitize_title($fragment);
    }

    return $url;
  }

  private function countPendingUrlUpdates(): int
  {
    $count = 0;
    $afterId = 0;
    $batchSize = 500;

    while (true) {
      $candidateIds = $this->fetchCandidatePendingUrlUpdateIds($batchSize, $afterId);
      if (empty($candidateIds)) {
        break;
      }

      $count += count($this->uploadHandler->filter_eligible_attachment_ids($candidateIds));
      $afterId = (int) end($candidateIds);

      if (count($candidateIds) < $batchSize) {
        break;
      }
    }

    return $count;
  }

  private function fetchCandidatePendingUrlUpdateIds(int $limit, int $afterId = 0): array
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return [];
    }

    $limit = max(1, $limit);
    $afterId = max(0, $afterId);
    $missingSourceRetryBefore = $this->missingSourceRetryBeforeTimestamp();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Candidate list for the admin URL-update tool; must be a live read of current attachment state, not cached.
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
      NIMBUS_META_KEY,
      NIMBUS_MISSING_SOURCE_AT_META_KEY,
      NIMBUS_IGNORE_META_KEY,
      'attachment',
      'inherit',
      $afterId,
      $missingSourceRetryBefore,
      $limit
    ));

    if (!is_array($ids)) {
      return [];
    }

    return array_map('intval', $ids);
  }

  private function missingSourceRetryBeforeTimestamp(): int
  {
    $cooldown = max(300, (int) apply_filters('nimbus_missing_source_retry_cooldown_seconds', 12 * HOUR_IN_SECONDS));
    return time() - $cooldown;
  }

  private function jobBadgeClass(string $status): string
  {
    return match ($status) {
      'completed', 'offloaded' => 'is-success',
      'paused', 'pending', 'processing', 'completed_with_errors' => 'is-warning',
      'failed' => 'is-error',
      default => '',
    };
  }

  /**
   * Returns the integration discovery summary. The pro plugin populates this
   * via the nimbus/integrations/summary filter.
   */
  private function integrationSummary(): array
  {
    return (array) apply_filters('nimbus/integrations/summary', [
      'detected' => 0,
      'rewrite_ready' => 0,
      'items' => [],
    ]);
  }

  /** Logo file in assets/img/logos for a provider key, or '' when there is none. */
  private function providerLogo(string $providerKey): string
  {
    $map = [
      's3' => 's3',
      'r2' => 'cloudflare',
      'gcs' => 'gcs',
      'backblaze_b2' => 'backblaze',
      'digitalocean_spaces' => 'digitalocean',
      'wasabi' => 'wasabi',
      'minio' => 'minio',
    ];

    return isset($map[$providerKey]) ? NIMBUS_URL . 'assets/img/logos/' . $map[$providerKey] . '.svg' : '';
  }

  /** Short provider names for the setup picker. */
  private function providerShortLabel(string $providerKey): string
  {
    $labels = [
      's3' => __('Amazon S3', 'arun-prakash-media-offload'),
      'r2' => __('Cloudflare R2', 'arun-prakash-media-offload'),
      'gcs' => __('Google Cloud', 'arun-prakash-media-offload'),
      'backblaze_b2' => __('Backblaze B2', 'arun-prakash-media-offload'),
      'digitalocean_spaces' => __('DO Spaces', 'arun-prakash-media-offload'),
      'wasabi' => __('Wasabi', 'arun-prakash-media-offload'),
      'minio' => __('MinIO', 'arun-prakash-media-offload'),
      's3_compatible' => __('Other S3-compatible', 'arun-prakash-media-offload'),
      'local' => __('Local (debug)', 'arun-prakash-media-offload'),
    ];

    return $labels[$providerKey] ?? $this->settings->provider_profile($providerKey)->label();
  }

  private function renderProviderIcon(string $providerKey): void
  {
    $logo = $this->providerLogo($providerKey);
    if ($logo !== '') {
      echo '<img class="nmb-provider-icon" src="' . esc_url($logo) . '" width="22" height="22" alt="" />';
      return;
    }

    echo '<span class="nmb-provider-icon nmb-provider-icon--text" aria-hidden="true">' . ($providerKey === 'local' ? 'L' : 'S3') . '</span>';
  }

  private function renderMark(): void
  {
    echo '<img class="nmb-mark" src="' . esc_url(NIMBUS_URL . 'assets/img/mark.svg') . '" width="36" height="36" alt="" />';
  }

  /** Brand row above the tabs: mark, name, version and the storage connection. */
  private function renderBrandHeader(): void
  {
    $settings = $this->settings->all();
    $providerKey = (string) ($settings['provider'] ?? 's3');
    $bucket = (string) ($settings['bucket'] ?? '');
    $ready = $providerKey === 'local' || $this->settings->has_valid_credentials();
    ?>
    <header class="nmb-brand-header">
      <div class="nmb-brand">
        <?php $this->renderMark(); ?>
        <h1><?php esc_html_e('Nimbus', 'arun-prakash-media-offload'); ?></h1>
        <span class="nmb-brand__version"><?php echo esc_html(NIMBUS_VERSION); ?></span>
      </div>
      <p class="nmb-connection">
        <?php $this->renderProviderIcon($providerKey); ?>
        <span><?php echo esc_html($this->settings->provider_profile($providerKey)->label()); ?></span>
        <?php if ($bucket !== '' && $providerKey !== 'local') : ?>
          <span aria-hidden="true">&middot;</span>
          <code><?php echo esc_html($bucket); ?></code>
        <?php endif; ?>
        <?php if ($ready) : ?>
          <span class="nmb-state nmb-state--ok"><?php esc_html_e('Configured', 'arun-prakash-media-offload'); ?></span>
        <?php else : ?>
          <span class="nmb-state nmb-state--warn"><?php esc_html_e('Needs credentials', 'arun-prakash-media-offload'); ?></span>
        <?php endif; ?>
      </p>
    </header>
    <hr class="wp-header-end" />
    <?php
  }
}
