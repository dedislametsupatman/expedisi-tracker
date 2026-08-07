<?php
/**
 * Authentication API - Register, Login, Logout, Profile
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

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/auth.php', '', $path) ?: '/';

switch ($path) {
    case '/register':
        if ($method !== 'POST') { json_error('Method not allowed', 405); }
        register();
        break;
    case '/login':
        if ($method !== 'POST') { json_error('Method not allowed', 405); }
        login();
        break;
    case '/logout':
        if ($method !== 'POST') { json_error('Method not allowed', 405); }
        logout();
        break;
    case '/profile':
        profile();
        break;
    case '/':
        json_response(['message' => 'Auth API - Expedisi Tracker', 'version' => '1.0']);
        break;
    default:
        json_error('Not found', 404);
}

function register() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $name = trim($input['name'] ?? '');
    
    // Validation
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
    
    // Check existing
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_error('Email sudah terdaftar', 409);
    }
    
    // Create user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)');
    $stmt->execute([$email, $hash, $name]);
    $userId = $pdo->lastInsertId();
    
    // Generate default API key
    $keyPlain = bin2hex(random_bytes(24)); // 48 char key
    $keyHash = hash('sha256', $keyPlain);
    $keyPrefix = substr($keyPlain, 0, 8);
    
    $stmt = $pdo->prepare('INSERT INTO api_keys (user_id, key_hash, key_prefix, name) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $keyHash, $keyPrefix, 'Default Key']);
    
    // Create session
    $token = createSession($userId);
    
    json_response([
        'message' => 'Registrasi berhasil',
        'user' => ['id' => $userId, 'email' => $email, 'name' => $name],
        'token' => $token,
        'api_key' => $keyPlain
    ], 201);
}

function login() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        json_error('Email dan password wajib diisi', 400);
    }
    
    $pdo = Database::get();
    
    $stmt = $pdo->prepare('SELECT id, email, password_hash, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error('Email atau password salah', 401);
    }
    
    $token = createSession($user['id']);
    
    json_response([
        'message' => 'Login berhasil',
        'user' => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name']],
        'token' => $token
    ]);
}

function logout() {
    $token = getBearerToken();
    if ($token) {
        deleteSession($token);
    }
    json_response(['message' => 'Logout berhasil']);
}

function profile() {
    $user = requireAuth();
    if (!$user) {
        json_error('Unauthorized', 401);
    }
    
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT id, email, name, created_at FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $userData = $stmt->fetch();
    
    json_response(['user' => $userData]);
}
