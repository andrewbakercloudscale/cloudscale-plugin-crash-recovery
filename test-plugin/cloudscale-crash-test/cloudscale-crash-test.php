<?php
/**
 * Plugin Name: CloudScale Crash Test
 * Description: A test plugin that deliberately crashes your WordPress site with a fatal error. Used to test CloudScale Plugin Crash Recovery. DO NOT install on a production site.
 * Version: 1.0.0
 * Author: CloudScale
 * License: GPLv2 or later
 *
 * WARNING: This plugin will immediately white screen your site on activation.
 * It exists solely to test crash recovery tools. If you install this without
 * a recovery mechanism in place, you will need SSH or FTP access to remove it.
 */

if (!defined('ABSPATH')) { exit; }

// This fires on every request, not just activation.
// The site is dead the moment this file is loaded.
throw new \Error(
    'CloudScale Crash Test: deliberate fatal error. '
    . 'This plugin exists to test crash recovery. '
    . 'If you are reading this in a log, your recovery tool should remove this plugin automatically. '
    . 'If it did not, delete wp-content/plugins/cloudscale-crash-test/ manually via SSH or FTP.'
);
