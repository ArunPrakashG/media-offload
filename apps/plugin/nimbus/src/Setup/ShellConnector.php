<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Amazon S3 and Google Cloud Storage: neither lets a self-hosted plugin sign
 * in on the user's behalf without a hosted OAuth broker. Instead Nimbus writes
 * one command for the provider's own browser shell (AWS CloudShell, Google
 * Cloud Shell). It creates the bucket and a bucket-only identity, then prints
 * one JSON line that the admin pastes back here.
 */
final class ShellConnector
{
  public const S3_REGIONS = [
    'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2', 'ca-central-1', 'sa-east-1',
    'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-central-1', 'eu-north-1',
    'ap-south-1', 'ap-southeast-1', 'ap-southeast-2', 'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3',
  ];

  public const GCS_LOCATIONS = [
    'us-central1', 'us-east1', 'us-west1', 'northamerica-northeast1', 'southamerica-east1',
    'europe-west1', 'europe-west2', 'europe-west3', 'europe-north1',
    'asia-south1', 'asia-southeast1', 'asia-northeast1', 'australia-southeast1', 'us', 'eu', 'asia',
  ];

  public const SHELL_URLS = [
    's3' => 'https://console.aws.amazon.com/cloudshell/home',
    'gcs' => 'https://shell.cloud.google.com/?show=terminal',
  ];

  public function __construct(private QuickConnect $quickConnect, private string $provider)
  {
  }

  /**
   * The command with the region left as __REGION__, which the wizard fills in
   * as the admin picks one.
   */
  public static function script(string $provider, string $bucket): string
  {
    $bucket = self::validBucket($bucket) ? $bucket : 'nimbus-media';

    if ($provider === 'gcs') {
      $cors = '[{"origin":["*"],"method":["GET","HEAD"],"responseHeader":["*"],"maxAgeSeconds":86400}]';

      return implode("\n", [
        '(',
        'set -e',
        'P=$(gcloud config get-value project 2>/dev/null)',
        '[ -n "$P" ] || { echo "Choose a project first: gcloud config set project PROJECT_ID"; exit 1; }',
        'B=' . $bucket . '; L=__REGION__; SA="nimbus-media@$P.iam.gserviceaccount.com"; PUB=true',
        'gcloud storage buckets create "gs://$B" --location="$L" --uniform-bucket-level-access --quiet',
        'gcloud storage buckets add-iam-policy-binding "gs://$B" --member=allUsers --role=roles/storage.objectViewer --quiet >/dev/null || PUB=false',
        "echo '" . $cors . "' > /tmp/nimbus-cors.json",
        'gcloud storage buckets update "gs://$B" --cors-file=/tmp/nimbus-cors.json --quiet',
        'gcloud iam service-accounts describe "$SA" >/dev/null 2>&1 || gcloud iam service-accounts create nimbus-media --display-name="Nimbus media" --quiet',
        'for i in 1 2 3 4 5 6; do gcloud storage buckets add-iam-policy-binding "gs://$B" --member="serviceAccount:$SA" --role=roles/storage.objectAdmin --quiet >/dev/null && break; sleep 5; done',
        'read K S < <(gcloud storage hmac create "$SA" --format="value(metadata.accessId,secret)")',
        'echo; echo "{\"nimbus\":1,\"provider\":\"gcs\",\"bucket\":\"$B\",\"region\":\"$L\",\"access_key\":\"$K\",\"secret_key\":\"$S\",\"public\":$PUB}"',
        ')',
      ]);
    }

    $publicPolicy = '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":"*","Action":"s3:GetObject","Resource":"arn:aws:s3:::' . $bucket . '/*"}]}';
    $cors = '{"CORSRules":[{"AllowedMethods":["GET","HEAD"],"AllowedOrigins":["*"],"AllowedHeaders":["*"],"MaxAgeSeconds":86400}]}';

    return implode("\n", [
      '(',
      'set -e',
      'B=' . $bucket . '; R=__REGION__; U="nimbus-$B"; PUB=true',
      'if [ "$R" = us-east-1 ]; then aws s3api create-bucket --bucket "$B" --region "$R" >/dev/null; else aws s3api create-bucket --bucket "$B" --region "$R" --create-bucket-configuration LocationConstraint="$R" >/dev/null; fi',
      'aws s3api put-public-access-block --bucket "$B" --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=false,RestrictPublicBuckets=false',
      "aws s3api put-bucket-policy --bucket \"\$B\" --policy '" . $publicPolicy . "' || PUB=false",
      "aws s3api put-bucket-cors --bucket \"\$B\" --cors-configuration '" . $cors . "'",
      'aws iam create-user --user-name "$U" >/dev/null',
      "aws iam put-user-policy --user-name \"\$U\" --policy-name nimbus-media --policy-document '" . BucketSetup::bucketOnlyPolicy($bucket) . "'",
      'read K S < <(aws iam create-access-key --user-name "$U" --query "AccessKey.[AccessKeyId,SecretAccessKey]" --output text)',
      'echo; echo "{\"nimbus\":1,\"provider\":\"s3\",\"bucket\":\"$B\",\"region\":\"$R\",\"access_key\":\"$K\",\"secret_key\":\"$S\",\"public\":$PUB}"',
      ')',
    ]);
  }

  public function connect(array $input): ConnectResult
  {
    $output = trim((string) ($input['output'] ?? ''));
    // Take the JSON line even if the admin copied some of the shell around it.
    if (preg_match('/\{[^{}]*"nimbus"\s*:\s*1[^{}]*\}/', $output, $matches)) {
      $output = $matches[0];
    }

    $data = json_decode($output, true);
    if (!is_array($data) || (int) ($data['nimbus'] ?? 0) !== 1) {
      throw new QuickConnectException(__('Paste the line the command printed. It starts with {"nimbus":1', 'nimbus'));
    }

    if (($data['provider'] ?? '') !== $this->provider) {
      throw new QuickConnectException(__('That line was made for a different provider. Run the command shown above.', 'nimbus'));
    }

    $bucket = (string) ($data['bucket'] ?? '');
    $region = (string) ($data['region'] ?? '');
    $key = trim((string) ($data['access_key'] ?? ''));
    $secret = trim((string) ($data['secret_key'] ?? ''));

    if (!self::validBucket($bucket) || $key === '' || $secret === '' || !preg_match('/^[a-z0-9-]+$/', $region)) {
      throw new QuickConnectException(__('The pasted line is incomplete. Run the command again and copy the whole last line.', 'nimbus'));
    }

    $result = new ConnectResult();
    $result->buckets = [$bucket];
    $result->public = !empty($data['public']);
    $result->step(__('Bucket found', 'nimbus'), $bucket . ' · ' . $region);
    $result->step(__('Keys belong to a user that can only reach this bucket', 'nimbus'));

    if (!$result->public) {
      $result->note = $this->provider === 'gcs'
        ? __('Your organization blocks public buckets, so visitors cannot load files straight from it. Serve media through a CDN, or allow public access for this bucket.', 'nimbus')
        : __('Your AWS account blocks public buckets, so visitors cannot load files straight from it. Turn off "Block public access" for this bucket, or serve media through CloudFront.', 'nimbus');
    }

    $result->values = [
      'provider' => $this->provider,
      'bucket' => $bucket,
      'region' => $this->provider === 'gcs' ? 'auto' : $region,
      'endpoint' => '',
      'access_key' => $key,
      'secret_key' => $secret,
      'session_token' => '',
    ];

    return $result;
  }

  private static function validBucket(string $bucket): bool
  {
    return (bool) preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket);
  }
}
