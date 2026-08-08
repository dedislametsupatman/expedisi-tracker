<?php
// API.co.id Expedition Cost Proxy - handles GET requests with village codes
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-api-co-id, origin');

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$api_key = API_CO_ID_KEY;

$path = isset($_GET['path']) ? urldecode($_GET['path']) : '';

if (empty($path)) {
    echo json_encode(['is_success' => false, 'message' => 'Missing path', 'data' => null]);
    exit;
}

$url = 'https://use.api.co.id/expedition' . $path;
$method = $_SERVER['REQUEST_METHOD'];

$headers = [
    'x-api-co-id: ' . $api_key,
    'Accept: application/json'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

if ($method === 'POST') {
    $body = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $headers[] = 'Content-Type: application/json';
}

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

if ($error) {
    echo json_encode(['is_success' => false, 'message' => $error, 'data' => null]);
} else {
    echo $response;
}
?>
