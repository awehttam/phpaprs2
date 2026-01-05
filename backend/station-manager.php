<?php
/**
 * Station Manager
 *
 * Manages in-memory state for all tracked APRS stations.
 * Handles station updates, track management, and pruning.
 */

class StationManager
{
    private static $instance = null;
    private $stations = [];
    private $config;

    private function __construct($config = null)
    {
        $this->config = $config ?? require __DIR__ . '/config.php';
    }

    /**
     * Get singleton instance
     */
    public static function getInstance($config = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Update or add a station with new position data
     *
     * @param array $parsed Parsed APRS data
     */
    public function updateStation(array $parsed): void
    {
        $callsign = $parsed['callsign'];

        // Initialize station if new
        if (!isset($this->stations[$callsign])) {
            $this->stations[$callsign] = [
                'callsign' => $callsign,
                'first_seen' => time(),
                'track' => [],
                'latest' => null,
                'last_update' => 0,
            ];
            $this->log("New station: $callsign");
        }

        $station = &$this->stations[$callsign];
        $station['last_update'] = time();

        // Check if position changed significantly
        $positionChanged = $this->hasPositionChanged($station['latest'], $parsed);

        // Update latest position
        $station['latest'] = $parsed;

        // Add to track if position changed
        if ($positionChanged) {
            $trackPoint = [
                'lat' => $parsed['latitude'],
                'lng' => $parsed['longitude'],
                'timestamp' => $parsed['timestamp'],
            ];

            // Add altitude if available
            if (isset($parsed['altitude'])) {
                $trackPoint['altitude'] = $parsed['altitude'];
            }

            $station['track'][] = $trackPoint;

            // Limit track points
            $maxPoints = $this->config['memory']['track_points'] ?? 50;
            if (count($station['track']) > $maxPoints) {
                array_shift($station['track']);
            }

            $this->log("Updated track for $callsign (points: " . count($station['track']) . ")");
        }

        // Check if we need to prune stations
        $this->checkStationLimit();
    }

    /**
     * Check if position has changed significantly
     */
    private function hasPositionChanged(?array $previous, array $current): bool
    {
        if ($previous === null) {
            return true;
        }

        if (!isset($previous['latitude']) || !isset($previous['longitude'])) {
            return true;
        }

        // Calculate distance in meters
        $distance = $this->calculateDistance(
            $previous['latitude'],
            $previous['longitude'],
            $current['latitude'],
            $current['longitude']
        );

        $minDistance = $this->config['memory']['min_track_distance'] ?? 50;

        return $distance >= $minDistance;
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     *
     * @return float Distance in meters
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get all stations
     */
    public function getStations(): array
    {
        $this->pruneInactive();
        return $this->stations;
    }

    /**
     * Get a specific station by callsign
     */
    public function getStation(string $callsign): ?array
    {
        return $this->stations[$callsign] ?? null;
    }

    /**
     * Get number of tracked stations
     */
    public function getStationCount(): int
    {
        return count($this->stations);
    }

    /**
     * Remove inactive stations
     */
    private function pruneInactive(): void
    {
        $timeout = $this->config['memory']['station_timeout'] ?? 3600;
        $now = time();
        $removed = 0;

        foreach ($this->stations as $callsign => $station) {
            if (($now - $station['last_update']) > $timeout) {
                unset($this->stations[$callsign]);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->log("Pruned $removed inactive stations");
        }
    }

    /**
     * Check station limit and remove oldest if exceeded
     */
    private function checkStationLimit(): void
    {
        $maxStations = $this->config['memory']['max_stations'] ?? 1000;

        while (count($this->stations) > $maxStations) {
            $this->removeOldestStation();
        }
    }

    /**
     * Remove the oldest station (LRU eviction)
     */
    private function removeOldestStation(): void
    {
        $oldest = null;
        $oldestTime = PHP_INT_MAX;

        foreach ($this->stations as $callsign => $station) {
            if ($station['last_update'] < $oldestTime) {
                $oldestTime = $station['last_update'];
                $oldest = $callsign;
            }
        }

        if ($oldest !== null) {
            unset($this->stations[$oldest]);
            $this->log("Removed oldest station: $oldest (LRU eviction)");
        }
    }

    /**
     * Clear all stations
     */
    public function clear(): void
    {
        $count = count($this->stations);
        $this->stations = [];
        $this->log("Cleared all stations ($count removed)");
    }

    /**
     * Get memory usage statistics
     */
    public function getStats(): array
    {
        return [
            'station_count' => count($this->stations),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Save stations to APCu for sharing with SSE server
     */
    public function saveToCache(): bool
    {
        if (!function_exists('apcu_store')) {
            $this->log("APCu not available, using fallback file storage");
            return $this->saveToFile();
        }

        $key = $this->config['apcu']['stations_key'] ?? 'aprs_stations';
        $ttl = $this->config['apcu']['ttl'] ?? 3600;

        $success = apcu_store($key, serialize($this->stations), $ttl);

        if ($success) {
            $this->log("Saved " . count($this->stations) . " stations to APCu");
        } else {
            $this->log("Failed to save stations to APCu");
        }

        return $success;
    }

    /**
     * Load stations from APCu
     */
    public function loadFromCache(): bool
    {
        if (!function_exists('apcu_fetch')) {
            $this->log("APCu not available, using fallback file storage");
            return $this->loadFromFile();
        }

        $key = $this->config['apcu']['stations_key'] ?? 'aprs_stations';
        $data = apcu_fetch($key, $success);

        if ($success && $data !== false) {
            $this->stations = unserialize($data);
            $this->log("Loaded " . count($this->stations) . " stations from APCu");
            return true;
        }

        return false;
    }

    /**
     * Fallback: Save to file if APCu not available
     */
    private function saveToFile(): bool
    {
        $file = __DIR__ . '/stations.json';
        $data = json_encode($this->stations, JSON_PRETTY_PRINT);

        if (file_put_contents($file, $data, LOCK_EX) !== false) {
            $this->log("Saved " . count($this->stations) . " stations to file");
            return true;
        }

        return false;
    }

    /**
     * Fallback: Load from file if APCu not available
     */
    private function loadFromFile(): bool
    {
        $file = __DIR__ . '/stations.json';

        if (file_exists($file)) {
            $data = file_get_contents($file);
            $this->stations = json_decode($data, true) ?? [];
            $this->log("Loaded " . count($this->stations) . " stations from file");
            return true;
        }

        return false;
    }

    /**
     * Log debug message
     */
    private function log(string $message): void
    {
        if (!($this->config['logging']['debug'] ?? false)) {
            return;
        }

        $logFile = $this->config['logging']['log_file'] ?? null;
        if ($logFile) {
            $timestamp = date('Y-m-d H:i:s');
            $line = "[$timestamp] [StationManager] $message\n";
            file_put_contents($logFile, $line, FILE_APPEND);
        }
    }
}
