<?php

/**
 * Database setup for Expedisi Tracker
 * Supports MySQL (production) and SQLite (development fallback)
 * DB config comes from config.php constants: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $useMysql = DB_DRIVER === 'mysql';

            if ($useMysql) {
                if (empty(DB_HOST)) {
                    throw new RuntimeException('DB_HOST is required when DB_DRIVER is mysql.');
                }
                // MySQL connection
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    DB_HOST,
                    DB_PORT ?: '3306',
                    DB_NAME
                );
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } else {
                // SQLite fallback for local dev
                $dbPath = __DIR__ . '/data/expedisi.db';
                $dir = dirname($dbPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                self::$pdo = new PDO('sqlite:' . $dbPath);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            }

            self::initTables();
        }
        return self::$pdo;
    }

    private static function initTables()
    {
        $pdo = self::$pdo;
        $useMysql = DB_DRIVER === 'mysql';

        if ($useMysql) {
            // MySQL schema
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    password_hash VARCHAR(255),
                    name VARCHAR(255) NOT NULL,
                    role VARCHAR(50) DEFAULT 'member',
                    is_verified TINYINT(1) DEFAULT 0,
                    verification_token VARCHAR(255),
                    verification_expires_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    google_id VARCHAR(255) UNIQUE,
                    google_token TEXT,
                    google_token_expiry DATETIME,
                    INDEX idx_users_email (email),
                    INDEX idx_users_google_id (google_id),
                    INDEX idx_users_verification_token (verification_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS api_keys (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    key_hash VARCHAR(255) NOT NULL UNIQUE,
                    key_prefix VARCHAR(50) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME,
                    is_active TINYINT(1) DEFAULT 1,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_api_keys_user (user_id),
                    INDEX idx_api_keys_hash (key_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS api_usage (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    api_key_id INT,
                    endpoint VARCHAR(255) NOT NULL,
                    method VARCHAR(10) NOT NULL,
                    status_code INT,
                    ip_address VARCHAR(45),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL,
                    INDEX idx_api_usage_key (api_key_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    token VARCHAR(255) PRIMARY KEY,
                    user_id INT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } else {
            // SQLite schema
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT UNIQUE NOT NULL,
                    password_hash TEXT,
                    name TEXT NOT NULL,
                    role TEXT DEFAULT 'member',
                    is_verified INTEGER DEFAULT 0,
                    verification_token TEXT,
                    verification_expires_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    google_id TEXT UNIQUE,
                    google_token TEXT,
                    google_token_expiry DATETIME
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS api_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    key_hash TEXT UNIQUE NOT NULL,
                    key_prefix TEXT NOT NULL,
                    name TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME,
                    is_active INTEGER DEFAULT 1,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS api_usage (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    api_key_id INTEGER,
                    endpoint TEXT NOT NULL,
                    method TEXT NOT NULL,
                    status_code INTEGER,
                    ip_address TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS payments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    order_id TEXT NOT NULL UNIQUE,
                    user_id INTEGER NOT NULL,
                    package TEXT NOT NULL,
                    amount INTEGER NOT NULL,
                    status TEXT NOT NULL,
                    payment_method TEXT,
                    project TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    token TEXT PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");

            // SQLite indexes
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_keys_user ON api_keys(user_id)");
            } catch (Exception $e) {
            }
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_keys_hash ON api_keys(key_hash)");
            } catch (Exception $e) {
            }
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_usage_key ON api_usage(api_key_id)");
            } catch (Exception $e) {
            }
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
            } catch (Exception $e) {
            }
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_google_id ON users(google_id)");
            } catch (Exception $e) {
            }
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_verification_token ON users(verification_token)");
            } catch (Exception $e) {
            }
        }
    }
}
