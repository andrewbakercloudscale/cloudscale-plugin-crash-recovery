<?php
/**
 * Plugin Name:       CloudScale Crash Recovery
 * Description:       System-cron-based watchdog that probes the site every minute. If a crash is detected, deactivates and deletes the most recently modified plugin (within 10 minutes). Includes compatibility checks to validate the instance supports system cron.
 * Version:           1.4.7
 * Requires at least: 6.0
 * Tested up to:      6.9
 * Requires PHP:      8.0
 * Author:            CloudScale
 * Author URI:        https://andrewbaker.ninja/
 * Plugin URI:        https://andrewbaker.ninja/2026/03/02/you-just-uploaded-a-new-plugin-and-your-wordpress-site-just-crashed-now-what/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cloudscale-crash-recovery
 */

/**
 * CloudScale Crash Recovery — main plugin file.
 *
 * Registers all hooks, AJAX handlers, and the admin page. The watchdog
 * recovery logic (callable from WP-CLI) also lives here. Shared stateless
 * helpers are in {@see Cloudscale_Crash_Recovery_Utils}.
 *
 * @package CloudScale_Crash_Recovery
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CS_PCR_VERSION', '1.4.7' );
define( 'CS_PCR_PROBE_KEY',      'cs_pcr_probe' );
define( 'CS_PCR_OK_BODY',        'CLOUDSCALE_OK' );
define( 'CS_PCR_WINDOW_SECONDS', 600 );
define( 'CS_PCR_SLUG',           'cloudscale-crash-recovery' );
define( 'CS_PCR_LOG_FILE',       '/var/log/cloudscale-crash-recovery.log' );
define( 'CS_PCR_WATCHDOG',       '/usr/local/bin/cs-crash-watchdog.sh' );

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded',        'cs_pcr_load_textdomain' );
add_action( 'init',                  'cs_pcr_maybe_probe_endpoint', 1 );
add_action( 'admin_menu',            'cs_pcr_add_menu' );
add_action( 'admin_enqueue_scripts', 'cs_pcr_enqueue_assets' );
add_action( 'admin_init',            'cs_pcr_no_cache_headers' );
add_action( 'wp_ajax_cs_pcr_run_checks',    'cs_pcr_ajax_run_checks' );
add_action( 'wp_ajax_cs_pcr_get_logs',      'cs_pcr_ajax_get_logs' );
add_action( 'wp_ajax_cs_pcr_enable_debug',  'cs_pcr_ajax_enable_debug' );
add_action( 'wp_ajax_cs_pcr_disable_debug', 'cs_pcr_ajax_disable_debug' );
add_action( 'wp_ajax_cs_pcr_check_config',  'cs_pcr_ajax_check_config' );
add_action( 'cs_pcr_revert_debug_hook',     'cs_pcr_do_revert_debug' );

// ---------------------------------------------------------------------------
// Probe endpoint
// ---------------------------------------------------------------------------

/**
 * Sends no-cache headers on the plugin's admin page before any object cache can respond.
 *
 * Prevents Redis and Cloudflare from serving a stale version of the admin page
 * or its JS/CSS assets.
 *
 * @since  1.0.0
 * @return void
 */
function cs_pcr_no_cache_headers() {
    // Fire before any object-cache plugin can serve a cached response.
    // Prevents Redis and Cloudflare from ever serving a stale version of
    // the plugin admin page or its JS/CSS assets.
    if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== CS_PCR_SLUG ) { return; }
    nocache_headers();
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
}

/**
 * Loads the plugin text domain for translations.
 *
 * Hooked on `plugins_loaded` so translations are available before `init`.
 *
 * @since  1.0.0
 * @return void
 */
function cs_pcr_load_textdomain() {
    load_plugin_textdomain( 'cloudscale-crash-recovery', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * Serves the plain-text health-check probe response.
 *
 * Fires on `init` at priority 1. If the `cs_pcr_probe` query arg is present,
 * outputs `CLOUDSCALE_OK` as plain text and exits — before any object cache
 * or page-builder logic runs. This is what the system-cron watchdog curl-checks.
 *
 * @since  1.0.0
 * @return void
 */
function cs_pcr_maybe_probe_endpoint() {
    if ( ! isset( $_GET[ CS_PCR_PROBE_KEY ] ) ) { return; }
    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );
    // Plain-text health-check constant — esc_html() is a no-op on this value
    // but satisfies PHPCS output-escaping rules.
    echo esc_html( CS_PCR_OK_BODY );
    exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text probe endpoint; wp_die() adds HTML incompatible with the watchdog curl check.
}

// ---------------------------------------------------------------------------
// Recovery logic (callable from WP-CLI)
// ---------------------------------------------------------------------------

/**
 * Deactivates and deletes the most recently modified plugin within the crash window.
 *
 * Scans all installed plugins (excluding this one) and identifies the plugin whose
 * main file was modified most recently. If that modification is within
 * `CS_PCR_WINDOW_SECONDS` (10 minutes), the plugin is deactivated via
 * `deactivate_plugins()` and its directory (or single file) is recursively deleted.
 *
 * Callable from WP-CLI: `wp eval 'echo cs_pcr_delete_most_recent_plugin_in_window();'`
 *
 * @since  1.0.0
 * @return string Human-readable result message.
 */
function cs_pcr_delete_most_recent_plugin_in_window() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all  = get_plugins();
    $now  = time();
    $self = plugin_basename( __FILE__ );
    $newest_file  = null;
    $newest_mtime = 0;
    foreach ( $all as $plugin_file => $data ) {
        if ( $plugin_file === $self ) { continue; }
        $abs   = WP_PLUGIN_DIR . '/' . $plugin_file;
        if ( ! file_exists( $abs ) ) { continue; }
        $mtime = filemtime( $abs );
        if ( ! $mtime ) { continue; }
        if ( $mtime > $newest_mtime ) { $newest_mtime = $mtime; $newest_file = $plugin_file; }
    }
    if ( ! $newest_file ) { return 'No candidate plugins found.'; }
    if ( ( $now - $newest_mtime ) > CS_PCR_WINDOW_SECONDS ) {
        return 'Most-recently-modified plugin is outside the 10-minute window. No action taken.';
    }
    if ( ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    deactivate_plugins( $newest_file, true );
    $target = WP_PLUGIN_DIR . '/' . $newest_file;
    $dir    = dirname( $target );
    cs_pcr_delete_path( ( basename( $dir ) === 'plugins' ) ? $target : $dir );
    return 'Removed: ' . $newest_file;
}

/**
 * Recursively deletes a file, symlink, or directory.
 *
 * Used by {@see cs_pcr_delete_most_recent_plugin_in_window()} to remove a
 * plugin directory after it has been deactivated. Does nothing if the path
 * does not exist.
 *
 * @since  1.0.0
 * @param  string $path Absolute path to delete.
 * @return void
 */
function cs_pcr_delete_path( $path ) {
    if ( is_file( $path ) || is_link( $path ) ) { unlink( $path ); return; }
    if ( is_dir( $path ) ) {
        $items = scandir( $path );
        if ( is_array( $items ) ) {
            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) { continue; }
                cs_pcr_delete_path( $path . '/' . $item );
            }
        }
        rmdir( $path );
    }
}

// ---------------------------------------------------------------------------
// Helpers — read log and cron status from server (shell_exec)
// ---------------------------------------------------------------------------

/**
 * Returns the last N lines of the watchdog log file as an array.
 *
 * Uses `shell_exec` + `tail` when available for efficiency on large log files.
 * Falls back gracefully when `shell_exec` is disabled.
 *
 * @since  1.0.0
 * @param  int $lines Number of lines to retrieve. Default 20.
 * @return string[]   Array of trimmed, non-empty log lines. Empty array if the log
 *                    is absent or shell_exec is unavailable.
 */
function cs_pcr_get_log_tail( $lines = 20 ) {
    if ( ! function_exists( 'shell_exec' ) ) { return []; }
    $log  = CS_PCR_LOG_FILE;
    $raw  = shell_exec( "tail -n {$lines} " . escapeshellarg( $log ) . ' 2>/dev/null' );
    if ( empty( $raw ) ) { return []; }
    return array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
}

// cron_installed(), watchdog_exists(), last_recovery(), last_alert() → Cloudscale_Crash_Recovery_Utils.

// ---------------------------------------------------------------------------
// Logs & Debug — helpers
// ---------------------------------------------------------------------------

define( 'CS_PCR_DEBUG_OPTION',   'cs_pcr_debug_revert_at' );
define( 'CS_PCR_DEBUG_MINUTES',  30 );
define( 'CS_PCR_DEBUG_CRON_SCRIPT', '/usr/local/bin/cs-debug-revert.sh' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-cloudscale-crash-recovery-utils.php';

// get_wp_config_path(), debug_is_active() → Cloudscale_Crash_Recovery_Utils.

/**
 * Adds or removes the CS_PCR debug block in wp-config.php.
 *
 * When enabling, inserts a clearly delimited block immediately after `<?php`
 * that sets `WP_DEBUG`, `WP_DEBUG_LOG`, and `WP_DEBUG_DISPLAY`. When
 * disabling, removes that block using a regex that matches the delimiters.
 * Uses the WP Filesystem API to read and write the file.
 *
 * @since  1.0.0
 * @param  bool            $enable True to insert the debug block, false to remove it.
 * @return true|WP_Error   True on success; WP_Error on read/write failure.
 */
function cs_pcr_patch_wp_config( $enable ) {
    $cfg = Cloudscale_Crash_Recovery_Utils::get_wp_config_path();
    if ( ! $cfg || ! is_writable( $cfg ) ) {
        return new WP_Error( 'not_writable', __( 'wp-config.php not found or not writable.', 'cloudscale-crash-recovery' ) );
    }

    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;

    $content = $wp_filesystem->get_contents( $cfg );
    if ( false === $content ) {
        return new WP_Error( 'read_fail', __( 'Could not read wp-config.php.', 'cloudscale-crash-recovery' ) );
    }

    // Remove any existing CS_PCR-managed debug block
    $content = preg_replace(
        '/\/\* CS_PCR DEBUG START \*\/.*?\/\* CS_PCR DEBUG END \*\//s',
        '',
        $content
    );
    $content = ltrim( $content, "\n" );

    if ( $enable ) {
        $block  = "/* CS_PCR DEBUG START */\n";
        $block .= "define( 'WP_DEBUG',         true );\n";
        $block .= "define( 'WP_DEBUG_LOG',     true );\n";
        $block .= "define( 'WP_DEBUG_DISPLAY', false );\n";
        $block .= "/* CS_PCR DEBUG END */\n";
        // Insert after the opening <?php line
        $content = preg_replace( '/^<\?php\s*/', "<?php\n" . $block, $content, 1 );
    }

    if ( ! $wp_filesystem->put_contents( $cfg, $content, FS_CHMOD_FILE ) ) {
        return new WP_Error( 'write_fail', __( 'Could not write wp-config.php.', 'cloudscale-crash-recovery' ) );
    }
    if ( function_exists( 'opcache_invalidate' ) ) {
        opcache_invalidate( $cfg, true );
    }
    return true;
}

/**
 * Creates a one-shot system-cron script that auto-reverts debug mode at a given time.
 *
 * Writes a bash script to CS_PCR_DEBUG_CRON_SCRIPT via a temp file and `sudo cp`,
 * then adds a `* * * * *` crontab entry for root. The script checks whether the
 * revert timestamp has passed on each tick, removes the debug block from wp-config.php
 * using `perl`, logs the action, and self-removes from crontab.
 *
 * This is a best-effort safety net — failures are silent.
 *
 * @since  1.0.0
 * @param  int  $revert_at Unix timestamp at which debug mode should be reverted.
 * @return bool            True on success, false if shell_exec is unavailable.
 */
function cs_pcr_write_debug_revert_cron( $revert_at ) {
    if ( ! function_exists( 'shell_exec' ) ) { return false; }
    $wp_path    = ABSPATH;
    $php_bin    = PHP_BINARY;
    $plugin_url = admin_url( 'admin-ajax.php' );
    $cfg        = Cloudscale_Crash_Recovery_Utils::get_wp_config_path();
    $script     = CS_PCR_DEBUG_CRON_SCRIPT;
    $log        = CS_PCR_LOG_FILE;

    // Write a one-shot revert shell script
    $sh  = "#!/bin/bash\n";
    $sh .= "# CS_PCR auto-generated debug revert script\n";
    $sh .= "REVERT_AT={$revert_at}\n";
    $sh .= "LOG_FILE=\"" . $log . "\"\n";
    $sh .= "NOW=\$(date +%s)\n";
    $sh .= "if [ \"\$NOW\" -lt \"\$REVERT_AT\" ]; then exit 0; fi\n";
    $sh .= "# Remove the debug block from wp-config.php\n";
    $sh .= "WP_CONFIG=\"" . $cfg . "\"\n";
    $sh .= "if [ -f \"\$WP_CONFIG\" ]; then\n";
    $sh .= "    perl -i -0pe 's|\/\* CS_PCR DEBUG START \*\/.*?\/\* CS_PCR DEBUG END \*\///s||sg' \"\$WP_CONFIG\"\n";
    $sh .= "    echo \"[\$(date '+%Y-%m-%d %H:%M:%S %Z')] CS_PCR: Debug mode auto-reverted by system cron.\" >> \"\$LOG_FILE\"\n";
    $sh .= "fi\n";
    $sh .= "# Self-remove from crontab\n";
    $sh .= "crontab -l 2>/dev/null | grep -v 'cs-debug-revert.sh' | crontab -\n";
    $sh .= "rm -f \"" . $script . "\"\n";
    $sh .= "exit 0\n";

    // Write the script via a temp file using WP Filesystem.
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;

    $tmp = tempnam( sys_get_temp_dir(), 'cs_pcr_' );
    $wp_filesystem->put_contents( $tmp, $sh, FS_CHMOD_FILE );
    shell_exec( 'sudo cp ' . escapeshellarg( $tmp ) . ' ' . escapeshellarg( $script ) . ' 2>/dev/null' );
    shell_exec( 'sudo chmod +x ' . escapeshellarg( $script ) . ' 2>/dev/null' );
    $wp_filesystem->delete( $tmp );

    // Add a crontab entry that fires every minute and self-removes
    $cron_line = "* * * * * " . $script;
    $existing  = shell_exec( 'sudo crontab -l 2>/dev/null' ) ?: '';
    if ( strpos( $existing, 'cs-debug-revert.sh' ) === false ) {
        $new_cron = trim( $existing ) . "\n" . $cron_line . "\n";
        $tmp2     = tempnam( sys_get_temp_dir(), 'cs_pcr_cron_' );
        $wp_filesystem->put_contents( $tmp2, $new_cron, FS_CHMOD_FILE );
        shell_exec( 'sudo crontab ' . escapeshellarg( $tmp2 ) . ' 2>/dev/null' );
        $wp_filesystem->delete( $tmp2 );
    }
    return true;
}

/**
 * WP-Cron callback: reverts debug mode when the scheduled event fires.
 *
 * Removes the CS_PCR debug block from wp-config.php, deletes the revert-at
 * option, and clears the WP-Cron event. Acts as the primary safety net;
 * the system-cron script is a secondary backup.
 *
 * @since  1.0.0
 * @return void
 */
function cs_pcr_do_revert_debug() {
    cs_pcr_patch_wp_config( false );
    delete_option( CS_PCR_DEBUG_OPTION );
    wp_clear_scheduled_hook( 'cs_pcr_revert_debug_hook' );
}

// ---------------------------------------------------------------------------
// AJAX — get aggregated logs (last 24 h)
// ---------------------------------------------------------------------------

/**
 * AJAX handler: returns aggregated log entries from all available sources.
 *
 * Reads the last 2 000 lines from each known log file (watchdog, wp-content/debug.log,
 * PHP error log, Apache/Nginx error logs), filters to the last 24 hours, merges,
 * sorts descending by timestamp, and returns up to 500 entries as JSON.
 *
 * Requires nonce `cs_pcr_checks` and capability `manage_options`.
 *
 * @since  1.0.0
 * @return void Exits via wp_send_json_success().
 */
function cs_pcr_ajax_get_logs() {
    check_ajax_referer( 'cs_pcr_checks', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden.', 'cloudscale-crash-recovery' ) ); }

    $cutoff   = time() - 86400; // 24 hours ago
    $entries  = [];

    $sources = [
        'watchdog'  => CS_PCR_LOG_FILE,
        'wp_debug'  => WP_CONTENT_DIR . '/debug.log',
        'php_error' => (string) ini_get( 'error_log' ),
        'apache'    => '/var/log/httpd/error_log',
        'apache2'   => '/var/log/apache2/error.log',
        'nginx'     => '/var/log/nginx/error.log',
    ];

    foreach ( $sources as $label => $path ) {
        if ( empty( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) { continue; }
        $lines = cs_pcr_read_log_last_24h( $path, $cutoff );
        foreach ( $lines as $line ) {
            $ts      = Cloudscale_Crash_Recovery_Utils::parse_log_timestamp( $line );
            $entries[] = [
                'source'    => $label,
                'path'      => $path,
                'line'      => $line,
                'timestamp' => $ts,
                'level'     => Cloudscale_Crash_Recovery_Utils::detect_level( $line ),
            ];
        }
    }

    // Sort by timestamp descending (undated entries go to bottom)
    usort( $entries, function( $a, $b ) {
        if ( $a['timestamp'] === 0 && $b['timestamp'] === 0 ) { return 0; }
        if ( $a['timestamp'] === 0 ) { return 1; }
        if ( $b['timestamp'] === 0 ) { return -1; }
        return $b['timestamp'] - $a['timestamp'];
    });

    $sources_found = [];
    foreach ( $sources as $label => $path ) {
        if ( ! empty( $path ) && file_exists( $path ) && is_readable( $path ) ) {
            $sources_found[ $label ] = $path;
        }
    }

    wp_send_json_success([
        'entries'       => array_slice( $entries, 0, 500 ),
        'total'         => count( $entries ),
        'sources_found' => $sources_found,
        'cutoff'        => $cutoff,
        'generated_at'  => time(),
    ]);
}

/**
 * Reads log lines from a file that fall within the last 24 hours.
 *
 * Reads up to 2 000 lines from the end of the file (using `tail` when available),
 * then discards lines whose parsed timestamp predates `$cutoff`. Lines with no
 * recognisable timestamp are included (timestamp 0).
 *
 * @since  1.0.0
 * @param  string $path   Absolute path to the log file.
 * @param  int    $cutoff Unix timestamp; lines older than this are dropped.
 * @return string[]       Array of matching log lines.
 */
function cs_pcr_read_log_last_24h( $path, $cutoff ) {
    // Read the last 2000 lines; filter to those within 24h
    if ( ! function_exists( 'shell_exec' ) ) {
        $raw = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! is_array( $raw ) ) { return []; }
        $lines = array_slice( $raw, -2000 );
    } else {
        $raw = shell_exec( 'tail -n 2000 ' . escapeshellarg( $path ) . ' 2>/dev/null' );
        if ( empty( $raw ) ) { return []; }
        $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
    }
    $result = [];
    foreach ( $lines as $line ) {
        if ( empty( $line ) ) { continue; }
        $ts = Cloudscale_Crash_Recovery_Utils::parse_log_timestamp( $line );
        if ( $ts === 0 || $ts >= $cutoff ) {
            $result[] = $line;
        }
    }
    return $result;
}

// cs_pcr_parse_log_timestamp() and cs_pcr_detect_level() have been moved
// to Cloudscale_Crash_Recovery_Utils.

// ---------------------------------------------------------------------------
// AJAX — enable debug mode (30 minutes)
// ---------------------------------------------------------------------------

/**
 * AJAX handler: enables WordPress debug mode for 30 minutes.
 *
 * Inserts WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY=false into wp-config.php,
 * records the revert timestamp in the options table, schedules a WP-Cron safety-net
 * event, and creates a system-cron one-shot revert script as a secondary backup.
 *
 * Requires nonce `cs_pcr_checks` and capability `manage_options`.
 *
 * @since  1.0.0
 * @return void Exits via wp_send_json_success() or wp_send_json_error().
 */
function cs_pcr_ajax_enable_debug() {
    check_ajax_referer( 'cs_pcr_checks', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden.', 'cloudscale-crash-recovery' ) ); }

    $revert_at = time() + ( CS_PCR_DEBUG_MINUTES * 60 );
    $result    = cs_pcr_patch_wp_config( true );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error([ 'message' => $result->get_error_message() ]);
    }

    update_option( CS_PCR_DEBUG_OPTION, $revert_at, false );

    // WP-Cron safety net
    wp_clear_scheduled_hook( 'cs_pcr_revert_debug_hook' );
    wp_schedule_single_event( $revert_at, 'cs_pcr_revert_debug_hook' );

    // System cron safety net (best effort — silent on failure)
    cs_pcr_write_debug_revert_cron( $revert_at );

    wp_send_json_success([
        'revert_at'      => $revert_at,
        'debug_log_path' => WP_CONTENT_DIR . '/debug.log',
    ]);
}

// ---------------------------------------------------------------------------
// AJAX — disable debug mode immediately
// ---------------------------------------------------------------------------

/**
 * AJAX handler: immediately reverts WordPress debug mode.
 *
 * Removes the CS_PCR debug block from wp-config.php, deletes the revert-at option,
 * clears the WP-Cron safety-net event, and removes the system-cron one-shot script
 * and crontab entry if they exist.
 *
 * Requires nonce `cs_pcr_checks` and capability `manage_options`.
 *
 * @since  1.0.0
 * @return void Exits via wp_send_json_success() or wp_send_json_error().
 */
function cs_pcr_ajax_disable_debug() {
    check_ajax_referer( 'cs_pcr_checks', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden.', 'cloudscale-crash-recovery' ) ); }

    $result = cs_pcr_patch_wp_config( false );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error([ 'message' => $result->get_error_message() ]);
    }

    delete_option( CS_PCR_DEBUG_OPTION );
    wp_clear_scheduled_hook( 'cs_pcr_revert_debug_hook' );

    // Remove system cron entry if present
    if ( function_exists( 'shell_exec' ) ) {
        shell_exec( 'sudo crontab -l 2>/dev/null | grep -v "cs-debug-revert.sh" | sudo crontab - 2>/dev/null' );
        shell_exec( 'sudo rm -f ' . escapeshellarg( CS_PCR_DEBUG_CRON_SCRIPT ) . ' 2>/dev/null' );
    }

    wp_send_json_success( [ 'message' => __( 'Debug mode disabled.', 'cloudscale-crash-recovery' ) ] );
}

// ---------------------------------------------------------------------------
// AJAX: live config check (writability — never cached)
// ---------------------------------------------------------------------------

/**
 * AJAX handler: live-checks whether wp-config.php is writable.
 *
 * Never cached — called from JS when the Logs tab is activated so the
 * Enable Debug button reflects the current filesystem state.
 *
 * Requires nonce `cs_pcr_checks` and capability `manage_options`.
 *
 * @since  1.0.0
 * @return void Exits via wp_send_json_success().
 */
function cs_pcr_ajax_check_config() {
    check_ajax_referer( 'cs_pcr_checks', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden.', 'cloudscale-crash-recovery' ) ); }

    $path     = Cloudscale_Crash_Recovery_Utils::get_wp_config_path();
    $writable = $path && is_writable( $path );

    wp_send_json_success([
        'found'    => (bool) $path,
        'writable' => $writable,
        'path'     => $path ?: '',
    ]);
}

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------

/**
 * Registers the plugin's admin menu page under Tools.
 *
 * @since  1.0.0
 * @return void
 */
function cs_pcr_add_menu() {
    add_management_page(
        'CloudScale Crash Recovery',
        'Crash Recovery',
        'manage_options',
        CS_PCR_SLUG,
        'cs_pcr_render_page'
    );
}

// ---------------------------------------------------------------------------
// Enqueue assets
// ---------------------------------------------------------------------------

/**
 * Enqueues the plugin's CSS and JS only on its own admin page.
 *
 * Version strings combine CS_PCR_VERSION with the asset's file mtime so that
 * Cloudflare cache-busts automatically on every deploy without a manual purge.
 * PHP data is passed to JS via wp_localize_script() — no inline JSON.
 *
 * @since  1.0.0
 * @param  string $hook The current admin page hook suffix.
 * @return void
 */
function cs_pcr_enqueue_assets( $hook ) {
    // 'tools_page_' is the prefix WordPress assigns to pages registered under add_management_page().
    if ( 'tools_page_' . CS_PCR_SLUG !== $hook ) { return; }
    // Use file modification time as the version so the URL changes on every
    // deploy. Cloudflare caches by full URL including ?ver=, so a changed
    // mtime forces a cache miss without needing a manual purge.
    $css_path = plugin_dir_path( __FILE__ ) . 'admin.css';
    $js_path  = plugin_dir_path( __FILE__ ) . 'admin.js';
    $css_ver  = CS_PCR_VERSION . '.' . ( file_exists( $css_path ) ? filemtime( $css_path ) : '0' );
    $js_ver   = CS_PCR_VERSION . '.' . ( file_exists( $js_path )  ? filemtime( $js_path )  : '0' );
    wp_enqueue_style(  'cs-pcr-admin', plugin_dir_url( __FILE__ ) . 'admin.css', [], $css_ver );
    wp_enqueue_script( 'cs-pcr-admin', plugin_dir_url( __FILE__ ) . 'admin.js',  [ 'jquery' ], $js_ver, true );
    wp_localize_script( 'cs-pcr-admin', 'CS_PCR', [
        'ajax_url'       => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'cs_pcr_checks' ),
        'debug_active'   => Cloudscale_Crash_Recovery_Utils::debug_is_active() ? 1 : 0,
        'debug_revert_at' => (int) get_option( CS_PCR_DEBUG_OPTION, 0 ),
    ] );
}

// ---------------------------------------------------------------------------
// AJAX — run compatibility checks
// ---------------------------------------------------------------------------

/**
 * AJAX handler: runs all 10 server-compatibility checks and returns results as JSON.
 *
 * Checks: PHP CLI, shell_exec, curl binary, probe endpoint, plugin-directory
 * permissions, WP-CLI, watchdog script, system-cron entry, log file, and legacy
 * WP-Cron entry. Each result is built via
 * {@see Cloudscale_Crash_Recovery_Utils::build_check()}.
 *
 * Requires nonce `cs_pcr_checks` and capability `manage_options`.
 *
 * @since  1.0.0
 * @return void Exits via wp_send_json_success().
 */
function cs_pcr_ajax_run_checks() {
    check_ajax_referer( 'cs_pcr_checks', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Forbidden.', 'cloudscale-crash-recovery' ) ); }

    $results = [];

    // 1. PHP CLI
    $php_bin  = PHP_BINARY;
    $php_test = shell_exec( escapeshellcmd( $php_bin ) . ' -r "echo \'OK\';" 2>&1' );
    $results[] = Cloudscale_Crash_Recovery_Utils::build_check('PHP CLI',
        strpos( (string)$php_test, 'OK' ) !== false,
        'PHP CLI available at ' . $php_bin,
        'PHP CLI not available or shell_exec disabled.',
        $php_bin );

    // 2. shell_exec
    $disabled  = array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) );
    $results[] = Cloudscale_Crash_Recovery_Utils::build_check('shell_exec enabled',
        ! in_array( 'shell_exec', $disabled, true ),
        'shell_exec is available.',
        'shell_exec is disabled in php.ini. The admin UI checks use it; system cron is unaffected.',
        null, 'warning' );

    // 3. curl binary
    $curl_path = trim( (string)shell_exec( 'which curl 2>/dev/null' ) );
    $results[] = Cloudscale_Crash_Recovery_Utils::build_check('curl binary',
        ! empty( $curl_path ),
        'curl found at ' . $curl_path,
        'curl not found. Run: sudo yum install curl',
        $curl_path );

    // 4. Probe endpoint
    $probe_url = add_query_arg( [ CS_PCR_PROBE_KEY => 1, 't' => time() ], home_url( '/' ) );
    $resp      = wp_remote_get( $probe_url, [ 'timeout' => 8, 'sslverify' => false, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
    $probe_ok  = ! is_wp_error( $resp )
                 && wp_remote_retrieve_response_code( $resp ) === 200
                 && strpos( wp_remote_retrieve_body( $resp ), CS_PCR_OK_BODY ) !== false;
    $results[] = Cloudscale_Crash_Recovery_Utils::build_check('Probe endpoint',
        $probe_ok,
        'Probe responded with CLOUDSCALE_OK.',
        'Probe did not return the expected response. Check the plugin is active and the site loads at ' . home_url( '/' ),
        $probe_url );

    // 5. Plugin directory writable
    $plugin_dir = WP_PLUGIN_DIR;
    $results[]  = Cloudscale_Crash_Recovery_Utils::build_check('Plugin directory writable',
        is_writable( $plugin_dir ),
        $plugin_dir . ' is writable.',
        $plugin_dir . ' is not writable by the web process.',
        $plugin_dir );

    // 6. WP-CLI
    $wpcli_path = trim( (string)shell_exec( 'which wp 2>/dev/null' ) );
    if ( empty( $wpcli_path ) && file_exists( '/usr/local/bin/wp' ) ) { $wpcli_path = '/usr/local/bin/wp'; }
    $results[]  = Cloudscale_Crash_Recovery_Utils::build_check('WP-CLI',
        ! empty( $wpcli_path ),
        'WP-CLI found at ' . $wpcli_path,
        'WP-CLI not found. The watchdog will fall back to direct file deletion. Install from https://wp-cli.org/',
        $wpcli_path,
        empty( $wpcli_path ) ? 'warning' : 'pass' );

    // 7. Watchdog script on disk
    $wd_exists = Cloudscale_Crash_Recovery_Utils::watchdog_exists();
    $results[] = Cloudscale_Crash_Recovery_Utils::build_check('Watchdog script',
        $wd_exists,
        CS_PCR_WATCHDOG . ' exists and is executable.',
        CS_PCR_WATCHDOG . ' not found or not executable. Deploy it from the System Cron Setup tab.',
        CS_PCR_WATCHDOG );

    // 8. System cron entry installed
    $cron_installed = Cloudscale_Crash_Recovery_Utils::cron_installed();
    if ( $cron_installed === null ) {
        $results[] = Cloudscale_Crash_Recovery_Utils::build_check('System cron entry', false,
            '', 'Could not read root crontab (shell_exec may be disabled).',
            null, 'warning' );
    } else {
        $results[] = Cloudscale_Crash_Recovery_Utils::build_check('System cron entry',
            $cron_installed,
            'Cron entry found in root crontab. Watchdog fires every minute.',
            'Cron entry not found. Add: * * * * * /usr/local/bin/cs-crash-watchdog.sh',
            null );
    }

    // 9. Log file exists and writable
    $log_exists   = file_exists( CS_PCR_LOG_FILE );
    $log_writable = $log_exists && is_writable( CS_PCR_LOG_FILE );
    $results[]    = Cloudscale_Crash_Recovery_Utils::build_check('Log file',
        $log_writable,
        CS_PCR_LOG_FILE . ' exists and is writable.',
        $log_exists
            ? CS_PCR_LOG_FILE . ' exists but is not writable. Run: sudo chmod 664 ' . CS_PCR_LOG_FILE
            : CS_PCR_LOG_FILE . ' does not exist. Run: sudo touch ' . CS_PCR_LOG_FILE . ' && sudo chmod 664 ' . CS_PCR_LOG_FILE,
        CS_PCR_LOG_FILE );

    // 10. Legacy WP cron
    $next_wpcron = wp_next_scheduled( 'cs_pcr_watchdog_tick' );
    $results[]   = Cloudscale_Crash_Recovery_Utils::build_check('Legacy WP cron',
        ! $next_wpcron,
        'No legacy WP cron entry. Clean slate.',
        'Legacy WP cron entry found (next: ' . wp_date( 'H:i:s', (int) $next_wpcron ) . '). Safe to ignore — system cron takes precedence.',
        null,
        $next_wpcron ? 'warning' : 'pass' );

    $failures = array_filter( $results, fn( $r ) => $r['status'] === 'fail' );
    $warnings = array_filter( $results, fn( $r ) => $r['status'] === 'warning' );

    wp_send_json_success( [
        'checks'    => $results,
        'ready'     => empty( $failures ),
        'failures'  => count( $failures ),
        'warnings'  => count( $warnings ),
        'probe_url' => $probe_url,
    ] );
}

// cs_pcr_check() has been moved to Cloudscale_Crash_Recovery_Utils::build_check().

// ---------------------------------------------------------------------------
// Admin page
// ---------------------------------------------------------------------------

/**
 * Renders the full plugin admin page.
 *
 * Outputs four tabs: Compatibility Checks, System Cron Setup, Status & Log,
 * and Logs & Debug. Status data is read once at the top of the function and
 * passed into the template. The Compatibility Checks tab results are loaded
 * via AJAX to avoid slowing the initial page render.
 *
 * @since  1.0.0
 * @return void
 */
function cs_pcr_render_page() {
    $probe_url  = add_query_arg( [ CS_PCR_PROBE_KEY => 1 ], home_url( '/' ) );
    $php_bin    = PHP_BINARY;
    $plugin_dir = WP_PLUGIN_DIR;

    // Status tab data — read once
    $log_lines      = cs_pcr_get_log_tail( 30 );
    $cron_installed = Cloudscale_Crash_Recovery_Utils::cron_installed();
    $wd_exists      = Cloudscale_Crash_Recovery_Utils::watchdog_exists();
    $legacy_cron    = wp_next_scheduled( 'cs_pcr_watchdog_tick' );
    $wpcli_bin      = trim( (string)shell_exec( 'which wp 2>/dev/null' ) ?: '' );
    $curl_bin       = trim( (string)shell_exec( 'which curl 2>/dev/null' ) ?: '' );
    $last_recovery  = Cloudscale_Crash_Recovery_Utils::last_recovery( $log_lines );
    $last_alert     = Cloudscale_Crash_Recovery_Utils::last_alert( $log_lines );
    $log_size       = file_exists( CS_PCR_LOG_FILE ) ? round( filesize( CS_PCR_LOG_FILE ) / 1024, 1 ) . ' KB' : 'not found';

    // Logs & Debug tab data
    $debug_active    = Cloudscale_Crash_Recovery_Utils::debug_is_active();
    $debug_revert_at = (int) get_option( CS_PCR_DEBUG_OPTION, 0 );
    $cfg_path        = Cloudscale_Crash_Recovery_Utils::get_wp_config_path();
    $cfg_writable    = $cfg_path && is_writable( $cfg_path );
    $debug_log_path  = WP_CONTENT_DIR . '/debug.log';
    $debug_log_size  = file_exists( $debug_log_path ) ? round( filesize( $debug_log_path ) / 1024, 1 ) . ' KB' : 'not found';
    ?>
    <div class="cs-pcr-wrap">

        <div class="cs-pcr-header">
            <div class="cs-pcr-header-inner">
                <div class="cs-pcr-header-title">
                    <span class="cs-pcr-logo">🛡️</span>
                    <div>
                        <h1>CloudScale Crash Recovery</h1>
                        <p>System-cron watchdog — probes every minute, removes the culprit plugin automatically</p>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <a href="https://andrewbaker.ninja/2026/03/02/you-just-uploaded-a-new-plugin-and-your-wordpress-site-just-crashed-now-what/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:6px 16px;background:#f57c00!important;color:#fff!important;font-size:0.8rem;font-weight:700;border-radius:20px;text-decoration:none!important;border:1px solid #e65100!important;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ffcc80;box-shadow:0 0 5px #ffcc80;flex-shrink:0;"></span>andrewbaker.ninja</a>
                    <span class="cs-pcr-version">v<?php echo esc_html( CS_PCR_VERSION ); ?></span>
                </div>
            </div>
        </div>

        <div class="cs-pcr-tabs">
            <button type="button" class="cs-pcr-tab active" data-tab="checks">Compatibility Checks</button>
            <button type="button" class="cs-pcr-tab" data-tab="setup">System Cron Setup</button>
            <button type="button" class="cs-pcr-tab" data-tab="status">Status &amp; Log</button>
            <button type="button" class="cs-pcr-tab" data-tab="logs">Logs &amp; Debug</button>
        </div>

        <!-- Tab: Compatibility Checks -->
        <div class="cs-pcr-tab-content active" id="cs-pcr-tab-checks">
            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-blue">
                    <span>Instance Compatibility Check</span>
                    <button type="button" class="cs-pcr-btn cs-pcr-btn-explain"
                        data-title="Compatibility Check"
                        data-body="Runs 10 server-side checks to confirm your instance is ready for the system cron watchdog: PHP CLI, shell_exec, curl, the probe endpoint, plugin directory permissions, WP-CLI, watchdog script presence, cron entry, log file, and legacy WP cron. Critical failures must be resolved before the watchdog can protect the site.">
                        Explain
                    </button>
                </div>
                <div class="cs-pcr-card-body">
                    <p>Run these checks to confirm your server is compatible. Critical failures must be resolved. Warnings are advisory.</p>
                    <div class="cs-pcr-button-row">
                        <button type="button" class="cs-pcr-btn cs-pcr-btn-primary" id="cs-pcr-run-checks">▶ Run Compatibility Checks</button>
                    </div>
                    <div id="cs-pcr-checks-spinner" style="display:none; margin-top:16px;">
                        <span class="cs-pcr-spinner"></span> Running checks&hellip;
                    </div>
                    <div id="cs-pcr-checks-output" style="margin-top:20px; display:none;">
                        <div id="cs-pcr-checks-summary"></div>
                        <table class="cs-pcr-checks-table">
                            <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
                            <tbody id="cs-pcr-checks-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: System Cron Setup -->
        <div class="cs-pcr-tab-content" id="cs-pcr-tab-setup">
            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-teal">
                    <span>1. Watchdog Script</span>
                    <button type="button" class="cs-pcr-btn cs-pcr-btn-explain"
                        data-title="Watchdog Script"
                        data-body="This bash script runs every minute via system cron, independently of WordPress. It probes the health endpoint with curl. On failure it identifies the most recently modified plugin within the 10-minute window, deactivates it via WP-CLI, and deletes its directory. All actions are logged to /var/log/cloudscale-crash-recovery.log. The script exits silently on a healthy probe — the log only fills on crash events.">
                        Explain
                    </button>
                </div>
                <div class="cs-pcr-card-body">
                    <p>Deploy to <code>/usr/local/bin/cs-crash-watchdog.sh</code> and make it executable.</p>
                    <div class="cs-pcr-terminal-wrap">
                        <div class="cs-pcr-terminal-header">
                            <span class="cs-pcr-terminal-dot"></span>
                            <span class="cs-pcr-terminal-label">cs-crash-watchdog.sh</span>
                        </div>
                        <pre class="cs-pcr-terminal" id="cs-pcr-watchdog-script">#!/bin/bash
# CloudScale Crash Recovery — System Cron Watchdog v1.4.7
# Deploy to: /usr/local/bin/cs-crash-watchdog.sh
# Permissions: chmod +x /usr/local/bin/cs-crash-watchdog.sh
# Cron (root): * * * * * /usr/local/bin/cs-crash-watchdog.sh

PROBE_URL="<?php echo esc_url( $probe_url ); ?>"
WP_PATH="<?php echo esc_html( ABSPATH ); ?>"
PLUGIN_DIR="<?php echo esc_html( $plugin_dir ); ?>"
LOG_FILE="/var/log/cloudscale-crash-recovery.log"
WINDOW_SECONDS=600
SELF_PLUGIN="cloudscale-plugin-crash-recovery"
WP_CLI="/usr/local/bin/wp"

timestamp() { date '+%Y-%m-%d %H:%M:%S %Z'; }
log() { echo "[$(timestamp)] $1" >> "$LOG_FILE"; }

HTTP_CODE=$(curl -s -o /tmp/cs_pcr_body.txt -w "%{http_code}" \
    --max-time 8 --no-keepalive \
    -H "Cache-Control: no-cache" \
    "${PROBE_URL}&t=$(date +%s)" 2>/dev/null)
BODY=$(cat /tmp/cs_pcr_body.txt 2>/dev/null)

if [ "$HTTP_CODE" = "200" ] && echo "$BODY" | grep -q "CLOUDSCALE_OK"; then
    exit 0
fi

log "ALERT: Probe failed (HTTP ${HTTP_CODE}). Initiating recovery."

NOW=$(date +%s)
NEWEST_DIR=""
NEWEST_MTIME=0

for PLUGIN_FOLDER in "$PLUGIN_DIR"/*/; do
    PLUGIN_BASENAME=$(basename "$PLUGIN_FOLDER")
    [ "$PLUGIN_BASENAME" = "$SELF_PLUGIN" ] && continue
    MAIN_PHP="${PLUGIN_FOLDER}${PLUGIN_BASENAME}.php"
    [ ! -f "$MAIN_PHP" ] && MAIN_PHP=$(ls "${PLUGIN_FOLDER}"*.php 2>/dev/null | head -1)
    [ -z "$MAIN_PHP" ] || [ ! -f "$MAIN_PHP" ] && continue
    MTIME=$(stat -c %Y "$MAIN_PHP" 2>/dev/null)
    [ -z "$MTIME" ] && continue
    if [ "$MTIME" -gt "$NEWEST_MTIME" ]; then
        NEWEST_MTIME=$MTIME
        NEWEST_DIR="$PLUGIN_FOLDER"
    fi
done

if [ -z "$NEWEST_DIR" ]; then
    log "No candidate plugin found. Manual intervention required."
    exit 1
fi

AGE=$(( NOW - NEWEST_MTIME ))
if [ "$AGE" -gt "$WINDOW_SECONDS" ]; then
    log "Most-recent plugin is ${AGE}s old (outside 10-min window). No action taken."
    exit 1
fi

PLUGIN_NAME=$(basename "$NEWEST_DIR")
log "Target: ${PLUGIN_NAME} (modified ${AGE}s ago). Proceeding."

if [ -x "$WP_CLI" ]; then
    "$WP_CLI" plugin deactivate "$PLUGIN_NAME" --path="$WP_PATH" --allow-root >> "$LOG_FILE" 2>/dev/null
    log "WP-CLI deactivate complete."
fi

rm -rf "$NEWEST_DIR"

if [ ! -d "$NEWEST_DIR" ]; then
    log "SUCCESS: Removed ${PLUGIN_NAME}. Site should recover on next request."
else
    log "ERROR: Could not remove ${NEWEST_DIR}. Check permissions."
    exit 1
fi

exit 0</pre>
                    </div>
                    <div class="cs-pcr-button-row" style="margin-top:14px;">
                        <button type="button" class="cs-pcr-btn cs-pcr-btn-secondary" id="cs-pcr-copy-script">📋 Copy Script</button>
                    </div>
                </div>
            </div>

            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-purple">
                    <span>2. Cron Entry</span>
                    <button type="button" class="cs-pcr-btn cs-pcr-btn-explain"
                        data-title="Cron Entry"
                        data-body="Add this line to root's crontab (sudo crontab -e). The watchdog runs every minute at the OS level, completely independent of WordPress. Even if the site is white-screening, this cron fires. The log file must exist and be writable before the first run.">
                        Explain
                    </button>
                </div>
                <div class="cs-pcr-card-body">
                    <p>Add to root crontab via <code>sudo crontab -e</code>:</p>
                    <div class="cs-pcr-terminal-wrap">
                        <div class="cs-pcr-terminal-header">
                            <span class="cs-pcr-terminal-dot"></span>
                            <span class="cs-pcr-terminal-label">crontab entry</span>
                        </div>
                        <pre class="cs-pcr-terminal" id="cs-pcr-cron-line">* * * * * /usr/local/bin/cs-crash-watchdog.sh</pre>
                    </div>
                    <div class="cs-pcr-button-row" style="margin-top:14px;">
                        <button type="button" class="cs-pcr-btn cs-pcr-btn-secondary" id="cs-pcr-copy-cron">📋 Copy Cron Line</button>
                    </div>
                    <p class="cs-pcr-note" style="margin-top:14px;">Create the log file first if it does not exist:<br>
                    <code>sudo touch /var/log/cloudscale-crash-recovery.log &amp;&amp; sudo chmod 664 /var/log/cloudscale-crash-recovery.log</code></p>
                </div>
            </div>
        </div>

        <!-- Tab: Status & Log -->
        <div class="cs-pcr-tab-content" id="cs-pcr-tab-status">

            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-green">
                    <span>Watchdog Status</span>
                    <button type="button" class="cs-pcr-btn cs-pcr-btn-explain"
                        data-title="Watchdog Status"
                        data-body="Shows whether the watchdog script is deployed, whether the system cron entry is installed in root's crontab, and key path information. The watchdog logs nothing on a healthy probe — entries only appear when a crash is detected or a recovery action is taken.">
                        Explain
                    </button>
                </div>
                <div class="cs-pcr-card-body">
                    <table class="cs-pcr-status-table">
                        <tr>
                            <td>Plugin version</td>
                            <td><span class="cs-pcr-badge cs-pcr-badge-blue"><?php echo esc_html( CS_PCR_VERSION ); ?></span></td>
                        </tr>
                        <tr>
                            <td>Watchdog script</td>
                            <td>
                                <?php if ( $wd_exists ) : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-green">✅ Deployed</span>
                                    <code style="margin-left:8px;font-size:12px;"><?php echo esc_html( CS_PCR_WATCHDOG ); ?></code>
                                <?php else : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-red">❌ Not found</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>System cron entry</td>
                            <td>
                                <?php if ( $cron_installed === true ) : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-green">✅ Installed</span>
                                <?php elseif ( $cron_installed === false ) : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-red">❌ Not installed</span>
                                <?php else : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-amber">⚠️ Cannot read crontab</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Last recovery action</td>
                            <td><?php echo $last_recovery ? '<code style="font-size:12px;">' . esc_html( $last_recovery ) . '</code>' : '<span class="cs-pcr-badge cs-pcr-badge-green">None on record</span>'; ?></td>
                        </tr>
                        <tr>
                            <td>Last alert</td>
                            <td><?php echo $last_alert ? '<code style="font-size:12px;color:#c0392b;">' . esc_html( $last_alert ) . '</code>' : '<span class="cs-pcr-badge cs-pcr-badge-green">None on record</span>'; ?></td>
                        </tr>
                        <tr>
                            <td>Log file</td>
                            <td>
                                <code><?php echo esc_html( CS_PCR_LOG_FILE ); ?></code>
                                <span style="margin-left:8px;font-size:12px;color:#6b7690;"><?php echo esc_html( $log_size ); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td>Probe URL</td>
                            <td><a href="<?php echo esc_url( $probe_url ); ?>" target="_blank" rel="noopener" style="font-size:12px;"><?php echo esc_html( $probe_url ); ?></a></td>
                        </tr>
                        <tr>
                            <td>WP-CLI</td>
                            <td><?php echo $wpcli_bin ? '<code>' . esc_html( $wpcli_bin ) . '</code>' : '<span class="cs-pcr-badge cs-pcr-badge-amber">Not found</span>'; ?></td>
                        </tr>
                        <tr>
                            <td>curl</td>
                            <td><?php echo $curl_bin ? '<code>' . esc_html( $curl_bin ) . '</code>' : '<span class="cs-pcr-badge cs-pcr-badge-red">Not found</span>'; ?></td>
                        </tr>
                        <tr>
                            <td>Legacy WP cron</td>
                            <td>
                                <?php if ( $legacy_cron ) : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-amber">Active — next: <?php echo esc_html( wp_date( 'H:i:s', $legacy_cron ) ); ?></span>
                                <?php else : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-green">None (correct)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Plugin directory</td>
                            <td>
                                <code><?php echo esc_html( WP_PLUGIN_DIR ); ?></code>
                                <?php if ( is_writable( WP_PLUGIN_DIR ) ) : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-green" style="margin-left:8px;">writable</span>
                                <?php else : ?>
                                    <span class="cs-pcr-badge cs-pcr-badge-red" style="margin-left:8px;">not writable</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php if ( ! empty( $log_lines ) ) : ?>
            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-blue">
                    <span>Recent Log Entries</span>
                </div>
                <div class="cs-pcr-card-body" style="padding:0;">
                    <div class="cs-pcr-terminal-wrap">
                        <pre class="cs-pcr-terminal" style="border-radius:0 0 8px 8px;"><?php
                            foreach ( $log_lines as $line ) {
                                $class = 'cs-pcr-log-normal';
                                if ( strpos( $line, 'SUCCESS' ) !== false ) { $class = 'cs-pcr-log-success'; }
                                elseif ( strpos( $line, 'ERROR' ) !== false || strpos( $line, 'ALERT' ) !== false ) { $class = 'cs-pcr-log-alert'; }
                                elseif ( strpos( $line, 'Target:' ) !== false || strpos( $line, 'Removed' ) !== false ) { $class = 'cs-pcr-log-action'; }
                                echo '<span class="' . $class . '">' . esc_html( $line ) . '</span>' . "\n";
                            }
                        ?></pre>
                    </div>
                </div>
            </div>
            <?php else : ?>
            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-blue"><span>Recent Log Entries</span></div>
                <div class="cs-pcr-card-body">
                    <p style="color:#6b7690;margin:0;">Log is empty — the watchdog only writes when a crash is detected. This is normal on a healthy site.</p>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- Tab: Logs & Debug -->
        <div class="cs-pcr-tab-content" id="cs-pcr-tab-logs">

            <!-- Debug Mode Control -->
            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-orange">
                    <span>WordPress Debug Mode</span>
                    <button type="button" class="cs-pcr-btn cs-pcr-btn-explain"
                        data-title="WordPress Debug Mode"
                        data-body="Temporarily enables WP_DEBUG, WP_DEBUG_LOG, and WP_DEBUG_DISPLAY=false in wp-config.php for exactly 30 minutes. Debug output is written to wp-content/debug.log only — it is never shown on screen. After 30 minutes the changes are automatically reverted by both WP-Cron and a system cron one-shot script, so the revert is guaranteed even if WordPress crashes during the debug window. You can also revert immediately at any time.">
                        Explain
                    </button>
                </div>
                <div class="cs-pcr-card-body">
                    <div class="cs-pcr-debug-status-row">
                        <div class="cs-pcr-debug-status-info">
                            <?php if ( $debug_active ) : ?>
                                <span class="cs-pcr-badge cs-pcr-badge-red cs-pcr-badge-lg">&#128308; DEBUG ACTIVE</span>
                                <span class="cs-pcr-debug-countdown-label">Auto-reverts in <strong id="cs-pcr-countdown">...</strong></span>
                                <span class="cs-pcr-debug-revert-time" id="cs-pcr-revert-time">at ...</span>
                            <?php else : ?>
                                <span class="cs-pcr-badge cs-pcr-badge-green cs-pcr-badge-lg">&#128994; DEBUG OFF</span>
                                <span class="cs-pcr-debug-countdown-label" style="color:#6b7690;">WordPress is running in normal mode</span>
                            <?php endif; ?>
                        </div>
                        <div class="cs-pcr-debug-btn-row">
                            <?php if ( $debug_active ) : ?>
                                <button type="button" class="cs-pcr-btn cs-pcr-btn-danger" id="cs-pcr-disable-debug">&#9209; Revert Debug Now</button>
                            <?php else : ?>
                                <button type="button" class="cs-pcr-btn cs-pcr-btn-warn" id="cs-pcr-enable-debug"
                                    <?php echo $cfg_writable ? '' : 'disabled title="wp-config.php is not writable"'; ?>>
                                    &#9654; Enable Debug (30 min)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                        <p id="cs-pcr-cfg-warn" class="cs-pcr-note" style="margin-top:12px;<?php echo $cfg_writable ? 'display:none;' : ''; ?>">&#9888;&#65039; <strong>wp-config.php is not writable</strong> by the web process.<br>If root owns the file: <code>sudo chown apache:apache <?php echo esc_html( $cfg_path ?: ABSPATH . 'wp-config.php' ); ?></code><br>If permissions are wrong: <code>sudo chmod 664 <?php echo esc_html( $cfg_path ?: ABSPATH . 'wp-config.php' ); ?></code><br>On managed hosts, contact your host to allow PHP to write wp-config.php.</p>
                    <table class="cs-pcr-status-table" style="margin-top:14px;">
                        <tr><td>wp-config.php</td><td><code><?php echo esc_html( $cfg_path ?: 'Not found' ); ?></code> <?php echo $cfg_writable ? '<span id="cs-pcr-cfg-badge" class="cs-pcr-badge cs-pcr-badge-green">writable</span>' : '<span id="cs-pcr-cfg-badge" class="cs-pcr-badge cs-pcr-badge-red">' . ( $cfg_path ? 'not writable' : 'Not found' ) . '</span>'; ?></td></tr>
                        <tr><td>debug.log path</td><td><code><?php echo esc_html( $debug_log_path ); ?></code> <span style="font-size:12px;color:#6b7690;"><?php echo esc_html( $debug_log_size ); ?></span></td></tr>
                        <tr><td>WP_DEBUG_DISPLAY</td><td><span class="cs-pcr-badge cs-pcr-badge-green">Always forced OFF</span> <span style="font-size:12px;color:#6b7690;">errors never shown on screen</span></td></tr>
                    </table>
                    <div id="cs-pcr-debug-message" style="margin-top:12px;display:none;"></div>
                </div>
            </div>

            <!-- Unified Log Viewer -->
            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-blue">
                    <span>Unified Log Viewer <span id="cs-pcr-log-entry-count" class="cs-pcr-log-count-badge"></span></span>
                    <div class="cs-pcr-header-actions">
                        <button type="button" class="cs-pcr-btn cs-pcr-btn-load-logs" id="cs-pcr-load-logs">&#128203; Load Logs</button>
                        <button type="button" class="cs-pcr-btn cs-pcr-btn-explain"
                            data-title="Unified Log Viewer"
                            data-body="Aggregates the last 24 hours of entries from all available log sources: the CloudScale watchdog log, WordPress debug.log, the PHP error log, and the Apache or Nginx error log. Entries are merged and sorted newest-first. Use the source filter to isolate a specific log. Entries without a parseable timestamp appear at the bottom. Up to 500 entries are shown per refresh.">
                            Explain
                        </button>
                    </div>
                </div>
                <div class="cs-pcr-card-body">
                    <div class="cs-pcr-log-toolbar">
                        <div class="cs-pcr-log-toolbar-left">
                            <label class="cs-pcr-autoreload-label">
                                <input type="checkbox" id="cs-pcr-autoreload"> Auto-refresh every 30s
                            </label>
                        </div>
                        <div class="cs-pcr-log-toolbar-right">
                            <select id="cs-pcr-filter-source" class="cs-pcr-select" style="display:none;">
                                <option value="">All sources</option>
                            </select>
                            <select id="cs-pcr-filter-level" class="cs-pcr-select">
                                <option value="">All levels</option>
                                <option value="fatal">Fatal</option>
                                <option value="error">Error</option>
                                <option value="warning">Warning</option>
                                <option value="notice">Notice</option>
                                <option value="info">Info</option>
                                <option value="success">Success</option>
                            </select>
                            <input type="text" id="cs-pcr-filter-text" class="cs-pcr-search-input" placeholder="&#128269; Filter text...">
                        </div>
                    </div>
                    <div id="cs-pcr-logs-spinner" style="display:none;margin-top:16px;"><span class="cs-pcr-spinner"></span> Loading logs&hellip;</div>
                    <div id="cs-pcr-logs-meta" style="display:none;margin:10px 0 6px;font-size:12px;color:#6b7690;"></div>
                    <div class="cs-pcr-terminal-wrap" id="cs-pcr-log-wrap" style="display:none;">
                        <div class="cs-pcr-terminal-header">
                            <span class="cs-pcr-terminal-dot"></span>
                            <span class="cs-pcr-terminal-label" id="cs-pcr-log-terminal-label">unified log — last 24 hours</span>
                        </div>
                        <div class="cs-pcr-terminal cs-pcr-log-terminal" id="cs-pcr-log-output"></div>
                    </div>
                    <div id="cs-pcr-logs-empty" style="display:none;padding:16px 0;color:#6b7690;font-size:13.5px;">No log entries found in the last 24 hours matching the current filters.</div>
                </div>
            </div>

        </div>

    </div>

    <div id="cs-pcr-modal-overlay" class="cs-pcr-modal-overlay" style="display:none;">
        <div class="cs-pcr-modal">
            <div class="cs-pcr-modal-header">
                <span id="cs-pcr-modal-title">Explain</span>
                <button type="button" class="cs-pcr-modal-close" id="cs-pcr-modal-close">&times;</button>
            </div>
            <div class="cs-pcr-modal-body" id="cs-pcr-modal-body"></div>
        </div>
    </div>
    <?php
}
