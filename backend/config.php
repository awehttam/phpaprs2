<?php
/**
 * APRS-IS Configuration
 *
 * Configure your APRS-IS connection and application settings here.
 */

if(file_exists("config.local.php"))
    return require "config.local.php";

return [
    'aprs_is' => [
        'server' => 'rotate.aprs2.net',
        'port' => 14580,

        // Your callsign with SSID (e.g., 'N0CALL-99')
        // Use a unique SSID to avoid conflicts
        'callsign' => 'N0CALL-99',

        // Passcode for APRS-IS
        // Use '-1' for read-only access (no authentication required)
        // For full access, generate passcode at https://apps.magicbug.co.uk/passcode/
        'passcode' => '-1',

        // APRS-IS filter
        // Format: r/lat/lon/radius (radius in km)
        // Example: 'r/49.2488/-122.9805/100' for Burnaby, Canada area, 100km radius
        // Change this to your desired area
        'filter' => 'r/49.2488/-122.9805/100',

        // User agent identification
        'user_agent' => 'phpAPRS2 1.0',

        // Connection timeout in seconds
        'connect_timeout' => 30,

        // Read timeout in seconds
        'read_timeout' => 300,

        // Reconnect delay in seconds after connection loss
        'reconnect_delay' => 5,
    ],

    'memory' => [
        // Maximum number of stations to track
        // When exceeded, oldest stations will be removed (LRU)
        'max_stations' => 1000,

        // Maximum number of track points to keep per station
        'track_points' => 50,

        // Remove stations that haven't been heard in this many seconds
        'station_timeout' => 3600, // 1 hour

        // Minimum distance in meters for a position to be added to track
        // This prevents cluttering tracks with minor position updates
        'min_track_distance' => 50,
    ],

    'sse' => [
        // How often to send updates to clients (in seconds)
        'update_interval' => 2,

        // How often to send heartbeat/keep-alive (in seconds)
        'heartbeat_interval' => 30,
    ],

    'logging' => [
        // Enable debug logging
        'debug' => true,

        // Log file path (relative to backend directory)
        'log_file' => __DIR__ . '/aprs-debug.log',

        // Maximum log file size in bytes before rotation
        'max_log_size' => 10485760, // 10 MB
    ],

    'apcu' => [
        // APCu cache key for station data
        'stations_key' => 'aprs_stations',

        // APCu cache TTL in seconds
        'ttl' => 3600,
    ],
];
