# phpAPRS2 - APRS-IS Live Map

A real-time web application that connects to the APRS-IS network and displays tracked stations on an interactive OpenStreetMap using Leaflet.

## Features

- **Real-time tracking**: Live updates from APRS-IS network via Server-Sent Events
- **Interactive map**: OpenStreetMap with Leaflet for smooth, responsive mapping
- **Station markers**: APRS symbols mapped to Font Awesome icons
- **Movement trails**: Visual track history for moving stations
- **Station details**: Click markers for detailed information (position, altitude, speed, comment)
- **Search & filter**: Find stations by callsign or comment
- **Geographic filtering**: Configure area of interest (radius-based filtering)
- **No database required**: In-memory storage with APCu or file fallback

## Requirements

- PHP 8.0 or higher
- APCu extension (optional but recommended for better performance)
- Web browser with EventSource support (all modern browsers)
- Internet connection to APRS-IS network

## Installation

1. **Clone or download** this repository to your web server

2. **Configure the application**:
   - Edit `backend/config.php`
   - Set your callsign (or use `N0CALL` for read-only testing)
   - Configure the geographic filter for your area of interest:
     ```php
     'filter' => 'r/49.2488/-122.9805/100',  // Burnaby, Canada - lat/lon/radius_km
     ```

3. **Check PHP configuration**:
   ```bash
   php -v  # Should be 8.0+
   php -m | grep apcu  # Check if APCu is available
   ```

## Running the Application

### Step 1: Start the APRS-IS Client Daemon

Open a terminal and run:

```bash
php backend/aprs-is-client.php
```

This daemon will:
- Connect to `rotate.aprs2.net:14580`
- Log in with your configured callsign
- Receive APRS packets matching your filter
- Parse position reports
- Store station data in memory (APCu or file)
- Print statistics every 60 seconds

**Keep this terminal running!**

### Step 2: Start the Web Server

In a **new terminal**, navigate to the `public` directory and start PHP's built-in server:

```bash
cd public
php -S localhost:8000
```

For production, use Apache or Nginx instead.

### Step 3: Open the Application

Open your web browser and navigate to:

```
http://localhost:8000
```

You should see:
- The map centered on your configured location
- Connection status in the sidebar
- Stations appearing as they are received from APRS-IS
- Real-time updates every 2 seconds

## Configuration

### Geographic Filter

Edit `backend/config.php` to change the filter:

```php
// Format: r/latitude/longitude/radius_km
'filter' => 'r/34.0522/-118.2437/150',  // Los Angeles area, 150km radius
```

### Memory Limits

Adjust station limits in `backend/config.php`:

```php
'memory' => [
    'max_stations' => 1000,        // Maximum stations to track
    'track_points' => 50,          // Points per track
    'station_timeout' => 3600,     // Remove after 1 hour of inactivity
    'min_track_distance' => 50,    // Minimum meters for track update
],
```

### Update Interval

Change how often the frontend receives updates:

```php
'sse' => [
    'update_interval' => 2,        // Seconds between updates
    'heartbeat_interval' => 30,    // Heartbeat frequency
],
```

## Usage

### Map Controls

- **Toggle Tracks**: Show/hide movement trails
- **Toggle Labels**: Show/hide callsign labels on markers
- **Center Map**: Zoom to fit all visible stations
- **Toggle Sidebar**: Show/hide the station list sidebar

### Station List

- Click any station to zoom to it on the map
- Use the search box to filter by callsign or comment
- Stations are sorted by most recently updated

### URL Parameters

Customize the initial map view:

```
http://localhost:8000?lat=40.7128&lon=-74.0060&zoom=12
```

## File Structure

```
phpaprs2/
├── backend/
│   ├── config.php              # Configuration
│   ├── aprs-is-client.php      # APRS-IS connection daemon
│   ├── aprs-parser.php         # Packet parser
│   ├── station-manager.php     # State management
│   ├── symbol-mapper.php       # Symbol to icon mapping
│   └── sse-server.php          # SSE endpoint (included by public/sse.php)
├── public/
│   ├── index.html              # Main page
│   ├── sse.php                 # SSE proxy (web-accessible endpoint)
│   ├── css/
│   │   └── style.css           # Styles
│   └── js/
│       ├── app.js              # Main application
│       ├── map-manager.js      # Map handling
│       └── sse-client.js       # SSE connection
└── README.md
```

## Troubleshooting

### No stations appearing

1. Check that the APRS-IS client is running and connected
2. Verify your filter matches stations in the area
3. Check browser console for JavaScript errors
4. Ensure SSE endpoint is accessible: `http://localhost:8000/sse.php`

### Connection keeps disconnecting

1. Check PHP error logs
2. Verify network connectivity to `rotate.aprs2.net:14580`
3. Increase PHP timeout settings if needed

### High memory usage

1. Reduce `max_stations` in config
2. Reduce `track_points` per station
3. Decrease `station_timeout` to prune inactive stations faster

### APCu not available

The application will automatically fall back to file-based storage (`backend/stations.json`). For better performance, install APCu:

```bash
# Ubuntu/Debian
sudo apt-get install php-apcu

# Windows
# Enable in php.ini: extension=apcu
```

## Development

### Debug Logging

Enable debug logging in `backend/config.php`:

```php
'logging' => [
    'debug' => true,
    'log_file' => __DIR__ . '/aprs-debug.log',
],
```

View logs:
```bash
tail -f backend/aprs-debug.log
```

### Testing Different Filters

Common filter examples:

```php
// All traffic (WARNING: Very high volume!)
'filter' => ''

// Specific callsigns
'filter' => 'p/W6/N6'  // W6* and N6* prefixes

// Multiple areas
'filter' => 'r/47.6/-122.3/100 r/34.0/-118.2/100'

// Position reports only
'filter' => 'r/47.6/-122.3/100 t/p'
```

See [APRS-IS Filter Documentation](http://www.aprs-is.net/javAPRSFilter.aspx) for more options.

## Production Deployment

1. **Use a process manager** for the APRS-IS client:
   - systemd (Linux)
   - Windows Service
   - PM2, Supervisor, etc.

2. **Use a proper web server**:
   - Apache with mod_php
   - Nginx with PHP-FPM

3. **Enable HTTPS** for security

4. **Configure proper logging and monitoring**

5. **Set appropriate resource limits**

## License

This project is open source. Feel free to modify and distribute.

## Credits

- **APRS**: Automatic Packet Reporting System
- **APRS-IS**: APRS Internet Service
- **Leaflet**: Open-source JavaScript mapping library
- **OpenStreetMap**: Free, editable map of the world
- **Font Awesome**: Icon library for APRS symbols

## Support

For issues or questions:
- Check the logs: `backend/aprs-debug.log`
- Verify your configuration in `backend/config.php`
- Ensure all PHP files are readable and executable

Enjoy tracking APRS stations in real-time!
