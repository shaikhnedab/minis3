<?php
// Router for the PHP built-in development server:
//   php -S 127.0.0.1:8000 router.php
// Not needed on Apache/nginx.

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

if ($uri === '/config.php' || strpos($uri, '/data/') === 0 || strpos($uri, '/lib/') === 0 || strpos($uri, '/tests/') === 0
    || strpos($uri, '/.git') === 0 || substr($uri, -16) === 'Zone.Identifier') {
    http_response_code(403);
    exit('Forbidden');
}

if ($uri === '/admin' || $uri === '/admin/' || strpos($uri, '/admin/') === 0) {
    $file = __DIR__ . $uri;
    if (is_file($file)) {
        return false;
    }
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    require __DIR__ . '/admin/index.php';
    exit;
}

$file = __DIR__ . $uri;
if (is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
