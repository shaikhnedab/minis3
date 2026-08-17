<?php
// Web-based admin password reset for hosts without shell access.
//
// Activation (site owner only):
//   1. Create an empty file:  data/reset.enabled   (via FTP / File Manager)
//   2. Open  /reset.php  in a browser and set a new username/password.
//   3. The marker file is deleted automatically after a successful reset.
//
// data/ is web-denied (Apache .htaccess, nginx.conf, router.php), so web
// visitors cannot create the marker - only someone with file access to the
// server (the site owner) can enable the reset page. Without the marker the
// page refuses to do anything.

declare(strict_types=1);

require __DIR__ . '/config.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/util.php';

db_init();

$marker = DATA_DIR . '/reset.enabled';
$active = is_file($marker);

$err = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$active) {
        $err = 'Reset is disabled. Create the file data/reset.enabled via FTP / File Manager, then try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $username) !== 1 || $username === '.' || $username === '..') {
            $err = 'Invalid username (letters, digits, . _ -; max 64 chars).';
        } elseif (strlen($p1) < 8) {
            $err = 'Password must be at least 8 characters.';
        } elseif ($p1 !== $p2) {
            $err = 'Passwords do not match.';
        } else {
            $clearTotp = !empty($_POST['clear_totp']) ? null : (string)db()->query('SELECT totp_secret FROM admin WHERE id = 1')->fetchColumn();
            $st = db()->prepare('UPDATE admin SET username = ?, totp_secret = ?, password_hash = ? WHERE id = 1');
            $st->execute([$username, $clearTotp, password_hash($p1, PASSWORD_DEFAULT)]);
            @unlink($marker);
            $done = true;
        }
    }
}

if ($done) {
    $username = trim((string)($_POST['username'] ?? ''));
}

$adminRow = db()->query('SELECT username, totp_secret FROM admin WHERE id = 1')->fetch();
$currentUser = is_array($adminRow) ? (string)$adminRow['username'] : 'admin';
$totpOn = is_array($adminRow) && (string)$adminRow['totp_secret'] !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars(app_name()) ?> - Admin reset</title>
<style>
:root{color-scheme:light;--surface:#F9F9FF;--surface-1:#FFFFFF;--on-surface:#191C22;--on-surface-var:#44474E;
    --primary:#0B57D0;--on-primary:#FFFFFF;--outline:#74777F;--error:#BA1A1A;--error-container:#FFDAD6;--on-error-container:#410002;
    --ok:#146C2E;--ok-container:#C6F0D2;--on-ok-container:#072711;--warn:#8F5000;--warn-container:#FFDCBE;--on-warn-container:#2E1500}
[data-theme="dark"]{color-scheme:dark;--surface:#111318;--surface-1:#1B1E24;--on-surface:#E3E2E9;--on-surface-var:#C4C6D0;
    --primary:#A8C7FA;--on-primary:#062E6F;--outline:#8E9099;--error:#FFB4AB;--error-container:#93000A;--on-error-container:#FFDAD6;
    --ok:#6DD58C;--ok-container:#0F5223;--on-ok-container:#C6F0D2;--warn:#FFB868;--warn-container:#6B3D00;--on-warn-container:#FFDCBE}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
    font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:var(--surface);color:var(--on-surface);font-size:14px;line-height:1.5}
.card{background:var(--surface-1);border-radius:20px;padding:28px;max-width:420px;width:100%;box-shadow:0 1px 3px rgba(0,0,0,.15)}
h1{font-size:20px;font-weight:600;margin:0 0 4px}
.sub{color:var(--on-surface-var);margin:0 0 16px;font-size:13px}
.tf{position:relative;margin:14px 0}
.tf input{width:100%;height:48px;padding:12px;font-size:14px;color:var(--on-surface);background:transparent;
    border:1px solid var(--outline);border-radius:10px;outline:none;font-family:inherit}
.tf input:focus{border-color:var(--primary)}
.tf label{position:absolute;left:11px;top:14px;font-size:14px;color:var(--on-surface-var);padding:0 5px;background:var(--surface-1);
    pointer-events:none;transition:all .15s}
.tf input:focus+label,.tf input:not(:placeholder-shown)+label{top:-9px;font-size:11.5px}
.btn{display:block;width:100%;height:44px;border:0;border-radius:999px;background:var(--primary);color:var(--on-primary);
    font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:8px}
label.check{display:flex;align-items:center;gap:8px;margin:10px 0;cursor:pointer}
label.check input{width:17px;height:17px;accent-color:var(--primary)}
.box{border-radius:12px;padding:12px 14px;font-size:13px;margin:14px 0}
.box.err{background:var(--error-container);color:var(--on-error-container)}
.box.ok{background:var(--ok-container);color:var(--on-ok-container)}
.box.warn{background:var(--warn-container);color:var(--on-warn-container)}
code{background:rgba(127,127,127,.15);border-radius:6px;padding:2px 7px;font-size:12px}
</style>
</head>
<body>
<div class="card">
  <h1><?= htmlspecialchars(app_name()) ?> - Admin reset</h1>
  <p class="sub">Set a new admin username and password for /admin/.</p>
  <?php if (!$active): ?>
    <div class="box warn">
      <b>Reset is disabled.</b> To enable it, create an empty file named
      <code>data/reset.enabled</code> on the server (FTP or File Manager -
      <code>data/</code> is not reachable from the web, so only the site owner
      can do this), then reload this page.
    </div>
  <?php elseif ($done): ?>
    <div class="box ok">
      <b>Done.</b> The admin account is now
      <code><?= htmlspecialchars($username) ?></code> with the password you
      chose<?= !empty($_POST['clear_totp']) ? ' (two-factor authentication cleared)' : '' ?>.
      The <code>data/reset.enabled</code> marker was deleted, so this page is
      disabled again. <a href="admin/">Go to /admin/ and sign in</a>.
    </div>
  <?php else: ?>
    <div class="box warn">
      Reset page is active (marker file present). Current admin username:
      <code><?= htmlspecialchars($currentUser) ?></code><?= $totpOn ? ' - two-factor authentication is enabled and will be cleared.' : '' ?>
    </div>
    <?php if ($err): ?>
      <div class="box err"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="tf">
        <input type="text" name="username" id="iuser" value="<?= htmlspecialchars($currentUser) ?>" required
          pattern="[A-Za-z0-9._\-]{1,64}" autocomplete="off" placeholder=" ">
        <label for="iuser">Username</label>
      </div>
      <div class="tf">
        <input type="password" name="password" id="ipass" required minlength="8" autocomplete="new-password" placeholder=" ">
        <label for="ipass">New password (min 8 characters)</label>
      </div>
      <div class="tf">
        <input type="password" name="password2" id="ipass2" required minlength="8" autocomplete="new-password" placeholder=" ">
        <label for="ipass2">Repeat new password</label>
      </div>
      <label class="check"><input type="checkbox" name="clear_totp" value="1" <?= $totpOn ? 'checked' : '' ?>>
        Clear two-factor authentication (needed if you lost the authenticator)</label>
      <button type="submit" class="btn">Reset admin account</button>
    </form>
  <?php endif; ?>
</div>
<script>
try { if (localStorage.getItem('minis3_theme') === 'dark') document.documentElement.dataset.theme = 'dark'; } catch (e) {}
</script>
</body>
</html>