# MiniS3

A small S3-compatible object storage server in PHP. Runs on plain shared
hosting (Apache) or a VPS (nginx), speaks AWS Signature V4 so `rclone`,
`aws cli`, `s3cmd` and `mc` work out of the box, and ships with a
dark, mobile-friendly admin panel.

```
minis3/
├── index.php        S3 API front controller (every non-admin request)
├── install.php      one-time installer (delete after use)
├── reset.php        web admin password reset (active only with a data/reset.enabled marker)
├── config.php       configuration
├── .htaccess        Apache rewrite + protection + compression-off rules
├── nginx.conf       sample nginx server block
├── router.php       only used by `php -S` for local development
├── admin/
│   ├── index.php    admin panel (dashboard, users, buckets, files, logs, trash, settings)
│   └── api.php      admin JSON API (session + CSRF protected)
├── lib/             util, db, log, auth (SigV4), s3 handlers, webauthn (passkeys)
├── data/            object storage + SQLite database (web access denied)
├── tools/
│   └── reset-admin.php  CLI password reset for the admin account (web-denied)
└── tests/smoke.php  end-to-end API test (46 checks, also runnable against existing keys)
```

Contents: [Features](#features) · [Requirements](#requirements) ·
[Installation](#installation) · [Client configuration](#client-configuration) ·
[Admin panel](#admin-panel) · [Admin API](#admin-api) ·
[Backup & restore](#backup--restore) · [Troubleshooting](#troubleshooting) ·
[Notes and limitations](#notes-and-limitations) ·
[Security checklist](#security-checklist) · [Releases](#releases)

## Features

**S3 API**

- Buckets: List/Create/Delete/Head, ListObjects V1 + V2 (prefix, delimiter,
  pagination), per-user namespaces (two users may own same-named buckets).
- Objects: Put/Get (Range, If-None-Match, If-Modified-Since)/Head/Delete,
  multi-object Delete, Copy, multipart uploads
  (initiate / part / complete / abort / list).
- Auth: header Signature V4 plus query-string **presigned URLs for GET, HEAD,
  PUT and DELETE** (generate time-limited share links from the panel, or
  presign uploads from external tools).
- **Per-user storage quotas** (MB per user, enforced on PUTs, multipart
  completion and panel uploads with `QuotaExceeded`).
- **Disable users** without deleting anything: keys stop working immediately
  (header auth and presigned URLs), buckets and files are kept. Access keys
  can also be regenerated independently of secrets.
- **Lifecycle rules** per bucket: auto-expire objects by prefix after N days
  (permanent, enforced lazily on every bucket listing - no cron needed).
- **Public buckets**: panel toggle serves object GET/HEAD without keys
  (listing and uploads stay private); anonymous misses still 404.
- `GET /health` public JSON health check (`ok`, `version`, `disk_free_bytes`,
  `db`), never logged - point uptime monitors at it instead of S3 paths.
- Share links: signed URLs (up to 7 days, expire automatically) plus
  revocable token links (`/share/<token>`, 30 days or never-expire) managed
  per object in the panel; tokens die with the file/user/bucket and survive
  renames.
- Empty-object folder markers (`keys ending in /`, as created by WinSCP /
  FolderSync) list as folders and follow move/copy/rename/delete.
- Storage layout: `data/users/{username}/{bucket}/{key...}`.

**Admin panel**

Dark field-instrument theme (navy canvas, copper accents, mono readouts),
Space Grotesk + IBM Plex Mono via Google Fonts with system fallbacks,
light/dark toggle that follows the OS, navigation rail on desktop, bottom bar
on phones, `Ctrl+K` command palette (jump anywhere, search users / buckets /
objects), keyboard shortcuts (`/` focuses key search, `u` uploads).

| Tab       | What you can do |
|-----------|-----------------|
| Dashboard | Usage stats, 24h / 7d / 30d request chart with previous-period deltas, status distribution with error delta, clickable stat cards, top users (click to filter), recent activity with one-click "view in logs", server-health panel (PHP/SQLite versions, disk, DB size, log span) |
| Users     | Add / edit / delete, search, per-user detail drawer (buckets, recent requests), storage bars + quotas, 14-day request sparklines, last-active column, disable / enable toggle, per-key copy buttons, masked secret with reveal, secret + access-key regeneration |
| Buckets   | Add / rename / type-to-confirm delete, public/private toggle, lifecycle rules, per-bucket object count + size; file browser with list/grid views, image thumbnails, search, sort, multi-select bulk copy/move/delete, new folder/file, upload (button, folder upload, drag & drop, paste) with progress, drag rows onto folders to move, inline image/video/audio/PDF preview, text editor (512 KB), object details (ETag, type, meta), share links with expiry picker (signed URLs + revocable never-expire tokens), folder/bucket ZIP download |
| Logs      | Every request with user/kind/method/status filters + search, slow-request highlighting, live-tail mode, one-click CSV export of the filtered view, clear, configurable retention (auto-prune) |
| Trash     | Soft-deleted files with retention badges, restore preview (original path, size, purge date), restore / purge / empty; retention days in Settings |
| Settings  | Software update (GitHub Releases, staged + rollback), Connect card (endpoint, region, copy-paste AWS CLI + rclone snippets per user), backup export/import (JSON), last sign-in + session revocation, branding (app name + favicon), logging toggles, log retention, admin account, password (with strength meter), trash retention, TOTP 2FA, passkeys, multipart-upload manager |

## Requirements

- PHP 7.4+ with `pdo_sqlite`, `simplexml`, `openssl`, `mbstring`, `fileinfo`
  and `json` (all bundled in standard builds; the installer runs a preflight
  check and tells you how to enable anything missing).
- Apache with mod_rewrite (shared-hosting default) or nginx.
- SQLite 3.24+ (for upserts; any distro PHP in the last few years has this).
- No `zip` extension required (the updater ships a dependency-free fallback);
  no shell access required.
- A browser with JavaScript for the admin panel. Clipboard copy needs a
  secure context (HTTPS or `localhost`); everywhere else the panel falls back
  to manual copy. Passkeys additionally need HTTPS or `localhost` (Chrome
  rejects bare-IP origins), so the passkey button only appears where allowed.

## Installation

1. Upload the whole `minis3/` folder to your web root and point a subdomain
   or subfolder at it, e.g. `https://s3.example.com/`.
2. Open `https://s3.example.com/install.php`. It starts with a **server
   preflight** (PHP version, extensions, `php.ini` limits, `data/`
   writability, disk space) with per-item DirectAdmin/SSH fixes and a
   Re-check button - clear any red rows first. Then set the admin username
   and password (strength meter included), and **delete `install.php` from
   the server**.
3. Open `/admin/`, sign in, add an S3 user on the **Users** tab. Copy the
   access key and secret key (or use the **Settings → Connect** card, which
   prints ready-to-paste `aws` / `rclone` configs).

PHP needs write access to `data/` (0775 or 0770) for files and the database.

- DirectAdmin shared hosting: step-by-step in `DIRECTADMIN.md`.
- nginx VPS with PHP-FPM: `NGINX.md` (sample block in `nginx.conf` — every
  request must reach `index.php`; object keys are never served as static
  files; the `.htaccess` does the equivalent on Apache and also blocks
  `data/`, `lib/` and `config.php`).

### Local development and CI

```bash
php -S 127.0.0.1:8000 router.php
php tests/smoke.php                      # fresh install + user + full suite (48 checks)
S3_ACCESS_KEY=... S3_SECRET_KEY=... php tests/smoke.php   # against existing keys (46 checks)
```

Pushes run the same suite on PHP 7.4–8.5 via `.github/workflows/ci.yml`
(lint + fresh-install + existing-keys runs).

### Docker

Alpine-based single image (nginx + PHP-FPM under supervisor, plain HTTP):

```bash
docker compose up --build -d   # serves :8080, ./data persisted
# or: docker build -t minis3 . && docker run -p 8080:80 -v ./data:/var/www/html/data minis3
```

Then open `http://localhost:8080/install.php`. Ensure the host `./data`
directory is writable by www-data (uid 33) inside the container.

On Windows run the same inside WSL, or use the Windows nginx + php-cgi
stack: `start-dev.ps1` serves http://127.0.0.1:8765 (`stop-dev.ps1` stops
it). The scripts auto-detect the project folder, including WSL paths like
`\\wsl.localhost\<distro>\home\<user>\minis3`.

## Client configuration

The panel's **Settings → Connect** card generates these per user, but the
shapes are:

```ini
# rclone
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
# aws cli (use a named profile per user)
aws configure set aws_access_key_id AKIA...     --profile minis3-user
aws configure set aws_secret_access_key ...     --profile minis3-user
aws configure set region us-east-1              --profile minis3-user
aws --profile minis3-user --endpoint-url https://s3.example.com s3 ls
```

```ini
# s3cmd
[default]
access_key = AKIA...
secret_key = ...
host_base = s3.example.com
host_bucket = s3.example.com
use_https = True
```

```bash
# mc (MinIO client)
mc alias set mys3 https://s3.example.com AKIA... secret... --path on
```

## Admin panel

Sign in at `/admin/` with the installer credentials. Sessions are PHP
sessions (30-day cookie) plus a CSRF token; **Sessions** in Settings signs
out every other browser/device. Failed logins are rate-limited (6 per IP per
15 minutes, then HTTP 429) and optionally gated by TOTP and/or passkeys.

**Forgot the admin password?**
- Shell: `php tools/reset-admin.php` from the app root (sets a new
  username/password, clears 2FA if enabled).
- No shell: create an empty `data/reset.enabled` via FTP / File Manager, open
  `/reset.php`, set a new username/password (optionally clearing 2FA). The
  marker is deleted automatically after a successful reset.

## Admin API

Same-origin JSON API at `/admin/api.php?action=…`, session cookie plus
`X-CSRF-Token` header on POSTs. Handy for scripting:

| Action | Notes |
|--------|-------|
| `users`, `buckets`, `objects`, `trash`, `uploads`, `logs`, `stats` | List + manage; `logs` accepts `user_id/kind/method/status/q` filters; `stats` accepts `range=24h\|7d\|30d` and returns previous-period deltas |
| `logs_export` | Download the filtered log view as CSV (cap 10k rows) |
| `search_all?q=` | Capped user / bucket / object search backing `Ctrl+K` |
| `lifecycle` | List/add/delete per-bucket auto-expiry rules (prefix + days) |
| `shares` | Token share links: create (30 days / never), list per object, revoke |
| `server_info` | PHP/SQLite versions, disk + data-dir + DB sizes, counts, log span |
| `backup_export` / `backup_import` | JSON with users (incl. keys), buckets and panel settings; import recreates missing entries and reports `{users_created, buckets_created, skipped}` |
| `revoke_sessions` | Invalidate every admin session except the current one |
| `updater` (`op=status/check/download/apply/migrate/cleanup/rollback`) | Staged panel updates from GitHub Releases (login + CSRF; `check`/`status` are GET) |
| `update_settings`, `update_profile`, `change_password`, `update_logs` | Panel preferences, admin renames/password |
| `totp_start`, `totp_enable`, `totp_disable` | TOTP 2FA lifecycle |
| `passkey_start`, `passkey_register`, `passkeys`, `passkey_delete`, `passkey_challenge`, `passkey_login` | WebAuthn lifecycle (ES256 / RS256 / Ed25519) |
| `upload_favicon`, `reset_favicon` | Custom favicon served at `/favicon.ico` |

`POST`s without (or with a wrong) CSRF token are rejected with 403; logged-out
calls get 401; revoked sessions get 401 with "Session revoked".

## Backup & restore

- **Panel**: Settings → Backup exports users/buckets/settings JSON and
  re-imports it (existing names are skipped, never overwritten). Object *data*
  is not included — re-upload files afterwards.
- **Full backup**: copy the whole `data/` directory (SQLite DB + object
  files). SQLite WAL mode is on, so copy it quiesced or also grab the
  `-wal`/`-shm` sidecars.

## Troubleshooting

- **Login says "Invalid username or password" (403 with a message):** wrong
  credentials (or the admin was renamed in Settings → Admin account). After 6
  failures the IP is locked out for 15 minutes (HTTP 429).
- **Login shows a bare "HTTP 403" with no message:** the POST never reached
  `api.php` — something in front (host WAF, proxy rule, `.htaccess` override)
  blocked it. Check the request in DevTools → Network and the server error log.
- **Copy buttons say "Copy failed":** the browser blocked clipboard access.
  Serve over HTTPS (or use `localhost`); on plain-HTTP LAN hosts use the
  click-to-select fields and copy manually.
- **No "Sign in with passkey" button:** expected on plain HTTP or bare-IP
  origins — WebAuthn needs HTTPS or `localhost`.
- **Video/audio previews download instead of playing:** webserver/PHP output
  compression is on — turn it off (see notes below); it also strips
  `Content-Length` from streamed downloads.
- **Certain bucket names 404 (e.g. `admin`, `data`):** reserved — Apache/nginx
  serve or deny the real app paths before the S3 router runs (full list in
  notes).

## Notes and limitations

- Signature V4 (header auth **and** presigned URLs for GET, HEAD, PUT,
  DELETE); no versioning; no bucket policies or object tagging (those
  sub-resources return 501 or a stub). Full matrix: `docs/COMPATIBILITY.md`.
- Bucket names follow S3 rules (3–63 chars, lowercase, digits, dots,
  hyphens) and are namespaced per user.
- Object keys with `.` / `..` segments are rejected; keys ending in `/` are
  empty-folder markers.
- Reserved bucket names: `admin`, `data`, `lib`, `tests`, `tools`,
  `config.php`, `health`, `index.php`, `install.php`, `reset.php` (`/health`
  is the public health endpoint).
- Size limits come from PHP (`max_execution_time`) and the web server
  (`client_max_body_size` on nginx). S3 PUTs and admin uploads stream to
  disk, so `upload_max_filesize` / `post_max_size` do not apply; only the
  server body limit and PHP time limits matter. 2 GB+ files are unreliable on
  32-bit PHP.
- Keep webserver/PHP output compression **off**: `.htaccess` disables
  `mod_deflate`/`mod_brotli`/`mod_gzip` and `zlib.output_compression`
  automatically; on nginx leave `gzip` off and set
  `zlib.output_compression = Off`. ZIP downloads are streamed with no
  `Content-Length` by design.
- Renaming a file onto an existing name replaces it (S3 copy semantics);
  creating a file over an existing name is rejected (409). Bulk copy/move
  without "overwrite" reports conflicts (409 + list) instead of partial writes.

## Security checklist

- HTTPS everywhere — SigV4 sends keys with every request.
- Delete `install.php` after installation.
- Enable admin 2FA (and/or a passkey) if the panel is exposed.
- Strong admin password (bcrypt-hashed); use **Sessions → Revoke others**
  after any incident, and regenerate leaked user keys (or **Disable** the user
  to block keys instantly while keeping data).
- Keep presigned-link expiries short; share only over trusted channels.
- Confirm `data/` is not web-readable after upload (bundled rules cover
  Apache + the nginx sample).

## Releases

Every commit is released on GitHub with a version bump: the commit sets
the version in the `VERSION` file (shown in the Settings footer; the legacy
`APP_VERSION` in `config.php` is preserved untouched by updates) and is tagged
`vX.Y.Z`, then pushed with the tag. Each Release ships the full source
(`data/` is gitignored, so live objects and the database never ship).
