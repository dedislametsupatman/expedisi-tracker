<?php
/**
 * Authentication API - Register, Login, Logout, Profile
 * Supports both /api/auth.php/action AND /api/auth.php?action=action
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/auth.php', '', $path) ?: '/';

// Read input once
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// Route: if path is /register, /login, etc. OR if action param is set
$route = '';
if ($path !== '/') {
    $route = ltrim($path, '/');
} elseif (!empty($action)) {
    $route = $action;
}

if (!empty($route)) {
    switch ($route) {
        case 'register':
            if ($method !== 'POST') { json_error('Method not allowed', 405); }
            register();
            break;
        case 'login':
            if ($method !== 'POST') { json_error('Method not allowed', 405); }
            login();
            break;
        case 'logout':
            if ($method !== 'POST') { json_error('Method not allowed', 405); }
            logout();
            break;
        case 'profile':
            profile();
            break;
        default:
            json_error('Unknown route: ' . $route, 400);
    }
    exit;
}

// Default: API info
json_response(['message' => 'Auth API - Expedisi Tracker', 'version' => '1.0']);

function register() {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $name = trim($input['name'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Email tidak valid', 400);
    }
    if (empty($password) || strlen($password) < 8) {
        json_error('Password minimal 8 karakter', 400);
    }
    if (empty($name) || strlen($name) < 2) {
        json_error('Nama minimal 2 karakter', 400);
    }

    $pdo = Database::get();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_error('Email sudah terdaftar', 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)');
    $stmt->execute([$email, $hash, $name]);
    $userId = $pdo->lastInsertId();

    // Create default API key
    $key = 'exp_' . bin2hex(random_bytes(24));
    $keyPrefix = 'exp_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $keyHash = hash('sha256', $key);
    $stmt = $pdo->prepare('INSERT INTO api_keys (user_id, name, key_hash, key_prefix) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, 'Default Key', $keyHash, $keyPrefix]);

    $token = createSession($userId);

    json_response([
        'success' => true,
        'user' => ['id' => $userId, 'email' => $email, 'name' => $name],
        'token' => $token,
        'api_key' => $key
    ]);
}

function login() {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        json_error('Email dan password diperlukan', 400);
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT id, email, name, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error('Email atau password salah', 401);
    }

    $token = createSession($user['id']);

    json_response([
        'success' => true,
        'user' => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name']],
        'token' => $token
    ]);
}

function logout() {
    $token = getBearerToken();
    if ($token) {
        deleteSession($token);
    }
    json_response(['success' => true, 'message' => 'Logged out']);
}

function profile() {
    $user = requireAuth();
    if (!$user) { json_error('Unauthorized', 401); }
    json_response(['success' => true, 'user' => $user]);
}
