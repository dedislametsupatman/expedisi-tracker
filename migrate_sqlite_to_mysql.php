<?php

/**
 * SQLite -> MySQL migration helper for Expedisi Tracker.
 * Run with: php migrate_sqlite_to_mysql.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (DB_DRIVER !== 'mysql') {
    echo "DB_DRIVER is not set to mysql. Update .env or environment and rerun.\n";
    exit(1);
}

$sqlitePath = __DIR__ . '/data/expedisi.db';
if (!file_exists($sqlitePath)) {
    echo "SQLite database not found at $sqlitePath\n";
    exit(1);
}

$available = PDO::getAvailableDrivers();
if (!in_array('sqlite', $available, true)) {
    echo "SQLite PDO driver is not installed for this PHP binary.\n";
    echo "Install the sqlite3 extension or run this script in the Docker container defined by Dockerfile,\n";
    echo "then retry.\n";
    echo "Available PDO drivers: " . implode(', ', $available) . "\n";
    exit(1);
}

try {
    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Failed to open SQLite database: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $mysql = Database::get();
} catch (PDOException $e) {
    echo "MySQL connection failed: " . $e->getMessage() . "\n";
    echo "DSN used: ";
    if (defined('DB_SOCKET') && DB_SOCKET) {
        echo "unix_socket=" . DB_SOCKET . ";dbname=" . DB_NAME . "\n";
    } else {
        echo "host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . "\n";
    }
    exit(1);
}

function copyTable(PDO $src, PDO $dst, string $table, array $columns, string $pk = 'id')
{
    echo "Migrating $table...\n";
    $dst->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $dst->exec("TRUNCATE TABLE `$table`");
    $dst->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $rows = $src->query("SELECT " . implode(', ', array_map(function ($c) {
        return "`$c`";
    }, $columns)) . " FROM `$table`")->fetchAll();
    if (empty($rows)) {
        echo "  no rows found, skipping.\n";
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $dst->prepare("INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES ($placeholders)");
    foreach ($rows as $row) {
        $stmt->execute(array_values($row));
    }
    echo "  migrated " . count($rows) . " rows.\n";
}

$tables = [
    'users' => [
        'id',
        'email',
        'password_hash',
        'name',
        'role',
        'is_verified',
        'verification_token',
        'verification_expires_at',
        'created_at',
        'updated_at',
        'google_id',
        'google_token',
        'google_token_expiry'
    ],
    'api_keys' => [
        'id',
        'user_id',
        'key_hash',
        'key_prefix',
        'name',
        'created_at',
        'last_used_at',
        'is_active'
    ],
    'api_usage' => [
        'id',
        'api_key_id',
        'endpoint',
        'method',
        'status_code',
        'ip_address',
        'created_at'
    ],
    'sessions' => [
        'token',
        'user_id',
        'created_at',
        'last_used_at'
    ],
];

$mysql->beginTransaction();
try {
    foreach ($tables as $table => $cols) {
        copyTable($sqlite, $mysql, $table, $cols);
    }
    $mysql->commit();
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    if ($mysql->inTransaction()) {
        $mysql->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
