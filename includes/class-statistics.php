<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

class Statistics
{
  private Storage_Adapter_Factory $factory;

  public function __construct(?Storage_Adapter_Factory $factory = null)
  {
    $this->factory = $factory;
  }

  public function get_remote_stats(): array
  {
    if (!$this->factory) {
      return [];
    }

    try {
      $adapter = $this->factory->get_adapter();
      if (method_exists($adapter, 'get_usage_statistics')) {
        return $adapter->get_usage_statistics();
      }
    } catch (\Exception $e) {
      // Adapter might not be configured
    }

    return [];
  }

  public function get_stats(): array
  {
    global $wpdb;

    $ignore_meta_key = esc_sql((string) PT_MEDIA_OFFLOAD_IGNORE_META_KEY);

    // Total Attachments
    $total_attachments = (int) $wpdb->get_var("
      SELECT COUNT(*) FROM {$wpdb->posts} 
      WHERE post_type = 'attachment' AND post_status = 'inherit'
    ");

    // Offloaded Attachments
    $offloaded_attachments = (int) $wpdb->get_var("
      SELECT COUNT(*) FROM {$wpdb->postmeta} 
      WHERE meta_key = '" . PT_MEDIA_OFFLOAD_META_KEY . "'
    ");

    $ignored_attachments = (int) $wpdb->get_var("
      SELECT COUNT(*)
      FROM {$wpdb->postmeta}
      WHERE meta_key = '" . $ignore_meta_key . "'
        AND meta_value = '1'
    ");

    // Pending
    $pending_attachments = max(0, $total_attachments - $offloaded_attachments - $ignored_attachments);

    // Daily Uploads (Last 7 Days)
    $daily_uploads = $wpdb->get_results("
      SELECT DATE(post_date) as date, COUNT(*) as count 
      FROM {$wpdb->posts} 
      WHERE post_type = 'attachment' 
      AND post_status = 'inherit' 
      AND post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      GROUP BY DATE(post_date)
      ORDER BY date ASC
    ");

    return [
      'total' => $total_attachments,
      'offloaded' => $offloaded_attachments,
      'ignored' => $ignored_attachments,
      'pending' => $pending_attachments,
      'percent' => $total_attachments > 0 ? round(($offloaded_attachments / $total_attachments) * 100, 1) : 0,
      'daily' => $daily_uploads,
    ];
  }

  public function get_disk_usage_estimate(): array
  {
    // This is an estimation as we can't easily sum serialized metadata in SQL
    // We assume average image size of 2MB for calculation if real data isn't available
    // In a real scenario, we might want to cache this value or run a background job to calculate it
    
    global $wpdb;
    
    // Get a sample of 100 attachments to estimate average size
    $sample_meta = $wpdb->get_col("
      SELECT meta_value FROM {$wpdb->postmeta} 
      WHERE meta_key = '_wp_attachment_metadata' 
      LIMIT 100
    ");

    $total_size_sample = 0;
    $sample_count = 0;

    foreach ($sample_meta as $meta) {
      $data = maybe_unserialize($meta);
      if (isset($data['filesize'])) {
        $total_size_sample += $data['filesize'];
        $sample_count++;
      }
    }

    $avg_size = $sample_count > 0 ? $total_size_sample / $sample_count : 200000; // Default 200KB

    $stats = $this->get_stats();
    
    return [
      'offloaded_size' => $stats['offloaded'] * $avg_size,
      'pending_size' => $stats['pending'] * $avg_size,
      'total_size' => $stats['total'] * $avg_size,
    ];
  }
}
