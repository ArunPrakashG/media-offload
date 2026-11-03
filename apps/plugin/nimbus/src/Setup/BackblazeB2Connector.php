<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Backblaze B2 from one application key that can manage buckets and keys.
 * Nimbus creates a key limited to the media bucket and only that key is saved.
 */
final class BackblazeB2Connector
{
  private const AUTHORIZE_URL = 'https://api.backblazeb2.com/b2api/v4/b2_authorize_account';

  private string $apiUrl = '';
  private string $authToken = '';
  private string $accountId = '';

  public function __construct(private QuickConnect $quickConnect)
  {
  }

  public function connect(array $input): ConnectResult
  {
    $keyId = trim((string) ($input['key_id'] ?? ''));
    $key = trim((string) ($input['key'] ?? ''));

    if ($keyId === '' || $key === '') {
      throw new QuickConnectException(esc_html__('Paste both the keyID and the applicationKey.', 'nimbus-media-offload'));
    }

    $result = new ConnectResult();
    $auth = $this->quickConnect->http('GET', self::AUTHORIZE_URL, [
      'Authorization' => 'Basic ' . base64_encode($keyId . ':' . $key),
    ]);

    if ($auth['status'] !== 200 || empty($auth['body']['authorizationToken'])) {
      throw new QuickConnectException(esc_html(__('Backblaze did not accept this key', 'nimbus-media-offload') . ': ' . $this->apiError($auth['body'])));
    }

    $storage = (array) ($auth['body']['apiInfo']['storageApi'] ?? []);
    $this->apiUrl = (string) ($storage['apiUrl'] ?? '');
    $this->authToken = (string) $auth['body']['authorizationToken'];
    $this->accountId = (string) ($auth['body']['accountId'] ?? '');
    $region = $this->regionFromS3Url((string) ($storage['s3ApiUrl'] ?? ''));
    $capabilities = (array) ($storage['allowed']['capabilities'] ?? []);
    $restrictedTo = (array) ($storage['allowed']['buckets'] ?? []);

    $result->step(__('Key verified', 'nimbus-media-offload'), $region);

    $values = [
      'provider' => 'backblaze_b2',
      'region' => $region,
      'endpoint' => '',
      'session_token' => '',
    ];

    // A key already limited to one bucket is used as is.
    if (count($restrictedTo) === 1 && !in_array('writeKeys', $capabilities, true)) {
      $bucket = (string) ($restrictedTo[0]['name'] ?? '');
      $result->buckets = [$bucket];
      $result->step(__('Using bucket', 'nimbus-media-offload'), $bucket);
      $result->values = $values + ['bucket' => $bucket, 'access_key' => $keyId, 'secret_key' => $key];
      return $result;
    }

    foreach (['listBuckets', 'writeBuckets', 'writeKeys'] as $needed) {
      if (!in_array($needed, $capabilities, true)) {
        throw new QuickConnectException(esc_html__('This key cannot create buckets and keys. In Backblaze, create a key for All buckets with Read and Write access.', 'nimbus-media-offload'));
      }
    }

    $buckets = [];
    foreach ((array) ($this->api('b2_list_buckets', ['accountId' => $this->accountId])['buckets'] ?? []) as $bucket) {
      $buckets[(string) $bucket['bucketName']] = $bucket;
    }
    $result->buckets = array_keys($buckets);

    $name = sanitize_text_field((string) ($input['bucket'] ?? ''));
    if ($name === '') {
      $name = $this->quickConnect->previousDefaultBucket($result->buckets);
    }
    if ($name !== '' && isset($buckets[$name])) {
      $bucket = $buckets[$name];
      $result->public = ($bucket['bucketType'] ?? '') === 'allPublic';
      $result->step(__('Using bucket', 'nimbus-media-offload'), $name);
    } else {
      $bucket = $this->createBucket($name !== '' ? $name : $this->quickConnect->defaultBucketName(true), $result);
      $result->buckets[] = (string) $bucket['bucketName'];
    }

    $scoped = $this->api('b2_create_key', [
      'accountId' => $this->accountId,
      'keyName' => substr('nimbus-' . $bucket['bucketName'], 0, 100),
      'capabilities' => ['listBuckets', 'listFiles', 'readFiles', 'writeFiles', 'deleteFiles'],
      'bucketIds' => [(string) $bucket['bucketId']],
    ]);
    $result->step(__('New key made for this bucket only', 'nimbus-media-offload'), __('The key you pasted is not saved', 'nimbus-media-offload'));

    $result->values = $values + [
      'bucket' => (string) $bucket['bucketName'],
      'access_key' => (string) ($scoped['applicationKeyId'] ?? ''),
      'secret_key' => (string) ($scoped['applicationKey'] ?? ''),
    ];

    return $result;
  }

  /**
   * Public bucket with CORS for media. New Backblaze accounts can't make public
   * buckets until they have a payment history, so fall back to private.
   *
   * @return array<string, mixed>
   */
  private function createBucket(string $name, ConnectResult $result): array
  {
    $request = [
      'accountId' => $this->accountId,
      'bucketName' => $name,
      'bucketType' => 'allPublic',
      'corsRules' => [[
        'corsRuleName' => 'nimbusMedia',
        'allowedOrigins' => ['*'],
        'allowedOperations' => ['s3_get', 's3_head', 'b2_download_file_by_name', 'b2_download_file_by_id'],
        'allowedHeaders' => ['*'],
        'maxAgeSeconds' => 86400,
      ]],
    ];

    try {
      $bucket = $this->api('b2_create_bucket', $request);
      $result->step(__('Public bucket created', 'nimbus-media-offload'), $name);
      return $bucket;
    } catch (QuickConnectException $exception) {
      $request['bucketType'] = 'allPrivate';
      $bucket = $this->api('b2_create_bucket', $request);
      $result->public = false;
      $result->step(__('Private bucket created', 'nimbus-media-offload'), $name);
      $result->note = __('Backblaze only allows public buckets once the account has a payment history. Make the bucket public in Backblaze before serving media from it.', 'nimbus-media-offload');
      return $bucket;
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function api(string $operation, array $body): array
  {
    $response = $this->quickConnect->http('POST', rtrim($this->apiUrl, '/') . '/b2api/v4/' . $operation, ['Authorization' => $this->authToken], $body);

    if ($response['status'] !== 200 || !is_array($response['body'])) {
      throw new QuickConnectException(esc_html(sprintf('%s: %s', $operation, $this->apiError($response['body']))));
    }

    return $response['body'];
  }

  private function regionFromS3Url(string $url): string
  {
    $host = (string) wp_parse_url($url, PHP_URL_HOST);

    return preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/', $host, $matches) ? $matches[1] : '';
  }

  private function apiError(?array $body): string
  {
    return (string) ($body['message'] ?? $body['code'] ?? __('unexpected response from Backblaze', 'nimbus-media-offload'));
  }
}
