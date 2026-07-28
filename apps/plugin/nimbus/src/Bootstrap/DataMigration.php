<?php
namespace Nimbus\Plugin\Bootstrap;

if (!defined('ABSPATH')) {
  exit;
}

final class DataMigration
{
  private const VERSION = 1;

  private const ATTACHMENT_BATCH_SIZE = 25;

  private const CRON_HOOK = 'nimbus_data_migration_batch';

  public static function register(): void
  {
    add_action(self::CRON_HOOK, [self::class, 'run']);
  }

  public static function status(): array
  {
    $currentVersion = (int) get_option(NIMBUS_DATA_VERSION_OPTION_KEY, 0);
    $legacyMetaKeys = self::existingLegacyMetaKeys([
      '_pt_media_offload',
      '_pt_media_offload_ignore',
      '_pt_media_offload_pending_at',
      '_pt_media_offload_claimed_by',
      '_pt_media_offload_claimed_at',
      '_pt_media_offload_missing_source_at',
    ]);
    $legacyOptions = self::existingLegacyOptions([
      'pt_media_offload_settings',
      'pt_media_offload_logs',
      'pt_media_offload_admin_notices',
    ]);
    $hasLegacyAttachmentPayloads = self::hasLegacyAttachmentMetadataPayloads();
    $scheduled = (bool) wp_next_scheduled(self::CRON_HOOK);
    $needsMigration = $currentVersion < self::VERSION && (!empty($legacyOptions) || !empty($legacyMetaKeys) || $hasLegacyAttachmentPayloads);

    return [
      'current_version' => $currentVersion,
      'target_version' => self::VERSION,
      'needs_migration' => $needsMigration,
      'scheduled' => $scheduled,
      'legacy_options' => $legacyOptions,
      'legacy_meta_keys' => $legacyMetaKeys,
      'has_legacy_attachment_payloads' => $hasLegacyAttachmentPayloads,
    ];
  }

  public static function resume(): array
  {
    self::run();
    return self::status();
  }

  public static function run(): void
  {
    $currentVersion = (int) get_option(NIMBUS_DATA_VERSION_OPTION_KEY, 0);
    if ($currentVersion >= self::VERSION) {
      return;
    }

    self::migrateOptions();
    self::migratePostMetaKeys();
    $hasRemainingAttachmentPayloads = self::migrateAttachmentMetadataPayloads();
    self::clearLegacySchedules();

    if ($hasRemainingAttachmentPayloads) {
      self::scheduleFollowUpMigration();
      return;
    }

    update_option(NIMBUS_DATA_VERSION_OPTION_KEY, self::VERSION, false);
    self::clearScheduledFollowUp();
  }

  private static function migrateOptions(): void
  {
    self::migrateOption('pt_media_offload_settings', NIMBUS_OPTION_KEY);
    self::migrateOption('pt_media_offload_logs', NIMBUS_LOG_OPTION_KEY);
    self::migrateOption('pt_media_offload_admin_notices', NIMBUS_ADMIN_NOTICES_OPTION_KEY);
  }

  private static function migrateOption(string $legacyKey, string $newKey): void
  {
    $newValue = get_option($newKey, null);
    if ($newValue !== null) {
      delete_option($legacyKey);
      return;
    }

    $legacyValue = get_option($legacyKey, null);
    if ($legacyValue === null) {
      return;
    }

    add_option($newKey, $legacyValue, '', false);
    delete_option($legacyKey);
  }

  private static function migratePostMetaKeys(): void
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return;
    }

    $mappings = [
      '_pt_media_offload' => NIMBUS_META_KEY,
      '_pt_media_offload_ignore' => NIMBUS_IGNORE_META_KEY,
      '_pt_media_offload_pending_at' => NIMBUS_PENDING_AT_META_KEY,
      '_pt_media_offload_claimed_by' => NIMBUS_CLAIMED_BY_META_KEY,
      '_pt_media_offload_claimed_at' => NIMBUS_CLAIMED_AT_META_KEY,
      '_pt_media_offload_missing_source_at' => NIMBUS_MISSING_SOURCE_AT_META_KEY,
    ];

    $legacyKeys = array_keys($mappings);
    if (empty(self::existingLegacyMetaKeys($legacyKeys))) {
      return;
    }

    foreach ($mappings as $legacyKey => $newKey) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time legacy-key migration on plugin upgrade; not a repeated read path, nothing to cache.
      $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
        $newKey,
        $legacyKey
      ));
    }
  }

  private static function migrateAttachmentMetadataPayloads(): bool
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time legacy-data migration scan on plugin upgrade; must read current state live, nothing to cache.
    $attachmentIds = $wpdb->get_col($wpdb->prepare(
      "SELECT post_id
       FROM {$wpdb->postmeta}
       WHERE meta_key = %s
         AND meta_value LIKE %s
       ORDER BY post_id ASC
       LIMIT %d",
      '_wp_attachment_metadata',
      '%pt_media_offload%',
      self::ATTACHMENT_BATCH_SIZE
    ));

    if (!is_array($attachmentIds) || empty($attachmentIds)) {
      return false;
    }

    foreach (array_map('intval', $attachmentIds) as $attachmentId) {
      $metadata = get_post_meta($attachmentId, '_wp_attachment_metadata', true);
      if (!is_array($metadata)) {
        continue;
      }

      $updated = false;
      if (isset($metadata['pt_media_offload']) && !isset($metadata['nimbus'])) {
        $metadata['nimbus'] = $metadata['pt_media_offload'];
        unset($metadata['pt_media_offload']);
        $updated = true;
      }

      if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size => $data) {
          if (!is_array($data)) {
            continue;
          }

          if (isset($data['pt_media_offload']) && !isset($data['nimbus'])) {
            $metadata['sizes'][$size]['nimbus'] = $data['pt_media_offload'];
            unset($metadata['sizes'][$size]['pt_media_offload']);
            $updated = true;
          }
        }
      }

      if ($updated) {
        update_post_meta($attachmentId, '_wp_attachment_metadata', $metadata);
      }
    }

    return count($attachmentIds) >= self::ATTACHMENT_BATCH_SIZE || self::hasLegacyAttachmentMetadataPayloads();
  }

  private static function clearLegacySchedules(): void
  {
    $hooks = [
      'pt_media_offload_nightly_batch',
      'pt_media_offload_nightly_worker',
      'pt_media_offload_cleanup_attachment',
    ];

    foreach ($hooks as $hook) {
      if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook($hook);
      }
    }
  }

  private static function existingLegacyMetaKeys(array $legacyKeys): array
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb) || empty($legacyKeys)) {
      return [];
    }

    $placeholders = implode(', ', array_fill(0, count($legacyKeys), '%s'));
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is $wpdb->prefix + a hardcoded suffix (never user input); values bound via $wpdb->prepare(). Placeholders are built dynamically (one %s per key) so the static sniff can't count them; the runtime count always matches. One-time legacy-key check on plugin upgrade, nothing to cache.
    $sql = $wpdb->prepare(
      "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
      ...$legacyKeys
    );

    $results = $wpdb->get_col($sql);
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    return is_array($results) ? array_map('strval', $results) : [];
  }

  private static function existingLegacyOptions(array $legacyOptions): array
  {
    $found = [];

    foreach ($legacyOptions as $optionKey) {
      if (get_option($optionKey, null) !== null) {
        $found[] = $optionKey;
      }
    }

    return $found;
  }

  private static function hasLegacyAttachmentMetadataPayloads(): bool
  {
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
      return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time legacy-data check on plugin upgrade; must read current state live, nothing to cache.
    $postId = $wpdb->get_var($wpdb->prepare(
      "SELECT post_id
       FROM {$wpdb->postmeta}
       WHERE meta_key = %s
         AND meta_value LIKE %s
       LIMIT 1",
      '_wp_attachment_metadata',
      '%pt_media_offload%'
    ));

    return !empty($postId);
  }

  private static function scheduleFollowUpMigration(): void
  {
    if (wp_next_scheduled(self::CRON_HOOK)) {
      return;
    }

    wp_schedule_single_event(time() + 15, self::CRON_HOOK);
  }

  private static function clearScheduledFollowUp(): void
  {
    if (function_exists('wp_clear_scheduled_hook')) {
      wp_clear_scheduled_hook(self::CRON_HOOK);
      return;
    }

    $next = wp_next_scheduled(self::CRON_HOOK);
    while ($next) {
      wp_unschedule_event($next, self::CRON_HOOK);
      $next = wp_next_scheduled(self::CRON_HOOK);
    }
  }
}