# Code Review: Active Streams Unraid Plugin

**Date:** 2026-01-21
**Reviewer:** Claude Code
**Branch:** claude/code-review-improvements-Ld3so

---

## Executive Summary

The Active Streams Unraid plugin is a well-structured, functional media stream monitoring tool with clean code organization and good UX. However, there are **critical security vulnerabilities** that should be addressed immediately, particularly around SSL certificate verification and credential storage.

**Overall Assessment:**
- **Code Quality:** Good (7/10)
- **Security:** Poor (4/10) - Critical issues present
- **Maintainability:** Good (7/10)
- **Performance:** Good (8/10) - Parallel curl_multi implementation

---

## Critical Security Issues

### 🔴 CRITICAL #1: SSL Certificate Verification Disabled

**Location:** `src/activestreams_api.php:98-99`, `src/activestreams_servers.php:132-133`

```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
```

**Risk:** Makes all HTTPS connections vulnerable to Man-in-the-Middle (MITM) attacks. API tokens transmitted over SSL are not actually secure.

**Impact:** HIGH - Attackers on the network can intercept credentials

**Recommendation:**
```php
// Enable SSL verification by default
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// Add optional override for self-signed certificates
if (isset($server['allow_self_signed']) && $server['allow_self_signed']) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}
```

Add a checkbox in the UI: "Allow self-signed certificates (insecure)"

---

### 🔴 CRITICAL #2: API Tokens Stored in Plaintext

**Location:** `src/activestreams_servers.php:31`, `/boot/config/plugins/activestreams/servers.json`

**Risk:** API tokens are stored unencrypted in JSON file. Anyone with file system access can read all media server credentials.

**Impact:** HIGH - Credential exposure

**Recommendation:**
```php
// Simple obfuscation (better than nothing)
function encryptToken($token) {
    return base64_encode($token);
}

function decryptToken($encrypted) {
    return base64_decode($encrypted);
}

// Before saving
$serverData['token'] = encryptToken($_POST['token']);

// After loading
foreach ($servers as &$server) {
    $server['token'] = decryptToken($server['token']);
}
```

**Better approach:** Use PHP openssl_encrypt() with a key derived from system UUID

---

### 🔴 CRITICAL #3: No CSRF Protection

**Location:** `src/activestreams_servers.php:190`

```php
$action = $_POST['action'] ?? '';
```

**Risk:** POST endpoints can be exploited via CSRF attacks to add/modify/delete servers

**Impact:** MEDIUM-HIGH - Unauthorized configuration changes

**Recommendation:**
```php
// Add CSRF token generation/validation
session_start();

function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// In API handler
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
```

---

### 🟡 MEDIUM #4: File Permissions Not Set

**Location:** Plugin manifest, `activestreams.plg`

**Risk:** Configuration files created without explicit permission restrictions may be world-readable

**Recommendation:**
```bash
# After creating servers.json
chmod 600 /boot/config/plugins/activestreams/servers.json
chmod 600 /boot/config/plugins/activestreams/activestreams.cfg
```

---

## Code Quality Issues

### Input Validation

**Issue #1: IPv6 Support Missing**
Location: `src/activestreams_servers.php:42-56`

```php
function validateHost($host) {
    // Current: Only validates IPv4
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return true;
    }

    // IMPROVEMENT: Add IPv6 support
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return true;
    }

    // Better: Combine flags
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
        return true;
    }
}
```

**Issue #2: Type Inconsistency**
Location: `src/activestreams_servers.php:198, 200`

```php
// Current: Port is string from POST
'port' => $_POST['port'] ?? '',
'ssl' => $_POST['ssl'] ?? '0'

// IMPROVEMENT: Type cast immediately
'port' => (int)($_POST['port'] ?? 0),
'ssl' => (bool)($_POST['ssl'] ?? false)
```

---

### Error Handling

**Issue #3: Insufficient Null Checks**
Location: `src/activestreams_api.php:139`

```php
// Current: Assumes MediaContainer exists
foreach ($data['MediaContainer']['Metadata'] as $session) {

// IMPROVEMENT: Add null coalescing
foreach ($data['MediaContainer']['Metadata'] ?? [] as $session) {
```

**Issue #4: JSON Parse Error No Recovery**
Location: `src/activestreams_api.php:31-40`

```php
// Current: Exit on error, no recovery
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Active Streams: JSON decode error in servers.json - " . json_last_error_msg());
    exit;
}

// IMPROVEMENT: Attempt recovery
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Active Streams: JSON decode error, attempting backup restore");

    // Try to restore from backup
    if (file_exists($servers_file . '.backup')) {
        $servers = json_decode(file_get_contents($servers_file . '.backup'), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Restore successful
            copy($servers_file . '.backup', $servers_file);
        }
    }
}
```

---

## Code Structure Improvements

### Recommendation #1: Extract Configuration Defaults

**Current:** Magic values scattered across files

```php
// Create src/config.php
<?php
const DEFAULT_PORTS = [
    'plex' => 32400,
    'emby' => 8096,
    'jellyfin' => 8096
];

const CURL_TIMEOUT = 10;
const CURL_CONNECT_TIMEOUT = 5;

const SERVER_TYPES = ['plex', 'emby', 'jellyfin'];
```

### Recommendation #2: DRY Principle - Shared CURL Options

**Current:** CURL options duplicated in two files

```php
// Create function in shared utility file
function getDefaultCurlOptions() {
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ];
}

// Usage
$ch = curl_init();
curl_setopt_array($ch, getDefaultCurlOptions());
```

### Recommendation #3: Add Type Hints (PHP 7.0+)

```php
// Current
function formatTime($seconds) {

// IMPROVEMENT
function formatTime(int $seconds): string {
```

### Recommendation #4: Extract Magic Strings

```php
// Current: Hardcoded paths
$servers_file = "/boot/config/plugins/activestreams/servers.json";

// IMPROVEMENT: Define constants
define('CONFIG_PATH', '/boot/config/plugins/activestreams');
define('SERVERS_FILE', CONFIG_PATH . '/servers.json');
define('CONFIG_FILE', CONFIG_PATH . '/activestreams.cfg');
```

---

## Performance Optimizations

### Recommendation #1: Add Response Caching

```php
// Cache API responses for 2-3 seconds to reduce load during rapid widget refreshes
function getCachedResponse($cacheKey, $ttl = 3) {
    $cacheFile = sys_get_temp_dir() . '/activestreams_' . md5($cacheKey) . '.cache';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return file_get_contents($cacheFile);
    }

    return null;
}
```

### Recommendation #2: Timeout Improvements

```php
// Add timeout handling to prevent hung connections
curl_setopt($ch, CURLOPT_TIMEOUT_MS, 8000); // 8 seconds max
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 3000); // 3 seconds to connect
```

---

## Testing Recommendations

### Unit Tests Needed

1. **`formatTime()` function tests**
   - Test edge cases: 0, negative, large values
   - Verify HH:MM:SS format for hours > 0
   - Verify MM:SS format for hours = 0

2. **`validateHost()` function tests**
   - Test IPv4, IPv6, localhost, domains
   - Test malformed inputs
   - Test SQL injection attempts

3. **`parsePlexResponse()` function tests**
   - Test with real Plex API response samples
   - Test with malformed JSON
   - Test with missing fields

4. **`parseEmbyJellyfinResponse()` function tests**
   - Test Emby vs Jellyfin differences
   - Test edge cases (no NowPlayingItem, etc.)

### Integration Tests

```php
// Test connection to mock servers
// Test CURL multi execution with failures
// Test JSON persistence roundtrip
```

---

## Documentation Improvements

### Add Inline Documentation

```php
/**
 * Parse Plex API response into standardized stream format
 *
 * @param array $server Server configuration containing name and type
 * @param string $response Raw JSON response from Plex API
 * @return array Associative array with 'streams' key containing parsed data
 * @throws None - Returns empty streams array on parse failure
 */
function parsePlexResponse($server, $response) {
```

### Add Architecture Documentation

Create `ARCHITECTURE.md` documenting:
- Plugin initialization flow
- API request/response cycle
- Data persistence strategy
- Widget refresh mechanism

---

## Browser Compatibility

### JavaScript Improvements Needed

**Location:** `src/ActiveStreamsSettings.page`

```javascript
// Current: Uses modern JS without polyfills
const ports = { plex: 32400, emby: 8096, jellyfin: 8096 };

// Consider: IE11 compatibility if Unraid supports older browsers
// Add transpilation or use var instead of const/let
```

---

## Security Best Practices Checklist

- [ ] Enable SSL certificate verification with optional bypass
- [ ] Encrypt API tokens at rest
- [ ] Implement CSRF protection
- [ ] Set restrictive file permissions (600) on config files
- [ ] Add rate limiting to prevent API abuse
- [ ] Sanitize error messages (don't expose paths/internal details)
- [ ] Add input length limits to prevent buffer issues
- [ ] Validate Content-Type headers on API responses
- [ ] Add audit logging for configuration changes
- [ ] Implement session timeout for settings page

---

## Code Metrics

| File | Lines | Functions | Complexity | Maintainability |
|------|-------|-----------|------------|-----------------|
| activestreams_api.php | 411 | 5 | Medium | Good |
| activestreams_servers.php | 285 | 8 | Low | Good |
| ActiveStreamsDashboard.page | 196 | - | Low | Good |
| ActiveStreamsSettings.page | 490 | 15+ (JS) | Medium | Fair |

---

## Priority Action Items

### Immediate (Security Fixes)
1. ✅ Enable SSL certificate verification
2. ✅ Implement token encryption
3. ✅ Add CSRF protection
4. ✅ Set proper file permissions

### Short-term (Quality)
5. ✅ Add PHP type hints
6. ✅ Extract configuration constants
7. ✅ Add comprehensive error handling
8. ✅ Implement response caching

### Long-term (Enhancements)
9. ⬜ Create unit test suite
10. ⬜ Add OOP refactoring
11. ⬜ Implement circuit breaker pattern
12. ⬜ Add historical stream tracking

---

## Positive Highlights

**What the code does well:**

✓ **Clean separation of concerns** - API, management, UI properly separated
✓ **Good use of curl_multi** - Efficient parallel requests
✓ **XSS prevention** - Uses `htmlspecialchars()` on user data
✓ **Input validation** - Validates server type, host, port, token
✓ **Error logging** - Appropriate use of error_log()
✓ **Responsive design** - Mobile-friendly widget
✓ **CI/CD automation** - GitHub Actions for builds
✓ **Clear documentation** - Good README with setup instructions

---

## Conclusion

The Active Streams plugin is a well-designed, functional tool with good code organization and UX. The primary concerns are security-related and can be addressed with focused improvements to SSL verification, credential storage, and CSRF protection.

**Recommendation:** Implement the Critical security fixes immediately before next release. The code quality improvements can be phased in over subsequent updates.

**Estimated Effort:**
- Critical fixes: 4-6 hours
- Quality improvements: 8-12 hours
- Testing infrastructure: 16-24 hours

---

## References

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)
- [CSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)
