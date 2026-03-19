'use strict';
const helpLib = require('/Users/cp363412/Desktop/github/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Crash Recovery',
    pluginDesc: 'A bad plugin update can take your entire site down in seconds — and if WordPress itself is broken, you cannot log in to fix it. CloudScale Crash Recovery watches your site from outside WordPress, detects crashes within minutes, and automatically rolls back the offending plugin before most visitors even notice. Completely free, no subscription, no premium tier.',
    pageTitle:  'CloudScale Crash Recovery: Online Help',
    pageSlug:   'crash-recovery-help',
    downloadUrl: 'https://andrewninjawordpress.s3.af-south-1.amazonaws.com/cloudscale-plugin-crash-recovery.zip',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-crash-recovery`,

    sections: [
        { id: 'watchdog',   label: 'Watchdog Dashboard',    file: 'panel-watchdog.png'   },
        { id: 'crash-log',  label: 'Crash Log',              file: 'panel-crash-log.png'  },
        { id: 'setup',      label: 'Setup & Configuration',  file: 'panel-setup.png'      },
    ],

    docs: {
        'watchdog': `
<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:28px;">
<h2 style="margin:0 0 10px;font-size:1.3em;color:#0f172a;">Why CloudScale Crash Recovery?</h2>
<p style="margin:0 0 10px;">Every WordPress site owner has experienced it: you click "Update" on a plugin, the page goes white, and suddenly your site is serving a 500 error to every visitor. Worse, you cannot log into wp-admin to fix it — because WordPress itself is broken.</p>
<p style="margin:0 0 10px;">CloudScale Crash Recovery watches your site from outside WordPress using your server's system cron. It probes your site every minute. The moment it detects a crash, it automatically deactivates and removes the most recently modified plugin — the most likely cause — and re-probes to confirm recovery. The whole process takes under two minutes.</p>
<p style="margin:0;"><strong>It is completely free.</strong> No premium version, no upgrade nag, no monthly fee. Install it, configure a system cron entry, and your site has automatic crash recovery running silently in the background.</p>
</div>
<p>The <strong>Watchdog Dashboard</strong> shows the real-time status of your automated crash recovery system. The watchdog is a PHP script invoked by a system cron job that makes an HTTP GET request to your site's frontend URL every minute. If the response code is not 200 OK, it triggers the recovery sequence.</p>
<ul>
<li><strong>Watchdog status</strong> — <em>Active</em>: the system cron is configured and the watchdog has probed the site within the last 2 minutes. <em>Inactive</em>: no recent probe recorded — check your crontab or see Setup &amp; Configuration.</li>
<li><strong>Last probe</strong> — exact timestamp of the most recent health check. If this timestamp is more than 3 minutes old and the watchdog shows Active, the cron job may be stalled or the PHP process is timing out. Investigate with <code>grep CRON /var/log/syslog | tail -20</code> (Linux) or <code>grep cron /var/log/system.log | tail -20</code> (macOS/cPanel).</li>
<li><strong>Last response code</strong> — the HTTP status code from the most recent probe request. 200 = healthy. 500 = PHP fatal error (most common cause of plugin-induced crashes). 503 = maintenance mode active. 301/302 = your homepage redirects — update the Probe URL to the redirect target to avoid false positives.</li>
<li><strong>Recovery count</strong> — cumulative number of automatic recoveries since the plugin was activated. A count above 0 means the watchdog has saved your site at least once. Each event is logged with full details in the Crash Log tab.</li>
</ul>
<p><strong>How the recovery sequence works:</strong> On detecting a non-200 response, the watchdog calls WP-CLI (<code>wp plugin deactivate</code> and <code>wp plugin delete</code>) on the plugin whose files in <code>wp-content/plugins/</code> have the most recent <code>mtime</code> within the configured recovery window (default: 10 minutes). It then re-probes the site. If the re-probe returns 200 OK, recovery is confirmed and the event is logged. If the re-probe still fails, the watchdog logs the failure and waits for the next cron cycle — it does not cascade-delete additional plugins.</p>`,

        'crash-log': `
<p>The <strong>Crash Log</strong> records every recovery event with enough detail to conduct a post-incident review and take preventive action.</p>
<ul>
<li><strong>Timestamp</strong> — exact date and time (server timezone) when the crash was first detected by the watchdog probe.</li>
<li><strong>HTTP status</strong> — the error code that triggered the recovery. <code>500 Internal Server Error</code> is the most common — indicates a PHP fatal error, typically caused by a plugin update that introduced a parse error or incompatible function call. <code>502 Bad Gateway</code> or <code>504 Gateway Timeout</code> indicate a PHP-FPM or web server process crash rather than a PHP code error.</li>
<li><strong>Plugin deactivated</strong> — the plugin folder name that was identified as the culprit and removed. Identification is based on the plugin directory with the most recent filesystem <code>mtime</code> within the recovery window. This is accurate when a plugin was updated or installed shortly before the crash, which covers the vast majority of plugin-induced outages.</li>
<li><strong>Recovery confirmed</strong> — <code>Yes</code> if the re-probe returned 200 OK after the plugin was removed. <code>No</code> means the site was still returning an error after the plugin was deactivated — the crash may have a different root cause (database corruption, server resource exhaustion, theme error).</li>
<li><strong>Response time</strong> — total elapsed time from crash detection to confirmed recovery, in seconds. Typical recovery time: 60–90 seconds (one cron cycle to detect + time to run WP-CLI + one re-probe).</li>
</ul>
<p><strong>Post-incident actions:</strong> After reviewing the log, reinstall the deactivated plugin only after confirming the crash root cause. Check the plugin's changelog for known PHP compatibility issues, or test the plugin on a staging environment before reactivating on production.</p>`,

        'setup': `
<p>The watchdog requires a <strong>system cron job</strong> — not WordPress WP-Cron — to run reliably every minute. WP-Cron is visitor-triggered: it only fires when a page is requested, cannot guarantee minute-level frequency, and does not run at all if your site is down (which is exactly when you need it).</p>
<p><strong>Setup steps:</strong></p>
<ol>
<li>Copy the exact cron command shown in this panel. It is pre-populated with the correct PHP binary path and WordPress root for your server. It will look similar to:<br><code>* * * * * /usr/bin/php /var/www/html/wp-content/plugins/cloudscale-crash-recovery/watchdog.php &gt;&gt; /var/log/cs-watchdog.log 2&gt;&amp;1</code></li>
<li>Open the crontab for the web server user: <code>sudo crontab -u apache -e</code> (Apache/RHEL) or <code>sudo crontab -u www-data -e</code> (Nginx/Debian). Using the web server user ensures the watchdog has the same filesystem permissions as WordPress itself.</li>
<li>Paste the command, save, and exit. Verify the cron was registered: <code>sudo crontab -u apache -l</code></li>
<li>Wait 2 minutes, then return to this panel. The <em>Last probe</em> timestamp should update and Watchdog Status should show <em>Active</em>.</li>
<li>Click <strong>Test Connectivity</strong> to verify the watchdog can reach your site's probe URL and that the HTTP response is being recorded correctly.</li>
</ol>
<p><strong>Configuration options:</strong></p>
<ul>
<li><strong>Probe URL</strong> — the URL the watchdog sends a GET request to. Defaults to your WordPress <code>home_url()</code>. Change this if: your homepage has a redirect (use the final destination URL), your homepage requires authentication, or you want to probe a specific health-check endpoint. The probe uses <code>wp_remote_get()</code> with a 10-second timeout and <code>sslverify = true</code>.</li>
<li><strong>Recovery window</strong> — how recently (in minutes) a plugin must have been modified on disk to be considered the crash culprit. Default: 10 minutes. If you deploy or update plugins frequently, reduce this to 5 minutes to avoid false positives. If you batch-install many plugins at once, increase it to 20–30 minutes.</li>
<li><strong>Notification email</strong> — the watchdog sends a plain-text email via <code>wp_mail()</code> to this address on every recovery event. Leave blank to disable email notifications. Check your server's mail delivery logs if emails are not arriving: <code>tail -50 /var/log/maillog</code></li>
</ul>`,
    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
