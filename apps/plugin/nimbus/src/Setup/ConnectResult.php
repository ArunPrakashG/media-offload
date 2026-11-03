<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * What a quick connect run produced: the storage values to fill into the
 * wizard, the steps it completed, and the buckets the user could pick instead.
 */
final class ConnectResult
{
  /** @var array<int, array{label: string, detail: string}> */
  private array $steps = [];

  /** @var array<string, string|bool> */
  public array $values = [];

  /** @var string[] */
  public array $buckets = [];

  /** @var array<int, array{id: string, name: string}> */
  public array $accounts = [];

  public bool $public = true;

  public string $note = '';

  public function step(string $label, string $detail = ''): void
  {
    $this->steps[] = ['label' => $label, 'detail' => $detail];
  }

  public function toArray(): array
  {
    return [
      'values' => $this->values,
      'steps' => $this->steps,
      'buckets' => array_values(array_unique($this->buckets)),
      'accounts' => $this->accounts,
      'public' => $this->public,
      'note' => $this->note,
    ];
  }
}
