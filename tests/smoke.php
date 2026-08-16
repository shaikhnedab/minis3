<?php
// MiniS3 smoke test - end-to-end verification of the S3 API.
//
// Usage:
//   1. Start the dev server:    php -S 127.0.0.1:8000 router.php
//   2. Run this script:         php tests/smoke.php
//
// It automatically installs the server, logs in as admin, creates a test user
// and runs the full API test suite. To reuse an existing user instead:
//   S3_ACCESS_KEY=... S3_SECRET_KEY=... php tests/smoke.php

declare(strict_types=1);

$endpoint = getenv('S3_ENDPOINT') ?: 'http://127.0.0.1:8000';
$adminPassword = getenv('S3_ADMIN_PASSWORD') ?: 'admin12345';
$region = 'us-east-1';

$passed = 0;
$failed = 0;

function t_check(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name\n";
    }
}

// ---------- tiny HTTP client with session cookie support ----------
// Uses raw sockets: PHP's stream wrapper can misparse headers against
// the single-threaded built-in dev server on Windows, raw TCP does not.
$cookies = '';

function http(string $method, string $url, array $headers = [], string $body = ''): array
{
    global $cookies;
    $p = parse_url($url);
    $host = $p['host'] ?? '127.0.0.1';
    $port = $p['port'] ?? 80;
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $hs = [];
    if ($cookies !== '') {
        $hs[] = 'Cookie: ' . $cookies;
    }
    foreach ($headers as $k => $v) {
        $hs[] = is_int($k) ? $v : $k . ': ' . $v;
    }
    $req = "$method $path HTTP/1.1\r\nHost: $host:$port\r\nConnection: close\r\n";
    if ($hs) {
        $req .= implode("\r\n", $hs) . "\r\n";
    }
    if ($body !== '') {
        $hasCT = false;
        foreach ($hs as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $hasCT = true;
                break;
            }
        }
        if (!$hasCT) {
            $req .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
        $req .= "Content-Length: " . strlen($body) . "\r\n";
    }
    $req .= "\r\n" . $body;

    $status = 0;
    $rh = [];
    $respBody = '';
    for ($attempt = 0; $attempt < 3 && $status === 0; $attempt++) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$fp) {
            usleep(100000);
            continue;
        }
        fwrite($fp, $req);
        stream_set_timeout($fp, 30);
        $resp = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $resp .= $chunk;
        }
        fclose($fp);
        $headEnd = strpos($resp, "\r\n\r\n");
        if ($headEnd === false) {
            usleep(100000);
            continue;
        }
        $head = substr($resp, 0, $headEnd);
        $respBody = substr($resp, $headEnd + 4);
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $head, $m)) {
            $status = (int)$m[1];
        }
        foreach (explode("\r\n", $head) as $line) {
            if (preg_match('/^Set-Cookie:\s*([^;=]+)=([^;]*)/i', $line, $m)) {
                $cookies = trim($m[1]) . '=' . trim($m[2]) . '; ';
            } elseif (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $rh[strtolower(trim($k))] = trim($v);
            }
        }
        if (isset($rh['transfer-encoding']) && stripos($rh['transfer-encoding'], 'chunked') !== false) {
            $decoded = '';
            $buf = $respBody;
            while (preg_match('/^([0-9a-fA-F]+)\r\n/', $buf, $m)) {
                $len = hexdec($m[1]);
                if ($len === 0) {
                    break;
                }
                $decoded .= substr($buf, strlen($m[0]), $len);
                $buf = substr($buf, strlen($m[0]) + $len + 2);
            }
            $respBody = $decoded;
        }
    }
    return [$status, $rh, $respBody];
}

// ---------- admin bootstrap ----------
$accessKey = getenv('S3_ACCESS_KEY') ?: '';
$secretKey = getenv('S3_SECRET_KEY') ?: '';

if ($accessKey === '' || $secretKey === '') {
    echo "Bootstrap: installing server and creating a test user...\n";
    [$s1] = http('POST', $endpoint . '/install.php', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['password' => $adminPassword, 'password2' => $adminPassword]));
    echo "  install.php -> $s1\n";
    [$s2, , $loginBody] = http('POST', $endpoint . '/admin/api.php?action=login', [], http_build_query(['username' => 'admin', 'password' => $adminPassword]));
    echo "  admin login -> $s2\n";
    if ($s2 !== 200) {
        echo "Admin login failed. Provide S3_ACCESS_KEY/S3_SECRET_KEY env vars for an existing user.\n";
        exit(1);
    }
    $loginData = json_decode($loginBody, true);
    $csrf = $loginData['data']['csrf'] ?? '';
    [$s3, , $usersBody] = http('POST', $endpoint . '/admin/api.php?action=users', ['X-CSRF-Token: ' . $csrf], http_build_query(['_sub' => 'create', 'username' => 'smoke_' . substr(bin2hex(random_bytes(3)), 0, 6)]));
    echo "  create user -> $s3\n";
    if ($s3 !== 200) {
        echo "Could not create test user.\n";
        exit(1);
    }
    $userData = json_decode($usersBody, true);
    $accessKey = $userData['data']['access_key'];
    $secretKey = $userData['data']['secret_key'];
    $adminUserId = $userData['data']['id'];
    echo "  user created, running tests...\n\n";
}

// ---------- AWS Signature V4 client ----------
function aws_encode(string $s): string
{
    return rawurlencode($s);
}

function canonical_query(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    $pairs = [];
    foreach (explode('&', $raw) as $pair) {
        if ($pair === '') {
            continue;
        }
        $eq = strpos($pair, '=');
        $k = $eq === false ? $pair : substr($pair, 0, $eq);
        $v = $eq === false ? '' : substr($pair, $eq + 1);
        $pairs[] = [aws_encode(rawurldecode($k)), aws_encode(rawurldecode($v))];
    }
    usort($pairs, function ($a, $b) {
        $c = strcmp($a[0], $b[0]);
        return $c !== 0 ? $c : strcmp($a[1], $b[1]);
    });
    return implode('&', array_map(function ($p) {
        return $p[0] . '=' . $p[1];
    }, $pairs));
}

function s3_request(string $method, string $path, array $headerLines = [], string $body = '', bool $tamper = false, ?string $signBody = null): array
{
    global $endpoint, $accessKey, $secretKey, $region;

    $headers = [];
    foreach ($headerLines as $line) {
        [$k, $v] = explode(':', $line, 2);
        $headers[trim($k)] = trim($v);
    }
    $headers = array_change_key_case($headers, CASE_LOWER);

    $qpos = strpos($path, '?');
    $p = $qpos === false ? $path : substr($path, 0, $qpos);
    $q = $qpos === false ? '' : substr($path, $qpos + 1);

    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $headers['x-amz-date'] = $amzDate;
    $headers['x-amz-content-sha256'] = hash('sha256', $signBody ?? $body);

    $signed = array_map('strtolower', array_keys($headers));
    sort($signed);
    $canonicalHeaders = '';
    foreach ($signed as $lk) {
        $canonicalHeaders .= $lk . ':' . trim((string)$headers[$lk]) . "\n";
    }
    $canonicalRequest = $method . "\n" . $p . "\n" . canonical_query($q) . "\n" . $canonicalHeaders . "\n" . implode(';', $signed) . "\n" . $headers['x-amz-content-sha256'];
    $scope = $date . '/' . $region . '/s3/aws4_request';
    $sts = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $sig = hash_hmac('sha256', $sts, $kSigning);
    if ($tamper) {
        $sig = substr($sig, 0, -1) . (substr($sig, -1) === '0' ? '1' : '0');
    }
    $authorization = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope . ', SignedHeaders=' . implode(';', $signed) . ', Signature=' . $sig;

    $hs = [];
    foreach ($headers as $k => $v) {
        $hs[] = $k . ': ' . $v;
    }
    $hs[] = 'Authorization: ' . $authorization;

    return http($method, $endpoint . $path, $hs, $body);
}

// Builds a presigned URL for any method (GET / PUT / DELETE / HEAD) and
// returns [url, signature]. Used to exercise query-string auth the way
// external tools (e.g. game-panel backup daemons) do.
function presign_url(string $method, string $path, int $expires = 300): array
{
    global $endpoint, $accessKey, $secretKey, $region;
    $host = parse_url($endpoint, PHP_URL_HOST) . ':' . parse_url($endpoint, PHP_URL_PORT);
    $date = gmdate('Ymd\THis\Z');
    $scope = substr($date, 0, 8) . '/' . $region . '/s3/aws4_request';
    $q = 'X-Amz-Algorithm=AWS4-HMAC-SHA256'
        . '&X-Amz-Credential=' . rawurlencode($accessKey . '/' . $scope)
        . '&X-Amz-Date=' . $date . '&X-Amz-Expires=' . $expires . '&X-Amz-SignedHeaders=host';
    $canonical = "$method\n$path\n$q\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
    $kDate = hash_hmac('sha256', substr($date, 0, 8), 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $sts = "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical);
    return [$path . '?' . $q . '&X-Amz-Signature=' . hash_hmac('sha256', $sts, $kSigning), hash_hmac('sha256', $sts, $kSigning)];
}

// ---------- tests ----------
$bucket = 'smoke-' . bin2hex(random_bytes(4));

[$st, , $body] = s3_request('GET', '/');
t_check($st === 200 && strpos($body, '<ListAllMyBucketsResult') !== false, 'ListBuckets 200');

[$st] = s3_request('PUT', '/' . $bucket);
t_check($st === 200, 'CreateBucket 200');

[$st] = s3_request('PUT', '/' . $bucket);
t_check($st === 409, 'CreateBucket duplicate 409');

[$st, , $body] = s3_request('GET', '/' . $bucket . '?location');
t_check($st === 200 && strpos($body, 'LocationConstraint') !== false, 'GetBucketLocation 200');

[$st] = s3_request('HEAD', '/' . $bucket);
t_check($st === 200, 'HeadBucket 200');

[$st, , $body] = s3_request('GET', '/' . $bucket . '?list-type=2');
t_check($st === 200 && strpos($body, '<KeyCount>0</KeyCount>') !== false, 'ListObjectsV2 empty');

$content = 'Hello, MiniS3!';
$md5 = md5($content);
[$st, $rh] = s3_request('PUT', '/' . $bucket . '/hello.txt', ['Content-Type: text/plain'], $content);
t_check($st === 200 && strpos($rh['etag'] ?? '', $md5) !== false, 'PutObject 200 + ETag');

[$st] = s3_request('PUT', '/' . $bucket . '/md5.txt', ['Content-Type: text/plain', 'Content-MD5: ' . base64_encode(md5('md5-body', true))], 'md5-body');
t_check($st === 200, 'PutObject with Content-MD5 200');

[$st, , $body] = s3_request('PUT', '/' . $bucket . '/md5bad.txt', ['Content-Type: text/plain', 'Content-MD5: ' . base64_encode(md5('other', true))], 'md5-body');
t_check($st === 400 && strpos($body, 'BadDigest') !== false, 'Wrong Content-MD5 400 BadDigest');

[$st, , $body] = s3_request('PUT', '/' . $bucket . '/tampered.txt', ['Content-Type: text/plain'], 'tampered-payload', false, 'original-payload');
t_check($st === 400 && strpos($body, 'XAmzContentSHA256Mismatch') !== false, 'Body swapped after signing 400');

[$st, $rh] = s3_request('HEAD', '/' . $bucket . '/hello.txt');
t_check($st === 200 && ($rh['content-length'] ?? '') === (string)strlen($content), 'HeadObject headers');

[$st, , $body] = s3_request('GET', '/' . $bucket . '/hello.txt');
t_check($st === 200 && $body === $content, 'GetObject content');

// ---------- presigned URL (query string auth) ----------
[$pUrl, $pSig] = presign_url('GET', '/' . $bucket . '/hello.txt');

[$st, , $body] = http('GET', $endpoint . $pUrl);
t_check($st === 200 && $body === $content, 'Presigned GET 200 (no auth header)');

$badSig = substr($pSig, 0, -1) . (substr($pSig, -1) === '0' ? '1' : '0');
[$st, , $body] = http('GET', $endpoint . preg_replace('/X-Amz-Signature=[^&]+/', 'X-Amz-Signature=' . $badSig, $pUrl));
t_check($st === 403 && strpos($body, 'SignatureDoesNotMatch') !== false, 'Tampered presigned signature 403');

[$st, , $body] = http('PUT', $endpoint . $pUrl, ['Content-Length: 1'], 'x');
t_check($st === 403, 'Presigned GET URL cannot be used with PUT (signature mismatch)');

[$st, , $body] = s3_request('GET', '/' . $bucket . '/hello.txt', ['Range: bytes=0-4']);
t_check($st === 206 && $body === 'Hello', 'GetObject range 0-4 (206)');

[$st, , $body] = s3_request('GET', '/' . $bucket . '/hello.txt', ['Range: bytes=-5']);
t_check($st === 206 && $body === 'niS3!', 'GetObject suffix range (206)');

[$st] = s3_request('PUT', '/' . $bucket . '/copy.txt', ['X-Amz-Copy-Source: /' . $bucket . '/hello.txt']);
t_check($st === 200, 'CopyObject 200');

[$st, , $body] = s3_request('GET', '/' . $bucket . '?list-type=2');
t_check($st === 200 && strpos($body, '<KeyCount>3</KeyCount>') !== false, 'ListObjectsV2 3 objects');

[$st, , $body] = s3_request('GET', '/' . $bucket . '?list-type=2&prefix=hello');
t_check($st === 200 && strpos($body, '<KeyCount>1</KeyCount>') !== false, 'ListObjectsV2 prefix filter');

[$st, , $body] = s3_request('PUT', '/' . $bucket . '/folder/sub/file.txt', ['Content-Type: text/plain'], 'nested');
t_check($st === 200, 'PutObject nested key');

[$st, , $body] = s3_request('GET', '/' . $bucket . '?list-type=2&delimiter=/');
t_check($st === 200 && strpos($body, '<CommonPrefixes><Prefix>folder/</Prefix></CommonPrefixes>') !== false, 'ListObjectsV2 delimiter');

[$st, , $body] = s3_request('POST', '/' . $bucket . '/big.bin?uploads');
t_check($st === 200 && preg_match('#<UploadId>([^<]+)</UploadId>#', $body, $m) === 1, 'InitiateMultipartUpload');
$uploadId = $m[1] ?? '';

$part1 = str_repeat('a', 5 * 1024 * 1024);
$part2 = 'tail-end';
[$st, $rh] = s3_request('PUT', '/' . $bucket . '/big.bin?partNumber=1&uploadId=' . urlencode($uploadId), ['Content-Type: application/octet-stream'], $part1);
t_check($st === 200, 'UploadPart 1');
$etag1 = $rh['etag'] ?? '';
[$st] = s3_request('PUT', '/' . $bucket . '/big.bin?partNumber=2&uploadId=' . urlencode($uploadId), ['Content-Type: application/octet-stream'], $part2);
t_check($st === 200, 'UploadPart 2');

[$st, , $body] = s3_request('GET', '/' . $bucket . '/big.bin?uploadId=' . urlencode($uploadId));
t_check($st === 200 && strpos($body, '<ListPartsResult') !== false && substr_count($body, '<Part>') === 2, 'ListParts shows 2 parts');

[$st, , $body] = s3_request('POST', '/' . $bucket . '/big.bin?uploadId=' . urlencode($uploadId), ['Content-Type: application/xml'],
    '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>' . $etag1 . '</ETag></Part><Part><PartNumber>2</PartNumber><ETag></ETag></Part></CompleteMultipartUpload>');
t_check($st === 200 && strpos($body, '<CompleteMultipartUploadResult') !== false, 'CompleteMultipartUpload');

[$st, , $body] = s3_request('GET', '/' . $bucket . '/big.bin');
t_check($st === 200 && strlen($body) === strlen($part1) + strlen($part2) && substr($body, -8) === 'tail-end', 'Multipart object readable');

[$st, , $body] = s3_request('POST', '/' . $bucket . '/abort.bin?uploads');
preg_match('#<UploadId>([^<]+)</UploadId>#', $body, $m);
$uid2 = $m[1] ?? '';
s3_request('PUT', '/' . $bucket . '/abort.bin?partNumber=1&uploadId=' . urlencode($uid2), ['Content-Type: application/octet-stream'], 'x');
[$st] = s3_request('DELETE', '/' . $bucket . '/abort.bin?uploadId=' . urlencode($uid2));
t_check($st === 204, 'AbortMultipartUpload 204');

// ---------- storage quota (admin sets 1 MB on the test user) ----------
if (!empty($csrf) && !empty($adminUserId)) {
    [$st] = http('POST', $endpoint . '/admin/api.php?action=users', ['X-CSRF-Token: ' . $csrf],
        http_build_query(['_sub' => 'update', 'id' => $adminUserId, 'quota_mb' => '1']));
    echo "  set quota -> $st\n";
    [$st, , $body] = s3_request('PUT', '/' . $bucket . '/quota.bin', ['Content-Type: application/octet-stream'], str_repeat('q', 2 * 1024 * 1024));
    t_check($st === 400 && strpos($body, 'QuotaExceeded') !== false, 'PUT beyond quota 400 QuotaExceeded');
    [$st] = http('POST', $endpoint . '/admin/api.php?action=users', ['X-CSRF-Token: ' . $csrf],
        http_build_query(['_sub' => 'update', 'id' => $adminUserId, 'quota_mb' => '0']));
    [$st] = s3_request('PUT', '/' . $bucket . '/quota.bin', ['Content-Type: application/octet-stream'], str_repeat('q', 2 * 1024 * 1024));
    t_check($st === 200, 'PUT succeeds after quota lifted');
}

[$st] = s3_request('PUT', '/' . $bucket . '/empty.txt', ['Content-Type: text/plain'], '');
t_check($st === 200, 'PutObject empty body');

[$st, , $body] = s3_request('GET', '/' . $bucket . '/missing.txt');
t_check($st === 404 && strpos($body, '<Code>NoSuchKey</Code>') !== false, 'GetObject missing 404');

[$st, , $body] = s3_request('GET', '/no-such-bucket-xyz/list?list-type=2');
t_check($st === 404, 'Missing bucket 404');

[$st, , $body] = s3_request('POST', '/' . $bucket . '?delete', ['Content-Type: application/xml'],
    '<Delete><Object><Key>hello.txt</Key></Object><Object><Key>copy.txt</Key></Object><Object><Key>nope.txt</Key></Object><Quiet>false</Quiet></Delete>');
t_check($st === 200 && strpos($body, '<Deleted>') !== false && strpos($body, 'NoSuchKey') !== false, 'DeleteObjects (multi)');

[$st] = s3_request('GET', '/', [], '', true);
t_check($st === 403, 'Bad signature 403');

[$st, , $body] = s3_request('GET', '/' . $bucket . '?list-type=2');
t_check($st === 200 && strpos($body, '<KeyCount>5</KeyCount>') !== false, '5 objects remain (nested, big.bin, empty.txt, md5.txt, quota.bin)');

// ---------- presigned PUT / DELETE (query string auth for uploads) ----------
// This is how game-panel backup daemons (e.g. Pelican Wings) push backups:
// the panel hands out presigned PUT URLs and the daemon PUTs the payload.
$ppBody = 'presigned-put-' . bin2hex(random_bytes(4));
[$ppUrl] = presign_url('PUT', '/' . $bucket . '/presign-put.bin');
[$st, , $body] = http('PUT', $endpoint . $ppUrl, ['Content-Length: ' . strlen($ppBody)], $ppBody);
t_check($st === 200, 'Presigned PUT upload 200');
[$st, , $body] = s3_request('GET', '/' . $bucket . '/presign-put.bin');
t_check($st === 200 && $body === $ppBody, 'Presigned PUT object readable');
[$st, , $body] = http('GET', $endpoint . '/' . $bucket . '/presign-put.bin');
t_check($st === 403, 'Presigned PUT object is not public');

s3_request('PUT', '/' . $bucket . '/presign-del.txt', ['Content-Type: text/plain'], 'del-me');
[$pdUrl] = presign_url('DELETE', '/' . $bucket . '/presign-del.txt');
[$st] = http('DELETE', $endpoint . $pdUrl);
t_check($st === 204, 'Presigned DELETE 204');
[$st] = s3_request('HEAD', '/' . $bucket . '/presign-del.txt');
t_check($st === 404, 'Presigned DELETE removed the object');
s3_request('DELETE', '/' . $bucket . '/presign-put.bin');

// ---------- delimiter pagination: CommonPrefixes must not be lost ----------
for ($i = 1; $i <= 6; $i++) {
    s3_request('PUT', '/' . $bucket . '/pdir' . $i . '/file.txt', ['Content-Type: text/plain'], 'p');
}
$seenPrefixes = [];
$token = '';
$pages = 0;
do {
    $url = '/' . $bucket . '?list-type=2&delimiter=/&max-keys=2'
        . ($token !== '' ? '&continuation-token=' . urlencode($token) : '');
    [$st, , $body] = s3_request('GET', $url);
    if ($st !== 200) {
        break;
    }
    preg_match_all('#<Prefix>([^<]+)</Prefix>#', $body, $m);
    $seenPrefixes = array_merge($seenPrefixes, $m[1]);
    $pages++;
    preg_match('#<NextContinuationToken>([^<]+)</NextContinuationToken>#', $body, $tm);
    $token = $tm[1] ?? '';
} while ($token !== '' && $pages < 10);
sort($seenPrefixes);
t_check($pages >= 3 && $seenPrefixes === ['folder/', 'pdir1/', 'pdir2/', 'pdir3/', 'pdir4/', 'pdir5/', 'pdir6/'],
    'Delimiter pagination keeps all CommonPrefixes across pages');
for ($i = 1; $i <= 6; $i++) {
    s3_request('DELETE', '/' . $bucket . '/pdir' . $i . '/file.txt');
}

[$st] = s3_request('DELETE', '/' . $bucket . '/big.bin');
t_check($st === 204, 'DeleteObject 204');
[$st] = s3_request('DELETE', '/' . $bucket . '/empty.txt');
[$st] = s3_request('DELETE', '/' . $bucket . '/md5.txt');
[$st] = s3_request('DELETE', '/' . $bucket . '/quota.bin');
[$st] = s3_request('DELETE', '/' . $bucket . '/folder/sub/file.txt');
[$st] = s3_request('DELETE', '/' . $bucket);
t_check($st === 204, 'DeleteBucket 204');

[$st, , $body] = s3_request('GET', '/');
t_check($st === 200 && strpos($body, $bucket) === false, 'Bucket gone from ListBuckets');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
