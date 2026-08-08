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
        $action = $_GET['action'] ?? '';
        if ($action === 'usage') {
            getUsage();
        } elseif ($action === 'billing') {
            getBilling();
        } elseif ($path === '/' || $path === '') {
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

function listKeys()
{
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

function getUsage()
{
    $pdo = Database::get();
    $userId = $GLOBALS['user']['id'];
    $today = date('Y-%m-%d');
    $month = date('Y-%m');

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS count FROM api_usage au JOIN api_keys ak ON ak.id = au.api_key_id WHERE ak.user_id = ? AND strftime("%Y-%m-%d", au.created_at) = ?'
    );
    $stmt->execute([$userId, $today]);
    $todayCount = intval($stmt->fetchColumn());

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS count FROM api_usage au JOIN api_keys ak ON ak.id = au.api_key_id WHERE ak.user_id = ? AND strftime("%Y-%m", au.created_at) = ?'
    );
    $stmt->execute([$userId, $month]);
    $monthCount = intval($stmt->fetchColumn());

    $history = [];
    for ($i = 5; $i >= 0; $i--) {
        $period = date('Y-m', strtotime("-$i months"));
        $label = date('M', strtotime("-$i months"));
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS count FROM api_usage au JOIN api_keys ak ON ak.id = au.api_key_id WHERE ak.user_id = ? AND strftime("%Y-%m", au.created_at) = ?'
        );
        $stmt->execute([$userId, $period]);
        $history[] = [
            'month' => $label,
            'calls' => intval($stmt->fetchColumn())
        ];
    }

    $plan = $GLOBALS['user']['role'] === 'admin' ? 'Standard' : 'Free';
    $quota = $plan === 'Standard' ? 5000 : 1000;

    json_response([
        'success' => true,
        'usage' => [
            'callsToday' => $todayCount,
            'callsThisMonth' => $monthCount,
            'quota' => $quota,
            'plan' => $plan,
            'history' => $history
        ]
    ]);
}

function getBilling()
{
    $pdo = Database::get();
    $user = $GLOBALS['user'];
    $stmt = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $credits = intval($stmt->fetchColumn() ?: 0);

    $plan = $user['role'] === 'admin' ? 'Standard' : 'Free';
    $quota = $plan === 'Standard' ? 5000 : 1000;
    $nextBilling = date('Y-m-d', strtotime('+30 days'));
    $paymentMethod = $user['role'] === 'admin' ? [
        'bank' => 'BCA',
        'account' => '1234 5678 9012',
    ] : [
        'gateway' => 'Pakasir',
        'project' => PAKASIR_PROJECT ?: 'not configured'
    ];

    json_response([
        'success' => true,
        'billing' => [
            'plan' => $plan,
            'credits' => $credits,
            'quota' => $quota,
            'nextBilling' => $nextBilling,
            'paymentMethod' => $paymentMethod
        ]
    ]);
}

function createKey()
{
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

function revokeKey()
{
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
