<?php

/**
 * Payment gateway integration for Pakasir
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';

$user = requireAuth();
if (!$user) {
    json_error('Unauthorized', 401);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = trim($input['order_id'] ?? '');
$amount = intval($input['amount'] ?? 0);
$pack = trim($input['package'] ?? '');
$paymentMethod = trim($input['payment_method'] ?? 'qris');

if (empty(PAKASIR_PROJECT) || empty(PAKASIR_API_KEY)) {
    json_error('Pakasir configuration is missing. Set PAKASIR_PROJECT and PAKASIR_API_KEY in .env.', 500);
}

if ($amount <= 0 || empty($orderId) || empty($pack)) {
    json_error('order_id, package, and amount are required.', 400);
}

// Build order ID to keep it unique for this user and package
$orderId = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', $orderId));
if (strlen($orderId) === 0) {
    json_error('order_id is invalid.', 400);
}

$payload = [
    'project' => PAKASIR_PROJECT,
    'order_id' => $orderId,
    'amount' => $amount,
    'api_key' => PAKASIR_API_KEY,
];

$url = 'https://app.pakasir.com/api/transactioncreate/' . urlencode($paymentMethod);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    json_error('Failed to contact Pakasir: ' . $curlError, 502);
}

$data = json_decode($response, true);
if (!is_array($data) || $httpCode !== 200) {
    json_error('Pakasir returned an error: ' . ($data['message'] ?? $response), 502);
}

$payment = $data['payment'] ?? $data;

$paymentUrl = $payment['payment_url'] ?? null;
if (!$paymentUrl && !empty(PAKASIR_PROJECT)) {
    $paymentUrl = sprintf(
        'https://app.pakasir.com/pay/%s/%s?order_id=%s',
        urlencode(PAKASIR_PROJECT),
        urlencode($amount),
        urlencode($orderId)
    );
}

$pdo = Database::get();
$stmt = $pdo->prepare('SELECT id FROM payments WHERE order_id = ?');
$stmt->execute([$orderId]);
$existingPayment = $stmt->fetch();
if ($existingPayment) {
    $stmt = $pdo->prepare('UPDATE payments SET user_id = ?, package = ?, amount = ?, status = ?, payment_method = ?, project = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?');
    $stmt->execute([$user['id'], $pack, $amount, 'pending', $paymentMethod, PAKASIR_PROJECT, $orderId]);
} else {
    $stmt = $pdo->prepare('INSERT INTO payments (order_id, user_id, package, amount, status, payment_method, project) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$orderId, $user['id'], $pack, $amount, 'pending', $paymentMethod, PAKASIR_PROJECT]);
}

json_response([
    'success' => true,
    'payment' => $payment,
    'payment_url' => $paymentUrl,
    'order_id' => $orderId,
    'package' => $pack,
    'amount' => $amount,
    'method' => $paymentMethod
]);
