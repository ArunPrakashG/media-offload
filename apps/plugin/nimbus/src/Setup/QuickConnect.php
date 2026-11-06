<?php
namespace Nimbus\Plugin\Setup;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Storage\StorageAdapterFactory;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Sets up storage from as little as one pasted token or key.
 *
 * Each provider connector creates or picks a bucket, prepares it for public
 * media, and returns the values the setup wizard should save. Tokens and keys
 * pasted here are only used for this request and never stored; only the final
 * (bucket-scoped where the provider allows it) credentials end up in settings.
 */
final class QuickConnect
{
  public const PROVIDERS = ['r2', 'backblaze_b2', 'wasabi', 'digitalocean_spaces', 'minio', 's3_compatible', 's3', 'gcs'];

  public function __construct(private Settings $settings, private Logger $logger)
  {
  }

  /**
   * @param array<string, string> $input
   */
  public function connect(string $provider, array $input): ConnectResult
  {
    $connector = match ($provider) {
      'r2' => new CloudflareR2Connector($this),
      'backblaze_b2' => new BackblazeB2Connector($this),
      'wasabi' => new WasabiConnector($this),
      'digitalocean_spaces' => new DigitalOceanSpacesConnector($this),
      'minio', 's3_compatible' => new S3EndpointConnector($this, $provider),
      's3', 'gcs' => new ShellConnector($this, $provider),
      default => throw new QuickConnectException(esc_html__('Quick connect is not available for this provider.', 'usenimbus-media-offload')),
    };

    if (function_exists('set_time_limit')) {
      // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- New keys can take up to a minute to start working.
      @set_time_limit(180);
    }

    $result = $connector->connect($input);

    if ($result->values !== []) {
      $this->verifyWrite($result, $provider === 'gcs' ? 14 : 8);
      $result->step(__('Upload test passed', 'usenimbus-media-offload'));
    }

    return $result;
  }

  /**
   * A bucket name derived from the site's host, e.g. "example-com-media".
   * Providers with one namespace for every customer get a random suffix.
   */
  public function defaultBucketName(bool $globallyUnique): string
  {
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $host), '-');
    $slug = substr($slug !== '' ? $slug : 'wordpress', 0, 40);
    $name = rtrim($slug, '-') . '-media';

    if ($globallyUnique) {
      $name .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    return $name;
  }

  /**
   * A bucket an earlier run already made for this site, so connecting again
   * does not leave a trail of new buckets.
   *
   * @param string[] $buckets
   */
  public function previousDefaultBucket(array $buckets): string
  {
    $pattern = '/^' . preg_quote($this->defaultBucketName(false), '/') . '(-[0-9a-f]{6})?$/';

    foreach ($buckets as $bucket) {
      if (preg_match($pattern, $bucket)) {
        return $bucket;
      }
    }

    return '';
  }

  public function siteOrigin(): string
  {
    $parts = wp_parse_url(home_url());

    return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
  }

  /**
   * JSON request to a provider API.
   *
   * @param array<string, string> $headers
   * @return array{status: int, body: array<mixed>|null}
   */
  public function http(string $method, string $url, array $headers = [], ?array $body = null): array
  {
    $args = [
      'method' => $method,
      'timeout' => 25,
      'headers' => $headers + ['Accept' => 'application/json'],
    ];

    if ($body !== null) {
      $args['headers']['Content-Type'] = 'application/json';
      $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
      /* translators: %s: network error message */
      throw new QuickConnectException(esc_html(sprintf(__('Could not reach the provider: %s', 'usenimbus-media-offload'), $response->get_error_message())));
    }

    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

    return [
      'status' => (int) wp_remote_retrieve_response_code($response),
      'body' => is_array($decoded) ? $decoded : null,
    ];
  }

  /**
   * @param array<string, mixed> $config endpoint, region, key, secret, path_style
   */
  public function s3Client(array $config): \Aws\S3\S3Client
  {
    if (!class_exists('\\Aws\\S3\\S3Client')) {
      throw new QuickConnectException(esc_html__('The AWS SDK bundled with Nimbus is missing.', 'usenimbus-media-offload'));
    }

    $clientConfig = [
      'version' => 'latest',
      'region' => (string) ($config['region'] ?? 'us-east-1') ?: 'us-east-1',
      'signature_version' => 'v4',
      'credentials' => ['key' => (string) $config['key'], 'secret' => (string) $config['secret']],
      'use_path_style_endpoint' => (bool) ($config['path_style'] ?? false),
      'http' => ['timeout' => 25, 'connect_timeout' => 10],
    ];

    if (!empty($config['endpoint'])) {
      $clientConfig['endpoint'] = (string) $config['endpoint'];
    }

    return new \Aws\S3\S3Client($clientConfig);
  }

  /**
   * Public-read policy for every object in the bucket.
   */
  public function publicReadPolicy(string $bucket): string
  {
    return (string) wp_json_encode([
      'Version' => '2012-10-17',
      'Statement' => [[
        'Sid' => 'NimbusPublicRead',
        'Effect' => 'Allow',
        'Principal' => '*',
        'Action' => ['s3:GetObject'],
        'Resource' => ['arn:aws:s3:::' . $bucket . '/*'],
      ]],
    ], JSON_UNESCAPED_SLASHES);
  }

  /**
   * CORS rule that lets any page read the media (fonts, canvas images).
   */
  public function s3CorsRules(): array
  {
    return [[
      'AllowedMethods' => ['GET', 'HEAD'],
      'AllowedOrigins' => ['*'],
      'AllowedHeaders' => ['*'],
      'MaxAgeSeconds' => 86400,
    ]];
  }

  /**
   * Readable message from an AWS SDK or other exception.
   */
  public function errorMessage(\Throwable $exception): string
  {
    if ($exception instanceof \Aws\Exception\AwsException) {
      return (string) ($exception->getAwsErrorMessage() ?: $exception->getAwsErrorCode() ?: $exception->getMessage());
    }

    return $exception->getMessage();
  }

  public function logger(): Logger
  {
    return $this->logger;
  }

  /**
   * Upload, read and delete a test object with the final values. New keys can
   * take a little while to work, so this retries before giving up.
   */
  private function verifyWrite(ConnectResult $result, int $attempts): void
  {
    $factory = new StorageAdapterFactory($this->settings, $this->logger);
    $lastError = '';

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
      try {
        $adapter = $factory->fromConfiguration($this->settings->adapterConfiguration($result->values));
        if (!method_exists($adapter, 'testConnection') || $adapter->testConnection()) {
          return;
        }
      } catch (\Throwable $exception) {
        $lastError = $exception->getMessage();
      }

      if ($attempt < $attempts) {
        sleep(min(2 + $attempt, 6));
      }
    }

    /* translators: %s: error from the storage provider */
    throw new QuickConnectException(esc_html(sprintf(__('Everything was set up, but the upload test failed: %s', 'usenimbus-media-offload'), $lastError)));
  }
}
