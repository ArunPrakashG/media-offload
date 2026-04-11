<?php
namespace Nimbus\Plugin\Bootstrap;

if (!defined('ABSPATH')) {
  exit;
}

final class Container
{
  /**
   * @var array<string, mixed>
   */
  private array $entries = [];

  public function has(string $id): bool
  {
    return array_key_exists($id, $this->entries);
  }

  /**
   * @param mixed $value
   */
  public function set(string $id, $value): void
  {
    $this->entries[$id] = $value;
  }

  /**
   * @template T
   * @param callable(self): T|null $factory
   * @return T
   */
  public function get(string $id, ?callable $factory = null)
  {
    if ($this->has($id)) {
      return $this->entries[$id];
    }

    if ($factory === null) {
      throw new \RuntimeException(sprintf('Service "%s" has not been registered.', $id));
    }

    $this->entries[$id] = $factory($this);

    return $this->entries[$id];
  }
}
