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
$showPlayStatus     = isset($cfg['SHOW_PLAY_STATUS'])    ? ($cfg['SHOW_PLAY_STATUS']    === '1') : false;
$showDevice         = isset($cfg['SHOW_DEVICE_COLUMN'])  ? ($cfg['SHOW_DEVICE_COLUMN']  === '1') : true;
$showUser           = isset($cfg['SHOW_USER_COLUMN'])    ? ($cfg['SHOW_USER_COLUMN']    === '1') : true;

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

function formatTimeFull($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours   = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs    = $seconds % 60;
    return sprintf("%d:%02d:%02d", $hours, $minutes, $secs);
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
            $url = "$protocol://{$server['host']}:{$server['port']}/status/sessions";
            $headers = [
                'Accept: application/json',
                'X-Plex-Token: ' . $server['token']
            ];
            break;

        case 'emby':
            $url = "$protocol://{$server['host']}:{$server['port']}/emby/Sessions";
            $headers = ['X-Emby-Token: ' . $server['token']];
            break;

        case 'jellyfin':
            $url = "$protocol://{$server['host']}:{$server['port']}/Sessions";
            $headers = ['Authorization: MediaBrowser Client="ActiveStreams", Device="Unraid", DeviceId="activestreams-plugin", Version="1.0.0", Token="' . $server['token'] . '"'];
            break;

        case 'dispatcharr':
            // Combined live + VOD stats (Dispatcharr v0.28.0+)
            $url = dispatcharrBaseUrl($server) . "/proxy/stats/";
            $headers = dispatcharrHeaders($server);
            break;

        default:
            return null;
    }

    return makeCurl($url, $headers, $server);
}

function makeCurl($url, $headers, $server) {
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

function dispatcharrBaseUrl($server) {
    $protocol = ($server['ssl'] === '1' || $server['ssl'] === true) ? 'https' : 'http';
    return "$protocol://{$server['host']}:{$server['port']}";
}

function dispatcharrHeaders($server) {
    return [
        'Accept: application/json',
        'X-API-Key: ' . $server['token']
    ];
}

/**
 * Synchronous GET against the Dispatcharr API. Returns decoded JSON or null on failure.
 */
function dispatcharrGet($server, $path) {
    $ch = makeCurl(dispatcharrBaseUrl($server) . $path, dispatcharrHeaders($server), $server);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function processServerResponse($server, $response, $http_code, $curl_error) {
    if ($curl_error) {
        $serverType = ucfirst($server['type']);
        error_log("$serverType connection error for {$server['name']}: $curl_error");
        return ['error' => "Connection error: $curl_error"];
    }

    // Older Dispatcharr versions lack /proxy/stats/; the parser falls back on 404
    if ($server['type'] === 'dispatcharr' && $http_code === 404) {
        return parseDispatcharrResponse($server, null);
    }

    if ($http_code !== 200 || !$response) {
        $serverType = ucfirst($server['type']);
        error_log("$serverType HTTP error for {$server['name']}: HTTP $http_code");
        return ['error' => "HTTP $http_code"];
    }

    if ($server['type'] === 'plex') {
        return parsePlexResponse($server, $response);
    } elseif ($server['type'] === 'dispatcharr') {
        return parseDispatcharrResponse($server, $response);
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

/**
 * Shorten a user agent to a readable client name, e.g. "VLC/3.0.20 LibVLC/3.0.20" -> "VLC".
 */
function shortUserAgent($ua) {
    $ua = trim((string)$ua);
    if ($ua === '' || $ua === 'Unknown') return 'Unknown';
    $parts = preg_split('#[\s/]+#', $ua);
    return $parts[0] !== '' ? $parts[0] : $ua;
}

/**
 * Parse Dispatcharr stats. $response is the /proxy/stats/ body, or null to fall back
 * to the live-only /proxy/ts/status endpoint used by versions before v0.28.0.
 */
function parseDispatcharrResponse($server, $response) {
    global $showEpisodeNumbers;

    if ($response === null) {
        $live = dispatcharrGet($server, '/proxy/ts/status');
        if ($live === null) {
            error_log("Dispatcharr stats unavailable for {$server['name']}");
            return ['error' => 'Stats unavailable'];
        }
        $data = ['live' => $live, 'vod' => []];
    } else {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['error' => 'Invalid response'];
        }
    }

    $channels = $data['live']['channels'] ?? [];
    $vodConnections = $data['vod']['vod_connections'] ?? [];
    // TODO: catch-up sessions ($data['catchup']) use a different schema; not shown yet

    // Resolve user IDs to usernames with a single lookup, only if needed
    $needUsers = false;
    foreach ($channels as $ch) {
        foreach ($ch['clients'] ?? [] as $c) {
            if (!empty($c['user_id']) && $c['user_id'] !== '0') { $needUsers = true; break 2; }
        }
    }
    if (!$needUsers) {
        foreach ($vodConnections as $v) {
            if (!empty($v['user_id']) && $v['user_id'] !== '0') { $needUsers = true; break; }
        }
    }
    $usernames = [];
    if ($needUsers) {
        $users = dispatcharrGet($server, '/api/accounts/users/');
        // Handle both plain list and paginated {results: [...]} responses
        if (isset($users['results']) && is_array($users['results'])) {
            $users = $users['results'];
        }
        foreach ($users ?? [] as $u) {
            if (isset($u['id'], $u['username'])) {
                $usernames[(string)$u['id']] = $u['username'];
            }
        }
    }
    $resolveUser = function ($userId, $ip) use ($usernames) {
        $userId = (string)($userId ?? '');
        if ($userId !== '' && $userId !== '0' && isset($usernames[$userId])) {
            return $usernames[$userId];
        }
        return ($ip && $ip !== 'Unknown') ? $ip : 'Unknown';
    };

    $now = time();
    $streams = [];

    foreach ($channels as $ch) {
        $title = $ch['channel_name'] ?? $ch['stream_name'] ?? 'Unknown Channel';

        // Source stream details shown in the tooltip
        $details = [];
        $videoParts = [];
        if (!empty($ch['resolution']))  $videoParts[] = $ch['resolution'];
        if (!empty($ch['video_codec'])) $videoParts[] = strtoupper($ch['video_codec']);
        if (!empty($ch['source_fps']))  $videoParts[] = '@ ' . round((float)$ch['source_fps']) . ' fps';
        if ($videoParts) $details[] = 'Video: ' . implode(' ', $videoParts);

        $audioParts = [];
        if (!empty($ch['audio_codec'])) $audioParts[] = strtoupper($ch['audio_codec']);
        if (!empty($ch['audio_channels'])) {
            $audioParts[] = is_numeric($ch['audio_channels']) ? formatChannels($ch['audio_channels']) : $ch['audio_channels'];
        }
        if ($audioParts) $details[] = 'Audio: ' . implode(' ', $audioParts);

        if (!empty($ch['avg_bitrate'])) $details[] = 'Bitrate: ' . $ch['avg_bitrate'];
        if (!empty($ch['stream_name']) && $ch['stream_name'] !== $title) {
            $details[] = 'Source: ' . $ch['stream_name'];
        }

        $clients = $ch['clients'] ?? [];
        foreach ($clients as $client) {
            $connectedAt = isset($client['connected_at']) ? (float)$client['connected_at'] : 0;
            $elapsed = $connectedAt > 0 ? $now - $connectedAt : ($ch['uptime'] ?? 0);
            $isTranscoding = !empty($client['output_profile_id']);

            $clientDetails = $details;
            if ($isTranscoding) {
                $clientDetails[] = 'Output: Transcoded (profile ' . $client['output_profile_id'] . ')';
            }

            $streams[] = [
                'server_name' => $server['name'],
                'server_type' => 'dispatcharr',
                'title' => $title,
                'user' => $resolveUser($client['user_id'] ?? null, $client['ip_address'] ?? ''),
                'device' => shortUserAgent($client['user_agent'] ?? ''),
                'state' => 'playing',
                'progress' => $elapsed,
                'duration' => 0,
                'live' => true,
                'transcoding' => $isTranscoding,
                'transcode_details' => $clientDetails
            ];
        }
    }

    foreach ($vodConnections as $v) {
        $meta = $v['content_metadata'] ?? [];
        $title = $v['content_name'] ?? 'Unknown';

        if (($v['content_type'] ?? '') === 'episode' && !empty($meta['series_name'])) {
            $episodeName = $meta['episode_name'] ?? '';
            if ($showEpisodeNumbers && isset($meta['season_number'], $meta['episode_number'])) {
                $season = str_pad($meta['season_number'], 2, '0', STR_PAD_LEFT);
                $episode = str_pad($meta['episode_number'], 2, '0', STR_PAD_LEFT);
                $title = "{$meta['series_name']} - S{$season}E{$episode} - $episodeName";
            } else {
                $title = "{$meta['series_name']} - $episodeName";
            }
        }

        $details = [];
        if (!empty($v['m3u_profile']['account_name']) && $v['m3u_profile']['account_name'] !== 'Unknown Account') {
            $details[] = 'Source: ' . $v['m3u_profile']['account_name'];
        }

        $streams[] = [
            'server_name' => $server['name'],
            'server_type' => 'dispatcharr',
            'title' => $title,
            'user' => $resolveUser($v['user_id'] ?? null, $v['client_ip'] ?? ''),
            'device' => shortUserAgent($v['user_agent'] ?? ''),
            'state' => 'playing',
            'progress' => $v['position_seconds'] ?? 0,
            'duration' => $meta['duration_secs'] ?? 0,
            'live' => false,
            'transcoding' => false,
            'transcode_details' => $details
        ];
    }

    return ['streams' => $streams];
}

$allStreams = [];
$errors = [];

$mh = curl_multi_init();
$handles = [];
$serverMap = [];

foreach ($servers as $index => $server) {
    if (isset($server['enabled']) && $server['enabled'] === '0') {
        continue;
    }
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

        $isLive = !empty($s['live']);
        $progressStr = formatTime($s['progress']);
        if ($isLive) {
            // Live TV has no duration; show time watched instead
            $timeDisplay = "$progressStr &middot; Live";
            $timeTooltip = htmlspecialchars('Watching for ' . formatTimeFull($s['progress']));
        } else {
            $durationStr = formatTime($s['duration']);
            $timeDisplay = "$progressStr / $durationStr";
            $timeTooltip = htmlspecialchars(formatTimeFull($s['progress']) . ' / ' . formatTimeFull($s['duration']));
        }

        $typeColors = [
            'plex' => '#e5a00d',
            'emby' => '#52b54b', 
            'jellyfin' => '#00a4dc',
            'dispatcharr' => '#14917e'
        ];
        $typeColor = $typeColors[$serverType] ?? '#888';
        
        $transcodeHtml = '';
        if ($s['transcoding']) {
            $transcodeTooltip = !empty($s['transcode_details'])
                ? htmlspecialchars(implode("\n", $s['transcode_details']))
                : 'Transcoding';
            $transcodeHtml = " <i class='fa fa-random as-transcode' style='color:#e5a00d; cursor:help;' title='$transcodeTooltip'></i>";
        } elseif ($serverType === 'dispatcharr' && !empty($s['transcode_details'])) {
            // Dispatcharr rows show source stream info even when not transcoding
            $infoTooltip = htmlspecialchars(implode("\n", $s['transcode_details']));
            $transcodeHtml = " <i class='fa fa-info-circle as-transcode' style='opacity:0.6; cursor:help;' title='$infoTooltip'></i>";
        }
        
        echo "<div class='as-row'>";
        
        echo "<span class='as-server' style='color:$typeColor;' title='$serverName ($serverType)'>
                <i class='fa fa-circle' style='font-size:8px;'></i>
              </span>";
        
        echo "<span class='as-name'><span class='as-name-text' title='$title'>$title</span></span>";
        
        if ($showDevice) echo "<span class='as-device' title='$device'>$device</span>";

        if ($showUser) echo "<span class='as-user' title='$user'>$user</span>";
        
        echo "<span class='as-time'>";
        echo $transcodeHtml;
        if ($showPlayStatus) {
            $statusText = $isLive ? "Live" : ($isPaused ? "Paused" : "Playing");
            echo "<span style='color:{$statusColor}; cursor:default;' title='{$timeTooltip}'>{$statusText}</span>";
        } else {
            echo "<span style='color:{$statusColor};'>{$timeDisplay}</span>";
        }
        echo "</span>";
        
        echo "</div>";
    }
}
?>
