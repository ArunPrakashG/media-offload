const fs = require('fs');

let code = fs.readFileSync('src/Admin/PageRenderer.php', 'utf8');

// Replace renderStorageTab and renderDeliveryTab with renderSettingsTab
const startStorage = code.indexOf('  private function renderStorageTab(): void');
const endDelivery = code.indexOf('  private function renderToolsTab(): void');

const newSettingsTab = `  private function renderSettingsTab(): void
  {
    ?>
    <div class="pt-mo-settings-shell">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Settings', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Storage, Delivery & Behaviors', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('Configure your provider, bucket, custom domain, caching, logging behavior all in one place.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>
      </div>

      <form method="post" action="options.php" autocomplete="off" class="pt-mo-settings-form">
        <div class="pt-mo-card pt-mo-settings-section-card">
        <?php
        settings_fields(MEDIA_OFFLOAD_SETTINGS_GROUP);
        $this->settingsRegistrar->renderSections($this->settingsRegistrar->getSectionIdsForTab('settings'));
        submit_button(__('Save All Settings', MEDIA_OFFLOAD_TEXT_DOMAIN));
        ?>
        </div>
      </form>
      
      <?php $this->renderIntegrationsTab(); ?>
      <?php $this->renderLicenseTab(); ?>
    </div>
    <?php
  }

`;

code = code.substring(0, startStorage) + newSettingsTab + code.substring(endDelivery);

// Now inside renderToolsTab, we need to add the test-connection card that was in StorageTab,
// and we need to call renderLogs() instead of the form that used to be there for tools.
const startTools = code.indexOf('  private function renderToolsTab(): void');
const endTools = code.indexOf('  private function renderBulkJobsPanel(): void');

const newToolsTab = `  private function renderToolsTab(): void
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
      <?php $this->renderLogs(); ?>
    </div>
    <?php
  }

`;

code = code.substring(0, startTools) + newToolsTab + code.substring(endTools);

// Also we should hide or format `renderIntegrationsTab`, `renderLicenseTab`, `renderLogs`
// Wait, they are currently rendered manually. But we just embedded `renderLogs` inside Tools, and `renderIntegrationsTab`/`renderLicenseTab` inside Settings. So that's perfectly fine as they are just outputting HTML.
// Wait! Previously `renderLogs()` had a <form>. But that's okay, since renderLogs() etc are still in the class and they work properly. Wait, do we have multiple forms inside one page?
// Yes, `renderIntegrationsTab` has a separate saving button/logic? No, `options.php` form is single. Wait! If `renderIntegrationsTab` does an AJAX thing or standard form, we might end up with nested <form>s. Let's check `renderIntegrationsTab` in code.

fs.writeFileSync('src/Admin/PageRenderer.php', code);
