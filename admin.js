/* CloudScale Plugin Crash Recovery — Admin JS v1.4.7 */
(function ($) {
    'use strict';

    // ── Tab switching ───────────────────────────────────────────────────────
    function activateTab(tab) {
        $('.cs-pcr-tab').removeClass('active');
        $('.cs-pcr-tab[data-tab="' + tab + '"]').addClass('active');
        $('.cs-pcr-tab-content').removeClass('active');
        $('#cs-pcr-tab-' + tab).addClass('active');
    }

    $(document).on('click', '.cs-pcr-tab', function () {
        var tab = $(this).data('tab');
        activateTab(tab);
        if (history.replaceState) {
            history.replaceState(null, '', location.pathname + location.search + '#tab-' + tab);
        }
    });

    // Restore active tab from hash on page load
    (function () {
        var hash = window.location.hash.replace('#tab-', '');
        var valid = ['checks', 'setup', 'status', 'logs'];
        if (hash && valid.indexOf(hash) !== -1) {
            activateTab(hash);
        }
    }());

    // ── Live config check ───────────────────────────────────────────────────
    function csCheckConfig() {
        $.post(CS_PCR.ajax_url, { action: 'cs_pcr_check_config', nonce: CS_PCR.nonce }, function (resp) {
            if (!resp.success) { return; }
            var writable = resp.data.found && resp.data.writable;
            var $btn = $('#cs-pcr-enable-debug');
            var $badge = $('#cs-pcr-cfg-badge');
            var $warn = $('#cs-pcr-cfg-warn');
            if (writable) {
                $btn.prop('disabled', false).removeAttr('title');
                $badge.removeClass('cs-pcr-badge-red').addClass('cs-pcr-badge-green').text('writable');
                $warn.hide();
            } else {
                $btn.prop('disabled', true);
                $badge.removeClass('cs-pcr-badge-green').addClass('cs-pcr-badge-red').text(resp.data.found ? 'not writable' : 'Not found');
                $warn.show();
            }
        });
    }

    // Run check when Logs tab is activated
    $(document).on('click', '.cs-pcr-tab[data-tab="logs"]', function () {
        csCheckConfig();
    });

    // Also run on page load if logs tab is active via hash
    $(function () {
        if (window.location.hash === '#tab-logs') { csCheckConfig(); }
    });

    // ── Explain modal ───────────────────────────────────────────────────────
    $(document).on('click', '.cs-pcr-btn-explain', function (e) {
        e.stopPropagation();
        var title = $(this).data('title') || 'Explain';
        var body  = $(this).data('body')  || '';
        $('#cs-pcr-modal-title').text(title);
        $('#cs-pcr-modal-body').text(body);
        $('#cs-pcr-modal-overlay').css('display','flex').hide().fadeIn(150);
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

    // ── Debug mode countdown ──────────────────────────────────────────────────────────────────
    var countdownInterval = null;

    function startCountdown(revertAt) {
        if (countdownInterval) { clearInterval(countdownInterval); }
        function tick() {
            var now  = Math.floor(Date.now() / 1000);
            var secs = revertAt - now;
            if (secs <= 0) {
                $('#cs-pcr-countdown').text('reverting…');
                clearInterval(countdownInterval);
                setTimeout(function () { location.href = location.pathname + location.search + '#tab-logs'; }, 3000);
                return;
            }
            var m = Math.floor(secs / 60);
            var s = secs % 60;
            $('#cs-pcr-countdown').text(m + 'm ' + (s < 10 ? '0' : '') + s + 's');
        }
        tick();
        countdownInterval = setInterval(tick, 1000);
    }

    if (parseInt(CS_PCR.debug_active, 10) === 1 && parseInt(CS_PCR.debug_revert_at, 10) > 0) {
        var revertAt = parseInt(CS_PCR.debug_revert_at, 10);
        var localTime = new Date(revertAt * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'});
        $('#cs-pcr-revert-time').text('at ' + localTime);
        startCountdown(revertAt);
    }

    // ── Enable debug ────────────────────────────────────────────────────────────────────
    $(document).on('click', '#cs-pcr-enable-debug', function () {
        var $btn = $(this);
        if (!confirm('Enable WP_DEBUG for 30 minutes? It will auto-revert. Errors go to debug.log only, never on screen.')) { return; }
        $btn.prop('disabled', true).text('Enabling…');
        showDebugMsg('', '');

        $.post(CS_PCR.ajax_url, { action: 'cs_pcr_enable_debug', nonce: CS_PCR.nonce }, function (resp) {
            if (!resp.success) {
                showDebugMsg('error', 'Error: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                $btn.prop('disabled', false).text('&#9654; Enable Debug (30 min)');
                return;
            }
            var localTime = new Date(resp.data.revert_at * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'});
            showDebugMsg('success', '✅ Debug mode active. Auto-reverts at ' + localTime + '. Log: ' + resp.data.debug_log_path);
            setTimeout(function () { location.href = location.pathname + location.search + '#tab-logs'; }, 1800);
        }).fail(function () {
            showDebugMsg('error', 'AJAX request failed.');
            $btn.prop('disabled', false).text('&#9654; Enable Debug (30 min)');
        });
    });

    // ── Disable debug ───────────────────────────────────────────────────────────────────
    $(document).on('click', '#cs-pcr-disable-debug', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Reverting…');
        showDebugMsg('', '');

        $.post(CS_PCR.ajax_url, { action: 'cs_pcr_disable_debug', nonce: CS_PCR.nonce }, function (resp) {
            if (!resp.success) {
                showDebugMsg('error', 'Error: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                $btn.prop('disabled', false).text('&#9209; Revert Debug Now');
                return;
            }
            showDebugMsg('success', '✅ Debug mode disabled successfully.');
            if (countdownInterval) { clearInterval(countdownInterval); }
            setTimeout(function () { location.href = location.pathname + location.search + '#tab-logs'; }, 1500);
        }).fail(function () {
            showDebugMsg('error', 'AJAX request failed.');
            $btn.prop('disabled', false).text('&#9209; Revert Debug Now');
        });
    });

    function showDebugMsg(type, text) {
        var $el = $('#cs-pcr-debug-message');
        if (!text) { $el.hide().empty(); return; }
        var cls = type === 'success' ? 'cs-pcr-summary-pass' : 'cs-pcr-summary-fail';
        $el.html('<div class="cs-pcr-summary ' + cls + '">' + escHtml(text) + '</div>').show();
    }

    // ── Log loading ────────────────────────────────────────────────────────────────────
    var allLogEntries   = [];
    var autoReloadTimer = null;

    $(document).on('click', '#cs-pcr-load-logs', function () {
        fetchLogs();
    });

    function fetchLogs() {
        $('#cs-pcr-logs-spinner').show();
        $('#cs-pcr-log-wrap, #cs-pcr-logs-empty, #cs-pcr-logs-meta').hide();
        $('#cs-pcr-log-entry-count').text('');

        $.post(CS_PCR.ajax_url, { action: 'cs_pcr_get_logs', nonce: CS_PCR.nonce }, function (resp) {
            $('#cs-pcr-logs-spinner').hide();
            if (!resp.success) {
                $('#cs-pcr-logs-empty').text('Failed to load logs.').show();
                return;
            }

            allLogEntries = resp.data.entries;

            // Populate source filter
            var $src = $('#cs-pcr-filter-source').empty().append('<option value="">All sources</option>');
            $.each(resp.data.sources_found, function (label, path) {
                $src.append('<option value="' + escHtml(label) + '">' + escHtml(label) + ' (' + escHtml(path) + ')</option>');
            });
            $src.show();

            var generated = new Date(resp.data.generated_at * 1000).toLocaleTimeString();
            $('#cs-pcr-logs-meta').html(
                'Showing up to 500 entries from the last 24h &bull; ' +
                Object.keys(resp.data.sources_found).length + ' source(s) found &bull; ' +
                resp.data.total + ' total entries &bull; refreshed at ' + generated
            ).show();

            renderLogEntries();
        }).fail(function () {
            $('#cs-pcr-logs-spinner').hide();
            $('#cs-pcr-logs-empty').text('AJAX request failed.').show();
        });
    }

    function renderLogEntries() {
        var filterSource = $('#cs-pcr-filter-source').val();
        var filterLevel  = $('#cs-pcr-filter-level').val();
        var filterText   = $('#cs-pcr-filter-text').val().toLowerCase();

        var filtered = allLogEntries.filter(function (e) {
            if (filterSource && e.source !== filterSource) { return false; }
            if (filterLevel  && e.level  !== filterLevel)  { return false; }
            if (filterText   && e.line.toLowerCase().indexOf(filterText) === -1) { return false; }
            return true;
        });

        var $out = $('#cs-pcr-log-output').empty();

        if (!filtered.length) {
            $('#cs-pcr-log-wrap').hide();
            $('#cs-pcr-logs-empty').show();
            $('#cs-pcr-log-entry-count').text('');
            return;
        }

        $('#cs-pcr-logs-empty').hide();
        $('#cs-pcr-log-wrap').show();
        $('#cs-pcr-log-entry-count').text('(' + filtered.length + ')');

        var levelColors = {
            fatal:   '#ff6b6b',
            error:   '#fca5a5',
            warning: '#fbbf24',
            notice:  '#93c5fd',
            success: '#4ade80',
            info:    '#e2e8f0'
        };
        var sourceColors = {
            watchdog:  '#a78bfa',
            wp_debug:  '#34d399',
            php_error: '#fb923c',
            apache:    '#60a5fa',
            apache2:   '#60a5fa',
            nginx:     '#f472b6'
        };

        filtered.forEach(function (e) {
            var lineColor  = levelColors[e.level]  || '#e2e8f0';
            var srcColor   = sourceColors[e.source] || '#94a3b8';
            var srcLabel   = '<span style="color:' + srcColor + ';font-size:10px;font-weight:700;margin-right:6px;opacity:0.85;">[' + escHtml(e.source) + ']</span>';
            var $line = $('<div class="cs-pcr-log-line">').css('color', lineColor)
                            .html(srcLabel + escHtml(e.line));
            $out.append($line);
        });

        // Scroll to top (newest first)
        $out.scrollTop(0);
    }

    // Live filter updates
    $(document).on('change', '#cs-pcr-filter-source, #cs-pcr-filter-level', renderLogEntries);
    $(document).on('input',  '#cs-pcr-filter-text', renderLogEntries);

    // Auto-refresh toggle
    $(document).on('change', '#cs-pcr-autoreload', function () {
        if ($(this).is(':checked')) {
            autoReloadTimer = setInterval(fetchLogs, 30000);
        } else {
            clearInterval(autoReloadTimer);
        }
    });

}(jQuery));
