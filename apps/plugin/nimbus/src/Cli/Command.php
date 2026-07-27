<?php
namespace Nimbus\Plugin\Cli;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Storage\StorageAdapterFactory;
use Nimbus\Plugin\Support\Events;
use Nimbus\Plugin\Support\Logger;

if (!defined('ABSPATH')) {
  exit;
}

final class Command
{
  public function __construct(
    private UploadHandler $handler,
    private Settings $settings,
    private StorageAdapterFactory $factory,
    private Logger $logger,
  ) {
  }

  public function register(): void
  {
    if (!defined('WP_CLI') || !WP_CLI || !class_exists('\\WP_CLI')) {
      return;
    }

    \WP_CLI::add_command('nimbus config', [$this, 'config'], [
      'shortdesc' => 'Show or update Nimbus plugin settings.',
    ]);
    \WP_CLI::add_command('nimbus process-pending', [$this, 'process_pending'], [
      'shortdesc' => 'Process queued pending offloads.',
    ]);
    \WP_CLI::add_command('nimbus deploy', [$this, 'deploy'], [
      'shortdesc' => 'Offload one or more attachments to remote storage.',
    ]);
    \WP_CLI::add_command('nimbus rewrite', [$this, 'rewrite'], [
      'shortdesc' => 'Rewrite local media URLs in post content to remote URLs.',
    ]);
    \WP_CLI::add_command('nimbus revert', [$this, 'revert'], [
      'shortdesc' => 'Restore remote URLs in post content back to local URLs.',
    ]);
    \WP_CLI::add_command('nimbus download', [$this, 'download'], [
      'shortdesc' => 'Download offloaded attachments back to local storage.',
    ]);
    \WP_CLI::add_command('nimbus replace-urls', [$this, 'replace_urls'], [
      'shortdesc' => 'Alias of rewrite/revert with explicit source and target URLs.',
    ]);
    \WP_CLI::add_command('nimbus offload-single', [$this, 'offload_single'], [
      'shortdesc' => 'Offload a single attachment by ID.',
    ]);
    \WP_CLI::add_command('nimbus sync', [$this, 'sync'], [
      'shortdesc' => 'Queue or offload attachments missing remote metadata.',
    ]);
  }

  public function config(array $args, array $assoc_args): void
  {
    if (empty($assoc_args)) {
      $settings = $this->settings->all();
      foreach (['access_key', 'secret_key', 'session_token', 'cloudflare_api_token'] as $secretKey) {
        if (!empty($settings[$secretKey])) {
          $settings[$secretKey] = '********';
        }
      }

      \WP_CLI::print_value($settings, ['format' => 'yaml']);
      return;
    }

    $current = $this->settings->all();
    $allowed = array_keys($current);
    $updates = [];
    foreach ($assoc_args as $key => $value) {
      if (!in_array($key, $allowed, true)) {
        \WP_CLI::warning(sprintf('Ignoring unknown setting: %s', $key));
        continue;
      }

      $updates[$key] = $value;
    }

    if (empty($updates)) {
      \WP_CLI::success('No valid settings were provided.');
      return;
    }

    $this->settings->update($updates);
    $this->factory->reset();
    \WP_CLI::success('Settings updated.');
  }

  public function process_pending(array $args, array $assoc_args): void
  {
    $hours = max(1, (int) ($assoc_args['hours'] ?? 24));
    $limit = max(1, (int) ($assoc_args['limit'] ?? 500));
    $result = $this->handler->process_pending_offloads($hours, $limit, 'cli-process-pending');
    \WP_CLI::print_value($result, ['format' => 'yaml']);
  }

  public function deploy(array $args, array $assoc_args): void
  {
    $attachmentIds = $this->resolve_attachment_ids($args, $assoc_args);
    if (empty($attachmentIds)) {
      \WP_CLI::warning('No attachment IDs resolved for deploy command.');
      return;
    }

    $success = 0;
    $failed = 0;
    foreach ($attachmentIds as $attachmentId) {
      if ($this->handler->offload_via_cli($attachmentId)) {
        $success++;
      } else {
        $failed++;
      }
    }

    \WP_CLI::success(sprintf('Deploy complete. Success: %d, Failed: %d', $success, $failed));
  }

  public function rewrite(array $args, array $assoc_args): void
  {
    $uploads = wp_upload_dir();
    $from = trailingslashit($uploads['baseurl']);
    $to = $this->resolve_remote_base_url();
    $context = [
      'source' => 'cli',
      'scope' => 'rewrite',
      'operation' => 'rewrite',
      'from' => $from,
      'to' => trailingslashit($to),
      'limit' => max(1, (int) ($assoc_args['limit'] ?? 500)),
    ];

    if ($to === '') {
      Events::dispatch(Events::REWRITE_FAILED, $context + [
        'error' => 'Unable to determine remote base URL. Configure storage first.',
      ]);
      \WP_CLI::error('Unable to determine remote base URL. Configure storage first.');
      return;
    }

    Events::dispatch(Events::REWRITE_STARTED, $context);
    $count = $this->replace_url_occurrences($from, trailingslashit($to), $assoc_args);
    Events::dispatch(Events::REWRITE_COMPLETED, $context + [
      'posts_updated' => $count,
    ]);
    \WP_CLI::success(sprintf('Rewrote media URLs in %d post(s).', $count));
  }

  public function revert(array $args, array $assoc_args): void
  {
    $uploads = wp_upload_dir();
    $to = trailingslashit($uploads['baseurl']);
    $from = $this->resolve_remote_base_url();
    $context = [
      'source' => 'cli',
      'scope' => 'rewrite',
      'operation' => 'revert',
      'from' => trailingslashit($from),
      'to' => $to,
      'limit' => max(1, (int) ($assoc_args['limit'] ?? 500)),
    ];

    if ($from === '') {
      Events::dispatch(Events::REWRITE_FAILED, $context + [
        'error' => 'Unable to determine remote base URL. Configure storage first.',
      ]);
      \WP_CLI::error('Unable to determine remote base URL. Configure storage first.');
      return;
    }

    Events::dispatch(Events::REWRITE_STARTED, $context);
    $count = $this->replace_url_occurrences(trailingslashit($from), $to, $assoc_args);
    Events::dispatch(Events::REWRITE_COMPLETED, $context + [
      'posts_updated' => $count,
    ]);
    \WP_CLI::success(sprintf('Reverted media URLs in %d post(s).', $count));
  }

  public function download(array $args, array $assoc_args): void
  {
    $attachmentIds = $this->resolve_attachment_ids($args, $assoc_args);
    if (empty($attachmentIds)) {
      \WP_CLI::warning('No attachment IDs resolved for download command.');
      return;
    }

    $success = 0;
    $failed = 0;
    foreach ($attachmentIds as $attachmentId) {
      if ($this->handler->download_media($attachmentId)) {
        $success++;
      } else {
        $failed++;
      }
    }

    \WP_CLI::success(sprintf('Download complete. Success: %d, Failed: %d', $success, $failed));
  }

  public function replace_urls(array $args, array $assoc_args): void
  {
    $from = (string) ($assoc_args['from'] ?? '');
    $to = (string) ($assoc_args['to'] ?? '');
    $context = [
      'source' => 'cli',
      'scope' => 'rewrite',
      'operation' => 'replace_urls',
      'from' => $from,
      'to' => $to,
      'limit' => max(1, (int) ($assoc_args['limit'] ?? 500)),
    ];

    if ($from === '' || $to === '') {
      Events::dispatch(Events::REWRITE_FAILED, $context + [
        'error' => 'Both --from and --to are required.',
      ]);
      \WP_CLI::error('Both --from and --to are required.');
      return;
    }

    Events::dispatch(Events::REWRITE_STARTED, $context);
    $count = $this->replace_url_occurrences($from, $to, $assoc_args);
    Events::dispatch(Events::REWRITE_COMPLETED, $context + [
      'posts_updated' => $count,
    ]);
    \WP_CLI::success(sprintf('Replaced URLs in %d post(s).', $count));
  }

  public function offload_single(array $args, array $assoc_args): void
  {
    $attachmentId = isset($args[0]) ? (int) $args[0] : 0;
    if ($attachmentId <= 0) {
      \WP_CLI::error('Provide an attachment ID.');
      return;
    }

    if ($this->handler->offload_via_cli($attachmentId)) {
      \WP_CLI::success(sprintf('Attachment %d offloaded.', $attachmentId));
      return;
    }

    \WP_CLI::error(sprintf('Failed to offload attachment %d.', $attachmentId));
  }

  public function sync(array $args, array $assoc_args): void
  {
    $attachmentIds = $this->resolve_attachment_ids($args, $assoc_args, true);
    if (empty($attachmentIds)) {
      \WP_CLI::warning('No attachments found to sync.');
      return;
    }

    $queued = 0;
    foreach ($attachmentIds as $attachmentId) {
      $status = $this->handler->get_attachment_status($attachmentId);
      if (($status['status'] ?? '') === 'offloaded') {
        continue;
      }

      $this->handler->sync_ignore_state($attachmentId, false);
      $queued++;
    }

    \WP_CLI::success(sprintf('Queued %d attachment(s) for sync.', $queued));
  }

  private function resolve_attachment_ids(array $args, array $assoc_args, bool $allowQuery = false): array
  {
    if (!empty($assoc_args['ids'])) {
      $ids = array_map('intval', array_filter(array_map('trim', explode(',', (string) $assoc_args['ids']))));
      return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    if (!empty($args)) {
      $ids = array_map('intval', $args);
      return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    if (!$allowQuery && empty($assoc_args['limit'])) {
      return [];
    }

    $query = new \WP_Query([
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'posts_per_page' => max(1, (int) ($assoc_args['limit'] ?? 100)),
      'fields' => 'ids',
      'orderby' => 'ID',
      'order' => 'ASC',
    ]);

    return is_array($query->posts) ? array_map('intval', $query->posts) : [];
  }

  private function resolve_remote_base_url(): string
  {
    try {
      $adapter = $this->factory->getAdapter();
      $probeName = 'nimbus-url-probe';
      $prefix = (string) $this->settings->get('prefix', '');
      $key = $prefix !== '' ? trim($prefix, '/') . '/' . $probeName : $probeName;
      $url = $adapter->getUrl($key);
      return rtrim(substr($url, 0, -strlen($probeName)), '/');
    } catch (\Throwable $exception) {
      $this->logger->warning('Failed to resolve remote base URL for CLI rewrite: {error}', ['error' => $exception->getMessage()]);
      return '';
    }
  }

  private function replace_url_occurrences(string $from, string $to, array $assoc_args): int
  {
    global $wpdb;

    $limit = max(1, (int) ($assoc_args['limit'] ?? 500));
    $postIds = $wpdb->get_col($wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'nav_menu_item') AND post_content LIKE %s LIMIT %d",
      '%' . $wpdb->esc_like($from) . '%',
      $limit
    ));

    if (!is_array($postIds) || empty($postIds)) {
      return 0;
    }

    $updated = 0;
    foreach (array_map('intval', $postIds) as $postId) {
      $content = (string) get_post_field('post_content', $postId);
      $replaced = str_replace($from, $to, $content, $count);
      if ($count < 1) {
        continue;
      }

      wp_update_post(['ID' => $postId, 'post_content' => $replaced]);
      $updated++;
    }

    return $updated;
  }
}
