<?php
/**
 * APRS Packet Parser
 *
 * Parses APRS packets and extracts position, symbol, and metadata.
 * Supports multiple APRS position formats:
 * - Uncompressed position reports (!lat/lonS or @timestamp/lat/lonS)
 * - Compressed position reports
 * - MIC-E encoded positions
 * - Object reports (repeaters, events, etc.)
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

            case ':': // APRS message
                $parsed = $this->parseMessage($callsign, $data);
                break;

            default:
                $this->log("Unsupported data type: $dataType");
                return null;
        }

        if ($parsed === null) {
            $this->log("Failed to parse packet data");
            return null;
        }

        // Messages are handled differently - they're valid as-is
        if (isset($parsed['type']) && $parsed['type'] === 'message') {
            $parsed['raw_packet'] = $packet;
            $this->log("Successfully parsed message: " . json_encode($parsed));
            return $parsed;
        }

        // For position data, add callsign and timestamp
        // For objects, use object name as callsign
        if (isset($parsed['is_object']) && isset($parsed['object_name'])) {
            $parsed['callsign'] = $parsed['object_name'];
            $parsed['source_callsign'] = $callsign; // Original callsign that reported the object
        } else {
            $parsed['callsign'] = $callsign;
        }

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
     * Note: Spaces can be used instead of digits to represent zero
     */
    private function parseUncompressedPosition(string $data): ?array
    {
        // Check if compressed (starts with symbol table character /)
        // Compressed positions are more complex, we'll focus on uncompressed first

        // Uncompressed format: DDMM.mmN/DDDMM.mmWS
        // Pattern: All coordinate digits can be spaces (representing zero)
        // APRS spec allows spaces to represent zero in positions
        $pattern = '/^([\d ]{4}\.([\d ]{2}))([NS])(.)([\d ]{5}\.([\d ]{2}))([EW])(.)(.*)$/';

        if (!preg_match($pattern, $data, $matches)) {
            $this->log("Failed to match uncompressed position format: $data");
            return null;
        }

        $lat = $matches[1];
        $latDir = $matches[3];
        $symbolTable = $matches[4];
        $lon = $matches[5];
        $lonDir = $matches[7];
        $symbolCode = $matches[8];
        $comment = $matches[9] ?? '';

        // Replace spaces with zeros in coordinates
        $lat = str_replace(' ', '0', $lat);
        $lon = str_replace(' ', '0', $lon);

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

        // Parse weather data (APRS weather report format)
        // Format: _DDMMHHHTTT.VVVGGGTTT...
        // Where: c=wind dir (degrees), s=wind speed (mph), g=gust, t=temp (F), r=rain, etc.
        // More common format in comment: c123s045g050t072r...p...P...h50b10201
        $weather = [];

        // Wind direction (c = course, 000-360 degrees)
        if (preg_match('/c(\d{3})/', $comment, $matches)) {
            $weather['wind_direction'] = intval($matches[1]);
        }

        // Wind speed (s = sustained, mph)
        if (preg_match('/s(\d{3})/', $comment, $matches)) {
            $weather['wind_speed'] = intval($matches[1]);
        }

        // Wind gust (g = gust, mph)
        if (preg_match('/g(\d{3})/', $comment, $matches)) {
            $weather['wind_gust'] = intval($matches[1]);
        }

        // Temperature (t = temperature, Fahrenheit, may be negative with leading -)
        if (preg_match('/t(-?\d{3})/', $comment, $matches)) {
            $tempF = intval($matches[1]);
            $weather['temperature_f'] = $tempF;
            $weather['temperature_c'] = round(($tempF - 32) * 5 / 9, 1);
        }

        // Humidity (h = humidity, 00-99%)
        if (preg_match('/h(\d{2})/', $comment, $matches)) {
            $humidity = intval($matches[1]);
            $weather['humidity'] = ($humidity == 0) ? 100 : $humidity; // 00 means 100%
        }

        // Barometric pressure (b = barometric, 0.1 mbar)
        if (preg_match('/b(\d{5})/', $comment, $matches)) {
            $weather['pressure'] = intval($matches[1]) / 10; // Convert to mbar
        }

        // Rainfall (last hour, last 24h, since midnight)
        if (preg_match('/r(\d{3})/', $comment, $matches)) {
            $weather['rain_1h'] = intval($matches[1]) / 100; // in inches
        }
        if (preg_match('/p(\d{3})/', $comment, $matches)) {
            $weather['rain_24h'] = intval($matches[1]) / 100; // in inches
        }

        // If we found weather data, mark this as a weather station
        if (!empty($weather)) {
            $result['weather'] = $weather;
        }
    }

    /**
     * Parse MIC-E encoded position
     * MIC-E encodes latitude in destination address and longitude/speed/course in data
     */
    private function parseMicE(string $callsign, string $path, string $data): ?array
    {
        // Extract destination address (first field in path before comma)
        $destination = explode(',', $path)[0];

        if (strlen($destination) < 6) {
            $this->log("MIC-E destination too short: $destination");
            return null;
        }

        // Decode latitude from destination address (first 6 characters)
        $latResult = $this->decodeMicELatitude(substr($destination, 0, 6));

        if ($latResult === null) {
            $this->log("Failed to decode MIC-E latitude from: " . substr($destination, 0, 6));
            return null;
        }

        ['latitude' => $latitude, 'longitude_offset' => $lonOffset, 'ns' => $ns] = $latResult;

        // Remove data type identifier (` or ')
        $data = substr($data, 1);

        // MIC-E information field format (minimum 8 bytes):
        // d+28, m+28, h+28, SP+28, DC+28, SE+28, symbol_code, symbol_table
        if (strlen($data) < 8) {
            $this->log("MIC-E data too short");
            return null;
        }

        // Decode longitude
        $lonResult = $this->decodeMicELongitude($data, $lonOffset);

        if ($lonResult === null) {
            $this->log("Failed to decode MIC-E longitude");
            return null;
        }

        ['longitude' => $longitude, 'speed' => $speed, 'course' => $course] = $lonResult;

        // Apply N/S to latitude
        if ($ns === 'S') {
            $latitude *= -1;
        }

        // Extract symbol (byte 6 = symbol code, byte 7 = symbol table)
        $symbolCode = $data[6];
        $symbolTable = $data[7];

        $result = [
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
            'symbol_table' => $symbolTable,
            'symbol_code' => $symbolCode,
            'speed' => $speed,
            'course' => $course,
        ];

        // Parse remaining data as comment (after byte 8)
        $comment = substr($data, 8);
        if (!empty($comment)) {
            $result['comment'] = trim($comment);

            // Check for altitude in comment (}ABC format where ABC are base-91 chars)
            // Valid base-91 characters are ! through { (ASCII 33-123)
            if (preg_match('/}([!-{]{3})/', $comment, $matches)) {
                // Base-91 altitude encoding
                $result['altitude'] = $this->decodeBase91Altitude($matches[1]);
            }
        }

        $this->log("Parsed MIC-E: lat={$result['latitude']}, lon={$result['longitude']}, spd=$speed, crs=$course");

        return $result;
    }

    /**
     * Decode latitude from MIC-E destination address
     * Returns ['latitude' => float, 'longitude_offset' => int, 'ns' => 'N'|'S']
     */
    private function decodeMicELatitude(string $dest): ?array
    {
        $latDigits = '';
        $messageBits = [];
        $longitudeOffset = 0;
        $ns = 'N';

        // Decode each character
        for ($i = 0; $i < 6; $i++) {
            $ch = $dest[$i];
            $ascii = ord($ch);

            // Determine digit and message bit
            if ($ascii >= ord('0') && $ascii <= ord('9')) {
                // 0-9: digit = character, bit = 0
                $latDigits .= $ch;
                $messageBits[] = 0;
            } elseif ($ascii >= ord('A') && $ascii <= ord('J')) {
                // A-J: digit = 0-9, bit = 1
                $latDigits .= chr(ord('0') + ($ascii - ord('A')));
                $messageBits[] = 1;
            } elseif ($ascii >= ord('P') && $ascii <= ord('Y')) {
                // P-Y: digit = 0-9, bit = 1
                $latDigits .= chr(ord('0') + ($ascii - ord('P')));
                $messageBits[] = 1;
            } elseif ($ch === 'K' || $ch === 'L' || $ch === 'Z') {
                // K, L, Z: space character
                $latDigits .= ' ';
                $messageBits[] = ($ch === 'L') ? 0 : 1;
            } else {
                $this->log("Invalid MIC-E character: $ch");
                return null;
            }
        }

        // Parse latitude: DDMM.HH format (space = 0 for missing digits)
        $latDigits = str_replace(' ', '0', $latDigits);

        // Extract degrees and minutes
        $degrees = intval(substr($latDigits, 0, 2));
        $minutes = floatval(substr($latDigits, 2, 2) . '.' . substr($latDigits, 4, 2));

        $latitude = $degrees + ($minutes / 60);

        // Decode message bits
        // Bit 3 (index 3): N/S (0=South, 1=North)
        $ns = $messageBits[3] ? 'N' : 'S';

        // Bit 4 (index 4): Longitude offset (0=+0, 1=+100)
        $longitudeOffset = $messageBits[4] ? 100 : 0;

        // Bit 5 (index 5): W/E (0=East, 1=West)
        $we = $messageBits[5] ? 'W' : 'E';

        // For East longitude, we need to handle it differently
        if ($we === 'E') {
            $longitudeOffset = -100; // Signal East hemisphere
        }

        return [
            'latitude' => $latitude,
            'longitude_offset' => $longitudeOffset,
            'ns' => $ns
        ];
    }

    /**
     * Decode longitude, speed, and course from MIC-E information field
     */
    private function decodeMicELongitude(string $data, int $lonOffset): ?array
    {
        // Extract first 6 bytes as ASCII values
        $ch = ord($data[0]);
        $m = ord($data[1]) - 28;
        $h = ord($data[2]) - 28;
        $sp = ord($data[3]) - 28;
        $dc = ord($data[4]) - 28;
        $se = ord($data[5]) - 28;

        // Decode longitude degrees using direwolf algorithm
        // The offset flag (from destination) determines which range to use
        $offset = ($lonOffset === 100) ? 1 : 0;

        if ($offset && $ch >= 118 && $ch <= 127) {
            // offset=1, range 118-127: 0-9 degrees
            $lonDeg = $ch - 118;
        } elseif (!$offset && $ch >= 38 && $ch <= 127) {
            // offset=0, range 38-127: 10-99 degrees
            $lonDeg = ($ch - 38) + 10;
        } elseif ($offset && $ch >= 108 && $ch <= 117) {
            // offset=1, range 108-117: 100-109 degrees
            $lonDeg = ($ch - 108) + 100;
        } elseif ($offset && $ch >= 38 && $ch <= 107) {
            // offset=1, range 38-107: 110-179 degrees
            $lonDeg = ($ch - 38) + 110;
        } else {
            $this->log("Invalid MIC-E longitude byte: $ch");
            return null;
        }

        // Decode longitude minutes
        $lonMin = $m;
        if ($lonMin >= 60) {
            $lonMin -= 60;
        }

        // Decode hundredths of minutes
        $lonHundredths = $h;

        $longitude = $lonDeg + ($lonMin / 60) + ($lonHundredths / 6000);

        // Apply West/East
        if ($lonOffset !== -100) {
            // West
            $longitude *= -1;
        }

        // Decode speed (in knots)
        $speed = ($sp * 10) + floor($dc / 10);
        if ($speed >= 800) {
            $speed -= 800;
        }

        // Decode course
        $course = (($dc % 10) * 100) + $se;
        if ($course >= 400) {
            $course -= 400;
        }

        // Handle course special cases
        if ($course == 360) {
            $course = 0; // North
        }

        return [
            'longitude' => $longitude,
            'speed' => $speed,
            'course' => $course
        ];
    }

    /**
     * Decode base-91 altitude (3 characters)
     * Returns altitude in meters
     */
    private function decodeBase91Altitude(string $alt): int
    {
        if (strlen($alt) !== 3) {
            return 0;
        }

        $a1 = ord($alt[0]) - 33;
        $a2 = ord($alt[1]) - 33;
        $a3 = ord($alt[2]) - 33;

        // Base-91 encoding with 10000m offset
        // Result is already in meters
        $altMeters = ($a1 * 91 * 91) + ($a2 * 91) + $a3 - 10000;

        return round($altMeters);
    }

    /**
     * Parse object report
     * Format: ;OBJECTNAM*DDHHMMz[position][comment]
     * OBJECTNAM is 9 characters (space-padded)
     * * = live object, _ = killed object
     */
    private function parseObject(string $data): ?array
    {
        // Remove data type identifier (;)
        $data = substr($data, 1);

        // Check minimum length (9 chars name + 1 status + 7 timestamp + position)
        if (strlen($data) < 20) {
            $this->log("Object data too short");
            return null;
        }

        // Extract object name (first 9 characters)
        $objectName = substr($data, 0, 9);
        $objectName = rtrim($objectName); // Remove trailing spaces

        // Extract status (* = live, _ = killed)
        $status = substr($data, 9, 1);

        // Skip killed objects (they're being removed from the map)
        if ($status === '_') {
            $this->log("Skipping killed object: $objectName");
            return null;
        }

        if ($status !== '*') {
            $this->log("Invalid object status: $status");
            return null;
        }

        // Skip timestamp (7 characters: DDHHMMZ)
        $positionData = substr($data, 17);

        // Parse the position data using existing method
        $parsed = $this->parseUncompressedPosition($positionData);

        if ($parsed === null) {
            return null;
        }

        // Mark this as an object and use object name as callsign
        $parsed['is_object'] = true;
        $parsed['object_name'] = $objectName;

        $this->log("Parsed object: $objectName");

        return $parsed;
    }

    /**
     * Parse APRS message packet
     * Format: :ADDRESSEE:MESSAGE TEXT{msgid
     *
     * @param string $callsign Source callsign
     * @param string $data Message data (including leading :)
     * @return array|null Parsed message or null if invalid/telemetry
     */
    private function parseMessage(string $callsign, string $data): ?array
    {
        // Remove data type identifier (:)
        $data = substr($data, 1);

        // Message format: ADDRESSEE:MESSAGE{id
        // Addressee is 9 characters (right-padded with spaces)
        if (strlen($data) < 11) { // 9 chars addressee + : + at least 1 char message
            $this->log("Message data too short: $data");
            return null;
        }

        // Extract addressee (first 9 characters)
        $addressee = substr($data, 0, 9);

        // Check for separator colon at position 9
        if ($data[9] !== ':') {
            $this->log("Missing message separator at position 9");
            return null;
        }

        // Extract message text (everything after position 10)
        $messageText = substr($data, 10);

        // Clean addressee (remove trailing spaces)
        $addressee = rtrim($addressee);

        // Filter out telemetry messages (PARM., UNIT., EQNS., BITS.)
        // These are technical data, not general text messages
        $telemetryPrefixes = ['PARM.', 'UNIT.', 'EQNS.', 'BITS.'];
        foreach ($telemetryPrefixes as $prefix) {
            if (strpos($messageText, $prefix) === 0) {
                $this->log("Filtered telemetry message: $prefix");
                return null;
            }
        }

        // Extract message ID if present (format: {msgid where msgid is 1-5 alphanumeric)
        $messageId = null;
        if (preg_match('/\{([A-Za-z0-9]{1,5})$/', $messageText, $matches)) {
            $messageId = $matches[1];
            // Remove message ID from text
            $messageText = substr($messageText, 0, -strlen($matches[0]));
        }

        // Return parsed message data
        return [
            'type' => 'message',
            'from' => $callsign,
            'to' => $addressee,
            'message' => trim($messageText),
            'message_id' => $messageId,
            'timestamp' => time()
        ];
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
        // Messages have different validation rules
        if (isset($parsed['type']) && $parsed['type'] === 'message') {
            return isset($parsed['from']) &&
                   isset($parsed['to']) &&
                   isset($parsed['message']);
        }

        // Check required fields for position data
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
