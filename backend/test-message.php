<?php
/**
 * Test script to inject an APRS message into the system
 *
 * Usage: php test-message.php
 */

require_once __DIR__ . '/aprs-parser.php';
require_once __DIR__ . '/station-manager.php';
require_once __DIR__ . '/message-manager.php';

// Load config
$config = require __DIR__ . '/config.php';

// Initialize parser, station manager, and message manager
$parser = new APRSParser($config);
$stationManager = StationManager::getInstance($config);
$messageManager = MessageManager::getInstance($config);

// Load existing data from cache
$stationManager->loadFromCache();
$messageManager->loadFromCache();
echo "Current station count: " . $stationManager->getStationCount() . "\n";
echo "Current message count: " . $messageManager->getMessageCount() . "\n";

// Get a real tracked station callsign for testing
$stations = $stationManager->getStations();
$trackedCallsign = null;
if (!empty($stations)) {
    $trackedCallsign = array_key_first($stations);
    echo "\nUsing tracked station for test: $trackedCallsign\n";
}

// Create test APRS message packets
// Format: CALLSIGN>DEST,PATH::ADDRESSEE:MESSAGE TEXT{msgid
$testPackets = [];

if ($trackedCallsign) {
    // Pad callsign to 9 characters for addressee field
    $paddedCallsign = str_pad($trackedCallsign, 9, ' ', STR_PAD_RIGHT);

    // Message FROM a tracked station (should be accepted)
    $testPackets[] = "$trackedCallsign>APRS,TCPIP*,qAC,SERVER::CQ       :Test message from tracked station{001";
    // Message TO a tracked station (should be accepted)
    $testPackets[] = "REMOTE>APRS,TCPIP*,qAC,SERVER::$paddedCallsign:Reply to tracked station{002";
}

// Message between two non-tracked stations (should be rejected)
$testPackets[] = "NOTHERE1>APRS,TCPIP*,qAC,SERVER::NOTHERE2 :This should be filtered out{003";

echo "\nInjecting " . count($testPackets) . " test messages...\n\n";

foreach ($testPackets as $packet) {
    echo "Parsing: $packet\n";

    // Parse the packet
    $parsed = $parser->parse($packet);

    if ($parsed === null) {
        echo "  ERROR: Failed to parse packet\n\n";
        continue;
    }

    // Validate the parsed data
    if (!$parser->validate($parsed)) {
        echo "  ERROR: Validation failed\n\n";
        continue;
    }

    // Check if it's a message
    if (isset($parsed['type']) && $parsed['type'] === 'message') {
        echo "  ✓ Parsed successfully\n";
        echo "  From: {$parsed['from']}\n";
        echo "  To: {$parsed['to']}\n";
        echo "  Message: {$parsed['message']}\n";
        echo "  Message ID: " . ($parsed['message_id'] ?? 'none') . "\n";

        // Check if message should be accepted (from/to tracked station)
        $fromStation = $stationManager->getStation($parsed['from']);
        $toStation = $stationManager->getStation($parsed['to']);

        if ($fromStation !== null || $toStation !== null) {
            $messageManager->addMessage($parsed);
            echo "  ✓ Added to MessageManager (from/to tracked station)\n\n";
        } else {
            echo "  ✗ Filtered out (neither from nor to is a tracked station)\n\n";
        }
    } else {
        echo "  ERROR: Not a message type\n\n";
    }
}

// Save messages to cache
echo "Saving messages to cache...\n";
$messageManager->saveToCache();

echo "New message count: " . $messageManager->getMessageCount() . "\n";
echo "\nTest messages injected successfully!\n";
echo "Refresh your browser to see them in the UI.\n";
