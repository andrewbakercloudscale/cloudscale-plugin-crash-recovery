# Changelog

All notable changes to CloudScale Crash Recovery are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [1.4.7] - 2026-03-13
### Fixed
- Tab hash persistence: JS valid-tab list now matches actual `data-tab` attribute values (`checks`, `setup`).
- Modal overlay now dismisses correctly on outside click.
- Local time display for debug-mode revert timestamp uses `toLocaleTimeString` in the browser.
### Added
- Live wp-config.php writability check via AJAX on Logs tab activation — never stale.
### Changed
- Asset version strings append file mtime to `CS_PCR_VERSION` so Cloudflare cache busts on every deploy without a manual purge.

## [1.4.2] - 2025-04-01
### Added
- Logs & Debug tab: unified log viewer aggregating watchdog, WordPress debug.log, PHP error log, and Apache/Nginx error logs filtered to the last 24 hours.
- WordPress debug-mode toggle: enables `WP_DEBUG` / `WP_DEBUG_LOG` for exactly 30 minutes with dual-revert safety net (WP-Cron + system cron one-shot script).
- Auto-countdown timer displayed while debug mode is active.
- Filter log entries by source, severity level, and free-text search.
- Auto-refresh log viewer toggle (30-second interval).
### Fixed
- Debug-mode countdown now re-calculates from correct server timestamp.
- Cache purge step added to deploy workflow.

## [1.2.0] - 2025-02-01
### Added
- System cron watchdog: probes the site every minute via `curl`; on failure identifies and removes the most recently modified plugin within the 10-minute crash window.
- Compatibility Checks tab: 10 server-side checks (PHP CLI, `shell_exec`, `curl`, probe endpoint, plugin-dir permissions, WP-CLI, watchdog script, cron entry, log file, legacy WP cron).
- Status & Log tab: watchdog deployment status, last recovery action, last alert, log tail.
- WP-CLI integration: watchdog deactivates the culprit plugin via WP-CLI before deletion.
- Probe endpoint: responds with `CLOUDSCALE_OK` on healthy load.
