<?php
// Request logging and the shared response helper.

declare(strict_types=1);

function s3_client_ip(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function s3_log_row(?int $userId, string $kind, string $method, string $uri, int $status, int $bytes = 0, ?float $started = null): void
{
    if (!LOG_REQUESTS) {
        return;
    }
    static $enabled = [true, true];
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        try {
            $r = db()->query('SELECT log_s3, log_admin FROM admin WHERE id = 1')->fetch();
            if ($r !== false) {
                $enabled = [(int)$r['log_s3'] !== 0, (int)$r['log_admin'] !== 0];
            }
        } catch (Throwable $e) {
        }
    }
    if (($kind === 's3' && !$enabled[0]) || ($kind === 'admin' && !$enabled[1])) {
        return;
    }
    try {
        $st = db()->prepare('INSERT INTO logs (ts, user_id, kind, ip, method, uri, status, bytes, ms, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            gmdate('Y-m-d H:i:s'),
            $userId,
            $kind,
            s3_client_ip(),
            $method,
            substr($uri, 0, 2000),
            $status,
            $bytes,
            $started !== null ? (int)((microtime(true) - $started) * 1000) : 0,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        // Never let a logging failure break a request.
    }
}

// Sends the response, logs the request, then exits.
// $body can be a string, or a closure that streams the body and returns the number of bytes sent.
function s3_finish($body, int $status, array $headers, array $ctx): void
{
    http_response_code($status);
    header('X-Content-Type-Options: nosniff');
    header('X-Amz-Request-Id: ' . s3_request_id());
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    if (is_string($body)) {
        $bytes = strlen($body);
        if ($body !== '') {
            header('Content-Length: ' . $bytes);
        }
        echo $body;
    } else {
        $bytes = $body();
    }
    $ctx['bytes'] += $bytes;
    if (LOG_REQUESTS) {
        s3_log_row(
            $ctx['user_id'] ?? null,
            $ctx['kind'] ?? 's3',
            $ctx['method'] ?? '',
            $ctx['uri'] ?? '',
            $status,
            $ctx['bytes'] ?? 0,
            $ctx['started'] ?? null
        );
    }
    exit;
}
