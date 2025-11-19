# NewBook API Cache Documentation

Welcome to the NewBook API Cache plugin documentation. This directory contains comprehensive guides for developers and system administrators.

---

## Documentation Overview

### 1. [API Reference](API_REFERENCE.md) (21 KB)

Complete class and method reference documentation.

**Contents:**
- **NewBook_Cache** - Core cache functionality with encryption and routing
- **NewBook_API_Client** - Direct NewBook API communication
- **NewBook_Cache_Sync** - Background synchronization system
- **NewBook_Cache_Logger** - Centralized logging with configurable levels
- **NewBook_API_Key_Manager** - API key generation and validation
- **NewBook_Cache_REST_Controller** - REST API endpoints with dual authentication

**Best for:**
- Understanding class architecture
- Method signatures and parameters
- Return types and response formats
- Integration examples

---

### 2. [Architecture](ARCHITECTURE.md) (29 KB)

System architecture and design documentation.

**Contents:**
- Overview and system components
- Database schema with field descriptions
- Caching system design and encryption
- Sync strategies (full refresh, incremental, cleanup)
- Cache invalidation logic
- Security architecture
- Integration points with booking-match-api
- Performance considerations

**Best for:**
- Understanding how the system works
- Database structure and indexes
- Performance optimization
- Troubleshooting issues
- Planning integrations

---

### 3. [Function Cheat Sheet](FUNCTION_CHEAT_SHEET.md) (22 KB)

Quick reference guide for common operations.

**Contents:**
- Cache operations (get, store, clear)
- API calls and authentication
- Sync operations (manual triggers)
- Logging functions
- API key management
- REST API examples (cURL, JavaScript)
- Statistics and monitoring
- Common use cases with code examples

**Best for:**
- Quick code lookups
- Copy-paste ready examples
- Common use case implementations
- REST API integration
- Day-to-day development

---

## Quick Start

### For Developers

1. **Start here**: [Function Cheat Sheet](FUNCTION_CHEAT_SHEET.md) for immediate code examples
2. **Deep dive**: [API Reference](API_REFERENCE.md) for detailed method documentation
3. **Understand internals**: [Architecture](ARCHITECTURE.md) for system design

### For System Administrators

1. **Start here**: [Architecture](ARCHITECTURE.md) - Overview and Performance sections
2. **Configuration**: [Function Cheat Sheet](FUNCTION_CHEAT_SHEET.md) - Configuration Options section
3. **Troubleshooting**: [Architecture](ARCHITECTURE.md) - Troubleshooting section

### For API Consumers

1. **Start here**: [Function Cheat Sheet](FUNCTION_CHEAT_SHEET.md) - REST API section
2. **Authentication**: [API Reference](API_REFERENCE.md) - NewBook_Cache_REST_Controller
3. **Integration**: [Architecture](ARCHITECTURE.md) - Integration Points section

---

## Common Tasks

### Get Bookings from Cache

```php
global $newbook_api_cache;
$response = $newbook_api_cache->call_api('bookings_list', array(
    'period_from' => '2025-11-01 00:00:00',
    'period_to' => '2025-11-30 23:59:59',
    'list_type' => 'staying'
));
```

**See:** [Function Cheat Sheet - Cache Operations](FUNCTION_CHEAT_SHEET.md#cache-operations)

---

### REST API Call

```bash
curl -X POST https://yoursite.com/wp-json/newbook-cache/v1/bookings/list \
  -H "Authorization: Bearer nbcache_abc123..." \
  -H "Content-Type: application/json" \
  -d '{"period_from":"2025-11-01","period_to":"2025-11-30"}'
```

**See:** [Function Cheat Sheet - REST API](FUNCTION_CHEAT_SHEET.md#rest-api)

---

### Generate API Key

```php
$key = NewBook_API_Key_Manager::generate_key('Mobile App');
echo "API Key (save this!): " . $key['key'];
```

**See:** [API Reference - NewBook_API_Key_Manager](API_REFERENCE.md#newbook_api_key_manager)

---

### Monitor Cache Performance

```php
global $newbook_api_cache;
$stats = $newbook_api_cache->get_cache_stats();
echo "Total bookings: {$stats['total_bookings']}\n";
echo "Database size: {$stats['database_size_mb']} MB\n";
```

**See:** [Function Cheat Sheet - Statistics](FUNCTION_CHEAT_SHEET.md#statistics)

---

## Key Concepts

### Caching Strategy

The plugin uses a **transparent proxy pattern** - it intercepts API calls from the booking-match-api plugin and serves cached data when available, falling back to the NewBook API when needed.

**Learn more:** [Architecture - Caching System](ARCHITECTURE.md#caching-system)

---

### Encryption

All booking data is encrypted with **AES-256-CBC** before storage, protecting personally identifiable information (PII) even if the database is compromised.

**Learn more:** [Architecture - Security Architecture](ARCHITECTURE.md#security-architecture)

---

### Sync Strategies

Three automated sync strategies keep the cache fresh:

1. **Full Refresh** (daily at 3 AM) - Complete rebuild
2. **Incremental Sync** (every 20 seconds) - Changed bookings only
3. **Cleanup** (daily at 4 AM) - Remove old data

**Learn more:** [Architecture - Sync Strategies](ARCHITECTURE.md#sync-strategies)

---

### Dual Authentication

REST endpoints support both:
- WordPress Application Passwords
- Custom API Keys

**Learn more:** [API Reference - NewBook_Cache_REST_Controller](API_REFERENCE.md#authenticate_request)

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_newbook_cache` | Encrypted booking cache |
| `wp_newbook_cache_logs` | System logs |
| `wp_newbook_cache_uncached_requests` | Monitoring uncached actions |
| `wp_newbook_cache_api_keys` | API key management |

**Learn more:** [Architecture - Database Schema](ARCHITECTURE.md#database-schema)

---

## REST API Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/bookings/list` | Fetch bookings by date range |
| POST | `/bookings/get` | Fetch single booking |
| POST | `/sites/list` | Fetch all sites/rooms |
| GET | `/cache/stats` | Cache statistics |

**Base URL:** `/wp-json/newbook-cache/v1`

**Learn more:** [API Reference - NewBook_Cache_REST_Controller](API_REFERENCE.md#newbook_cache_rest_controller)

---

## Configuration

### Essential Settings

```php
// Enable caching
update_option('newbook_cache_enabled', true);

// API credentials
update_option('newbook_cache_username', 'your_username');
update_option('newbook_cache_password', 'your_password');
update_option('newbook_cache_api_key', 'your_api_key');

// Sync interval (seconds)
update_option('newbook_cache_sync_interval', 20);

// Retention (days)
update_option('newbook_cache_retention_future', 365);
update_option('newbook_cache_retention_past', 30);
```

**Learn more:** [Function Cheat Sheet - Configuration Options](FUNCTION_CHEAT_SHEET.md#configuration-options)

---

## Performance

### Typical Performance Improvements

| Operation | Without Cache | With Cache | Speedup |
|-----------|---------------|------------|---------|
| Get single booking | 200-500ms | 1-5ms | 40-500x |
| List 100 bookings | 300-800ms | 10-30ms | 30-80x |
| Dashboard load | 2-5 seconds | 50-200ms | 10-100x |

**Learn more:** [Architecture - Performance Considerations](ARCHITECTURE.md#performance-considerations)

---

## Troubleshooting

### Cache Not Working

1. Check settings: Settings → NewBook Cache → Enable caching
2. Verify API credentials
3. Check sync status (last sync timestamps)
4. Review logs (filter by ERROR level)

**Learn more:** [Architecture - Troubleshooting](ARCHITECTURE.md#troubleshooting)

---

### Debug Mode

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Set log level to DEBUG in plugin settings.

**Learn more:** [Architecture - Debug Mode](ARCHITECTURE.md#debug-mode)

---

## Support

### Plugin Information

- **Version:** 1.0.0
- **Requires PHP:** 7.4+
- **Requires WordPress:** 5.8+
- **Dependency:** booking-match-api plugin

### Getting Help

1. Check documentation in this directory
2. Review plugin logs: Settings → NewBook Cache → View Logs
3. Enable debug mode for detailed troubleshooting
4. Check cache statistics for performance metrics

---

## Contributing

When modifying the plugin:

1. Review [Architecture](ARCHITECTURE.md) to understand system design
2. Follow existing code patterns documented in [API Reference](API_REFERENCE.md)
3. Use [Function Cheat Sheet](FUNCTION_CHEAT_SHEET.md) for common operations
4. Update documentation when adding new features

---

## Documentation Maintenance

These documentation files should be updated when:

- New classes or methods are added
- Database schema changes
- New REST endpoints are created
- Configuration options change
- Performance characteristics change

---

## File Sizes

- **API_REFERENCE.md**: 21 KB - Comprehensive class reference
- **ARCHITECTURE.md**: 29 KB - System design and architecture
- **FUNCTION_CHEAT_SHEET.md**: 22 KB - Quick reference guide
- **README.md**: This file - Documentation index

**Total:** 72 KB of comprehensive documentation

---

Last updated: 2025-11-19
