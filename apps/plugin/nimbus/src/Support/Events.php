<?php
namespace Nimbus\Plugin\Support;

if (!defined('ABSPATH')) {
  exit;
}

final class Events
{
  public const REWRITE_STARTED = 'nimbus_rewrite_started';
  public const REWRITE_COMPLETED = 'nimbus_rewrite_completed';
  public const REWRITE_FAILED = 'nimbus_rewrite_failed';

  public const INTEGRATION_REWRITE_STARTED = 'nimbus_integration_rewrite_started';
  public const INTEGRATION_REWRITE_COMPLETED = 'nimbus_integration_rewrite_completed';
  public const INTEGRATION_REWRITE_FAILED = 'nimbus_integration_rewrite_failed';

  public const OFFLOAD_STARTED = 'nimbus_offload_started';
  public const OFFLOAD_COMPLETED = 'nimbus_offload_completed';
  public const OFFLOAD_FAILED = 'nimbus_offload_failed';

  public const RESTORE_STARTED = 'nimbus_restore_started';
  public const RESTORE_COMPLETED = 'nimbus_restore_completed';
  public const RESTORE_FAILED = 'nimbus_restore_failed';

  public const REMOTE_DELETE_STARTED = 'nimbus_remote_delete_started';
  public const REMOTE_DELETE_COMPLETED = 'nimbus_remote_delete_completed';
  public const REMOTE_DELETE_FAILED = 'nimbus_remote_delete_failed';

  public const CACHE_PURGE_REQUESTED = 'nimbus_cache_purge_requested';
  public const CACHE_PURGE_STARTED = 'nimbus_cache_purge_started';
  public const CACHE_PURGE_COMPLETED = 'nimbus_cache_purge_completed';
  public const CACHE_PURGE_FAILED = 'nimbus_cache_purge_failed';

  public static function dispatch(string $hook, array $payload = []): void
  {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is always one of this class's own nimbus_*-prefixed constants; every call site passes an Events::* constant, never an arbitrary string.
    do_action($hook, $payload);
  }
}