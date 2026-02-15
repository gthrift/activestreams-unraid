<?php
/**
 * Active Streams - Server Management API
 * Handles adding, editing, deleting, and testing media server connections
 */

header('Content-Type: application/json');

function validateRequestOrigin() {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }

    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    // Strip port from HTTP_HOST so comparison works on non-standard ports
    $serverHostOnly = parse_url('http://' . $serverHost, PHP_URL_HOST) ?: $serverHost;
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';

    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $origin = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
        if ($origin && ($origin === $serverHostOnly || $origin === $serverAddr)) {
            return true;
        }
    }

    if (isset($_SERVER['HTTP_REFERER'])) {
        $referer = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        if ($referer && ($referer === $serverHostOnly || $referer === $serverAddr)) {
            return true;
        }
    }

    // Fallback: if neither Origin nor Referer is present (privacy extensions, some
    // reverse proxies), allow XMLHttpRequest calls which are same-origin by default
    if (!isset($_SERVER['HTTP_ORIGIN']) && !isset($_SERVER['HTTP_REFERER'])) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateRequestOrigin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request origin']);
    exit;
}

$servers_file = "/boot/config/plugins/activestreams/servers.json";

function loadServers() {
    global $servers_file;
    if (!file_exists($servers_file)) {
        return [];
    }
    $data = json_decode(file_get_contents($servers_file), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Active Streams: JSON decode error in loadServers() - " . json_last_error_msg());
        return [];
    }

    return is_array($data) ? $data : [];
}

function saveServers($servers) {
    global $servers_file;
    $dir = dirname($servers_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $result = file_put_contents($servers_file, json_encode($servers, JSON_PRETTY_PRINT));

    if ($result === false) {
        error_log("Active Streams: Failed to write servers.json - check file permissions");
        return false;
    }

    return $result;
}

function validateHost($host) {

    if (filter_var($host, FILTER_VALIDATE_IP)) {
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

function validatePort($port) {
    return is_numeric($port) && $port >= 1 && $port <= 65535;
}

function validateServerType($type) {
    return in_array($type, ['plex', 'emby', 'jellyfin'], true);
}

function validateServerData($data) {
    $errors = [];

    if (!isset($data['type']) || !validateServerType($data['type'])) {
        $errors[] = 'Invalid server type';
    }

    if (!isset($data['name']) || trim($data['name']) === '') {
        $errors[] = 'Server name is required';
    } elseif (strlen($data['name']) > 100) {
        $errors[] = 'Server name too long (max 100 characters)';
    }

    if (!isset($data['host']) || trim($data['host']) === '') {
        $errors[] = 'Host is required';
    } elseif (!validateHost($data['host'])) {
        $errors[] = 'Invalid host IP/hostname';
    }

    if (!isset($data['port']) || !validatePort($data['port'])) {
        $errors[] = 'Invalid port number (must be 1-65535)';
    }

    if (!isset($data['token']) || trim($data['token']) === '') {
        $errors[] = 'API token/key is required';
    }

    return $errors;
}

function testConnection($type, $host, $port, $token, $ssl, $sslVerify = false) {
    $protocol = $ssl ? 'https' : 'http';
    $url = '';
    $headers = [];

    switch ($type) {
        case 'plex':
            $url = "$protocol://$host:$port/status/sessions?X-Plex-Token=$token";
            $headers[] = 'Accept: application/json';
            $headers[] = "X-Plex-Token: $token";
            break;

        case 'emby':
            $url = "$protocol://$host:$port/emby/System/Info?api_key=$token";
            break;

        case 'jellyfin':
            $url = "$protocol://$host:$port/System/Info";
            $headers[] = "X-Emby-Token: $token";
            break;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    if ($curl_errno !== 0) {
        $debugInfo = "Failed to connect to $protocol://$host:$port";
        if ($curl_error) {
            $debugInfo .= " - Error: $curl_error";
        }
        error_log("Test connection failed for $type server: $debugInfo");
        return [
            'success' => false,
            'error' => $debugInfo
        ];
    }

    if ($http_code === 200 || $http_code === 204) {
        $data = json_decode($response, true);
        $serverName = '';

        if ($type === 'plex' && isset($data['MediaContainer'])) {
            $serverName = 'Plex Server';
        } elseif (($type === 'emby' || $type === 'jellyfin') && isset($data['ServerName'])) {
            $serverName = $data['ServerName'];
        }

        return ['success' => true, 'message' => $serverName ? "Connected to: $serverName" : 'Connection successful'];
    }

    // Handle HTTP errors with more detail
    $errorMsg = "HTTP $http_code";
    if ($http_code === 401) {
        $errorMsg = "Authentication failed (HTTP 401) - Please verify your token/API key";
    } elseif ($http_code === 404) {
        $errorMsg = "Server endpoint not found (HTTP 404) - Check host and port";
    } elseif ($http_code === 0) {
        $errorMsg = "Connection failed - Server unreachable at $protocol://$host:$port";
    }

    error_log("Test connection failed for $type server at $protocol://$host:$port: $errorMsg");

    return [
        'success' => false,
        'error' => $errorMsg
    ];
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        $serverData = [
            'type' => $_POST['type'] ?? 'plex',
            'name' => trim($_POST['name'] ?? ''),
            'host' => trim($_POST['host'] ?? ''),
            'port' => $_POST['port'] ?? '',
            'token' => $_POST['token'] ?? '',
            'ssl' => $_POST['ssl'] ?? '0',
            'ssl_verify' => $_POST['ssl_verify'] ?? '0'
        ];

        $validationErrors = validateServerData($serverData);
        if (!empty($validationErrors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $validationErrors)]);
            break;
        }

        $servers = loadServers();
        $servers[] = $serverData;

        if (saveServers($servers)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save']);
        }
        break;
        
    case 'edit':
        $index = (int)($_POST['index'] ?? -1);
        $servers = loadServers();

        if ($index < 0 || $index >= count($servers)) {
            echo json_encode(['success' => false, 'error' => 'Invalid index']);
            break;
        }

        $serverData = [
            'type' => $_POST['type'] ?? 'plex',
            'name' => trim($_POST['name'] ?? ''),
            'host' => trim($_POST['host'] ?? ''),
            'port' => $_POST['port'] ?? '',
            'token' => $_POST['token'] ?? '',
            'ssl' => $_POST['ssl'] ?? '0',
            'ssl_verify' => $_POST['ssl_verify'] ?? '0'
        ];

        $validationErrors = validateServerData($serverData);
        if (!empty($validationErrors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $validationErrors)]);
            break;
        }

        $servers[$index] = $serverData;

        if (saveServers($servers)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save']);
        }
        break;
        
    case 'delete':
        $index = (int)($_POST['index'] ?? -1);
        $servers = loadServers();
        
        if ($index >= 0 && $index < count($servers)) {
            array_splice($servers, $index, 1);
            
            if (saveServers($servers)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid index']);
        }
        break;
        
    case 'test':
        $result = testConnection(
            $_POST['type'] ?? 'plex',
            $_POST['host'] ?? '',
            $_POST['port'] ?? '',
            $_POST['token'] ?? '',
            ($_POST['ssl'] ?? '0') === '1',
            ($_POST['ssl_verify'] ?? '0') === '1'
        );
        echo json_encode($result);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
