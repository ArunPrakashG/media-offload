<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Bucket steps shared by every S3-API provider: pick or create the bucket,
 * then open it for public reads and cross-origin media.
 */
final class BucketSetup
{
  /**
   * @param string $locationConstraint Region sent with CreateBucket; empty to omit it.
   */
  public function __construct(
    private QuickConnect $quickConnect,
    private \Aws\S3\S3Client $client,
    private string $locationConstraint = '',
  ) {
  }

  /**
   * Use the named bucket when it exists, else create it (or a default name).
   */
  public function ensure(string $requested, bool $globallyUnique, ConnectResult $result): string
  {
    $requested = strtolower(sanitize_text_field($requested));

    try {
      $listing = $this->client->listBuckets();
    } catch (\Throwable $exception) {
      throw new QuickConnectException(esc_html(__('These keys were not accepted', 'plume-media-offload') . ': ' . $this->quickConnect->errorMessage($exception)));
    }

    $result->step(__('Keys verified', 'plume-media-offload'));

    foreach ((array) ($listing['Buckets'] ?? []) as $bucket) {
      $result->buckets[] = (string) $bucket['Name'];
    }

    $name = $requested;
    if ($name === '') {
      $name = $this->quickConnect->previousDefaultBucket($result->buckets) ?: $this->quickConnect->defaultBucketName($globallyUnique);
    }

    if (in_array($name, $result->buckets, true)) {
      $result->step(__('Using bucket', 'plume-media-offload'), $name);
      return $name;
    }

    $args = ['Bucket' => $name];
    if ($this->locationConstraint !== '' && $this->locationConstraint !== 'us-east-1') {
      $args['CreateBucketConfiguration'] = ['LocationConstraint' => $this->locationConstraint];
    }

    try {
      $this->client->createBucket($args);
    } catch (\Aws\S3\Exception\S3Exception $exception) {
      if ($exception->getAwsErrorCode() === 'BucketAlreadyOwnedByYou') {
        $result->buckets[] = $name;
        $result->step(__('Using bucket', 'plume-media-offload'), $name);
        return $name;
      }
      if ($exception->getAwsErrorCode() === 'BucketAlreadyExists') {
        throw new QuickConnectException(esc_html(sprintf(
          /* translators: %s: bucket name */
          __('The bucket name %s is taken by another account. Choose another name.', 'plume-media-offload'),
          $name
        )));
      }
      throw new QuickConnectException(esc_html(__('Could not create the bucket', 'plume-media-offload') . ': ' . $this->quickConnect->errorMessage($exception)));
    } catch (\Throwable $exception) {
      throw new QuickConnectException(esc_html(__('Could not create the bucket', 'plume-media-offload') . ': ' . $this->quickConnect->errorMessage($exception)));
    }

    $result->buckets[] = $name;
    $result->step(__('Bucket created', 'plume-media-offload'), $name);

    return $name;
  }

  /**
   * CORS and a public-read policy. Both are best effort: some services or
   * accounts refuse public policies, and the admin is told what to do instead.
   */
  public function makePublic(string $bucket, ConnectResult $result): void
  {
    try {
      $this->client->putBucketCors(['Bucket' => $bucket, 'CORSConfiguration' => ['CORSRules' => $this->quickConnect->s3CorsRules()]]);
    } catch (\Throwable $exception) {
      $this->quickConnect->logger()->warning('Quick connect could not set CORS on {bucket}: {error}', [
        'bucket' => $bucket,
        'error' => $this->quickConnect->errorMessage($exception),
      ]);
    }

    try {
      $this->client->putBucketPolicy(['Bucket' => $bucket, 'Policy' => $this->quickConnect->publicReadPolicy($bucket)]);
      $result->step(__('Public reads on', 'plume-media-offload'), __('Files can be loaded by visitors', 'plume-media-offload'));
    } catch (\Throwable $exception) {
      $result->public = false;
      $result->note = __('The provider refused a public-read policy for this bucket. Make it public in the provider console, or serve media through a CDN.', 'plume-media-offload');
      $this->quickConnect->logger()->warning('Quick connect could not make {bucket} public: {error}', [
        'bucket' => $bucket,
        'error' => $this->quickConnect->errorMessage($exception),
      ]);
    }
  }

  /**
   * IAM policy for a user that can only work inside one bucket.
   */
  public static function bucketOnlyPolicy(string $bucket): string
  {
    return (string) wp_json_encode([
      'Version' => '2012-10-17',
      'Statement' => [
        [
          'Effect' => 'Allow',
          'Action' => ['s3:ListBucket', 's3:GetBucketLocation'],
          'Resource' => ['arn:aws:s3:::' . $bucket],
        ],
        [
          'Effect' => 'Allow',
          'Action' => ['s3:PutObject', 's3:GetObject', 's3:DeleteObject', 's3:PutObjectAcl'],
          'Resource' => ['arn:aws:s3:::' . $bucket . '/*'],
        ],
      ],
    ], JSON_UNESCAPED_SLASHES);
  }
}
