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
    var s = localStorage.getItem('minis3_theme');
    if (s !== 'light' && s !== 'dark') s = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    if (s) document.documentElement.dataset.theme = s;
} catch (e) {}
</script>
</body>
</html>
