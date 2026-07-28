<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;
use Nimbus\Plugin\Media\UploadHandler;
use Nimbus\Plugin\Support\Logger;

use function Nimbus\Plugin\Support\pushAdminNotice;

if (!defined('ABSPATH')) {
  exit;
}

final class MediaLibraryController
{
  private const STATUS_FILTER_QUERY_ARG = 'nimbus_status';

  private const BULK_ACTION_QUERY_ARG = 'nimbus_bulk_action';

  private const BULK_SUCCESS_QUERY_ARG = 'nimbus_success';

  private const BULK_FAILED_QUERY_ARG = 'nimbus_failed';

  private const BULK_SKIPPED_QUERY_ARG = 'nimbus_skipped';

  private const BULK_ACTION_OFFLOAD = 'nimbus_bulk_offload';

  private const BULK_ACTION_RESTORE = 'nimbus_bulk_restore';

  private const BULK_ACTION_RETRY = 'nimbus_bulk_retry';

  public function __construct(
    private Settings $settings,
    private UploadHandler $uploadHandler,
    private Logger $logger,
  ) {
  }

  public function register(): void
  {
    add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    add_filter('manage_upload_columns', [$this, 'addStatusColumn']);
    add_action('manage_media_custom_column', [$this, 'renderStatusColumn'], 10, 2);
    add_filter('media_row_actions', [$this, 'filterRowActions'], 10, 2);
    add_filter('attachment_fields_to_edit', [$this, 'addAttachmentStatusField'], 10, 2);
    add_action('restrict_manage_posts', [$this, 'renderStatusFilter']);
    add_action('pre_get_posts', [$this, 'filterLibraryQuery']);
    add_filter('bulk_actions-upload', [$this, 'registerBulkActions']);
    add_filter('handle_bulk_actions-upload', [$this, 'handleBulkActions'], 10, 3);
    add_action('admin_notices', [$this, 'renderBulkActionNotice']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_ATTACHMENT_OFFLOAD, [$this, 'handleAttachmentOffload']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_ATTACHMENT_RESTORE, [$this, 'handleAttachmentRestore']);
    add_action('admin_post_' . NIMBUS_ADMIN_ACTION_ATTACHMENT_RETRY, [$this, 'handleAttachmentRetry']);
  }

  public function enqueueAssets(string $hook): void
  {
    if ($hook !== 'upload.php' && !$this->isAttachmentEditScreen($hook)) {
      return;
    }

    $css_version = file_exists(NIMBUS_PATH . 'assets/css/admin.css') ? filemtime(NIMBUS_PATH . 'assets/css/admin.css') : NIMBUS_VERSION;
    wp_enqueue_style(NIMBUS_SCRIPT_HANDLE, NIMBUS_URL . 'assets/css/admin.css', [], $css_version);
  }

  public function addStatusColumn(array $columns): array
  {
    $columns['nimbus_status'] = __('Offload Status', 'nimbus');
    return $columns;
  }

  public function renderStatusColumn(string $columnName, int $postId): void
  {
    if ($columnName !== 'nimbus_status') {
      return;
    }

    $status = $this->uploadHandler->get_attachment_status($postId);
    printf(
      '<span class="nmb-inline-badge %1$s">%2$s</span>',
      esc_attr($this->badgeClass((string) ($status['status'] ?? 'local'))),
      esc_html((string) ($status['label'] ?? __('Unknown', 'nimbus')))
    );

    if (!empty($status['reason'])) {
      printf('<div class="nmb-muted nmb-library-note">%s</div>', esc_html((string) $status['reason']));
    }

    if (($status['status'] ?? '') === 'offloaded') {
      $nimbusMeta = get_post_meta($postId, NIMBUS_META_KEY, true);
      $fileSizeBytes = is_array($nimbusMeta) && isset($nimbusMeta['file_size_bytes']) ? (int) $nimbusMeta['file_size_bytes'] : 0;
      if ($fileSizeBytes > 0) {
        printf(
          '<div class="nmb-muted nmb-library-note">%s</div>',
          esc_html(size_format($fileSizeBytes, 1))
        );
      }
    }
  }

  public function filterRowActions(array $actions, \WP_Post $post): array
  {
    if ($post->post_type !== 'attachment' || !current_user_can('upload_files')) {
      return $actions;
    }

    $status = $this->uploadHandler->get_attachment_status((int) $post->ID);
    $statusKey = (string) ($status['status'] ?? 'local');

    if ($statusKey === 'offloaded') {
      $actions['nimbus_restore'] = sprintf(
        '<a href="%s">%s</a>',
        esc_url($this->actionUrl(NIMBUS_ADMIN_ACTION_ATTACHMENT_RESTORE, (int) $post->ID)),
        esc_html__('Restore local', 'nimbus')
      );
    } elseif ($statusKey !== 'ignored') {
      $actions['nimbus_offload'] = sprintf(
        '<a href="%s">%s</a>',
        esc_url($this->actionUrl(NIMBUS_ADMIN_ACTION_ATTACHMENT_OFFLOAD, (int) $post->ID)),
        esc_html($statusKey === 'failed' ? __('Retry offload', 'nimbus') : __('Offload now', 'nimbus'))
      );
    }

    if ($statusKey === 'failed') {
      $actions['nimbus_retry'] = sprintf(
        '<a href="%s">%s</a>',
        esc_url($this->actionUrl(NIMBUS_ADMIN_ACTION_ATTACHMENT_RETRY, (int) $post->ID)),
        esc_html__('Retry', 'nimbus')
      );
    }

    $actions['nimbus_view'] = sprintf(
      '<a href="%s">%s</a>',
      esc_url(add_query_arg([
        'page' => NIMBUS_SLUG,
        'tab' => 'tools',
        'attachment_id' => (int) $post->ID,
      ], admin_url('upload.php'))),
      esc_html__('View status', 'nimbus')
    );

    return $actions;
  }

  public function addAttachmentStatusField(array $formFields, \WP_Post $post): array
  {
    if ($post->post_type !== 'attachment') {
      return $formFields;
    }

    $status = $this->uploadHandler->get_attachment_status((int) $post->ID);
    $html = sprintf(
      '<span class="nmb-inline-badge %1$s">%2$s</span>',
      esc_attr($this->badgeClass((string) ($status['status'] ?? 'local'))),
      esc_html((string) ($status['label'] ?? __('Unknown', 'nimbus')))
    );

    if (!empty($status['reason'])) {
      $html .= sprintf('<div class="nmb-muted nmb-library-note">%s</div>', esc_html((string) $status['reason']));
    }

    $html .= sprintf(
      '<p><a href="%s">%s</a></p>',
      esc_url(add_query_arg([
        'page' => NIMBUS_SLUG,
        'tab' => 'tools',
        'attachment_id' => (int) $post->ID,
      ], admin_url('upload.php'))),
      esc_html__('Open Nimbus tools', 'nimbus')
    );

    $formFields['nimbus_status'] = [
      'label' => __('Nimbus', 'nimbus'),
      'input' => 'html',
      'html' => $html,
      'show_in_edit' => true,
      'show_in_modal' => true,
    ];

    return $formFields;
  }

  public function renderStatusFilter(string $postType): void
  {
    if ($postType !== 'attachment' || !$this->isUploadListScreen()) {
      return;
    }

    $current = $this->currentStatusFilter();

    echo '<label class="screen-reader-text" for="nimbus-status-filter">' . esc_html__('Filter by Nimbus status', 'nimbus') . '</label>';
    echo '<select id="nimbus-status-filter" name="' . esc_attr(self::STATUS_FILTER_QUERY_ARG) . '">';

    foreach ($this->statusOptions() as $value => $label) {
      printf(
        '<option value="%1$s" %2$s>%3$s</option>',
        esc_attr($value),
        selected($current, $value, false),
        esc_html($label)
      );
    }

    echo '</select>';
  }

  public function filterLibraryQuery(\WP_Query $query): void
  {
    if (!is_admin() || !$query->is_main_query() || !$this->isUploadListScreen()) {
      return;
    }

    if (($query->get('post_type') ?: 'attachment') !== 'attachment') {
      return;
    }

    $status = $this->currentStatusFilter();
    if ($status === '') {
      return;
    }

    $statusMetaQuery = $this->metaQueryForStatus($status);
    $existingMetaQuery = $query->get('meta_query');

    if (is_array($existingMetaQuery) && !empty($existingMetaQuery)) {
      $query->set('meta_query', [
        'relation' => 'AND',
        $existingMetaQuery,
        $statusMetaQuery,
      ]);
      return;
    }

    $query->set('meta_query', $statusMetaQuery);
  }

  public function registerBulkActions(array $actions): array
  {
    $actions[self::BULK_ACTION_OFFLOAD] = __('Offload to storage', 'nimbus');
    $actions[self::BULK_ACTION_RESTORE] = __('Restore local copies', 'nimbus');
    $actions[self::BULK_ACTION_RETRY] = __('Retry failed offloads', 'nimbus');

    return $actions;
  }

  public function handleBulkActions(string $redirectTo, string $action, array $postIds): string
  {
    if (!current_user_can('upload_files')) {
      return $redirectTo;
    }

    if (!in_array($action, [self::BULK_ACTION_OFFLOAD, self::BULK_ACTION_RESTORE, self::BULK_ACTION_RETRY], true)) {
      return $redirectTo;
    }

    [$success, $failed, $skipped] = $this->processBulkAction($action, $postIds);

    $this->logger->info('Media Library bulk action {action} completed. Success: {success}, Failed: {failed}, Skipped: {skipped}', [
      'action' => $action,
      'success' => $success,
      'failed' => $failed,
      'skipped' => $skipped,
    ]);

    return add_query_arg([
      self::BULK_ACTION_QUERY_ARG => rawurlencode($action),
      self::BULK_SUCCESS_QUERY_ARG => $success,
      self::BULK_FAILED_QUERY_ARG => $failed,
      self::BULK_SKIPPED_QUERY_ARG => $skipped,
    ], $redirectTo);
  }

  public function renderBulkActionNotice(): void
  {
    if (!$this->isUploadListScreen()) {
      return;
    }

    // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only GET parameter used for display/navigation only (tab, filter, or self-generated redirect notice) -- no state-changing action is performed, so no nonce applies.
    $action = isset($_GET[self::BULK_ACTION_QUERY_ARG]) ? sanitize_key((string) wp_unslash($_GET[self::BULK_ACTION_QUERY_ARG])) : '';
    if (!in_array($action, [self::BULK_ACTION_OFFLOAD, self::BULK_ACTION_RESTORE, self::BULK_ACTION_RETRY], true)) {
      return;
    }

    $success = isset($_GET[self::BULK_SUCCESS_QUERY_ARG]) ? max(0, (int) $_GET[self::BULK_SUCCESS_QUERY_ARG]) : 0;
    $failed = isset($_GET[self::BULK_FAILED_QUERY_ARG]) ? max(0, (int) $_GET[self::BULK_FAILED_QUERY_ARG]) : 0;
    $skipped = isset($_GET[self::BULK_SKIPPED_QUERY_ARG]) ? max(0, (int) $_GET[self::BULK_SKIPPED_QUERY_ARG]) : 0;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

    if ($success <= 0 && $failed <= 0 && $skipped <= 0) {
      return;
    }

    $noticeClass = 'notice notice-success';
    if ($success <= 0 && $failed > 0) {
      $noticeClass = 'notice notice-error';
    } elseif ($failed > 0 || $skipped > 0) {
      $noticeClass = 'notice notice-warning';
    }

    printf(
      '<div class="%1$s"><p>%2$s</p></div>',
      esc_attr($noticeClass),
      esc_html($this->bulkNoticeMessage($action, $success, $failed, $skipped))
    );
  }

  public function handleAttachmentOffload(): void
  {
    $this->handleAttachmentAction(NIMBUS_ADMIN_ACTION_ATTACHMENT_OFFLOAD, static fn (UploadHandler $uploadHandler, int $attachmentId): bool => $uploadHandler->offload_via_cli($attachmentId), __('Attachment offloaded successfully.', 'nimbus'), __('Attachment could not be offloaded.', 'nimbus'));
  }

  public function handleAttachmentRestore(): void
  {
    $this->handleAttachmentAction(NIMBUS_ADMIN_ACTION_ATTACHMENT_RESTORE, static fn (UploadHandler $uploadHandler, int $attachmentId): bool => $uploadHandler->download_media($attachmentId), __('Attachment restored locally.', 'nimbus'), __('Attachment could not be restored locally.', 'nimbus'));
  }

  public function handleAttachmentRetry(): void
  {
    $this->handleAttachmentAction(NIMBUS_ADMIN_ACTION_ATTACHMENT_RETRY, static fn (UploadHandler $uploadHandler, int $attachmentId): bool => $uploadHandler->offload_via_cli($attachmentId), __('Attachment retry completed successfully.', 'nimbus'), __('Attachment retry failed.', 'nimbus'));
  }

  /**
  * @param callable(UploadHandler, int): bool $operation
   */
  private function handleAttachmentAction(string $action, callable $operation, string $successMessage, string $failureMessage): void
  {
    if (!current_user_can('upload_files')) {
      wp_die(esc_html__('Permission denied.', 'nimbus'));
    }

    check_admin_referer($action);

    $attachmentId = isset($_GET['attachment_id']) ? max(0, (int) $_GET['attachment_id']) : 0;
    if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
      pushAdminNotice(__('Attachment not found.', 'nimbus'), 'error');
      $this->redirectBack();
    }

    try {
      $result = $operation($this->uploadHandler, $attachmentId);
      pushAdminNotice($result ? $successMessage : $failureMessage, $result ? 'success' : 'error');
    } catch (\Throwable $exception) {
      $this->logger->warning('Media Library action failed for attachment {attachment}: {error}', [
        'attachment' => $attachmentId,
        'error' => $exception->getMessage(),
      ]);
      pushAdminNotice($failureMessage, 'error');
    }

    $this->redirectBack();
  }

  private function actionUrl(string $action, int $attachmentId): string
  {
    return wp_nonce_url(add_query_arg([
      'action' => $action,
      'attachment_id' => $attachmentId,
    ], admin_url('admin-post.php')), $action);
  }

  private function redirectBack(): void
  {
    $fallback = admin_url('upload.php');
    $referer = wp_get_referer();
    wp_safe_redirect($referer ? $referer : $fallback);
    exit;
  }

  private function currentStatusFilter(): string
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only GET parameter used for display/navigation only (tab, filter, or self-generated redirect notice) -- no state-changing action is performed, so no nonce applies.
    $status = isset($_GET[self::STATUS_FILTER_QUERY_ARG]) ? sanitize_key((string) wp_unslash($_GET[self::STATUS_FILTER_QUERY_ARG])) : '';
    return array_key_exists($status, $this->statusOptions()) ? $status : '';
  }

  private function statusOptions(): array
  {
    return [
      '' => __('All Nimbus states', 'nimbus'),
      'local' => __('Local only', 'nimbus'),
      'offloaded' => __('Offloaded', 'nimbus'),
      'pending' => __('Pending', 'nimbus'),
      'processing' => __('Processing', 'nimbus'),
      'failed' => __('Needs attention', 'nimbus'),
      'ignored' => __('Ignored', 'nimbus'),
    ];
  }

  private function metaQueryForStatus(string $status): array
  {
    $notIgnored = [
      'relation' => 'OR',
      ['key' => NIMBUS_IGNORE_META_KEY, 'compare' => 'NOT EXISTS'],
      ['key' => NIMBUS_IGNORE_META_KEY, 'value' => '1', 'compare' => '!='],
    ];

    return match ($status) {
      'ignored' => [
        [
          'key' => NIMBUS_IGNORE_META_KEY,
          'value' => '1',
          'compare' => '=',
        ],
      ],
      'offloaded' => [
        'relation' => 'AND',
        $notIgnored,
        ['key' => NIMBUS_META_KEY, 'compare' => 'EXISTS'],
      ],
      'processing' => [
        'relation' => 'AND',
        $notIgnored,
        ['key' => NIMBUS_META_KEY, 'compare' => 'NOT EXISTS'],
        ['key' => NIMBUS_PENDING_AT_META_KEY, 'compare' => 'EXISTS'],
        [
          'relation' => 'AND',
          ['key' => NIMBUS_CLAIMED_BY_META_KEY, 'compare' => 'EXISTS'],
          ['key' => NIMBUS_CLAIMED_BY_META_KEY, 'value' => '', 'compare' => '!='],
        ],
      ],
      'pending' => [
        'relation' => 'AND',
        $notIgnored,
        ['key' => NIMBUS_META_KEY, 'compare' => 'NOT EXISTS'],
        ['key' => NIMBUS_PENDING_AT_META_KEY, 'compare' => 'EXISTS'],
        [
          'relation' => 'OR',
          ['key' => NIMBUS_CLAIMED_BY_META_KEY, 'compare' => 'NOT EXISTS'],
          ['key' => NIMBUS_CLAIMED_BY_META_KEY, 'value' => '', 'compare' => '='],
        ],
      ],
      'failed' => [
        'relation' => 'AND',
        $notIgnored,
        ['key' => NIMBUS_META_KEY, 'compare' => 'NOT EXISTS'],
        ['key' => NIMBUS_PENDING_AT_META_KEY, 'compare' => 'NOT EXISTS'],
        ['key' => NIMBUS_MISSING_SOURCE_AT_META_KEY, 'compare' => 'EXISTS'],
      ],
      'local' => [
        'relation' => 'AND',
        $notIgnored,
        ['key' => NIMBUS_META_KEY, 'compare' => 'NOT EXISTS'],
        ['key' => NIMBUS_PENDING_AT_META_KEY, 'compare' => 'NOT EXISTS'],
        ['key' => NIMBUS_MISSING_SOURCE_AT_META_KEY, 'compare' => 'NOT EXISTS'],
      ],
      default => [],
    };
  }

  private function processBulkAction(string $action, array $postIds): array
  {
    $success = 0;
    $failed = 0;
    $skipped = 0;

    foreach (array_unique(array_map('intval', $postIds)) as $attachmentId) {
      if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
        $skipped++;
        continue;
      }

      $status = (string) ($this->uploadHandler->get_attachment_status($attachmentId)['status'] ?? 'unknown');
      if (!$this->supportsBulkAction($action, $status)) {
        $skipped++;
        continue;
      }

      try {
        $result = match ($action) {
          self::BULK_ACTION_OFFLOAD => $this->uploadHandler->offload_via_cli($attachmentId),
          self::BULK_ACTION_RESTORE => $this->uploadHandler->download_media($attachmentId),
          self::BULK_ACTION_RETRY => $this->uploadHandler->offload_via_cli($attachmentId),
          default => false,
        };
      } catch (\Throwable $exception) {
        $failed++;
        $this->logger->warning('Media Library bulk action failed for attachment {attachment}: {error}', [
          'attachment' => $attachmentId,
          'action' => $action,
          'error' => $exception->getMessage(),
        ]);
        continue;
      }

      if ($result) {
        $success++;
      } else {
        $failed++;
      }
    }

    return [$success, $failed, $skipped];
  }

  private function supportsBulkAction(string $action, string $status): bool
  {
    return match ($action) {
      self::BULK_ACTION_OFFLOAD => in_array($status, ['local', 'failed', 'pending'], true),
      self::BULK_ACTION_RESTORE => $status === 'offloaded',
      self::BULK_ACTION_RETRY => $status === 'failed',
      default => false,
    };
  }

  private function bulkNoticeMessage(string $action, int $success, int $failed, int $skipped): string
  {
    $prefix = match ($action) {
      self::BULK_ACTION_OFFLOAD => __('Bulk offload finished.', 'nimbus'),
      self::BULK_ACTION_RESTORE => __('Bulk restore finished.', 'nimbus'),
      self::BULK_ACTION_RETRY => __('Bulk retry finished.', 'nimbus'),
      default => __('Bulk media action finished.', 'nimbus'),
    };

    return trim(sprintf(
      /* translators: 1: action prefix, 2: success message, 3: failure message, 4: skipped message */
      '%1$s %2$s %3$s %4$s',
      $prefix,
      /* translators: %d: number of attachments successfully updated */
      sprintf(_n('%d attachment updated.', '%d attachments updated.', $success, 'nimbus'), $success),
      /* translators: %d: number of attachments that failed */
      sprintf(_n('%d failed.', '%d failed.', $failed, 'nimbus'), $failed),
      /* translators: %d: number of attachments skipped */
      sprintf(_n('%d skipped.', '%d skipped.', $skipped, 'nimbus'), $skipped)
    ));
  }

  private function isUploadListScreen(): bool
  {
    global $pagenow;

    return is_admin() && $pagenow === 'upload.php';
  }

  private function isAttachmentEditScreen(string $hook): bool
  {
    if (!in_array($hook, ['post.php', 'post-new.php'], true) || !function_exists('get_current_screen')) {
      return false;
    }

    $screen = get_current_screen();
    return $screen instanceof \WP_Screen && $screen->post_type === 'attachment';
  }

  private function badgeClass(string $status): string
  {
    return match ($status) {
      'offloaded' => 'is-success',
      'pending', 'processing' => 'is-warning',
      'failed' => 'is-error',
      default => '',
    };
  }
}
