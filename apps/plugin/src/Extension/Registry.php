<?php
namespace Nimbus\Plugin\Extension;

use Nimbus\Plugin\Bootstrap\Container;
use Nimbus\Plugin\Extension\Contracts\ProExtension;
use Nimbus\Plugin\License\LicenseManager;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Holds and boots every registered Nimbus pro extension.
 *
 * Usage in nimbus-pro.php (before plugins_loaded fires):
 *
 *   add_action('nimbus/extensions/register', function (Registry $registry) {
 *       $registry->register(new ProPlugin());
 *   });
 *
 * The free plugin fires `nimbus/extensions/register` at plugins_loaded priority 0
 * and calls `bootAll()` at priority 25.
 */
final class Registry
{
  /** @var ProExtension[] */
  private array $extensions = [];

  public function register(ProExtension $extension): void
  {
    $this->extensions[] = $extension;
  }

  /**
   * Boot all registered extensions in registration order.
   * Called by Plugin::bootExtensions() at plugins_loaded priority 25.
   */
  public function bootAll(Container $container, LicenseManager $lm): void
  {
    foreach ($this->extensions as $extension) {
      $extension->boot($container, $lm);
    }
  }

  public function isEmpty(): bool
  {
    return empty($this->extensions);
  }
}
