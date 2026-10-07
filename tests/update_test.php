<?php
// Updater unit tests - no server, no network, no sessions.
//
// Usage: php tests/update_test.php
//
// Covers version handling, release asset picking, checksum parsing, path
// safety, manifest validation, install planning, both ZIP extractors, the
// backup/apply/rollback cycle and requirements gating.

declare(strict_types=1);

error_reporting(E_ALL);

$tmp = sys_get_temp_dir() . '/minis3-update-test-' . getmypid();
define('APP_ROOT', $tmp . '/app');
define('DATA_DIR', $tmp . '/data');
define('DB_PATH', $tmp . '/data/app.sqlite');

require __DIR__ . '/../lib/version.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/update.php';

$passed = 0;
$failed = 0;

function t_check($cond, string $name, string $extra = '')
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo '  FAIL ' . $name . ($extra !== '' ? ' [' . $extra . ']' : '') . "\n";
    }
}

function t_rmdir(string $dir): void
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

t_rmdir($tmp);
mkdir(APP_ROOT, 0775, true);
mkdir(DATA_DIR, 0775, true);

// ---------- versions ----------
t_check(app_version_normalize('v1.2.3') === '1.2.3', 'normalize v-tag');
t_check(app_version_normalize('1.0.10') === '1.0.10', 'normalize plain');
t_check(app_version_normalize('main') === '', 'normalize garbage rejected');
t_check(app_version_normalize('../x') === '', 'normalize traversal rejected');
t_check(version_compare('1.0.11', '1.0.10', '>'), 'newer detected');
t_check(!version_compare('1.0.10', '1.0.10', '>'), 'same is not newer');
t_check(!version_compare('1.0.9', '1.0.10', '>'), 'older blocked (no downgrade)');

// ---------- asset picking ----------
$rel = ['tag_name' => 'v9.9.9', 'assets' => [
    ['name' => 'minis3-v9.9.9.tar.gz', 'browser_download_url' => 'https://x/t'],
    ['name' => 'minis3-v9.9.9.zip', 'browser_download_url' => 'https://x/z', 'size' => 123],
    ['name' => 'minis3-v9.9.9.zip.sha256', 'browser_download_url' => 'https://x/s', 'size' => 70],
]];
list($asset, $aerr) = updater_pick_asset($rel, 'v9.9.9');
t_check($aerr === null && $asset['size'] === 123 && strpos($asset['checksum_url'], '/s') !== false, 'asset + checksum picked');
list($asset2, $aerr2) = updater_pick_asset(['assets' => []], 'v1.0.0');
t_check($asset2 === null && $aerr2 !== null, 'missing package rejected');
$relNoSha = ['assets' => [['name' => 'minis3-v1.0.0.zip', 'browser_download_url' => 'https://x/z']]];
list($asset3, $aerr3) = updater_pick_asset($relNoSha, 'v1.0.0');
t_check($asset3 === null && strpos((string)$aerr3, 'sha256') !== false, 'missing checksum rejected');

// ---------- checksums ----------
t_check(updater_parse_sha256(str_repeat('a', 64) . "  minis3-v1.zip\n") === str_repeat('a', 64), 'sha256 file format parsed');
t_check(updater_parse_sha256(str_repeat('B', 64)) === strtolower(str_repeat('B', 64)), 'bare hex uppercased');
t_check(updater_parse_sha256('not a hash') === '', 'garbage checksum rejected');

// ---------- paths ----------
t_check(updater_safe_relpath('admin/index.php') === 'admin/index.php', 'safe path kept');
t_check(updater_safe_relpath('../config.php') === '', 'traversal rejected');
t_check(updater_safe_relpath('/abs/path.php') === '', 'absolute rejected');
t_check(updater_safe_relpath('C:\\win\\x.php') === '', 'drive rejected');
t_check(updater_safe_relpath('data/.update/x') === '', 'work dir rejected');
t_check(updater_is_protected('config.php'), 'config protected');
t_check(updater_is_protected('data/app.sqlite'), 'data protected');
t_check(!updater_is_protected('index.php'), 'index not protected');

// ---------- manifest + plan ----------
$manifest = [
    'app' => 'minis3-update',
    'version' => '9.9.9',
    'tag' => 'v9.9.9',
    'min_php' => '7.4.0',
    'required_extensions' => ['json'],
    'protected_paths' => ['config.php', 'data'],
    'no_restore' => ['install.php'],
    'files' => [
        ['path' => 'index.php', 'size' => 10, 'sha256' => str_repeat('0', 64)],
        ['path' => 'lib/new.php', 'size' => 10, 'sha256' => str_repeat('1', 64)],
        ['path' => 'config.php', 'size' => 10, 'sha256' => str_repeat('2', 64)],
        ['path' => 'install.php', 'size' => 10, 'sha256' => str_repeat('3', 64)],
    ],
];
t_check(updater_manifest_validate($manifest) === [], 'manifest valid');
$badm = $manifest;
$badm['app'] = 'nope';
t_check(count(updater_manifest_validate($badm)) > 0, 'manifest app rejected');
$badm2 = $manifest;
$badm2['files'][0]['path'] = '../evil.php';
t_check(count(updater_manifest_validate($badm2)) > 0, 'manifest unsafe path rejected');
file_put_contents(APP_ROOT . '/index.php', 'old-index');
file_put_contents(DATA_DIR . '/keep.txt', 'user-data');
@mkdir(DATA_DIR . '/users', 0775, true);
$plan = updater_build_plan($manifest, APP_ROOT);
t_check($plan['replace'] === ['index.php'], 'plan replaces live file', json_encode($plan['replace']));
t_check($plan['add'] === ['lib/new.php'], 'plan adds new file', json_encode($plan['add']));
t_check($plan['skipped_protected'] === ['config.php'], 'plan skips config', json_encode($plan['skipped_protected']));
t_check($plan['skipped_deleted'] === ['install.php'], 'plan skips deleted installer', json_encode($plan['skipped_deleted']));

// ---------- extractors ----------
$makeZip = $tmp . '/pkg.zip';
if (class_exists('ZipArchive')) {
    $z = new ZipArchive();
    $z->open($makeZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('index.php', str_repeat('N', 1000));
    $z->addFromString('lib/new.php', str_repeat('x', 5000));
    $z->addFromString('../evil.php', 'evil');
    $z->addFromString('/abs.php', 'evil');
    $z->close();
    list($ok, $err) = updater_extract($makeZip, $tmp . '/staged');
    t_check($ok, 'native extract ok', (string)$err);
    t_check(is_file($tmp . '/staged/index.php') && is_file($tmp . '/staged/lib/new.php'), 'native entries land');
    t_check(!file_exists($tmp . '/evil.php') && !file_exists($tmp . '/abs.php') && !file_exists($tmp . '/staged/../evil.php'), 'native skips unsafe entries');
    // Fallback parser against the same real-world zip (stored + deflated).
    list($ok2, $err2) = updater_extract_fallback($makeZip, $tmp . '/staged2');
    t_check($ok2, 'fallback extract ok', (string)$err2);
    t_check(
        is_file($tmp . '/staged2/index.php') && file_get_contents($tmp . '/staged2/index.php') === str_repeat('N', 1000)
        && is_file($tmp . '/staged2/lib/new.php') && file_get_contents($tmp . '/staged2/lib/new.php') === str_repeat('x', 5000),
        'fallback byte-identical'
    );
    t_check(!file_exists($tmp . '/evil.php'), 'fallback skips unsafe entries');
} else {
    echo "  skip extractor zip tests (no ZipArchive to build fixture)\n";
}

// ---------- backup / apply / rollback ----------
@mkdir($tmp . '/staged3/lib', 0775, true);
file_put_contents($tmp . '/staged3/index.php', 'new-index');
file_put_contents($tmp . '/staged3/lib/new.php', 'new-lib');
$installed = updater_installed_hashes($tmp . '/staged3', ['index.php', 'lib/new.php']);
$plan2 = ['replace' => ['index.php', 'config.php', 'data/keep.txt'], 'add' => ['lib/new.php'], 'skipped_protected' => [], 'skipped_deleted' => [], 'warnings' => []];
// Simulate the planner's protection (planner output is trusted here only for
// paths, protection is re-checked by using the real planner):
$plan2 = updater_build_plan([
    'app' => 'minis3-update', 'version' => '9.9.9', 'files' => [
        ['path' => 'index.php', 'size' => 9, 'sha256' => $installed['index.php']],
        ['path' => 'lib/new.php', 'size' => 7, 'sha256' => $installed['lib/new.php']],
        ['path' => 'config.php', 'size' => 1, 'sha256' => str_repeat('0', 64)],
    ],
    'protected_paths' => ['config.php', 'data'], 'no_restore' => [],
], APP_ROOT);
file_put_contents(APP_ROOT . '/config.php', 'user-config');
list($backup, $err) = updater_do_backup(APP_ROOT, $plan2, '1.0.0', $installed, DATA_DIR);
t_check($err === null && $backup !== null, 'backup ok', (string)$err);
t_check(is_file($backup['dir'] . '/files/index.php') && is_file($backup['dir'] . '/db.sqlite'), 'backup has files + db');
t_check(file_get_contents(DATA_DIR . '/keep.txt') === 'user-data', 'user data untouched by backup');
list($counts, $err) = updater_do_apply(APP_ROOT, $tmp . '/staged3', $backup['journal'], $backup['dir']);
t_check($err === null, 'apply ok', (string)$err);
t_check(file_get_contents(APP_ROOT . '/index.php') === 'new-index', 'file replaced');
t_check(file_get_contents(APP_ROOT . '/config.php') === 'user-config', 'config preserved on apply');
t_check(is_file(APP_ROOT . '/lib/new.php'), 'file added');
// User edits the added file after install: rollback must keep it.
file_put_contents(APP_ROOT . '/lib/new.php', 'user-edited');
list($out, $err) = updater_do_rollback(APP_ROOT, $backup);
t_check($err === null, 'rollback ok', (string)$err);
t_check(file_get_contents(APP_ROOT . '/index.php') === 'old-index', 'rollback restores replaced');
t_check(file_get_contents(APP_ROOT . '/lib/new.php') === 'user-edited', 'rollback keeps user-edited added file');
t_check(in_array('lib/new.php', $out['kept']), 'kept list reported');
// Clean rollback path: fresh added file is removed.
@unlink(APP_ROOT . '/lib/new.php');
file_put_contents($tmp . '/staged3/lib/new.php', 'new-lib');
$plan3 = $plan2;
list($backup3, $err3) = updater_do_backup(APP_ROOT, $plan3, '1.0.0', $installed, DATA_DIR);
list($counts3, $err3b) = updater_do_apply(APP_ROOT, $tmp . '/staged3', $backup3['journal'], $backup3['dir']);
list($out3, $err3c) = updater_do_rollback(APP_ROOT, $backup3);
t_check($err3c === null && !file_exists(APP_ROOT . '/lib/new.php') && $out3['removed'] === 1, 'rollback removes pristine added file');

// ---------- requirements / misc ----------
t_check(updater_requirements(['min_php' => '7.4.0', 'required_extensions' => ['json']], APP_ROOT, 1024) === [], 'requirements pass');
$issues = updater_requirements(['min_php' => '99.0', 'required_extensions' => ['nope_ext_xyz']], APP_ROOT, 1024);
t_check(count($issues) === 2, 'requirements fail loudly', implode('; ', $issues));
// Writability gate: read-only target is reported before anything is touched.
@mkdir($tmp . '/wapp', 0775, true);
file_put_contents($tmp . '/wapp/ok.php', 'x');
file_put_contents($tmp . '/wapp/locked.php', 'x');
chmod($tmp . '/wapp/locked.php', 0444);
$wplan = ['replace' => ['ok.php', 'locked.php'], 'add' => ['newdir/n.php'], 'skipped_protected' => [], 'skipped_deleted' => [], 'warnings' => []];
$blocked = updater_check_writable($tmp . '/wapp', $wplan);
if (posix_geteuid() === 0) {
    echo "  skip writability gate (running as root)\n";
} else {
    t_check($blocked === ['locked.php'], 'writability gate lists read-only file', json_encode($blocked));
}
chmod($tmp . '/wapp/locked.php', 0644);
t_check(in_array(updater_extractor_note(), ['zip', 'fallback', 'none'], true), 'extractor note');
t_check(updater_human_bytes(1536) === '1.5 KB', 'human bytes');

// ---------- state + lock ----------
$dd = DATA_DIR;
$s = updater_state_load($dd);
t_check($s['lock'] === null && $s['error'] === null, 'fresh state');
t_check(updater_lock_acquire('test', $dd, 60), 'lock acquire');
t_check(!updater_lock_acquire('test', $dd, 60), 'concurrent lock refused');
updater_lock_release($dd);
t_check(updater_lock_acquire('test', $dd, 60), 'lock re-acquire after release');
updater_fail('boom', $dd);
$s = updater_state_load($dd);
t_check($s['error'] === 'boom' && $s['lock'] === null, 'fail records + releases');
updater_lock_release($dd);

t_rmdir($tmp);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
