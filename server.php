<?php
// Simple router: serve static files or proxy API calls
require_once __DIR__ . '/config.php';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve index.html for non-file paths
if ($uri === '/' || !is_file(__DIR__ . $uri)) {
    readfile(__DIR__ . '/index.html');
    return;
}

// Proxy API calls
if (strpos($uri, '/proxy.php') === 0) {
    $path = isset($_GET['path']) ? urldecode($_GET['path']) : '';
    if (empty($path)) {
        header('Content-Type: application/json');
        echo json_encode(['is_success' => false, 'message' => 'Missing path']);
        return;
    }
    $api_key = API_CO_ID_KEY;
    $url = 'https://use.api.co.id/expedition' . $path;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => [
            'x-api-co-id: ' . $api_key,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        header('Content-Type: application/json');
        echo json_encode(['is_success' => false, 'message' => curl_error($ch)]);
    } else {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo $response;
    }
    curl_close($ch);
    return;
}

readfile(__DIR__ . $uri);
