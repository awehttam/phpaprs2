/**
 * APRS-IS Live Map Application
 *
 * Main application that coordinates MapManager and SSEClient.
 */

class APRSApp {
    constructor() {
        console.log('Initializing APRS-IS Live Map...');

        // Get config center from URL or use default (Burnaby, Canada)
        const urlParams = new URLSearchParams(window.location.search);
        const lat = parseFloat(urlParams.get('lat')) || 49.2488;
        const lon = parseFloat(urlParams.get('lon')) || -122.9805;
        const zoom = parseInt(urlParams.get('zoom')) || 10;

        // Initialize map
        this.mapManager = new MapManager('map', {
            center: [lat, lon],
            zoom: zoom
        });

        // Initialize SSE client
        this.sseClient = new SSEClient('sse.php', {
            onStationsUpdate: (action, station) => this.handleStationUpdate(action, station),
            onStatusChange: (status) => this.handleStatusChange(status),
            onHeartbeat: (data) => this.handleHeartbeat(data),
            onError: (error) => this.handleError(error)
        });

        // UI state
        this.sidebarVisible = true;
        this.searchFilter = '';

        // Initialize UI
        this.initializeUI();
        this.initializeControls();

        // Connect to SSE
        this.sseClient.connect();

        console.log('APRS-IS Live Map initialized');
    }

    /**
     * Handle station update from SSE
     */
    handleStationUpdate(action, data) {
        if (action === 'batch') {
            // Batch update - much more efficient
            if (data.updated && data.updated.length > 0) {
                this.mapManager.updateStationsBatch(data.updated);
            }
            if (data.removed && data.removed.length > 0) {
                data.removed.forEach(callsign => {
                    this.mapManager.removeStation(callsign);
                });
            }

            // Update station list once for entire batch
            this.updateStationList();
        } else if (action === 'update') {
            // Legacy single station update (for backwards compatibility)
            this.mapManager.updateStation(data);
            this.updateStationList();
        } else if (action === 'remove') {
            this.mapManager.removeStation(data.callsign);
            this.updateStationList();
        }
    }

    /**
     * Handle connection status change
     */
    handleStatusChange(status) {
        const statusEl = document.getElementById('connection-status');
        const statusMiniEl = document.getElementById('connection-status-mini');
        const iconEl = document.getElementById('connection-icon');

        let statusText = '';
        let className = '';
        let iconClass = '';

        switch (status) {
            case 'connected':
                statusText = 'Connected';
                className = 'connected';
                iconClass = 'fa-signal';
                break;
            case 'connecting':
                statusText = 'Connecting...';
                className = 'connecting';
                iconClass = 'fa-spinner fa-spin';
                break;
            case 'disconnected':
                statusText = 'Disconnected';
                className = 'disconnected';
                iconClass = 'fa-exclamation-triangle';
                break;
            case 'error':
                statusText = 'Error';
                className = 'error';
                iconClass = 'fa-exclamation-circle';
                break;
        }

        statusEl.textContent = statusText;
        statusEl.className = className;

        if (statusMiniEl) {
            statusMiniEl.textContent = statusText;
            statusMiniEl.className = className;
        }

        if (iconEl) {
            iconEl.className = `fas ${iconClass}`;
        }
    }

    /**
     * Handle heartbeat from server
     */
    handleHeartbeat(data) {
        console.log('Heartbeat:', data);
    }

    /**
     * Handle SSE error
     */
    handleError(error) {
        console.error('SSE Error:', error);
    }

    /**
     * Update station list in sidebar
     */
    updateStationList() {
        const stations = this.sseClient.getStations();
        const listEl = document.getElementById('station-list');
        const countEl = document.getElementById('station-count');
        const countMiniEl = document.getElementById('station-count-mini');

        // Update count
        const count = stations.length;
        const countText = `${count} station${count !== 1 ? 's' : ''}`;
        countEl.textContent = countText;

        if (countMiniEl) {
            countMiniEl.textContent = count;
        }

        // Filter stations by search
        const filtered = this.filterStations(stations);

        // Sort by last update (most recent first)
        filtered.sort((a, b) => b.last_update - a.last_update);

        // Render list
        if (filtered.length === 0) {
            listEl.innerHTML = `
                <div class="empty-message">
                    <i class="fas fa-satellite-dish fa-2x"></i>
                    <p>${stations.length === 0 ? 'Waiting for stations...' : 'No matching stations'}</p>
                </div>
            `;
            return;
        }

        listEl.innerHTML = filtered.map(station => {
            const latest = station.latest;
            const symbol = latest.symbol_code || '>';
            const lastSeen = this.formatTimeSince(station.last_update);
            const speed = latest.speed ? `${latest.speed} km/h` : '';

            return `
                <div class="station-item" data-callsign="${station.callsign}">
                    <div class="station-item-header">
                        <span class="station-callsign">${station.callsign}</span>
                        <span class="station-time">${lastSeen}</span>
                    </div>
                    <div class="station-item-details">
                        ${latest.comment ? `<div class="station-comment">${this.escapeHtml(latest.comment)}</div>` : ''}
                        ${speed ? `<div class="station-speed"><i class="fas fa-gauge-high"></i> ${speed}</div>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        // Click handlers now handled by event delegation (no need to reattach)
    }

    /**
     * Filter stations by search term
     */
    filterStations(stations) {
        if (!this.searchFilter) {
            return stations;
        }

        const filter = this.searchFilter.toLowerCase();
        return stations.filter(station =>
            station.callsign.toLowerCase().includes(filter) ||
            (station.latest.comment && station.latest.comment.toLowerCase().includes(filter))
        );
    }

    /**
     * Initialize UI elements
     */
    initializeUI() {
        // Search input with debouncing to reduce rebuilds
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            let debounceTimer;
            searchInput.addEventListener('input', (e) => {
                this.searchFilter = e.target.value;

                // Debounce to avoid rebuilding on every keystroke
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    this.updateStationList();
                }, 200); // 200ms debounce delay
            });
        }

        // Event delegation for station list clicks (prevents memory leaks)
        const listEl = document.getElementById('station-list');
        if (listEl) {
            listEl.addEventListener('click', (e) => {
                // Find the station-item element (handle clicks on children)
                const stationItem = e.target.closest('.station-item');
                if (stationItem) {
                    const callsign = stationItem.dataset.callsign;
                    this.mapManager.focusStation(callsign);
                }
            });
        }
    }

    /**
     * Initialize control buttons
     */
    initializeControls() {
        // Toggle tracks
        const toggleTracksBtn = document.getElementById('toggle-tracks');
        if (toggleTracksBtn) {
            toggleTracksBtn.addEventListener('click', () => {
                const enabled = this.mapManager.toggleTracks();
                toggleTracksBtn.classList.toggle('active', enabled);
                console.log('Tracks:', enabled ? 'enabled' : 'disabled');
            });
        }

        // Toggle labels
        const toggleLabelsBtn = document.getElementById('toggle-labels');
        if (toggleLabelsBtn) {
            toggleLabelsBtn.addEventListener('click', () => {
                const enabled = this.mapManager.toggleLabels();
                toggleLabelsBtn.classList.toggle('active', enabled);
                console.log('Labels:', enabled ? 'enabled' : 'disabled');
            });
        }

        // Center map
        const centerMapBtn = document.getElementById('center-map');
        if (centerMapBtn) {
            centerMapBtn.addEventListener('click', () => {
                this.mapManager.centerMap();
                console.log('Map centered on all stations');
            });
        }

        // Toggle sidebar
        const toggleSidebarBtn = document.getElementById('toggle-sidebar');
        if (toggleSidebarBtn) {
            toggleSidebarBtn.addEventListener('click', () => {
                this.toggleSidebar();
            });
        }
    }

    /**
     * Toggle sidebar visibility
     */
    toggleSidebar() {
        this.sidebarVisible = !this.sidebarVisible;

        const sidebar = document.getElementById('sidebar');
        const infoPanel = document.getElementById('info-panel');
        const controls = document.getElementById('controls');

        // Check if we're in mobile view
        const isMobile = window.innerWidth <= 768;

        if (this.sidebarVisible) {
            if (isMobile) {
                // In mobile view, use the .visible class for transform animation
                sidebar.classList.add('visible');
                // Increase z-index of controls so they appear above sidebar
                controls.style.zIndex = '3000';
            } else {
                // In desktop view, use display property
                sidebar.style.display = 'flex';
                controls.style.left = '320px';
                controls.style.zIndex = '';
            }
            infoPanel.style.display = 'none';
        } else {
            if (isMobile) {
                // In mobile view, remove the .visible class
                sidebar.classList.remove('visible');
                // Reset z-index
                controls.style.zIndex = '';
            } else {
                // In desktop view, use display property
                sidebar.style.display = 'none';
                controls.style.zIndex = '';
            }
            infoPanel.style.display = 'block';
            controls.style.left = '20px';
        }
    }

    /**
     * Format time since
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
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM ready, initializing app...');
    window.aprsApp = new APRSApp();
});
