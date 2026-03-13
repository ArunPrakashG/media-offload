jQuery(document).ready(function ($) {
  var optionKey = 'pt_media_offload_settings';
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

  function updateAdvancedToggle() {
    var $toggle = $('#pt-mo-toggle-advanced');
    if (!$toggle.length) {
      return;
    }

    var strings = (window.pt_media_offload_vars && pt_media_offload_vars.strings) || {};
    var label = connectionAdvancedOpen ? strings.hideAdvanced : strings.showAdvanced;
    $toggle.text(label || 'Show advanced connection fields');
    $toggle.attr('aria-expanded', connectionAdvancedOpen ? 'true' : 'false');
  }

  function renderProviderSummary(provider) {
    var providers = (window.pt_media_offload_vars && pt_media_offload_vars.providers) || {};
    var config = providers[provider] || providers.s3 || null;
    if (!config) {
      return;
    }

    var $title = $('#pt-mo-provider-summary-title');
    var $copy = $('#pt-mo-provider-summary-copy');
    var $chips = $('#pt-mo-provider-required-fields');

    $title.text(config.title || 'Storage provider');
    $copy.text(config.summary || '');
    $chips.empty();

    $.each(config.required || [], function (_, label) {
      $('<span/>', {
        'class': 'pt-mo-chip',
        text: label
      }).appendTo($chips);
    });
  }

  function providerHasAdvancedValues($form, provider) {
    var hasAdvanced = false;

    $.each(advancedConnectionFields, function (_, field) {
      if (hasValue(fieldControl($form, field))) {
        hasAdvanced = true;
        return false;
      }
    });

    if (!hasAdvanced && provider === 's3' && hasValue(fieldControl($form, 'use_path_style_endpoint'))) {
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

    if (previousProvider === 'r2' && provider === 's3' && regionValue === 'auto') {
      $region.val('');
    }
  }

  function applyProviderVisibility() {
    var $form = $('.pt-media-offload-settings form[action="options.php"]');
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
    var $batchTime = rowForField($form, 'off_hours_batch_time');
    var $workerCount = rowForField($form, 'off_hours_worker_count');
    var $workerBatchSize = rowForField($form, 'off_hours_worker_batch_size');
    var $claimTtl = rowForField($form, 'off_hours_claim_ttl_seconds');

    var showS3 = provider === 's3';
    var showR2 = provider === 'r2';
    var showLocal = provider === 'local';
    var showStorage = !showLocal;
    var serveFromStorage = $serveFromStorageControl.is(':checked');
    var purgeEnabled = $purgeToggleControl.is(':checked');
    var offHoursEnabled = $offHoursControl.is(':checked');
    var customDomainHasValue = hasValue($customDomainControl);
    var showAdvanced = connectionAdvancedOpen || providerHasAdvancedValues($form, provider);

    renderProviderSummary(provider);
    updateAdvancedToggle();

    setRowVisible($bucket, showStorage);
    setRowVisible($access, showStorage);
    setRowVisible($secret, showStorage);
    setRowVisible($prefix, showStorage);
    setRowVisible($region, showS3);
    setRowVisible($sessionToken, showS3 && showAdvanced);
    setRowVisible($endpoint, showStorage && showAdvanced);
    setRowVisible($pathStyle, showS3 && showAdvanced);
    setRowVisible($allowedPaths, showStorage);
    setRowVisible($ignoredPaths, showStorage);

    setRowVisible($r2Account, showR2);
    setRowVisible($serveFromStorage, showStorage);
    setRowVisible($customDomain, showStorage && (serveFromStorage || customDomainHasValue));
    setRowVisible($removeLocal, showStorage);
    setRowVisible($purgeToggle, showStorage && serveFromStorage && (customDomainHasValue || purgeEnabled));
    setRowVisible($zoneId, showStorage && serveFromStorage && purgeEnabled);
    setRowVisible($apiToken, showStorage && serveFromStorage && purgeEnabled);
    setRowVisible($batchTime, offHoursEnabled);
    setRowVisible($workerCount, offHoursEnabled);
    setRowVisible($workerBatchSize, offHoursEnabled);
    setRowVisible($claimTtl, offHoursEnabled);

    previousProvider = provider;
  }

  (function initDynamicSettingsUi() {
    var $form = $('.pt-media-offload-settings form[action="options.php"]');
    if (!$form.length) {
      return;
    }

    previousProvider = (fieldControl($form, 'provider').val() || 's3').toLowerCase();
    connectionAdvancedOpen = providerHasAdvancedValues($form, previousProvider);
    normalizeProviderDefaults($form, previousProvider);
    applyProviderVisibility();
  })();

  $(document).on('change', fieldSelector('provider'), function () {
    var $form = $('.pt-media-offload-settings form[action="options.php"]');
    var provider = ($(this).val() || '').toLowerCase();
    normalizeProviderDefaults($form, provider);
    applyProviderVisibility();
  });

  $(document).on('change input', [
    fieldSelector('serve_from_storage'),
    fieldSelector('purge_cloudflare_cache_on_delete'),
    fieldSelector('off_hours_batch_enabled'),
    fieldSelector('custom_domain')
  ].join(', '), applyProviderVisibility);

  $(document).on('click', '#pt-mo-toggle-advanced', function (e) {
    e.preventDefault();
    connectionAdvancedOpen = !connectionAdvancedOpen;
    applyProviderVisibility();
  });

  $('#pt-mo-test-connection').on('click', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var $status = $('#pt-mo-connection-status');

    $btn.prop('disabled', true).text('Testing...');
    $status.html('<span class="dashicons dashicons-update spin"></span> Testing connection...');

    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: {
        action: 'pt_media_offload_test_connection',
        nonce: pt_media_offload_vars.nonce
      },
      success: function (response) {
        if (response.success) {
          $status.html('<span class="dashicons dashicons-yes-alt status-ok"></span> ' + response.data.message);
          $status.css('color', 'green');
        } else {
          $status.html('<span class="dashicons dashicons-warning status-error"></span> ' + response.data.message);
          $status.css('color', 'red');
        }
      },
      error: function () {
        $status.html('<span class="dashicons dashicons-warning status-error"></span> Server error occurred.');
        $status.css('color', 'red');
      },
      complete: function () {
        $btn.prop('disabled', false).text('Test Connection');
      }
    });
  });
});
