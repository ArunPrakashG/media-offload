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

    if (!hasAdvanced && (provider === 's3' || provider === 's3_compatible') && hasValue(fieldControl($form, 'use_path_style_endpoint'))) {
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
    var showS3Compatible = provider === 's3_compatible';
    var showAwsLike = showS3 || showS3Compatible;
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

    var steps = ['account', 'storage', 'delivery', 'review'];
    var currentStep = parseInt($wizard.data('currentStep') || 0, 10);
    var $form = $wizard.find('form.nmb-onboarding-main');
    var $notice = $('<div/>', {
      'class': 'nmb-onboarding-inline-notice',
      hidden: true
    });

    $form.prepend($notice);

    function providerValue() {
      return (($form.find('[name="provider"]').val() || '') + '').toLowerCase();
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
      s3_compatible: 'Use the API base URL, bucket name, and credentials from your provider’s object storage dashboard. Region requirements vary by service.',
      local: 'Local mode stores files on this server’s filesystem — no cloud credentials needed. Useful for development or testing.'
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
      var isS3Compatible = provider === 's3_compatible';
      var isS3 = provider === 's3';
      var $region = fieldByName('region');

      if (isR2 && $.trim($region.val() || '') === '') {
        $region.val('auto');
      }
      $region.prop('readonly', isR2);

      setGroupVisible('bucket-location', !isLocal);
      setGroupVisible('credentials', !isLocal);
      setGroupVisible('r2-extra', isR2);
      setGroupVisible('s3c-endpoint', isS3Compatible);
      setGroupVisible('session', isS3 || isS3Compatible);

      updateProviderTip(provider);
    }

    function validateStep(step) {
      var provider = providerValue();

      if (step === 'account' && $.trim(fieldByName('license_key').val() || '') === '') {
        return 'Enter a license key before continuing.';
      }

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

      if ((provider === 's3' || provider === 's3_compatible') && $.trim(fieldByName('region').val() || '') === '') {
        return 'Enter the storage region before continuing.';
      }

      if (provider === 's3_compatible' && $.trim(fieldByName('endpoint').val() || '') === '') {
        return 'Enter the custom endpoint for your S3-compatible storage.';
      }

      if (provider === 'r2') {
        var hasAccountId = $.trim(fieldByName('r2_account_id').val() || '') !== '';
        var hasEndpoint = $.trim(fieldByName('endpoint').val() || '') !== '';

        if (!hasAccountId && !hasEndpoint) {
          return 'Enter either the R2 account ID or a custom R2 endpoint before continuing.';
        }
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

    $wizard.on('click', '[data-free-tier]', function () {
      var $btn = $(this);

      if ($btn.prop('disabled')) {
        return;
      }

      $btn.prop('disabled', true)
        .html('<span class="nmb-spinner"></span> Registering&hellip;');

      $('#nmb-free-tier-nonce').val($btn.data('nonce'));
      $('#nmb-free-tier-action').val($btn.data('action'));
      $('#nmb-free-tier-form').attr('action', $btn.data('url')).get(0).submit();
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
      applyOnboardingProviderVisibility();
    });

    $form.on('submit', function (event) {
      var blockingSteps = ['account', 'storage'];

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
});
