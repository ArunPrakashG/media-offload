<?php
namespace Nimbus\Plugin\License;

if (!defined('ABSPATH')) {
  exit;
}

final class LicenseManager
{
  private const DEFAULTS = [
    'key' => '',
    'status' => 'inactive',
    'plan' => 'monthly',
    'last_validated_at' => 0,
  ];

  public function all(): array
  {
    $stored = get_option(NIMBUS_LICENSE_OPTION_KEY, []);
    return wp_parse_args(is_array($stored) ? $stored : [], self::DEFAULTS);
  }

  public function key(): string
  {
    return trim((string) ($this->all()['key'] ?? ''));
  }

  public function hasKey(): bool
  {
    return $this->key() !== '';
  }

  public function status(): string
  {
    return (string) ($this->all()['status'] ?? 'inactive');
  }

  public function update(string $key, array $meta = []): array
  {
    $payload = wp_parse_args($meta, self::DEFAULTS);
    $payload['key'] = sanitize_text_field($key);
    $payload['status'] = sanitize_key((string) ($payload['status'] ?? 'inactive')) ?: 'inactive';
    $payload['plan'] = sanitize_key((string) ($payload['plan'] ?? 'monthly')) ?: 'monthly';
    $payload['last_validated_at'] = max(0, (int) ($payload['last_validated_at'] ?? 0));

    update_option(NIMBUS_LICENSE_OPTION_KEY, $payload, false);

    return $payload;
  }
}
