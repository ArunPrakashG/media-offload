<?php
namespace ArunPrakash\MediaOffload\Storage;

if (!defined('ABSPATH')) {
  exit;
}

interface StorageAdapter
{
  public function upload(string $sourcePath, string $destinationKey, array $args = []): string;

  public function delete(string $key): void;

  public function download(string $key, string $destinationPath): void;

  public function getUrl(string $key): string;

  public function exists(string $key): bool;

  public function existingKeys(array $keys, array $options = []): array;

  public function getObjectSize(string $key): int;
}