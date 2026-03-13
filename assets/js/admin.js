jQuery(document).ready(function ($) {
  var adminConfig = window.mediaOffloadAdmin || window.pt_media_offload_vars || {};
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
    var $form = $('.media-offload-settings form[action="options.php"]');
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
    var showS3Compatible = provider === 's3_compatible';
    var showAwsLike = showS3 || showS3Compatible;
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
    var $form = $('.media-offload-settings form[action="options.php"]');
    if (!$form.length) {
      return;
    }

    previousProvider = (fieldControl($form, 'provider').val() || 's3').toLowerCase();
    connectionAdvancedOpen = providerHasAdvancedValues($form, previousProvider);
    normalizeProviderDefaults($form, previousProvider);
    applyProviderVisibility();
  })();

  $(document).on('change', fieldSelector('provider'), function () {
    var $form = $('.media-offload-settings form[action="options.php"]');
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

  (function initBulkJobsUi() {
    var $app = $('#pt-mo-bulk-job-app');
    if (!$app.length || !adminConfig.restBase || !adminConfig.restNonce) {
      return;
    }

    var strings = adminConfig.strings || {};
    var $badge = $('#pt-mo-job-status-badge');
    var $label = $('#pt-mo-job-label');
    var $message = $('#pt-mo-job-message');
    var $bar = $('#pt-mo-job-progress-bar');
    var $progressText = $('#pt-mo-job-progress-text');
    var $processed = $('#pt-mo-job-processed');
    var $total = $('#pt-mo-job-total');
    var $success = $('#pt-mo-job-success');
    var $failed = $('#pt-mo-job-failed');
    var $skipped = $('#pt-mo-job-skipped');

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
        return response.json().then(function (data) {
          if (!response.ok) {
            var message = (data && data.message) || 'Request failed';
            throw new Error(message);
          }

          return data;
        });
      });
    }

    function refresh() {
      request('/jobs').then(renderJobState).catch(function () {
        // Keep current UI state if polling fails.
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
    window.setInterval(refresh, 5000);
  })();
});
