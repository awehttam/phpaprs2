<?php
/**
 * APRS-IS Configuration Loader
 *
 * Loads configuration from etc/config.local.php
 */

$configPath = __DIR__ . '/../etc/config.local.php';

if (file_exists($configPath)) {
    return require $configPath;
}

// Fallback error if no config exists
throw new Exception(
    "Configuration file not found. Please copy etc/config.local.example.php to etc/config.local.php and configure your settings."
);

