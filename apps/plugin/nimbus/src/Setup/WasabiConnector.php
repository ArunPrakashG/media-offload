<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Wasabi from a root or admin access key. Wasabi speaks the AWS IAM API, so
 * Nimbus creates a user that can only reach the media bucket and saves its key.
 */
final class WasabiConnector
{
  public const REGIONS = [
    'us-east-1', 'us-east-2', 'us-central-1', 'us-west-1', 'us-west-2', 'ca-central-1',
    'eu-central-1', 'eu-central-2', 'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-south-1',
    'ap-northeast-1', 'ap-northeast-2', 'ap-southeast-1', 'ap-southeast-2',
  ];

  public function __construct(private QuickConnect $quickConnect)
  {
  }

  public function connect(array $input): ConnectResult
  {
    $key = trim((string) ($input['access_key'] ?? ''));
    $secret = trim((string) ($input['secret_key'] ?? ''));
    $region = sanitize_key((string) ($input['region'] ?? 'us-east-1'));

    if ($key === '' || $secret === '') {
      throw new QuickConnectException(esc_html__('Paste both the access key and the secret key.', 'nimbus'));
    }
    if (!in_array($region, self::REGIONS, true)) {
      throw new QuickConnectException(esc_html__('Pick a Wasabi region.', 'nimbus'));
    }

    $result = new ConnectResult();
    $s3 = $this->quickConnect->s3Client([
      'endpoint' => 'https://s3.' . $region . '.wasabisys.com',
      'region' => $region,
      'key' => $key,
      'secret' => $secret,
    ]);

    $buckets = new BucketSetup($this->quickConnect, $s3, $region);
    $bucket = $buckets->ensure((string) ($input['bucket'] ?? ''), true, $result);
    $buckets->makePublic($bucket, $result);

    $result->values = [
      'provider' => 'wasabi',
      'bucket' => $bucket,
      'region' => $region,
      'endpoint' => '',
      'session_token' => '',
    ];

    try {
      [$scopedKey, $scopedSecret] = $this->createScopedUser($bucket, $key, $secret);
      $result->values['access_key'] = $scopedKey;
      $result->values['secret_key'] = $scopedSecret;
      $result->step(__('New key made for this bucket only', 'nimbus'), __('The key you pasted is not saved', 'nimbus'));
    } catch (\Throwable $exception) {
      // A sub-user without IAM rights can still run Nimbus with its own key.
      $this->quickConnect->logger()->warning('Wasabi quick connect could not create a bucket-only user: {error}', ['error' => $this->quickConnect->errorMessage($exception)]);
      $result->values['access_key'] = $key;
      $result->values['secret_key'] = $secret;
      $result->step(__('Using the key you pasted', 'nimbus'), __('It cannot create users, so Nimbus saves it', 'nimbus'));
    }

    return $result;
  }

  /**
   * @return array{0: string, 1: string}
   */
  private function createScopedUser(string $bucket, string $key, string $secret): array
  {
    $user = substr('nimbus-' . $bucket, 0, 64);

    $created = $this->iam('CreateUser', ['UserName' => $user], $key, $secret);
    if ($created['error'] !== '' && $created['error'] !== 'EntityAlreadyExists') {
      throw new QuickConnectException(esc_html($created['error']));
    }

    $policy = $this->iam('PutUserPolicy', [
      'UserName' => $user,
      'PolicyName' => 'nimbus-media',
      'PolicyDocument' => BucketSetup::bucketOnlyPolicy($bucket),
    ], $key, $secret);
    if ($policy['error'] !== '') {
      throw new QuickConnectException(esc_html($policy['error']));
    }

    $accessKey = $this->iam('CreateAccessKey', ['UserName' => $user], $key, $secret);
    $node = $accessKey['xml']?->CreateAccessKeyResult->AccessKey;
    if ($accessKey['error'] !== '' || $node === null || (string) $node->AccessKeyId === '') {
      throw new QuickConnectException(esc_html($accessKey['error'] !== '' ? $accessKey['error'] : 'CreateAccessKey returned no key'));
    }

    return [(string) $node->AccessKeyId, (string) $node->SecretAccessKey];
  }

  /**
   * One call to Wasabi's IAM API. Signed with the SDK's SigV4 signer, since the
   * bundled SDK only ships the S3 client.
   *
   * @param array<string, string> $params
   * @return array{error: string, xml: \SimpleXMLElement|null}
   */
  private function iam(string $action, array $params, string $key, string $secret): array
  {
    $url = 'https://iam.wasabisys.com/';
    $body = http_build_query(['Action' => $action, 'Version' => '2010-05-08'] + $params, '', '&', PHP_QUERY_RFC3986);
    $request = new \GuzzleHttp\Psr7\Request('POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'], $body);
    $signed = (new \Aws\Signature\SignatureV4('iam', 'us-east-1'))->signRequest($request, new \Aws\Credentials\Credentials($key, $secret));

    $headers = [];
    foreach ($signed->getHeaders() as $name => $values) {
      if (strtolower($name) !== 'host') {
        $headers[$name] = implode(', ', $values);
      }
    }

    $response = wp_remote_post($url, ['timeout' => 25, 'headers' => $headers, 'body' => $body]);
    if (is_wp_error($response)) {
      return ['error' => $response->get_error_message(), 'xml' => null];
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string) wp_remote_retrieve_body($response)) ?: null;
    libxml_use_internal_errors($previous);

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status >= 200 && $status < 300) {
      return ['error' => '', 'xml' => $xml];
    }

    $code = $xml !== null ? (string) $xml->Error->Code : '';

    return ['error' => $code !== '' ? $code : 'HTTP ' . $status, 'xml' => $xml];
  }
}
