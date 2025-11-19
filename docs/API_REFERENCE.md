# API Reference

Complete reference documentation for all classes and methods in the NewBook API Cache plugin.

---

## Table of Contents

1. [NewBook_Cache](#newbook_cache)
2. [NewBook_API_Client](#newbook_api_client)
3. [NewBook_Cache_Sync](#newbook_cache_sync)
4. [NewBook_Cache_Logger](#newbook_cache_logger)
5. [NewBook_API_Key_Manager](#newbook_api_key_manager)
6. [NewBook_Cache_REST_Controller](#newbook_cache_rest_controller)

---

## NewBook_Cache

**File:** `includes/class-newbook-cache.php`

**Purpose:** Core cache logic with encryption, transparent proxy functionality, and intelligent routing for NewBook API requests.

### Constructor

#### `__construct()`

Initializes the cache system and registers WordPress filters.

**Parameters:** None

**Returns:** void

**Example:**
```php
global $newbook_api_cache;
$newbook_api_cache = new NewBook_API_Cache();
```

---

### Public Methods

#### `intercept_api_call($response, $action, $data, $context_info)`

Intercepts API calls from the booking-match-api plugin using WordPress filters.

**Parameters:**
- `$response` (mixed) - Existing response (null if not set)
- `$action` (string) - API action name (e.g., 'bookings_list', 'bookings_get')
- `$data` (array) - Request parameters
- `$context_info` (array) - Context data (caller, user, IP, route, force_refresh, etc.)

**Returns:** array - NewBook API response format

**Example:**
```php
// This method is automatically called via WordPress filter
// Manual usage:
$response = $cache->intercept_api_call(
    null,
    'bookings_list',
    array('period_from' => '2025-01-01', 'period_to' => '2025-01-31'),
    array('force_refresh' => false, 'caller' => 'manual')
);
```

---

#### `is_caching_enabled()`

Checks if caching is enabled in plugin settings.

**Parameters:** None

**Returns:** bool - True if caching is enabled

**Example:**
```php
if ($cache->is_caching_enabled()) {
    // Caching is active
}
```

---

#### `call_api($action, $data, $force_refresh, $context_info)`

Main API gateway - routes all NewBook API requests through the caching layer.

**Parameters:**
- `$action` (string) - API action ('bookings_list', 'bookings_get', 'sites_list')
- `$data` (array) - Request parameters
- `$force_refresh` (bool) - Bypass cache and fetch fresh data
- `$context_info` (array) - Context data for logging

**Returns:** array - NewBook API response format
```php
array(
    'success' => true/false,
    'data' => array(...),
    'message' => 'Error message if failed',
    '_cache_hit' => true/false  // Present if from cache
)
```

**Example:**
```php
// Fetch bookings for November 2025
$response = $cache->call_api(
    'bookings_list',
    array(
        'period_from' => '2025-11-01 00:00:00',
        'period_to' => '2025-11-30 23:59:59',
        'list_type' => 'staying'
    ),
    false,  // Use cache if available
    array(
        'caller' => 'dashboard',
        'username' => 'admin'
    )
);

if ($response['success']) {
    foreach ($response['data'] as $booking) {
        echo "Booking ID: " . $booking['booking_id'] . "\n";
    }
}
```

---

#### `store_booking($booking)`

Stores a booking in the encrypted cache.

**Parameters:**
- `$booking` (array) - Booking data from NewBook API

**Returns:** bool - Success status

**Example:**
```php
$booking = array(
    'booking_id' => 123456,
    'booking_arrival' => '2025-11-15',
    'booking_departure' => '2025-11-17',
    'booking_status' => 'confirmed',
    'site_name' => 'Cabin 5',
    'booking_adults' => 2,
    'booking_children' => 1
);

if ($cache->store_booking($booking)) {
    echo "Booking cached successfully";
}
```

---

#### `clear_booking_cache($booking_id)`

Clears cache for a specific booking.

**Parameters:**
- `$booking_id` (int) - Booking ID to clear

**Returns:** bool - Success status

**Example:**
```php
// Clear cache after booking modification
$cache->clear_booking_cache(123456);
```

---

#### `clear_all_cache()`

Clears all cached bookings and sites.

**Parameters:** None

**Returns:** bool - Success status

**Example:**
```php
$cache->clear_all_cache();
```

---

#### `get_cache_stats()`

Retrieves comprehensive cache statistics.

**Parameters:** None

**Returns:** array - Statistics array

**Response Format:**
```php
array(
    'total_bookings' => 1250,
    'hot_bookings' => 850,          // Future bookings
    'historical_bookings' => 400,   // Past bookings
    'active_bookings' => 600,
    'checked_out' => 500,
    'cancelled' => 150,
    'database_size_mb' => 12.5,
    'all_statuses' => array('confirmed', 'departed', 'cancelled')
)
```

**Example:**
```php
$stats = $cache->get_cache_stats();
echo "Total bookings cached: " . $stats['total_bookings'];
echo "\nDatabase size: " . $stats['database_size_mb'] . " MB";
```

---

#### `get_cache_summary_by_date($year, $month)`

Gets cache summary grouped by arrival date.

**Parameters:**
- `$year` (int|null) - Optional year filter (e.g., 2025)
- `$month` (int|null) - Optional month filter (1-12)

**Returns:** array - Array of date summaries

**Response Format:**
```php
array(
    array(
        'cache_date' => '2025-11-15',
        'total_bookings' => 25,
        'last_cache_update' => '2025-11-19 10:30:00',
        'cancelled_count' => 2,
        'active_count' => 23,
        'earliest_arrival' => '2025-11-15',
        'latest_departure' => '2025-11-20'
    ),
    // ... more dates
)
```

**Example:**
```php
// Get summary for November 2025
$summary = $cache->get_cache_summary_by_date(2025, 11);
foreach ($summary as $date_info) {
    echo "{$date_info['cache_date']}: {$date_info['total_bookings']} bookings\n";
}
```

---

## NewBook_API_Client

**File:** `includes/class-api-client.php`

**Purpose:** Handles direct communication with NewBook API over HTTPS.

### Public Methods

#### `call_api($action, $data)`

Makes an authenticated API request to NewBook.

**Parameters:**
- `$action` (string) - API action endpoint
- `$data` (array) - Request parameters (automatically adds api_key and region)

**Returns:** array - NewBook API response

**Response Format:**
```php
array(
    'success' => true/false,
    'data' => array(...),
    'message' => 'Error message if failed'
)
```

**Example:**
```php
$client = new NewBook_API_Client();

$response = $client->call_api('bookings_get', array(
    'booking_id' => 123456
));

if ($response['success']) {
    $booking = $response['data'];
    echo "Guest: " . $booking['guest_name'];
}
```

---

#### `test_connection()`

Tests API connection with current credentials.

**Parameters:** None

**Returns:** bool|string - True on success, error message on failure

**Example:**
```php
$client = new NewBook_API_Client();
$result = $client->test_connection();

if ($result === true) {
    echo "Connection successful!";
} else {
    echo "Connection failed: " . $result;
}
```

---

## NewBook_Cache_Sync

**File:** `includes/class-cache-sync.php`

**Purpose:** Handles scheduled cache synchronization via WordPress cron jobs.

### Constructor

#### `__construct()`

Initializes sync system and registers cron hooks.

**Parameters:** None

**Example:**
```php
new NewBook_Cache_Sync();
```

---

### Public Methods

#### `full_refresh()`

Performs full cache refresh - fetches all bookings within retention window.

**Parameters:** None

**Returns:** void

**Process:**
1. Fetches future bookings in 30-day chunks (configurable retention period)
2. Fetches cancelled bookings (last 30 days)
3. Fetches past bookings (configurable lookback period)

**Scheduled:** Daily at 3:00 AM via cron

**Example:**
```php
$sync = new NewBook_Cache_Sync();
$sync->full_refresh();
```

---

#### `incremental_sync()`

Performs incremental sync - only fetches bookings modified since last sync.

**Parameters:** None

**Returns:** void

**Process:**
1. Queries NewBook for bookings modified since last sync
2. Updates or adds changed bookings to cache
3. Logs number of updated/added bookings

**Scheduled:** Every 20 seconds (configurable) via cron

**Example:**
```php
$sync = new NewBook_Cache_Sync();
$sync->incremental_sync();
```

---

#### `cleanup()`

Removes old bookings from cache based on retention policies.

**Parameters:** None

**Returns:** void

**Process:**
1. Deletes checked-out bookings older than retention period
2. Deletes cancelled bookings older than cancellation retention period

**Scheduled:** Daily at 4:00 AM via cron

**Example:**
```php
$sync = new NewBook_Cache_Sync();
$sync->cleanup();
```

---

#### `trigger_full_refresh()`

Manually triggers a full refresh (called from admin interface).

**Parameters:** None

**Returns:** void

**Example:**
```php
// Triggered from admin settings page
$sync = new NewBook_Cache_Sync();
$sync->trigger_full_refresh();
```

---

#### `trigger_cleanup()`

Manually triggers cache cleanup.

**Parameters:** None

**Returns:** void

**Example:**
```php
$sync = new NewBook_Cache_Sync();
$sync->trigger_cleanup();
```

---

## NewBook_Cache_Logger

**File:** `includes/class-logger.php`

**Purpose:** Centralized logging system with configurable log levels and automatic cleanup.

### Constants

```php
const OFF = 0;    // Logging disabled
const ERROR = 1;  // Only errors
const INFO = 2;   // Errors and info
const DEBUG = 3;  // All messages including debug
```

---

### Static Methods

#### `log($message, $level, $context)`

Logs a message to database and optionally WP debug log.

**Parameters:**
- `$message` (string) - Log message
- `$level` (int) - Log level constant (ERROR, INFO, DEBUG)
- `$context` (array) - Optional additional context data

**Returns:** void

**Example:**
```php
// Log an error
NewBook_Cache_Logger::log(
    'API connection failed',
    NewBook_Cache_Logger::ERROR,
    array('endpoint' => 'bookings_list', 'timeout' => 30)
);

// Log info
NewBook_Cache_Logger::log(
    'Cache refresh completed',
    NewBook_Cache_Logger::INFO,
    array('bookings_updated' => 150)
);

// Log debug info
NewBook_Cache_Logger::log(
    'Cache query: arrival_date between 2025-11-01 and 2025-11-30',
    NewBook_Cache_Logger::DEBUG
);
```

---

#### `get_logs($args)`

Retrieves log entries with filtering and pagination.

**Parameters:**
- `$args` (array) - Query arguments
  - `level` (int|null) - Filter by log level
  - `search` (string|null) - Search in message text
  - `limit` (int) - Number of entries to return (default: 50)
  - `offset` (int) - Pagination offset (default: 0)
  - `order` (string) - Sort order: 'ASC' or 'DESC' (default: 'DESC')

**Returns:** array - Array of log entry objects

**Example:**
```php
// Get last 100 error logs
$errors = NewBook_Cache_Logger::get_logs(array(
    'level' => NewBook_Cache_Logger::ERROR,
    'limit' => 100,
    'order' => 'DESC'
));

foreach ($errors as $log) {
    echo "[{$log->timestamp}] {$log->message}\n";
}

// Search logs
$search_results = NewBook_Cache_Logger::get_logs(array(
    'search' => 'API timeout',
    'limit' => 50
));
```

---

#### `get_log_count($args)`

Gets count of log entries matching criteria.

**Parameters:**
- `$args` (array) - Query arguments
  - `level` (int|null) - Filter by log level
  - `search` (string|null) - Search in message text

**Returns:** int - Number of matching log entries

**Example:**
```php
$error_count = NewBook_Cache_Logger::get_log_count(array(
    'level' => NewBook_Cache_Logger::ERROR
));

echo "Total errors: {$error_count}";
```

---

#### `clear_logs()`

Clears all log entries.

**Parameters:** None

**Returns:** void

**Example:**
```php
NewBook_Cache_Logger::clear_logs();
```

---

#### `get_level_name($level)`

Converts log level constant to human-readable name.

**Parameters:**
- `$level` (int) - Log level constant

**Returns:** string - Level name ('ERROR', 'INFO', 'DEBUG', 'UNKNOWN')

**Example:**
```php
$level_name = NewBook_Cache_Logger::get_level_name(NewBook_Cache_Logger::ERROR);
echo $level_name; // Outputs: "ERROR"
```

---

## NewBook_API_Key_Manager

**File:** `includes/class-api-key-manager.php`

**Purpose:** Manages API keys for external system authentication to REST endpoints.

### Static Methods

#### `generate_key($label)`

Generates a new API key for external systems.

**Parameters:**
- `$label` (string) - Human-readable label for the key

**Returns:** array|false - Array with key details on success, false on failure

**Response Format:**
```php
array(
    'key' => 'nbcache_abc123...', // Full key (shown ONCE)
    'id' => 1,
    'label' => 'Mobile App'
)
```

**Security Note:** The full key is returned only once and should be stored securely by the client.

**Example:**
```php
$new_key = NewBook_API_Key_Manager::generate_key('Mobile App v2.0');

if ($new_key) {
    echo "API Key (save this!): " . $new_key['key'];
    echo "\nKey ID: " . $new_key['id'];
    echo "\nLabel: " . $new_key['label'];
}
```

---

#### `validate_key($key)`

Validates an API key.

**Parameters:**
- `$key` (string) - Full API key from Authorization header

**Returns:** array|false - Key details on success, false if invalid

**Response Format:**
```php
array(
    'id' => 1,
    'label' => 'Mobile App',
    'created_date' => '2025-11-01 10:00:00',
    'last_used' => '2025-11-19 14:30:00',
    'usage_count' => 1523
)
```

**Example:**
```php
$key_data = NewBook_API_Key_Manager::validate_key($provided_key);

if ($key_data) {
    echo "Valid key: " . $key_data['label'];
} else {
    echo "Invalid API key";
}
```

---

#### `track_usage($key_id)`

Tracks API key usage (increments counter, updates last_used timestamp).

**Parameters:**
- `$key_id` (int) - API key ID

**Returns:** bool - Success status

**Example:**
```php
NewBook_API_Key_Manager::track_usage(1);
```

---

#### `revoke_key($key_id)`

Revokes an API key (sets is_active to 0).

**Parameters:**
- `$key_id` (int) - API key ID

**Returns:** bool - Success status

**Example:**
```php
if (NewBook_API_Key_Manager::revoke_key(1)) {
    echo "API key revoked successfully";
}
```

---

#### `delete_key($key_id)`

Permanently deletes an API key from database.

**Parameters:**
- `$key_id` (int) - API key ID

**Returns:** bool - Success status

**Example:**
```php
NewBook_API_Key_Manager::delete_key(1);
```

---

#### `get_all_keys($active_only)`

Retrieves all API keys.

**Parameters:**
- `$active_only` (bool) - Only return active keys (default: true)

**Returns:** array - Array of key objects

**Example:**
```php
$active_keys = NewBook_API_Key_Manager::get_all_keys(true);

foreach ($active_keys as $key) {
    echo "ID: {$key->id}, Label: {$key->key_label}, ";
    echo "Usage: {$key->usage_count}, Last used: {$key->last_used}\n";
}
```

---

#### `get_stats()`

Gets API key usage statistics.

**Parameters:** None

**Returns:** array - Statistics

**Response Format:**
```php
array(
    'total_active_keys' => 3,
    'total_usage' => 15234,
    'last_used' => '2025-11-19 14:30:00'
)
```

**Example:**
```php
$stats = NewBook_API_Key_Manager::get_stats();
echo "Active keys: {$stats['total_active_keys']}\n";
echo "Total API calls: {$stats['total_usage']}\n";
```

---

## NewBook_Cache_REST_Controller

**File:** `includes/class-cache-rest-controller.php`

**Purpose:** Provides NewBook-compatible REST API endpoints with dual authentication (WordPress + API keys).

### Constructor

#### `__construct()`

Initializes REST controller and gets reference to cache instance.

**Parameters:** None

**Example:**
```php
$controller = new NewBook_Cache_REST_Controller();
```

---

### Public Methods

#### `register_routes()`

Registers all REST API routes.

**Parameters:** None

**Returns:** void

**Registered Routes:**
- `POST /wp-json/newbook-cache/v1/bookings/list`
- `POST /wp-json/newbook-cache/v1/bookings/get`
- `POST /wp-json/newbook-cache/v1/sites/list`
- `GET /wp-json/newbook-cache/v1/cache/stats`

**Example:**
```php
$controller = new NewBook_Cache_REST_Controller();
$controller->register_routes();
```

---

#### `authenticate_request($request)`

Authenticates REST API requests using WordPress credentials OR API keys.

**Parameters:**
- `$request` (WP_REST_Request) - REST request object

**Returns:** bool|WP_Error - True if authenticated, WP_Error if not

**Authentication Methods:**
1. WordPress application passwords (HTTP Basic Auth)
2. WordPress session/cookies
3. Custom API keys (Bearer token)

**Example:**
```php
// This method is called automatically by WordPress REST API
// Authentication is handled via HTTP headers
```

---

#### `bookings_list($request)`

REST endpoint for fetching bookings by date range.

**Endpoint:** `POST /wp-json/newbook-cache/v1/bookings/list`

**Parameters (in request body):**
- `period_from` (string) - Start date/time (YYYY-MM-DD HH:MM:SS)
- `period_to` (string) - End date/time (YYYY-MM-DD HH:MM:SS)
- `list_type` (string) - Filter: 'staying', 'placed', 'cancelled', 'all'
- `booking_id` (int) - Optional: specific booking ID
- `force_refresh` (bool) - Optional: bypass cache

**Returns:** WP_REST_Response

**Example Request:**
```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/bookings/list \
  -H "Authorization: Bearer nbcache_abc123..." \
  -H "Content-Type: application/json" \
  -d '{
    "period_from": "2025-11-01 00:00:00",
    "period_to": "2025-11-30 23:59:59",
    "list_type": "staying"
  }'
```

**Example Response:**
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

#### `bookings_get($request)`

REST endpoint for fetching a single booking by ID.

**Endpoint:** `POST /wp-json/newbook-cache/v1/bookings/get`

**Parameters (in request body):**
- `booking_id` (int) - NewBook booking ID
- `force_refresh` (bool) - Optional: bypass cache

**Returns:** WP_REST_Response

**Example Request:**
```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/bookings/get \
  -H "Authorization: Bearer nbcache_abc123..." \
  -H "Content-Type: application/json" \
  -d '{"booking_id": 123456}'
```

---

#### `sites_list($request)`

REST endpoint for fetching all sites/rooms.

**Endpoint:** `POST /wp-json/newbook-cache/v1/sites/list`

**Parameters (in request body):**
- `force_refresh` (bool) - Optional: bypass cache

**Returns:** WP_REST_Response

**Example Request:**
```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/sites/list \
  -H "Authorization: Bearer nbcache_abc123..." \
  -H "Content-Type: application/json"
```

---

#### `cache_stats($request)`

REST endpoint for fetching cache statistics.

**Endpoint:** `GET /wp-json/newbook-cache/v1/cache/stats`

**Parameters:** None

**Returns:** WP_REST_Response

**Example Request:**
```bash
curl https://yoursite.com/wp-json/newbook-cache/v1/cache/stats \
  -H "Authorization: Bearer nbcache_abc123..."
```

**Example Response:**
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

#### `get_endpoint_docs()` (static)

Returns comprehensive endpoint documentation.

**Parameters:** None

**Returns:** array - Endpoint documentation

**Example:**
```php
$docs = NewBook_Cache_REST_Controller::get_endpoint_docs();
print_r($docs['bookings_list']);
```

---

## WordPress Filters

The plugin provides the following WordPress filters for integration:

### `bma_newbook_api_call`

Intercepts NewBook API calls from booking-match-api plugin.

**Parameters:**
- `$response` (mixed) - Existing response
- `$action` (string) - API action
- `$data` (array) - Request parameters
- `$context_info` (array) - Context data

**Example:**
```php
add_filter('bma_newbook_api_call', function($response, $action, $data, $context) {
    // Custom logic before/after cache
    return $response;
}, 10, 4);
```

---

### `bma_use_newbook_cache`

Checks if caching is enabled.

**Returns:** bool

**Example:**
```php
if (apply_filters('bma_use_newbook_cache', false)) {
    echo "Caching is enabled";
}
```

---

## Global Variables

### `$newbook_api_cache`

Global instance of NewBook_API_Cache.

**Example:**
```php
global $newbook_api_cache;
$stats = $newbook_api_cache->get_cache_stats();
```

---

## Constants

```php
NEWBOOK_CACHE_VERSION        // Plugin version
NEWBOOK_CACHE_PLUGIN_DIR     // Plugin directory path
NEWBOOK_CACHE_PLUGIN_URL     // Plugin URL
NEWBOOK_CACHE_PLUGIN_FILE    // Main plugin file path
```
