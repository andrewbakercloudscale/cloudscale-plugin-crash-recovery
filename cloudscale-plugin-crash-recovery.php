<?php
/**
 * Plugin Name: CloudScale Plugin Crash Recovery
 * Description: Every minute, probes the site. If busted, deactivates + deletes the most recently modified plugin (only if modified within last 10 minutes).
 * Version: 1.0.0
 * Author: CloudScale
 */

if (!defined('ABSPATH')) { exit; }

class CloudScale_Plugin_Crash_Recovery {
    const CRON_HOOK = 'cs_pcr_watchdog_tick';
    const PROBE_KEY = 'cs_pcr_probe';
    const OK_BODY   = 'CLOUDSCALE_OK';
    const WINDOW_SECONDS = 600; // 10 minutes

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);
        add_action(self::CRON_HOOK, [__CLASS__, 'tick']);
        add_action('init', [__CLASS__, 'maybe_probe_endpoint'], 1);
    }

    public static function add_cron_schedules($schedules) {
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => 'Every Minute',
            ];
        }
        return $schedules;
    }

    public static function activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'every_minute', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    public static function maybe_probe_endpoint() {
        if (!isset($_GET[self::PROBE_KEY])) {
            return;
        }
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo self::OK_BODY;
        exit;
    }

    public static function tick() {
        static $running = false;
        if ($running) return;
        $running = true;

        $probe_url = add_query_arg(
            [
                self::PROBE_KEY => 1,
                't' => time(),
            ],
            home_url('/')
        );

        $resp = wp_remote_get($probe_url, [
            'timeout' => 5,
            'blocking' => true,
            'sslverify' => false,
            'headers' => [
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ],
        ]);

        $ok = false;
        if (!is_wp_error($resp)) {
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            if ($code === 200 && is_string($body) && strpos($body, self::OK_BODY) !== false) {
                $ok = true;
            }
        }

        if ($ok) {
            $running = false;
            return;
        }

        self::delete_most_recent_plugin_in_window();
        $running = false;
    }

    private static function delete_most_recent_plugin_in_window() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all = get_plugins();
        if (empty($all)) return;

        $now = time();
        $newest_plugin_file = null;
        $newest_mtime = 0;
        $self_plugin_file = plugin_basename(__FILE__);

        foreach ($all as $plugin_file => $data) {
            if ($plugin_file === $self_plugin_file) continue;

            $abs = WP_PLUGIN_DIR . '/' . $plugin_file;
            $mtime = @filemtime($abs);
            if (!$mtime) continue;

            if ($mtime > $newest_mtime) {
                $newest_mtime = $mtime;
                $newest_plugin_file = $plugin_file;
            }
        }

        if (!$newest_plugin_file) return;
        if (($now - $newest_mtime) > self::WINDOW_SECONDS) return;

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($newest_plugin_file, true);

        $target_path = WP_PLUGIN_DIR . '/' . $newest_plugin_file;
        $dir = dirname($target_path);
        $is_single_file = (basename($dir) === 'plugins');

        if ($is_single_file) {
            self::delete_path($target_path);
        } else {
            self::delete_path($dir);
        }
    }

    private static function delete_path($path) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        if (is_dir($path)) {
            $items = @scandir($path);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    self::delete_path($path . '/' . $item);
                }
            }
            @rmdir($path);
        }
    }
}

CloudScale_Plugin_Crash_Recovery::init();
register_activation_hook(__FILE__, ['CloudScale_Plugin_Crash_Recovery', 'activate']);
register_deactivation_hook(__FILE__, ['CloudScale_Plugin_Crash_Recovery', 'deactivate']);
