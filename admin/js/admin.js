/**
 * Stellar Meta — Admin JS
 * Handles tab navigation, live data polling, and UI interactions.
 */
(function ($, config) {
  'use strict';

  if (!config) return;

  /* ── Tab system ────────────────────────────────────────── */
  $(document).on('click', '.sm-tab', function () {
    var target = $(this).data('tab');
    $(this).closest('.sm-tabs').find('.sm-tab').removeClass('active');
    $(this).addClass('active');
    $(this).closest('.sm-card,.sm-content').find('.sm-tab-panel').removeClass('active');
    $('#' + target).addClass('active');
  });

  /* ── Auto-refresh event stream on dashboard ────────────── */
  if ($('#sm-event-stream').length) {
    refreshEventStream();
    setInterval(refreshEventStream, 30000); // refresh every 30s
  }

  function refreshEventStream() {
    $.post(config.ajaxUrl, {
      action: 'stellar_meta_get_events',
      nonce:  config.nonce,
    }, function (res) {
      if (!res.success || !res.data || !res.data.length) return;
      var html = '';
      res.data.slice(0, 10).forEach(function (e) {
        var statusClass = {
          delivered: 'sm-status-ok',
          failed:    'sm-status-fail',
          retrying:  'sm-status-retry',
          pending:   'sm-status-pending',
          processing:'sm-status-pending',
        }[e.status] || 'sm-status-pending';

        var dotColor = {
          delivered: 'var(--sm-green)',
          failed:    'var(--sm-red)',
          retrying:  'var(--sm-amber)',
        }[e.status] || 'var(--sm-blue)';

        html += '<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--sm-raised);border-radius:8px;border:1px solid var(--sm-border);margin-bottom:4px">';
        html += '<div style="width:7px;height:7px;border-radius:50%;background:' + dotColor + ';flex-shrink:0"></div>';
        html += '<div style="flex:1;font-size:12px;font-family:\'DM Mono\',monospace">' + escHtml(e.event_name) + '</div>';
        html += '<span class="sm-status ' + statusClass + '">' + escHtml(e.status) + '</span>';
        html += '<div style="font-size:11px;color:var(--sm-muted);font-family:\'DM Mono\',monospace;min-width:52px;text-align:right">' + (e.latency_ms ? e.latency_ms + 'ms' : '—') + '</div>';
        html += '</div>';
      });
      $('#sm-event-stream').html(html);
    });
  }

  /* ── Notice auto-dismiss ───────────────────────────────── */
  $(document).on('click', '.sm-notice', function () {
    $(this).fadeOut(200);
  });

  setTimeout(function () {
    $('.sm-notice-success').fadeOut(4000);
  }, 3000);

  /* ── Copy-to-clipboard helper ─────────────────────────── */
  $(document).on('click', '[data-copy]', function () {
    var text = $(this).data('copy') || $($(this).data('target')).text();
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function () {});
    }
    var orig = $(this).text();
    $(this).text('Copied!');
    setTimeout(function () { $(this).text(orig); }.bind(this), 1500);
  });

  /* ── Progress bar animation on load ───────────────────── */
  $('.sm-prog-fill').each(function () {
    var $el    = $(this);
    var target = $el[0].style.width;
    $el.css('width', '0');
    setTimeout(function () { $el.css('width', target); }, 300);
  });

  /* ── Funnel bar animation on load ──────────────────────── */
  $('.sm-funnel-bar').each(function () {
    var $el    = $(this);
    var target = $el[0].style.width;
    $el.css('width', '0');
    setTimeout(function () { $el.css({ width: target, transition: 'width 1.2s cubic-bezier(.4,0,.2,1)' }); }, 400);
  });

  /* ── Metric counter animation ─────────────────────────── */
  $('.sm-metric-value').each(function () {
    var $el = $(this);
    var text = $el.text().trim();
    var num  = parseFloat(text.replace(/[^0-9.]/g, ''));
    if (!isNaN(num) && num > 10) {
      var suffix   = text.replace(/[\d,.]/g, '').trim();
      var duration = 800;
      var start    = 0;
      var step     = Math.ceil(num / (duration / 16));
      var prefix   = text.startsWith('$') ? '$' : '';
      var timer    = setInterval(function () {
        start += step;
        if (start >= num) { start = num; clearInterval(timer); }
        $el.text(prefix + Math.floor(start).toLocaleString() + suffix);
      }, 16);
    }
  });

  /* ── Confirm-before-delete utility ───────────────────── */
  $(document).on('click', '[data-confirm]', function (e) {
    if (!confirm($(this).data('confirm') || config.i18n.confirm_delete)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });

  /* ── Utility ──────────────────────────────────────────── */
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

}(jQuery, window.StellarMetaAdmin || null));
