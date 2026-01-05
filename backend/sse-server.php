<?php
/**
 * Server-Sent Events (SSE) Server
 *
 * Streams station updates to connected frontend clients in real-time.
 * Reads station data from shared memory (APCu or file) and pushes updates.
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

// Event ID counter
$eventId = 0;

// Last heartbeat time
$lastHeartbeat = time();

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

// Send initial connection event
logSSE("New SSE client connected");
sendEvent('connected', ['message' => 'Connected to APRS-IS stream', 'time' => time()], $eventId);

// Main SSE loop
while (true) {
    // Check if client disconnected
    if (connection_aborted()) {
        logSSE("Client disconnected");
        break;
    }

    // Load stations from cache
    $stationManager->loadFromCache();
    $stations = $stationManager->getStations();

    // Send station update
    sendEvent('stations', $stations, $eventId);

    logSSE("Sent " . count($stations) . " stations to client (event #$eventId)");

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
