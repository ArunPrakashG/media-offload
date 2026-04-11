<?php
namespace Nimbus\Plugin\License;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * UpdateChecker integrates Nimbus with the WordPress update system.
 *
 * ## How it works
 *
 * WordPress fires `pre_set_site_transient_update_plugins` whenever it runs an
 * update check (roughly every 12 hours, or when the admin visits
 * Dashboard → Updates). This class intercepts that transient, calls the Nimbus
 * backend to see if a newer version of the plugin is available, and injects
 * the result so WordPress presents the standard "Update available" badge and
 * one-click install — exactly like any plugin hosted on wordpress.org.
 *
 * ## License gating
 *
 * The backend returns update metadata only for active (paid-up) licenses.
 * If the license has expired the response says:
 *
 *   { "update_available": false, "reason": "license_inactive" }
 *
 * In that case we surface an admin notice prompting renewal rather than
 * silently dropping the update.
 *
 * ## Caching
 *
 * Results are cached in a WordPress transient for 12 hours so we don't hammer
 * the backend on every page load. The cache is keyed by plugin file + a hash
 * of the activation token, so different sites (different tokens) never collide.
 */
final class UpdateChecker
{
  /**
   * How long (in seconds) to cache the update check result locally.
   * This matches WordPress's own plugin-update-check interval.
   */
  private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

  /**
   * The basename of the main plugin file, e.g. "nimbus/nimbus.php".
   * WordPress uses this string as the key inside the update transient.
   */
  private string $pluginBasename;

  public function __construct(private LicenseManager $licenseManager)
  {
    $this->pluginBasename = plugin_basename((string) NIMBUS_FILE);
  }

  // ── Registration ────────────────────────────────────────────────────────────

  public function register(): void
  {
    add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdateInfo']);
    add_filter('plugins_api', [$this, 'injectPluginInfo'], 10, 3);
    add_filter('upgrader_process_complete', [$this, 'purgeUpdateCache'], 10, 2);
  }

  // ── WordPress filter hooks ──────────────────────────────────────────────────

  /**
   * Inject our update info into the plugins update transient.
   *
   * WordPress passes the transient object to this filter and expects the same
   * object back, optionally with additional plugins inserted into
   * `$transient->response` (update available) or `$transient->no_update`
   * (current / inactive).
   *
   * @param object $transient The update_plugins transient.
   * @return object The (possibly modified) transient.
   */
  public function injectUpdateInfo(object $transient): object
  {
    if (empty($transient->checked)) {
      return $transient;
    }

    $info = $this->fetchUpdateInfo();
    if ($info === null) {
      return $transient;
    }

    $currentVersion = $transient->checked[$this->pluginBasename] ?? NIMBUS_VERSION;

    if (!empty($info['update_available']) && !empty($info['version'])) {
      // There is a newer version — inject into the response array so WP shows
      // the "Update available" row in the plugins list and Dashboard → Updates.
      $transient->response[$this->pluginBasename] = $this->buildUpdateObject($info, $currentVersion);

      // If the license is expired, also surface an admin notice once per day.
    } else {
      // No update or license inactive — populate no_update so WP knows we
      // checked (prevents it from falling back to the wordpress.org API).
      $transient->no_update[$this->pluginBasename] = $this->buildNoUpdateObject($currentVersion);

      if (isset($info['reason']) && $info['reason'] === 'license_inactive') {
        $this->scheduleRenewalNotice();
      }
    }

    return $transient;
  }

  /**
   * Provide plugin information for the "View version details" modal.
   *
   * When a user clicks "View details" in the plugins list, WordPress fires
   * `plugins_api` to get changelog, requirements, etc. We intercept only
   * requests for our own plugin slug.
   *
   * @param false|object|array $result  The result to return (false = not handled).
   * @param string             $action  The requested action (e.g. "plugin_information").
   * @param object             $args    Arguments containing the plugin slug.
   * @return false|object
   */
  public function injectPluginInfo(false|object|array $result, string $action, object $args): false|object|array
  {
    if ($action !== 'plugin_information') {
      return $result;
    }
    if (($args->slug ?? '') !== $this->pluginSlug()) {
      return $result;
    }

    $info = $this->fetchUpdateInfo();
    if ($info === null || empty($info['version'])) {
      return $result;
    }

    return (object) [
      'name'              => 'Nimbus',
      'slug'              => $this->pluginSlug(),
      'version'           => $info['version'],
      'author'            => '<a href="https://usenimbus.dev">Nimbus</a>',
      'homepage'          => 'https://usenimbus.dev',
      'requires'          => $info['min_wp_version'] ?? '6.0',
      'requires_php'      => $info['min_php_version'] ?? '8.0',
      'tested'            => $info['tested_up_to'] ?? '',
      'sections'          => [
        'changelog' => $this->markdownToHtml((string) ($info['changelog'] ?? '')),
        'description' => '<p>Nimbus offloads your WordPress media library to Amazon S3, Cloudflare R2, or any S3-compatible storage.</p>',
      ],
      'download_link'     => $info['download_url'] ?? '',
    ];
  }

  /**
   * Clear the cached update info after a successful plugin upgrade so the
   * next check reflects the freshly installed version.
   *
   * @param \WP_Upgrader $upgrader The upgrader instance.
   * @param array        $hookExtra Information about the upgrade action.
   */
  public function purgeUpdateCache(\WP_Upgrader $upgrader, array $hookExtra): void
  {
    if (
      ($hookExtra['action'] ?? '') === 'update' &&
      ($hookExtra['type'] ?? '') === 'plugin' &&
      in_array($this->pluginBasename, (array) ($hookExtra['plugins'] ?? []), strict: true)
    ) {
      delete_transient($this->cacheKey());
    }
  }

  // ── Remote fetch ────────────────────────────────────────────────────────────

  /**
   * Fetch update info from the backend, honouring the local cache.
   *
   * Returns the decoded JSON body on success, or null if the license has no
   * token yet (not activated) or if the HTTP request fails.
   *
   * @return array<string,mixed>|null
   */
  private function fetchUpdateInfo(): ?array
  {
    // Only poll when the site has an active activation token.
    $token = $this->licenseManager->token();
    if ($token === '') {
      return null;
    }

    $cacheKey = $this->cacheKey();
    $cached   = get_transient($cacheKey);
    if (is_array($cached)) {
      return $cached;
    }

    $url = $this->baseUrl() . '/v1/updates/check?' . http_build_query([
      'version' => defined('NIMBUS_VERSION') ? NIMBUS_VERSION : '0.0.0',
    ]);

    $response = wp_remote_get($url, [
      'timeout'   => 10,
      'sslverify' => true,
      'headers'   => [
        'Accept'        => 'application/json',
        'Authorization' => 'Bearer ' . $token,
      ],
    ]);

    if (is_wp_error($response)) {
      return null;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
      return null;
    }

    $body = json_decode((string) wp_remote_retrieve_body($response), associative: true);
    if (!is_array($body)) {
      return null;
    }

    // Cache both "update available" and "no update" responses to avoid
    // constant polling. The cache is cleared after a successful upgrade.
    set_transient($cacheKey, $body, self::CACHE_TTL);

    return $body;
  }

  // ── Object builders ─────────────────────────────────────────────────────────

  /** Build the stdClass that WordPress expects in $transient->response. */
  private function buildUpdateObject(array $info, string $currentVersion): \stdClass
  {
    return (object) [
      'id'            => 'nimbus/' . $this->pluginSlug(),
      'slug'          => $this->pluginSlug(),
      'plugin'        => $this->pluginBasename,
      'new_version'   => $info['version'],
      'url'           => 'https://usenimbus.dev',
      'package'       => $info['download_url'] ?? '',
      'icons'         => [],
      'banners'       => [],
      'banners_rtl'   => [],
      'tested'        => $info['tested_up_to'] ?? '',
      'requires_php'  => $info['min_php_version'] ?? '8.0',
      'compatibility' => new \stdClass(),
    ];
  }

  /** Build the stdClass that WordPress expects in $transient->no_update. */
  private function buildNoUpdateObject(string $currentVersion): \stdClass
  {
    return (object) [
      'id'          => 'nimbus/' . $this->pluginSlug(),
      'slug'        => $this->pluginSlug(),
      'plugin'      => $this->pluginBasename,
      'new_version' => $currentVersion,
      'url'         => 'https://usenimbus.dev',
      'package'     => '',
      'icons'       => [],
      'banners'     => [],
      'banners_rtl' => [],
    ];
  }

  // ── Renewal notice ──────────────────────────────────────────────────────────

  /**
   * Store a flag so the admin notice system can show a "renew your license"
   * message once per day without this class knowing about the UI layer.
   */
  private function scheduleRenewalNotice(): void
  {
    $flagKey = 'nimbus_renewal_notice_flag';
    if (!get_transient($flagKey)) {
      set_transient($flagKey, true, DAY_IN_SECONDS);
      update_option('nimbus_renewal_notice_pending', true, autoload: false);
    }
  }

  // ── Helpers ─────────────────────────────────────────────────────────────────

  /** The directory slug of the plugin (parent folder of the main file). */
  private function pluginSlug(): string
  {
    return explode('/', $this->pluginBasename)[0];
  }

  /** Cache key unique per-site (hashed from token so tokens change = new check). */
  private function cacheKey(): string
  {
    $token = $this->licenseManager->token();
    return 'nimbus_update_check_' . substr(md5($token . NIMBUS_VERSION), 0, 12);
  }

  private function baseUrl(): string
  {
    return defined('NIMBUS_BACKEND_URL')
      ? rtrim((string) NIMBUS_BACKEND_URL, '/')
      : 'https://api.usenimbus.dev';
  }

  /**
   * Convert a minimal subset of Markdown to HTML for the changelog section.
   * Only handles headings, bold, and line breaks — enough for version notes.
   */
  private function markdownToHtml(string $markdown): string
  {
    if ($markdown === '') {
      return '';
    }
    $html = esc_html($markdown);
    // ### Heading → <h4>
    $html = preg_replace('/^###\s+(.+)$/m', '<h4>$1</h4>', $html) ?? $html;
    // ## Heading → <h3>
    $html = preg_replace('/^##\s+(.+)$/m', '<h3>$1</h3>', $html) ?? $html;
    // # Heading → <h3>
    $html = preg_replace('/^#\s+(.+)$/m', '<h3>$1</h3>', $html) ?? $html;
    // **bold**
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html) ?? $html;
    // line break
    $html = nl2br($html);
    return $html;
  }
}
