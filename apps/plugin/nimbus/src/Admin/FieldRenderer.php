<?php
namespace Nimbus\Plugin\Admin;

use Nimbus\Plugin\Config\Settings;

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
      '<label class="nmb-toggle-switch"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> <span class="nmb-toggle-label">%4$s</span></label>',
      esc_attr(NIMBUS_OPTION_KEY),
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
      'title' => __('Storage provider', 'nimbus'),
      'summary' => '',
      'required' => [],
    ]);
    $requiredLabels = $this->normalizeRequiredLabels((array) ($currentProvider['required'] ?? []));
    ?>
    <div class="nmb-provider-field">
      <div class="nmb-provider-picker">
        <select id="nimbus-provider-select" name="<?php echo esc_attr(NIMBUS_OPTION_KEY); ?>[provider]">
          <?php foreach ($providers as $key => $providerConfig) : ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($provider, $key); ?>><?php echo esc_html($providerConfig['label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="nmb-provider-summary">
        <div class="nmb-provider-summary-header">
          <div>
            <h3 id="nmb-provider-summary-title"><?php echo esc_html((string) ($currentProvider['title'] ?? __('Storage provider', 'nimbus'))); ?></h3>
            <p id="nmb-provider-summary-copy"><?php echo esc_html((string) ($currentProvider['summary'] ?? '')); ?></p>
          </div>
          <button type="button" class="button-link nmb-advanced-toggle" id="nmb-toggle-advanced" aria-expanded="false">
            <?php esc_html_e('Show advanced connection fields', 'nimbus'); ?>
          </button>
        </div>

        <div class="nmb-chip-list" id="nmb-provider-required-fields">
          <?php foreach ($requiredLabels as $requiredFieldLabel) : ?>
            <span class="nmb-chip"><?php echo esc_html((string) $requiredFieldLabel); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <p class="description"><?php esc_html_e('The form below now focuses on required fields first. Provider-specific extras stay hidden until you need them.', 'nimbus'); ?></p>
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
      return [__('No extra credentials required', 'nimbus')];
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
      esc_attr(NIMBUS_OPTION_KEY),
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
        esc_attr(NIMBUS_OPTION_KEY),
      esc_attr($key),
      esc_attr($value),
      esc_attr($placeholder),
      esc_attr($required)
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
        esc_attr(NIMBUS_OPTION_KEY),
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
        esc_attr(NIMBUS_OPTION_KEY),
      esc_attr($key),
      esc_attr((string) $value),
      (int) $min,
      (int) $max,
      (int) $step
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
        esc_attr(NIMBUS_OPTION_KEY),
      esc_attr($key),
      (int) $rows,
      esc_attr($placeholder),
      esc_textarea($value)
    );

    if (!empty($args['description'])) {
      printf('<p class="description">%s</p>', esc_html($args['description']));
    }
  }

  /**
   * Render a pill-based path pattern input.
   *
   * Stores value as a newline-delimited string in a hidden textarea so that
   * form submission and sanitization are identical to the plain textarea field.
   * The visible interface shows:
   *   - Discovered upload folder suggestions (pillsas clickable add-buttons)
   *   - Current patterns as removable pills
   *   - A free-text input for custom patterns
   */
  public function renderPathPillField(array $args): void
  {
    $settings = $this->settings->all();
    $key      = $args['key'];
    $value    = (string) ($settings[$key] ?? '');

    // Existing patterns: split stored newline list.
    $existing = array_values(array_filter(array_map('trim', explode("\n", $value))));

    $fieldName = esc_attr(NIMBUS_OPTION_KEY) . '[' . esc_attr($key) . ']';
    $widgetId  = 'nmb-pill-' . esc_attr($key);
    ?>
    <div class="nmb-path-pill-widget" id="<?php echo esc_attr($widgetId); ?>" data-field-key="<?php echo esc_attr($key); ?>">

      <!-- Hidden textarea that gets submitted — keep name identical to old field -->
      <textarea name="<?php echo esc_attr($fieldName); ?>"
                id="<?php echo esc_attr($widgetId); ?>-textarea"
                class="nmb-path-pill-hidden-textarea"
                hidden><?php echo esc_textarea($value); ?></textarea>

      <!-- Suggestion strip — populated by JS from nimbusAdmin.assetRoots -->
      <div class="nmb-path-pill-suggestions" id="<?php echo esc_attr($widgetId); ?>-suggestions" aria-label="<?php esc_attr_e('Discovered upload folders — click to add', 'nimbus'); ?>" hidden></div>

      <!-- Active pill list -->
      <div class="nmb-path-pill-list" id="<?php echo esc_attr($widgetId); ?>-list" role="list"
           aria-label="<?php esc_attr_e('Active path patterns', 'nimbus'); ?>">
        <?php foreach ($existing as $pattern) : ?>
          <span class="nmb-path-pill nmb-path-pill--active" role="listitem" data-value="<?php echo esc_attr($pattern); ?>">
            <span class="nmb-path-pill-label"><?php echo esc_html($pattern); ?></span><!--
            --><button type="button" class="nmb-path-pill-remove" aria-label="<?php /* translators: %s: path pattern being removed */ echo esc_attr(sprintf(__('Remove %s', 'nimbus'), $pattern)); ?>">×</button>
          </span>
        <?php endforeach; ?>
      </div>

      <!-- Custom entry input -->
      <div class="nmb-path-pill-input-row">
        <input type="text"
               id="<?php echo esc_attr($widgetId); ?>-input"
               class="nmb-path-pill-input"
               placeholder="<?php esc_attr_e('Type a pattern and press Enter or comma…', 'nimbus'); ?>"
               autocomplete="off"
               spellcheck="false" />
        <button type="button" class="button nmb-path-pill-add-btn"><?php esc_html_e('Add', 'nimbus'); ?></button>
      </div>

    </div>
    <?php if (!empty($args['description'])) : ?>
      <p class="description"><?php echo esc_html($args['description']); ?></p>
    <?php endif;
  }

  public function renderPasswordField(array $args): void
  {
    $settings = $this->settings->all();
    $key = $args['key'];
    $value = $settings[$key] ?? '';
    $required = !empty($args['required']) ? 'required' : '';

    printf(
      '<input type="password" name="%1$s[%2$s]" value="%3$s" class="regular-text" autocomplete="off" %4$s />',
        esc_attr(NIMBUS_OPTION_KEY),
      esc_attr($key),
      esc_attr($value),
      esc_attr($required)
    );

    if (!empty($args['description'])) {
      printf('<p class="description">%s</p>', esc_html($args['description']));
    }
  }
}
