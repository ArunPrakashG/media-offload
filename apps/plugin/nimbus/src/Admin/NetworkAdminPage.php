<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\License\LicenseManager;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Network Admin panel shown in WordPress Multisite installations.
 *
 * Registers a top-level menu item under "My Sites → Network Admin" that
 * gives the super admin a bird's-eye view of Nimbus settings and per-site
 * offload statistics without leaving the network dashboard.
 */
final class NetworkAdminPage
{
  private const MENU_SLUG = 'nimbus-network';

  public function __construct(
    private Settings $settings,
    private LicenseManager $licenseManager,
  ) {
  }

  public function register(): void
  {
    add_action('network_admin_menu', [$this, 'addNetworkMenu']);
  }

  public function addNetworkMenu(): void
  {
    add_menu_page(
      __('Nimbus Network', NIMBUS_TEXT_DOMAIN),
      __('Nimbus', NIMBUS_TEXT_DOMAIN),
      'manage_network',
      self::MENU_SLUG,
      [$this, 'renderPage'],
      'dashicons-cloud-upload',
      80
    );
  }

  public function renderPage(): void
  {
    if (!current_user_can('manage_network')) {
      wp_die(esc_html__('You do not have permission to access this page.', NIMBUS_TEXT_DOMAIN));
    }

    $sites = $this->getSiteStats();
    $license = $this->licenseManager->status();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Nimbus — Network Overview', NIMBUS_TEXT_DOMAIN); ?></h1>

      <h2><?php esc_html_e('License', NIMBUS_TEXT_DOMAIN); ?></h2>
      <table class="widefat striped" style="max-width:600px;margin-bottom:2em">
        <tbody>
          <tr>
            <th><?php esc_html_e('Status', NIMBUS_TEXT_DOMAIN); ?></th>
            <td><?php echo esc_html((string) ($license['status'] ?? '—')); ?></td>
          </tr>
          <tr>
            <th><?php esc_html_e('Plan', NIMBUS_TEXT_DOMAIN); ?></th>
            <td><?php echo esc_html((string) ($license['tier'] ?? '—')); ?></td>
          </tr>
          <tr>
            <th><?php esc_html_e('Sites allowed', NIMBUS_TEXT_DOMAIN); ?></th>
            <td><?php echo esc_html((string) ($license['sites_allowed'] ?? '—')); ?></td>
          </tr>
          <tr>
            <th><?php esc_html_e('Domain', NIMBUS_TEXT_DOMAIN); ?></th>
            <td><?php echo esc_html((string) ($license['domain'] ?? '—')); ?></td>
          </tr>
        </tbody>
      </table>

      <h2><?php esc_html_e('Per-site offload statistics', NIMBUS_TEXT_DOMAIN); ?></h2>
      <?php if (empty($sites)) : ?>
        <p><?php esc_html_e('No sites found in this network.', NIMBUS_TEXT_DOMAIN); ?></p>
      <?php else : ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th><?php esc_html_e('Site', NIMBUS_TEXT_DOMAIN); ?></th>
              <th><?php esc_html_e('Provider', NIMBUS_TEXT_DOMAIN); ?></th>
              <th><?php esc_html_e('Offloaded files', NIMBUS_TEXT_DOMAIN); ?></th>
              <th><?php esc_html_e('Pending', NIMBUS_TEXT_DOMAIN); ?></th>
              <th><?php esc_html_e('Settings', NIMBUS_TEXT_DOMAIN); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sites as $site) : ?>
              <tr>
                <td>
                  <strong><?php echo esc_html($site['name']); ?></strong>
                  <div class="row-actions">
                    <span><a href="<?php echo esc_url($site['dashboard_url']); ?>" target="_blank"><?php esc_html_e('Dashboard', NIMBUS_TEXT_DOMAIN); ?></a></span>
                  </div>
                </td>
                <td><?php echo esc_html($site['provider']); ?></td>
                <td><?php echo esc_html((string) $site['offloaded_count']); ?></td>
                <td><?php echo esc_html((string) $site['pending_count']); ?></td>
                <td>
                  <a href="<?php echo esc_url($site['settings_url']); ?>" target="_blank">
                    <?php esc_html_e('Open', NIMBUS_TEXT_DOMAIN); ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
  }

  /**
   * Collect per-blog offload stats by switching blog context.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getSiteStats(): array
  {
    if (!is_multisite()) {
      return [];
    }

    $blogs = get_sites(['number' => 200, 'fields' => 'ids']);
    if (!is_array($blogs)) {
      return [];
    }

    $results = [];

    foreach ($blogs as $blogId) {
      $blogId = (int) $blogId;
      switch_to_blog($blogId);

      global $wpdb;

      $offloadedCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        NIMBUS_META_KEY
      ));

      $pendingCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        NIMBUS_PENDING_AT_META_KEY
      ));

      $siteSettings = get_option(NIMBUS_OPTION_KEY, []);
      $siteProvider = is_array($siteSettings) ? (string) ($siteSettings['provider'] ?? '—') : '—';

      $results[] = [
        'id' => $blogId,
        'name' => get_bloginfo('name') ?: get_bloginfo('url'),
        'dashboard_url' => admin_url(),
        'settings_url' => admin_url('admin.php?page=nimbus'),
        'provider' => $siteProvider,
        'offloaded_count' => $offloadedCount,
        'pending_count' => $pendingCount,
      ];

      restore_current_blog();
    }

    return $results;
  }
}
