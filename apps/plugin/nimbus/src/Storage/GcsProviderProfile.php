<?php
namespace Nimbus\Plugin\Storage;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Google Cloud Storage provider profile (HMAC / S3-compatible XML API).
 *
 * GCS exposes an S3-compatible API via XML at:
 *   https://storage.googleapis.com
 *
 * Authentication uses HMAC keys generated in the GCS console under
 * IAM → Service Accounts → HMAC keys. These are not the same as a service
 * account JSON file; they are an access-key / secret pair that work with
 * the S3-compatible endpoint.
 *
 * Uses the generic S3StorageAdapter with path-style endpoint enabled,
 * which is required by the GCS S3-compatible API.
 */
final class GcsProviderProfile extends AbstractProviderProfile implements ProviderProfile
{
  private const GCS_ENDPOINT = 'https://storage.googleapis.com';

  public function key(): string
  {
    return 'gcs';
  }

  public function adapterType(): string
  {
    return 's3';
  }

  public function label(): string
  {
    return __('Google Cloud Storage', 'nimbus');
  }

  public function title(): string
  {
    return __('Google Cloud Storage', 'nimbus');
  }

  public function summary(): string
  {
    return __('Google Cloud Storage via the S3-compatible XML API. Requires an HMAC key pair generated from IAM → Service Accounts in the GCS console. Uniform bucket-level access must be enabled and the service account must have the Storage Object Admin role.', 'nimbus');
  }

  public function requiredFields(): array
  {
    return [
      'bucket' => __('Bucket Name', 'nimbus'),
      'region' => __('Location (e.g. us-central1, europe-west1)', 'nimbus'),
      'access_key' => __('HMAC Access Key', 'nimbus'),
      'secret_key' => __('HMAC Secret', 'nimbus'),
    ];
  }

  public function fieldHints(): array
  {
    return [
      'bucket'        => __('Your GCS bucket name. Uniform bucket-level access must be enabled.', 'nimbus'),
      'region'        => __('GCS location for metadata, e.g. us-central1, europe-west1, US, EU. The API endpoint is always storage.googleapis.com.', 'nimbus'),
      'access_key'    => __('HMAC Access Key from the GCS console: IAM → Service Accounts → select account → HMAC Keys tab.', 'nimbus'),
      'secret_key'    => __('HMAC Secret shown once at creation time in the GCS console, paired with the access key above.', 'nimbus'),
      'session_token' => '',
      'endpoint'      => __('Pre-filled to https://storage.googleapis.com — change only if using a custom endpoint.', 'nimbus'),
      'r2_account_id' => '',
    ];
  }

  public function normalize(array $values): array
  {
    $values = $this->normalizeCommon($values);
    $values['provider'] = $this->key();

    // Always use the GCS S3-compatible endpoint.
    $values['endpoint'] = self::GCS_ENDPOINT;

    // GCS S3-compatible API requires path-style URLs.
    $values['use_path_style_endpoint'] = true;

    // GCS does not use session tokens.
    $values['session_token'] = '';

    return $values;
  }

  public function hasValidCredentials(array $values): bool
  {
    $values = $this->normalize($values);

    return $values['bucket'] !== ''
      && $values['access_key'] !== ''
      && $values['secret_key'] !== '';
  }
}
