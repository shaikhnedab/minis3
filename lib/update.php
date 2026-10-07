<?php
// In-panel updater: staged, resumable updates from GitHub Releases.
//
// Design constraints (shared hosting, no shell):
// - PHP 7.4 compatible (no union types, match, nullsafe, str_contains).
// - Works with or without the ZipArchive extension (bundled fallback parser).
// - Never overwrites config.php or anything under data/, except the updater's
//   own DENIED working directory (data/.update).
// - Database migrations are additive only, via the existing db_init().
// - Every destructive step is preceded by a verified backup + rollback path.
//
// The lib functions take explicit roots/paths so they are unit-testable
// without sessions; admin/api.php provides the thin authenticated wrappers.

declare(strict_types=1);

function updater_repo(): string
{
    return 'shaikhnedab/minis3';
}

function updater_api_latest(string $apiBase = ''): string
{
    if ($apiBase === '') {
        $env = getenv('MINIS3_UPDATE_API');
        $apiBase = is_string($env) && $env !== '' ? $env : 'https://api.github.com';
    }
    return rtrim($apiBase, '/') . '/repos/' . updater_repo() . '/releases/latest';
}

function updater_work_dir(?string $dataDir = null): string
{
    if ($dataDir === null) {
        $dataDir = defined('DATA_DIR') ? DATA_DIR : sys_get_temp_dir() . '/minis3-update';
    }
    return rtrim($dataDir, '/') . '/.update';
}

function updater_state_file(?string $dataDir = null): string
{
    return updater_work_dir($dataDir) . '/state.json';
}

function updater_lock_file(?string $dataDir = null): string
{
    return updater_work_dir($dataDir) . '/lock';
}

// Files the updater must never write, even if a package lists them.
function updater_protected_paths(): array
{
    return ['config.php', 'data', 'data/'];
}

// Entry points that are only installed when already present locally, so the
// updater never resurrects a deliberately deleted installer/reset page.
function updater_no_restore_paths(): array
{
    return ['install.php', 'reset.php'];
}

function updater_is_protected(string $rel): bool
{
    $rel = str_replace('\\', '/', $rel);
    foreach (updater_protected_paths() as $p) {
        if ($rel === $p || strpos($rel, rtrim($p, '/') . '/') === 0) {
            return true;
        }
    }
    return false;
}

// Normalize a zip entry name to a safe relative path, or '' when unsafe.
function updater_safe_relpath(string $name): string
{
    $name = str_replace('\\', '/', trim($name));
    if ($name === '' || $name[0] === '/' || strpos($name, ':') !== false) {
        return '';
    }
    $parts = explode('/', $name);
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.') {
            continue;
        }
        if ($p === '..' || $p === '.update') {
            return '';
        }
        $out[] = $p;
    }
    $rel = implode('/', $out);
    if ($rel === '') {
        return '';
    }
    return $rel;
}

function updater_human_bytes($n): string
{
    $n = max(0, (int)$n);
    if ($n < 1024) {
        return $n . ' B';
    }
    $u = ['KB', 'MB', 'GB'];
    $i = -1;
    $v = (float)$n;
    while ($v >= 1024 && $i < count($u) - 1) {
        $v /= 1024;
        $i++;
    }
    return round($v, 1) . ' ' . $u[$i];
}

// Parse "<sha256>  <filename>" or bare-hex checksum files.
function updater_parse_sha256(string $text): string
{
    if (preg_match('/\b([0-9a-fA-F]{64})\b/', $text, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

// ---- state (flock-guarded JSON) ----

function updater_state_default(): array
{
    return [
        'lock' => null,
        'checked_at' => null,
        'release' => null,
        'download' => null,
        'staged' => null,
        'backup' => null,
        'applied' => null,
        'error' => null,
        'updated_at' => null,
    ];
}

function updater_state_load(?string $dataDir = null): array
{
    $file = updater_state_file($dataDir);
    if (!is_file($file)) {
        return updater_state_default();
    }
    $fp = @fopen($file, 'r');
    if ($fp === false) {
        return updater_state_default();
    }
    @flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) {
        return updater_state_default();
    }
    return array_merge(updater_state_default(), $d);
}

function updater_state_save(array $state, ?string $dataDir = null): bool
{
    $dir = updater_work_dir($dataDir);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $state['updated_at'] = gmdate('Y-m-d H:i:s');
    $file = updater_state_file($dataDir);
    $fp = @fopen($file, 'c');
    if ($fp === false) {
        return false;
    }
    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    $ok = fwrite($fp, json_encode($state)) !== false;
    fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function updater_lock_acquire(string $stage, ?string $dataDir = null, int $ttl = 900): bool
{
    $state = updater_state_load($dataDir);
    $lock = is_array($state['lock']) ? $state['lock'] : null;
    if ($lock !== null && isset($lock['at']) && (time() - (int)$lock['at']) < $ttl) {
        return false;
    }
    $state['lock'] = ['stage' => $stage, 'at' => time(), 'pid' => function_exists('getmypid') ? getmypid() : 0];
    $state['error'] = null;
    return updater_state_save($state, $dataDir);
}

function updater_lock_release(?string $dataDir = null): void
{
    $state = updater_state_load($dataDir);
    $state['lock'] = null;
    updater_state_save($state, $dataDir);
}

function updater_fail(string $msg, ?string $dataDir = null): void
{
    $state = updater_state_load($dataDir);
    $state['error'] = $msg;
    $state['lock'] = null;
    updater_state_save($state, $dataDir);
}

// ---- HTTP (cURL preferred, streams fallback) ----

function updater_http_json(string $url, int $timeout = 15): array
{
    $headers = ['User-Agent: MiniS3-Updater', 'Accept: application/vnd.github+json'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return [0, null, 'Download failed: ' . $err];
        }
        $data = json_decode((string)$body, true);
        if ($code === 403) {
            return [$code, $data, 'GitHub API rate limit reached. Try again later.'];
        }
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return [$code, $data, 'GitHub API error (HTTP ' . $code . ').'];
        }
        return [$code, $data, null];
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'follow_location' => 1,
            'max_redirects' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $respHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : (isset($http_response_header) && is_array($http_response_header) ? $http_response_header : []);
    $code = 0;
    foreach ($respHeaders as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    if ($body === false) {
        return [$code, null, 'Download failed (network error).'];
    }
    $data = json_decode((string)$body, true);
    if ($code === 403) {
        return [$code, $data, 'GitHub API rate limit reached. Try again later.'];
    }
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        return [$code, $data, 'GitHub API error (HTTP ' . $code . ').'];
    }
    return [$code, $data, null];
}

// Stream a URL to $dest. Resumes a matching .part file when cURL is
// available; otherwise restarts. Returns [ok, error].
function updater_download(string $url, string $dest, int $expectedSize = 0, int $maxBytes = 67108864): array
{
    $part = $dest . '.part';
    $resumeFrom = 0;
    if (is_file($part) && $expectedSize > 0 && filesize($part) > 0 && filesize($part) < $expectedSize) {
        $resumeFrom = (int)filesize($part);
    } elseif (is_file($part)) {
        @unlink($part);
    }
    if (function_exists('curl_init')) {
        $fp = @fopen($resumeFrom > 0 ? $part : $dest, $resumeFrom > 0 ? 'ab' : 'wb');
        if ($fp === false) {
            return [false, 'Cannot write download file.'];
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: MiniS3-Updater']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        if ($resumeFrom > 0) {
            curl_setopt($ch, CURLOPT_RANGE, $resumeFrom . '-');
        }
        @set_time_limit(0);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($ok === false) {
            return [false, 'Download failed: ' . $err];
        }
        if ($resumeFrom > 0 && $code !== 206 && $code !== 200) {
            @unlink($part);
            return updater_download($url, $dest, $expectedSize, $maxBytes);
        }
        $final = $resumeFrom > 0 ? $part : $dest;
        if ($resumeFrom > 0 && !@rename($part, $dest)) {
            return [false, 'Cannot finalize download file.'];
        }
        $size = filesize($final);
        if ($size === false || $size > $maxBytes) {
            @unlink($final);
            return [false, 'Package exceeds the size limit.'];
        }
        if ($expectedSize > 0 && $size !== $expectedSize) {
            @unlink($final);
            return [false, 'Download incomplete (got ' . $size . ' of ' . $expectedSize . ' bytes).'];
        }
        return [true, null];
    }
    // Streams fallback: full restart.
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => 'User-Agent: MiniS3-Updater',
        'timeout' => 0,
        'follow_location' => 1,
        'max_redirects' => 3,
    ]]);
    $in = @fopen($url, 'rb', false, $ctx);
    if ($in === false) {
        return [false, 'Download failed (network error).'];
    }
    $out = @fopen($dest, 'wb');
    if ($out === false) {
        fclose($in);
        return [false, 'Cannot write download file.'];
    }
    @set_time_limit(0);
    $size = 0;
    while (!feof($in)) {
        $chunk = fread($in, 1048576);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $size += strlen($chunk);
        if ($size > $maxBytes) {
            fclose($in);
            fclose($out);
            @unlink($dest);
            return [false, 'Package exceeds the size limit.'];
        }
        fwrite($out, $chunk);
    }
    fclose($in);
    fclose($out);
    if ($expectedSize > 0 && $size !== $expectedSize) {
        @unlink($dest);
        return [false, 'Download incomplete (got ' . $size . ' of ' . $expectedSize . ' bytes).'];
    }
    return [true, null];
}

// ---- release handling ----

function updater_pick_asset(array $release, string $tag): array
{
    $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
    $wantZip = 'minis3-' . $tag . '.zip';
    $zip = null;
    $sha = null;
    foreach ($assets as $a) {
        if (!is_array($a) || empty($a['name']) || empty($a['browser_download_url'])) {
            continue;
        }
        if ($a['name'] === $wantZip) {
            $zip = $a;
        }
        if ($a['name'] === $wantZip . '.sha256') {
            $sha = $a;
        }
    }
    if ($zip === null) {
        return [null, 'Release has no ' . $wantZip . ' package asset.'];
    }
    if ($sha === null) {
        return [null, 'Release is missing the ' . $wantZip . '.sha256 checksum asset.'];
    }
    return [[
        'name' => $zip['name'],
        'size' => (int)($zip['size'] ?? 0),
        'url' => (string)$zip['browser_download_url'],
        'checksum_name' => $sha['name'],
        'checksum_url' => (string)$sha['browser_download_url'],
        'checksum_size' => (int)($sha['size'] ?? 0),
    ], null];
}

function updater_check(string $currentVersion, string $apiBase = ''): array
{
    $current = app_version_normalize($currentVersion);
    if ($current === '') {
        return ['ok' => false, 'error' => 'Installed version is unreadable.'];
    }
    list($code, $rel, $err) = updater_http_json(updater_api_latest($apiBase));
    if ($err !== null) {
        return ['ok' => false, 'error' => $err, 'http' => $code];
    }
    if (empty($rel['tag_name'])) {
        return ['ok' => false, 'error' => 'Release metadata is missing a tag.'];
    }
    $tag = (string)$rel['tag_name'];
    $latest = app_version_normalize($tag);
    if ($latest === '') {
        return ['ok' => false, 'error' => 'Release tag is not a version number: ' . $tag];
    }
    $cmp = version_compare($latest, $current);
    if ($cmp <= 0) {
        return [
            'ok' => true,
            'update_available' => false,
            'current' => $current,
            'latest' => $latest,
            'tag' => $tag,
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
    list($asset, $aerr) = updater_pick_asset($rel, $tag);
    if ($aerr !== null) {
        return ['ok' => false, 'error' => $aerr];
    }
    return [
        'ok' => true,
        'update_available' => true,
        'current' => $current,
        'latest' => $latest,
        'tag' => $tag,
        'name' => isset($rel['name']) ? (string)$rel['name'] : $tag,
        'body' => isset($rel['body']) ? (string)$rel['body'] : '',
        'published_at' => isset($rel['published_at']) ? (string)$rel['published_at'] : '',
        'asset' => $asset,
        'checked_at' => gmdate('Y-m-d H:i:s'),
    ];
}

// ---- package manifest ----

function updater_manifest_validate(array $m): array
{
    $errors = [];
    if (($m['app'] ?? '') !== 'minis3-update') {
        $errors[] = 'Not an update manifest.';
    }
    if (app_version_normalize((string)($m['version'] ?? '')) === '') {
        $errors[] = 'Manifest version is invalid.';
    }
    if (empty($m['files']) || !is_array($m['files'])) {
        $errors[] = 'Manifest file list is empty.';
    } else {
        foreach ($m['files'] as $i => $f) {
            if (!is_array($f) || updater_safe_relpath((string)($f['path'] ?? '')) === '') {
                $errors[] = 'Manifest file #' . $i . ' has an unsafe path.';
                continue;
            }
            if (!preg_match('/^[0-9a-f]{64}$/', (string)($f['sha256'] ?? ''))) {
                $errors[] = 'Manifest file #' . $i . ' has an invalid checksum.';
            }
            if ((int)($f['size'] ?? -1) < 0) {
                $errors[] = 'Manifest file #' . $i . ' has an invalid size.';
            }
        }
    }
    foreach ((array)($m['protected_paths'] ?? []) as $p) {
        if (updater_safe_relpath((string)$p) === '' && $p !== 'data') {
            $errors[] = 'Manifest protected path is invalid: ' . $p;
        }
    }
    return $errors;
}

// Build the install plan from a validated manifest. Never returns protected
// paths; records skipped deletions and warnings instead of acting on them.
function updater_build_plan(array $manifest, string $appRoot, array $opts = []): array
{
    $plan = ['replace' => [], 'add' => [], 'skipped_protected' => [], 'skipped_deleted' => [], 'warnings' => []];
    $noRestore = isset($manifest['no_restore']) && is_array($manifest['no_restore'])
        ? $manifest['no_restore'] : updater_no_restore_paths();
    $protected = array_merge(updater_protected_paths(), (array)($manifest['protected_paths'] ?? []));
    $isProtected = function ($rel) use ($protected) {
        $rel = str_replace('\\', '/', $rel);
        foreach ($protected as $p) {
            $p = str_replace('\\', '/', (string)$p);
            if ($rel === $p || strpos($rel, rtrim($p, '/') . '/') === 0) {
                return true;
            }
        }
        return false;
    };
    foreach ((array)$manifest['files'] as $f) {
        $rel = updater_safe_relpath((string)$f['path']);
        if ($rel === '') {
            continue;
        }
        if ($isProtected($rel)) {
            $plan['skipped_protected'][] = $rel;
            continue;
        }
        $live = rtrim($appRoot, '/') . '/' . $rel;
        if (!file_exists($live) && in_array($rel, $noRestore, true)) {
            $plan['skipped_deleted'][] = $rel;
            continue;
        }
        if (file_exists($live)) {
            $plan['replace'][] = $rel;
        } else {
            $plan['add'][] = $rel;
        }
    }
    if (empty($plan['replace']) && empty($plan['add'])) {
        $plan['warnings'][] = 'Package contains no installable files for this layout.';
    }
    return $plan;
}

// ---- extraction ----

function updater_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir() && !$f->isLink()) {
            @rmdir($f->getPathname());
        } else {
            @unlink($f->getPathname());
        }
    }
    @rmdir($dir);
}

// Extract validated entry names with ZipArchive (streams internally).
// When $names is null, every path-safe entry is extracted.
function updater_extract_native(string $zipPath, string $dest, ?array $names = null): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [false, 'Cannot open update package.'];
    }
    $total = 0;
    $safe = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if (!is_array($st)) {
            continue;
        }
        $rel = updater_safe_relpath((string)($st['name'] ?? ''));
        if ($rel === '') {
            continue;
        }
        if ($names !== null && !isset($names[$rel]) && !isset($names[rtrim((string)($st['name'] ?? ''), '/')])) {
            continue;
        }
        $safe[] = (string)$st['name'];
        $total += (int)($st['size'] ?? 0);
    }
    if ($total > 134217728) {
        $zip->close();
        return [false, 'Package contents exceed the 128 MB safety cap.'];
    }
    if (!$zip->extractTo($dest, $safe)) {
        $zip->close();
        return [false, 'Package extraction failed.'];
    }
    $zip->close();
    return [true, null];
}

// Dependency-free ZIP reader: stored + deflated entries, Zip Slip proofed,
// per-file and total caps, CRC verified. Used only when ZipArchive is absent.
// When $wanted is null, every path-safe entry is extracted.
function updater_extract_fallback(string $zipPath, string $dest, ?array $wanted = null): array
{
    if (!function_exists('gzinflate')) {
        return [false, 'PHP ZipArchive and zlib are both unavailable. Enable the zip extension to update.'];
    }
    $fp = @fopen($zipPath, 'rb');
    if ($fp === false) {
        return [false, 'Cannot read update package.'];
    }
    $size = filesize($zipPath);
    if ($size === false || $size < 22) {
        fclose($fp);
        return [false, 'Update package is corrupt.'];
    }
    // Locate End of Central Directory.
    $tailLen = (int)min($size, 65558);
    fseek($fp, $size - $tailLen);
    $tail = fread($fp, $tailLen);
    if ($tail === false) {
        fclose($fp);
        return [false, 'Update package is corrupt.'];
    }
    $eocd = -1;
    for ($i = strlen($tail) - 22; $i >= 0; $i--) {
        if (substr($tail, $i, 4) === "PK\x05\x06") {
            $eocd = $i;
            break;
        }
    }
    if ($eocd < 0) {
        fclose($fp);
        return [false, 'Update package is corrupt.'];
    }
    $e = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLen', substr($tail, $eocd, 22));
    $central = [];
    fseek($fp, (int)$e['cdOffset']);
    for ($n = 0; $n < (int)$e['totalEntries']; $n++) {
        $h = fread($fp, 46);
        if ($h === false || strlen($h) < 46 || substr($h, 0, 4) !== "PK\x01\x02") {
            fclose($fp);
            return [false, 'Update package is corrupt.'];
        }
        $c = unpack('Vsig/vverMade/vverNeed/vflags/vmethod/vmtime/vmdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vattrInt/VattrExt/VlocalOff', $h);
        $name = $c['nameLen'] > 0 ? fread($fp, (int)$c['nameLen']) : '';
        if ($c['extraLen'] > 0) {
            fread($fp, (int)$c['extraLen']);
        }
        if ($c['commentLen'] > 0) {
            fread($fp, (int)$c['commentLen']);
        }
        if ((int)$c['flags'] & 0x01) {
            fclose($fp);
            return [false, 'Encrypted packages are not supported.'];
        }
        $central[(string)$name] = $c;
    }
    $want = null;
    if ($wanted !== null) {
        $want = [];
        foreach ($wanted as $w) {
            $want[$w] = true;
        }
    }
    $written = 0;
    foreach ($central as $name => $c) {
        $rel = updater_safe_relpath($name);
        if ($rel === '' || ($want !== null && !isset($want[$rel]) && !isset($want[rtrim($name, '/')]))) {
            continue;
        }
        $method = (int)$c['method'];
        if ($method !== 0 && $method !== 8) {
            fclose($fp);
            return [false, 'Unsupported compression in update package.'];
        }
        if ((int)$c['size'] > 33554432) {
            fclose($fp);
            return [false, 'Package entry exceeds the 32 MB safety cap: ' . $rel];
        }
        $target = rtrim($dest, '/') . '/' . $rel;
        if (substr($name, -1) === '/') {
            if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                fclose($fp);
                return [false, 'Cannot create directory in staging area.'];
            }
            continue;
        }
        // Locate local header to find the data offset.
        fseek($fp, (int)$c['localOff']);
        $lh = fread($fp, 30);
        if ($lh === false || strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") {
            fclose($fp);
            return [false, 'Update package is corrupt.'];
        }
        $l = unpack('Vsig/vver/vflags/vmethod/vmtime/vmdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen', $lh);
        $dataOff = (int)$c['localOff'] + 30 + (int)$l['nameLen'] + (int)$l['extraLen'];
        fseek($fp, $dataOff);
        $comp = ($c['compSize'] > 0) ? fread($fp, (int)$c['compSize']) : '';
        if ($comp === false) {
            fclose($fp);
            return [false, 'Update package is corrupt.'];
        }
        $data = ($method === 8) ? @gzinflate($comp) : $comp;
        if (!is_string($data)) {
            fclose($fp);
            return [false, 'Package entry failed integrity check: ' . $rel];
        }
        if (strlen($data) !== (int)$c['size'] || sprintf('%u', crc32($data)) !== sprintf('%u', $c['crc'])) {
            fclose($fp);
            return [false, 'Package entry failed integrity check: ' . $rel];
        }
        $written += strlen($data);
        if ($written > 134217728) {
            fclose($fp);
            return [false, 'Package contents exceed the 128 MB safety cap.'];
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            fclose($fp);
            return [false, 'Cannot create directory in staging area.'];
        }
        if (@file_put_contents($target, $data) === false) {
            fclose($fp);
            return [false, 'Cannot write staging area.'];
        }
    }
    fclose($fp);
    return [true, null];
}

function updater_extract(string $zipPath, string $dest, ?array $names = null): array
{
    if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
        return [false, 'Cannot create staging area.'];
    }
    if (class_exists('ZipArchive')) {
        return updater_extract_native($zipPath, $dest, $names);
    }
    return updater_extract_fallback($zipPath, $dest, $names);
}

// Locate the update manifest inside an extracted package. Packages carry a
// single top-level directory (minis3-vX.Y.Z/), with UPDATE.json at its root.
function updater_find_manifest(string $staging): string
{
    $direct = rtrim($staging, '/') . '/UPDATE.json';
    if (is_file($direct)) {
        return $direct;
    }
    $items = @scandir($staging);
    if (is_array($items)) {
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $cand = rtrim($staging, '/') . '/' . $i . '/UPDATE.json';
            if (is_file($cand)) {
                return $cand;
            }
        }
    }
    return '';
}

// Package root directory (for stripping the top-level folder when applying).
function updater_package_root(string $staging): string
{
    $items = @scandir($staging);
    if (is_array($items)) {
        $dirs = [];
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            if (is_dir(rtrim($staging, '/') . '/' . $i)) {
                $dirs[] = $i;
            }
        }
        if (count($dirs) === 1) {
            return rtrim($staging, '/') . '/' . $dirs[0];
        }
    }
    return rtrim($staging, '/');
}

// Fetch a small text document (checksum files). Returns null on failure.
function updater_fetch_text(string $url, int $timeout = 15, int $maxBytes = 1024): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: MiniS3-Updater']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300 || strlen((string)$body) > $maxBytes) {
            return null;
        }
        return (string)$body;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => 'User-Agent: MiniS3-Updater',
        'timeout' => $timeout,
        'follow_location' => 1,
        'max_redirects' => 3,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || strlen($body) > $maxBytes) {
        return null;
    }
    return $body;
}

// ---- requirements ----

function updater_requirements(array $manifest, string $appRoot, int $packageBytes = 0): array
{
    $issues = [];
    $minPhp = isset($manifest['min_php']) ? (string)$manifest['min_php'] : '7.4.0';
    if (!preg_match('/^[0-9]+(\.[0-9]+){0,2}$/', $minPhp)) {
        $minPhp = '7.4.0';
    }
    if (version_compare(PHP_VERSION, $minPhp, '<')) {
        $issues[] = 'PHP ' . $minPhp . '+ is required (running ' . PHP_VERSION . ').';
    }
    $exts = isset($manifest['required_extensions']) && is_array($manifest['required_extensions'])
        ? $manifest['required_extensions']
        : ['pdo_sqlite', 'simplexml', 'openssl', 'mbstring', 'fileinfo', 'json'];
    foreach ($exts as $ext) {
        if (!extension_loaded((string)$ext)) {
            $issues[] = 'Missing PHP extension: ' . $ext . '.';
        }
    }
    $free = @disk_free_space($appRoot);
    $need = max($packageBytes * 3, 10485760);
    if ($free !== false && $free < $need) {
        $issues[] = 'Not enough free disk space (need ' . updater_human_bytes($need) . ').';
    }
    if (!is_writable($appRoot)) {
        $issues[] = 'Application directory is not writable.';
    }
    return $issues;
}

function updater_extractor_note(): string
{
    if (class_exists('ZipArchive')) {
        return 'zip';
    }
    if (function_exists('gzinflate')) {
        return 'fallback';
    }
    return 'none';
}

// Every install target must be writable BEFORE backup starts, so a
// read-only file aborts with a fixable message instead of a half-applied
// update. Returns the list of blocked relative paths.
function updater_check_writable(string $appRoot, array $plan): array
{
    $bad = [];
    foreach ((array)($plan['replace'] ?? []) as $rel) {
        $live = rtrim($appRoot, '/') . '/' . $rel;
        if (!is_file($live) || !is_writable($live)) {
            $bad[] = $rel;
        }
    }
    foreach ((array)($plan['add'] ?? []) as $rel) {
        $dir = dirname(rtrim($appRoot, '/') . '/' . $rel);
        $probe = $dir;
        while (!is_dir($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        if (!is_dir($probe) || !is_writable($probe)) {
            $bad[] = $rel;
        }
    }
    return array_values(array_unique($bad));
}

// ---- backup / apply / rollback ----

function updater_backup_root(?string $dataDir = null): string
{
    return updater_work_dir($dataDir) . '/backups';
}

function updater_mkdir(string $dir): bool
{
    return is_dir($dir) || (@mkdir($dir, 0755, true) && is_dir($dir));
}

// Snapshot every live file the plan will replace, plus a consistent DB copy.
// Never includes config.php or data/ contents beyond the DB snapshot itself.
function updater_do_backup(string $appRoot, array $plan, string $fromVersion, array $installed, ?string $dataDir = null): array
{
    $safe = preg_replace('/[^0-9A-Za-z.-]/', '', $fromVersion);
    $dir = updater_backup_root($dataDir) . '/' . gmdate('Ymd-His') . '-' . $safe . '-' . bin2hex(random_bytes(4));
    if (!updater_mkdir($dir . '/files')) {
        return [null, 'Cannot create backup directory.'];
    }
    $journal = ['from' => $fromVersion, 'at' => gmdate('Y-m-d H:i:s'), 'replaced' => [], 'added' => [], 'installed' => $installed];
    foreach ($plan['replace'] as $rel) {
        $live = rtrim($appRoot, '/') . '/' . $rel;
        if (!is_file($live)) {
            continue;
        }
        $dst = $dir . '/files/' . $rel;
        if (!updater_mkdir(dirname($dst))) {
            return [null, 'Cannot back up: ' . $rel];
        }
        if (!@copy($live, $dst)) {
            return [null, 'Cannot back up: ' . $rel];
        }
        $mode = fileperms($live);
        $journal['replaced'][] = ['rel' => $rel, 'mode' => $mode === false ? 0644 : ($mode & 0777)];
    }
    foreach ($plan['add'] as $rel) {
        $journal['added'][] = ['rel' => $rel, 'hash' => isset($installed[$rel]) ? $installed[$rel] : ''];
    }
    $dbDest = $dir . '/db.sqlite';
    try {
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $dbDest) . "'");
    } catch (Throwable $e) {
        return [null, 'Cannot back up the database: ' . $e->getMessage()];
    }
    if (@file_put_contents($dir . '/plan.json', json_encode($journal)) === false) {
        return [null, 'Cannot write backup journal.'];
    }
    return [['dir' => $dir, 'journal' => $journal], null];
}

function updater_installed_hashes(string $pkgRoot, array $rels): array
{
    $out = [];
    foreach ($rels as $rel) {
        $p = rtrim($pkgRoot, '/') . '/' . $rel;
        if (is_file($p)) {
            $h = @hash_file('sha256', $p);
            if (is_string($h)) {
                $out[$rel] = $h;
            }
        }
    }
    return $out;
}

// Copies staged files over the live tree per the journal. Added targets that
// appeared since planning are backed up on the fly first.
function updater_do_apply(string $appRoot, string $pkgRoot, array $journal, string $backupDir): array
{
    $counts = ['replaced' => 0, 'added' => 0];
    $put = function ($rel, $wasPlanned) use ($appRoot, $pkgRoot, $backupDir, &$counts) {
        $src = rtrim($pkgRoot, '/') . '/' . $rel;
        $live = rtrim($appRoot, '/') . '/' . $rel;
        if (!is_file($src)) {
            return 'Package file missing: "' . $rel . '"';
        }
        if (!updater_mkdir(dirname($live))) {
            return 'Cannot create directory for: "' . $rel . '"';
        }
        if (is_file($live) && !$wasPlanned) {
            $bdst = rtrim($backupDir, '/') . '/files/' . $rel;
            if (!updater_mkdir(dirname($bdst)) || !@copy($live, $bdst)) {
                return 'Cannot back up: "' . $rel . '"';
            }
        }
        $mode = is_file($live) ? fileperms($live) : false;
        if (!@copy($src, $live)) {
            return 'Cannot write: "' . $rel . '"';
        }
        if ($mode !== false) {
            @chmod($live, $mode & 0777);
        }
        $counts[$wasPlanned || is_file($live) ? 'replaced' : 'added']++;
        return null;
    };
    foreach ($journal['replaced'] as $r) {
        $err = $put($r['rel'], true);
        if ($err !== null) {
            return [$counts, $err];
        }
    }
    foreach ($journal['added'] as $a) {
        $err = $put($a['rel'], false);
        if ($err !== null) {
            return [$counts, $err];
        }
    }
    return [$counts, null];
}

// Restores a backup: replaced files go back with original modes; added files
// are removed only when untouched since install (otherwise kept + reported).
function updater_do_rollback(string $appRoot, array $backup): array
{
    $dir = (string)($backup['dir'] ?? '');
    $journal = isset($backup['journal']) && is_array($backup['journal']) ? $backup['journal'] : [];
    if ($dir === '' || !is_dir($dir)) {
        return [['restored' => 0, 'removed' => 0, 'kept' => [], 'warnings' => ['Backup is missing.']], null];
    }
    $out = ['restored' => 0, 'removed' => 0, 'kept' => [], 'warnings' => []];
    foreach ((array)($journal['replaced'] ?? []) as $r) {
        $rel = (string)($r['rel'] ?? '');
        if ($rel === '') {
            continue;
        }
        $src = rtrim($dir, '/') . '/files/' . $rel;
        $live = rtrim($appRoot, '/') . '/' . $rel;
        if (!is_file($src)) {
            $out['warnings'][] = 'Backup copy missing: ' . $rel;
            continue;
        }
        if (!updater_mkdir(dirname($live)) || !@copy($src, $live)) {
            return [$out, 'Cannot restore: ' . $rel];
        }
        @chmod($live, (int)($r['mode'] ?? 0644));
        $out['restored']++;
    }
    foreach ((array)($journal['added'] ?? []) as $a) {
        $rel = (string)($a['rel'] ?? '');
        if ($rel === '') {
            continue;
        }
        $live = rtrim($appRoot, '/') . '/' . $rel;
        if (!is_file($live)) {
            continue;
        }
        $expect = (string)($a['hash'] ?? '');
        $actual = @hash_file('sha256', $live);
        if ($expect !== '' && $actual === $expect) {
            if (@unlink($live)) {
                $out['removed']++;
                updater_prune_empty_dirs($appRoot, dirname($live));
            } else {
                $out['warnings'][] = 'Cannot remove added file: ' . $rel;
            }
        } else {
            $out['kept'][] = $rel;
        }
    }
    return [$out, null];
}

function updater_prune_empty_dirs(string $appRoot, string $dir): void
{
    $appRoot = rtrim($appRoot, '/');
    $dir = rtrim($dir, '/');
    while ($dir !== '' && $dir !== $appRoot && strpos($dir, $appRoot . '/') === 0) {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        $left = array_diff($items, ['.', '..']);
        if (!empty($left)) {
            return;
        }
        if (!@rmdir($dir)) {
            return;
        }
        $dir = dirname($dir);
    }
}

// Drop old backups, newest first, keeping $keep (the active one is newest).
function updater_prune_backups(?string $dataDir = null, int $keep = 2): void
{
    $root = updater_backup_root($dataDir);
    $items = @scandir($root);
    if (!is_array($items)) {
        return;
    }
    $dirs = [];
    foreach ($items as $i) {
        if ($i === '.' || $i === '..') {
            continue;
        }
        if (is_dir($root . '/' . $i)) {
            $dirs[] = $i;
        }
    }
    rsort($dirs);
    foreach (array_slice($dirs, max(0, $keep)) as $d) {
        updater_rmdir($root . '/' . $d);
    }
}
