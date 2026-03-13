=== CloudScale Crash Test ===
Contributors: cloudscale
Tags: testing, crash, recovery, development
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

A deliberate crash plugin for testing CloudScale Plugin Crash Recovery.

== Description ==

This plugin throws a fatal error on every request. It will white screen your WordPress site immediately.

**DO NOT install this on a production site without a recovery mechanism in place.**

It exists for one purpose: to verify that CloudScale Plugin Crash Recovery (or any similar watchdog tool) correctly detects the crash, identifies this plugin as the most recently modified file, and removes it automatically.

== Installation ==

1. Install and activate CloudScale Plugin Crash Recovery first.
2. Upload this plugin via Plugins > Add New > Upload Plugin.
3. Activate it. Your site will immediately white screen.
4. Wait 60 seconds. CloudScale Plugin Crash Recovery should detect the failure and delete this plugin.
5. Your site should be back online within two minutes.

If recovery does not happen, delete `wp-content/plugins/cloudscale-crash-test/` via SSH or FTP.

== Changelog ==

= 1.0.0 =
* Initial release. Throws a fatal error. That is the entire feature set.
