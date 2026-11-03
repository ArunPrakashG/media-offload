<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * MinIO and other S3-compatible services: endpoint plus keys. Nimbus lists the
 * buckets, creates one when needed and opens it for public media.
 */
final class S3EndpointConnector
{
  public function __construct(private QuickConnect $quickConnect, private string $provider)
  {
  }

  public function connect(array $input): ConnectResult
  {
    $endpoint = esc_url_raw(trim((string) ($input['endpoint'] ?? '')));
    $key = trim((string) ($input['access_key'] ?? ''));
    $secret = trim((string) ($input['secret_key'] ?? ''));
    $region = sanitize_text_field((string) ($input['region'] ?? ''));

    if ($endpoint === '' || $key === '' || $secret === '') {
      throw new QuickConnectException(__('Enter the endpoint, access key and secret key.', 'nimbus'));
    }

    $result = new ConnectResult();
    $s3 = $this->quickConnect->s3Client([
      'endpoint' => $endpoint,
      'region' => $region !== '' ? $region : 'us-east-1',
      'key' => $key,
      'secret' => $secret,
      'path_style' => true,
    ]);

    $buckets = new BucketSetup($this->quickConnect, $s3, $region);
    $bucket = $buckets->ensure((string) ($input['bucket'] ?? ''), false, $result);

    if ($region === '') {
      $region = $this->detectRegion($s3, $bucket);
    }

    $buckets->makePublic($bucket, $result);

    $result->values = [
      'provider' => $this->provider,
      'bucket' => $bucket,
      'endpoint' => $endpoint,
      'region' => $region !== '' ? $region : 'us-east-1',
      'access_key' => $key,
      'secret_key' => $secret,
      'session_token' => '',
      'use_path_style_endpoint' => true,
    ];

    if ($this->provider === 'minio') {
      $result->note = __('Nimbus is using the keys you entered. To give it a key limited to this bucket, run: mc admin user svcacct add ALIAS USER', 'nimbus');
    }

    return $result;
  }

  private function detectRegion(\Aws\S3\S3Client $s3, string $bucket): string
  {
    try {
      $region = (string) ($s3->getBucketLocation(['Bucket' => $bucket])['LocationConstraint'] ?? '');
    } catch (\Throwable $exception) {
      return '';
    }

    // Some servers answer with something other than a plain region name.
    return preg_match('/^[a-z0-9-]{2,32}$/', $region) ? $region : '';
  }
}
