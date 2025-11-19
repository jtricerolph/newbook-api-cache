# Function Cheat Sheet

Quick reference guide for common functions and use cases in the NewBook API Cache plugin.

---

## Table of Contents

1. [Cache Operations](#cache-operations)
2. [API Calls](#api-calls)
3. [Sync Operations](#sync-operations)
4. [Logging](#logging)
5. [API Key Management](#api-key-management)
6. [REST API](#rest-api)
7. [Statistics](#statistics)
8. [Common Use Cases](#common-use-cases)

---

## Cache Operations

### Get Bookings from Cache

```php
global $newbook_api_cache;

// Get bookings for a date range
$response = $newbook_api_cache->call_api(
    'bookings_list',
    array(
        'period_from' => '2025-11-01 00:00:00',
        'period_to' => '2025-11-30 23:59:59',
        'list_type' => 'staying'  // staying, placed, cancelled, all
    )
);

// Response format
array(
    'success' => true,
    'data' => array(...),
    '_cache_hit' => true
)
```

**List Types:**

- `staying` - Active bookings (default)
- `placed` - Bookings by placement date
- `cancelled` - Cancelled bookings
- `all` - All bookings regardless of status

---

### Get Single Booking

```php
global $newbook_api_cache;

$response = $newbook_api_cache->call_api(
    'bookings_get',
    array('booking_id' => 123456)
);

if ($response['success']) {
    $booking = $response['data'];
    echo $booking['guest_name'];
}
```

---

### Force Refresh (Bypass Cache)

```php
global $newbook_api_cache;

// Bypass cache and fetch fresh from NewBook API
$response = $newbook_api_cache->call_api(
    'bookings_list',
    array(
        'period_from' => '2025-11-01',
        'period_to' => '2025-11-30'
    ),
    $force_refresh = true  // Bypass cache
);
```

---

### Store Booking in Cache

```php
global $newbook_api_cache;

$booking = array(
    'booking_id' => 123456,
    'booking_arrival' => '2025-11-15',
    'booking_departure' => '2025-11-17',
    'booking_status' => 'confirmed',
    'site_name' => 'Cabin 5',
    'guest_name' => 'John Doe',
    'booking_adults' => 2,
    'booking_children' => 1
);

if ($newbook_api_cache->store_booking($booking)) {
    echo "Booking cached successfully";
}
```

---

### Clear Cache

```php
global $newbook_api_cache;

// Clear specific booking
$newbook_api_cache->clear_booking_cache(123456);

// Clear ALL cache
$newbook_api_cache->clear_all_cache();
```

---

### Check if Caching Enabled

```php
global $newbook_api_cache;

if ($newbook_api_cache->is_caching_enabled()) {
    echo "Caching is active";
}
```

---

## API Calls

### Direct API Call (Bypasses Cache)

```php
$client = new NewBook_API_Client();

// Get bookings directly from NewBook
$response = $client->call_api('bookings_list', array(
    'period_from' => '2025-11-01 00:00:00',
    'period_to' => '2025-11-30 23:59:59'
));

// Get single booking
$response = $client->call_api('bookings_get', array(
    'booking_id' => 123456
));

// Get sites list
$response = $client->call_api('sites_list', array());
```

---

### Test API Connection

```php
$client = new NewBook_API_Client();
$result = $client->test_connection();

if ($result === true) {
    echo "API connection successful!";
} else {
    echo "Connection failed: " . $result;
}
```

---

## Sync Operations

### Trigger Full Refresh

```php
$sync = new NewBook_Cache_Sync();
$sync->trigger_full_refresh();

// Or via global instance
global $newbook_cache_sync;
$newbook_cache_sync->full_refresh();
```

---

### Trigger Incremental Sync

```php
$sync = new NewBook_Cache_Sync();
$sync->incremental_sync();
```

---

### Trigger Cleanup

```php
$sync = new NewBook_Cache_Sync();
$sync->trigger_cleanup();
```

---

### Manual Cron Job Execution

```php
// Run full refresh manually
do_action('newbook_cache_full_refresh');

// Run incremental sync manually
do_action('newbook_cache_incremental_sync');

// Run cleanup manually
do_action('newbook_cache_cleanup');
```

---

### Check Next Scheduled Sync

```php
// Get next full refresh time
$next_full = wp_next_scheduled('newbook_cache_full_refresh');
echo "Next full refresh: " . date('Y-m-d H:i:s', $next_full);

// Get next incremental sync time
$next_incremental = wp_next_scheduled('newbook_cache_incremental_sync');
echo "Next incremental sync: " . date('Y-m-d H:i:s', $next_incremental);

// Get next cleanup time
$next_cleanup = wp_next_scheduled('newbook_cache_cleanup');
echo "Next cleanup: " . date('Y-m-d H:i:s', $next_cleanup);
```

---

## Logging

### Log Messages

```php
// Error log
NewBook_Cache_Logger::log(
    'Critical error occurred',
    NewBook_Cache_Logger::ERROR,
    array('error_code' => 500, 'details' => 'API timeout')
);

// Info log
NewBook_Cache_Logger::log(
    'Cache refreshed successfully',
    NewBook_Cache_Logger::INFO,
    array('bookings_updated' => 150)
);

// Debug log
NewBook_Cache_Logger::log(
    'Querying cache for date range',
    NewBook_Cache_Logger::DEBUG,
    array('from' => '2025-11-01', 'to' => '2025-11-30')
);
```

**Log Levels:**

```php
NewBook_Cache_Logger::OFF    // 0 - Logging disabled
NewBook_Cache_Logger::ERROR  // 1 - Errors only
NewBook_Cache_Logger::INFO   // 2 - Errors + Info
NewBook_Cache_Logger::DEBUG  // 3 - All messages
```

---

### Retrieve Logs

```php
// Get last 50 logs
$logs = NewBook_Cache_Logger::get_logs();

// Get last 100 error logs
$errors = NewBook_Cache_Logger::get_logs(array(
    'level' => NewBook_Cache_Logger::ERROR,
    'limit' => 100
));

// Search logs
$results = NewBook_Cache_Logger::get_logs(array(
    'search' => 'API timeout',
    'limit' => 50
));

// Paginated logs
$page_2 = NewBook_Cache_Logger::get_logs(array(
    'limit' => 50,
    'offset' => 50
));

// Display logs
foreach ($logs as $log) {
    $level = NewBook_Cache_Logger::get_level_name($log->level);
    echo "[{$log->timestamp}] {$level}: {$log->message}\n";

    if ($log->context) {
        $context = json_decode($log->context, true);
        print_r($context);
    }
}
```

---

### Log Count

```php
// Total error count
$error_count = NewBook_Cache_Logger::get_log_count(array(
    'level' => NewBook_Cache_Logger::ERROR
));

// Search count
$timeout_count = NewBook_Cache_Logger::get_log_count(array(
    'search' => 'timeout'
));

echo "Total errors: {$error_count}";
```

---

### Clear Logs

```php
NewBook_Cache_Logger::clear_logs();
```

---

## API Key Management

### Generate New API Key

```php
$new_key = NewBook_API_Key_Manager::generate_key('Mobile App v2.0');

if ($new_key) {
    // IMPORTANT: This is shown only ONCE!
    echo "API Key: " . $new_key['key'] . "\n";
    echo "Key ID: " . $new_key['id'] . "\n";
    echo "Label: " . $new_key['label'] . "\n";

    // Key format: nbcache_{40_hex_chars}
    // Example: nbcache_a1b2c3d4e5f6...
}
```

---

### Validate API Key

```php
$provided_key = 'nbcache_abc123...';
$key_data = NewBook_API_Key_Manager::validate_key($provided_key);

if ($key_data) {
    echo "Valid key: " . $key_data['label'];
    echo "\nUsage count: " . $key_data['usage_count'];
} else {
    echo "Invalid API key";
}
```

---

### Revoke API Key

```php
// Revoke (sets is_active = 0)
if (NewBook_API_Key_Manager::revoke_key($key_id)) {
    echo "API key revoked";
}
```

---

### Delete API Key

```php
// Permanently delete
NewBook_API_Key_Manager::delete_key($key_id);
```

---

### List All API Keys

```php
// Active keys only
$active_keys = NewBook_API_Key_Manager::get_all_keys(true);

// All keys (including revoked)
$all_keys = NewBook_API_Key_Manager::get_all_keys(false);

foreach ($active_keys as $key) {
    echo "ID: {$key->id}\n";
    echo "Label: {$key->key_label}\n";
    echo "Created: {$key->created_date}\n";
    echo "Last used: {$key->last_used}\n";
    echo "Usage count: {$key->usage_count}\n\n";
}
```

---

### Get API Key Stats

```php
$stats = NewBook_API_Key_Manager::get_stats();

echo "Active keys: " . $stats['total_active_keys'] . "\n";
echo "Total API calls: " . $stats['total_usage'] . "\n";
echo "Last used: " . $stats['last_used'] . "\n";
```

---

## REST API

### Authentication

**Option 1: WordPress Application Password**

```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/bookings/list \
  -u username:application_password \
  -H "Content-Type: application/json" \
  -d '{"period_from":"2025-11-01","period_to":"2025-11-30"}'
```

**Option 2: API Key (Bearer Token)**

```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/bookings/list \
  -H "Authorization: Bearer nbcache_abc123..." \
  -H "Content-Type: application/json" \
  -d '{"period_from":"2025-11-01","period_to":"2025-11-30"}'
```

---

### Fetch Bookings (REST)

```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/bookings/list \
  -H "Authorization: Bearer nbcache_abc123..." \
  -H "Content-Type: application/json" \
  -d '{
    "period_from": "2025-11-01 00:00:00",
    "period_to": "2025-11-30 23:59:59",
    "list_type": "staying",
    "force_refresh": false
  }'
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "booking_id": 123456,
      "booking_arrival": "2025-11-15",
      "booking_departure": "2025-11-17",
      "booking_status": "confirmed",
      "site_name": "Cabin 5",
      "guest_name": "John Doe"
    }
  ],
  "_cache_hit": true
}
```

---

### Get Single Booking (REST)

```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/bookings/get \
  -H "Authorization: Bearer nbcache_abc123..." \
  -H "Content-Type: application/json" \
  -d '{"booking_id": 123456}'
```

---

### Get Sites List (REST)

```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/sites/list \
  -H "Authorization: Bearer nbcache_abc123..." \
  -H "Content-Type: application/json"
```

---

### Get Cache Stats (REST)

```bash
curl https://yoursite.com/wp-json/newbook-cache/v1/cache/stats \
  -H "Authorization: Bearer nbcache_abc123..."
```

**Response:**

```json
{
  "success": true,
  "cache": {
    "total_bookings": 1250,
    "hot_bookings": 850,
    "historical_bookings": 400,
    "active_bookings": 600,
    "checked_out": 500,
    "cancelled": 150,
    "database_size_mb": 12.5
  },
  "api_keys": {
    "total_active_keys": 3,
    "total_usage": 15234,
    "last_used": "2025-11-19 14:30:00"
  }
}
```

---

### JavaScript/Fetch Example

```javascript
const fetchBookings = async () => {
    const response = await fetch('https://yoursite.com/wp-json/newbook-cache/v1/bookings/list', {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer nbcache_abc123...',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            period_from: '2025-11-01 00:00:00',
            period_to: '2025-11-30 23:59:59',
            list_type: 'staying'
        })
    });

    const data = await response.json();

    if (data.success) {
        console.log('Bookings:', data.data);
        console.log('Cache hit:', data._cache_hit);
    }
};
```

---

## Statistics

### Get Cache Stats

```php
global $newbook_api_cache;
$stats = $newbook_api_cache->get_cache_stats();

echo "Total bookings: " . $stats['total_bookings'] . "\n";
echo "Future bookings: " . $stats['hot_bookings'] . "\n";
echo "Past bookings: " . $stats['historical_bookings'] . "\n";
echo "Active bookings: " . $stats['active_bookings'] . "\n";
echo "Checked out: " . $stats['checked_out'] . "\n";
echo "Cancelled: " . $stats['cancelled'] . "\n";
echo "Database size: " . $stats['database_size_mb'] . " MB\n";
```

---

### Get Cache Summary by Date

```php
global $newbook_api_cache;

// Get all dates
$summary = $newbook_api_cache->get_cache_summary_by_date();

// Filter by year
$summary_2025 = $newbook_api_cache->get_cache_summary_by_date(2025);

// Filter by year and month
$summary_nov_2025 = $newbook_api_cache->get_cache_summary_by_date(2025, 11);

foreach ($summary_nov_2025 as $date) {
    echo "Date: {$date->cache_date}\n";
    echo "Total bookings: {$date->total_bookings}\n";
    echo "Active: {$date->active_count}\n";
    echo "Cancelled: {$date->cancelled_count}\n";
    echo "Last updated: {$date->last_cache_update}\n\n";
}
```

---

## Common Use Cases

### Use Case 1: Display Bookings for Current Month

```php
global $newbook_api_cache;

$first_day = date('Y-m-01 00:00:00');
$last_day = date('Y-m-t 23:59:59');

$response = $newbook_api_cache->call_api(
    'bookings_list',
    array(
        'period_from' => $first_day,
        'period_to' => $last_day,
        'list_type' => 'staying'
    )
);

if ($response['success']) {
    foreach ($response['data'] as $booking) {
        echo "Booking #{$booking['booking_id']}: ";
        echo "{$booking['guest_name']} - ";
        echo "{$booking['site_name']}\n";
    }
}
```

---

### Use Case 2: Check Availability for Specific Date Range

```php
global $newbook_api_cache;

$check_in = '2025-11-15';
$check_out = '2025-11-17';

$response = $newbook_api_cache->call_api(
    'bookings_list',
    array(
        'period_from' => $check_in . ' 00:00:00',
        'period_to' => $check_out . ' 23:59:59',
        'list_type' => 'staying'
    )
);

if ($response['success']) {
    $occupied_sites = array();
    foreach ($response['data'] as $booking) {
        $occupied_sites[] = $booking['site_name'];
    }

    echo "Occupied sites: " . implode(', ', $occupied_sites);

    // Get all sites and calculate available
    $all_sites_response = $newbook_api_cache->call_api('sites_list', array());
    if ($all_sites_response['success']) {
        $available_sites = array_filter(
            $all_sites_response['data'],
            function($site) use ($occupied_sites) {
                return !in_array($site['site_name'], $occupied_sites);
            }
        );
        echo "\nAvailable sites: " . count($available_sites);
    }
}
```

---

### Use Case 3: Get Recently Placed Bookings

```php
global $newbook_api_cache;

// Last 7 days
$from = date('Y-m-d H:i:s', strtotime('-7 days'));
$to = date('Y-m-d H:i:s');

$response = $newbook_api_cache->call_api(
    'bookings_list',
    array(
        'period_from' => $from,
        'period_to' => $to,
        'list_type' => 'placed'  // Query by placement date
    )
);

if ($response['success']) {
    echo "New bookings in last 7 days: " . count($response['data']) . "\n";
    foreach ($response['data'] as $booking) {
        echo "Booking #{$booking['booking_id']} placed on {$booking['booking_placed']}\n";
    }
}
```

---

### Use Case 4: Get Cancellations This Month

```php
global $newbook_api_cache;

$first_day = date('Y-m-01 00:00:00');
$last_day = date('Y-m-t 23:59:59');

$response = $newbook_api_cache->call_api(
    'bookings_list',
    array(
        'period_from' => $first_day,
        'period_to' => $last_day,
        'list_type' => 'cancelled'  // Query by cancellation date
    )
);

if ($response['success']) {
    echo "Cancellations this month: " . count($response['data']) . "\n";
    foreach ($response['data'] as $booking) {
        echo "Booking #{$booking['booking_id']} cancelled on {$booking['booking_cancelled']}\n";
    }
}
```

---

### Use Case 5: Monitor Cache Performance

```php
global $newbook_api_cache;

// Get stats
$stats = $newbook_api_cache->get_cache_stats();

// Calculate metrics
$cache_efficiency = ($stats['total_bookings'] > 0)
    ? ($stats['hot_bookings'] / $stats['total_bookings'] * 100)
    : 0;

echo "Cache Performance Report\n";
echo "========================\n";
echo "Total bookings cached: {$stats['total_bookings']}\n";
echo "Database size: {$stats['database_size_mb']} MB\n";
echo "Hot cache efficiency: " . round($cache_efficiency, 2) . "%\n\n";

// Get recent errors
$errors = NewBook_Cache_Logger::get_logs(array(
    'level' => NewBook_Cache_Logger::ERROR,
    'limit' => 10
));

if (!empty($errors)) {
    echo "Recent Errors:\n";
    foreach ($errors as $error) {
        echo "[{$error->timestamp}] {$error->message}\n";
    }
}
```

---

### Use Case 6: Create External API Integration

```php
// external-system.php

class ExternalBookingSystem {

    private $api_key = 'nbcache_abc123...';
    private $base_url = 'https://yoursite.com/wp-json/newbook-cache/v1';

    public function fetchBookings($from, $to) {
        $url = $this->base_url . '/bookings/list';

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'period_from' => $from,
                'period_to' => $to,
                'list_type' => 'staying'
            ))
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body['success'] ? $body['data'] : false;
    }

    public function getCacheStats() {
        $url = $this->base_url . '/cache/stats';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body['success'] ? $body : false;
    }
}

// Usage
$external = new ExternalBookingSystem();
$bookings = $external->fetchBookings('2025-11-01', '2025-11-30');
$stats = $external->getCacheStats();
```

---

### Use Case 7: Admin Dashboard Widget

```php
// Add dashboard widget
add_action('wp_dashboard_setup', 'newbook_cache_dashboard_widget');

function newbook_cache_dashboard_widget() {
    wp_add_dashboard_widget(
        'newbook_cache_widget',
        'NewBook Cache Status',
        'newbook_cache_widget_display'
    );
}

function newbook_cache_widget_display() {
    global $newbook_api_cache;

    $stats = $newbook_api_cache->get_cache_stats();
    $last_sync = get_option('newbook_cache_last_incremental_sync', 'Never');

    echo "<div class='newbook-cache-widget'>";
    echo "<h3>Cache Statistics</h3>";
    echo "<table>";
    echo "<tr><td>Total Bookings:</td><td><strong>{$stats['total_bookings']}</strong></td></tr>";
    echo "<tr><td>Active Bookings:</td><td><strong>{$stats['active_bookings']}</strong></td></tr>";
    echo "<tr><td>Database Size:</td><td><strong>{$stats['database_size_mb']} MB</strong></td></tr>";
    echo "<tr><td>Last Sync:</td><td><strong>{$last_sync}</strong></td></tr>";
    echo "</table>";

    echo "<p><a href='" . admin_url('options-general.php?page=newbook-cache-settings') . "' class='button'>View Settings</a></p>";
    echo "</div>";
}
```

---

## Quick Command Reference

### Plugin Activation

```php
newbook_cache_activate();  // Run activation tasks
newbook_cache_create_tables();  // Create database tables
newbook_cache_schedule_cron();  // Schedule cron jobs
```

---

### Plugin Deactivation

```php
newbook_cache_deactivate();  // Run deactivation tasks
newbook_cache_unschedule_cron();  // Remove cron jobs
```

---

### Global Access

```php
global $newbook_api_cache;  // Main cache instance
```

---

### WordPress Filters

```php
// Check if cache is being used
$is_cached = apply_filters('bma_use_newbook_cache', false);

// Intercept API call
apply_filters('bma_newbook_api_call', null, $action, $data, $context);
```

---

## Configuration Options

### Plugin Settings (wp_options)

```php
// Enable/disable caching
update_option('newbook_cache_enabled', true);

// Sync settings
update_option('newbook_cache_enable_incremental_sync', true);
update_option('newbook_cache_enable_daily_refresh', true);
update_option('newbook_cache_sync_interval', 20);  // seconds

// Retention settings
update_option('newbook_cache_retention_past', 30);  // days
update_option('newbook_cache_retention_future', 365);  // days
update_option('newbook_cache_retention_cancelled', 30);  // days

// Logging
update_option('newbook_cache_log_level', NewBook_Cache_Logger::INFO);
update_option('newbook_cache_max_logs', 1000);

// Security
update_option('newbook_cache_allow_unknown_relay', false);
update_option('newbook_cache_anonymize_ips', true);

// API credentials
update_option('newbook_cache_username', 'your_username');
update_option('newbook_cache_password', 'your_password');
update_option('newbook_cache_api_key', 'your_api_key');
update_option('newbook_cache_region', 'au');
```

---

## Constants

```php
NEWBOOK_CACHE_VERSION          // Plugin version
NEWBOOK_CACHE_PLUGIN_DIR       // Full path to plugin directory
NEWBOOK_CACHE_PLUGIN_URL       // URL to plugin directory
NEWBOOK_CACHE_PLUGIN_FILE      // Full path to main plugin file
```

---

## Error Handling

### Check for Errors

```php
$response = $newbook_api_cache->call_api('bookings_list', $params);

if (!$response['success']) {
    error_log('NewBook API error: ' . $response['message']);
    // Handle error
}
```

---

### Validate API Connection

```php
$client = new NewBook_API_Client();
$test = $client->test_connection();

if ($test !== true) {
    // Connection failed
    echo "Error: " . $test;
}
```

---

## Performance Tips

1. **Use Cache**: Always use cache unless you need real-time data
2. **Avoid force_refresh**: Let incremental sync handle updates
3. **Set Appropriate Retention**: Balance cache size vs data needs
4. **Monitor Log Level**: Use INFO in production, DEBUG only for troubleshooting
5. **Check Database Size**: Monitor `database_size_mb` in stats
6. **Use REST API**: For external systems, REST API is more efficient than direct database access

---

## Security Best Practices

1. **Use API Keys**: Generate separate keys for each external system
2. **Revoke Old Keys**: Regularly audit and revoke unused keys
3. **Monitor Usage**: Check `usage_count` for unusual activity
4. **Enable IP Anonymization**: Complies with GDPR
5. **Use HTTPS**: Always use HTTPS for REST API calls
6. **Rotate Keys**: Periodically generate new keys and revoke old ones
