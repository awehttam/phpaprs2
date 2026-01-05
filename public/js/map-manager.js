/**
 * Map Manager
 *
 * Manages Leaflet map, markers, tracks, and station display.
 */

class MapManager {
    constructor(elementId, options = {}) {
        // Initialize map centered on default location (Burnaby, Canada)
        this.map = L.map(elementId).setView(
            options.center || [49.2488, -122.9805],
            options.zoom || 10
        );

        // Add OpenStreetMap tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(this.map);

        // Storage for markers and tracks
        this.markers = new Map();
        this.tracks = new Map();
        this.showTracks = true;
        this.showLabels = true;

        // Symbol to icon mapping (APRS symbols to Font Awesome)
        this.iconMap = this.buildIconMap();

        console.log('MapManager initialized');
    }

    /**
     * Update or create station marker and track
     */
    updateStation(station) {
        const callsign = station.callsign;
        const latest = station.latest;

        if (!latest || !latest.latitude || !latest.longitude) {
            return;
        }

        const position = [latest.latitude, latest.longitude];

        // Create or update marker
        if (!this.markers.has(callsign)) {
            this.createMarker(callsign, station);
        } else {
            this.updateMarker(callsign, station);
        }

        // Update track
        if (this.showTracks && station.track && station.track.length > 1) {
            this.updateTrack(callsign, station);
        }
    }

    /**
     * Create a new marker for a station
     */
    createMarker(callsign, station) {
        const latest = station.latest;
        const position = [latest.latitude, latest.longitude];

        // Create custom icon
        const icon = this.createIcon(latest.symbol_table, latest.symbol_code);

        // Create marker
        const marker = L.marker(position, { icon });

        // Bind popup with station info
        marker.bindPopup(this.createPopupContent(station));

        // Bind tooltip (callsign label) if labels enabled
        if (this.showLabels) {
            marker.bindTooltip(callsign, {
                permanent: true,
                direction: 'top',
                className: 'station-label',
                offset: [0, -20]
            });
        }

        // Add click handler
        marker.on('click', () => {
            console.log('Clicked station:', callsign);
        });

        // Add to map
        marker.addTo(this.map);
        this.markers.set(callsign, marker);
    }

    /**
     * Update existing marker
     */
    updateMarker(callsign, station) {
        const marker = this.markers.get(callsign);
        const latest = station.latest;
        const position = [latest.latitude, latest.longitude];

        // Update position
        marker.setLatLng(position);

        // Update popup content
        marker.setPopupContent(this.createPopupContent(station));

        // Update icon if symbol changed
        const icon = this.createIcon(latest.symbol_table, latest.symbol_code);
        marker.setIcon(icon);
    }

    /**
     * Update or create track polyline
     */
    updateTrack(callsign, station) {
        if (!station.track || station.track.length < 2) {
            return;
        }

        const trackPoints = station.track.map(p => [p.lat, p.lng]);
        const color = this.getTrackColor(callsign);

        if (this.tracks.has(callsign)) {
            // Update existing track
            this.tracks.get(callsign).setLatLngs(trackPoints);
        } else {
            // Create new track
            const polyline = L.polyline(trackPoints, {
                color: color,
                weight: 3,
                opacity: 0.7
            }).addTo(this.map);

            this.tracks.set(callsign, polyline);
        }
    }

    /**
     * Remove a station marker and track
     */
    removeStation(callsign) {
        // Remove marker
        if (this.markers.has(callsign)) {
            this.map.removeLayer(this.markers.get(callsign));
            this.markers.delete(callsign);
        }

        // Remove track
        if (this.tracks.has(callsign)) {
            this.map.removeLayer(this.tracks.get(callsign));
            this.tracks.delete(callsign);
        }
    }

    /**
     * Create custom icon based on APRS symbol
     */
    createIcon(symbolTable, symbolCode) {
        const key = (symbolTable || '/') + (symbolCode || '>');
        const iconClass = this.iconMap[key] || 'fa-circle-dot';
        const color = this.getSymbolColor(key);

        const iconHtml = `
            <div class="station-marker" style="background-color: ${color};">
                <i class="fas ${iconClass}"></i>
            </div>
        `;

        return L.divIcon({
            html: iconHtml,
            className: 'station-icon-wrapper',
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -16]
        });
    }

    /**
     * Create popup content for a station
     */
    createPopupContent(station) {
        const s = station.latest;
        const lastSeen = this.formatTimeSince(station.last_update);

        let content = `
            <div class="station-popup">
                <h3><i class="fas fa-satellite-dish"></i> ${station.callsign}</h3>
                <table>
                    <tr>
                        <td><strong>Position:</strong></td>
                        <td>${s.latitude.toFixed(6)}, ${s.longitude.toFixed(6)}</td>
                    </tr>
        `;

        if (s.altitude) {
            content += `
                    <tr>
                        <td><strong>Altitude:</strong></td>
                        <td>${Math.round(s.altitude)}m (${Math.round(s.altitude * 3.28084)}ft)</td>
                    </tr>
            `;
        }

        if (s.speed) {
            content += `
                    <tr>
                        <td><strong>Speed:</strong></td>
                        <td>${s.speed} km/h (${Math.round(s.speed * 0.621371)} mph)</td>
                    </tr>
            `;
        }

        if (s.course) {
            content += `
                    <tr>
                        <td><strong>Course:</strong></td>
                        <td>${s.course}° (${this.getCompassDirection(s.course)})</td>
                    </tr>
            `;
        }

        if (s.comment) {
            content += `
                    <tr>
                        <td><strong>Comment:</strong></td>
                        <td>${this.escapeHtml(s.comment)}</td>
                    </tr>
            `;
        }

        content += `
                    <tr>
                        <td><strong>Last seen:</strong></td>
                        <td>${lastSeen}</td>
                    </tr>
        `;

        if (station.track && station.track.length > 0) {
            content += `
                    <tr>
                        <td><strong>Track points:</strong></td>
                        <td>${station.track.length}</td>
                    </tr>
            `;
        }

        content += `
                </table>
            </div>
        `;

        return content;
    }

    /**
     * Toggle track display
     */
    toggleTracks() {
        this.showTracks = !this.showTracks;

        if (!this.showTracks) {
            // Hide all tracks
            this.tracks.forEach(track => this.map.removeLayer(track));
            this.tracks.clear();
        }

        return this.showTracks;
    }

    /**
     * Toggle label display
     */
    toggleLabels() {
        this.showLabels = !this.showLabels;

        this.markers.forEach((marker, callsign) => {
            if (this.showLabels) {
                marker.bindTooltip(callsign, {
                    permanent: true,
                    direction: 'top',
                    className: 'station-label',
                    offset: [0, -20]
                });
            } else {
                marker.unbindTooltip();
            }
        });

        return this.showLabels;
    }

    /**
     * Center map on all stations
     */
    centerMap() {
        if (this.markers.size === 0) {
            return;
        }

        const bounds = L.latLngBounds(
            Array.from(this.markers.values()).map(m => m.getLatLng())
        );

        this.map.fitBounds(bounds, { padding: [50, 50] });
    }

    /**
     * Focus on a specific station
     */
    focusStation(callsign) {
        const marker = this.markers.get(callsign);
        if (marker) {
            this.map.setView(marker.getLatLng(), 14);
            marker.openPopup();
        }
    }

    /**
     * Get color for track based on callsign
     */
    getTrackColor(callsign) {
        // Generate consistent color per callsign using hash
        let hash = 0;
        for (let i = 0; i < callsign.length; i++) {
            hash = callsign.charCodeAt(i) + ((hash << 5) - hash);
        }

        const hue = Math.abs(hash % 360);
        return `hsl(${hue}, 70%, 50%)`;
    }

    /**
     * Get symbol color
     */
    getSymbolColor(key) {
        const colorMap = {
            '/>': '#3498db',  // Car - blue
            '/-': '#27ae60',  // House - green
            '/[': '#e67e22',  // Jogger - orange
            '/^': '#3498db',  // Aircraft - blue
            '/_': '#16a085',  // Weather - teal
        };

        return colorMap[key] || '#3498db';
    }

    /**
     * Format time since last update
     */
    formatTimeSince(timestamp) {
        const now = Math.floor(Date.now() / 1000);
        const diff = now - timestamp;

        if (diff < 60) return `${diff}s ago`;
        if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
        return new Date(timestamp * 1000).toLocaleString();
    }

    /**
     * Get compass direction from degrees
     */
    getCompassDirection(degrees) {
        const directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        const index = Math.round(degrees / 45) % 8;
        return directions[index];
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Build APRS symbol to Font Awesome icon mapping
     */
    buildIconMap() {
        return {
            // Primary table (/)
            '/-': 'fa-house',
            '/>': 'fa-car',
            '/[': 'fa-person-running',
            '/\'': 'fa-plane',
            '/^': 'fa-plane',
            '/@': 'fa-helicopter',
            '/k': 'fa-truck',
            '/K': 'fa-truck',
            '/b': 'fa-bicycle',
            '/U': 'fa-bus',
            '/V': 'fa-van-shuttle',
            '/s': 'fa-ship',
            '/Y': 'fa-sailboat',
            '/_': 'fa-cloud',
            '/+': 'fa-square-plus',
            '/a': 'fa-truck-medical',
            '/f': 'fa-fire-flame-curved',
            '/A': 'fa-hospital',
            '/P': 'fa-dog',
            '/H': 'fa-hotel',
            '/:': 'fa-fire',
            '/!': 'fa-phone',

            // Alternate table (\)
            '\\>': 'fa-car-side',
            '\\a': 'fa-truck-medical',
            '\\b': 'fa-bicycle',
            '\\f': 'fa-fire-flame-curved',
            '\\P': 'fa-building-shield',
            '\\s': 'fa-ship',
            '\\k': 'fa-truck',
            '\\^': 'fa-plane',
            '\\_': 'fa-cloud-rain',

            // Default
            'default': 'fa-circle-dot'
        };
    }
}
