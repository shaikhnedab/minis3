<?php
// Canonical application version. This file (and VERSION) is owned by the
// release package and may be replaced by the in-panel updater. Local
// config.php is never overwritten by updates, so the legacy APP_VERSION
// constant there is only a fallback for very old installs.

declare(strict_types=1);

if (!defined('MINIS3_VERSION')) {
    $minis3VersionFile = __DIR__ . '/../VERSION';
    $minis3Version = '';
    if (is_file($minis3VersionFile)) {
        $minis3Version = trim((string)@file_get_contents($minis3VersionFile));
    }
    if ($minis3Version === '' && defined('APP_VERSION')) {
        $minis3Version = trim((string)APP_VERSION);
    }
    define('MINIS3_VERSION', $minis3Version !== '' ? $minis3Version : '0.0.0');
}

// Installed version reported by the panel, health endpoint and updater.
function app_version(): string
{
    $v = defined('MINIS3_VERSION') ? trim((string)MINIS3_VERSION) : '';
    if ($v === '' && defined('APP_VERSION')) {
        $v = trim((string)APP_VERSION);
    }
    return $v !== '' ? $v : '0.0.0';
}

// "v1.2.3" -> "1.2.3" for version_compare(). Returns '' when unusable.
function app_version_normalize(string $v): string
{
    $v = trim($v);
    if (strpos($v, 'v') === 0 || strpos($v, 'V') === 0) {
        $v = substr($v, 1);
    }
    // Keep numeric dotted versions only (optional pre-release suffix).
    if (!preg_match('/^[0-9]+(\.[0-9]+){0,3}(-[0-9A-Za-z.-]+)?$/', $v)) {
        return '';
    }
    return $v;
}

// php2shell (CVE-2026-109618 + CVE-2026-109619, disclosed 2026-10-21): two
// critical unauthenticated RCEs in the PHP runtime itself, not in application
// code. Fixed in PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12; PHP <= 8.1 is EOL and
// only fixed by vendor/source backports; 8.6 pre-release builds predate the
// fix. Returns ['vulnerable' => bool, 'fixed' => string, 'cves' => string,
// 'note' => string] for the given (default: running) PHP version. The panel
// surfaces this as a warning banner; upgrading PHP is the only complete fix.
function php_sec_php2shell(?string $version = null): array
{
    $v = app_version_normalize($version ?? PHP_VERSION);
    $cves = 'CVE-2026-109618, CVE-2026-109619';
    if ($v === '') {
        return ['vulnerable' => true, 'fixed' => 'unknown', 'cves' => $cves,
            'note' => 'PHP version could not be determined.'];
    }
    if (preg_match('/^(\d+)\.(\d+)/', $v, $m)) {
        $branch = (int)$m[1] . '.' . (int)$m[2];
    } else {
        $branch = '';
    }
    $fixedByBranch = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
    if (isset($fixedByBranch[$branch])) {
        $fixed = $fixedByBranch[$branch];
        $vuln = version_compare($v, $fixed, '<');
        return ['vulnerable' => $vuln, 'fixed' => $fixed, 'cves' => $cves,
            'note' => $vuln
                ? 'Update PHP to ' . $fixed . ' or newer (or a vendor backport) and restart every worker.'
                : 'This PHP version includes the php2shell fixes.'];
    }
    if ($branch !== '' && version_compare($branch, '8.6', '>=')) {
        return ['vulnerable' => true, 'fixed' => '8.6.0 stable (or 8.5.12+)', 'cves' => $cves,
            'note' => 'Pre-release 8.6 builds predate the fix. Use 8.6.0 stable or a fixed 8.5.x.'];
    }
    // 8.1 and older: EOL upstream; 7.4/8.0/8.1 have minimal source backports.
    return ['vulnerable' => true, 'fixed' => 'a vendor/source backport (or upgrade to 8.2.35+)', 'cves' => $cves,
        'note' => 'PHP ' . $branch . ' is EOL and not fixed upstream; only vendor or source backports help.'];
}
