<?php
namespace Nimbus\Plugin\Storage;

use Nimbus\Plugin\Support\Logger;
use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

final class S3StorageAdapter implements StorageAdapter
{
  /**
   * @var object|null
   */
  private $client;

  public function __construct(private array $config, private Logger $logger)
  {
    $required = ['bucket', 'region', 'access_key', 'secret_key'];
    foreach ($required as $key) {
      if (empty($config[$key])) {
        throw new RuntimeException(sprintf('Missing required S3 configuration value: %s', $key));
      }
    }
  }

  private function getClient()
  {
    if ($this->client) {
      return $this->client;
    }

    if (!class_exists('\\Aws\\S3\\S3Client')) {
      throw new RuntimeException('AWS SDK for PHP (aws/aws-sdk-php) is required for S3 storage.');
    }

    $endpoint = $this->normalizeEndpoint((string) ($this->config['endpoint'] ?? ''));
    $isR2Endpoint = $this->endpointLooksLikeR2($endpoint);

    $region = trim((string) ($this->config['region'] ?? ''));
    if ($region === '') {
      $region = 'us-east-1';
    }
    if ($isR2Endpoint && strtolower($region) === 'us-east-1') {
      $region = 'auto';
    }

    $clientConfig = [
      'version' => 'latest',
      'region' => $region,
      'signature_version' => 'v4',
      'credentials' => [
        'key' => $this->config['access_key'],
        'secret' => $this->config['secret_key'],
      ],
    ];

    if (!empty($this->config['session_token']) && !$isR2Endpoint) {
      $clientConfig['credentials']['token'] = $this->config['session_token'];
    }

    if ($endpoint !== '') {
      $clientConfig['endpoint'] = $endpoint;
    }

    if (isset($this->config['use_path_style_endpoint'])) {
      $clientConfig['use_path_style_endpoint'] = (bool) $this->config['use_path_style_endpoint'];
    }

    if ($isR2Endpoint) {
      $clientConfig['use_path_style_endpoint'] = true;
    }

    $clientClass = '\\Aws\\S3\\S3Client';
    $this->client = new $clientClass($clientConfig);

    return $this->client;
  }

  public function upload(string $sourcePath, string $destinationKey, array $args = []): string
  {
    $destinationKey = $this->normalizeKey($destinationKey);
    $mime = $args['content_type'] ?? $this->guessMimeType($sourcePath);

    $params = [
      'Bucket' => $this->config['bucket'],
      'Key' => $destinationKey,
      'SourceFile' => $sourcePath,
      'ContentType' => $mime,
    ];

    if (!empty($this->config['storage_class'])) {
      $params['StorageClass'] = $this->config['storage_class'];
    }

    if (!empty($args['cache_control'])) {
      $params['CacheControl'] = $args['cache_control'];
    }

    try {
      $this->getClient()->putObject($params);
    } catch (\Throwable $exception) {
      if ($this->isAuthSignatureError($exception)) {
        $fallbackKey = $this->signatureSafeKey($destinationKey);
        if ($fallbackKey !== $destinationKey) {
          $this->logger->warning('Retrying upload for {key} using signature-safe key {fallback_key}.', [
            'key' => $destinationKey,
            'fallback_key' => $fallbackKey,
          ]);

          $params['Key'] = $fallbackKey;

          try {
            $this->getClient()->putObject($params);
            return $fallbackKey;
          } catch (\Throwable $fallbackException) {
            $exception = $fallbackException;
          }
        }
      }

      $error = $this->extractErrorMessage($exception);
      $this->logger->error('Failed to upload {key} to storage: {error}', [
        'key' => $destinationKey,
        'error' => $error,
      ]);
      throw new RuntimeException('Failed to upload object to storage: ' . $error);
    }

    return $destinationKey;
  }

  public function delete(string $key): void
  {
    $key = $this->normalizeKey($key);

    try {
      $this->getClient()->deleteObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
      ]);
    } catch (\Throwable $exception) {
      if ($this->isAuthSignatureError($exception)) {
        $fallbackKey = $this->signatureSafeKey($key);
        if ($fallbackKey !== $key) {
          $this->logger->warning('Retrying delete for {key} using signature-safe key {fallback_key}.', [
            'key' => $key,
            'fallback_key' => $fallbackKey,
          ]);

          try {
            $this->getClient()->deleteObject([
              'Bucket' => $this->config['bucket'],
              'Key' => $fallbackKey,
            ]);
            return;
          } catch (\Throwable $fallbackException) {
            $exception = $fallbackException;
          }
        }

        throw new RuntimeException('Storage authentication failed while deleting object: ' . $this->extractErrorMessage($exception));
      }

      $this->logger->warning('Failed to delete {key} from storage: {error}', [
        'key' => $key,
        'error' => $this->extractErrorMessage($exception),
      ]);
    }
  }

  public function download(string $key, string $destinationPath): void
  {
    $key = $this->normalizeKey($key);

    try {
      $this->getClient()->getObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
        'SaveAs' => $destinationPath,
      ]);
    } catch (\Throwable $exception) {
      $this->logger->error('Failed to download {key} from storage: {error}', [
        'key' => $key,
        'error' => $this->extractErrorMessage($exception),
      ]);
      throw new RuntimeException('Failed to download object from storage.');
    }
  }

  public function getUrl(string $key): string
  {
    $key = $this->normalizeKey($key);
    if (!empty($this->config['custom_domain'])) {
      return rtrim($this->config['custom_domain'], '/') . '/' . ltrim($key, '/');
    }

    return $this->getClient()->getObjectUrl($this->config['bucket'], $key);
  }

  public function exists(string $key): bool
  {
    $key = $this->normalizeKey($key);

    try {
      $this->getClient()->headObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
      ]);
      return true;
    } catch (\Throwable $exception) {
      if ($this->shouldTryReadFallback($exception)) {
        return $this->canReadObject($key);
      }

      if ($this->isNotFoundException($exception)) {
        return false;
      }

      $this->logger->warning('Failed existence check for {key}: {error}', [
        'key' => $key,
        'error' => $this->extractErrorMessage($exception),
      ]);

      return false;
    }
  }

  public function existingKeys(array $keys, array $options = []): array
  {
    $requested = [];
    foreach ($keys as $key) {
      $normalizedKey = ltrim((string) $key, '/');
      if ($normalizedKey !== '') {
        $requested[$normalizedKey] = true;
      }
    }

    if (empty($requested)) {
      return [];
    }

    $requestedKeys = array_keys($requested);
    $existing = [];

    $listedExisting = $this->findExistingKeysByListing($requestedKeys);
    if ($listedExisting !== null) {
      foreach ($listedExisting as $key) {
        $existing[$key] = true;
      }
    }

    if (count($existing) < count($requested)) {
      $missingKeys = [];
      foreach ($requested as $key => $_present) {
        if (!isset($existing[$key])) {
          $missingKeys[] = $key;
        }
      }

      $concurrency = max(1, (int) ($options['concurrency'] ?? 20));
      foreach ($this->checkMissingKeysConcurrently($missingKeys, $concurrency) as $key) {
        $existing[$key] = true;
      }
    }

    return array_keys($existing);
  }

  public function getObjectSize(string $key): int
  {
    $key = $this->normalizeKey($key);

    try {
      $result = $this->getClient()->headObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
      ]);

      if (!isset($result['ContentLength'])) {
        throw new RuntimeException(sprintf('Object size missing for key: %s', $key));
      }

      return (int) $result['ContentLength'];
    } catch (\Throwable $exception) {
      if ($this->shouldTryReadFallback($exception)) {
        try {
          return $this->getObjectSizeViaReadFallback($key);
        } catch (\Throwable $fallbackException) {
          $this->logger->warning('Failed size check for {key}: {error}', [
            'key' => $key,
            'error' => $this->extractErrorMessage($fallbackException),
          ]);

          throw new RuntimeException(sprintf('Failed to get object size for key: %s', $key));
        }
      }

      $this->logger->warning('Failed size check for {key}: {error}', [
        'key' => $key,
        'error' => $this->extractErrorMessage($exception),
      ]);

      throw new RuntimeException(sprintf('Failed to get object size for key: %s', $key));
    }
  }

  public function testConnection(): bool
  {
    try {
      $client = $this->getClient();
      $bucket = $this->config['bucket'];
      $testKey = 'connection-test-' . time() . '.txt';

      $client->putObject([
        'Bucket' => $bucket,
        'Key' => $testKey,
        'Body' => 'Connection test successful.',
      ]);

      $client->headObject([
        'Bucket' => $bucket,
        'Key' => $testKey,
      ]);

      $client->deleteObject([
        'Bucket' => $bucket,
        'Key' => $testKey,
      ]);

      return true;
    } catch (\Throwable $exception) {
      $this->logger->error('Connection test failed: {error}', [
        'error' => $this->extractErrorMessage($exception),
      ]);
      throw new RuntimeException($this->extractErrorMessage($exception));
    }
  }

  public function getUsageStatistics(): array
  {
    return [];
  }

  /**
   * Create a new bucket in the configured provider.
   *
   * @throws \RuntimeException if the bucket could not be created.
   */
  public function createBucket(string $name, string $region = ''): void
  {
    try {
      $client = $this->getClient();
      $args = ['Bucket' => $name];

      // S3 requires a LocationConstraint for all regions except us-east-1.
      $effectiveRegion = $region !== '' ? $region : (string) ($this->config['region'] ?? '');
      if ($effectiveRegion !== '' && $effectiveRegion !== 'us-east-1' && $effectiveRegion !== 'auto') {
        $args['CreateBucketConfiguration'] = ['LocationConstraint' => $effectiveRegion];
      }

      $client->createBucket($args);
    } catch (\Throwable $exception) {
      $this->logger->error('createBucket("{name}") failed: {error}', [
        'name' => $name,
        'error' => $this->extractErrorMessage($exception),
      ]);
      throw new \RuntimeException($this->extractErrorMessage($exception));
    }
  }

  /**
   * Generate a pre-signed (temporary) URL for a remote object.
   *
   * @throws \RuntimeException if the client cannot sign the request.
   */
  public function presign(string $key, int $ttlSeconds = 3600): string
  {
    try {
      $client = $this->getClient();
      $command = $client->getCommand('GetObject', [
        'Bucket' => $this->config['bucket'],
        'Key' => $this->normalizeKey($key),
      ]);
      $presignedRequest = $client->createPresignedRequest($command, '+' . $ttlSeconds . ' seconds');
      return (string) $presignedRequest->getUri();
    } catch (\Throwable $exception) {
      $this->logger->error('presign("{key}") failed: {error}', [
        'key' => $key,
        'error' => $this->extractErrorMessage($exception),
      ]);
      throw new \RuntimeException($this->extractErrorMessage($exception));
    }
  }

  private function guessMimeType(string $sourcePath): string
  {
    $type = wp_check_filetype($sourcePath);
    if (!empty($type['type'])) {
      return $type['type'];
    }

    return 'application/octet-stream';
  }

  private function extractErrorMessage(\Throwable $exception): string
  {
    if (method_exists($exception, 'getAwsErrorMessage')) {
      $message = $exception->getAwsErrorMessage();
      if (!empty($message)) {
        return $message;
      }
    }

    return $exception->getMessage();
  }

  private function isAuthSignatureError(\Throwable $exception): bool
  {
    $code = strtolower((string) $this->getAwsErrorCode($exception));
    if (in_array($code, ['signaturedoesnotmatch', 'invalidaccesskeyid', 'authfailure'], true)) {
      return true;
    }

    $message = strtolower($this->extractErrorMessage($exception));
    return strpos($message, 'signature we calculated does not match') !== false
      || strpos($message, 'invalid access key') !== false
      || strpos($message, 'request signature') !== false;
  }

  private function normalizeKey(string $key): string
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
      }
    }

    return $key;
  }

  private function signatureSafeKey(string $key): string
  {
    return str_replace(["\u{00D7}", "\u{2715}", "\u{2716}", "\u{2717}"], 'x', $key);
  }

  private function normalizeEndpoint(string $endpoint): string
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

  private function endpointLooksLikeR2(string $endpoint): bool
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

  private function shouldTryReadFallback(\Throwable $exception): bool
  {
    $status = $this->getAwsStatusCode($exception);
    if ($status === 403) {
      return true;
    }

    $code = strtolower((string) $this->getAwsErrorCode($exception));
    return in_array($code, ['accessdenied', 'forbidden'], true);
  }

  private function isNotFoundException(\Throwable $exception): bool
  {
    $status = $this->getAwsStatusCode($exception);
    if ($status === 404) {
      return true;
    }

    $code = strtolower((string) $this->getAwsErrorCode($exception));
    return in_array($code, ['nosuchkey', 'notfound'], true);
  }

  private function canReadObject(string $key): bool
  {
    try {
      $this->getClient()->getObject([
        'Bucket' => $this->config['bucket'],
        'Key' => $key,
        'Range' => 'bytes=0-0',
      ]);

      return true;
    } catch (\Throwable $exception) {
      $status = $this->getAwsStatusCode($exception);
      $code = strtolower((string) $this->getAwsErrorCode($exception));
      if ($status === 416 || $code === 'invalidrange') {
        return true;
      }

      return false;
    }
  }

  private function getObjectSizeViaReadFallback(string $key): int
  {
    try {
      $result = $this->getClient()->getObject([
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
      $status = $this->getAwsStatusCode($exception);
      $code = strtolower((string) $this->getAwsErrorCode($exception));
      if ($status === 416 || $code === 'invalidrange') {
        return 0;
      }

      throw $exception;
    }
  }

  private function findExistingKeysByListing(array $keys): ?array
  {
    $requestedLookup = array_fill_keys($keys, true);
    $prefixMap = [];

    foreach ($keys as $key) {
      $prefix = dirname($key);
      $prefix = $prefix === '.' ? '' : trailingslashit($prefix);
      $prefixMap[$prefix] ??= [];
      $prefixMap[$prefix][$key] = true;
    }

    $existing = [];
    foreach ($prefixMap as $prefix => $prefixKeys) {
      $continuationToken = null;
      do {
        $params = [
          'Bucket' => $this->config['bucket'],
          'Prefix' => $prefix,
          'MaxKeys' => 1000,
        ];

        if (!empty($continuationToken)) {
          $params['ContinuationToken'] = $continuationToken;
        }

        try {
          $result = $this->getClient()->listObjectsV2($params);
        } catch (\Throwable $exception) {
          if ($this->isListingNotAllowed($exception)) {
            return null;
          }

          $this->logger->warning('Bulk list check failed for prefix {prefix}: {error}', [
            'prefix' => $prefix,
            'error' => $this->extractErrorMessage($exception),
          ]);

          return null;
        }

        if (!empty($result['Contents']) && is_array($result['Contents'])) {
          foreach ($result['Contents'] as $item) {
            if (!is_array($item) || empty($item['Key'])) {
              continue;
            }

            $objectKey = (string) $item['Key'];
            if (isset($requestedLookup[$objectKey])) {
              $existing[$objectKey] = true;
              unset($prefixKeys[$objectKey]);
            }
          }
        }

        $isTruncated = !empty($result['IsTruncated']);
        $continuationToken = $isTruncated && !empty($result['NextContinuationToken'])
          ? (string) $result['NextContinuationToken']
          : null;
      } while (!empty($continuationToken) && !empty($prefixKeys));
    }

    return array_keys($existing);
  }

  private function isListingNotAllowed(\Throwable $exception): bool
  {
    $status = $this->getAwsStatusCode($exception);
    if ($status === 403 || $status === 401) {
      return true;
    }

    $code = strtolower((string) $this->getAwsErrorCode($exception));
    return in_array($code, ['accessdenied', 'allaccessdisabled', 'unauthorizedoperation'], true);
  }

  private function checkMissingKeysConcurrently(array $keys, int $concurrency): array
  {
    if (empty($keys)) {
      return [];
    }

    $client = $this->getClient();
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
        fn (): bool => true,
        function ($reason) use ($key): bool {
          $exception = $this->normalizePromiseReason($reason);
          if ($this->shouldTryReadFallback($exception)) {
            return $this->canReadObject($key);
          }

          if ($this->isNotFoundException($exception)) {
            return false;
          }

          $this->logger->warning('Failed existence check for {key}: {error}', [
            'key' => $key,
            'error' => $this->extractErrorMessage($exception),
          ]);

          return false;
        }
      );

      if (count($pending) >= $concurrency) {
        foreach ($this->resolveExistsPromises($pending) as $foundKey) {
          $existing[$foundKey] = true;
        }
        $pending = [];
      }
    }

    if (!empty($pending)) {
      foreach ($this->resolveExistsPromises($pending) as $foundKey) {
        $existing[$foundKey] = true;
      }
    }

    return array_keys($existing);
  }

  private function resolveExistsPromises(array $promises): array
  {
    $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
    $existing = [];

    foreach ($results as $key => $result) {
      if (($result['state'] ?? '') === 'fulfilled' && !empty($result['value'])) {
        $existing[] = (string) $key;
        continue;
      }

      if (($result['state'] ?? '') === 'rejected') {
        $exception = $this->normalizePromiseReason($result['reason'] ?? null);
        $this->logger->warning('Async existence check failed for {key}: {error}', [
          'key' => (string) $key,
          'error' => $this->extractErrorMessage($exception),
        ]);
      }
    }

    return $existing;
  }

  private function normalizePromiseReason($reason): \Throwable
  {
    if ($reason instanceof \Throwable) {
      return $reason;
    }

    if (is_string($reason) && $reason !== '') {
      return new RuntimeException($reason);
    }

    return new RuntimeException('Unknown async S3 error.');
  }

  private function getAwsStatusCode(\Throwable $exception): ?int
  {
    if (method_exists($exception, 'getStatusCode')) {
      $status = $exception->getStatusCode();
      if ($status !== null) {
        return (int) $status;
      }
    }

    return null;
  }

  private function getAwsErrorCode(\Throwable $exception): ?string
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
