# Suggested Improvements Summary

This document provides a prioritized list of improvements for the Active Streams Unraid plugin based on the comprehensive code review.

## 🔴 Critical Security Fixes (Implement Immediately)

### 1. Enable SSL Certificate Verification

**Files affected:** `src/activestreams_api.php`, `src/activestreams_servers.php`

**Current code:**
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
```

**Improved code:**
```php
// Enable verification by default for security
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// Optional: Allow user override for self-signed certificates
if (!empty($server['allow_self_signed'])) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}
```

**UI change needed:** Add checkbox "Allow self-signed certificates" to server settings

---

### 2. Encrypt API Tokens

**File affected:** `src/activestreams_servers.php`

**Add encryption functions:**
```php
/**
 * Encrypt token before storage
 */
function encryptToken($token) {
    $key = hash('sha256', file_get_contents('/proc/sys/kernel/random/uuid'), true);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($token, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt token after loading
 */
function decryptToken($encrypted) {
    $key = hash('sha256', file_get_contents('/proc/sys/kernel/random/uuid'), true);
    $data = base64_decode($encrypted);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
}
```

**Update save/load:**
```php
// When saving (in add/edit actions)
$serverData['token'] = encryptToken($_POST['token']);

// When loading
function loadServers() {
    // ... existing code ...
    foreach ($servers as &$server) {
        if (isset($server['token'])) {
            $server['token'] = decryptToken($server['token']);
        }
    }
    return $servers;
}
```

---

### 3. Add CSRF Protection

**File affected:** `src/activestreams_servers.php`

**Add at the top of file:**
```php
session_start();

function getCsrfToken() {
    if (empty($_SESSION['activestreams_csrf'])) {
        $_SESSION['activestreams_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['activestreams_csrf'];
}

function validateCsrfToken($token) {
    return !empty($_SESSION['activestreams_csrf']) &&
           hash_equals($_SESSION['activestreams_csrf'], $token);
}
```

**Add validation before processing actions:**
```php
// Before switch ($action)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}
```

**Update settings page to include token:**
```javascript
// In ActiveStreamsSettings.page, add to all AJAX requests
data.csrf_token = '<?php echo getCsrfToken(); ?>';
```

---

### 4. Set Secure File Permissions

**File affected:** `activestreams.plg`

**Add after creating config files:**
```bash
# Set restrictive permissions on sensitive files
chmod 600 /boot/config/plugins/activestreams/servers.json
chmod 600 /boot/config/plugins/activestreams/activestreams.cfg
chmod 700 /boot/config/plugins/activestreams
```

---

## 🟡 High Priority Code Quality Improvements

### 5. Add IPv6 Support

**File:** `src/activestreams_servers.php:42-56`

```php
function validateHost($host) {
    // Support IPv4, IPv6, and hostnames
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return true;
    }
    if ($host === 'localhost') {
        return true;
    }
    return false;
}
```

---

### 6. Improve Type Safety

**File:** `src/activestreams_servers.php`

```php
// In add/edit actions, cast types immediately
$serverData = [
    'type' => $_POST['type'] ?? 'plex',
    'name' => trim($_POST['name'] ?? ''),
    'host' => trim($_POST['host'] ?? ''),
    'port' => (int)($_POST['port'] ?? 0),      // Cast to int
    'token' => $_POST['token'] ?? '',
    'ssl' => filter_var($_POST['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN)  // Cast to bool
];
```

---

### 7. Add Null-Safe Array Access

**File:** `src/activestreams_api.php:139`

```php
// Current
foreach ($data['MediaContainer']['Metadata'] as $session) {

// Improved
foreach ($data['MediaContainer']['Metadata'] ?? [] as $session) {
```

**Apply to all array accesses where structure isn't guaranteed**

---

### 8. Extract Configuration Constants

**Create new file:** `src/config.php`

```php
<?php
/**
 * Active Streams Configuration Constants
 */

// File paths
define('CONFIG_PATH', '/boot/config/plugins/activestreams');
define('SERVERS_FILE', CONFIG_PATH . '/servers.json');
define('CONFIG_FILE', CONFIG_PATH . '/activestreams.cfg');

// Server defaults
const DEFAULT_PORTS = [
    'plex' => 32400,
    'emby' => 8096,
    'jellyfin' => 8096
];

const VALID_SERVER_TYPES = ['plex', 'emby', 'jellyfin'];

// API timeouts
const CURL_TIMEOUT = 10;
const CURL_CONNECT_TIMEOUT = 5;

// Server type colors for UI
const SERVER_COLORS = [
    'plex' => '#e5a00d',
    'emby' => '#52b54b',
    'jellyfin' => '#00a4dc'
];
```

**Update all files to use constants:**
```php
require_once 'config.php';

// Then replace hardcoded values
$servers_file = SERVERS_FILE;  // Instead of "/boot/config/plugins/activestreams/servers.json"
```

---

### 9. Add PHP Type Hints

**File:** All PHP files

```php
// Before
function formatTime($seconds) {
    // ...
}

// After
function formatTime(int $seconds): string {
    // ...
}

// Before
function validateHost($host) {
    // ...
}

// After
function validateHost(string $host): bool {
    // ...
}

// Before
function loadServers() {
    // ...
}

// After
function loadServers(): array {
    // ...
}
```

---

### 10. Implement Better Error Recovery

**File:** `src/activestreams_api.php:31-40`

```php
$servers = json_decode(file_get_contents($servers_file), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Active Streams: JSON decode error - " . json_last_error_msg());

    // Attempt backup restoration
    $backup_file = $servers_file . '.backup';
    if (file_exists($backup_file)) {
        $servers = json_decode(file_get_contents($backup_file), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            error_log("Active Streams: Restored from backup");
            copy($backup_file, $servers_file);
        } else {
            // Both files corrupted, initialize empty
            $servers = [];
            error_log("Active Streams: Backup also corrupted, initializing empty");
        }
    } else {
        $servers = [];
    }
}

// Create backup on successful load
if (!empty($servers)) {
    copy($servers_file, $servers_file . '.backup');
}
```

---

## 🟢 Medium Priority Enhancements

### 11. Add Response Caching

**Create new file:** `src/cache.php`

```php
<?php
/**
 * Simple file-based caching for API responses
 */

function getCachedResponse(string $cacheKey, int $ttl = 3): ?string {
    $cacheFile = sys_get_temp_dir() . '/activestreams_' . md5($cacheKey);

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return file_get_contents($cacheFile);
    }

    return null;
}

function setCachedResponse(string $cacheKey, string $data): void {
    $cacheFile = sys_get_temp_dir() . '/activestreams_' . md5($cacheKey);
    file_put_contents($cacheFile, $data);
}
```

**Usage in API:**
```php
// Before fetching
$cacheKey = "server_{$server['name']}";
$cached = getCachedResponse($cacheKey, 2); // 2 second TTL

if ($cached !== null) {
    return json_decode($cached, true);
}

// After fetching
setCachedResponse($cacheKey, json_encode($result));
```

---

### 12. Extract Shared CURL Configuration

**File:** `src/curl_helpers.php`

```php
<?php
/**
 * Shared CURL configuration helpers
 */

function getDefaultCurlOptions(bool $enableSslVerify = true): array {
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => $enableSslVerify,
        CURLOPT_SSL_VERIFYHOST => $enableSslVerify ? 2 : 0
    ];
}

function initCurlHandle(string $url, array $headers = [], array $extraOptions = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt_array($ch, getDefaultCurlOptions());

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if (!empty($extraOptions)) {
        curl_setopt_array($ch, $extraOptions);
    }

    return $ch;
}
```

---

### 13. Add Comprehensive Logging

**Create:** `src/logger.php`

```php
<?php
/**
 * Logging utility with levels
 */

class ActiveStreamsLogger {
    const ERROR = 'ERROR';
    const WARNING = 'WARNING';
    const INFO = 'INFO';
    const DEBUG = 'DEBUG';

    public static function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';
        $logMessage = "[$timestamp] [$level] Active Streams: $message $contextStr";
        error_log($logMessage);
    }

    public static function error(string $message, array $context = []): void {
        self::log(self::ERROR, $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log(self::WARNING, $message, $context);
    }

    public static function info(string $message, array $context = []): void {
        self::log(self::INFO, $message, $context);
    }
}
```

**Usage:**
```php
// Instead of
error_log("Active Streams: Connection failed");

// Use
ActiveStreamsLogger::error('Connection failed', ['server' => $server['name']]);
```

---

### 14. Add Input Sanitization Layer

**File:** `src/activestreams_servers.php`

```php
function sanitizeServerInput(array $input): array {
    return [
        'type' => trim($input['type'] ?? ''),
        'name' => substr(trim($input['name'] ?? ''), 0, 100), // Max 100 chars
        'host' => trim($input['host'] ?? ''),
        'port' => filter_var($input['port'] ?? 0, FILTER_VALIDATE_INT),
        'token' => trim($input['token'] ?? ''),
        'ssl' => filter_var($input['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN)
    ];
}

// Usage
$serverData = sanitizeServerInput($_POST);
```

---

## 📋 Testing Recommendations

### Unit Tests to Create

```php
// tests/FormatTimeTest.php
class FormatTimeTest extends PHPUnit\Framework\TestCase {
    public function testFormatTimeWithHours() {
        $this->assertEquals('1:30:45', formatTime(5445));
    }

    public function testFormatTimeWithoutHours() {
        $this->assertEquals('5:30', formatTime(330));
    }

    public function testFormatTimeZero() {
        $this->assertEquals('0:00', formatTime(0));
    }

    public function testFormatTimeNegative() {
        $this->assertEquals('0:00', formatTime(-100));
    }
}
```

---

## Implementation Checklist

### Phase 1: Critical Security (Week 1)
- [ ] Enable SSL verification with optional override
- [ ] Implement token encryption
- [ ] Add CSRF protection
- [ ] Set secure file permissions
- [ ] Test all security changes

### Phase 2: Code Quality (Week 2)
- [ ] Add IPv6 support
- [ ] Improve type safety
- [ ] Add null-safe array access
- [ ] Extract configuration constants
- [ ] Add PHP type hints

### Phase 3: Robustness (Week 3)
- [ ] Implement error recovery
- [ ] Add response caching
- [ ] Extract shared CURL config
- [ ] Add comprehensive logging
- [ ] Add input sanitization

### Phase 4: Testing (Week 4)
- [ ] Create unit tests
- [ ] Add integration tests
- [ ] Set up CI/CD testing
- [ ] Create test documentation

---

## Estimated Impact

| Improvement | Security Impact | Code Quality | Performance | Effort |
|-------------|----------------|--------------|-------------|---------|
| SSL Verification | 🔴 Critical | ✓ | - | 2h |
| Token Encryption | 🔴 Critical | ✓ | - | 3h |
| CSRF Protection | 🔴 Critical | ✓ | - | 2h |
| File Permissions | 🔴 Critical | - | - | 1h |
| IPv6 Support | 🟡 Medium | ✓ | - | 1h |
| Type Safety | - | ✓✓ | - | 2h |
| Constants | - | ✓✓ | ✓ | 2h |
| Type Hints | - | ✓✓✓ | - | 3h |
| Error Recovery | 🟡 Medium | ✓ | - | 2h |
| Caching | - | ✓ | ✓✓✓ | 3h |
| Logging | - | ✓✓ | - | 2h |
| Testing | 🟡 Medium | ✓✓✓ | - | 16h |

**Total Estimated Effort:** ~40 hours

---

## Questions & Clarifications

Before implementing these changes, consider:

1. **Backward Compatibility:** Should we support existing unencrypted tokens?
2. **Migration Path:** How to handle existing installations upgrading to encrypted tokens?
3. **Performance:** What's acceptable API response caching duration?
4. **Testing:** Do we have test Plex/Emby/Jellyfin servers available?
5. **PHP Version:** What minimum PHP version should we target? (affects type hints)

---

## Resources

- [PHP Type Declarations](https://www.php.net/manual/en/language.types.declarations.php)
- [OWASP CSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)
- [PHP OpenSSL Functions](https://www.php.net/manual/en/ref.openssl.php)
- [CURL SSL Options](https://curl.se/docs/sslcerts.html)
