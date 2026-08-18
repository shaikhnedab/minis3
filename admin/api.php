<?php
// Admin JSON API. Sessions + CSRF protection.
// Endpoints: me, login, logout, change_password, users, buckets, objects, logs.

declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require APP_ROOT . '/lib/util.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/s3.php';
require APP_ROOT . '/lib/webauthn.php';
require APP_ROOT . '/lib/log.php';

db_init();

const ADMIN_TEXT_MAX = 512 * 1024;

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
// Keep server-side sessions alive as long as the cookie (30 days). The PHP
// default GC lifetime is 24 minutes, so closing the browser and returning
// later would otherwise log the user out even though the cookie persists.
ini_set('session.gc_maxlifetime', (string)(30 * 86400));
session_set_cookie_params(['lifetime' => 30 * 86400, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    admin_route($method, $action);
} catch (S3Exception $e) {
    admin_err($e->getMessage(), $e->s3_status);
} catch (Throwable $e) {
    @error_log('MiniS3 admin error: ' . $e->getMessage());
    admin_err('Internal error', 500);
}

function admin_json($data, int $status = 200): void
{
    $action = $_GET['action'] ?? '';
    if (LOG_REQUESTS && $action !== 'me') {
        $uri = $action;
        if ($status >= 400 && is_array($data) && isset($data['error'])) {
            $uri .= '?err=' . urlencode((string)$data['error']);
        }
        s3_log_row(null, 'admin', $_SERVER['REQUEST_METHOD'], $uri, $status, 0, null);
    }
    http_response_code($status);
    $out = json_encode($data);
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

function admin_ok($data = null): void
{
    admin_json(['ok' => true, 'data' => $data]);
}

function admin_err(string $msg, int $status = 400): void
{
    admin_json(['ok' => false, 'error' => $msg], $status);
}

function admin_require_login(): void
{
    if (empty($_SESSION['admin'])) {
        admin_err('Not logged in', 401);
    }
}

function admin_require_csrf(): void
{
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($t === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) {
        admin_err('Invalid CSRF token', 403);
    }
}

// Brute-force protection: at most 6 failed logins per IP per 15 minutes.
function admin_rate_limited(string $ip): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ts > datetime('now', '-15 minutes')");
    $st->execute([$ip]);
    return (int)$st->fetchColumn() >= 6;
}

function admin_record_failed_login(string $ip): void
{
    $st = db()->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)');
    $st->execute([$ip, gmdate('Y-m-d H:i:s')]);
    db()->exec("DELETE FROM login_attempts WHERE ts < datetime('now', '-1 hour')");
}

function admin_route(string $method, string $action): void
{
    switch ($action) {
        case 'login':
            $ip = s3_client_ip();
            if (admin_rate_limited($ip)) {
                admin_err('Too many failed attempts. Try again in a few minutes.', 429);
            }
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
            $st = db()->prepare('SELECT username, password_hash, totp_secret FROM admin WHERE id = 1');
            $st->execute();
            $row = $st->fetch();
            if ($row === false) {
                admin_err('Admin account not initialized. Run install.php first.', 500);
            }
            if ($username === '' || $username !== $row['username'] || !password_verify($password, $row['password_hash'])) {
                admin_record_failed_login($ip);
                usleep(500000);
                admin_err('Invalid username or password', 403);
            }
            if ((string)$row['totp_secret'] !== '' && !totp_verify((string)$row['totp_secret'], $code)) {
                admin_json(['ok' => false, 'error' => 'Enter your two-factor authentication code.', 'totp' => true], 401);
            }
            $st = db()->prepare('DELETE FROM login_attempts WHERE ip = ?');
            $st->execute([$ip]);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $row = db()->query('SELECT username, log_s3, log_admin, totp_secret FROM admin WHERE id = 1')->fetch();
            if ($row === false) {
                admin_err('Admin account not initialized. Run install.php first.', 500);
            }
            admin_ok(['csrf' => $_SESSION['csrf'], 'username' => $row['username'], 'log_s3' => (int)$row['log_s3'], 'log_admin' => (int)$row['log_admin'], 'totp' => (string)$row['totp_secret'] !== '', 'trash_days' => (int)$row['trash_days'], 'app_name' => app_name(), 'favicon' => favicon_ext(), 'version' => APP_VERSION]);

        case 'logout':
            $_SESSION = [];
            session_destroy();
            admin_ok();

        case 'me':
            if (empty($_SESSION['admin'])) {
                admin_err('Not logged in', 401);
            }
            if (empty($_SESSION['csrf'])) {
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
            }
            $row = db()->query('SELECT username, log_s3, log_admin, totp_secret, trash_days FROM admin WHERE id = 1')->fetch();
            if ($row === false) {
                admin_err('Admin account not initialized. Run install.php first.', 500);
            }
            admin_ok(['csrf' => $_SESSION['csrf'], 'username' => $row['username'], 'log_s3' => (int)$row['log_s3'], 'log_admin' => (int)$row['log_admin'], 'totp' => (string)$row['totp_secret'] !== '', 'trash_days' => (int)$row['trash_days'], 'app_name' => app_name(), 'favicon' => favicon_ext(), 'version' => APP_VERSION]);

        case 'folders':
            admin_require_login();
            $bucketId = (int)($_GET['bucket_id'] ?? 0);
            $b = db_find_bucket($bucketId);
            if ($b === null) {
                admin_err('Bucket not found', 404);
            }
            $limit = min(max((int)($_GET['limit'] ?? 2000), 1), 5000);
            $st = db()->prepare('SELECT key FROM objects WHERE bucket_id = ? AND key LIKE "%/" ORDER BY key LIMIT ' . $limit);
            $st->execute([$bucketId]);
            admin_ok(['folders' => array_map(fn($r) => $r['key'], $st->fetchAll())]);

        case 'object_content':
            admin_require_login();
            $bucketId = (int)($_GET['bucket_id'] ?? 0);
            $key = (string)($_GET['key'] ?? '');
            $b = db_find_bucket($bucketId);
            if ($b === null) {
                admin_err('Bucket not found', 404);
            }
            $obj = db_find_object($bucketId, $key);
            if ($obj === null) {
                admin_err('Object not found', 404);
            }
            if (s3_is_folder_marker($key)) {
                admin_err('Cannot view a folder.', 400);
            }
            if ((int)$obj['size'] > ADMIN_TEXT_MAX) {
                admin_err('File is too large to view in the browser (max ' . (int)(ADMIN_TEXT_MAX / 1024) . ' KB).', 413);
            }
            if (!admin_is_text_object($obj)) {
                admin_err('Not a text file.', 415);
            }
            $user = db_find_user((int)$b['user_id']);
            $path = s3_object_path($user['username'], $b['name'], $key);
            if (!is_file($path)) {
                admin_err('Object not found', 404);
            }
            $content = file_get_contents($path);
            if ($content === false || ($content !== '' && preg_match('//u', $content) !== 1) || strpos($content, "\0") !== false) {
                admin_err('Not a text file.', 415);
            }
            admin_ok(['content' => $content, 'size' => (int)$obj['size'], 'content_type' => $obj['content_type']]);

        case 'object_conflicts':
            admin_require_login();
            $bucketId = (int)($_GET['bucket_id'] ?? 0);
            $b = db_find_bucket($bucketId);
            if ($b === null) {
                admin_err('Bucket not found', 404);
            }
            $keys = json_decode((string)($_GET['keys'] ?? '[]'), true);
            if (!is_array($keys)) {
                $keys = [];
            }
            $conflicts = [];
            $st = db()->prepare('SELECT key, size FROM objects WHERE bucket_id = ? AND key = ?');
            foreach (array_slice($keys, 0, 500) as $k) {
                if (!is_string($k) || $k === '') {
                    continue;
                }
                $st->execute([$bucketId, $k]);
                $r = $st->fetch();
                if ($r !== false) {
                    $conflicts[] = ['key' => (string)$r['key'], 'size' => (int)$r['size']];
                }
            }
            admin_ok(['conflicts' => $conflicts]);

        case 'update_logs':
            admin_require_login();
            admin_require_csrf();
            $logS3 = (int)!empty($_POST['log_s3']);
            $logAdmin = (int)!empty($_POST['log_admin']);
            $st = db()->prepare('UPDATE admin SET log_s3 = ?, log_admin = ? WHERE id = 1');
            $st->execute([$logS3, $logAdmin]);
            admin_ok(['log_s3' => $logS3, 'log_admin' => $logAdmin]);

        case 'update_settings':
            admin_require_login();
            admin_require_csrf();
            $out = [];
            if (isset($_POST['app_name'])) {
                $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$_POST['app_name']));
                if ($name === '' || u_strlen($name) > 40) {
                    admin_err('App name must be 1-40 characters.');
                }
                $st = db()->prepare('UPDATE admin SET app_name = ? WHERE id = 1');
                $st->execute([$name]);
                $out['app_name'] = $name;
            }
            if (isset($_POST['trash_days'])) {
                $days = max(0, min(365, (int)$_POST['trash_days']));
                $st = db()->prepare('UPDATE admin SET trash_days = ? WHERE id = 1');
                $st->execute([$days]);
                $out['trash_days'] = $days;
            }
            admin_ok($out);

        case 'upload_favicon':
            admin_require_login();
            admin_require_csrf();
            $f = $_FILES['file'] ?? null;
            if ($f === null || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                admin_err('No file uploaded.');
            }
            if ((int)$f['size'] > 1048576) {
                admin_err('Favicon must be 1 MB or smaller.');
            }
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'gif', 'jpg', 'jpeg', 'svg', 'ico', 'webp'];
            if (!in_array($ext, $allowed, true)) {
                admin_err('Allowed types: ' . implode(', ', $allowed) . '.');
            }
            $tmp = (string)$f['tmp_name'];
            if (function_exists('mime_content_type')) {
                $mime = (string)@mime_content_type($tmp);
                if (strpos($mime, 'image/') !== 0 && $mime !== 'text/xml' && $mime !== 'text/plain' && $mime !== 'application/xml') {
                    admin_err('That does not look like an image.');
                }
            }
            foreach ($allowed as $a) {
                @unlink(DATA_DIR . '/favicon.' . $a);
            }
            if (!@move_uploaded_file($tmp, DATA_DIR . '/favicon.' . $ext)) {
                admin_err('Could not store the favicon.', 500);
            }
            $st = db()->prepare('UPDATE admin SET favicon = ? WHERE id = 1');
            $st->execute([$ext]);
            admin_ok(['favicon' => $ext]);

        case 'reset_favicon':
            admin_require_login();
            admin_require_csrf();
            foreach (['png', 'gif', 'jpg', 'jpeg', 'svg', 'ico', 'webp'] as $a) {
                @unlink(DATA_DIR . '/favicon.' . $a);
            }
            $st = db()->prepare('UPDATE admin SET favicon = NULL WHERE id = 1');
            $st->execute();
            admin_ok(['favicon' => null]);

        case 'totp_start':
            admin_require_login();
            admin_require_csrf();
            $secret = totp_secret_generate();
            $_SESSION['totp_pending'] = $secret;
            $issuer = rawurlencode(app_name() . ' Admin');
            admin_ok([
                'secret' => $secret,
                'otpauth' => 'otpauth://totp/' . $issuer . '?secret=' . $secret . '&issuer=' . $issuer . '&algorithm=SHA1&digits=6&period=30',
            ]);

        case 'totp_enable':
            admin_require_login();
            admin_require_csrf();
            $secret = (string)($_SESSION['totp_pending'] ?? '');
            $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
            if ($secret === '') {
                admin_err('No pending setup. Click Enable again.', 400);
            }
            if (!totp_verify($secret, $code)) {
                admin_err('Invalid code. Check your authenticator app and try again.', 403);
            }
            $st = db()->prepare('UPDATE admin SET totp_secret = ? WHERE id = 1');
            $st->execute([$secret]);
            unset($_SESSION['totp_pending']);
            admin_ok(['totp' => true]);

        case 'totp_disable':
            admin_require_login();
            admin_require_csrf();
            $current = (string)($_POST['current'] ?? '');
            $st = db()->prepare('SELECT password_hash FROM admin WHERE id = 1');
            $st->execute();
            $row = $st->fetch();
            if ($row === false || !password_verify($current, $row['password_hash'])) {
                admin_err('Password is incorrect', 403);
            }
            $st = db()->prepare('UPDATE admin SET totp_secret = NULL WHERE id = 1');
            $st->execute();
            admin_ok(['totp' => false]);

        case 'trash':
            admin_trash($method);
            return;

        case 'uploads':
            admin_uploads($method);
            return;

        case 'update_profile':
            admin_require_login();
            admin_require_csrf();
            $uname = trim((string)($_POST['username'] ?? ''));
            if (preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $uname) !== 1) {
                admin_err('Invalid username (letters, digits, . _ -; max 64 chars).');
            }
            $st = db()->prepare('UPDATE admin SET username = ? WHERE id = 1');
            $st->execute([$uname]);
            admin_ok(['username' => $uname]);

        case 'change_password':
            admin_require_login();
            admin_require_csrf();
            $current = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            if (strlen($new) < 8) {
                admin_err('New password must be at least 8 characters.');
            }
            $st = db()->prepare('SELECT password_hash FROM admin WHERE id = 1');
            $st->execute();
            $row = $st->fetch();
            if ($row === false || !password_verify($current, $row['password_hash'])) {
                admin_err('Current password is incorrect', 403);
            }
            $st = db()->prepare('UPDATE admin SET password_hash = ? WHERE id = 1');
            $st->execute([password_hash($new, PASSWORD_DEFAULT)]);
            admin_ok();

        case 'download_object':
            admin_require_login();
            $bucketId = (int)($_GET['bucket_id'] ?? 0);
            $key = (string)($_GET['key'] ?? '');
            $inline = !empty($_GET['inline']);
            $b = db_find_bucket($bucketId);
            if ($b === null) {
                admin_err('Bucket not found', 404);
            }
            $user = db_find_user((int)$b['user_id']);
            $obj = db_find_object($bucketId, $key);
            if ($obj === null) {
                admin_err('Object not found', 404);
            }
            if (s3_is_folder_marker($key)) {
                header('Content-Type: application/octet-stream');
                header('Content-Length: 0');
                header('Content-Disposition: attachment; filename="' . addslashes(rtrim($key, '/')) . '"');
                exit;
            }
            $path = s3_object_path($user['username'], $b['name'], $key);
            if (!is_file($path)) {
                admin_err('Object not found', 404);
            }
            $size = filesize($path);
            $ct = $obj['content_type'] !== '' ? $obj['content_type'] : 'application/octet-stream';
            if ($inline && $ct === 'application/octet-stream') {
                $extCt = s3_mime_from_ext($key);
                if ($extCt !== 'application/octet-stream') {
                    $ct = $extCt;
                }
            }
            header('X-Content-Type-Options: nosniff');
            if ($inline) {
                // content_type is client-controlled (set at PUT time), so only
                // known-safe types may render inline; everything else (notably
                // text/html and anything sniffable) is forced to download.
                $ctLower = strtolower($ct);
                $safeInline = $ct === 'application/pdf'
                    || $ct === 'text/plain'
                    || strpos($ctLower, 'image/') === 0
                    || strpos($ctLower, 'video/') === 0
                    || strpos($ctLower, 'audio/') === 0;
                if (!$safeInline) {
                    $inline = false;
                    $ct = 'application/octet-stream';
                }
            }
            header('Content-Type: ' . $ct);
            if (strpos(strtolower($ct), 'image/svg+xml') === 0) {
                // SVG documents can embed scripts; neutralize them even though
                // the admin UI only loads SVGs through <img>.
                header('Content-Security-Policy: sandbox');
            }
            header('Accept-Ranges: bytes');
            $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
            if ($range !== '') {
                try {
                    [$start, $end] = s3_parse_range($range, $size);
                } catch (S3Exception $e) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    header('Content-Length: 0');
                    exit;
                }
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
                header('Content-Length: ' . ($end - $start + 1));
                http_response_code(206);
                admin_stream_file($path, $start, $end);
                exit;
            }
            header('Content-Length: ' . $size);
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes(basename($key)) . '"');
            set_time_limit(0);
            @ini_set('zlib.output_compression', 'Off');
            @ini_set('output_buffering', 'Off');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($path);
            exit;

        case 'users':
            admin_users($method);
            return;

        case 'buckets':
            admin_buckets($method);
            return;

        case 'objects':
            admin_objects($method);
            return;

        case 'logs':
            admin_logs($method);
            return;

        case 'stats':
            admin_require_login();
            admin_stats();
            return;

        case 'passkey_start':
            admin_require_login();
            admin_require_csrf();
            $data = admin_post_array();
            admin_passkey_begin('register', trim((string)($data['rp_id'] ?? '')));
            admin_ok(admin_passkey_options($_SESSION['passkey_challenge']['rp_id']));

        case 'passkey_register':
            admin_require_login();
            admin_require_csrf();
            admin_passkey_register();

        case 'passkey_challenge':
            $data = admin_post_array();
            admin_passkey_begin('login', trim((string)($data['rp_id'] ?? '')));
            admin_ok(['challenge' => $_SESSION['passkey_challenge']['c'], 'rp_id' => $_SESSION['passkey_challenge']['rp_id']]);

        case 'passkey_login':
            admin_passkey_login();

        case 'passkeys':
            admin_require_login();
            admin_ok(db()->query('SELECT id, name, created_at, last_used FROM admin_passkeys ORDER BY id')->fetchAll());

        case 'passkey_delete':
            admin_require_login();
            admin_require_csrf();
            $data = admin_post_array();
            $id = (int)($data['id'] ?? 0);
            db()->prepare('DELETE FROM admin_passkeys WHERE id = ?')->execute([$id]);
            admin_ok();

        default:
            admin_err('Unknown action: ' . $action, 404);
    }
}

function admin_username_valid(string $u): bool
{
    return preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $u) === 1 && $u !== '.' && $u !== '..';
}

/* ---------------- passkeys (WebAuthn) ---------------- */

function admin_post_array(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

// The WebAuthn RP ID is the hostname without a port; the origin is the full
// scheme://host as the browser sees it (reusing the HTTPS/proxy detection).
function admin_rp_id(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return strtolower((string)parse_url('http://' . $host, PHP_URL_HOST));
}

function admin_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// Stores a single-use, 5-minute challenge in the session, bound to the RP ID
// and origin the browser is actually on (so passkeys work on localhost, any
// domain, or behind a proxy regardless of how the host is reached).
function admin_passkey_begin(string $kind, string $rpId): void
{
    $_SESSION['passkey_challenge'] = [
        'c' => webauthn_challenge(),
        't' => time(),
        'k' => $kind,
        'rp_id' => $rpId !== '' ? $rpId : admin_rp_id(),
        'origin' => admin_origin(),
    ];
}

function admin_passkey_consume(string $kind): array
{
    $pc = $_SESSION['passkey_challenge'] ?? null;
    unset($_SESSION['passkey_challenge']);
    if (!is_array($pc) || ($pc['k'] ?? '') !== $kind || empty($pc['c']) || (int)($pc['t'] ?? 0) < time() - 300) {
        admin_err('Passkey challenge expired. Try again.', 400);
    }
    return $pc;
}

function admin_passkey_handle(): string
{
    $h = (string)db()->query('SELECT passkey_handle FROM admin WHERE id = 1')->fetchColumn();
    if ($h === '') {
        $h = webauthn_b64url_encode(random_bytes(16));
        db()->exec('UPDATE admin SET passkey_handle = ' . db()->quote($h) . ' WHERE id = 1');
    }
    return $h;
}

// Registration options for navigator.credentials.create().
function admin_passkey_options(string $rpId): array
{
    $uname = (string)db()->query('SELECT username FROM admin WHERE id = 1')->fetchColumn();
    return [
        'challenge' => $_SESSION['passkey_challenge']['c'],
        'rp_id' => $rpId,
        'rp_name' => app_name(),
        'user' => ['id' => admin_passkey_handle(), 'name' => $uname, 'displayName' => $uname],
    ];
}

function admin_passkey_register(): void
{
    $ch = admin_passkey_consume('register');
    $data = admin_post_array();
    $id = (string)($data['id'] ?? '');
    $clientDataJSON = webauthn_b64url_decode((string)($data['client_data_json'] ?? ''));
    $attestationObject = webauthn_b64url_decode((string)($data['attestation_object'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    if ($id === '' || $clientDataJSON === '' || $attestationObject === '') {
        admin_err('Missing passkey data.', 400);
    }
    if ($name === '' || u_strlen($name) > 60) {
        $name = 'Passkey';
    }
    try {
        $reg = webauthn_verify_registration($clientDataJSON, $attestationObject, $ch['c'], $ch['rp_id'], $ch['origin']);
    } catch (S3Exception $e) {
        admin_err($e->getMessage(), 400);
    }
    if (!hash_equals($id, webauthn_b64url_encode($reg['credentialId']))) {
        admin_err('Passkey credential ID mismatch.', 400);
    }
    $st = db()->prepare('SELECT COUNT(*) FROM admin_passkeys WHERE credential_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        admin_err('This passkey is already registered.', 409);
    }
    $st = db()->prepare('INSERT INTO admin_passkeys (credential_id, alg, key, sign_count, name, created_at) VALUES (?,?,?,?,?,?)');
    $st->execute([$id, $reg['alg'], base64_encode($reg['pubkey']), $reg['signCount'], $name, gmdate('Y-m-d H:i:s')]);
    admin_ok();
}

function admin_passkey_login(): void
{
    $ch = admin_passkey_consume('login');
    $data = admin_post_array();
    $id = (string)($data['id'] ?? '');
    $clientDataJSON = webauthn_b64url_decode((string)($data['client_data_json'] ?? ''));
    $authData = webauthn_b64url_decode((string)($data['authenticator_data'] ?? ''));
    $signature = webauthn_b64url_decode((string)($data['signature'] ?? ''));
    $userHandle = (string)($data['user_handle'] ?? '');
    if ($id === '' || $clientDataJSON === '' || $authData === '' || $signature === '') {
        admin_err('Missing passkey data.', 400);
    }
    if ($userHandle !== '') {
        $decoded = webauthn_b64url_decode($userHandle);
        if (!hash_equals(admin_passkey_handle(), webauthn_b64url_encode($decoded))) {
            admin_err('Passkey user mismatch.', 403);
        }
    }
    $st = db()->prepare('SELECT * FROM admin_passkeys WHERE credential_id = ?');
    $st->execute([$id]);
    $cred = $st->fetch();
    if ($cred === false) {
        admin_err('Passkey not recognized.', 404);
    }
    try {
        $count = webauthn_verify_assertion(
            $clientDataJSON,
            $authData,
            $signature,
            $ch['c'],
            $ch['rp_id'],
            $ch['origin'],
            (string)base64_decode((string)$cred['key'], true),
            (int)$cred['alg'],
            (int)$cred['sign_count']
        );
    } catch (S3Exception $e) {
        admin_err($e->getMessage(), 403);
    }
    db()->prepare('UPDATE admin_passkeys SET sign_count = ?, last_used = ? WHERE id = ?')
        ->execute([$count, gmdate('Y-m-d H:i:s'), $cred['id']]);

    // Establish the session exactly like a password login.
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $row = db()->query('SELECT username, log_s3, log_admin, totp_secret, trash_days FROM admin WHERE id = 1')->fetch();
    if ($row === false) {
        admin_err('Admin account not initialized. Run install.php first.', 500);
    }
    admin_ok(['csrf' => $_SESSION['csrf'], 'username' => $row['username'], 'log_s3' => (int)$row['log_s3'], 'log_admin' => (int)$row['log_admin'], 'totp' => (string)$row['totp_secret'] !== '', 'trash_days' => (int)$row['trash_days'], 'app_name' => app_name(), 'favicon' => favicon_ext(), 'version' => APP_VERSION]);
}

function admin_users(string $method): void
{
    admin_require_login();
    if ($method === 'GET') {
        $rows = db()->query('SELECT u.id, u.username, u.access_key, u.secret_key, u.created_at, u.quota_bytes,
                COALESCE((SELECT SUM(o.size) FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.user_id = u.id), 0) AS storage_used,
                COALESCE((SELECT COUNT(*) FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.user_id = u.id), 0) AS object_count,
                (SELECT COUNT(*) FROM buckets b2 WHERE b2.user_id = u.id) AS bucket_count
            FROM users u ORDER BY u.username')->fetchAll();
        // Daily S3 request counts for the last 14 days, per user (sparklines).
        $usage = [];
        $st = db()->query("SELECT user_id, strftime('%Y-%m-%d', ts) AS d, COUNT(*) AS c FROM logs
            WHERE kind = 's3' AND ts >= date('now', '-13 days') GROUP BY user_id, d");
        while ($r = $st->fetch()) {
            $usage[(int)$r['user_id']][$r['d']] = (int)$r['c'];
        }
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $days[] = gmdate('Y-m-d', time() - $i * 86400);
        }
        foreach ($rows as &$row) {
            $uid = (int)$row['id'];
            $row['usage14'] = array_map(fn($d) => $usage[$uid][$d] ?? 0, $days);
        }
        unset($row);
        admin_ok($rows);
    }
    admin_require_csrf();
    $sub = (string)($_POST['_sub'] ?? '');

    if ($sub === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        if (!admin_username_valid($username)) {
            admin_err('Invalid username (letters, digits, . _ -; max 64 chars).');
        }
        if (db_find_user_by_username($username) !== null) {
            admin_err('Username already exists.');
        }
        $accessKey = trim((string)($_POST['access_key'] ?? ''));
        $secretKey = trim((string)($_POST['secret_key'] ?? ''));
        if ($accessKey === '') {
            $accessKey = s3_generate_access_key();
        }
        if ($secretKey === '') {
            $secretKey = s3_generate_secret_key();
        }
        if (strlen($accessKey) < 4 || strlen($secretKey) < 6) {
            admin_err('Keys are too short (access key min 4 chars, secret key min 6 chars).');
        }
        $quotaBytes = max(0, (int)($_POST['quota_mb'] ?? 0)) * 1048576;
        $st = db()->prepare('INSERT INTO users (username, access_key, secret_key, quota_bytes, created_at) VALUES (?,?,?,?,?)');
        try {
            $st->execute([$username, $accessKey, $secretKey, $quotaBytes, gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                admin_err('Username or access key already exists.', 409);
            }
            throw $e;
        }
        s3_ensure_dir(s3_user_dir($username));
        $id = (int)db()->lastInsertId();
        admin_ok(['id' => $id, 'username' => $username, 'access_key' => $accessKey, 'secret_key' => $secretKey, 'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    if ($sub === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $user = db_find_user($id);
        if ($user === null) {
            admin_err('User not found', 404);
        }
        $username = trim((string)($_POST['username'] ?? $user['username']));
        if ($username !== $user['username']) {
            if (!admin_username_valid($username)) {
                admin_err('Invalid username (letters, digits, . _ -; max 64 chars).');
            }
            if (db_find_user_by_username($username) !== null) {
                admin_err('Username already exists.');
            }
            $oldDir = s3_user_dir($user['username']);
            $newDir = s3_user_dir($username);
            if (is_dir($oldDir) && !@rename($oldDir, $newDir)) {
                admin_err('Could not rename user folder.', 500);
            }
            $st = db()->prepare('UPDATE users SET username = ? WHERE id = ?');
            $st->execute([$username, $id]);
            $user['username'] = $username;
        }
        if (isset($_POST['quota_mb'])) {
            $quotaBytes = max(0, (int)$_POST['quota_mb']) * 1048576;
            $st = db()->prepare('UPDATE users SET quota_bytes = ? WHERE id = ?');
            $st->execute([$quotaBytes, $id]);
        }
        if (!empty($_POST['regen_secret'])) {
            $secret = s3_generate_secret_key();
            $st = db()->prepare('UPDATE users SET secret_key = ? WHERE id = ?');
            $st->execute([$secret, $id]);
            admin_ok(['id' => $id, 'username' => $user['username'], 'access_key' => $user['access_key'], 'secret_key' => $secret]);
        }
        admin_ok(['id' => $id, 'username' => $user['username'], 'access_key' => $user['access_key']]);
    }

    if ($sub === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $user = db_find_user($id);
        if ($user === null) {
            admin_err('User not found', 404);
        }
        s3_delete_tree(s3_user_dir($user['username']));
        s3_delete_tree(s3_uploads_user_dir($user['username']));
        s3_delete_tree(trash_dir() . '/' . $user['username']);
        $st = db()->prepare('DELETE FROM uploads WHERE user_id = ?');
        $st->execute([$id]);
        $st = db()->prepare('DELETE FROM trash WHERE user_id = ?');
        $st->execute([$id]);
        $st = db()->prepare('DELETE FROM users WHERE id = ?');
        $st->execute([$id]);
        admin_ok();
    }

    admin_err('Bad request', 400);
}

function admin_buckets(string $method): void
{
    admin_require_login();
    if ($method === 'GET') {
        $userId = (int)($_GET['user_id'] ?? 0);
        if ($userId > 0) {
            $st = db()->prepare('SELECT b.id, b.user_id, b.name, b.created_at, u.username,
                (SELECT COUNT(*) FROM objects o WHERE o.bucket_id = b.id) AS object_count
                FROM buckets b JOIN users u ON u.id = b.user_id WHERE b.user_id = ? ORDER BY b.name');
            $st->execute([$userId]);
            admin_ok($st->fetchAll());
        }
        $rows = db()->query('SELECT b.id, b.user_id, b.name, b.created_at, u.username,
            (SELECT COUNT(*) FROM objects o WHERE o.bucket_id = b.id) AS object_count
            FROM buckets b JOIN users u ON u.id = b.user_id ORDER BY u.username, b.name')->fetchAll();
        admin_ok($rows);
    }
    admin_require_csrf();
    $sub = (string)($_POST['_sub'] ?? '');

    if ($sub === 'create') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $user = db_find_user($userId);
        if ($user === null) {
            admin_err('User not found', 404);
        }
        if (!s3_bucket_name_valid($name)) {
            admin_err('Invalid bucket name (3-63 chars, lowercase letters, digits, dots, hyphens).');
        }
        if (db_find_bucket_by_name($userId, $name) !== null) {
            admin_err('Bucket already exists for this user.');
        }
        s3_ensure_dir(s3_bucket_dir($user['username'], $name));
        $st = db()->prepare('INSERT INTO buckets (user_id, name, created_at) VALUES (?,?,?)');
        try {
            $st->execute([$userId, $name, gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                admin_err('Bucket already exists for this user.', 409);
            }
            throw $e;
        }
        admin_ok();
    }

    if ($sub === 'rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $b = db_find_bucket($id);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        if (!s3_bucket_name_valid($name)) {
            admin_err('Invalid bucket name (3-63 chars, lowercase letters, digits, dots, hyphens).');
        }
        $user = db_find_user((int)$b['user_id']);
        if (db_find_bucket_by_name((int)$b['user_id'], $name) !== null) {
            admin_err('Bucket already exists for this user.');
        }
        $old = s3_bucket_dir($user['username'], $b['name']);
        $new = s3_bucket_dir($user['username'], $name);
        if (is_dir($old) && !@rename($old, $new)) {
            admin_err('Could not rename bucket folder.', 500);
        }
        $st = db()->prepare('UPDATE buckets SET name = ? WHERE id = ?');
        $st->execute([$name, $id]);
        admin_ok();
    }

    if ($sub === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $b = db_find_bucket($id);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        $user = db_find_user((int)$b['user_id']);
        s3_delete_tree(s3_bucket_dir($user['username'], $b['name']));
        s3_delete_tree(s3_uploads_user_dir($user['username']) . '/' . $b['name']);
        admin_trash_purge_bucket($user['username'], $b['name']);
        $st = db()->prepare('DELETE FROM uploads WHERE bucket_id = ?');
        $st->execute([$id]);
        $st = db()->prepare('DELETE FROM buckets WHERE id = ?');
        $st->execute([$id]);
        admin_ok();
    }

    admin_err('Bad request', 400);
}

function admin_objects(string $method): void
{
    admin_require_login();
    if ($method === 'GET') {
        $bucketId = (int)($_GET['bucket_id'] ?? 0);
        $prefix = (string)($_GET['prefix'] ?? '');
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        if (($_GET['_sub'] ?? '') === 'zip') {
            admin_download_zip($b, $prefix);
        }
        $perPage = min((int)($_GET['per_page'] ?? 100), 500);
        if ($perPage < 1) {
            $perPage = 100;
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $q = trim((string)($_GET['q'] ?? ''));
        $where = 'bucket_id = ?';
        $args = [$bucketId];
        if ($prefix !== '') {
            $where .= ' AND key LIKE ? ESCAPE "\" AND key <> ?';
            array_push($args, s3_escape_like($prefix) . '%', $prefix);
        }
        if ($q !== '') {
            $where .= ' AND key LIKE ? ESCAPE "\"';
            $args[] = '%' . s3_escape_like($q) . '%';
        }
        $stCount = db()->prepare('SELECT COUNT(*) FROM objects WHERE ' . $where);
        $stCount->execute($args);
        $total = (int)$stCount->fetchColumn();
        $sortCols = [
            'name' => 'key',
            'size' => 'size',
            'modified' => 'last_modified',
            'type' => "(CASE WHEN key LIKE '%/' THEN '' ELSE lower(substr(key, instr(key, '.') + 1)) END)",
        ];
        $sortCol = $sortCols[$_GET['sort'] ?? ''] ?? 'key';
        $sortDir = strtolower((string)($_GET['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
        $sql = 'SELECT key, size, etag, content_type, last_modified FROM objects WHERE ' . $where
            . ' ORDER BY (key LIKE "%/") DESC, ' . $sortCol . ' ' . $sortDir . ', key ASC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
        $st = db()->prepare($sql);
        $st->execute($args);
        admin_ok([
            'rows' => $st->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'per_page' => $perPage,
        ]);
    }
    admin_require_csrf();
    $sub = (string)($_POST['_sub'] ?? ($_GET['_sub'] ?? ''));
    $json = null;
    if ($sub === '') {
        $raw = (string)file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['_sub'])) {
            $sub = (string)$json['_sub'];
        }
    }

    if ($sub === 'delete') {
        $bucketId = (int)($_POST['bucket_id'] ?? 0);
        $key = (string)($_POST['key'] ?? '');
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        $user = db_find_user((int)$b['user_id']);
        $obj = db_find_object($bucketId, $key);
        if ($obj === null) {
            admin_err('Object not found', 404);
        }
        admin_delete_object($user, $b, $obj);
        if (!s3_is_folder_marker($key)) {
            admin_prune_dirs(dirname(s3_object_path($user['username'], $b['name'], $key)), s3_bucket_dir($user['username'], $b['name']));
        }
        admin_ok(['trashed' => admin_trash_enabled()]);
    }

    if ($sub === 'create_folder') {
        $bucketId = (int)($_POST['bucket_id'] ?? 0);
        $prefix = (string)($_POST['prefix'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false) {
            admin_err('Invalid folder name.');
        }
        $key = ($prefix !== '' ? rtrim($prefix, '/') . '/' : '') . $name . '/';
        if (!s3_key_valid($key)) {
            admin_err('Invalid folder key.');
        }
        s3_upsert_object($bucketId, $key, 0, md5(''), 'application/octet-stream', [], gmdate('Y-m-d H:i:s'));
        admin_ok();
    }

    if ($sub === 'create_file') {
        $data = $json;
        if (!is_array($data)) {
            admin_err('Bad request', 400);
        }
        $bucketId = (int)($data['bucket_id'] ?? 0);
        $prefix = (string)($data['prefix'] ?? '');
        $name = trim((string)($data['name'] ?? ''));
        $content = (string)($data['content'] ?? '');
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false) {
            admin_err('Invalid file name.');
        }
        $key = ($prefix !== '' ? rtrim($prefix, '/') . '/' : '') . $name;
        if (!s3_key_valid($key)) {
            admin_err('Invalid file key.');
        }
        $user = db_find_user((int)$b['user_id']);
        $path = s3_object_path($user['username'], $b['name'], $key);
        s3_ensure_dir(dirname($path), [$user['username'], $b['name'], (int)$b['id']]);
        $tmp = dirname($path) . '/.tmp-' . s3_random_id(8);
        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            admin_err('Could not write file.', 500);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            admin_err('Could not store file.', 500);
        }
        s3_upsert_object($bucketId, $key, strlen($content), md5($content), 'text/plain', [], gmdate('Y-m-d H:i:s'));
        admin_ok();
    }

    if ($sub === 'update_content') {
        $data = $json;
        if (!is_array($data)) {
            admin_err('Bad request', 400);
        }
        $bucketId = (int)($data['bucket_id'] ?? 0);
        $key = (string)($data['key'] ?? '');
        $content = (string)($data['content'] ?? '');
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        $obj = db_find_object($bucketId, $key);
        if ($obj === null) {
            admin_err('Object not found', 404);
        }
        if (s3_is_folder_marker($key)) {
            admin_err('Cannot edit a folder.', 400);
        }
        if (strlen($content) > ADMIN_TEXT_MAX) {
            admin_err('File is too large to edit in the browser (max ' . (int)(ADMIN_TEXT_MAX / 1024) . ' KB).', 413);
        }
        if (!admin_is_text_object($obj)) {
            admin_err('Not a text file.', 415);
        }
        $user = db_find_user((int)$b['user_id']);
        $path = s3_object_path($user['username'], $b['name'], $key);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }
        $tmp = dirname($path) . '/.tmp-' . s3_random_id(8);
        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            admin_err('Could not write file.', 500);
        }
        @unlink($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            admin_err('Could not store file.', 500);
        }
        $ct = $obj['content_type'] !== '' ? $obj['content_type'] : 'text/plain';
        s3_upsert_object($bucketId, $key, strlen($content), md5($content), $ct, json_decode($obj['meta'] ?? '{}', true) ?: [], gmdate('Y-m-d H:i:s'));
        admin_ok(['size' => strlen($content)]);
    }

    if ($sub === 'bulk_delete') {
        $data = $json;
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            admin_err('Bad request', 400);
        }
        $bucketId = (int)($data['bucket_id'] ?? 0);
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        $user = db_find_user((int)$b['user_id']);
        $bucketDir = s3_bucket_dir($user['username'], $b['name']);
        $deleted = 0;
        foreach ($data['keys'] as $k) {
            $k = (string)$k;
            if ($k === '' || !s3_key_valid($k)) {
                continue;
            }
            if (s3_is_folder_marker($k)) {
                // Folder: delete the marker and everything inside it.
                $st = db()->prepare('SELECT * FROM objects WHERE bucket_id = ? AND key LIKE ? ESCAPE "\"');
                $st->execute([$bucketId, s3_escape_like($k) . '%']);
            } else {
                // Plain file: the exact key only. A prefix match here would
                // silently delete unrelated keys such as "report.txt.bak".
                $st = db()->prepare('SELECT * FROM objects WHERE bucket_id = ? AND key = ?');
                $st->execute([$bucketId, $k]);
            }
            foreach ($st->fetchAll() as $o) {
                admin_delete_object($user, $b, $o);
                $deleted++;
            }
            admin_prune_dirs(dirname($bucketDir . '/' . $k), $bucketDir);
        }
        admin_ok(['deleted' => $deleted]);
    }

    if ($sub === 'move' || $sub === 'copy') {
        $data = $json;
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            admin_err('Bad request', 400);
        }
        $bucketId = (int)($data['bucket_id'] ?? 0);
        $srcPrefix = (string)($data['src_prefix'] ?? '');
        $destPrefix = (string)($data['dest_prefix'] ?? '');
        if (strlen($destPrefix) > 1024) {
            admin_err('Destination too long.');
        }
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        $user = db_find_user((int)$b['user_id']);
        $res = admin_transfer($user, $b, $srcPrefix, array_values(array_unique(array_map('strval', $data['keys']))), $destPrefix, $sub, !empty($data['overwrite']));
        if ($res['conflicts']) {
            admin_json(['ok' => false, 'error' => 'Destination conflict: ' . count($res['conflicts']) . ' object(s) already exist. Enable "overwrite" or choose another folder.', 'conflicts' => array_slice($res['conflicts'], 0, 10)], 409);
        }
        admin_ok($res);
    }

    if ($sub === 'rename') {
        $data = $json;
        if (!is_array($data)) {
            admin_err('Bad request', 400);
        }
        $bucketId = (int)($data['bucket_id'] ?? 0);
        $key = (string)($data['key'] ?? '');
        $newKey = (string)($data['new_key'] ?? '');
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        if ($key === '' || $newKey === '' || !s3_key_valid($key) || !s3_key_valid($newKey)) {
            admin_err('Invalid object key.');
        }
        if (s3_is_folder_marker($key) !== s3_is_folder_marker($newKey)) {
            admin_err('Cannot rename between folder and file.');
        }
        $user = db_find_user((int)$b['user_id']);
        $res = admin_transfer($user, $b, '', [$key], '', 'move', true, [$key => $newKey]);
        if ($res['conflicts']) {
            admin_err('Destination already exists.', 409);
        }
        admin_ok($res);
    }

    if ($sub === 'info') {
        $data = $json;
        if (!is_array($data)) {
            admin_err('Bad request', 400);
        }
        $bucketId = (int)($data['bucket_id'] ?? 0);
        $key = (string)($data['key'] ?? '');
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        $obj = db_find_object($bucketId, $key);
        if ($obj === null) {
            admin_err('Object not found', 404);
        }
        $user = db_find_user((int)$b['user_id']);
        $meta = json_decode($obj['meta'] ?? '{}', true);
        admin_ok([
            'key' => $obj['key'],
            'bucket' => $b['name'],
            'username' => $user['username'],
            'size' => (int)$obj['size'],
            'etag' => $obj['etag'],
            'content_type' => $obj['content_type'],
            'meta' => is_array($meta) ? $meta : [],
            'last_modified' => $obj['last_modified'],
            'is_folder' => s3_is_folder_marker($key),
        ]);
    }

    if ($sub === 'presign') {
        $data = $json;
        if (!is_array($data)) {
            admin_err('Bad request', 400);
        }
        $bucketId = (int)($data['bucket_id'] ?? 0);
        $key = (string)($data['key'] ?? '');
        $expires = (int)($data['expires'] ?? 3600);
        if (!in_array($expires, [300, 3600, 86400, 604800], true)) {
            $expires = 3600;
        }
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        if (db_find_object($bucketId, $key) === null || s3_is_folder_marker($key)) {
            admin_err('Object not found', 404);
        }
        $user = db_find_user((int)$b['user_id']);
        admin_ok(['url' => s3_presign_url($user, $b['name'], $key, $expires, REGION), 'expires' => $expires]);
    }

    if ($sub === 'upload') {
        $bucketId = (int)($_GET['bucket_id'] ?? 0);
        $prefix = (string)($_GET['prefix'] ?? '');
        $fname = basename((string)($_GET['name'] ?? ''));
        $b = db_find_bucket($bucketId);
        if ($b === null) {
            admin_err('Bucket not found', 404);
        }
        if ($fname === '' || $fname === '.' || $fname === '..') {
            admin_err('Invalid file name.');
        }
        $key = ($prefix !== '' ? rtrim($prefix, '/') . '/' : '') . $fname;
        if (!s3_key_valid($key) || s3_is_folder_marker($key)) {
            admin_err('Invalid object key.');
        }
        $user = db_find_user((int)$b['user_id']);
        $dir = s3_bucket_dir($user['username'], $b['name']);
        $target = $dir . '/' . $key;
        s3_ensure_dir(dirname($target), [$user['username'], $b['name'], (int)$b['id']]);
        $tmp = $dir . '/.tmp-' . s3_random_id(8);
        try {
            [$size, $etag] = s3_receive_body_to_file($tmp);
        } catch (S3Exception $e) {
            @unlink($tmp);
            admin_err($e->getMessage(), $e->s3_status);
        }
        try {
            $existing = db_find_object((int)$b['id'], $key);
            s3_quota_check($user, $size, $existing !== null ? (int)$existing['size'] : 0);
        } catch (S3Exception $e) {
            @unlink($tmp);
            admin_err($e->getMessage(), $e->s3_status);
        }
        @unlink($target);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            admin_err('Could not store object.', 500);
        }
        $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if ($ct === '' || $ct === 'application/octet-stream') {
            $ct = s3_mime_from_ext($fname);
        }
        s3_upsert_object((int)$b['id'], $key, $size, $etag, $ct, [], gmdate('Y-m-d H:i:s'));
        admin_ok();
    }

    admin_err('Bad request', 400);
}

function admin_prune_dirs(string $dir, string $stopAt): void
{
    $dir = rtrim($dir, '/');
    $stopAt = rtrim($stopAt, '/');
    while ($dir !== '' && strpos($dir . '/', $stopAt . '/') === 0) {
        if (!@rmdir($dir)) {
            break;
        }
        if ($dir === $stopAt) {
            break;
        }
        $dir = dirname($dir);
    }
}

// Streams $path bytes [$start..$end] to the client, flushing PHP output buffering.
function admin_stream_file(string $path, int $start, int $end): void
{
    set_time_limit(0);
    // Never let output compression (zlib / mod_deflate) touch streamed media:
    // it corrupts Range/206 responses and makes video/audio preview fail on
    // hosts that enable it (e.g. shared hosting like DirectAdmin).
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('output_buffering', 'Off');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) {
        $chunk = fread($fp, min(1048576, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}

// True when the object is a viewable/editable text-like file (by stored
// content type or by its key extension).
function admin_is_text_object(array $obj): bool
{
    $ct = strtolower((string)$obj['content_type']);
    if ($ct !== '') {
        if (strpos($ct, 'text/') === 0 || strpos($ct, '+xml') !== false) {
            return true;
        }
        if (in_array($ct, [
            'application/json', 'application/xml', 'application/xhtml+xml',
            'application/javascript', 'application/x-javascript', 'application/x-httpd-php',
            'application/x-sh', 'image/svg+xml', 'application/yaml', 'application/x-yaml',
        ], true)) {
            return true;
        }
        if (strpos($ct, 'json') !== false || strpos($ct, 'yaml') !== false || strpos($ct, 'xml') !== false) {
            return true;
        }
    }
    $ext = strtolower(pathinfo((string)$obj['key'], PATHINFO_EXTENSION));
    return in_array($ext, [
        'txt', 'text', 'md', 'markdown', 'log', 'conf', 'cfg', 'ini', 'json', 'xml',
        'yml', 'yaml', 'toml', 'csv', 'tsv', 'html', 'htm', 'css', 'js', 'mjs',
        'php', 'py', 'rb', 'pl', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'sql',
        'env', 'htaccess', 'properties', 'lock', 'gitignore', 'gitattributes',
        'editorconfig', 'srt', 'vtt', 'nfo',
    ], true);
}

// Expands items (files and folder markers) into absolute (srcKey => dstKey) pairs and
// copies or moves them. $exact (srcKey => dstKey) pins the destination of specific
// items (used for renames). Returns ['copied','deleted','skipped','conflicts'].
function admin_transfer(array $user, array $b, string $srcPrefix, array $items, string $destPrefix, string $mode, bool $overwrite, ?array $exact = null): array
{
    $bucketId = (int)$b['id'];
    $exact = $exact ?? [];
    $plan = [];
    $markers = [];
    $lastSegment = function (string $k): string {
        $base = rtrim($k, '/');
        $pos = strrpos($base, '/');
        return ($pos === false ? $base : substr($base, $pos + 1)) . '/';
    };
    $dstFor = function (string $srcKey, bool $isMarker) use ($srcPrefix, $destPrefix, $lastSegment): string {
        if ($srcPrefix !== '' && strpos($srcKey, $srcPrefix) === 0) {
            return $destPrefix . substr($srcKey, strlen($srcPrefix));
        }
        return $destPrefix . ($isMarker ? $lastSegment($srcKey) : basename($srcKey));
    };
    foreach ($items as $k) {
        if ($k === '' || !s3_key_valid($k)) {
            continue;
        }
        if (s3_is_folder_marker($k)) {
            $markers[] = $k;
            $markerDst = isset($exact[$k]) ? $exact[$k] : $dstFor($k, true);
            if (db_find_object($bucketId, $k) !== null) {
                $plan[$k] = $markerDst;
            }
            $st = db()->prepare('SELECT key FROM objects WHERE bucket_id = ? AND key LIKE ? ESCAPE "\" ORDER BY key');
            $st->execute([$bucketId, s3_escape_like($k) . '%']);
            foreach ($st->fetchAll() as $r) {
                $plan[$r['key']] = $markerDst . substr($r['key'], strlen($k));
            }
        } else {
            $o = db_find_object($bucketId, $k);
            if ($o !== null) {
                $plan[$k] = isset($exact[$k]) ? $exact[$k] : $dstFor($k, false);
            }
        }
    }

    // Guard: destination must not be inside a moved folder (would loop forever).
    foreach ($markers as $m) {
        if ($destPrefix === $m || strpos($destPrefix, $m) === 0) {
            throw new S3Exception('InvalidRequest', 'Destination folder is inside a folder being moved.', 400);
        }
    }

    $dir = s3_bucket_dir($user['username'], $b['name']);
    $copied = 0;
    $deleted = 0;
    $skipped = 0;
    $conflicts = [];

    foreach ($plan as $srcKey => $dstKey) {
        if ($dstKey === '' || !s3_key_valid($dstKey)) {
            continue;
        }
        if ($dstKey === $srcKey) {
            $skipped++;
            continue;
        }
        $existing = db_find_object($bucketId, $dstKey);
        if ($existing !== null && !$overwrite) {
            $conflicts[] = $dstKey;
            continue;
        }

        if (s3_is_folder_marker($srcKey)) {
            if (!s3_is_folder_marker($dstKey)) {
                $conflicts[] = $dstKey;
                continue;
            }
            $row = db_find_object($bucketId, $srcKey);
            if ($row === null) {
                continue;
            }
            s3_upsert_object($bucketId, $dstKey, 0, $row['etag'], $row['content_type'], json_decode($row['meta'] ?? '{}', true) ?: [], gmdate('Y-m-d H:i:s'));
        } else {
            $srcPath = $dir . '/' . $srcKey;
            if (!is_file($srcPath)) {
                continue;
            }
            s3_ensure_dir(dirname($dir . '/' . $dstKey), [$user['username'], $b['name'], $bucketId]);
            $tmp = $dir . '/.tmp-' . s3_random_id(8);
            [$size, $etag] = s3_copy_file_to($srcPath, $tmp);
            if ($mode === 'copy') {
                $dstRow = db_find_object($bucketId, $dstKey);
                try {
                    s3_quota_check($user, $size, $dstRow !== null ? (int)$dstRow['size'] : 0);
                } catch (S3Exception $e) {
                    @unlink($tmp);
                    throw $e;
                }
            }
            $target = $dir . '/' . $dstKey;
            @unlink($target);
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new S3Exception('InternalError', 'Failed to store object.', 500);
            }
            $srcRow = db_find_object($bucketId, $srcKey);
            if ($srcRow === null) {
                @unlink($target);
                continue;
            }
            s3_upsert_object($bucketId, $dstKey, $size, $etag, $srcRow['content_type'], json_decode($srcRow['meta'] ?? '{}', true) ?: [], gmdate('Y-m-d H:i:s'));
        }
        $copied++;

        if ($mode === 'move') {
            if (!s3_is_folder_marker($srcKey)) {
                @unlink($dir . '/' . $srcKey);
                admin_prune_dirs(dirname($dir . '/' . $srcKey), $dir);
            }
            $st = db()->prepare('DELETE FROM objects WHERE bucket_id = ? AND key = ?');
            $st->execute([$bucketId, $srcKey]);
            $deleted++;
        }
    }

    if ($mode === 'move') {
        foreach ($markers as $m) {
            admin_prune_dirs($dir . '/' . rtrim($m, '/'), $dir);
        }
    }

    return ['copied' => $copied, 'deleted' => $deleted, 'skipped' => $skipped, 'conflicts' => $conflicts];
}

function admin_logs(string $method): void
{
    admin_require_login();
    if ($method === 'GET') {
        $perPage = min((int)($_GET['per_page'] ?? 100), 500);
        if ($perPage < 1) {
            $perPage = 100;
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = [];
        $args = [];
        if (!empty($_GET['user_id']) && (int)$_GET['user_id'] > 0) {
            $where[] = 'l.user_id = ?';
            $args[] = (int)$_GET['user_id'];
        }
        if (!empty($_GET['kind'])) {
            $where[] = 'l.kind = ?';
            $args[] = (string)$_GET['kind'];
        }
        if (!empty($_GET['method'])) {
            $where[] = 'l.method = ?';
            $args[] = (string)$_GET['method'];
        }
        if (!empty($_GET['status'])) {
            $status = (string)$_GET['status'];
            if ($status === '2xx') {
                $where[] = 'l.status >= 200 AND l.status < 300';
            } elseif ($status === '4xx') {
                $where[] = 'l.status >= 400 AND l.status < 500';
            } elseif ($status === '5xx') {
                $where[] = 'l.status >= 500';
            } else {
                $where[] = 'l.status = ?';
                $args[] = (int)$status;
            }
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(l.uri LIKE ? OR l.ip LIKE ? OR l.method LIKE ? OR l.user_agent LIKE ? OR CAST(l.status AS TEXT) LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stCount = db()->prepare('SELECT COUNT(*) FROM logs l' . $whereSql);
        $stCount->execute($args);
        $total = (int)$stCount->fetchColumn();
        $sql = 'SELECT l.id, l.ts, l.kind, l.ip, l.method, l.uri, l.status, l.bytes, l.ms, l.user_agent, u.username
                FROM logs l LEFT JOIN users u ON u.id = l.user_id' . $whereSql
            . ' ORDER BY l.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
        $st = db()->prepare($sql);
        $st->execute($args);
        admin_ok([
            'rows' => $st->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'per_page' => $perPage,
        ]);
    }
    admin_require_csrf();
    if ($method === 'POST' && ($_POST['_sub'] ?? '') === 'clear') {
        db()->exec('DELETE FROM logs');
        admin_ok();
    }
    admin_err('Bad request', 400);
}

function admin_stats(): void
{
    $count = function (string $sql): int {
        return (int)db()->query($sql)->fetchColumn();
    };
    $stats = [
        'users' => $count('SELECT COUNT(*) FROM users'),
        'buckets' => $count('SELECT COUNT(*) FROM buckets'),
        'objects' => $count('SELECT COUNT(*) FROM objects'),
        'size' => $count('SELECT COALESCE(SUM(size), 0) FROM objects'),
        'requests' => $count('SELECT COUNT(*) FROM logs'),
        'req2xx' => $count('SELECT COUNT(*) FROM logs WHERE status >= 200 AND status < 300'),
        'req4xx' => $count('SELECT COUNT(*) FROM logs WHERE status >= 400 AND status < 500'),
        'req5xx' => $count('SELECT COUNT(*) FROM logs WHERE status >= 500'),
        'avgMs' => round((float)db()->query('SELECT COALESCE(AVG(ms), 0) FROM logs')->fetchColumn(), 1),
    ];

    $h24 = [];
    $st = db()->query("SELECT strftime('%Y-%m-%d %H:00:00', ts) AS h, COUNT(*) AS c FROM logs WHERE ts >= datetime('now', '-24 hours') GROUP BY h ORDER BY h");
    while ($r = $st->fetch()) {
        $h24[] = [$r['h'], (int)$r['c']];
    }
    $stats['h24'] = $h24;

    $top = [];
    $st = db()->query('SELECT u.username, COUNT(*) AS c FROM logs l JOIN users u ON u.id = l.user_id GROUP BY l.user_id ORDER BY c DESC LIMIT 5');
    while ($r = $st->fetch()) {
        $top[] = ['username' => $r['username'], 'count' => (int)$r['c']];
    }
    $stats['topUsers'] = $top;

    $recent = [];
    $st = db()->query('SELECT l.ts, l.kind, l.method, l.uri, l.status, l.ms, u.username FROM logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 10');
    while ($r = $st->fetch()) {
        $recent[] = $r;
    }
    $stats['recent'] = $recent;

    admin_ok($stats);
}

/* ================= trash (soft delete) ================= */

function admin_trash_days(): int
{
    return max(0, (int)db()->query('SELECT trash_days FROM admin WHERE id = 1')->fetchColumn());
}

function admin_trash_enabled(): bool
{
    return admin_trash_days() > 0;
}

// Deletes one object row; when trash is enabled the file is moved aside and a
// trash row is written so it can be restored. Falls back to a permanent
// delete if the trash move fails for any reason.
function admin_delete_object(array $user, array $b, array $obj): void
{
    $key = (string)$obj['key'];
    $src = s3_object_path($user['username'], $b['name'], $key);
    $trashed = false;
    if (admin_trash_enabled()) {
        try {
            $tp = trash_object_path($user['username'], $b['name'], $key);
            s3_ensure_dir(dirname($tp));
            if (!is_file($src) || @rename($src, $tp)) {
                $st = db()->prepare('INSERT INTO trash (user_id, username, bucket_name, key, size, etag, content_type, meta, deleted_at, expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $st->execute([
                    (int)$user['id'], $user['username'], $b['name'], $key,
                    (int)$obj['size'], $obj['etag'], $obj['content_type'], $obj['meta'],
                    gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + admin_trash_days() * 86400),
                ]);
                $trashed = true;
            }
        } catch (Throwable $e) {
            $trashed = false;
        }
    }
    if (!$trashed && is_file($src)) {
        @unlink($src);
    }
    $st = db()->prepare('DELETE FROM objects WHERE id = ?');
    $st->execute([$obj['id']]);
}

function admin_trash_purge_row(array $row): void
{
    $p = trash_object_path((string)$row['username'], (string)$row['bucket_name'], (string)$row['key']);
    if (is_file($p)) {
        @unlink($p);
    }
    $st = db()->prepare('DELETE FROM trash WHERE id = ?');
    $st->execute([$row['id']]);
}

// Drops all trash entries for a bucket (used when the bucket is deleted).
function admin_trash_purge_bucket(string $username, string $bucketName): void
{
    $st = db()->prepare('SELECT * FROM trash WHERE username = ? AND bucket_name = ?');
    $st->execute([$username, $bucketName]);
    foreach ($st->fetchAll() as $row) {
        admin_trash_purge_row($row);
    }
}

// Permanently removes entries past their expiry. Called when trash is listed.
function admin_trash_purge_expired(): void
{
    $st = db()->prepare("SELECT * FROM trash WHERE expires_at <= ?");
    $st->execute([gmdate('Y-m-d H:i:s')]);
    foreach ($st->fetchAll() as $row) {
        admin_trash_purge_row($row);
    }
}

function admin_trash(string $method): void
{
    admin_require_login();
    admin_trash_purge_expired();
    if ($method === 'GET') {
        $perPage = min((int)($_GET['per_page'] ?? 100), 500);
        if ($perPage < 1) {
            $perPage = 100;
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $total = (int)db()->query('SELECT COUNT(*) FROM trash')->fetchColumn();
        $st = db()->prepare('SELECT * FROM trash ORDER BY deleted_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $st->execute();
        admin_ok([
            'rows' => $st->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'per_page' => $perPage,
            'enabled' => admin_trash_enabled(),
            'days' => admin_trash_days(),
        ]);
    }
    admin_require_csrf();
    $sub = (string)($_POST['_sub'] ?? '');

    if ($sub === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM trash WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row === false) {
            admin_err('Trash entry not found', 404);
        }
        $user = db_find_user((int)$row['user_id']);
        if ($user === null) {
            admin_trash_purge_row($row);
            admin_err('The owning user no longer exists.', 410);
        }
        $b = db_find_bucket_by_name((int)$user['id'], (string)$row['bucket_name']);
        if ($b === null) {
            admin_err('Bucket "' . $row['bucket_name'] . '" no longer exists.', 409);
        }
        if (db_find_object((int)$b['id'], (string)$row['key']) !== null) {
            admin_err('An object with this key already exists. Rename or delete it first.', 409);
        }
        if (!s3_is_folder_marker((string)$row['key'])) {
            $src = trash_object_path($user['username'], $b['name'], (string)$row['key']);
            if (!is_file($src)) {
                admin_trash_purge_row($row);
                admin_err('The file data is missing and cannot be restored.', 410);
            }
            $dst = s3_object_path($user['username'], $b['name'], (string)$row['key']);
            s3_ensure_dir(dirname($dst));
            @unlink($dst);
            if (!@rename($src, $dst)) {
                admin_err('Could not move the file back.', 500);
            }
        }
        $meta = json_decode((string)$row['meta'], true);
        s3_upsert_object((int)$b['id'], (string)$row['key'], (int)$row['size'], (string)$row['etag'], (string)$row['content_type'], is_array($meta) ? $meta : [], gmdate('Y-m-d H:i:s'));
        $st = db()->prepare('DELETE FROM trash WHERE id = ?');
        $st->execute([$id]);
        admin_ok();
    }

    if ($sub === 'purge') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM trash WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row === false) {
            admin_err('Trash entry not found', 404);
        }
        admin_trash_purge_row($row);
        admin_ok();
    }

    if ($sub === 'empty') {
        foreach (db()->query('SELECT * FROM trash')->fetchAll() as $row) {
            admin_trash_purge_row($row);
        }
        s3_delete_tree(trash_dir());
        admin_ok();
    }

    admin_err('Bad request', 400);
}

/* ================= in-progress multipart uploads ================= */

function admin_dir_size(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $total = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $total += $f->getSize();
        }
    }
    return $total;
}

function admin_uploads(string $method): void
{
    admin_require_login();
    if ($method === 'GET') {
        $rows = db()->query('SELECT up.upload_id, up.key, up.created_at, up.user_id, up.bucket_id,
                u.username, b.name AS bucket_name
            FROM uploads up
            LEFT JOIN users u ON u.id = up.user_id
            LEFT JOIN buckets b ON b.id = up.bucket_id
            ORDER BY up.created_at DESC LIMIT 200')->fetchAll();
        foreach ($rows as &$row) {
            $dir = ($row['username'] !== null && $row['bucket_name'] !== null)
                ? DATA_DIR . '/_uploads/' . $row['username'] . '/' . $row['bucket_name'] . '/' . $row['upload_id']
                : null;
            $row['size'] = $dir !== null ? admin_dir_size($dir) : 0;
        }
        unset($row);
        admin_ok(['rows' => $rows]);
    }
    admin_require_csrf();
    $sub = (string)($_POST['_sub'] ?? '');

    if ($sub === 'abort') {
        $up = db_find_upload((string)($_POST['upload_id'] ?? ''));
        if ($up === null) {
            admin_err('Upload not found', 404);
        }
        $user = db_find_user((int)$up['user_id']);
        $b = db_find_bucket((int)$up['bucket_id']);
        if ($user !== null && $b !== null) {
            s3_delete_tree(s3_uploads_upload_dir($user['username'], $b['name'], (string)$up['upload_id']));
        }
        $st = db()->prepare('DELETE FROM uploads WHERE upload_id = ?');
        $st->execute([$up['upload_id']]);
        admin_ok();
    }

    if ($sub === 'cleanup') {
        $days = max(1, (int)($_POST['days'] ?? 7));
        $st = db()->prepare('SELECT * FROM uploads WHERE created_at < ?');
        $st->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        $removed = 0;
        foreach ($st->fetchAll() as $up) {
            $user = db_find_user((int)$up['user_id']);
            $b = db_find_bucket((int)$up['bucket_id']);
            if ($user !== null && $b !== null) {
                s3_delete_tree(s3_uploads_upload_dir($user['username'], $b['name'], (string)$up['upload_id']));
            }
            db()->prepare('DELETE FROM uploads WHERE upload_id = ?')->execute([$up['upload_id']]);
            $removed++;
        }
        admin_ok(['removed' => $removed]);
    }

    admin_err('Bad request', 400);
}

/* ================= ZIP download (pure PHP, stored entries) ================= */

// Streams $entries (list of [absolutePath, zipName]) as a ZIP archive and exits.
function admin_zip_stream(array $entries, string $zipName): void
{
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('output_buffering', 'Off');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addslashes($zipName) . '"');
    header('X-Content-Type-Options: nosniff');
    if (LOG_REQUESTS) {
        s3_log_row(null, 'admin', $_SERVER['REQUEST_METHOD'], 'objects?zip', 200, 0, null);
    }
    set_time_limit(0);
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($entries as [$path, $name]) {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            continue;
        }
        $size = filesize($path);
        $crc = hexdec(hash_file('crc32b', $path));
        $t = getdate(filemtime($path) ?: time());
        $dtime = (($t['hours'] & 0x1F) << 11) | (($t['minutes'] & 0x3F) << 5) | ((int)($t['seconds'] / 2) & 0x1F);
        $ddate = ((($t['year'] - 1980) & 0x7F) << 9) | (($t['mon'] & 0x0F) << 5) | ($t['mday'] & 0x1F);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 0x0014, 0x0800, 0, $dtime, $ddate, $crc, $size, $size, strlen($name), 0) . $name;
        echo $local;
        while (!feof($fp)) {
            $chunk = fread($fp, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
        }
        fclose($fp);
        flush();
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0014, 0x0014, 0x0800, 0, $dtime, $ddate,
            $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $offset += 30 + strlen($name) + $size;
        $count++;
    }
    echo $central;
    echo pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
    exit;
}

function admin_download_zip(array $b, string $prefix): void
{
    $user = db_find_user((int)$b['user_id']);
    $where = 'bucket_id = ?';
    $args = [(int)$b['id']];
    if ($prefix !== '') {
        $where .= ' AND key LIKE ? ESCAPE "\" AND key <> ?';
        array_push($args, s3_escape_like($prefix) . '%', $prefix);
    }
    $st = db()->prepare('SELECT key FROM objects WHERE ' . $where . ' ORDER BY key LIMIT 10000');
    $st->execute($args);
    $bucketDir = s3_bucket_dir($user['username'], $b['name']);
    $entries = [];
    foreach ($st->fetchAll() as $r) {
        $key = (string)$r['key'];
        if (s3_is_folder_marker($key)) {
            continue;
        }
        $path = $bucketDir . '/' . $key;
        if (!is_file($path)) {
            continue;
        }
        $name = ltrim(substr($key, strlen($prefix)), '/');
        $entries[] = [$path, $name];
    }
    if (!$entries) {
        admin_err('Nothing to download here.', 404);
    }
    $base = $prefix !== '' ? basename(rtrim($prefix, '/')) : $b['name'];
    admin_zip_stream($entries, preg_replace('/[^A-Za-z0-9._-]/', '_', $base) . '.zip');
}
