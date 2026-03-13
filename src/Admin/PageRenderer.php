<?php
namespace ArunPrakash\MediaOffload\Admin;

use ArunPrakash\MediaOffload\Bootstrap\DataMigration;
use ArunPrakash\MediaOffload\Jobs\BulkJobManager;
use ArunPrakash\MediaOffload\Integration\IntegrationDiscovery;
use ArunPrakash\MediaOffload\License\LicenseManager;
use ArunPrakash\MediaOffload\Onboarding\OnboardingManager;
use ArunPrakash\MediaOffload\Config\Settings;
use ArunPrakash\MediaOffload\Media\UploadHandler;
use ArunPrakash\MediaOffload\Stats\Statistics;
use ArunPrakash\MediaOffload\Support\Logger;

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
    'integrations' => 'tools',
    'license' => 'overview',
    'logs' => 'tools',
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

    if (isset($_GET['media_offload_logs_cleared']) && $_GET['media_offload_logs_cleared'] === '1') {
      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Plugin logs cleared.', MEDIA_OFFLOAD_TEXT_DOMAIN) . '</p></div>';
    }

    settings_errors(MEDIA_OFFLOAD_SETTINGS_GROUP);

    $onboarding = $this->onboardingManager->state();
    if (empty($onboarding['completed'])) {
      ?>
      <div class="wrap media-offload-settings media-offload-settings--onboarding">
        <h1><?php esc_html_e('Set up Media Offload', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h1>
        <?php $this->renderOnboardingTab(); ?>
      </div>
      <?php
      return;
    }

    $activeTab = $this->resolveActiveTab();
    ?>
    <div class="wrap media-offload-settings">
      <h1><?php esc_html_e('Media Offload', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h1>

      <h2 class="nav-tab-wrapper">
        <a href="?page=<?php echo esc_attr(MEDIA_OFFLOAD_SLUG); ?>&tab=overview" class="nav-tab <?php echo $activeTab === 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
        <a href="?page=<?php echo esc_attr(MEDIA_OFFLOAD_SLUG); ?>&tab=settings" class="nav-tab <?php echo $activeTab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
        <a href="?page=<?php echo esc_attr(MEDIA_OFFLOAD_SLUG); ?>&tab=tools" class="nav-tab <?php echo $activeTab === 'tools' ? 'nav-tab-active' : ''; ?>">Tools</a>
      </h2>

      <?php if ($activeTab === 'overview') : ?>
        <?php $this->renderOverviewTab(); ?>
      <?php elseif ($activeTab === 'settings') : ?>
        <?php $this->renderSettingsTab(); ?>
      <?php elseif ($activeTab === 'tools') : ?>
        <?php $this->renderToolsTab(); ?>
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

    if (!in_array($tab, ['overview', 'settings', 'tools'], true)) {
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
    <div id="pt-mo-onboarding-wizard" class="pt-mo-onboarding-shell" data-current-step="<?php echo esc_attr((string) $currentStepIndex); ?>">
      <aside class="pt-mo-onboarding-sidebar">
        <div class="pt-mo-card pt-mo-onboarding-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Guided setup', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Finish setup to unlock the dashboard', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('Complete each step in order. Overview, Settings, and Tools stay locked until this flow is finished.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>

        <ol class="pt-mo-onboarding-step-list">
          <li class="pt-mo-onboarding-step-item" data-step-item="account">
            <span class="pt-mo-onboarding-step-index">1</span>
            <div>
              <strong><?php esc_html_e('Account', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span><?php esc_html_e('Add your license key.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
            </div>
          </li>
          <li class="pt-mo-onboarding-step-item" data-step-item="storage">
            <span class="pt-mo-onboarding-step-index">2</span>
            <div>
              <strong><?php esc_html_e('Storage', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span><?php esc_html_e('Connect the provider and bucket.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
            </div>
          </li>
          <li class="pt-mo-onboarding-step-item" data-step-item="delivery">
            <span class="pt-mo-onboarding-step-index">3</span>
            <div>
              <strong><?php esc_html_e('Delivery', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span><?php esc_html_e('Choose how media should be served.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
            </div>
          </li>
          <li class="pt-mo-onboarding-step-item" data-step-item="review">
            <span class="pt-mo-onboarding-step-index">4</span>
            <div>
              <strong><?php esc_html_e('Review', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span><?php esc_html_e('Confirm and unlock the dashboard.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
            </div>
          </li>
        </ol>

        <div class="pt-mo-card pt-mo-onboarding-status-card">
          <h3><?php esc_html_e('Current checklist', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <ul class="pt-mo-bullet-list">
            <li><?php echo !empty($state['license_ready']) ? '✅ ' : '⬜ '; ?><?php esc_html_e('License key recorded', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
            <li><?php echo !empty($state['storage_ready']) ? '✅ ' : '⬜ '; ?><?php esc_html_e('Storage credentials configured', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
            <li><?php echo empty($state['has_active_conflicts']) ? '✅ ' : '⚠️ '; ?><?php esc_html_e('Conflicts reviewed', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
          </ul>
        </div>
      </aside>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pt-mo-onboarding-form pt-mo-onboarding-main">
        <?php wp_nonce_field(MEDIA_OFFLOAD_ADMIN_ACTION_COMPLETE_ONBOARDING); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(MEDIA_OFFLOAD_ADMIN_ACTION_COMPLETE_ONBOARDING); ?>" />

        <section class="pt-mo-card pt-mo-onboarding-step" data-step="account">
          <div class="pt-mo-onboarding-step-header">
            <p class="pt-mo-eyebrow"><?php esc_html_e('Step 1', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
            <h2><?php esc_html_e('Connect your account', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Paste the Media Offload license key you received. This is required before the plugin dashboard becomes available.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          </div>

          <label class="pt-mo-onboarding-field pt-mo-onboarding-field--full">
            <span><?php esc_html_e('License key', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
            <input type="text" id="media-offload-license-key" name="license_key" value="<?php echo esc_attr((string) ($license['key'] ?? '')); ?>" placeholder="mo_live_xxxxxxxxx" autocomplete="off" />
          </label>

          <div class="pt-mo-card pt-mo-onboarding-helper-card">
            <h3><?php esc_html_e('How to do this', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
            <ul class="pt-mo-bullet-list">
              <li><?php esc_html_e('Copy the key exactly as shown in your account or email.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('Avoid spaces before or after the value.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('If you do not have a key yet, pause here and get one before continuing.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
            </ul>
          </div>

          <div class="pt-mo-onboarding-actions">
            <span></span>
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to storage', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
          </div>
        </section>

        <section class="pt-mo-card pt-mo-onboarding-step" data-step="storage" hidden>
          <div class="pt-mo-onboarding-step-header">
            <p class="pt-mo-eyebrow"><?php esc_html_e('Step 2', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
            <h2><?php esc_html_e('Connect your storage provider', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Select the provider where media should live, then enter the bucket and credentials needed for offloading.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          </div>

          <div class="pt-mo-onboarding-field-grid pt-mo-onboarding-field-grid--two-up">
            <label class="pt-mo-onboarding-field">
              <span><?php esc_html_e('Provider', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <select id="media-offload-provider" name="provider">
                <?php foreach ($this->settings->available_provider_keys() as $providerKey) : ?>
                  <option value="<?php echo esc_attr($providerKey); ?>" <?php selected((string) ($settings['provider'] ?? 's3'), $providerKey); ?>><?php echo esc_html($this->settings->provider_profile($providerKey)->label()); ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="pt-mo-onboarding-field">
              <span><?php esc_html_e('Bucket', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <input type="text" id="media-offload-bucket" name="bucket" value="<?php echo esc_attr((string) ($settings['bucket'] ?? '')); ?>" placeholder="my-media-bucket" autocomplete="off" />
            </label>

            <label class="pt-mo-onboarding-field">
              <span><?php esc_html_e('Region', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <input type="text" id="media-offload-region" name="region" value="<?php echo esc_attr((string) ($settings['region'] ?? '')); ?>" placeholder="ap-south-1 / auto" autocomplete="off" />
            </label>

            <label class="pt-mo-onboarding-field">
              <span><?php esc_html_e('Path prefix', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <input type="text" id="media-offload-prefix" name="prefix" value="<?php echo esc_attr((string) ($settings['prefix'] ?? '')); ?>" placeholder="media/uploads" autocomplete="off" />
            </label>

            <label class="pt-mo-onboarding-field">
              <span><?php esc_html_e('Access key', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <input type="text" id="media-offload-access-key" name="access_key" value="<?php echo esc_attr((string) ($settings['access_key'] ?? '')); ?>" autocomplete="off" />
            </label>

            <label class="pt-mo-onboarding-field">
              <span><?php esc_html_e('Secret key', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <input type="password" id="media-offload-secret-key" name="secret_key" value="<?php echo esc_attr((string) ($settings['secret_key'] ?? '')); ?>" autocomplete="new-password" />
            </label>

            <label class="pt-mo-onboarding-field">
              <span><?php esc_html_e('R2 account ID', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <input type="text" id="media-offload-r2-account-id" name="r2_account_id" value="<?php echo esc_attr((string) ($settings['r2_account_id'] ?? '')); ?>" placeholder="For Cloudflare R2" autocomplete="off" />
            </label>

            <label class="pt-mo-onboarding-field">
              <span><?php esc_html_e('Custom endpoint', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <input type="url" id="media-offload-endpoint" name="endpoint" value="<?php echo esc_attr((string) ($settings['endpoint'] ?? '')); ?>" placeholder="https://account-id.r2.cloudflarestorage.com" autocomplete="off" />
            </label>

            <label class="pt-mo-onboarding-field pt-mo-onboarding-field--full">
              <span><?php esc_html_e('Session token (optional)', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
              <input type="password" id="media-offload-session-token" name="session_token" value="<?php echo esc_attr((string) ($settings['session_token'] ?? '')); ?>" autocomplete="new-password" />
            </label>
          </div>

          <div class="pt-mo-card pt-mo-onboarding-helper-card">
            <h3><?php esc_html_e('How to do this', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
            <ul class="pt-mo-bullet-list">
              <li><?php esc_html_e('Amazon S3 usually needs bucket, region, access key, and secret key.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('Cloudflare R2 usually needs bucket, access key, secret key, and either an account ID or custom endpoint. Use region “auto”.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('S3-compatible services normally need a custom endpoint in addition to standard credentials.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
            </ul>
          </div>

          <div class="pt-mo-onboarding-actions">
            <button type="button" class="button button-secondary" data-onboarding-back><?php esc_html_e('Back', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to delivery', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
          </div>
        </section>

        <section class="pt-mo-card pt-mo-onboarding-step" data-step="delivery" hidden>
          <div class="pt-mo-onboarding-step-header">
            <p class="pt-mo-eyebrow"><?php esc_html_e('Step 3', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
            <h2><?php esc_html_e('Choose delivery behavior', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Decide how Media Offload should behave once setup is complete. You can still tune these later from Settings.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          </div>

          <div class="pt-mo-onboarding-preference-grid">
            <label class="pt-mo-card pt-mo-onboarding-choice-card">
              <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?> />
              <div>
                <strong><?php esc_html_e('Enable Media Offload after setup', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
                <p><?php esc_html_e('Turn on the offload workflow once the wizard is finished.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
              </div>
            </label>

            <label class="pt-mo-card pt-mo-onboarding-choice-card">
              <input type="checkbox" name="serve_from_storage" value="1" <?php checked(!empty($settings['serve_from_storage'])); ?> />
              <div>
                <strong><?php esc_html_e('Serve media from remote storage', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
                <p><?php esc_html_e('Rewrite public media URLs so visitors load files from your bucket or CDN.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
              </div>
            </label>

            <label class="pt-mo-card pt-mo-onboarding-choice-card">
              <input type="checkbox" name="use_path_style_endpoint" value="1" <?php checked(!empty($settings['use_path_style_endpoint'])); ?> />
              <div>
                <strong><?php esc_html_e('Use path-style requests', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
                <p><?php esc_html_e('Recommended for Cloudflare R2 and some S3-compatible providers.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
              </div>
            </label>
          </div>

          <label class="pt-mo-onboarding-field pt-mo-onboarding-field--full">
            <span><?php esc_html_e('Custom delivery domain (optional)', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
            <input type="url" name="custom_domain" value="<?php echo esc_attr((string) ($settings['custom_domain'] ?? '')); ?>" placeholder="https://cdn.example.com" autocomplete="off" />
          </label>

          <div class="pt-mo-card pt-mo-onboarding-helper-card">
            <h3><?php esc_html_e('What happens next', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
            <ul class="pt-mo-bullet-list">
              <li><?php esc_html_e('After setup, you can validate the connection from Tools.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('You can delay public URL rewriting by leaving remote delivery off for now.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
              <li><?php esc_html_e('All of these preferences remain editable from Settings later.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
            </ul>
          </div>

          <div class="pt-mo-onboarding-actions">
            <button type="button" class="button button-secondary" data-onboarding-back><?php esc_html_e('Back', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <button type="button" class="button button-primary" data-onboarding-next><?php esc_html_e('Continue to review', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
          </div>
        </section>

        <section class="pt-mo-card pt-mo-onboarding-step" data-step="review" hidden>
          <div class="pt-mo-onboarding-step-header">
            <p class="pt-mo-eyebrow"><?php esc_html_e('Step 4', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
            <h2><?php esc_html_e('Review and finish setup', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
            <p><?php esc_html_e('Confirm the final checklist below. Once you finish this step, the plugin dashboard unlocks and the guided setup disappears.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          </div>

          <div class="pt-mo-onboarding-review-grid">
            <div class="pt-mo-card pt-mo-onboarding-helper-card">
              <h3><?php esc_html_e('Final checklist', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
              <ul class="pt-mo-status-list">
                <li><span class="dashicons dashicons-admin-network"></span><strong><?php esc_html_e('License key', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><?php echo !empty($license['key']) ? esc_html__('Previously saved', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Will be saved now', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
                <li><span class="dashicons dashicons-cloud"></span><strong><?php esc_html_e('Storage', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><?php esc_html_e('Connected in this setup flow', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
                <li><span class="dashicons dashicons-admin-home"></span><strong><?php esc_html_e('Dashboard', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><?php esc_html_e('Unlocks after finish', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
              </ul>
            </div>

            <div class="pt-mo-card pt-mo-onboarding-helper-card">
              <h3><?php esc_html_e('Conflict review', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
              <?php if (!empty($state['conflicts'])) : ?>
                <ul class="pt-mo-status-list">
                  <?php foreach ($state['conflicts'] as $plugin) : ?>
                    <li>
                      <span class="dashicons <?php echo !empty($plugin['active']) ? 'dashicons-warning status-error' : 'dashicons-info status-warning'; ?>"></span>
                      <div>
                        <strong><?php echo esc_html((string) ($plugin['name'] ?? '')); ?></strong>
                        <div class="pt-mo-muted"><?php echo esc_html((string) ($plugin['reason'] ?? '')); ?></div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else : ?>
                <p class="pt-mo-muted"><?php esc_html_e('No conflicting media offload plugins were detected. Nice and clean.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
              <?php endif; ?>

              <?php if (!empty($state['has_active_conflicts'])) : ?>
                <label class="pt-mo-onboarding-checkbox-note">
                  <input type="checkbox" name="conflict_acknowledged" value="1" />
                  <span><?php esc_html_e('I understand another media offload plugin is active and I will review the warnings after setup.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
                </label>
              <?php endif; ?>
            </div>
          </div>

          <div class="pt-mo-onboarding-actions">
            <button type="button" class="button button-secondary" data-onboarding-back><?php esc_html_e('Back', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <button type="submit" class="button button-primary"><?php esc_html_e('Finish setup and unlock dashboard', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
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
    $license = $this->licenseManager->all();
    ?>
    <div class="pt-mo-settings-shell">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Commercial admin foundation', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Storage, delivery, and tools now have dedicated surfaces', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('This new layout keeps provider setup, CDN delivery, and operational tools separate so each workflow is easier to understand and maintain.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <div class="pt-mo-chip-list">
            <span class="pt-mo-chip"><?php esc_html_e('Amazon S3', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
            <span class="pt-mo-chip"><?php esc_html_e('Cloudflare R2', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
            <span class="pt-mo-chip"><?php esc_html_e('S3-compatible', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
          </div>
        </div>

        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Quick actions', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Move fast without playing settings hide-and-seek', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <div class="pt-mo-code-list pt-mo-link-list">
            <a href="<?php echo esc_url($this->adminTabUrl('overview', 'overview-setup-health')); ?>"><?php esc_html_e('Review setup health and readiness', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
            <a href="<?php echo esc_url($this->adminTabUrl('settings', 'settings-core')); ?>"><?php esc_html_e('Configure provider, bucket, and delivery', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
            <a href="<?php echo esc_url($this->adminTabUrl('settings', 'settings-automation')); ?>"><?php esc_html_e('Review automation, schedules, and path rules', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
            <a href="<?php echo esc_url($this->adminTabUrl('tools')); ?>"><?php printf(esc_html__('Open tools (%d pending URL updates)', MEDIA_OFFLOAD_TEXT_DOMAIN), $pending); ?></a>
            <a href="<?php echo esc_url($this->adminTabUrl('tools', 'tools-integrations')); ?>"><?php printf(esc_html__('Review integrations (%d detected)', MEDIA_OFFLOAD_TEXT_DOMAIN), (int) ($integrationSummary['detected'] ?? 0)); ?></a>
            <a href="<?php echo esc_url($this->adminTabUrl('tools', 'logs-panel')); ?>"><?php esc_html_e('Inspect logs and diagnostics', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
            <a href="<?php echo esc_url($this->adminTabUrl('overview', 'overview-license')); ?>"><?php esc_html_e('Check license status', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
          </div>
        </div>
      </div>

      <?php $this->renderDashboard(); ?>

      <div class="pt-mo-grid-2 pt-mo-overview-actions-grid">
        <div class="pt-mo-card" id="overview-integrations">
          <h3><?php esc_html_e('Integration compatibility', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <ul class="pt-mo-status-list">
            <li>
              <span class="dashicons dashicons-admin-plugins"></span>
              <strong><?php esc_html_e('Detected surfaces', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span class="pt-mo-spacer"></span>
              <?php echo number_format((int) ($integrationSummary['detected'] ?? 0)); ?>
            </li>
            <li>
              <span class="dashicons dashicons-update"></span>
              <strong><?php esc_html_e('Rewrite ready', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span class="pt-mo-spacer"></span>
              <?php echo number_format((int) ($integrationSummary['rewrite_ready'] ?? 0)); ?>
            </li>
          </ul>
          <p class="pt-mo-muted"><?php esc_html_e('Compatibility scans and rewrite actions now live under Tools so Settings stays focused on configuration.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <div class="pt-mo-actions pt-mo-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('tools', 'tools-integrations')); ?>"><?php esc_html_e('Open integration tools', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
          </div>
        </div>

        <div class="pt-mo-card" id="overview-license">
          <h3><?php esc_html_e('License snapshot', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <ul class="pt-mo-status-list">
            <li>
              <span class="dashicons dashicons-admin-network"></span>
              <strong><?php esc_html_e('Plan', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span class="pt-mo-spacer"></span>
              <?php echo esc_html((string) ($license['plan'] ?? 'monthly')); ?>
            </li>
            <li>
              <span class="dashicons dashicons-lock"></span>
              <strong><?php esc_html_e('Status', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span class="pt-mo-spacer"></span>
              <?php echo esc_html((string) ($license['status'] ?? 'inactive')); ?>
            </li>
            <li>
              <span class="dashicons dashicons-admin-generic"></span>
              <strong><?php esc_html_e('Key present', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
              <span class="pt-mo-spacer"></span>
              <?php echo !empty($license['key']) ? esc_html__('Yes', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('No', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
            </li>
          </ul>
          <p class="pt-mo-muted"><?php esc_html_e('License capture happens during onboarding, while this snapshot helps you verify plan and status after setup is complete.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <div class="pt-mo-actions pt-mo-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('settings', 'settings-core')); ?>"><?php esc_html_e('Open configuration settings', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
          </div>
        </div>
      </div>

      <?php if (!empty($migration['needs_migration'])) : ?>
        <?php $this->renderActivationRecoveryPanel($migration); ?>
      <?php endif; ?>

      <div class="pt-mo-grid-2 pt-mo-overview-actions-grid">
        <div class="pt-mo-card" id="overview-setup-health">
          <h3><?php esc_html_e('Setup health', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <ul class="pt-mo-status-list">
            <li>
              <span class="dashicons <?php echo !empty($onboarding['completed']) ? 'dashicons-yes-alt status-ok' : 'dashicons-warning status-warning'; ?>"></span>
              <strong><?php esc_html_e('Onboarding:', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong> <?php echo !empty($onboarding['completed']) ? esc_html__('Completed', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Needs review', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $this->settings->has_valid_credentials() ? 'dashicons-yes-alt status-ok' : 'dashicons-warning status-error'; ?>"></span>
              <strong><?php esc_html_e('Credentials:', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong> <?php echo $this->settings->has_valid_credentials() ? esc_html__('Configured', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Needs attention', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $this->settings->should_serve_from_storage() ? 'dashicons-admin-links status-ok' : 'dashicons-minus'; ?>"></span>
              <strong><?php esc_html_e('Delivery:', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong> <?php echo $this->settings->should_serve_from_storage() ? esc_html__('Serving from storage', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Using local/origin URLs', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
            </li>
            <li>
              <span class="dashicons <?php echo $pending > 0 ? 'dashicons-update status-warning' : 'dashicons-yes-alt status-ok'; ?>"></span>
              <strong><?php esc_html_e('Pending updates:', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong> <?php echo number_format($pending); ?>
            </li>
            <li>
              <span class="dashicons dashicons-format-status"></span>
              <strong><?php esc_html_e('Offloaded media:', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong> <?php echo number_format((int) ($stats['offloaded'] ?? 0)); ?>
            </li>
            <li>
              <span class="dashicons <?php echo !empty($onboarding['has_active_conflicts']) ? 'dashicons-warning status-error' : 'dashicons-yes-alt status-ok'; ?>"></span>
              <strong><?php esc_html_e('Plugin conflicts:', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong> <?php echo !empty($onboarding['has_active_conflicts']) ? esc_html__('Review required', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('No active conflicts detected', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
            </li>
          </ul>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('What’s next', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <p><?php esc_html_e('This screen now surfaces onboarding, integration compatibility, and API-backed operational status. The next product slice can focus on resumable jobs, single-item actions in the media grid, and license validation.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <div class="pt-mo-code-list pt-mo-link-list">
            <a href="<?php echo esc_url($this->adminTabUrl('overview', 'overview-license')); ?>"><?php esc_html_e('Review license state', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
            <a href="<?php echo esc_url($this->adminTabUrl('tools', 'tools-integrations')); ?>"><?php esc_html_e('Inspect plugin-specific asset roots', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
          </div>
        </div>
      </div>

      <div class="pt-mo-grid-2 pt-mo-overview-actions-grid">
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Asset roots detected', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <?php if (empty($deepStats['asset_roots'])) : ?>
            <p><?php esc_html_e('No attachment roots sampled yet.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <?php else : ?>
            <ul class="pt-mo-status-list">
              <?php foreach ($deepStats['asset_roots'] as $root) : ?>
                <li>
                  <span class="dashicons dashicons-category"></span>
                  <strong><?php echo esc_html((string) ($root['root'] ?? '')); ?></strong>
                  <span class="pt-mo-spacer"></span>
                  <?php echo number_format((int) ($root['count'] ?? 0)); ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Provider cost comparison', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <p class="pt-mo-muted"><?php echo esc_html((string) ($deepStats['cost_comparison']['note'] ?? '')); ?></p>
          <ul class="pt-mo-status-list">
            <?php foreach (($deepStats['cost_comparison']['scenarios'] ?? []) as $scenario) : ?>
              <li>
                <span class="dashicons dashicons-chart-line"></span>
                <strong><?php echo esc_html(ucwords(str_replace('_', ' ', (string) ($scenario['provider'] ?? '')))); ?></strong>
                <span class="pt-mo-spacer"></span>
                <?php echo '$' . esc_html(number_format((float) ($scenario['estimated_monthly_cost'] ?? 0), 2)); ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php
  }

  private function renderSettingsTab(): void
  {
    $settings = $this->settings->all();
    $providerLabel = $this->settings->provider_profile((string) ($settings['provider'] ?? 's3'))->label();
    $allowedPatterns = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($settings['allowed_path_patterns'] ?? '')) ?: []));
    $ignoredPatterns = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($settings['ignored_path_patterns'] ?? '')) ?: []));
    ?>
    <div class="pt-mo-settings-shell">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Settings', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Connection, delivery, and automation', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('This tab is now focused on the settings that directly control how media is offloaded and served. Diagnostics and integration tools have been moved out so this area stays easier to scan.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>

        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Focused layout', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Two groups, one save action', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('Use Core setup for provider and delivery settings, then Automation for path rules and off-hours behavior.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>
      </div>

      <div class="pt-mo-grid pt-mo-settings-summary-grid">
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Provider', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo esc_html($providerLabel); ?></div>
          <div class="pt-mo-substat"><?php echo esc_html((string) ($settings['bucket'] ?? __('Bucket not set', MEDIA_OFFLOAD_TEXT_DOMAIN))); ?></div>
        </div>
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Delivery', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo !empty($settings['serve_from_storage']) ? esc_html__('Remote', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Local', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
          <div class="pt-mo-substat"><?php echo !empty($settings['custom_domain']) ? esc_html((string) $settings['custom_domain']) : esc_html__('No custom domain configured', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
        </div>
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Path rules', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format(count($allowedPatterns) + count($ignoredPatterns)); ?></div>
          <div class="pt-mo-substat"><?php echo esc_html(sprintf(__('Allowed: %1$d · Ignored: %2$d', MEDIA_OFFLOAD_TEXT_DOMAIN), count($allowedPatterns), count($ignoredPatterns))); ?></div>
        </div>
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Off-hours batch', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo !empty($settings['off_hours_batch_enabled']) ? esc_html__('On', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Off', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
          <div class="pt-mo-substat"><?php echo esc_html((string) (!empty($settings['off_hours_batch_time']) ? $settings['off_hours_batch_time'] : __('No schedule set', MEDIA_OFFLOAD_TEXT_DOMAIN))); ?></div>
        </div>
      </div>

      <form method="post" action="options.php" autocomplete="off" class="pt-mo-settings-form">
        <?php settings_fields(MEDIA_OFFLOAD_SETTINGS_GROUP); ?>

        <div class="pt-mo-settings-layout">
          <aside class="pt-mo-settings-sidebar">
            <div class="pt-mo-card pt-mo-settings-nav-card">
              <h3><?php esc_html_e('Jump to', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
              <div class="pt-mo-settings-jumpnav">
                <a href="#settings-core">
                  <strong><?php esc_html_e('Core setup', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
                  <span><?php esc_html_e('Provider, bucket, credentials, and delivery behavior.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
                </a>
                <a href="#settings-automation">
                  <strong><?php esc_html_e('Automation', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
                  <span><?php esc_html_e('Path filters and off-hours scheduling controls.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
                </a>
              </div>
            </div>

            <div class="pt-mo-card pt-mo-settings-nav-card">
              <h3><?php esc_html_e('Moved out of Settings', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
              <div class="pt-mo-code-list pt-mo-link-list">
                <a href="<?php echo esc_url($this->adminTabUrl('tools', 'tools-integrations')); ?>"><?php esc_html_e('Integrations & rewrites → Tools', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
                <a href="<?php echo esc_url($this->adminTabUrl('tools', 'logs-panel')); ?>"><?php esc_html_e('Logging & diagnostics → Tools', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
                <a href="<?php echo esc_url($this->adminTabUrl('overview', 'overview-license')); ?>"><?php esc_html_e('License snapshot → Overview', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
              </div>
            </div>
          </aside>

          <div class="pt-mo-settings-main">
            <section id="settings-core" class="pt-mo-settings-cluster">
              <div class="pt-mo-settings-cluster-header">
                <p class="pt-mo-eyebrow"><?php esc_html_e('Core setup', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
                <h2><?php esc_html_e('Connection and delivery', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('Everything required to connect a provider, define the bucket, and decide how media URLs are served.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
              </div>
              <?php $this->settingsRegistrar->renderSections(['media_offload_connection', 'media_offload_delivery']); ?>
            </section>

            <section id="settings-automation" class="pt-mo-settings-cluster">
              <div class="pt-mo-settings-cluster-header">
                <p class="pt-mo-eyebrow"><?php esc_html_e('Automation', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
                <h2><?php esc_html_e('Rules and scheduling', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('Control which paths are eligible for offload and when background batching should run.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
              </div>
              <?php $this->settingsRegistrar->renderSections(['media_offload_path_filters', 'media_offload_off_hours']); ?>
            </section>

            <div class="pt-mo-settings-submit">
              <?php submit_button(__('Save Settings', MEDIA_OFFLOAD_TEXT_DOMAIN)); ?>
            </div>
          </div>
        </div>
      </form>

      <div class="pt-mo-secondary-surface-grid">
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Integration rewrites', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <p class="pt-mo-muted"><?php esc_html_e('Plugin-specific asset roots and rewrite actions are now grouped with operational tools, where you can test and execute them without cluttering configuration.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <div class="pt-mo-actions pt-mo-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('tools', 'tools-integrations')); ?>"><?php esc_html_e('Open integration tools', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
          </div>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Account and licensing', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <p class="pt-mo-muted"><?php esc_html_e('License state is shown on Overview, while initial key entry remains in onboarding so setup stays linear for new installs.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <div class="pt-mo-actions pt-mo-actions-left">
            <a class="button button-secondary" href="<?php echo esc_url($this->adminTabUrl('overview', 'overview-license')); ?>"><?php esc_html_e('View license snapshot', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
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
    <div class="pt-mo-settings-shell">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Operational tools', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Run maintenance tasks and control background behavior', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('Start an existing-library offload, restore local copies from storage, or retry the failed subset from the most recent job.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>

        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Connection check', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Validate storage access', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('Verify credentials work with your configured bucket.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <div class="pt-mo-actions pt-mo-actions-left">
            <button type="button" id="pt-mo-test-connection" class="button button-secondary"><?php esc_html_e('Test Connection', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <span id="pt-mo-connection-status" style="margin-left: 10px;"></span>
          </div>
          <div id="mo-connection-results" class="mo-connection-results" hidden></div>
        </div>
      </div>

      <?php $this->renderBulkJobsPanel(); ?>
      <?php if (!empty($migration['needs_migration'])) : ?>
        <?php $this->renderActivationRecoveryPanel($migration); ?>
      <?php endif; ?>
      <?php $this->renderAttachmentInspector(); ?>
      <?php $this->renderUrlUpdatesPanel(); ?>
      <?php $this->renderIntegrationsTab(true); ?>
      <?php $this->renderLogs(); ?>
    </div>
    <?php
  }

  private function renderBulkJobsPanel(): void
  {
    $state = $this->bulkJobManager->state();
    $job = !empty($state['current']['id']) ? $state['current'] : $state['last'];
    $hasJob = !empty($job['id']);
    ?>
    <section class="pt-mo-card pt-mo-bulk-job-shell" id="pt-mo-bulk-job-app">
      <div class="pt-mo-bulk-job-header">
        <div>
          <h3><?php esc_html_e('Bulk media jobs', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <p class="pt-mo-muted"><?php esc_html_e('This panel polls the current queue state and lets you start, pause, resume, or retry background jobs.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>
        <span id="pt-mo-job-status-badge" class="pt-mo-inline-badge <?php echo $hasJob ? esc_attr($this->jobBadgeClass((string) ($job['status'] ?? 'idle'))) : ''; ?>"><?php echo esc_html($hasJob ? (string) ($job['status_label'] ?? __('Idle', MEDIA_OFFLOAD_TEXT_DOMAIN)) : __('Idle', MEDIA_OFFLOAD_TEXT_DOMAIN)); ?></span>
      </div>

      <div class="pt-mo-grid pt-mo-bulk-job-grid">
        <div class="pt-mo-card pt-mo-bulk-job-card">
          <h3><?php esc_html_e('Current job', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat" id="pt-mo-job-label"><?php echo esc_html($hasJob ? (string) ($job['type_label'] ?? __('No active job', MEDIA_OFFLOAD_TEXT_DOMAIN)) : __('No active job', MEDIA_OFFLOAD_TEXT_DOMAIN)); ?></div>
          <div class="pt-mo-substat" id="pt-mo-job-message"><?php echo esc_html($hasJob ? (string) ($job['last_message'] ?? '') : __('Choose a bulk task to begin.', MEDIA_OFFLOAD_TEXT_DOMAIN)); ?></div>
          <div class="pt-mo-progress pt-mo-bulk-job-progress"><div id="pt-mo-job-progress-bar" class="pt-mo-bar" style="width: <?php echo esc_attr((string) ($job['percent_complete'] ?? 0)); ?>%"></div></div>
          <div class="pt-mo-substat" id="pt-mo-job-progress-text"><?php echo esc_html(sprintf(__('%1$d%% complete', MEDIA_OFFLOAD_TEXT_DOMAIN), (int) ($job['percent_complete'] ?? 0))); ?></div>
        </div>

        <div class="pt-mo-card pt-mo-bulk-job-card">
          <h3><?php esc_html_e('Queue state', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <ul class="pt-mo-status-list">
            <li><strong><?php esc_html_e('Processed', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><span id="pt-mo-job-processed"><?php echo number_format((int) ($job['processed_items'] ?? 0)); ?></span> / <span id="pt-mo-job-total"><?php echo number_format((int) ($job['total_items'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Succeeded', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><span id="pt-mo-job-success"><?php echo number_format((int) ($job['success_count'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Failed', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><span id="pt-mo-job-failed"><?php echo number_format((int) ($job['failed_count'] ?? 0)); ?></span></li>
            <li><strong><?php esc_html_e('Skipped', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><span id="pt-mo-job-skipped"><?php echo number_format((int) ($job['skipped_count'] ?? 0)); ?></span></li>
          </ul>
        </div>

        <div class="pt-mo-card pt-mo-bulk-job-card">
          <h3><?php esc_html_e('Actions', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-bulk-job-actions">
            <button type="button" class="button button-primary" data-job-action="start" data-job-type="offload_existing"><?php esc_html_e('Offload existing library', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <button type="button" class="button" data-job-action="start" data-job-type="restore_local"><?php esc_html_e('Restore local copies', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <button type="button" class="button" data-job-action="pause"><?php esc_html_e('Pause', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <button type="button" class="button" data-job-action="resume"><?php esc_html_e('Resume', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <button type="button" class="button" data-job-action="retry-failed"><?php esc_html_e('Retry failed items', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
          </div>
          <p class="pt-mo-muted"><?php esc_html_e('Pause/resume works on the current job. Retry failed items uses the failed subset from the most recent completed job.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
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
    <section class="pt-mo-card pt-mo-activation-recovery-shell">
      <div class="pt-mo-bulk-job-header">
        <div>
          <h3><?php esc_html_e('Activation recovery', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <p class="pt-mo-muted"><?php esc_html_e('An earlier activation/upgrade migration did not finish. Resume it here in small batches instead of forcing the entire site through one heavy request.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>
        <span class="pt-mo-inline-badge is-warning"><?php esc_html_e('Recovery needed', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
      </div>

      <ul class="pt-mo-status-list">
        <li>
          <span class="dashicons <?php echo !empty($migration['scheduled']) ? 'dashicons-clock status-warning' : 'dashicons-update status-warning'; ?>"></span>
          <strong><?php esc_html_e('Background follow-up', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
          <span class="pt-mo-spacer"></span>
          <?php echo !empty($migration['scheduled']) ? esc_html__('Scheduled', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Not scheduled yet', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
        </li>
        <li>
          <span class="dashicons dashicons-database"></span>
          <strong><?php esc_html_e('Legacy metadata keys', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
          <span class="pt-mo-spacer"></span>
          <?php echo number_format(count($legacyMetaKeys)); ?>
        </li>
        <li>
          <span class="dashicons dashicons-admin-settings"></span>
          <strong><?php esc_html_e('Legacy options', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
          <span class="pt-mo-spacer"></span>
          <?php echo number_format(count($legacyOptions)); ?>
        </li>
        <li>
          <span class="dashicons dashicons-format-image"></span>
          <strong><?php esc_html_e('Attachment metadata payloads', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong>
          <span class="pt-mo-spacer"></span>
          <?php echo $hasAttachmentPayloads ? esc_html__('Still pending', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Clear', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
        </li>
      </ul>

      <div class="pt-mo-actions pt-mo-actions-left">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field(MEDIA_OFFLOAD_ADMIN_ACTION_RESUME_MIGRATION); ?>
          <input type="hidden" name="action" value="<?php echo esc_attr(MEDIA_OFFLOAD_ADMIN_ACTION_RESUME_MIGRATION); ?>" />
          <button type="submit" class="button button-primary"><?php esc_html_e('Resume activation recovery', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
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
    <section class="pt-mo-card pt-mo-attachment-inspector">
      <h3><?php esc_html_e('Attachment status', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
      <ul class="pt-mo-status-list">
        <li><strong><?php esc_html_e('Attachment ID', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><?php echo number_format($attachmentId); ?></li>
        <li><strong><?php esc_html_e('Status', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong><span class="pt-mo-spacer"></span><span class="pt-mo-inline-badge <?php echo esc_attr($this->jobBadgeClass((string) ($status['status'] ?? 'local'))); ?>"><?php echo esc_html((string) ($status['label'] ?? __('Unknown', MEDIA_OFFLOAD_TEXT_DOMAIN))); ?></span></li>
      </ul>
      <?php if (!empty($status['reason'])) : ?>
        <p class="pt-mo-muted"><?php echo esc_html((string) $status['reason']); ?></p>
      <?php endif; ?>
    </section>
    <?php
  }

  private function renderIntegrationsTab(bool $embedded = false): void
  {
    $summary = $this->integrationDiscovery->summary();
    $items = $summary['items'] ?? [];
    ?>
    <?php if (!$embedded) : ?>
    <div class="pt-mo-settings-shell">
    <?php endif; ?>
      <section id="tools-integrations" class="pt-mo-embedded-surface">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('External assets', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Detect plugin-specific asset directories and rewrite candidates', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('Some plugins keep files outside the default year/month upload pattern or store URLs inside their own database tables. This tab surfaces those locations and lets Media Offload run supported rewrite passes.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>

        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Current support', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php printf(esc_html__('%d integration surfaces detected', MEDIA_OFFLOAD_TEXT_DOMAIN), (int) ($summary['detected'] ?? 0)); ?></h2>
          <p><?php printf(esc_html__('%d integrations are ready for automated rewrites right now.', MEDIA_OFFLOAD_TEXT_DOMAIN), (int) ($summary['rewrite_ready'] ?? 0)); ?></p>
        </div>
      </div>

      <div class="pt-mo-integrations-grid">
        <?php foreach ($items as $item) : ?>
          <section class="pt-mo-card pt-mo-integration-card">
            <div class="pt-mo-integration-header">
              <div>
                <h3><?php echo esc_html((string) ($item['label'] ?? '')); ?></h3>
                <p class="pt-mo-muted"><?php echo esc_html((string) ($item['notes'] ?? '')); ?></p>
              </div>
              <span class="pt-mo-inline-badge <?php echo !empty($item['rewrite_ready']) ? 'is-success' : (!empty($item['rewrite_supported']) ? 'is-warning' : ''); ?>">
                <?php
                if (!empty($item['rewrite_ready'])) {
                  esc_html_e('Rewrite ready', MEDIA_OFFLOAD_TEXT_DOMAIN);
                } elseif (!empty($item['rewrite_supported'])) {
                  esc_html_e('Supported', MEDIA_OFFLOAD_TEXT_DOMAIN);
                } else {
                  esc_html_e('Detection only', MEDIA_OFFLOAD_TEXT_DOMAIN);
                }
                ?>
              </span>
            </div>

            <ul class="pt-mo-status-list">
              <li>
                <span class="dashicons <?php echo !empty($item['plugin_active']) ? 'dashicons-admin-plugins status-ok' : (!empty($item['plugin_installed']) ? 'dashicons-admin-plugins status-warning' : 'dashicons-minus'); ?>"></span>
                <strong><?php esc_html_e('Plugin state', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>:</strong>
                <span class="pt-mo-spacer"></span>
                <?php
                if (!empty($item['plugin_active'])) {
                  esc_html_e('Active', MEDIA_OFFLOAD_TEXT_DOMAIN);
                } elseif (!empty($item['plugin_installed'])) {
                  esc_html_e('Installed', MEDIA_OFFLOAD_TEXT_DOMAIN);
                } else {
                  esc_html_e('Not detected', MEDIA_OFFLOAD_TEXT_DOMAIN);
                }
                ?>
              </li>
              <li>
                <span class="dashicons dashicons-category"></span>
                <strong><?php esc_html_e('Directories', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>:</strong>
                <span class="pt-mo-spacer"></span>
                <?php echo number_format(count((array) ($item['directories'] ?? []))); ?>
              </li>
              <li>
                <span class="dashicons dashicons-database"></span>
                <strong><?php esc_html_e('Tables', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>:</strong>
                <span class="pt-mo-spacer"></span>
                <?php echo number_format((int) ($item['table_count'] ?? 0)); ?>
              </li>
            </ul>

            <?php if (!empty($item['directories'])) : ?>
              <div class="pt-mo-code-list">
                <?php foreach ((array) $item['directories'] as $directory) : ?>
                  <code><?php echo esc_html((string) $directory); ?></code>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($item['tables'])) : ?>
              <div class="pt-mo-code-list">
                <?php foreach ((array) $item['tables'] as $table) : ?>
                  <code><?php echo esc_html((string) $table); ?></code>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($item['rewrite_supported']) && !empty($item['rewrite_action_label'])) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pt-mo-inline-form">
                <?php wp_nonce_field(MEDIA_OFFLOAD_ADMIN_ACTION_REWRITE_INTEGRATION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(MEDIA_OFFLOAD_ADMIN_ACTION_REWRITE_INTEGRATION); ?>" />
                <input type="hidden" name="integration" value="<?php echo esc_attr((string) ($item['key'] ?? '')); ?>" />
                <button type="submit" class="button button-primary" <?php disabled(empty($item['rewrite_ready'])); ?>><?php echo esc_html((string) ($item['rewrite_action_label'] ?? __('Run rewrite', MEDIA_OFFLOAD_TEXT_DOMAIN))); ?></button>
              </form>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
      </section>
    <?php if (!$embedded) : ?>
    </div>
    <?php endif; ?>
    <?php
  }

  private function renderLicenseTab(): void
  {
    $license = $this->licenseManager->all();
    ?>
    <div class="pt-mo-settings-shell">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('License', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Commercial subscription state', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('The onboarding flow now stores the license key and exposes status through the REST API. Remote validation and billing sync can plug into this surface next.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>

        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Planned capabilities', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <ul class="pt-mo-bullet-list">
            <li><?php esc_html_e('Activate or deactivate a license key / account token', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
            <li><?php esc_html_e('See subscription and renewal status', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
            <li><?php esc_html_e('Show grace period and feature access details', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
            <li><?php esc_html_e('Link to billing/help actions without affecting front-end delivery', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
          </ul>
        </div>
      </div>

      <div class="pt-mo-card pt-mo-placeholder-card">
        <h3><?php esc_html_e('Stored license state', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
        <ul class="pt-mo-status-list">
          <li><span class="dashicons dashicons-admin-network"></span><strong><?php esc_html_e('Plan', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>:</strong><span class="pt-mo-spacer"></span><?php echo esc_html((string) ($license['plan'] ?? 'monthly')); ?></li>
          <li><span class="dashicons dashicons-lock"></span><strong><?php esc_html_e('Status', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>:</strong><span class="pt-mo-spacer"></span><?php echo esc_html((string) ($license['status'] ?? 'inactive')); ?></li>
          <li><span class="dashicons dashicons-admin-generic"></span><strong><?php esc_html_e('Key present', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>:</strong><span class="pt-mo-spacer"></span><?php echo !empty($license['key']) ? esc_html__('Yes', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('No', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></li>
        </ul>
        <p class="pt-mo-muted"><?php esc_html_e('The key itself stays editable from onboarding for now so first-run setup remains a single flow.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
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
    <div class="pt-mo-dashboard">
      <div class="pt-mo-grid">
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
          <div class="pt-mo-stat"><?php echo size_format($storageSize); ?></div>
          <div class="pt-mo-substat"><?php echo number_format($storageCount); ?> objects</div>
        </div>
        <div class="pt-mo-card">
          <h3>Est. Monthly Cost</h3>
          <div class="pt-mo-stat">$<?php echo number_format($storageCost, 2); ?></div>
          <div class="pt-mo-substat">Based on $0.023/GB</div>
        </div>
      </div>

      <div class="pt-mo-grid-2">
        <div class="pt-mo-card">
          <h3>Uploads (Last 7 Days)</h3>
          <div class="pt-mo-chart">
            <?php if (empty($stats['daily'])) : ?>
              <p>No uploads in the last 7 days.</p>
            <?php else : ?>
              <?php
              $max = max(array_column($stats['daily'], 'count'));
              foreach ($stats['daily'] as $day) :
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
        </div>
      </div>

      <div class="pt-mo-grid-2">
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Media type breakdown', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <?php if (empty($mimeBreakdown)) : ?>
            <p><?php esc_html_e('No media type breakdown available yet.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <?php else : ?>
            <ul class="pt-mo-status-list">
              <?php foreach ($mimeBreakdown as $row) : ?>
                <li>
                  <span class="dashicons dashicons-media-default"></span>
                  <strong><?php echo esc_html(ucfirst((string) ($row['category'] ?? 'other'))); ?></strong>
                  <span class="pt-mo-spacer"></span>
                  <?php echo esc_html(sprintf('%d / %d', (int) ($row['offloaded'] ?? 0), (int) ($row['total'] ?? 0))); ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Recent monthly trend', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <?php if (empty($monthlyBreakdown)) : ?>
            <p><?php esc_html_e('No monthly attachment trend available yet.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <?php else : ?>
            <ul class="pt-mo-status-list">
              <?php foreach ($monthlyBreakdown as $row) : ?>
                <li>
                  <span class="dashicons dashicons-calendar-alt"></span>
                  <strong><?php echo esc_html((string) ($row['month'] ?? '')); ?></strong>
                  <span class="pt-mo-spacer"></span>
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

  private function renderLogs(): void
  {
    $settings = $this->settings->all();
    $stats = $this->logger->stats();
    $selectedLevel = isset($_GET['log_level']) ? sanitize_key((string) wp_unslash($_GET['log_level'])) : '';
    $selectedSource = isset($_GET['log_source']) ? sanitize_key((string) wp_unslash($_GET['log_source'])) : '';
    $search = isset($_GET['log_search']) ? sanitize_text_field((string) wp_unslash($_GET['log_search'])) : '';
    $limit = isset($_GET['log_limit']) ? max(25, min(1000, (int) $_GET['log_limit'])) : 200;
    $entries = $this->logger->queryEntries([
      'level' => $selectedLevel,
      'source' => $selectedSource,
      'search' => $search,
      'limit' => $limit,
    ]);
    $sources = array_keys((array) ($stats['by_source'] ?? []));
    $errorCount = (int) (($stats['by_level']['error'] ?? 0) + ($stats['by_level']['critical'] ?? 0));
    $warningCount = (int) ($stats['by_level']['warning'] ?? 0);
    ?>
    <div class="pt-mo-settings-shell" id="logs-panel">
      <div class="pt-mo-grid pt-mo-log-stats-grid">
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Stored entries', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format((int) ($stats['total'] ?? 0)); ?></div>
          <div class="pt-mo-substat"><?php echo esc_html(sprintf(__('Retention cap: %d entries', MEDIA_OFFLOAD_TEXT_DOMAIN), (int) ($settings['log_max_entries'] ?? 2000))); ?></div>
        </div>
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Warnings', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format($warningCount); ?></div>
          <div class="pt-mo-substat"><?php esc_html_e('Messages stored at warning severity.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
        </div>
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Errors + critical', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format($errorCount); ?></div>
          <div class="pt-mo-substat"><?php esc_html_e('High-severity issues worth investigating first.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
        </div>
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Logger status', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo !empty($settings['logging_enabled']) ? esc_html__('On', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Off', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
          <div class="pt-mo-substat"><?php echo esc_html(sprintf(__('Minimum level: %s', MEDIA_OFFLOAD_TEXT_DOMAIN), strtoupper((string) ($settings['log_level'] ?? 'info')))); ?></div>
        </div>
      </div>

      <div class="pt-mo-grid-2 pt-mo-logs-grid">
        <section class="pt-mo-card pt-mo-settings-section-card">
          <h3><?php esc_html_e('Logger controls', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <form method="post" action="options.php" autocomplete="off" class="pt-mo-settings-form">
            <?php
            settings_fields(MEDIA_OFFLOAD_SETTINGS_GROUP);
            $this->settingsRegistrar->renderSections($this->settingsRegistrar->getSectionIdsForTab('logs'));
            submit_button(__('Save Logging Settings', MEDIA_OFFLOAD_TEXT_DOMAIN));
            ?>
          </form>
        </section>

        <section class="pt-mo-card pt-mo-settings-section-card">
          <h3><?php esc_html_e('Viewer filters', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <form method="get" class="pt-mo-log-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(MEDIA_OFFLOAD_SLUG); ?>" />
            <input type="hidden" name="tab" value="logs" />
            <div class="pt-mo-log-filter-grid">
              <label>
                <span><?php esc_html_e('Level', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
                <select name="log_level">
                  <option value=""><?php esc_html_e('All levels', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></option>
                  <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) : ?>
                    <option value="<?php echo esc_attr($level); ?>" <?php selected($selectedLevel, $level); ?>><?php echo esc_html(strtoupper($level)); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span><?php esc_html_e('Source', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
                <select name="log_source">
                  <option value=""><?php esc_html_e('All sources', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></option>
                  <?php foreach ($sources as $source) : ?>
                    <option value="<?php echo esc_attr((string) $source); ?>" <?php selected($selectedSource, (string) $source); ?>><?php echo esc_html((string) $source); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>
                <span><?php esc_html_e('Contains text', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
                <input type="search" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="attachment 123 / purge / rewrite" />
              </label>
              <label>
                <span><?php esc_html_e('Rows', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
                <select name="log_limit">
                  <?php foreach ([50, 100, 200, 500, 1000] as $option) : ?>
                    <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($limit, $option); ?>><?php echo esc_html((string) $option); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="pt-mo-actions pt-mo-actions-left pt-mo-log-filter-actions">
              <button type="submit" class="button button-secondary"><?php esc_html_e('Apply filters', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
              <a class="button button-link" href="<?php echo esc_url($this->adminTabUrl('logs')); ?>"><?php esc_html_e('Reset', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></a>
            </div>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pt-mo-log-clear-form">
            <?php wp_nonce_field(MEDIA_OFFLOAD_ADMIN_ACTION_CLEAR_LOGS); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(MEDIA_OFFLOAD_ADMIN_ACTION_CLEAR_LOGS); ?>" />
            <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Clear all plugin logs?', MEDIA_OFFLOAD_TEXT_DOMAIN)); ?>');">
              <?php esc_html_e('Clear Logs', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
            </button>
          </form>
        </section>
      </div>

      <section class="pt-mo-card pt-mo-log-viewer-card">
        <h3><?php esc_html_e('Plugin logs', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
        <p><?php esc_html_e('Each entry now records source, level, optional event/code metadata, and normalized context for debugging operational flows.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>

        <?php if (empty($entries)) : ?>
          <p><?php esc_html_e('No log entries match the current filters yet.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        <?php else : ?>
          <div class="pt-mo-log-table-wrap">
            <table class="widefat striped pt-mo-log-table">
              <thead>
                <tr>
                  <th style="width:180px;"><?php esc_html_e('Time', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></th>
                  <th style="width:110px;"><?php esc_html_e('Level', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></th>
                  <th style="width:140px;"><?php esc_html_e('Source', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></th>
                  <th style="width:180px;"><?php esc_html_e('Event / Code', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></th>
                  <th><?php esc_html_e('Message', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></th>
                  <th style="width:220px;"><?php esc_html_e('Context', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($entries as $entry) : ?>
                  <tr>
                    <td><?php echo esc_html((string) ($entry['datetime'] ?? '')); ?></td>
                    <td><span class="pt-mo-log-level-badge is-<?php echo esc_attr((string) ($entry['level_key'] ?? 'info')); ?>"><?php echo esc_html((string) ($entry['level'] ?? 'INFO')); ?></span></td>
                    <td><code><?php echo esc_html((string) ($entry['source'] ?? 'plugin')); ?></code></td>
                    <td>
                      <?php if (!empty($entry['event'])) : ?>
                        <div><code><?php echo esc_html((string) $entry['event']); ?></code></div>
                      <?php endif; ?>
                      <?php if (!empty($entry['code'])) : ?>
                        <div class="pt-mo-muted"><code><?php echo esc_html((string) $entry['code']); ?></code></div>
                      <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) ($entry['message'] ?? '')); ?></td>
                    <td>
                      <?php if (!empty($entry['context'])) : ?>
                        <details class="pt-mo-log-context-details">
                          <summary><?php esc_html_e('View context', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></summary>
                          <pre><?php echo esc_html(wp_json_encode($entry['context'] ?? [], JSON_PRETTY_PRINT)); ?></pre>
                        </details>
                      <?php else : ?>
                        <span class="pt-mo-muted"><?php esc_html_e('—', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></span>
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
    <div class="pt-mo-dashboard">
      <?php if (isset($_GET['ptmo_url_update']) && (string) $_GET['ptmo_url_update'] === 'error') : ?>
        <?php
        $reason = isset($_GET['ptmo_url_update_reason']) ? sanitize_key((string) wp_unslash($_GET['ptmo_url_update_reason'])) : '';
        $message = __('URL update could not be started.', MEDIA_OFFLOAD_TEXT_DOMAIN);

        if ($reason === 'disabled') {
          $message = __('Media offload is currently disabled. Enable it first in Settings.', MEDIA_OFFLOAD_TEXT_DOMAIN);
        } elseif ($reason === 'credentials') {
          $message = __('Missing or invalid storage credentials. Please verify your settings first.', MEDIA_OFFLOAD_TEXT_DOMAIN);
        } elseif ($reason === 'connection') {
          $message = __('Storage connection test failed (authentication/signing). Verify provider, endpoint, region, and keys before retrying.', MEDIA_OFFLOAD_TEXT_DOMAIN);
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

        $modeLabel = $mode === 'full' ? __('full run', MEDIA_OFFLOAD_TEXT_DOMAIN) : __('single update', MEDIA_OFFLOAD_TEXT_DOMAIN);
        $noticeClass = 'notice notice-' . ($status === 'warning' ? 'warning' : ($status === 'info' ? 'info' : 'success'));
        ?>
        <div class="<?php echo esc_attr($noticeClass); ?>">
          <p>
            <?php
            echo esc_html(sprintf(
              __('URL update %1$s finished. Processed: %2$d, Success: %3$d, Failed: %4$d, Remaining pending: %5$d.', MEDIA_OFFLOAD_TEXT_DOMAIN),
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

      <div class="pt-mo-grid">
        <div class="pt-mo-card">
          <h3><?php esc_html_e('Pending URL Updates', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format($pending); ?></div>
          <div class="pt-mo-substat"><?php esc_html_e('Attachments waiting for URL/offload metadata update', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Offloaded Attachments', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format((int) ($stats['offloaded'] ?? 0)); ?></div>
          <div class="pt-mo-substat"><?php esc_html_e('Already linked with offload metadata', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Plugin Status', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo $isEnabled ? esc_html__('Enabled', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Disabled', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
          <div class="pt-mo-substat"><?php echo $hasCredentials ? esc_html__('Credentials configured', MEDIA_OFFLOAD_TEXT_DOMAIN) : esc_html__('Credentials missing', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
        </div>

        <div class="pt-mo-card">
          <h3><?php esc_html_e('Total Attachments', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
          <div class="pt-mo-stat"><?php echo number_format((int) ($stats['total'] ?? 0)); ?></div>
          <div class="pt-mo-substat"><?php esc_html_e('In media library', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></div>
        </div>
      </div>

      <div class="pt-mo-card">
        <h3><?php esc_html_e('Run URL Updates', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h3>
        <p><?php esc_html_e('Use one-by-one for safer/manual progression, or full run to process all pending attachments in one go from this admin panel.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        <p><em><?php esc_html_e('Tip: attachments marked “Ignore from Media Offload” and files outside your allowed path filters are skipped automatically here.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></em></p>

        <?php if (!$isEnabled) : ?>
          <p><strong><?php esc_html_e('Media offload is disabled.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong></p>
        <?php endif; ?>

        <?php if (!$hasCredentials) : ?>
          <p><strong><?php esc_html_e('Valid storage credentials are required before URL updates can run.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></strong></p>
        <?php endif; ?>

        <div style="display:flex; gap:12px; flex-wrap:wrap;">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(MEDIA_OFFLOAD_ADMIN_ACTION_RUN_URL_UPDATES); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(MEDIA_OFFLOAD_ADMIN_ACTION_RUN_URL_UPDATES); ?>" />
            <input type="hidden" name="mode" value="single" />
            <button type="submit" class="button button-secondary" <?php disabled(!$isEnabled || !$hasCredentials || $pending <= 0); ?>>
              <?php esc_html_e('Update One Entry', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
            </button>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Run full URL update for all pending entries now?', MEDIA_OFFLOAD_TEXT_DOMAIN)); ?>');">
            <?php wp_nonce_field(MEDIA_OFFLOAD_ADMIN_ACTION_RUN_URL_UPDATES); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(MEDIA_OFFLOAD_ADMIN_ACTION_RUN_URL_UPDATES); ?>" />
            <input type="hidden" name="mode" value="full" />
            <button type="submit" class="button button-primary" <?php disabled(!$isEnabled || !$hasCredentials || $pending <= 0); ?>>
              <?php esc_html_e('Update All Pending Entries', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php
  }

  private function adminTabUrl(string $tab, string $fragment = ''): string
  {
    $url = add_query_arg([
      'page' => MEDIA_OFFLOAD_SLUG,
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
      MEDIA_OFFLOAD_META_KEY,
      MEDIA_OFFLOAD_MISSING_SOURCE_AT_META_KEY,
      MEDIA_OFFLOAD_IGNORE_META_KEY,
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
    $cooldown = max(300, (int) apply_filters('media_offload_missing_source_retry_cooldown_seconds', 12 * HOUR_IN_SECONDS));
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
