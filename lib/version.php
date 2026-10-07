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
