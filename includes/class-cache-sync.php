<?php
/**
 * NewBook Cache Sync
 *
 * Handles cron jobs for cache synchronization
 *
 * @package NewBook_API_Cache
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NewBook_Cache_Sync {

    private $api_client;
    private $cache;

    public function __construct() {
        $this->api_client = new NewBook_API_Client();

        // Access global cache instance
        global $newbook_api_cache;
        $this->cache = $newbook_api_cache;

        // Register cron hooks
        add_action('newbook_cache_full_refresh', array($this, 'full_refresh'));
        add_action('newbook_cache_incremental_sync', array($this, 'incremental_sync'));
        add_action('newbook_cache_cleanup', array($this, 'cleanup'));
    }

    /**
     * Full cache refresh (runs daily at 3 AM)
     */
    public function full_refresh() {
        NewBook_Cache_Logger::log('=== Full Refresh Started ===', NewBook_Cache_Logger::INFO);
        $start_time = microtime(true);

        $total_bookings = 0;
        $today = date('Y-m-d');
        $retention_past = get_option('newbook_cache_retention_past', 30);
        $retention_future = get_option('newbook_cache_retention_future', 365);

        // Step 1: Fetch future bookings (staying) in 30-day chunks
        NewBook_Cache_Logger::log('Fetching future bookings (next 12 months)', NewBook_Cache_Logger::INFO);

        $months_to_fetch = ceil($retention_future / 30);
        for ($chunk = 0; $chunk < $months_to_fetch; $chunk++) {
            $from = date('Y-m-d', strtotime("+{$chunk} months"));
            $to = date('Y-m-d', strtotime("+".($chunk + 1)." months"));

            NewBook_Cache_Logger::log("Requesting chunk {$chunk}: {$from} to {$to}", NewBook_Cache_Logger::DEBUG);

            $response = $this->api_client->call_api('bookings_list', array(
                'period_from' => $from . ' 00:00:00',
                'period_to' => $to . ' 23:59:59',
                'list_type' => 'staying'
            ));

            if ($response && isset($response['data'])) {
                $count = count($response['data']);
                NewBook_Cache_Logger::log("Received {$count} bookings for chunk {$chunk}", NewBook_Cache_Logger::INFO);

                foreach ($response['data'] as $booking) {
                    if ($this->cache->store_booking($booking)) {
                        $total_bookings++;
                    }
                }
            }

            // Rate limit friendly - brief pause between chunks
            usleep(100000); // 100ms
        }

        // Step 2: Fetch cancelled bookings (last 30 days)
        NewBook_Cache_Logger::log('Fetching cancelled bookings (last 30 days)', NewBook_Cache_Logger::INFO);

        $retention_cancelled = get_option('newbook_cache_retention_cancelled', 30);
        $cancelled_from = date('Y-m-d', strtotime("-{$retention_cancelled} days"));

        $response = $this->api_client->call_api('bookings_list', array(
            'period_from' => $cancelled_from . ' 00:00:00',
            'period_to' => date('Y-m-d', strtotime("+{$retention_future} days")) . ' 23:59:59',
            'list_type' => 'cancelled'
        ));

        if ($response && isset($response['data'])) {
            $count = count($response['data']);
            NewBook_Cache_Logger::log("Received {$count} cancelled bookings", NewBook_Cache_Logger::INFO);

            foreach ($response['data'] as $booking) {
                if ($this->cache->store_booking($booking)) {
                    $total_bookings++;
                }
            }
        }

        // Step 3: Fetch past bookings (last X days for looking back)
        NewBook_Cache_Logger::log("Fetching past bookings (last {$retention_past} days)", NewBook_Cache_Logger::INFO);

        $past_from = date('Y-m-d', strtotime("-{$retention_past} days"));

        $response = $this->api_client->call_api('bookings_list', array(
            'period_from' => $past_from . ' 00:00:00',
            'period_to' => $today . ' 23:59:59',
            'list_type' => 'staying'
        ));

        if ($response && isset($response['data'])) {
            $count = count($response['data']);
            NewBook_Cache_Logger::log("Received {$count} past bookings", NewBook_Cache_Logger::INFO);

            foreach ($response['data'] as $booking) {
                if ($this->cache->store_booking($booking)) {
                    $total_bookings++;
                }
            }
        }

        $elapsed = round((microtime(true) - $start_time), 2);
        NewBook_Cache_Logger::log("=== Full Refresh Complete: {$total_bookings} bookings in {$elapsed}s ===", NewBook_Cache_Logger::INFO);

        update_option('newbook_cache_last_full_refresh', current_time('mysql'));
    }

    /**
     * Incremental sync (runs every 20 seconds)
     */
    public function incremental_sync() {
        $last_sync = get_option('newbook_cache_last_incremental_sync', date('Y-m-d H:i:s', strtotime('-1 minute')));

        NewBook_Cache_Logger::log("Incremental sync check (since {$last_sync})", NewBook_Cache_Logger::DEBUG);

        // Fetch changes using 'all' list type
        $response = $this->api_client->call_api('bookings_list', array(
            'period_from' => $last_sync,
            'period_to' => current_time('mysql'),
            'list_type' => 'all'
        ));

        if (!$response || !isset($response['data'])) {
            NewBook_Cache_Logger::log('Incremental sync: No response from API', NewBook_Cache_Logger::DEBUG);
            update_option('newbook_cache_last_incremental_sync', current_time('mysql'));
            return;
        }

        $changes = $response['data'];

        if (empty($changes)) {
            // No changes - don't log (prevent spam)
            update_option('newbook_cache_last_incremental_sync', current_time('mysql'));
            return;
        }

        // Changes detected - log it
        NewBook_Cache_Logger::log("Incremental sync: " . count($changes) . " changes detected", NewBook_Cache_Logger::INFO);

        $updated = 0;
        $added = 0;

        foreach ($changes as $booking) {
            global $wpdb;
            $table = $wpdb->prefix . 'newbook_cache';
            $booking_id = isset($booking['booking_id']) ? intval($booking['booking_id']) : 0;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT booking_id FROM {$table} WHERE booking_id = %d",
                $booking_id
            ));

            if ($this->cache->store_booking($booking)) {
                if ($exists) {
                    $updated++;
                } else {
                    $added++;
                }
            }
        }

        NewBook_Cache_Logger::log("Incremental sync complete: {$updated} updated, {$added} added", NewBook_Cache_Logger::INFO);

        update_option('newbook_cache_last_incremental_sync', current_time('mysql'));
    }

    /**
     * Cleanup old bookings (runs daily at 4 AM)
     */
    public function cleanup() {
        NewBook_Cache_Logger::log('=== Cleanup Started ===', NewBook_Cache_Logger::INFO);

        global $wpdb;
        $table = $wpdb->prefix . 'newbook_cache';

        $retention_past = get_option('newbook_cache_retention_past', 30);
        $retention_cancelled = get_option('newbook_cache_retention_cancelled', 30);

        // Remove old checked-out bookings
        $deleted_checked_out = $wpdb->query($wpdb->prepare("
            DELETE FROM {$table}
            WHERE booking_status IN ('checked_out', 'no_show')
              AND departure_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)
        ", $retention_past));

        // Remove old cancelled bookings
        $deleted_cancelled = $wpdb->query($wpdb->prepare("
            DELETE FROM {$table}
            WHERE booking_status = 'cancelled'
              AND last_updated < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $retention_cancelled));

        NewBook_Cache_Logger::log("Cleanup complete: {$deleted_checked_out} old bookings, {$deleted_cancelled} old cancellations removed", NewBook_Cache_Logger::INFO);

        update_option('newbook_cache_last_cleanup', current_time('mysql'));
    }

    /**
     * Manual trigger for full refresh (called from admin)
     */
    public function trigger_full_refresh() {
        NewBook_Cache_Logger::log('Manual full refresh triggered', NewBook_Cache_Logger::INFO);
        $this->full_refresh();
    }

    /**
     * Manual trigger for cleanup (called from admin)
     */
    public function trigger_cleanup() {
        NewBook_Cache_Logger::log('Manual cleanup triggered', NewBook_Cache_Logger::INFO);
        $this->cleanup();
    }
}
