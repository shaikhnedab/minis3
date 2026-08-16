<?php
// MiniS3 installer - run once in the browser, then delete this file.

declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/lib/util.php';
require APP_ROOT . '/lib/db.php';

db_init();

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
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#F9F9FF">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#111318">
<title><?= htmlspecialchars(app_name()) ?> - Install</title>
<link rel="icon" href="/favicon.ico">
<style>
:root{
    color-scheme:light;
    --primary:#0B57D0; --on-primary:#FFFFFF;
    --primary-container:#D3E3FD; --on-primary-container:#041E49;
    --secondary-container:#DAE2F9; --on-secondary-container:#131B2C;
    --surface:#F9F9FF; --surface-1:#FFFFFF; --surface-2:#EEF0F8;
    --on-surface:#191C22; --on-surface-var:#44474E;
    --outline:#74777F; --outline-var:#C4C6D0;
    --error:#BA1A1A; --error-container:#FFDAD6; --on-error-container:#410002;
    --ok:#146C2E; --ok-container:#C6F0D2; --on-ok-container:#072711;
    --shadow-1:0 1px 2px rgba(9,17,30,.10),0 1px 3px 1px rgba(9,17,30,.06);
    --shadow-2:0 1px 2px rgba(9,17,30,.12),0 2px 6px 2px rgba(9,17,30,.08);
    --ease-standard:cubic-bezier(.2,0,.2,1);
}
[data-theme="dark"]{
    color-scheme:dark;
    --primary:#A8C7FA; --on-primary:#062E6F;
    --primary-container:#0842A0; --on-primary-container:#D3E3FD;
    --secondary-container:#3F4759; --on-secondary-container:#DAE2F9;
    --surface:#111318; --surface-1:#1B1E24; --surface-2:#20242B;
    --on-surface:#E3E2E9; --on-surface-var:#C4C6D0;
    --outline:#8E9099; --outline-var:#44474E;
    --error:#FFB4AB; --error-container:#93000A; --on-error-container:#FFDAD6;
    --ok:#6DD58C; --ok-container:#0F5223; --on-ok-container:#C6F0D2;
    --shadow-1:0 1px 2px rgba(0,0,0,.5),0 1px 3px 1px rgba(0,0,0,.35);
    --shadow-2:0 1px 2px rgba(0,0,0,.55),0 2px 6px 2px rgba(0,0,0,.4);
}
*{box-sizing:border-box}
body{
    margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
    font-family:"Roboto Flex","Roboto",system-ui,-apple-system,"Segoe UI",Arial,sans-serif;
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
</style>
</head>
<body>
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
<script>
try {
    if (localStorage.getItem('minis3_theme') === 'dark') document.documentElement.dataset.theme = 'dark';
} catch (e) {}
</script>
</body>
</html>
