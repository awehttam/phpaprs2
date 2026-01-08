<?php
/**
 * Message Manager
 *
 * Manages in-memory state for APRS text messages.
 * Handles message storage, filtering, and retrieval.
 */

class MessageManager
{
    private static $instance = null;
    private $messages = [];
    private $config;
    private $memcached = null;

    private function __construct($config = null)
    {
        $this->config = $config ?? require __DIR__ . '/config.php';
        $this->initializeCache();
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
     * Initialize cache backend (Memcached)
     */
    private function initializeCache(): void
    {
        $backend = $this->config['cache']['backend'] ?? 'file';

        if ($backend === 'memcached' && class_exists('Memcached')) {
            $this->memcached = new Memcached();
            $host = $this->config['cache']['memcached']['host'] ?? '127.0.0.1';
            $port = $this->config['cache']['memcached']['port'] ?? 11211;

            $this->memcached->addServer($host, $port);

            // Test connection
            $stats = @$this->memcached->getStats();
            if (empty($stats) || $stats === false) {
                $this->log("Memcached connection failed, falling back to file storage");
                $this->memcached = null;
            } else {
                $this->log("Memcached connection established ($host:$port)");
            }
        } elseif ($backend === 'memcached') {
            $this->log("Memcached extension not available, falling back to file storage");
        }
    }

    /**
     * Add a new message
     *
     * @param array $message Parsed APRS message data
     */
    public function addMessage(array $message): void
    {
        // Create message record
        $record = [
            'from' => $message['from'],
            'to' => $message['to'],
            'message' => $message['message'],
            'message_id' => $message['message_id'] ?? null,
            'timestamp' => $message['timestamp'],
        ];

        // Add to messages array
        $this->messages[] = $record;

        // Enforce message limit (prevent memory bloat during long sessions)
        $maxMessages = $this->config['memory']['max_messages'] ?? 500;
        if (count($this->messages) > $maxMessages) {
            // Remove oldest messages (FIFO)
            array_shift($this->messages);
            $this->log("Pruned oldest message (limit: $maxMessages)");
        }

        $this->log("Added message from {$record['from']} to {$record['to']}");
    }

    /**
     * Get all messages
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get messages since a specific timestamp
     */
    public function getMessagesSince(int $timestamp): array
    {
        return array_filter($this->messages, function($msg) use ($timestamp) {
            return $msg['timestamp'] > $timestamp;
        });
    }

    /**
     * Get message count
     */
    public function getMessageCount(): int
    {
        return count($this->messages);
    }

    /**
     * Clear all messages
     */
    public function clear(): void
    {
        $count = count($this->messages);
        $this->messages = [];
        $this->log("Cleared all messages ($count removed)");
    }

    /**
     * Save messages to cache
     */
    public function saveToCache(): bool
    {
        if ($this->memcached !== null) {
            $key = $this->config['cache']['messages_key'] ?? 'aprs_messages';
            $ttl = $this->config['cache']['ttl'] ?? 3600;

            $success = $this->memcached->set($key, $this->messages, $ttl);

            if ($success) {
                $this->log("Saved " . count($this->messages) . " messages to Memcached");
            } else {
                $this->log("Failed to save messages to Memcached: " . $this->memcached->getResultMessage());
                return $this->saveToFile();
            }

            return $success;
        }

        return $this->saveToFile();
    }

    /**
     * Load messages from cache
     */
    public function loadFromCache(): bool
    {
        if ($this->memcached !== null) {
            $key = $this->config['cache']['messages_key'] ?? 'aprs_messages';
            $data = $this->memcached->get($key);

            if ($this->memcached->getResultCode() === Memcached::RES_SUCCESS && is_array($data)) {
                $this->messages = $data;
                $this->log("Loaded " . count($this->messages) . " messages from Memcached");
                return true;
            }

            $this->log("Failed to load from Memcached: " . $this->memcached->getResultMessage());
        }

        return $this->loadFromFile();
    }

    /**
     * Save to file fallback
     */
    private function saveToFile(): bool
    {
        $file = __DIR__ . '/messages.json';
        $data = json_encode($this->messages, JSON_PRETTY_PRINT);

        if (file_put_contents($file, $data, LOCK_EX) !== false) {
            $this->log("Saved " . count($this->messages) . " messages to file");
            return true;
        }

        return false;
    }

    /**
     * Load from file fallback
     */
    private function loadFromFile(): bool
    {
        $file = __DIR__ . '/messages.json';

        if (file_exists($file)) {
            $data = file_get_contents($file);
            $this->messages = json_decode($data, true) ?? [];
            $this->log("Loaded " . count($this->messages) . " messages from file");
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
            $line = "[$timestamp] [MessageManager] $message\n";
            file_put_contents($logFile, $line, FILE_APPEND);
        }
    }
}
