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
      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Plugin logs cleared.', 'nimbus') . '</p></div>';
    }

    $onboarding = $this->onboardingManager->state();
    if (empty($onboarding['completed'])) {
      ?>
      <div class="wrap nimbus-settings nimbus-settings--onboarding">
        <h1><?php esc_html_e('Set up Nimbus', 'nimbus'); ?></h1>
        <?php $this->renderOnboardingTab(); ?>
      </div>
      <?php
      return;
    }

    $activeTab = $this->resolveActiveTab();
    ?>
    <div class="wrap nimbus-settings">
      <h1 class="wp-heading-inline"><?php esc_html_e('Nimbus', 'nimbus'); ?></h1>

      <?php settings_errors(NIMBUS_SETTINGS_GROUP); ?>

      <nav class="nav-tab-wrapper nmb-shell-tabs" aria-label="<?php esc_attr_e('Nimbus navigation', 'nimbus'); ?>">
        <a href="<?php echo esc_url($this->adminTabUrl('overview')); ?>" class="nav-tab <?php echo $activeTab === 'overview' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Overview', 'nimbus'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('settings')); ?>" class="nav-tab <?php echo $activeTab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Settings', 'nimbus'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('advanced')); ?>" class="nav-tab <?php echo $activeTab === 'advanced' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Advanced', 'nimbus'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>" class="nav-tab <?php echo $activeTab === 'integrations' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Integrations', 'nimbus'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('tools')); ?>" class="nav-tab <?php echo $activeTab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Tools', 'nimbus'); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('logs')); ?>" class="nav-tab <?php echo $activeTab === 'logs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Logs', 'nimbus'); ?></a>
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
    ?>
    <div id="nmb-onboarding-wizard" class="nmb-onboarding-shell" data-current-step="<?php echo esc_attr((string) $currentStepIndex); ?>">
      <aside class="nmb-onboarding-sidebar">
        <div class="nmb-onboarding-brand-panel">
          <span class="nmb-onboarding-brand-icon dashicons dashicons-cloud-upload"></span>
          <strong class="nmb-onboarding-brand-name">Nimbus</strong>
          <p class="nmb-onboarding-brand-tagline"><?php esc_html_e('Your media, elevated.', 'nimbus'); ?></p>
        </div>

        <div class="nmb-card nmb-onboarding-step-nav">
          <ol class="nmb-onboarding-step-list">
            <li class="nmb-onboarding-step-item" data-step-item="storage">
              <span class="nmb-onboarding-step-index">1</span>
              <div>
                <strong><?php esc_html_e('Storage', 'nimbus'); ?></strong>
                <span><?php esc_html_e('Connect the provider and bucket.', 'nimbus'); ?></span>
              </div>
            </li>
            <li class="nmb-onboarding-step-item" data-step-item="delivery">
              <span class="nmb-onboarding-step-index">2</span>
              <div>
                <strong><?php esc_html_e('Delivery', 'nimbus'); ?></strong>
                <span><?php esc_html_e('Choose how media should be served.', 'nimbus'); ?></span>
              </div>
            </li>
            <li class="nmb-onboarding-step-item" data-step-item="review">
              <span class="nmb-onboarding-step-index">3</span>
              <div>
                <strong><?php esc_html_e('Review', 'nimbus'); ?></strong>
                <span><?php esc_html_e('Confirm and unlock the dashboard.', 'nimbus'); ?></span>
              </div>
            </li>
          </ol>
        </div>
      </aside>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nmb-onboarding-form nmb-onboarding-main">
        <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING); ?>" />

        <section class="nmb-card nmb-onboarding-step" data-step="storage">
          <?php
            $savedProvider  = (string) ($settings['provider'] ?? 's3');
            $isLocalDefault = $savedProvider === 'local';
            $isR2Default    = $savedProvider === 'r2';
            $isS3CDefault   = in_array($savedProvider, ['s3_compatible', 'minio'], true);
          ?>
          <div class="nmb-onboarding-step-header">
            <p class="nmb-eyebrow"><?php esc_html_e('Step 1', 'nimbus'); ?></p>
            <h2><?php esc_html_e('Connect your storage provider', 'nimbus'); ?></h2>
            <p><?php esc_html_e('Select a provider and only the fields you need will appear.', 'nimbus'); ?></p>
          </div>

          <!-- JSON import shortcut -->
          <div class="nmb-config-import-bar">
            <label for="nmb-onboarding-config-file" class="nmb-config-import-label">
              <span class="dashicons dashicons-upload"></span>
              <?php esc_html_e('Already have a config file?', 'nimbus'); ?>
            </label>
            <input type="file" id="nmb-onboarding-config-file" accept=".json,application/json" class="nmb-config-import-input" data-config-import />
            <span class="nmb-config-import-hint nmb-muted"><?php esc_html_e('Select a Nimbus .json export to pre-fill all fields below instantly.', 'nimbus'); ?></span>
          </div>

          <!-- Provider selector — always visible -->
          <div class="nmb-storage-group">
            <label class="nmb-onboarding-field nmb-onboarding-field--full">
              <span><?php esc_html_e('Provider', 'nimbus'); ?></span>
              <select id="nimbus-provider" name="provider">
                <?php foreach ($this->settings->available_provider_keys() as $providerKey) : ?>
                  <option value="<?php echo esc_attr($providerKey); ?>" <?php selected($savedProvider, $providerKey); ?>><?php echo esc_html($this->settings->provider_profile($providerKey)->label()); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <p id="nmb-provider-tip" class="nmb-provider-tip" hidden></p>
          </div>

          <!-- Bucket & location — all cloud providers -->
          <div class="nmb-storage-group" data-provider-group="bucket-location"<?php if ($isLocalDefault) echo ' hidden'; ?>>
            <p class="nmb-storage-group__label"><?php esc_html_e('Bucket &amp; location', 'nimbus'); ?></p>
            <div class="nmb-onboarding-field-grid nmb-onboarding-field-grid--two-up">
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Bucket', 'nimbus'); ?></span>
                <input type="text" name="bucket" value="<?php echo esc_attr((string) ($settings['bucket'] ?? '')); ?>" placeholder="my-media-bucket" autocomplete="off"<?php if ($isLocalDefault) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('The name of the bucket you created in your provider\'s console.', 'nimbus'); ?></small>
              </label>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Region', 'nimbus'); ?></span>
                <input type="text" id="nimbus-region" name="region" value="<?php echo esc_attr((string) ($settings['region'] ?? '')); ?>" placeholder="us-east-1"<?php if ($isR2Default) echo ' readonly'; ?> autocomplete="off"<?php if ($isLocalDefault) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('e.g. us-east-1 for AWS. Cloudflare R2 uses &ldquo;auto&rdquo; — filled in automatically.', 'nimbus'); ?></small>
              </label>
              <label class="nmb-onboarding-field nmb-onboarding-field--full">
                <span><?php esc_html_e('Path prefix', 'nimbus'); ?> <em class="nmb-optional"><?php esc_html_e('optional', 'nimbus'); ?></em></span>
                <input type="text" name="prefix" value="<?php echo esc_attr((string) ($settings['prefix'] ?? '')); ?>" placeholder="wp-uploads" autocomplete="off"<?php if ($isLocalDefault) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('A sub-folder inside the bucket. Leave blank to store files at the root.', 'nimbus'); ?></small>
              </label>
            </div>
          </div>

          <!-- Credentials — all cloud providers -->
          <div class="nmb-storage-group" data-provider-group="credentials"<?php if ($isLocalDefault) echo ' hidden'; ?>>
            <p class="nmb-storage-group__label"><?php esc_html_e('Credentials', 'nimbus'); ?></p>
            <div class="nmb-onboarding-field-grid nmb-onboarding-field-grid--two-up">
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Access key', 'nimbus'); ?></span>
                <input type="text" name="access_key" value="<?php echo esc_attr((string) ($settings['access_key'] ?? '')); ?>" autocomplete="off"<?php if ($isLocalDefault) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('AWS: IAM \u2192 Users \u2192 Security credentials. Cloudflare: R2 \u2192 Manage API tokens.', 'nimbus'); ?></small>
              </label>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Secret key', 'nimbus'); ?></span>
                <input type="password" name="secret_key" value="<?php echo esc_attr((string) ($settings['secret_key'] ?? '')); ?>" autocomplete="new-password"<?php if ($isLocalDefault) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('The secret paired with your access key. Shown only once \u2014 copy it before closing.', 'nimbus'); ?></small>
              </label>
            </div>
          </div>

          <!-- Cloudflare R2 extras — r2 only -->
          <div class="nmb-storage-group" data-provider-group="r2-extra"<?php if (! $isR2Default) echo ' hidden'; ?>>
            <p class="nmb-storage-group__label"><?php esc_html_e('Cloudflare account', 'nimbus'); ?></p>
            <div class="nmb-onboarding-field-grid nmb-onboarding-field-grid--two-up">
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Account ID', 'nimbus'); ?></span>
                <input type="text" name="r2_account_id" value="<?php echo esc_attr((string) ($settings['r2_account_id'] ?? '')); ?>" autocomplete="off"<?php if (! $isR2Default) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('Found in the right sidebar of your Cloudflare dashboard home page.', 'nimbus'); ?></small>
              </label>
              <label class="nmb-onboarding-field">
                <span><?php esc_html_e('Custom endpoint', 'nimbus'); ?> <em class="nmb-optional"><?php esc_html_e('alternative', 'nimbus'); ?></em></span>
                <input type="url" name="endpoint" value="<?php echo esc_attr((string) ($settings['endpoint'] ?? '')); ?>" placeholder="https://&lt;account-id&gt;.r2.cloudflarestorage.com" autocomplete="off"<?php if (! $isR2Default) echo ' disabled'; ?> />
                <small class="nmb-field-hint"><?php esc_html_e('Use this instead of Account ID if you prefer an explicit URL.', 'nimbus'); ?></small>
              </label>
            </div>
          </div>

          <!-- Custom endpoint — s3_compatible only -->
          <div class="nmb-storage-group" data-provider-group="s3c-endpoint"<?php if (! $isS3CDefault) echo ' hidden'; ?>>
            <p class="nmb-storage-group__label"><?php esc_html_e('Connection', 'nimbus'); ?></p>
            <label class="nmb-onboarding-field nmb-onboarding-field--full">
              <span><?php esc_html_e('Custom endpoint', 'nimbus'); ?></span>
              <input type="url" name="endpoint" value="<?php echo esc_attr((string) ($settings['endpoint'] ?? '')); ?>" placeholder="https://s3.example.com" autocomplete="off"<?php if (! $isS3CDefault) echo ' disabled'; ?> />
              <small class="nmb-field-hint"><?php esc_html_e('The S3-compatible API base URL from your provider, e.g. https://s3.example.com.', 'nimbus'); ?></small>
            </label>
          </div>

          <!-- Session token — s3 and s3_compatible only -->
          <div class="nmb-storage-group" data-provider-group="session"<?php if ($isR2Default || $isLocalDefault) echo ' hidden'; ?>>
            <p class="nmb-storage-group__label"><?php esc_html_e('Temporary credentials', 'nimbus'); ?> <em class="nmb-optional"><?php esc_html_e('optional', 'nimbus'); ?></em></p>
            <label class="nmb-onboarding-field nmb-onboarding-field--full">
              <span><?php esc_html_e('Session token', 'nimbus'); ?></span>
              <input type="password" name="session_token" value="<?php echo esc_attr((string) ($settings['session_token'] ?? '')); ?>" autocomplete="new-password"<?php if ($isR2Default || $isLocalDefault) echo ' disabled'; ?> />
              <small class="nmb-field-hint"><?php esc_html_e('Only needed for temporary AWS STS credentials. Leave blank for standard IAM access keys.', 'nimbus'); ?></small>
            </label>
          </div>

          <div class="nmb-onboarding-actions">
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to delivery', 'nimbus'); ?></button>
          </div>
        </section>

        <section class="nmb-card nmb-onboarding-step" data-step="delivery" hidden>
          <div class="nmb-onboarding-step-header">
            <p class="nmb-eyebrow"><?php esc_html_e('Step 2', 'nimbus'); ?></p>
            <h2><?php esc_html_e('Choose delivery behavior', 'nimbus'); ?></h2>
            <p><?php esc_html_e('Decide how Nimbus should behave once setup is complete. You can still tune these later from Settings.', 'nimbus'); ?></p>
          </div>

          <div class="nmb-onboarding-preference-grid">
            <label class="nmb-card nmb-onboarding-choice-card">
              <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?> />
              <div>
                <strong><?php esc_html_e('Enable Nimbus after setup', 'nimbus'); ?></strong>
                <p><?php esc_html_e('Turn on the offload workflow once the wizard is finished.', 'nimbus'); ?></p>
              </div>
            </label>

            <label class="nmb-card nmb-onboarding-choice-card">
              <input type="checkbox" name="serve_from_storage" value="1" <?php checked(!empty($settings['serve_from_storage'])); ?> />
              <div>
                <strong><?php esc_html_e('Serve media from remote storage', 'nimbus'); ?></strong>
                <p><?php esc_html_e('Rewrite public media URLs so visitors load files from your bucket or CDN.', 'nimbus'); ?></p>
              </div>
            </label>

            <label class="nmb-card nmb-onboarding-choice-card">
              <input type="checkbox" name="use_path_style_endpoint" value="1" <?php checked(!empty($settings['use_path_style_endpoint'])); ?> />
              <div>
                <strong><?php esc_html_e('Use path-style requests', 'nimbus'); ?></strong>
                <p><?php esc_html_e('Changes the S3 request URL format from subdomain-style (bucket.s3.amazonaws.com) to path-style (s3.amazonaws.com/bucket). Required for Cloudflare R2 and most S3-compatible providers. Not needed for standard AWS S3.', 'nimbus'); ?></p>
              </div>
            </label>
          </div>

          <label class="nmb-onboarding-field nmb-onboarding-field--full">
            <span><?php esc_html_e('Custom delivery domain (optional)', 'nimbus'); ?></span>
            <?php
              $siteDomain = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'example.com';
              $cdnPlaceholder = 'https://cdn.' . $siteDomain;
            ?>
            <input type="url" name="custom_domain" value="<?php echo esc_attr((string) ($settings['custom_domain'] ?? '')); ?>" placeholder="<?php echo esc_attr($cdnPlaceholder); ?>" autocomplete="off" />
          </label>

          <div class="nmb-card nmb-onboarding-helper-card">
            <h3><?php esc_html_e('What happens next', 'nimbus'); ?></h3>
            <ul class="nmb-bullet-list">
              <li><?php esc_html_e('After setup, you can validate the connection from Tools.', 'nimbus'); ?></li>
              <li><?php esc_html_e('You can delay public URL rewriting by leaving remote delivery off for now.', 'nimbus'); ?></li>
              <li><?php esc_html_e('All of these preferences remain editable from Settings later.', 'nimbus'); ?></li>
            </ul>
          </div>

          <div class="nmb-onboarding-actions">
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to review', 'nimbus'); ?></button>
          </div>
        </section>

        <section class="nmb-card nmb-onboarding-step" data-step="review" hidden>
          <div class="nmb-onboarding-step-header">
            <p class="nmb-eyebrow"><?php esc_html_e('Step 3', 'nimbus'); ?></p>
            <h2><?php esc_html_e('Review and finish setup', 'nimbus'); ?></h2>
            <p><?php esc_html_e('Confirm the final checklist below. Once you finish this step, the plugin dashboard unlocks and the guided setup disappears.', 'nimbus'); ?></p>
          </div>

          <div class="nmb-onboarding-review-grid">
            <div class="nmb-card nmb-onboarding-helper-card">
              <h3><?php esc_html_e('Final checklist', 'nimbus'); ?></h3>
              <ul class="nmb-status-list">
                <li><span class="dashicons dashicons-cloud"></span><strong><?php esc_html_e('Storage', 'nimbus'); ?></strong><span class="nmb-spacer"></span><?php esc_html_e('Connected in this setup flow', 'nimbus'); ?></li>
                <li><span class="dashicons dashicons-admin-home"></span><strong><?php esc_html_e('Dashboard', 'nimbus'); ?></strong><span class="nmb-spacer"></span><?php esc_html_e('Unlocks after finish', 'nimbus'); ?></li>
              </ul>
            </div>

            <div class="nmb-card nmb-onboarding-helper-card">
              <h3><?php esc_html_e('Conflict review', 'nimbus'); ?></h3>
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
                <p class="nmb-muted"><?php esc_html_e('No conflicting media offload plugins were detected. Nice and clean.', 'nimbus'); ?></p>
              <?php endif; ?>

              <?php if (!empty($state['has_active_conflicts'])) : ?>
                <label class="nmb-onboarding-checkbox-note">
                  <input type="checkbox" name="conflict_acknowledged" value="1" />
                  <span><?php esc_html_e('I understand another media offload plugin is active and I will review the warnings after setup.', 'nimbus'); ?></span>
                </label>
              <?php endif; ?>
            </div>
          </div>

          <div class="nmb-onboarding-actions">
            <button type="submit" class="button button-primary"><?php esc_html_e('Finish setup and unlock dashboard', 'nimbus'); ?></button>
          </div>
        </section>
      </form>
    </div>
    <?php
  }

  private function renderOverviewTab(): void
  {
    $stats = $this->statistics->get_stats();
    $deepStats = $this->statistics->get_deep_stats();
    $pending = $this->countPendingUrlUpdates();
    $integrationSummary = $this->integrationSummary();
    $onboarding = $this->onboardingManager->state();
    $migration = DataMigration::status();
    ?>
    <div class="nmb-settings-shell">

      <?php $this->renderDashboard(); ?>

      <?php if (!empty($migration['needs_migration'])) : ?>
        <?php $this->renderActivationRecoveryPanel($migration); ?>
      <?php endif; ?>

      <div class="nmb-grid-2 nmb-overview-actions-grid">
        <div class="nmb-card" id="overview-setup-health">
          <h3><?php esc_html_e('Setup health', 'nimbus'); ?></h3>
          <ul class="nmb-status-list">
            <li>
              <span class="dashicons <?php echo !empty($onboarding['completed']) ? 'dashicons-yes-alt status-ok' : 'dashicons-warning status-warning'; ?>"></span>
              <strong><?php esc_html_e('Onboarding:', 'nimbus'); ?></strong>
              <?php echo !empty($onboarding['completed']) ? esc_html__('Completed', 'nimbus') : esc_html__('Needs review', 'nimbus'); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $this->settings->has_valid_credentials() ? 'dashicons-yes-alt status-ok' : 'dashicons-warning status-error'; ?>"></span>
              <strong><?php esc_html_e('Credentials:', 'nimbus'); ?></strong>
              <?php echo $this->settings->has_valid_credentials() ? esc_html__('Configured', 'nimbus') : esc_html__('Needs attention', 'nimbus'); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $this->settings->should_serve_from_storage() ? 'dashicons-admin-links status-ok' : 'dashicons-minus'; ?>"></span>
              <strong><?php esc_html_e('Delivery:', 'nimbus'); ?></strong>
              <?php echo $this->settings->should_serve_from_storage() ? esc_html__('Serving from storage', 'nimbus') : esc_html__('Using local/origin URLs', 'nimbus'); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $pending > 0 ? 'dashicons-update status-warning' : 'dashicons-yes-alt status-ok'; ?>"></span>
              <strong><?php esc_html_e('Pending URL updates:', 'nimbus'); ?></strong>
              <?php echo number_format($pending); ?>
            </li>
            <li>
              <span class="dashicons dashicons-format-status"></span>
              <strong><?php esc_html_e('Offloaded media:', 'nimbus'); ?></strong>
              <?php echo number_format((int) ($stats['offloaded'] ?? 0)); ?>
            </li>
            <li>
              <span class="dashicons <?php echo !empty($onboarding['has_active_conflicts']) ? 'dashicons-warning status-error' : 'dashicons-yes-alt status-ok'; ?>"></span>
              <strong><?php esc_html_e('Plugin conflicts:', 'nimbus'); ?></strong>
              <?php echo !empty($onboarding['has_active_conflicts']) ? esc_html__('Review required', 'nimbus') : esc_html__('No active conflicts detected', 'nimbus'); ?>
            </li>
          </ul>
          <div class="nmb-actions nmb-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('settings', 'settings-core')); ?>"><?php esc_html_e('Configure settings', 'nimbus'); ?></a>
            <a class="button" href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>"><?php /* translators: %d: number of detected integrations */ printf(esc_html__('Integrations (%d detected)', 'nimbus'), (int) ($integrationSummary['detected'] ?? 0)); ?></a>
          </div>
        </div>

        <div class="nmb-card">
          <h3><?php esc_html_e('Upload folder structure', 'nimbus'); ?></h3>
          <p class="nmb-muted" style="margin:0 0 12px;"><?php esc_html_e('WordPress organises your media into date-based sub-folders. These are the top-level folders found in your uploads directory.', 'nimbus'); ?></p>
          <?php if (empty($deepStats['asset_roots'])) : ?>
            <p class="nmb-muted"><?php esc_html_e('No upload folders sampled yet. Upload a file to populate this.', 'nimbus'); ?></p>
          <?php else : ?>
            <ul class="nmb-status-list">
              <?php foreach ($deepStats['asset_roots'] as $root) : ?>
                <li>
                  <span class="dashicons dashicons-category"></span>
                  <code style="font-family:var(--mo-mono);font-size:12px;background:var(--mo-surface-alt);padding:2px 6px;border:1px solid var(--mo-border);border-radius:var(--mo-radius);"><?php echo esc_html((string) ($root['root'] ?? '')); ?>/</code>
                  <span class="nmb-spacer"></span>
                  <span title="<?php esc_attr_e('Files in this folder', 'nimbus'); ?>"><?php echo number_format((int) ($root['count'] ?? 0)); ?> <?php esc_html_e('files', 'nimbus'); ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
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
      ? __('Local debug', 'nimbus')
      : (!empty($settings['serve_from_storage']) ? __('Remote', 'nimbus') : __('Local', 'nimbus'));
    $providerSubstat = $isLocalProvider
      ? __('No bucket required in local mode', 'nimbus')
      : (string) ($settings['bucket'] ?? __('Bucket not set', 'nimbus'));
    ?>
    <div class="nmb-settings-shell">
      <?php $this->renderSummaryStrip([
        [
          'label' => __('Provider', 'nimbus'),
          'value' => $providerLabel,
          'value_id' => 'nmb-settings-provider-label',
          'substat' => $providerSubstat,
          'substat_id' => 'nmb-settings-provider-substat',
        ],
        [
          'label' => __('Delivery', 'nimbus'),
          'value' => $deliveryLabel,
          'value_id' => 'nmb-settings-delivery-label',
          'substat' => !empty($settings['custom_domain']) ? (string) $settings['custom_domain'] : __('No custom domain configured', 'nimbus'),
          'substat_id' => 'nmb-settings-delivery-substat',
        ],
        [
          'label' => __('Off-hours batch', 'nimbus'),
          'value' => !empty($settings['off_hours_batch_enabled']) ? __('On', 'nimbus') : __('Off', 'nimbus'),
          'value_id' => 'nmb-settings-batch-label',
          'substat' => !empty($settings['off_hours_batch_time']) ? (string) $settings['off_hours_batch_time'] : __('No schedule set', 'nimbus'),
          'substat_id' => 'nmb-settings-batch-substat',
        ],
      ], 'nmb-settings-summary-strip'); ?>

      <form method="post" action="options.php" autocomplete="off" class="nmb-settings-form">
        <?php settings_fields(NIMBUS_SETTINGS_GROUP); ?>

        <div class="nmb-settings-main">
            <section id="settings-core" class="nmb-settings-cluster">
              <div class="nmb-settings-cluster-header">
                <p class="nmb-eyebrow"><?php esc_html_e('Core setup', 'nimbus'); ?></p>
                <h2><?php esc_html_e('Connection and delivery', 'nimbus'); ?></h2>
                <p><?php esc_html_e('Everything required to connect a provider, define the bucket, and decide how media URLs are served.', 'nimbus'); ?></p>
              </div>
              <?php $this->settingsRegistrar->renderSections(['nimbus_connection', 'nimbus_delivery']); ?>
            </section>

            <section id="settings-automation" class="nmb-settings-cluster">
              <div class="nmb-settings-cluster-header">
                <p class="nmb-eyebrow"><?php esc_html_e('Automation', 'nimbus'); ?></p>
                <h2><?php esc_html_e('Scheduling', 'nimbus'); ?></h2>
                <p><?php esc_html_e('Configure the off-hours batch window for background offloading. Path filters and cache purging can be found on the Advanced tab.', 'nimbus'); ?></p>
              </div>
              <?php $this->settingsRegistrar->renderSections(['nimbus_off_hours']); ?>
            </section>

            <div class="nmb-settings-submit">
              <?php submit_button(__('Save Settings', 'nimbus')); ?>
            </div>
          </div>
      </form>

      <div class="nmb-secondary-surface-grid">
        <div class="nmb-card">
          <h3><?php esc_html_e('Integration rewrites', 'nimbus'); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('Plugin-specific asset roots and rewrite actions are now grouped with operational tools, where you can test and execute them without cluttering configuration.', 'nimbus'); ?></p>
          <div class="nmb-actions nmb-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>"><?php esc_html_e('Manage integrations', 'nimbus'); ?></a>
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
              <p class="nmb-eyebrow"><?php esc_html_e('Offload rules', 'nimbus'); ?></p>
              <h2><?php esc_html_e('Path filters', 'nimbus'); ?></h2>
              <p><?php esc_html_e('Limit which uploads paths are eligible for offload using allow and ignore pattern lists.', 'nimbus'); ?></p>
            </div>
            <?php $this->settingsRegistrar->renderSections(['nimbus_path_filters']); ?>
          </section>

          <section id="advanced-cache-purging" class="nmb-settings-cluster">
            <div class="nmb-settings-cluster-header">
              <p class="nmb-eyebrow"><?php esc_html_e('Delivery', 'nimbus'); ?></p>
              <h2><?php esc_html_e('Cache purging', 'nimbus'); ?></h2>
              <p><?php esc_html_e('Automatically invalidate CDN and server-side page caches when media is offloaded or URLs are rewritten.', 'nimbus'); ?></p>
            </div>
            <?php $this->settingsRegistrar->renderSections(['nimbus_cache_purging']); ?>
          </section>

          <div class="nmb-settings-submit">
            <?php submit_button(__('Save Advanced Settings', 'nimbus')); ?>
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
        <strong><?php esc_html_e('Connectivity', 'nimbus'); ?></strong>
        <button type="button" id="nmb-test-connection" class="button button-secondary"><?php esc_html_e('Test Connection', 'nimbus'); ?></button>
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
          <h3><?php esc_html_e('Bulk media jobs', 'nimbus'); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('This panel polls the current queue state and lets you start, pause, resume, or retry background jobs.', 'nimbus'); ?></p>
        </div>
        <span id="nmb-job-status-badge" class="nmb-inline-badge <?php echo $hasJob ? esc_attr($this->jobBadgeClass((string) ($job['status'] ?? 'idle'))) : ''; ?>"><?php echo esc_html($hasJob ? (string) ($job['status_label'] ?? __('Idle', 'nimbus')) : __('Idle', 'nimbus')); ?></span>
      </div>

      <div class="nmb-bulk-job-grid">
        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Current job', 'nimbus'); ?></h3>
          <div class="nmb-stat" id="nmb-job-label"><?php echo esc_html($hasJob ? (string) ($job['type_label'] ?? __('No active job', 'nimbus')) : __('No active job', 'nimbus')); ?></div>
          <div class="nmb-substat" id="nmb-job-message"><?php echo esc_html($hasJob ? (string) ($job['last_message'] ?? '') : __('Choose a bulk task to begin.', 'nimbus')); ?></div>
          <div class="nmb-progress nmb-bulk-job-progress"><div id="nmb-job-progress-bar" class="nmb-bar" style="width: <?php echo esc_attr((string) ($job['percent_complete'] ?? 0)); ?>%"></div></div>
          <div class="nmb-substat" id="nmb-job-progress-text"><?php /* translators: %1$d: job completion percentage */ echo esc_html(sprintf(__('%1$d%% complete', 'nimbus'), (int) ($job['percent_complete'] ?? 0))); ?></div>
        </div>

        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Queue state', 'nimbus'); ?></h3>
          <ul class="nmb-status-list">
            <li><strong><?php esc_html_e('Processed', 'nimbus'); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-processed"><?php echo number_format((int) ($job['processed_items'] ?? 0)); ?></span> / <span id="nmb-job-total"><?php echo number_format((int) ($job['total_items'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Succeeded', 'nimbus'); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-success"><?php echo number_format((int) ($job['success_count'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Failed', 'nimbus'); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-failed"><?php echo number_format((int) ($job['failed_count'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Skipped', 'nimbus'); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-skipped"><?php echo number_format((int) ($job['skipped_count'] ?? 0)); ?></span></li>
          </ul>
        </div>

        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Actions', 'nimbus'); ?></h3>
          <div class="nmb-bulk-job-actions">
            <button type="button" class="button button-primary" data-job-action="start" data-job-type="offload_existing"><?php esc_html_e('Offload existing library', 'nimbus'); ?></button>
            <button type="button" class="button" data-job-action="start" data-job-type="restore_local"><?php esc_html_e('Restore local copies', 'nimbus'); ?></button>
            <button type="button" class="button" data-job-action="pause"><?php esc_html_e('Pause', 'nimbus'); ?></button>
            <button type="button" class="button" data-job-action="resume"><?php esc_html_e('Resume', 'nimbus'); ?></button>
            <button type="button" class="button" data-job-action="retry-failed"><?php esc_html_e('Retry failed items', 'nimbus'); ?></button>
          </div>
          <p class="nmb-muted"><?php esc_html_e('Pause/resume works on the current job. Retry failed items uses the failed subset from the most recent completed job.', 'nimbus'); ?></p>
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
          <h3><?php esc_html_e('Activation recovery', 'nimbus'); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('An earlier activation/upgrade migration did not finish. Resume it here in small batches instead of forcing the entire site through one heavy request.', 'nimbus'); ?></p>
        </div>
        <span class="nmb-inline-badge is-warning"><?php esc_html_e('Recovery needed', 'nimbus'); ?></span>
      </div>

      <ul class="nmb-status-list">
        <li>
          <span class="dashicons <?php echo !empty($migration['scheduled']) ? 'dashicons-clock status-warning' : 'dashicons-update status-warning'; ?>"></span>
          <strong><?php esc_html_e('Background follow-up', 'nimbus'); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo !empty($migration['scheduled']) ? esc_html__('Scheduled', 'nimbus') : esc_html__('Not scheduled yet', 'nimbus'); ?>
        </li>
        <li>
          <span class="dashicons dashicons-database"></span>
          <strong><?php esc_html_e('Legacy metadata keys', 'nimbus'); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo number_format(count($legacyMetaKeys)); ?>
        </li>
        <li>
          <span class="dashicons dashicons-admin-settings"></span>
          <strong><?php esc_html_e('Legacy options', 'nimbus'); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo number_format(count($legacyOptions)); ?>
        </li>
        <li>
          <span class="dashicons dashicons-format-image"></span>
          <strong><?php esc_html_e('Attachment metadata payloads', 'nimbus'); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo $hasAttachmentPayloads ? esc_html__('Still pending', 'nimbus') : esc_html__('Clear', 'nimbus'); ?>
        </li>
      </ul>

      <div class="nmb-actions nmb-actions-left">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RESUME_MIGRATION); ?>
          <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RESUME_MIGRATION); ?>" />
          <button type="submit" class="button button-primary"><?php esc_html_e('Resume activation recovery', 'nimbus'); ?></button>
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
      <h3><?php esc_html_e('Attachment status', 'nimbus'); ?></h3>
      <ul class="nmb-status-list">
        <li><strong><?php esc_html_e('Attachment ID', 'nimbus'); ?></strong><span class="nmb-spacer"></span><?php echo number_format($attachmentId); ?></li>
        <li><strong><?php esc_html_e('Status', 'nimbus'); ?></strong><span class="nmb-spacer"></span><span class="nmb-inline-badge <?php echo esc_attr($this->jobBadgeClass((string) ($status['status'] ?? 'local'))); ?>"><?php echo esc_html((string) ($status['label'] ?? __('Unknown', 'nimbus'))); ?></span></li>
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
            <h3 style="margin:0 0 4px;"><?php esc_html_e('Plugin integrations', 'nimbus'); ?></h3>
            <p class="nmb-muted" style="margin:0;"><?php esc_html_e('Nimbus detects active plugins that store assets outside the standard wp-content/uploads path. Enable or disable each integration below.', 'nimbus'); ?></p>
          </div>
          <span class="nmb-inline-badge">
            <?php echo number_format((int) ($summary['detected'] ?? 0)); ?> <?php esc_html_e('detected', 'nimbus'); ?>
          </span>
        </div>
      </div>

      <div class="nmb-card" style="padding:0;overflow:hidden;">
        <table class="nmb-integrations-table widefat" style="border:none;">
          <thead>
            <tr>
              <th><?php esc_html_e('Plugin', 'nimbus'); ?></th>
              <th><?php esc_html_e('State', 'nimbus'); ?></th>
              <th><?php esc_html_e('Asset paths', 'nimbus'); ?></th>
              <th><?php esc_html_e('Coverage', 'nimbus'); ?></th>
              <th><?php esc_html_e('Actions', 'nimbus'); ?></th>
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
                $stateLabel = __('Active', 'nimbus');
              } elseif (!$isEnabled) {
                $stateClass = 'is-disabled';
                $stateLabel = __('Disabled', 'nimbus');
              } elseif ($isInstalled) {
                $stateClass = 'is-installed';
                $stateLabel = __('Installed', 'nimbus');
              } else {
                $stateClass = 'is-not-installed';
                $stateLabel = __('Not installed', 'nimbus');
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
                    <span class="nmb-int-state-badge is-active"><?php esc_html_e('Rewrite supported', 'nimbus'); ?></span>
                  <?php else : ?>
                    <span class="nmb-muted"><?php esc_html_e('Detection only', 'nimbus'); ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                      <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION); ?>
                      <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION); ?>" />
                      <input type="hidden" name="integration" value="<?php echo esc_attr($itemKey); ?>" />
                      <button type="submit" class="button button-small">
                        <?php echo $isEnabled ? esc_html__('Disable', 'nimbus') : esc_html__('Enable', 'nimbus'); ?>
                      </button>
                    </form>
                    <?php if (!empty($item['rewrite_supported']) && !empty($item['rewrite_action_label'])) : ?>
                      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION); ?>" />
                        <input type="hidden" name="integration" value="<?php echo esc_attr($itemKey); ?>" />
                        <button type="submit" class="button button-small button-primary" <?php disabled(empty($item['rewrite_ready'])); ?>>
                          <?php echo esc_html((string) ($item['rewrite_action_label'] ?? __('Run rewrite', 'nimbus'))); ?>
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
                  <?php esc_html_e('No integrations detected. Install a supported plugin to see it listed here.', 'nimbus'); ?>
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
    $mimeBreakdown = $deepStats['mime_breakdown'] ?? [];
    $monthlyBreakdown = $deepStats['monthly_breakdown'] ?? [];
    $costComparison = $deepStats['cost_comparison'] ?? [];

    $storageSize = isset($remoteStats['size_bytes']) ? $remoteStats['size_bytes'] : $usage['offloaded_size'];
    $storageCount = isset($remoteStats['object_count']) ? $remoteStats['object_count'] : $stats['offloaded'];

    // Derive estimated monthly cost for the currently configured provider
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
    ?>
    <div class="nmb-dashboard">
      <!-- Unified stats bar — all 4 cells are identical height -->
      <div class="nmb-stats-bar">
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Library assets', 'nimbus'); ?></span>
          <span class="nmb-stat-cell__value"><?php echo number_format($stats['total']); ?></span>
          <span class="nmb-stat-cell__sub"><?php esc_html_e('Attachments in WordPress', 'nimbus'); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Offloaded items', 'nimbus'); ?></span>
          <span class="nmb-stat-cell__value"><?php echo number_format($stats['offloaded']); ?></span>
          <div class="nmb-stat-cell__bar"><div class="nmb-stat-cell__bar-fill" style="width:<?php echo (int) $stats['percent']; ?>%"></div></div>
          <span class="nmb-stat-cell__sub"><?php /* translators: %d: percentage of attachments linked to remote storage */ echo esc_html(sprintf(__('%d%% linked to remote storage', 'nimbus'), (int) $stats['percent'])); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Remote storage', 'nimbus'); ?></span>
          <span class="nmb-stat-cell__value"><?php echo esc_html(size_format($storageSize)); ?></span>
          <span class="nmb-stat-cell__sub"><?php /* translators: %d: number of remote objects tracked */ echo esc_html(sprintf(__('%d remote objects tracked', 'nimbus'), (int) $storageCount)); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Est. monthly cost', 'nimbus'); ?></span>
          <span class="nmb-stat-cell__value">$<?php echo number_format($storageCost, 2); ?></span>
          <span class="nmb-stat-cell__sub"><?php
            $providerLabel = ($currentScenario ?? [])['label'] ?? strtoupper($currentProvider);
            /* translators: %s: storage provider name */
            echo esc_html(sprintf(__('%s estimate', 'nimbus'), $providerLabel));
          ?></span>
        </div>
      </div>

      <div class="nmb-grid-2">
        <div class="nmb-card">
          <h3><?php esc_html_e('Uploads — last 7 days', 'nimbus'); ?></h3>
          <div class="nmb-chart">
            <?php if (empty($stats['daily'])) : ?>
              <p class="nmb-muted" style="align-self:center;margin:auto;"><?php esc_html_e('No uploads in the last 7 days.', 'nimbus'); ?></p>
            <?php else : ?>
              <?php
              $max = max(array_column($stats['daily'], 'count'));
              foreach ($stats['daily'] as $day) :
                $height = $max > 0 ? ($day->count / $max) * 100 : 0;
              ?>
                <div class="nmb-bar-group" title="<?php echo esc_attr($day->date . ': ' . $day->count); ?>">
                  <div class="nmb-chart-bar" style="height:<?php echo (int) $height; ?>%"></div>
                  <div class="nmb-chart-label"><?php echo esc_html(gmdate('M d', strtotime($day->date))); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="nmb-card">
          <h3><?php esc_html_e('System status', 'nimbus'); ?></h3>
          <ul class="nmb-status-list">
            <li>
              <span class="dashicons dashicons-yes-alt status-ok"></span>
              <strong><?php esc_html_e('Plugin enabled:', 'nimbus'); ?></strong>
              <span class="nmb-spacer"></span>
              <?php echo $this->settings->is_enabled() ? esc_html__('Yes', 'nimbus') : esc_html__('No', 'nimbus'); ?>
            </li>
            <li>
              <span class="dashicons dashicons-cloud status-ok"></span>
              <strong><?php esc_html_e('Provider:', 'nimbus'); ?></strong>
              <span class="nmb-spacer"></span>
              <?php echo esc_html(strtoupper($this->settings->provider())); ?>
            </li>
            <li>
              <span class="dashicons dashicons-database status-ok"></span>
              <strong><?php esc_html_e('Bucket:', 'nimbus'); ?></strong>
              <span class="nmb-spacer"></span>
              <?php echo esc_html($this->settings->get('bucket') ?: '—'); ?>
            </li>
            <li>
              <span class="dashicons dashicons-admin-links status-ok"></span>
              <strong><?php esc_html_e('Rewrite URLs:', 'nimbus'); ?></strong>
              <span class="nmb-spacer"></span>
              <?php echo $this->settings->should_serve_from_storage() ? esc_html__('Yes', 'nimbus') : esc_html__('No', 'nimbus'); ?>
            </li>
          </ul>
        </div>
      </div>

      <div class="nmb-grid-2">
        <div class="nmb-card">
          <h3><?php esc_html_e('Media type breakdown', 'nimbus'); ?></h3>
          <?php if (empty($mimeBreakdown)) : ?>
            <p><?php esc_html_e('No media type breakdown available yet.', 'nimbus'); ?></p>
          <?php else : ?>
            <ul class="nmb-status-list">
              <?php foreach ($mimeBreakdown as $row) : ?>
                <li>
                  <span class="dashicons dashicons-media-default"></span>
                  <strong><?php echo esc_html(ucfirst((string) ($row['category'] ?? 'other'))); ?></strong>
                  <span class="nmb-spacer"></span>
                  <?php echo esc_html(sprintf('%d / %d', (int) ($row['offloaded'] ?? 0), (int) ($row['total'] ?? 0))); ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="nmb-card">
          <h3><?php esc_html_e('Recent monthly trend', 'nimbus'); ?></h3>
          <?php if (empty($monthlyBreakdown)) : ?>
            <p><?php esc_html_e('No monthly attachment trend available yet.', 'nimbus'); ?></p>
          <?php else : ?>
            <ul class="nmb-status-list">
              <?php foreach ($monthlyBreakdown as $row) : ?>
                <li>
                  <span class="dashicons dashicons-calendar-alt"></span>
                  <strong><?php echo esc_html((string) ($row['month'] ?? '')); ?></strong>
                  <span class="nmb-spacer"></span>
                  <?php echo esc_html(sprintf('%d offloaded / %d total', (int) ($row['offloaded'] ?? 0), (int) ($row['total'] ?? 0))); ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($costComparison['scenarios'])) : ?>
      <div class="nmb-card nmb-card--full">
        <div class="nmb-cost-card-header">
          <div>
            <h3><?php esc_html_e('Provider Cost Comparison', 'nimbus'); ?></h3>
            <p class="nmb-muted" style="margin:4px 0 0;"><?php echo esc_html(sprintf(
              /* translators: 1: GB stored, 2: number of files, 3: GB egress estimate */
              __('Based on %1$.1f GB stored · %2$s files · est. %3$.1f GB monthly egress', 'nimbus'),
              (float) ($costComparison['storage_gb'] ?? 0),
              number_format((int) ($costComparison['offloaded_count'] ?? 0)),
              (float) ($costComparison['egress_gb_est'] ?? 0)
            )); ?></p>
          </div>
          <?php if ($currentScenario !== null && $currentScenario['estimated_monthly_total'] !== null) :
            // Find the cheapest non-null, non-minio scenario to surface a savings callout
            $cheapestTotal = null;
            $cheapestLabel = '';
            foreach ($costComparison['scenarios'] as $sc) {
              if ($sc['estimated_monthly_total'] === null || $sc['provider_key'] === 'minio') {
                continue;
              }
              if ($cheapestTotal === null || $sc['estimated_monthly_total'] < $cheapestTotal) {
                $cheapestTotal = $sc['estimated_monthly_total'];
                $cheapestLabel = $sc['label'];
              }
            }
            $savings = $currentScenario['estimated_monthly_total'] - ($cheapestTotal ?? $currentScenario['estimated_monthly_total']);
            if ($savings > 0.50 && ($cheapestTotal !== null && $cheapestLabel !== ($currentScenario['label'] ?? ''))) : ?>
            <div class="nmb-cost-savings-badge">
              <span class="dashicons dashicons-money-alt"></span>
              <?php echo esc_html(sprintf(
                /* translators: 1: monthly savings, 2: cheapest provider name */
                __('Save ~$%1$s/mo with %2$s', 'nimbus'),
                number_format($savings, 2),
                $cheapestLabel
              )); ?>
            </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="nmb-cost-table-wrap">
          <table class="nmb-cost-table">
            <thead>
              <tr>
                <th><?php esc_html_e('Provider', 'nimbus'); ?></th>
                <th class="nmb-cost-table__num"><?php esc_html_e('Storage', 'nimbus'); ?></th>
                <th class="nmb-cost-table__num"><?php esc_html_e('Egress', 'nimbus'); ?></th>
                <th class="nmb-cost-table__num"><?php esc_html_e('Requests', 'nimbus'); ?></th>
                <th class="nmb-cost-table__num"><?php esc_html_e('Base Fee', 'nimbus'); ?></th>
                <th class="nmb-cost-table__num"><?php esc_html_e('Est. Monthly', 'nimbus'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($costComparison['scenarios'] as $scenario) :
                $isCurrent = ($scenario['provider_key'] ?? '') === $currentProvider;
                $rowClass  = $isCurrent ? 'nmb-cost-table__row--current' : '';
              ?>
                <tr class="<?php echo esc_attr($rowClass); ?>">
                  <td class="nmb-cost-table__provider">
                    <?php if (!empty($scenario['pricing_url'])) : ?>
                      <a href="<?php echo esc_url($scenario['pricing_url']); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($scenario['label']); ?>
                      </a>
                    <?php else : ?>
                      <?php echo esc_html($scenario['label']); ?>
                    <?php endif; ?>
                    <?php if ($isCurrent) : ?>
                      <span class="nmb-badge nmb-badge--active"><?php esc_html_e('active', 'nimbus'); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($scenario['notes'])) : ?>
                      <span class="nmb-cost-table__note"><?php echo esc_html($scenario['notes']); ?></span>
                    <?php endif; ?>
                  </td>
                  <?php if ($scenario['estimated_monthly_total'] === null) : ?>
                    <td colspan="5" class="nmb-muted nmb-cost-table__num"><?php esc_html_e('Custom pricing — varies by vendor', 'nimbus'); ?></td>
                  <?php else : ?>
                    <td class="nmb-cost-table__num">$<?php echo number_format((float) $scenario['storage_cost'], 2); ?></td>
                    <td class="nmb-cost-table__num">
                      <?php if ($scenario['egress_cost'] == 0.0) : ?>
                        <span class="nmb-free"><?php esc_html_e('FREE', 'nimbus'); ?></span>
                      <?php else : ?>
                        $<?php echo number_format((float) $scenario['egress_cost'], 2); ?>
                      <?php endif; ?>
                    </td>
                    <td class="nmb-cost-table__num">
                      <?php if ($scenario['request_cost'] == 0.0) : ?>
                        <span class="nmb-free"><?php esc_html_e('FREE', 'nimbus'); ?></span>
                      <?php else : ?>
                        $<?php echo number_format((float) $scenario['request_cost'], 2); ?>
                      <?php endif; ?>
                    </td>
                    <td class="nmb-cost-table__num">
                      <?php echo $scenario['flat_monthly'] > 0 ? '$' . number_format((float) $scenario['flat_monthly'], 2) : '—'; ?>
                    </td>
                    <td class="nmb-cost-table__num nmb-cost-table__total">
                      <strong>$<?php echo number_format((float) $scenario['estimated_monthly_total'], 2); ?></strong>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="nmb-muted nmb-cost-disclaimer">
          <?php // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- The "%" here is literal prose ("1% monthly new uploads"), not a printf placeholder; there is nothing for a translator to reorder. ?>
          <?php esc_html_e('Estimates assume 10 monthly GET requests/file and 1% monthly new uploads. Prices sourced from provider websites and may change — always verify directly with your provider.', 'nimbus'); ?>
        </p>
      </div>
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
            <span class="nmb-log-chip-label"><?php esc_html_e('entries', 'nimbus'); ?></span>
          </span>
          <?php if ($warningCount > 0) : ?>
          <span class="nmb-log-chip is-warn">
            <strong class="nmb-log-chip-value"><?php echo esc_html(number_format($warningCount)); ?></strong>
            <span class="nmb-log-chip-label"><?php esc_html_e('warnings', 'nimbus'); ?></span>
          </span>
          <?php endif; ?>
          <?php if ($errorCount > 0) : ?>
          <span class="nmb-log-chip is-error">
            <strong class="nmb-log-chip-value"><?php echo esc_html(number_format($errorCount)); ?></strong>
            <span class="nmb-log-chip-label"><?php esc_html_e('errors', 'nimbus'); ?></span>
          </span>
          <?php endif; ?>
          <span class="nmb-log-chip <?php echo $loggerOn ? 'is-ok' : 'is-muted'; ?>">
            <?php echo $loggerOn ? esc_html__('Logging on', 'nimbus') : esc_html__('Logging off', 'nimbus'); ?>
            <span class="nmb-log-chip-label">· <?php echo esc_html($minLevel); ?></span>
          </span>
        </div>
        <div class="nmb-log-header-actions">
          <button type="button"
                  class="button button-secondary nmb-log-settings-toggle"
                  aria-expanded="false"
                  aria-controls="nmb-log-settings-panel">
            <?php esc_html_e('Logging settings', 'nimbus'); ?>
          </button>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_CLEAR_LOGS); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_CLEAR_LOGS); ?>" />
            <button type="submit"
                    class="button button-link nmb-log-clear-btn"
                    onclick="return confirm('<?php echo esc_js(__('Delete all plugin log entries? This cannot be undone.', 'nimbus')); ?>');">
              <?php esc_html_e('Clear logs', 'nimbus'); ?>
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
            <?php submit_button(__('Save Logging Settings', 'nimbus')); ?>
          </div>
        </form>
      </div>

      <!-- ─── Filter toolbar ───────────────────────────────────── -->
      <section class="nmb-card nmb-log-filter-card">
        <form method="get" class="nmb-log-filter-bar">
          <input type="hidden" name="page" value="<?php echo esc_attr(NIMBUS_SLUG); ?>" />
          <input type="hidden" name="tab" value="logs" />
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Level', 'nimbus'); ?></span>
            <select name="log_level">
              <option value=""><?php esc_html_e('All levels', 'nimbus'); ?></option>
              <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) : ?>
                <option value="<?php echo esc_attr($level); ?>" <?php selected($selectedLevel, $level); ?>><?php echo esc_html(strtoupper($level)); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Source', 'nimbus'); ?></span>
            <select name="log_source">
              <option value=""><?php esc_html_e('All sources', 'nimbus'); ?></option>
              <?php foreach ($sources as $src) : ?>
                <option value="<?php echo esc_attr((string) $src); ?>" <?php selected($selectedSource, (string) $src); ?>><?php echo esc_html((string) $src); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="nmb-log-filter-item nmb-log-filter-item--search">
            <span><?php esc_html_e('Search', 'nimbus'); ?></span>
            <input type="search" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('message, event, code…', 'nimbus'); ?>" />
          </label>
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Rows', 'nimbus'); ?></span>
            <select name="log_limit">
              <?php foreach ([50, 100, 200, 500, 1000] as $option) : ?>
                <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($limit, $option); ?>><?php echo esc_html((string) $option); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="nmb-log-filter-item nmb-log-filter-btns">
            <span aria-hidden="true"></span>
            <div>
              <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'nimbus'); ?></button>
              <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('logs')); ?>"><?php esc_html_e('Reset', 'nimbus'); ?></a>
            </div>
          </div>
        </form>
      </section>

      <!-- ─── Log table ────────────────────────────────────────── -->
      <section class="nmb-card nmb-log-viewer-card">
        <?php if (empty($entries)) : ?>
          <p class="nmb-muted"><?php esc_html_e('No log entries match the current filters.', 'nimbus'); ?></p>
        <?php else : ?>
          <div class="nmb-log-table-wrap">
            <table class="widefat striped nmb-log-table">
              <thead>
                <tr>
                  <th style="width:155px;"><?php esc_html_e('Time', 'nimbus'); ?></th>
                  <th style="width:90px;"><?php esc_html_e('Level', 'nimbus'); ?></th>
                  <th style="width:120px;"><?php esc_html_e('Source', 'nimbus'); ?></th>
                  <th style="width:160px;"><?php esc_html_e('Event / code', 'nimbus'); ?></th>
                  <th><?php esc_html_e('Message', 'nimbus'); ?></th>
                  <th style="width:200px;"><?php esc_html_e('Context', 'nimbus'); ?></th>
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
                          <summary><?php esc_html_e('View context', 'nimbus'); ?></summary>
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
        $message = __('URL update could not be started.', 'nimbus');

        if ($reason === 'disabled') {
          $message = __('Media offload is currently disabled. Enable it first in Settings.', 'nimbus');
        } elseif ($reason === 'credentials') {
          $message = __('Missing or invalid storage credentials. Please verify your settings first.', 'nimbus');
        } elseif ($reason === 'connection') {
          $message = __('Storage connection test failed (authentication/signing). Verify provider, endpoint, region, and keys before retrying.', 'nimbus');
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

        $modeLabel = $mode === 'full' ? __('full run', 'nimbus') : __('single update', 'nimbus');
        $noticeClass = 'notice notice-' . ($status === 'warning' ? 'warning' : ($status === 'info' ? 'info' : 'success'));
        ?>
        <div class="<?php echo esc_attr($noticeClass); ?>">
          <p>
            <?php
            echo esc_html(sprintf(
              /* translators: 1: run mode label, 2: processed count, 3: success count, 4: failed count, 5: remaining pending count */
              __('URL update %1$s finished. Processed: %2$d, Success: %3$d, Failed: %4$d, Remaining pending: %5$d.', 'nimbus'),
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
          'label' => __('Pending URL updates', 'nimbus'),
          'value' => number_format($pending),
          'substat' => __('Attachments waiting for URL/offload metadata update', 'nimbus'),
        ],
        [
          'label' => __('Offloaded attachments', 'nimbus'),
          'value' => number_format((int) ($stats['offloaded'] ?? 0)),
          'substat' => __('Already linked with offload metadata', 'nimbus'),
        ],
        [
          'label' => __('Plugin status', 'nimbus'),
          'value' => $isEnabled ? __('Enabled', 'nimbus') : __('Disabled', 'nimbus'),
          'substat' => $hasCredentials ? __('Credentials configured', 'nimbus') : __('Credentials missing', 'nimbus'),
        ],
        [
          'label' => __('Total attachments', 'nimbus'),
          'value' => number_format((int) ($stats['total'] ?? 0)),
          'substat' => __('In media library', 'nimbus'),
        ],
      ], 'nmb-url-update-summary-strip'); ?>

      <div class="nmb-card">
        <h3><?php esc_html_e('Run URL Updates', 'nimbus'); ?></h3>
        <p><?php esc_html_e('Use one-by-one for safer/manual progression, or full run to process all pending attachments in one go from this admin panel.', 'nimbus'); ?></p>
        <p><em><?php esc_html_e('Tip: attachments marked “Ignore from Nimbus” and files outside your allowed path filters are skipped automatically here.', 'nimbus'); ?></em></p>

        <?php if (!$isEnabled) : ?>
          <p><strong><?php esc_html_e('Media offload is disabled.', 'nimbus'); ?></strong></p>
        <?php endif; ?>

        <?php if (!$hasCredentials) : ?>
          <p><strong><?php esc_html_e('Valid storage credentials are required before URL updates can run.', 'nimbus'); ?></strong></p>
        <?php endif; ?>

        <div class="nmb-inline-actions">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>" />
            <input type="hidden" name="mode" value="single" />
            <button type="submit" class="button button-secondary" <?php disabled(!$isEnabled || !$hasCredentials || $pending <= 0); ?>>
              <?php esc_html_e('Update One Entry', 'nimbus'); ?>
            </button>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Run full URL update for all pending entries now?', 'nimbus')); ?>');">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>" />
            <input type="hidden" name="mode" value="full" />
            <button type="submit" class="button button-primary" <?php disabled(!$isEnabled || !$hasCredentials || $pending <= 0); ?>>
              <?php esc_html_e('Update All Pending Entries', 'nimbus'); ?>
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
      <h3><?php esc_html_e('Configuration backup', 'nimbus'); ?></h3>
      <p class="nmb-muted"><?php esc_html_e('Export your current settings as a portable JSON file, or import a previously exported file to restore them on another site. Credentials are included in the export — handle the file like a password.', 'nimbus'); ?></p>

      <div class="nmb-config-port-grid">

        <!-- Export form -->
        <div class="nmb-config-port-block">
          <h4><?php esc_html_e('Export configuration', 'nimbus'); ?></h4>
          <p class="nmb-muted"><?php esc_html_e('Downloads a .json file with all current provider, bucket, and delivery settings.', 'nimbus'); ?></p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_EXPORT_CONFIG); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_EXPORT_CONFIG); ?>" />
            <button type="submit" class="button button-secondary"><?php esc_html_e('Download config.json', 'nimbus'); ?></button>
          </form>
        </div>

        <!-- Import form -->
        <div class="nmb-config-port-block">
          <h4><?php esc_html_e('Import configuration', 'nimbus'); ?></h4>
          <p class="nmb-muted"><?php esc_html_e('Upload a Nimbus JSON export to overwrite the current settings immediately. Existing settings will be replaced.', 'nimbus'); ?></p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_IMPORT_CONFIG); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_IMPORT_CONFIG); ?>" />
            <input type="hidden" name="redirect_tab" value="tools" />
            <div class="nmb-config-port-file-row">
              <input type="file" name="nimbus_config_file" accept=".json,application/json" required />
              <button type="submit" class="button button-primary"><?php esc_html_e('Import', 'nimbus'); ?></button>
            </div>
          </form>
        </div>

      </div>
    </section>
    <?php
  }

  private function renderAdminShellHeader(string $activeTab): void
  {
    $settings = $this->settings->all();
    $providerKey = (string) ($settings['provider'] ?? 's3');
    $providerLabel = $this->settings->provider_profile($providerKey)->label();
    $deliveryLabel = $providerKey === 'local'
      ? __('Local debug mode', 'nimbus')
      : (!empty($settings['serve_from_storage']) ? __('Serving from storage', 'nimbus') : __('Serving from local URLs', 'nimbus'));
    $loggingLabel = !empty($settings['logging_enabled']) ? __('Logging enabled', 'nimbus') : __('Logging disabled', 'nimbus');

    $headerContent = match ($activeTab) {
      'settings' => [
        'kicker' => __('Settings', 'nimbus'),
        'description' => __('Designated for provider setup, delivery behavior, caching rules, and automation—without burying the important controls under a wall of unrelated tools.', 'nimbus'),
      ],
      'tools' => [
        'kicker' => __('Tools', 'nimbus'),
        'description' => __('The operational workbench for jobs, migration recovery, URL repair, integrations, and logging—built for confidence instead of guesswork.', 'nimbus'),
      ],
      default => [
        'kicker' => __('Overview', 'nimbus'),
        'description' => __('A clean command center for checking readiness, volume, pipeline health, and the next actions worth taking on your media stack.', 'nimbus'),
      ],
    };
    ?>
    <section class="nmb-shell-header">
      <div class="nmb-shell-header__content">
        <p class="nmb-shell-kicker"><?php echo esc_html($headerContent['kicker']); ?></p>
        <h1><?php esc_html_e('Nimbus', 'nimbus'); ?></h1>
        <p class="nmb-shell-lede"><?php echo esc_html($headerContent['description']); ?></p>
      </div>

      <div class="nmb-shell-header__meta">
        <div class="nmb-shell-meta-card">
          <span class="nmb-shell-meta-card__label"><?php esc_html_e('Active view', 'nimbus'); ?></span>
          <strong><?php echo esc_html(ucfirst($activeTab)); ?></strong>
        </div>
        <div class="nmb-shell-meta-card">
          <span class="nmb-shell-meta-card__label"><?php esc_html_e('Provider', 'nimbus'); ?></span>
          <strong id="nmb-shell-meta-provider"><?php echo esc_html($providerLabel); ?></strong>
        </div>
        <div class="nmb-shell-meta-card">
          <span class="nmb-shell-meta-card__label"><?php esc_html_e('Delivery', 'nimbus'); ?></span>
          <strong id="nmb-shell-meta-delivery"><?php echo esc_html($deliveryLabel); ?></strong>
        </div>
        <div class="nmb-shell-meta-card">
          <span class="nmb-shell-meta-card__label"><?php esc_html_e('Diagnostics', 'nimbus'); ?></span>
          <strong id="nmb-shell-meta-diagnostics"><?php echo esc_html($loggingLabel); ?></strong>
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
}
