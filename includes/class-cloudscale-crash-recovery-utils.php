<?php
/**
 * Shared utility helpers for CloudScale Crash Recovery.
 *
 * All stateless helpers used in more than one context belong here.
 * Call Cloudscale_Crash_Recovery_Utils::method() from any code that needs them.
 * Never duplicate these functions elsewhere in the plugin.
 *
 * @package CloudScale_Crash_Recovery
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless utility helpers for CloudScale Crash Recovery.
 *
 * @since 1.0.0
 */
class Cloudscale_Crash_Recovery_Utils {

	/**
	 * Locates wp-config.php in the standard WordPress locations.
	 *
	 * Checks ABSPATH first, then one level above (common on managed hosts
	 * that move wp-config.php outside the web root for security).
	 *
	 * @since  1.0.0
	 * @return string|null Absolute path to wp-config.php, or null if not found.
	 */
	public static function get_wp_config_path() {
		$path = ABSPATH . 'wp-config.php';
		if ( file_exists( $path ) ) {
			return $path;
		}
		$path = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $path ) ) {
			return $path;
		}
		return null;
	}

	/**
	 * Returns true if the debug-mode revert timestamp is still in the future.
	 *
	 * @since  1.0.0
	 * @return bool True while debug mode is scheduled to be active.
	 */
	public static function debug_is_active() {
		$revert_at = (int) get_option( CS_PCR_DEBUG_OPTION, 0 );
		return $revert_at > time();
	}

	/**
	 * Checks whether the watchdog system-cron entry exists in root's crontab.
	 *
	 * Returns null when shell_exec is unavailable, false when the entry is
	 * absent, and true when the entry is present.
	 *
	 * @since  1.0.0
	 * @return bool|null True if installed, false if absent, null if shell_exec is disabled.
	 */
	public static function cron_installed() {
		if ( ! function_exists( 'shell_exec' ) ) {
			return null;
		}
		$out = shell_exec( 'sudo crontab -l 2>/dev/null' );
		if ( null === $out ) {
			return null;
		}
		return strpos( $out, 'cs-crash-watchdog.sh' ) !== false;
	}

	/**
	 * Returns true if the watchdog shell script exists and is executable.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public static function watchdog_exists() {
		return file_exists( CS_PCR_WATCHDOG ) && is_executable( CS_PCR_WATCHDOG );
	}

	/**
	 * Finds the most recent SUCCESS or ERROR line in a log-line array.
	 *
	 * @since  1.0.0
	 * @param  string[] $lines Array of log lines, oldest first.
	 * @return string|null Most-recent matching line, or null if none found.
	 */
	public static function last_recovery( array $lines ) {
		foreach ( array_reverse( $lines ) as $line ) {
			if ( strpos( $line, 'SUCCESS:' ) !== false || strpos( $line, 'ERROR:' ) !== false ) {
				return $line;
			}
		}
		return null;
	}

	/**
	 * Finds the most recent ALERT line in a log-line array.
	 *
	 * @since  1.0.0
	 * @param  string[] $lines Array of log lines, oldest first.
	 * @return string|null Most-recent ALERT line, or null if none found.
	 */
	public static function last_alert( array $lines ) {
		foreach ( array_reverse( $lines ) as $line ) {
			if ( strpos( $line, 'ALERT:' ) !== false ) {
				return $line;
			}
		}
		return null;
	}

	/**
	 * Parses a Unix timestamp from a log line using multiple timestamp formats.
	 *
	 * Recognises:
	 * - CS_PCR/watchdog: [2025-03-10 14:22:01 SAST]
	 * - PHP error log:   [10-Mar-2025 14:22:01 UTC]
	 * - Apache:          [Mon Mar 10 14:22:01.123456 2025]
	 *
	 * @since  1.0.0
	 * @param  string $line A single log line.
	 * @return int Unix timestamp, or 0 if no recognisable timestamp is found.
	 */
	public static function parse_log_timestamp( $line ) {
		// CS_PCR / watchdog format.
		if ( preg_match( '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m ) ) {
			$ts = strtotime( $m[1] );
			return false !== $ts ? $ts : 0;
		}
		// PHP error log format.
		if ( preg_match( '/\[(\d{2}-[A-Za-z]+-\d{4} \d{2}:\d{2}:\d{2}[^\]]*)]/', $line, $m ) ) {
			$ts = strtotime( $m[1] );
			return false !== $ts ? $ts : 0;
		}
		// Apache error log format.
		if ( preg_match( '/\[\w+ (\w+ \d+ \d+:\d+:\d+)[.\d]* (\d{4})/', $line, $m ) ) {
			$ts = strtotime( $m[1] . ' ' . $m[2] );
			return false !== $ts ? $ts : 0;
		}
		return 0;
	}

	/**
	 * Infers a severity level string from a log line's content.
	 *
	 * @since  1.0.0
	 * @param  string $line A single log line.
	 * @return string One of 'fatal', 'error', 'warning', 'notice', 'success', 'info'.
	 */
	public static function detect_level( $line ) {
		$upper = strtoupper( $line );
		if ( strpos( $upper, 'FATAL' )   !== false ) { return 'fatal'; }
		if ( strpos( $upper, 'ERROR' )   !== false ) { return 'error'; }
		if ( strpos( $upper, 'ALERT' )   !== false ) { return 'error'; }
		if ( strpos( $upper, 'CRIT' )    !== false ) { return 'error'; }
		if ( strpos( $upper, 'WARNING' ) !== false ) { return 'warning'; }
		if ( strpos( $upper, 'WARN' )    !== false ) { return 'warning'; }
		if ( strpos( $upper, 'NOTICE' )  !== false ) { return 'notice'; }
		if ( strpos( $upper, 'SUCCESS' ) !== false ) { return 'success'; }
		if ( strpos( $upper, 'INFO' )    !== false ) { return 'info'; }
		return 'info';
	}

	/**
	 * Builds a single compatibility-check result array.
	 *
	 * @since  1.0.0
	 * @param  string      $name     Human-readable check name.
	 * @param  bool        $passed   Whether the check passed.
	 * @param  string      $pass_msg Message shown on pass.
	 * @param  string      $fail_msg Message shown on fail.
	 * @param  string|null $detail   Optional extra detail (path, URL, etc.). Default null.
	 * @param  string|null $override Override the derived status: 'pass', 'fail', or 'warning'. Default null.
	 * @return array{name: string, status: string, message: string, detail: string|null}
	 */
	public static function build_check( $name, $passed, $pass_msg, $fail_msg, $detail = null, $override = null ) {
		$status = $override ?: ( $passed ? 'pass' : 'fail' );
		return array(
			'name'    => $name,
			'status'  => $status,
			'message' => $passed ? $pass_msg : $fail_msg,
			'detail'  => $detail,
		);
	}
}
