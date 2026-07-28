<?php
namespace Nimbus\Plugin\Onboarding;

use Nimbus\Plugin\Config\Settings;

if (!defined('ABSPATH')) {
  exit;
}

final class OnboardingManager
{
  private const DEFAULTS = [
    'completed' => false,
    'completed_at' => 0,
  ];

  public function __construct(
    private Settings $settings,
    private ConflictDetector $conflictDetector,
  ) {
  }

  public function handleActivationRedirect(): void
  {
    if (!is_admin() || !current_user_can('manage_options')) {
      return;
    }

    if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_CRON') && DOING_CRON)) {
      return;
    }

    if ((string) get_option(NIMBUS_ONBOARDING_REDIRECT_OPTION, '') !== '1') {
      return;
    }

    delete_option(NIMBUS_ONBOARDING_REDIRECT_OPTION);

    $url = add_query_arg([
      'page' => NIMBUS_SLUG,
    ], admin_url('upload.php'));

    wp_safe_redirect($url);
    exit;
  }

  public function state(): array
  {
    $stored = get_option(NIMBUS_ONBOARDING_OPTION_KEY, []);
    $state = wp_parse_args(is_array($stored) ? $stored : [], self::DEFAULTS);
    $conflicts = $this->conflictDetector->detectSimilarPlugins();

    return [
      'completed' => !empty($state['completed']),
      'completed_at' => max(0, (int) ($state['completed_at'] ?? 0)),
      'current_step' => isset($state['current_step']) ? (string) $state['current_step'] : '',
      'storage_ready' => $this->settings->has_valid_credentials(),
      'conflicts' => $conflicts,
      'has_active_conflicts' => count(array_filter($conflicts, static fn (array $plugin): bool => !empty($plugin['active']))) > 0,
    ];
  }

  public function isCompleted(): bool
  {
    return (bool) ($this->state()['completed'] ?? false);
  }

  public function saveStep(string $step): void
  {
    $stored = get_option(NIMBUS_ONBOARDING_OPTION_KEY, []);
    $state = is_array($stored) ? $stored : [];
    $state['current_step'] = $step;
    update_option(NIMBUS_ONBOARDING_OPTION_KEY, $state, false);
  }

  public function markCompleted(): void
  {
    update_option(NIMBUS_ONBOARDING_OPTION_KEY, [
      'completed' => true,
      'completed_at' => time(),
    ], false);
    delete_option(NIMBUS_ONBOARDING_REDIRECT_OPTION);
  }
}
