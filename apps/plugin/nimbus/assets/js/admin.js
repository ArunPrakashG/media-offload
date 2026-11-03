jQuery(document).ready(function ($) {
  var adminConfig = window.nimbusAdmin || window.nimbusAdmin || {};
  var optionKey = adminConfig.optionKey || 'pt_media_offload_settings';
  var previousProvider = null;
  var connectionAdvancedOpen = false;
  var advancedConnectionFields = ['session_token', 'endpoint'];

  function fieldSelector(field) {
    return '[name="' + optionKey + '[' + field + ']"]';
  }

  function fieldControl($scope, field) {
    return $scope.find(fieldSelector(field)).first();
  }

  function rowForField($scope, field) {
    return fieldControl($scope, field).closest('tr');
  }

  function setRowVisible($row, visible) {
    if (!$row.length) {
      return;
    }

    var $controls = $row.find('input, select, textarea');
    $row.prop('hidden', !visible);

    if (visible) {
      $row.removeClass('pt-mo-hidden-row');
      $controls.prop('disabled', false);
      $controls.each(function () {
        var $control = $(this);
        if ($control.data('ptMoRequired') === true) {
          $control.prop('required', true);
        }
      });
      return;
    }

    $row.addClass('pt-mo-hidden-row');
    $controls.each(function () {
      var $control = $(this);
      if ($control.prop('required')) {
        $control.data('ptMoRequired', true);
      }
      $control.prop('required', false);
      $control.prop('disabled', true);
    });
  }

  function hasValue($control) {
    if (!$control.length) {
      return false;
    }

    if ($control.is(':checkbox') || $control.is(':radio')) {
      return $control.is(':checked');
    }

    return $.trim($control.val() || '') !== '';
  }

  function lineCount(value) {
    return $.grep(String(value || '').split(/\r\n|\r|\n/), function (line) {
      return $.trim(line) !== '';
    }).length;
  }

  function formatTemplate(template, values) {
    return String(template || '').replace(/%([0-9]+)\$d/g, function (_, index) {
      var position = parseInt(index, 10) - 1;
      return typeof values[position] !== 'undefined' ? values[position] : '0';
    });
  }

  function setText(selector, value) {
    var $target = $(selector);
    if ($target.length) {
      $target.text(value);
    }
  }

  function updateAdvancedToggle() {
    var $toggle = $('#nmb-toggle-advanced');
    if (!$toggle.length) {
      return;
    }

    var strings = adminConfig.strings || {};
    var label = connectionAdvancedOpen ? strings.hideAdvanced : strings.showAdvanced;
    $toggle.text(label || 'Show advanced connection fields');
    $toggle.attr('aria-expanded', connectionAdvancedOpen ? 'true' : 'false');
  }

  // Keys of fields whose <p class="description"> text should change per provider.
  var hintFieldKeys = ['bucket', 'region', 'access_key', 'secret_key', 'session_token', 'endpoint', 'r2_account_id'];

  // Snapshot the original description text so we can restore it when a provider
  // has no specific hint for a given field.
  function initFieldHintDefaults($form) {
    $.each(hintFieldKeys, function (_, key) {
      var $desc = rowForField($form, key).find('p.description').first();
      if ($desc.length && !$desc.data('nmbDefaultHint')) {
        $desc.data('nmbDefaultHint', $desc.text());
      }
    });
  }

  function updateFieldHints($form, provider) {
    var providers = adminConfig.providers || {};
    var hints = (providers[provider] && providers[provider].hints) || {};

    $.each(hintFieldKeys, function (_, key) {
      var $desc = rowForField($form, key).find('p.description').first();
      if (!$desc.length) {
        return;
      }

      if (Object.prototype.hasOwnProperty.call(hints, key)) {
        if (hints[key]) {
          $desc.text(hints[key]).prop('hidden', false);
        } else {
          // Empty string = this provider never uses the field; hide the hint.
          $desc.prop('hidden', true);
        }
      } else {
        // No provider-specific hint — restore the generic default.
        var defaultHint = $desc.data('nmbDefaultHint') || '';
        $desc.text(defaultHint).prop('hidden', !defaultHint);
      }
    });

    // Update the <th> label for r2_account_id: show provider-specific label when R2.
    var $r2Row = rowForField($form, 'r2_account_id');
    if ($r2Row.length) {
      var $th = $r2Row.find('th').first();
      if ($th.length) {
        if (!$th.data('nmbDefaultLabel')) {
          // Strip any trailing asterisk/whitespace from "Required" markup
          var rawLabel = $th.text().replace(/\s*\*\s*$/, '').trim();
          $th.data('nmbDefaultLabel', rawLabel);
        }
        var providerLabels = (providers[provider] && providers[provider].fieldLabels) || {};
        var newLabel = providerLabels.r2_account_id || $th.data('nmbDefaultLabel');
        // Preserve any child nodes (e.g. required asterisk <span>) and update only text node
        var $asterisk = $th.find('span.required');
        $th.contents().filter(function () { return this.nodeType === 3; }).remove();
        $th.prepend(document.createTextNode(newLabel + ' '));
        if (!$asterisk.length) {
          $th.text(newLabel);
        }
      }
    }
  }

  function renderProviderSummary(provider) {
    var providers = adminConfig.providers || {};
    var config = providers[provider] || providers.s3 || null;
    if (!config) {
      return;
    }

    var $title = $('#nmb-provider-summary-title');
    var $copy = $('#nmb-provider-summary-copy');
    var $chips = $('#nmb-provider-required-fields');

    $title.text(config.title || 'Storage provider');
    $copy.text(config.summary || '');
    $chips.empty();

    var required = (config.required || []).slice();
    var strings = adminConfig.strings || {};

    if (required.length === 1 && String(required[0]).toLowerCase() === 'provider') {
      required = [strings.noExtraCredentials || 'No extra credentials required'];
    }

    $.each(required, function (_, label) {
      $('<span/>', {
        'class': 'pt-mo-chip',
        text: label
      }).appendTo($chips);
    });
  }

  function updateSettingsSummary($form, provider) {
    var strings = adminConfig.strings || {};
    var providerLabel = fieldControl($form, 'provider').find('option:selected').text() || 'Storage provider';
    var bucketValue = $.trim(fieldControl($form, 'bucket').val() || '');
    var customDomainValue = $.trim(fieldControl($form, 'custom_domain').val() || '');
    var allowedCount = lineCount(fieldControl($form, 'allowed_path_patterns').val());
    var ignoredCount = lineCount(fieldControl($form, 'ignored_path_patterns').val());
    var offHoursEnabled = fieldControl($form, 'off_hours_batch_enabled').is(':checked');
    var batchTimeValue = $.trim(fieldControl($form, 'off_hours_batch_time').val() || '');
    var serveFromStorage = fieldControl($form, 'serve_from_storage').is(':checked');
    var isLocal = provider === 'local';

    var providerSubstat = isLocal
      ? (strings.providerNoBucket || 'No bucket required in local mode')
      : (bucketValue || strings.providerBucketNotSet || 'Bucket not set');

    var deliveryLabel = isLocal
      ? (strings.deliveryLocalDebug || 'Local debug')
      : (serveFromStorage ? (strings.deliveryRemote || 'Remote') : (strings.deliveryLocal || 'Local'));

    var deliverySubstat = customDomainValue || strings.deliveryNoCustomDomain || 'No custom domain configured';
    var rulesText = formatTemplate(strings.rulesTemplate || 'Allowed: %1$d · Ignored: %2$d', [allowedCount, ignoredCount]);
    var shellDelivery = isLocal
      ? (strings.shellLocalDebugMode || 'Local debug mode')
      : (serveFromStorage ? (strings.shellServingStorage || 'Serving from storage') : (strings.shellServingLocal || 'Serving from local URLs'));

    setText('#nmb-settings-provider-label', providerLabel);
    setText('#nmb-settings-provider-substat', providerSubstat);
    setText('#nmb-settings-delivery-label', deliveryLabel);
    setText('#nmb-settings-delivery-substat', deliverySubstat);
    setText('#nmb-settings-rules-count', String(allowedCount + ignoredCount));
    setText('#nmb-settings-rules-substat', rulesText);
    setText('#nmb-settings-batch-label', offHoursEnabled ? (strings.batchOn || 'On') : (strings.batchOff || 'Off'));
    setText('#nmb-settings-batch-substat', batchTimeValue || (strings.batchNoSchedule || 'No schedule set'));
    setText('#nmb-shell-meta-provider', providerLabel);
    setText('#nmb-shell-meta-delivery', shellDelivery);
  }

  function providerHasAdvancedValues($form, provider) {
    var hasAdvanced = false;

    $.each(advancedConnectionFields, function (_, field) {
      if (hasValue(fieldControl($form, field))) {
        hasAdvanced = true;
        return false;
      }
    });

    if (!hasAdvanced && (provider === 's3' || provider === 's3_compatible' || provider === 'minio' || provider === 'wasabi' || provider === 'backblaze_b2') && hasValue(fieldControl($form, 'use_path_style_endpoint'))) {
      hasAdvanced = true;
    }

    return hasAdvanced;
  }

  function normalizeProviderDefaults($form, provider) {
    var $region = fieldControl($form, 'region');
    var $sessionToken = fieldControl($form, 'session_token');
    var $pathStyle = fieldControl($form, 'use_path_style_endpoint');
    var regionValue = $.trim(($region.val() || '').toLowerCase());

    if (provider === 'r2') {
      if (!$.trim($region.val() || '')) {
        $region.val('auto');
      }
      $sessionToken.val('');
      $pathStyle.prop('checked', true);
      return;
    }

    if (provider === 'minio') {
      $pathStyle.prop('checked', true);
      $sessionToken.val('');
      return;
    }

    if (provider === 'wasabi' || provider === 'backblaze_b2') {
      $pathStyle.prop('checked', false);
      $sessionToken.val('');
      return;
    }

    if (previousProvider === 'r2' && (provider === 's3' || provider === 's3_compatible') && regionValue === 'auto') {
      $region.val('');
    }
  }

  function applyProviderVisibility() {
    var $form = $('.nimbus-settings form[action="options.php"]');
    if (!$form.length) {
      return;
    }

    var $provider = $form.find(fieldSelector('provider'));
    if (!$provider.length) {
      return;
    }

    var provider = ($provider.val() || '').toLowerCase();
    var $serveFromStorageControl = fieldControl($form, 'serve_from_storage');
    var $purgeToggleControl = fieldControl($form, 'purge_cloudflare_cache_on_delete');
    var $proxyToggleControl = fieldControl($form, 'purge_proxy_cache');
    var $offHoursControl = fieldControl($form, 'off_hours_batch_enabled');
    var $customDomainControl = fieldControl($form, 'custom_domain');

    var $bucket = rowForField($form, 'bucket');
    var $r2Account = rowForField($form, 'r2_account_id');
    var $region = rowForField($form, 'region');
    var $access = rowForField($form, 'access_key');
    var $secret = rowForField($form, 'secret_key');
    var $sessionToken = rowForField($form, 'session_token');
    var $prefix = rowForField($form, 'prefix');
    var $endpoint = rowForField($form, 'endpoint');
    var $pathStyle = rowForField($form, 'use_path_style_endpoint');
    var $allowedPaths = rowForField($form, 'allowed_path_patterns');
    var $ignoredPaths = rowForField($form, 'ignored_path_patterns');
    var $serveFromStorage = rowForField($form, 'serve_from_storage');
    var $customDomain = rowForField($form, 'custom_domain');
    var $removeLocal = rowForField($form, 'remove_local_copies');
    var $purgeToggle = rowForField($form, 'purge_cloudflare_cache_on_delete');
    var $zoneId = rowForField($form, 'cloudflare_zone_id');
    var $apiToken = rowForField($form, 'cloudflare_api_token');
    var $liteSpeedPurge = rowForField($form, 'purge_litespeed_cache');
    var $wpFastestPurge = rowForField($form, 'purge_wp_fastest_cache');
    var $proxyPurgeToggle = rowForField($form, 'purge_proxy_cache');
    var $proxyPurgeMethod = rowForField($form, 'proxy_cache_purge_method');
    var $proxyPurgeUrls = rowForField($form, 'proxy_cache_purge_urls');
    var $proxyPurgeAllUrls = rowForField($form, 'proxy_cache_purge_all_urls');
    var $batchTime = rowForField($form, 'off_hours_batch_time');
    var $workerCount = rowForField($form, 'off_hours_worker_count');
    var $workerBatchSize = rowForField($form, 'off_hours_worker_batch_size');
    var $claimTtl = rowForField($form, 'off_hours_claim_ttl_seconds');

    var showS3 = provider === 's3';
    var showS3Compatible = provider === 's3_compatible' || provider === 'minio';
    var showAwsLike = showS3 || showS3Compatible || provider === 'wasabi' || provider === 'backblaze_b2';
    var showR2 = provider === 'r2';
    var showLocal = provider === 'local';
    var showStorage = !showLocal;
    var serveFromStorage = $serveFromStorageControl.is(':checked');
    var purgeEnabled = $purgeToggleControl.is(':checked');
    var proxyPurgeEnabled = $proxyToggleControl.is(':checked');
    var offHoursEnabled = $offHoursControl.is(':checked');
    var customDomainHasValue = hasValue($customDomainControl);
    var proxyPurgeHasUrls = hasValue(fieldControl($form, 'proxy_cache_purge_urls')) || hasValue(fieldControl($form, 'proxy_cache_purge_all_urls'));
    var showAdvanced = connectionAdvancedOpen || providerHasAdvancedValues($form, provider);
    var showRemoteDeliveryControls = showStorage && serveFromStorage;

    renderProviderSummary(provider);
    updateSettingsSummary($form, provider);
    updateAdvancedToggle();
    updateFieldHints($form, provider);
    $('#nmb-toggle-advanced').prop('hidden', showLocal);

    setRowVisible($bucket, showStorage);
    setRowVisible($access, showStorage);
    setRowVisible($secret, showStorage);
    setRowVisible($prefix, showStorage);
    setRowVisible($region, showAwsLike);
    setRowVisible($sessionToken, showAwsLike && showAdvanced);
    setRowVisible($endpoint, showStorage && (showS3Compatible || showAdvanced));
    setRowVisible($pathStyle, showAwsLike && showAdvanced);
    setRowVisible($allowedPaths, showStorage);
    setRowVisible($ignoredPaths, showStorage);

    setRowVisible($r2Account, showR2);
    setRowVisible($serveFromStorage, showStorage);
    setRowVisible($customDomain, showStorage && (serveFromStorage || customDomainHasValue));
    setRowVisible($removeLocal, showStorage);
    setRowVisible($purgeToggle, showRemoteDeliveryControls && (customDomainHasValue || purgeEnabled));
    setRowVisible($zoneId, showRemoteDeliveryControls && purgeEnabled);
    setRowVisible($apiToken, showRemoteDeliveryControls && purgeEnabled);
    setRowVisible($liteSpeedPurge, showRemoteDeliveryControls);
    setRowVisible($wpFastestPurge, showRemoteDeliveryControls);
    setRowVisible($proxyPurgeToggle, showRemoteDeliveryControls || proxyPurgeEnabled || proxyPurgeHasUrls);
    setRowVisible($proxyPurgeMethod, showRemoteDeliveryControls && (proxyPurgeEnabled || proxyPurgeHasUrls));
    setRowVisible($proxyPurgeUrls, showRemoteDeliveryControls && (proxyPurgeEnabled || proxyPurgeHasUrls));
    setRowVisible($proxyPurgeAllUrls, showRemoteDeliveryControls && (proxyPurgeEnabled || proxyPurgeHasUrls));
    setRowVisible($batchTime, offHoursEnabled);
    setRowVisible($workerCount, offHoursEnabled);
    setRowVisible($workerBatchSize, offHoursEnabled);
    setRowVisible($claimTtl, offHoursEnabled);

    // Hide the entire delivery/path-filter cards when local provider is active (no fields apply)
    $('.nmb-settings-section-card--media_offload_delivery').prop('hidden', showLocal);
    $('.nmb-settings-section-card--media_offload_path_filters').prop('hidden', showLocal);

    previousProvider = provider;
  }

  (function initDynamicSettingsUi() {
    var $form = $('.nimbus-settings form[action="options.php"]');
    if (!$form.length) {
      return;
    }

    previousProvider = (fieldControl($form, 'provider').val() || 's3').toLowerCase();
    connectionAdvancedOpen = providerHasAdvancedValues($form, previousProvider);
    initFieldHintDefaults($form);
    normalizeProviderDefaults($form, previousProvider);
    applyProviderVisibility();
  })();

  $(document).on('change', fieldSelector('provider'), function () {
    var $form = $('.nimbus-settings form[action="options.php"]');
    var provider = ($(this).val() || '').toLowerCase();
    normalizeProviderDefaults($form, provider);
    applyProviderVisibility();
  });

  $(document).on('change input', [
    fieldSelector('serve_from_storage'),
    fieldSelector('purge_cloudflare_cache_on_delete'),
    fieldSelector('purge_proxy_cache'),
    fieldSelector('off_hours_batch_enabled'),
    fieldSelector('off_hours_batch_time'),
    fieldSelector('bucket'),
    fieldSelector('custom_domain'),
    fieldSelector('allowed_path_patterns'),
    fieldSelector('ignored_path_patterns'),
    fieldSelector('proxy_cache_purge_urls'),
    fieldSelector('proxy_cache_purge_all_urls')
  ].join(', '), applyProviderVisibility);

  // Logs tab: toggle the collapsible logging-settings panel
  $(document).on('click', '.nmb-log-settings-toggle', function () {
    var $btn = $(this);
    var $panel = $('#nmb-log-settings-panel');
    var isOpen = $btn.attr('aria-expanded') === 'true';
    $btn.attr('aria-expanded', isOpen ? 'false' : 'true');
    $panel.prop('hidden', isOpen);
  });

  $(document).on('click', '#nmb-toggle-advanced', function (e) {
    e.preventDefault();
    connectionAdvancedOpen = !connectionAdvancedOpen;
    applyProviderVisibility();
  });

  $('#nmb-test-connection').on('click', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var $status = $('#nmb-connection-status');
    var $results = $('#mo-connection-results');
    var strings = adminConfig.strings || {};
    var actionName = (adminConfig.actions && adminConfig.actions.testConnection) || 'pt_media_offload_test_connection';

    function renderConnectionMeta(data) {
      var details = data.details || [];
      var hints = data.hints || [];

      if (!details.length && !hints.length) {
        $results.prop('hidden', true).empty();
        return;
      }

      var html = '';

      if (details.length) {
        html += '<h4>' + (strings.detailsHeading || 'Connection details') + '</h4>';
        html += '<dl class="mo-connection-detail-list">';
        $.each(details, function (_, item) {
          html += '<dt>' + $('<div/>').text(item.label || '').html() + '</dt>';
          html += '<dd>' + $('<div/>').text(item.value || '').html() + '</dd>';
        });
        html += '</dl>';
      }

      if (hints.length) {
        html += '<h4>' + (strings.hintsHeading || 'Suggested checks') + '</h4>';
        html += '<ul class="mo-connection-hints">';
        $.each(hints, function (_, hint) {
          html += '<li>' + $('<div/>').text(hint || '').html() + '</li>';
        });
        html += '</ul>';
      }

      $results.html(html).prop('hidden', false);
    }

    $btn.prop('disabled', true).html('<span class="mo-spinner"></span> ' + (strings.testing || 'Testing…'));
    $status.html('<span class="mo-spinner" style="border-left-color: var(--mo-text-main); border-color: rgba(0,0,0,0.1) rgba(0,0,0,0.1) rgba(0,0,0,0.1) var(--mo-text-main);"></span> ' + (strings.testingMessage || 'Testing connection…'));
    $results.prop('hidden', true).empty();

    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: {
        action: actionName,
        nonce: adminConfig.nonce
      },
      success: function (response) {
        if (response.success) {
          $status.html('<span class="dashicons dashicons-yes-alt status-ok"></span> ' + response.data.message);
          $status.css('color', 'green');
        } else {
          $status.html('<span class="dashicons dashicons-warning status-error"></span> ' + response.data.message);
          $status.css('color', 'red');
        }

        renderConnectionMeta(response.data || {});
      },
      error: function () {
        $status.html('<span class="dashicons dashicons-warning status-error"></span> ' + (strings.serverError || 'Server error occurred.'));
        $status.css('color', 'red');
        $results.prop('hidden', true).empty();
      },
      complete: function () {
        $btn.prop('disabled', false).text(strings.testConnection || 'Test Connection');
      }
    });
  });

  (function initOnboardingWizard() {
    var $wizard = $('#nmb-onboarding-wizard');
    if (!$wizard.length) {
      return;
    }

    var steps = ['storage', 'delivery', 'review'];
    var currentStep = parseInt($wizard.data('currentStep') || 0, 10);
    var $form = $wizard.find('form.nmb-onboarding-main');
    var $notice = $('<div/>', {
      'class': 'nmb-onboarding-inline-notice',
      hidden: true
    });

    $form.prepend($notice);

    function providerValue() {
      var $provider = $form.find('[name="provider"]');
      var $checked = $provider.filter(':checked');
      return ((($checked.length ? $checked : $provider).val() || '') + '').toLowerCase();
    }

    function fieldByName(name) {
      // When the same name exists in multiple groups (e.g. "endpoint" in r2-extra
      // and s3c-endpoint), prefer the enabled instance — the other is disabled
      // because its group is hidden.
      var $all = $form.find('[name="' + name + '"]');
      var $active = $all.filter(':not([disabled])');
      return ($active.length ? $active : $all).first();
    }

    function fieldWrapper(name) {
      var $field = fieldByName(name);
      return $field.closest('.nmb-onboarding-field, .nmb-onboarding-choice-card');
    }

    // Show or hide an entire provider group div.
    // Hides/shows the wrapper and disables/enables all inputs inside so that
    // invisible fields are excluded from form submission and validation.
    function setGroupVisible(groupName, visible) {
      var $group = $wizard.find('[data-provider-group="' + groupName + '"]');
      if (!$group.length) { return; }
      $group.prop('hidden', !visible);
      $group.find('input, select, textarea').each(function () {
        var $f = $(this);
        if (visible) {
          $f.prop('disabled', false);
          if ($f.data('nmbRequired')) { $f.prop('required', true); }
        } else {
          if ($f.prop('required')) { $f.data('nmbRequired', true); }
          $f.prop('required', false).prop('disabled', true);
        }
      });
    }

    var providerTips = {
      s3: 'You need an IAM user in AWS with S3 read/write permissions. <strong>Access Key ID</strong> and <strong>Secret Access Key</strong> are under IAM → Users → Security credentials.',
      r2: 'Create an R2 bucket in your Cloudflare dashboard. Then go to R2 → Manage API tokens → Create API token and choose <strong>Object Read &amp; Write</strong>.',
      digitalocean_spaces: 'Create a Space in your DigitalOcean control panel. Go to API → Spaces Keys to generate an Access Key and Secret Key.',
      wasabi: 'Create a bucket in the Wasabi console and go to Access Keys to generate credentials. The region code is shown in the bucket details (e.g. us-east-1, eu-central-1).',
      backblaze_b2: 'Create a bucket in the Backblaze console. Under Account → Application Keys, create a new key with read/write access to your bucket. Use the keyID as Access Key and the applicationKey as Secret Key.',
      minio: 'Enter the full base URL of your MinIO server as the endpoint (e.g. https://minio.example.com). Use your MinIO root credentials or a policy-scoped access key.',
      s3_compatible: 'Use the API base URL, bucket name, and credentials from your provider\u2019s object storage dashboard. Region requirements vary by service.',
      local: 'Local mode stores files on this server\u2019s filesystem — no cloud credentials needed. Useful for development or testing.'
    };

    function updateProviderTip(provider) {
      var $tip = $wizard.find('#nmb-provider-tip');
      var tip = providerTips[provider] || '';
      if ($tip.length) {
        $tip.html(tip).prop('hidden', !tip);
      }
    }

    function showNotice(message) {
      $notice.text(message).prop('hidden', false);
    }

    function clearNotice() {
      $notice.text('').prop('hidden', true);
    }

    function setFieldVisible(name, visible) {
      var $wrapper = fieldWrapper(name);
      var $field = fieldByName(name);

      if (!$wrapper.length || !$field.length) {
        return;
      }

      $wrapper.prop('hidden', !visible).toggleClass('is-hidden', !visible);
      $field.prop('disabled', !visible);
    }

    function applyOnboardingProviderVisibility() {
      var provider = providerValue();
      var isLocal = provider === 'local';
      var isR2 = provider === 'r2';
      var isS3Compatible = provider === 's3_compatible' || provider === 'minio';
      var isS3 = provider === 's3';
      var $region = fieldByName('region');

      if (isR2 && $.trim($region.val() || '') === '') {
        $region.val('auto');
      }
      $region.prop('readonly', isR2);
      $wizard.find('[data-hide-for="r2"]').prop('hidden', isR2);

      setGroupVisible('bucket-location', !isLocal);
      setGroupVisible('credentials', !isLocal);
      setGroupVisible('r2-extra', isR2);
      setGroupVisible('s3c-endpoint', isS3Compatible);
      setGroupVisible('session', isS3 || provider === 's3_compatible');
      setGroupVisible('prefix', !isLocal);

      updateProviderTip(provider);
      applyQuickMode();
    }

    function validateStep(step) {
      var message = validateFields(step);

      if (message && step === 'storage' && isQuickMode()) {
        return 'Connect your storage first, or enter keys by hand.';
      }

      return message;
    }

    function validateFields(step) {
      var provider = providerValue();

      if (step !== 'storage' || provider === 'local') {
        return '';
      }

      if ($.trim(fieldByName('bucket').val() || '') === '') {
        return 'Enter the storage bucket before continuing.';
      }

      if ($.trim(fieldByName('access_key').val() || '') === '') {
        return 'Enter the access key before continuing.';
      }

      if ($.trim(fieldByName('secret_key').val() || '') === '') {
        return 'Enter the secret key before continuing.';
      }

      if ((provider === 's3' || provider === 's3_compatible' || provider === 'wasabi' || provider === 'backblaze_b2') && $.trim(fieldByName('region').val() || '') === '') {
        return 'Enter the storage region before continuing.';
      }

      if ((provider === 's3_compatible' || provider === 'minio') && $.trim(fieldByName('endpoint').val() || '') === '') {
        return 'Enter the custom endpoint for your S3-compatible storage.';
      }

      if (provider === 'r2' && $.trim(fieldByName('r2_account_id').val() || '') === '') {
        return 'Enter your Cloudflare account ID before continuing.';
      }

      return '';
    }

    function focusCurrentStep() {
      var $current = $wizard.find('.nmb-onboarding-step[data-step="' + steps[currentStep] + '"]');
      var $focusable = $current.find('input, select, textarea, button').filter(':visible').first();

      if ($focusable.length) {
        $focusable.trigger('focus');
      }
    }

    function renderSteps() {
      $.each(steps, function (index, step) {
        var isActive = index === currentStep;
        var isComplete = index < currentStep;
        var $section = $wizard.find('.nmb-onboarding-step[data-step="' + step + '"]');
        var $item = $wizard.find('.nmb-onboarding-step-item[data-step-item="' + step + '"]');

        $section.prop('hidden', !isActive);
        $item.toggleClass('is-active', isActive);
        $item.toggleClass('is-complete', isComplete);
      });
    }

    function goToStep(index) {
      if (index < 0 || index >= steps.length) {
        return;
      }

      currentStep = index;
      clearNotice();
      applyOnboardingProviderVisibility();
      renderSteps();
      focusCurrentStep();

      // Persist progress so returning to the page resumes at this step.
      var cfg = adminConfig.onboarding || null;
      if (cfg && cfg.saveStepAction && cfg.saveStepNonce && adminConfig.ajaxUrl) {
        $.post(adminConfig.ajaxUrl, {
          action: cfg.saveStepAction,
          nonce: cfg.saveStepNonce,
          step: steps[currentStep],
        });
      }
    }

    $wizard.on('click', '[data-onboarding-next]', function () {
      var message = validateStep(steps[currentStep]);

      if (message) {
        showNotice(message);
        return;
      }

      goToStep(currentStep + 1);
    });

    $wizard.on('click', '.nmb-onboarding-step-item', function () {
      var step = $(this).data('stepItem');
      var targetIndex = steps.indexOf(step);

      if (targetIndex === -1 || targetIndex === currentStep) {
        return;
      }

      // Completed steps are locked — clicking them does nothing.
      if (targetIndex < currentStep) {
        return;
      }

      var message = validateStep(steps[currentStep]);
      if (message) {
        showNotice(message);
        return;
      }

      goToStep(targetIndex);
    });

    $form.on('change', '[name="provider"]', function () {
      $wizard.find('#nmb-wizard-status').text('').removeClass('is-ok is-error');
      applyOnboardingProviderVisibility();
    });

    // Test the storage fields as typed, before anything is saved.
    $wizard.on('click', '[data-wizard-test]', function () {
      var $btn = $(this);
      var $status = $wizard.find('#nmb-wizard-status');
      var strings = adminConfig.strings || {};
      var message = validateStep('storage');

      if (message) {
        showNotice(message);
        return;
      }

      clearNotice();
      var data = {
        action: (adminConfig.actions && adminConfig.actions.testConnection) || 'pt_media_offload_test_connection',
        nonce: adminConfig.nonce
      };
      $form.find('[data-step="storage"]').find('input:not([disabled])').each(function () {
        if (this.name && (this.type !== 'radio' || this.checked)) {
          data['values[' + this.name + ']'] = this.value;
        }
      });
      if ($form.find('[name="use_path_style_endpoint"]').is(':checked')) {
        data['values[use_path_style_endpoint]'] = '1';
      }

      $btn.prop('disabled', true);
      $status.removeClass('is-ok is-error').text(strings.testingMessage || 'Testing connection…');

      $.post(ajaxurl, data)
        .done(function (response) {
          var ok = !!(response && response.success);
          var bucket = $.trim(fieldByName('bucket').val() || '');
          var text = ok
            ? (bucket ? 'Connected to ' + bucket : (response.data && response.data.message) || 'Connected')
            : ((response && response.data && response.data.message) || strings.serverError || 'Connection failed.');
          $status.addClass(ok ? 'is-ok' : 'is-error').text(text);
        })
        .fail(function (xhr) {
          var data = xhr.responseJSON && xhr.responseJSON.data;
          $status.addClass('is-error').text((data && data.message) || strings.serverError || 'Server error occurred.');
        })
        .always(function () {
          $btn.prop('disabled', false);
        });
    });

    // Quick connect: one pasted token or key sets up the bucket and fills the
    // storage fields. The manual fields stay in the form and are what gets saved.
    var $storage = $form.find('[data-step="storage"]');
    var $quickToggle = $wizard.find('[data-quick-toggle]');
    var tickSvg = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function quickPanel(provider) {
      return $wizard.find('[data-quick-for="' + provider + '"]');
    }

    function isQuickMode() {
      return $storage.attr('data-quick-mode') === 'quick' && quickPanel(providerValue()).length > 0;
    }

    function applyQuickMode() {
      var provider = providerValue();
      var quick = isQuickMode();

      $wizard.find('[data-quick-for]').each(function () {
        $(this).prop('hidden', !quick || $(this).attr('data-quick-for') !== provider);
      });
      $storage.find('[data-manual-fields], [data-manual-only]').prop('hidden', quick);
      $quickToggle
        .prop('hidden', steps[currentStep] !== 'storage' || !quickPanel(provider).length)
        .text($quickToggle.attr(quick ? 'data-label-manual' : 'data-label-quick'));
    }

    function setQuickMode(quick) {
      $storage.attr('data-quick-mode', quick ? 'quick' : 'manual');
      applyQuickMode();
    }

    function fillCommand($panel) {
      var region = $panel.find('select[data-quick-input="region"]').val() || '';
      $panel.find('[data-quick-command]').each(function () {
        $(this).text(($(this).attr('data-template') || '').split('__REGION__').join(region));
      });
    }

    function fillStorageFields(result) {
      var values = result.values || {};

      $.each(values, function (key, value) {
        if (key === 'provider' || key === 'use_path_style_endpoint') { return; }
        var $field = fieldByName(key);
        if ($field.length) {
          $field.val(value);
        }
      });

      fieldByName('use_path_style_endpoint').prop('checked', !!values.use_path_style_endpoint);
      fieldByName('enabled').prop('checked', true);
      // Serving straight from a bucket visitors cannot read would break images.
      fieldByName('serve_from_storage').prop('checked', !!result.public);
    }

    function renderQuickResult($panel, result) {
      var values = result.values || {};
      var buckets = result.buckets || [];
      var accounts = result.accounts || [];
      var $checks = $panel.find('[data-quick-checks]').empty();
      var $bucketChoice = $panel.find('[data-quick-buckets]');
      var $accountChoice = $panel.find('[data-quick-accounts]');

      $.each(result.steps || [], function (_index, step) {
        var $item = $('<li/>').append($('<span class="nmb-quick__tick"/>').html(tickSvg));
        $item.append($('<span class="nmb-quick__label"/>').text(step.label));
        if (step.detail) {
          $item.append($('<code class="nmb-quick__detail"/>').text(step.detail));
        }
        if (values.bucket && step.detail === values.bucket && buckets.length > 1 && $bucketChoice.prop('hidden')) {
          $item.append($('<button type="button" class="nmb-link nmb-quick__pick" data-quick-pick-bucket/>').text('Use an existing bucket'));
        }
        $checks.append($item);
      });

      var $bucketSelect = $bucketChoice.find('select').empty();
      $.each(buckets, function (_index, name) {
        $bucketSelect.append($('<option/>').val(name).text(name));
      });
      $bucketSelect.val(values.bucket || '');

      var $accountSelect = $accountChoice.find('select');
      if (accounts.length) {
        $accountSelect.empty().append($('<option value=""/>').text('Choose an account'));
        $.each(accounts, function (_index, account) {
          $accountSelect.append($('<option/>').val(account.id).text(account.name));
        });
      }
      $accountChoice.prop('hidden', !accounts.length && !$accountSelect.val());

      $panel.find('[data-quick-note]').text(result.note || '').prop('hidden', !result.note);
      $panel.find('[data-quick-result]').prop('hidden', false);
    }

    function runQuickConnect($panel) {
      var cfg = adminConfig.onboarding || {};
      var provider = $panel.attr('data-quick-for');
      var $button = $panel.find('[data-quick-connect]');
      var $status = $wizard.find('#nmb-wizard-status');
      var strings = adminConfig.strings || {};
      var data = {
        action: cfg.quickConnectAction,
        nonce: cfg.quickConnectNonce,
        provider: provider
      };

      if ($button.prop('disabled')) { return; }

      $panel.find('[data-quick-input]').each(function () {
        var $input = $(this);
        if ($input.closest('[hidden]').length || $.trim($input.val() || '') === '') { return; }
        data['input[' + $input.attr('data-quick-input') + ']'] = $input.val();
      });

      clearNotice();
      $panel.find('[data-quick-input], [data-quick-connect]').prop('disabled', true);
      $status.removeClass('is-ok is-error').text('Setting up your storage. This can take up to a minute…');

      $.post(adminConfig.ajaxUrl || ajaxurl, data)
        .done(function (response) {
          if (!response || !response.success) {
            $status.addClass('is-error').text((response && response.data && response.data.message) || strings.serverError || 'Could not connect.');
            return;
          }

          var result = response.data || {};
          renderQuickResult($panel, result);

          if (result.values && result.values.bucket) {
            fillStorageFields(result);
            $panel.find('.nmb-quick__step').addClass('is-done');
            $status.addClass('is-ok').text('Connected to ' + result.values.bucket);
          } else {
            $status.text('');
          }
        })
        .fail(function (xhr) {
          var body = xhr.responseJSON && xhr.responseJSON.data;
          $status.addClass('is-error').text((body && body.message) || strings.serverError || 'Server error occurred.');
        })
        .always(function () {
          $panel.find('[data-quick-input], [data-quick-connect]').prop('disabled', false);
        });
    }

    $wizard.on('click', '[data-quick-connect]', function () {
      runQuickConnect($(this).closest('[data-quick-for]'));
    });

    $wizard.on('keydown', 'input[data-quick-input]', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        runQuickConnect($(this).closest('[data-quick-for]'));
      }
    });

    $wizard.on('change', '[data-quick-buckets] select, [data-quick-accounts] select', function () {
      if ($(this).val()) {
        runQuickConnect($(this).closest('[data-quick-for]'));
      }
    });

    $wizard.on('click', '[data-quick-pick-bucket]', function () {
      var $panel = $(this).closest('[data-quick-for]');
      $(this).remove();
      $panel.find('[data-quick-buckets]').prop('hidden', false).find('select').trigger('focus');
    });

    $wizard.on('click', '.nmb-quick__open', function () {
      $(this).closest('.nmb-quick__step').addClass('is-done');
    });

    $wizard.on('change', 'select[data-quick-input="region"]', function () {
      fillCommand($(this).closest('[data-quick-for]'));
    });

    $wizard.on('click', '[data-quick-copy]', function () {
      var $button = $(this);
      var text = $button.closest('.nmb-quick__step').find('[data-quick-command]').text();
      var done = function () {
        $button.text('Copied');
        setTimeout(function () { $button.text('Copy command'); }, 2000);
      };

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
        return;
      }

      var $temp = $('<textarea class="screen-reader-text"/>').val(text).appendTo('body');
      $temp[0].select();
      document.execCommand('copy');
      $temp.remove();
      done();
    });

    $quickToggle.on('click', function () {
      clearNotice();
      setQuickMode(!isQuickMode());
    });

    $wizard.find('[data-quick-for]').each(function () {
      fillCommand($(this));
    });

    $form.on('submit', function (event) {
      var blockingSteps = ['storage'];

      applyOnboardingProviderVisibility();

      for (var i = 0; i < blockingSteps.length; i += 1) {
        var message = validateStep(blockingSteps[i]);
        if (message) {
          event.preventDefault();
          goToStep(steps.indexOf(blockingSteps[i]));
          showNotice(message);
          return;
        }
      }
    });

    $wizard.on('change', '[data-config-import]', function () {
      var file = this.files && this.files[0];
      if (!file) { return; }

      var $input = $(this);
      var $hint = $input.siblings('.nmb-config-import-hint');

      var reader = new FileReader();
      reader.onload = function (e) {
        try {
          var json = JSON.parse(e.target.result);

          // Support both wrapped format { settings: {…}, credentials: {…} }
          // and legacy flat format {…}
          var settings = (json && json.settings) ? json.settings : json;
          var credentials = (json && json.credentials) ? json.credentials : {};
          var merged = $.extend({}, settings, credentials);

          // Apply the provider first so group visibility is correct before we
          // fill the other inputs.
          var newProvider = merged.provider;
          if (newProvider) {
            $form.find('[name="provider"]').filter(function () {
              return this.value === newProvider;
            }).prop('checked', true);
          }

          applyOnboardingProviderVisibility();

          // Fill every field present in the export.
          $.each(merged, function (key, value) {
            if (key === 'provider') { return; } // already handled above
            var $field = fieldByName(key);
            if (!$field.length) { return; }
            if ($field.is(':checkbox')) {
              $field.prop('checked', !!value);
            } else {
              $field.val(value);
            }
          });

          // If R2 was imported and region is blank, fill "auto".
          if (providerValue() === 'r2') {
            var $region = fieldByName('region');
            if ($region.length && $.trim($region.val() || '') === '') {
              $region.val('auto');
            }
          }

          setQuickMode(false);
          $hint.text('Configuration loaded — review the fields below, then continue.').css('color', '#00a32a');
        } catch (_err) {
          $hint.text('Could not read file. Make sure it is a valid Nimbus JSON export.').css('color', '#d63638');
        }

        // Reset the file input so re-selecting the same file fires change again.
        $input.val('');
      };

      reader.readAsText(file);
    });

    if (Number.isNaN(currentStep) || currentStep < 0 || currentStep >= steps.length) {
      currentStep = 0;
    }

    goToStep(currentStep);
  })();

  (function initBulkJobsUi() {
    var $app = $('#nmb-bulk-job-app');
    if (!$app.length || !adminConfig.restBase || !adminConfig.restNonce) {
      return;
    }

    var strings = adminConfig.strings || {};
    var $badge = $('#nmb-job-status-badge');
    var $label = $('#nmb-job-label');
    var $message = $('#nmb-job-message');
    var $bar = $('#nmb-job-progress-bar');
    var $progressText = $('#nmb-job-progress-text');
    var $processed = $('#nmb-job-processed');
    var $total = $('#nmb-job-total');
    var $success = $('#nmb-job-success');
    var $failed = $('#nmb-job-failed');
    var $skipped = $('#nmb-job-skipped');
    var refreshFailures = 0;
    var refreshTimer = null;

    function badgeClassFor(status) {
      if (status === 'completed' || status === 'offloaded') {
        return 'is-success';
      }
      if (status === 'paused' || status === 'pending' || status === 'processing' || status === 'completed_with_errors') {
        return 'is-warning';
      }
      if (status === 'failed') {
        return 'is-error';
      }
      return '';
    }

    function currentJobFromState(state) {
      if (state.current && state.current.id) {
        return state.current;
      }

      return state.last || {};
    }

    function renderJobState(state) {
      var job = currentJobFromState(state || {});
      var status = job.status || 'idle';
      var statusLabel = job.status_label || 'Idle';
      var percent = parseInt(job.percent_complete || 0, 10);

      $badge.attr('class', 'pt-mo-inline-badge ' + badgeClassFor(status)).text(statusLabel);
      $label.text(job.type_label || 'No active job');
      $message.text(job.last_message || 'Choose a bulk task to begin.');
      $bar.css('width', percent + '%');
      $progressText.text(percent + '% complete');
      $processed.text(job.processed_items || 0);
      $total.text(job.total_items || 0);
      $success.text(job.success_count || 0);
      $failed.text(job.failed_count || 0);
      $skipped.text(job.skipped_count || 0);
    }

    function request(path, options) {
      options = options || {};
      options.method = options.method || 'GET';
      options.headers = $.extend({}, options.headers || {}, {
        'X-WP-Nonce': adminConfig.restNonce
      });

      if (options.body && !options.headers['Content-Type']) {
        options.headers['Content-Type'] = 'application/json';
      }

      return fetch(adminConfig.restBase + path, options).then(function (response) {
        return response.text().then(function (text) {
          var data = null;

          if (text) {
            try {
              data = JSON.parse(text);
            } catch (error) {
              data = null;
            }
          }

          if (!response.ok) {
            var message = (data && data.message) || response.statusText || 'Request failed';
            throw new Error(message);
          }

          return data || {};
        });
      });
    }

    function refresh() {
      request('/jobs').then(function (state) {
        refreshFailures = 0;
        renderJobState(state);
      }).catch(function () {
        refreshFailures += 1;

        if (refreshFailures >= 3 && refreshTimer) {
          window.clearInterval(refreshTimer);
          refreshTimer = null;
          $badge.attr('class', 'pt-mo-inline-badge is-warning').text('Status unavailable');
          $message.text('Live queue updates are temporarily unavailable. Reload after fixing REST access to resume polling.');
        }
      });
    }

    $app.on('click', '[data-job-action]', function (event) {
      event.preventDefault();

      var $button = $(this);
      var action = $button.data('jobAction');
      var type = $button.data('jobType');
      var path = '/jobs';
      var options = { method: 'POST' };
      var fallbackError = strings.jobStartError || 'Unable to start the requested bulk job.';

      if (action === 'start') {
        path = '/jobs/start';
        options.body = JSON.stringify({ type: type });
      } else if (action === 'pause') {
        path = '/jobs/pause';
        fallbackError = strings.jobPauseError || 'Unable to pause the current bulk job.';
      } else if (action === 'resume') {
        path = '/jobs/resume';
        fallbackError = strings.jobResumeError || 'Unable to resume the current bulk job.';
      } else if (action === 'retry-failed') {
        path = '/jobs/retry-failed';
        fallbackError = strings.jobRetryError || 'Unable to retry failed items right now.';
      }

      $button.prop('disabled', true);
      request(path, options)
        .then(function () {
          refresh();
        })
        .catch(function (error) {
          window.alert(error.message || fallbackError);
        })
        .finally(function () {
          $button.prop('disabled', false);
        });
    });

    refresh();
    refreshTimer = window.setInterval(refresh, 5000);
  })();

  // ── Path-pattern pill widgets ──────────────────────────────────────────────
  (function initPathPillWidgets() {
    var roots = (adminConfig.assetRoots || []).map(function (r) {
      return typeof r === 'object' ? r.root : String(r);
    }).filter(Boolean);

    // Build a wildcard suggestion from a root segment.
    function suggestionFor(root) {
      if (root === '{year}') {
        return '{year}/**';
      }
      return root + '/**';
    }

    $('.nmb-path-pill-widget').each(function () {
      var $widget     = $(this);
      var $list       = $widget.find('.nmb-path-pill-list');
      var $textarea   = $widget.find('.nmb-path-pill-hidden-textarea');
      var $input      = $widget.find('.nmb-path-pill-input');
      var $addBtn     = $widget.find('.nmb-path-pill-add-btn');
      var $suggestBox = $widget.find('.nmb-path-pill-suggestions');

      // --- helpers ---

      function currentPatterns() {
        return $.grep(
          $.map($list.find('.nmb-path-pill--active'), function (el) {
            return $.trim($(el).data('value') || '');
          }),
          function (v) { return v !== ''; }
        );
      }

      function syncTextarea() {
        $textarea.val(currentPatterns().join('\n'));
      }

      function hasPill(value) {
        return currentPatterns().indexOf($.trim(value)) !== -1;
      }

      function addPill(value) {
        value = $.trim(value);
        if (value === '' || hasPill(value)) {
          return false;
        }

        var $pill = $('<span class="nmb-path-pill nmb-path-pill--active" role="listitem"></span>')
          .data('value', value)
          .attr('data-value', value);

        $('<span class="nmb-path-pill-label"></span>').text(value).appendTo($pill);
        $('<button type="button" class="nmb-path-pill-remove" aria-label="Remove ' + value + '">×</button>').appendTo($pill);

        $list.append($pill);
        syncTextarea();

        // grey-out matching suggestion chip if present
        $suggestBox.find('.nmb-path-suggestion-chip').each(function () {
          if ($(this).data('pattern') === value) {
            $(this).addClass('nmb-path-suggestion-chip--used').prop('disabled', true);
          }
        });

        return true;
      }

      function removePill($pill) {
        var value = $.trim($pill.data('value') || '');
        $pill.remove();
        syncTextarea();

        // re-enable suggestion chip
        $suggestBox.find('.nmb-path-suggestion-chip').each(function () {
          if ($(this).data('pattern') === value) {
            $(this).removeClass('nmb-path-suggestion-chip--used').prop('disabled', false);
          }
        });
      }

      // --- suggestions ---

      if (roots.length) {
        var $label = $('<p class="nmb-path-suggestions-label description"></p>').text('Discovered folders:');
        $suggestBox.append($label);

        $.each(roots, function (_, root) {
          var pattern = suggestionFor(root);
          var $chip = $('<button type="button" class="nmb-path-suggestion-chip"></button>')
            .text(pattern)
            .data('pattern', pattern);

          if (hasPill(pattern)) {
            $chip.addClass('nmb-path-suggestion-chip--used').prop('disabled', true);
          }

          $chip.on('click', function () {
            addPill(pattern);
            $input.focus();
          });

          $suggestBox.append($chip);
        });

        $suggestBox.prop('hidden', false);
      }

      // --- remove via click / keyboard ---

      $list.on('click', '.nmb-path-pill-remove', function () {
        removePill($(this).closest('.nmb-path-pill'));
      });

      $list.on('keydown', '.nmb-path-pill-remove', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          removePill($(this).closest('.nmb-path-pill'));
          $input.focus();
        }
      });

      // --- text input: Enter or comma adds pill ---

      $input.on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',') {
          e.preventDefault();
          var val = $.trim($input.val().replace(/,/g, ''));
          if (val && addPill(val)) {
            $input.val('');
          }
        }

        // Backspace on empty input removes last pill
        if (e.key === 'Backspace' && $.trim($input.val()) === '') {
          var $pills = $list.find('.nmb-path-pill--active');
          if ($pills.length) {
            removePill($pills.last());
          }
        }
      });

      $addBtn.on('click', function () {
        var val = $.trim($input.val().replace(/,/g, ''));
        if (val && addPill(val)) {
          $input.val('');
        }
        $input.focus();
      });
    });
  })();
});
