<?php
namespace Nimbus\Plugin\Jobs;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Manages the wp_nimbus_jobs table used to persist bulk job state.
 *
 * Using a dedicated table instead of wp_options avoids row-level locking on
 * the options table and removes the autoload overhead for large payload blobs.
 *
 * Schema
 * ------
 *   slot        VARCHAR(20)  PK — 'current' | 'last'
 *   payload     LONGTEXT     JSON-encoded job array
 *   updated_at  BIGINT       Unix timestamp of last write
 */
final class JobTable
{
  private const SCHEMA_VERSION_OPTION = 'nimbus_jobs_table_version';
  private const SCHEMA_VERSION = 1;

  public static function tableName(): string
  {
    global $wpdb;
    return $wpdb->prefix . 'nimbus_jobs';
  }

  /**
   * Create or upgrade the wp_nimbus_jobs table.
   * Safe to call on every boot — dbDelta skips unchanged tables.
   */
  public static function ensureTable(): void
  {
    $currentVersion = (int) get_option(self::SCHEMA_VERSION_OPTION, 0);
    if ($currentVersion >= self::SCHEMA_VERSION) {
      return;
    }

    global $wpdb;

    if (!function_exists('dbDelta')) {
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    $table = self::tableName();
    $charsetCollate = $wpdb->get_charset_collate();

    dbDelta("
      CREATE TABLE {$table} (
        slot varchar(20) NOT NULL,
        payload longtext NOT NULL,
        updated_at bigint unsigned NOT NULL DEFAULT '0',
        PRIMARY KEY (slot)
      ) {$charsetCollate};
    ");

    update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
  }

  /**
   * Read a job from the given slot. Returns [] if the slot is empty.
   *
   * @param 'current'|'last' $slot
   * @return array<string, mixed>
   */
  public static function get(string $slot): array
  {
    global $wpdb;

    $table = self::tableName();
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is $wpdb->prefix + a hardcoded suffix (never user input). Values are bound via $wpdb->prepare() placeholders.
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT payload FROM {$table} WHERE slot = %s LIMIT 1", $slot),
      ARRAY_A
    );
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    if (!is_array($row) || empty($row['payload'])) {
      return [];
    }

    $decoded = json_decode((string) $row['payload'], true);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Write (upsert) a job into the given slot.
   *
   * @param 'current'|'last' $slot
   * @param array<string, mixed> $job
   */
  public static function put(string $slot, array $job): void
  {
    global $wpdb;

    $table = self::tableName();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom job-state table write; must persist immediately, nothing to cache on a write path.
    $wpdb->replace(
      $table,
      [
        'slot' => $slot,
        'payload' => wp_json_encode($job),
        'updated_at' => time(),
      ],
      ['%s', '%s', '%d']
    );
  }
}
