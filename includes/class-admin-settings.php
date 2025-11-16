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
        add_action('admin_post_newbook_cache_clear_logs', array($this, 'handle_clear_logs'));
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
        // API Credentials
        register_setting('newbook_cache_settings', 'newbook_cache_username', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('newbook_cache_settings', 'newbook_cache_password', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('newbook_cache_settings', 'newbook_cache_api_key', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting('newbook_cache_settings', 'newbook_cache_region', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'au'));

        // Cache Settings
        register_setting('newbook_cache_settings', 'newbook_cache_enabled', array('sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('newbook_cache_settings', 'newbook_cache_retention_future', array('sanitize_callback' => 'absint', 'default' => 365));
        register_setting('newbook_cache_settings', 'newbook_cache_retention_past', array('sanitize_callback' => 'absint', 'default' => 30));
        register_setting('newbook_cache_settings', 'newbook_cache_retention_cancelled', array('sanitize_callback' => 'absint', 'default' => 30));

        // Logging
        register_setting('newbook_cache_settings', 'newbook_cache_log_level', array('sanitize_callback' => 'absint', 'default' => NewBook_Cache_Logger::INFO));
        register_setting('newbook_cache_settings', 'newbook_cache_max_logs', array('sanitize_callback' => 'absint', 'default' => 1000));

        // Privacy & GDPR
        register_setting('newbook_cache_settings', 'newbook_cache_anonymize_ips', array('sanitize_callback' => 'rest_sanitize_boolean', 'default' => true));
        register_setting('newbook_cache_settings', 'newbook_cache_log_retention_days', array('sanitize_callback' => 'absint', 'default' => 30));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_newbook-cache-settings') {
            return;
        }

        wp_enqueue_style('newbook-cache-admin', NEWBOOK_CACHE_PLUGIN_URL . 'assets/css/admin.css', array(), NEWBOOK_CACHE_VERSION);
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

            <?php settings_errors(); ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=newbook-cache-settings&tab=credentials" class="nav-tab <?php echo $active_tab === 'credentials' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API Credentials', 'newbook-api-cache'); ?>
                </a>
                <a href="?page=newbook-cache-settings&tab=cache" class="nav-tab <?php echo $active_tab === 'cache' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Cache Settings', 'newbook-api-cache'); ?>
                </a>
                <a href="?page=newbook-cache-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logging & Debug', 'newbook-api-cache'); ?>
                </a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('newbook_cache_settings'); ?>

                <?php
                switch ($active_tab) {
                    case 'credentials':
                        $this->render_credentials_tab();
                        break;
                    case 'cache':
                        $this->render_cache_tab($stats);
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                }
                ?>

                <?php if ($active_tab !== 'logs'): ?>
                    <?php submit_button(); ?>
                <?php endif; ?>
            </form>
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

        ?>
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
                        <option value="au" <?php selected($region, 'au'); ?>>Australia</option>
                        <option value="nz" <?php selected($region, 'nz'); ?>>New Zealand</option>
                        <option value="uk" <?php selected($region, 'uk'); ?>>United Kingdom</option>
                        <option value="us" <?php selected($region, 'us'); ?>>United States</option>
                    </select>
                </td>
            </tr>
        </table>

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
        $retention_future = get_option('newbook_cache_retention_future', 365);
        $retention_past = get_option('newbook_cache_retention_past', 30);
        $retention_cancelled = get_option('newbook_cache_retention_cancelled', 30);

        $last_full_refresh = get_option('newbook_cache_last_full_refresh', 'Never');
        $last_incremental_sync = get_option('newbook_cache_last_incremental_sync', 'Never');
        $last_cleanup = get_option('newbook_cache_last_cleanup', 'Never');

        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Enable Caching', 'newbook-api-cache'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="newbook_cache_enabled" value="1" <?php checked($enabled, true); ?> />
                        <?php _e('Enable NewBook API caching', 'newbook-api-cache'); ?>
                    </label>
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

        <h3><?php echo sprintf(__('Recent Log Entries (%d total)', 'newbook-api-cache'), $log_count); ?></h3>

        <p>
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
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="4"><?php _e('No logs found', 'newbook-api-cache'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->timestamp); ?></td>
                            <td>
                                <span class="log-level log-level-<?php echo esc_attr(strtolower(NewBook_Cache_Logger::get_level_name($log->level))); ?>">
                                    <?php echo esc_html(NewBook_Cache_Logger::get_level_name($log->level)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td><?php echo esc_html($log->memory_usage); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
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

        wp_redirect(add_query_arg(array('page' => 'newbook-cache-settings', 'tab' => 'cache', 'message' => 'refresh_triggered'), admin_url('options-general.php')));
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
}
