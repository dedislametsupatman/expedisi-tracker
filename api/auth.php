<?php

/**
 * Authentication API - Register, Login, Logout, Profile, Verify, Google OAuth
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../email.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/auth.php', '', $path) ?: '/';

// Read input once
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// Route: path takes priority, then action param
$route = '';
if ($path !== '/') {
    $route = ltrim($path, '/');
} elseif (!empty($action)) {
    $route = $action;
}

// ─── PUBLIC: Email Verification (GET with token) ──────────────────
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'verify') {
    $token = $_GET['token'] ?? '';
    verifyEmail($token);
    exit;
}

// ─── API Routes ───────────────────────────────────────────────────
if (!empty($route)) {
    switch ($route) {
        case 'register':
            if ($method !== 'POST') {
                json_error('Method not allowed', 405);
            }
            register();
            break;
        case 'login':
            if ($method !== 'POST') {
                json_error('Method not allowed', 405);
            }
            login();
            break;
        case 'logout':
            if ($method !== 'POST') {
                json_error('Method not allowed', 405);
            }
            logout();
            break;
        case 'profile':
            profile();
            break;
        case 'verify-resend':
            verifyResend();
            break;
        case 'reset-password-request':
            resetPasswordRequest();
            break;
        case 'reset-password':
            resetPassword();
            break;
        case 'google-callback':
            googleCallback();
            break;
        case 'google-token':
            googleToken();
            break;
        // Admin routes
        case 'admin/users':
            requireAdmin();
            listUsers();
            break;
        case 'admin/keys':
            requireAdmin();
            listAllKeys();
            break;
        default:
            json_error('Unknown route: ' . $route, 400);
    }
    exit;
}

// Default: API info
json_response(['message' => 'Auth API - Expedisi Tracker', 'version' => '2.0']);

// ─── Registration ─────────────────────────────────────────────────
function register()
{
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

    // Check existing user
    $stmt = $pdo->prepare('SELECT id, is_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    $verificationToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours

    if ($existing) {
        if ($existing['is_verified']) {
            json_error('Email sudah terdaftar', 409);
        }
        // Resend verification for unverified user
        $stmt = $pdo->prepare('UPDATE users SET name=?, password_hash=?, verification_token=?, verification_expires_at=? WHERE id=?');
        $stmt->execute([$name, password_hash($password, PASSWORD_DEFAULT), $verificationToken, $expiresAt, $existing['id']]);
        $userId = $existing['id'];
    } else {
        // New user
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, verification_token, verification_expires_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, $verificationToken, $expiresAt]);
        $userId = $pdo->lastInsertId();
    }

    // Send verification email
    EmailService::sendVerificationEmail($email, $name, $verificationToken);

    json_response([
        'success' => true,
        'message' => 'Registrasi berhasil! Cek email untuk verifikasi.',
        'email' => $email,
        'requires_verification' => true
    ], 201);
}

// ─── Login ────────────────────────────────────────────────────────
function login()
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        json_error('Email dan password diperlukan', 400);
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT id, email, name, password_hash, is_verified, role FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error('Email atau password salah', 401);
    }

    if (!$user['is_verified']) {
        json_error('Email belum diverifikasi. Cek inbox email kamu.', 403);
    }

    $token = createSession($user['id']);

    json_response([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role']
        ],
        'token' => $token
    ]);
}

// ─── Logout ───────────────────────────────────────────────────────
function logout()
{
    $token = getBearerToken();
    if ($token) {
        deleteSession($token);
    }
    json_response(['success' => true, 'message' => 'Logged out']);
}

// ─── Profile ──────────────────────────────────────────────────────
function profile()
{
    $user = requireAuth();
    if (!$user) {
        json_error('Unauthorized', 401);
    }
    json_response(['success' => true, 'user' => $user]);
}

// ─── Verify Email ─────────────────────────────────────────────────
function verifyEmail(string $token)
{
    if (empty($token)) {
        renderVerificationPage(false, 'Token tidak valid', 'Tautan verifikasi tidak sah. Silakan coba lagi atau minta email verifikasi baru.', 'Kembali ke login', '/');
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT id, email, name, is_verified FROM users WHERE verification_token = ? AND verification_expires_at > ?');
    $stmt->execute([$token, date('Y-m-d H:i:s')]);
    $user = $stmt->fetch();

    if (!$user) {
        renderVerificationPage(false, 'Verifikasi gagal', 'Token tidak valid atau sudah kedaluwarsa. Minta email verifikasi baru melalui halaman login.', 'Kembali ke login', '/');
    }

    if ($user['is_verified']) {
        renderVerificationPage(false, 'Sudah diverifikasi', 'Email kamu sudah terdaftar dan diverifikasi. Silakan login kembali untuk melanjutkan.', 'Masuk sekarang', '/');
    }

    // Mark as verified
    $stmt = $pdo->prepare('UPDATE users SET is_verified = 1, verification_token = NULL, verification_expires_at = NULL WHERE id = ?');
    $stmt->execute([$user['id']]);

    // Create default API key
    $key = 'exp_' . bin2hex(random_bytes(24));
    $keyPrefix = 'exp_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $keyHash = hash('sha256', $key);
    $stmt = $pdo->prepare('INSERT INTO api_keys (user_id, name, key_hash, key_prefix) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user['id'], 'Default Key', $keyHash, $keyPrefix]);

    // Send welcome email with API key
    EmailService::sendWelcomeEmail($user['email'], $user['name'], $key);

    renderVerificationPage(true, 'Verifikasi Berhasil!', 'Email kamu sudah diverifikasi. Silakan login kembali untuk masuk ke LacakOngkir.', 'Masuk sekarang', '/');
}

function renderVerificationPage(bool $success, string $title, string $message, string $buttonText, string $buttonLink)
{
    http_response_code($success ? 200 : 400);
    $bgColor = $success ? '#e6f4ea' : '#fff1f0';
    $textColor = $success ? '#0b5a2b' : '#a12020';
    $buttonBg = $success ? '#0b5a2b' : '#9a1b1b';
    $icon = $success ? '✓' : '✕';
    echo '<!DOCTYPE html>';
    echo '<html lang="id">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<style>';
    echo 'body{margin:0;font-family:Inter,system-ui,Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#111;}';
    echo '.container{max-width:520px;margin:72px auto;padding:32px;background:#fff;border-radius:24px;box-shadow:0 24px 80px rgba(15,23,42,.12);}';
    echo '.banner{display:flex;align-items:center;justify-content:center;width:96px;height:96px;margin:0 auto 24px;border-radius:50%;background:' . $bgColor . ';color:' . $textColor . ';font-size:44px;box-shadow:inset 0 0 0 1px rgba(15,23,42,.08);}';
    echo '.title{margin:0 0 14px;font-size:28px;font-weight:700;text-align:center;}';
    echo '.message{margin:0 0 28px;font-size:16px;line-height:1.8;text-align:center;color:#334155;}';
    echo '.button{display:inline-flex;align-items:center;justify-content:center;padding:14px 24px;font-size:16px;font-weight:600;color:#fff;background:' . $buttonBg . ';border:none;border-radius:12px;text-decoration:none;cursor:pointer;}';
    echo '.info{margin-top:20px;padding:18px 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;color:#475569;font-size:14px;line-height:1.7;}';
    echo '.info strong{color:#0f172a;}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="container">';
    echo '<div class="banner">' . $icon . '</div>';
    echo '<h1 class="title">' . htmlspecialchars($title) . '</h1>';
    echo '<p class="message">' . htmlspecialchars($message) . '</p>';
    echo '<div style="text-align:center;"><a class="button" href="' . htmlspecialchars($buttonLink) . '">' . htmlspecialchars($buttonText) . '</a></div>';
    echo '<div class="info"><strong>Tips:</strong> gunakan email dan password yang kamu daftarkan untuk masuk. Jika belum menerima email, periksa folder spam atau kirim ulang verifikasi dari halaman login.</div>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
    exit;
}

// ─── Resend Verification ──────────────────────────────────────────
function verifyResend()
{
    $user = requireAuth();
    if (!$user) {
        json_error('Unauthorized', 401);
    }
    if ($user['is_verified']) {
        json_error('Email sudah diverifikasi', 400);
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 86400);

    $pdo = Database::get();
    $stmt = $pdo->prepare('UPDATE users SET verification_token = ?, verification_expires_at = ? WHERE id = ?');
    $stmt->execute([$token, $expiresAt, $user['id']]);

    EmailService::sendVerificationEmail($user['email'], $user['name'], $token);

    json_response(['success' => true, 'message' => 'Email verifikasi telah dikirim ulang']);
}

// ─── Password Reset Request ────────────────────────────────────────
function resetPasswordRequest()
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim($input['email'] ?? '');

    if (empty($email)) {
        json_error('Email diperlukan', 400);
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ? AND is_verified = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always return success to prevent email enumeration
    if (!$user) {
        json_response(['success' => true, 'message' => 'Jika email exists, reset link telah dikirim']);
        return;
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    $stmt = $pdo->prepare('UPDATE users SET verification_token = ?, verification_expires_at = ? WHERE id = ?');
    $stmt->execute([$token, $expiresAt, $user['id']]);

    EmailService::sendPasswordResetEmail($email, $user['name'], $token);

    json_response(['success' => true, 'message' => 'Jika email exists, reset link telah dikirim']);
}

// ─── Password Reset ───────────────────────────────────────────────
function resetPassword()
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['token'] ?? '';
    $newPassword = $input['password'] ?? '';

    if (empty($token) || empty($newPassword)) {
        json_error('Token dan password baru diperlukan', 400);
    }
    if (strlen($newPassword) < 8) {
        json_error('Password minimal 8 karakter', 400);
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE verification_token = ? AND verification_expires_at > ?');
    $stmt->execute([$token, date('Y-m-d H:i:s')]);
    $user = $stmt->fetch();

    if (!$user) {
        json_error('Token tidak valid atau sudah kadaluarsa', 400);
    }

    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, verification_token = NULL, verification_expires_at = NULL WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);

    json_response(['success' => true, 'message' => 'Password berhasil direset. Silakan login dengan password baru.']);
}

// ─── Google OAuth Callback ────────────────────────────────────────
function googleCallback()
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $googleToken = $input['google_token'] ?? '';

    if (empty($googleToken)) {
        json_error('Google token diperlukan', 400);
    }

    // Verify Google token with Google's tokeninfo endpoint
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['access_token' => $googleToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $tokenInfo = json_decode($response, true);
    if (empty($tokenInfo['sub']) || empty($tokenInfo['email'])) {
        json_error('Invalid Google token', 401);
    }

    $googleId = $tokenInfo['sub'];
    $email = $tokenInfo['email'];
    $name = $tokenInfo['name'] ?? explode('@', $email)[0];
    $picture = $tokenInfo['picture'] ?? '';

    $pdo = Database::get();

    // Check if user exists by Google ID
    $stmt = $pdo->prepare('SELECT id, email, name, is_verified, role FROM users WHERE google_id = ?');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    if (!$user) {
        // New Google user - create account (auto-verified via Google)
        $stmt = $pdo->prepare('SELECT id, email, name, is_verified, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existingEmail = $stmt->fetch();

        if ($existingEmail) {
            // Link Google account to existing email user
            $stmt = $pdo->prepare('UPDATE users SET google_id = ?, name = COALESCE(name, ?) WHERE id = ?');
            $stmt->execute([$googleId, $name, $existingEmail['id']]);
            $user = $existingEmail;
            $user['google_id'] = $googleId;
            $user['name'] = $name;
        } else {
            // Create new user
            $stmt = $pdo->prepare('INSERT INTO users (email, name, google_id, is_verified) VALUES (?, ?, ?, 1)');
            $stmt->execute([$email, $name, $googleId]);
            $userId = $pdo->lastInsertId();

            // Create default API key
            $key = 'exp_' . bin2hex(random_bytes(24));
            $keyPrefix = 'exp_' . substr(bin2hex(random_bytes(4)), 0, 8);
            $keyHash = hash('sha256', $key);
            $stmt = $pdo->prepare('INSERT INTO api_keys (user_id, name, key_hash, key_prefix) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, 'Default Key', $keyHash, $keyPrefix]);

            $user = [
                'id' => $userId,
                'email' => $email,
                'name' => $name,
                'is_verified' => 1,
                'role' => 'member'
            ];

            // Send welcome email
            EmailService::sendWelcomeEmail($email, $name, $key);
        }
    }

    $sessionToken = createSession($user['id']);

    json_response([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role']
        ],
        'token' => $sessionToken
    ]);
}

// ─── Google Token (alternative - use Google's auth code flow) ───
function googleToken()
{
    json_error('Google OAuth requires client-side token from Google Sign-In', 400);
}

// ─── Admin: List Users ───────────────────────────────────────────
function listUsers()
{
    $pdo = Database::get();
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(10, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare('SELECT id, email, name, role, is_verified, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->execute([$limit, $offset]);
    $users = $stmt->fetchAll();

    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    $total = $stmt->fetchColumn();

    json_response([
        'success' => true,
        'users' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}

// ─── Admin: List All API Keys ────────────────────────────────────
function listAllKeys()
{
    $pdo = Database::get();
    $stmt = $pdo->query('
        SELECT ak.id, ak.name, ak.key_prefix, ak.is_active, ak.created_at, ak.last_used_at, u.email as owner_email
        FROM api_keys ak
        JOIN users u ON u.id = ak.user_id
        ORDER BY ak.created_at DESC
        LIMIT 100
    ');
    $keys = $stmt->fetchAll();

    json_response(['success' => true, 'keys' => $keys]);
}

// ─── Require Admin ────────────────────────────────────────────────
function requireAdmin()
{
    $user = requireAuth();
    if (!$user) {
        json_error('Unauthorized', 401);
    }
    if ($user['role'] !== 'admin') {
        json_error('Forbidden - Admin only', 403);
    }
    return $user;
}
