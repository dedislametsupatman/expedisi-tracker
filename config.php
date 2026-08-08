<?php

/**
 * Central configuration - loads .env file and provides config constants
 * All API keys and secrets should be accessed via this file
 */

// Load .env file if it exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;
        // Parse KEY=value
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            // Remove surrounding quotes
            if ((strpos($val, '"') === 0 && substr($val, -1) === '"') ||
                (strpos($val, "'") === 0 && substr($val, -1) === "'")
            ) {
                $val = substr($val, 1, -1);
            }
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

define('DB_DRIVER', getenv('DB_DRIVER') ?: 'sqlite');
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'expedisi');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_SOCKET', getenv('DB_SOCKET') ?: null);

define('PAKASIR_PROJECT', getenv('PAKASIR_PROJECT') ?: '');
define('PAKASIR_API_KEY', getenv('PAKASIR_API_KEY') ?: '');

// ── Brevo Email ───────────────────────────────────────────────
define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '');
define('BREVO_BASE_URL', 'https://api.brevo.com/v3');

// ── Shipping API (api.co.id) ──────────────────────────────────
define('API_CO_ID_KEY', getenv('API_CO_ID_KEY') ?: '');
define('API_CO_ID_BASE_URL', 'https://use.api.co.id/expedition');

// ── Rajaongkir (Komerce) ──────────────────────────────────────
define('RAJAONGKIR_KEY', getenv('RAJAONGKIR_KEY') ?: '');
define('RAJAONGKIR_BASE_URL', 'https://rajaongkir.komerce.id/api/v1');

// ── App URL (used in email links) ─────────────────────────────────────────────
define('APP_URL', getenv('APP_URL') ?: 'https://expedisi.aplikasirt.my.id');
define('SENDER_EMAIL', getenv('SENDER_EMAIL') ?: 'noreply@expedisi.aplikasirt.my.id');
define('SENDER_NAME', getenv('SENDER_NAME') ?: 'LacakOngkir');

// ── OAuth ──────────────────────────────────────────────────────
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GITHUB_CLIENT_ID', getenv('GITHUB_CLIENT_ID') ?: '');
define('GITHUB_CLIENT_SECRET', getenv('GITHUB_CLIENT_SECRET') ?: '');
