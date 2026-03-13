<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

class Local_Storage_Adapter implements Storage_Adapter
{
  private string $base_path;

  private string $base_url;

  private Logger $logger;

  public function __construct(array $config, Logger $logger)
  {
    $uploads = wp_upload_dir();
    $default_path = trailingslashit($uploads['basedir']) . 'pt-media-offload';
    $default_url = trailingslashit($uploads['baseurl']) . 'pt-media-offload';

    $this->base_path = rtrim($config['root_path'] ?? $default_path, '/');
    $this->base_url = rtrim($config['root_url'] ?? $default_url, '/');
    $this->logger = $logger;
  }

  public function upload(string $source_path, string $destination_key, array $args = []): string
  {
    $target_path = trailingslashit($this->base_path) . ltrim($destination_key, '/');
    ensure_directory($target_path);

    if (!copy($source_path, $target_path)) {
      $this->logger->error('Failed to copy {source} to {target}', ['source' => $source_path, 'target' => $target_path]);
      throw new \RuntimeException('Unable to copy file to local adapter destination.');
    }

    return $destination_key;
  }

  public function delete(string $key): void
  {
    $path = trailingslashit($this->base_path) . ltrim($key, '/');
    if (file_exists($path)) {
      @unlink($path);
    }
  }

  public function download(string $key, string $destination_path): void
  {
    $source_path = trailingslashit($this->base_path) . ltrim($key, '/');
    
    if (!file_exists($source_path)) {
        throw new \RuntimeException("File not found in local storage: $key");
    }

    ensure_directory(dirname($destination_path));

    if (!copy($source_path, $destination_path)) {
      $this->logger->error('Failed to download (copy) {source} to {target}', ['source' => $source_path, 'target' => $destination_path]);
      throw new \RuntimeException('Unable to copy file from local storage.');
    }
  }

  public function get_url(string $key): string
  {
    return trailingslashit($this->base_url) . ltrim($key, '/');
  }

  public function exists(string $key): bool
  {
    $path = trailingslashit($this->base_path) . ltrim($key, '/');
    return file_exists($path) && is_readable($path);
  }

  public function existing_keys(array $keys, array $options = []): array
  {
    $existing = [];

    foreach ($keys as $key) {
      $normalized_key = ltrim((string) $key, '/');
      if ($normalized_key === '') {
        continue;
      }

      if ($this->exists($normalized_key)) {
        $existing[$normalized_key] = true;
      }
    }

    return array_keys($existing);
  }

  public function get_object_size(string $key): int
  {
    $path = trailingslashit($this->base_path) . ltrim($key, '/');

    if (!file_exists($path) || !is_readable($path)) {
      throw new \RuntimeException(sprintf('Object not found in local adapter: %s', $key));
    }

    $size = filesize($path);
    if ($size === false) {
      throw new \RuntimeException(sprintf('Failed to read object size in local adapter: %s', $key));
    }

    return (int) $size;
  }
}
