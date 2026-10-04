<?php
// MiniS3 installer - run once in the browser, then delete this file.

declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/lib/util.php';
require APP_ROOT . '/lib/db.php';

db_init();

function install_shorthand_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    if (preg_match('/^([0-9.]+)\s*([KMG]?)/i', $v, $m)) {
        $n = (float)$m[1];
        switch (strtoupper($m[2])) {
            case 'G': $n *= 1073741824; break;
            case 'M': $n *= 1048576; break;
            case 'K': $n *= 1024; break;
        }
        return (int)$n;
    }
    return (int)$v;
}

// Server preflight: every check returns
// {id, label, status: ok|warn|bad, value, detail, fix_da, fix_ssh}.
function install_checks(): array
{
    $phpV = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    $checks = [];
    $checks[] = [
        'id' => 'php', 'label' => 'PHP version', 'value' => $phpV . ' (' . php_sapi_name() . ')',
        'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'bad',
        'detail' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'Meets the 7.4+ requirement.' : 'MiniS3 needs PHP 7.4 or newer.',
        'fix_da' => 'Domain Setup -> select the domain -> Select PHP Version -> pick 8.2 or newer.',
        'fix_ssh' => 'Install a newer PHP (e.g. apt install php8.3-fpm / dnf module enable php:remi-8.3) and point the web server at it.',
    ];
    $exts = [
        'pdo_sqlite' => ['Required: database driver. Without it nothing works.', true],
        'sqlite3' => ['Recommended: native SQLite3 API used by some hosts/tools.', false],
        'simplexml' => ['Required: S3 XML requests/responses are parsed with it.', true],
        'openssl' => ['Required for passkey (WebAuthn) signatures.', true],
        'mbstring' => ['Required: multibyte string handling in auth/crypto paths.', true],
        'fileinfo' => ['Required: detects uploaded file types (favicon, previews).', true],
        'json' => ['Required: admin API and stored metadata.', true],
    ];
    foreach ($exts as $ext => [$why, $required]) {
        $on = extension_loaded($ext);
        $checks[] = [
            'id' => 'ext-' . $ext, 'label' => 'Extension: ' . $ext,
            'value' => $on ? 'loaded' : 'missing',
            'status' => $on ? 'ok' : ($required ? 'bad' : 'warn'),
            'detail' => $on ? $why : $why . ' Currently missing.',
            'fix_da' => 'Domain Setup -> PHP Selector (or Select PHP Version) -> Extensions -> tick ' . $ext . ' -> Save.',
            'fix_ssh' => 'apt install -y php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-' . $ext .
                '  (Debian/Ubuntu)  or  dnf install -y php-' . $ext . '  (AlmaLinux/Rocky), then restart PHP-FPM / the web server.',
        ];
    }
    $ini = [
        'upload_max_filesize' => ['Max single POST file (favicon, forms). S3 PUTs and panel uploads stream and bypass it.', '512M'],
        'post_max_size' => ['Must exceed upload_max_filesize for form posts.', '512M'],
        'max_execution_time' => ['0 = unlimited. Under ~30s, huge uploads can be killed.', '600'],
        'max_input_time' => ['Time PHP spends parsing request input.', '600'],
        'memory_limit' => ['256M+ recommended; ZIP listings and XML parsing use RAM.', '256M'],
        'max_file_uploads' => ['Files per form post.', '20+'],
    ];
    foreach ($ini as $k => [$why, $rec]) {
        $v = ini_get($k);
        $checks[] = [
            'id' => 'ini-' . $k, 'label' => 'php.ini: ' . $k, 'value' => ($v === '' || $v === false) ? '(not set)' : $v,
            'status' => 'info', 'detail' => $why . ' Recommended: ' . $rec . '.',
            'fix_da' => 'Domain Setup -> PHP Settings (or PHP Selector -> Configuration) -> set ' . $k . ' -> Save.',
            'fix_ssh' => 'Edit php.ini (' . php_ini_loaded_file() . ') e.g. ' . $k . ' = ' . $rec . ', then: systemctl restart php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm',
        ];
    }
    $zlib = ini_get('zlib.output_compression');
    $zlibOn = $zlib && strtolower((string)$zlib) !== 'off' && $zlib !== '0';
    $checks[] = [
        'id' => 'ini-zlib', 'label' => 'php.ini: zlib.output_compression',
        'value' => ($zlib === '' || $zlib === false) ? '(not set)' : $zlib,
        'status' => $zlibOn ? 'bad' : 'ok',
        'detail' => $zlibOn
            ? 'ON: strips Content-Length from streamed downloads and corrupts media previews. Must be Off.'
            : 'Off: streamed downloads keep Content-Length; previews work.',
        'fix_da' => 'PHP Settings -> set zlib.output_compression = Off (the bundled .htaccess already forces it off where the host allows php_value).',
        'fix_ssh' => 'php.ini: zlib.output_compression = Off, then restart PHP-FPM.',
    ];
    $fu = ini_get('file_uploads');
    $fuOn = !$fu || strtolower((string)$fu) === '1' || strtolower((string)$fu) === 'on';
    $checks[] = [
        'id' => 'ini-uploads', 'label' => 'php.ini: file_uploads', 'value' => $fuOn ? 'On' : 'Off',
        'status' => $fuOn ? 'ok' : 'warn',
        'detail' => $fuOn ? 'Form uploads (favicon) work.' : 'Off: favicon upload and multipart forms will fail.',
        'fix_da' => 'PHP Settings -> set file_uploads = On.',
        'fix_ssh' => 'php.ini: file_uploads = On, then restart PHP-FPM.',
    ];
    $dataOk = is_dir(DATA_DIR) && is_writable(DATA_DIR);
    $checks[] = [
        'id' => 'data', 'label' => 'data/ writable',
        'value' => realpath(DATA_DIR) ?: DATA_DIR,
        'status' => $dataOk ? 'ok' : 'bad',
        'detail' => $dataOk ? 'Object files and the SQLite database can be created.' : 'PHP cannot write here - installation cannot proceed.',
        'fix_da' => 'File Manager -> right-click data/ -> Change Permissions -> 775 (PHP runs as your user on most DirectAdmin hosts).',
        'fix_ssh' => 'chown -R www-data:www-data ' . DATA_DIR . ' && chmod 770 ' . DATA_DIR . '  (use the FPM pool user instead of www-data if different).',
    ];
    $free = @disk_free_space(DATA_DIR);
    $checks[] = [
        'id' => 'disk', 'label' => 'Disk free',
        'value' => $free === false ? 'unknown' : number_format($free / 1073741824, 1) . ' GB',
        'status' => ($free === false || $free < 1073741824) ? 'warn' : 'ok',
        'detail' => 'Free space where objects are stored.',
        'fix_da' => 'Free disk space or ask the host to raise the account quota.',
        'fix_ssh' => 'df -h ' . DATA_DIR . ' to inspect; free space or mount more storage.',
    ];
    return $checks;
}

if (($_GET['checks'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['checks' => install_checks()]);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}
if (!is_dir(UPLOADS_DIR)) {
    @mkdir(UPLOADS_DIR, 0775, true);
}
@file_put_contents(DATA_DIR . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");

$st = db()->prepare('SELECT id FROM admin WHERE id = 1');
$st->execute();
$installed = $st->fetch() !== false;

$err = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($installed) {
        $err = 'Already installed. Delete the admin row or use the Settings tab to change the password.';
    } else {
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        $username = trim((string)($_POST['username'] ?? 'admin')) ?: 'admin';
        if (preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $username) !== 1 || $username === '.' || $username === '..') {
            $err = 'Invalid username (letters, digits, . _ -; max 64 chars).';
        } elseif (strlen($p1) < 8) {
            $err = 'Password must be at least 8 characters.';
        } elseif ($p1 !== $p2) {
            $err = 'Passwords do not match.';
        } else {
            $st = db()->prepare('INSERT INTO admin (id, username, password_hash) VALUES (1, ?, ?)');
            $st->execute([$username, password_hash($p1, PASSWORD_DEFAULT)]);
            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#eef1f6">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0a1120">
<title><?= htmlspecialchars(app_name()) ?> - Install</title>
<link rel="icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script>
/* Set the theme before first paint so the page never flashes the wrong
   canvas. Resolution order mirrors the toggle logic below; keep in sync. */
(function(){try{var s=localStorage.getItem('minis3_theme');if(s!=='light'&&s!=='dark'){s=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',s);}catch(e){}})();
</script>
<style>
:root{
    color-scheme:dark;
    --primary:#d38a4d; --on-primary:#2a1305;
    --primary-container:#3d2c1a; --on-primary-container:#f0c48d;
    --secondary-container:#173b37; --on-secondary-container:#b6f0ea;
    --surface:#0a1120; --surface-1:#101a2c; --surface-2:#0c1626;
    --on-surface:#eaf0f8; --on-surface-var:#8b98b0;
    --outline:#33445f; --outline-var:#1e2c44;
    --error:#ef5f5f; --error-container:#3d1d1d; --on-error-container:#ffb3b3;
    --ok:#3ed399; --ok-container:#14382a; --on-ok-container:#a8f2d6;
    --shadow-1:0 1px 2px rgba(0,0,0,.4),0 1px 3px 1px rgba(0,0,0,.3);
    --shadow-2:0 8px 28px rgba(0,0,0,.45),0 2px 6px 2px rgba(0,0,0,.35);
    --ease-standard:cubic-bezier(.2,0,.2,1);
}
[data-theme="light"]{
    color-scheme:light;
    --primary:#b26a2a; --on-primary:#ffffff;
    --primary-container:#f3ddc2; --on-primary-container:#4a2c10;
    --secondary-container:#cdebe8; --on-secondary-container:#0b3f3a;
    --surface:#eef1f6; --surface-1:#ffffff; --surface-2:#f4f6fa;
    --on-surface:#121a29; --on-surface-var:#55627a;
    --outline:#b9c2d2; --outline-var:#d7dee9;
    --error:#b3261e; --error-container:#f9dedc; --on-error-container:#410002;
    --ok:#10714c; --ok-container:#c9efda; --on-ok-container:#072711;
    --shadow-1:0 1px 2px rgba(16,30,55,.10),0 1px 3px 1px rgba(16,30,55,.06);
    --shadow-2:0 8px 28px -10px rgba(16,30,55,.18),0 2px 6px 2px rgba(16,30,55,.08);
}
*{box-sizing:border-box}
body{
    margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
    font-family:'Space Grotesk',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;
    background:var(--surface);color:var(--on-surface);font-size:14px;line-height:1.5;
    transition:background-color .25s var(--ease-standard),color .25s var(--ease-standard);
}
.card{
    background:var(--surface-1);border-radius:28px;padding:32px;max-width:420px;width:100%;
    box-shadow:var(--shadow-1);animation:in .3s var(--ease-standard);
}
@keyframes in{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.login-logo{
    width:60px;height:60px;border-radius:18px;background:var(--primary);color:var(--on-primary);
    display:flex;align-items:center;justify-content:center;margin:0 auto 16px;box-shadow:var(--shadow-2);
}
h1{font-size:24px;font-weight:400;text-align:center;margin:0 0 4px}
.sub{color:var(--on-surface-var);text-align:center;margin:0 0 12px;font-size:13.5px}
.tf{position:relative;margin:18px 0}
.tf>input{
    width:100%;height:52px;padding:15px;font-size:14.5px;color:var(--on-surface);
    background:transparent;border:1px solid var(--outline);border-radius:10px;outline:none;
    font-family:inherit;transition:border-color .15s var(--ease-standard),box-shadow .15s var(--ease-standard);
}
.tf>input:focus{border-color:var(--primary);box-shadow:inset 0 0 0 1px var(--primary)}
.tf>label{
    position:absolute;left:11px;top:15px;font-size:14.5px;color:var(--on-surface-var);
    padding:0 5px;background:transparent;pointer-events:none;transition:all .15s var(--ease-standard);
}
.tf>input:focus+label,.tf>input:not(:placeholder-shown)+label{top:-9px;font-size:12px;background:var(--surface-1)}
.tf>input:focus+label{color:var(--primary)}
.btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;height:44px;width:100%;
    border:0;border-radius:999px;background:var(--primary);color:var(--on-primary);
    font-size:14px;font-weight:500;letter-spacing:.1px;cursor:pointer;font-family:inherit;margin-top:8px;
    transition:box-shadow .15s var(--ease-standard);-webkit-tap-highlight-color:transparent;
}
.btn:hover{box-shadow:var(--shadow-1)}
.error{
    display:flex;gap:8px;align-items:flex-start;background:var(--error-container);color:var(--on-error-container);
    border-radius:12px;padding:12px 14px;font-size:13px;margin-top:14px;
}
.ok-box{
    display:flex;flex-direction:column;gap:14px;background:var(--ok-container);color:var(--on-ok-container);
    border-radius:16px;padding:18px;text-align:center;font-size:14px;
}
.ok-box svg{margin:0 auto}
ul{margin:0;padding-left:18px;color:var(--on-surface-var);font-size:13.5px}
li{margin:6px 0}
a{color:var(--primary);font-weight:500}
code{background:var(--surface-2);border-radius:6px;padding:2px 7px;font-size:12px;font-family:ui-monospace,Consolas,monospace}
:focus-visible{outline:2px solid var(--primary);outline-offset:2px}

/* field-instrument pass */
body{font-family:'Space Grotesk',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background-image:linear-gradient(rgba(96,150,214,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(96,150,214,.07) 1px,transparent 1px);background-size:28px 28px;background-attachment:fixed}
[data-theme="light"] body{background-image:linear-gradient(rgba(51,90,148,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(51,90,148,.06) 1px,transparent 1px)}
h1{letter-spacing:-.01em}
.card{border:1px solid var(--outline-var);border-radius:16px}
.login-logo{background:linear-gradient(155deg,#d38a4d,#a85f28) !important;color:#2a1305 !important;box-shadow:none !important;border-radius:12px !important}
.btn{border-radius:6px}
.tf>input{font-family:'IBM Plex Mono',ui-monospace,'SFMono-Regular',Menlo,Consolas,'Courier New',monospace}
.tf>input:focus{box-shadow:0 0 0 3px rgba(211,138,77,.18)}
code{font-family:'IBM Plex Mono',ui-monospace,'SFMono-Regular',Menlo,Consolas,'Courier New',monospace}
::selection{background:rgba(211,138,77,.25)}
body{flex-direction:column;gap:16px;justify-content:center;padding:24px 20px}
.wrap{display:flex;gap:16px;align-items:flex-start;justify-content:center;width:100%;max-width:1020px}
.wrap>.card{margin:0;min-width:0}
.wrap>.card.wide{flex:1 1 560px}
.wrap>.card:not(.wide){flex:0 1 420px}
#checkList{max-height:46vh;overflow-y:auto;padding-right:6px;overscroll-behavior:contain}
#checkList::-webkit-scrollbar{width:8px}
#checkList::-webkit-scrollbar-thumb{background:var(--outline-var);border-radius:8px}
@media(max-width:920px){
.wrap{flex-direction:column;align-items:stretch}
.wrap>.card:not(.wide){flex-basis:auto}
#checkList{max-height:300px}
}
.card.wide{max-width:560px;text-align:left}
.card.wide h1{text-align:left}
.verdict{border-radius:10px;padding:10px 14px;font-size:13.5px;margin:0 0 12px;border:1px solid transparent}
.verdict.ok{background:rgba(62,211,153,.1);border-color:rgba(62,211,153,.35);color:var(--ok)}
.verdict.bad{background:rgba(239,95,95,.1);border-color:rgba(239,95,95,.35);color:var(--error)}
[data-theme="light"] .verdict.ok{color:#10714c}
[data-theme="light"] .verdict.bad{color:#a92b2b}
.chk{display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-top:1px solid var(--outline-var);font-size:13px}
.chk:first-of-type{border-top:0}
.chk .st{flex:none;margin-top:1px}
.chk b{display:block;font-size:13px}
.chk .val{font-family:'IBM Plex Mono',ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:var(--on-surface-var);overflow-wrap:anywhere}
.chk .det{margin:4px 0 0;font-size:12px;color:var(--on-surface-var)}
.chk .fix{margin:6px 0 0;font-size:12px;display:grid;gap:4px}
.chk .fix div{background:var(--surface-2);border:1px solid var(--outline-var);border-radius:8px;padding:6px 9px}
.chk .fix b{display:inline;font-size:12px;color:var(--primary)}
.chk .fix code{overflow-wrap:anywhere}
.st{display:inline-block;border:1px solid var(--outline-var);border-radius:999px;padding:1px 9px;font-size:11px;font-weight:700;white-space:nowrap}
.st-ok{color:var(--ok);border-color:rgba(62,211,153,.4);background:rgba(62,211,153,.1)}
.st-warn{color:#eab35a;border-color:rgba(234,179,90,.4);background:rgba(234,179,90,.1)}
.st-bad{color:var(--error);border-color:rgba(239,95,95,.4);background:rgba(239,95,95,.1)}
.st-info{color:var(--on-surface-var)}
[data-theme="light"] .st-ok{color:#10714c}
[data-theme="light"] .st-warn{color:#8a5b06}
[data-theme="light"] .st-bad{color:#a92b2b}
.pwmeter{height:6px;background:var(--surface-2);border:1px solid var(--outline-var);border-radius:99px;overflow:hidden;margin:-8px 0 14px}
.pwmeter div{height:100%;width:0;transition:width .2s}
.pwmeter div.weak{background:var(--error)}
.pwmeter div.fair{background:#eab35a}
.pwmeter div.good{background:var(--primary)}
.pwmeter div.strong{background:var(--ok)}
.pwhint{font-size:12px;color:var(--on-surface-var);margin:-12px 0 12px}
.rowbtns{display:flex;gap:8px;margin-top:12px}
.rowbtns .btn{margin-top:0}
.btn-ghost{background:transparent;border:1px solid var(--outline-var);color:var(--on-surface)}

</style>
</head>
<body>
<div class="wrap">
<div class="card wide">
  <h1>Server preflight</h1>
  <p class="sub" style="text-align:left">PHP, extensions, limits and permissions this panel needs. Fix reds before installing.</p>
  <div id="preVerdict"></div>
  <div id="checkList"></div>
  <div class="rowbtns">
    <button type="button" class="btn btn-ghost" id="recheckBtn" style="width:auto;padding:0 18px">Re-check</button>
  </div>
</div>
<div class="card">
  <div class="login-logo">
    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14l2 12H3z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/><path d="M3 19h18"/></svg>
  </div>
  <h1><?= htmlspecialchars(app_name()) ?> - Install</h1>
  <?php if ($done): ?>
    <div style="height:14px"></div>
    <div class="ok-box">
      <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>Installation complete. The admin account is ready.</span>
    </div>
    <ul>
      <li>Open <a href="admin/">/admin/</a> and sign in with this password.</li>
      <li>Create an S3 user to get an access key and secret key.</li>
      <li>Delete <code>install.php</code> from the server.</li>
    </ul>
  <?php elseif ($installed): ?>
    <div style="height:14px"></div>
    <div class="error">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span>Already installed. Delete the admin row from the database or change the password in the admin Settings tab.</span>
    </div>
    <p class="sub" style="margin-top:16px"><a href="admin/">Go to admin</a></p>
  <?php else: ?>
    <p class="sub">Set the admin panel username and password. You will use them to log in at <code>/admin/</code> and manage users, buckets and logs.</p>
    <form method="post">
      <div class="tf">
        <input type="text" name="username" id="iuser" value="admin" required pattern="[A-Za-z0-9._\-]{1,64}" autocomplete="off" placeholder=" ">
        <label for="iuser">Username</label>
      </div>
      <div class="tf">
        <input type="password" name="password" id="ipass" required minlength="8" autocomplete="new-password" placeholder=" ">
        <label for="ipass">Password (min 8 characters)</label>
      </div>
      <div class="pwmeter" id="ipwMeter"><div></div></div>
      <div class="pwhint" id="ipwHint">Use 12+ characters with mixed case, digits and symbols.</div>
      <div class="tf">
        <input type="password" name="password2" id="ipass2" required minlength="8" autocomplete="new-password" placeholder=" ">
        <label for="ipass2">Repeat password</label>
      </div>
      <button type="submit" class="btn">Install</button>
      <?php if ($err): ?>
        <div class="error">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span><?= htmlspecialchars($err) ?></span>
        </div>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>
</div>
<script>
try {
    var s = localStorage.getItem('minis3_theme');
    if (s !== 'light' && s !== 'dark') s = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    if (s) document.documentElement.dataset.theme = s;
} catch (e) {}
function escH(t) {
    return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
}
function renderChecks(list) {
    var host = document.getElementById('checkList');
    host.innerHTML = '';
    var bad = 0, warn = 0;
    list.forEach(function (c) {
        if (c.status === 'bad') bad++;
        if (c.status === 'warn') warn++;
        var pill = c.status === 'ok' ? 'st-ok' : (c.status === 'warn' ? 'st-warn' : (c.status === 'bad' ? 'st-bad' : 'st-info'));
        var html = '<div class="chk"><span class="st ' + pill + '">' + escH(c.status.toUpperCase()) + '</span><div>' +
            '<b>' + escH(c.label) + '</b><span class="val">' + escH(c.value) + '</span>' +
            '<p class="det">' + escH(c.detail) + '</p>';
        if (c.status === 'warn' || c.status === 'bad') {
            html += '<div class="fix"><div><b>DirectAdmin:</b> ' + escH(c.fix_da) + '</div>' +
                '<div><b>SSH:</b> <code>' + escH(c.fix_ssh) + '</code></div></div>';
        }
        host.insertAdjacentHTML('beforeend', html + '</div></div>');
    });
    var v = document.getElementById('preVerdict');
    if (bad > 0) {
        v.className = 'verdict bad';
        v.textContent = bad + ' blocking problem' + (bad === 1 ? '' : 's') + ' found - fix the red rows, then Re-check.' + (warn ? ' ' + warn + ' warning(s).' : '');
    } else if (warn > 0) {
        v.className = 'verdict bad';
        v.textContent = 'No blockers, but ' + warn + ' thing(s) need attention below.';
    } else {
        v.className = 'verdict ok';
        v.textContent = 'All checks pass - safe to install.';
    }
}
async function loadChecks() {
    var btn = document.getElementById('recheckBtn');
    btn.disabled = true;
    btn.textContent = 'Checking...';
    try {
        var r = await fetch('install.php?checks=1', { cache: 'no-store' });
        var d = await r.json();
        renderChecks(d.checks || []);
    } catch (e) {
        document.getElementById('preVerdict').className = 'verdict bad';
        document.getElementById('preVerdict').textContent = 'Could not run checks: ' + e.message;
    }
    btn.disabled = false;
    btn.textContent = 'Re-check';
}
document.getElementById('recheckBtn').addEventListener('click', loadChecks);
loadChecks();
function pwScore(pw) {
    pw = String(pw || '');
    if (!pw) return { pct: 0, label: '' };
    var classes = (/[a-z]/.test(pw) ? 1 : 0) + (/[A-Z]/.test(pw) ? 1 : 0) + (/[0-9]/.test(pw) ? 1 : 0) + (/[^A-Za-z0-9]/.test(pw) ? 1 : 0);
    var pts = Math.min(40, pw.length * 3) + classes * 12;
    if (/^(password|admin|minis3|12345678|qwerty|letmein|welcome).*/i.test(pw)) pts = Math.min(pts, 15);
    var pct = Math.max(4, Math.min(100, Math.round(pts)));
    return { pct: pct, label: pct < 35 ? 'Weak' : (pct < 65 ? 'Fair' : (pct < 85 ? 'Good' : 'Strong')) };
}
(function () {
    var inp = document.getElementById('ipass');
    if (!inp) return;
    var meter = document.getElementById('ipwMeter');
    var hint = document.getElementById('ipwHint');
    inp.addEventListener('input', function () {
        var sc = pwScore(inp.value);
        var bar = meter.querySelector('div');
        bar.style.width = sc.pct + '%';
        bar.className = sc.pct < 35 ? 'weak' : (sc.pct < 65 ? 'fair' : (sc.pct < 85 ? 'good' : 'strong'));
        hint.textContent = inp.value ? ('Strength: ' + sc.label + (inp.value.length < 12 ? ' - aim for 12+ characters.' : '')) : 'Use 12+ characters with mixed case, digits and symbols.';
    });
})();
</script>
</body>
</html>
