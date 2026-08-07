<?php
/**
 * API Keys Management - Create, List, Revoke API Keys
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/keys.php', '', $path) ?: '/';

$user = requireAuth();
if (!$user) {
    json_error('Unauthorized', 401);
}

switch ($method) {
    case 'GET':
        if ($path === '/' || $path === '') {
            listKeys();
        } else {
            json_error('Not found', 404);
        }
        break;
    case 'POST':
        createKey();
        break;
    case 'DELETE':
        revokeKey();
        break;
    default:
        json_error('Method not allowed', 405);
}

function listKeys() {
    $pdo = Database::get();
    $userId = $GLOBALS['user']['id'];
    $stmt = $pdo->prepare('
        SELECT id, name, key_prefix, created_at, last_used_at, is_active 
        FROM api_keys WHERE user_id = ? ORDER BY created_at DESC
    ');
    $stmt->execute([$userId]);
    $keys = $stmt->fetchAll();
    
    json_response([
        'success' => true,
        'keys' => $keys
    ]);
}

function createKey() {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? 'New Key');
    
    if (empty($name)) {
        json_error('Nama key wajib diisi', 400);
    }
    
    $pdo = Database::get();
    $userId = $GLOBALS['user']['id'];
    
    $keyPlain = 'exp_api_' . bin2hex(random_bytes(24));
    $keyHash = hash('sha256', $keyPlain);
    $keyPrefix = substr($keyPlain, 0, 12);
    
    $stmt = $pdo->prepare('INSERT INTO api_keys (user_id, key_hash, key_prefix, name) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $keyHash, $keyPrefix, $name]);
    
    json_response([
        'success' => true,
        'message' => 'API Key berhasil dibuat',
        'api_key' => [
            'id' => $pdo->lastInsertId(),
            'name' => $name,
            'key' => $keyPlain,
            'prefix' => $keyPrefix,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ], 201);
}

function revokeKey() {
    $input = json_decode(file_get_contents('php://input'), true);
    $keyId = intval($input['id'] ?? 0);
    
    if (!$keyId) {
        json_error('Key ID wajib diisi', 400);
    }
    
    $pdo = Database::get();
    $userId = $GLOBALS['user']['id'];
    
    // Verify ownership
    $stmt = $pdo->prepare('SELECT id FROM api_keys WHERE id = ? AND user_id = ?');
    $stmt->execute([$keyId, $userId]);
    if (!$stmt->fetch()) {
        json_error('Key tidak ditemukan atau bukan milik Anda', 404);
    }
    
    // Soft delete - mark inactive
    $stmt = $pdo->prepare('UPDATE api_keys SET is_active = 0 WHERE id = ?');
    $stmt->execute([$keyId]);
    
    json_response([
        'success' => true,
        'message' => 'API Key berhasil direvoke'
    ]);
}
