<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Cloudflare R2 from one API token with "Workers R2 Storage: Edit".
 *
 * R2's S3 credentials come straight from the token: the access key is the
 * token id and the secret is the SHA-256 of the token value.
 */
final class CloudflareR2Connector
{
  private const API = 'https://api.cloudflare.com/client/v4';

  private string $token = '';

  public function __construct(private QuickConnect $quickConnect)
  {
  }

  /**
   * Cloudflare dashboard link that opens the token form with the R2 permission filled in.
   */
  public static function tokenUrl(string $name): string
  {
    $permissions = wp_json_encode([['key' => 'workers_r2', 'type' => 'edit']]);

    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A link to the Cloudflare dashboard, not a loaded asset.
    return 'https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=' . rawurlencode((string) $permissions)
      . '&accountId=*&zoneId=all&name=' . rawurlencode($name);
  }

  public function connect(array $input): ConnectResult
  {
    $this->token = trim((string) ($input['token'] ?? ''));
    if ($this->token === '') {
      throw new QuickConnectException(esc_html__('Paste the Cloudflare API token first.', 'nimbus-media-offload'));
    }

    $result = new ConnectResult();
    $accounts = $this->accounts();
    $tokenId = $this->tokenId($accounts);

    $account = $this->pickAccount($accounts, (string) ($input['account_id'] ?? ''), $result);
    if ($account === null) {
      $result->step(__('Token verified', 'nimbus-media-offload'));
      return $result;
    }

    $result->step(__('Token verified', 'nimbus-media-offload'), $account['name']);
    $accountId = $account['id'];

    $existing = $this->bucketNames($accountId);
    $result->buckets = $existing;
    $bucket = sanitize_text_field((string) ($input['bucket'] ?? ''));

    if ($bucket === '') {
      $bucket = $this->quickConnect->defaultBucketName(false);
    }

    if (in_array($bucket, $existing, true)) {
      $result->step(__('Using bucket', 'nimbus-media-offload'), $bucket);
    } else {
      $this->call('POST', "/accounts/{$accountId}/r2/buckets", ['name' => $bucket], __('Could not create the bucket', 'nimbus-media-offload'));
      $result->buckets[] = $bucket;
      $result->step(__('Bucket created', 'nimbus-media-offload'), $bucket);
    }

    $this->setCors($accountId, $bucket);
    $publicUrl = $this->publicUrl($accountId, $bucket, $result);

    $result->values = [
      'provider' => 'r2',
      'bucket' => $bucket,
      'r2_account_id' => $accountId,
      'region' => 'auto',
      'endpoint' => '',
      'access_key' => $tokenId,
      'secret_key' => hash('sha256', $this->token),
      'session_token' => '',
      'use_path_style_endpoint' => true,
      'custom_domain' => $publicUrl,
    ];

    return $result;
  }

  /**
   * @return array<int, array{id: string, name: string}>
   */
  private function accounts(): array
  {
    $response = $this->request('GET', '/accounts?per_page=50');
    if (in_array($response['status'], [400, 401, 403], true)) {
      throw new QuickConnectException(esc_html__('Cloudflare did not accept this token. Copy it again, it is only shown once.', 'nimbus-media-offload'));
    }

    if ($response['status'] !== 200 || empty($response['body']['success'])) {
      throw new QuickConnectException(esc_html(__('Could not reach Cloudflare', 'nimbus-media-offload') . ': ' . $this->apiError($response['body'])));
    }

    $accounts = [];

    foreach ((array) ($response['body']['result'] ?? []) as $account) {
      if (!empty($account['id'])) {
        $accounts[] = ['id' => (string) $account['id'], 'name' => (string) ($account['name'] ?? $account['id'])];
      }
    }

    if ($accounts === []) {
      throw new QuickConnectException(esc_html__('This token cannot see any Cloudflare account. Create it with the link above so the R2 permission is included.', 'nimbus-media-offload'));
    }

    return $accounts;
  }

  /**
   * The token's id, which R2 uses as the S3 access key. User tokens verify at
   * /user/tokens/verify, account-owned tokens at /accounts/{id}/tokens/verify.
   *
   * @param array<int, array{id: string, name: string}> $accounts
   */
  private function tokenId(array $accounts): string
  {
    $paths = ['/user/tokens/verify'];
    foreach ($accounts as $account) {
      $paths[] = '/accounts/' . $account['id'] . '/tokens/verify';
    }

    foreach ($paths as $path) {
      $response = $this->request('GET', $path);
      $id = (string) ($response['body']['result']['id'] ?? '');
      if ($response['status'] === 200 && $id !== '') {
        if (($response['body']['result']['status'] ?? 'active') !== 'active') {
          throw new QuickConnectException(esc_html__('This token is disabled or expired in Cloudflare.', 'nimbus-media-offload'));
        }
        return $id;
      }
    }

    throw new QuickConnectException(esc_html__('Cloudflare did not accept this token. Copy it again, it is only shown once.', 'nimbus-media-offload'));
  }

  /**
   * One account is picked automatically; with several, the first call returns
   * them so the wizard can ask.
   *
   * @param array<int, array{id: string, name: string}> $accounts
   * @return array{id: string, name: string}|null
   */
  private function pickAccount(array $accounts, string $chosen, ConnectResult $result): ?array
  {
    foreach ($accounts as $account) {
      if ($chosen !== '' && $account['id'] === $chosen) {
        return $account;
      }
    }

    if (count($accounts) === 1) {
      return $accounts[0];
    }

    // Only offer accounts where the token can actually reach R2.
    $usable = array_values(array_filter($accounts, fn(array $account): bool => $this->request('GET', '/accounts/' . $account['id'] . '/r2/buckets?per_page=1')['status'] === 200));

    if (count($usable) === 1) {
      return $usable[0];
    }

    if ($usable === []) {
      throw new QuickConnectException(esc_html__('This token has no R2 access. Create it with the link above so the R2 permission is included.', 'nimbus-media-offload'));
    }

    $result->accounts = $usable;
    $result->note = __('This token can reach more than one Cloudflare account. Pick the one for your media.', 'nimbus-media-offload');

    return null;
  }

  /**
   * @return string[]
   */
  private function bucketNames(string $accountId): array
  {
    $body = $this->call('GET', "/accounts/{$accountId}/r2/buckets?per_page=1000", null, __('Could not list R2 buckets', 'nimbus-media-offload'));
    $names = [];

    foreach ((array) ($body['result']['buckets'] ?? []) as $bucket) {
      if (!empty($bucket['name'])) {
        $names[] = (string) $bucket['name'];
      }
    }

    return $names;
  }

  private function setCors(string $accountId, string $bucket): void
  {
    $response = $this->request('PUT', "/accounts/{$accountId}/r2/buckets/{$bucket}/cors", [
      'rules' => [[
        'id' => 'nimbus-media',
        'allowed' => ['methods' => ['GET', 'HEAD'], 'origins' => ['*'], 'headers' => ['*']],
        'maxAgeSeconds' => 86400,
      ]],
    ]);

    if ($response['status'] !== 200) {
      $this->quickConnect->logger()->warning('R2 quick connect could not set CORS on {bucket}: {error}', [
        'bucket' => $bucket,
        'error' => $this->apiError($response['body']),
      ]);
    }
  }

  /**
   * Prefer a custom domain already attached to the bucket, else turn on the
   * r2.dev public URL.
   */
  private function publicUrl(string $accountId, string $bucket, ConnectResult $result): string
  {
    $custom = $this->request('GET', "/accounts/{$accountId}/r2/buckets/{$bucket}/domains/custom");
    foreach ((array) ($custom['body']['result']['domains'] ?? []) as $domain) {
      if (!empty($domain['enabled']) && !empty($domain['domain'])) {
        $result->step(__('Public URL', 'nimbus-media-offload'), (string) $domain['domain']);
        return 'https://' . $domain['domain'];
      }
    }

    $managed = $this->request('PUT', "/accounts/{$accountId}/r2/buckets/{$bucket}/domains/managed", ['enabled' => true]);
    $domain = (string) ($managed['body']['result']['domain'] ?? '');

    if ($managed['status'] === 200 && $domain !== '') {
      $result->step(__('Public URL on', 'nimbus-media-offload'), $domain);
      $result->note = __('r2.dev links are rate limited by Cloudflare. Before going live, connect a custom domain to this bucket in Cloudflare and enter it on the next step.', 'nimbus-media-offload');
      return 'https://' . $domain;
    }

    $result->public = false;
    $result->note = __('Nimbus could not turn on public access for this bucket. Add a custom domain to it in Cloudflare, then enter it on the next step.', 'nimbus-media-offload');
    $this->quickConnect->logger()->warning('R2 quick connect could not enable the r2.dev URL on {bucket}: {error}', [
      'bucket' => $bucket,
      'error' => $this->apiError($managed['body']),
    ]);

    return '';
  }

  /**
   * @return array<mixed>
   */
  private function call(string $method, string $path, ?array $body, string $failure): array
  {
    $response = $this->request($method, $path, $body);

    if ($response['status'] < 200 || $response['status'] >= 300 || empty($response['body']['success'])) {
      throw new QuickConnectException(esc_html($failure . ': ' . $this->apiError($response['body'])));
    }

    return $response['body'];
  }

  /**
   * @return array{status: int, body: array<mixed>|null}
   */
  private function request(string $method, string $path, ?array $body = null): array
  {
    return $this->quickConnect->http($method, self::API . $path, ['Authorization' => 'Bearer ' . $this->token], $body);
  }

  private function apiError(?array $body): string
  {
    $error = $body['errors'][0]['message'] ?? '';

    return $error !== '' ? (string) $error : __('unexpected response from Cloudflare', 'nimbus-media-offload');
  }
}
