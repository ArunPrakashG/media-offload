<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

interface Storage_Adapter
{
  /**
   * Upload a file to remote storage.
   *
   * @return string Remote key that was stored.
   */
  public function upload(string $source_path, string $destination_key, array $args = []): string;

  /**
   * Remove an object from storage.
   */
  public function delete(string $key): void;

  /**
   * Download a file from remote storage to local path.
   */
  public function download(string $key, string $destination_path): void;

  /**
   * Generate public URL for a storage key.
   */
  public function get_url(string $key): string;

  /**
   * Check whether an object exists and is accessible in storage.
   */
  public function exists(string $key): bool;

  /**
   * Return the subset of provided keys that exist in storage.
   *
    * @param array<int, string> $keys
    * @param array<string, mixed> $options
   * @return array<int, string>
   */
  public function existing_keys(array $keys, array $options = []): array;

  /**
   * Get object size in bytes.
   *
   * @throws \RuntimeException When object does not exist or cannot be read.
   */
  public function get_object_size(string $key): int;
}
