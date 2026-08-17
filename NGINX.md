# MiniS3 on nginx with PHP 8.5 - installation guide

Guide for a VPS / dedicated server running nginx + PHP 8.5 FPM (Debian,
Ubuntu, AlmaLinux/Rocky and similar). Assumes root access. Takes ~15 minutes.

---

## 1. Install nginx and PHP 8.5

**Debian / Ubuntu** - PHP 8.5 comes from the Sury PPA (Ubuntu) or Debian
unstable/sid packages (Debian 13 "trixie" ships 8.4 - add Sury for 8.5):

```bash
# Ubuntu
apt update
apt install -y lsb-release ca-certificates curl gnupg
curl -sSLo /tmp/php.gpg https://packages.sury.org/php/apt.gpg
install -m 644 /tmp/php.gpg /etc/apt/trusted.gpg.d/php.gpg
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
apt update

apt install -y nginx php8.5-fpm php8.5-sqlite3 php8.5-cli
php8.5 -v        # verify 8.5.x
```

**AlmaLinux / Rocky Linux** - PHP 8.5 from the Remi repository:

```bash
dnf install -y epel-release
dnf install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %rhel).rpm
dnf module reset php -y
dnf module enable php:remi-8.5 -y
dnf install -y nginx php-fpm php-cli php-pdo_sqlite php-sqlite3
php -v           # verify 8.5.x
```

Check the FPM socket exists (the socket path depends on the distro/PHP
build - this guide uses `/run/php/php8.5-fpm.sock`):

```bash
ls /run/php/php8.5-fpm.sock
```

## 2. Upload the code

```bash
mkdir -p /var/www/minis3
# upload the minis3/ contents here (scp, rsync, git, ...)
# e.g.: scp -r minis3/* root@YOUR-SERVER:/var/www/minis3/
```

Result:

```
/var/www/minis3/
|-- .htaccess       (Apache-only; harmless on nginx, can be deleted)
|-- index.php
|-- install.php
|-- config.php
|-- router.php
|-- admin/
|-- lib/
|-- data/
|-- tools/          (optional - reset-admin.php, CLI admin password reset;
|                    web access is denied by the bundled nginx.conf)
```

## 3. Configure nginx

The bundled `nginx.conf` ships with a full TLS setup: a port-80 server that
redirects to HTTPS and a port-443 server with certificate placeholders and
TLS hardening.

Copy it and edit the `CHANGE THIS` lines:

```bash
cp /var/www/minis3/nginx.conf /etc/nginx/conf.d/s3.conf
nano /etc/nginx/conf.d/s3.conf
```

- `server_name s3.example.com` -> your domain (or IP)
- `root /var/www/minis3;` -> your path (or leave as-is)
- `fastcgi_pass unix:/run/php/php8.5-fpm.sock;` -> your FPM socket
  (on AlmaLinux it is typically `/run/php-fpm/www.sock` - run
  `php-fpm -t` or `grep -r listen /etc/php-fpm.d/` to find it)

The two `ssl_certificate*` lines point at Let's Encrypt paths that do not
exist yet. **If you run `nginx -t` before issuing certificates, comment out
those two lines first** (step 6's certbot fills them back in):

```bash
# ssl_certificate     /etc/letsencrypt/live/s3.example.com/fullchain.pem;
# ssl_certificate_key /etc/letsencrypt/live/s3.example.com/privkey.pem;
```

Test and reload:

```bash
nginx -t && systemctl reload nginx
```

If you use a full `server_name` like `s3.example.com`, point a DNS A record
at the server first, or nginx will refuse to serve requests for it.

## 4. Permissions

PHP-FPM runs as `www-data` (Debian/Ubuntu) or `apache` (AlmaLinux). Make the
app root readable and `data/` writable by that user:

```bash
chown -R www-data:www-data /var/www/minis3   # or apache:apache on AlmaLinux
chmod -R u+rwX,go+rX /var/www/minis3
chmod 770 /var/www/minis3/data               # objects + SQLite DB are written here
```

(If the default pool uses another user - `grep -r '^user' /etc/php/*/fpm/pool.d/`
- use that instead.)

## 5. Install

1. `systemctl start nginx php8.5-fpm` (if not already running) and
   `systemctl enable nginx php8.5-fpm`.
2. Open `http://YOUR-IP-OR-DOMAIN/install.php` (or the https URL from step 6),
   set the admin password. The sample nginx config has a `location = /install.php`
   block that runs the installer; once you delete the file it returns 404.
3. **Delete `install.php` from the server.**
4. Open `/admin/`, sign in, **Users** -> **+ Add user**, note the keys.

## 6. TLS (recommended)

```bash
apt install -y certbot python3-certbot-nginx   # or: dnf install certbot python3-certbot-nginx
certbot --nginx -d s3.example.com
```

certbot detects the port-443 server block in `s3.conf` and fills in the two
`ssl_certificate*` lines with the real Let's Encrypt paths. If you commented
them out in step 3, certbot adds its own lines - either way works.

After issuing, uncomment (if needed), verify TLS with `openssl` and reload:

```bash
nginx -t && systemctl reload nginx
openssl s_client -connect s3.example.com:443 -servername s3.example.com </dev/null 2>/dev/null | grep -E "subject|issuer"
```

Then check the HTTP->HTTPS redirect: `curl -I http://s3.example.com` should
return `301` to the https URL. Renewal is automatic via a certbot
systemd timer (`systemctl list-timers | grep certbot`).

Note: the sample enables HTTP/2 (`http2 on;`, nginx >= 1.25.1). On older
nginx builds use `listen 443 ssl http2;` instead.

## 7. Test with an S3 client

```
# rclone
rclone config create s3 s3 provider Other endpoint https://s3.example.com \
  access_key_id AKIA... secret_access_key ... region us-east-1 \
  force_path_style true

# aws cli
aws configure set default.region us-east-1
aws configure set default.s3.addressing_style path
aws --endpoint-url https://s3.example.com s3 ls
aws --endpoint-url https://s3.example.com s3 cp file.txt s3://my-bucket/
```

## 8. Sizing for large uploads

- `client_max_body_size 10G` in `s3.conf` - raise if you store larger objects.
- PHP-FPM has no body-size limit (the S3 API streams `php://input`), but
  `request_terminate_timeout` in the FPM pool defaults to 0 (unlimited) -
  leave it, or set it high (600s) if you want a safety net.
- `fastcgi_read_timeout 600s` covers slow uploads/downloads; large downloads
  stream straight from disk (`set_time_limit(0)` inside the app).
- nginx buffers `client_body_buffer_size` (default 16k) to temp files for big
  bodies - make sure the temp dir (`/var/lib/nginx`) has free space, or set
  `client_body_buffer_size 64k` to force more to disk early.

Output compression: leave nginx `gzip` **off** (the sample config does) and
keep `zlib.output_compression = Off` in your PHP-FPM `php.ini` (the app also
disables it at runtime). Compression strips `Content-Length` from streamed
downloads and corrupts video/audio previews - the same symptoms as on Apache
hosts where mod_deflate is enabled.

## 9. Backups

```bash
# full backup of app + database (stop writes for a consistent snapshot):
tar czf s3-backup.tar.gz /var/www/minis3
```

For live copies, copy `data/app.sqlite` together with
`data/app.sqlite-wal` and `data/app.sqlite-shm` (WAL journal), or checkpoint
first:

```bash
php8.5 -r '$db = new PDO("sqlite:/var/www/minis3/data/app.sqlite"); $db->exec("PRAGMA wal_checkpoint(TRUNCATE);");'
```

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| `nginx: [warn] conflicting server name` | Another vhost already uses that name; pick a unique domain |
| `502 Bad Gateway` | FPM socket path is wrong or php-fpm not running; check `ls /run/php/`, `systemctl status php8.5-fpm` |
| `404` for `/admin/` | The `location = /admin` / `location /admin/` blocks are missing; re-copy nginx.conf |
| `403` on `/admin/api.php` | `data/`/`lib/` deny block is too broad - the `~ ^/(data\|lib\|tests)` regex must not match `admin`; it doesn't by default |
| `SignatureDoesNotMatch` | Keys differ from the server's; re-copy from the admin panel. Server clock wrong - run `timedatectl set-ntp true` (skew limit 15 min, `MAX_SKEW` in config.php) |
| Big upload fails with `413` | `client_max_body_size` too small for the object |
| Slow uploads / timeouts | `fastcgi_read_timeout` and `client_body_timeout`; also check disk space for nginx temp buffer |
| `install.php` shows an S3 XML `AccessDenied` error | The installer is being routed to `index.php` (S3 API) - the `location = /install.php` block is missing; re-copy nginx.conf |
| Can't reach the installer | It was deleted (good); or on an existing install the `install.php` page says "Already installed" |
| Bucket named `admin` unreachable | `admin` is reserved by the admin panel routes - rename the bucket |
| `AccessDenied: x-amz-date` | Client doesn't send `x-amz-date`; use a real S3 client (WinSCP S3, rclone, aws cli) |
| Downloads show no file size / video preview won't play | nginx `gzip` or PHP `zlib.output_compression` is enabled - turn them off (section 8) |
| Forgot the admin username / password | From a shell in the app root run `php tools/reset-admin.php` - it sets a new username/password and clears two-factor authentication if enabled |

## 11. Security checklist

- [ ] `install.php` deleted
- [ ] HTTPS enabled with certbot; SigV4 signs every request, so plain HTTP
      lets anyone capture and replay requests
- [ ] `data/` not web-accessible - verify:
      `curl -I http://s3.example.com/data/app.sqlite` -> 403
- [ ] `nginx.conf`'s `location /` sends everything to `index.php`, so object
      keys can never be served as static files - don't add `try_files` there
- [ ] Strong admin password; secret keys regenerated in the admin panel if
      they leak
- [ ] Logs tab: disable S3/admin logging in **Settings** if the log table
      grows too fast
