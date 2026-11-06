<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;

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
  ) {
  }

  public function register(): void
  {
    add_action('network_admin_menu', [$this, 'addNetworkMenu']);
  }

  public function addNetworkMenu(): void
  {
    add_menu_page(
      __('Nimbus Network', 'usenimbus-media-offload'),
      __('Nimbus', 'usenimbus-media-offload'),
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
      wp_die(esc_html__('You do not have permission to access this page.', 'usenimbus-media-offload'));
    }

    $sites = $this->getSiteStats();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Nimbus — Network Overview', 'usenimbus-media-offload'); ?></h1>

      <h2><?php esc_html_e('Per-site offload statistics', 'usenimbus-media-offload'); ?></h2>
      <?php if (empty($sites)) : ?>
        <p><?php esc_html_e('No sites found in this network.', 'usenimbus-media-offload'); ?></p>
      <?php else : ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th><?php esc_html_e('Site', 'usenimbus-media-offload'); ?></th>
              <th><?php esc_html_e('Provider', 'usenimbus-media-offload'); ?></th>
              <th><?php esc_html_e('Offloaded files', 'usenimbus-media-offload'); ?></th>
              <th><?php esc_html_e('Pending', 'usenimbus-media-offload'); ?></th>
              <th><?php esc_html_e('Settings', 'usenimbus-media-offload'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sites as $site) : ?>
              <tr>
                <td>
                  <strong><?php echo esc_html($site['name']); ?></strong>
                  <div class="row-actions">
                    <span><a href="<?php echo esc_url($site['dashboard_url']); ?>" target="_blank"><?php esc_html_e('Dashboard', 'usenimbus-media-offload'); ?></a></span>
                  </div>
                </td>
                <td><?php echo esc_html($site['provider']); ?></td>
                <td><?php echo esc_html((string) $site['offloaded_count']); ?></td>
                <td><?php echo esc_html((string) $site['pending_count']); ?></td>
                <td>
                  <a href="<?php echo esc_url($site['settings_url']); ?>" target="_blank">
                    <?php esc_html_e('Open', 'usenimbus-media-offload'); ?>
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

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-site counts on the network admin overview; read live across each blog in the switch_to_blog() loop, viewed only occasionally by super admins.
      $offloadedCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        NIMBUS_META_KEY
      ));

      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-site counts on the network admin overview; read live across each blog in the switch_to_blog() loop, viewed only occasionally by super admins.
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
