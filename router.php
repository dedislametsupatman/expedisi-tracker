<?php
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Explicit route for proxy.php BEFORE file check (avoids PHP built-in file execution quirk)
if ($path === '/proxy.php' || strpos($path, '/proxy.php') === 0) {
    require __DIR__ . '/proxy.php';
    exit;
}

// API routes
if (strpos($path, '/api/') === 0 || $path === '/auth.php' || $path === '/payment.php' || $path === '/resend_verification.php' || $path === '/spx-track.php') {
    if (strpos($path, '/api/auth') === 0 || $path === '/auth.php') {
        require __DIR__ . '/api/auth.php';
    } elseif (strpos($path, '/api/keys') === 0) {
        require __DIR__ . '/api/keys.php';
    } elseif (strpos($path, '/api/v1') === 0) {
        require __DIR__ . '/api/v1.php';
    } elseif (strpos($path, '/api/resend_verification') === 0 || $path === '/resend_verification.php') {
        require __DIR__ . '/api/resend_verification.php';
    } elseif (strpos($path, '/api/spx-track') === 0 || $path === '/spx-track.php') {
        require __DIR__ . '/api/spx-track.php';
    } elseif (strpos($path, '/api/payment') === 0 || $path === '/payment.php') {
        require __DIR__ . '/api/payment.php';
    } elseif (strpos($path, '/api/webhook') === 0) {
        require __DIR__ . '/api/webhook.php';
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'API endpoint not found']);
    }
    exit;
}

// Static assets
$file = __DIR__ . $uri;
if ($path !== '/' && is_file($file)) {
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon'];
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        readfile($file);
        exit;
    }
}

// SPA fallback
readfile(__DIR__ . '/index.html');
