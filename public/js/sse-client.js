/**
 * SSE Client
 *
 * Handles Server-Sent Events connection to receive real-time station updates.
 */

class SSEClient {
    constructor(url, options = {}) {
        this.url = url;
        this.eventSource = null;
        this.reconnectDelay = options.reconnectDelay || 5000;
        this.maxReconnectDelay = options.maxReconnectDelay || 30000;
        this.currentReconnectDelay = this.reconnectDelay;
        this.reconnectAttempts = 0;

        // Callbacks
        this.onStationsUpdate = options.onStationsUpdate || (() => {});
        this.onStatusChange = options.onStatusChange || (() => {});
        this.onHeartbeat = options.onHeartbeat || (() => {});
        this.onError = options.onError || (() => {});

        // Station storage
        this.stations = new Map();

        console.log('SSEClient initialized with URL:', url);
    }

    /**
     * Connect to SSE endpoint
     */
    connect() {
        if (this.eventSource) {
            this.eventSource.close();
        }

        console.log('Connecting to SSE endpoint...');
        this.onStatusChange('connecting');

        try {
            this.eventSource = new EventSource(this.url);

            // Connection opened
            this.eventSource.addEventListener('open', () => {
                console.log('SSE connection opened');
                this.onStatusChange('connected');
                this.currentReconnectDelay = this.reconnectDelay;
                this.reconnectAttempts = 0;
            });

            // Connected event
            this.eventSource.addEventListener('connected', (e) => {
                const data = JSON.parse(e.data);
                console.log('Connected to APRS-IS stream:', data.message);
            });

            // Station updates
            this.eventSource.addEventListener('stations', (e) => {
                const stations = JSON.parse(e.data);
                this.processStationUpdate(stations);
            });

            // Heartbeat
            this.eventSource.addEventListener('heartbeat', (e) => {
                const data = JSON.parse(e.data);
                console.log('Heartbeat:', data);
                this.onHeartbeat(data);
            });

            // Error handler
            this.eventSource.addEventListener('error', (e) => {
                console.error('SSE error:', e);

                if (this.eventSource.readyState === EventSource.CLOSED) {
                    console.log('SSE connection closed, will attempt to reconnect');
                    this.onStatusChange('disconnected');
                    this.eventSource.close();
                    this.scheduleReconnect();
                } else if (this.eventSource.readyState === EventSource.CONNECTING) {
                    console.log('SSE reconnecting...');
                    this.onStatusChange('connecting');
                }

                this.onError(e);
            });

        } catch (error) {
            console.error('Failed to create EventSource:', error);
            this.onStatusChange('error');
            this.onError(error);
            this.scheduleReconnect();
        }
    }

    /**
     * Schedule reconnection with exponential backoff
     */
    scheduleReconnect() {
        this.reconnectAttempts++;

        // Calculate delay with exponential backoff
        const delay = Math.min(
            this.currentReconnectDelay * Math.pow(1.5, this.reconnectAttempts - 1),
            this.maxReconnectDelay
        );

        console.log(`Reconnecting in ${Math.round(delay / 1000)}s (attempt ${this.reconnectAttempts})...`);

        setTimeout(() => {
            this.connect();
        }, delay);
    }

    /**
     * Process station update from server
     */
    processStationUpdate(stationsData) {
        const currentCallsigns = new Set(Object.keys(stationsData));
        const previousCallsigns = new Set(this.stations.keys());

        // Track updates
        let added = 0;
        let updated = 0;
        let removed = 0;

        // Update or add stations
        for (const [callsign, station] of Object.entries(stationsData)) {
            const isNew = !this.stations.has(callsign);

            this.stations.set(callsign, station);
            this.onStationsUpdate('update', station);

            if (isNew) {
                added++;
            } else {
                updated++;
            }
        }

        // Remove stations no longer present
        for (const callsign of previousCallsigns) {
            if (!currentCallsigns.has(callsign)) {
                this.stations.delete(callsign);
                this.onStationsUpdate('remove', { callsign });
                removed++;
            }
        }

        // Log statistics
        if (added > 0 || removed > 0) {
            console.log(`Station update: ${added} added, ${updated} updated, ${removed} removed (total: ${this.stations.size})`);
        }
    }

    /**
     * Disconnect from SSE
     */
    disconnect() {
        if (this.eventSource) {
            console.log('Disconnecting from SSE...');
            this.eventSource.close();
            this.eventSource = null;
            this.onStatusChange('disconnected');
        }
    }

    /**
     * Get all stations
     */
    getStations() {
        return Array.from(this.stations.values());
    }

    /**
     * Get a specific station
     */
    getStation(callsign) {
        return this.stations.get(callsign);
    }

    /**
     * Get number of stations
     */
    getStationCount() {
        return this.stations.size;
    }

    /**
     * Check if connected
     */
    isConnected() {
        return this.eventSource && this.eventSource.readyState === EventSource.OPEN;
    }

    /**
     * Get connection state
     */
    getConnectionState() {
        if (!this.eventSource) {
            return 'disconnected';
        }

        switch (this.eventSource.readyState) {
            case EventSource.CONNECTING:
                return 'connecting';
            case EventSource.OPEN:
                return 'connected';
            case EventSource.CLOSED:
                return 'disconnected';
            default:
                return 'unknown';
        }
    }
}
