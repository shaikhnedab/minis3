<?php
// AWS Signature V4 authentication (path-style, like S3-compatible servers).
// Header-form Authorization and query-string presigned GET/HEAD URLs.

declare(strict_types=1);

function s3_headers_map(): array
{
    $map = [];
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h)) {
            foreach ($h as $k => $v) {
                $map[strtolower($k)] = $v;
            }
        }
    }
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            if (!isset($map[$name])) {
                $map[$name] = $v;
            }
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $map['content-type'] = $_SERVER['CONTENT_TYPE'];
    }
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $map['content-length'] = (string)$_SERVER['CONTENT_LENGTH'];
    }
    return $map;
}

function s3_header(string $name): ?string
{
    static $map = null;
    if ($map === null) {
        $map = s3_headers_map();
    }
    return $map[strtolower($name)] ?? null;
}

function aws_uri_encode(string $s): string
{
    return rawurlencode($s);
}

function canonical_query_string(string $rawQuery): string
{
    if ($rawQuery === '') {
        return '';
    }
    $pairs = [];
    foreach (explode('&', $rawQuery) as $pair) {
        if ($pair === '') {
            continue;
        }
        $eq = strpos($pair, '=');
        $k = $eq === false ? $pair : substr($pair, 0, $eq);
        $v = $eq === false ? '' : substr($pair, $eq + 1);
        $pairs[] = [aws_uri_encode(rawurldecode($k)), aws_uri_encode(rawurldecode($v))];
    }
    usort($pairs, function ($a, $b) {
        $c = strcmp($a[0], $b[0]);
        return $c !== 0 ? $c : strcmp($a[1], $b[1]);
    });
    $out = [];
    foreach ($pairs as $p) {
        $out[] = $p[0] . '=' . $p[1];
    }
    return implode('&', $out);
}

function parse_sigv4_authorization(string $h): array
{
    if (!preg_match('/^AWS4-HMAC-SHA256\s+Credential=([^,\s]+)\s*,\s*SignedHeaders=([^,\s]+)\s*,\s*Signature=([a-f0-9]{64})$/i', $h, $m)) {
        throw new S3Exception('InvalidArgument', 'Unsupported Authorization header format.', 400);
    }
    $cred = explode('/', $m[1]);
    if (count($cred) !== 5) {
        throw new S3Exception('InvalidArgument', 'Invalid credential scope.', 400);
    }
    return [
        'access_key' => $cred[0],
        'date' => $cred[1],
        'region' => $cred[2],
        'service' => $cred[3],
        'terminator' => $cred[4],
        'signed_headers' => $m[2],
        'signature' => strtolower($m[3]),
    ];
}

function raw_request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    return ($path === null || $path === '') ? '/' : $path;
}

function decoded_request_path(): string
{
    $p = raw_request_path();
    if ($p !== '/' && strpos($p, '%') !== false) {
        $p = rawurldecode($p);
    }
    return $p;
}

function s3_authenticate(): array
{
    $auth = s3_header('authorization');
    if ($auth === null || trim($auth) === '') {
        $user = s3_authenticate_presigned();
        if ($user !== null) {
            return $user;
        }
        throw new S3Exception('AccessDenied', 'Access Denied', 403);
    }
    $parts = parse_sigv4_authorization(trim($auth));

    if ($parts['service'] !== SERVICE) {
        throw new S3Exception('InvalidArgument', 'Signature service must be s3.', 400);
    }
    if ($parts['terminator'] !== 'aws4_request') {
        throw new S3Exception('InvalidArgument', 'Invalid signature terminator.', 400);
    }

    $amzDate = s3_header('x-amz-date');
    if ($amzDate === null || preg_match('/^\d{8}T\d{6}Z$/', $amzDate) !== 1) {
        throw new S3Exception('AccessDenied', 'Missing required header for this request: x-amz-date', 403);
    }
    $ts = DateTime::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));
    if ($ts === false || abs(time() - $ts->getTimestamp()) > MAX_SKEW) {
        throw new S3Exception('RequestTimeTooSkewed', 'The difference between the request time and the current time is too large.', 403);
    }
    if ($parts['date'] !== substr($amzDate, 0, 8)) {
        throw new S3Exception('RequestTimeTooSkewed', 'Credential date does not match request date.', 403);
    }

    $user = db_find_user_by_access_key($parts['access_key']);
    if ($user === null) {
        throw new S3Exception('InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.', 403);
    }

    $signed = array_map('strtolower', explode(';', $parts['signed_headers']));
    $signed = array_values(array_unique($signed));
    sort($signed);

    $canonicalHeaders = '';
    foreach ($signed as $name) {
        $val = s3_header($name);
        if ($val === null) {
            throw new S3Exception('InvalidArgument', 'Signed header not present: ' . $name, 400);
        }
        $canonicalHeaders .= $name . ':' . trim(preg_replace('/\s+/', ' ', $val)) . "\n";
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = raw_request_path();
    $canonicalQuery = canonical_query_string($_SERVER['QUERY_STRING'] ?? '');

    $payloadHash = s3_header('x-amz-content-sha256');
    if ($payloadHash === null || $payloadHash === '') {
        $payloadHash = 'UNSIGNED-PAYLOAD';
    }

    $canonicalRequest = $method . "\n"
        . $uri . "\n"
        . $canonicalQuery . "\n"
        . $canonicalHeaders . "\n"
        . implode(';', $signed) . "\n"
        . $payloadHash;

    $scope = $parts['date'] . '/' . $parts['region'] . '/' . SERVICE . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n"
        . $amzDate . "\n"
        . $scope . "\n"
        . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $parts['date'], 'AWS4' . $user['secret_key'], true);
    $kRegion = hash_hmac('sha256', $parts['region'], $kDate, true);
    $kService = hash_hmac('sha256', SERVICE, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $sig = hash_hmac('sha256', $stringToSign, $kSigning);

    if (!hash_equals($parts['signature'], $sig)) {
        throw new S3Exception('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.', 403);
    }

    return $user;
}

/* ---------- presigned URLs (query string SigV4, GET/HEAD only) ---------- */

// Signs a canonical request the way presigned URLs do (payload is always
// UNSIGNED-PAYLOAD) and returns the hex signature.
function s3_presign_signature(string $secretKey, string $date, string $region, string $amzDate, string $canonicalRequest): string
{
    $scope = $date . '/' . $region . '/' . SERVICE . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', SERVICE, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    return hash_hmac('sha256', $stringToSign, $kSigning);
}

// Builds a time-limited presigned GET URL for an object.
function s3_presign_url(array $user, string $bucketName, string $key, int $expires, string $region): string
{
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $scope = $date . '/' . $region . '/' . SERVICE . '/aws4_request';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = '/' . rawurlencode($bucketName) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    $q = [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => $user['access_key'] . '/' . $scope,
        'X-Amz-Date' => $amzDate,
        'X-Amz-Expires' => (string)$expires,
        'X-Amz-SignedHeaders' => 'host',
    ];
    $canonicalQuery = canonical_query_string(http_build_query($q));
    $canonicalRequest = "GET\n" . $path . "\n" . $canonicalQuery . "\n"
        . "host:" . $host . "\n\nhost\nUNSIGNED-PAYLOAD";
    $sig = s3_presign_signature($user['secret_key'], $date, $region, $amzDate, $canonicalRequest);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    return $scheme . '://' . $host . $path . '?' . $canonicalQuery . '&X-Amz-Signature=' . $sig;
}

// Authenticates a request that carries no Authorization header but has
// X-Amz-Signature in the query string (a presigned URL). Returns the user on
// success, null when this is not a presigned request, throws on bad signature.
function s3_authenticate_presigned(): ?array
{
    $sig = (string)($_GET['X-Amz-Signature'] ?? '');
    if ($sig === '') {
        return null;
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'GET' && $method !== 'HEAD') {
        throw new S3Exception('AccessDenied', 'Presigned URLs only support GET and HEAD.', 403);
    }
    if (($_GET['X-Amz-Algorithm'] ?? '') !== 'AWS4-HMAC-SHA256') {
        throw new S3Exception('AuthorizationQueryParametersError', 'Unsupported X-Amz-Algorithm.', 400);
    }
    $credential = (string)($_GET['X-Amz-Credential'] ?? '');
    $cred = explode('/', $credential);
    if (count($cred) !== 5) {
        throw new S3Exception('AuthorizationQueryParametersError', 'Invalid X-Amz-Credential.', 400);
    }
    [$accessKey, $date, $region, $service, $terminator] = $cred;
    if ($service !== SERVICE || $terminator !== 'aws4_request') {
        throw new S3Exception('AuthorizationQueryParametersError', 'Invalid credential scope.', 400);
    }
    $amzDate = (string)($_GET['X-Amz-Date'] ?? '');
    if (preg_match('/^\d{8}T\d{6}Z$/', $amzDate) !== 1 || $date !== substr($amzDate, 0, 8)) {
        throw new S3Exception('AccessDenied', 'Invalid X-Amz-Date.', 403);
    }
    $expires = (int)($_GET['X-Amz-Expires'] ?? 0);
    if ($expires < 1 || $expires > 604800) {
        throw new S3Exception('AuthorizationQueryParametersError', 'X-Amz-Expires must be between 1 and 604800.', 400);
    }
    $ts = DateTime::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));
    if ($ts === false || $ts->getTimestamp() + $expires < time() - 30) {
        throw new S3Exception('AccessDenied', 'Request has expired.', 403);
    }
    if (abs(time() - $ts->getTimestamp()) > MAX_SKEW + $expires) {
        throw new S3Exception('RequestTimeTooSkewed', 'The difference between the request time and the current time is too large.', 403);
    }

    $user = db_find_user_by_access_key($accessKey);
    if ($user === null) {
        throw new S3Exception('InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.', 403);
    }

    $signedHeaders = (string)($_GET['X-Amz-SignedHeaders'] ?? 'host');
    $signed = array_map('strtolower', explode(';', $signedHeaders));
    $signed = array_values(array_unique($signed));
    sort($signed);
    $canonicalHeaders = '';
    foreach ($signed as $name) {
        $val = $name === 'host' ? (string)($_SERVER['HTTP_HOST'] ?? '') : (string)s3_header($name);
        if ($val === '') {
            throw new S3Exception('AccessDenied', 'Signed header not present: ' . $name, 403);
        }
        $canonicalHeaders .= $name . ':' . trim(preg_replace('/\s+/', ' ', $val)) . "\n";
    }

    // Canonical query: every parameter except X-Amz-Signature, sorted + encoded.
    $pairs = [];
    foreach (explode('&', $_SERVER['QUERY_STRING'] ?? '') as $pair) {
        if ($pair === '') {
            continue;
        }
        $eq = strpos($pair, '=');
        $k = $eq === false ? $pair : substr($pair, 0, $eq);
        $v = $eq === false ? '' : substr($pair, $eq + 1);
        if (rawurldecode($k) === 'X-Amz-Signature') {
            continue;
        }
        $pairs[] = [aws_uri_encode(rawurldecode($k)), aws_uri_encode(rawurldecode($v))];
    }
    usort($pairs, function ($a, $b) {
        $c = strcmp($a[0], $b[0]);
        return $c !== 0 ? $c : strcmp($a[1], $b[1]);
    });
    $canonicalQuery = implode('&', array_map(fn($p) => $p[0] . '=' . $p[1], $pairs));

    $canonicalRequest = $method . "\n"
        . raw_request_path() . "\n"
        . $canonicalQuery . "\n"
        . $canonicalHeaders . "\n"
        . implode(';', $signed) . "\n"
        . 'UNSIGNED-PAYLOAD';

    $calc = s3_presign_signature($user['secret_key'], $date, $region, $amzDate, $canonicalRequest);
    if (!hash_equals(strtolower($sig), $calc)) {
        throw new S3Exception('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.', 403);
    }
    return $user;
}
