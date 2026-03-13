<?php
namespace ArunPrakash\MediaOffload\Onboarding;

if (!defined('ABSPATH')) {
  exit;
}

final class ConflictDetector
{
  private const KNOWN_CONFLICTS = [
    'amazon-s3-and-cloudfront/wp-offload-media-lite.php' => [
      'label' => 'WP Offload Media Lite',
      'reason' => 'Also offloads WordPress media and rewrites attachment URLs.',
      'severity' => 'high',
    ],
    'amazon-s3-and-cloudfront-pro/wp-offload-media-pro.php' => [
      'label' => 'WP Offload Media',
      'reason' => 'Also offloads WordPress media and rewrites attachment URLs.',
      'severity' => 'high',
    ],
    'media-cloud/media-cloud.php' => [
      'label' => 'Media Cloud',
      'reason' => 'Uses object storage and URL rewriting for the same media library.',
      'severity' => 'high',
    ],
    'ilab-media-tools/ilab-media-tools.php' => [
      'label' => 'Media Cloud / Ilab Media Tools',
      'reason' => 'Uses object storage and delivery rewrites for media files.',
      'severity' => 'high',
    ],
    'wp-stateless/wp-stateless-media.php' => [
      'label' => 'WP-Stateless',
      'reason' => 'Maintains media in cloud storage and can rewrite public URLs.',
      'severity' => 'high',
    ],
    's3-uploads/s3-uploads.php' => [
      'label' => 'S3 Uploads',
      'reason' => 'Intercepts uploads and serves media from S3-compatible storage.',
      'severity' => 'medium',
    ],
    'infinite-uploads/infinite-uploads.php' => [
      'label' => 'Infinite Uploads',
      'reason' => 'Replaces local media storage with remote object storage.',
      'severity' => 'medium',
    ],
  ];

  public function detectSimilarPlugins(): array
  {
    $this->ensurePluginFunctions();

    if (!function_exists('get_plugins')) {
      return [];
    }

    $installed = get_plugins();
    $active = array_map('strval', (array) get_option('active_plugins', []));
    $detected = [];

    foreach (self::KNOWN_CONFLICTS as $pluginFile => $definition) {
      if (!isset($installed[$pluginFile])) {
        continue;
      }

      $plugin = $installed[$pluginFile];
      $detected[] = [
        'file' => $pluginFile,
        'name' => (string) ($definition['label'] ?? ($plugin['Name'] ?? $pluginFile)),
        'active' => in_array($pluginFile, $active, true),
        'severity' => (string) ($definition['severity'] ?? 'medium'),
        'reason' => (string) ($definition['reason'] ?? ''),
        'version' => (string) ($plugin['Version'] ?? ''),
      ];
    }

    foreach ($installed as $pluginFile => $plugin) {
      if (isset(self::KNOWN_CONFLICTS[$pluginFile])) {
        continue;
      }

      $name = strtolower((string) ($plugin['Name'] ?? ''));
      $description = strtolower((string) ($plugin['Description'] ?? ''));
      $haystack = $name . ' ' . $description;

      if (
        strpos($haystack, 'offload media') === false
        && strpos($haystack, 'object storage') === false
        && strpos($haystack, 's3 upload') === false
        && strpos($haystack, 'stateless') === false
        && strpos($haystack, 'media cloud') === false
      ) {
        continue;
      }

      $detected[] = [
        'file' => (string) $pluginFile,
        'name' => (string) ($plugin['Name'] ?? $pluginFile),
        'active' => in_array((string) $pluginFile, $active, true),
        'severity' => 'medium',
        'reason' => 'Potential overlap with media offload / URL rewriting behavior detected from plugin metadata.',
        'version' => (string) ($plugin['Version'] ?? ''),
      ];
    }

    usort($detected, static function (array $left, array $right): int {
      return strcmp($left['name'] ?? '', $right['name'] ?? '');
    });

    return array_values($detected);
  }

  private function ensurePluginFunctions(): void
  {
    if (function_exists('get_plugins')) {
      return;
    }

    $pluginFile = ABSPATH . 'wp-admin/includes/plugin.php';
    if (file_exists($pluginFile)) {
      require_once $pluginFile;
    }
  }
}
