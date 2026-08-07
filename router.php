<?php
/**
 * Built-in server router for php -S
 */
$uri = $_SERVER['REQUEST_URI'];

// API routes
if (strpos($uri, '/api/') === 0) {
    // API routes
    if (strpos($uri, '/api/auth') === 0) {
        require __DIR__ . '/api/auth.php';
    } elseif (strpos($uri, '/api/keys') === 0) {
        require __DIR__ . '/api/keys.php';
    } elseif (strpos($uri, '/api/v1') === 0) {
        require __DIR__ . '/api/v1.php';
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'API endpoint not found']);
    }
    exit;
}

// Static assets
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    $ext = pathinfo($uri, PATHINFO_EXTENSION);
    $types = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        readfile($file);
        exit;
    }
}

// Everything else → index.html
readfile(__DIR__ . '/index.html');
