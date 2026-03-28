<?php
namespace ArunPrakash\MediaOffload\Admin;

use ArunPrakash\MediaOffload\Config\Settings;

if (!defined('ABSPATH')) {
  exit;
}

final class FieldRenderer
{
  public function __construct(
    private Settings $settings,
    private ProviderCatalog $providerCatalog,
  )
  {
  }

  public function renderToggle(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $label = $args['label'] ?? '';
    $checked = !empty($settings[$key]);

    printf(
      '<label class="mo-toggle-switch"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> <span class="mo-toggle-label">%4$s</span></label>',
      esc_attr(MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      checked($checked, true, false),
      esc_html($label)
    );
  }

  public function renderProviderField(): void
  {
    $settings = $this->settings->all();
    $provider = (string) ($settings['provider'] ?? 's3');
    $providers = $this->providerCatalog->selectOptions();
    $providerDetails = $this->providerCatalog->all();
    $currentProvider = $providerDetails[$provider] ?? ($providerDetails['s3'] ?? [
      'title' => __('Storage provider', MEDIA_OFFLOAD_TEXT_DOMAIN),
      'summary' => '',
      'required' => [],
    ]);
    $requiredLabels = $this->normalizeRequiredLabels((array) ($currentProvider['required'] ?? []));
    ?>
    <div class="pt-mo-provider-field">
      <div class="pt-mo-provider-picker">
        <select id="media-offload-provider-select" name="<?php echo esc_attr(MEDIA_OFFLOAD_OPTION_KEY); ?>[provider]">
          <?php foreach ($providers as $key => $providerConfig) : ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($provider, $key); ?>><?php echo esc_html($providerConfig['label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="pt-mo-provider-summary">
        <div class="pt-mo-provider-summary-header">
          <div>
            <h3 id="pt-mo-provider-summary-title"><?php echo esc_html((string) ($currentProvider['title'] ?? __('Storage provider', MEDIA_OFFLOAD_TEXT_DOMAIN))); ?></h3>
            <p id="pt-mo-provider-summary-copy"><?php echo esc_html((string) ($currentProvider['summary'] ?? '')); ?></p>
          </div>
          <button type="button" class="button-link pt-mo-advanced-toggle" id="pt-mo-toggle-advanced" aria-expanded="false">
            <?php esc_html_e('Show advanced connection fields', MEDIA_OFFLOAD_TEXT_DOMAIN); ?>
          </button>
        </div>

        <div class="pt-mo-chip-list" id="pt-mo-provider-required-fields">
          <?php foreach ($requiredLabels as $requiredFieldLabel) : ?>
            <span class="pt-mo-chip"><?php echo esc_html((string) $requiredFieldLabel); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <p class="description"><?php esc_html_e('The form below now focuses on required fields first. Provider-specific extras stay hidden until you need them.', MEDIA_OFFLOAD_TEXT_DOMAIN); ?></p>
    <?php
  }

  /**
   * @param array<int, string> $labels
   * @return array<int, string>
   */
  private function normalizeRequiredLabels(array $labels): array
  {
    $labels = array_values(array_filter(array_map('strval', $labels)));

    if (count($labels) === 1 && strtolower($labels[0]) === 'provider') {
      return [__('No extra credentials required', MEDIA_OFFLOAD_TEXT_DOMAIN)];
    }

    return $labels;
  }

  public function renderSelectField(array $args): void
  {
    $settings = $this->settings->all();
    $key = (string) ($args['key'] ?? '');
    $value = (string) ($settings[$key] ?? ($args['default'] ?? ''));
    $options = is_array($args['options'] ?? null) ? $args['options'] : [];

    printf(
      '<select name="%1$s[%2$s]">',
      esc_attr(MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key)
    );

    foreach ($options as $optionValue => $label) {
      printf(
        '<option value="%1$s" %2$s>%3$s</option>',
        esc_attr((string) $optionValue),
        selected($value, (string) $optionValue, false),
        esc_html((string) $label)
      );
    }

    echo '</select>';

    if (!empty($args['description'])) {
      printf('<p class="description">%s</p>', esc_html((string) $args['description']));
    }
  }

  public function renderTextField(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? '';
    $placeholder = $args['placeholder'] ?? '';
    $description = $args['description'] ?? '';
    $required = !empty($args['required']) ? 'required' : '';

    printf(
      '<input type="text" name="%1$s[%2$s]" value="%3$s" class="regular-text" placeholder="%4$s" %5$s />',
        esc_attr(MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      esc_attr($value),
      esc_attr($placeholder),
      $required
    );

    if ($description) {
      printf('<p class="description">%s</p>', esc_html($description));
    }
  }

  public function renderTimeField(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? '03:00';
    $description = $args['description'] ?? '';

    printf(
      '<input type="time" name="%1$s[%2$s]" value="%3$s" step="60" />',
        esc_attr(MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      esc_attr($value)
    );

    if ($description) {
      printf('<p class="description">%s</p>', esc_html($description));
    }
  }

  public function renderNumberField(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? ($args['default'] ?? '');
    $description = $args['description'] ?? '';
    $min = isset($args['min']) ? (int) $args['min'] : 0;
    $max = isset($args['max']) ? (int) $args['max'] : 999999;
    $step = isset($args['step']) ? (int) $args['step'] : 1;

    printf(
      '<input type="number" name="%1$s[%2$s]" value="%3$s" min="%4$d" max="%5$d" step="%6$d" class="small-text" />',
        esc_attr(MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      esc_attr((string) $value),
      $min,
      $max,
      $step
    );

    if ($description) {
      printf('<p class="description">%s</p>', esc_html($description));
    }
  }

  public function renderTextareaField(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = (string) ($settings[$key] ?? '');
    $placeholder = (string) ($args['placeholder'] ?? '');
    $rows = isset($args['rows']) ? max(2, (int) $args['rows']) : 4;

    printf(
      '<textarea name="%1$s[%2$s]" rows="%3$d" class="large-text code" placeholder="%4$s">%5$s</textarea>',
        esc_attr(MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      $rows,
      esc_attr($placeholder),
      esc_textarea($value)
    );

    if (!empty($args['description'])) {
      printf('<p class="description">%s</p>', esc_html($args['description']));
    }
  }

  public function renderPasswordField(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? '';
    $required = !empty($args['required']) ? 'required' : '';

    printf(
      '<input type="password" name="%1$s[%2$s]" value="%3$s" class="regular-text" autocomplete="off" %4$s />',
        esc_attr(MEDIA_OFFLOAD_OPTION_KEY),
      esc_attr($key),
      esc_attr($value),
      $required
    );

    if (!empty($args['description'])) {
      printf('<p class="description">%s</p>', esc_html($args['description']));
    }
  }
}
