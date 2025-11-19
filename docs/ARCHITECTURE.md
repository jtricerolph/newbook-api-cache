# Architecture Documentation

System architecture and design documentation for the NewBook API Cache plugin.

---

## Table of Contents

1. [Overview](#overview)
2. [System Architecture](#system-architecture)
3. [Database Schema](#database-schema)
4. [Caching System](#caching-system)
5. [Sync Strategies](#sync-strategies)
6. [Cache Invalidation](#cache-invalidation)
7. [Security Architecture](#security-architecture)
8. [Integration Points](#integration-points)
9. [Performance Considerations](#performance-considerations)

---

## Overview

The NewBook API Cache plugin provides a high-performance caching layer between WordPress applications and the NewBook API. It intercepts API calls, serves cached data when available, and maintains data freshness through scheduled synchronization.

### Key Features

- **Transparent Proxy**: Automatically intercepts API calls from booking-match-api plugin
- **Encrypted Storage**: All booking data stored with AES-256-CBC encryption
- **Intelligent Routing**: Smart decision-making about cache hits vs API calls
- **Multiple Sync Strategies**: Full refresh, incremental sync, and daily maintenance
- **Dual Authentication**: WordPress credentials + custom API keys for REST endpoints
- **Comprehensive Logging**: Configurable logging with automatic cleanup

---

## System Architecture

### Component Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     WordPress Application                        │
│                    (booking-match-api plugin)                   │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            │ apply_filters('bma_newbook_api_call')
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    NewBook_API_Cache                             │
│                  (Transparent Proxy Layer)                       │
│                                                                   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │ Request      │  │ Cache Logic  │  │ Encryption   │          │
│  │ Interceptor  │─▶│ & Routing    │─▶│ Layer        │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
│                            │                                      │
│                            ├──────────┬──────────┐               │
│                            ▼          ▼          ▼               │
│                    ┌──────────┐ ┌─────────┐ ┌─────────┐         │
│                    │ Database │ │ API     │ │ Logger  │         │
│                    │ Cache    │ │ Client  │ │         │         │
│                    └──────────┘ └─────────┘ └─────────┘         │
└─────────────────────────────────────────────────────────────────┘
                                   │
                                   │ HTTPS
                                   ▼
                        ┌──────────────────────┐
                        │   NewBook API        │
                        │ (api.newbook.cloud)  │
                        └──────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    Background Sync System                        │
│                                                                   │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────┐  │
│  │ Full Refresh     │  │ Incremental Sync │  │ Cleanup      │  │
│  │ (Daily 3 AM)     │  │ (Every 20 sec)   │  │ (Daily 4 AM) │  │
│  └──────────────────┘  └──────────────────┘  └──────────────┘  │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    REST API Layer                                │
│              (External System Integration)                       │
│                                                                   │
│  Authentication: WordPress App Passwords OR API Keys            │
│                                                                   │
│  Endpoints:                                                      │
│  • POST /bookings/list                                          │
│  • POST /bookings/get                                           │
│  • POST /sites/list                                             │
│  • GET  /cache/stats                                            │
└─────────────────────────────────────────────────────────────────┘
```

### Request Flow

1. **WordPress Application** makes NewBook API call via booking-match-api
2. **WordPress Filter Hook** (`bma_newbook_api_call`) intercepts the call
3. **NewBook_API_Cache** receives request and determines routing:
   - If `force_refresh=true`: Skip cache, go to step 6
   - If action is unknown and relay disabled: Return error
   - Otherwise, check cache
4. **Cache Query**: Search encrypted database for matching data
5. **Cache Decision**:
   - **Cache Hit**: Decrypt and return data immediately
   - **Cache Miss**: Proceed to API call
6. **API Call**: NewBook_API_Client makes HTTPS request to NewBook
7. **Store Response**: Cache the API response for future requests
8. **Return Data**: Send response back to application
9. **Logger**: Record request details, cache hit/miss, performance metrics

---

## Database Schema

### Table: `wp_newbook_cache`

Primary cache storage table with encrypted booking data.

```sql
CREATE TABLE wp_newbook_cache (
    booking_id BIGINT UNSIGNED NOT NULL,
    arrival_date DATE NOT NULL,
    departure_date DATE NOT NULL,
    booking_status VARCHAR(20) DEFAULT 'confirmed',
    booking_placed_date DATETIME DEFAULT NULL,
    booking_cancelled_date DATETIME DEFAULT NULL,
    group_id VARCHAR(50) DEFAULT NULL,
    room_name VARCHAR(100) DEFAULT NULL,
    num_guests TINYINT UNSIGNED DEFAULT 0,
    encrypted_data LONGTEXT NOT NULL,
    last_updated DATETIME NOT NULL,
    cache_type ENUM('hot', 'historical') DEFAULT 'hot',
    PRIMARY KEY (booking_id),
    INDEX idx_dates (arrival_date, departure_date),
    INDEX idx_status (booking_status),
    INDEX idx_placed (booking_placed_date),
    INDEX idx_cancelled (booking_cancelled_date),
    INDEX idx_cache_type (cache_type)
);
```

**Field Descriptions:**

- `booking_id`: Primary key, unique NewBook booking identifier
- `arrival_date`: Check-in date (indexed for range queries)
- `departure_date`: Check-out date (indexed for range queries)
- `booking_status`: Current status (confirmed, departed, cancelled, etc.)
- `booking_placed_date`: When booking was created (for 'placed' list type queries)
- `booking_cancelled_date`: When booking was cancelled (for 'cancelled' list type queries)
- `group_id`: NewBook group identifier for multi-booking groups
- `room_name`: Site/cabin/room name (for quick filtering)
- `num_guests`: Total guests (adults + children)
- `encrypted_data`: AES-256-CBC encrypted full booking JSON
- `last_updated`: Last cache update timestamp
- `cache_type`: 'hot' (future bookings) or 'historical' (past bookings)

**Index Strategy:**

- `idx_dates`: Composite index on arrival/departure for date range queries
- `idx_status`: Filter by booking status
- `idx_placed`: Fast queries for recently placed bookings
- `idx_cancelled`: Fast queries for cancellations
- `idx_cache_type`: Separate hot vs historical data

---

### Table: `wp_newbook_cache_logs`

Comprehensive logging table.

```sql
CREATE TABLE wp_newbook_cache_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    timestamp DATETIME NOT NULL,
    level TINYINT NOT NULL,
    message TEXT,
    context TEXT,
    memory_usage VARCHAR(20),
    PRIMARY KEY (id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_level (level)
);
```

**Field Descriptions:**

- `id`: Auto-incrementing log entry ID
- `timestamp`: When log entry was created
- `level`: Log level (0=OFF, 1=ERROR, 2=INFO, 3=DEBUG)
- `message`: Log message text
- `context`: JSON-encoded additional context data
- `memory_usage`: PHP memory usage at time of log

**Log Levels:**

- `OFF (0)`: Logging disabled
- `ERROR (1)`: Critical errors only
- `INFO (2)`: Important events (cache hits, sync results)
- `DEBUG (3)`: Detailed debugging information

**Automatic Cleanup:** Maintains maximum of 1,000 entries (configurable)

---

### Table: `wp_newbook_cache_uncached_requests`

Monitoring table for uncached/unknown API actions.

```sql
CREATE TABLE wp_newbook_cache_uncached_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    action VARCHAR(50) NOT NULL,
    params TEXT,
    timestamp DATETIME NOT NULL,
    caller VARCHAR(255),
    PRIMARY KEY (id),
    INDEX idx_action (action),
    INDEX idx_timestamp (timestamp)
);
```

**Purpose:** Track API actions that aren't cached, helping identify new endpoints to add caching support for.

---

### Table: `wp_newbook_cache_api_keys`

API key management for REST endpoint authentication.

```sql
CREATE TABLE wp_newbook_cache_api_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    key_hash CHAR(64) NOT NULL,
    key_label VARCHAR(255) NOT NULL,
    created_date DATETIME NOT NULL,
    last_used DATETIME DEFAULT NULL,
    usage_count BIGINT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY idx_key_hash (key_hash),
    INDEX idx_is_active (is_active),
    INDEX idx_created_date (created_date)
);
```

**Security:** Keys are hashed with SHA-256 before storage. Full keys are never stored in plaintext.

---

## Caching System

### Cache Storage

All booking data is stored with **AES-256-CBC encryption** using WordPress authentication salts as the encryption key.

**Encryption Process:**

```php
// Encryption
$key = wp_salt('auth');  // WordPress auth salt
$iv = openssl_random_pseudo_bytes(16);  // Random IV
$encrypted = openssl_encrypt(
    serialize($booking_data),
    'AES-256-CBC',
    hash('sha256', $key),
    0,
    $iv
);
$stored = base64_encode($iv . $encrypted);  // IV + ciphertext
```

**Decryption Process:**

```php
// Decryption
$key = wp_salt('auth');
$data = base64_decode($stored);
$iv = substr($data, 0, 16);  // Extract IV
$encrypted = substr($data, 16);  // Extract ciphertext
$decrypted = openssl_decrypt(
    $encrypted,
    'AES-256-CBC',
    hash('sha256', $key),
    0,
    $iv
);
$booking_data = unserialize($decrypted);
```

**Why Encryption?**

- Protects personally identifiable information (PII)
- Prevents SQL injection from reading sensitive data
- Complies with data protection regulations (GDPR, etc.)
- Adds security layer even if database is compromised

---

### Cache Types

#### Hot Cache

- **Definition**: Bookings with future departure dates
- **Purpose**: Active/upcoming bookings requiring fast access
- **Retention**: Configurable future window (default: 365 days)
- **Query Pattern**: Frequently accessed, high-performance requirements

#### Historical Cache

- **Definition**: Bookings with past departure dates
- **Purpose**: Historical data for reporting and lookback queries
- **Retention**: Configurable past window (default: 30 days)
- **Query Pattern**: Less frequent access, cleaned up after retention period

---

### Cache Hit Logic

The system uses intelligent cache decision-making:

**Cache Hit Scenarios:**

1. **Direct Match**: Booking(s) found in cache matching query parameters
2. **Empty Date Inference**: Query date within retention window + recent sync = no bookings exist

**Cache Miss Scenarios:**

1. **No Data**: No bookings found and outside retention window
2. **Force Refresh**: `force_refresh=true` parameter
3. **Stale Data**: No recent sync (fallback to API)

**Empty Date Optimization:**

```php
if (no_cached_bookings && is_date_in_retention() && has_synced_recently()) {
    // Don't call API - we know there are no bookings
    return array('success' => true, 'data' => array());
}
```

This prevents unnecessary API calls for empty date ranges.

---

### List Type Queries

Different query strategies based on `list_type` parameter:

#### `staying` (Default)

- **Date Field**: arrival_date / departure_date
- **Logic**: Bookings overlapping query date range
- **Status Filter**: Excludes cancelled, no_show, quote, waitlist, owner_occupied
- **SQL**: `arrival_date <= period_to AND departure_date > period_from`

#### `placed`

- **Date Field**: booking_placed_date
- **Logic**: Bookings created within query date range
- **Status Filter**: None
- **SQL**: `booking_placed_date >= period_from AND booking_placed_date <= period_to`

#### `cancelled`

- **Date Field**: booking_cancelled_date
- **Logic**: Bookings cancelled within query date range
- **Status Filter**: None
- **SQL**: `booking_cancelled_date >= period_from AND booking_cancelled_date <= period_to`

#### `all`

- **Date Field**: arrival_date / departure_date
- **Logic**: All bookings overlapping query date range
- **Status Filter**: None
- **SQL**: `arrival_date <= period_to AND departure_date > period_from`

---

## Sync Strategies

### 1. Full Refresh (Daily at 3 AM)

**Purpose:** Complete cache rebuild to ensure data consistency.

**Process:**

```
1. Fetch future bookings in 30-day chunks
   - Start: Today
   - End: Today + retention_future (default 365 days)
   - Chunk size: 30 days
   - API calls: ~12 for full year

2. Fetch cancelled bookings
   - Start: Today - retention_cancelled (default 30 days)
   - End: Today + retention_future
   - Single API call

3. Fetch past bookings
   - Start: Today - retention_past (default 30 days)
   - End: Today
   - Single API call

4. Store all bookings in cache
   - Encrypts each booking
   - Uses REPLACE to update existing or insert new

5. Update last refresh timestamp
```

**Configuration Options:**

- `newbook_cache_retention_past`: Days to look back (default: 30)
- `newbook_cache_retention_future`: Days to look ahead (default: 365)
- `newbook_cache_retention_cancelled`: Days to keep cancellations (default: 30)
- `newbook_cache_enable_daily_refresh`: Enable/disable (default: true)

**Performance:**

- Rate limiting: 100ms pause between chunks
- Typical execution: 2-5 seconds for 1000 bookings
- Memory efficient: Processes bookings individually

**Scheduled Via:** `wp_schedule_event('daily')` at 3:00 AM

---

### 2. Incremental Sync (Every 20 Seconds)

**Purpose:** Keep cache fresh with minimal API overhead.

**Process:**

```
1. Get last sync timestamp
   - Stored in: newbook_cache_last_incremental_sync

2. Query NewBook for changes since last sync
   - Action: bookings_list
   - list_type: 'all'
   - period_from: last_sync_time
   - period_to: current_time

3. Process changed bookings
   - For each booking:
     - Check if exists in cache
     - Update if exists (increment update counter)
     - Insert if new (increment add counter)

4. Update last sync timestamp
   - Store current time for next sync

5. Log results
   - Only log if changes detected (reduces log noise)
```

**Configuration Options:**

- `newbook_cache_sync_interval`: Seconds between syncs (default: 20)
- `newbook_cache_enable_incremental_sync`: Enable/disable (default: true)

**Performance:**

- Minimal overhead: Most syncs return 0 changes
- Efficient: Only processes modified bookings
- Low latency: Changes appear in cache within 20 seconds

**Scheduled Via:** Custom cron interval `newbook_cache_incremental_sync`

**Edge Cases:**

- **No credentials**: Silent skip with hourly warning log
- **API timeout**: Continues with next scheduled sync
- **Zero changes**: DEBUG level log only (reduces noise)

---

### 3. Cleanup (Daily at 4 AM)

**Purpose:** Remove old bookings to control database size.

**Process:**

```
1. Delete old checked-out bookings
   - Status: 'checked_out', 'no_show'
   - Criteria: departure_date < (today - retention_past days)

2. Delete old cancelled bookings
   - Status: 'cancelled'
   - Criteria: last_updated < (today - retention_cancelled days)

3. Log deleted counts
   - Example: "Cleanup complete: 45 old bookings, 12 old cancellations removed"

4. Update last cleanup timestamp
```

**Configuration Options:**

- `newbook_cache_retention_past`: Days to keep checked-out bookings (default: 30)
- `newbook_cache_retention_cancelled`: Days to keep cancellations (default: 30)

**Scheduled Via:** `wp_schedule_event('daily')` at 4:00 AM

---

### Sync Monitoring

**Option Values Tracking:**

- `newbook_cache_last_full_refresh`: Timestamp or "Never"
- `newbook_cache_last_incremental_sync`: Timestamp or "Never"
- `newbook_cache_last_cleanup`: Timestamp or "Never"

**Admin Interface:**

- Displays last sync times
- Manual trigger buttons for each sync type
- Real-time sync status

---

## Cache Invalidation

### Automatic Invalidation

**On Booking Update:**

When a booking is modified in NewBook:

1. Incremental sync detects change (within 20 seconds)
2. Existing cache entry is replaced with fresh data
3. `last_updated` timestamp is updated

**On Sync:**

- Full refresh: Replaces all bookings within retention window
- Incremental: Updates only changed bookings
- Cleanup: Removes old bookings based on rules

---

### Manual Invalidation

**Clear Specific Booking:**

```php
global $newbook_api_cache;
$newbook_api_cache->clear_booking_cache(123456);
```

**Clear All Cache:**

```php
global $newbook_api_cache;
$newbook_api_cache->clear_all_cache();
```

**Force Refresh on Request:**

```php
$response = $cache->call_api(
    'bookings_list',
    $params,
    $force_refresh = true  // Bypasses cache
);
```

---

### Transient Cache (Sites List)

Sites/rooms are cached separately using WordPress transients:

- **Key**: `newbook_cache_sites`
- **Duration**: 24 hours (DAY_IN_SECONDS)
- **Reason**: Site inventory changes rarely
- **Invalidation**: `delete_transient('newbook_cache_sites')`

---

## Security Architecture

### Authentication

**Dual Authentication System:**

#### 1. WordPress Authentication

- Application Passwords (recommended for external systems)
- Cookie-based sessions (WordPress admin)
- HTTP Basic Auth with WordPress credentials

#### 2. Custom API Keys

- Generated with `NewBook_API_Key_Manager::generate_key()`
- Format: `nbcache_{40_random_hex_chars}`
- Storage: SHA-256 hashed, never plaintext
- Usage: Bearer token in Authorization header

**Authentication Flow:**

```
1. Check WordPress authentication first
   - is_user_logged_in()
   - HTTP Basic Auth credentials
   - Return username if valid

2. If WordPress auth fails, check API key
   - Extract Bearer token from header
   - Hash provided key with SHA-256
   - Look up hash in database
   - Check is_active flag

3. Track usage
   - Increment usage_count
   - Update last_used timestamp

4. Allow or deny request
```

---

### Data Protection

**Encryption:**

- **Algorithm**: AES-256-CBC
- **Key Source**: WordPress authentication salt (`wp_salt('auth')`)
- **IV**: Randomly generated per booking (16 bytes)
- **Storage**: Base64(IV + ciphertext)

**Benefits:**

- PII protection at rest
- Defense in depth (even if DB compromised)
- Compliance with data protection laws

**Access Control:**

- REST endpoints require authentication
- API keys can be revoked instantly
- WordPress role-based access (admin settings)

---

### Input Sanitization

**SQL Injection Prevention:**

- All queries use `$wpdb->prepare()`
- Parameterized queries throughout
- No raw SQL with user input

**Example:**

```php
$wpdb->get_var($wpdb->prepare(
    "SELECT encrypted_data FROM {$table} WHERE booking_id = %d",
    $booking_id
));
```

**XSS Prevention:**

- Output escaping in admin pages
- `sanitize_text_field()` on all user inputs
- WordPress nonces for form submissions

---

### Logging Security

**PII Protection in Logs:**

- API keys never logged in full (truncated to prefix)
- User passwords never logged
- Sensitive booking data not logged in plaintext
- Context arrays filtered to safe parameters

**Example:**

```php
private function sanitize_params($data) {
    $safe_keys = array('booking_id', 'period_from', 'period_to', 'list_type');
    // Only log safe parameters, exclude sensitive data
}
```

---

## Integration Points

### 1. booking-match-api Plugin

**Integration Type:** WordPress Filter Hook

**Hook:** `bma_newbook_api_call`

**Implementation:**

```php
add_filter('bma_newbook_api_call', array($this, 'intercept_api_call'), 10, 4);
```

**Parameters:**

- `$response`: Previous filter response (or null)
- `$action`: NewBook API action
- `$data`: Request parameters
- `$context_info`: Request context (user, IP, caller, etc.)

**Flow:**

```
booking-match-api                  newbook-api-cache
      │                                   │
      ├──► apply_filters()                │
      │    'bma_newbook_api_call'         │
      │                                   │
      │                             ◄─────┤
      │                          intercept_api_call()
      │                                   │
      │                             Check cache
      │                                   │
      │                             Return data
      ◄───────────────────────────────────┤
```

**Dependency Check:**

```php
if (!class_exists('BMA_NewBook_Search')) {
    // Show admin notice: booking-match-api required
    return;
}
```

---

### 2. REST API Endpoints

**Base Namespace:** `newbook-cache/v1`

**Endpoints:**

| Method | Endpoint | Purpose | Cached |
|--------|----------|---------|--------|
| POST | `/bookings/list` | Fetch bookings by date range | Yes |
| POST | `/bookings/get` | Fetch single booking | Yes |
| POST | `/sites/list` | Fetch all sites/rooms | Yes (24h) |
| GET | `/cache/stats` | Cache statistics | No |

**Authentication:**

- WordPress Application Passwords
- Custom API Keys (Bearer tokens)

**Example Integration (External System):**

```javascript
// Using API key
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
```

---

### 3. WordPress Cron System

**Registered Hooks:**

- `newbook_cache_full_refresh` - Daily at 3:00 AM
- `newbook_cache_incremental_sync` - Every 20 seconds (custom interval)
- `newbook_cache_cleanup` - Daily at 4:00 AM

**Custom Interval Registration:**

```php
add_filter('cron_schedules', function($schedules) {
    $interval = get_option('newbook_cache_sync_interval', 20);
    $schedules['newbook_cache_incremental_sync'] = array(
        'interval' => $interval,
        'display' => "Every {$interval} Seconds"
    );
    return $schedules;
});
```

**Monitoring:**

- Check next scheduled time: `wp_next_scheduled('hook_name')`
- View cron jobs: WP-CLI `wp cron event list`
- Manual trigger from admin settings page

---

### 4. NewBook API

**API Base URL:** `https://api.newbook.cloud/rest/`

**Supported Actions:**

- `bookings_list` - Fetch bookings by date range
- `bookings_get` - Fetch single booking by ID
- `sites_list` - Fetch all sites/rooms

**Authentication:**

- Method: HTTP Basic Auth
- Credentials: NewBook username + password
- API Key: Sent in request body

**Request Format:**

```php
POST https://api.newbook.cloud/rest/bookings_list
Authorization: Basic base64(username:password)
Content-Type: application/json

{
    "api_key": "newbook_api_key",
    "region": "au",
    "period_from": "2025-11-01 00:00:00",
    "period_to": "2025-11-30 23:59:59",
    "list_type": "staying"
}
```

---

## Performance Considerations

### Query Optimization

**Indexed Queries:**

All cache queries use indexed fields:

```sql
-- Fast date range query (uses idx_dates)
SELECT * FROM wp_newbook_cache
WHERE arrival_date <= '2025-11-30'
  AND departure_date > '2025-11-01';

-- Fast status filter (uses idx_status)
SELECT * FROM wp_newbook_cache
WHERE booking_status = 'confirmed';

-- Fast placed/cancelled queries (uses idx_placed/idx_cancelled)
SELECT * FROM wp_newbook_cache
WHERE booking_placed_date BETWEEN '2025-11-01' AND '2025-11-30';
```

**Query Performance:**

- Indexed date range queries: <10ms for 10,000 bookings
- Single booking lookup: <1ms
- Cache stats aggregation: <50ms

---

### Memory Management

**Encryption Overhead:**

- Per booking: ~2KB encrypted data
- 1000 bookings: ~2MB RAM during encryption
- Bookings processed individually (not batch)

**Logger Cleanup:**

- Automatic cleanup every 100 log writes (1% probability)
- Maintains max 1000 entries (configurable)
- Prevents unbounded log table growth

**PHP Memory:**

- Typical request: <5MB
- Full refresh: <20MB
- Incremental sync: <2MB

---

### API Rate Limiting

**Full Refresh:**

- 100ms pause between 30-day chunks
- ~12 API calls for 365-day retention
- Total time: ~2-5 seconds

**Incremental Sync:**

- Single API call every 20 seconds
- Most calls return 0 results (minimal overhead)
- Typical response time: <500ms

**Best Practices:**

- Use cache whenever possible
- Only use `force_refresh` when necessary
- Rely on incremental sync for real-time updates

---

### Database Size Management

**Growth Rate:**

- ~2KB per booking (encrypted)
- 1000 bookings ≈ 2MB
- 10,000 bookings ≈ 20MB

**Automatic Cleanup:**

- Daily removal of old bookings
- Configurable retention periods
- Prevents unbounded growth

**Monitoring:**

```php
$stats = $cache->get_cache_stats();
echo "Database size: " . $stats['database_size_mb'] . " MB";
```

---

### Caching Benefits

**Performance Improvements:**

| Operation | Without Cache | With Cache | Speedup |
|-----------|---------------|------------|---------|
| Get single booking | 200-500ms | 1-5ms | 40-500x |
| List 100 bookings | 300-800ms | 10-30ms | 30-80x |
| Dashboard load | 2-5 seconds | 50-200ms | 10-100x |

**API Call Reduction:**

- Typical site: 95%+ cache hit rate
- Dashboard load: 10+ API calls → 0-1 API calls
- Real-time updates: Incremental sync maintains freshness

---

## Troubleshooting

### Common Issues

**Cache Not Working:**

1. Check if caching enabled: Settings → NewBook Cache → Enable caching
2. Verify credentials: Test connection button in settings
3. Check sync status: View last sync timestamps
4. Review logs: Filter by ERROR level

**Stale Data:**

1. Check incremental sync: Should run every 20 seconds
2. Review last sync time: Should be recent
3. Force refresh: Use admin button or `force_refresh=true`
4. Check cron jobs: Ensure WordPress cron running

**Performance Issues:**

1. Check database size: May need cleanup
2. Review log level: DEBUG creates many entries
3. Monitor cache hit rate: Should be >80%
4. Check API response times: NewBook API slowness

---

### Debug Mode

**Enable WP_DEBUG:**

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Logs will appear in `/wp-content/debug.log`

**Set Log Level to DEBUG:**

Settings → NewBook Cache → Log Level → DEBUG

**View Logs:**

Settings → NewBook Cache → View Logs

Filter by level, search messages, paginate results.

---

## Future Enhancements

**Potential Improvements:**

1. **Redis/Memcached Support**: Alternative to database storage
2. **Webhook Integration**: Real-time updates from NewBook
3. **Multi-site Support**: Shared cache across WordPress multisite
4. **Advanced Analytics**: Cache performance dashboards
5. **GraphQL API**: Modern API alternative to REST
6. **CDN Integration**: Distribute cache globally
