<?php
/**
 * Active Streams - Stream Fetching API
 * Fetches and aggregates active streams from all configured media servers
 */

// Proper error handling: log errors but don't display them to users
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$cfg_file = "/boot/config/plugins/activestreams/activestreams.cfg";
$servers_file = "/boot/config/plugins/activestreams/servers.json";

// Load configuration
if (!file_exists($cfg_file)) {
    echo "<div style='padding:15px; text-align:center; opacity:0.6;'>_(Configuration missing)_</div>";
    exit;
}

$cfg = parse_ini_file($cfg_file);

// Get display settings
$showEpisodeNumbers = isset($cfg['SHOW_EPISODE_NUMBERS']) ? ($cfg['SHOW_EPISODE_NUMBERS'] === '1') : false;

// Load servers
if (!file_exists($servers_file)) {
    echo "<div style='padding:15px; text-align:center; color:#eebb00;'>
            <i class='fa fa-exclamation-triangle'></i> _(No servers configured. Please add a server in settings.)_
          </div>";
    exit;
}

$servers = json_decode(file_get_contents($servers_file), true);

// Check for JSON decode errors
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Active Streams: JSON decode error in servers.json - " . json_last_error_msg());
    echo "<div style='padding:15px; text-align:center; color:#d44;'>
            <i class='fa fa-exclamation-circle'></i> _(Error loading server configuration. Please check settings.)_
          </div>";
    exit;
}

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
 * Build curl handle for a specific server type
 */
function buildCurlHandle($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';

    switch ($server['type']) {
        case 'plex':
            $url = "$protocol://{$server['host']}:{$server['port']}/status/sessions?X-Plex-Token={$server['token']}";
            $headers = [
                'Accept: application/json',
                'X-Plex-Token: ' . $server['token']
            ];
            break;

        case 'emby':
            $url = "$protocol://{$server['host']}:{$server['port']}/emby/Sessions?api_key={$server['token']}";
            $headers = [];
            break;

        case 'jellyfin':
            $url = "$protocol://{$server['host']}:{$server['port']}/Sessions";
            $headers = ["X-Emby-Token: {$server['token']}"];
            break;

        default:
            return null;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // SSL verification - configurable per server (defaults to disabled for self-signed certs)
    $sslVerify = isset($server['ssl_verify']) && ($server['ssl_verify'] === '1' || $server['ssl_verify'] === true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    return $ch;
}

/**
 * Process response for a specific server type
 */
function processServerResponse($server, $response, $http_code, $curl_error) {
    if ($curl_error) {
        $serverType = ucfirst($server['type']);
        error_log("$serverType connection error for {$server['name']}: $curl_error");
        return ['error' => "Connection error: $curl_error"];
    }

    if ($http_code !== 200 || !$response) {
        $serverType = ucfirst($server['type']);
        error_log("$serverType HTTP error for {$server['name']}: HTTP $http_code");
        return ['error' => "HTTP $http_code"];
    }

    // Delegate to the appropriate parsing function
    if ($server['type'] === 'plex') {
        return parsePlexResponse($server, $response);
    } else {
        return parseEmbyJellyfinResponse($server, $response);
    }
}

/**
 * Parse Plex response into streams array
 */
function parsePlexResponse($server, $response) {
    global $showEpisodeNumbers;
    $data = json_decode($response, true);
    $streams = [];

    if (isset($data['MediaContainer']['Metadata'])) {
        foreach ($data['MediaContainer']['Metadata'] as $session) {
            $title = $session['title'] ?? 'Unknown';

            // Handle TV shows
            if (isset($session['grandparentTitle'])) {
                $showName = $session['grandparentTitle'];
                $episodeName = $session['title'] ?? '';

                if ($showEpisodeNumbers && isset($session['parentIndex']) && isset($session['index'])) {
                    // Format: "Show Name - S01E05 - Episode Name"
                    $title = "$showName - S{$session['parentIndex']}E{$session['index']} - $episodeName";
                } else {
                    // Format: "Show Name - Episode Name"
                    $title = "$showName - $episodeName";
                }
            }

            $user = $session['User']['title'] ?? 'Unknown';
            $device = $session['Player']['device'] ?? $session['Player']['product'] ?? 'Unknown';
            $state = $session['Player']['state'] ?? 'playing';

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
                'transcode_details' => $transcodeDetails
            ];
        }
    }

    return ['streams' => $streams];
}

/**
 * Parse Emby/Jellyfin response into streams array
 */
function parseEmbyJellyfinResponse($server, $response) {
    global $showEpisodeNumbers;
    $sessions = json_decode($response, true);
    $streams = [];

    if ($sessions) {
        foreach ($sessions as $session) {
            if (!isset($session['NowPlayingItem'])) continue;

            $item = $session['NowPlayingItem'];
            $episodeName = $item['Name'] ?? 'Unknown';

            // Handle TV shows
            if (isset($item['SeriesName'])) {
                $seriesTitle = $item['SeriesName'];

                // Add season/episode numbering if enabled and available
                if ($showEpisodeNumbers && isset($item['ParentIndexNumber']) && isset($item['IndexNumber'])) {
                    $season = str_pad($item['ParentIndexNumber'], 2, '0', STR_PAD_LEFT);
                    $episode = str_pad($item['IndexNumber'], 2, '0', STR_PAD_LEFT);
                    // Format: "Show Name - S01E05 - Episode Name"
                    $title = "$seriesTitle - S{$season}E{$episode} - $episodeName";
                } else {
                    // Format: "Show Name - Episode Name"
                    $title = "$seriesTitle - $episodeName";
                }
            } else {
                $title = $episodeName;
            }

            $user = $session['UserName'] ?? 'Unknown';
            $device = $session['DeviceName'] ?? 'Unknown';
            $isPaused = isset($session['PlayState']['IsPaused']) && $session['PlayState']['IsPaused'];

            // Time info (Emby/Jellyfin use ticks - 10,000,000 ticks = 1 second)
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
                'server_type' => $server['type'],
                'title' => $title,
                'user' => $user,
                'device' => $device,
                'state' => $isPaused ? 'paused' : 'playing',
                'progress' => $position,
                'duration' => $duration,
                'transcoding' => $isTranscoding,
                'transcode_details' => $transcodeDetails
            ];
        }
    }

    return ['streams' => $streams];
}

// Fetch from all servers in parallel using curl_multi
$allStreams = [];
$errors = [];

// Build curl handles for all servers
$mh = curl_multi_init();
$handles = [];
$serverMap = [];

foreach ($servers as $index => $server) {
    $ch = buildCurlHandle($server);
    if ($ch !== null) {
        $handles[$index] = $ch;
        $serverMap[$index] = $server;
        curl_multi_add_handle($mh, $ch);
    } else {
        $errors[] = "{$server['name']}: Unknown server type";
    }
}

// Execute all queries simultaneously
$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

// Collect results from all servers
foreach ($handles as $index => $ch) {
    $server = $serverMap[$index];
    $response = curl_multi_getcontent($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    $result = processServerResponse($server, $response, $http_code, $curl_error);

    if (isset($result['error'])) {
        $errors[] = "{$server['name']}: {$result['error']}";
    } elseif (isset($result['streams'])) {
        $allStreams = array_merge($allStreams, $result['streams']);
    }

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}

curl_multi_close($mh);

// Output
if (empty($allStreams)) {
    if (!empty($errors)) {
        echo "<div style='padding:15px; text-align:center; color:#d44;'>
                <i class='fa fa-exclamation-circle'></i> " . implode(', ', $errors) . "
              </div>";
    } else {
        echo "<div style='padding:15px; text-align:center; opacity:0.6; font-style:italic;'>No active streams</div>";
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
        
        // Transcoding icon with details tooltip
        $transcodeHtml = '';
        if ($s['transcoding']) {
            $transcodeTooltip = !empty($s['transcode_details']) 
                ? htmlspecialchars(implode(" | ", $s['transcode_details'])) 
                : 'Transcoding';
            $transcodeHtml = " <i class='fa fa-random' style='color:#e5a00d; cursor:help;' title='$transcodeTooltip'></i>";
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
        
        // Progress/Time with play/pause, and transcode indicators
        echo "<span class='as-time' style='color:$statusColor;' title='$timeDisplay'>
                <i class='fa $statusIcon' style='font-size:9px;'></i>
                $transcodeHtml
                <span style='margin-left:4px;'>$timeDisplay</span>
              </span>";
        
        echo "</div>";
    }
}
?>
