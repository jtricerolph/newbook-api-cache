<?php
/**
 * NewBook API Cache - REST API Controller
 *
 * Provides NewBook-compatible REST endpoints with dual authentication
 *
 * @package NewBook_API_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NewBook_Cache_REST_Controller {

    private $namespace = 'newbook-cache/v1';
    private $cache;

    public function __construct() {
        global $newbook_api_cache;
        $this->cache = $newbook_api_cache;
    }

    /**
     * Register REST routes
     */
    public function register_routes() {
        // POST /bookings/list - Fetch bookings by date range
        register_rest_route($this->namespace, '/bookings/list', array(
            'methods' => 'POST',
            'callback' => array($this, 'bookings_list'),
            'permission_callback' => array($this, 'authenticate_request'),
        ));

        // POST /bookings/get - Fetch single booking by ID
        register_rest_route($this->namespace, '/bookings/get', array(
            'methods' => 'POST',
            'callback' => array($this, 'bookings_get'),
            'permission_callback' => array($this, 'authenticate_request'),
        ));

        // POST /sites/list - Fetch room/site inventory
        register_rest_route($this->namespace, '/sites/list', array(
            'methods' => 'POST',
            'callback' => array($this, 'sites_list'),
            'permission_callback' => array($this, 'authenticate_request'),
        ));

        // GET /cache/stats - Cache statistics
        register_rest_route($this->namespace, '/cache/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'cache_stats'),
            'permission_callback' => array($this, 'authenticate_request'),
        ));
    }

    /**
     * Authenticate request - Supports WordPress app passwords AND API keys
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error True if authenticated, WP_Error if not
     */
    public function authenticate_request($request) {
        // Try WordPress authentication first (app passwords, cookies, nonce)
        $wp_auth = $this->authenticate_wordpress($request);
        if (!is_wp_error($wp_auth)) {
            // Store auth details for logging
            $request->set_param('_auth_method', 'wordpress');
            $request->set_param('_auth_user', $wp_auth['username']);
            $request->set_param('_auth_user_id', $wp_auth['user_id']);
            return true;
        }

        // Try API key authentication
        $api_auth = $this->authenticate_api_key($request);
        if (!is_wp_error($api_auth)) {
            // Store auth details for logging
            $request->set_param('_auth_method', 'api_key');
            $request->set_param('_auth_user', 'api_key:' . $api_auth['label']);
            $request->set_param('_auth_key_id', $api_auth['id']);

            // Track API key usage
            NewBook_API_Key_Manager::track_usage($api_auth['id']);
            return true;
        }

        // Both methods failed
        return new WP_Error(
            'rest_forbidden',
            __('Authentication required. Provide WordPress application password or API key.', 'newbook-api-cache'),
            array('status' => 401)
        );
    }

    /**
     * Authenticate via WordPress (app passwords, cookies)
     *
     * @param WP_REST_Request $request
     * @return array|WP_Error User details on success, WP_Error on failure
     */
    private function authenticate_wordpress($request) {
        $user = wp_get_current_user();

        // If already authenticated via cookie/session
        if ($user && $user->ID > 0) {
            return array(
                'user_id' => $user->ID,
                'username' => $user->user_login
            );
        }

        // Try HTTP Basic Auth (application passwords)
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            $user = wp_authenticate($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);

            if ($user && !is_wp_error($user)) {
                wp_set_current_user($user->ID);
                return array(
                    'user_id' => $user->ID,
                    'username' => $user->user_login
                );
            }
        }

        return new WP_Error('rest_forbidden', 'WordPress authentication failed');
    }

    /**
     * Authenticate via API key
     *
     * @param WP_REST_Request $request
     * @return array|WP_Error Key details on success, WP_Error on failure
     */
    private function authenticate_api_key($request) {
        $auth_header = $request->get_header('authorization');

        if (empty($auth_header)) {
            return new WP_Error('rest_forbidden', 'No authorization header');
        }

        // Check for Bearer token
        if (strpos($auth_header, 'Bearer ') === 0) {
            $api_key = substr($auth_header, 7); // Remove "Bearer "

            // Validate key
            $key_data = NewBook_API_Key_Manager::validate_key($api_key);

            if ($key_data) {
                return $key_data;
            }

            return new WP_Error('rest_forbidden', 'Invalid API key');
        }

        return new WP_Error('rest_forbidden', 'Invalid authorization format');
    }

    /**
     * Build request context for logging
     *
     * @param WP_REST_Request $request
     * @return array Context data
     */
    private function build_request_context($request) {
        // Use booking-match-api helper if available
        if (function_exists('bma_get_request_context')) {
            $context = bma_get_request_context($request);
        } else {
            // Fallback context building
            $current_user = wp_get_current_user();
            $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';

            // Apply IP anonymization if enabled
            $anonymize = get_option('newbook_cache_anonymize_ips', true);
            if ($anonymize && function_exists('bma_anonymize_ip')) {
                $ip_address = bma_anonymize_ip($ip_address);
            }

            $context = array(
                'user_id' => $current_user->ID ?: 0,
                'username' => $current_user->user_login ?: 'guest',
                'ip_address' => $ip_address,
                'user_agent' => $request->get_header('user_agent') ?: 'unknown',
                'route' => $request->get_route(),
                'method' => $request->get_method(),
                'referrer' => $request->get_header('referer') ?: 'none',
                'origin' => $request->get_header('origin') ?: 'none',
                'timestamp' => current_time('mysql'),
                'client_type' => $this->identify_client_type($request),
            );
        }

        // Add authentication details
        $context['auth_method'] = $request->get_param('_auth_method') ?: 'unknown';
        $context['auth_user'] = $request->get_param('_auth_user') ?: 'unknown';

        if ($context['auth_method'] === 'api_key') {
            $context['api_key_id'] = $request->get_param('_auth_key_id');
        }

        return $context;
    }

    /**
     * Identify client type (fallback if bma function not available)
     *
     * @param WP_REST_Request $request
     * @return string Client type
     */
    private function identify_client_type($request) {
        if (function_exists('bma_identify_client_type')) {
            return bma_identify_client_type($request);
        }

        $user_agent = $request->get_header('user_agent') ?: '';

        if (stripos($user_agent, 'curl') !== false) return 'curl';
        if (stripos($user_agent, 'postman') !== false) return 'postman';
        if (stripos($user_agent, 'chrome') !== false) return 'browser';

        return 'unknown';
    }

    /**
     * Endpoint: POST /bookings/list
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function bookings_list($request) {
        $context = $this->build_request_context($request);

        $data = array(
            'period_from' => $request->get_param('period_from'),
            'period_to' => $request->get_param('period_to'),
            'list_type' => $request->get_param('list_type'),
            'booking_id' => $request->get_param('booking_id'),
        );

        $force_refresh = $request->get_param('force_refresh') ?: false;

        $response = $this->cache->call_api('bookings_list', $data, $force_refresh, $context);

        return rest_ensure_response($response);
    }

    /**
     * Endpoint: POST /bookings/get
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function bookings_get($request) {
        $context = $this->build_request_context($request);

        $data = array(
            'booking_id' => $request->get_param('booking_id'),
        );

        $force_refresh = $request->get_param('force_refresh') ?: false;

        $response = $this->cache->call_api('bookings_get', $data, $force_refresh, $context);

        return rest_ensure_response($response);
    }

    /**
     * Endpoint: POST /sites/list
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function sites_list($request) {
        $context = $this->build_request_context($request);

        $data = array();
        $force_refresh = $request->get_param('force_refresh') ?: false;

        $response = $this->cache->call_api('sites_list', $data, $force_refresh, $context);

        return rest_ensure_response($response);
    }

    /**
     * Endpoint: GET /cache/stats
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function cache_stats($request) {
        $context = $this->build_request_context($request);

        NewBook_Cache_Logger::log('Cache stats requested', NewBook_Cache_Logger::INFO, $context);

        $stats = $this->cache->get_cache_stats();
        $api_key_stats = NewBook_API_Key_Manager::get_stats();

        return rest_ensure_response(array(
            'success' => true,
            'cache' => $stats,
            'api_keys' => $api_key_stats,
        ));
    }

    /**
     * Get endpoint documentation metadata
     *
     * @return array Endpoint documentation
     */
    public static function get_endpoint_docs() {
        return array(
            'bookings_list' => array(
                'path' => '/wp-json/newbook-cache/v1/bookings/list',
                'method' => 'POST',
                'newbook_action' => 'bookings_list',
                'cached' => true,
                'description' => 'Fetch bookings within a date range',
                'parameters' => array(
                    'period_from' => array(
                        'required' => true,
                        'type' => 'string',
                        'description' => 'Start date/time in format YYYY-MM-DD HH:MM:SS',
                        'example' => '2025-11-01 00:00:00'
                    ),
                    'period_to' => array(
                        'required' => true,
                        'type' => 'string',
                        'description' => 'End date/time in format YYYY-MM-DD HH:MM:SS',
                        'example' => '2025-11-30 23:59:59'
                    ),
                    'list_type' => array(
                        'required' => false,
                        'type' => 'string',
                        'description' => 'Filter bookings by type: staying | placed | cancelled | all',
                        'example' => 'staying'
                    ),
                    'booking_id' => array(
                        'required' => false,
                        'type' => 'integer',
                        'description' => 'Specific booking ID to fetch',
                        'example' => '123456'
                    ),
                    'force_refresh' => array(
                        'required' => false,
                        'type' => 'boolean',
                        'description' => 'Bypass cache and fetch fresh data from NewBook API',
                        'example' => 'false'
                    ),
                ),
                'response' => '{"success": true, "data": [...array of booking objects...]}'
            ),
            'bookings_get' => array(
                'path' => '/wp-json/newbook-cache/v1/bookings/get',
                'method' => 'POST',
                'newbook_action' => 'bookings_get',
                'cached' => true,
                'description' => 'Fetch a single booking by ID',
                'parameters' => array(
                    'booking_id' => array(
                        'required' => true,
                        'type' => 'integer',
                        'description' => 'NewBook booking ID',
                        'example' => '123456'
                    ),
                    'force_refresh' => array(
                        'required' => false,
                        'type' => 'boolean',
                        'description' => 'Bypass cache and fetch fresh data',
                        'example' => 'false'
                    ),
                ),
                'response' => '{"success": true, "data": {...booking object...}}'
            ),
            'sites_list' => array(
                'path' => '/wp-json/newbook-cache/v1/sites/list',
                'method' => 'POST',
                'newbook_action' => 'sites_list',
                'cached' => true,
                'description' => 'Fetch list of all sites/rooms',
                'parameters' => array(
                    'force_refresh' => array(
                        'required' => false,
                        'type' => 'boolean',
                        'description' => 'Bypass cache and fetch fresh data',
                        'example' => 'false'
                    ),
                ),
                'response' => '{"success": true, "data": [...array of site objects...]}'
            ),
            'cache_stats' => array(
                'path' => '/wp-json/newbook-cache/v1/cache/stats',
                'method' => 'GET',
                'newbook_action' => 'N/A (cache-only)',
                'cached' => false,
                'description' => 'Get cache statistics and API key usage',
                'parameters' => array(),
                'response' => '{"success": true, "cache": {...stats...}, "api_keys": {...stats...}}'
            ),
        );
    }
}
