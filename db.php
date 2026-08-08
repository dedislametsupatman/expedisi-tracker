<?php
/**
 * Database setup for Expedisi Tracker
 * Uses SQLite - no external DB needed
 */

class Database {
    private static ?PDO $pdo = null;
    
    public static function get(): PDO {
        if (self::$pdo === null) {
            $dbPath = __DIR__ . '/data/expedisi.db';
            
            // Create data directory if not exists
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            self::$pdo = new PDO('sqlite:' . $dbPath);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::initTables();
        }
        return self::$pdo;
    }
    
    private static function initTables() {
        $pdo = self::$pdo;
        
        // Users table
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
        
        // API keys table
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
        
        // API usage log
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

        // Sessions table (DB-backed for PHP built-in server)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                token TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        
        // Create indexes (use try-catch for older DBs)
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_keys_user ON api_keys(user_id)"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_keys_hash ON api_keys(key_hash)"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_usage_key ON api_usage(api_key_id)"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_google_id ON users(google_id)"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_verification_token ON users(verification_token)"); } catch (Exception $e) {}
    }
}
