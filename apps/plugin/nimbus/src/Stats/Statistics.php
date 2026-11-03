<?php
namespace Nimbus\Plugin\Stats;

use Nimbus\Plugin\Storage\StorageAdapterFactory;

if (!defined('ABSPATH')) {
  exit;
}

final class Statistics
{
  /**
   * Per-provider pricing data sourced from provider websites (2025/2026 rates).
   * Storage in USD/GB/month, egress in USD/GB, requests in USD/1k ops.
   * null values indicate unknown/custom pricing.
   */
  private const PROVIDER_PRICING = [
    's3' => [
      'label'           => 'Amazon S3 Standard',
      'storage_per_gb'  => 0.023,    // First 50 TB/month, US-East
      'egress_per_gb'   => 0.090,    // To internet, first 10 TB/month
      'put_per_1k'      => 0.005,    // PUT/COPY/POST/LIST ops
      'get_per_1k'      => 0.0004,   // GET and other ops
      'flat_monthly'    => 0.0,
      'free_storage_gb' => 5.0,      // Free tier (12-month new accounts)
      'free_egress_gb'  => 0.0,
      'notes'           => 'First 50 TB/mo storage, US-East-1. Free tier (12 mo): 5 GB, 20 k GET, 2 k PUT.',
      'pricing_url'     => 'https://aws.amazon.com/s3/pricing/',
    ],
    'r2' => [
      'label'           => 'Cloudflare R2',
      'storage_per_gb'  => 0.015,
      'egress_per_gb'   => 0.000,    // Zero egress fees
      'put_per_1k'      => 0.0045,   // Class A ops ($4.50/million)
      'get_per_1k'      => 0.00036,  // Class B ops ($0.36/million)
      'flat_monthly'    => 0.0,
      'free_storage_gb' => 10.0,
      'free_egress_gb'  => 999999.0, // Unlimited egress
      'notes'           => 'Zero egress fees. Free/mo: 10 GB storage, 1 M Class A ops, 10 M Class B ops.',
      // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A link to Cloudflare's pricing page, not a loaded asset.
      'pricing_url'     => 'https://developers.cloudflare.com/r2/pricing/',
    ],
    'digitalocean_spaces' => [
      'label'           => 'DigitalOcean Spaces',
      'storage_per_gb'  => 0.020,    // Overage beyond 250 GiB included
      'egress_per_gb'   => 0.010,    // Overage beyond 1 TiB included
      'put_per_1k'      => 0.000,    // No request fees
      'get_per_1k'      => 0.000,
      'flat_monthly'    => 5.0,      // $5/mo per bucket (includes 250 GiB + 1 TiB)
      'free_storage_gb' => 250.0,    // Included in flat fee
      'free_egress_gb'  => 1024.0,   // 1 TiB included in flat fee
      'notes'           => '$5/mo includes 250 GiB storage + 1 TiB transfer. No request fees.',
      'pricing_url'     => 'https://www.digitalocean.com/pricing/spaces-object-storage',
    ],
    'wasabi' => [
      'label'           => 'Wasabi Hot Cloud Storage',
      'storage_per_gb'  => 0.0068,   // $6.99/TB ≈ $0.0068/GB, US-East-1
      'egress_per_gb'   => 0.000,    // No egress fees
      'put_per_1k'      => 0.000,    // No request fees
      'get_per_1k'      => 0.000,
      'flat_monthly'    => 0.0,
      'free_storage_gb' => 0.0,
      'free_egress_gb'  => 999999.0, // Unlimited egress
      'notes'           => 'No egress or API request fees. 90-day minimum storage per object.',
      'pricing_url'     => 'https://wasabi.com/pricing/',
    ],
    'backblaze_b2' => [
      'label'           => 'Backblaze B2',
      'storage_per_gb'  => 0.006,    // $6/TB/month
      'egress_per_gb'   => 0.010,    // Beyond 3× monthly storage free allowance
      'put_per_1k'      => 0.000,    // Upload (Class A) free
      'get_per_1k'      => 0.000,    // API calls free
      'flat_monthly'    => 0.0,
      'free_storage_gb' => 0.0,
      'free_egress_gb'  => 0.0,      // Special: 3× avg monthly storage free — computed dynamically
      'notes'           => 'Upload & API calls free. Free egress = 3× avg monthly storage; $0.01/GB beyond.',
      'pricing_url'     => 'https://www.backblaze.com/cloud-storage/pricing',
    ],
    'gcs' => [
      'label'           => 'Google Cloud Storage',
      'storage_per_gb'  => 0.020,    // Standard, US single-region
      'egress_per_gb'   => 0.120,    // To internet, first 10 TiB/month
      'put_per_1k'      => 0.005,    // Class A operations
      'get_per_1k'      => 0.0004,   // Class B operations
      'flat_monthly'    => 0.0,
      'free_storage_gb' => 5.0,      // Always Free: US regions only
      'free_egress_gb'  => 0.0,
      'notes'           => 'US single-region Standard. Always Free: 5 GB, 5 k Class A, 50 k Class B ops/mo.',
      'pricing_url'     => 'https://cloud.google.com/storage/pricing',
    ],
    'minio' => [
      'label'           => 'MinIO (self-hosted)',
      'storage_per_gb'  => 0.000,
      'egress_per_gb'   => 0.000,
      'put_per_1k'      => 0.000,
      'get_per_1k'      => 0.000,
      'flat_monthly'    => 0.0,
      'free_storage_gb' => 999999.0,
      'free_egress_gb'  => 999999.0,
      'notes'           => 'Self-hosted. Cloud costs are $0; infrastructure & bandwidth costs vary.',
      'pricing_url'     => null,
    ],
    's3_compatible' => [
      'label'           => 'S3-compatible (custom)',
      'storage_per_gb'  => null,     // Unknown — custom provider
      'egress_per_gb'   => null,
      'put_per_1k'      => null,
      'get_per_1k'      => null,
      'flat_monthly'    => null,
      'free_storage_gb' => 0.0,
      'free_egress_gb'  => 0.0,
      'notes'           => 'Custom provider — pricing depends on your specific vendor.',
      'pricing_url'     => null,
    ],
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

    // All counts are per original upload (one WordPress attachment post = one
    // original upload, regardless of how many image-size variants WP generated
    // on disk or in the bucket).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live dashboard/statistics aggregate; must reflect current counts immediately after an offload/restore action, so it is intentionally not cached.
    $totalAttachments = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
        'attachment',
        'inherit'
      )
    );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live dashboard/statistics aggregate; must reflect current counts immediately after an offload/restore action, so it is intentionally not cached.
    $offloadedAttachments = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
        NIMBUS_META_KEY
      )
    );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live dashboard/statistics aggregate; must reflect current counts immediately after an offload/restore action, so it is intentionally not cached.
    $ignoredAttachments = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
        NIMBUS_IGNORE_META_KEY,
        '1'
      )
    );

    $pendingAttachments = max(0, $totalAttachments - $offloadedAttachments - $ignoredAttachments);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live dashboard/statistics aggregate; must reflect current counts immediately after an offload/restore action, so it is intentionally not cached.
    $dailyUploads = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT DATE(post_date) as date, COUNT(*) as count
         FROM {$wpdb->posts}
         WHERE post_type = %s
           AND post_status = %s
           AND post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(post_date)
         ORDER BY date ASC",
        'attachment',
        'inherit'
      )
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
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live dashboard/statistics aggregate; must reflect current counts immediately after an offload/restore action, so it is intentionally not cached.
    $sampleMeta  = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 200", '_wp_attachment_metadata'));
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
      'summary'           => $summary,
      'usage'             => $usage,
      'remote'            => $remote,
      'mime_breakdown'    => $this->getMimeBreakdown(),
      'monthly_breakdown' => $this->getMonthlyBreakdown(),
      'asset_roots'       => $this->getAttachmentRoots(),
      'cost_comparison'   => $this->getCostComparison(
        $storageBytes,
        (int) ($summary['offloaded'] ?? 0),
        (int) ($usage['avg_bytes_per_upload'] ?? 200000)
      ),
    ];
  }

  public function get_deep_stats(): array
  {
    return $this->getDeepStats();
  }

  public function get_attachment_roots(): array
  {
    return $this->getAttachmentRoots();
  }

  private function getMimeBreakdown(): array
  {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live dashboard/statistics aggregate; must reflect current counts immediately after an offload/restore action, so it is intentionally not cached.
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

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live dashboard/statistics aggregate; must reflect current counts immediately after an offload/restore action, so it is intentionally not cached.
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

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live dashboard/statistics aggregate; must reflect current counts immediately after an offload/restore action, so it is intentionally not cached.
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

  /**
   * Compute a full cost comparison across all supported providers.
   *
   * Monthly traffic assumptions:
   *  - GET requests: ~10 per offloaded item/month (typical media site serving)
   *  - PUT requests: ~1% of library re-uploaded per month (new uploads estimate)
   *  - Egress:       GET requests × avg file size
   *
   * @param int $storageBytes  Total bytes currently stored remotely.
   * @param int $offloadedCount Number of offloaded attachment originals.
   * @param int $avgBytesPerUpload Average bytes per upload (incl. size variants).
   */
  private function getCostComparison(int $storageBytes, int $offloadedCount, int $avgBytesPerUpload): array
  {
    $storageGb = max(0.0, $storageBytes / 1073741824);

    // Monthly traffic estimates
    $monthlyGets    = $offloadedCount * 10;
    $monthlyPuts    = max(1, (int) round($offloadedCount * 0.01));
    $monthlyEgressGb = ($monthlyGets * max(1, $avgBytesPerUpload)) / 1073741824;

    $scenarios = [];

    foreach (self::PROVIDER_PRICING as $key => $p) {
      // Unknown/custom provider — cannot estimate
      if ($p['storage_per_gb'] === null) {
        $scenarios[] = [
          'provider_key'            => $key,
          'label'                   => $p['label'],
          'storage_cost'            => null,
          'egress_cost'             => null,
          'request_cost'            => null,
          'flat_monthly'            => null,
          'estimated_monthly_total' => null,
          'breakdown'               => null,
          'notes'                   => $p['notes'],
          'pricing_url'             => $p['pricing_url'],
        ];
        continue;
      }

      // Backblaze B2 special: free egress = 3× current monthly storage
      $freeEgressGb = ($key === 'backblaze_b2')
        ? min(3.0 * $storageGb, $monthlyEgressGb)
        : (float) $p['free_egress_gb'];

      $billableStorageGb = max(0.0, $storageGb - (float) $p['free_storage_gb']);
      $billableEgressGb  = max(0.0, $monthlyEgressGb - $freeEgressGb);

      $storageCost = $billableStorageGb * (float) $p['storage_per_gb'];
      $egressCost  = $billableEgressGb  * (float) $p['egress_per_gb'];
      $getCost     = ($monthlyGets / 1000.0)  * (float) $p['get_per_1k'];
      $putCost     = ($monthlyPuts / 1000.0)  * (float) $p['put_per_1k'];
      $flatCost    = (float) $p['flat_monthly'];

      $total = $storageCost + $egressCost + $getCost + $putCost + $flatCost;

      $scenarios[] = [
        'provider_key'            => $key,
        'label'                   => $p['label'],
        'storage_cost'            => round($storageCost, 2),
        'egress_cost'             => round($egressCost, 2),
        'request_cost'            => round($getCost + $putCost, 2),
        'flat_monthly'            => round($flatCost, 2),
        'estimated_monthly_total' => round($total, 2),
        'breakdown' => [
          'storage_gb'      => round($storageGb, 3),
          'egress_gb'       => round($monthlyEgressGb, 3),
          'monthly_gets'    => $monthlyGets,
          'monthly_puts'    => $monthlyPuts,
          'free_egress_gb'  => round($freeEgressGb, 3),
        ],
        'notes'       => $p['notes'],
        'pricing_url' => $p['pricing_url'],
      ];
    }

    // Sort by estimated monthly total ascending (nulls last)
    usort($scenarios, static function (array $a, array $b): int {
      if ($a['estimated_monthly_total'] === null && $b['estimated_monthly_total'] === null) {
        return 0;
      }
      if ($a['estimated_monthly_total'] === null) {
        return 1;
      }
      if ($b['estimated_monthly_total'] === null) {
        return -1;
      }
      return $a['estimated_monthly_total'] <=> $b['estimated_monthly_total'];
    });

    return [
      'storage_bytes'    => $storageBytes,
      'storage_gb'       => round($storageGb, 3),
      'offloaded_count'  => $offloadedCount,
      'avg_file_mb'      => round(max(0.0001, $avgBytesPerUpload / 1048576), 2),
      'monthly_gets_est' => $monthlyGets,
      'monthly_puts_est' => $monthlyPuts,
      'egress_gb_est'    => round($monthlyEgressGb, 3),
      'currency'         => 'USD',
      'estimation_note'  => 'Assumes ~10 GET requests/file/month and ~1% new uploads/month. Prices from provider websites; verify for accurate billing.',
      'scenarios'        => $scenarios,
    ];
  }
}