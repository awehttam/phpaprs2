<?php
/**
 * APRS-IS Client Daemon
 *
 * Connects to APRS-IS network, receives packets, parses them,
 * and maintains station state in memory.
 *
 * Run this as a long-running daemon:
 * php aprs-is-client.php
 */

// Include dependencies
require_once __DIR__ . '/aprs-parser.php';
require_once __DIR__ . '/station-manager.php';
require_once __DIR__ . '/message-manager.php';

class APRSISClient
{
    private $config;
    private $socket;
    private $parser;
    private $stationManager;
    private $messageManager;
    private $running = true;
    private $connected = false;
    private $packetsReceived = 0;
    private $packetsParsed = 0;
    private $startTime;

    public function __construct()
    {
        $this->config = require __DIR__ . '/config.php';
        $this->parser = new APRSParser($this->config);
        $this->stationManager = StationManager::getInstance($this->config);
        $this->messageManager = MessageManager::getInstance($this->config);
        $this->startTime = time();

        // Load previously cached stations and messages on startup
        $this->stationManager->loadFromCache();
        $this->messageManager->loadFromCache();

        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }

        // Set unlimited execution time
        set_time_limit(0);
        ini_set('max_execution_time', 0);

        $this->log("APRS-IS Client initialized");
        $this->log("Filter: " . $this->config['aprs_is']['filter']);
    }

    /**
     * Main run loop
     */
    public function run(): void
    {
        $this->log("Starting APRS-IS client...");

        while ($this->running) {
            try {
                if (!$this->connected) {
                    $this->connect();
                }

                if ($this->connected) {
                    $this->processPackets();
                }
            } catch (Exception $e) {
                $this->log("Error: " . $e->getMessage());
                $this->disconnect();
                $this->sleep($this->config['aprs_is']['reconnect_delay']);
            }

            // Process signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->disconnect();
        $this->log("APRS-IS client stopped");
    }

    /**
     * Connect to APRS-IS server
     */
    private function connect(): void
    {
        $server = $this->config['aprs_is']['server'];
        $port = $this->config['aprs_is']['port'];
        $timeout = $this->config['aprs_is']['connect_timeout'];

        $this->log("Connecting to $server:$port...");

        $address = "tcp://$server:$port";
        $this->socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($this->socket === false) {
            throw new Exception("Failed to connect: $errstr ($errno)");
        }

        // Set socket options
        stream_set_timeout($this->socket, $this->config['aprs_is']['read_timeout']);
        stream_set_blocking($this->socket, true);

        $this->log("Connected to APRS-IS");

        // Send login sequence
        $this->login();

        $this->connected = true;
    }

    /**
     * Send login sequence to APRS-IS
     */
    private function login(): void
    {
        $callsign = $this->config['aprs_is']['callsign'];
        $passcode = $this->config['aprs_is']['passcode'];
        $version = $this->config['aprs_is']['user_agent'];
        $filter = $this->config['aprs_is']['filter'];

        // Login format: user CALLSIGN pass PASSCODE vers SOFTWARE VERSION filter FILTER
        $login = "user $callsign pass $passcode vers $version filter $filter\r\n";

        $this->log("Logging in as $callsign...");
        fwrite($this->socket, $login);

        // Read server response
        $response = fgets($this->socket);
        $this->log("Server response: " . trim($response));

        // Read additional server messages (typically version info and login confirmation)
        while ($line = fgets($this->socket)) {
            $line = trim($line);
            if (empty($line)) {
                break;
            }

            $this->log("Server: $line");

            // Check for successful login
            if (strpos($line, 'verified') !== false || strpos($line, 'unverified') !== false) {
                $this->log("Login successful!");
                break;
            }
        }
    }

    /**
     * Process incoming packets
     */
    private function processPackets(): void
    {
        static $lastSave = 0;
        static $lastStats = 0;

        while ($this->running && $this->connected) {
            $line = fgets($this->socket);

            if ($line === false) {
                // Check if socket is still connected
                $meta = stream_get_meta_data($this->socket);
                if ($meta['eof'] || $meta['timed_out']) {
                    throw new Exception("Connection lost");
                }
                continue;
            }

            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Ignore server messages (start with #)
            if ($line[0] === '#') {
                continue;
            }

            $this->packetsReceived++;

            // Parse packet
            $parsed = $this->parser->parse($line);

            if ($parsed !== null && $this->parser->validate($parsed)) {
                $this->packetsParsed++;

                // Check if this is a message or position data
                if (isset($parsed['type']) && $parsed['type'] === 'message') {
                    // Only add messages from/to tracked stations
                    $fromStation = $this->stationManager->getStation($parsed['from']);
                    $toStation = $this->stationManager->getStation($parsed['to']);

                    if ($fromStation !== null || $toStation !== null) {
                        $this->messageManager->addMessage($parsed);
                    }
                } else {
                    $this->stationManager->updateStation($parsed);
                }
            }

            // Save to cache periodically (every 5 seconds)
            $now = time();
            if ($now - $lastSave >= 5) {
                $this->stationManager->saveToCache();
                $this->messageManager->saveToCache();
                $lastSave = $now;
            }

            // Print stats periodically (every 60 seconds)
            if ($now - $lastStats >= 60) {
                $this->printStats();
                $lastStats = $now;
            }

            // Process signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Disconnect from APRS-IS
     */
    private function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
        $this->log("Disconnected from APRS-IS");
    }

    /**
     * Graceful shutdown
     */
    public function shutdown(): void
    {
        $this->log("Shutdown signal received");
        $this->running = false;
        $this->disconnect();
    }

    /**
     * Print statistics
     */
    private function printStats(): void
    {
        $uptime = time() - $this->startTime;
        $stats = $this->stationManager->getStats();

        $message = sprintf(
            "Stats - Uptime: %s | Packets: %d received, %d parsed (%.1f%%) | Stations: %d | Memory: %s",
            $this->formatDuration($uptime),
            $this->packetsReceived,
            $this->packetsParsed,
            $this->packetsReceived > 0 ? ($this->packetsParsed / $this->packetsReceived * 100) : 0,
            $stats['station_count'],
            $this->formatBytes($stats['memory_usage'])
        );

        $this->log($message);
        echo $message . "\n";
    }

    /**
     * Format duration in human-readable format
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
    }

    /**
     * Format bytes in human-readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return sprintf("%.2f %s", $bytes, $units[$index]);
    }

    /**
     * Sleep for specified seconds
     */
    private function sleep(int $seconds): void
    {
        $this->log("Sleeping for $seconds seconds...");
        sleep($seconds);
    }

    /**
     * Log message
     */
    private function log(string $message): void
    {
        $logFile = $this->config['logging']['log_file'] ?? null;

        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] [Client] $message\n";

        if ($logFile) {
            file_put_contents($logFile, $line, FILE_APPEND);

            // Check log size and rotate if needed
            if (file_exists($logFile) && filesize($logFile) > ($this->config['logging']['max_log_size'] ?? 10485760)) {
                rename($logFile, $logFile . '.' . time());
            }
        }
    }
}

// Run the client
if (php_sapi_name() === 'cli') {
    echo "====================================\n";
    echo "   APRS-IS Client for phpAPRS2     \n";
    echo "====================================\n\n";

    $client = new APRSISClient();
    $client->run();
} else {
    die("This script must be run from the command line\n");
}
