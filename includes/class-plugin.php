<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

class Plugin
{
  private static ?Plugin $instance = null;

  private Settings $settings;

  private Logger $logger;

  private Storage_Adapter_Factory $adapter_factory;

  private Upload_Handler $upload_handler;

  private URL_Rewriter $url_rewriter;

  private Settings_UI $settings_ui;

  private CLI_Command $cli_command;

  private Statistics $statistics;

  public static function instance(): Plugin
  {
    if (!self::$instance instanceof Plugin) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  public function boot(): void
  {
    $this->logger = new Logger();
    $this->settings = new Settings($this->logger);
    $this->adapter_factory = new Storage_Adapter_Factory($this->settings, $this->logger);
    $this->statistics = new Statistics($this->adapter_factory);
    $this->upload_handler = new Upload_Handler($this->settings, $this->adapter_factory, $this->logger);
    $this->url_rewriter = new URL_Rewriter($this->settings, $this->adapter_factory, $this->logger);
    $this->settings_ui = new Settings_UI($this->settings, $this->logger, $this->statistics, $this->upload_handler);
    $this->cli_command = new CLI_Command($this->upload_handler, $this->settings, $this->adapter_factory, $this->logger);

    $this->register_hooks();
  }

  private function register_hooks(): void
  {
    add_action('init', [$this, 'on_init']);
    add_action('plugins_loaded', [$this, 'on_plugins_loaded'], 20);
    
    // Register UI hooks immediately so admin_menu action is added in time
    $this->settings_ui->register();
  }

  public function on_init(): void
  {
    // error_log('[PT Media Offload] on_init called');
    if ($this->settings->is_enabled()) {
      $this->url_rewriter->register();
    }
  }

  public function on_plugins_loaded(): void
  {
    $this->upload_handler->register();
    $this->cli_command->register();
  }
}
