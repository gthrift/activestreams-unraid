<?php
/**
 * Active Streams - Stream Fetching API
 * Fetches and aggregates active streams from all configured media servers
*/

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$cfg_file = "/boot/config/plugins/activestreams/activestreams.cfg";
$servers_file = "/boot/config/plugins/activestreams/servers.json";

if (!file_exists($cfg_file)) {
    echo "<div style='padding:15px; text-align:center; opacity:0.6;'>_(Configuration missing)_</div>";
    exit;
}

$cfg = parse_ini_file($cfg_file);

$showEpisodeNumbers = isset($cfg['SHOW_EPISODE_NUMBERS']) ? ($cfg['SHOW_EPISODE_NUMBERS'] === '1') : false;

if (!file_exists($servers_file)) {
    echo "<div style='padding:15px; text-align:center; color:#eebb00;'>
            <i class='fa fa-exclamation-triangle'></i> _(No servers configured. Please add a server in settings.)_
          </div>";
    exit;
}

$servers = json_decode(file_get_contents($servers_file), true);

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

function formatBitrate($kbps) {
    if (!$kbps || !is_numeric($kbps)) return '';
    $kbps = (int)$kbps;
    if ($kbps >= 1000) {
        return round($kbps / 1000, 1) . ' Mbps';
    }
    return $kbps . ' kbps';
}

function formatResolution($height) {
    if (!$height || !is_numeric($height)) return '';
    return $height . 'p';
}

function formatChannels($channels) {
    if (!$channels || !is_numeric($channels)) return '';
    switch ((int)$channels) {
        case 1: return 'Mono';
        case 2: return 'Stereo';
        case 6: return '5.1';
        case 8: return '7.1';
        default: return $channels . 'ch';
    }
}


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

    $sslVerify = isset($server['ssl_verify']) && ($server['ssl_verify'] === '1' || $server['ssl_verify'] === true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    return $ch;
}

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

    if ($server['type'] === 'plex') {
        return parsePlexResponse($server, $response);
    } else {
        return parseEmbyJellyfinResponse($server, $response);
    }
}

function parsePlexResponse($server, $response) {
    global $showEpisodeNumbers;
    $data = json_decode($response, true);
    $streams = [];

    if (isset($data['MediaContainer']['Metadata'])) {
        foreach ($data['MediaContainer']['Metadata'] as $session) {
            $title = $session['title'] ?? 'Unknown';

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

            $viewOffset = isset($session['viewOffset']) ? $session['viewOffset'] / 1000 : 0;
            $duration = isset($session['duration']) ? $session['duration'] / 1000 : 0;

            $isTranscoding = false;
            $transcodeDetails = [];

            if (isset($session['TranscodeSession'])) {
                $ts = $session['TranscodeSession'];
                $vidDec = $ts['videoDecision'] ?? '';
                $audDec = $ts['audioDecision'] ?? '';
                $isTranscoding = ($vidDec === 'transcode' || $audDec === 'transcode');

                if ($isTranscoding) {
                    // Source info from Media object
                    $media = $session['Media'][0] ?? [];
                    $srcContainer = strtoupper($media['container'] ?? '');
                    $srcBitrate = formatBitrate($media['bitrate'] ?? 0);
                    $srcHeight = $media['height'] ?? 0;
                    $srcAudioChannels = formatChannels($media['audioChannels'] ?? 0);

                    // Source codecs from TranscodeSession
                    $srcVideoCodec = strtoupper($ts['sourceVideoCodec'] ?? '');
                    $srcAudioCodec = strtoupper($ts['sourceAudioCodec'] ?? '');

                    // Target info from TranscodeSession
                    $tgtContainer = strtoupper($ts['container'] ?? '');
                    $tgtVideoCodec = strtoupper($ts['videoCodec'] ?? '');
                    $tgtAudioCodec = strtoupper($ts['audioCodec'] ?? '');
                    $tgtAudioChannels = formatChannels($ts['audioChannels'] ?? 0);

                    $hwAccel = !empty($ts['transcodeHwRequested']);
                    $hwTag = $hwAccel ? ' [HW]' : '';

                    // Stream line
                    $streamLine = "Stream: $srcContainer";
                    if ($srcBitrate) $streamLine .= " ($srcBitrate)";
                    if ($tgtContainer) $streamLine .= " → $tgtContainer";
                    $throttled = $ts['throttled'] ?? false;
                    $speed = $ts['speed'] ?? 0;
                    if ($throttled) {
                        $streamLine .= " (Throttled)";
                    } elseif ($speed && is_numeric($speed)) {
                        $streamLine .= " (" . round((float)$speed, 1) . "x)";
                    }
                    $transcodeDetails[] = $streamLine;

                    // Video line
                    $srcRes = formatResolution($srcHeight);
                    if ($vidDec === 'transcode') {
                        $videoLine = "Video: ";
                        if ($srcRes) $videoLine .= "$srcRes ";
                        $videoLine .= "$srcVideoCodec → $tgtVideoCodec$hwTag";
                    } else {
                        $videoLine = "Video: Direct " . ucfirst($vidDec);
                    }
                    $transcodeDetails[] = $videoLine;

                    // Audio line
                    if ($audDec === 'transcode') {
                        $audioLine = "Audio: $srcAudioCodec";
                        if ($srcAudioChannels) $audioLine .= " $srcAudioChannels";
                        $audioLine .= " → $tgtAudioCodec";
                        if ($tgtAudioChannels) $audioLine .= " $tgtAudioChannels";
                    } else {
                        $audioLine = "Audio: Direct " . ucfirst($audDec);
                    }
                    $transcodeDetails[] = $audioLine;
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


function parseEmbyJellyfinResponse($server, $response) {
    global $showEpisodeNumbers;
    $sessions = json_decode($response, true);
    $streams = [];

    if ($sessions) {
        foreach ($sessions as $session) {
            if (!isset($session['NowPlayingItem'])) continue;

            $item = $session['NowPlayingItem'];
            $episodeName = $item['Name'] ?? 'Unknown';

            if (isset($item['SeriesName'])) {
                $seriesTitle = $item['SeriesName'];
                
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

            $playMethod = $session['PlayState']['PlayMethod'] ?? 'DirectPlay';
            $transcodeDetails = [];

            // Extract source info from MediaStreams
            $mediaStreams = $item['MediaStreams'] ?? [];
            $srcVideo = null;
            $srcAudio = null;
            foreach ($mediaStreams as $ms) {
                if (!$srcVideo && ($ms['Type'] ?? '') === 'Video') $srcVideo = $ms;
                if (!$srcAudio && ($ms['Type'] ?? '') === 'Audio') $srcAudio = $ms;
            }

            $isTranscoding = false;

            if ($playMethod === 'Transcode' && isset($session['TranscodingInfo'])) {
                $ti = $session['TranscodingInfo'];
                $vidDirect = $ti['IsVideoDirect'] ?? true;
                $audDirect = $ti['IsAudioDirect'] ?? true;

                // If both video and audio are direct, this is a container remux, not real transcoding
                $isTranscoding = (!$vidDirect || !$audDirect);

                if ($isTranscoding) {
                    $srcContainer = strtoupper($item['Container'] ?? '');
                    $srcVideoBitrate = $srcVideo['BitRate'] ?? 0;
                    $srcVideoCodec = strtoupper($srcVideo['Codec'] ?? '');
                    $srcHeight = $srcVideo['Height'] ?? 0;
                    $srcAudioCodec = strtoupper($srcAudio['Codec'] ?? '');
                    $srcAudioChannels = formatChannels($srcAudio['Channels'] ?? 0);

                    $tgtContainer = strtoupper($ti['Container'] ?? '');
                    $tgtVideoCodec = strtoupper($ti['VideoCodec'] ?? '');
                    $tgtAudioCodec = strtoupper($ti['AudioCodec'] ?? '');
                    $tgtBitrate = formatBitrate(isset($ti['Bitrate']) ? $ti['Bitrate'] / 1000 : 0);
                    $tgtHeight = $ti['Height'] ?? 0;
                    $tgtAudioChannels = formatChannels($ti['AudioChannels'] ?? 0);
                    $tgtFramerate = $ti['Framerate'] ?? 0;

                    $hwAccelType = $ti['HardwareAccelerationType'] ?? '';
                    $hwTag = $hwAccelType ? " [HW]" : '';

                    // Stream line
                    $streamLine = "Stream: $srcContainer";
                    $srcTotalBitrate = formatBitrate(($item['Bitrate'] ?? 0) / 1000);
                    if (!$srcTotalBitrate && $srcVideoBitrate) {
                        $srcTotalBitrate = formatBitrate($srcVideoBitrate / 1000);
                    }
                    if ($srcTotalBitrate) $streamLine .= " ($srcTotalBitrate)";
                    if ($tgtContainer) {
                        $streamLine .= " → $tgtContainer";
                        $tgtInfo = [];
                        if ($tgtBitrate) $tgtInfo[] = $tgtBitrate;
                        if ($tgtFramerate) $tgtInfo[] = round((float)$tgtFramerate) . ' fps';
                        if (!empty($tgtInfo)) $streamLine .= ' (' . implode(', ', $tgtInfo) . ')';
                    }
                    $transcodeDetails[] = $streamLine;

                    // Video line
                    $srcRes = formatResolution($srcHeight);
                    if (!$vidDirect) {
                        $videoLine = "Video: ";
                        if ($srcRes) $videoLine .= "$srcRes ";
                        $videoLine .= "$srcVideoCodec → $tgtVideoCodec";
                        if ($tgtHeight && $tgtHeight != $srcHeight) {
                            $videoLine .= ' ' . formatResolution($tgtHeight);
                        }
                        $videoLine .= $hwTag;
                    } else {
                        $videoLine = "Video: Direct Stream";
                    }
                    $transcodeDetails[] = $videoLine;

                    // Audio line
                    if (!$audDirect) {
                        $audioLine = "Audio: $srcAudioCodec";
                        if ($srcAudioChannels) $audioLine .= " $srcAudioChannels";
                        $audioLine .= " → $tgtAudioCodec";
                        if ($tgtAudioChannels) $audioLine .= " $tgtAudioChannels";
                    } else {
                        $audioLine = "Audio: Direct Stream";
                    }
                    $transcodeDetails[] = $audioLine;

                    // Reason line
                    if (isset($ti['TranscodeReasons']) && is_array($ti['TranscodeReasons']) && !empty($ti['TranscodeReasons'])) {
                        $transcodeDetails[] = "Reason: " . implode(', ', $ti['TranscodeReasons']);
                    }
                }
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

$allStreams = [];
$errors = [];

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

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

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
        
        $progressStr = formatTime($s['progress']);
        $durationStr = formatTime($s['duration']);
        $timeDisplay = "$progressStr / $durationStr";
        
        $typeColors = [
            'plex' => '#e5a00d',
            'emby' => '#52b54b', 
            'jellyfin' => '#00a4dc'
        ];
        $typeColor = $typeColors[$serverType] ?? '#888';
        
        $transcodeHtml = '';
        if ($s['transcoding']) {
            $transcodeTooltip = !empty($s['transcode_details'])
                ? htmlspecialchars(implode("\n", $s['transcode_details']))
                : 'Transcoding';
            $transcodeHtml = " <i class='fa fa-random' style='color:#e5a00d; cursor:help;' title='$transcodeTooltip'></i>";
        }
        
        echo "<div class='as-row'>";
        
        echo "<span class='as-server' style='color:$typeColor;' title='$serverName ($serverType)'>
                <i class='fa fa-circle' style='font-size:8px;'></i>
              </span>";
        
        echo "<span class='as-name' title='$title'>$title</span>";
        
        echo "<span class='as-device' title='$device'>$device</span>";
        
        echo "<span class='as-user' title='$user'>$user</span>";
        
        echo "<span class='as-time' style='color:$statusColor;' title='$timeDisplay'>
                <i class='fa $statusIcon' style='font-size:9px;'></i>
                $transcodeHtml
                <span style='margin-left:4px;'>$timeDisplay</span>
              </span>";
        
        echo "</div>";
    }
}
?>
