<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Setup\CloudflareR2Connector;
use Nimbus\Plugin\Setup\DigitalOceanSpacesConnector;
use Nimbus\Plugin\Setup\QuickConnect;
use Nimbus\Plugin\Setup\ShellConnector;
use Nimbus\Plugin\Setup\WasabiConnector;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * The "Connect <provider>" panels in the setup wizard's storage step.
 *
 * Inputs here carry no name attribute on purpose: pasted tokens and admin keys
 * go to the quick connect request only and are never submitted with the form.
 */
final class QuickConnectPanel
{
  public function __construct(private Settings $settings, private Logger $logger)
  {
  }

  public function render(string $selectedProvider): void
  {
    foreach ($this->panels() as $provider => $panel) {
      $this->renderPanel($provider, $panel, $provider === $selectedProvider);
    }
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function panels(): array
  {
    $host = (string) (wp_parse_url(home_url(), PHP_URL_HOST) ?: 'WordPress');
    $quickConnect = new QuickConnect($this->settings, $this->logger);
    $globalBucket = $quickConnect->defaultBucketName(true);

    $regionField = static fn(array $regions, string $default): array => [
      'key' => 'region',
      'label' => __('Region', 'nimbus'),
      'type' => 'select',
      'options' => $regions,
      'value' => $default,
    ];
    $keyFields = [
      ['key' => 'access_key', 'label' => __('Access key', 'nimbus'), 'type' => 'text'],
      ['key' => 'secret_key', 'label' => __('Secret key', 'nimbus'), 'type' => 'password'],
    ];

    return [
      'r2' => [
        'title' => __('Connect Cloudflare R2', 'nimbus'),
        'lede' => __('Paste one token. Nimbus finds your account, creates the bucket and turns on public access.', 'nimbus'),
        'steps' => [
          ['type' => 'link', 'title' => __('Create a token in Cloudflare', 'nimbus'), 'hint' => __('The R2 permission is already filled in. Select Continue to summary, then Create token.', 'nimbus'), 'label' => __('Open Cloudflare', 'nimbus'), 'url' => CloudflareR2Connector::tokenUrl('Nimbus ' . $host)],
          ['type' => 'fields', 'fields' => [['key' => 'token', 'label' => __('Paste the token', 'nimbus'), 'type' => 'password']]],
        ],
        'footnote' => __('Nimbus saves S3 keys made from this token. Delete the token in Cloudflare to disconnect.', 'nimbus'),
      ],
      'backblaze_b2' => [
        'title' => __('Connect Backblaze B2', 'nimbus'),
        'lede' => __('Paste one application key. Nimbus picks the region, sets up the bucket and makes a key that can only reach it.', 'nimbus'),
        'steps' => [
          ['type' => 'link', 'title' => __('Create an application key in Backblaze', 'nimbus'), 'hint' => __('Choose All buckets and Read and Write. Copy both values before you close the dialog.', 'nimbus'), 'label' => __('Open Backblaze', 'nimbus'), 'url' => 'https://secure.backblaze.com/app_keys.htm'],
          ['type' => 'fields', 'fields' => [
            ['key' => 'key_id', 'label' => __('keyID', 'nimbus'), 'type' => 'text'],
            ['key' => 'key', 'label' => __('applicationKey', 'nimbus'), 'type' => 'password'],
          ]],
        ],
        'footnote' => __('The key you paste is not saved. You can delete it in Backblaze once you are connected.', 'nimbus'),
      ],
      'wasabi' => [
        'title' => __('Connect Wasabi', 'nimbus'),
        'lede' => __('Paste an admin access key. Nimbus creates the bucket and a user that can only reach it.', 'nimbus'),
        'steps' => [
          ['type' => 'link', 'title' => __('Create an access key in Wasabi', 'nimbus'), 'hint' => __('Use a root or admin key. Copy both values before you close the dialog.', 'nimbus'), 'label' => __('Open Wasabi', 'nimbus'), 'url' => 'https://console.wasabisys.com/access_keys'],
          ['type' => 'fields', 'fields' => array_merge([$regionField(WasabiConnector::REGIONS, 'us-east-1')], $keyFields)],
        ],
        'footnote' => __('The key you paste is not saved unless it cannot create users.', 'nimbus'),
      ],
      'digitalocean_spaces' => [
        'title' => __('Connect DigitalOcean Spaces', 'nimbus'),
        'lede' => __('Paste one API token. Nimbus creates the Space and a key that can only reach it.', 'nimbus'),
        'steps' => [
          ['type' => 'link', 'title' => __('Create an API token in DigitalOcean', 'nimbus'), 'hint' => __('Choose Custom scopes, then tick create, read and delete under spaces_key.', 'nimbus'), 'label' => __('Open DigitalOcean', 'nimbus'), 'url' => DigitalOceanSpacesConnector::tokenUrl()],
          ['type' => 'fields', 'fields' => [
            $regionField(DigitalOceanSpacesConnector::REGIONS, 'nyc3'),
            ['key' => 'token', 'label' => __('Paste the token', 'nimbus'), 'type' => 'password'],
          ]],
        ],
        'footnote' => __('The token you paste is not saved. Spaces bills from the first Space you create.', 'nimbus'),
      ],
      'minio' => [
        'title' => __('Connect MinIO', 'nimbus'),
        'lede' => __('Enter your server and keys. Nimbus creates the bucket and opens it for media.', 'nimbus'),
        'steps' => [
          ['type' => 'fields', 'fields' => array_merge([['key' => 'endpoint', 'label' => __('Endpoint', 'nimbus'), 'type' => 'url', 'placeholder' => 'https://minio.example.com']], $keyFields)],
        ],
        'footnote' => '',
      ],
      's3_compatible' => [
        'title' => __('Connect S3-compatible storage', 'nimbus'),
        'lede' => __('Enter the S3 API address and keys. Nimbus lists your buckets or creates one.', 'nimbus'),
        'steps' => [
          ['type' => 'fields', 'fields' => array_merge([['key' => 'endpoint', 'label' => __('Endpoint', 'nimbus'), 'type' => 'url', 'placeholder' => 'https://s3.example.com']], $keyFields)],
        ],
        'footnote' => '',
      ],
      's3' => [
        'title' => __('Connect Amazon S3', 'nimbus'),
        'lede' => __('Run one command in your AWS account. Nothing leaves AWS except the line you paste back.', 'nimbus'),
        'steps' => [
          ['type' => 'region', 'field' => $regionField(ShellConnector::S3_REGIONS, 'us-east-1')],
          ['type' => 'link', 'title' => __('Open AWS CloudShell', 'nimbus'), 'hint' => __('Sign in to the AWS account that should hold your media.', 'nimbus'), 'label' => __('Open CloudShell', 'nimbus'), 'url' => ShellConnector::SHELL_URLS['s3']],
          ['type' => 'command', 'title' => __('Paste this command into CloudShell', 'nimbus'), 'hint' => __('It creates the bucket and a user that can only reach it, then prints one line.', 'nimbus'), 'script' => ShellConnector::script('s3', $globalBucket)],
          ['type' => 'fields', 'fields' => [['key' => 'output', 'label' => __('Paste what it printed', 'nimbus'), 'type' => 'text', 'placeholder' => '{"nimbus":1,…}']]],
        ],
        'footnote' => '',
      ],
      'gcs' => [
        'title' => __('Connect Google Cloud Storage', 'nimbus'),
        'lede' => __('Run one command in Cloud Shell. Nothing leaves Google Cloud except the line you paste back.', 'nimbus'),
        'steps' => [
          ['type' => 'region', 'field' => $regionField(ShellConnector::GCS_LOCATIONS, 'us-central1')],
          ['type' => 'link', 'title' => __('Open Google Cloud Shell', 'nimbus'), 'hint' => __('Sign in and pick the project that should hold your media.', 'nimbus'), 'label' => __('Open Cloud Shell', 'nimbus'), 'url' => ShellConnector::SHELL_URLS['gcs']],
          ['type' => 'command', 'title' => __('Paste this command into Cloud Shell', 'nimbus'), 'hint' => __('It creates the bucket and a service account that can only reach it, then prints one line.', 'nimbus'), 'script' => ShellConnector::script('gcs', $globalBucket)],
          ['type' => 'fields', 'fields' => [['key' => 'output', 'label' => __('Paste what it printed', 'nimbus'), 'type' => 'text', 'placeholder' => '{"nimbus":1,…}']]],
        ],
        'footnote' => '',
      ],
    ];
  }

  private function renderPanel(string $provider, array $panel, bool $visible): void
  {
    $id = 'nmb-quick-' . $provider;
    ?>
    <section class="nmb-quick" data-quick-for="<?php echo esc_attr($provider); ?>" aria-labelledby="<?php echo esc_attr($id); ?>-h"<?php echo $visible ? '' : ' hidden'; ?>>
      <div class="nmb-quick__head">
        <h2 id="<?php echo esc_attr($id); ?>-h"><?php echo esc_html($panel['title']); ?></h2>
        <p><?php echo esc_html($panel['lede']); ?></p>
      </div>
      <ol class="nmb-quick__steps">
        <?php foreach ($panel['steps'] as $index => $step) : ?>
          <li class="nmb-quick__step">
            <span class="nmb-quick__n" aria-hidden="true"><span><?php echo esc_html((string) ($index + 1)); ?></span></span>
            <?php $this->renderStep($id, $step); ?>
          </li>
        <?php endforeach; ?>
      </ol>
      <div class="nmb-quick__result" data-quick-result hidden>
        <ul class="nmb-quick__checks" aria-label="<?php esc_attr_e('What Nimbus set up', 'nimbus'); ?>" data-quick-checks></ul>
        <label class="nmb-quick__choice" data-quick-accounts hidden>
          <span><?php esc_html_e('Cloudflare account', 'nimbus'); ?></span>
          <select data-quick-input="account_id"></select>
        </label>
        <label class="nmb-quick__choice" data-quick-buckets hidden>
          <span><?php esc_html_e('Bucket', 'nimbus'); ?></span>
          <select data-quick-input="bucket"></select>
        </label>
        <p class="nmb-quick__note" data-quick-note hidden></p>
      </div>
      <?php if ($panel['footnote'] !== '') : ?>
        <p class="nmb-quick__foot"><?php echo esc_html($panel['footnote']); ?></p>
      <?php endif; ?>
    </section>
    <?php
  }

  private function renderStep(string $id, array $step): void
  {
    switch ($step['type']) {
      case 'link':
        ?>
        <div class="nmb-quick__text">
          <strong><?php echo esc_html($step['title']); ?></strong>
          <span><?php echo esc_html($step['hint']); ?></span>
        </div>
        <a class="button nmb-quick__open" href="<?php echo esc_url($step['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($step['label']); ?><svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 3h7v7M13 3L4 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'nimbus'); ?></span></a>
        <?php
        return;

      case 'region':
        ?>
        <div class="nmb-quick__body">
          <?php $this->renderField($id, $step['field']); ?>
        </div>
        <?php
        return;

      case 'command':
        ?>
        <div class="nmb-quick__body">
          <div class="nmb-quick__command-head">
            <strong><?php echo esc_html($step['title']); ?></strong>
            <button type="button" class="button nmb-quick__copy" data-quick-copy><?php esc_html_e('Copy command', 'nimbus'); ?></button>
          </div>
          <pre class="nmb-quick__command" data-quick-command data-template="<?php echo esc_attr($step['script']); ?>"><?php echo esc_html($step['script']); ?></pre>
          <span class="nmb-quick__hint"><?php echo esc_html($step['hint']); ?></span>
        </div>
        <?php
        return;

      case 'fields':
        $fields = $step['fields'];
        $last = array_pop($fields);
        ?>
        <div class="nmb-quick__body">
          <?php foreach ($fields as $field) : ?>
            <?php $this->renderField($id, $field); ?>
          <?php endforeach; ?>
          <?php $this->renderField($id, $last, true); ?>
        </div>
        <?php
        return;
    }
  }

  private function renderField(string $id, array $field, bool $withButton = false): void
  {
    $inputId = $id . '-' . $field['key'];
    ?>
    <div class="nmb-quick__field">
      <label for="<?php echo esc_attr($inputId); ?>"><?php echo esc_html($field['label']); ?></label>
      <div class="nmb-quick__row">
        <?php if ($field['type'] === 'select') : ?>
          <select id="<?php echo esc_attr($inputId); ?>" data-quick-input="<?php echo esc_attr($field['key']); ?>">
            <?php foreach ($field['options'] as $option) : ?>
              <option value="<?php echo esc_attr($option); ?>" <?php selected($field['value'] ?? '', $option); ?>><?php echo esc_html($option); ?></option>
            <?php endforeach; ?>
          </select>
        <?php else : ?>
          <input id="<?php echo esc_attr($inputId); ?>" type="<?php echo esc_attr($field['type']); ?>" data-quick-input="<?php echo esc_attr($field['key']); ?>" placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>" autocomplete="off" spellcheck="false" />
        <?php endif; ?>
        <?php if ($withButton) : ?>
          <button type="button" class="button nmb-button-ink" data-quick-connect><?php esc_html_e('Connect', 'nimbus'); ?></button>
        <?php endif; ?>
      </div>
    </div>
    <?php
  }
}
