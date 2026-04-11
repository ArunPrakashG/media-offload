<?php
namespace Nimbus\Plugin\Stats;

use Nimbus\Plugin\Storage\StorageAdapterFactory;

if (!defined('ABSPATH')) {
  exit;
}

final class Statistics
{
  private const COST_RATES = [
    'amazon_s3_standard' => 0.023,
    'cloudflare_r2' => 0.015,
  ];

  public function __construct(private ?StorageAdapterFactory $factory = null)
  {
  }

  public function getRemoteStats(): array
  {
    if (!$this->factory) {
      return [];
    }

    try {
      $adapter = $this->factory->getAdapter();
      if (method_exists($adapter, 'getUsageStatistics')) {
        return $adapter->getUsageStatistics();
      }
    } catch (\Throwable $exception) {
      // Adapter might not be configured.
    }

    return [];
  }

  public function get_remote_stats(): array
  {
    return $this->getRemoteStats();
  }

  public function getStats(): array
  {
    global $wpdb;

    $ignoreMetaKey = esc_sql((string) NIMBUS_IGNORE_META_KEY);

    // All counts are per original upload (one WordPress attachment post = one
    // original upload, regardless of how many image-size variants WP generated
    // on disk or in the bucket).
    $totalAttachments    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'");
    $offloadedAttachments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '" . NIMBUS_META_KEY . "'");
    $ignoredAttachments  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '" . $ignoreMetaKey . "' AND meta_value = '1'");

    $pendingAttachments = max(0, $totalAttachments - $offloadedAttachments - $ignoredAttachments);
    $dailyUploads = $wpdb->get_results(
      "SELECT DATE(post_date) as date, COUNT(*) as count
       FROM {$wpdb->posts}
       WHERE post_type = 'attachment'
         AND post_status = 'inherit'
         AND post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
       GROUP BY DATE(post_date)
       ORDER BY date ASC"
    );

    return [
      'total'      => $totalAttachments,
      'offloaded'  => $offloadedAttachments,
      'ignored'    => $ignoredAttachments,
      'pending'    => $pendingAttachments,
      'percent'    => $totalAttachments > 0 ? round(($offloadedAttachments / $totalAttachments) * 100, 1) : 0,
      'daily'      => $dailyUploads,
      // Clarifies the counting unit for API consumers and the admin UI.
      'count_unit' => 'original_upload',
    ];
  }

  public function get_stats(): array
  {
    return $this->getStats();
  }

  public function getDiskUsageEstimate(): array
  {
    global $wpdb;

    // Sample up to 200 attachment metadata rows.  For each attachment we sum
    // the main file's filesize AND the filesize of every image-size variant
    // that WordPress stored (WP 5.3+ includes 'filesize' per size).  This
    // gives an accurate per-attachment total that reflects all the physical
    // files that would have been — or have been — uploaded to the bucket.
    $sampleMeta  = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' LIMIT 200");
    $totalBytes  = 0;
    $sampleCount = 0;
    $totalVariantsObserved = 0;
    $attachmentsWithVariants = 0;

    foreach ($sampleMeta as $raw) {
      $data = maybe_unserialize($raw);
      if (!is_array($data)) {
        continue;
      }

      $attachmentBytes = isset($data['filesize']) ? (int) $data['filesize'] : 0;
      $variantCount    = 0;

      if (!empty($data['sizes']) && is_array($data['sizes'])) {
        foreach ($data['sizes'] as $sizeData) {
          if (!is_array($sizeData)) {
            continue;
          }
          // WP 5.3+ stores filesize per size; fall back to 0 if absent.
          $attachmentBytes += isset($sizeData['filesize']) ? (int) $sizeData['filesize'] : 0;
          $variantCount++;
        }
      }

      if ($attachmentBytes > 0) {
        $totalBytes += $attachmentBytes;
        $sampleCount++;
      }

      if ($variantCount > 0) {
        $totalVariantsObserved += $variantCount;
        $attachmentsWithVariants++;
      }
    }

    // Average bytes across ALL physical files (original + all size variants)
    // for a single original upload.
    $avgBytesPerUpload = $sampleCount > 0 ? intdiv($totalBytes, $sampleCount) : 200000;

    // Average number of size variants per upload (used informatively in UI).
    $avgVariants = $attachmentsWithVariants > 0
      ? round($totalVariantsObserved / $attachmentsWithVariants, 1)
      : 0.0;

    $stats = $this->getStats();

    return [
      'offloaded_size'      => $stats['offloaded'] * $avgBytesPerUpload,
      'pending_size'        => $stats['pending'] * $avgBytesPerUpload,
      'total_size'          => $stats['total'] * $avgBytesPerUpload,
      // Exposes both parts of the estimate so the UI can explain it clearly.
      'avg_bytes_per_upload' => $avgBytesPerUpload,
      'avg_size_variants'    => $avgVariants,
      'sizes_included'       => true,
    ];
  }

  public function get_disk_usage_estimate(): array
  {
    return $this->getDiskUsageEstimate();
  }

  public function getDeepStats(): array
  {
    $summary = $this->getStats();
    $usage = $this->getDiskUsageEstimate();
    $remote = $this->getRemoteStats();
    $storageBytes = isset($remote['size_bytes']) ? (int) $remote['size_bytes'] : (int) ($usage['offloaded_size'] ?? 0);

    return [
      'summary' => $summary,
      'usage' => $usage,
      'remote' => $remote,
      'mime_breakdown' => $this->getMimeBreakdown(),
      'monthly_breakdown' => $this->getMonthlyBreakdown(),
      'asset_roots' => $this->getAttachmentRoots(),
      'cost_comparison' => $this->getCostComparison($storageBytes),
    ];
  }

  public function get_deep_stats(): array
  {
    return $this->getDeepStats();
  }

  private function getMimeBreakdown(): array
  {
    global $wpdb;

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT p.post_mime_type AS mime_type,
                COUNT(*) AS total,
                SUM(CASE WHEN pm.meta_id IS NOT NULL THEN 1 ELSE 0 END) AS offloaded
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm
           ON pm.post_id = p.ID
          AND pm.meta_key = %s
         WHERE p.post_type = %s
           AND p.post_status = %s
         GROUP BY p.post_mime_type
         ORDER BY total DESC",
        NIMBUS_META_KEY,
        'attachment',
        'inherit'
      ),
      ARRAY_A
    );

    if (!is_array($rows)) {
      return [];
    }

    $breakdown = [];
    foreach ($rows as $row) {
      $mimeType = (string) ($row['mime_type'] ?? 'unknown/unknown');
      $category = strtok($mimeType, '/');
      $category = $category !== false && $category !== '' ? $category : 'other';

      if (!isset($breakdown[$category])) {
        $breakdown[$category] = ['category' => $category, 'total' => 0, 'offloaded' => 0];
      }

      $breakdown[$category]['total'] += (int) ($row['total'] ?? 0);
      $breakdown[$category]['offloaded'] += (int) ($row['offloaded'] ?? 0);
    }

    foreach ($breakdown as &$item) {
      $item['pending'] = max(0, $item['total'] - $item['offloaded']);
    }
    unset($item);

    return array_values($breakdown);
  }

  private function getMonthlyBreakdown(): array
  {
    global $wpdb;

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT DATE_FORMAT(p.post_date, '%%Y-%%m') AS month,
                COUNT(*) AS total,
                SUM(CASE WHEN pm.meta_id IS NOT NULL THEN 1 ELSE 0 END) AS offloaded
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm
           ON pm.post_id = p.ID
          AND pm.meta_key = %s
         WHERE p.post_type = %s
           AND p.post_status = %s
         GROUP BY DATE_FORMAT(p.post_date, '%%Y-%%m')
         ORDER BY month DESC
         LIMIT 6",
        NIMBUS_META_KEY,
        'attachment',
        'inherit'
      ),
      ARRAY_A
    );

    if (!is_array($rows)) {
      return [];
    }

    return array_map(static function (array $row): array {
      $total = (int) ($row['total'] ?? 0);
      $offloaded = (int) ($row['offloaded'] ?? 0);

      return [
        'month' => (string) ($row['month'] ?? ''),
        'total' => $total,
        'offloaded' => $offloaded,
        'pending' => max(0, $total - $offloaded),
      ];
    }, array_reverse($rows));
  }

  private function getAttachmentRoots(): array
  {
    global $wpdb;

    $rows = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 5000", '_wp_attached_file'));
    if (!is_array($rows)) {
      return [];
    }

    $roots = [];
    foreach ($rows as $path) {
      $path = ltrim((string) $path, '/');
      if ($path === '') {
        continue;
      }

      $segments = explode('/', $path);
      $root = $segments[0] ?? '';
      if ($root === '') {
        continue;
      }

      if (preg_match('/^\d{4}$/', $root)) {
        $root = '{year}';
      }

      $roots[$root] = ($roots[$root] ?? 0) + 1;
    }

    arsort($roots);

    $normalized = [];
    foreach (array_slice($roots, 0, 10, true) as $root => $count) {
      $normalized[] = ['root' => (string) $root, 'count' => (int) $count];
    }

    return $normalized;
  }

  private function getCostComparison(int $storageBytes): array
  {
    $storageGb = max(0, $storageBytes) / 1073741824;
    $scenarios = [];

    foreach (self::COST_RATES as $provider => $ratePerGb) {
      $scenarios[] = [
        'provider' => $provider,
        'rate_per_gb' => $ratePerGb,
        'estimated_monthly_cost' => round($storageGb * $ratePerGb, 2),
      ];
    }

    return [
      'storage_bytes' => $storageBytes,
      'storage_gb' => round($storageGb, 3),
      'currency' => 'USD',
      'note' => 'Illustrative storage-only estimate. Request, bandwidth, and CDN fees vary by provider.',
      'scenarios' => $scenarios,
    ];
  }
}