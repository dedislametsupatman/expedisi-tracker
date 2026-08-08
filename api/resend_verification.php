<?php
/**
 * Public resend verification - standalone endpoint (avoids opcache)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../email.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = trim($input['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email tidak valid']);
    exit;
}

$pdo = Database::get();
$stmt = $pdo->prepare('SELECT id, email, name, is_verified FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    // Don't reveal whether email exists - return success anyway
    echo json_encode(['success' => true, 'message' => 'Jika email belum diverifikasi, link telah dikirim.']);
    exit;
}

if ($user['is_verified']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email sudah diverifikasi. Silakan login.']);
    exit;
}

// Generate new token
$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', time() + 86400);

$stmt = $pdo->prepare('UPDATE users SET verification_token = ?, verification_expires_at = ? WHERE id = ?');
$stmt->execute([$token, $expiresAt, $user['id']]);

$sent = EmailService::sendVerificationEmail($user['email'], $user['name'], $token);

if ($sent) {
    echo json_encode(['success' => true, 'message' => 'Email verifikasi telah dikirim!', 'token' => $token]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal mengirim email. Brevo API key mungkin invalid atau sender belum diverifikasi.']);
}
