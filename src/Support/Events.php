<?php
namespace ArunPrakash\MediaOffload\Support;

if (!defined('ABSPATH')) {
  exit;
}

final class Events
{
  public const REWRITE_STARTED = 'media_offload_rewrite_started';
  public const REWRITE_COMPLETED = 'media_offload_rewrite_completed';
  public const REWRITE_FAILED = 'media_offload_rewrite_failed';

  public const INTEGRATION_REWRITE_STARTED = 'media_offload_integration_rewrite_started';
  public const INTEGRATION_REWRITE_COMPLETED = 'media_offload_integration_rewrite_completed';
  public const INTEGRATION_REWRITE_FAILED = 'media_offload_integration_rewrite_failed';

  public const OFFLOAD_STARTED = 'media_offload_offload_started';
  public const OFFLOAD_COMPLETED = 'media_offload_offload_completed';
  public const OFFLOAD_FAILED = 'media_offload_offload_failed';

  public const RESTORE_STARTED = 'media_offload_restore_started';
  public const RESTORE_COMPLETED = 'media_offload_restore_completed';
  public const RESTORE_FAILED = 'media_offload_restore_failed';

  public const REMOTE_DELETE_STARTED = 'media_offload_remote_delete_started';
  public const REMOTE_DELETE_COMPLETED = 'media_offload_remote_delete_completed';
  public const REMOTE_DELETE_FAILED = 'media_offload_remote_delete_failed';

  public const CACHE_PURGE_REQUESTED = 'media_offload_cache_purge_requested';
  public const CACHE_PURGE_STARTED = 'media_offload_cache_purge_started';
  public const CACHE_PURGE_COMPLETED = 'media_offload_cache_purge_completed';
  public const CACHE_PURGE_FAILED = 'media_offload_cache_purge_failed';

  public static function dispatch(string $hook, array $payload = []): void
  {
    do_action($hook, $payload);
  }
}