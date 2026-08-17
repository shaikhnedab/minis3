<?php
// Reset the admin panel username / password (and optionally disable 2FA).
// CLI only - run from the app root:  php tools/reset-admin.php
// A web request to this file refuses to do anything.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require __DIR__ . '/../config.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/util.php';

db_init();

$pdo = db();
$row = $pdo->query('SELECT username, totp_secret FROM admin WHERE id = 1')->fetch();
if ($row === false) {
    fwrite(STDERR, "No admin account found. Run install.php first.\n");
    exit(1);
}

echo "MiniS3 admin reset\n";
echo "Current admin username: " . $row['username'] . "\n\n";

function ask(string $prompt): string
{
    echo $prompt;
    $v = fgets(STDIN);
    return $v === false ? '' : rtrim($v, "\r\n");
}

$username = trim(ask("New username [{$row['username']}]: "));
if ($username === '') {
    $username = $row['username'];
}
if (preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $username) !== 1 || $username === '.' || $username === '..') {
    fwrite(STDERR, "Invalid username (letters, digits, . _ -; max 64 chars).\n");
    exit(1);
}

$p1 = ask("New password (min 8 chars): ");
$p2 = ask("Repeat new password: ");
if (strlen($p1) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
if ($p1 !== $p2) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$totp = (string)$row['totp_secret'] !== '';
$clearTotp = false;
if ($totp) {
    $a = strtolower(trim(ask("Two-factor authentication is enabled. Clear it so you can log in? [Y/n]: ")));
    $clearTotp = $a === '' || $a === 'y' || $a === 'yes';
} else {
    echo "Two-factor authentication is not enabled.\n";
}

$st = $pdo->prepare('UPDATE admin SET username = ?, totp_secret = ?, password_hash = ? WHERE id = 1');
$st->execute([$username, $clearTotp ? null : $row['totp_secret'], password_hash($p1, PASSWORD_DEFAULT)]);

echo "\nDone. Sign in at /admin/ with username '{$username}'";
echo $clearTotp ? " (two-factor cleared).\n" : ".\n";
exit(0);
