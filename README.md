# MiniS3

A small S3-compatible object storage server written in PHP, designed to run on a
normal shared web host (Apache) or a VPS (nginx). It speaks the AWS S3 REST API
(AWS Signature V4) so it works with `rclone`, `aws cli`, `s3cmd`, `mc` and any
other S3 client, and it ships with a web admin panel.

```
minis3/
├── index.php        S3 API front controller (every non-admin request)
├── install.php      one-time installer (delete after use)
├── config.php       configuration
├── .htaccess        Apache rewrite + protection rules
├── nginx.conf       sample nginx server block
├── router.php       only used by `php -S` for local development
├── admin/
│   ├── index.php    admin panel (users, buckets, files, logs, settings)
│   └── api.php      admin JSON API
├── lib/             util, db, log, auth (SigV4), s3 handlers
├── data/            object storage + SQLite database (web access denied)
└── tests/smoke.php  end-to-end API test
```

## Features

- S3 API: ListBuckets, Create/Delete/HeadBucket, ListObjectsV1/V2 (prefix,
  delimiter, pagination), PutObject, GetObject (Range, If-None-Match,
  If-Modified-Since), HeadObject, DeleteObject, DeleteObjects, CopyObject,
  multipart uploads (initiate/part/complete/abort/list), bucket ACL/versioning/
  location stubs.
- AWS Signature V4 authentication (path-style requests), including
  query-string **presigned URLs for GET, HEAD, PUT and DELETE** (generate
  time-limited share links from the admin panel; external clients can presign
  uploads too - tested with the Pelican Panel + Wings backup upload flow).
- Optional **per-user storage quotas** (set in MB per user; enforced on S3
  PUTs, multipart completion and admin uploads with `QuotaExceeded` errors).
- Admin panel trash: files deleted in the UI are kept for a configurable
  number of days (Settings) and can be restored or purged from the Trash tab.
- Download any folder (or a whole bucket) as a ZIP archive - streamed, no
  temp files, no PHP extensions required.
- Admin **two-factor authentication** (TOTP / authenticator apps) and login
  rate limiting (6 failed attempts per IP per 15 minutes).
- **Branding**: rename the app (title, header, install page, 2FA issuer) and
  upload a custom favicon (PNG/GIF/JPG/SVG/ICO/WebP) from Settings - served
  at `/favicon.ico`.
- Object details (size, type, ETag, `x-amz-meta-*`), file browser list/grid
  views with image thumbnails, drag & drop + clipboard upload, keyboard
  shortcuts (`/` search, `u` upload), sortable user/bucket tables, per-user
  14-day usage sparklines, and a multipart-upload manager (abort single
  uploads or clean up everything older than 7 days).
- Storage layout: one folder per bucket inside one folder per user:
  `data/users/{username}/{bucket}/{key...}`.
- Admin panel: manage S3 users (access key / secret key, regenerate keys,
  quotas), create/rename/delete buckets, browse/upload/download/delete files
  with a folder view (pagination, search, multi-select bulk delete / move /
  copy / rename), view images/videos/audio inline in the browser, view and
  edit readable text and config files (512 KB limit), and inspect request
  logs with filters and search. Uploads stream straight to disk with a
  byte-accurate progress bar and percentage.
- Empty-object "folder markers" (keys ending in `/`, as created by WinSCP /
  FolderSync for empty folders) are supported: they list as folders, stay
  empty when files are deleted, and follow move/copy/rename/delete.

## Requirements

- PHP 7.4+ with `pdo_sqlite` and `simplexml` (both are bundled in standard
  builds).
- Apache with mod_rewrite (shared hosting default) or nginx.
- SQLite 3.24+ (for upserts; any distro PHP in the last few years has this).

## Installation

1. Upload the whole `minis3/` folder to your web root (e.g. `public_html/`
   or `www/`). Point a subdomain or subfolder at it, e.g.
   `https://s3.example.com/`.
2. Open `https://s3.example.com/install.php` in a browser, set the admin
   username and password, then **delete `install.php` from the server**.
3. Open `/admin/`, sign in, go to the **Users** tab and add an S3 user. Note
   the access key and secret key.
4. Point your S3 client at the server (examples below).

The web root must be writable by PHP (the `data/` directory, 0775 or 0770) so
that files and the SQLite database can be created.

On DirectAdmin shared hosting, see `DIRECTADMIN.md` for a step-by-step guide.
On a nginx VPS with PHP 8.5 FPM, see `NGINX.md`.

### nginx

Use the sample `nginx.conf`. Important: the `location /` block sends *every*
request to `index.php` - S3 object keys must never be served as static files.

### Apache

The `.htaccess` handles routing and blocks direct access to `data/`, `lib/`
and `config.php`. `.htaccess` must be enabled (`AllowOverride All`).

### Local development

```bash
php -S 127.0.0.1:8000 router.php
php tests/smoke.php
```

The smoke test installs the server, creates a test user and runs ~40 API
checks against the running server (including presigned URLs, tampered
signatures, quota enforcement and Content-MD5).

On Windows you can either run the same commands inside WSL, or run the
Windows nginx + php-cgi stack with `start-dev.ps1` (serves
http://127.0.0.1:8765, stop with `stop-dev.ps1`). The scripts auto-detect
the project folder - including WSL locations like
`\\wsl.localhost\<distro>\home\<user>\minis3`, which are mapped to a drive
letter automatically.

## Client configuration

### rclone

```
[rclone_s3]
type = s3
provider = Other
endpoint = https://s3.example.com
access_key_id = AKIA...
secret_access_key = ...
region = us-east-1
force_path_style = true
```

```bash
rclone ls rclone_s3:my-bucket
rclone copy file.txt rclone_s3:my-bucket/
```

### aws cli

```bash
aws configure set default.region us-east-1
aws configure set default.s3.addressing_style path
aws --endpoint-url https://s3.example.com s3 ls
aws --endpoint-url https://s3.example.com s3 cp file.txt s3://my-bucket/
aws --endpoint-url https://s3.example.com s3 cp file.txt s3://my-bucket/ sse-c  # not supported
```

### s3cmd

```
[default]
access_key = AKIA...
secret_key = ...
host_base = s3.example.com
host_bucket = s3.example.com
use_https = True
```

### mc (MinIO client)

```bash
mc alias set mys3 https://s3.example.com AKIA... secret... --path on
mc ls mys3/my-bucket
```

## Admin panel

Material 3 single-page app, fully self-contained (no CDN), mobile friendly
(navigation rail on desktop, bottom bar on phones), light/dark theme that
follows your system preference.

| Tab        | What you can do                                                                   |
|------------|-----------------------------------------------------------------------------------|
| Dashboard  | Usage stats, request status distribution, 24-hour chart, top users, recent activity |
| Users      | Add / edit / delete S3 users, quotas, show or regenerate secret keys, usage sparklines |
| Buckets    | Add / rename / delete buckets per user; browse files (list or grid view with image thumbnails), upload (button, drag & drop or paste), download, preview/edit text, share presigned links, download folders as ZIP, bulk move/copy/delete |
| Logs       | Inspect every request with filters (user, kind, method, status) and search        |
| Trash      | Restore or purge admin-deleted files (retention configurable in Settings)         |
| Settings   | Branding (app name + favicon), logging toggles, trash retention, admin 2FA (TOTP), multipart-upload cleanup, password change |

The admin panel uses PHP sessions plus a CSRF token, rate-limits failed
logins (6 per IP per 15 minutes) and supports TOTP two-factor
authentication. The S3 API is protected by SigV4 signatures. Put the whole
site behind HTTPS.

## Notes and limitations

- Signature V4 (header auth **and** query-string presigned URLs for GET,
  HEAD, PUT and DELETE); no versioning; no bucket policies or object tagging
  (those sub-resources return 501 or a stub response).
- Bucket names must follow S3 rules (3-63 chars, lowercase letters, digits,
  dots, hyphens). Buckets are namespaced per user, so two users may have
  buckets with the same name.
- Object keys containing `.` or `..` path segments are rejected (filesystem
  safety). Keys ending with `/` are treated as empty-folder markers and are
  supported; normal files use real keys like `folder/file.txt`.
- Do not name buckets `admin`, `data`, `lib` or `tests`, and do not use keys
  that collide with the app files (`index.php`, `install.php`) - Apache serves
  real files before the S3 router runs.
- Upload/download size limits come from your PHP (`upload_max_filesize`,
  `post_max_size`, `max_execution_time`) and web server (`client_max_body_size`
  on nginx) configuration. Admin uploads and the S3 `PUT` API stream the body
  to disk, so `upload_max_filesize` / `post_max_size` do not apply to them;
  only the web server's request-body limit and PHP's time limits matter.
- Files larger than 2 GB are unreliable on 32-bit PHP builds.
- `data/` holds the SQLite database. Back it up together with the files, or
  exclude it if you only need the object files.

## Security checklist

- Use HTTPS (self-signed or Let's Encrypt) - SigV4 sends keys in every request.
- Delete `install.php` after installation.
- Enable admin two-factor authentication (Settings) if the panel is exposed.
- Keep the admin password strong; secrets are stored as bcrypt hashes.
- Don't share secret keys; regenerate via the admin panel if one leaks.
- Presigned share links grant download access until they expire - keep the
  expiry short and only share over trusted channels.
- Check `data/` permissions after upload: the directory and its contents must
  not be readable by the web server (the bundled `.htaccess` denies it on
  Apache; the nginx sample denies it too).
