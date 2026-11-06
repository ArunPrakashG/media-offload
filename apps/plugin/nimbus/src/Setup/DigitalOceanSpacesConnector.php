<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * DigitalOcean Spaces from one personal access token with Spaces key scopes.
 *
 * Nimbus makes a short-lived full-access Spaces key to create and open the
 * bucket, then a read/write key for that bucket only, and deletes the first.
 */
final class DigitalOceanSpacesConnector
{
  private const API = 'https://api.digitalocean.com/v2';

  public const REGIONS = ['nyc3', 'sfo2', 'sfo3', 'ams3', 'fra1', 'lon1', 'sgp1', 'syd1', 'blr1', 'tor1', 'atl1'];

  private string $token = '';

  public function __construct(private QuickConnect $quickConnect)
  {
  }

  public static function tokenUrl(): string
  {
    return 'https://cloud.digitalocean.com/account/api/tokens/new';
  }

  public function connect(array $input): ConnectResult
  {
    $this->token = trim((string) ($input['token'] ?? ''));
    $region = sanitize_key((string) ($input['region'] ?? 'nyc3'));

    if ($this->token === '') {
      throw new QuickConnectException(esc_html__('Paste the DigitalOcean token first.', 'arun-prakash-media-offload'));
    }
    if (!in_array($region, self::REGIONS, true)) {
      throw new QuickConnectException(esc_html__('Pick a Spaces region.', 'arun-prakash-media-offload'));
    }

    $result = new ConnectResult();
    $setupKey = $this->createKey('nimbus-setup', [['bucket' => '', 'permission' => 'fullaccess']]);
    $result->step(__('Token verified', 'arun-prakash-media-offload'));

    try {
      $s3 = $this->quickConnect->s3Client([
        'endpoint' => 'https://' . $region . '.digitaloceanspaces.com',
        'region' => 'us-east-1',
        'key' => $setupKey['access_key'],
        'secret' => $setupKey['secret_key'],
      ]);

      $buckets = new BucketSetup($this->quickConnect, $s3);
      $bucket = $this->withRetry(fn(): string => $buckets->ensure((string) ($input['bucket'] ?? ''), true, $result));
      $buckets->makePublic($bucket, $result);

      $scoped = $this->createKey('nimbus-' . $bucket, [['bucket' => $bucket, 'permission' => 'readwrite']]);
      $result->step(__('New key made for this bucket only', 'arun-prakash-media-offload'), __('The token you pasted is not saved', 'arun-prakash-media-offload'));
    } finally {
      $this->request('DELETE', '/spaces/keys/' . rawurlencode($setupKey['access_key']));
    }

    $result->values = [
      'provider' => 'digitalocean_spaces',
      'bucket' => $bucket,
      'region' => $region,
      'endpoint' => '',
      'session_token' => '',
      'access_key' => $scoped['access_key'],
      'secret_key' => $scoped['secret_key'],
    ];

    return $result;
  }

  /**
   * @param array<int, array{bucket: string, permission: string}> $grants
   * @return array{access_key: string, secret_key: string}
   */
  private function createKey(string $name, array $grants): array
  {
    $response = $this->request('POST', '/spaces/keys', ['name' => substr($name, 0, 64), 'grants' => $grants]);
    $key = (array) ($response['body']['key'] ?? []);

    if ($response['status'] === 401 || $response['status'] === 403) {
      throw new QuickConnectException(esc_html__('DigitalOcean did not accept this token. It needs the spaces_key create, read and delete scopes.', 'arun-prakash-media-offload'));
    }

    if (empty($key['access_key']) || empty($key['secret_key'])) {
      throw new QuickConnectException(esc_html(__('Could not create a Spaces key', 'arun-prakash-media-offload') . ': ' . (string) ($response['body']['message'] ?? $response['status'])));
    }

    return ['access_key' => (string) $key['access_key'], 'secret_key' => (string) $key['secret_key']];
  }

  /**
   * A new Spaces key can take a few seconds to work.
   */
  private function withRetry(callable $step): string
  {
    for ($attempt = 1; ; $attempt++) {
      try {
        return $step();
      } catch (QuickConnectException $exception) {
        if ($attempt >= 5) {
          throw $exception;
        }
        sleep(3);
      }
    }
  }

  /**
   * @return array{status: int, body: array<mixed>|null}
   */
  private function request(string $method, string $path, ?array $body = null): array
  {
    return $this->quickConnect->http($method, self::API . $path, ['Authorization' => 'Bearer ' . $this->token], $body);
  }
}
