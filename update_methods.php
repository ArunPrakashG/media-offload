<?php
$contents = file_get_contents('src/Admin/PageRenderer.php');

$startTools = strpos($contents, 'private function renderToolsTab(): void');
$endLogs = strpos($contents, 'private function renderUrlUpdatesPanel(): void');

$before = substr($contents, 0, strpos($contents, 'private function renderStorageTab(): void'));
$after = substr($contents, $endLogs);

// Add renderSettingsTab and updated renderToolsTab etc
$newMethods = <<<HTML
  private function renderSettingsTab(): void
  {
    ?>
    <div class="pt-mo-settings-shell">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Settings', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Manage Storage, Delivery, and Behavior', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('From here you can adjust which provider you connect to, how files are served, caching paths, logging behavior, and license management. Everything you need to shape how Media Offload behaves.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>
      </div>

      <form method="post" action="options.php" autocomplete="off" class="pt-mo-settings-form">
        <div class="pt-mo-card pt-mo-settings-section-card">
        <?php
        settings_fields(MEDIA_OFFLOAD_SETTINGS_GROUP);
        \$this->settingsRegistrar->renderSections(\$this->settingsRegistrar->getSectionIdsForTab('settings'));
        ?>
        </div>
        <div class="pt-mo-actions-left" style="margin-top: 24px;">
          <?php submit_button(__('Save All Settings', MEDIA_OFFLOAD_TEXT_DOMAIN), 'primary', 'submit', false); ?>
        </div>
      </form>

      <?php // Moved integrations and license panels here visually instead of as their own tabs ?>
      <div class="pt-mo-settings-hero" style="margin-top:24px;">
        <?php \$this->renderIntegrationsTab(); ?>
      </div>
      
      <div class="pt-mo-settings-hero" style="margin-top:24px;">
        <?php \$this->renderLicenseTab(); ?>
      </div>
    </div>
    <?php
  }

  private function renderToolsTab(): void
  {
    \$migration = \ArunPrakash\MediaOffload\Bootstrap\DataMigration::status();
    ?>
    <div class="pt-mo-settings-shell">
      <div class="pt-mo-settings-hero">
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Operational tools', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Run maintenance tasks and inspect data', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('Includes background jobs for bulk offload/restore, test connection tools, logging, URL updates, and attachment inspectors.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
        </div>
        
        <div class="pt-mo-card pt-mo-settings-hero-card">
          <p class="pt-mo-eyebrow"><?php esc_html_e('Connection check', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <h2><?php esc_html_e('Validate storage access', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></h2>
          <p><?php esc_html_e('Run this after configuring settings to verify everything passes network layers.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
          <div class="pt-mo-actions pt-mo-actions-left">
            <button type="button" id="pt-mo-test-connection" class="button button-secondary"><?php esc_html_e('Test Connection', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></button>
            <span id="pt-mo-connection-status" style="margin-left: 10px;"></span>
          </div>
          <div id="mo-connection-results" class="mo-connection-results" hidden></div>
        </div>
      </div>

      <?php \$this->renderBulkJobsPanel(); ?>
      
      <?php if (!empty(\$migration['needs_migration'])) : ?>
        <?php \$this->renderActivationRecoveryPanel(\$migration); ?>
      <?php endif; ?>
      
      <?php \$this->renderAttachmentInspector(); ?>
      <?php \$this->renderUrlUpdatesPanel(); ?>
      <?php \$this->renderLogs(); ?>
    </div>
    <?php
  }

HTML;

// Keep the bulk job panels etc. which are inside the extracted range
// We extract them from the original contents and replace just the tab functions.

function extract_function($contents, $funcName) {
    if(!preg_match('/(private function ' . preg_quote($funcName) . '\s*\([^)]*\)\s*:\s*void\s*\{)(.*?)(^\s+private function |\z)/ms', $contents, $matches)) {
      return '';
    }
    return $matches[1] . $matches[2];
}

$bulkJobs = extract_function($contents, 'renderBulkJobsPanel');
$activation = extract_function($contents, 'renderActivationRecoveryPanel');
$attachmentHtml = extract_function($contents, 'renderAttachmentInspector');
$integrationsHtml = extract_function($contents, 'renderIntegrationsTab');
$licenseHtml = extract_function($contents, 'renderLicenseTab');
$logsHtml = extract_function($contents, 'renderLogs');

// Instead of rewriting everything let's just make a huge string replacement
file_put_contents('src/Admin/PageRenderer.php', $before . $newMethods . "\n" . $bulkJobs . "\n" . $activation . "\n" . $attachmentHtml . "\n" . $integrationsHtml . "\n" . $licenseHtml . "\n" . $logsHtml . "\n" . $after);
