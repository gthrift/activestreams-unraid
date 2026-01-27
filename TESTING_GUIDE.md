# Testing Guide for Active Streams Plugin

This guide explains how to test plugin changes without affecting production users.

---

## Testing Workflow

### Method 1: Using activestreams_test.plg (Recommended)

This method installs directly from your local `src/` directory for rapid iteration.

#### Setup

1. **Clone the repo to your Unraid USB drive:**
   ```bash
   cd /boot/config/plugins/activestreams
   git clone https://github.com/gthrift/activestreams-unraid.git .
   # OR if already cloned:
   git pull origin claude/code-review-improvements-Ld3so
   ```

2. **Update the srcPATH in activestreams_test.plg:**

   Edit line 9 in `activestreams_test.plg`:
   ```xml
   <!ENTITY srcPATH   "/boot/config/plugins/activestreams/src">
   ```

   Or if you cloned elsewhere:
   ```xml
   <!ENTITY srcPATH   "/mnt/user/development/activestreams-unraid/src">
   ```

3. **Install the test plugin:**
   ```bash
   # Option A: Via Unraid UI
   Plugins → Install Plugin → paste path to activestreams_test.plg

   # Option B: Via command line
   plugin install /boot/config/plugins/activestreams/activestreams_test.plg
   ```

#### Testing Cycle

**Fast iteration loop:**

1. Make changes to files in `src/`
2. Reinstall test plugin:
   ```bash
   plugin install /boot/config/plugins/activestreams/activestreams_test.plg
   ```
3. Test changes in UI
4. Repeat

**No package building required!**

---

### Method 2: Manual File Copy (Fastest)

For quick testing of individual files:

```bash
# After editing a file in src/
cp /boot/config/plugins/activestreams/src/activestreams_servers.php \
   /usr/local/emhttp/plugins/activestreams/

# Refresh browser - changes take effect immediately
```

**Best for:** Single file changes, rapid debugging

---

### Method 3: Build Test Package

For testing the full package workflow:

#### Build the package

```bash
cd /path/to/activestreams-unraid

# Create package directory
mkdir -p package/usr/local/emhttp/plugins/activestreams

# Copy source files
cp src/*.php package/usr/local/emhttp/plugins/activestreams/
cp src/*.page package/usr/local/emhttp/plugins/activestreams/

# Create tarball
cd package
tar -cJf activestreams-2026.01.08-TEST.txz usr/
cd ..
mv package/activestreams-2026.01.08-TEST.txz ./

# Calculate MD5
md5sum activestreams-2026.01.08-TEST.txz
```

#### Upload to test location

```bash
# Upload to GitHub releases or your own server
# Update activestreams_test.plg to point to this URL
```

**Best for:** Final testing before production release

---

## Verification Checklist

### After Installing Test Version

- [ ] **Check version**
  ```bash
  # Should show "2026.01.08-TEST"
  plugin version activestreams
  ```

- [ ] **Verify files are from src/**
  ```bash
  ls -lh /usr/local/emhttp/plugins/activestreams/
  # Check timestamps match your recent edits
  ```

- [ ] **Check logs for errors**
  ```bash
  tail -f /var/log/syslog | grep -i "active streams"
  ```

### Test All Three Security Fixes

#### 1. Token Encryption
```bash
# View raw config (tokens should be encrypted)
cat /boot/config/plugins/activestreams/servers.json

# Expected: Base64 strings, not plaintext tokens
```

#### 2. CSRF Protection
```javascript
// Open browser console on settings page
// Try invalid CSRF token:
$.post('/plugins/activestreams/activestreams_servers.php', {
    action: 'test',
    type: 'plex',
    host: '192.168.1.1',
    port: 32400,
    token: 'test',
    csrf_token: 'INVALID'
}).fail(function(xhr) {
    console.log(xhr.status); // Should be 403
});
```

#### 3. SSL Certificate Verification
- [ ] Add server with HTTPS and valid cert → Should work
- [ ] Add server with HTTPS and self-signed cert (unchecked) → Should fail with SSL error
- [ ] Add server with HTTPS and self-signed cert (checked) → Should work

---

## Common Testing Scenarios

### Test 1: Fresh Installation

1. Uninstall production version (if installed)
2. Install test version
3. Configure a server
4. Verify dashboard shows streams

**Expected:** Everything works, tokens encrypted immediately

---

### Test 2: Upgrade from Previous Version

1. Keep production version installed
2. Note existing servers.json content (plaintext tokens)
3. Uninstall production
4. Install test version
5. Check dashboard (should still work with plaintext)
6. Edit any server
7. Check servers.json (tokens now encrypted)

**Expected:** Seamless migration, no data loss

---

### Test 3: CSRF Token Expiration

1. Open settings page
2. Wait for PHP session to expire (default ~2 hours)
   - Or manually delete session: `rm /tmp/sess_*`
3. Try to add/edit server
4. Observe error message

**Expected:** "Security token expired. Please refresh the page."

---

### Test 4: Self-Signed Certificate Flow

1. Add HTTPS server with self-signed cert
2. Leave "Allow self-signed" UNCHECKED
3. Test connection

**Expected:** Fails with "SSL certificate problem: self signed certificate"

4. CHECK "Allow self-signed certificates"
5. Test connection

**Expected:** Success

---

## Debugging

### Enable Verbose Logging

Add to top of `src/activestreams_servers.php`:
```php
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/activestreams_debug.log');
```

Then:
```bash
tail -f /tmp/activestreams_debug.log
```

### Check PHP Errors

```bash
tail -f /var/log/syslog | grep -i php
```

### View Browser Console

Open DevTools (F12) and check for JavaScript errors

### Inspect Network Requests

1. Open DevTools (F12) → Network tab
2. Filter by "activestreams"
3. Check request/response for each API call

---

## Cleanup After Testing

### Remove Test Plugin

```bash
# Via Unraid UI
Plugins → Active Streams → Remove

# Via command line
plugin remove activestreams
```

### Restore to Production

```bash
# Install production version
plugin install https://raw.githubusercontent.com/gthrift/activestreams-unraid/main/activestreams.plg
```

### Your config/servers are preserved!

The `/boot/config/plugins/activestreams/` directory is NOT deleted when uninstalling.

---

## Best Practices

### ✅ DO

- Test all three security features thoroughly
- Test with both plaintext and encrypted tokens
- Test migration path (install over old version)
- Test on a non-production Unraid server if possible
- Keep test plugin version different from production (e.g., "2026.01.08-TEST")
- Document any issues found

### ❌ DON'T

- Don't push test versions to production plugin URL
- Don't test on production server without backup
- Don't skip testing the migration path
- Don't modify `/usr/local/emhttp/plugins/activestreams/` directly (use test plugin)

---

## Troubleshooting

### "Source directory not found" error

**Problem:** activestreams_test.plg can't find src/ directory

**Solution:**
```bash
# Check where you cloned the repo
ls -la /boot/config/plugins/activestreams/src/

# Update line 9 in activestreams_test.plg:
<!ENTITY srcPATH   "/actual/path/to/src">
```

---

### Files aren't updating

**Problem:** Changes to src/ files don't appear after reinstalling

**Solution:**
```bash
# Clear browser cache
# Or hard refresh: Ctrl+Shift+R (Cmd+Shift+R on Mac)

# Verify file timestamps
ls -lh /usr/local/emhttp/plugins/activestreams/
# Should match your edit times

# Check if files were actually copied
cat /usr/local/emhttp/plugins/activestreams/activestreams_servers.php | head -20
# Look for your changes
```

---

### Plugin won't install

**Problem:** Installation fails with error

**Solutions:**

1. **Check XML syntax:**
   ```bash
   xmllint --noout activestreams_test.plg
   ```

2. **Check bash syntax:**
   Look for unescaped characters in `<INLINE>` sections

3. **Check paths:**
   Make sure all file paths exist

4. **View detailed error:**
   ```bash
   tail -f /var/log/syslog | grep -i plugin
   ```

---

### CSRF token not working

**Problem:** All POST requests return 403

**Solutions:**

1. **Check if session_start() is called:**
   ```bash
   grep -n "session_start" /usr/local/emhttp/plugins/activestreams/activestreams_servers.php
   ```

2. **Check PHP sessions are working:**
   ```bash
   ls -la /tmp/sess_*
   # Should see session files
   ```

3. **Check browser console:**
   Look for the CSRF token fetch request
   Should return: `{"csrf_token": "..."}`

---

## Performance Testing

### Load Test Dashboard

```bash
# Simulate rapid refreshes
for i in {1..100}; do
    curl -s "http://localhost/plugins/activestreams/activestreams_api.php" > /dev/null
    echo "Request $i"
done
```

**Monitor:** CPU usage, response time

---

## Release Checklist

Before pushing to production:

- [ ] All tests pass
- [ ] Migration from previous version works
- [ ] No errors in logs
- [ ] No browser console errors
- [ ] Documentation updated
- [ ] CHANGES section in .plg updated
- [ ] Version number incremented
- [ ] Package built and MD5 calculated
- [ ] Package uploaded to GitHub releases
- [ ] Production .plg file updated with new URL/MD5
- [ ] Test one more time with production .plg

---

## Questions?

If something isn't working as expected:

1. Check logs: `/var/log/syslog`
2. Check debug log: `/tmp/activestreams_debug.log` (if enabled)
3. Check browser console (F12)
4. Verify file paths in test plugin
5. Try manual file copy method to isolate the issue

---

**Happy Testing!**
