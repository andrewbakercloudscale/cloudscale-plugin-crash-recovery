# CloudScale Plugin Crash Recovery

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue) ![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple) ![License](https://img.shields.io/badge/License-GPLv2-green) ![Version](https://img.shields.io/badge/Version-1.0.0-orange)

A WordPress watchdog plugin that automatically detects when your site has crashed and rolls back the most recently modified plugin. Zero configuration. Install it, activate it, forget about it until it saves you.

> Full write up: [You Just Uploaded a New Plugin and Your WordPress Site Just Crashed. Now What?](https://andrewbaker.ninja/2026/03/02/you-just-uploaded-a-new-plugin-and-your-wordpress-site-just-crashed-now-what/)

## The Problem

You install or update a plugin and it white screens your site. WordPress is down. You cannot access the admin to deactivate the offending plugin. Your only options are SSH or FTP to manually delete the plugin files, or restore from a backup. If you are on shared hosting without shell access, you are stuck until your host responds to a support ticket.

## How It Works

The plugin registers a WordPress Cron job that fires every 60 seconds. Each tick does the following:

1. **Probe**: Sends an HTTP GET to a lightweight endpoint on your own site (`?cs_pcr_probe=1`). The endpoint returns `CLOUDSCALE_OK` with no cache headers.

2. **Evaluate**: If the probe returns HTTP 200 with the expected body, your site is healthy. The tick exits and does nothing.

3. **Recover**: If the probe fails (500 error, timeout, unexpected body, connection refused), the plugin identifies the most recently modified plugin file on disk. If that file was modified within the last 10 minutes (the recovery window), it deactivates and deletes that plugin.

The 10 minute window is critical. It means the watchdog only acts on plugins that were just installed, updated, or modified. It will never touch a plugin that has been sitting quietly on your server for days. If your site crashes for a reason unrelated to a recent plugin change (database failure, disk full, PHP upgrade), the watchdog sees no recently modified plugin and takes no action.

## What It Protects Against

- A newly installed plugin that fatal errors on activation
- A plugin update that introduces a breaking change
- A plugin file upload via FTP or SSH that contains bad code
- Any scenario where a recently modified plugin causes a white screen, 500 error, or fatal PHP error

## What It Does Not Do

- It does not protect against database corruption, server misconfigurations, or theme errors
- It does not act on plugins older than 10 minutes (by file modification time)
- It does not have a UI, settings page, or admin panel. There is nothing to configure
- It never deactivates or deletes itself

## The Recovery Window

The `WINDOW_SECONDS` constant is set to 600 (10 minutes). Only plugins with a file modification time within this window are candidates for removal. This means:

- Install a plugin at 14:00, site crashes at 14:03: the plugin is within the window and gets removed
- Install a plugin at 14:00, site crashes at 14:15: the plugin is outside the window and is left alone
- Two plugins updated at 14:00 and 14:05, site crashes at 14:06: the plugin modified at 14:05 (most recent) gets removed first. If the site is still down at the next tick, the 14:00 plugin gets removed next (if still within the window)

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- WordPress Cron must be functional (either visitor triggered or via system cron)

## Installation

1. Download `cloudscale-plugin-crash-recovery.php`
2. Upload it to `wp-content/plugins/`
3. Activate in WordPress admin

Or download the release zip and install via **Plugins > Add New > Upload Plugin**.

That is it. No settings. No configuration. The watchdog starts ticking immediately.

### For Reliable Timing

WordPress Cron only fires on page visits. On low traffic sites the watchdog may not tick for several minutes. For precise 60 second ticks, add a real system cron:
```
* * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

## How the Probe Works

The plugin hooks into `init` at priority 1 and checks for the `cs_pcr_probe` query parameter. If present, it outputs `CLOUDSCALE_OK` with no cache headers and exits immediately. This endpoint is as lightweight as possible: no template loading, no theme, no other plugins executing past the init hook.

The probe request includes a cache busting timestamp parameter and explicit `Cache-Control: no-cache` headers to prevent CDN or browser caching of the response.

## Single File Plugin

The entire plugin is a single PHP file with no dependencies, no assets, no database tables, and no options. It can be dropped into `wp-content/plugins/` manually or installed as a standard WordPress plugin zip.

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.

## Author

[Andrew Baker](https://andrewbaker.ninja/) - CIO at Capitec Bank, South Africa.
