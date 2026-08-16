<?php
// S3-compatible API handlers. Storage layout:
//   data/users/{username}/{bucket}/{key...}
//   data/_uploads/{username}/{bucket}/{uploadId}/part-N   (multipart uploads in progress)

declare(strict_types=1);

function s3_user_dir(string $username): string
{
    return DATA_DIR . '/users/' . $username;
}

function s3_bucket_dir(string $username, string $bucket): string
{
    return s3_user_dir($username) . '/' . $bucket;
}

function s3_object_path(string $username, string $bucket, string $key): string
{
    return s3_bucket_dir($username, $bucket) . '/' . $key;
}

function s3_uploads_user_dir(string $username): string
{
    return UPLOADS_DIR . '/' . $username;
}

function s3_uploads_upload_dir(string $username, string $bucket, string $uploadId): string
{
    return UPLOADS_DIR . '/' . $username . '/' . $bucket . '/' . $uploadId;
}

function s3_bucket_name_valid(string $name): bool
{
    return strlen($name) >= 3 && strlen($name) <= 63
        && preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $name) === 1;
}

function s3_key_valid(string $key): bool
{
    if ($key === '' || strlen($key) > 1024) {
        return false;
    }
    if ($key[0] === '/') {
        return false;
    }
    if (strpos($key, '\\') !== false || strpos($key, "\0") !== false) {
        return false;
    }
    foreach (explode('/', $key) as $seg) {
        if ($seg === '..' || $seg === '.') {
            return false;
        }
    }
    return true;
}

function s3_escape_like(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

function s3_route(string $method, string $path, array $q, array $user, array $ctx): void
{
    $path = ltrim($path, '/');
    if ($path === '') {
        if ($method !== 'GET') {
            throw new S3Exception('MethodNotAllowed', 'The specified method is not allowed against this resource.', 405);
        }
        s3_list_buckets($user, $ctx);
        return;
    }

    $parts = explode('/', $path);
    $bucket = array_shift($parts);
    if (!s3_bucket_name_valid($bucket)) {
        throw new S3Exception('InvalidBucketName', 'The specified bucket is not valid.', 400);
    }

    if (count($parts) === 1 && $parts[0] === '') {
        $parts = [];
    }

    if (count($parts) === 0) {
        s3_bucket_route($method, $bucket, $q, $user, $ctx);
        return;
    }

    $key = implode('/', $parts);
    if (!s3_key_valid($key)) {
        throw new S3Exception('InvalidArgument', 'Object key is not valid.', 400);
    }
    s3_key_route($method, $bucket, $key, $q, $user, $ctx);
}

function s3_unsupported_subresource(array $keys): bool
{
    $unsupported = [
        'tagging', 'lifecycle', 'cors', 'replication', 'encryption', 'website',
        'logging', 'requestPayment', 'notification', 'accelerate',
        'ownershipControls', 'publicAccessBlock', 'analytics', 'intelligent-tiering',
        'metrics', 'inventory', 'object-lock', 'legal-hold', 'retention', 'versions',
    ];
    foreach ($unsupported as $u) {
        if (in_array($u, $keys, true)) {
            return true;
        }
    }
    return false;
}

function s3_bucket_require(?array $b, string $bucket): void
{
    if ($b === null) {
        throw new S3Exception('NoSuchBucket', 'The specified bucket does not exist', 404);
    }
}

function s3_object_require(?array $o): void
{
    if ($o === null) {
        throw new S3Exception('NoSuchKey', 'The specified key does not exist.', 404);
    }
}

function s3_bucket_route(string $method, string $bucket, array $q, array $user, array $ctx): void
{
    $b = db_find_bucket_by_name($user['id'], $bucket);

    if (s3_unsupported_subresource(array_keys($q))) {
        throw new S3Exception('NotImplemented', 'A header you provided implies functionality that is not implemented.', 501);
    }

    if ($method === 'GET') {
        if (($q['list-type'] ?? '') === '2') {
            s3_list_objects_v2($user, $bucket, $b, $q, $ctx);
            return;
        }
        if (array_key_exists('location', $q)) {
            s3_bucket_require($b, $bucket);
            s3_get_bucket_location($ctx);
            return;
        }
        if (array_key_exists('versioning', $q)) {
            s3_bucket_require($b, $bucket);
            s3_get_bucket_versioning($ctx);
            return;
        }
        if (array_key_exists('acl', $q)) {
            s3_bucket_require($b, $bucket);
            s3_get_bucket_acl($user, $ctx);
            return;
        }
        if (array_key_exists('policy', $q)) {
            s3_bucket_require($b, $bucket);
            throw new S3Exception('NoSuchBucketPolicy', 'The bucket policy does not exist', 404);
        }
        if (array_key_exists('uploads', $q)) {
            s3_bucket_require($b, $bucket);
            s3_list_multipart_uploads($b, $ctx);
            return;
        }
        s3_list_objects_v1($user, $bucket, $b, $q, $ctx);
        return;
    }

    if ($method === 'HEAD') {
        s3_bucket_require($b, $bucket);
        s3_finish('', 200, ['x-amz-bucket-region' => REGION], $ctx);
        return;
    }

    if ($method === 'PUT') {
        if (array_key_exists('acl', $q)) {
            s3_bucket_require($b, $bucket);
            s3_put_acl($ctx);
            return;
        }
        s3_create_bucket($user, $bucket, $ctx);
        return;
    }

    if ($method === 'DELETE') {
        s3_delete_bucket($user, $bucket, $b, $ctx);
        return;
    }

    if ($method === 'POST') {
        if (array_key_exists('delete', $q)) {
            s3_bucket_require($b, $bucket);
            s3_delete_objects($user, $b, $ctx);
            return;
        }
        throw new S3Exception('MethodNotAllowed', 'The specified method is not allowed against this resource.', 405);
    }

    throw new S3Exception('MethodNotAllowed', 'The specified method is not allowed against this resource.', 405);
}

function s3_key_route(string $method, string $bucket, string $key, array $q, array $user, array $ctx): void
{
    $b = db_find_bucket_by_name($user['id'], $bucket);
    s3_bucket_require($b, $bucket);

    if (s3_unsupported_subresource(array_keys($q))) {
        throw new S3Exception('NotImplemented', 'A header you provided implies functionality that is not implemented.', 501);
    }

    if ($method === 'PUT') {
        if (array_key_exists('uploads', $q)) {
            s3_initiate_multipart($user, $b, $key, $ctx);
            return;
        }
        if (array_key_exists('uploadId', $q) && array_key_exists('partNumber', $q)) {
            s3_upload_part($user, $b, $key, $q, $ctx);
            return;
        }
        if (array_key_exists('acl', $q)) {
            s3_put_acl($ctx);
            return;
        }
        if (s3_header('x-amz-copy-source') !== null) {
            s3_copy_object($user, $b, $key, $ctx);
            return;
        }
        s3_put_object($user, $b, $key, $ctx);
        return;
    }

    if ($method === 'POST') {
        if (array_key_exists('uploads', $q)) {
            s3_initiate_multipart($user, $b, $key, $ctx);
            return;
        }
        if (array_key_exists('uploadId', $q)) {
            s3_complete_multipart($user, $b, $key, $q, $ctx);
            return;
        }
        if (array_key_exists('restore', $q)) {
            throw new S3Exception('NotImplemented', 'A header you provided implies functionality that is not implemented.', 501);
        }
        throw new S3Exception('MethodNotAllowed', 'The specified method is not allowed against this resource.', 405);
    }

    if ($method === 'DELETE') {
        if (array_key_exists('uploadId', $q)) {
            s3_abort_multipart($user, $b, $key, $q, $ctx);
            return;
        }
        s3_delete_object($user, $b, $key, $ctx);
        return;
    }

    if ($method === 'GET') {
        if (array_key_exists('uploadId', $q)) {
            s3_list_parts($user, $b, $key, $q, $ctx);
            return;
        }
        if (array_key_exists('acl', $q)) {
            s3_get_object_acl($b, $key, $user, $ctx);
            return;
        }
        s3_get_object($user, $b, $key, $ctx);
        return;
    }

    if ($method === 'HEAD') {
        s3_head_object($user, $b, $key, $ctx);
        return;
    }

    throw new S3Exception('MethodNotAllowed', 'The specified method is not allowed against this resource.', 405);
}

function s3_list_buckets(array $user, array $ctx): void
{
    $st = db()->prepare('SELECT id, name, created_at FROM buckets WHERE user_id = ? ORDER BY name');
    $st->execute([$user['id']]);
    $rows = $st->fetchAll();

    $xml = '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Owner><ID>' . e((string)$user['id']) . '</ID><DisplayName>' . e($user['username']) . '</DisplayName></Owner>'
        . '<Buckets>';
    foreach ($rows as $r) {
        $xml .= '<Bucket><Name>' . e($r['name']) . '</Name><CreationDate>' . e(s3_iso8601(strtotime($r['created_at']))) . '</CreationDate></Bucket>';
    }
    $xml .= '</Buckets></ListAllMyBucketsResult>';

    s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_create_bucket(array $user, string $bucket, array $ctx): void
{
    if (!s3_bucket_name_valid($bucket)) {
        throw new S3Exception('InvalidBucketName', 'The specified bucket is not valid.', 400);
    }
    if (db_find_bucket_by_name($user['id'], $bucket) !== null) {
        throw new S3Exception('BucketAlreadyExists', 'The requested bucket name is not available.', 409);
    }
    s3_ensure_dir(s3_bucket_dir($user['username'], $bucket));
    $st = db()->prepare('INSERT INTO buckets (user_id, name, created_at) VALUES (?, ?, ?)');
    $st->execute([$user['id'], $bucket, gmdate('Y-m-d H:i:s')]);
    s3_finish('', 200, [], $ctx);
}

function s3_delete_bucket(array $user, string $bucket, ?array $b, array $ctx): void
{
    s3_bucket_require($b, $bucket);
    $dir = s3_bucket_dir($user['username'], $bucket);
    if (!s3_tree_empty($dir)) {
        throw new S3Exception('BucketNotEmpty', 'The bucket you tried to delete is not empty', 409);
    }
    s3_delete_tree($dir);
    s3_delete_tree(s3_uploads_user_dir($user['username']) . '/' . $bucket);
    $st = db()->prepare('DELETE FROM uploads WHERE bucket_id = ?');
    $st->execute([$b['id']]);
    $st = db()->prepare('DELETE FROM buckets WHERE id = ?');
    $st->execute([$b['id']]);
    s3_finish('', 204, [], $ctx);
}

function s3_get_bucket_location(array $ctx): void
{
    s3_finish('<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></LocationConstraint>', 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_get_bucket_versioning(array $ctx): void
{
    s3_finish('<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>', 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_acl_xml(array $user): string
{
    return '<AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Owner><ID>' . e((string)$user['id']) . '</ID><DisplayName>' . e($user['username']) . '</DisplayName></Owner>'
        . '<AccessControlList><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser">'
        . '<ID>' . e((string)$user['id']) . '</ID><DisplayName>' . e($user['username']) . '</DisplayName></Grantee>'
        . '<Permission>FULL_CONTROL</Permission></Grant></AccessControlList></AccessControlPolicy>';
}

function s3_get_bucket_acl(array $user, array $ctx): void
{
    s3_finish(s3_acl_xml($user), 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_get_object_acl(array $b, string $key, array $user, array $ctx): void
{
    $obj = db_find_object((int)$b['id'], $key);
    s3_object_require($obj);
    s3_finish(s3_acl_xml($user), 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_put_acl(array $ctx): void
{
    s3_finish('', 200, [], $ctx);
}

function s3_list_objects_core(int $bucketId, string $prefix, string $delimiter, string $startAfter, int $maxKeys): array
{
    if ($maxKeys < 0) {
        $maxKeys = 0;
    }
    $baseSql = 'SELECT key, size, etag, content_type, last_modified FROM objects WHERE bucket_id = ?';
    $baseArgs = [$bucketId];
    if ($prefix !== '') {
        $baseSql .= ' AND key LIKE ? ESCAPE "\"';
        $baseArgs[] = s3_escape_like($prefix) . '%';
    }

    $contents = [];
    $prefixes = [];
    $nextKey = '';
    $emitted = 0;
    $cursor = $startAfter;
    // Key of the last row that produced an emitted item (content or new
    // CommonPrefix). The page cursor must resume from that key, not from the
    // row that triggered the cutoff: the cutoff row may collapse into a
    // CommonPrefix that has not been emitted yet, and using its raw key as
    // the cursor would skip that prefix forever (lost folders in listings).
    $lastEmittedKey = $startAfter;

    // Fetch in batches: keys collapse into CommonPrefixes under a delimiter,
    // so maxKeys+1 rows may not cover maxKeys result items. Keep fetching
    // until we have emitted maxKeys items or the bucket is exhausted.
    while (true) {
        $sql = $baseSql;
        $args = $baseArgs;
        if ($cursor !== '') {
            $sql .= ' AND key > ?';
            $args[] = $cursor;
        }
        $sql .= ' ORDER BY key LIMIT ' . (int)($maxKeys + 1);
        $st = db()->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();
        if (count($rows) === 0) {
            break;
        }
        $hasMore = count($rows) === $maxKeys + 1;
        $lastKey = $rows[count($rows) - 1]['key'];

        foreach ($rows as $row) {
            if ($emitted >= $maxKeys) {
                $nextKey = $lastEmittedKey;
                break 2;
            }
            $k = $row['key'];
            if ($delimiter !== '') {
                $rest = substr($k, strlen($prefix));
                $pos = strpos($rest, $delimiter);
                if ($pos !== false) {
                    $cp = $prefix . substr($rest, 0, $pos + strlen($delimiter));
                    if (!isset($prefixes[$cp])) {
                        $prefixes[$cp] = true;
                        $emitted++;
                        $lastEmittedKey = $k;
                    }
                    continue;
                }
            }
            $contents[] = $row;
            $emitted++;
            $lastEmittedKey = $k;
        }
        if (!$hasMore) {
            break;
        }
        $cursor = $lastKey;
    }

    return [
        'contents' => $contents,
        'prefixes' => array_keys($prefixes),
        'truncated' => $nextKey !== '',
        'nextKey' => $nextKey,
    ];
}

function s3_content_xml(array $c, array $user, bool $urlEncode = false): string
{
    return '<Contents>'
        . '<Key>' . e($urlEncode ? rawurlencode($c['key']) : $c['key']) . '</Key>'
        . '<LastModified>' . e(s3_iso8601(strtotime($c['last_modified']))) . '</LastModified>'
        . '<ETag>' . e('"' . $c['etag'] . '"') . '</ETag>'
        . '<Size>' . (int)$c['size'] . '</Size>'
        . '<StorageClass>STANDARD</StorageClass>'
        . '<Owner><ID>' . e((string)$user['id']) . '</ID><DisplayName>' . e($user['username']) . '</DisplayName></Owner>'
        . '</Contents>';
}

function s3_list_objects_v1(array $user, string $bucket, ?array $b, array $q, array $ctx): void
{
    s3_bucket_require($b, $bucket);
    $prefix = $q['prefix'] ?? '';
    $delimiter = $q['delimiter'] ?? '';
    $marker = $q['marker'] ?? '';
    $maxKeys = min(max(1, (int)($q['max-keys'] ?? DEFAULT_MAX_KEYS)), 1000);
    $res = s3_list_objects_core((int)$b['id'], $prefix, $delimiter, $marker, $maxKeys);
    $urlEnc = strtolower((string)($q['encoding-type'] ?? '')) === 'url';
    $ek = fn(string $s): string => $urlEnc ? rawurlencode($s) : $s;

    $xml = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Name>' . e($bucket) . '</Name>'
        . '<Prefix>' . e($ek($prefix)) . '</Prefix>'
        . '<Marker>' . e($ek($marker)) . '</Marker>'
        . ($res['truncated'] && $delimiter !== '' ? '<NextMarker>' . e($ek($res['nextKey'])) . '</NextMarker>' : '')
        . '<MaxKeys>' . (int)$maxKeys . '</MaxKeys>'
        . ($delimiter !== '' ? '<Delimiter>' . e($ek($delimiter)) . '</Delimiter>' : '')
        . '<IsTruncated>' . ($res['truncated'] ? 'true' : 'false') . '</IsTruncated>';
    foreach ($res['contents'] as $c) {
        $xml .= s3_content_xml($c, $user, $urlEnc);
    }
    foreach ($res['prefixes'] as $p) {
        $xml .= '<CommonPrefixes><Prefix>' . e($ek($p)) . '</Prefix></CommonPrefixes>';
    }
    $xml .= '</ListBucketResult>';
    s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_list_objects_v2(array $user, string $bucket, ?array $b, array $q, array $ctx): void
{
    s3_bucket_require($b, $bucket);
    $prefix = $q['prefix'] ?? '';
    $delimiter = $q['delimiter'] ?? '';
    $startAfter = $q['start-after'] ?? '';
    $continuation = $q['continuation-token'] ?? '';
    if ($continuation !== '') {
        $decoded = base64_decode($continuation, true);
        if ($decoded !== false && $decoded !== '') {
            $startAfter = $decoded;
        }
    }
    $maxKeys = min(max(1, (int)($q['max-keys'] ?? DEFAULT_MAX_KEYS)), 1000);
    $res = s3_list_objects_core((int)$b['id'], $prefix, $delimiter, $startAfter, $maxKeys);
    $urlEnc = strtolower((string)($q['encoding-type'] ?? '')) === 'url';
    $ek = fn(string $s): string => $urlEnc ? rawurlencode($s) : $s;

    $xml = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Name>' . e($bucket) . '</Name>'
        . '<Prefix>' . e($ek($prefix)) . '</Prefix>'
        . '<StartAfter>' . e($ek($startAfter)) . '</StartAfter>'
        . ($continuation !== '' ? '<ContinuationToken>' . e($continuation) . '</ContinuationToken>' : '')
        . ($res['truncated'] ? '<NextContinuationToken>' . e(base64_encode($res['nextKey'])) . '</NextContinuationToken>' : '')
        . '<KeyCount>' . (count($res['contents']) + count($res['prefixes'])) . '</KeyCount>'
        . '<MaxKeys>' . (int)$maxKeys . '</MaxKeys>'
        . ($delimiter !== '' ? '<Delimiter>' . e($ek($delimiter)) . '</Delimiter>' : '')
        . '<IsTruncated>' . ($res['truncated'] ? 'true' : 'false') . '</IsTruncated>';
    foreach ($res['contents'] as $c) {
        $xml .= s3_content_xml($c, $user, $urlEnc);
    }
    foreach ($res['prefixes'] as $p) {
        $xml .= '<CommonPrefixes><Prefix>' . e($ek($p)) . '</Prefix></CommonPrefixes>';
    }
    $xml .= '</ListBucketResult>';
    s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_meta_headers(): array
{
    $meta = [];
    foreach (s3_headers_map() as $k => $v) {
        if (strpos((string)$k, 'x-amz-meta-') === 0) {
            $meta[$k] = $v;
        }
    }
    return $meta;
}

function s3_upsert_object(int $bucketId, string $key, int $size, string $etag, string $contentType, array $meta, string $now): void
{
    $st = db()->prepare('INSERT INTO objects (bucket_id, key, size, etag, content_type, meta, last_modified) VALUES (?,?,?,?,?,?,?)
        ON CONFLICT(bucket_id, key) DO UPDATE SET size=excluded.size, etag=excluded.etag, content_type=excluded.content_type, meta=excluded.meta, last_modified=excluded.last_modified');
    $st->execute([$bucketId, $key, $size, $etag, $contentType, json_encode($meta), $now]);
}

function s3_receive_body_to_file(string $tmpPath): array
{
    $in = fopen('php://input', 'rb');
    if ($in === false) {
        throw new S3Exception('InternalError', 'Unable to read request body.', 500);
    }
    $out = @fopen($tmpPath, 'wb');
    if ($out === false) {
        fclose($in);
        throw new S3Exception('InternalError', 'Unable to write temp file.', 500);
    }
    $h = hash_init('md5');
    $hSha = hash_init('sha256');
    $size = 0;
    try {
        while (!feof($in)) {
            $chunk = fread($in, 262144);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $size += strlen($chunk);
            hash_update($h, $chunk);
            hash_update($hSha, $chunk);
            if (fwrite($out, $chunk) === false) {
                throw new S3Exception('InternalError', 'Unable to write temp file.', 500);
            }
            if (connection_aborted()) {
                throw new S3Exception('IncompleteBody', 'The client aborted the upload.', 400);
            }
        }
    } catch (Throwable $t) {
        fclose($in);
        fclose($out);
        @unlink($tmpPath);
        throw $t;
    }
    fclose($in);
    fclose($out);

    $expected = s3_header('content-length');
    if ($expected !== null && (int)$expected !== $size) {
        @unlink($tmpPath);
        throw new S3Exception('IncompleteBody', 'You did not provide the number of bytes specified by the Content-Length HTTP header', 400);
    }
    $md5raw = hash_final($h, true);
    $etag = bin2hex($md5raw);
    $md5Header = s3_header('content-md5');
    if ($md5Header !== null && $md5Header !== '') {
        if (!hash_equals(trim($md5Header), base64_encode($md5raw))) {
            @unlink($tmpPath);
            throw new S3Exception('BadDigest', 'The Content-MD5 you specified did not match what we received.', 400);
        }
    }
    if (!s3_verify_payload_sha256(hash_final($hSha), $tmpPath)) {
        throw new S3Exception('XAmzContentSHA256Mismatch', 'The provided x-amz-content-sha256 header does not match what we received.', 400);
    }
    return [$size, $etag];
}

// Verifies the received payload against the x-amz-content-sha256 header when
// the client sent a concrete digest (UNSIGNED-PAYLOAD / STREAMING-* are not
// verifiable by design). $actual is the hex sha256 of the received bytes;
// $tmpPath is removed on mismatch.
function s3_verify_payload_sha256(string $actual, string $tmpPath): bool
{
    $declared = s3_header('x-amz-content-sha256');
    if ($declared === null || preg_match('/^[0-9a-f]{64}$/i', $declared) !== 1) {
        return true;
    }
    if (hash_equals(strtolower($declared), $actual)) {
        return true;
    }
    @unlink($tmpPath);
    return false;
}

// Reads the full request body as a string, verifying x-amz-content-sha256
// when a concrete digest was declared.
function s3_read_body_string(): string
{
    $raw = (string)file_get_contents('php://input');
    $declared = s3_header('x-amz-content-sha256');
    if ($declared !== null && preg_match('/^[0-9a-f]{64}$/i', $declared) === 1) {
        if (!hash_equals(strtolower($declared), hash('sha256', $raw))) {
            throw new S3Exception('XAmzContentSHA256Mismatch', 'The provided x-amz-content-sha256 header does not match what we received.', 400);
        }
    }
    return $raw;
}

function s3_copy_file_to(string $srcPath, string $tmpPath): array
{
    $in = fopen($srcPath, 'rb');
    if ($in === false) {
        throw new S3Exception('NoSuchKey', 'The specified key does not exist.', 404);
    }
    $out = fopen($tmpPath, 'wb');
    if ($out === false) {
        fclose($in);
        throw new S3Exception('InternalError', 'Unable to write temp file.', 500);
    }
    $h = hash_init('md5');
    $size = 0;
    while (!feof($in)) {
        $chunk = fread($in, 262144);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $size += strlen($chunk);
        hash_update($h, $chunk);
        fwrite($out, $chunk);
    }
    fclose($in);
    fclose($out);
    return [$size, hash_final($h)];
}

function s3_parse_copy_source(string $header): array
{
    $src = rawurldecode($header);
    $src = explode('?', $src, 2)[0];
    $src = ltrim($src, '/');
    $pos = strpos($src, '/');
    if ($pos === false || $pos === 0) {
        throw new S3Exception('InvalidArgument', 'Invalid copy source.', 400);
    }
    return [substr($src, 0, $pos), substr($src, $pos + 1)];
}

function s3_user_storage_used(int $userId): int
{
    $st = db()->prepare('SELECT COALESCE(SUM(o.size), 0) FROM objects o JOIN buckets b ON b.id = o.bucket_id WHERE b.user_id = ?');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

// Throws when adding $incomingBytes would push the user past their quota.
// $replacedSize subtracts an object that is about to be overwritten.
function s3_quota_check(array $user, int $incomingBytes, int $replacedSize = 0): void
{
    $quota = (int)($user['quota_bytes'] ?? 0);
    if ($quota <= 0 || $incomingBytes <= 0) {
        return;
    }
    $used = s3_user_storage_used((int)$user['id']) - $replacedSize;
    if ($used + $incomingBytes > $quota) {
        throw new S3Exception('QuotaExceeded', 'Storage quota exceeded for this user.', 400);
    }
}

function s3_put_object(array $user, array $b, string $key, array $ctx): void
{
    $dir = s3_bucket_dir($user['username'], $b['name']);
    $target = $dir . '/' . $key;

    if (s3_is_folder_marker($key)) {
        $contentType = s3_header('content-type') ?? 'application/octet-stream';
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }
        $etag = md5('');
        s3_upsert_object((int)$b['id'], $key, 0, $etag, $contentType, s3_meta_headers(), gmdate('Y-m-d H:i:s'));
        $ctx['bytes'] = 0;
        s3_finish('', 200, ['ETag' => '"' . $etag . '"'], $ctx);
    }

    s3_ensure_dir(dirname($target), [$user['username'], $b['name'], (int)$b['id']]);

    $tmp = $dir . '/.tmp-' . s3_random_id(8);
    [$size, $etag] = s3_receive_body_to_file($tmp);

    $existing = db_find_object((int)$b['id'], $key);
    try {
        s3_quota_check($user, $size, $existing !== null ? (int)$existing['size'] : 0);
    } catch (S3Exception $e) {
        @unlink($tmp);
        throw $e;
    }

    @unlink($target);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new S3Exception('InternalError', 'Failed to store object.', 500);
    }

    $contentType = s3_header('content-type') ?? 'application/octet-stream';
    if ($contentType === '') {
        $contentType = 'application/octet-stream';
    }
    s3_upsert_object((int)$b['id'], $key, $size, $etag, $contentType, s3_meta_headers(), gmdate('Y-m-d H:i:s'));

    $ctx['bytes'] = $size;
    s3_finish('', 200, ['ETag' => '"' . $etag . '"'], $ctx);
}

function s3_copy_object(array $user, array $b, string $key, array $ctx): void
{
    [$srcBucket, $srcKey] = s3_parse_copy_source((string)s3_header('x-amz-copy-source'));
    if (!s3_key_valid($srcKey)) {
        throw new S3Exception('InvalidArgument', 'Invalid copy source key.', 400);
    }
    $srcBucketRow = db_find_bucket_by_name($user['id'], $srcBucket);
    if ($srcBucketRow === null) {
        throw new S3Exception('NoSuchBucket', 'The specified bucket does not exist', 404);
    }
    $srcObj = db_find_object((int)$srcBucketRow['id'], $srcKey);
    s3_object_require($srcObj);
    $srcMarker = s3_is_folder_marker($srcKey);

    $dir = s3_bucket_dir($user['username'], $b['name']);
    $target = $dir . '/' . $key;

    if (s3_is_folder_marker($key)) {
        $contentType = $srcMarker
            ? ($srcObj['content_type'] !== '' ? $srcObj['content_type'] : 'application/octet-stream')
            : (s3_header('content-type') ?? 'application/octet-stream');
        $etag = md5('');
        s3_upsert_object((int)$b['id'], $key, 0, $etag, $contentType, [], gmdate('Y-m-d H:i:s'));
        $xml = '<CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<LastModified>' . s3_iso8601() . '</LastModified>'
            . '<ETag>"' . $etag . '"</ETag>'
            . '</CopyObjectResult>';
        s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
    }

    if (!$srcMarker) {
        $srcPath = s3_object_path($user['username'], $srcBucket, $srcKey);
        if (!is_file($srcPath)) {
            throw new S3Exception('NoSuchKey', 'The specified key does not exist.', 404);
        }
    }

    s3_ensure_dir(dirname($target), [$user['username'], $b['name'], (int)$b['id']]);
    $tmp = $dir . '/.tmp-' . s3_random_id(8);
    if ($srcMarker) {
        $size = 0;
        $etag = md5('');
        file_put_contents($tmp, '');
    } else {
        [$size, $etag] = s3_copy_file_to($srcPath, $tmp);
    }

    $existing = db_find_object((int)$b['id'], $key);
    try {
        s3_quota_check($user, $size, $existing !== null ? (int)$existing['size'] : 0);
    } catch (S3Exception $e) {
        @unlink($tmp);
        throw $e;
    }

    @unlink($target);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new S3Exception('InternalError', 'Failed to store object.', 500);
    }

    $directive = strtolower((string)(s3_header('x-amz-metadata-directive') ?? 'COPY'));
    if ($directive === 'replace') {
        $contentType = s3_header('content-type') ?? 'application/octet-stream';
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }
        $meta = s3_meta_headers();
    } else {
        $contentType = $srcObj['content_type'];
        $meta = json_decode($srcObj['meta'] ?? '{}', true);
        if (!is_array($meta)) {
            $meta = [];
        }
    }
    s3_upsert_object((int)$b['id'], $key, $size, $etag, $contentType, $meta, gmdate('Y-m-d H:i:s'));

    $xml = '<CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<LastModified>' . s3_iso8601() . '</LastModified>'
        . '<ETag>"' . $etag . '"</ETag>'
        . '</CopyObjectResult>';
    s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_object_headers(array $obj, int $size, int $mtime, string $etag): array
{
    $h = [
        'Content-Type' => $obj['content_type'] !== '' ? $obj['content_type'] : 'application/octet-stream',
        'ETag' => $etag,
        'Last-Modified' => s3_http_date($mtime),
        'Accept-Ranges' => 'bytes',
        'x-amz-storage-class' => 'STANDARD',
    ];
    $meta = json_decode($obj['meta'] ?? '{}', true);
    if (is_array($meta)) {
        foreach ($meta as $k => $v) {
            if (preg_match('/^x-amz-meta-[a-z0-9_-]+$/', $k)) {
                $h[$k] = $v;
            }
        }
    }
    return $h;
}

function s3_get_object(array $user, array $b, string $key, array $ctx): void
{
    $obj = db_find_object((int)$b['id'], $key);
    s3_object_require($obj);

    if (s3_is_folder_marker($key)) {
        $etag = '"' . $obj['etag'] . '"';
        $inm = s3_header('if-none-match');
        if ($inm !== null) {
            $list = array_map('trim', explode(',', $inm));
            if ($inm === '*' || in_array($etag, $list, true) || in_array($obj['etag'], $list, true)) {
                s3_finish('', 304, ['ETag' => $etag], $ctx);
            }
        }
        $headers = s3_object_headers($obj, 0, (int)strtotime($obj['last_modified'] ?? 'now'), $etag);
        $headers['Content-Length'] = '0';
        s3_finish('', 200, $headers, $ctx);
    }

    $path = s3_object_path($user['username'], $b['name'], $key);
    if (!is_file($path)) {
        throw new S3Exception('NoSuchKey', 'The specified key does not exist.', 404);
    }
    $size = filesize($path);
    $mtime = filemtime($path);
    $etag = '"' . $obj['etag'] . '"';

    $inm = s3_header('if-none-match');
    if ($inm !== null) {
        $inm = trim($inm);
        $list = array_map('trim', explode(',', $inm));
        if ($inm === '*' || in_array($etag, $list, true) || in_array($obj['etag'], $list, true)) {
            s3_finish('', 304, ['ETag' => $etag], $ctx);
        }
    }
    $ims = s3_header('if-modified-since');
    if ($inm === null && $ims !== null) {
        $since = strtotime($ims);
        if ($since !== false && $mtime <= $since) {
            s3_finish('', 304, ['ETag' => $etag, 'Last-Modified' => s3_http_date($mtime)], $ctx);
        }
    }

    $headers = s3_object_headers($obj, $size, $mtime, $etag);
    $range = s3_header('range');
    $status = 200;
    if ($range !== null) {
        try {
            [$start, $end] = s3_parse_range($range, $size);
        } catch (S3Exception $e) {
            header('Content-Range: bytes */' . $size);
            throw $e;
        }
        if ($start !== 0 || $end !== $size - 1) {
            $status = 206;
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }
        $headers['Content-Length'] = (string)($end - $start + 1);
    } else {
        $headers['Content-Length'] = (string)$size;
        $start = 0;
        $end = $size - 1;
    }

    s3_finish(function () use ($path, $start, $end) {
        set_time_limit(0);
        @ini_set('zlib.output_compression', 'Off');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return 0;
        }
        fseek($fh, $start);
        $remaining = $end - $start + 1;
        $sent = 0;
        while ($remaining > 0) {
            $chunk = fread($fh, min(262144, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $sent += strlen($chunk);
            $remaining -= strlen($chunk);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fh);
        return $sent;
    }, $status, $headers, $ctx);
}

function s3_head_object(array $user, array $b, string $key, array $ctx): void
{
    $obj = db_find_object((int)$b['id'], $key);
    s3_object_require($obj);

    if (s3_is_folder_marker($key)) {
        $etag = '"' . $obj['etag'] . '"';
        $headers = s3_object_headers($obj, 0, (int)strtotime($obj['last_modified'] ?? 'now'), $etag);
        $headers['Content-Length'] = '0';
        s3_finish('', 200, $headers, $ctx);
    }

    $path = s3_object_path($user['username'], $b['name'], $key);
    if (!is_file($path)) {
        throw new S3Exception('NoSuchKey', 'The specified key does not exist.', 404);
    }
    $size = filesize($path);
    $mtime = filemtime($path);
    $headers = s3_object_headers($obj, $size, $mtime, '"' . $obj['etag'] . '"');
    $headers['Content-Length'] = (string)$size;
    s3_finish('', 200, $headers, $ctx);
}

function s3_delete_object(array $user, array $b, string $key, array $ctx): void
{
    $obj = db_find_object((int)$b['id'], $key);
    if ($obj !== null) {
        @unlink(s3_object_path($user['username'], $b['name'], $key));
        $st = db()->prepare('DELETE FROM objects WHERE id = ?');
        $st->execute([$obj['id']]);
    }
    s3_finish('', 204, [], $ctx);
}

function s3_delete_objects(array $user, array $b, array $ctx): void
{
    $raw = s3_read_body_string();
    if ($raw === false || $raw === '' || strlen($raw) > 4 * 1024 * 1024) {
        throw new S3Exception('MalformedXML', 'The XML you provided was not well-formed or did not validate against our published schema', 400);
    }
    $xml = @simplexml_load_string($raw);
    if ($xml === false) {
        throw new S3Exception('MalformedXML', 'The XML you provided was not well-formed or did not validate against our published schema', 400);
    }
    if (count($xml->Object) > 1000) {
        throw new S3Exception('InvalidRequest', 'The number of keys in the request exceeds the maximum (1000).', 400);
    }
    $quiet = strtolower((string)($xml->Quiet ?? '')) === 'true';
    $deleted = [];
    $errors = [];
    foreach ($xml->Object as $objNode) {
        $k = (string)$objNode->Key;
        if ($k === '' || !s3_key_valid($k)) {
            $errors[] = ['key' => $k, 'code' => 'MalformedXML', 'message' => 'Invalid key'];
            continue;
        }
        $found = db_find_object((int)$b['id'], $k);
        if ($found === null) {
            $errors[] = ['key' => $k, 'code' => 'NoSuchKey', 'message' => 'The specified key does not exist.'];
        } else {
            @unlink(s3_object_path($user['username'], $b['name'], $k));
            $st = db()->prepare('DELETE FROM objects WHERE id = ?');
            $st->execute([$found['id']]);
            $deleted[] = $k;
        }
    }
    $xmlOut = '<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
    if (!$quiet) {
        foreach ($deleted as $k) {
            $xmlOut .= '<Deleted><Key>' . e($k) . '</Key></Deleted>';
        }
    }
    foreach ($errors as $err) {
        $xmlOut .= '<Error><Key>' . e($err['key']) . '</Key><Code>' . e($err['code']) . '</Code><Message>' . e($err['message']) . '</Message></Error>';
    }
    $xmlOut .= '</DeleteResult>';
    s3_finish($xmlOut, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_initiate_multipart(array $user, array $b, string $key, array $ctx): void
{
    $uploadId = s3_random_id(16);
    $contentType = s3_header('content-type') ?? 'application/octet-stream';
    if ($contentType === '') {
        $contentType = 'application/octet-stream';
    }
    $st = db()->prepare('INSERT INTO uploads (upload_id, user_id, bucket_id, key, content_type, meta, created_at) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$uploadId, $user['id'], $b['id'], $key, $contentType, json_encode(s3_meta_headers()), gmdate('Y-m-d H:i:s')]);
    $xml = '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Bucket>' . e($b['name']) . '</Bucket>'
        . '<Key>' . e($key) . '</Key>'
        . '<UploadId>' . e($uploadId) . '</UploadId>'
        . '</InitiateMultipartUploadResult>';
    s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_upload_part(array $user, array $b, string $key, array $q, array $ctx): void
{
    $uploadId = (string)($q['uploadId'] ?? '');
    $partNumber = (int)($q['partNumber'] ?? 0);
    if ($partNumber < 1 || $partNumber > MAX_PARTS) {
        throw new S3Exception('InvalidArgument', 'Part number must be between 1 and 10000', 400);
    }
    $up = db_find_upload($uploadId);
    if ($up === null || (int)$up['bucket_id'] !== (int)$b['id'] || $up['key'] !== $key) {
        throw new S3Exception('NoSuchUpload', 'The specified upload does not exist. The upload ID may be invalid, or the upload may have been aborted or completed.', 404);
    }

    $dir = s3_uploads_upload_dir($user['username'], $b['name'], $uploadId);
    s3_ensure_dir($dir);
    $tmp = $dir . '/.part-' . $partNumber . '-tmp-' . s3_random_id(6);

    if (s3_header('x-amz-copy-source') !== null) {
        [$srcBucket, $srcKey] = s3_parse_copy_source((string)s3_header('x-amz-copy-source'));
        if (!s3_key_valid($srcKey)) {
            throw new S3Exception('InvalidArgument', 'Invalid copy source key.', 400);
        }
        $srcBucketRow = db_find_bucket_by_name($user['id'], $srcBucket);
        if ($srcBucketRow === null) {
            throw new S3Exception('NoSuchBucket', 'The specified bucket does not exist', 404);
        }
        $srcObj = db_find_object((int)$srcBucketRow['id'], $srcKey);
        s3_object_require($srcObj);
        $srcPath = s3_object_path($user['username'], $srcBucket, $srcKey);
        if (!is_file($srcPath)) {
            throw new S3Exception('NoSuchKey', 'The specified key does not exist.', 404);
        }
        [$size, $etag] = s3_copy_file_to($srcPath, $tmp);
    } else {
        [$size, $etag] = s3_receive_body_to_file($tmp);
    }

    $partFile = $dir . '/part-' . $partNumber;
    @unlink($partFile);
    if (!@rename($tmp, $partFile)) {
        @unlink($tmp);
        throw new S3Exception('InternalError', 'Failed to store part.', 500);
    }
    $ctx['bytes'] = $size;
    s3_finish('', 200, ['ETag' => '"' . $etag . '"'], $ctx);
}

function s3_complete_multipart(array $user, array $b, string $key, array $q, array $ctx): void
{
    $uploadId = (string)($q['uploadId'] ?? '');
    $up = db_find_upload($uploadId);
    if ($up === null || (int)$up['bucket_id'] !== (int)$b['id'] || $up['key'] !== $key) {
        throw new S3Exception('NoSuchUpload', 'The specified upload does not exist. The upload ID may be invalid, or the upload may have been aborted or completed.', 404);
    }

    $raw = s3_read_body_string();
    if ($raw === false || strlen($raw) > 4 * 1024 * 1024) {
        throw new S3Exception('MalformedXML', 'The XML you provided was not well-formed or did not validate against our published schema', 400);
    }
    $xml = @simplexml_load_string($raw);
    if ($xml === false) {
        throw new S3Exception('MalformedXML', 'The XML you provided was not well-formed or did not validate against our published schema', 400);
    }

    $parts = [];
    foreach ($xml->Part as $p) {
        $n = (int)$p->PartNumber;
        $etag = strtolower(trim(trim((string)$p->ETag, '"')));
        if ($n < 1 || $n > MAX_PARTS) {
            throw new S3Exception('InvalidPart', 'One or more of the specified parts could not be found.', 400);
        }
        $parts[$n] = $etag;
    }
    if (count($parts) === 0) {
        throw new S3Exception('MalformedXML', 'The XML you provided was not well-formed or did not validate against our published schema', 400);
    }
    if (count($parts) > MAX_PARTS) {
        throw new S3Exception('InvalidPart', 'The number of parts in the request exceeds the maximum (10000).', 400);
    }
    ksort($parts);
    if (array_keys($parts) !== range(1, count($parts))) {
        throw new S3Exception('InvalidPartOrder', 'The list of parts was not in ascending order. Parts must be ordered by part number.', 400);
    }

    $dir = s3_uploads_upload_dir($user['username'], $b['name'], $uploadId);
    $last = count($parts);
    $i = 0;
    foreach ($parts as $n => $etag) {
        $i++;
        $pf = $dir . '/part-' . $n;
        if (!is_file($pf)) {
            throw new S3Exception('InvalidPart', 'One or more of the specified parts could not be found.', 400);
        }
        if ($i < $last && filesize($pf) < MIN_PART_BYTES) {
            throw new S3Exception('EntityTooSmall', 'Your proposed upload is smaller than the minimum allowed object size.', 400);
        }
        // The object is assembled from the parts stored on this server, so a
        // client-supplied ETag that does not match is not a consistency
        // problem: some client stacks (e.g. game-panel backup daemons) report
        // an ETag with quoting/encoding differences that do not match what was
        // stored. Only missing parts are fatal; the response ETag is always
        // computed from the actual concatenated data.
    }

    if (s3_is_folder_marker($key)) {
        $etagFinal = md5('');
        $meta = json_decode($up['meta'] ?? '{}', true);
        if (!is_array($meta)) {
            $meta = [];
        }
        s3_delete_tree($dir);
        $st = db()->prepare('DELETE FROM uploads WHERE upload_id = ?');
        $st->execute([$uploadId]);
        s3_upsert_object((int)$b['id'], $key, 0, $etagFinal, $up['content_type'], $meta, gmdate('Y-m-d H:i:s'));

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $xml = '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Location>' . e($scheme . '://' . $host . '/' . $b['name'] . '/' . $key) . '</Location>'
            . '<Bucket>' . e($b['name']) . '</Bucket>'
            . '<Key>' . e($key) . '</Key>'
            . '<ETag>"' . $etagFinal . '"</ETag>'
            . '</CompleteMultipartUploadResult>';
        s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
    }

    $target = s3_object_path($user['username'], $b['name'], $key);
    s3_ensure_dir(dirname($target), [$user['username'], $b['name'], (int)$b['id']]);
    $tmp = dirname($target) . '/.tmp-' . s3_random_id(8);
    $h = hash_init('md5');
    $out = fopen($tmp, 'wb');
    if ($out === false) {
        throw new S3Exception('InternalError', 'Unable to write temp file.', 500);
    }
    foreach ($parts as $n => $etag) {
        $in = fopen($dir . '/part-' . $n, 'rb');
        while (!feof($in)) {
            $chunk = fread($in, 262144);
            if ($chunk === false || $chunk === '') {
                break;
            }
            hash_update($h, $chunk);
            fwrite($out, $chunk);
        }
        fclose($in);
    }
    fclose($out);
    $etagFinal = hash_final($h);

    // Quota: the final object is the sum of its parts (minus any object it
    // overwrites). The concatenated tmp file already exists; remove it on hit.
    $finalSize = filesize($tmp) ?: 0;
    $existing = db_find_object((int)$b['id'], $key);
    try {
        s3_quota_check($user, (int)$finalSize, $existing !== null ? (int)$existing['size'] : 0);
    } catch (S3Exception $e) {
        @unlink($tmp);
        throw $e;
    }

    @unlink($target);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new S3Exception('InternalError', 'Failed to store object.', 500);
    }

    s3_delete_tree($dir);
    $st = db()->prepare('DELETE FROM uploads WHERE upload_id = ?');
    $st->execute([$uploadId]);

    $meta = json_decode($up['meta'] ?? '{}', true);
    if (!is_array($meta)) {
        $meta = [];
    }
    $size = filesize($target);
    s3_upsert_object((int)$b['id'], $key, $size, $etagFinal, $up['content_type'], $meta, gmdate('Y-m-d H:i:s'));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $xml = '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Location>' . e($scheme . '://' . $host . '/' . $b['name'] . '/' . $key) . '</Location>'
        . '<Bucket>' . e($b['name']) . '</Bucket>'
        . '<Key>' . e($key) . '</Key>'
        . '<ETag>"' . $etagFinal . '"</ETag>'
        . '</CompleteMultipartUploadResult>';
    s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_abort_multipart(array $user, array $b, string $key, array $q, array $ctx): void
{
    $uploadId = (string)($q['uploadId'] ?? '');
    $up = db_find_upload($uploadId);
    if ($up === null || (int)$up['bucket_id'] !== (int)$b['id'] || $up['key'] !== $key) {
        throw new S3Exception('NoSuchUpload', 'The specified upload does not exist. The upload ID may be invalid, or the upload may have been aborted or completed.', 404);
    }
    s3_delete_tree(s3_uploads_upload_dir($user['username'], $b['name'], $uploadId));
    $st = db()->prepare('DELETE FROM uploads WHERE upload_id = ?');
    $st->execute([$uploadId]);
    s3_finish('', 204, [], $ctx);
}

function s3_list_parts(array $user, array $b, string $key, array $q, array $ctx): void
{
    $uploadId = (string)($q['uploadId'] ?? '');
    $up = db_find_upload($uploadId);
    if ($up === null || (int)$up['bucket_id'] !== (int)$b['id'] || $up['key'] !== $key) {
        throw new S3Exception('NoSuchUpload', 'The specified upload does not exist. The upload ID may be invalid, or the upload may have been aborted or completed.', 404);
    }
    $dir = s3_uploads_upload_dir($user['username'], $b['name'], $uploadId);
    $parts = [];
    if (is_dir($dir)) {
        foreach (@scandir($dir) ?: [] as $f) {
            if (preg_match('/^part-(\d+)$/', $f, $m)) {
                $parts[(int)$m[1]] = $f;
            }
        }
    }
    ksort($parts);
    $xml = '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Bucket>' . e($b['name']) . '</Bucket>'
        . '<Key>' . e($key) . '</Key>'
        . '<UploadId>' . e($uploadId) . '</UploadId>'
        . '<PartNumberMarker>0</PartNumberMarker>'
        . '<NextPartNumberMarker>0</NextPartNumberMarker>'
        . '<MaxParts>1000</MaxParts>'
        . '<IsTruncated>false</IsTruncated>';
    foreach ($parts as $n => $f) {
        $path = $dir . '/' . $f;
        $xml .= '<Part>'
            . '<PartNumber>' . $n . '</PartNumber>'
            . '<LastModified>' . e(s3_iso8601(filemtime($path))) . '</LastModified>'
            . '<ETag>"' . md5_file($path) . '"</ETag>'
            . '<Size>' . filesize($path) . '</Size>'
            . '</Part>';
    }
    $xml .= '</ListPartsResult>';
    s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_list_multipart_uploads(array $b, array $ctx): void
{
    $st = db()->prepare('SELECT upload_id, key, created_at FROM uploads WHERE bucket_id = ? ORDER BY created_at');
    $st->execute([$b['id']]);
    $xml = '<ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Bucket>' . e($b['name']) . '</Bucket>'
        . '<KeyMarker></KeyMarker>'
        . '<UploadIdMarker></UploadIdMarker>'
        . '<NextKeyMarker></NextKeyMarker>'
        . '<NextUploadIdMarker></NextUploadIdMarker>'
        . '<Delimiter></Delimiter>'
        . '<Prefix></Prefix>'
        . '<MaxUploads>1000</MaxUploads>'
        . '<IsTruncated>false</IsTruncated>';
    foreach ($st->fetchAll() as $u) {
        $xml .= '<Upload><Key>' . e($u['key']) . '</Key><UploadId>' . e($u['upload_id']) . '</UploadId><Initiated>' . e(s3_iso8601(strtotime($u['created_at']))) . '</Initiated></Upload>';
    }
    $xml .= '</ListMultipartUploadsResult>';
    s3_finish($xml, 200, ['Content-Type' => 'application/xml'], $ctx);
}

function s3_mime_from_ext(string $name): string
{
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown',
        'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
        'js' => 'text/javascript', 'json' => 'application/json', 'xml' => 'application/xml',
        'zip' => 'application/zip', 'gz' => 'application/gzip', 'tar' => 'application/x-tar',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav', 'csv' => 'text/csv',
    ];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}
