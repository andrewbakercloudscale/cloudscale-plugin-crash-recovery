=== CloudScale Crash Recovery ===
Contributors: andrewbaker007
Tags: crash recovery, plugin watchdog, site health, auto-recovery
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.4.7
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

System-cron watchdog that detects site crashes and automatically deactivates the culprit plugin.

== Description ==

CloudScale Crash Recovery is a lightweight watchdog plugin that probes your site every minute using a system cron job. If a crash is detected, it automatically deactivates and deletes the most recently modified plugin (within the last 10 minutes), restoring your site to a working state without manual intervention.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/cloudscale-plugin-crash-recovery/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Ensure your server supports system cron.

== Changelog ==

= 1.4.7 =
* Fixed tab hash persistence — JS valid-tab list now matches actual data-tab values.
* Fixed modal overlay dismiss on outside click.
* Added live wp-config.php writability AJAX check on Logs tab activation.
* Local time display for debug-mode revert uses browser toLocaleTimeString.
* Asset versions now include file mtime for automatic Cloudflare cache-busting.

= 1.4.2 =
* Added unified Log Viewer tab aggregating watchdog, debug.log, PHP error log, Apache/Nginx logs.
* Added WordPress debug-mode toggle (30-minute window, dual revert safety net).
* Added auto-countdown timer while debug mode is active.
* Added source/severity/text filters and auto-refresh toggle for log viewer.
* Fixed debug-mode countdown calculation.

= 1.2.0 =
* Initial public release — system cron watchdog, compatibility checks, Status & Log tab.
