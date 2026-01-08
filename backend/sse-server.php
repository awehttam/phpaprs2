<?php
/**
 * Server-Sent Events (SSE) Server
 *
 * Streams station updates to connected frontend clients in real-time.
 * Reads station data from shared cache (Memcached or file) and pushes updates.
 *
 * Access this endpoint from the frontend to receive real-time updates.
 */

// Include dependencies
require_once __DIR__ . '/station-manager.php';

// Set headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Prevent timeout
set_time_limit(0);
ini_set('max_execution_time', 0);

// Disable output buffering
if (ob_get_level()) {
    ob_end_clean();
}

// Load configuration
$config = require __DIR__ . '/config.php';

// Create station manager instance
$stationManager = StationManager::getInstance($config);

// Get update interval from config
$updateInterval = $config['sse']['update_interval'] ?? 2;
$heartbeatInterval = $config['sse']['heartbeat_interval'] ?? 30;
$stationTimeout = $config['memory']['station_timeout'] ?? 3600;

// Event ID counter
$eventId = 0;

// Last heartbeat time
$lastHeartbeat = time();

// Track last sent state for delta updates
$lastSentStations = [];

// Log function
function logSSE($message) {
    global $config;
    if (!($config['logging']['debug'] ?? false)) {
        return;
    }

    $logFile = $config['logging']['log_file'] ?? null;
    if ($logFile) {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] [SSE] $message\n";
        file_put_contents($logFile, $line, FILE_APPEND);
    }
}

// Send SSE event
function sendEvent($event, $data, &$eventId) {
    echo "id: " . ++$eventId . "\n";
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";

    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

// Detect changed stations for delta updates
function getChangedStations($currentStations, &$lastSentStations, $stationTimeout) {
    $changed = [];

    // Check for new or updated stations
    foreach ($currentStations as $callsign => $station) {
        // New station
        if (!isset($lastSentStations[$callsign])) {
            $changed[$callsign] = $station;
            continue;
        }

        // Check if station data changed
        // Compare last_update timestamp for efficiency
        if ($station['last_update'] !== $lastSentStations[$callsign]['last_update']) {
            $changed[$callsign] = $station;
        }
    }

    // Check for removed stations
    $removed = [];
    foreach ($lastSentStations as $callsign => $lastSentStation) {
        if (!isset($currentStations[$callsign])) {
            // Station is no longer in current data - mark as removed
            $removed[] = $callsign;
        }
    }

    return [
        'updated' => $changed,
        'removed' => $removed
    ];
}

// Send initial connection event
logSSE("New SSE client connected");
sendEvent('connected', ['message' => 'Connected to APRS-IS stream', 'time' => time()], $eventId);

// Flag to track initial load
$isInitialLoad = true;

// Main SSE loop
while (true) {
    // Check if client disconnected
    if (connection_aborted()) {
        logSSE("Client disconnected");
        break;
    }

    // Load stations from cache
    $loadSuccess = $stationManager->loadFromCache();
    $stations = $stationManager->getStations();

    // Validate loaded data - if empty and we had stations before, cache might be corrupted
    if (empty($stations) && !empty($lastSentStations) && !$isInitialLoad) {
        logSSE("WARNING: Cache returned empty stations but we have " . count($lastSentStations) . " known stations. Skipping update to prevent data loss.");
        // Don't update - keep previous state
        sleep($updateInterval);
        continue;
    }

    // On initial connection, send all stations
    if ($isInitialLoad) {
        sendEvent('stations', $stations, $eventId);
        logSSE("Sent initial " . count($stations) . " stations to client (event #$eventId)");
        $lastSentStations = $stations;
        $isInitialLoad = false;
    } else {
        // Send only changed stations (delta update)
        $delta = getChangedStations($stations, $lastSentStations, $stationTimeout);

        if (!empty($delta['updated']) || !empty($delta['removed'])) {
            sendEvent('stations_delta', $delta, $eventId);
            logSSE("Sent delta: " . count($delta['updated']) . " updated, " . count($delta['removed']) . " removed (event #$eventId)");

            // Update last sent state
            foreach ($delta['updated'] as $callsign => $station) {
                $lastSentStations[$callsign] = $station;
            }
            foreach ($delta['removed'] as $callsign) {
                unset($lastSentStations[$callsign]);
            }
        }
    }

    // Send heartbeat if needed
    $now = time();
    if ($now - $lastHeartbeat >= $heartbeatInterval) {
        $stats = $stationManager->getStats();
        sendEvent('heartbeat', [
            'time' => $now,
            'station_count' => $stats['station_count'],
            'memory_usage' => $stats['memory_usage'],
        ], $eventId);

        $lastHeartbeat = $now;
        logSSE("Sent heartbeat");
    }

    // Sleep before next update
    sleep($updateInterval);
}

logSSE("SSE connection closed");
