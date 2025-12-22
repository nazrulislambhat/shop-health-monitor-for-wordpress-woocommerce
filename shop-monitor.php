<?php
/*
Plugin Name: Shop Health Monitor for WooCommerce
Plugin URI: https://nazrulislam.dev/products/shop-health-monitor-woocommerce
Description: Monitors WooCommerce shop health every minute. Detects DB + frontend cache failures, auto-recovers, and alerts immediately.
Version: 1.6.0
Author: Nazrul Islam
License: GPLv2 or later
*/

if (!defined('ABSPATH')) exit;

class Woo_Shop_Health_Monitor {

    private $slack_webhook;

    public function __construct() {

        $this->slack_webhook = get_option('woo_shop_slack_webhook');

        /* CRON */
        add_action('plugins_loaded', [$this, 'register_cron']);
        add_action('woo_shop_monitor_event', [$this, 'run_monitor']);
        add_action('woo_shop_monitor_recovery_event', [$this, 'run_recovery_check']);

        /* ADMIN */
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        add_action('admin_init', [$this, 'handle_manual_actions']);
        add_action('admin_menu', [$this, 'add_settings_page']);
    }

    /* ----------------------------------------------------
     * CRON (EVERY MINUTE)
     * ---------------------------------------------------- */
    public function register_cron() {

        add_filter('cron_schedules', function ($schedules) {
            $schedules['woo_monitor_every_minute'] = [
                'interval' => 60,
                'display'  => 'Every Minute (Shop Monitor)',
            ];
            return $schedules;
        });

        if (!wp_next_scheduled('woo_shop_monitor_event')) {
            wp_schedule_event(time(), 'woo_monitor_every_minute', 'woo_shop_monitor_event');
            $this->log_incident('info', 'Cron registered automatically.');
        }
    }

    /* ----------------------------------------------------
     * MAIN MONITOR
     * ---------------------------------------------------- */
    public function run_monitor() {

        if (!class_exists('WooCommerce')) return;

        update_option('woo_shop_last_check', wp_date('Y-m-d H:i:s'));

        /* DB CHECK */
        $products = wc_get_products([
            'status' => 'publish',
            'limit'  => 1,
        ]);

        $db_empty = empty($products);

        /* FRONTEND / SHOP QUERY CHECK */
        $shop_empty = $this->is_shop_query_empty();

        $current_status  = ($db_empty || $shop_empty) ? 'empty' : 'ok';
        $previous_status = get_option('woo_shop_status', 'unknown');

        update_option('woo_shop_status', $current_status);

        /* ----------------------------------
         * CACHE DESYNC (MOST COMMON FAILURE)
         * ---------------------------------- */
        if (!$db_empty && $shop_empty) {

            $this->log_incident(
                'warning',
                'Shop empty but products exist. Cache desync detected.'
            );

            $this->flush_shop_cache();

            $this->send_alerts(
                '⚠ WooCommerce Cache Desync',
                "Shop page empty while products exist.\nCache flushed.\n" . home_url()
            );

            update_option('woo_shop_last_fail', wp_date('Y-m-d H:i:s'));

            return;
        }

        /* ----------------------------------
         * HARD FAILURE (OK → EMPTY)
         * ---------------------------------- */
        if ($previous_status !== 'empty' && $current_status === 'empty') {

            update_option('woo_shop_last_fail', wp_date('Y-m-d H:i:s'));

            $this->log_incident('failure', 'Zero products detected. Auto-recovery started.');

            $this->flush_shop_cache();

            $this->send_alerts(
                '⚠ WooCommerce Products Missing',
                "Zero products detected.\nAuto-recovery started.\n" . home_url()
            );

            if (!wp_next_scheduled('woo_shop_monitor_recovery_event')) {
                wp_schedule_single_event(time() + 10, 'woo_shop_monitor_recovery_event');
            }

            return;
        }

        /* ----------------------------------
         * NORMAL RECOVERY
         * ---------------------------------- */
        if ($previous_status === 'empty' && $current_status === 'ok') {

            $this->log_incident('recovery', 'Shop recovered.');

            $this->send_alerts(
                '✅ WooCommerce Shop Recovered',
                "Products are visible again."
            );
        }

        /* ----------------------------------
         * HEARTBEAT (STALL DETECTION)
         * ---------------------------------- */
        $last = strtotime(get_option('woo_shop_last_check', ''));
        if ($last && time() - $last > 300) {
            $this->send_slack_alert(
                "🚨 *Shop Monitor Stalled*\nNo checks in last 5 minutes."
            );
        }
    }

    /* ----------------------------------------------------
     * SHOP QUERY (CACHE-SENSITIVE)
     * ---------------------------------------------------- */
    private function is_shop_query_empty() {

        if (!function_exists('wc_get_page_id')) return false;

        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => WC()->query->get_meta_query(),
            'tax_query'      => WC()->query->get_tax_query(),
        ]);

        return !$query->have_posts();
    }

    /* ----------------------------------------------------
     * RECOVERY CHECK
     * ---------------------------------------------------- */
    public function run_recovery_check() {

        if (get_option('woo_shop_status') !== 'empty') return;

        $products = wc_get_products([
            'status' => 'publish',
            'limit'  => 1,
        ]);

        if (!empty($products)) {

            update_option('woo_shop_status', 'ok');
            $this->log_incident('recovery', 'Immediate recovery after cache flush.');

            $this->send_alerts(
                '✅ Immediate Recovery',
                'Products visible again after cache purge.'
            );
        }
    }

    /* ----------------------------------------------------
     * CACHE FLUSH
     * ---------------------------------------------------- */
    private function flush_shop_cache() {

        $flushed = [];

        if (function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
            $flushed[] = 'LiteSpeed';
        } elseif (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $flushed[] = 'WP Rocket';
        } elseif (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $flushed[] = 'W3TC';
        } elseif (class_exists('autoptimizeCache')) {
            \autoptimizeCache::clearall();
            $flushed[] = 'Autoptimize';
        } else {
            wp_cache_flush();
            $flushed[] = 'Object Cache';
        }

        update_option('woo_shop_last_flush', wp_date('Y-m-d H:i:s'));
        $this->log_incident('info', 'Cache flushed: ' . implode(', ', $flushed));
    }

    /* ----------------------------------------------------
     * ALERTS
     * ---------------------------------------------------- */
    private function send_alerts($subject, $message) {

        wp_mail(
            get_option('admin_email'),
            $subject,
            $message,
            ['Content-Type: text/plain; charset=UTF-8']
        );

        $this->send_slack_alert("*{$subject}*\n{$message}");
    }

    private function send_slack_alert($message) {

        if (!$this->slack_webhook) return;

        wp_remote_post($this->slack_webhook, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['text' => $message]),
            'timeout' => 5,
        ]);
    }

    /* ----------------------------------------------------
     * INCIDENT LOG
     * ---------------------------------------------------- */
    private function log_incident($type, $message) {

        $log = get_option('woo_shop_incident_log', []);

        array_unshift($log, [
            'time'    => wp_date('Y-m-d H:i:s'),
            'type'    => $type,
            'message' => $message
        ]);

        update_option('woo_shop_incident_log', array_slice($log, 0, 20));
    }

    /* ----------------------------------------------------
     * DASHBOARD
     * ---------------------------------------------------- */
    public function add_dashboard_widget() {

        wp_add_dashboard_widget(
            'woo_shop_monitor_widget',
            '🛒 Woo Shop Health Monitor',
            [$this, 'render_dashboard_widget']
        );
    }

    public function render_dashboard_widget() {

        echo '<p><strong>Status:</strong> ' . esc_html(get_option('woo_shop_status', 'unknown')) . '</p>';
        echo '<p><strong>Last Check:</strong> ' . esc_html(get_option('woo_shop_last_check', 'Never')) . '</p>';

        $log = get_option('woo_shop_incident_log', []);
        if ($log) {
            echo '<hr><ul>';
            foreach (array_slice($log, 0, 3) as $e) {
                echo "<li>[{$e['time']}] <strong>{$e['type']}:</strong> {$e['message']}</li>";
            }
            echo '</ul>';
        }

        echo '<p>
            <a class="button button-primary" href="' . admin_url('?woo_manual_shop_check=1') . '">Run Check</a>
            <a class="button" style="margin-left:6px;color:#d63638;" href="' . admin_url('?woo_manual_test_alerts=1') . '">Test Alerts</a>
        </p>';
    }

    /* ----------------------------------------------------
     * MANUAL ACTIONS
     * ---------------------------------------------------- */
    public function handle_manual_actions() {

        if (!current_user_can('manage_options')) return;

        if (isset($_GET['woo_manual_shop_check'])) {
            do_action('woo_shop_monitor_event');
            wp_die('Manual check completed.');
        }

        if (isset($_GET['woo_manual_test_alerts'])) {

            $this->log_incident('test', 'Manual test alert triggered.');
            $this->flush_shop_cache();
            $this->send_alerts('[TEST] Shop Monitor', 'This is a test alert.');

            wp_die('Test alert sent.');
        }
    }

    /* ----------------------------------------------------
     * SETTINGS
     * ---------------------------------------------------- */
    public function add_settings_page() {

        add_options_page(
            'Woo Shop Monitor',
            'Woo Shop Monitor',
            'manage_options',
            'woo-shop-monitor',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {

        if (isset($_POST['save'])) {
            check_admin_referer('woo_shop_monitor');
            update_option('woo_shop_slack_webhook', sanitize_text_field($_POST['webhook']));
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $webhook = esc_attr(get_option('woo_shop_slack_webhook', ''));

        echo '<div class="wrap"><h1>Woo Shop Health Monitor</h1>
        <form method="post">';
        wp_nonce_field('woo_shop_monitor');
        echo '
        <table class="form-table">
            <tr>
                <th>Slack Webhook</th>
                <td><input type="text" name="webhook" value="' . $webhook . '" class="large-text"></td>
            </tr>
        </table>
        <p><button name="save" class="button-primary">Save</button></p>
        </form></div>';
    }
}

new Woo_Shop_Health_Monitor();
