<?php
namespace Nimbus\Plugin\Storage;

use Nimbus\Plugin\Support\Logger;

use function Nimbus\Plugin\Support\ensureDirectory;

if (!defined('ABSPATH')) {
  exit;
}

final class LocalStorageAdapter implements StorageAdapter
{
  private string $basePath;

  private string $baseUrl;

  public function __construct(array $config, private Logger $logger)
  {
    $uploads = wp_upload_dir();
    $defaultPath = trailingslashit($uploads['basedir']) . NIMBUS_SLUG;
    $defaultUrl = trailingslashit($uploads['baseurl']) . NIMBUS_SLUG;

    $this->basePath = rtrim($config['root_path'] ?? $defaultPath, '/');
    $this->baseUrl = rtrim($config['root_url'] ?? $defaultUrl, '/');
  }

  public function upload(string $sourcePath, string $destinationKey, array $args = []): string
  {
    $targetPath = trailingslashit($this->basePath) . ltrim($destinationKey, '/');
    ensureDirectory($targetPath);

    if (!copy($sourcePath, $targetPath)) {
      $this->logger->error('Failed to copy {source} to {target}', ['source' => $sourcePath, 'target' => $targetPath]);
      throw new \RuntimeException('Unable to copy file to local adapter destination.');
    }

    return $destinationKey;
  }

  public function delete(string $key): void
  {
    $path = trailingslashit($this->basePath) . ltrim($key, '/');
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Plain writability check on a local dev-adapter path; WP_Filesystem would require credential prompts inappropriate for same-server file management.
    if (file_exists($path) && is_writable($path)) {
      wp_delete_file($path);
    }
  }

  public function download(string $key, string $destinationPath): void
  {
    $sourcePath = trailingslashit($this->basePath) . ltrim($key, '/');
    if (!file_exists($sourcePath)) {
      // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message, never echoed directly; caught and logged or wrapped by callers.
      throw new \RuntimeException(sprintf('File not found in local storage: %s', $key));
    }

    if (!is_dir(dirname($destinationPath)) && !wp_mkdir_p(dirname($destinationPath))) {
      // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message, never echoed directly; caught and logged or wrapped by callers.
      throw new \RuntimeException(sprintf('Unable to prepare local destination: %s', dirname($destinationPath)));
    }

    if (!copy($sourcePath, $destinationPath)) {
      $this->logger->error('Failed to download (copy) {source} to {target}', ['source' => $sourcePath, 'target' => $destinationPath]);
      throw new \RuntimeException('Unable to copy file from local storage.');
    }
  }

  public function getUrl(string $key): string
  {
    return trailingslashit($this->baseUrl) . ltrim($key, '/');
  }

  public function exists(string $key): bool
  {
    $path = trailingslashit($this->basePath) . ltrim($key, '/');

    return file_exists($path) && is_readable($path);
  }

  public function existingKeys(array $keys, array $options = []): array
  {
    $existing = [];
    foreach ($keys as $key) {
      $normalizedKey = ltrim((string) $key, '/');
      if ($normalizedKey !== '' && $this->exists($normalizedKey)) {
        $existing[$normalizedKey] = true;
      }
    }

    return array_keys($existing);
  }

  public function getObjectSize(string $key): int
  {
    $path = trailingslashit($this->basePath) . ltrim($key, '/');
    if (!file_exists($path) || !is_readable($path)) {
      // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message, never echoed directly; caught and logged or wrapped by callers.
      throw new \RuntimeException(sprintf('Object not found in local adapter: %s', $key));
    }

    $size = filesize($path);
    if ($size === false) {
      // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message, never echoed directly; caught and logged or wrapped by callers.
      throw new \RuntimeException(sprintf('Failed to read object size in local adapter: %s', $key));
    }

    return (int) $size;
  }

  /**
   * Local storage has no signed URL concept — return the plain public URL.
   * This satisfies the optional presign capability expected by WooCommerce
   * and other integrations on development/staging environments.
   */
  public function presign(string $key, int $ttlSeconds = 3600): string
  {
    return $this->getUrl($key);
  }
}