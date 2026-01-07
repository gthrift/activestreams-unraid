<?php
/**
 * Active Streams - Stream Fetching API
 * Fetches and aggregates active streams from all configured media servers
 */

error_reporting(0);

$cfg_file = "/boot/config/plugins/activestreams/activestreams.cfg";
$servers_file = "/boot/config/plugins/activestreams/servers.json";

// Load configuration
if (!file_exists($cfg_file)) {
    echo "<div style='padding:15px; text-align:center; opacity:0.6;'>_(Configuration missing)_</div>";
    exit;
}

$cfg = parse_ini_file($cfg_file);

// Load servers
if (!file_exists($servers_file)) {
    echo "<div style='padding:15px; text-align:center; color:#eebb00;'>
            <i class='fa fa-exclamation-triangle'></i> _(No servers configured. Please add a server in settings.)_
          </div>";
    exit;
}

$servers = json_decode(file_get_contents($servers_file), true);
if (empty($servers)) {
    echo "<div style='padding:15px; text-align:center; color:#eebb00;'>
            <i class='fa fa-exclamation-triangle'></i> _(No servers configured. Please add a server in settings.)_
          </div>";
    exit;
}

/**
 * Format seconds to HH:MM:SS or MM:SS
 */
function formatTime($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf("%d:%02d:%02d", $hours, $minutes, $secs);
    }
    return sprintf("%d:%02d", $minutes, $secs);
}

/**
 * Check if IP is local/LAN
 */
function isLocalIP($ip) {
    if (empty($ip)) return true; // Assume local if unknown
    
    // Check for common local IP patterns
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|::1|fe80:|fc00:|fd00:)/i', $ip)) {
        return true;
    }
    
    // Check for localhost
    if ($ip === 'localhost' || $ip === '::1') {
        return true;
    }
    
    return false;
}

/**
 * Fetch streams from Plex server
 */
function fetchPlexStreams($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';
    $url = "$protocol://{$server['host']}:{$server['port']}/status/sessions?X-Plex-Token={$server['token']}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200 || !$response) {
        return ['error' => 'Connection failed'];
    }
    
    $data = json_decode($response, true);
    $streams = [];
    
    if (isset($data['MediaContainer']['Metadata'])) {
        foreach ($data['MediaContainer']['Metadata'] as $session) {
            $title = $session['title'] ?? 'Unknown';
            
            // Handle TV shows
            if (isset($session['grandparentTitle'])) {
                $title = $session['grandparentTitle'] . ' - ' . $title;
                if (isset($session['parentIndex']) && isset($session['index'])) {
                    $title = $session['grandparentTitle'] . " - S{$session['parentIndex']}E{$session['index']}";
                }
            }
            
            $user = $session['User']['title'] ?? 'Unknown';
            $device = $session['Player']['device'] ?? $session['Player']['product'] ?? 'Unknown';
            $state = $session['Player']['state'] ?? 'playing';
            
            // Location - Plex provides this directly
            $location = $session['Session']['location'] ?? 'lan';
            $isLocal = ($location === 'lan');
            
            // Client IP
            $clientIP = $session['Player']['address'] ?? '';
            
            // Time info (Plex uses milliseconds)
            $viewOffset = isset($session['viewOffset']) ? $session['viewOffset'] / 1000 : 0;
            $duration = isset($session['duration']) ? $session['duration'] / 1000 : 0;
            
            // Transcode detection and details
            $isTranscoding = false;
            $transcodeDetails = [];
            
            if (isset($session['TranscodeSession'])) {
                $isTranscoding = true;
                $ts = $session['TranscodeSession'];
                
                // Video transcode info
                if (isset($ts['videoDecision']) && $ts['videoDecision'] === 'transcode') {
                    $transcodeDetails[] = "Video: Transcoding";
                    if (isset($ts['transcodeHwRequested']) && $ts['transcodeHwRequested']) {
                        $transcodeDetails[] = "Hardware Accelerated";
                    } else {
                        $transcodeDetails[] = "Software Transcode";
                    }
                } elseif (isset($ts['videoDecision'])) {
                    $transcodeDetails[] = "Video: " . ucfirst($ts['videoDecision']);
                }
                
                // Audio transcode info
                if (isset($ts['audioDecision']) && $ts['audioDecision'] === 'transcode') {
                    $transcodeDetails[] = "Audio: Transcoding";
                } elseif (isset($ts['audioDecision'])) {
                    $transcodeDetails[] = "Audio: " . ucfirst($ts['audioDecision']);
                }
                
                // Transcode reason if available
                if (isset($ts['transcodeHwFullPipeline'])) {
                    $transcodeDetails[] = $ts['transcodeHwFullPipeline'] ? "Full HW Pipeline" : "Partial HW";
                }
            }
            
            $streams[] = [
                'server_name' => $server['name'],
                'server_type' => 'plex',
                'title' => $title,
                'user' => $user,
                'device' => $device,
                'state' => $state === 'paused' ? 'paused' : 'playing',
                'progress' => $viewOffset,
                'duration' => $duration,
                'transcoding' => $isTranscoding,
                'transcode_details' => $transcodeDetails,
                'is_local' => $isLocal,
                'client_ip' => $clientIP
            ];
        }
    }
    
    return ['streams' => $streams];
}

/**
 * Fetch streams from Emby server
 */
function fetchEmbyStreams($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';
    $url = "$protocol://{$server['host']}:{$server['port']}/emby/Sessions?api_key={$server['token']}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200 || !$response) {
        return ['error' => 'Connection failed'];
    }
    
    $sessions = json_decode($response, true);
    $streams = [];
    
    if ($sessions) {
        foreach ($sessions as $session) {
            if (!isset($session['NowPlayingItem'])) continue;
            
            $item = $session['NowPlayingItem'];
            $title = $item['Name'] ?? 'Unknown';
            
            // Handle TV shows
            if (isset($item['SeriesName'])) {
                $title = $item['SeriesName'] . ' - ' . $title;
            }
            
            $user = $session['UserName'] ?? 'Unknown';
            $device = $session['DeviceName'] ?? 'Unknown';
            $isPaused = isset($session['PlayState']['IsPaused']) && $session['PlayState']['IsPaused'];
            
            // Client IP and location
            $clientIP = $session['RemoteEndPoint'] ?? '';
            $isLocal = isLocalIP($clientIP);
            
            // Time info (Emby uses ticks - 10,000,000 ticks = 1 second)
            $position = isset($session['PlayState']['PositionTicks']) ? $session['PlayState']['PositionTicks'] / 10000000 : 0;
            $duration = isset($item['RunTimeTicks']) ? $item['RunTimeTicks'] / 10000000 : 0;
            
            // Transcode detection and details
            $playMethod = $session['PlayState']['PlayMethod'] ?? 'DirectPlay';
            $isTranscoding = ($playMethod === 'Transcode');
            $transcodeDetails = [];
            
            if ($isTranscoding && isset($session['TranscodingInfo'])) {
                $ti = $session['TranscodingInfo'];
                
                if (isset($ti['IsVideoDirect'])) {
                    $transcodeDetails[] = $ti['IsVideoDirect'] ? "Video: Direct Stream" : "Video: Transcoding";
                }
                if (isset($ti['IsAudioDirect'])) {
                    $transcodeDetails[] = $ti['IsAudioDirect'] ? "Audio: Direct Stream" : "Audio: Transcoding";
                }
                if (isset($ti['VideoCodec'])) {
                    $transcodeDetails[] = "Codec: " . strtoupper($ti['VideoCodec']);
                }
                if (isset($ti['TranscodeReasons']) && is_array($ti['TranscodeReasons'])) {
                    $transcodeDetails[] = "Reason: " . implode(', ', $ti['TranscodeReasons']);
                }
                if (isset($ti['HardwareAccelerationType']) && $ti['HardwareAccelerationType']) {
                    $transcodeDetails[] = "HW Accel: " . $ti['HardwareAccelerationType'];
                }
            } elseif ($playMethod === 'DirectStream') {
                $transcodeDetails[] = "Direct Stream (Container remux)";
            } else {
                $transcodeDetails[] = "Direct Play";
            }
            
            $streams[] = [
                'server_name' => $server['name'],
                'server_type' => 'emby',
                'title' => $title,
                'user' => $user,
                'device' => $device,
                'state' => $isPaused ? 'paused' : 'playing',
                'progress' => $position,
                'duration' => $duration,
                'transcoding' => $isTranscoding,
                'transcode_details' => $transcodeDetails,
                'is_local' => $isLocal,
                'client_ip' => $clientIP
            ];
        }
    }
    
    return ['streams' => $streams];
}

/**
 * Fetch streams from Jellyfin server
 */
function fetchJellyfinStreams($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';
    $url = "$protocol://{$server['host']}:{$server['port']}/Sessions";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Emby-Token: {$server['token']}"
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200 || !$response) {
        return ['error' => 'Connection failed'];
    }
    
    $sessions = json_decode($response, true);
    $streams = [];
    
    if ($sessions) {
        foreach ($sessions as $session) {
            if (!isset($session['NowPlayingItem'])) continue;
            
            $item = $session['NowPlayingItem'];
            $title = $item['Name'] ?? 'Unknown';
            
            // Handle TV shows
            if (isset($item['SeriesName'])) {
                $title = $item['SeriesName'] . ' - ' . $title;
            }
            
            $user = $session['UserName'] ?? 'Unknown';
            $device = $session['DeviceName'] ?? 'Unknown';
            $isPaused = isset($session['PlayState']['IsPaused']) && $session['PlayState']['IsPaused'];
            
            // Client IP and location
            $clientIP = $session['RemoteEndPoint'] ?? '';
            $isLocal = isLocalIP($clientIP);
            
            // Time info (Jellyfin uses ticks - 10,000,000 ticks = 1 second)
            $position = isset($session['PlayState']['PositionTicks']) ? $session['PlayState']['PositionTicks'] / 10000000 : 0;
            $duration = isset($item['RunTimeTicks']) ? $item['RunTimeTicks'] / 10000000 : 0;
            
            // Transcode detection and details
            $playMethod = $session['PlayState']['PlayMethod'] ?? 'DirectPlay';
            $isTranscoding = ($playMethod === 'Transcode');
            $transcodeDetails = [];
            
            if ($isTranscoding && isset($session['TranscodingInfo'])) {
                $ti = $session['TranscodingInfo'];
                
                if (isset($ti['IsVideoDirect'])) {
                    $transcodeDetails[] = $ti['IsVideoDirect'] ? "Video: Direct Stream" : "Video: Transcoding";
                }
                if (isset($ti['IsAudioDirect'])) {
                    $transcodeDetails[] = $ti['IsAudioDirect'] ? "Audio: Direct Stream" : "Audio: Transcoding";
                }
                if (isset($ti['VideoCodec'])) {
                    $transcodeDetails[] = "Codec: " . strtoupper($ti['VideoCodec']);
                }
                if (isset($ti['TranscodeReasons']) && is_array($ti['TranscodeReasons'])) {
                    $transcodeDetails[] = "Reason: " . implode(', ', $ti['TranscodeReasons']);
                }
                if (isset($ti['HardwareAccelerationType']) && $ti['HardwareAccelerationType']) {
                    $transcodeDetails[] = "HW Accel: " . $ti['HardwareAccelerationType'];
                }
            } elseif ($playMethod === 'DirectStream') {
                $transcodeDetails[] = "Direct Stream (Container remux)";
            } else {
                $transcodeDetails[] = "Direct Play";
            }
            
            $streams[] = [
                'server_name' => $server['name'],
                'server_type' => 'jellyfin',
                'title' => $title,
                'user' => $user,
                'device' => $device,
                'state' => $isPaused ? 'paused' : 'playing',
                'progress' => $position,
                'duration' => $duration,
                'transcoding' => $isTranscoding,
                'transcode_details' => $transcodeDetails,
                'is_local' => $isLocal,
                'client_ip' => $clientIP
            ];
        }
    }
    
    return ['streams' => $streams];
}

// Fetch from all servers
$allStreams = [];
$errors = [];

foreach ($servers as $server) {
    switch ($server['type']) {
        case 'plex':
            $result = fetchPlexStreams($server);
            break;
        case 'emby':
            $result = fetchEmbyStreams($server);
            break;
        case 'jellyfin':
            $result = fetchJellyfinStreams($server);
            break;
        default:
            $result = ['error' => 'Unknown server type'];
    }
    
    if (isset($result['error'])) {
        $errors[] = "{$server['name']}: {$result['error']}";
    } elseif (isset($result['streams'])) {
        $allStreams = array_merge($allStreams, $result['streams']);
    }
}

// Output
if (empty($allStreams)) {
    if (!empty($errors)) {
        echo "<div style='padding:15px; text-align:center; color:#d44;'>
                <i class='fa fa-exclamation-circle'></i> " . implode(', ', $errors) . "
              </div>";
    } else {
        echo "<div style='padding:15px; text-align:center; opacity:0.6; font-style:italic;'>_(No active streams)_</div>";
    }
} else {
    foreach ($allStreams as $s) {
        $title = htmlspecialchars($s['title']);
        $user = htmlspecialchars($s['user']);
        $device = htmlspecialchars($s['device']);
        $serverName = htmlspecialchars($s['server_name']);
        $serverType = $s['server_type'];
        
        $isPaused = ($s['state'] === 'paused');
        $statusColor = $isPaused ? "#f0ad4e" : "#8cc43c";
        $statusIcon = $isPaused ? "fa-pause" : "fa-play";
        
        // Format progress/duration
        $progressStr = formatTime($s['progress']);
        $durationStr = formatTime($s['duration']);
        $timeDisplay = "$progressStr / $durationStr";
        
        // Server type color
        $typeColors = [
            'plex' => '#e5a00d',
            'emby' => '#52b54b', 
            'jellyfin' => '#00a4dc'
        ];
        $typeColor = $typeColors[$serverType] ?? '#888';
        
        // LAN/WAN icon (fa-network-wired requires FA5+, falls back gracefully)
        $locationIcon = $s['is_local'] ? 'fa-network-wired' : 'fa-globe';
        $locationTitle = $s['is_local'] ? 'Local (LAN)' : 'Remote (WAN)';
        if (!empty($s['client_ip'])) {
            $locationTitle .= ' - ' . htmlspecialchars($s['client_ip']);
        }
        
        // Transcoding icon with details tooltip
        $transcodeHtml = '';
        if ($s['transcoding']) {
            $transcodeTooltip = !empty($s['transcode_details']) 
                ? htmlspecialchars(implode(" | ", $s['transcode_details'])) 
                : 'Transcoding';
            $transcodeHtml = " <i class='fa fa-microchip' style='color:#e5a00d; cursor:help;' title='$transcodeTooltip'></i>";
        }
        
        echo "<div class='as-row'>";
        
        // Server indicator (small colored dot)
        echo "<span class='as-server' style='color:$typeColor;' title='$serverName ($serverType)'>
                <i class='fa fa-circle' style='font-size:8px;'></i>
              </span>";
        
        // Title
        echo "<span class='as-name' title='$title'>$title</span>";
        
        // Device
        echo "<span class='as-device' title='$device'>$device</span>";
        
        // User (no icon)
        echo "<span class='as-user' title='$user'>$user</span>";
        
        // Progress/Time with LAN/WAN icon, play/pause, and transcode indicator
        echo "<span class='as-time' style='color:$statusColor;' title='$timeDisplay'>
                <i class='fa $locationIcon' style='font-size:10px; margin-right:4px; opacity:0.7;' title='$locationTitle'></i>
                <i class='fa $statusIcon' style='font-size:9px;'></i>
                $transcodeHtml
                <span style='margin-left:4px;'>$timeDisplay</span>
              </span>";
        
        echo "</div>";
    }
}
?>
