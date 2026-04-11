<?php
namespace Nimbus\Plugin\Extension\Contracts;

use Nimbus\Plugin\Bootstrap\Container;
use Nimbus\Plugin\Extension\Registry;
use Nimbus\Plugin\License\LicenseManager;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Contract that every Nimbus pro extension must implement.
 *
 * A pro extension is typically a separate plugin (e.g. nimbus-pro) that extends
 * the free Nimbus plugin with gated features. The two-phase lifecycle keeps
 * the dependency graph clean:
 *
 *   1. register() – called synchronously during the `nimbus/extensions/register`
 *      WordPress action. Use this to declare service factories in the shared
 *      Container, register WP action/filter hooks, or do any work that must
 *      happen before `plugins_loaded` priority 25.
 *
 *   2. boot() – called at `plugins_loaded` priority 25, after the free plugin
 *      has initialised (priority 20). Instantiate services here and wire them
 *      into the free plugin (e.g. via `apply_filters('nimbus/bulk_job_manager')`).
 */
interface ProExtension
{
  /**
   * Register the extension.
   *
   * @param Registry  $registry  The shared extension registry.
   * @param Container $container The shared service container.
   */
  public function register(Registry $registry, Container $container): void;

  /**
   * Boot pro services after the free plugin has fully initialised.
   *
   * @param Container    $container The shared service container.
   * @param LicenseManager $lm      The live license manager instance.
   */
  public function boot(Container $container, LicenseManager $lm): void;
}
