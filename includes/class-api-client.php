<?php
/**
 * NewBook API Client
 *
 * Handles direct communication with NewBook API
 *
 * @package NewBook_API_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NewBook_API_Client {

    private $api_base_url = 'https://api.newbook.cloud/rest/';

    /**
     * Call NewBook API
     *
     * @param string $action API action (bookings_get, bookings_list, etc.)
     * @param array $data Request parameters
     * @return array API response in NewBook format
     */
    public function call_api($action, $data = array()) {
        // Get credentials
        $username = get_option('newbook_cache_username');
        $password = get_option('newbook_cache_password');
        $api_key = get_option('newbook_cache_api_key');
        $region = get_option('newbook_cache_region', 'au');

        if (empty($username) || empty($password) || empty($api_key)) {
            NewBook_Cache_Logger::log('API credentials not configured', NewBook_Cache_Logger::ERROR);
            return array(
                'data' => array(),
                'success' => false,
                'message' => 'API credentials not configured'
            );
        }

        // Add required fields to request body
        $data['api_key'] = $api_key;
        $data['region'] = $region;

        // Build request
        $url = $this->api_base_url . $action;
        $args = array(
            'method' => 'POST',
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
            ),
            'body' => json_encode($data)
        );

        NewBook_Cache_Logger::log("API request: {$action}", NewBook_Cache_Logger::DEBUG, array(
            'url' => $url,
            'params' => $this->sanitize_params($data)
        ));

        // Make request
        $response = wp_remote_post($url, $args);

        // Handle WP_Error
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            NewBook_Cache_Logger::log("API error: {$error_msg}", NewBook_Cache_Logger::ERROR, array(
                'action' => $action
            ));

            return array(
                'data' => array(),
                'success' => false,
                'message' => $error_msg
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        // Handle non-200 response
        if ($response_code !== 200) {
            // Try to parse error response
            $error_data = json_decode($response_body, true);
            $error_message = "API returned HTTP {$response_code}";

            // Extract error message from various possible fields
            if ($error_data && is_array($error_data)) {
                if (isset($error_data['message'])) {
                    $error_message = $error_data['message'];
                } elseif (isset($error_data['error'])) {
                    $error_message = $error_data['error'];
                } elseif (isset($error_data['error_message'])) {
                    $error_message = $error_data['error_message'];
                }
            }

            NewBook_Cache_Logger::log("API returned HTTP {$response_code}", NewBook_Cache_Logger::ERROR, array(
                'action' => $action,
                'response_code' => $response_code,
                'response_body' => $response_body,
                'response_headers' => $response_headers->getAll(),
                'error_message' => $error_message
            ));

            return array(
                'data' => array(),
                'success' => false,
                'message' => $error_message,
                'http_code' => $response_code,
                'raw_response' => $response_body,
                'headers' => $response_headers->getAll()
            );
        }

        // Parse JSON response
        $response_data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            NewBook_Cache_Logger::log('JSON parse error: ' . json_last_error_msg(), NewBook_Cache_Logger::ERROR, array(
                'action' => $action
            ));

            return array(
                'data' => array(),
                'success' => false,
                'message' => 'Invalid API response'
            );
        }

        NewBook_Cache_Logger::log("API success: {$action} - " . count($response_data['data'] ?? []) . " results", NewBook_Cache_Logger::INFO);

        return $response_data;
    }

    /**
     * Sanitize parameters for logging (remove sensitive data)
     *
     * @param array $data Parameters
     * @return array Safe parameters
     */
    private function sanitize_params($data) {
        $safe_params = array();
        $safe_keys = array('booking_id', 'period_from', 'period_to', 'list_type', 'check_from', 'check_to', 'status', 'region');

        foreach ($safe_keys as $key) {
            if (isset($data[$key])) {
                $safe_params[$key] = $data[$key];
            }
        }

        // Indicate api_key is present without logging its value
        if (isset($data['api_key'])) {
            $safe_params['api_key'] = '(provided)';
        }

        return $safe_params;
    }

    /**
     * Test API connection
     *
     * @return bool|string True if successful, error message if failed
     */
    public function test_connection() {
        $response = $this->call_api('sites_list', array());

        if (!$response || !$response['success']) {
            return $response['message'] ?? 'Unknown error';
        }

        return true;
    }
}
