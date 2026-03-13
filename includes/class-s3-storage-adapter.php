<?php
namespace Pathanamthitta\MediaOffload;

use RuntimeException;

// Defer referencing AWS classes until runtime to keep plugin fatal-safe when SDK is absent.


if (!defined('ABSPATH')) {
  exit;
}

class S3_Storage_Adapter implements Storage_Adapter
{
  private Logger $logger;

  private array $config;

  /**
   * @var object|null
   */
  private $client;

  public function __construct(array $config, Logger $logger)
  {
    $required = ['bucket', 'region', 'access_key', 'secret_key'];
    foreach ($required as $key) {
      if (empty($config[$key])) {
        throw new RuntimeException(sprintf('Missing required S3 configuration value: %s', $key));
      }
    }

    $this->config = $config;
    $this->logger = $logger;
  }

  private function get_client()
  {
    if ($this->client) {
      return $this->client;
    }

    if (!class_exists('\\Aws\\S3\\S3Client')) {
      throw new RuntimeException('AWS SDK for PHP (aws/aws-sdk-php) is required for S3 storage.');
    }

    $endpoint = $this->normalize_endpoint((string) ($this->config['endpoint'] ?? ''));
    $is_r2_endpoint = $this->endpoint_looks_like_r2($endpoint);

    $region = trim((string) ($this->config['region'] ?? ''));
    if ($region === '') {
      $region = 'us-east-1';
    }
    if ($is_r2_endpoint && strtolower($region) === 'us-east-1') {
      $region = 'auto';
    }

    $client_config = [
      'version' => 'latest',
      'region' => $region,
      'signature_version' => 'v4',
      'credentials' => [
        'key' => $this->config['access_key'],
        'secret' => $this->config['secret_key'],
      ],
    ];

    if (!empty($this->config['session_token']) && !$is_r2_endpoint) {
      $client_config['credentials']['token'] = $this->config['session_token'];
    }

    if ($endpoint !== '') {
      $client_config['endpoint'] = $endpoint;
    }

    if (isset($this->config['use_path_style_endpoint'])) {
      $client_config['use_path_style_endpoint'] = (bool) $this->config['use_path_style_endpoint'];
    }

    if ($is_r2_endpoint) {
      // R2 works best with path-style addressing and no session token.
      $client_config['use_path_style_endpoint'] = true;
    }

    $client_class = '\\Aws\\S3\\S3Client';
    $this->client = new $client_class($client_config);

    return $this->client;
  }

  public function upload(string $source_path, string $destination_key, array $args = []): string
  {
    $destination_key = $this->normalize_key($destination_key);
    $mime = $args['content_type'] ?? $this->guess_mime_type($source_path);

    $params = [
      'Bucket' => $this->config['bucket'],
      'Key' => $destination_key,
      'SourceFile' => $source_path,
      'ContentType' => $mime,
    ];

    if (!empty($this->config['storage_class'])) {
      $params['StorageClass'] = $this->config['storage_class'];
    }

    if (!empty($args['cache_control'])) {
      $params['CacheControl'] = $args['cache_control'];
    }

    try {
      $this->get_client()->putObject($params);
    } catch (\Throwable $exception) {
      if ($this->is_auth_signature_error($exception)) {
        $fallback_key = $this->signature_safe_key($destination_key);
        if ($fallback_key !== $destination_key) {
          $this->logger->warning('Retrying upload for {key} using signature-safe key {fallback_key}.', [
            'key' => $destination_key,
            'fallback_key' => $fallback_key,
          ]);

          $params['Key'] = $fallback_key;

          try {
            $this->get_client()->putObject($params);
            return $fallback_key;
          } catch (\Throwable $fallback_exception) {
            $exception = $fallback_exception;
          }
        }
      }

      $error = $this->extract_error_message($exception);
      $this->logger->error('Failed to upload {key} to S3: {error}', [
        'key' => $destination_key,
        'error' => $error,
      ]);
      throw new RuntimeException('Failed to upload object to S3: ' . $error);
    }

    return $destination_key;
  }

  public function delete(string $key): void
  {
    $key = $this->normalize_key($key);
    try {
      $this->get_client()->deleteObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
      ]);
    } catch (\Throwable $exception) {
      if ($this->is_auth_signature_error($exception)) {
        $fallback_key = $this->signature_safe_key($key);
        if ($fallback_key !== $key) {
          $this->logger->warning('Retrying delete for {key} using signature-safe key {fallback_key}.', [
            'key' => $key,
            'fallback_key' => $fallback_key,
          ]);

          try {
            $this->get_client()->deleteObject([
              'Bucket' => $this->config['bucket'],
              'Key' => $fallback_key,
            ]);

            return;
          } catch (\Throwable $fallback_exception) {
            $exception = $fallback_exception;
          }
        }

        $error = $this->extract_error_message($exception);
        throw new RuntimeException('Storage authentication failed while deleting object: ' . $error);
      }

      $error = $this->extract_error_message($exception);

      $this->logger->warning('Failed to delete {key} from S3: {error}', [
        'key' => $key,
        'error' => $error,
      ]);
      // We don't throw here to avoid blocking local deletion if remote fails
    }
  }

  public function download(string $key, string $destination_path): void
  {
    $key = $this->normalize_key($key);
    try {
      $this->get_client()->getObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
        'SaveAs' => $destination_path,
      ]);
    } catch (\Throwable $exception) {
      $this->logger->error('Failed to download {key} from S3: {error}', [
        'key' => $key,
        'error' => $this->extract_error_message($exception),
      ]);
      throw new RuntimeException('Failed to download object from S3.');
    }
  }

  public function get_url(string $key): string
  {
    $key = $this->normalize_key($key);
    if (!empty($this->config['custom_domain'])) {
      return rtrim($this->config['custom_domain'], '/') . '/' . ltrim($key, '/');
    }

    return $this->get_client()->getObjectUrl($this->config['bucket'], $key);
  }

  public function exists(string $key): bool
  {
    $key = $this->normalize_key($key);
    try {
      $this->get_client()->headObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
      ]);

      return true;
    } catch (\Throwable $exception) {
      if ($this->should_try_read_fallback($exception)) {
        if ($this->can_read_object($key)) {
          return true;
        }

        // With restricted credentials, some providers return 403 for missing
        // objects. Treat this as a normal "not found" signal to avoid
        // flooding logs during batch verification.
        return false;
      }

      if ($this->is_not_found_exception($exception)) {
        return false;
      }

      $this->logger->warning('Failed existence check for {key}: {error}', [
        'key' => $key,
        'error' => $this->extract_error_message($exception),
      ]);

      return false;
    }
  }

  public function existing_keys(array $keys, array $options = []): array
  {
    $requested = [];
    foreach ($keys as $key) {
      $normalized_key = ltrim((string) $key, '/');
      if ($normalized_key === '') {
        continue;
      }

      $requested[$normalized_key] = true;
    }

    if (empty($requested)) {
      return [];
    }

    $requested_keys = array_keys($requested);
    $existing = [];

    $listed_existing = $this->find_existing_keys_by_listing($requested_keys);
    if ($listed_existing !== null) {
      foreach ($listed_existing as $key) {
        $existing[$key] = true;
      }
    }

    if (count($existing) < count($requested)) {
      $missing_keys = [];
      foreach ($requested as $key => $_present) {
        if (!isset($existing[$key])) {
          $missing_keys[] = $key;
        }
      }

      $concurrency = max(1, (int) ($options['concurrency'] ?? 20));
      foreach ($this->check_missing_keys_concurrently($missing_keys, $concurrency) as $key) {
        $existing[$key] = true;
      }
    }

    return array_keys($existing);
  }

  public function get_object_size(string $key): int
  {
    $key = $this->normalize_key($key);
    try {
      $result = $this->get_client()->headObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
      ]);

      if (!isset($result['ContentLength'])) {
        throw new RuntimeException(sprintf('Object size missing for key: %s', $key));
      }

      return (int) $result['ContentLength'];
    } catch (\Throwable $exception) {
      if ($this->should_try_read_fallback($exception)) {
        try {
          return $this->get_object_size_via_read_fallback($key);
        } catch (\Throwable $fallback_exception) {
          $this->logger->warning('Failed size check for {key}: {error}', [
            'key' => $key,
            'error' => $this->extract_error_message($fallback_exception),
          ]);

          throw new RuntimeException(sprintf('Failed to get object size for key: %s', $key));
        }
      }

      $this->logger->warning('Failed size check for {key}: {error}', [
        'key' => $key,
        'error' => $this->extract_error_message($exception),
      ]);

      throw new RuntimeException(sprintf('Failed to get object size for key: %s', $key));
    }
  }

  public function test_connection(): bool
  {
    try {
      $client = $this->get_client();
      $bucket = $this->config['bucket'];

      // Try a full write/read/delete cycle because some providers (including R2)
      // may not support bucket-level existence checks with limited API credentials.
      $test_key = 'connection-test-' . time() . '.txt';
      $client->putObject([
          'Bucket' => $bucket,
          'Key'    => $test_key,
          'Body'   => 'Connection test successful.',
      ]);

      $client->headObject([
        'Bucket' => $bucket,
        'Key'    => $test_key,
      ]);

      $client->deleteObject([
          'Bucket' => $bucket,
          'Key'    => $test_key,
      ]);

      return true;
    } catch (\Throwable $exception) {
      $this->logger->error('Connection test failed: {error}', [
        'error' => $this->extract_error_message($exception),
      ]);
      // Re-throw so the UI can show the error message
      throw new \RuntimeException($this->extract_error_message($exception));
    }
  }

  public function get_usage_statistics(): array
  {
    // Temporarily disabled to save memory
    return [];

    if (!class_exists('\\Aws\\CloudWatch\\CloudWatchClient')) {
      return [];
    }

    try {
      $cw_client = new \Aws\CloudWatch\CloudWatchClient([
        'version' => 'latest',
        'region' => $this->config['region'],
        'credentials' => [
          'key' => $this->config['access_key'],
          'secret' => $this->config['secret_key'],
        ],
      ]);

      $end_time = time();
      $start_time = strtotime('-2 days'); // S3 metrics are reported once a day

      // Get BucketSizeBytes (StandardStorage)
      $size_result = $cw_client->getMetricStatistics([
        'Namespace' => 'AWS/S3',
        'MetricName' => 'BucketSizeBytes',
        'Dimensions' => [
          ['Name' => 'BucketName', 'Value' => $this->config['bucket']],
          ['Name' => 'StorageType', 'Value' => 'StandardStorage'],
        ],
        'StartTime' => $start_time,
        'EndTime' => $end_time,
        'Period' => 86400,
        'Statistics' => ['Maximum'],
      ]);

      // Get NumberOfObjects (AllStorageTypes)
      $count_result = $cw_client->getMetricStatistics([
        'Namespace' => 'AWS/S3',
        'MetricName' => 'NumberOfObjects',
        'Dimensions' => [
          ['Name' => 'BucketName', 'Value' => $this->config['bucket']],
          ['Name' => 'StorageType', 'Value' => 'AllStorageTypes'],
        ],
        'StartTime' => $start_time,
        'EndTime' => $end_time,
        'Period' => 86400,
        'Statistics' => ['Maximum'],
      ]);

      $size_bytes = 0;
      if (!empty($size_result['Datapoints'])) {
        // Get the latest datapoint
        $datapoints = $size_result['Datapoints'];
        usort($datapoints, function($a, $b) {
          return $b['Timestamp'] <=> $a['Timestamp'];
        });
        $size_bytes = $datapoints[0]['Maximum'];
      }

      $object_count = 0;
      if (!empty($count_result['Datapoints'])) {
        $datapoints = $count_result['Datapoints'];
        usort($datapoints, function($a, $b) {
          return $b['Timestamp'] <=> $a['Timestamp'];
        });
        $object_count = $datapoints[0]['Maximum'];
      }

      return [
        'size_bytes' => $size_bytes,
        'object_count' => $object_count,
        'estimated_cost' => ($size_bytes / 1073741824) * 0.023, // Approx $0.023 per GB
      ];

    } catch (\Throwable $exception) {
      $this->logger->warning('Failed to fetch CloudWatch metrics: {error}', [
        'error' => $this->extract_error_message($exception),
      ]);
      return [];
    }
  }

  private function guess_mime_type(string $source_path): string
  {
    $type = wp_check_filetype($source_path);
    if (!empty($type['type'])) {
      return $type['type'];
    }

    return 'application/octet-stream';
  }

  private function extract_error_message(\Throwable $exception): string
  {
    if (method_exists($exception, 'getAwsErrorMessage')) {
      $message = $exception->getAwsErrorMessage();
      if (!empty($message)) {
        return $message;
      }
    }

    return $exception->getMessage();
  }

  private function is_auth_signature_error(\Throwable $exception): bool
  {
    $code = strtolower((string) $this->get_aws_error_code($exception));
    if (in_array($code, ['signaturedoesnotmatch', 'invalidaccesskeyid', 'authfailure'], true)) {
      return true;
    }

    $message = strtolower($this->extract_error_message($exception));
    return strpos($message, 'signature we calculated does not match') !== false
      || strpos($message, 'invalid access key') !== false
      || strpos($message, 'request signature') !== false;
  }

  private function normalize_key(string $key): string
  {
    $key = ltrim(str_replace('\\', '/', $key), '/');

    if ($key === '') {
      return $key;
    }

    if (class_exists('\\Normalizer')) {
      try {
        $normalized = \Normalizer::normalize($key, \Normalizer::FORM_C);
        if (is_string($normalized) && $normalized !== '') {
          $key = $normalized;
        }
      } catch (\Throwable $ignored) {
        // Keep original key when intl normalizer is unavailable or fails.
      }
    }

    return $key;
  }

  private function signature_safe_key(string $key): string
  {
    return str_replace(["\u{00D7}", "\u{2715}", "\u{2716}", "\u{2717}"], 'x', $key);
  }

  private function normalize_endpoint(string $endpoint): string
  {
    $endpoint = trim($endpoint);
    if ($endpoint === '') {
      return '';
    }

    if (!preg_match('#^https?://#i', $endpoint)) {
      $endpoint = 'https://' . $endpoint;
    }

    $parts = wp_parse_url($endpoint);
    if (!is_array($parts) || empty($parts['host'])) {
      return rtrim($endpoint, '/');
    }

    $scheme = !empty($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';

    $bucket = trim((string) ($this->config['bucket'] ?? ''), '/');
    if ($bucket !== '' && $path !== '') {
      if ($path === $bucket) {
        $path = '';
      } elseif (strpos($path, $bucket . '/') === 0) {
        $path = ltrim(substr($path, strlen($bucket)), '/');
      }
    }

    return $scheme . '://' . $host . $port . ($path !== '' ? '/' . $path : '');
  }

  private function endpoint_looks_like_r2(string $endpoint): bool
  {
    if ($endpoint === '') {
      return false;
    }

    $host = (string) wp_parse_url($endpoint, PHP_URL_HOST);
    if ($host === '') {
      return false;
    }

    return strpos(strtolower($host), 'r2.cloudflarestorage.com') !== false;
  }

  private function should_try_read_fallback(\Throwable $exception): bool
  {
    $status = $this->get_aws_status_code($exception);
    if ($status === 403) {
      return true;
    }

    $code = strtolower((string) $this->get_aws_error_code($exception));
    return in_array($code, ['accessdenied', 'forbidden'], true);
  }

  private function is_not_found_exception(\Throwable $exception): bool
  {
    $status = $this->get_aws_status_code($exception);
    if ($status === 404) {
      return true;
    }

    $code = strtolower((string) $this->get_aws_error_code($exception));
    return in_array($code, ['nosuchkey', 'notfound'], true);
  }

  private function can_read_object(string $key): bool
  {
    try {
      $this->get_client()->getObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
        'Range' => 'bytes=0-0',
      ]);

      return true;
    } catch (\Throwable $exception) {
      $status = $this->get_aws_status_code($exception);
      $code = strtolower((string) $this->get_aws_error_code($exception));

      // Zero-byte objects can return InvalidRange/416 for byte-range reads.
      if ($status === 416 || $code === 'invalidrange') {
        return true;
      }

      return false;
    }
  }

  private function get_object_size_via_read_fallback(string $key): int
  {
    try {
      $result = $this->get_client()->getObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
        'Range' => 'bytes=0-0',
      ]);

      if (!empty($result['ContentRange']) && preg_match('/\/(\d+)$/', (string) $result['ContentRange'], $matches)) {
        return (int) $matches[1];
      }

      if (isset($result['ContentLength'])) {
        return (int) $result['ContentLength'];
      }

      throw new RuntimeException(sprintf('Object size missing for key: %s', $key));
    } catch (\Throwable $exception) {
      $status = $this->get_aws_status_code($exception);
      $code = strtolower((string) $this->get_aws_error_code($exception));

      if ($status === 416 || $code === 'invalidrange') {
        return 0;
      }

      throw $exception;
    }
  }

  /**
   * @param array<int, string> $keys
   * @return array<int, string>|null Null means caller should fallback to per-key checks.
   */
  private function find_existing_keys_by_listing(array $keys): ?array
  {
    $requested_lookup = array_fill_keys($keys, true);
    $prefix_map = [];

    foreach ($keys as $key) {
      $prefix = dirname($key);
      $prefix = $prefix === '.' ? '' : trailingslashit($prefix);

      if (!isset($prefix_map[$prefix])) {
        $prefix_map[$prefix] = [];
      }

      $prefix_map[$prefix][$key] = true;
    }

    $existing = [];

    foreach ($prefix_map as $prefix => $prefix_keys) {
      $continuation_token = null;

      do {
        $params = [
          'Bucket' => $this->config['bucket'],
          'Prefix' => $prefix,
          'MaxKeys' => 1000,
        ];

        if (!empty($continuation_token)) {
          $params['ContinuationToken'] = $continuation_token;
        }

        try {
          $result = $this->get_client()->listObjectsV2($params);
        } catch (\Throwable $exception) {
          if ($this->is_listing_not_allowed($exception)) {
            return null;
          }

          $this->logger->warning('Bulk list check failed for prefix {prefix}: {error}', [
            'prefix' => $prefix,
            'error' => $this->extract_error_message($exception),
          ]);

          return null;
        }

        if (!empty($result['Contents']) && is_array($result['Contents'])) {
          foreach ($result['Contents'] as $item) {
            if (!is_array($item) || empty($item['Key'])) {
              continue;
            }

            $object_key = (string) $item['Key'];
            if (isset($requested_lookup[$object_key])) {
              $existing[$object_key] = true;
              unset($prefix_keys[$object_key]);
            }
          }
        }

        $is_truncated = !empty($result['IsTruncated']);
        $continuation_token = $is_truncated && !empty($result['NextContinuationToken'])
          ? (string) $result['NextContinuationToken']
          : null;
      } while (!empty($continuation_token) && !empty($prefix_keys));
    }

    return array_keys($existing);
  }

  private function is_listing_not_allowed(\Throwable $exception): bool
  {
    $status = $this->get_aws_status_code($exception);
    if ($status === 403 || $status === 401) {
      return true;
    }

    $code = strtolower((string) $this->get_aws_error_code($exception));
    return in_array($code, ['accessdenied', 'allaccessdisabled', 'unauthorizedoperation'], true);
  }

  /**
   * @param array<int, string> $keys
   * @return array<int, string>
   */
  private function check_missing_keys_concurrently(array $keys, int $concurrency): array
  {
    if (empty($keys)) {
      return [];
    }

    $client = $this->get_client();
    if (!method_exists($client, 'headObjectAsync') || !class_exists('\\GuzzleHttp\\Promise\\Utils')) {
      $existing = [];
      foreach ($keys as $key) {
        if ($this->exists($key)) {
          $existing[] = $key;
        }
      }

      return $existing;
    }

    $existing = [];
    $pending = [];

    foreach ($keys as $key) {
      $pending[$key] = $client->headObjectAsync([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
      ])->then(
        function (): bool {
          return true;
        },
        function ($reason) use ($key): bool {
          $exception = $this->normalize_promise_reason($reason);

          if ($this->should_try_read_fallback($exception)) {
            return $this->can_read_object($key);
          }

          if ($this->is_not_found_exception($exception)) {
            return false;
          }

          $this->logger->warning('Failed existence check for {key}: {error}', [
            'key' => $key,
            'error' => $this->extract_error_message($exception),
          ]);

          return false;
        }
      );

      if (count($pending) >= $concurrency) {
        foreach ($this->resolve_exists_promises($pending) as $found_key) {
          $existing[$found_key] = true;
        }
        $pending = [];
      }
    }

    if (!empty($pending)) {
      foreach ($this->resolve_exists_promises($pending) as $found_key) {
        $existing[$found_key] = true;
      }
    }

    return array_keys($existing);
  }

  /**
   * @param array<string, mixed> $promises
   * @return array<int, string>
   */
  private function resolve_exists_promises(array $promises): array
  {
    $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
    $existing = [];

    foreach ($results as $key => $result) {
      if (($result['state'] ?? '') === 'fulfilled' && !empty($result['value'])) {
        $existing[] = (string) $key;
        continue;
      }

      if (($result['state'] ?? '') === 'rejected') {
        $exception = $this->normalize_promise_reason($result['reason'] ?? null);
        $this->logger->warning('Async existence check failed for {key}: {error}', [
          'key' => (string) $key,
          'error' => $this->extract_error_message($exception),
        ]);
      }
    }

    return $existing;
  }

  private function normalize_promise_reason($reason): \Throwable
  {
    if ($reason instanceof \Throwable) {
      return $reason;
    }

    if (is_string($reason) && $reason !== '') {
      return new RuntimeException($reason);
    }

    return new RuntimeException('Unknown async S3 error.');
  }

  private function get_aws_status_code(\Throwable $exception): ?int
  {
    if (method_exists($exception, 'getStatusCode')) {
      $status = $exception->getStatusCode();
      if ($status !== null) {
        return (int) $status;
      }
    }

    return null;
  }

  private function get_aws_error_code(\Throwable $exception): ?string
  {
    if (method_exists($exception, 'getAwsErrorCode')) {
      $code = $exception->getAwsErrorCode();
      if (!empty($code)) {
        return (string) $code;
      }
    }

    return null;
  }
}
