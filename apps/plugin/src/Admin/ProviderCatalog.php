<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Storage\ProviderRegistry;

if (!defined('ABSPATH')) {
  exit;
}

final class ProviderCatalog
{
  public function __construct(private ?ProviderRegistry $providerRegistry = null)
  {
    $this->providerRegistry ??= new ProviderRegistry();
  }

  public function all(): array
  {
    $providers = [];

    foreach ($this->providerRegistry->all() as $key => $profile) {
      $providers[$key] = [
        'label' => $profile->label(),
        'title' => $profile->title(),
        'summary' => $profile->summary(),
        'required' => array_values($profile->requiredFields()),
      ];
    }

    return $providers;
  }

  public function selectOptions(): array
  {
    $options = [];

    foreach ($this->all() as $key => $provider) {
      $options[$key] = [
        'label' => $provider['label'],
      ];
    }

    return $options;
  }

  public function scriptData(): array
  {
    $providers = [];

    foreach ($this->all() as $key => $provider) {
      $providers[$key] = [
        'title' => $provider['title'],
        'summary' => $provider['summary'],
        'required' => $provider['required'],
      ];
    }

    return $providers;
  }
}
