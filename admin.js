/* CloudScale Plugin Crash Recovery — Admin JS v1.1.0 */
(function ($) {
    'use strict';

    // ── Tab switching ───────────────────────────────────────────────────────
    $(document).on('click', '.cs-pcr-tab', function () {
        var tab = $(this).data('tab');
        $('.cs-pcr-tab').removeClass('active');
        $(this).addClass('active');
        $('.cs-pcr-tab-content').removeClass('active');
        $('#cs-pcr-tab-' + tab).addClass('active');
    });

    // ── Explain modal ───────────────────────────────────────────────────────
    $(document).on('click', '.cs-pcr-btn-explain', function (e) {
        e.stopPropagation();
        var title = $(this).data('title') || 'Explain';
        var body  = $(this).data('body')  || '';
        $('#cs-pcr-modal-title').text(title);
        $('#cs-pcr-modal-body').text(body);
        $('#cs-pcr-modal-overlay').fadeIn(150);
    });

    $(document).on('click', '#cs-pcr-modal-close, #cs-pcr-modal-overlay', function (e) {
        if (e.target === this) {
            $('#cs-pcr-modal-overlay').fadeOut(150);
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') { $('#cs-pcr-modal-overlay').fadeOut(150); }
    });

    // ── Run compatibility checks ────────────────────────────────────────────
    $('#cs-pcr-run-checks').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Running…');
        $('#cs-pcr-checks-output').hide();
        $('#cs-pcr-checks-spinner').show();

        $.post(CS_PCR.ajax_url, {
            action: 'cs_pcr_run_checks',
            nonce:  CS_PCR.nonce
        }, function (resp) {
            $btn.prop('disabled', false).text('▶ Run Compatibility Checks');
            $('#cs-pcr-checks-spinner').hide();

            if (!resp.success) {
                alert('Check failed: ' + (resp.data || 'Unknown error'));
                return;
            }

            var data     = resp.data;
            var checks   = data.checks;
            var $tbody   = $('#cs-pcr-checks-body').empty();
            var $summary = $('#cs-pcr-checks-summary').empty();

            // Build summary banner
            var summaryClass, summaryIcon, summaryText;
            if (data.failures > 0) {
                summaryClass = 'cs-pcr-summary-fail';
                summaryIcon  = '❌';
                summaryText  = data.failures + ' critical check(s) failed. Resolve these before installing system cron.';
            } else if (data.warnings > 0) {
                summaryClass = 'cs-pcr-summary-warn';
                summaryIcon  = '⚠️';
                summaryText  = 'All critical checks passed with ' + data.warnings + ' warning(s). Review warnings before proceeding.';
            } else {
                summaryClass = 'cs-pcr-summary-pass';
                summaryIcon  = '✅';
                summaryText  = 'All checks passed. Your instance is ready for system cron installation.';
            }

            $summary.append(
                $('<div>').addClass('cs-pcr-summary ' + summaryClass).html(
                    '<span style="font-size:18px;">' + summaryIcon + '</span> ' + summaryText
                )
            );

            // Build results table rows
            $.each(checks, function (i, check) {
                var icon, badgeClass;
                if (check.status === 'pass') {
                    icon = '✅'; badgeClass = 'cs-pcr-badge-green';
                } else if (check.status === 'warning') {
                    icon = '⚠️'; badgeClass = 'cs-pcr-badge-amber';
                } else {
                    icon = '❌'; badgeClass = 'cs-pcr-badge-red';
                }

                var detailHtml = '';
                if (check.detail) {
                    detailHtml = '<br><code style="font-size:11px;color:#6b7690;">' + escHtml(check.detail) + '</code>';
                }

                var $tr = $('<tr>').append(
                    $('<td>').text(check.name),
                    $('<td>').html('<span class="cs-pcr-badge ' + badgeClass + '">' + icon + ' ' + check.status.toUpperCase() + '</span>'),
                    $('<td>').html(escHtml(check.message) + detailHtml)
                );
                $tbody.append($tr);
            });

            $('#cs-pcr-checks-output').show();
        }).fail(function () {
            $btn.prop('disabled', false).text('▶ Run Compatibility Checks');
            $('#cs-pcr-checks-spinner').hide();
            alert('AJAX request failed. Check your network connection.');
        });
    });

    // ── Copy buttons ────────────────────────────────────────────────────────
    $('#cs-pcr-copy-script').on('click', function () {
        copyText($('#cs-pcr-watchdog-script').text(), $(this), 'Script copied!');
    });

    $('#cs-pcr-copy-cron').on('click', function () {
        copyText($('#cs-pcr-cron-line').text().trim(), $(this), 'Cron line copied!');
    });

    function copyText(text, $btn, msg) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                flash($btn, msg);
            }).catch(function () { fallbackCopy(text, $btn, msg); });
        } else {
            fallbackCopy(text, $btn, msg);
        }
    }

    function fallbackCopy(text, $btn, msg) {
        var $ta = $('<textarea>').val(text).css({ position: 'fixed', top: -9999 }).appendTo('body');
        $ta[0].select();
        try { document.execCommand('copy'); flash($btn, msg); } catch (e) { /* silent */ }
        $ta.remove();
    }

    function flash($btn, msg) {
        var orig = $btn.text();
        $btn.text(msg);
        setTimeout(function () { $btn.text(orig); }, 2000);
    }

    function escHtml(str) {
        if (!str) { return ''; }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

}(jQuery));
