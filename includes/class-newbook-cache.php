<?php
/**
 * NewBook API Cache
 *
 * Core cache logic with encryption, transparent proxy, and intelligent routing
 *
 * @package NewBook_API_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NewBook_API_Cache {

    private $api_client;

    public function __construct() {
        $this->api_client = new NewBook_API_Client();

        // Register WordPress filters for integration
        add_filter('bma_newbook_api_call', array($this, 'intercept_api_call'), 10, 4);
        add_filter('bma_use_newbook_cache', array($this, 'is_caching_enabled'));
    }

    /**
     * Intercept API calls from booking-match-api
     *
     * @param mixed $response Existing response (null if not set)
     * @param string $action API action
     * @param array $data Request parameters
     * @param array $context_info Context data (caller, user, IP, route, force_refresh, etc.)
     * @return array NewBook API response format
     */
    public function intercept_api_call($response, $action, $data, $context_info = array()) {
        // If response already set by another plugin, don't override
        if ($response !== null) {
            return $response;
        }

        // Extract force_refresh from context
        $force_refresh = isset($context_info['force_refresh']) ? $context_info['force_refresh'] : false;

        // Handle the API call with context
        return $this->call_api($action, $data, $force_refresh, $context_info);
    }

    /**
     * Check if caching is enabled
     *
     * @return bool True if enabled
     */
    public function is_caching_enabled() {
        return (bool) get_option('newbook_cache_enabled', true);
    }

    /**
     * Main API gateway - routes all NewBook API requests
     *
     * @param string $action API action
     * @param array $data Request parameters
     * @param bool $force_refresh Bypass cache
     * @param array $context_info Context data (caller, user, IP, route, etc.)
     * @return array NewBook API response format
     */
    public function call_api($action, $data = array(), $force_refresh = false, $context_info = array()) {
        // Build comprehensive log message with context
        $log_message = "Request: {$action}" . ($force_refresh ? ' (force_refresh)' : '');

        if (!empty($context_info)) {
            $client_type = isset($context_info['client_type']) ? $context_info['client_type'] : 'unknown';
            $username = isset($context_info['username']) ? $context_info['username'] : 'guest';
            $ip = isset($context_info['ip_address']) ? $context_info['ip_address'] : 'unknown';
            $route = isset($context_info['route']) ? $context_info['route'] : '';
            $method = isset($context_info['method']) ? $context_info['method'] : '';

            $log_message .= " from {$client_type} (user: {$username}, IP: {$ip})";
            if ($route) {
                $log_message .= " → {$method} {$route}";
            }
        }

        NewBook_Cache_Logger::log($log_message, NewBook_Cache_Logger::DEBUG, $context_info);

        // Route to appropriate handler
        switch ($action) {
            case 'bookings_list':
                return $this->handle_bookings_list($data, $force_refresh);

            case 'bookings_get':
                return $this->handle_bookings_get($data, $force_refresh);

            case 'sites_list':
                return $this->handle_sites_list($data, $force_refresh);

            default:
                // Unknown action - check if relay is allowed
                $allow_unknown_relay = get_option('newbook_cache_allow_unknown_relay', false);

                $this->log_uncached_request($action, $data, $context_info);

                if (!$allow_unknown_relay) {
                    // Relay disabled - return error response
                    NewBook_Cache_Logger::log(
                        "BLOCKED: Unknown action '{$action}' - relay disabled for security",
                        NewBook_Cache_Logger::WARNING,
                        array_merge(array('action' => $action), $context_info)
                    );

                    return array(
                        'data' => array(),
                        'success' => false,
                        'message' => "Unknown API action '{$action}' not supported. Only bookings_list, bookings_get, and sites_list are allowed. Enable 'Allow unknown relay' in settings to relay this action."
                    );
                }

                // Relay enabled - pass through to NewBook API
                NewBook_Cache_Logger::log(
                    "Relaying unknown action '{$action}' to NewBook (relay enabled)",
                    NewBook_Cache_Logger::INFO
                );
                return $this->relay_to_newbook_api($action, $data);
        }
    }

    /**
     * Handle bookings_list requests (CACHED)
     *
     * @param array $data Request parameters
     * @param bool $force_refresh Bypass cache
     * @return array NewBook API response
     */
    private function handle_bookings_list($data, $force_refresh) {
        if ($force_refresh) {
            NewBook_Cache_Logger::log('bookings_list: force_refresh=true, bypassing cache', NewBook_Cache_Logger::INFO);
            return $this->relay_to_newbook_api('bookings_list', $data);
        }

        // Extract parameters
        $period_from = isset($data['period_from']) ? substr($data['period_from'], 0, 10) : '';
        $period_to = isset($data['period_to']) ? substr($data['period_to'], 0, 10) : '';
        $list_type = isset($data['list_type']) ? $data['list_type'] : 'staying';

        // Try cache
        $cached_bookings = $this->get_from_cache($period_from, $period_to, $list_type);

        if ($cached_bookings !== null) {
            NewBook_Cache_Logger::log("bookings_list: CACHE HIT - " . count($cached_bookings) . " bookings", NewBook_Cache_Logger::INFO);

            return array(
                'data' => $cached_bookings,
                'success' => true,
                'message' => '',
                '_cache_hit' => true
            );
        }

        // Cache miss
        NewBook_Cache_Logger::log('bookings_list: CACHE MISS - calling NewBook API', NewBook_Cache_Logger::INFO, array(
            'period_from' => $period_from,
            'period_to' => $period_to,
            'list_type' => $list_type
        ));

        // Fetch from API
        $response = $this->relay_to_newbook_api('bookings_list', $data);

        // Store fetched bookings in cache for future requests
        if ($response && isset($response['data']) && $response['success']) {
            $stored_count = 0;
            foreach ($response['data'] as $booking) {
                if ($this->store_booking($booking)) {
                    $stored_count++;
                }
            }

            if ($stored_count > 0) {
                NewBook_Cache_Logger::log("Stored {$stored_count} bookings from API response in cache", NewBook_Cache_Logger::INFO);
            }
        }

        return $response;
    }

    /**
     * Handle bookings_get requests (CACHED)
     *
     * @param array $data Request parameters
     * @param bool $force_refresh Bypass cache
     * @return array NewBook API response
     */
    private function handle_bookings_get($data, $force_refresh) {
        if ($force_refresh) {
            return $this->relay_to_newbook_api('bookings_get', $data);
        }

        $booking_id = isset($data['booking_id']) ? intval($data['booking_id']) : 0;

        // Try cache
        $cached_booking = $this->get_booking_from_cache($booking_id);

        if ($cached_booking !== null) {
            NewBook_Cache_Logger::log("bookings_get: CACHE HIT - booking #{$booking_id}", NewBook_Cache_Logger::INFO);

            return array(
                'data' => $cached_booking,
                'success' => true,
                'message' => '',
                '_cache_hit' => true
            );
        }

        // Cache miss
        NewBook_Cache_Logger::log("bookings_get: CACHE MISS - booking #{$booking_id}", NewBook_Cache_Logger::INFO);

        // Fetch from API
        $response = $this->relay_to_newbook_api('bookings_get', $data);

        // Store fetched booking in cache for future requests
        if ($response && isset($response['data']) && $response['success']) {
            if ($this->store_booking($response['data'])) {
                NewBook_Cache_Logger::log("Stored booking #{$booking_id} from API response in cache", NewBook_Cache_Logger::DEBUG);
            }
        }

        return $response;
    }

    /**
     * Handle sites_list requests (CACHED - 24 hours)
     *
     * @param array $data Request parameters
     * @param bool $force_refresh Bypass cache
     * @return array NewBook API response
     */
    private function handle_sites_list($data, $force_refresh) {
        if ($force_refresh) {
            delete_transient('newbook_cache_sites');
            NewBook_Cache_Logger::log('sites_list: force_refresh=true, cleared cache', NewBook_Cache_Logger::DEBUG);
        }

        // Sites change rarely - cache for 24 hours
        $cached_sites = get_transient('newbook_cache_sites');

        if ($cached_sites !== false) {
            NewBook_Cache_Logger::log("sites_list: CACHE HIT - " . count($cached_sites) . " sites", NewBook_Cache_Logger::INFO);

            return array(
                'data' => $cached_sites,
                'success' => true,
                'message' => '',
                '_cache_hit' => true
            );
        }

        // Cache miss - fetch and cache
        NewBook_Cache_Logger::log('sites_list: CACHE MISS - calling NewBook API', NewBook_Cache_Logger::INFO);
        $response = $this->relay_to_newbook_api('sites_list', $data);

        if ($response && isset($response['data']) && $response['success']) {
            set_transient('newbook_cache_sites', $response['data'], DAY_IN_SECONDS);
            NewBook_Cache_Logger::log('sites_list: Cached ' . count($response['data']) . ' sites for 24 hours', NewBook_Cache_Logger::DEBUG);
        }

        return $response;
    }

    /**
     * Relay to NewBook API (for uncached/unknown actions)
     *
     * @param string $action API action
     * @param array $data Request parameters
     * @return array NewBook API response
     */
    private function relay_to_newbook_api($action, $data) {
        NewBook_Cache_Logger::log("Relay to NewBook API: {$action}", NewBook_Cache_Logger::DEBUG);
        return $this->api_client->call_api($action, $data);
    }

    /**
     * Get bookings from cache
     *
     * @param string $from_date Date from (YYYY-MM-DD)
     * @param string $to_date Date to (YYYY-MM-DD)
     * @param string $list_type Filter type (staying, cancelled, etc.)
     * @return array|null Bookings array or null if cache miss
     */
    private function get_from_cache($from_date, $to_date, $list_type) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache';

        // Build query based on list_type
        $where_clause = $wpdb->prepare(
            "arrival_date <= %s AND departure_date > %s",
            $to_date,
            $from_date
        );

        // Filter by status for list_type
        if ($list_type === 'staying') {
            $where_clause .= " AND booking_status NOT IN ('cancelled', 'no_show')";
        } elseif ($list_type === 'cancelled') {
            $where_clause .= " AND booking_status = 'cancelled'";
        }

        $results = $wpdb->get_results("SELECT encrypted_data FROM {$table} WHERE {$where_clause}");

        if (empty($results)) {
            return null; // Cache miss
        }

        // Decrypt all bookings
        $bookings = array();
        foreach ($results as $row) {
            $booking = $this->decrypt_booking_data($row->encrypted_data);
            if ($booking) {
                $bookings[] = $booking;
            }
        }

        return $bookings;
    }

    /**
     * Get single booking from cache
     *
     * @param int $booking_id Booking ID
     * @return array|null Booking data or null if not found
     */
    private function get_booking_from_cache($booking_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT encrypted_data FROM {$table} WHERE booking_id = %d",
            $booking_id
        ));

        if (!$result) {
            return null;
        }

        return $this->decrypt_booking_data($result);
    }

    /**
     * Store booking in cache
     *
     * @param array $booking Booking data
     * @return bool Success
     */
    public function store_booking($booking) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache';

        // Extract operational data
        $booking_id = isset($booking['booking_id']) ? intval($booking['booking_id']) : 0;
        $arrival = isset($booking['booking_arrival']) ? substr($booking['booking_arrival'], 0, 10) : '';
        $departure = isset($booking['booking_departure']) ? substr($booking['booking_departure'], 0, 10) : '';
        $room = isset($booking['site_name']) ? $booking['site_name'] : '';
        $guests = (isset($booking['booking_adults']) ? intval($booking['booking_adults']) : 0) +
                  (isset($booking['booking_children']) ? intval($booking['booking_children']) : 0);
        $status = isset($booking['booking_status']) ? strtolower($booking['booking_status']) : 'confirmed';
        $group_id = isset($booking['group_id']) ? $booking['group_id'] : null;

        // Determine cache type
        $cache_type = (strtotime($departure) >= strtotime('today')) ? 'hot' : 'historical';

        // Encrypt full booking data
        $encrypted_data = $this->encrypt_booking_data($booking);

        if (!$encrypted_data) {
            NewBook_Cache_Logger::log("Failed to encrypt booking #{$booking_id}", NewBook_Cache_Logger::ERROR);
            return false;
        }

        // Insert or update
        $result = $wpdb->replace($table, array(
            'booking_id' => $booking_id,
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'booking_status' => $status,
            'group_id' => $group_id,
            'room_name' => $room,
            'num_guests' => $guests,
            'encrypted_data' => $encrypted_data,
            'last_updated' => current_time('mysql'),
            'cache_type' => $cache_type
        ));

        return $result !== false;
    }

    /**
     * Encrypt booking data
     *
     * @param array $data Booking data
     * @return string|false Encrypted data or false on failure
     */
    private function encrypt_booking_data($data) {
        $key = wp_salt('auth');
        $iv = openssl_random_pseudo_bytes(16);

        $encrypted = openssl_encrypt(
            serialize($data),
            'AES-256-CBC',
            hash('sha256', $key),
            0,
            $iv
        );

        if ($encrypted === false) {
            return false;
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt booking data
     *
     * @param string $encrypted_data Encrypted data
     * @return array|null Booking data or null on failure
     */
    private function decrypt_booking_data($encrypted_data) {
        $key = wp_salt('auth');
        $data = base64_decode($encrypted_data);

        if ($data === false || strlen($data) < 16) {
            NewBook_Cache_Logger::log('Decryption failed: Invalid data', NewBook_Cache_Logger::ERROR);
            return null;
        }

        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            hash('sha256', $key),
            0,
            $iv
        );

        if ($decrypted === false) {
            NewBook_Cache_Logger::log('Decryption failed: openssl_decrypt failed', NewBook_Cache_Logger::ERROR);
            return null;
        }

        return unserialize($decrypted);
    }

    /**
     * Log uncached request for monitoring
     *
     * @param string $action API action
     * @param array $data Request parameters
     * @param array $context_info Context data
     */
    private function log_uncached_request($action, $data, $context_info = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache_uncached_requests';

        // Build caller string from context
        $caller = 'unknown';
        if (!empty($context_info)) {
            $client_type = isset($context_info['client_type']) ? $context_info['client_type'] : '';
            $route = isset($context_info['route']) ? $context_info['route'] : '';
            $bma_caller = isset($context_info['caller']) ? $context_info['caller'] : '';

            if ($client_type && $route) {
                $caller = "{$client_type} → {$route}";
                if ($bma_caller) {
                    $caller .= " → {$bma_caller}";
                }
            } elseif ($bma_caller) {
                $caller = $bma_caller;
            }
        }

        $wpdb->insert($table, array(
            'action' => $action,
            'params' => json_encode($this->sanitize_params($data)),
            'timestamp' => current_time('mysql'),
            'caller' => $caller
        ));

        NewBook_Cache_Logger::log("UNCACHED REQUEST: {$action} - consider adding caching support", NewBook_Cache_Logger::WARNING, array_merge(
            array('action' => $action),
            $context_info
        ));
    }

    /**
     * Get calling function for debugging
     *
     * @return string Caller info
     */
    private function get_caller() {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        if (isset($backtrace[4])) {
            $caller_class = isset($backtrace[4]['class']) ? $backtrace[4]['class'] : '';
            $caller_function = isset($backtrace[4]['function']) ? $backtrace[4]['function'] : '';
            return $caller_class ? "{$caller_class}::{$caller_function}" : $caller_function;
        }

        return 'unknown';
    }

    /**
     * Sanitize parameters for logging
     *
     * @param array $data Parameters
     * @return array Safe parameters
     */
    private function sanitize_params($data) {
        $safe_params = array();
        $safe_keys = array('booking_id', 'period_from', 'period_to', 'list_type');

        foreach ($safe_keys as $key) {
            if (isset($data[$key])) {
                $safe_params[$key] = $data[$key];
            }
        }

        return $safe_params;
    }

    /**
     * Clear cache for specific booking
     *
     * @param int $booking_id Booking ID
     * @return bool Success
     */
    public function clear_booking_cache($booking_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache';

        $result = $wpdb->delete($table, array('booking_id' => $booking_id));

        if ($result) {
            NewBook_Cache_Logger::log("Cleared cache for booking #{$booking_id}", NewBook_Cache_Logger::INFO);
        }

        return $result !== false;
    }

    /**
     * Clear all cache
     *
     * @return bool Success
     */
    public function clear_all_cache() {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache';

        $wpdb->query("TRUNCATE TABLE {$table}");
        delete_transient('newbook_cache_sites');

        NewBook_Cache_Logger::log('All cache cleared', NewBook_Cache_Logger::INFO);

        return true;
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics
     */
    public function get_cache_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache';

        $total_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $hot_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE cache_type = 'hot'");
        $historical_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE cache_type = 'historical'");
        $cancelled_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE booking_status = 'cancelled'");

        $db_size = $wpdb->get_var("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()
            AND table_name = '{$table}'
        ");

        return array(
            'total_bookings' => (int) $total_bookings,
            'hot_bookings' => (int) $hot_bookings,
            'historical_bookings' => (int) $historical_bookings,
            'cancelled_bookings' => (int) $cancelled_bookings,
            'database_size_mb' => (float) $db_size
        );
    }
}
