<?php
/**
 * APRS Symbol Mapper
 *
 * Maps APRS symbol table and code to Font Awesome icons for display.
 * APRS uses two symbol tables (Primary '/' and Alternate '\') with 94 symbols each.
 */

class SymbolMapper
{
    private $symbolMap;

    public function __construct()
    {
        $this->symbolMap = $this->buildSymbolMap();
    }

    /**
     * Get mapped icon for an APRS symbol
     *
     * @param string $table Symbol table ('/' or '\')
     * @param string $code Symbol code (single character)
     * @return array Icon data with 'icon' (FA class) and 'name' (description)
     */
    public function getSymbol(string $table, string $code): array
    {
        $key = $table . $code;
        return $this->symbolMap[$key] ?? $this->getDefaultSymbol();
    }

    /**
     * Get Font Awesome icon class
     */
    public function getIconClass(string $table, string $code): string
    {
        $symbol = $this->getSymbol($table, $code);
        return $symbol['icon'];
    }

    /**
     * Get symbol name/description
     */
    public function getSymbolName(string $table, string $code): string
    {
        $symbol = $this->getSymbol($table, $code);
        return $symbol['name'];
    }

    /**
     * Get default symbol for unmapped codes
     */
    private function getDefaultSymbol(): array
    {
        return [
            'icon' => 'fa-circle-dot',
            'name' => 'Unknown Station',
            'color' => '#3498db'
        ];
    }

    /**
     * Build the complete symbol map
     * Maps APRS symbols to Font Awesome 6 icons
     */
    private function buildSymbolMap(): array
    {
        return [
            // Primary Table (/) - Most common symbols
            '/!' => ['icon' => 'fa-phone', 'name' => 'Emergency', 'color' => '#e74c3c'],
            '/"' => ['icon' => 'fa-wifi', 'name' => 'Reserved', 'color' => '#3498db'],
            '/#' => ['icon' => 'fa-tower-cell', 'name' => 'Digipeater', 'color' => '#9b59b6'],
            '/$' => ['icon' => 'fa-phone', 'name' => 'Phone', 'color' => '#3498db'],
            '/&' => ['icon' => 'fa-tower-broadcast', 'name' => 'Gateway', 'color' => '#e67e22'],
            '/\'' => ['icon' => 'fa-plane', 'name' => 'Small Aircraft', 'color' => '#3498db'],
            '/(' => ['icon' => 'fa-mobile-screen', 'name' => 'Mobile Satellite', 'color' => '#3498db'],
            '/)' => ['icon' => 'fa-wheelchair', 'name' => 'Wheelchair', 'color' => '#3498db'],
            '/*' => ['icon' => 'fa-snowflake', 'name' => 'Snowmobile', 'color' => '#3498db'],
            '/+' => ['icon' => 'fa-square-plus', 'name' => 'Red Cross', 'color' => '#e74c3c'],
            '/,' => ['icon' => 'fa-triangle-exclamation', 'name' => 'Reverse L', 'color' => '#f39c12'],
            '/-' => ['icon' => 'fa-house', 'name' => 'House QTH', 'color' => '#27ae60'],
            '/.' => ['icon' => 'fa-circle', 'name' => 'X', 'color' => '#3498db'],
            '/0' => ['icon' => 'fa-circle', 'name' => 'Numeric 0', 'color' => '#3498db'],
            '/1' => ['icon' => 'fa-circle', 'name' => 'Numeric 1', 'color' => '#3498db'],
            '/2' => ['icon' => 'fa-circle', 'name' => 'Numeric 2', 'color' => '#3498db'],
            '/3' => ['icon' => 'fa-circle', 'name' => 'Numeric 3', 'color' => '#3498db'],
            '/4' => ['icon' => 'fa-circle', 'name' => 'Numeric 4', 'color' => '#3498db'],
            '/5' => ['icon' => 'fa-circle', 'name' => 'Numeric 5', 'color' => '#3498db'],
            '/6' => ['icon' => 'fa-circle', 'name' => 'Numeric 6', 'color' => '#3498db'],
            '/7' => ['icon' => 'fa-circle', 'name' => 'Numeric 7', 'color' => '#3498db'],
            '/8' => ['icon' => 'fa-circle', 'name' => 'Numeric 8', 'color' => '#3498db'],
            '/9' => ['icon' => 'fa-circle', 'name' => 'Numeric 9', 'color' => '#3498db'],
            '/:' => ['icon' => 'fa-fire', 'name' => 'Fire', 'color' => '#e74c3c'],
            '/;' => ['icon' => 'fa-caravan', 'name' => 'Campground', 'color' => '#27ae60'],
            '/<' => ['icon' => 'fa-motorcycle', 'name' => 'Motorcycle', 'color' => '#e67e22'],
            '/=' => ['icon' => 'fa-train', 'name' => 'Railroad Engine', 'color' => '#7f8c8d'],
            '/>' => ['icon' => 'fa-car', 'name' => 'Car', 'color' => '#3498db'],
            '/?' => ['icon' => 'fa-server', 'name' => 'File Server', 'color' => '#95a5a6'],
            '/@' => ['icon' => 'fa-helicopter', 'name' => 'Helicopter', 'color' => '#e74c3c'],
            '/A' => ['icon' => 'fa-hospital', 'name' => 'Aid Station', 'color' => '#e74c3c'],
            '/B' => ['icon' => 'fa-signal', 'name' => 'BBS', 'color' => '#3498db'],
            '/C' => ['icon' => 'fa-sailboat', 'name' => 'Canoe', 'color' => '#3498db'],
            '/D' => ['icon' => 'fa-circle', 'name' => 'Eyeball', 'color' => '#3498db'],
            '/E' => ['icon' => 'fa-horse', 'name' => 'Horse', 'color' => '#8e44ad'],
            '/F' => ['icon' => 'fa-fire-extinguisher', 'name' => 'Fire Truck', 'color' => '#e74c3c'],
            '/G' => ['icon' => 'fa-paper-plane', 'name' => 'Glider', 'color' => '#3498db'],
            '/H' => ['icon' => 'fa-hotel', 'name' => 'Hospital', 'color' => '#e74c3c'],
            '/I' => ['icon' => 'fa-wifi', 'name' => 'IOTA', 'color' => '#3498db'],
            '/J' => ['icon' => 'fa-plane', 'name' => 'Jeep', 'color' => '#27ae60'],
            '/K' => ['icon' => 'fa-truck', 'name' => 'Truck', 'color' => '#e67e22'],
            '/L' => ['icon' => 'fa-laptop', 'name' => 'Laptop', 'color' => '#34495e'],
            '/M' => ['icon' => 'fa-microphone', 'name' => 'Mic-E Repeater', 'color' => '#9b59b6'],
            '/N' => ['icon' => 'fa-location-crosshairs', 'name' => 'Node', 'color' => '#3498db'],
            '/O' => ['icon' => 'fa-train', 'name' => 'EOC', 'color' => '#e67e22'],
            '/P' => ['icon' => 'fa-dog', 'name' => 'Rover', 'color' => '#8e44ad'],
            '/Q' => ['icon' => 'fa-circle', 'name' => 'Grid Square', 'color' => '#3498db'],
            '/R' => ['icon' => 'fa-car-side', 'name' => 'Recreational Vehicle', 'color' => '#27ae60'],
            '/S' => ['icon' => 'fa-shuffle', 'name' => 'Space Shuttle', 'color' => '#3498db'],
            '/T' => ['icon' => 'fa-tty', 'name' => 'SSTV', 'color' => '#3498db'],
            '/U' => ['icon' => 'fa-bus', 'name' => 'Bus', 'color' => '#f39c12'],
            '/V' => ['icon' => 'fa-van-shuttle', 'name' => 'Van', 'color' => '#3498db'],
            '/W' => ['icon' => 'fa-water', 'name' => 'Water Station', 'color' => '#3498db'],
            '/X' => ['icon' => 'fa-helicopter', 'name' => 'XAPRS', 'color' => '#e67e22'],
            '/Y' => ['icon' => 'fa-ship', 'name' => 'Yacht', 'color' => '#3498db'],
            '/Z' => ['icon' => 'fa-person-shelter', 'name' => 'WinAPRS', 'color' => '#3498db'],
            '/[' => ['icon' => 'fa-person-running', 'name' => 'Jogger', 'color' => '#e67e22'],
            '/\\' => ['icon' => 'fa-triangle-exclamation', 'name' => 'Triangle DF', 'color' => '#f39c12'],
            '/]' => ['icon' => 'fa-square', 'name' => 'PBBS', 'color' => '#3498db'],
            '/^' => ['icon' => 'fa-plane', 'name' => 'Large Aircraft', 'color' => '#3498db'],
            '/_' => ['icon' => 'fa-cloud', 'name' => 'Weather Station', 'color' => '#3498db'],
            '/`' => ['icon' => 'fa-satellite-dish', 'name' => 'Dish Antenna', 'color' => '#9b59b6'],
            '/a' => ['icon' => 'fa-truck-medical', 'name' => 'Ambulance', 'color' => '#e74c3c'],
            '/b' => ['icon' => 'fa-bicycle', 'name' => 'Bike', 'color' => '#27ae60'],
            '/c' => ['icon' => 'fa-fire-burner', 'name' => 'Incident Command', 'color' => '#e67e22'],
            '/d' => ['icon' => 'fa-fire-extinguisher', 'name' => 'Fire Department', 'color' => '#e74c3c'],
            '/e' => ['icon' => 'fa-horse', 'name' => 'Horse', 'color' => '#8e44ad'],
            '/f' => ['icon' => 'fa-fire-flame-curved', 'name' => 'Fire Truck', 'color' => '#e74c3c'],
            '/g' => ['icon' => 'fa-paper-plane', 'name' => 'Glider', 'color' => '#3498db'],
            '/h' => ['icon' => 'fa-house-medical', 'name' => 'Hospital', 'color' => '#e74c3c'],
            '/i' => ['icon' => 'fa-building', 'name' => 'IOTA', 'color' => '#3498db'],
            '/j' => ['icon' => 'fa-car', 'name' => 'Jeep', 'color' => '#27ae60'],
            '/k' => ['icon' => 'fa-truck', 'name' => 'Truck', 'color' => '#e67e22'],
            '/l' => ['icon' => 'fa-laptop', 'name' => 'Laptop', 'color' => '#34495e'],
            '/m' => ['icon' => 'fa-microphone', 'name' => 'Mic-E Repeater', 'color' => '#9b59b6'],
            '/n' => ['icon' => 'fa-location-dot', 'name' => 'Node', 'color' => '#3498db'],
            '/o' => ['icon' => 'fa-circle', 'name' => 'EOC', 'color' => '#e67e22'],
            '/p' => ['icon' => 'fa-dog', 'name' => 'Dog', 'color' => '#8e44ad'],
            '/q' => ['icon' => 'fa-border-none', 'name' => 'Grid Square', 'color' => '#3498db'],
            '/r' => ['icon' => 'fa-satellite-dish', 'name' => 'Repeater', 'color' => '#9b59b6'],
            '/s' => ['icon' => 'fa-ship', 'name' => 'Ship', 'color' => '#3498db'],
            '/t' => ['icon' => 'fa-truck-pickup', 'name' => 'Pickup Truck', 'color' => '#e67e22'],
            '/u' => ['icon' => 'fa-truck', 'name' => 'Semi Truck', 'color' => '#95a5a6'],
            '/v' => ['icon' => 'fa-van-shuttle', 'name' => 'Van', 'color' => '#3498db'],
            '/w' => ['icon' => 'fa-droplet', 'name' => 'Water Station', 'color' => '#3498db'],
            '/x' => ['icon' => 'fa-xmark', 'name' => 'X', 'color' => '#e74c3c'],
            '/y' => ['icon' => 'fa-sailboat', 'name' => 'Sailboat', 'color' => '#3498db'],

            // Alternate Table (\) - Additional symbols
            '\\!' => ['icon' => 'fa-triangle-exclamation', 'name' => 'Emergency', 'color' => '#e74c3c'],
            '\\#' => ['icon' => 'fa-hashtag', 'name' => 'Overlay Digipeater', 'color' => '#9b59b6'],
            '\\&' => ['icon' => 'fa-diamond', 'name' => 'Diamond', 'color' => '#3498db'],
            '\\(' => ['icon' => 'fa-cloud', 'name' => 'Cloudy', 'color' => '#95a5a6'],
            '\\0' => ['icon' => 'fa-circle', 'name' => 'Circle', 'color' => '#3498db'],
            '\\<' => ['icon' => 'fa-circle', 'name' => 'Advisory', 'color' => '#f39c12'],
            '\\>' => ['icon' => 'fa-car-side', 'name' => 'Car', 'color' => '#3498db'],
            '\\A' => ['icon' => 'fa-square', 'name' => 'Box', 'color' => '#3498db'],
            '\\C' => ['icon' => 'fa-person-running', 'name' => 'Civil Defense', 'color' => '#e67e22'],
            '\\F' => ['icon' => 'fa-tractor', 'name' => 'Farm Vehicle', 'color' => '#27ae60'],
            '\\G' => ['icon' => 'fa-location-crosshairs', 'name' => 'Grid Square', 'color' => '#3498db'],
            '\\H' => ['icon' => 'fa-hotel', 'name' => 'Hotel', 'color' => '#3498db'],
            '\\I' => ['icon' => 'fa-tower-cell', 'name' => 'TCP/IP', 'color' => '#9b59b6'],
            '\\K' => ['icon' => 'fa-school', 'name' => 'School', 'color' => '#f39c12'],
            '\\M' => ['icon' => 'fa-user', 'name' => 'Mac APRS', 'color' => '#34495e'],
            '\\O' => ['icon' => 'fa-balloon', 'name' => 'Balloon', 'color' => '#e74c3c'],
            '\\P' => ['icon' => 'fa-building-shield', 'name' => 'Police', 'color' => '#3498db'],
            '\\R' => ['icon' => 'fa-car', 'name' => 'Recreational Vehicle', 'color' => '#27ae60'],
            '\\S' => ['icon' => 'fa-rocket', 'name' => 'Shuttle', 'color' => '#9b59b6'],
            '\\T' => ['icon' => 'fa-bolt', 'name' => 'Thunderstorm', 'color' => '#f39c12'],
            '\\U' => ['icon' => 'fa-sun', 'name' => 'Sunny', 'color' => '#f39c12'],
            '\\V' => ['icon' => 'fa-leaf', 'name' => 'VORTAC', 'color' => '#27ae60'],
            '\\W' => ['icon' => 'fa-building', 'name' => 'NWS Site', 'color' => '#3498db'],
            '\\X' => ['icon' => 'fa-map-pin', 'name' => 'Pharmacy', 'color' => '#e74c3c'],
            '\\Y' => ['icon' => 'fa-radio', 'name' => 'Radios', 'color' => '#9b59b6'],
            '\\[' => ['icon' => 'fa-person', 'name' => 'Person', 'color' => '#e67e22'],
            '\\]' => ['icon' => 'fa-square', 'name' => 'Post Office', 'color' => '#3498db'],
            '\\^' => ['icon' => 'fa-plane', 'name' => 'Aircraft', 'color' => '#3498db'],
            '\\_' => ['icon' => 'fa-cloud-rain', 'name' => 'Rain', 'color' => '#3498db'],
            '\\a' => ['icon' => 'fa-truck-medical', 'name' => 'Ambulance', 'color' => '#e74c3c'],
            '\\b' => ['icon' => 'fa-bicycle', 'name' => 'Bicycle', 'color' => '#27ae60'],
            '\\d' => ['icon' => 'fa-fire-extinguisher', 'name' => 'Fire Department', 'color' => '#e74c3c'],
            '\\e' => ['icon' => 'fa-eye', 'name' => 'Eyeball', 'color' => '#3498db'],
            '\\f' => ['icon' => 'fa-fire-flame-curved', 'name' => 'Fire Truck', 'color' => '#e74c3c'],
            '\\g' => ['icon' => 'fa-paper-plane', 'name' => 'Glider', 'color' => '#3498db'],
            '\\h' => ['icon' => 'fa-house-medical', 'name' => 'Hospital', 'color' => '#e74c3c'],
            '\\j' => ['icon' => 'fa-car', 'name' => 'Jeep', 'color' => '#27ae60'],
            '\\k' => ['icon' => 'fa-truck', 'name' => 'Truck', 'color' => '#e67e22'],
            '\\l' => ['icon' => 'fa-laptop', 'name' => 'Laptop', 'color' => '#34495e'],
            '\\m' => ['icon' => 'fa-microphone', 'name' => 'Mic-E', 'color' => '#9b59b6'],
            '\\n' => ['icon' => 'fa-location-dot', 'name' => 'Node', 'color' => '#3498db'],
            '\\p' => ['icon' => 'fa-building-shield', 'name' => 'Police', 'color' => '#3498db'],
            '\\r' => ['icon' => 'fa-satellite-dish', 'name' => 'Repeater', 'color' => '#9b59b6'],
            '\\s' => ['icon' => 'fa-ship', 'name' => 'Ship', 'color' => '#3498db'],
            '\\t' => ['icon' => 'fa-truck-pickup', 'name' => 'Pickup Truck', 'color' => '#e67e22'],
            '\\u' => ['icon' => 'fa-truck', 'name' => 'Truck (18-wheeler)', 'color' => '#95a5a6'],
            '\\v' => ['icon' => 'fa-van-shuttle', 'name' => 'Van', 'color' => '#3498db'],
            '\\y' => ['icon' => 'fa-sailboat', 'name' => 'Yacht/Sail', 'color' => '#3498db'],
        ];
    }

    /**
     * Get all available symbols
     */
    public function getAllSymbols(): array
    {
        return $this->symbolMap;
    }

    /**
     * Get symbol color
     */
    public function getSymbolColor(string $table, string $code): string
    {
        $symbol = $this->getSymbol($table, $code);
        return $symbol['color'] ?? '#3498db';
    }
}
