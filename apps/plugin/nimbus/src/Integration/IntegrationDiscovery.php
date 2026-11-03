<?php
namespace Nimbus\Plugin\Integration;

if (!defined('ABSPATH')) {
  exit;
}

final class IntegrationDiscovery
{
  private const KNOWN_INTEGRATIONS = [
    [
      'key' => 'smart_slider',
      'label' => 'Smart Slider 3',
      'plugin_files' => ['smart-slider-3/smart-slider-3.php'],
      'directories' => ['smartslider3', 'nextend', 'nextend2'],
      'rewrite_supported' => true,
      'rewrite_action_label' => 'Rewrite Smart Slider URLs',
      'notes' => 'Supports database URL rewrites for Nextend storage tables and upload placeholders.',
    ],
    [
      'key' => 'slider_revolution',
      'label' => 'Slider Revolution',
      'plugin_files' => ['revslider/revslider.php', 'slider-revolution/revslider.php'],
      'directories' => ['revslider', 'revslider-assets', 'rs-plugin'],
      'rewrite_supported' => true,
      'rewrite_action_label' => 'Rewrite Slider Revolution URLs',
      'notes' => 'Rewrites Slider Revolution text columns inside revslider tables when local upload URLs need to move to storage.',
    ],
    [
      'key' => 'elementor',
      'label' => 'Elementor',
      'plugin_files' => ['elementor/elementor.php'],
      'directories' => ['elementor', 'elementor/css', 'elementor/thumbs'],
      'rewrite_supported' => true,
      'rewrite_action_label' => 'Rewrite Elementor URLs',
      'notes' => 'Rewrites Elementor JSON/postmeta payloads that store upload URLs inside _elementor_* postmeta keys.',
    ],
    [
      'key' => 'nextgen_gallery',
      'label' => 'NextGEN Gallery',
      'plugin_files' => ['nextgen-gallery/nggallery.php'],
      'directories' => ['nggallery', 'nextgen-gallery', 'galleries'],
      'rewrite_supported' => true,
      'rewrite_action_label' => 'Rewrite NextGEN Gallery URLs',
      'notes' => 'Rewrites NextGEN Gallery text columns in ngg_* tables when stored URLs still point at local uploads.',
    ],
  ];

  public function __construct(
    private SmartSliderAssetRewriter $smartSliderRewriter,
    private DatabaseIntegrationRewriter $databaseRewriter,
  )
  {
  }

  public function definition(string $key): ?array
  {
    foreach ($this->definitions() as $definition) {
      if (($definition['key'] ?? '') === $key) {
        return $definition;
      }
    }

    return null;
  }

  public function canRewrite(string $key): bool
  {
    $definition = $this->definition($key);

    return !empty($definition['rewrite_supported']) && is_callable($definition['rewrite_callback'] ?? null);
  }

  public function runRewrite(string $key): array
  {
    $definition = $this->definition($key);
    if (!$definition || !is_callable($definition['rewrite_callback'] ?? null)) {
      throw new \RuntimeException('This integration does not yet support automated URL rewriting.');
    }

    $result = call_user_func($definition['rewrite_callback']);

    return is_array($result) ? $result : [];
  }

  public function detectAll(): array
  {
    $plugins = $this->loadPlugins();
    $active = array_map('strval', (array) get_option('active_plugins', []));
    $uploads = wp_upload_dir();
    $baseDir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
    $knownDirectories = [];
    $results = [];
    $disabledKeys = array_map('strval', (array) get_option(NIMBUS_DISABLED_INTEGRATIONS_KEY, []));

    foreach ($this->definitions() as $definition) {
      $pluginFiles = (array) ($definition['plugin_files'] ?? []);
      $installedFile = '';
      foreach ($pluginFiles as $pluginFile) {
        if (isset($plugins[$pluginFile])) {
          $installedFile = (string) $pluginFile;
          break;
        }
      }

      $directories = $this->discoverDirectories($baseDir, (array) ($definition['directories'] ?? []));
      foreach ($directories as $directory) {
        $knownDirectories[] = basename($directory);
      }

      $tableCount = 0;
      $tables = [];
      if (is_callable($definition['table_callback'] ?? null)) {
        $tables = call_user_func($definition['table_callback']);
        if (!is_array($tables)) {
          $tables = [];
        }
        $tableCount = count($tables);
      }

      $results[] = [
        'key' => (string) ($definition['key'] ?? ''),
        'label' => (string) ($definition['label'] ?? ''),
        'plugin_installed' => $installedFile !== '',
        'plugin_active' => $installedFile !== '' && in_array($installedFile, $active, true),
        'plugin_file' => $installedFile,
        'directories' => $directories,
        'rewrite_supported' => !empty($definition['rewrite_supported']),
        'rewrite_ready' => $this->isRewriteReady($definition, $tableCount),
        'rewrite_action_label' => (string) ($definition['rewrite_action_label'] ?? __('Run rewrite', 'nimbus-media-offload')),
        'table_count' => $tableCount,
        'tables' => $tables,
        'notes' => (string) ($definition['notes'] ?? ''),
        'enabled' => !in_array((string) ($definition['key'] ?? ''), $disabledKeys, true),
      ];
    }

    $results[] = [
      'key' => 'custom_upload_roots',
      'label' => 'Discovered asset roots',
      'plugin_installed' => true,
      'plugin_active' => true,
      'plugin_file' => '',
      'directories' => $this->discoverCustomRoots($baseDir, $knownDirectories),
      'rewrite_supported' => false,
      'rewrite_ready' => false,
      'table_count' => 0,
      'tables' => [],
      'notes' => 'Top-level upload directories that do not look like standard year/month attachment paths.',
      'enabled' => !in_array('custom_upload_roots', $disabledKeys, true),
    ];

    return $results;
  }

  public function summary(): array
  {
    $items = $this->detectAll();

    return [
      'detected' => count(array_filter($items, static function (array $item): bool {
        return !empty($item['plugin_installed']) || !empty($item['directories']) || !empty($item['table_count']);
      })),
      'rewrite_ready' => count(array_filter($items, static function (array $item): bool {
        return !empty($item['rewrite_ready']);
      })),
      'items' => $items,
    ];
  }

  private function loadPlugins(): array
  {
    if (!function_exists('get_plugins')) {
      $pluginFile = ABSPATH . 'wp-admin/includes/plugin.php';
      if (file_exists($pluginFile)) {
        require_once $pluginFile;
      }
    }

    return function_exists('get_plugins') ? get_plugins() : [];
  }

  private function definitions(): array
  {
    $definitions = array_map(function (array $definition): array {
      $key = (string) ($definition['key'] ?? '');
      if ($key === 'smart_slider') {
        $definition['table_callback'] = [$this->smartSliderRewriter, 'getTables'];
        $definition['rewrite_callback'] = [$this->smartSliderRewriter, 'rewriteLocalToRemote'];
      } elseif ($key === 'slider_revolution') {
        $definition['table_callback'] = fn (): array => $this->databaseRewriter->tablesByPatterns(['{prefix}revslider_%']);
        $definition['rewrite_callback'] = fn (): array => $this->databaseRewriter->rewriteLocalToRemote('slider_revolution', [
          'table_patterns' => ['{prefix}revslider_%'],
        ]);
        $definition['rewrite_ready_callback'] = fn (array $unusedDefinition = [], int $unusedTableCount = 0): bool => !empty($this->databaseRewriter->tablesByPatterns(['{prefix}revslider_%']));
      } elseif ($key === 'elementor') {
        $definition['table_callback'] = static fn (): array => [];
        $definition['rewrite_callback'] = fn (): array => $this->databaseRewriter->rewriteLocalToRemote('elementor', [
          'postmeta_keys' => ['_elementor_data', '_elementor_page_settings'],
        ]);
        $definition['rewrite_ready_callback'] = fn (array $unusedDefinition = [], int $unusedTableCount = 0): bool => $this->databaseRewriter->countPostmetaKeys(['_elementor_data', '_elementor_page_settings']) > 0;
      } elseif ($key === 'nextgen_gallery') {
        $definition['table_callback'] = fn (): array => $this->databaseRewriter->tablesByPatterns(['{prefix}ngg_%']);
        $definition['rewrite_callback'] = fn (): array => $this->databaseRewriter->rewriteLocalToRemote('nextgen_gallery', [
          'table_patterns' => ['{prefix}ngg_%'],
        ]);
        $definition['rewrite_ready_callback'] = fn (array $unusedDefinition = [], int $unusedTableCount = 0): bool => !empty($this->databaseRewriter->tablesByPatterns(['{prefix}ngg_%']));
      }

      return $definition;
    }, self::KNOWN_INTEGRATIONS);

    $definitions = apply_filters('nimbus_integrations', $definitions);

    return is_array($definitions) ? array_values($definitions) : [];
  }

  private function isRewriteReady(array $definition, int $tableCount): bool
  {
    if (is_callable($definition['rewrite_ready_callback'] ?? null)) {
      return (bool) call_user_func($definition['rewrite_ready_callback'], $definition, $tableCount);
    }

    if (($definition['key'] ?? '') === 'smart_slider') {
      return $tableCount > 0;
    }

    return !empty($definition['rewrite_supported']) && !empty($definition['rewrite_ready']);
  }

  private function discoverDirectories(string $baseDir, array $candidates): array
  {
    if ($baseDir === '' || !is_dir($baseDir)) {
      return [];
    }

    $found = [];
    foreach ($candidates as $candidate) {
      $path = trailingslashit($baseDir) . ltrim((string) $candidate, '/');
      if (is_dir($path)) {
        $found[] = $path;
      }
    }

    return array_values(array_unique($found));
  }

  private function discoverCustomRoots(string $baseDir, array $knownDirectories): array
  {
    if ($baseDir === '' || !is_dir($baseDir)) {
      return [];
    }

    $entries = scandir($baseDir);
    if (!is_array($entries)) {
      return [];
    }

    $roots = [];
    $skip = array_map('strtolower', array_merge($knownDirectories, ['.', '..', 'sites', 'cache', 'woocommerce_uploads', 'sb-instagram-feed-images']));

    foreach ($entries as $entry) {
      $entry = (string) $entry;
      if ($entry === '' || in_array(strtolower($entry), $skip, true)) {
        continue;
      }

      $path = trailingslashit($baseDir) . $entry;
      if (!is_dir($path)) {
        continue;
      }

      if (preg_match('/^\d{4}$/', $entry)) {
        continue;
      }

      $roots[] = $path;
    }

    sort($roots);

    return array_slice(array_values(array_unique($roots)), 0, 20);
  }
}
