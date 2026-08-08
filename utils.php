<?php
/**
 * Utility functions - Auth, Sessions, API Key validation
 * Uses DB-backed sessions for PHP built-in server compatibility
 */

require_once __DIR__ . '/db.php';

// ─── Response Helpers ───────────────────────────────────────────
function json_response($data, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($message, $code = 400, $details = null) {
    if (!headers_sent()) {
        http_response_code($code);
    }
    $data = ['success' => false, 'error' => $message];
    if ($details) $data['details'] = $details;
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Auth Helpers (DB-backed sessions) ──────────────────────────
function createSession(int $userId): string {
    $token = bin2hex(random_bytes(32));
    $pdo = Database::get();
    $stmt = $pdo->prepare('INSERT INTO sessions (token, user_id) VALUES (?, ?)');
    $stmt->execute([$token, $userId]);
    return $token;
}

function deleteSession(string $token): void {
    $pdo = Database::get();
    $stmt = $pdo->prepare('DELETE FROM sessions WHERE token = ?');
    $stmt->execute([$token]);
}

function getSession(string $token): ?array {
    if (empty($token)) return null;
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function getBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Require valid auth token - returns user data or exits
 */
function requireAuth(): ?array {
    $token = getBearerToken();
    if (!$token) {
        return null;
    }

    $session = getSession($token);
    if (!$session) {
        return null;
    }

    // Session expired (7 days)
    $createdAt = strtotime($session['created_at']);
    if (time() - $createdAt > 7 * 86400) {
        deleteSession($token);
        return null;
    }

    // Update last used
    $pdo = Database::get();
    $stmt = $pdo->prepare('UPDATE sessions SET last_used_at = CURRENT_TIMESTAMP WHERE token = ?');
    $stmt->execute([$token]);

    $stmt = $pdo->prepare('SELECT id, email, name, role, is_verified, created_at FROM users WHERE id = ?');
    $stmt->execute([$session['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Require API key - returns api_key record or exits
 */
function requireApiKey(): ?array {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';

    // Or Bearer token with api_key prefix
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+exp_api_(.+)/i', $header, $m)) {
        $key = 'exp_api_' . trim($m[1]);
    }

    if (empty($key)) {
        return null;
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare('
        SELECT ak.id, ak.user_id, ak.name, ak.is_active, ak.last_used_at, u.email
        FROM api_keys ak
        JOIN users u ON u.id = ak.user_id
        WHERE ak.key_hash = ? AND ak.is_active = 1
    ');
    $stmt->execute([hash('sha256', $key)]);
    $keyData = $stmt->fetch();

    if (!$keyData) {
        return null;
    }

    // Update last_used_at
    $stmt = $pdo->prepare('UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$keyData['id']]);

    return $keyData;
}

// ─── API Key Helpers ────────────────────────────────────────────
function generateApiKey(): array {
    $plain = 'exp_api_' . bin2hex(random_bytes(24));
    return [
        'plain' => $plain,
        'hash' => hash('sha256', $plain),
        'prefix' => substr($plain, 0, 12)
    ];
}

function validateApiKey(string $key): ?array {
    $pdo = Database::get();
    $stmt = $pdo->prepare('
        SELECT ak.*, u.email, u.name as user_name
        FROM api_keys ak
        JOIN users u ON u.id = ak.user_id
        WHERE ak.key_hash = ? AND ak.is_active = 1
    ');
    $stmt->execute([hash('sha256', $key)]);
    return $stmt->fetch() ?: null;
}

// ─── Logging ───────────────────────────────────────────────────
function logApiUsage(?int $apiKeyId, string $endpoint, string $method, int $statusCode, ?string $ip = null) {
    $pdo = Database::get();
    $stmt = $pdo->prepare('INSERT INTO api_usage (api_key_id, endpoint, method, status_code, ip_address) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$apiKeyId, $endpoint, $method, $statusCode, $ip ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
}

// ─── Password ───────────────────────────────────────────────────
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}
