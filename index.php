<?php

/**
 * Main Router - serves index.html or routes API calls
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ─── API Routes ───────────────────────────────────────────────
if (strpos($uri, '/api/auth') === 0) {
    require __DIR__ . '/api/auth.php';
    exit;
}

if (strpos($uri, '/api/keys') === 0) {
    require __DIR__ . '/api/keys.php';
    exit;
}

if (strpos($uri, '/api/payment') === 0) {
    require __DIR__ . '/api/payment.php';
    exit;
}

if (strpos($uri, '/api/webhook') === 0) {
    require __DIR__ . '/api/webhook.php';
    exit;
}

if (strpos($uri, '/api/v1') === 0) {
    require __DIR__ . '/api/v1.php';
    exit;
}

// ─── Serve static files ──────────────────────────────────────
$filePath = __DIR__ . $uri;

// Serve assets
if ($uri !== '/' && is_file($filePath)) {
    $ext = pathinfo($uri, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
        readfile($filePath);
        exit;
    }
}

// Serve index.html for everything else
readfile(__DIR__ . '/index.html');
