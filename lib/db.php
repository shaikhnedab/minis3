<?php
// SQLite database access and helpers.

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

function db_init(): void
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        access_key TEXT NOT NULL UNIQUE,
        secret_key TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS buckets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        created_at TEXT NOT NULL,
        UNIQUE(user_id, name)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS objects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bucket_id INTEGER NOT NULL REFERENCES buckets(id) ON DELETE CASCADE,
        key TEXT NOT NULL,
        size INTEGER NOT NULL DEFAULT 0,
        etag TEXT NOT NULL DEFAULT "",
        content_type TEXT NOT NULL DEFAULT "application/octet-stream",
        meta TEXT NOT NULL DEFAULT "{}",
        last_modified TEXT NOT NULL,
        UNIQUE(bucket_id, key)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        upload_id TEXT NOT NULL UNIQUE,
        user_id INTEGER NOT NULL,
        bucket_id INTEGER NOT NULL,
        key TEXT NOT NULL,
        content_type TEXT NOT NULL DEFAULT "application/octet-stream",
        meta TEXT NOT NULL DEFAULT "{}",
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL,
        user_id INTEGER,
        kind TEXT NOT NULL DEFAULT "s3",
        ip TEXT,
        method TEXT,
        uri TEXT,
        status INTEGER,
        bytes INTEGER DEFAULT 0,
        ms INTEGER DEFAULT 0,
        user_agent TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        username TEXT NOT NULL DEFAULT "admin",
        log_s3 INTEGER NOT NULL DEFAULT 1,
        log_admin INTEGER NOT NULL DEFAULT 1,
        password_hash TEXT NOT NULL,
        totp_secret TEXT,
        trash_days INTEGER NOT NULL DEFAULT 7
    )');
    $cols = $pdo->query('PRAGMA table_info(admin)')->fetchAll();
    $colNames = [];
    foreach ($cols as $c) {
        $colNames[] = $c['name'];
    }
    if (!in_array('username', $colNames, true)) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN username TEXT NOT NULL DEFAULT "admin"');
    }
    if (!in_array('log_s3', $colNames, true)) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN log_s3 INTEGER NOT NULL DEFAULT 1');
    }
    if (!in_array('log_admin', $colNames, true)) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN log_admin INTEGER NOT NULL DEFAULT 1');
    }
    if (!in_array('totp_secret', $colNames, true)) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN totp_secret TEXT');
    }
    if (!in_array('trash_days', $colNames, true)) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN trash_days INTEGER NOT NULL DEFAULT 7');
    }
    if (!in_array('app_name', $colNames, true)) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN app_name TEXT');
    }
    if (!in_array('favicon', $colNames, true)) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN favicon TEXT');
    }
    if (!in_array('passkey_handle', $colNames, true)) {
        $pdo->exec('ALTER TABLE admin ADD COLUMN passkey_handle TEXT');
    }
    // Admin passkeys (WebAuthn / passwordless login).
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_passkeys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        credential_id TEXT NOT NULL UNIQUE,
        alg INTEGER NOT NULL,
        key TEXT NOT NULL,
        sign_count INTEGER NOT NULL DEFAULT 0,
        name TEXT NOT NULL,
        created_at TEXT NOT NULL,
        last_used TEXT
    )');
    // Per-user storage quota in bytes (0 = unlimited).
    $userCols = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $userColNames = [];
    foreach ($userCols as $c) {
        $userColNames[] = $c['name'];
    }
    if (!in_array('quota_bytes', $userColNames, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN quota_bytes INTEGER NOT NULL DEFAULT 0');
    }
    // Soft-deleted objects (admin UI) waiting for restore / expiry.
    $pdo->exec('CREATE TABLE IF NOT EXISTS trash (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        username TEXT NOT NULL,
        bucket_name TEXT NOT NULL,
        key TEXT NOT NULL,
        size INTEGER NOT NULL DEFAULT 0,
        etag TEXT NOT NULL DEFAULT "",
        content_type TEXT NOT NULL DEFAULT "application/octet-stream",
        meta TEXT NOT NULL DEFAULT "{}",
        deleted_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
    )');
    // Failed admin logins, used for rate limiting.
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        ts TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_buckets_user ON buckets(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_objects_bucket ON objects(bucket_id, key)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_ts ON logs(ts)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_uploads_bucket ON uploads(bucket_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trash_expiry ON trash(expires_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts ON login_attempts(ip, ts)');
}

function db_find_user_by_access_key(string $ak): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE access_key = ?');
    $st->execute([$ak]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_find_user_by_username(string $username): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_find_user(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_find_bucket(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM buckets WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_find_bucket_by_name(int $userId, string $name): ?array
{
    $st = db()->prepare('SELECT * FROM buckets WHERE user_id = ? AND name = ?');
    $st->execute([$userId, $name]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_find_object(int $bucketId, string $key): ?array
{
    $st = db()->prepare('SELECT * FROM objects WHERE bucket_id = ? AND key = ?');
    $st->execute([$bucketId, $key]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_find_upload(string $uploadId): ?array
{
    $st = db()->prepare('SELECT * FROM uploads WHERE upload_id = ?');
    $st->execute([$uploadId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}
