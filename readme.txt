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

= 1.3.0 =
* Added Logs & Debug tab: unified log viewer aggregating watchdog, WordPress debug.log, PHP error log, and Apache/Nginx logs filtered to the last 24 hours.
* Added WordPress debug mode toggle: enables WP_DEBUG and WP_DEBUG_LOG for exactly 30 minutes with dual revert safety net (WP-Cron and system cron one-shot script).
* Auto-countdown timer displayed while debug mode is active.
* Filter log entries by source, severity level, and free-text search.
* Auto-refresh log viewer every 30 seconds toggle.

= 1.2.0 =
* Previous release.
