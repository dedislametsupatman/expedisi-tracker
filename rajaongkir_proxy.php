<?php
// Rajaongkir API Proxy
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, key');

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$api_key = RAJAONGKIR_KEY;
$base_url = RAJAONGKIR_BASE_URL;

$request_uri = $_SERVER['REQUEST_URI'];
$path = preg_replace('#^/api/rajaongkir/#', '', $request_uri);
$url = $base_url . '/' . $path;

$post_data = file_get_contents('php://input');
$headers = ['key: ' . $api_key];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['meta' => ['message' => $error, 'code' => 500, 'status' => 'error']]);
} else {
    echo $response;
}
?>
