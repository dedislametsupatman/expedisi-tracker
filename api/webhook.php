<?php

/**
 * Pakasir webhook receiver for completed payments
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';

// Pakasir webhook will send JSON payload.
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    json_error('Invalid JSON payload', 400);
}

// Validate required fields.
$orderId = trim($payload['order_id'] ?? '');
$status = trim($payload['status'] ?? '');
$amount = intval($payload['amount'] ?? 0);
$project = trim($payload['project'] ?? '');
$paymentMethod = trim($payload['payment_method'] ?? '');

if (empty($orderId) || empty($status) || $amount <= 0 || empty($project)) {
    json_error('Missing required webhook fields', 400);
}

if ($project !== PAKASIR_PROJECT) {
    json_error('Project mismatch', 400);
}

// Only credit when payment is completed.
if (strtolower($status) !== 'completed') {
    json_response(['success' => true, 'message' => 'Ignored non-completed webhook.']);
}

// Use order_id to locate user and package if needed. We store webhook order IDs here.
$pdo = Database::get();
$pdo->beginTransaction();
try {
    // Create payments table if missing.
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id VARCHAR(255) UNIQUE,
        user_id INT NOT NULL,
        amount INT NOT NULL,
        status VARCHAR(50) NOT NULL,
        payment_method VARCHAR(64),
        project VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $userId = null;
    $stmt = $pdo->prepare('SELECT user_id FROM payments WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if ($row) {
        $userId = intval($row['user_id']);
    }

    if (!$userId) {
        json_error('User not found for webhook order', 404);
    }

    // Prevent duplicate crediting.
    $stmt = $pdo->prepare('SELECT id, status FROM payments WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $existing = $stmt->fetch();
    if ($existing && strtolower($existing['status']) === 'completed') {
        $pdo->commit();
        json_response(['success' => true, 'message' => 'Payment already processed']);
    }

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE payments SET status = ?, payment_method = ?, project = ?, amount = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([strtolower($status), $paymentMethod, $project, $amount, $existing['id']]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO payments (order_id, user_id, amount, status, payment_method, project) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$orderId, $userId, $amount, strtolower($status), $paymentMethod, $project]);
    }

    // Credit user.
    $stmt = $pdo->prepare('UPDATE users SET credits = COALESCE(credits, 0) + ? WHERE id = ?');
    $stmt->execute([$amount, $userId]);

    $pdo->commit();
    json_response(['success' => true, 'message' => 'User credited', 'user_id' => $userId, 'amount' => $amount]);
} catch (Exception $e) {
    $pdo->rollBack();
    json_error('Webhook processing failed: ' . $e->getMessage(), 500);
}
