<?php
namespace Nimbus\Plugin\Integration;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Storage\StorageAdapter;
use Nimbus\Plugin\Storage\StorageAdapterFactory;
use Nimbus\Plugin\Support\Events;
use Nimbus\Plugin\Support\Logger;

use function Nimbus\Plugin\Support\buildStorageKey;

if (!defined('ABSPATH')) {
  exit;
}

final class SmartSliderAssetRewriter
{
  public function __construct(
    private Settings $settings,
    private Logger $logger,
  ) {
  }

  public function getTables(): array
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
      return [];
    }

    $prefix = (string) $wpdb->prefix;
    $pattern = $prefix . 'nextend2_%';
    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
    if (!is_array($tables)) {
      return [];
    }

    $smartsliderTables = [];
    foreach ($tables as $table) {
      $tableName = (string) $table;
      if (preg_match('/nextend2_(smartslider3_|image_storage|section_storage)/i', $tableName)) {
        $smartsliderTables[] = $tableName;
      }
    }

    sort($smartsliderTables);

    return array_values(array_unique($smartsliderTables));
  }

  public function rewriteLocalToRemote(): array
  {
    $uploads = wp_upload_dir();
    $localBase = trailingslashit((string) ($uploads['baseurl'] ?? ''));
    $remoteBase = $this->resolveRemoteBaseUrl();
    $context = [
      'source' => 'integration',
      'scope' => 'integration',
      'integration' => 'smart_slider',
      'operation' => 'rewrite_local_to_remote',
      'local_base' => $localBase,
      'remote_base' => $remoteBase,
    ];

    Events::dispatch(Events::INTEGRATION_REWRITE_STARTED, $context);
    Events::dispatch(Events::REWRITE_STARTED, $context + ['kind' => 'integration']);

    if ($localBase === '' || $remoteBase === '') {
      $result = [
        'tables' => 0,
        'columns' => 0,
        'updated_rows' => 0,
        'errors' => 1,
        'local_base' => $localBase,
        'remote_base' => $remoteBase,
      ];

      Events::dispatch(Events::INTEGRATION_REWRITE_FAILED, $context + $result + [
        'error' => 'Unable to resolve local or remote base URL for Smart Slider rewrite.',
      ]);
      Events::dispatch(Events::REWRITE_FAILED, $context + $result + [
        'kind' => 'integration',
        'error' => 'Unable to resolve local or remote base URL for Smart Slider rewrite.',
      ]);

      return $result;
    }

    try {
      $result = $this->applyRewrites($localBase, $remoteBase, true);
      $result['local_base'] = $localBase;
      $result['remote_base'] = $remoteBase;
    } catch (\Throwable $exception) {
      Events::dispatch(Events::INTEGRATION_REWRITE_FAILED, $context + [
        'error' => $exception->getMessage(),
      ]);
      Events::dispatch(Events::REWRITE_FAILED, $context + [
        'kind' => 'integration',
        'error' => $exception->getMessage(),
      ]);
      throw $exception;
    }

    Events::dispatch(Events::INTEGRATION_REWRITE_COMPLETED, $context + $result);
    Events::dispatch(Events::REWRITE_COMPLETED, $context + $result + ['kind' => 'integration']);

    return $result;
  }

  private function resolveRemoteBaseUrl(): string
  {
    try {
      $factory = new StorageAdapterFactory($this->settings, $this->logger);
      $adapter = $factory->getAdapter();
    } catch (\Throwable $exception) {
      $this->logger->warning('Failed to initialize adapter for Smart Slider rewrite: {error}', [
        'error' => $exception->getMessage(),
      ]);
      return '';
    }

    return $this->resolveRemoteBaseUrlFromAdapter($adapter);
  }

  private function resolveRemoteBaseUrlFromAdapter(StorageAdapter $adapter): string
  {
    $probeName = 'nimbus-url-probe';
    $probeKey = buildStorageKey($probeName, (string) $this->settings->get('prefix', ''));

    try {
      $probeUrl = $adapter->getUrl($probeKey);
    } catch (\Throwable $exception) {
      $this->logger->warning('Failed to build remote Smart Slider base URL: {error}', [
        'error' => $exception->getMessage(),
      ]);
      return '';
    }

    $position = strrpos($probeUrl, $probeName);
    if ($position === false) {
      return '';
    }

    return trailingslashit(substr($probeUrl, 0, $position));
  }

  private function applyRewrites(string $fromBase, string $toBase, bool $rewriteUploadPlaceholder): array
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
      return [
        'tables' => 0,
        'columns' => 0,
        'updated_rows' => 0,
        'errors' => 1,
      ];
    }

    $pairs = $this->buildReplacementPairs($fromBase, $toBase, $rewriteUploadPlaceholder);
    if (empty($pairs)) {
      return [
        'tables' => 0,
        'columns' => 0,
        'updated_rows' => 0,
        'errors' => 0,
      ];
    }

    $tables = $this->getTables();
    if (empty($tables)) {
      return [
        'tables' => 0,
        'columns' => 0,
        'updated_rows' => 0,
        'errors' => 0,
      ];
    }

    $touchedColumns = 0;
    $updatedRows = 0;
    $errors = 0;

    foreach ($tables as $table) {
      $textColumns = $this->getTextColumnsForTable($table);
      foreach ($textColumns as $column) {
        $touchedColumns++;

        $tableSql = '`' . str_replace('`', '``', $table) . '`';
        $columnSql = '`' . str_replace('`', '``', $column) . '`';

        foreach ($pairs as $pair) {
          $from = (string) ($pair['from'] ?? '');
          $to = (string) ($pair['to'] ?? '');

          if ($from === '' || $from === $to) {
            continue;
          }

          $sql = $wpdb->prepare(
            "UPDATE {$tableSql} SET {$columnSql} = REPLACE({$columnSql}, %s, %s) WHERE {$columnSql} LIKE %s",
            $from,
            $to,
            '%' . $wpdb->esc_like($from) . '%'
          );

          $result = $wpdb->query($sql);
          if ($result === false) {
            $errors++;
            continue;
          }

          $updatedRows += (int) $result;
        }
      }
    }

    return [
      'tables' => count($tables),
      'columns' => $touchedColumns,
      'updated_rows' => $updatedRows,
      'errors' => $errors,
    ];
  }

  private function buildReplacementPairs(string $fromBase, string $toBase, bool $rewriteUploadPlaceholder): array
  {
    $fromBase = trailingslashit($fromBase);
    $toBase = trailingslashit($toBase);

    $pairs = [
      ['from' => $fromBase, 'to' => $toBase],
      ['from' => untrailingslashit($fromBase), 'to' => untrailingslashit($toBase)],
      ['from' => str_replace('/', '\\/', $fromBase), 'to' => str_replace('/', '\\/', $toBase)],
      ['from' => str_replace('/', '\\/', untrailingslashit($fromBase)), 'to' => str_replace('/', '\\/', untrailingslashit($toBase))],
      ['from' => rawurlencode($fromBase), 'to' => rawurlencode($toBase)],
      ['from' => rawurlencode(untrailingslashit($fromBase)), 'to' => rawurlencode(untrailingslashit($toBase))],
    ];

    if ($rewriteUploadPlaceholder) {
      $pairs[] = ['from' => '$upload$/', 'to' => $toBase];
    }

    $deduped = [];
    $seen = [];

    foreach ($pairs as $pair) {
      $from = (string) ($pair['from'] ?? '');
      $to = (string) ($pair['to'] ?? '');
      if ($from === '' || $from === $to) {
        continue;
      }

      $key = $from . "\n" . $to;
      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = true;
      $deduped[] = [
        'from' => $from,
        'to' => $to,
      ];
    }

    return $deduped;
  }

  private function getTextColumnsForTable(string $table): array
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb || $table === '') {
      return [];
    }

    $tableSql = '`' . str_replace('`', '``', $table) . '`';
    $columns = $wpdb->get_results("DESCRIBE {$tableSql}", ARRAY_A);
    if (!is_array($columns)) {
      return [];
    }

    $textColumns = [];
    foreach ($columns as $column) {
      $field = (string) ($column['Field'] ?? '');
      $type = strtolower((string) ($column['Type'] ?? ''));
      if ($field === '') {
        continue;
      }

      if (preg_match('/(char|text)/', $type)) {
        $textColumns[] = $field;
      }
    }

    return array_values(array_unique($textColumns));
  }
}
