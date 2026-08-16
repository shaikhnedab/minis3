<?php
// Common helpers, XML building and the S3 error exception.

declare(strict_types=1);

class S3Exception extends Exception
{
    public string $s3_code;
    public int $s3_status;

    public function __construct(string $code, string $message, int $status = 400)
    {
        $this->s3_code = $code;
        $this->s3_status = $status;
        parent::__construct($message);
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function s3_request_id(): string
{
    return strtoupper(bin2hex(random_bytes(8)));
}

function s3_iso8601(?int $ts = null): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts ?? time());
}

function s3_http_date(?int $ts = null): string
{
    return gmdate('D, d M Y H:i:s', $ts ?? time()) . ' GMT';
}

function s3_random_id(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function s3_generate_access_key(): string
{
    return 'AKIA' . strtoupper(bin2hex(random_bytes(8)));
}

function s3_generate_secret_key(): string
{
    return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
}

function s3_exit(string $body, int $status = 200, array $headers = []): void
{
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    header('X-Amz-Request-Id: ' . s3_request_id());
    if ($body !== '') {
        header('Content-Length: ' . strlen($body));
    }
    http_response_code($status);
    echo $body;
    exit;
}

function s3_xml(string $xml, int $status = 200, array $headers = []): void
{
    $headers['Content-Type'] = 'application/xml';
    s3_exit("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $xml, $status, $headers);
}

function s3_error_xml(S3Exception $ex, string $resource = ''): string
{
    return '<Error>'
        . '<Code>' . e($ex->s3_code) . '</Code>'
        . '<Message>' . e($ex->getMessage()) . '</Message>'
        . ($resource !== '' ? '<Resource>' . e($resource) . '</Resource>' : '')
        . '<RequestId>' . s3_request_id() . '</RequestId>'
        . '</Error>';
}

function s3_is_folder_marker(string $key): bool
{
    return $key !== '' && $key[strlen($key) - 1] === '/';
}

function s3_ensure_dir(string $dir, ?array $markerCtx = null): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        // A folder-placeholder object (key ending with "/") may occupy this
        // path as a plain file from uploads made before markers became
        // DB-only. If the DB agrees it is an empty placeholder, remove the
        // file so the real directory can be created.
        if ($markerCtx !== null && is_file($dir)) {
            [$username, $bucketName, $bucketId] = $markerCtx;
            $bucketDir = DATA_DIR . '/users/' . $username . '/' . $bucketName;
            if (strpos($dir, $bucketDir . '/') === 0) {
                $key = substr($dir, strlen($bucketDir) + 1) . '/';
                $obj = db_find_object((int)$bucketId, $key);
                if ($obj !== null && (int)$obj['size'] === 0) {
                    @unlink($dir);
                }
            }
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new S3Exception('InternalError', 'Failed to create directory.', 500);
        }
    }
}

function s3_tree_empty(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return true;
    }
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') {
            continue;
        }
        $p = $dir . '/' . $i;
        if (is_dir($p) && !is_link($p)) {
            if (!s3_tree_empty($p)) {
                return false;
            }
        } else {
            return false;
        }
    }
    return true;
}

function s3_delete_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') {
            continue;
        }
        $p = $dir . '/' . $i;
        if (is_dir($p) && !is_link($p)) {
            s3_delete_tree($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

function s3_parse_range(string $h, int $size): array
{
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($h), $m) || ($m[1] === '' && $m[2] === '')) {
        throw new S3Exception('InvalidRange', 'The requested range is not satisfiable', 416);
    }
    if ($m[1] === '') {
        $len = (int)$m[2];
        if ($len <= 0 || $size <= 0) {
            throw new S3Exception('InvalidRange', 'The requested range is not satisfiable', 416);
        }
        $start = max(0, $size - $len);
        $end = $size - 1;
    } else {
        $start = (int)$m[1];
        $end = ($m[2] === '') ? $size - 1 : (int)$m[2];
        if ($start >= $size) {
            throw new S3Exception('InvalidRange', 'The requested range is not satisfiable', 416);
        }
        if ($end >= $size) {
            $end = $size - 1;
        }
        if ($end < $start) {
            throw new S3Exception('InvalidRange', 'The requested range is not satisfiable', 416);
        }
    }
    return [$start, $end];
}

function s3_size_human(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return $i === 0 ? $v . ' B' : number_format($v, 1) . ' ' . $units[$i];
}

/* ---------- TOTP (RFC 6238) for admin two-factor authentication ---------- */

function totp_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) {
            return '';
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr((int)bindec($byte));
        }
    }
    return $out;
}

function totp_base32_encode(string $raw): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0; $i < strlen($raw); $i++) {
        $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[(int)bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function totp_secret_generate(): string
{
    return totp_base32_encode(random_bytes(20));
}

// 6-digit code for the given unix time; 30s step, SHA1 (standard TOTP).
function totp_code(string $secret, ?int $time = null): string
{
    $key = totp_base32_decode($secret);
    $counter = intdiv($time ?? time(), 30);
    $binCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

// True when $code matches the secret for the current or adjacent step.
function totp_verify(string $secret, string $code): bool
{
    if (preg_match('/^\d{6}$/', $code) !== 1) {
        return false;
    }
    foreach ([0, -1, 1] as $drift) {
        if (hash_equals(totp_code($secret, time() + $drift * 30), $code)) {
            return true;
        }
    }
    return false;
}

/* ---------- trash storage paths ---------- */

function trash_dir(): string
{
    return DATA_DIR . '/trash';
}

function trash_object_path(string $username, string $bucketName, string $key): string
{
    return trash_dir() . '/' . $username . '/' . $bucketName . '/' . $key;
}

/* ---------- branding (editable app name + favicon) ---------- */

// Multibyte-safe length (mbstring is optional in this project).
function u_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : (int)preg_match_all('/./us', $s);
}

// Custom application name from the admin settings; falls back to the
// APP_NAME constant when unset (or before installation).
function app_name(): string
{
    static $name = null;
    if ($name !== null) {
        return $name;
    }
    try {
        $n = db()->query('SELECT app_name FROM admin WHERE id = 1')->fetchColumn();
        $n = is_string($n) ? trim($n) : '';
    } catch (Throwable $e) {
        $n = '';
    }
    $name = ($n !== '' && u_strlen($n) <= 40) ? $n : APP_NAME;
    return $name;
}

// Extension of the uploaded custom favicon (data/favicon.{ext}), or null
// when the built-in icon is in use.
function favicon_ext(): ?string
{
    try {
        $e = db()->query('SELECT favicon FROM admin WHERE id = 1')->fetchColumn();
    } catch (Throwable $err) {
        return null;
    }
    return is_string($e) && preg_match('/^(png|gif|jpe?g|svg|ico|webp)$/', $e) === 1 ? $e : null;
}

function favicon_path(): ?string
{
    $e = favicon_ext();
    return $e === null ? null : DATA_DIR . '/favicon.' . $e;
}

// The built-in favicon: a blue rounded square with a white bucket.
function favicon_default_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#0B57D0"/>'
        . '<path d="M10 12h12l2 10H8z" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round"/>'
        . '<path d="M13 12V9a3 3 0 0 1 6 0v3" fill="none" stroke="#fff" stroke-width="2"/></svg>';
}
