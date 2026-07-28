<?php
namespace Nimbus\Plugin\Media;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Encapsulates all postmeta read/write operations for Nimbus attachment state.
 *
 * This repository is the single point of truth for the six Nimbus meta keys:
 *   - _nimbus              : offload data (key, sizes map, file_size_bytes)
 *   - _nimbus_ignore       : whether this attachment is excluded from offloading
 *   - _nimbus_pending_at   : timestamp when the attachment entered the pending queue
 *   - _nimbus_claimed_by   : worker token that has locked this attachment for processing
 *   - _nimbus_claimed_at   : timestamp when the current worker claimed it
 *   - _nimbus_missing_source_at : timestamp when the local source was detected as missing
 */
final class AttachmentRepository
{
  // ── Offload meta ─────────────────────────────────────────────────────────

  /**
   * Return the full _nimbus offload meta array, or [] if not offloaded.
   *
   * @return array<string, mixed>
   */
  public function getOffloadMeta(int $attachmentId): array
  {
    $meta = get_post_meta($attachmentId, NIMBUS_META_KEY, true);
    return is_array($meta) ? $meta : [];
  }

  /**
   * Persist the full _nimbus offload meta array.
   *
   * @param array<string, mixed> $meta
   */
  public function setOffloadMeta(int $attachmentId, array $meta): void
  {
    update_post_meta($attachmentId, NIMBUS_META_KEY, $meta);
  }

  /** Remove the _nimbus offload meta (called on attachment delete or restore). */
  public function deleteOffloadMeta(int $attachmentId): void
  {
    delete_post_meta($attachmentId, NIMBUS_META_KEY);
  }

  /** Return true if this attachment has been offloaded (has a remote key). */
  public function hasBeenOffloaded(int $attachmentId): bool
  {
    $meta = $this->getOffloadMeta($attachmentId);
    return !empty($meta['key']) && is_string($meta['key']);
  }

  // ── Ignore flag ───────────────────────────────────────────────────────────

  public function isIgnored(int $attachmentId): bool
  {
    return (string) get_post_meta($attachmentId, NIMBUS_IGNORE_META_KEY, true) === '1';
  }

  public function setIgnored(int $attachmentId): void
  {
    update_post_meta($attachmentId, NIMBUS_IGNORE_META_KEY, '1');
  }

  public function clearIgnored(int $attachmentId): void
  {
    delete_post_meta($attachmentId, NIMBUS_IGNORE_META_KEY);
  }

  // ── Pending state ─────────────────────────────────────────────────────────

  /** Return the Unix timestamp the attachment entered the pending queue, or 0. */
  public function getPendingAt(int $attachmentId): int
  {
    return (int) get_post_meta($attachmentId, NIMBUS_PENDING_AT_META_KEY, true);
  }

  /** Mark the attachment as pending only if it is not already pending. */
  public function markPending(int $attachmentId): void
  {
    if ($this->getPendingAt($attachmentId) <= 0) {
      update_post_meta($attachmentId, NIMBUS_PENDING_AT_META_KEY, time());
    }
  }

  /** Remove all pending-state meta keys (pending_at, claimed_by, claimed_at). */
  public function clearPendingState(int $attachmentId): void
  {
    delete_post_meta($attachmentId, NIMBUS_PENDING_AT_META_KEY);
    delete_post_meta($attachmentId, NIMBUS_CLAIMED_BY_META_KEY);
    delete_post_meta($attachmentId, NIMBUS_CLAIMED_AT_META_KEY);
  }

  // ── Worker claim ──────────────────────────────────────────────────────────

  /** Return the worker token that currently holds the claim, or ''. */
  public function getClaimedBy(int $attachmentId): string
  {
    return (string) get_post_meta($attachmentId, NIMBUS_CLAIMED_BY_META_KEY, true);
  }

  /** Return the Unix timestamp of the current claim, or 0. */
  public function getClaimedAt(int $attachmentId): int
  {
    return (int) get_post_meta($attachmentId, NIMBUS_CLAIMED_AT_META_KEY, true);
  }

  /**
   * Attempt to claim the attachment for the given worker token.
   *
   * Returns true on success. Returns false if already claimed by a different
   * worker whose claim has not yet expired.
   */
  public function claim(int $attachmentId, string $workerToken, int $ttlSeconds): bool
  {
    $existing = $this->getClaimedBy($attachmentId);
    if ($existing !== '' && $existing !== $workerToken) {
      $age = time() - $this->getClaimedAt($attachmentId);
      if ($age < $ttlSeconds) {
        return false;
      }
    }

    update_post_meta($attachmentId, NIMBUS_CLAIMED_BY_META_KEY, $workerToken);
    update_post_meta($attachmentId, NIMBUS_CLAIMED_AT_META_KEY, time());
    return true;
  }

  public function releaseClaim(int $attachmentId): void
  {
    delete_post_meta($attachmentId, NIMBUS_CLAIMED_BY_META_KEY);
    delete_post_meta($attachmentId, NIMBUS_CLAIMED_AT_META_KEY);
  }

  // ── Missing source marker ─────────────────────────────────────────────────

  /** Return the Unix timestamp when the local source was detected missing, or 0. */
  public function getMissingSourceAt(int $attachmentId): int
  {
    return (int) get_post_meta($attachmentId, NIMBUS_MISSING_SOURCE_AT_META_KEY, true);
  }

  public function markMissingSource(int $attachmentId): void
  {
    if ($this->getMissingSourceAt($attachmentId) <= 0) {
      update_post_meta($attachmentId, NIMBUS_MISSING_SOURCE_AT_META_KEY, time());
    }
  }

  public function clearMissingSource(int $attachmentId): void
  {
    delete_post_meta($attachmentId, NIMBUS_MISSING_SOURCE_AT_META_KEY);
  }
}
