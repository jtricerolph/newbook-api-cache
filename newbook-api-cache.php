<?php
/**
 * Plugin Name: NewBook API Cache
 * Plugin URI: https://github.com/yourusername/newbook-api-cache
 * Description: High-performance caching layer for NewBook API with encrypted storage and transparent proxy functionality
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: newbook-api-cache
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('NEWBOOK_CACHE_VERSION', '1.0.0');
define('NEWBOOK_CACHE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NEWBOOK_CACHE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NEWBOOK_CACHE_PLUGIN_FILE', __FILE__);

// Include dependencies
require_once NEWBOOK_CACHE_PLUGIN_DIR . 'includes/class-logger.php';
require_once NEWBOOK_CACHE_PLUGIN_DIR . 'includes/class-api-client.php';
require_once NEWBOOK_CACHE_PLUGIN_DIR . 'includes/class-newbook-cache.php';
require_once NEWBOOK_CACHE_PLUGIN_DIR . 'includes/class-cache-sync.php';

// Include admin only on admin pages
if (is_admin()) {
    require_once NEWBOOK_CACHE_PLUGIN_DIR . 'includes/class-admin-settings.php';
}

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, 'newbook_cache_activate');
function newbook_cache_activate() {
    newbook_cache_create_tables();
    newbook_cache_schedule_cron();

    // Set default options
    if (get_option('newbook_cache_enabled') === false) {
        update_option('newbook_cache_enabled', true);
    }
    if (get_option('newbook_cache_log_level') === false) {
        update_option('newbook_cache_log_level', NewBook_Cache_Logger::INFO);
    }

    // Show activation notice
    set_transient('newbook_cache_activation_notice', true, 60);
}

/**
 * Plugin deactivation hook
 */
register_deactivation_hook(__FILE__, 'newbook_cache_deactivate');
function newbook_cache_deactivate() {
    newbook_cache_unschedule_cron();
}

/**
 * Create database tables
 */
function newbook_cache_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Main cache table
    $table_cache = $wpdb->prefix . 'newbook_cache';
    $sql_cache = "CREATE TABLE IF NOT EXISTS $table_cache (
        booking_id BIGINT UNSIGNED NOT NULL,
        arrival_date DATE NOT NULL,
        departure_date DATE NOT NULL,
        booking_status VARCHAR(20) DEFAULT 'confirmed',
        group_id VARCHAR(50) DEFAULT NULL,
        room_name VARCHAR(100) DEFAULT NULL,
        num_guests TINYINT UNSIGNED DEFAULT 0,
        encrypted_data LONGTEXT NOT NULL,
        last_updated DATETIME NOT NULL,
        cache_type ENUM('hot', 'historical') DEFAULT 'hot',
        PRIMARY KEY (booking_id),
        INDEX idx_dates (arrival_date, departure_date),
        INDEX idx_status (booking_status),
        INDEX idx_cache_type (cache_type)
    ) $charset_collate;";

    // Logs table
    $table_logs = $wpdb->prefix . 'newbook_cache_logs';
    $sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        timestamp DATETIME NOT NULL,
        level TINYINT NOT NULL,
        message TEXT,
        context TEXT,
        memory_usage VARCHAR(20),
        PRIMARY KEY (id),
        INDEX idx_timestamp (timestamp),
        INDEX idx_level (level)
    ) $charset_collate;";

    // Uncached requests monitoring table
    $table_uncached = $wpdb->prefix . 'newbook_cache_uncached_requests';
    $sql_uncached = "CREATE TABLE IF NOT EXISTS $table_uncached (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        action VARCHAR(50) NOT NULL,
        params TEXT,
        timestamp DATETIME NOT NULL,
        caller VARCHAR(255),
        PRIMARY KEY (id),
        INDEX idx_action (action),
        INDEX idx_timestamp (timestamp)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_cache);
    dbDelta($sql_logs);
    dbDelta($sql_uncached);
}

/**
 * Schedule cron jobs
 */
function newbook_cache_schedule_cron() {
    // Full refresh: Daily at 3 AM
    if (!wp_next_scheduled('newbook_cache_full_refresh')) {
        wp_schedule_event(strtotime('tomorrow 3:00 AM'), 'daily', 'newbook_cache_full_refresh');
    }

    // Incremental sync: Every 20 seconds
    if (!wp_next_scheduled('newbook_cache_incremental_sync')) {
        wp_schedule_event(time(), 'newbook_cache_20_seconds', 'newbook_cache_incremental_sync');
    }

    // Cleanup: Daily at 4 AM
    if (!wp_next_scheduled('newbook_cache_cleanup')) {
        wp_schedule_event(strtotime('tomorrow 4:00 AM'), 'daily', 'newbook_cache_cleanup');
    }
}

/**
 * Unschedule cron jobs
 */
function newbook_cache_unschedule_cron() {
    wp_clear_scheduled_hook('newbook_cache_full_refresh');
    wp_clear_scheduled_hook('newbook_cache_incremental_sync');
    wp_clear_scheduled_hook('newbook_cache_cleanup');
}

/**
 * Add custom cron intervals
 */
add_filter('cron_schedules', 'newbook_cache_cron_intervals');
function newbook_cache_cron_intervals($schedules) {
    $schedules['newbook_cache_20_seconds'] = array(
        'interval' => 20,
        'display' => __('Every 20 Seconds', 'newbook-api-cache')
    );
    return $schedules;
}

/**
 * Initialize plugin
 */
add_action('plugins_loaded', 'newbook_cache_init', 5); // Priority 5 (early loading)
function newbook_cache_init() {
    // Check if booking-match-api is active
    if (!class_exists('BMA_NewBook_Search')) {
        add_action('admin_notices', 'newbook_cache_missing_dependency_notice');
        return;
    }

    // Initialize cache system
    global $newbook_api_cache;
    $newbook_api_cache = new NewBook_API_Cache();

    // Initialize sync jobs
    new NewBook_Cache_Sync();

    // Initialize admin settings
    if (is_admin()) {
        new NewBook_Cache_Admin_Settings();
    }

    NewBook_Cache_Logger::log('NewBook API Cache initialized', NewBook_Cache_Logger::INFO);
}

/**
 * Admin notice if booking-match-api not found
 */
function newbook_cache_missing_dependency_notice() {
    ?>
    <div class="notice notice-warning">
        <p>
            <strong>NewBook API Cache:</strong>
            This plugin requires the <strong>Booking Match API</strong> plugin to be installed and activated.
        </p>
    </div>
    <?php
}

/**
 * Show activation notice
 */
add_action('admin_notices', 'newbook_cache_activation_notice');
function newbook_cache_activation_notice() {
    if (get_transient('newbook_cache_activation_notice')) {
        delete_transient('newbook_cache_activation_notice');
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong>NewBook API Cache activated!</strong>
                <a href="<?php echo admin_url('options-general.php?page=newbook-cache-settings'); ?>">
                    Configure settings →
                </a>
            </p>
        </div>
        <?php
    }
}
