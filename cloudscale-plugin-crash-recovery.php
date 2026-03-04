<?php
/**
 * Plugin Name: CloudScale Crash Recovery
 * Description: System-cron-based watchdog that probes the site every minute. If a crash is detected, deactivates and deletes the most recently modified plugin (within 10 minutes). Includes compatibility checks to validate the instance supports system cron.
 * Version: 1.2.0
 * Author: CloudScale
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CS_PCR_VERSION',        '1.2.0' );
define( 'CS_PCR_PROBE_KEY',      'cs_pcr_probe' );
define( 'CS_PCR_OK_BODY',        'CLOUDSCALE_OK' );
define( 'CS_PCR_WINDOW_SECONDS', 600 );
define( 'CS_PCR_SLUG',           'cloudscale-crash-recovery' );
define( 'CS_PCR_LOG_FILE',       '/var/log/cloudscale-crash-recovery.log' );
define( 'CS_PCR_WATCHDOG',       '/usr/local/bin/cs-crash-watchdog.sh' );

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

add_action( 'init',                  'cs_pcr_maybe_probe_endpoint', 1 );
add_action( 'admin_menu',            'cs_pcr_add_menu' );
add_action( 'admin_enqueue_scripts', 'cs_pcr_enqueue_assets' );
add_action( 'wp_ajax_cs_pcr_run_checks', 'cs_pcr_ajax_run_checks' );

// ---------------------------------------------------------------------------
// Probe endpoint
// ---------------------------------------------------------------------------

function cs_pcr_maybe_probe_endpoint() {
    if ( ! isset( $_GET[ CS_PCR_PROBE_KEY ] ) ) { return; }
    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );
    echo CS_PCR_OK_BODY;
    exit;
}

// ---------------------------------------------------------------------------
// Recovery logic (callable from WP-CLI)
// ---------------------------------------------------------------------------

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
        $mtime = @filemtime( $abs );
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

function cs_pcr_delete_path( $path ) {
    if ( is_file( $path ) || is_link( $path ) ) { @unlink( $path ); return; }
    if ( is_dir( $path ) ) {
        $items = @scandir( $path );
        if ( is_array( $items ) ) {
            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) { continue; }
                cs_pcr_delete_path( $path . '/' . $item );
            }
        }
        @rmdir( $path );
    }
}

// ---------------------------------------------------------------------------
// Helpers — read log and cron status from server (shell_exec)
// ---------------------------------------------------------------------------

function cs_pcr_get_log_tail( $lines = 20 ) {
    if ( ! function_exists( 'shell_exec' ) ) { return []; }
    $log  = CS_PCR_LOG_FILE;
    $raw  = shell_exec( "tail -n {$lines} " . escapeshellarg( $log ) . ' 2>/dev/null' );
    if ( empty( $raw ) ) { return []; }
    return array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
}

function cs_pcr_cron_installed() {
    if ( ! function_exists( 'shell_exec' ) ) { return null; }
    $out = shell_exec( 'sudo crontab -l 2>/dev/null' );
    if ( $out === null ) { return null; }
    return strpos( $out, 'cs-crash-watchdog.sh' ) !== false;
}

function cs_pcr_watchdog_exists() {
    return file_exists( CS_PCR_WATCHDOG ) && is_executable( CS_PCR_WATCHDOG );
}

function cs_pcr_last_recovery( $lines ) {
    foreach ( array_reverse( $lines ) as $line ) {
        if ( strpos( $line, 'SUCCESS:' ) !== false || strpos( $line, 'ERROR:' ) !== false ) {
            return $line;
        }
    }
    return null;
}

function cs_pcr_last_alert( $lines ) {
    foreach ( array_reverse( $lines ) as $line ) {
        if ( strpos( $line, 'ALERT:' ) !== false ) { return $line; }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------

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

function cs_pcr_enqueue_assets( $hook ) {
    if ( strpos( $hook, CS_PCR_SLUG ) === false ) { return; }
    wp_enqueue_style(  'cs-pcr-admin', plugin_dir_url( __FILE__ ) . 'admin.css', [], CS_PCR_VERSION );
    wp_enqueue_script( 'cs-pcr-admin', plugin_dir_url( __FILE__ ) . 'admin.js', [ 'jquery' ], CS_PCR_VERSION, true );
    wp_localize_script( 'cs-pcr-admin', 'CS_PCR', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'cs_pcr_checks' ),
    ] );
}

// ---------------------------------------------------------------------------
// AJAX — run compatibility checks
// ---------------------------------------------------------------------------

function cs_pcr_ajax_run_checks() {
    check_ajax_referer( 'cs_pcr_checks', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }

    $results = [];

    // 1. PHP CLI
    $php_bin  = PHP_BINARY;
    $php_test = shell_exec( escapeshellcmd( $php_bin ) . ' -r "echo \'OK\';" 2>&1' );
    $results[] = cs_pcr_check( 'PHP CLI',
        strpos( (string)$php_test, 'OK' ) !== false,
        'PHP CLI available at ' . $php_bin,
        'PHP CLI not available or shell_exec disabled.',
        $php_bin );

    // 2. shell_exec
    $disabled  = array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) );
    $results[] = cs_pcr_check( 'shell_exec enabled',
        ! in_array( 'shell_exec', $disabled, true ),
        'shell_exec is available.',
        'shell_exec is disabled in php.ini. The admin UI checks use it; system cron is unaffected.',
        null, 'warning' );

    // 3. curl binary
    $curl_path = trim( (string)shell_exec( 'which curl 2>/dev/null' ) );
    $results[] = cs_pcr_check( 'curl binary',
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
    $results[] = cs_pcr_check( 'Probe endpoint',
        $probe_ok,
        'Probe responded with CLOUDSCALE_OK.',
        'Probe did not return the expected response. Check the plugin is active and the site loads at ' . home_url( '/' ),
        $probe_url );

    // 5. Plugin directory writable
    $plugin_dir = WP_PLUGIN_DIR;
    $results[]  = cs_pcr_check( 'Plugin directory writable',
        is_writable( $plugin_dir ),
        $plugin_dir . ' is writable.',
        $plugin_dir . ' is not writable by the web process.',
        $plugin_dir );

    // 6. WP-CLI
    $wpcli_path = trim( (string)shell_exec( 'which wp 2>/dev/null' ) );
    if ( empty( $wpcli_path ) && file_exists( '/usr/local/bin/wp' ) ) { $wpcli_path = '/usr/local/bin/wp'; }
    $results[]  = cs_pcr_check( 'WP-CLI',
        ! empty( $wpcli_path ),
        'WP-CLI found at ' . $wpcli_path,
        'WP-CLI not found. The watchdog will fall back to direct file deletion. Install from https://wp-cli.org/',
        $wpcli_path,
        empty( $wpcli_path ) ? 'warning' : 'pass' );

    // 7. Watchdog script on disk
    $wd_exists = cs_pcr_watchdog_exists();
    $results[] = cs_pcr_check( 'Watchdog script',
        $wd_exists,
        CS_PCR_WATCHDOG . ' exists and is executable.',
        CS_PCR_WATCHDOG . ' not found or not executable. Deploy it from the System Cron Setup tab.',
        CS_PCR_WATCHDOG );

    // 8. System cron entry installed
    $cron_installed = cs_pcr_cron_installed();
    if ( $cron_installed === null ) {
        $results[] = cs_pcr_check( 'System cron entry', false,
            '', 'Could not read root crontab (shell_exec may be disabled).',
            null, 'warning' );
    } else {
        $results[] = cs_pcr_check( 'System cron entry',
            $cron_installed,
            'Cron entry found in root crontab. Watchdog fires every minute.',
            'Cron entry not found. Add: * * * * * /usr/local/bin/cs-crash-watchdog.sh',
            null );
    }

    // 9. Log file exists and writable
    $log_exists   = file_exists( CS_PCR_LOG_FILE );
    $log_writable = $log_exists && is_writable( CS_PCR_LOG_FILE );
    $results[]    = cs_pcr_check( 'Log file',
        $log_writable,
        CS_PCR_LOG_FILE . ' exists and is writable.',
        $log_exists
            ? CS_PCR_LOG_FILE . ' exists but is not writable. Run: sudo chmod 664 ' . CS_PCR_LOG_FILE
            : CS_PCR_LOG_FILE . ' does not exist. Run: sudo touch ' . CS_PCR_LOG_FILE . ' && sudo chmod 664 ' . CS_PCR_LOG_FILE,
        CS_PCR_LOG_FILE );

    // 10. Legacy WP cron
    $next_wpcron = wp_next_scheduled( 'cs_pcr_watchdog_tick' );
    $results[]   = cs_pcr_check( 'Legacy WP cron',
        ! $next_wpcron,
        'No legacy WP cron entry. Clean slate.',
        'Legacy WP cron entry found (next: ' . date( 'H:i:s', (int)$next_wpcron ) . '). Safe to ignore — system cron takes precedence.',
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

function cs_pcr_check( $name, $passed, $pass_msg, $fail_msg, $detail = null, $override = null ) {
    $status = $override ?: ( $passed ? 'pass' : 'fail' );
    return [
        'name'    => $name,
        'status'  => $status,
        'message' => $passed ? $pass_msg : $fail_msg,
        'detail'  => $detail,
    ];
}

// ---------------------------------------------------------------------------
// Admin page
// ---------------------------------------------------------------------------

function cs_pcr_render_page() {
    $probe_url  = add_query_arg( [ CS_PCR_PROBE_KEY => 1 ], home_url( '/' ) );
    $php_bin    = PHP_BINARY;
    $plugin_dir = WP_PLUGIN_DIR;

    // Status tab data — read once
    $log_lines      = cs_pcr_get_log_tail( 30 );
    $cron_installed = cs_pcr_cron_installed();
    $wd_exists      = cs_pcr_watchdog_exists();
    $legacy_cron    = wp_next_scheduled( 'cs_pcr_watchdog_tick' );
    $wpcli_bin      = trim( (string)shell_exec( 'which wp 2>/dev/null' ) ?: '' );
    $curl_bin       = trim( (string)shell_exec( 'which curl 2>/dev/null' ) ?: '' );
    $last_recovery  = cs_pcr_last_recovery( $log_lines );
    $last_alert     = cs_pcr_last_alert( $log_lines );
    $log_size       = file_exists( CS_PCR_LOG_FILE ) ? round( filesize( CS_PCR_LOG_FILE ) / 1024, 1 ) . ' KB' : 'not found';
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
                <span class="cs-pcr-version">v<?php echo esc_html( CS_PCR_VERSION ); ?></span>
            </div>
        </div>

        <div class="cs-pcr-tabs">
            <button class="cs-pcr-tab active" data-tab="checks">Compatibility Checks</button>
            <button class="cs-pcr-tab" data-tab="setup">System Cron Setup</button>
            <button class="cs-pcr-tab" data-tab="status">Status &amp; Log</button>
        </div>

        <!-- Tab: Compatibility Checks -->
        <div class="cs-pcr-tab-content active" id="cs-pcr-tab-checks">
            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-blue">
                    <span>Instance Compatibility Check</span>
                    <button class="cs-pcr-btn cs-pcr-btn-explain"
                        data-title="Compatibility Check"
                        data-body="Runs 10 server-side checks to confirm your instance is ready for the system cron watchdog: PHP CLI, shell_exec, curl, the probe endpoint, plugin directory permissions, WP-CLI, watchdog script presence, cron entry, log file, and legacy WP cron. Critical failures must be resolved before the watchdog can protect the site.">
                        Explain
                    </button>
                </div>
                <div class="cs-pcr-card-body">
                    <p>Run these checks to confirm your server is compatible. Critical failures must be resolved. Warnings are advisory.</p>
                    <div class="cs-pcr-button-row">
                        <button class="cs-pcr-btn cs-pcr-btn-primary" id="cs-pcr-run-checks">▶ Run Compatibility Checks</button>
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
                    <button class="cs-pcr-btn cs-pcr-btn-explain"
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
# CloudScale Crash Recovery — System Cron Watchdog v1.2.0
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
                        <button class="cs-pcr-btn cs-pcr-btn-secondary" id="cs-pcr-copy-script">📋 Copy Script</button>
                    </div>
                </div>
            </div>

            <div class="cs-pcr-card">
                <div class="cs-pcr-card-header cs-pcr-header-purple">
                    <span>2. Cron Entry</span>
                    <button class="cs-pcr-btn cs-pcr-btn-explain"
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
                        <button class="cs-pcr-btn cs-pcr-btn-secondary" id="cs-pcr-copy-cron">📋 Copy Cron Line</button>
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
                    <button class="cs-pcr-btn cs-pcr-btn-explain"
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
                                    <span class="cs-pcr-badge cs-pcr-badge-amber">Active — next: <?php echo esc_html( date( 'H:i:s', $legacy_cron ) ); ?></span>
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

    </div>

    <div id="cs-pcr-modal-overlay" class="cs-pcr-modal-overlay" style="display:none;">
        <div class="cs-pcr-modal">
            <div class="cs-pcr-modal-header">
                <span id="cs-pcr-modal-title">Explain</span>
                <button class="cs-pcr-modal-close" id="cs-pcr-modal-close">&times;</button>
            </div>
            <div class="cs-pcr-modal-body" id="cs-pcr-modal-body"></div>
        </div>
    </div>
    <?php
}
