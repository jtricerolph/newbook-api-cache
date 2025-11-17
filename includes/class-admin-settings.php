<?php
/**
 * NewBook Cache Admin Settings
 *
 * Admin settings page with tabs for configuration
 *
 * @package NewBook_API_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NewBook_Cache_Admin_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Handle manual actions
        add_action('admin_post_newbook_cache_clear_cache', array($this, 'handle_clear_cache'));
        add_action('admin_post_newbook_cache_full_refresh', array($this, 'handle_full_refresh'));
        add_action('admin_post_newbook_cache_incremental_sync', array($this, 'handle_incremental_sync'));
        add_action('admin_post_newbook_cache_reschedule_cron', array($this, 'handle_reschedule_cron'));
        add_action('admin_post_newbook_cache_clear_logs', array($this, 'handle_clear_logs'));
        add_action('admin_post_newbook_cache_generate_api_key', array($this, 'handle_generate_api_key'));
        add_action('admin_post_newbook_cache_revoke_api_key', array($this, 'handle_revoke_api_key'));
        add_action('admin_post_newbook_cache_test_connection', array($this, 'handle_test_connection'));

        // AJAX handlers
        add_action('wp_ajax_newbook_cache_refresh_logs', array($this, 'ajax_refresh_logs'));

        // Reschedule cron when sync interval changes
        add_action('update_option_newbook_cache_sync_interval', array($this, 'reschedule_sync_on_interval_change'), 10, 2);
    }

    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page() {
        add_options_page(
            __('NewBook API Cache Settings', 'newbook-api-cache'),
            __('NewBook Cache', 'newbook-api-cache'),
            'manage_options',
            'newbook-cache-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API Credentials (separate group)
        register_setting('newbook_cache_credentials', 'newbook_cache_username', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('newbook_cache_credentials', 'newbook_cache_password', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('newbook_cache_credentials', 'newbook_cache_api_key', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('newbook_cache_credentials', 'newbook_cache_region', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'au'));

        // Cache Settings (separate group)
        register_setting('newbook_cache_cache_settings', 'newbook_cache_enabled', array('sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('newbook_cache_cache_settings', 'newbook_cache_enable_incremental_sync', array('sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('newbook_cache_cache_settings', 'newbook_cache_enable_daily_refresh', array('sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('newbook_cache_cache_settings', 'newbook_cache_sync_interval', array('sanitize_callback' => 'absint', 'default' => 20));
        register_setting('newbook_cache_cache_settings', 'newbook_cache_retention_future', array('sanitize_callback' => 'absint', 'default' => 365));
        register_setting('newbook_cache_cache_settings', 'newbook_cache_retention_past', array('sanitize_callback' => 'absint', 'default' => 30));
        register_setting('newbook_cache_cache_settings', 'newbook_cache_retention_cancelled', array('sanitize_callback' => 'absint', 'default' => 30));
        register_setting('newbook_cache_cache_settings', 'newbook_cache_allow_unknown_relay', array('sanitize_callback' => 'rest_sanitize_boolean', 'default' => false));

        // Logging (separate group)
        register_setting('newbook_cache_logging', 'newbook_cache_log_level', array('sanitize_callback' => 'absint', 'default' => NewBook_Cache_Logger::INFO));
        register_setting('newbook_cache_logging', 'newbook_cache_max_logs', array('sanitize_callback' => 'absint', 'default' => 1000));

        // Privacy & GDPR (same group as logging since they're on the same form)
        register_setting('newbook_cache_logging', 'newbook_cache_anonymize_ips', array('sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('newbook_cache_logging', 'newbook_cache_log_retention_days', array('sanitize_callback' => 'absint', 'default' => 30));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_newbook-cache-settings') {
            return;
        }

        wp_enqueue_style('newbook-cache-admin', NEWBOOK_CACHE_PLUGIN_URL . 'assets/css/admin.css', array(), NEWBOOK_CACHE_VERSION);

        // Enqueue JavaScript for AJAX log refresh
        $inline_js = "
        jQuery(document).ready(function($) {
            $('#newbook-cache-refresh-logs').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var originalText = button.text();

                // Disable button and show loading state
                button.prop('disabled', true).text('Refreshing...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'newbook_cache_refresh_logs',
                        nonce: '" . wp_create_nonce('newbook_cache_refresh_logs') . "'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the logs table
                            $('#newbook-cache-logs-tbody').html(response.data.html);
                            // Update the count in the header
                            $('#newbook-cache-logs-count').text(response.data.count);
                        } else {
                            alert('Failed to refresh logs: ' + (response.data.message || 'Unknown error'));
                        }
                    },
                    error: function() {
                        alert('Failed to refresh logs. Please try again.');
                    },
                    complete: function() {
                        // Re-enable button and restore text
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        ";
        wp_add_inline_script('jquery', $inline_js);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'credentials';

        global $newbook_api_cache;
        $stats = $newbook_api_cache ? $newbook_api_cache->get_cache_stats() : array();

        ?>
        <div class="wrap">
            <h1><?php _e('NewBook API Cache Settings', 'newbook-api-cache'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=newbook-cache-settings&tab=credentials" class="nav-tab <?php echo $active_tab === 'credentials' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API Credentials', 'newbook-api-cache'); ?>
                </a>
                <a href="?page=newbook-cache-settings&tab=cache" class="nav-tab <?php echo $active_tab === 'cache' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Cache Settings', 'newbook-api-cache'); ?>
                </a>
                <a href="?page=newbook-cache-settings&tab=status" class="nav-tab <?php echo $active_tab === 'status' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Status & Diagnostics', 'newbook-api-cache'); ?>
                </a>
                <a href="?page=newbook-cache-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logging & Debug', 'newbook-api-cache'); ?>
                </a>
                <a href="?page=newbook-cache-settings&tab=api-access" class="nav-tab <?php echo $active_tab === 'api-access' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API Access', 'newbook-api-cache'); ?>
                </a>
                <a href="?page=newbook-cache-settings&tab=api-docs" class="nav-tab <?php echo $active_tab === 'api-docs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API Documentation', 'newbook-api-cache'); ?>
                </a>
            </h2>

            <?php
            switch ($active_tab) {
                case 'credentials':
                    $this->render_credentials_tab();
                    break;
                case 'cache':
                    $this->render_cache_tab($stats);
                    break;
                case 'status':
                    $this->render_status_tab($stats);
                    break;
                case 'logs':
                    $this->render_logs_tab();
                    break;
                case 'api-access':
                    $this->render_api_access_tab();
                    break;
                case 'api-docs':
                    $this->render_api_docs_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render API Credentials tab
     */
    private function render_credentials_tab() {
        $username = get_option('newbook_cache_username');
        $password = get_option('newbook_cache_password');
        $api_key = get_option('newbook_cache_api_key');
        $region = get_option('newbook_cache_region', 'au');

        // Display test connection results
        if (isset($_GET['test_result'])) {
            $test_result = sanitize_text_field($_GET['test_result']);

            switch ($test_result) {
                case 'success':
                    $response_time = isset($_GET['response_time']) ? absint($_GET['response_time']) : 0;
                    $test_region = isset($_GET['region']) ? sanitize_text_field($_GET['region']) : $region;
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p>
                            <strong><?php _e('Connection Successful!', 'newbook-api-cache'); ?></strong>
                        </p>
                        <p>
                            <?php _e('Your NewBook API credentials are valid and working correctly.', 'newbook-api-cache'); ?>
                        </p>
                        <ul>
                            <li><strong><?php _e('Region:', 'newbook-api-cache'); ?></strong> <?php echo esc_html(strtoupper($test_region)); ?></li>
                            <li><strong><?php _e('Response Time:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($response_time); ?>ms</li>
                            <li><strong><?php _e('Endpoint:', 'newbook-api-cache'); ?></strong> https://api.newbook.cloud/rest/sites_list</li>
                        </ul>
                    </div>
                    <?php
                    break;

                case 'missing_credentials':
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong><?php _e('Missing Credentials', 'newbook-api-cache'); ?></strong>
                        </p>
                        <p><?php _e('Please configure all required fields (Username, Password, and API Key) before testing the connection.', 'newbook-api-cache'); ?></p>
                    </div>
                    <?php
                    break;

                case 'auth_failed':
                    $status_code = isset($_GET['status_code']) ? absint($_GET['status_code']) : 401;
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong><?php _e('Authentication Failed', 'newbook-api-cache'); ?></strong>
                        </p>
                        <p><?php _e('The NewBook API rejected your credentials. Please check your Username and Password are correct.', 'newbook-api-cache'); ?></p>
                        <p><strong><?php _e('HTTP Status:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($status_code); ?></p>
                    </div>
                    <?php
                    break;

                case 'precondition_failed':
                    $status_code = isset($_GET['status_code']) ? absint($_GET['status_code']) : 412;
                    $error_msg = isset($_GET['test_error']) ? urldecode(sanitize_text_field($_GET['test_error'])) : 'Precondition failed';
                    $test_region = isset($_GET['region']) ? sanitize_text_field($_GET['region']) : $region;
                    $raw_response = isset($_GET['raw_response']) ? urldecode($_GET['raw_response']) : '';
                    $debug_info = isset($_GET['debug_info']) ? json_decode(urldecode($_GET['debug_info']), true) : array();
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong><?php _e('Region/API Key Mismatch', 'newbook-api-cache'); ?></strong>
                        </p>
                        <p><?php _e('The NewBook API returned HTTP 412 (Precondition Failed). This typically means:', 'newbook-api-cache'); ?></p>
                        <ul style="margin-left: 20px;">
                            <li><?php _e('Your API Key does not match the selected Region', 'newbook-api-cache'); ?></li>
                            <li><?php _e('The API Key is for a different NewBook region than you selected', 'newbook-api-cache'); ?></li>
                            <li><?php _e('Your account doesn\'t have access to this region', 'newbook-api-cache'); ?></li>
                        </ul>
                        <p>
                            <strong><?php _e('Current Region:', 'newbook-api-cache'); ?></strong> <?php echo esc_html(strtoupper($test_region)); ?>
                            <br>
                            <strong><?php _e('Suggestion:', 'newbook-api-cache'); ?></strong>
                            <?php _e('Try changing the Region setting to match where your NewBook account is located (Australia, New Zealand, Europe, or United States).', 'newbook-api-cache'); ?>
                        </p>
                        <p><strong><?php _e('HTTP Status:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($status_code); ?></p>
                        <p><strong><?php _e('Error Message:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($error_msg); ?></p>

                        <?php if (!empty($debug_info)): ?>
                            <details style="margin-top: 10px;" open>
                                <summary style="cursor: pointer; font-weight: 600; font-size: 14px; color: #d63638;"><?php _e('Show Complete Debug Information', 'newbook-api-cache'); ?></summary>
                                <div style="margin-top: 10px; padding: 15px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">
                                    <table style="width: 100%; border-collapse: collapse;">
                                        <?php foreach ($debug_info as $key => $value): ?>
                                            <tr style="border-bottom: 1px solid #f0f0f1;">
                                                <td style="padding: 8px; font-weight: 600; vertical-align: top; width: 200px;"><?php echo esc_html($key); ?>:</td>
                                                <td style="padding: 8px; font-family: monospace; font-size: 12px; word-break: break-all;">
                                                    <?php
                                                    if (strlen($value) > 200 && strpos($key, 'Header') === false) {
                                                        echo '<pre style="margin: 0; white-space: pre-wrap; max-height: 200px; overflow-y: auto;">' . esc_html($value) . '</pre>';
                                                    } else {
                                                        echo esc_html($value);
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </details>
                        <?php elseif (!empty($raw_response)): ?>
                            <details style="margin-top: 10px;">
                                <summary style="cursor: pointer; font-weight: 600;"><?php _e('Show Raw API Response', 'newbook-api-cache'); ?></summary>
                                <pre style="margin-top: 10px; padding: 10px; background: #f0f0f1; overflow-x: auto; font-size: 12px;"><?php echo esc_html($raw_response); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                    <?php
                    break;

                case 'api_error':
                    $status_code = isset($_GET['status_code']) ? absint($_GET['status_code']) : 0;
                    $error_msg = isset($_GET['test_error']) ? urldecode(sanitize_text_field($_GET['test_error'])) : 'Unknown error';
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong><?php _e('API Error', 'newbook-api-cache'); ?></strong>
                        </p>
                        <p><?php _e('The NewBook API returned an error. This may indicate an incorrect API Key or Region setting.', 'newbook-api-cache'); ?></p>
                        <p><strong><?php _e('HTTP Status:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($status_code); ?></p>
                        <p><strong><?php _e('Error Message:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($error_msg); ?></p>
                    </div>
                    <?php
                    break;

                case 'error':
                    $error_msg = isset($_GET['test_error']) ? urldecode(sanitize_text_field($_GET['test_error'])) : 'Unknown error';
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong><?php _e('Connection Error', 'newbook-api-cache'); ?></strong>
                        </p>
                        <p><?php _e('Failed to connect to the NewBook API. This may be a network issue.', 'newbook-api-cache'); ?></p>
                        <p><strong><?php _e('Error:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($error_msg); ?></p>
                    </div>
                    <?php
                    break;
            }
        }
        ?>

        <form method="post" action="options.php">
            <?php settings_fields('newbook_cache_credentials'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('NewBook Username', 'newbook-api-cache'); ?></th>
                    <td>
                        <input type="text" name="newbook_cache_username" value="<?php echo esc_attr($username); ?>" class="regular-text" />
                        <p class="description"><?php _e('Your NewBook API username', 'newbook-api-cache'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('NewBook Password', 'newbook-api-cache'); ?></th>
                    <td>
                        <input type="password" name="newbook_cache_password" value="<?php echo esc_attr($password); ?>" class="regular-text" />
                        <p class="description"><?php _e('Your NewBook API password', 'newbook-api-cache'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('NewBook API Key', 'newbook-api-cache'); ?></th>
                    <td>
                        <input type="text" name="newbook_cache_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                        <p class="description"><?php _e('Your NewBook API key', 'newbook-api-cache'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Region', 'newbook-api-cache'); ?></th>
                    <td>
                        <select name="newbook_cache_region">
                            <option value="au" <?php selected($region, 'au'); ?>>Australia (au)</option>
                            <option value="nz" <?php selected($region, 'nz'); ?>>New Zealand (nz)</option>
                            <option value="eu" <?php selected($region, 'eu'); ?>>Europe (eu)</option>
                            <option value="us" <?php selected($region, 'us'); ?>>United States (us)</option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <p>
            <a href="<?php echo admin_url('admin-post.php?action=newbook_cache_test_connection'); ?>" class="button">
                <?php _e('Test Connection', 'newbook-api-cache'); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Render Cache Settings tab
     */
    private function render_cache_tab($stats) {
        $enabled = get_option('newbook_cache_enabled', true);
        $enable_incremental_sync = get_option('newbook_cache_enable_incremental_sync', true);
        $enable_daily_refresh = get_option('newbook_cache_enable_daily_refresh', true);
        $sync_interval = get_option('newbook_cache_sync_interval', 20);
        $allow_unknown_relay = get_option('newbook_cache_allow_unknown_relay', false);
        $retention_future = get_option('newbook_cache_retention_future', 365);
        $retention_past = get_option('newbook_cache_retention_past', 30);
        $retention_cancelled = get_option('newbook_cache_retention_cancelled', 30);

        $last_full_refresh = get_option('newbook_cache_last_full_refresh', 'Never');
        $last_incremental_sync = get_option('newbook_cache_last_incremental_sync', 'Never');
        $last_cleanup = get_option('newbook_cache_last_cleanup', 'Never');

        ?>
        <form method="post" action="options.php">
            <?php settings_fields('newbook_cache_cache_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Caching', 'newbook-api-cache'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="newbook_cache_enabled" value="1" <?php checked($enabled, true); ?> />
                            <?php _e('Enable NewBook API caching', 'newbook-api-cache'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Master switch - when disabled, all caching and sync operations are stopped.', 'newbook-api-cache'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Automated Sync', 'newbook-api-cache'); ?></th>
                    <td>
                        <p>
                            <label>
                                <input type="checkbox" name="newbook_cache_enable_incremental_sync" value="1" <?php checked($enable_incremental_sync, true); ?> />
                                <?php _e('Enable Incremental Sync', 'newbook-api-cache'); ?>
                            </label>
                        </p>
                        <p class="description" style="margin-left: 25px;">
                            <?php _e('Automatically check for booking changes every few seconds.', 'newbook-api-cache'); ?>
                        </p>
                        <p style="margin-top: 10px;">
                            <label>
                                <input type="checkbox" name="newbook_cache_enable_daily_refresh" value="1" <?php checked($enable_daily_refresh, true); ?> />
                                <?php _e('Enable Daily Full Refresh', 'newbook-api-cache'); ?>
                            </label>
                        </p>
                        <p class="description" style="margin-left: 25px;">
                            <?php _e('Perform complete cache rebuild daily at 3 AM.', 'newbook-api-cache'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Incremental Sync Interval', 'newbook-api-cache'); ?></th>
                    <td>
                        <input type="number" name="newbook_cache_sync_interval" value="<?php echo esc_attr($sync_interval); ?>" min="10" max="300" class="small-text" />
                        <?php _e('seconds', 'newbook-api-cache'); ?>
                        <p class="description">
                            <?php _e('How often to check NewBook API for booking updates (10-300 seconds).', 'newbook-api-cache'); ?>
                            <br />
                            <strong><?php _e('Recommended:', 'newbook-api-cache'); ?></strong> <?php _e('20-60 seconds for most properties. Lower values increase server load.', 'newbook-api-cache'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Unknown API Actions', 'newbook-api-cache'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="newbook_cache_allow_unknown_relay" value="1" <?php checked($allow_unknown_relay, true); ?> />
                            <?php _e('Allow relaying unknown API actions to NewBook', 'newbook-api-cache'); ?>
                        </label>
                        <p class="description">
                            <?php _e('<strong>Security Notice:</strong> By default, only known read-only actions (bookings_list, bookings_get, sites_list) are processed.', 'newbook-api-cache'); ?>
                            <br />
                            <?php _e('When disabled (recommended), unknown actions will be blocked and logged.', 'newbook-api-cache'); ?>
                            <br />
                            <?php _e('Only enable this if you need to relay other NewBook API actions through this plugin.', 'newbook-api-cache'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Retention Periods', 'newbook-api-cache'); ?></th>
                    <td>
                        <p>
                            <label>
                                <?php _e('Future bookings:', 'newbook-api-cache'); ?>
                                <input type="number" name="newbook_cache_retention_future" value="<?php echo esc_attr($retention_future); ?>" min="30" max="730" class="small-text" />
                                <?php _e('days', 'newbook-api-cache'); ?>
                            </label>
                        </p>
                        <p>
                            <label>
                                <?php _e('Past bookings:', 'newbook-api-cache'); ?>
                                <input type="number" name="newbook_cache_retention_past" value="<?php echo esc_attr($retention_past); ?>" min="1" max="365" class="small-text" />
                                <?php _e('days', 'newbook-api-cache'); ?>
                            </label>
                        </p>
                        <p>
                            <label>
                                <?php _e('Cancelled bookings:', 'newbook-api-cache'); ?>
                                <input type="number" name="newbook_cache_retention_cancelled" value="<?php echo esc_attr($retention_cancelled); ?>" min="1" max="365" class="small-text" />
                                <?php _e('days', 'newbook-api-cache'); ?>
                            </label>
                        </p>
                        <p class="description"><?php _e('How long to keep bookings in cache', 'newbook-api-cache'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h3><?php _e('Cache Statistics', 'newbook-api-cache'); ?></h3>
        <table class="widefat">
            <tr>
                <td><strong><?php _e('Total Bookings Cached:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['total_bookings'] ?? 0); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Active Bookings:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['hot_bookings'] ?? 0); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Historical Bookings:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['historical_bookings'] ?? 0); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Cancelled Bookings:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['cancelled_bookings'] ?? 0); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Database Size:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['database_size_mb'] ?? 0, 2); ?> MB</td>
            </tr>
            <tr>
                <td><strong><?php _e('Last Full Refresh:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo esc_html($this->time_ago($last_full_refresh)); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Last Incremental Sync:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo esc_html($this->time_ago($last_incremental_sync)); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Last Cleanup:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo esc_html($this->time_ago($last_cleanup)); ?></td>
            </tr>
        </table>

        <p class="submit">
            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=newbook_cache_full_refresh'), 'newbook_cache_full_refresh'); ?>" class="button">
                <?php _e('Force Full Refresh Now', 'newbook-api-cache'); ?>
            </a>
            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=newbook_cache_clear_cache'), 'newbook_cache_clear_cache'); ?>" class="button" onclick="return confirm('<?php _e('Are you sure? This will clear all cached bookings.', 'newbook-api-cache'); ?>');">
                <?php _e('Clear All Cache', 'newbook-api-cache'); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Render Status & Diagnostics tab
     */
    private function render_status_tab($stats) {
        // Display action messages
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            switch ($message) {
                case 'refresh_triggered':
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php _e('Full refresh has been triggered and is running in the background. Check the logs for progress.', 'newbook-api-cache'); ?></p>
                    </div>
                    <?php
                    break;
                case 'sync_triggered':
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php _e('Incremental sync has been triggered. Check the logs and last execution time below.', 'newbook-api-cache'); ?></p>
                    </div>
                    <?php
                    break;
                case 'cron_rescheduled':
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php _e('All cron jobs have been rescheduled successfully. Check the schedule below to verify.', 'newbook-api-cache'); ?></p>
                    </div>
                    <?php
                    break;
            }
        }

        // Get cron information
        $cron_jobs = _get_cron_array();
        $full_refresh_next = wp_next_scheduled('newbook_cache_full_refresh');
        $incremental_sync_next = wp_next_scheduled('newbook_cache_incremental_sync');
        $cleanup_next = wp_next_scheduled('newbook_cache_cleanup');

        // Get last execution times
        $last_full_refresh = get_option('newbook_cache_last_full_refresh', 'Never');
        $last_incremental_sync = get_option('newbook_cache_last_incremental_sync', 'Never');
        $last_cleanup = get_option('newbook_cache_last_cleanup', 'Never');

        // Check if WP-Cron is disabled
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        // Calculate time since last sync
        $time_since_last_sync = 'Unknown';
        $sync_warning = false;
        if ($last_incremental_sync !== 'Never') {
            $last_sync_time = strtotime($last_incremental_sync);
            $time_diff = time() - $last_sync_time;
            $time_since_last_sync = $this->format_time_diff($time_diff);

            // Warn if incremental sync hasn't run in 5 minutes
            $sync_interval = get_option('newbook_cache_sync_interval', 20);
            if ($time_diff > 300) { // 5 minutes
                $sync_warning = true;
            }
        }

        ?>
        <h2><?php _e('System Status', 'newbook-api-cache'); ?></h2>

        <!-- WordPress Cron Status -->
        <table class="widefat" style="margin-bottom: 20px;">
            <thead>
                <tr>
                    <th colspan="2" style="background: #f0f0f1; padding: 10px;">
                        <strong><?php _e('WordPress Cron System', 'newbook-api-cache'); ?></strong>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="width: 300px;"><strong><?php _e('WP-Cron Status:', 'newbook-api-cache'); ?></strong></td>
                    <td>
                        <?php if ($wp_cron_disabled): ?>
                            <span style="color: #d63638;">⚠ <?php _e('Disabled', 'newbook-api-cache'); ?></span>
                            <p class="description">
                                <?php _e('DISABLE_WP_CRON is set to true. You must set up a system cron job to trigger wp-cron.php for automated syncs to work.', 'newbook-api-cache'); ?>
                                <br>
                                <?php _e('Add this to your server crontab:', 'newbook-api-cache'); ?>
                                <br>
                                <code>* * * * * curl -s <?php echo site_url('wp-cron.php'); ?>?doing_wp_cron >/dev/null 2>&1</code>
                            </p>
                        <?php else: ?>
                            <span style="color: #46b450;">✓ <?php _e('Enabled', 'newbook-api-cache'); ?></span>
                            <p class="description">
                                <?php _e('WordPress cron runs when pages are visited. For better reliability, consider setting up a system cron job.', 'newbook-api-cache'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php _e('Total Scheduled Jobs:', 'newbook-api-cache'); ?></strong></td>
                    <td><?php echo count($cron_jobs); ?> <?php _e('total WordPress cron events', 'newbook-api-cache'); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- System Cron Setup Instructions -->
        <details style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
            <summary style="cursor: pointer; font-weight: 600; font-size: 14px; color: #2271b1;">
                <?php _e('📖 How to Set Up System Cron for Reliable Syncs', 'newbook-api-cache'); ?>
            </summary>

            <div style="margin-top: 15px; line-height: 1.6;">
                <h4 style="margin-top: 0;"><?php _e('Why Use System Cron?', 'newbook-api-cache'); ?></h4>
                <p><?php _e('WordPress cron (WP-Cron) only runs when someone visits your site. This means:', 'newbook-api-cache'); ?></p>
                <ul style="margin-left: 20px;">
                    <li><?php _e('Overnight: No traffic = no syncs = stale data in the morning', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Low traffic sites: Unreliable sync schedule', 'newbook-api-cache'); ?></li>
                    <li><?php _e('High traffic sites: Multiple simultaneous cron executions (performance impact)', 'newbook-api-cache'); ?></li>
                </ul>
                <p><strong><?php _e('Solution:', 'newbook-api-cache'); ?></strong> <?php _e('Set up a real system cron job to trigger wp-cron.php every minute.', 'newbook-api-cache'); ?></p>

                <h4><?php _e('Step-by-Step Setup', 'newbook-api-cache'); ?></h4>

                <h5 style="color: #2271b1;"><?php _e('Option 1: cPanel Cron Jobs (Most Common)', 'newbook-api-cache'); ?></h5>
                <ol style="margin-left: 20px;">
                    <li><?php _e('Log in to your cPanel', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Find "Cron Jobs" under "Advanced" section', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Set "Common Settings" to: <strong>Once Per Minute (* * * * *)</strong>', 'newbook-api-cache'); ?></li>
                    <li><?php _e('In the "Command" field, enter:', 'newbook-api-cache'); ?>
                        <pre style="background: #fff; padding: 10px; border: 1px solid #ccc; overflow-x: auto; margin: 10px 0;">wget -q -O - <?php echo site_url('wp-cron.php'); ?>?doing_wp_cron >/dev/null 2>&1</pre>
                        <p class="description"><?php _e('Or use curl if wget is not available:', 'newbook-api-cache'); ?></p>
                        <pre style="background: #fff; padding: 10px; border: 1px solid #ccc; overflow-x: auto; margin: 10px 0;">curl -s <?php echo site_url('wp-cron.php'); ?>?doing_wp_cron >/dev/null 2>&1</pre>
                    </li>
                    <li><?php _e('Click "Add New Cron Job"', 'newbook-api-cache'); ?></li>
                </ol>

                <h5 style="color: #2271b1;"><?php _e('Option 2: SSH/Terminal Access', 'newbook-api-cache'); ?></h5>
                <ol style="margin-left: 20px;">
                    <li><?php _e('SSH into your server', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Edit crontab: <code>crontab -e</code>', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Add this line:', 'newbook-api-cache'); ?>
                        <pre style="background: #fff; padding: 10px; border: 1px solid #ccc; overflow-x: auto; margin: 10px 0;">* * * * * curl -s <?php echo site_url('wp-cron.php'); ?>?doing_wp_cron >/dev/null 2>&1</pre>
                    </li>
                    <li><?php _e('Save and exit (in vim: press ESC, type :wq, press ENTER)', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Verify: <code>crontab -l</code>', 'newbook-api-cache'); ?></li>
                </ol>

                <h5 style="color: #2271b1;"><?php _e('Option 3: Disable WP-Cron (Advanced)', 'newbook-api-cache'); ?></h5>
                <p><?php _e('After setting up system cron, you can optionally disable WP-Cron to prevent duplicate executions:', 'newbook-api-cache'); ?></p>
                <ol style="margin-left: 20px;">
                    <li><?php _e('Add this line to your <code>wp-config.php</code> file (above "/* That\'s all, stop editing! */"):', 'newbook-api-cache'); ?>
                        <pre style="background: #fff; padding: 10px; border: 1px solid #ccc; overflow-x: auto; margin: 10px 0;">define('DISABLE_WP_CRON', true);</pre>
                    </li>
                    <li><?php _e('This stops WP-Cron from running on page loads, relying entirely on your system cron', 'newbook-api-cache'); ?></li>
                </ol>

                <h4><?php _e('Verification', 'newbook-api-cache'); ?></h4>
                <p><?php _e('After setting up system cron:', 'newbook-api-cache'); ?></p>
                <ol style="margin-left: 20px;">
                    <li><?php _e('Wait 1-2 minutes', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Check the "Last Executed" times in the Sync Jobs Status table below', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Incremental sync should show a recent timestamp (within last minute)', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Check the Logging & Debug tab to see sync activity', 'newbook-api-cache'); ?></li>
                </ol>

                <h4><?php _e('Troubleshooting', 'newbook-api-cache'); ?></h4>
                <ul style="margin-left: 20px;">
                    <li><strong><?php _e('Syncs still not running?', 'newbook-api-cache'); ?></strong>
                        <ul style="margin-left: 20px;">
                            <li><?php _e('Check your server\'s cron logs (usually in /var/log/cron or via cPanel)', 'newbook-api-cache'); ?></li>
                            <li><?php _e('Try the alternative command (wget vs curl)', 'newbook-api-cache'); ?></li>
                            <li><?php _e('Ensure your server can make HTTP requests to itself', 'newbook-api-cache'); ?></li>
                        </ul>
                    </li>
                    <li><strong><?php _e('504 Gateway Timeout errors?', 'newbook-api-cache'); ?></strong>
                        <ul style="margin-left: 20px;">
                            <li><?php _e('Your cron jobs might be taking too long', 'newbook-api-cache'); ?></li>
                            <li><?php _e('Consider increasing PHP max_execution_time or adjusting sync intervals', 'newbook-api-cache'); ?></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </details>

        <!-- Sync Job Status -->
        <h3><?php _e('Sync Jobs Status', 'newbook-api-cache'); ?></h3>

        <?php if ($sync_warning): ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php _e('Warning: Incremental Sync Not Running', 'newbook-api-cache'); ?></strong>
                </p>
                <p>
                    <?php printf(__('The last incremental sync was %s ago. Expected interval is %d seconds.', 'newbook-api-cache'), $time_since_last_sync, $sync_interval); ?>
                </p>
                <p>
                    <strong><?php _e('Possible causes:', 'newbook-api-cache'); ?></strong>
                </p>
                <ul style="margin-left: 20px;">
                    <li><?php _e('No traffic to your site (WP-Cron only runs on page loads)', 'newbook-api-cache'); ?></li>
                    <li><?php _e('System cron job not configured', 'newbook-api-cache'); ?></li>
                    <li><?php _e('Plugin deactivated or caching disabled', 'newbook-api-cache'); ?></li>
                </ul>
                <p>
                    <?php _e('Try clicking "Force Incremental Sync Now" below to test if the sync is working.', 'newbook-api-cache'); ?>
                </p>
            </div>
        <?php endif; ?>

        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('Job', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Status', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Next Scheduled', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Last Executed', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Interval', 'newbook-api-cache'); ?></th>
                </tr>
            </thead>
            <tbody>
                <!-- Incremental Sync -->
                <tr>
                    <td><strong><?php _e('Incremental Sync', 'newbook-api-cache'); ?></strong></td>
                    <td>
                        <?php if ($incremental_sync_next): ?>
                            <span style="color: #46b450;">✓ <?php _e('Scheduled', 'newbook-api-cache'); ?></span>
                        <?php else: ?>
                            <span style="color: #d63638;">✗ <?php _e('Not Scheduled', 'newbook-api-cache'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($incremental_sync_next): ?>
                            <?php echo date('Y-m-d H:i:s', $incremental_sync_next); ?>
                            <br>
                            <span style="color: #666; font-size: 11px;">(<?php echo human_time_diff($incremental_sync_next); ?>)</span>
                        <?php else: ?>
                            <span style="color: #d63638;"><?php _e('Not scheduled', 'newbook-api-cache'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($last_incremental_sync); ?>
                        <?php if ($last_incremental_sync !== 'Never'): ?>
                            <br>
                            <span style="color: #666; font-size: 11px;">(<?php echo $this->time_ago($last_incremental_sync); ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo get_option('newbook_cache_sync_interval', 20); ?> <?php _e('seconds', 'newbook-api-cache'); ?></td>
                </tr>

                <!-- Full Refresh -->
                <tr>
                    <td><strong><?php _e('Full Refresh', 'newbook-api-cache'); ?></strong></td>
                    <td>
                        <?php if ($full_refresh_next): ?>
                            <span style="color: #46b450;">✓ <?php _e('Scheduled', 'newbook-api-cache'); ?></span>
                        <?php else: ?>
                            <span style="color: #d63638;">✗ <?php _e('Not Scheduled', 'newbook-api-cache'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($full_refresh_next): ?>
                            <?php echo date('Y-m-d H:i:s', $full_refresh_next); ?>
                            <br>
                            <span style="color: #666; font-size: 11px;">(<?php echo human_time_diff($full_refresh_next); ?>)</span>
                        <?php else: ?>
                            <span style="color: #d63638;"><?php _e('Not scheduled', 'newbook-api-cache'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($last_full_refresh); ?>
                        <?php if ($last_full_refresh !== 'Never'): ?>
                            <br>
                            <span style="color: #666; font-size: 11px;">(<?php echo $this->time_ago($last_full_refresh); ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td><?php _e('Daily at 3:00 AM', 'newbook-api-cache'); ?></td>
                </tr>

                <!-- Cleanup -->
                <tr>
                    <td><strong><?php _e('Cleanup', 'newbook-api-cache'); ?></strong></td>
                    <td>
                        <?php if ($cleanup_next): ?>
                            <span style="color: #46b450;">✓ <?php _e('Scheduled', 'newbook-api-cache'); ?></span>
                        <?php else: ?>
                            <span style="color: #d63638;">✗ <?php _e('Not Scheduled', 'newbook-api-cache'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($cleanup_next): ?>
                            <?php echo date('Y-m-d H:i:s', $cleanup_next); ?>
                            <br>
                            <span style="color: #666; font-size: 11px;">(<?php echo human_time_diff($cleanup_next); ?>)</span>
                        <?php else: ?>
                            <span style="color: #d63638;"><?php _e('Not scheduled', 'newbook-api-cache'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($last_cleanup); ?>
                        <?php if ($last_cleanup !== 'Never'): ?>
                            <br>
                            <span style="color: #666; font-size: 11px;">(<?php echo $this->time_ago($last_cleanup); ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td><?php _e('Daily at 4:00 AM', 'newbook-api-cache'); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Manual Triggers -->
        <h3 style="margin-top: 30px;"><?php _e('Manual Operations', 'newbook-api-cache'); ?></h3>
        <p class="description">
            <?php _e('Use these buttons to manually trigger sync operations for testing. This is useful for diagnosing cron issues.', 'newbook-api-cache'); ?>
        </p>

        <p class="submit">
            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=newbook_cache_full_refresh'), 'newbook_cache_full_refresh'); ?>" class="button button-primary">
                <?php _e('Force Full Refresh Now', 'newbook-api-cache'); ?>
            </a>

            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=newbook_cache_incremental_sync'), 'newbook_cache_incremental_sync'); ?>" class="button">
                <?php _e('Force Incremental Sync Now', 'newbook-api-cache'); ?>
            </a>

            <a href="<?php echo admin_url('admin-post.php?action=newbook_cache_reschedule_cron'); ?>" class="button">
                <?php _e('Reschedule All Cron Jobs', 'newbook-api-cache'); ?>
            </a>
        </p>

        <!-- Cache Statistics -->
        <h3 style="margin-top: 30px;"><?php _e('Cache Statistics', 'newbook-api-cache'); ?></h3>
        <table class="widefat">
            <tr>
                <td style="width: 300px;"><strong><?php _e('Total Bookings Cached:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['total_bookings'] ?? 0); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Active Bookings:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['active_bookings'] ?? 0); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Checked Out:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['checked_out'] ?? 0); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Cancelled:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo number_format($stats['cancelled'] ?? 0); ?></td>
            </tr>
        </table>

        <!-- System Information -->
        <h3 style="margin-top: 30px;"><?php _e('System Information', 'newbook-api-cache'); ?></h3>
        <table class="widefat">
            <tr>
                <td style="width: 300px;"><strong><?php _e('WordPress Version:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo get_bloginfo('version'); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('PHP Version:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo PHP_VERSION; ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Plugin Version:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo NEWBOOK_CACHE_VERSION; ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Server Time:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo current_time('Y-m-d H:i:s'); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Timezone:', 'newbook-api-cache'); ?></strong></td>
                <td><?php echo wp_timezone_string(); ?></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Helper function to format time difference
     */
    private function format_time_diff($seconds) {
        if ($seconds < 60) {
            return sprintf(__('%d seconds', 'newbook-api-cache'), $seconds);
        } elseif ($seconds < 3600) {
            return sprintf(__('%d minutes', 'newbook-api-cache'), round($seconds / 60));
        } elseif ($seconds < 86400) {
            return sprintf(__('%d hours', 'newbook-api-cache'), round($seconds / 3600));
        } else {
            return sprintf(__('%d days', 'newbook-api-cache'), round($seconds / 86400));
        }
    }

    /**
     * Render Logging & Debug tab
     */
    private function render_logs_tab() {
        $log_level = get_option('newbook_cache_log_level', NewBook_Cache_Logger::INFO);
        $max_logs = get_option('newbook_cache_max_logs', 1000);
        $anonymize_ips = get_option('newbook_cache_anonymize_ips', true);
        $log_retention_days = get_option('newbook_cache_log_retention_days', 30);

        $logs = NewBook_Cache_Logger::get_logs(array('limit' => 50));
        $log_count = NewBook_Cache_Logger::get_log_count();

        ?>
        <form method="post" action="options.php">
            <?php settings_fields('newbook_cache_logging'); ?>
            <h3><?php _e('Logging Settings', 'newbook-api-cache'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Log Level', 'newbook-api-cache'); ?></th>
                    <td>
                        <select name="newbook_cache_log_level">
                            <option value="<?php echo NewBook_Cache_Logger::OFF; ?>" <?php selected($log_level, NewBook_Cache_Logger::OFF); ?>><?php _e('Off', 'newbook-api-cache'); ?></option>
                            <option value="<?php echo NewBook_Cache_Logger::ERROR; ?>" <?php selected($log_level, NewBook_Cache_Logger::ERROR); ?>><?php _e('Error', 'newbook-api-cache'); ?></option>
                            <option value="<?php echo NewBook_Cache_Logger::INFO; ?>" <?php selected($log_level, NewBook_Cache_Logger::INFO); ?>><?php _e('Info', 'newbook-api-cache'); ?></option>
                            <option value="<?php echo NewBook_Cache_Logger::DEBUG; ?>" <?php selected($log_level, NewBook_Cache_Logger::DEBUG); ?>><?php _e('Debug', 'newbook-api-cache'); ?></option>
                        </select>
                        <p class="description"><?php _e('Control logging verbosity. Debug logs the most detail.', 'newbook-api-cache'); ?></p>

                        <details style="margin-top: 10px;">
                            <summary style="cursor: pointer; font-weight: 600; color: #2271b1;"><?php _e('What do these logging levels mean?', 'newbook-api-cache'); ?></summary>
                            <div style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                                <p><strong><?php _e('Off (0):', 'newbook-api-cache'); ?></strong></p>
                                <ul style="margin-left: 20px;">
                                    <li><?php _e('No logging at all', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Use when you don\'t need any logs or want to minimize database usage', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Not recommended unless you have a specific reason', 'newbook-api-cache'); ?></li>
                                </ul>

                                <p style="margin-top: 10px;"><strong><?php _e('Error (1):', 'newbook-api-cache'); ?></strong></p>
                                <ul style="margin-left: 20px;">
                                    <li><?php _e('Logs only critical failures and errors', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Examples: API authentication failures, database errors, cache encryption failures', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Best for production environments where you only need to know when things go wrong', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Minimal performance impact and database usage', 'newbook-api-cache'); ?></li>
                                </ul>

                                <p style="margin-top: 10px;"><strong><?php _e('Info (2) - Recommended:', 'newbook-api-cache'); ?></strong></p>
                                <ul style="margin-left: 20px;">
                                    <li><?php _e('Logs errors + important events and milestones', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Examples: API connections, cache hits/misses, sync operations, API key usage', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Provides good visibility into what the plugin is doing without excessive detail', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Recommended for most use cases - balanced detail and performance', 'newbook-api-cache'); ?></li>
                                </ul>

                                <p style="margin-top: 10px;"><strong><?php _e('Debug (3):', 'newbook-api-cache'); ?></strong></p>
                                <ul style="margin-left: 20px;">
                                    <li><?php _e('Logs everything including detailed request/response data', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Examples: Every API request with client type, user, IP, route, all cache lookups, data transformations', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Use when troubleshooting issues or understanding plugin behavior in detail', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Warning: Generates many log entries - may impact performance and fill database quickly', 'newbook-api-cache'); ?></li>
                                    <li><?php _e('Only use temporarily for debugging, then switch back to Info or Error', 'newbook-api-cache'); ?></li>
                                </ul>

                                <p style="margin-top: 15px; padding: 10px; background: #fff; border-left: 4px solid #d63638;">
                                    <strong><?php _e('Privacy Note:', 'newbook-api-cache'); ?></strong><br>
                                    <?php _e('Info and Debug levels log user activity including IP addresses (anonymized if enabled below), usernames, and request details. Ensure this complies with your privacy policy and data protection requirements.', 'newbook-api-cache'); ?>
                                </p>
                            </div>
                        </details>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Max Log Entries', 'newbook-api-cache'); ?></th>
                    <td>
                        <input type="number" name="newbook_cache_max_logs" value="<?php echo esc_attr($max_logs); ?>" min="100" max="10000" class="small-text" />
                        <p class="description"><?php _e('Maximum number of log entries to keep in database', 'newbook-api-cache'); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Privacy & GDPR Compliance', 'newbook-api-cache'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Anonymize IP Addresses', 'newbook-api-cache'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="newbook_cache_anonymize_ips" value="1" <?php checked($anonymize_ips, true); ?> />
                            <?php _e('Remove last octet from IP addresses in logs', 'newbook-api-cache'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, IP addresses like 192.168.1.123 become 192.168.1.0 for privacy compliance (GDPR).', 'newbook-api-cache'); ?>
                            <br />
                            <strong><?php _e('Recommended:', 'newbook-api-cache'); ?></strong> <?php _e('Enable this to minimize personal data collection while maintaining security monitoring.', 'newbook-api-cache'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Log Retention Period', 'newbook-api-cache'); ?></th>
                    <td>
                        <input type="number" name="newbook_cache_log_retention_days" value="<?php echo esc_attr($log_retention_days); ?>" min="1" max="365" class="small-text" />
                        <?php _e('days', 'newbook-api-cache'); ?>
                        <p class="description">
                            <?php _e('Automatically delete logs older than this many days (1-365).', 'newbook-api-cache'); ?>
                            <br />
                            <strong><?php _e('Note:', 'newbook-api-cache'); ?></strong> <?php _e('Shorter retention periods reduce data storage and privacy risk. Recommended: 30 days.', 'newbook-api-cache'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h3><?php _e('Recent Log Entries', 'newbook-api-cache'); ?> (<span id="newbook-cache-logs-count"><?php echo $log_count; ?></span> <?php _e('total', 'newbook-api-cache'); ?>)</h3>

        <p>
            <button type="button" id="newbook-cache-refresh-logs" class="button">
                <?php _e('Refresh Logs', 'newbook-api-cache'); ?>
            </button>
            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=newbook_cache_clear_logs'), 'newbook_cache_clear_logs'); ?>" class="button" onclick="return confirm('<?php _e('Are you sure? This will clear all logs.', 'newbook-api-cache'); ?>');">
                <?php _e('Clear Logs', 'newbook-api-cache'); ?>
            </a>
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('Time', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Level', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Message', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Memory', 'newbook-api-cache'); ?></th>
                </tr>
            </thead>
            <tbody id="newbook-cache-logs-tbody">
                <?php echo $this->render_logs_rows($logs); ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Handle clear cache action
     */
    public function handle_clear_cache() {
        check_admin_referer('newbook_cache_clear_cache');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'newbook-api-cache'));
        }

        global $newbook_api_cache;
        if ($newbook_api_cache) {
            $newbook_api_cache->clear_all_cache();
        }

        wp_redirect(add_query_arg(array('page' => 'newbook-cache-settings', 'tab' => 'cache', 'message' => 'cache_cleared'), admin_url('options-general.php')));
        exit;
    }

    /**
     * Handle full refresh action
     */
    public function handle_full_refresh() {
        check_admin_referer('newbook_cache_full_refresh');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'newbook-api-cache'));
        }

        // Trigger full refresh in background
        do_action('newbook_cache_full_refresh');

        wp_redirect(add_query_arg(array('page' => 'newbook-cache-settings', 'tab' => 'status', 'message' => 'refresh_triggered'), admin_url('options-general.php')));
        exit;
    }

    /**
     * Handle incremental sync action
     */
    public function handle_incremental_sync() {
        check_admin_referer('newbook_cache_incremental_sync');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'newbook-api-cache'));
        }

        // Trigger incremental sync
        do_action('newbook_cache_incremental_sync');

        wp_redirect(add_query_arg(array('page' => 'newbook-cache-settings', 'tab' => 'status', 'message' => 'sync_triggered'), admin_url('options-general.php')));
        exit;
    }

    /**
     * Handle reschedule cron action
     */
    public function handle_reschedule_cron() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'newbook-api-cache'));
        }

        // Clear all existing cron jobs
        wp_clear_scheduled_hook('newbook_cache_full_refresh');
        wp_clear_scheduled_hook('newbook_cache_incremental_sync');
        wp_clear_scheduled_hook('newbook_cache_cleanup');

        // Reschedule them
        newbook_cache_schedule_cron();

        NewBook_Cache_Logger::log('Cron jobs manually rescheduled', NewBook_Cache_Logger::INFO);

        wp_redirect(add_query_arg(array('page' => 'newbook-cache-settings', 'tab' => 'status', 'message' => 'cron_rescheduled'), admin_url('options-general.php')));
        exit;
    }

    /**
     * Handle clear logs action
     */
    public function handle_clear_logs() {
        check_admin_referer('newbook_cache_clear_logs');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'newbook-api-cache'));
        }

        NewBook_Cache_Logger::clear_logs();

        wp_redirect(add_query_arg(array('page' => 'newbook-cache-settings', 'tab' => 'logs', 'message' => 'logs_cleared'), admin_url('options-general.php')));
        exit;
    }

    /**
     * Render API Access tab
     */
    private function render_api_access_tab() {
        $api_keys = NewBook_API_Key_Manager::get_all_keys(true);
        $api_key_stats = NewBook_API_Key_Manager::get_stats();

        ?>
        <h3><?php _e('API Access Management', 'newbook-api-cache'); ?></h3>
        <p><?php _e('Manage API keys for external systems to access cached NewBook data. These keys can be used by Chrome extensions, PWA apps, EPOS systems, and other integrations.', 'newbook-api-cache'); ?></p>

        <div class="card">
            <h3><?php _e('Authentication Methods', 'newbook-api-cache'); ?></h3>
            <p><?php _e('This plugin supports two authentication methods for REST API access:', 'newbook-api-cache'); ?></p>

            <h4>1. <?php _e('WordPress Application Passwords', 'newbook-api-cache'); ?></h4>
            <p><?php _e('Use your WordPress username and an application-specific password. Generate these in your WordPress profile.', 'newbook-api-cache'); ?></p>
            <pre>curl -u username:app_password https://yoursite.com/wp-json/newbook-cache/v1/bookings/list</pre>

            <h4>2. <?php _e('Custom API Keys', 'newbook-api-cache'); ?></h4>
            <p><?php _e('Generate dedicated API keys below for external systems. Each key can be labeled, tracked, and revoked independently.', 'newbook-api-cache'); ?></p>
            <pre>curl -H "Authorization: Bearer nbcache_..." https://yoursite.com/wp-json/newbook-cache/v1/bookings/list</pre>
        </div>

        <h3><?php _e('API Keys', 'newbook-api-cache'); ?></h3>

        <p>
            <strong><?php _e('Total Active Keys:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($api_key_stats['total_active_keys']); ?>
            &nbsp;|&nbsp;
            <strong><?php _e('Total API Usage:', 'newbook-api-cache'); ?></strong> <?php echo number_format($api_key_stats['total_usage']); ?>
            &nbsp;|&nbsp;
            <strong><?php _e('Last Used:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($api_key_stats['last_used'] ?: 'Never'); ?>
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('Label', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Created', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Last Used', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Usage Count', 'newbook-api-cache'); ?></th>
                    <th><?php _e('Actions', 'newbook-api-cache'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($api_keys)): ?>
                    <tr>
                        <td colspan="5"><?php _e('No API keys found. Generate your first key below.', 'newbook-api-cache'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($api_keys as $key): ?>
                        <tr>
                            <td><strong><?php echo esc_html($key->key_label); ?></strong></td>
                            <td><?php echo esc_html($this->time_ago($key->created_date)); ?></td>
                            <td><?php echo esc_html($key->last_used ? $this->time_ago($key->last_used) : 'Never'); ?></td>
                            <td><?php echo number_format($key->usage_count); ?></td>
                            <td>
                                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=newbook_cache_revoke_api_key&key_id=' . $key->id), 'newbook_cache_revoke_api_key'); ?>"
                                   class="button button-small"
                                   onclick="return confirm('<?php _e('Are you sure you want to revoke this API key? Applications using it will lose access.', 'newbook-api-cache'); ?>');">
                                    <?php _e('Revoke', 'newbook-api-cache'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3><?php _e('Generate New API Key', 'newbook-api-cache'); ?></h3>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="newbook_cache_generate_api_key" />
            <?php wp_nonce_field('newbook_cache_generate_api_key'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="api_key_label"><?php _e('Key Label', 'newbook-api-cache'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="api_key_label" name="api_key_label" class="regular-text" required
                               placeholder="<?php _e('e.g., Chrome Extension, EPOS System, PWA App', 'newbook-api-cache'); ?>" />
                        <p class="description"><?php _e('Give this key a descriptive label to identify where it will be used.', 'newbook-api-cache'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-primary" value="<?php _e('Generate API Key', 'newbook-api-cache'); ?>" />
            </p>
        </form>

        <?php
        // Display newly generated key if present
        if (isset($_GET['new_key']) && isset($_GET['key_id'])) {
            $new_key = sanitize_text_field($_GET['new_key']);
            $key_label = sanitize_text_field($_GET['key_label']);
            ?>
            <div class="notice notice-success">
                <h3><?php _e('API Key Generated Successfully', 'newbook-api-cache'); ?></h3>
                <p><strong><?php _e('IMPORTANT:', 'newbook-api-cache'); ?></strong> <?php _e('Copy this key now. It will only be shown once and cannot be recovered.', 'newbook-api-cache'); ?></p>

                <p><strong><?php _e('Label:', 'newbook-api-cache'); ?></strong> <?php echo esc_html($key_label); ?></p>
                <p>
                    <strong><?php _e('API Key:', 'newbook-api-cache'); ?></strong>
                    <input type="text" value="<?php echo esc_attr($new_key); ?>" readonly
                           style="width: 500px; font-family: monospace; font-size: 14px; padding: 5px;"
                           onclick="this.select();" />
                    <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($new_key); ?>'); alert('Copied to clipboard!');">
                        <?php _e('Copy', 'newbook-api-cache'); ?>
                    </button>
                </p>

                <p><strong><?php _e('Usage Example:', 'newbook-api-cache'); ?></strong></p>
                <pre>curl -X POST \
  -H "Authorization: Bearer <?php echo esc_html($new_key); ?>" \
  -H "Content-Type: application/json" \
  -d '{"period_from":"2025-11-01 00:00:00","period_to":"2025-11-30 23:59:59"}' \
  <?php echo esc_url(rest_url('newbook-cache/v1/bookings/list')); ?></pre>
            </div>
            <?php
        }
        ?>
        <?php
    }

    /**
     * Render API Documentation tab
     */
    private function render_api_docs_tab() {
        $endpoints = NewBook_Cache_REST_Controller::get_endpoint_docs();
        $site_url = rest_url('newbook-cache/v1');

        ?>
        <h3><?php _e('API Documentation', 'newbook-api-cache'); ?></h3>
        <p><?php _e('This plugin provides REST endpoints that mirror NewBook API functionality with caching. Use these endpoints as drop-in replacements for direct NewBook API calls.', 'newbook-api-cache'); ?></p>

        <div class="card">
            <h3><?php _e('Base URL', 'newbook-api-cache'); ?></h3>
            <p><code><?php echo esc_html($site_url); ?></code></p>

            <h3><?php _e('Authentication', 'newbook-api-cache'); ?></h3>
            <p><?php _e('All endpoints require authentication via WordPress application passwords OR API keys. See the API Access tab for details.', 'newbook-api-cache'); ?></p>
        </div>

        <?php foreach ($endpoints as $endpoint_name => $endpoint): ?>
            <div class="card" style="margin-top: 20px;">
                <h3><?php echo esc_html(ucwords(str_replace('_', ' ', $endpoint_name))); ?></h3>

                <table class="widefat">
                    <tr>
                        <td style="width: 150px;"><strong><?php _e('Endpoint:', 'newbook-api-cache'); ?></strong></td>
                        <td>
                            <code><?php echo esc_html($endpoint['path']); ?></code>
                            <button type="button" class="button button-small" style="margin-left: 10px;"
                                    onclick="navigator.clipboard.writeText('<?php echo esc_js(site_url($endpoint['path'])); ?>'); alert('Copied!');">
                                <?php _e('Copy URL', 'newbook-api-cache'); ?>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Method:', 'newbook-api-cache'); ?></strong></td>
                        <td><code><?php echo esc_html($endpoint['method']); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('NewBook Action:', 'newbook-api-cache'); ?></strong></td>
                        <td><code><?php echo esc_html($endpoint['newbook_action']); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Cached:', 'newbook-api-cache'); ?></strong></td>
                        <td><?php echo $endpoint['cached'] ? __('Yes', 'newbook-api-cache') : __('No', 'newbook-api-cache'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Description:', 'newbook-api-cache'); ?></strong></td>
                        <td><?php echo esc_html($endpoint['description']); ?></td>
                    </tr>
                </table>

                <?php if (!empty($endpoint['parameters'])): ?>
                    <h4><?php _e('Parameters', 'newbook-api-cache'); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php _e('Parameter', 'newbook-api-cache'); ?></th>
                                <th><?php _e('Required', 'newbook-api-cache'); ?></th>
                                <th><?php _e('Type', 'newbook-api-cache'); ?></th>
                                <th><?php _e('Description', 'newbook-api-cache'); ?></th>
                                <th><?php _e('Example', 'newbook-api-cache'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($endpoint['parameters'] as $param_name => $param_info): ?>
                                <tr>
                                    <td><code><?php echo esc_html($param_name); ?></code></td>
                                    <td><?php echo $param_info['required'] ? __('Yes', 'newbook-api-cache') : __('No', 'newbook-api-cache'); ?></td>
                                    <td><?php echo esc_html($param_info['type']); ?></td>
                                    <td><?php echo esc_html($param_info['description']); ?></td>
                                    <td><code><?php echo esc_html($param_info['example']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h4><?php _e('Example Request (WordPress Auth)', 'newbook-api-cache'); ?></h4>
                <pre><?php echo $this->generate_curl_example($endpoint, 'wordpress'); ?></pre>
                <button type="button" class="button button-small"
                        onclick="navigator.clipboard.writeText(<?php echo htmlspecialchars(json_encode($this->generate_curl_example($endpoint, 'wordpress')), ENT_QUOTES, 'UTF-8'); ?>); alert('Copied!');">
                    <?php _e('Copy', 'newbook-api-cache'); ?>
                </button>

                <h4><?php _e('Example Request (API Key)', 'newbook-api-cache'); ?></h4>
                <pre><?php echo $this->generate_curl_example($endpoint, 'api_key'); ?></pre>
                <button type="button" class="button button-small"
                        onclick="navigator.clipboard.writeText(<?php echo htmlspecialchars(json_encode($this->generate_curl_example($endpoint, 'api_key')), ENT_QUOTES, 'UTF-8'); ?>); alert('Copied!');">
                    <?php _e('Copy', 'newbook-api-cache'); ?>
                </button>

                <h4><?php _e('Example Response', 'newbook-api-cache'); ?></h4>
                <pre><?php echo esc_html($endpoint['response']); ?></pre>
            </div>
        <?php endforeach; ?>
        <?php
    }

    /**
     * Generate curl example for endpoint
     */
    private function generate_curl_example($endpoint, $auth_type = 'wordpress') {
        $url = site_url($endpoint['path']);
        $method = $endpoint['method'];

        // Build example data
        $example_data = array();
        if (!empty($endpoint['parameters'])) {
            foreach ($endpoint['parameters'] as $param_name => $param_info) {
                if ($param_info['required']) {
                    $example_data[$param_name] = $param_info['example'];
                }
            }
        }

        $curl = "curl -X {$method}";

        if ($auth_type === 'wordpress') {
            $curl .= " \\\n  -u username:app_password";
        } else {
            $curl .= " \\\n  -H \"Authorization: Bearer nbcache_your_api_key_here\"";
        }

        $curl .= " \\\n  -H \"Content-Type: application/json\"";

        if (!empty($example_data)) {
            $curl .= " \\\n  -d '" . json_encode($example_data, JSON_PRETTY_PRINT) . "'";
        }

        $curl .= " \\\n  {$url}";

        return $curl;
    }

    /**
     * Handle generate API key action
     */
    public function handle_generate_api_key() {
        check_admin_referer('newbook_cache_generate_api_key');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'newbook-api-cache'));
        }

        $label = isset($_POST['api_key_label']) ? sanitize_text_field($_POST['api_key_label']) : '';

        if (empty($label)) {
            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'api-access',
                'error' => 'missing_label'
            ), admin_url('options-general.php')));
            exit;
        }

        $result = NewBook_API_Key_Manager::generate_key($label);

        if ($result) {
            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'api-access',
                'new_key' => $result['key'],
                'key_id' => $result['id'],
                'key_label' => $label
            ), admin_url('options-general.php')));
        } else {
            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'api-access',
                'error' => 'generation_failed'
            ), admin_url('options-general.php')));
        }
        exit;
    }

    /**
     * Handle revoke API key action
     */
    public function handle_revoke_api_key() {
        check_admin_referer('newbook_cache_revoke_api_key');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'newbook-api-cache'));
        }

        $key_id = isset($_GET['key_id']) ? absint($_GET['key_id']) : 0;

        if ($key_id > 0) {
            NewBook_API_Key_Manager::revoke_key($key_id);
        }

        wp_redirect(add_query_arg(array(
            'page' => 'newbook-cache-settings',
            'tab' => 'api-access',
            'message' => 'key_revoked'
        ), admin_url('options-general.php')));
        exit;
    }

    /**
     * Handle test connection action
     */
    public function handle_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'newbook-api-cache'));
        }

        // Get credentials from settings
        $username = get_option('newbook_cache_username');
        $password = get_option('newbook_cache_password');
        $api_key = get_option('newbook_cache_api_key');
        $region = get_option('newbook_cache_region', 'au');

        // Validate credentials are configured
        if (empty($username) || empty($password) || empty($api_key)) {
            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'credentials',
                'test_result' => 'missing_credentials'
            ), admin_url('options-general.php')));
            exit;
        }

        // Build API URL (use live endpoint)
        $base_url = 'https://api.newbook.cloud/rest/';
        $action = 'sites_list'; // Lightweight endpoint for testing
        $url = $base_url . $action;

        // Build request body
        $body = json_encode(array(
            'region' => $region,
            'api_key' => $api_key
        ));

        // Make test request
        $start_time = microtime(true);
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
            ),
            'body' => $body,
            'sslverify' => true
        ));
        $response_time = round((microtime(true) - $start_time) * 1000); // Convert to ms

        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            NewBook_Cache_Logger::log('Test connection failed: ' . $error_message, NewBook_Cache_Logger::ERROR);

            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'credentials',
                'test_result' => 'error',
                'test_error' => urlencode($error_message)
            ), admin_url('options-general.php')));
            exit;
        }

        // Check response code
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);
        $data = json_decode($response_body, true);

        // Log the test result
        NewBook_Cache_Logger::log('Test connection completed', NewBook_Cache_Logger::INFO, array(
            'status_code' => $status_code,
            'response_time_ms' => $response_time,
            'region' => $region,
            'response_body' => $response_body,
            'response_headers' => is_object($response_headers) ? $response_headers->getAll() : $response_headers,
            'success' => ($status_code === 200 && isset($data['success']) && $data['success'])
        ));

        // Check if successful
        if ($status_code === 200 && isset($data['success']) && $data['success']) {
            // Success!
            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'credentials',
                'test_result' => 'success',
                'response_time' => $response_time,
                'region' => $region
            ), admin_url('options-general.php')));
            exit;
        } elseif ($status_code === 401) {
            // Authentication failed
            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'credentials',
                'test_result' => 'auth_failed',
                'status_code' => $status_code
            ), admin_url('options-general.php')));
            exit;
        } elseif ($status_code === 412) {
            // Precondition Failed - usually region/API key mismatch
            // Try multiple possible error field names from NewBook response
            $error_msg = '';
            if (isset($data['message'])) {
                $error_msg = $data['message'];
            } elseif (isset($data['error'])) {
                $error_msg = $data['error'];
            } elseif (isset($data['error_message'])) {
                $error_msg = $data['error_message'];
            } elseif (isset($data['msg'])) {
                $error_msg = $data['msg'];
            } else {
                $error_msg = 'Precondition failed - check region and API key match';
            }

            // Build detailed debug info
            $debug_info = array(
                'HTTP Status' => $status_code,
                'Region' => strtoupper($region),
                'Response Body' => $response_body ?: '(empty)',
                'Decoded Data' => $data ? json_encode($data, JSON_PRETTY_PRINT) : '(no JSON)',
            );

            // Add response headers
            if (is_object($response_headers)) {
                $headers_array = $response_headers->getAll();
                foreach ($headers_array as $key => $value) {
                    $debug_info['Header: ' . $key] = is_array($value) ? implode(', ', $value) : $value;
                }
            }

            NewBook_Cache_Logger::log('Test connection HTTP 412: Region/API key mismatch', NewBook_Cache_Logger::WARNING, array(
                'status_code' => $status_code,
                'region' => $region,
                'response_body' => $response_body,
                'response_headers' => is_object($response_headers) ? $response_headers->getAll() : $response_headers,
                'decoded_data' => $data,
                'debug_info' => $debug_info
            ));

            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'credentials',
                'test_result' => 'precondition_failed',
                'status_code' => $status_code,
                'region' => $region,
                'test_error' => urlencode($error_msg),
                'raw_response' => urlencode($response_body), // Full response
                'debug_info' => urlencode(json_encode($debug_info))
            ), admin_url('options-general.php')));
            exit;
        } else {
            // Other error
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
            NewBook_Cache_Logger::log('Test connection failed', NewBook_Cache_Logger::WARNING, array(
                'status_code' => $status_code,
                'response_body' => $response_body
            ));
            wp_redirect(add_query_arg(array(
                'page' => 'newbook-cache-settings',
                'tab' => 'credentials',
                'test_result' => 'api_error',
                'status_code' => $status_code,
                'test_error' => urlencode($error_msg)
            ), admin_url('options-general.php')));
            exit;
        }
    }

    /**
     * Helper: Convert timestamp to relative time
     */
    private function time_ago($datetime) {
        if ($datetime === 'Never') {
            return 'Never';
        }

        $time = strtotime($datetime);
        if (!$time) {
            return $datetime;
        }

        $diff = time() - $time;

        if ($diff < 60) {
            return sprintf(__('%d seconds ago', 'newbook-api-cache'), $diff);
        } elseif ($diff < 3600) {
            return sprintf(__('%d minutes ago', 'newbook-api-cache'), round($diff / 60));
        } elseif ($diff < 86400) {
            return sprintf(__('%d hours ago', 'newbook-api-cache'), round($diff / 3600));
        } else {
            return sprintf(__('%d days ago', 'newbook-api-cache'), round($diff / 86400));
        }
    }

    /**
     * Reschedule incremental sync when interval setting changes
     *
     * @param int $old_value Old interval value
     * @param int $new_value New interval value
     */
    public function reschedule_sync_on_interval_change($old_value, $new_value) {
        if ($old_value !== $new_value) {
            // Unschedule existing cron job
            wp_clear_scheduled_hook('newbook_cache_incremental_sync');

            // Reschedule with new interval
            wp_schedule_event(time(), 'newbook_cache_incremental_sync', 'newbook_cache_incremental_sync');

            NewBook_Cache_Logger::log('Incremental sync rescheduled', NewBook_Cache_Logger::INFO, array(
                'old_interval' => $old_value . ' seconds',
                'new_interval' => $new_value . ' seconds'
            ));
        }
    }

    /**
     * Render logs table rows
     *
     * @param array $logs Array of log entries
     * @return string HTML for table rows
     */
    private function render_logs_rows($logs) {
        if (empty($logs)) {
            return '<tr><td colspan="4">' . __('No logs found', 'newbook-api-cache') . '</td></tr>';
        }

        $html = '';
        foreach ($logs as $log) {
            $level_name = NewBook_Cache_Logger::get_level_name($log->level);
            $html .= '<tr>';
            $html .= '<td style="white-space: nowrap;">' . esc_html($log->timestamp) . '</td>';
            $html .= '<td>';
            $html .= '<span class="log-level log-level-' . esc_attr(strtolower($level_name)) . '">';
            $html .= esc_html($level_name);
            $html .= '</span>';
            $html .= '</td>';
            $html .= '<td>';
            $html .= esc_html($log->message);

            if (!empty($log->context)) {
                $context_json = json_encode(json_decode($log->context), JSON_PRETTY_PRINT);
                $html .= '<details style="margin-top: 5px;">';
                $html .= '<summary style="cursor: pointer; font-size: 11px; color: #666;">' . __('Show Details', 'newbook-api-cache') . '</summary>';
                $html .= '<pre style="margin-top: 5px; padding: 8px; background: #f9f9f9; overflow-x: auto; font-size: 11px; max-height: 300px;">' . esc_html($context_json) . '</pre>';
                $html .= '</details>';
            }

            $html .= '</td>';
            $html .= '<td style="white-space: nowrap;">' . esc_html($log->memory_usage) . '</td>';
            $html .= '</tr>';
        }

        return $html;
    }

    /**
     * AJAX handler for refreshing logs
     */
    public function ajax_refresh_logs() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'newbook_cache_refresh_logs')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        // Get fresh logs
        $logs = NewBook_Cache_Logger::get_logs(array('limit' => 50));
        $log_count = NewBook_Cache_Logger::get_log_count();

        // Render logs HTML
        $html = $this->render_logs_rows($logs);

        wp_send_json_success(array(
            'html' => $html,
            'count' => $log_count
        ));
    }
}
