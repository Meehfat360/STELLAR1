/* Stellar Overstock Sync — Enterprise Admin JS */
(function ($) {
    'use strict';

    /* ── Profit Rule inline editor ───────────────────────────── */
    $(document).on('change', '.sos-profit-select', function () {
        var $select = $(this);
        var $cell   = $select.closest('.sos-profit-cell');
        var $btn    = $cell.find('.sos-profit-save-btn');
        var $tick   = $cell.find('.sos-profit-saved-tick');

        // Show save button when value changes
        if ($select.val() !== $select.data('original')) {
            $btn.addClass('visible');
            $tick.removeClass('show');
        } else {
            $btn.removeClass('visible');
        }
    });

    $(document).on('click', '.sos-profit-save-btn', function (e) {
        e.preventDefault();
        var $btn    = $(this);
        var $cell   = $btn.closest('.sos-profit-cell');
        var $select = $cell.find('.sos-profit-select');
        var $tick   = $cell.find('.sos-profit-saved-tick');
        var $row    = $btn.closest('tr');

        var mappingId  = $select.data('mapping-id');
        var profitType = $select.data('profit-type');
        var newValue   = $select.val();

        if (!mappingId || !newValue) { return; }

        $btn.html('<span class="sos-spinner"></span>').prop('disabled', true);

        $.post(
            window.sosAdmin.ajaxUrl || ajaxurl,
            {
                action:      'sos_update_profit_rule',
                mapping_id:  mappingId,
                profit_type: profitType,
                profit_value: newValue,
                _ajax_nonce: window.sosAdmin.profitNonce || ''
            },
            function (resp) {
                if (resp && resp.success) {
                    $select.data('original', newValue);
                    // Keep data-profit-type in sync so subsequent saves send the current type
                    if (resp.data && resp.data.profit_type) {
                        $select.data('profit-type', resp.data.profit_type);
                    }
                    $btn.removeClass('visible').html('✓').prop('disabled', false);
                    $tick.addClass('show');
                    $row.addClass('sos-row-just-updated');
                    setTimeout(function () {
                        $row.removeClass('sos-row-just-updated');
                        $tick.removeClass('show');
                    }, 2500);
                } else {
                    $btn.html('✓').prop('disabled', false);
                    alert(resp && resp.data ? resp.data : 'Save failed. Please try again.');
                }
            }
        ).fail(function () {
            $btn.html('✓').prop('disabled', false);
            alert('Network error. Please try again.');
        });
    });

    /* ── Dismiss-able notices ────────────────────────────────── */
    $(document).on('click', '.sos-notice .notice-dismiss', function () {
        $(this).closest('.sos-notice').fadeOut(200);
    });

    /* ── Confirm destructive actions ─────────────────────────── */
    $(document).on('submit', '.sos-confirm-form', function (e) {
        var msg = $(this).data('confirm') || 'Are you sure?';
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });

}(jQuery));
