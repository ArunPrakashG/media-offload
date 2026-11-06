<?php
namespace Nimbus\Plugin\Config;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Encrypts storage credentials inside the nimbus_settings option.
 *
 * Works on the option filters, so every get_option()/update_option() call sees
 * plain values while the database (and any backup of it) only holds ciphertext.
 * The key comes from NIMBUS_ENCRYPTION_KEY when defined, otherwise from the
 * site's LOGGED_IN_KEY and LOGGED_IN_SALT in wp-config.php.
 */
final class CredentialVault
{
  public const FIELDS = ['access_key', 'secret_key', 'session_token', 'cloudflare_api_token'];

  private const PREFIX = 'nimbus-enc:v1:';

  private static bool $registered = false;

  private static bool $sawPlaintext = false;

  private static bool $decryptFailed = false;

  public static function register(): void
  {
    if (self::$registered) {
      return;
    }

    add_filter('option_' . NIMBUS_OPTION_KEY, [self::class, 'decryptSettings']);
    add_filter('pre_update_option_' . NIMBUS_OPTION_KEY, [self::class, 'encryptSettings']);
    add_action('admin_init', [self::class, 'encryptLegacyValues']);
    add_action('admin_notices', [self::class, 'renderDecryptNotice']);
    add_action('network_admin_notices', [self::class, 'renderDecryptNotice']);
    self::$registered = true;
  }

  public static function isAvailable(): bool
  {
    return self::key() !== '';
  }

  /**
   * @param mixed $settings
   * @return mixed
   */
  public static function decryptSettings($settings)
  {
    if (!is_array($settings)) {
      return $settings;
    }

    foreach (self::FIELDS as $field) {
      $value = $settings[$field] ?? '';
      if (!is_string($value) || $value === '') {
        continue;
      }

      if (!str_starts_with($value, self::PREFIX)) {
        self::$sawPlaintext = true;
        continue;
      }

      $plain = self::decrypt($value);
      if ($plain === null) {
        self::$decryptFailed = true;
        $plain = '';
      }
      $settings[$field] = $plain;
    }

    return $settings;
  }

  /**
   * @param mixed $settings
   * @return mixed
   */
  public static function encryptSettings($settings)
  {
    if (!is_array($settings) || !self::isAvailable()) {
      return $settings;
    }

    foreach (self::FIELDS as $field) {
      $value = $settings[$field] ?? '';
      if (!is_string($value) || $value === '' || str_starts_with($value, self::PREFIX)) {
        continue;
      }

      $settings[$field] = self::encrypt($value);
    }

    return $settings;
  }

  /** Re-saves settings written before encryption existed, so they get encrypted. */
  public static function encryptLegacyValues(): void
  {
    $settings = get_option(NIMBUS_OPTION_KEY, null);
    if (!self::$sawPlaintext || !is_array($settings) || !self::isAvailable()) {
      return;
    }

    self::$sawPlaintext = false;
    update_option(NIMBUS_OPTION_KEY, $settings, false);
    wp_cache_delete(Settings::CACHE_KEY, Settings::CACHE_GROUP);
  }

  public static function renderDecryptNotice(): void
  {
    if (!self::$decryptFailed || !current_user_can('manage_options')) {
      return;
    }

    printf(
      '<div class="notice notice-error"><p>%s</p></div>',
      esc_html__('Plume could not read its saved storage credentials. This happens when the security keys in wp-config.php change. Enter your storage keys again in Media → Plume → Settings.', 'plume-media-offload')
    );
  }

  private static function encrypt(string $plain): string
  {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

    return self::PREFIX . base64_encode($nonce . $cipher);
  }

  private static function decrypt(string $stored): ?string
  {
    $key = self::key();
    $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
    if ($key === '' || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
      return null;
    }

    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    try {
      $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    } catch (\Throwable $e) {
      return null;
    }

    return $plain === false ? null : $plain;
  }

  /** 32-byte key, or '' when there is no key material or no sodium. */
  private static function key(): string
  {
    static $key = null;
    if ($key !== null) {
      return $key;
    }

    $material = '';
    if (defined('NIMBUS_ENCRYPTION_KEY') && (string) NIMBUS_ENCRYPTION_KEY !== '') {
      $material = (string) NIMBUS_ENCRYPTION_KEY;
    } elseif (defined('LOGGED_IN_KEY') && defined('LOGGED_IN_SALT')) {
      $material = (string) LOGGED_IN_KEY . (string) LOGGED_IN_SALT;
    }

    if ($material === '' || !function_exists('sodium_crypto_secretbox') || !function_exists('sodium_crypto_generichash')) {
      $key = '';
      return $key;
    }

    $key = sodium_crypto_generichash('nimbus-credentials|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    return $key;
  }
}
