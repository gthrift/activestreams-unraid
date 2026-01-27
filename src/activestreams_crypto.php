<?php
/**
 * Active Streams - Encryption Functions
 * Shared encryption/decryption functions for API token security
 */

/**
 * Get or create encryption key for token storage
 */
function getEncryptionKey() {
    $encryption_key_file = "/boot/config/plugins/activestreams/.encryption_key";

    // If key file exists, load it
    if (file_exists($encryption_key_file)) {
        $key = file_get_contents($encryption_key_file);
        if ($key && strlen($key) === 32) {
            return $key;
        }
    }

    // Generate new key
    $key = random_bytes(32);

    // Save key with restricted permissions
    file_put_contents($encryption_key_file, $key);
    chmod($encryption_key_file, 0600);

    return $key;
}

/**
 * Encrypt API token before storage
 */
function encryptToken($token) {
    if (empty($token)) {
        return '';
    }

    $key = getEncryptionKey();
    $iv = random_bytes(16);

    $encrypted = openssl_encrypt($token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    // Prepend IV to encrypted data and encode
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt API token after loading
 */
function decryptToken($encryptedToken) {
    if (empty($encryptedToken)) {
        return '';
    }

    // Check if token is already plaintext (for migration)
    $decoded = base64_decode($encryptedToken, true);
    if ($decoded === false || strlen($decoded) < 17) {
        // Likely plaintext token, return as-is for migration
        return $encryptedToken;
    }

    $key = getEncryptionKey();
    $data = base64_decode($encryptedToken);

    // Extract IV (first 16 bytes)
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);

    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    return $decrypted !== false ? $decrypted : '';
}
?>
