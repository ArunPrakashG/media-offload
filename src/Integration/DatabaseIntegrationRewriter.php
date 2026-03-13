<?php
namespace ArunPrakash\MediaOffload\Integration;

use ArunPrakash\MediaOffload\Config\Settings;
use ArunPrakash\MediaOffload\Storage\StorageAdapter;
use ArunPrakash\MediaOffload\Storage\StorageAdapterFactory;
use ArunPrakash\MediaOffload\Support\Events;
use ArunPrakash\MediaOffload\Support\Logger;

use function ArunPrakash\MediaOffload\Support\buildStorageKey;

if (!defined('ABSPATH')) {
  exit;
}

final class DatabaseIntegrationRewriter
{
  public function __construct(
    private Settings $settings,
    private Logger $logger,
  ) {
  }

  /**
   * @param array<string, mixed> $definition
   * @return array<string, mixed>
   */
  public function rewriteLocalToRemote(string $integration, array $definition): array
  {
    $uploads = wp_upload_dir();
    $localBase = trailingslashit((string) ($uploads['baseurl'] ?? ''));
    $remoteBase = $this->resolveRemoteBaseUrl();
    $context = [
      'source' => 'integration',
      'scope' => 'integration',
      'integration' => $integration,
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
        'error' => 'Unable to resolve local or remote base URL for integration rewrite.',
      ]);
      Events::dispatch(Events::REWRITE_FAILED, $context + $result + [
        'kind' => 'integration',
        'error' => 'Unable to resolve local or remote base URL for integration rewrite.',
      ]);

      return $result;
    }

    try {
      $result = $this->applyRewrites($localBase, $remoteBase, $definition);
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

  /**
   * @param array<int, string> $patterns
   * @return array<int, string>
   */
  public function tablesByPatterns(array $patterns): array
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
      return [];
    }

    $tables = [];
    foreach ($patterns as $pattern) {
      $resolved = str_replace('{prefix}', (string) $wpdb->prefix, (string) $pattern);
      $matches = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $resolved));
      if (is_array($matches)) {
        foreach ($matches as $table) {
          $tables[] = (string) $table;
        }
      }
    }

    sort($tables);

    return array_values(array_unique($tables));
  }

  /**
   * @param array<int, string> $metaKeys
   */
  public function countPostmetaKeys(array $metaKeys): int
  {
    global $wpdb;

    if (!$wpdb instanceof \wpdb || empty($metaKeys)) {
      return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($metaKeys), '%s'));
    $sql = $wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
      ...array_values(array_map('strval', $metaKeys))
    );

    return max(0, (int) $wpdb->get_var($sql));
  }

  /**
   * @param array<string, mixed> $definition
   * @return array<string, mixed>
   */
  private function applyRewrites(string $fromBase, string $toBase, array $definition): array
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

    $pairs = $this->buildReplacementPairs($fromBase, $toBase, !empty($definition['rewrite_upload_placeholder']));
    if (empty($pairs)) {
      return [
        'tables' => 0,
        'columns' => 0,
        'updated_rows' => 0,
        'errors' => 0,
      ];
    }

    $tables = $this->tablesByPatterns((array) ($definition['table_patterns'] ?? []));
    $postmetaKeys = array_values(array_unique(array_filter(array_map('strval', (array) ($definition['postmeta_keys'] ?? [])))));
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
          $sql = $wpdb->prepare(
            "UPDATE {$tableSql} SET {$columnSql} = REPLACE({$columnSql}, %s, %s) WHERE {$columnSql} LIKE %s",
            (string) $pair['from'],
            (string) $pair['to'],
            '%' . $wpdb->esc_like((string) $pair['from']) . '%'
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

    if (!empty($postmetaKeys)) {
      $placeholders = implode(', ', array_fill(0, count($postmetaKeys), '%s'));
      foreach ($pairs as $pair) {
        $sql = $wpdb->prepare(
          "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key IN ({$placeholders}) AND meta_value LIKE %s",
          ...array_merge([
            (string) $pair['from'],
            (string) $pair['to'],
          ], $postmetaKeys, ['%' . $wpdb->esc_like((string) $pair['from']) . '%'])
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
          $errors++;
          continue;
        }

        $updatedRows += (int) $result;
      }
    }

    return [
      'tables' => count($tables),
      'columns' => $touchedColumns,
      'postmeta_keys' => count($postmetaKeys),
      'updated_rows' => $updatedRows,
      'errors' => $errors,
    ];
  }

  private function resolveRemoteBaseUrl(): string
  {
    try {
      $factory = new StorageAdapterFactory($this->settings, $this->logger);
      $adapter = $factory->getAdapter();
    } catch (\Throwable $exception) {
      $this->logger->warning('Failed to initialize adapter for integration rewrite: {error}', [
        'error' => $exception->getMessage(),
        'source' => 'integration',
        'event' => 'integration_rewrite',
        'code' => 'adapter_init_failed',
      ]);
      return '';
    }

    return $this->resolveRemoteBaseUrlFromAdapter($adapter);
  }

  private function resolveRemoteBaseUrlFromAdapter(StorageAdapter $adapter): string
  {
    $probeName = 'media-offload-url-probe';
    $probeKey = buildStorageKey($probeName, (string) $this->settings->get('prefix', ''));

    try {
      $probeUrl = $adapter->getUrl($probeKey);
    } catch (\Throwable $exception) {
      $this->logger->warning('Failed to build remote integration base URL: {error}', [
        'error' => $exception->getMessage(),
        'source' => 'integration',
        'event' => 'integration_rewrite',
        'code' => 'remote_base_failed',
      ]);
      return '';
    }

    $position = strrpos($probeUrl, $probeName);
    if ($position === false) {
      return '';
    }

    return trailingslashit(substr($probeUrl, 0, $position));
  }

  /**
   * @return array<int, array{from: string, to: string}>
   */
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
      $deduped[] = ['from' => $from, 'to' => $to];
    }

    return $deduped;
  }

  /**
   * @return array<int, string>
   */
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