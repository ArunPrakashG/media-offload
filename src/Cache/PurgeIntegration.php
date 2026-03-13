<?php
namespace ArunPrakash\MediaOffload\Cache;

if (!defined('ABSPATH')) {
  exit;
}

interface PurgeIntegration
{
  public function id(): string;

  /**
   * @param array<string, mixed> $request
   * @return array<string, mixed>
   */
  public function purge(array $request): array;
}