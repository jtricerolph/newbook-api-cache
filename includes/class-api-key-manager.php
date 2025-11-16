<?php
/**
 * NewBook API Cache - API Key Manager
 *
 * Manages custom API keys for external system authentication
 *
 * @package NewBook_API_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NewBook_API_Key_Manager {

    /**
     * Generate new API key
     *
     * @param string $label Human-readable label for the key
     * @return array|false Array with 'key' (full key shown ONCE) and 'id' on success, false on failure
     */
    public static function generate_key($label) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_api_keys';

        // Generate secure random key
        $random_bytes = bin2hex(random_bytes(20)); // 40 characters
        $full_key = 'nbcache_' . $random_bytes;

        // Hash for storage (never store plaintext)
        $key_hash = hash('sha256', $full_key);

        // Insert into database
        $result = $wpdb->insert($table, array(
            'key_hash' => $key_hash,
            'key_label' => sanitize_text_field($label),
            'created_date' => current_time('mysql'),
            'last_used' => null,
            'usage_count' => 0,
            'is_active' => 1
        ));

        if ($result === false) {
            NewBook_Cache_Logger::log('Failed to create API key', NewBook_Cache_Logger::ERROR, array(
                'label' => $label,
                'error' => $wpdb->last_error
            ));
            return false;
        }

        $key_id = $wpdb->insert_id;

        NewBook_Cache_Logger::log('API key created', NewBook_Cache_Logger::INFO, array(
            'key_id' => $key_id,
            'label' => $label
        ));

        // Return full key (shown ONCE) and ID
        return array(
            'key' => $full_key,
            'id' => $key_id,
            'label' => $label
        );
    }

    /**
     * Validate API key
     *
     * @param string $key Full API key from Authorization header
     * @return array|false Array with key details on success, false if invalid
     */
    public static function validate_key($key) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_api_keys';

        // Hash the provided key
        $key_hash = hash('sha256', $key);

        // Look up in database
        $key_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE key_hash = %s AND is_active = 1",
            $key_hash
        ));

        if (!$key_data) {
            NewBook_Cache_Logger::log('Invalid API key attempt', NewBook_Cache_Logger::WARNING, array(
                'key_prefix' => substr($key, 0, 15) . '...'
            ));
            return false;
        }

        return array(
            'id' => $key_data->id,
            'label' => $key_data->key_label,
            'created_date' => $key_data->created_date,
            'last_used' => $key_data->last_used,
            'usage_count' => $key_data->usage_count
        );
    }

    /**
     * Track API key usage
     *
     * @param int $key_id API key ID
     * @return bool Success
     */
    public static function track_usage($key_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_api_keys';

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET usage_count = usage_count + 1,
                 last_used = %s
             WHERE id = %d",
            current_time('mysql'),
            $key_id
        ));

        return $result !== false;
    }

    /**
     * Revoke API key
     *
     * @param int $key_id API key ID
     * @return bool Success
     */
    public static function revoke_key($key_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_api_keys';

        $key_label = $wpdb->get_var($wpdb->prepare(
            "SELECT key_label FROM {$table} WHERE id = %d",
            $key_id
        ));

        $result = $wpdb->update(
            $table,
            array('is_active' => 0),
            array('id' => $key_id),
            array('%d'),
            array('%d')
        );

        if ($result !== false) {
            NewBook_Cache_Logger::log('API key revoked', NewBook_Cache_Logger::INFO, array(
                'key_id' => $key_id,
                'label' => $key_label
            ));
            return true;
        }

        return false;
    }

    /**
     * Get all API keys
     *
     * @param bool $active_only Only return active keys
     * @return array Array of key objects
     */
    public static function get_all_keys($active_only = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_api_keys';

        $where = $active_only ? 'WHERE is_active = 1' : '';
        $keys = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY created_date DESC");

        return $keys ?: array();
    }

    /**
     * Delete API key permanently
     *
     * @param int $key_id API key ID
     * @return bool Success
     */
    public static function delete_key($key_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_api_keys';

        $result = $wpdb->delete($table, array('id' => $key_id), array('%d'));

        return $result !== false;
    }

    /**
     * Get API key statistics
     *
     * @return array Statistics
     */
    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_api_keys';

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
        $total_usage = $wpdb->get_var("SELECT SUM(usage_count) FROM {$table} WHERE is_active = 1");
        $last_used = $wpdb->get_var("SELECT MAX(last_used) FROM {$table} WHERE is_active = 1");

        return array(
            'total_active_keys' => (int) $total,
            'total_usage' => (int) $total_usage,
            'last_used' => $last_used
        );
    }
}
