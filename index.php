<?php
// MiniS3 front controller - routes every S3 API request.

declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/lib/util.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/s3.php';
require APP_ROOT . '/lib/log.php';

db_init();

$started = microtime(true);
$method = $_SERVER['REQUEST_METHOD'];
$path = decoded_request_path();
$queryRaw = $_SERVER['QUERY_STRING'] ?? '';
parse_str($queryRaw, $query);

$ctx = [
    'user_id' => null,
    'kind' => 's3',
    'method' => $method,
    'uri' => $path . ($queryRaw !== '' ? '?' . $queryRaw : ''),
    'bytes' => 0,
    'started' => $started,
];

// Hidden diagnostics: enabled by creating an empty file "data/diag.enabled",
// then requesting /diag. Delete the marker file when done.
if ($path === '/diag' && is_file(APP_ROOT . '/data/diag.enabled')) {
    header('Content-Type: text/plain');
    echo 'PHP: ' . PHP_VERSION . "\n";
    echo 'SAPI: ' . php_sapi_name() . "\n";
    echo 'SERVER_SOFTWARE: ' . ($_SERVER['SERVER_SOFTWARE'] ?? '(not set)') . "\n";
    echo 'GATEWAY_INTERFACE: ' . ($_SERVER['GATEWAY_INTERFACE'] ?? '(not set)') . "\n";
    echo 'SERVER_PROTOCOL: ' . ($_SERVER['SERVER_PROTOCOL'] ?? '(not set)') . "\n";
    echo 'METHOD: ' . $method . "\n";
    echo 'CONTENT_LENGTH: ' . ($_SERVER['CONTENT_LENGTH'] ?? '(not set)') . "\n";
    echo 'HTTP_TRANSFER_ENCODING: ' . ($_SERVER['HTTP_TRANSFER_ENCODING'] ?? '(not set)') . "\n";
    echo 'HTTP_CONTENT_ENCODING: ' . ($_SERVER['HTTP_CONTENT_ENCODING'] ?? '(not set)') . "\n";
    echo 'HTTP_EXPECT: ' . ($_SERVER['HTTP_EXPECT'] ?? '(not set)') . "\n";
    echo 'HTTP_X_AMZ_CONTENT_SHA256: ' . ($_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? '(not set)') . "\n";
    $in = file_get_contents('php://input');
    echo 'INPUT_BYTES: ' . strlen($in) . "\n";
    echo 'INPUT_HEX_PREFIX: ' . bin2hex(substr($in, 0, 300)) . "\n";
    echo 'INPUT_ASCII_PREFIX: ' . var_export(substr($in, 0, 300), true) . "\n";
    echo 'APP_ROOT: ' . APP_ROOT . "\n";
    exit;
}

// Favicon: browsers poll /favicon.ico and pollute the logs with auth errors.
// Serves the admin-uploaded custom icon when one exists, else the built-in SVG.
if ($path === '/favicon.ico') {
    header('X-Content-Type-Options: nosniff');
    $custom = favicon_path();
    if ($custom !== null && is_file($custom)) {
        $types = ['png' => 'image/png', 'gif' => 'image/gif', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'webp' => 'image/webp'];
        header('Content-Type: ' . ($types[favicon_ext()] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string)filesize($custom));
        header('Cache-Control: public, max-age=3600');
        readfile($custom);
        exit;
    }
    $svg = favicon_default_svg();
    header('Content-Type: image/svg+xml');
    header('Content-Length: ' . strlen($svg));
    header('Cache-Control: public, max-age=86400');
    echo $svg;
    exit;
}

try {
    $user = s3_authenticate();
    $ctx['user_id'] = (int)$user['id'];
    s3_route($method, $path, $query, $user, $ctx);
} catch (S3Exception $e) {
    s3_finish(s3_error_xml($e, $path), $e->s3_status, ['Content-Type' => 'application/xml'], $ctx);
} catch (Throwable $e) {
    @error_log('MiniS3 internal error: ' . $e->getMessage() . ' @ ' . $path);
    s3_finish(
        s3_error_xml(new S3Exception('InternalError', 'We encountered an internal error. Please try again.', 500), $path),
        500,
        ['Content-Type' => 'application/xml'],
        $ctx
    );
}
