<?php
/**
 * APRS Packet Parser
 *
 * Parses APRS packets and extracts position, symbol, and metadata.
 * Supports multiple APRS position formats:
 * - Uncompressed position reports (!lat/lonS or @timestamp/lat/lonS)
 * - Compressed position reports
 * - MIC-E encoded positions
 */

class APRSParser
{
    private $config;
    private $debugLog;

    public function __construct($config = null)
    {
        $this->config = $config ?? require __DIR__ . '/config.php';
        $this->debugLog = $this->config['logging']['debug'] ?? false;
    }

    /**
     * Parse an APRS packet and extract position data
     *
     * @param string $packet Raw APRS packet
     * @return array|null Parsed data or null if unable to parse
     */
    public function parse(string $packet): ?array
    {
        $packet = trim($packet);

        // Ignore comments and server messages
        if (empty($packet) || $packet[0] === '#') {
            return null;
        }

        $this->log("Parsing packet: $packet");

        // Extract callsign, path, and data
        // Format: CALLSIGN>DESTINATION,PATH:DATA
        if (!preg_match('/^([A-Z0-9-]+)>([^:]+):(.+)$/i', $packet, $matches)) {
            $this->log("Failed to match packet format");
            return null;
        }

        $callsign = $matches[1];
        $path = $matches[2];
        $data = $matches[3];

        $this->log("Callsign: $callsign, Path: $path, Data: $data");

        // Determine packet type by first character of data
        $dataType = $data[0];

        $parsed = null;

        switch ($dataType) {
            case '!': // Position without timestamp (no messaging)
            case '=': // Position without timestamp (with messaging)
                $parsed = $this->parsePositionNoTimestamp($data);
                break;

            case '@': // Position with timestamp (with messaging)
            case '/': // Position with timestamp (no messaging)
                $parsed = $this->parsePositionWithTimestamp($data);
                break;

            case '\'': // MIC-E (old style)
            case '`': // MIC-E (current style)
                $parsed = $this->parseMicE($callsign, $path, $data);
                break;

            case ';': // Object report
                $parsed = $this->parseObject($data);
                break;

            default:
                $this->log("Unsupported data type: $dataType");
                return null;
        }

        if ($parsed === null) {
            $this->log("Failed to parse position data");
            return null;
        }

        // Add callsign and timestamp
        $parsed['callsign'] = $callsign;
        $parsed['timestamp'] = time();
        $parsed['raw_packet'] = $packet;

        $this->log("Successfully parsed: " . json_encode($parsed));

        return $parsed;
    }

    /**
     * Parse position without timestamp (! or =)
     */
    private function parsePositionNoTimestamp(string $data): ?array
    {
        // Remove data type identifier
        $data = substr($data, 1);

        return $this->parseUncompressedPosition($data);
    }

    /**
     * Parse position with timestamp (@ or /)
     */
    private function parsePositionWithTimestamp(string $data): ?array
    {
        // Remove data type identifier
        $data = substr($data, 1);

        // Extract timestamp (DHM or HMS format, 7 characters)
        // We'll skip timestamp parsing for now and just extract position
        if (strlen($data) < 7) {
            return null;
        }

        // Skip timestamp
        $data = substr($data, 7);

        return $this->parseUncompressedPosition($data);
    }

    /**
     * Parse uncompressed position format
     * Format: DDMM.mmN/DDDMM.mmWS or compressed
     */
    private function parseUncompressedPosition(string $data): ?array
    {
        // Check if compressed (starts with symbol table character /)
        // Compressed positions are more complex, we'll focus on uncompressed first

        // Uncompressed format: DDMM.mmN/DDDMM.mmWS
        // Pattern: 4 digits, decimal, 2 digits, N/S, symbol, 5 digits, decimal, 2 digits, E/W, symbol
        $pattern = '/^(\d{4}\.\d{2})([NS])(.)(\d{5}\.\d{2})([EW])(.)(.*)$/';

        if (!preg_match($pattern, $data, $matches)) {
            $this->log("Failed to match uncompressed position format: $data");
            return null;
        }

        $lat = $matches[1];
        $latDir = $matches[2];
        $symbolTable = $matches[3];
        $lon = $matches[4];
        $lonDir = $matches[5];
        $symbolCode = $matches[6];
        $comment = $matches[7] ?? '';

        // Convert coordinates to decimal degrees
        $latitude = $this->convertToDecimal($lat, $latDir, false);
        $longitude = $this->convertToDecimal($lon, $lonDir, true);

        if ($latitude === null || $longitude === null) {
            $this->log("Failed to convert coordinates");
            return null;
        }

        $result = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'symbol_table' => $symbolTable,
            'symbol_code' => $symbolCode,
        ];

        // Parse comment for additional data (altitude, speed, course)
        $this->parseComment($comment, $result);

        return $result;
    }

    /**
     * Convert APRS coordinate format (DDMM.mm) to decimal degrees
     *
     * @param string $coord Coordinate string (DDMM.mm or DDDMM.mm)
     * @param string $dir Direction (N/S/E/W)
     * @param bool $isLongitude True if longitude (3-digit degrees)
     * @return float|null Decimal degrees or null on error
     */
    private function convertToDecimal(string $coord, string $dir, bool $isLongitude): ?float
    {
        $degreeLength = $isLongitude ? 3 : 2;

        // Extract degrees and minutes
        $degrees = intval(substr($coord, 0, $degreeLength));
        $minutes = floatval(substr($coord, $degreeLength));

        // Validate ranges
        if ($degrees < 0 || $degrees > ($isLongitude ? 180 : 90)) {
            return null;
        }

        if ($minutes < 0 || $minutes >= 60) {
            return null;
        }

        // Convert to decimal
        $decimal = $degrees + ($minutes / 60);

        // Apply direction
        if ($dir === 'S' || $dir === 'W') {
            $decimal *= -1;
        }

        return round($decimal, 6);
    }

    /**
     * Parse comment field for additional data
     */
    private function parseComment(string $comment, array &$result): void
    {
        $result['comment'] = trim($comment);

        // Parse course and speed (CSE/SPD format: 123/045)
        if (preg_match('/(\d{3})\/(\d{3})/', $comment, $matches)) {
            $result['course'] = intval($matches[1]);
            $result['speed'] = intval($matches[2]);
        }

        // Parse altitude (format: /A=001234 in feet)
        if (preg_match('/\/A=(-?\d{6})/', $comment, $matches)) {
            $altitudeFeet = intval($matches[1]);
            $result['altitude'] = round($altitudeFeet * 0.3048); // Convert to meters
        }
    }

    /**
     * Parse MIC-E encoded position
     * MIC-E is complex and encodes position in destination field
     */
    private function parseMicE(string $callsign, string $path, string $data): ?array
    {
        // MIC-E parsing is quite complex and would require decoding
        // the destination field. For now, we'll return null
        // This can be implemented later if needed
        $this->log("MIC-E parsing not yet implemented");
        return null;
    }

    /**
     * Parse object report
     */
    private function parseObject(string $data): ?array
    {
        // Object format: ;NAME     *DDHHMM/lat/lonS...
        // For now, we'll skip object parsing
        $this->log("Object parsing not yet implemented");
        return null;
    }

    /**
     * Log debug message
     */
    private function log(string $message): void
    {
        if (!$this->debugLog) {
            return;
        }

        $logFile = $this->config['logging']['log_file'] ?? null;
        if ($logFile) {
            $timestamp = date('Y-m-d H:i:s');
            $line = "[$timestamp] [Parser] $message\n";
            file_put_contents($logFile, $line, FILE_APPEND);

            // Check log size and rotate if needed
            if (filesize($logFile) > ($this->config['logging']['max_log_size'] ?? 10485760)) {
                rename($logFile, $logFile . '.' . time());
            }
        }
    }

    /**
     * Validate parsed position data
     */
    public function validate(array $parsed): bool
    {
        // Check required fields
        if (!isset($parsed['callsign']) || !isset($parsed['latitude']) || !isset($parsed['longitude'])) {
            return false;
        }

        // Validate coordinates
        if ($parsed['latitude'] < -90 || $parsed['latitude'] > 90) {
            return false;
        }

        if ($parsed['longitude'] < -180 || $parsed['longitude'] > 180) {
            return false;
        }

        return true;
    }
}
