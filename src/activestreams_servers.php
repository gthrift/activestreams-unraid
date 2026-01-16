<?php
/**
 * What's Streaming - Server Management API
 * Handles adding, editing, deleting, and testing media server connections
 */

header('Content-Type: application/json');

$servers_file = "/boot/config/plugins/whatsstreaming/servers.json";

// Load existing servers
function loadServers() {
    global $servers_file;
    if (!file_exists($servers_file)) {
        return [];
    }
    $data = json_decode(file_get_contents($servers_file), true);
    return is_array($data) ? $data : [];
}

// Save servers
function saveServers($servers) {
    global $servers_file;
    return file_put_contents($servers_file, json_encode($servers, JSON_PRETTY_PRINT));
}

// Test server connection
function testConnection($type, $host, $port, $token, $ssl) {
    $protocol = $ssl ? 'https' : 'http';
    $url = '';
    $headers = [];
    
    switch ($type) {
        case 'plex':
            $url = "$protocol://$host:$port/status/sessions?X-Plex-Token=$token";
            $headers[] = 'Accept: application/json';
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($http_code === 200 || $http_code === 204) {
        $data = json_decode($response, true);
        $serverName = '';
        
        if ($type === 'plex' && isset($data['MediaContainer'])) {
            $serverName = 'Plex Server';
        } elseif (($type === 'emby' || $type === 'jellyfin') && isset($data['ServerName'])) {
            $serverName = $data['ServerName'];
        }
        
        return ['success' => true, 'message' => $serverName ? "Connected to: $serverName" : ''];
    }
    
    return [
        'success' => false, 
        'error' => $error ?: "HTTP $http_code"
    ];
}

// Handle requests
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        $servers = loadServers();
        $servers[] = [
            'type' => $_POST['type'] ?? 'plex',
            'name' => $_POST['name'] ?? 'New Server',
            'host' => $_POST['host'] ?? '',
            'port' => $_POST['port'] ?? '',
            'token' => $_POST['token'] ?? '',
            'ssl' => $_POST['ssl'] ?? '0'
        ];
        
        if (saveServers($servers)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save']);
        }
        break;
        
    case 'edit':
        $index = (int)($_POST['index'] ?? -1);
        $servers = loadServers();
        
        if ($index >= 0 && $index < count($servers)) {
            $servers[$index] = [
                'type' => $_POST['type'] ?? 'plex',
                'name' => $_POST['name'] ?? 'Server',
                'host' => $_POST['host'] ?? '',
                'port' => $_POST['port'] ?? '',
                'token' => $_POST['token'] ?? '',
                'ssl' => $_POST['ssl'] ?? '0'
            ];
            
            if (saveServers($servers)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid index']);
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
            ($_POST['ssl'] ?? '0') === '1'
        );
        echo json_encode($result);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
