<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Bootstrap\DataMigration;
use Nimbus\Plugin\Jobs\BulkJobManager;
use Nimbus\Plugin\Integration\IntegrationDiscovery;
use Nimbus\Plugin\License\LicenseManager;
use Nimbus\Plugin\Onboarding\OnboardingManager;
use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Media\UploadHandler;
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
    private LicenseManager $licenseManager,
    private OnboardingManager $onboardingManager,
    private IntegrationDiscovery $integrationDiscovery,
    private BulkJobManager $bulkJobManager,
  ) {
  }

  public function renderPage(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    if (isset($_GET['nimbus_logs_cleared']) && $_GET['nimbus_logs_cleared'] === '1') {
      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Plugin logs cleared.', NIMBUS_TEXT_DOMAIN) . '</p></div>';
    }

    settings_errors(NIMBUS_SETTINGS_GROUP);

    $onboarding = $this->onboardingManager->state();
    if (empty($onboarding['completed'])) {
      ?>
      <div class="wrap nimbus-settings nimbus-settings--onboarding">
        <h1><?php esc_html_e('Set up Nimbus', NIMBUS_TEXT_DOMAIN); ?></h1>
        <?php $this->renderOnboardingTab(); ?>
      </div>
      <?php
      return;
    }

    $activeTab = $this->resolveActiveTab();
    $showOverlay = !$this->licenseManager->hasKey() && $activeTab !== 'license';
    ?>
    <div class="wrap nimbus-settings">
      <h1 class="wp-heading-inline"><?php esc_html_e('Nimbus', NIMBUS_TEXT_DOMAIN); ?></h1>

      <?php settings_errors(NIMBUS_SETTINGS_GROUP); ?>

      <nav class="nav-tab-wrapper nmb-shell-tabs" aria-label="<?php esc_attr_e('Nimbus navigation', NIMBUS_TEXT_DOMAIN); ?>">
        <a href="<?php echo esc_url($this->adminTabUrl('overview')); ?>" class="nav-tab <?php echo $activeTab === 'overview' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Overview', NIMBUS_TEXT_DOMAIN); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('settings')); ?>" class="nav-tab <?php echo $activeTab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Settings', NIMBUS_TEXT_DOMAIN); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>" class="nav-tab <?php echo $activeTab === 'integrations' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Integrations', NIMBUS_TEXT_DOMAIN); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('tools')); ?>" class="nav-tab <?php echo $activeTab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Tools', NIMBUS_TEXT_DOMAIN); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('logs')); ?>" class="nav-tab <?php echo $activeTab === 'logs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Logs', NIMBUS_TEXT_DOMAIN); ?></a>
        <a href="<?php echo esc_url($this->adminTabUrl('license')); ?>" class="nav-tab <?php echo $activeTab === 'license' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('License', NIMBUS_TEXT_DOMAIN); ?></a>
      </nav>

      <?php if ($showOverlay) : ?>
        <?php $this->renderLicenseOverlay(); ?>
      <?php endif; ?>

      <?php if ($activeTab === 'overview') : ?>
        <?php $this->renderOverviewTab(); ?>
      <?php elseif ($activeTab === 'settings') : ?>
        <?php $this->renderSettingsTab(); ?>
      <?php elseif ($activeTab === 'integrations') : ?>
        <?php $this->renderIntegrationsTab(); ?>
      <?php elseif ($activeTab === 'tools') : ?>
        <?php $this->renderToolsTab(); ?>
      <?php elseif ($activeTab === 'logs') : ?>
        <?php $this->renderLogsTab(); ?>
      <?php elseif ($activeTab === 'license') : ?>
        <?php $this->renderLicenseTab(); ?>
      <?php else : ?>
        <?php $this->renderOverviewTab(); ?>
      <?php endif; ?>
    </div>
    <?php
  }

  private function resolveActiveTab(): string
  {
    if (!$this->onboardingManager->isCompleted()) {
      return 'onboarding';
    }

    $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'overview';
    $tab = self::TAB_ALIASES[$tab] ?? $tab;

    if (!in_array($tab, ['overview', 'settings', 'integrations', 'tools', 'logs', 'license'], true)) {
      return 'overview';
    }

    return $tab;
  }

  private function renderOnboardingTab(): void
  {
    $settings = $this->settings->all();
    $license = $this->licenseManager->all();
    $state = $this->onboardingManager->state();
    $requestedStep = isset($_GET['onboarding_step']) ? sanitize_key((string) wp_unslash($_GET['onboarding_step'])) : 'account';
    $stepKeys = ['account', 'storage', 'delivery', 'review'];
    $currentStepIndex = array_search($requestedStep, $stepKeys, true);
    if ($currentStepIndex === false) {
      $currentStepIndex = 0;
    }
    ?>
    <div id="nmb-onboarding-wizard" class="nmb-onboarding-shell" data-current-step="<?php echo esc_attr((string) $currentStepIndex); ?>">
      <aside class="nmb-onboarding-sidebar">
        <div class="nmb-onboarding-brand-panel">
          <span class="nmb-onboarding-brand-icon dashicons dashicons-cloud-upload"></span>
          <strong class="nmb-onboarding-brand-name">Nimbus</strong>
          <p class="nmb-onboarding-brand-tagline"><?php esc_html_e('Your media, elevated.', NIMBUS_TEXT_DOMAIN); ?></p>
        </div>

        <div class="nmb-card nmb-onboarding-step-nav">
          <ol class="nmb-onboarding-step-list">
            <li class="nmb-onboarding-step-item" data-step-item="account">
              <span class="nmb-onboarding-step-index">1</span>
              <div>
                <strong><?php esc_html_e('Account', NIMBUS_TEXT_DOMAIN); ?></strong>
                <span><?php esc_html_e('Add your license key.', NIMBUS_TEXT_DOMAIN); ?></span>
              </div>
            </li>
            <li class="nmb-onboarding-step-item" data-step-item="storage">
              <span class="nmb-onboarding-step-index">2</span>
              <div>
                <strong><?php esc_html_e('Storage', NIMBUS_TEXT_DOMAIN); ?></strong>
                <span><?php esc_html_e('Connect the provider and bucket.', NIMBUS_TEXT_DOMAIN); ?></span>
              </div>
            </li>
            <li class="nmb-onboarding-step-item" data-step-item="delivery">
              <span class="nmb-onboarding-step-index">3</span>
              <div>
                <strong><?php esc_html_e('Delivery', NIMBUS_TEXT_DOMAIN); ?></strong>
                <span><?php esc_html_e('Choose how media should be served.', NIMBUS_TEXT_DOMAIN); ?></span>
              </div>
            </li>
            <li class="nmb-onboarding-step-item" data-step-item="review">
              <span class="nmb-onboarding-step-index">4</span>
              <div>
                <strong><?php esc_html_e('Review', NIMBUS_TEXT_DOMAIN); ?></strong>
                <span><?php esc_html_e('Confirm and unlock the dashboard.', NIMBUS_TEXT_DOMAIN); ?></span>
              </div>
            </li>
          </ol>
        </div>
      </aside>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nmb-onboarding-form nmb-onboarding-main">
        <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_COMPLETE_ONBOARDING); ?>" />

        <section class="nmb-card nmb-onboarding-step" data-step="account">
          <div class="nmb-onboarding-step-header">
            <p class="nmb-eyebrow"><?php esc_html_e('Step 1', NIMBUS_TEXT_DOMAIN); ?></p>
            <h2><?php esc_html_e('Connect your account', NIMBUS_TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Paste the Nimbus license key you received. This is required before the plugin dashboard becomes available.', NIMBUS_TEXT_DOMAIN); ?></p>
          </div>

          <label class="nmb-onboarding-field nmb-onboarding-field--full">
            <span><?php esc_html_e('License key', NIMBUS_TEXT_DOMAIN); ?></span>
            <input type="text" id="nimbus-license-key" name="license_key" value="<?php echo esc_attr((string) ($license['key'] ?? '')); ?>" placeholder="nmb_live_xxxxxxxxx" autocomplete="off" class="nmb-key-input" />
          </label>

          <ul class="nmb-onboarding-tips">
            <li><?php esc_html_e('Copy the key exactly as shown in your account or email.', NIMBUS_TEXT_DOMAIN); ?></li>
            <li><?php esc_html_e('Avoid spaces before or after the value.', NIMBUS_TEXT_DOMAIN); ?></li>
            <li><?php esc_html_e('If you do not have a key yet, pause here and get one before continuing.', NIMBUS_TEXT_DOMAIN); ?></li>
          </ul>

          <div class="nmb-onboarding-actions">
            <span></span>
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to storage', NIMBUS_TEXT_DOMAIN); ?></button>
          </div>
        </section>

        <section class="nmb-card nmb-onboarding-step" data-step="storage" hidden>
          <div class="nmb-onboarding-step-header">
            <p class="nmb-eyebrow"><?php esc_html_e('Step 2', NIMBUS_TEXT_DOMAIN); ?></p>
            <h2><?php esc_html_e('Connect your storage provider', NIMBUS_TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Select the provider where media should live, then enter the bucket and credentials needed for offloading.', NIMBUS_TEXT_DOMAIN); ?></p>
          </div>

          <div class="nmb-onboarding-field-grid nmb-onboarding-field-grid--two-up">
            <label class="nmb-onboarding-field">
              <span><?php esc_html_e('Provider', NIMBUS_TEXT_DOMAIN); ?></span>
              <select id="nimbus-provider" name="provider">
                <?php foreach ($this->settings->available_provider_keys() as $providerKey) : ?>
                  <option value="<?php echo esc_attr($providerKey); ?>" <?php selected((string) ($settings['provider'] ?? 's3'), $providerKey); ?>><?php echo esc_html($this->settings->provider_profile($providerKey)->label()); ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="nmb-onboarding-field">
              <span><?php esc_html_e('Bucket', NIMBUS_TEXT_DOMAIN); ?></span>
              <input type="text" id="nimbus-bucket" name="bucket" value="<?php echo esc_attr((string) ($settings['bucket'] ?? '')); ?>" placeholder="my-media-bucket" autocomplete="off" />
            </label>

            <label class="nmb-onboarding-field">
              <span><?php esc_html_e('Region', NIMBUS_TEXT_DOMAIN); ?></span>
              <input type="text" id="nimbus-region" name="region" value="<?php echo esc_attr((string) ($settings['region'] ?? '')); ?>" placeholder="ap-south-1 / auto" autocomplete="off" />
            </label>

            <label class="nmb-onboarding-field">
              <span><?php esc_html_e('Path prefix', NIMBUS_TEXT_DOMAIN); ?></span>
              <input type="text" id="nimbus-prefix" name="prefix" value="<?php echo esc_attr((string) ($settings['prefix'] ?? '')); ?>" placeholder="media/uploads" autocomplete="off" />
            </label>

            <label class="nmb-onboarding-field">
              <span><?php esc_html_e('Access key', NIMBUS_TEXT_DOMAIN); ?></span>
              <input type="text" id="nimbus-access-key" name="access_key" value="<?php echo esc_attr((string) ($settings['access_key'] ?? '')); ?>" autocomplete="off" />
            </label>

            <label class="nmb-onboarding-field">
              <span><?php esc_html_e('Secret key', NIMBUS_TEXT_DOMAIN); ?></span>
              <input type="password" id="nimbus-secret-key" name="secret_key" value="<?php echo esc_attr((string) ($settings['secret_key'] ?? '')); ?>" autocomplete="new-password" />
            </label>

            <label class="nmb-onboarding-field">
              <span><?php esc_html_e('R2 account ID', NIMBUS_TEXT_DOMAIN); ?></span>
              <input type="text" id="nimbus-r2-account-id" name="r2_account_id" value="<?php echo esc_attr((string) ($settings['r2_account_id'] ?? '')); ?>" placeholder="For Cloudflare R2" autocomplete="off" />
            </label>

            <label class="nmb-onboarding-field">
              <span><?php esc_html_e('Custom endpoint', NIMBUS_TEXT_DOMAIN); ?></span>
              <input type="url" id="nimbus-endpoint" name="endpoint" value="<?php echo esc_attr((string) ($settings['endpoint'] ?? '')); ?>" placeholder="https://account-id.r2.cloudflarestorage.com" autocomplete="off" />
            </label>

            <label class="nmb-onboarding-field nmb-onboarding-field--full">
              <span><?php esc_html_e('Session token (optional)', NIMBUS_TEXT_DOMAIN); ?></span>
              <input type="password" id="nimbus-session-token" name="session_token" value="<?php echo esc_attr((string) ($settings['session_token'] ?? '')); ?>" autocomplete="new-password" />
            </label>
          </div>

          <div class="nmb-card nmb-onboarding-helper-card">
            <h3><?php esc_html_e('How to do this', NIMBUS_TEXT_DOMAIN); ?></h3>
            <ul class="nmb-bullet-list">
              <li><?php esc_html_e('Amazon S3 usually needs bucket, region, access key, and secret key.', NIMBUS_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('Cloudflare R2 usually needs bucket, access key, secret key, and either an account ID or custom endpoint. Use region “auto”.', NIMBUS_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('S3-compatible services normally need a custom endpoint in addition to standard credentials.', NIMBUS_TEXT_DOMAIN); ?></li>
            </ul>
          </div>

          <div class="nmb-onboarding-actions">
            <button type="button" class="button button-secondary" data-onboarding-back><?php esc_html_e('Back', NIMBUS_TEXT_DOMAIN); ?></button>
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to delivery', NIMBUS_TEXT_DOMAIN); ?></button>
          </div>
        </section>

        <section class="nmb-card nmb-onboarding-step" data-step="delivery" hidden>
          <div class="nmb-onboarding-step-header">
            <p class="nmb-eyebrow"><?php esc_html_e('Step 3', NIMBUS_TEXT_DOMAIN); ?></p>
            <h2><?php esc_html_e('Choose delivery behavior', NIMBUS_TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Decide how Nimbus should behave once setup is complete. You can still tune these later from Settings.', NIMBUS_TEXT_DOMAIN); ?></p>
          </div>

          <div class="nmb-onboarding-preference-grid">
            <label class="nmb-card nmb-onboarding-choice-card">
              <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?> />
              <div>
                <strong><?php esc_html_e('Enable Nimbus after setup', NIMBUS_TEXT_DOMAIN); ?></strong>
                <p><?php esc_html_e('Turn on the offload workflow once the wizard is finished.', NIMBUS_TEXT_DOMAIN); ?></p>
              </div>
            </label>

            <label class="nmb-card nmb-onboarding-choice-card">
              <input type="checkbox" name="serve_from_storage" value="1" <?php checked(!empty($settings['serve_from_storage'])); ?> />
              <div>
                <strong><?php esc_html_e('Serve media from remote storage', NIMBUS_TEXT_DOMAIN); ?></strong>
                <p><?php esc_html_e('Rewrite public media URLs so visitors load files from your bucket or CDN.', NIMBUS_TEXT_DOMAIN); ?></p>
              </div>
            </label>

            <label class="nmb-card nmb-onboarding-choice-card">
              <input type="checkbox" name="use_path_style_endpoint" value="1" <?php checked(!empty($settings['use_path_style_endpoint'])); ?> />
              <div>
                <strong><?php esc_html_e('Use path-style requests', NIMBUS_TEXT_DOMAIN); ?></strong>
                <p><?php esc_html_e('Recommended for Cloudflare R2 and some S3-compatible providers.', NIMBUS_TEXT_DOMAIN); ?></p>
              </div>
            </label>
          </div>

          <label class="nmb-onboarding-field nmb-onboarding-field--full">
            <span><?php esc_html_e('Custom delivery domain (optional)', NIMBUS_TEXT_DOMAIN); ?></span>
            <input type="url" name="custom_domain" value="<?php echo esc_attr((string) ($settings['custom_domain'] ?? '')); ?>" placeholder="https://cdn.example.com" autocomplete="off" />
          </label>

          <div class="nmb-card nmb-onboarding-helper-card">
            <h3><?php esc_html_e('What happens next', NIMBUS_TEXT_DOMAIN); ?></h3>
            <ul class="nmb-bullet-list">
              <li><?php esc_html_e('After setup, you can validate the connection from Tools.', NIMBUS_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('You can delay public URL rewriting by leaving remote delivery off for now.', NIMBUS_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('All of these preferences remain editable from Settings later.', NIMBUS_TEXT_DOMAIN); ?></li>
            </ul>
          </div>

          <div class="nmb-onboarding-actions">
            <button type="button" class="button button-secondary" data-onboarding-back><?php esc_html_e('Back', NIMBUS_TEXT_DOMAIN); ?></button>
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to review', NIMBUS_TEXT_DOMAIN); ?></button>
          </div>
        </section>

        <section class="nmb-card nmb-onboarding-step" data-step="review" hidden>
          <div class="nmb-onboarding-step-header">
            <p class="nmb-eyebrow"><?php esc_html_e('Step 4', NIMBUS_TEXT_DOMAIN); ?></p>
            <h2><?php esc_html_e('Review and finish setup', NIMBUS_TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Confirm the final checklist below. Once you finish this step, the plugin dashboard unlocks and the guided setup disappears.', NIMBUS_TEXT_DOMAIN); ?></p>
          </div>

          <div class="nmb-onboarding-review-grid">
            <div class="nmb-card nmb-onboarding-helper-card">
              <h3><?php esc_html_e('Final checklist', NIMBUS_TEXT_DOMAIN); ?></h3>
              <ul class="nmb-status-list">
                <li><span class="dashicons dashicons-admin-network"></span><strong><?php esc_html_e('License key', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><?php echo !empty($license['key']) ? esc_html__('Previously saved', NIMBUS_TEXT_DOMAIN) : esc_html__('Will be saved now', NIMBUS_TEXT_DOMAIN); ?></li>
                <li><span class="dashicons dashicons-cloud"></span><strong><?php esc_html_e('Storage', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><?php esc_html_e('Connected in this setup flow', NIMBUS_TEXT_DOMAIN); ?></li>
                <li><span class="dashicons dashicons-admin-home"></span><strong><?php esc_html_e('Dashboard', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><?php esc_html_e('Unlocks after finish', NIMBUS_TEXT_DOMAIN); ?></li>
              </ul>
            </div>

            <div class="nmb-card nmb-onboarding-helper-card">
              <h3><?php esc_html_e('Conflict review', NIMBUS_TEXT_DOMAIN); ?></h3>
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
                <p class="nmb-muted"><?php esc_html_e('No conflicting media offload plugins were detected. Nice and clean.', NIMBUS_TEXT_DOMAIN); ?></p>
              <?php endif; ?>

              <?php if (!empty($state['has_active_conflicts'])) : ?>
                <label class="nmb-onboarding-checkbox-note">
                  <input type="checkbox" name="conflict_acknowledged" value="1" />
                  <span><?php esc_html_e('I understand another media offload plugin is active and I will review the warnings after setup.', NIMBUS_TEXT_DOMAIN); ?></span>
                </label>
              <?php endif; ?>
            </div>
          </div>

          <div class="nmb-onboarding-actions">
            <button type="button" class="button button-secondary" data-onboarding-back><?php esc_html_e('Back', NIMBUS_TEXT_DOMAIN); ?></button>
            <button type="submit" class="button button-primary"><?php esc_html_e('Finish setup and unlock dashboard', NIMBUS_TEXT_DOMAIN); ?></button>
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
    $integrationSummary = $this->integrationDiscovery->summary();
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
          <h3><?php esc_html_e('Setup health', NIMBUS_TEXT_DOMAIN); ?></h3>
          <ul class="nmb-status-list">
            <li>
              <span class="dashicons <?php echo !empty($onboarding['completed']) ? 'dashicons-yes-alt status-ok' : 'dashicons-warning status-warning'; ?>"></span>
              <strong><?php esc_html_e('Onboarding:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <?php echo !empty($onboarding['completed']) ? esc_html__('Completed', NIMBUS_TEXT_DOMAIN) : esc_html__('Needs review', NIMBUS_TEXT_DOMAIN); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $this->settings->has_valid_credentials() ? 'dashicons-yes-alt status-ok' : 'dashicons-warning status-error'; ?>"></span>
              <strong><?php esc_html_e('Credentials:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <?php echo $this->settings->has_valid_credentials() ? esc_html__('Configured', NIMBUS_TEXT_DOMAIN) : esc_html__('Needs attention', NIMBUS_TEXT_DOMAIN); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $this->settings->should_serve_from_storage() ? 'dashicons-admin-links status-ok' : 'dashicons-minus'; ?>"></span>
              <strong><?php esc_html_e('Delivery:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <?php echo $this->settings->should_serve_from_storage() ? esc_html__('Serving from storage', NIMBUS_TEXT_DOMAIN) : esc_html__('Using local/origin URLs', NIMBUS_TEXT_DOMAIN); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $pending > 0 ? 'dashicons-update status-warning' : 'dashicons-yes-alt status-ok'; ?>"></span>
              <strong><?php esc_html_e('Pending URL updates:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <?php echo number_format($pending); ?>
            </li>
            <li>
              <span class="dashicons dashicons-format-status"></span>
              <strong><?php esc_html_e('Offloaded media:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <?php echo number_format((int) ($stats['offloaded'] ?? 0)); ?>
            </li>
            <li>
              <span class="dashicons <?php echo !empty($onboarding['has_active_conflicts']) ? 'dashicons-warning status-error' : 'dashicons-yes-alt status-ok'; ?>"></span>
              <strong><?php esc_html_e('Plugin conflicts:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <?php echo !empty($onboarding['has_active_conflicts']) ? esc_html__('Review required', NIMBUS_TEXT_DOMAIN) : esc_html__('No active conflicts detected', NIMBUS_TEXT_DOMAIN); ?>
            </li>
          </ul>
          <div class="nmb-actions nmb-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('settings', 'settings-core')); ?>"><?php esc_html_e('Configure settings', NIMBUS_TEXT_DOMAIN); ?></a>
            <a class="button" href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>"><?php printf(esc_html__('Integrations (%d detected)', NIMBUS_TEXT_DOMAIN), (int) ($integrationSummary['detected'] ?? 0)); ?></a>
          </div>
        </div>

        <div class="nmb-card">
          <h3><?php esc_html_e('Upload folder structure', NIMBUS_TEXT_DOMAIN); ?></h3>
          <p class="nmb-muted" style="margin:0 0 12px;"><?php esc_html_e('WordPress organises your media into date-based sub-folders. These are the top-level folders found in your uploads directory.', NIMBUS_TEXT_DOMAIN); ?></p>
          <?php if (empty($deepStats['asset_roots'])) : ?>
            <p class="nmb-muted"><?php esc_html_e('No upload folders sampled yet. Upload a file to populate this.', NIMBUS_TEXT_DOMAIN); ?></p>
          <?php else : ?>
            <ul class="nmb-status-list">
              <?php foreach ($deepStats['asset_roots'] as $root) : ?>
                <li>
                  <span class="dashicons dashicons-category"></span>
                  <code style="font-family:var(--mo-mono);font-size:12px;background:var(--mo-surface-alt);padding:2px 6px;border:1px solid var(--mo-border);border-radius:var(--mo-radius);"><?php echo esc_html((string) ($root['root'] ?? '')); ?>/</code>
                  <span class="nmb-spacer"></span>
                  <span title="<?php esc_attr_e('Files in this folder', NIMBUS_TEXT_DOMAIN); ?>"><?php echo number_format((int) ($root['count'] ?? 0)); ?> <?php esc_html_e('files', NIMBUS_TEXT_DOMAIN); ?></span>
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
    $allowedPatterns = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($settings['allowed_path_patterns'] ?? '')) ?: []));
    $ignoredPatterns = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($settings['ignored_path_patterns'] ?? '')) ?: []));
    $deliveryLabel = $isLocalProvider
      ? __('Local debug', NIMBUS_TEXT_DOMAIN)
      : (!empty($settings['serve_from_storage']) ? __('Remote', NIMBUS_TEXT_DOMAIN) : __('Local', NIMBUS_TEXT_DOMAIN));
    $providerSubstat = $isLocalProvider
      ? __('No bucket required in local mode', NIMBUS_TEXT_DOMAIN)
      : (string) ($settings['bucket'] ?? __('Bucket not set', NIMBUS_TEXT_DOMAIN));
    ?>
    <div class="nmb-settings-shell">
      <?php $this->renderSummaryStrip([
        [
          'label' => __('Provider', NIMBUS_TEXT_DOMAIN),
          'value' => $providerLabel,
          'value_id' => 'nmb-settings-provider-label',
          'substat' => $providerSubstat,
          'substat_id' => 'nmb-settings-provider-substat',
        ],
        [
          'label' => __('Delivery', NIMBUS_TEXT_DOMAIN),
          'value' => $deliveryLabel,
          'value_id' => 'nmb-settings-delivery-label',
          'substat' => !empty($settings['custom_domain']) ? (string) $settings['custom_domain'] : __('No custom domain configured', NIMBUS_TEXT_DOMAIN),
          'substat_id' => 'nmb-settings-delivery-substat',
        ],
        [
          'label' => __('Path rules', NIMBUS_TEXT_DOMAIN),
          'value' => number_format(count($allowedPatterns) + count($ignoredPatterns)),
          'value_id' => 'nmb-settings-rules-count',
          'substat' => sprintf(__('Allowed: %1$d · Ignored: %2$d', NIMBUS_TEXT_DOMAIN), count($allowedPatterns), count($ignoredPatterns)),
          'substat_id' => 'nmb-settings-rules-substat',
        ],
        [
          'label' => __('Off-hours batch', NIMBUS_TEXT_DOMAIN),
          'value' => !empty($settings['off_hours_batch_enabled']) ? __('On', NIMBUS_TEXT_DOMAIN) : __('Off', NIMBUS_TEXT_DOMAIN),
          'value_id' => 'nmb-settings-batch-label',
          'substat' => !empty($settings['off_hours_batch_time']) ? (string) $settings['off_hours_batch_time'] : __('No schedule set', NIMBUS_TEXT_DOMAIN),
          'substat_id' => 'nmb-settings-batch-substat',
        ],
      ], 'nmb-settings-summary-strip'); ?>

      <form method="post" action="options.php" autocomplete="off" class="nmb-settings-form">
        <?php settings_fields(NIMBUS_SETTINGS_GROUP); ?>

        <div class="nmb-settings-main">
            <section id="settings-core" class="nmb-settings-cluster">
              <div class="nmb-settings-cluster-header">
                <p class="nmb-eyebrow"><?php esc_html_e('Core setup', NIMBUS_TEXT_DOMAIN); ?></p>
                <h2><?php esc_html_e('Connection and delivery', NIMBUS_TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('Everything required to connect a provider, define the bucket, and decide how media URLs are served.', NIMBUS_TEXT_DOMAIN); ?></p>
              </div>
              <?php $this->settingsRegistrar->renderSections(['nimbus_connection', 'nimbus_delivery']); ?>
            </section>

            <section id="settings-automation" class="nmb-settings-cluster">
              <div class="nmb-settings-cluster-header">
                <p class="nmb-eyebrow"><?php esc_html_e('Automation', NIMBUS_TEXT_DOMAIN); ?></p>
                <h2><?php esc_html_e('Rules and scheduling', NIMBUS_TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('Control which paths are eligible for offload and when background batching should run.', NIMBUS_TEXT_DOMAIN); ?></p>
              </div>
              <?php $this->settingsRegistrar->renderSections(['nimbus_path_filters', 'nimbus_off_hours']); ?>
            </section>

            <div class="nmb-settings-submit">
              <?php submit_button(__('Save Settings', NIMBUS_TEXT_DOMAIN)); ?>
            </div>
          </div>
      </form>

      <div class="nmb-secondary-surface-grid">
        <div class="nmb-card">
          <h3><?php esc_html_e('Integration rewrites', NIMBUS_TEXT_DOMAIN); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('Plugin-specific asset roots and rewrite actions are now grouped with operational tools, where you can test and execute them without cluttering configuration.', NIMBUS_TEXT_DOMAIN); ?></p>
          <div class="nmb-actions nmb-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('integrations')); ?>"><?php esc_html_e('Manage integrations', NIMBUS_TEXT_DOMAIN); ?></a>
          </div>
        </div>

        <div class="nmb-card">
          <h3><?php esc_html_e('Account and licensing', NIMBUS_TEXT_DOMAIN); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('License state is shown on Overview, while initial key entry remains in onboarding so setup stays linear for new installs.', NIMBUS_TEXT_DOMAIN); ?></p>
          <div class="nmb-actions nmb-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('license')); ?>"><?php esc_html_e('Manage license', NIMBUS_TEXT_DOMAIN); ?></a>
          </div>
        </div>
      </div>
    </div>
    <?php
  }

  private function renderToolsTab(): void
  {
    $migration = DataMigration::status();
    ?>
    <div class="nmb-settings-shell">
      <div class="nmb-panel-toolbar nmb-card" style="display:flex;align-items:center;gap:12px;padding:12px 16px;margin-bottom:4px;">
        <strong><?php esc_html_e('Connectivity', NIMBUS_TEXT_DOMAIN); ?></strong>
        <button type="button" id="nmb-test-connection" class="button button-secondary"><?php esc_html_e('Test Connection', NIMBUS_TEXT_DOMAIN); ?></button>
        <span id="nmb-connection-status"></span>
      </div>
      <div id="mo-connection-results" class="nmb-connection-results" hidden></div>

      <?php $this->renderBulkJobsPanel(); ?>
      <?php if (!empty($migration['needs_migration'])) : ?>
        <?php $this->renderActivationRecoveryPanel($migration); ?>
      <?php endif; ?>
      <?php $this->renderAttachmentInspector(); ?>
      <?php $this->renderUrlUpdatesPanel(); ?>
    </div>
    <?php
  }

  private function renderBulkJobsPanel(): void
  {
    $state = $this->bulkJobManager->state();
    $job = !empty($state['current']['id']) ? $state['current'] : $state['last'];
    $hasJob = !empty($job['id']);
    ?>
    <section class="nmb-card nmb-bulk-job-shell" id="nmb-bulk-job-app">
      <div class="nmb-bulk-job-header">
        <div>
          <h3><?php esc_html_e('Bulk media jobs', NIMBUS_TEXT_DOMAIN); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('This panel polls the current queue state and lets you start, pause, resume, or retry background jobs.', NIMBUS_TEXT_DOMAIN); ?></p>
        </div>
        <span id="nmb-job-status-badge" class="nmb-inline-badge <?php echo $hasJob ? esc_attr($this->jobBadgeClass((string) ($job['status'] ?? 'idle'))) : ''; ?>"><?php echo esc_html($hasJob ? (string) ($job['status_label'] ?? __('Idle', NIMBUS_TEXT_DOMAIN)) : __('Idle', NIMBUS_TEXT_DOMAIN)); ?></span>
      </div>

      <div class="nmb-bulk-job-grid">
        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Current job', NIMBUS_TEXT_DOMAIN); ?></h3>
          <div class="nmb-stat" id="nmb-job-label"><?php echo esc_html($hasJob ? (string) ($job['type_label'] ?? __('No active job', NIMBUS_TEXT_DOMAIN)) : __('No active job', NIMBUS_TEXT_DOMAIN)); ?></div>
          <div class="nmb-substat" id="nmb-job-message"><?php echo esc_html($hasJob ? (string) ($job['last_message'] ?? '') : __('Choose a bulk task to begin.', NIMBUS_TEXT_DOMAIN)); ?></div>
          <div class="nmb-progress nmb-bulk-job-progress"><div id="nmb-job-progress-bar" class="nmb-bar" style="width: <?php echo esc_attr((string) ($job['percent_complete'] ?? 0)); ?>%"></div></div>
          <div class="nmb-substat" id="nmb-job-progress-text"><?php echo esc_html(sprintf(__('%1$d%% complete', NIMBUS_TEXT_DOMAIN), (int) ($job['percent_complete'] ?? 0))); ?></div>
        </div>

        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Queue state', NIMBUS_TEXT_DOMAIN); ?></h3>
          <ul class="nmb-status-list">
            <li><strong><?php esc_html_e('Processed', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-processed"><?php echo number_format((int) ($job['processed_items'] ?? 0)); ?></span> / <span id="nmb-job-total"><?php echo number_format((int) ($job['total_items'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Succeeded', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-success"><?php echo number_format((int) ($job['success_count'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Failed', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-failed"><?php echo number_format((int) ($job['failed_count'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Skipped', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><span id="nmb-job-skipped"><?php echo number_format((int) ($job['skipped_count'] ?? 0)); ?></span></li>
          </ul>
        </div>

        <div class="nmb-bulk-job-pane">
          <h3><?php esc_html_e('Actions', NIMBUS_TEXT_DOMAIN); ?></h3>
          <div class="nmb-bulk-job-actions">
            <button type="button" class="button button-primary" data-job-action="start" data-job-type="offload_existing"><?php esc_html_e('Offload existing library', NIMBUS_TEXT_DOMAIN); ?></button>
            <button type="button" class="button" data-job-action="start" data-job-type="restore_local"><?php esc_html_e('Restore local copies', NIMBUS_TEXT_DOMAIN); ?></button>
            <button type="button" class="button" data-job-action="pause"><?php esc_html_e('Pause', NIMBUS_TEXT_DOMAIN); ?></button>
            <button type="button" class="button" data-job-action="resume"><?php esc_html_e('Resume', NIMBUS_TEXT_DOMAIN); ?></button>
            <button type="button" class="button" data-job-action="retry-failed"><?php esc_html_e('Retry failed items', NIMBUS_TEXT_DOMAIN); ?></button>
          </div>
          <p class="nmb-muted"><?php esc_html_e('Pause/resume works on the current job. Retry failed items uses the failed subset from the most recent completed job.', NIMBUS_TEXT_DOMAIN); ?></p>
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
          <h3><?php esc_html_e('Activation recovery', NIMBUS_TEXT_DOMAIN); ?></h3>
          <p class="nmb-muted"><?php esc_html_e('An earlier activation/upgrade migration did not finish. Resume it here in small batches instead of forcing the entire site through one heavy request.', NIMBUS_TEXT_DOMAIN); ?></p>
        </div>
        <span class="nmb-inline-badge is-warning"><?php esc_html_e('Recovery needed', NIMBUS_TEXT_DOMAIN); ?></span>
      </div>

      <ul class="nmb-status-list">
        <li>
          <span class="dashicons <?php echo !empty($migration['scheduled']) ? 'dashicons-clock status-warning' : 'dashicons-update status-warning'; ?>"></span>
          <strong><?php esc_html_e('Background follow-up', NIMBUS_TEXT_DOMAIN); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo !empty($migration['scheduled']) ? esc_html__('Scheduled', NIMBUS_TEXT_DOMAIN) : esc_html__('Not scheduled yet', NIMBUS_TEXT_DOMAIN); ?>
        </li>
        <li>
          <span class="dashicons dashicons-database"></span>
          <strong><?php esc_html_e('Legacy metadata keys', NIMBUS_TEXT_DOMAIN); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo number_format(count($legacyMetaKeys)); ?>
        </li>
        <li>
          <span class="dashicons dashicons-admin-settings"></span>
          <strong><?php esc_html_e('Legacy options', NIMBUS_TEXT_DOMAIN); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo number_format(count($legacyOptions)); ?>
        </li>
        <li>
          <span class="dashicons dashicons-format-image"></span>
          <strong><?php esc_html_e('Attachment metadata payloads', NIMBUS_TEXT_DOMAIN); ?></strong>
          <span class="nmb-spacer"></span>
          <?php echo $hasAttachmentPayloads ? esc_html__('Still pending', NIMBUS_TEXT_DOMAIN) : esc_html__('Clear', NIMBUS_TEXT_DOMAIN); ?>
        </li>
      </ul>

      <div class="nmb-actions nmb-actions-left">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RESUME_MIGRATION); ?>
          <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RESUME_MIGRATION); ?>" />
          <button type="submit" class="button button-primary"><?php esc_html_e('Resume activation recovery', NIMBUS_TEXT_DOMAIN); ?></button>
        </form>
      </div>
    </section>
    <?php
  }

  private function renderAttachmentInspector(): void
  {
    $attachmentId = isset($_GET['attachment_id']) ? max(0, (int) $_GET['attachment_id']) : 0;
    if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
      return;
    }

    $status = $this->uploadHandler->get_attachment_status($attachmentId);
    ?>
    <section class="nmb-card nmb-attachment-inspector">
      <h3><?php esc_html_e('Attachment status', NIMBUS_TEXT_DOMAIN); ?></h3>
      <ul class="nmb-status-list">
        <li><strong><?php esc_html_e('Attachment ID', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><?php echo number_format($attachmentId); ?></li>
        <li><strong><?php esc_html_e('Status', NIMBUS_TEXT_DOMAIN); ?></strong><span class="nmb-spacer"></span><span class="nmb-inline-badge <?php echo esc_attr($this->jobBadgeClass((string) ($status['status'] ?? 'local'))); ?>"><?php echo esc_html((string) ($status['label'] ?? __('Unknown', NIMBUS_TEXT_DOMAIN))); ?></span></li>
      </ul>
      <?php if (!empty($status['reason'])) : ?>
        <p class="nmb-muted"><?php echo esc_html((string) $status['reason']); ?></p>
      <?php endif; ?>
    </section>
    <?php
  }

  private function renderIntegrationsTab(): void
  {
    $summary = $this->integrationDiscovery->summary();
    $items = $summary['items'] ?? [];
    ?>
    <div class="nmb-settings-shell">
      <div class="nmb-card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
          <div>
            <h3 style="margin:0 0 4px;"><?php esc_html_e('Plugin integrations', NIMBUS_TEXT_DOMAIN); ?></h3>
            <p class="nmb-muted" style="margin:0;"><?php esc_html_e('Nimbus detects active plugins that store assets outside the standard wp-content/uploads path. Enable or disable each integration below.', NIMBUS_TEXT_DOMAIN); ?></p>
          </div>
          <span class="nmb-inline-badge">
            <?php echo number_format((int) ($summary['detected'] ?? 0)); ?> <?php esc_html_e('detected', NIMBUS_TEXT_DOMAIN); ?>
          </span>
        </div>
      </div>

      <div class="nmb-card" style="padding:0;overflow:hidden;">
        <table class="nmb-integrations-table widefat" style="border:none;">
          <thead>
            <tr>
              <th><?php esc_html_e('Plugin', NIMBUS_TEXT_DOMAIN); ?></th>
              <th><?php esc_html_e('State', NIMBUS_TEXT_DOMAIN); ?></th>
              <th><?php esc_html_e('Asset paths', NIMBUS_TEXT_DOMAIN); ?></th>
              <th><?php esc_html_e('Coverage', NIMBUS_TEXT_DOMAIN); ?></th>
              <th><?php esc_html_e('Actions', NIMBUS_TEXT_DOMAIN); ?></th>
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
                $stateLabel = __('Active', NIMBUS_TEXT_DOMAIN);
              } elseif (!$isEnabled) {
                $stateClass = 'is-disabled';
                $stateLabel = __('Disabled', NIMBUS_TEXT_DOMAIN);
              } elseif ($isInstalled) {
                $stateClass = 'is-installed';
                $stateLabel = __('Installed', NIMBUS_TEXT_DOMAIN);
              } else {
                $stateClass = 'is-not-installed';
                $stateLabel = __('Not installed', NIMBUS_TEXT_DOMAIN);
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
                    <span class="nmb-int-state-badge is-active"><?php esc_html_e('Rewrite supported', NIMBUS_TEXT_DOMAIN); ?></span>
                  <?php else : ?>
                    <span class="nmb-muted"><?php esc_html_e('Detection only', NIMBUS_TEXT_DOMAIN); ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                      <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION); ?>
                      <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_TOGGLE_INTEGRATION); ?>" />
                      <input type="hidden" name="integration" value="<?php echo esc_attr($itemKey); ?>" />
                      <button type="submit" class="button button-small">
                        <?php echo $isEnabled ? esc_html__('Disable', NIMBUS_TEXT_DOMAIN) : esc_html__('Enable', NIMBUS_TEXT_DOMAIN); ?>
                      </button>
                    </form>
                    <?php if (!empty($item['rewrite_supported']) && !empty($item['rewrite_action_label'])) : ?>
                      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_REWRITE_INTEGRATION); ?>" />
                        <input type="hidden" name="integration" value="<?php echo esc_attr($itemKey); ?>" />
                        <button type="submit" class="button button-small button-primary" <?php disabled(empty($item['rewrite_ready'])); ?>>
                          <?php echo esc_html((string) ($item['rewrite_action_label'] ?? __('Run rewrite', NIMBUS_TEXT_DOMAIN))); ?>
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
                  <?php esc_html_e('No integrations detected. Install a supported plugin to see it listed here.', NIMBUS_TEXT_DOMAIN); ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
  }

  private function renderLicenseTab(): void
  {
    $license = $this->licenseManager->all();
    $hasKey  = $this->licenseManager->hasKey();
    $status  = (string) ($license['status'] ?? 'inactive');
    $isActive = $status === 'active';
    ?>
    <div class="nmb-settings-shell">
      <div class="nmb-card" id="license-key-section">
        <h3><?php esc_html_e('License key', NIMBUS_TEXT_DOMAIN); ?></h3>
        <?php if ($hasKey) : ?>
          <dl style="margin:0 0 16px;">
            <div class="nmb-license-status-row">
              <dt><?php esc_html_e('Status', NIMBUS_TEXT_DOMAIN); ?></dt>
              <dd class="<?php echo $isActive ? 'mo-license-status-active' : 'mo-license-status-inactive'; ?>">
                <?php echo $isActive ? esc_html__('Active', NIMBUS_TEXT_DOMAIN) : esc_html(ucfirst($status)); ?>
              </dd>
            </div>
            <div class="nmb-license-status-row">
              <dt><?php esc_html_e('Plan', NIMBUS_TEXT_DOMAIN); ?></dt>
              <dd><?php echo esc_html(ucfirst((string) ($license['plan'] ?? 'monthly'))); ?></dd>
            </div>
            <?php if (!empty($license['expires'])) : ?>
              <div class="nmb-license-status-row">
                <dt><?php esc_html_e('Expires', NIMBUS_TEXT_DOMAIN); ?></dt>
                <dd><?php echo esc_html((string) $license['expires']); ?></dd>
              </div>
            <?php endif; ?>
          </dl>
          <div class="nmb-actions nmb-actions-left">
            <a class="button button-secondary" href="https://plugintree.com/account/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Manage subscription', NIMBUS_TEXT_DOMAIN); ?></a>
          </div>
        <?php else : ?>
          <p class="nmb-muted"><?php esc_html_e('Enter your license key to activate Nimbus. You can find your key in your account dashboard after purchase.', NIMBUS_TEXT_DOMAIN); ?></p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
            <?php wp_nonce_field('nimbus_save_license'); ?>
            <input type="hidden" name="action" value="nimbus_save_license" />
            <div class="nmb-license-key-field">
              <label for="mo-license-key"><?php esc_html_e('License key', NIMBUS_TEXT_DOMAIN); ?></label>
              <input type="text" id="mo-license-key" name="license_key" class="regular-text" placeholder="XXXX-XXXX-XXXX-XXXX" autocomplete="off" />
            </div>
            <div class="nmb-actions nmb-actions-left" style="margin-top:12px;">
              <button type="submit" class="button button-primary"><?php esc_html_e('Activate license', NIMBUS_TEXT_DOMAIN); ?></button>
              <a href="https://plugintree.com/nimbus/" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e('Purchase a license', NIMBUS_TEXT_DOMAIN); ?></a>
            </div>
          </form>
        <?php endif; ?>
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

    $storageSize = isset($remoteStats['size_bytes']) ? $remoteStats['size_bytes'] : $usage['offloaded_size'];
    $storageCost = isset($remoteStats['estimated_cost']) ? $remoteStats['estimated_cost'] : ($storageSize / 1073741824 * 0.023);
    $storageCount = isset($remoteStats['object_count']) ? $remoteStats['object_count'] : $stats['offloaded'];
    ?>
    <div class="nmb-dashboard">
      <!-- Unified stats bar — all 4 cells are identical height -->
      <div class="nmb-stats-bar">
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Library assets', NIMBUS_TEXT_DOMAIN); ?></span>
          <span class="nmb-stat-cell__value"><?php echo number_format($stats['total']); ?></span>
          <span class="nmb-stat-cell__sub"><?php esc_html_e('Attachments in WordPress', NIMBUS_TEXT_DOMAIN); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Offloaded items', NIMBUS_TEXT_DOMAIN); ?></span>
          <span class="nmb-stat-cell__value"><?php echo number_format($stats['offloaded']); ?></span>
          <div class="nmb-stat-cell__bar"><div class="nmb-stat-cell__bar-fill" style="width:<?php echo (int) $stats['percent']; ?>%"></div></div>
          <span class="nmb-stat-cell__sub"><?php echo esc_html(sprintf(__('%d%% linked to remote storage', NIMBUS_TEXT_DOMAIN), (int) $stats['percent'])); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Remote storage', NIMBUS_TEXT_DOMAIN); ?></span>
          <span class="nmb-stat-cell__value"><?php echo size_format($storageSize); ?></span>
          <span class="nmb-stat-cell__sub"><?php echo esc_html(sprintf(__('%d remote objects tracked', NIMBUS_TEXT_DOMAIN), (int) $storageCount)); ?></span>
        </div>
        <div class="nmb-stat-cell">
          <span class="nmb-stat-cell__label"><?php esc_html_e('Est. storage cost', NIMBUS_TEXT_DOMAIN); ?></span>
          <span class="nmb-stat-cell__value">$<?php echo number_format($storageCost, 2); ?></span>
          <span class="nmb-stat-cell__sub"><?php esc_html_e('Rough monthly estimate', NIMBUS_TEXT_DOMAIN); ?></span>
        </div>
      </div>

      <div class="nmb-grid-2">
        <div class="nmb-card">
          <h3><?php esc_html_e('Uploads — last 7 days', NIMBUS_TEXT_DOMAIN); ?></h3>
          <div class="nmb-chart">
            <?php if (empty($stats['daily'])) : ?>
              <p class="nmb-muted" style="align-self:center;margin:auto;"><?php esc_html_e('No uploads in the last 7 days.', NIMBUS_TEXT_DOMAIN); ?></p>
            <?php else : ?>
              <?php
              $max = max(array_column($stats['daily'], 'count'));
              foreach ($stats['daily'] as $day) :
                $height = $max > 0 ? ($day->count / $max) * 100 : 0;
              ?>
                <div class="nmb-bar-group" title="<?php echo esc_attr($day->date . ': ' . $day->count); ?>">
                  <div class="nmb-chart-bar" style="height:<?php echo (int) $height; ?>%"></div>
                  <div class="nmb-chart-label"><?php echo esc_html(date('M d', strtotime($day->date))); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="nmb-card">
          <h3><?php esc_html_e('System status', NIMBUS_TEXT_DOMAIN); ?></h3>
          <ul class="nmb-status-list">
            <li>
              <span class="dashicons dashicons-yes-alt status-ok"></span>
              <strong><?php esc_html_e('Plugin enabled:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <span class="nmb-spacer"></span>
              <?php echo $this->settings->is_enabled() ? esc_html__('Yes', NIMBUS_TEXT_DOMAIN) : esc_html__('No', NIMBUS_TEXT_DOMAIN); ?>
            </li>
            <li>
              <span class="dashicons dashicons-cloud status-ok"></span>
              <strong><?php esc_html_e('Provider:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <span class="nmb-spacer"></span>
              <?php echo esc_html(strtoupper($this->settings->provider())); ?>
            </li>
            <li>
              <span class="dashicons dashicons-database status-ok"></span>
              <strong><?php esc_html_e('Bucket:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <span class="nmb-spacer"></span>
              <?php echo esc_html($this->settings->get('bucket') ?: '—'); ?>
            </li>
            <li>
              <span class="dashicons dashicons-admin-links status-ok"></span>
              <strong><?php esc_html_e('Rewrite URLs:', NIMBUS_TEXT_DOMAIN); ?></strong>
              <span class="nmb-spacer"></span>
              <?php echo $this->settings->should_serve_from_storage() ? esc_html__('Yes', NIMBUS_TEXT_DOMAIN) : esc_html__('No', NIMBUS_TEXT_DOMAIN); ?>
            </li>
          </ul>
        </div>
      </div>

      <div class="nmb-grid-2">
        <div class="nmb-card">
          <h3><?php esc_html_e('Media type breakdown', NIMBUS_TEXT_DOMAIN); ?></h3>
          <?php if (empty($mimeBreakdown)) : ?>
            <p><?php esc_html_e('No media type breakdown available yet.', NIMBUS_TEXT_DOMAIN); ?></p>
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
          <h3><?php esc_html_e('Recent monthly trend', NIMBUS_TEXT_DOMAIN); ?></h3>
          <?php if (empty($monthlyBreakdown)) : ?>
            <p><?php esc_html_e('No monthly attachment trend available yet.', NIMBUS_TEXT_DOMAIN); ?></p>
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
    </div>
    <?php
  }

  private function renderLogsTab(): void
  {
    $settings       = $this->settings->all();
    $stats          = $this->logger->stats();
    $selectedLevel  = isset($_GET['log_level'])  ? sanitize_key((string) wp_unslash($_GET['log_level']))          : '';
    $selectedSource = isset($_GET['log_source']) ? sanitize_key((string) wp_unslash($_GET['log_source']))         : '';
    $search         = isset($_GET['log_search']) ? sanitize_text_field((string) wp_unslash($_GET['log_search']))  : '';
    $limit          = isset($_GET['log_limit'])  ? max(25, min(1000, (int) $_GET['log_limit']))                   : 200;
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
            <span class="nmb-log-chip-label"><?php esc_html_e('entries', NIMBUS_TEXT_DOMAIN); ?></span>
          </span>
          <?php if ($warningCount > 0) : ?>
          <span class="nmb-log-chip is-warn">
            <strong class="nmb-log-chip-value"><?php echo esc_html(number_format($warningCount)); ?></strong>
            <span class="nmb-log-chip-label"><?php esc_html_e('warnings', NIMBUS_TEXT_DOMAIN); ?></span>
          </span>
          <?php endif; ?>
          <?php if ($errorCount > 0) : ?>
          <span class="nmb-log-chip is-error">
            <strong class="nmb-log-chip-value"><?php echo esc_html(number_format($errorCount)); ?></strong>
            <span class="nmb-log-chip-label"><?php esc_html_e('errors', NIMBUS_TEXT_DOMAIN); ?></span>
          </span>
          <?php endif; ?>
          <span class="nmb-log-chip <?php echo $loggerOn ? 'is-ok' : 'is-muted'; ?>">
            <?php echo $loggerOn ? esc_html__('Logging on', NIMBUS_TEXT_DOMAIN) : esc_html__('Logging off', NIMBUS_TEXT_DOMAIN); ?>
            <span class="nmb-log-chip-label">· <?php echo esc_html($minLevel); ?></span>
          </span>
        </div>
        <div class="nmb-log-header-actions">
          <button type="button"
                  class="button button-secondary nmb-log-settings-toggle"
                  aria-expanded="false"
                  aria-controls="nmb-log-settings-panel">
            <?php esc_html_e('Logging settings', NIMBUS_TEXT_DOMAIN); ?>
          </button>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_CLEAR_LOGS); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_CLEAR_LOGS); ?>" />
            <button type="submit"
                    class="button button-link nmb-log-clear-btn"
                    onclick="return confirm('<?php echo esc_js(__('Delete all plugin log entries? This cannot be undone.', NIMBUS_TEXT_DOMAIN)); ?>');">
              <?php esc_html_e('Clear logs', NIMBUS_TEXT_DOMAIN); ?>
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
            <?php submit_button(__('Save Logging Settings', NIMBUS_TEXT_DOMAIN)); ?>
          </div>
        </form>
      </div>

      <!-- ─── Filter toolbar ───────────────────────────────────── -->
      <section class="nmb-card nmb-log-filter-card">
        <form method="get" class="nmb-log-filter-bar">
          <input type="hidden" name="page" value="<?php echo esc_attr(NIMBUS_SLUG); ?>" />
          <input type="hidden" name="tab" value="logs" />
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Level', NIMBUS_TEXT_DOMAIN); ?></span>
            <select name="log_level">
              <option value=""><?php esc_html_e('All levels', NIMBUS_TEXT_DOMAIN); ?></option>
              <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) : ?>
                <option value="<?php echo esc_attr($level); ?>" <?php selected($selectedLevel, $level); ?>><?php echo esc_html(strtoupper($level)); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Source', NIMBUS_TEXT_DOMAIN); ?></span>
            <select name="log_source">
              <option value=""><?php esc_html_e('All sources', NIMBUS_TEXT_DOMAIN); ?></option>
              <?php foreach ($sources as $src) : ?>
                <option value="<?php echo esc_attr((string) $src); ?>" <?php selected($selectedSource, (string) $src); ?>><?php echo esc_html((string) $src); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="nmb-log-filter-item nmb-log-filter-item--search">
            <span><?php esc_html_e('Search', NIMBUS_TEXT_DOMAIN); ?></span>
            <input type="search" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('message, event, code…', NIMBUS_TEXT_DOMAIN); ?>" />
          </label>
          <label class="nmb-log-filter-item">
            <span><?php esc_html_e('Rows', NIMBUS_TEXT_DOMAIN); ?></span>
            <select name="log_limit">
              <?php foreach ([50, 100, 200, 500, 1000] as $option) : ?>
                <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($limit, $option); ?>><?php echo esc_html((string) $option); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="nmb-log-filter-item nmb-log-filter-btns">
            <span aria-hidden="true"></span>
            <div>
              <button type="submit" class="button button-primary"><?php esc_html_e('Apply', NIMBUS_TEXT_DOMAIN); ?></button>
              <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('logs')); ?>"><?php esc_html_e('Reset', NIMBUS_TEXT_DOMAIN); ?></a>
            </div>
          </div>
        </form>
      </section>

      <!-- ─── Log table ────────────────────────────────────────── -->
      <section class="nmb-card nmb-log-viewer-card">
        <?php if (empty($entries)) : ?>
          <p class="nmb-muted"><?php esc_html_e('No log entries match the current filters.', NIMBUS_TEXT_DOMAIN); ?></p>
        <?php else : ?>
          <div class="nmb-log-table-wrap">
            <table class="widefat striped nmb-log-table">
              <thead>
                <tr>
                  <th style="width:155px;"><?php esc_html_e('Time', NIMBUS_TEXT_DOMAIN); ?></th>
                  <th style="width:90px;"><?php esc_html_e('Level', NIMBUS_TEXT_DOMAIN); ?></th>
                  <th style="width:120px;"><?php esc_html_e('Source', NIMBUS_TEXT_DOMAIN); ?></th>
                  <th style="width:160px;"><?php esc_html_e('Event / code', NIMBUS_TEXT_DOMAIN); ?></th>
                  <th><?php esc_html_e('Message', NIMBUS_TEXT_DOMAIN); ?></th>
                  <th style="width:200px;"><?php esc_html_e('Context', NIMBUS_TEXT_DOMAIN); ?></th>
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
                          <summary><?php esc_html_e('View context', NIMBUS_TEXT_DOMAIN); ?></summary>
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
      <?php if (isset($_GET['ptmo_url_update']) && (string) $_GET['ptmo_url_update'] === 'error') : ?>
        <?php
        $reason = isset($_GET['ptmo_url_update_reason']) ? sanitize_key((string) wp_unslash($_GET['ptmo_url_update_reason'])) : '';
        $message = __('URL update could not be started.', NIMBUS_TEXT_DOMAIN);

        if ($reason === 'disabled') {
          $message = __('Media offload is currently disabled. Enable it first in Settings.', NIMBUS_TEXT_DOMAIN);
        } elseif ($reason === 'credentials') {
          $message = __('Missing or invalid storage credentials. Please verify your settings first.', NIMBUS_TEXT_DOMAIN);
        } elseif ($reason === 'connection') {
          $message = __('Storage connection test failed (authentication/signing). Verify provider, endpoint, region, and keys before retrying.', NIMBUS_TEXT_DOMAIN);
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

        $modeLabel = $mode === 'full' ? __('full run', NIMBUS_TEXT_DOMAIN) : __('single update', NIMBUS_TEXT_DOMAIN);
        $noticeClass = 'notice notice-' . ($status === 'warning' ? 'warning' : ($status === 'info' ? 'info' : 'success'));
        ?>
        <div class="<?php echo esc_attr($noticeClass); ?>">
          <p>
            <?php
            echo esc_html(sprintf(
              __('URL update %1$s finished. Processed: %2$d, Success: %3$d, Failed: %4$d, Remaining pending: %5$d.', NIMBUS_TEXT_DOMAIN),
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
          'label' => __('Pending URL updates', NIMBUS_TEXT_DOMAIN),
          'value' => number_format($pending),
          'substat' => __('Attachments waiting for URL/offload metadata update', NIMBUS_TEXT_DOMAIN),
        ],
        [
          'label' => __('Offloaded attachments', NIMBUS_TEXT_DOMAIN),
          'value' => number_format((int) ($stats['offloaded'] ?? 0)),
          'substat' => __('Already linked with offload metadata', NIMBUS_TEXT_DOMAIN),
        ],
        [
          'label' => __('Plugin status', NIMBUS_TEXT_DOMAIN),
          'value' => $isEnabled ? __('Enabled', NIMBUS_TEXT_DOMAIN) : __('Disabled', NIMBUS_TEXT_DOMAIN),
          'substat' => $hasCredentials ? __('Credentials configured', NIMBUS_TEXT_DOMAIN) : __('Credentials missing', NIMBUS_TEXT_DOMAIN),
        ],
        [
          'label' => __('Total attachments', NIMBUS_TEXT_DOMAIN),
          'value' => number_format((int) ($stats['total'] ?? 0)),
          'substat' => __('In media library', NIMBUS_TEXT_DOMAIN),
        ],
      ], 'nmb-url-update-summary-strip'); ?>

      <div class="nmb-card">
        <h3><?php esc_html_e('Run URL Updates', NIMBUS_TEXT_DOMAIN); ?></h3>
        <p><?php esc_html_e('Use one-by-one for safer/manual progression, or full run to process all pending attachments in one go from this admin panel.', NIMBUS_TEXT_DOMAIN); ?></p>
        <p><em><?php esc_html_e('Tip: attachments marked “Ignore from Nimbus” and files outside your allowed path filters are skipped automatically here.', NIMBUS_TEXT_DOMAIN); ?></em></p>

        <?php if (!$isEnabled) : ?>
          <p><strong><?php esc_html_e('Media offload is disabled.', NIMBUS_TEXT_DOMAIN); ?></strong></p>
        <?php endif; ?>

        <?php if (!$hasCredentials) : ?>
          <p><strong><?php esc_html_e('Valid storage credentials are required before URL updates can run.', NIMBUS_TEXT_DOMAIN); ?></strong></p>
        <?php endif; ?>

        <div class="nmb-inline-actions">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>" />
            <input type="hidden" name="mode" value="single" />
            <button type="submit" class="button button-secondary" <?php disabled(!$isEnabled || !$hasCredentials || $pending <= 0); ?>>
              <?php esc_html_e('Update One Entry', NIMBUS_TEXT_DOMAIN); ?>
            </button>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Run full URL update for all pending entries now?', NIMBUS_TEXT_DOMAIN)); ?>');">
            <?php wp_nonce_field(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(NIMBUS_ADMIN_ACTION_RUN_URL_UPDATES); ?>" />
            <input type="hidden" name="mode" value="full" />
            <button type="submit" class="button button-primary" <?php disabled(!$isEnabled || !$hasCredentials || $pending <= 0); ?>>
              <?php esc_html_e('Update All Pending Entries', NIMBUS_TEXT_DOMAIN); ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php
  }

  private function renderLicenseOverlay(): void
  {
    $activateUrl = esc_url($this->adminTabUrl('license'));
    ?>
    <div class="nmb-license-overlay" id="mo-license-overlay" role="dialog" aria-modal="true" aria-labelledby="mo-overlay-heading">
      <div class="nmb-license-overlay__box">
        <div class="nmb-license-overlay__icon">
          <span class="dashicons dashicons-lock" aria-hidden="true"></span>
        </div>
        <h2 id="mo-overlay-heading"><?php esc_html_e('License required', NIMBUS_TEXT_DOMAIN); ?></h2>
        <p><?php esc_html_e('Nimbus requires an active license key. Activate your license to access all features, including media offloading, URL rewriting, and background jobs.', NIMBUS_TEXT_DOMAIN); ?></p>
        <div class="nmb-license-overlay__actions">
          <a href="<?php echo $activateUrl; ?>" class="button button-primary"><?php esc_html_e('Activate license', NIMBUS_TEXT_DOMAIN); ?></a>
          <a href="https://plugintree.com/nimbus/" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e('Purchase', NIMBUS_TEXT_DOMAIN); ?></a>
          <button type="button" class="button" onclick="document.getElementById('mo-license-overlay').style.display='none'"><?php esc_html_e('Dismiss', NIMBUS_TEXT_DOMAIN); ?></button>
        </div>
      </div>
    </div>
    <?php
  }

  private function renderAdminShellHeader(string $activeTab): void
  {
    $settings = $this->settings->all();
    $providerKey = (string) ($settings['provider'] ?? 's3');
    $providerLabel = $this->settings->provider_profile($providerKey)->label();
    $deliveryLabel = $providerKey === 'local'
      ? __('Local debug mode', NIMBUS_TEXT_DOMAIN)
      : (!empty($settings['serve_from_storage']) ? __('Serving from storage', NIMBUS_TEXT_DOMAIN) : __('Serving from local URLs', NIMBUS_TEXT_DOMAIN));
    $loggingLabel = !empty($settings['logging_enabled']) ? __('Logging enabled', NIMBUS_TEXT_DOMAIN) : __('Logging disabled', NIMBUS_TEXT_DOMAIN);

    $headerContent = match ($activeTab) {
      'settings' => [
        'kicker' => __('Settings', NIMBUS_TEXT_DOMAIN),
        'description' => __('Designated for provider setup, delivery behavior, caching rules, and automation—without burying the important controls under a wall of unrelated tools.', NIMBUS_TEXT_DOMAIN),
      ],
      'tools' => [
        'kicker' => __('Tools', NIMBUS_TEXT_DOMAIN),
        'description' => __('The operational workbench for jobs, migration recovery, URL repair, integrations, and logging—built for confidence instead of guesswork.', NIMBUS_TEXT_DOMAIN),
      ],
      default => [
        'kicker' => __('Overview', NIMBUS_TEXT_DOMAIN),
        'description' => __('A clean command center for checking readiness, volume, pipeline health, and the next actions worth taking on your media stack.', NIMBUS_TEXT_DOMAIN),
      ],
    };
    ?>
    <section class="nmb-shell-header">
      <div class="nmb-shell-header__content">
        <p class="nmb-shell-kicker"><?php echo esc_html($headerContent['kicker']); ?></p>
        <h1><?php esc_html_e('Nimbus', NIMBUS_TEXT_DOMAIN); ?></h1>
        <p class="nmb-shell-lede"><?php echo esc_html($headerContent['description']); ?></p>
      </div>

      <div class="nmb-shell-header__meta">
        <div class="nmb-shell-meta-card">
          <span class="nmb-shell-meta-card__label"><?php esc_html_e('Active view', NIMBUS_TEXT_DOMAIN); ?></span>
          <strong><?php echo esc_html(ucfirst($activeTab)); ?></strong>
        </div>
        <div class="nmb-shell-meta-card">
          <span class="nmb-shell-meta-card__label"><?php esc_html_e('Provider', NIMBUS_TEXT_DOMAIN); ?></span>
          <strong id="nmb-shell-meta-provider"><?php echo esc_html($providerLabel); ?></strong>
        </div>
        <div class="nmb-shell-meta-card">
          <span class="nmb-shell-meta-card__label"><?php esc_html_e('Delivery', NIMBUS_TEXT_DOMAIN); ?></span>
          <strong id="nmb-shell-meta-delivery"><?php echo esc_html($deliveryLabel); ?></strong>
        </div>
        <div class="nmb-shell-meta-card">
          <span class="nmb-shell-meta-card__label"><?php esc_html_e('Diagnostics', NIMBUS_TEXT_DOMAIN); ?></span>
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
}
