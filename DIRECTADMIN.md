# MiniS3 on DirectAdmin - installation guide

This guide walks through deploying MiniS3 on a typical DirectAdmin host
(Apache 2.4 + PHP, shared hosting style). It should take about 10 minutes.

---

## 1. Create the subdomain (or domain)

1. Log in to DirectAdmin.
2. **Domain Setup** -> add a subdomain, e.g. `s3.yourdomain.com`
   (or use a regular domain).
3. Enable SSL:
   - **Domain Setup** -> select the domain -> toggle **SSL** on if it is off.
   - Go to **SSL Certificates** -> **Let's Encrypt** tab, select the domain
     (and its aliases, if any) and click **Issue**.
   - DirectAdmin renews the certificate automatically on its cron, so no
     manual renewal is needed.
4. Force HTTPS (SigV4 signs every request - plain HTTP lets anyone capture
   and replay them). Add this to the top of your `.htaccess`, or check the
   panel's **Force SSL / SSL redirect** option if your host provides one:

   ```
   RewriteCond %{HTTPS} off
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

The web root will be something like:

```
/home/USERNAME/domains/s3.yourdomain.com/public_html
```

## 2. Upload the files

Upload the **contents of the `minis3/` folder** into that `public_html/`
(not a sub-folder):

- `index.php`, `install.php`, `config.php`, `.htaccess`, `router.php`
- `admin/` (index.php, api.php)
- `lib/` (util.php, db.php, log.php, auth.php, s3.php)
- `data/` (can be empty)
- `tests/` is optional - skip it or delete it

Use SFTP (FileZilla / WinSCP) or DirectAdmin's File Manager. If you use the
File Manager, make sure dot-files are visible so `.htaccess` is uploaded.

Verify the tree:

```
public_html/
|-- .htaccess
|-- index.php
|-- install.php
|-- config.php
|-- admin/
|   |-- index.php
|   `-- api.php
|-- lib/
|-- data/
```

## 3. PHP version and extensions

1. **Domain Setup** -> select the domain -> **Select PHP Version**
   (or the CloudLinux PHP Selector / custombuild PHP version).
2. Choose **PHP 8.1 or 8.2**.
3. Required extensions: `pdo_sqlite`, `sqlite3` - these are bundled in every
   DirectAdmin PHP build and are enabled by default. You can verify by opening
   `https://s3.yourdomain.com/admin/index.php` and, if it fails, checking with
   a temp `phpinfo()` file.

## 4. Install

1. Open `https://s3.yourdomain.com/install.php` in a browser.
2. Set the admin password (at least 8 chars), submit.
3. **Delete `install.php` from the server** (critical - otherwise anyone who
   finds it can reset your admin password if the database is ever wiped).

## 5. First login and test

1. Open `https://s3.yourdomain.com/admin/` and sign in.
2. **Users** tab -> **+ Add user** -> note the access key and secret key.
3. **Buckets** tab -> create a bucket for that user.
4. Test with an S3 client:

   rclone:
   ```
   [s3]
   type = s3
   provider = Other
   endpoint = https://s3.yourdomain.com
   access_key_id = AKIA...
   secret_access_key = ...
   region = us-east-1
   force_path_style = true
   ```
   ```
   rclone ls s3:my-bucket
   rclone copy file.txt s3:my-bucket/
   ```

   aws cli:
   ```
   aws configure set default.region us-east-1
   aws configure set default.s3.addressing_style path
   aws --endpoint-url https://s3.yourdomain.com s3 ls
   aws --endpoint-url https://s3.yourdomain.com s3 cp file.txt s3://my-bucket/
   ```

   WinSCP: new site, **File protocol: S3**, host `s3.yourdomain.com`,
   port 443, **Encryption: TLS/SSL** (or "No encryption" if you skip HTTPS),
   access key / secret key as username / password. Region: us-east-1.

## 6. DirectAdmin-specific settings

### Upload size limits

Both the S3 API (`PUT` via `php://input`) and the admin panel's "Upload files"
button stream the request body directly to disk, so PHP's `upload_max_filesize`
/ `post_max_size` do not limit them. Still, raise them for safety and set time
limits so very large uploads are never killed mid-stream:

- **Domain Setup** -> the domain -> **PHP Settings** (or CloudLinux PHP
  Selector -> Configuration), set:
  - `upload_max_filesize` = e.g. `10240M`
  - `post_max_size` = e.g. `10245M` (must be larger than upload_max_filesize)
  - `max_file_uploads` = `100`
  - `max_execution_time` / `max_input_time` = `600`
- Apache itself does not limit request bodies by default; Litespeed / nginx
  proxying hosts may need the equivalent raised in their config (for Litespeed
  that is often fine by default).

### Permissions

DirectAdmin runs PHP-FPM / LSAPI as the site's user, so `data/` is
automatically writable - no chmod needed. If your host uses `mod_php`
(Apache running as `apache`), make `data/` writable by Apache:

```
chown -R apache:apache /home/USERNAME/domains/s3.yourdomain.com/public_html/data
```

### .htaccess / mod_rewrite

DirectAdmin enables `AllowOverride All` and `mod_rewrite` by default, so the
bundled `.htaccess` works as-is. It:

- blocks web access to `data/`, `lib/`, `tests/`, `config.php`
- serves the admin panel (`/admin/`)
- routes everything else to `index.php` (the S3 API)
- disables webserver output compression (mod_deflate / mod_brotli / mod_gzip)
  and PHP `zlib.output_compression` for the app

### Output compression (important on shared hosting)

Many DirectAdmin hosts enable compression (`mod_deflate` and/or PHP's
`zlib.output_compression`). That breaks streamed downloads: the `Content-Length`
header gets stripped (browsers show **no file size** when downloading backups)
and video/audio previews fail because Range/206 responses get corrupted.

The bundled `.htaccess` handles this automatically:

- `SetEnv no-gzip` / `no-brotli` (re-enabled only for the admin SPA page)
  disables mod_deflate / mod_brotli for everything else, and `mod_gzip_on No`
  covers the older mod_gzip module.
- `php_value zlib.output_compression Off` inside `<IfModule mod_php.c>` /
  `mod_php7.c` / `mod_php8.c` / `mod_lsapi.c` blocks covers PHP-level
  compression when PHP runs as mod_php or LiteSpeed LSAPI.

If the `.htaccess` change causes a `500` on every page, your host rejects
`php_value` in .htaccess - delete the four `<IfModule mod_php*>` /
`mod_lsapi` blocks (the env-var rules are the important part). If PHP runs as
PHP-FPM or CGI, also set `zlib.output_compression = Off` in the **PHP
Settings** / PHP selector for the domain.

Verify a download is uncompressed: in the browser devtools (Network tab) the
download response must have a `Content-Length` header and **no**
`Content-Encoding` header.

Note: folder **ZIP** downloads are streamed and intentionally have no
`Content-Length` - that is expected on every host.

### Verify data is not web-accessible

Open these URLs in a browser (or curl) - all must return 403/404, never 200:

```
https://s3.yourdomain.com/data/app.sqlite
https://s3.yourdomain.com/lib/s3.php
https://s3.yourdomain.com/config.php
```

## 7. Backups

- The whole `public_html/` (or at least `data/` + `lib/` + `admin/` + the
  root files) is your backup unit. DirectAdmin's **Scheduled Backups** already
  include it.
- `data/app.sqlite` is the database. If you copy it while the server is
  running, also copy `data/app.sqlite-wal` and `data/app.sqlite-shm` if they
  exist, or run a sqlite checkpoint first:
  ```
  php -r '$db = new PDO("sqlite:data/app.sqlite"); $db->exec("PRAGMA wal_checkpoint(TRUNCATE);");'
  ```

## 8. Troubleshooting

| Symptom | Fix |
|---|---|
| `install.php` shows an S3 XML `AccessDenied` error | The installer is being rewritten to `index.php` (S3 API) - the bundled `.htaccess` line `RewriteRule ^(index|install)\.php$ - [L]` is missing or overridden; re-upload the current `.htaccess` |
| `/admin/` returns 403 / doesn't render | `.htaccess` missing or `AllowOverride` off; re-upload the file, ask the host to enable `AllowOverride All` |
| `install.php` -> 500 | PHP < 7.4 or `pdo_sqlite` missing; switch to PHP 8.1/8.2 |
| `SignatureDoesNotMatch` in WinSCP/aws cli | Keys differ from what the server has - re-copy them from the admin panel (Users -> Show). Check server clock (skew limit is 15 min, `MAX_SKEW` in config.php) |
| WinSCP: `SSL handshake failed` / certificate error | Server has no valid cert for the hostname. Issue Let's Encrypt in **SSL Certificates** (section 1) and connect to the exact hostname on the cert; or set WinSCP Encryption to "No encryption" (not recommended) |
| WinSCP: `Connection reset` on TLS | Some hosts proxy TLS via an nginx/LiteSpeed layer with a stale cert - re-issue the cert for the domain, use port 443, and clear WinSCP's cached cert for the host |
| `AccessDenied` with `x-amz-date` error | Client is not sending `x-amz-date`; use a real S3 client (WinSCP S3, rclone, aws cli) |
| Big upload stalls / times out | Raise PHP time limits (section 6); check `max_execution_time`, and `set_time_limit` being disabled via `disable_functions` |
| Bucket named `admin` unreachable | `admin` is reserved by the admin panel routes - rename the bucket |
| Admin panel works but S3 API returns HTML | Apache rewriting is off; `.htaccess` `RewriteRule ^ index.php` is what routes S3 requests |
| 403 on everything from a new client | Client used signature v2, or virtual-host style URLs (bucket.yourdomain.com); path-style + SigV4 is required |
| Downloads show no file size / video preview won't play | Webserver or PHP output compression is stripping `Content-Length` / corrupting streams - the bundled `.htaccess` disables it (section 6 "Output compression"); if a host rejects `php_value`, remove those blocks and set `zlib.output_compression = Off` in the domain's PHP settings |

## 9. Security checklist

- [ ] `install.php` deleted
- [ ] HTTPS enabled (Let's Encrypt); SigV4 sends the secret-derived signature
      in every request - plain HTTP lets anyone capture and replay it
- [ ] `data/` verified not web-accessible (section 6)
- [ ] Strong admin password, changed from the default
- [ ] Secret keys only shared with the people who need them; regenerate in
      the admin panel if one leaks
- [ ] Logs tab: consider disabling admin/S3 request logging in **Settings**
      if the log table grows too fast
