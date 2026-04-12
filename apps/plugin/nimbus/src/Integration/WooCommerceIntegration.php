<?php
namespace Nimbus\Plugin\Integration;

use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * WooCommerce protected file delivery via signed (pre-signed) URLs.
 *
 * When WooCommerce serves a downloadable product whose file is stored in a
 * remote storage provider (e.g. S3, R2, GCS), the default behaviour is to
 * proxy the file through WordPress — consuming server bandwidth and being slow.
 *
 * This integration hooks `woocommerce_download_url` and replaces the internal
 * WP URL with a provider-signed URL that redirects the buyer directly to the
 * storage provider, bypassing the web server entirely.
 *
 * The filter is only applied when:
 *  1. The attachment has been offloaded (has `_nimbus` postmeta with a `key`).
 *  2. The active storage adapter exposes a `presign(string $key, int $ttl): string` method.
 *
 * Signed URLs expire after one hour by default. Adjust via the
 * `nimbus/woocommerce/presign_ttl` filter (in seconds).
 */
final class WooCommerceIntegration
{
  public function __construct(
    private UploadHandler $uploadHandler,
    private Settings $settings,
    private Logger $logger,
  ) {
  }

  public function register(): void
  {
    // WooCommerce resolves the download URL just before streaming starts.
    // Priority 20 to run after WooCommerce's own redirects are in place.
    add_filter('woocommerce_download_url', [$this, 'rewriteDownloadUrl'], 20, 2);
  }

  /**
   * Replace the local WP download URL with a signed provider URL.
   *
   * @param string $url        The original WooCommerce download URL.
   * @param string $downloadId The WooCommerce download ID.
   * @return string            The possibly-replaced URL.
   */
  public function rewriteDownloadUrl(string $url, string $downloadId): string
  {
    if (!$this->settings->has_valid_credentials()) {
      return $url;
    }

    $adapter = $this->uploadHandler->getAdapter();
    if (!method_exists($adapter, 'presign')) {
      return $url;
    }

    // Resolve the attachment ID from the download URL.
    $attachmentId = $this->resolveAttachmentId($url);
    if ($attachmentId <= 0) {
      return $url;
    }

    $meta = get_post_meta($attachmentId, NIMBUS_META_KEY, true);
    if (!is_array($meta) || empty($meta['key'])) {
      return $url;
    }

    /** @var positive-int $ttl */
    $ttl = max(60, (int) apply_filters('nimbus/woocommerce/presign_ttl', 3600));

    try {
      return $adapter->presign((string) $meta['key'], $ttl);
    } catch (\Throwable $e) {
      $this->logger->error('WooCommerce presign failed for attachment {id}: {error}', [
        'id' => $attachmentId,
        'error' => $e->getMessage(),
      ]);
      return $url;
    }
  }

  /**
   * Try to derive an attachment post ID from a URL.
   * Uses attachment_url_to_postid() to cover both local and CDN URL forms.
   */
  private function resolveAttachmentId(string $url): int
  {
    if ($url === '') {
      return 0;
    }

    $id = (int) attachment_url_to_postid($url);
    if ($id > 0) {
      return $id;
    }

    // Fallback: strip the query string and try again (download URLs often carry nonces).
    $cleanUrl = strtok($url, '?');
    if (is_string($cleanUrl) && $cleanUrl !== $url) {
      $id = (int) attachment_url_to_postid($cleanUrl);
    }

    return $id;
  }
}
