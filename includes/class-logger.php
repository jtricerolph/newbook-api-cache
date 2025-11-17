<?php
/**
 * NewBook Cache Logger
 *
 * Handles logging with configurable levels and non-spammy output
 *
 * @package NewBook_API_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NewBook_Cache_Logger {

    const OFF = 0;
    const ERROR = 1;
    const INFO = 2;
    const DEBUG = 3;

    /**
     * Log a message
     *
     * @param string $message Log message
     * @param int $level Log level (ERROR, INFO, DEBUG)
     * @param array $context Additional context data
     */
    public static function log($message, $level = self::INFO, $context = array()) {
        $current_level = get_option('newbook_cache_log_level', self::INFO);

        // Don't log if below threshold
        if ($level > $current_level) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_logs';

        // Insert log entry
        $wpdb->insert($table, array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'context' => !empty($context) ? json_encode($context) : null,
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
        ));

        // Also write to WordPress debug log if WP_DEBUG is on
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $level_name = self::get_level_name($level);
            error_log("[NewBook Cache - {$level_name}] {$message}");
        }

        // Cleanup old logs periodically (every 100 logs)
        if (rand(1, 100) === 1) {
            self::cleanup_old_logs();
        }
    }

    /**
     * Cleanup old log entries
     */
    private static function cleanup_old_logs() {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_logs';
        $max_logs = get_option('newbook_cache_max_logs', 1000);

        // Delete logs beyond max limit
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$table}
            WHERE id NOT IN (
                SELECT id FROM (
                    SELECT id FROM {$table}
                    ORDER BY timestamp DESC
                    LIMIT %d
                ) tmp
            )
        ", $max_logs));
    }

    /**
     * Get level name
     *
     * @param int $level Level constant
     * @return string Level name
     */
    public static function get_level_name($level) {
        switch ($level) {
            case self::ERROR:
                return 'ERROR';
            case self::INFO:
                return 'INFO';
            case self::DEBUG:
                return 'DEBUG';
            default:
                return 'UNKNOWN';
        }
    }

    /**
     * Get all log entries
     *
     * @param array $args Query arguments (level, limit, offset)
     * @return array Log entries
     */
    public static function get_logs($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_logs';

        $defaults = array(
            'level' => null,
            'search' => null,
            'limit' => 50,
            'offset' => 0,
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array();

        if ($args['level'] !== null) {
            $where_clauses[] = $wpdb->prepare("level = %d", $args['level']);
        }

        if (!empty($args['search'])) {
            $where_clauses[] = $wpdb->prepare("message LIKE %s", '%' . $wpdb->esc_like($args['search']) . '%');
        }

        $where = '';
        if (!empty($where_clauses)) {
            $where = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY timestamp {$args['order']} LIMIT %d OFFSET %d",
            $args['limit'],
            $args['offset']
        );

        return $wpdb->get_results($sql);
    }

    /**
     * Clear all logs
     */
    public static function clear_logs() {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_logs';
        $wpdb->query("TRUNCATE TABLE {$table}");

        self::log('Logs cleared', self::INFO);
    }

    /**
     * Get log count
     *
     * @param array $args Query arguments (level, search)
     * @return int Number of log entries
     */
    public static function get_log_count($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_logs';

        $defaults = array(
            'level' => null,
            'search' => null
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array();

        if ($args['level'] !== null) {
            $where_clauses[] = $wpdb->prepare("level = %d", $args['level']);
        }

        if (!empty($args['search'])) {
            $where_clauses[] = $wpdb->prepare("message LIKE %s", '%' . $wpdb->esc_like($args['search']) . '%');
        }

        $where = '';
        if (!empty($where_clauses)) {
            $where = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
    }
}
