# S3 compatibility matrix

What MiniS3 implements, stubs, or rejects — checked against `lib/s3.php`
(`s3_route` / `s3_bucket_route` / `s3_key_route`). Anything not listed here
falls through to `405 MethodNotAllowed` or `400 InvalidArgument`.

## Buckets

| Operation | Status | Notes |
|-----------|--------|-------|
| ListBuckets (`GET /`) | ✅ | Path-style only |
| CreateBucket (`PUT /b`) | ✅ | Per-user namespaces; S3 name rules |
| HeadBucket | ✅ | 200 + region header, or 404 |
| DeleteBucket | ✅ | Must be empty (S3 behavior) |
| ListObjectsV1 / V2 | ✅ | `prefix`, `delimiter`, `max-keys`, pagination, `fetch-owner` ignored; lifecycle-expired objects are purged on list |
| `?location` | ✅ stub | Always returns the configured region |
| `?versioning` | ✅ stub | Always returns `Suspended` |
| `?uploads` (list multiparts) | ✅ | |
| `?acl` (get/put) | ✅ stub | Canned private ACL echo |
| `?policy` | ❌ `NoSuchBucketPolicy` | No bucket policies; use per-bucket public toggle in the panel instead |
| `?tagging`, `?lifecycle`, `?cors`, `?website`, `?replication`, `?encryption`, `?logging`, `?notification`, `?ownershipControls`, `?versions`, … | ❌ `501 NotImplemented` | Full list in `s3_unsupported_subresource()` |

## Objects

| Operation | Status | Notes |
|-----------|--------|-------|
| PutObject | ✅ | Streams to disk; quota + lifecycle aware; `Content-MD5` verified when sent |
| GetObject | ✅ | `Range`, `If-None-Match`, `If-Modified-Since`, `Range`+`If-Range`; unsafe content types force download |
| HeadObject | ✅ | |
| DeleteObject | ✅ | Idempotent (204 even if missing, like AWS) |
| DeleteObjects (multi, ≤1000) | ✅ | Per-key `<Deleted>` / `<Error>` results |
| CopyObject | ✅ | Same-bucket and cross-bucket; metadata replace supported |
| `uploadId` flows | ✅ | Initiate / UploadPart / Complete / Abort / ListParts; complete tolerates client/server ETag formatting differences |
| `?acl` (object) | ✅ stub | Private echo |
| `?tagging`, `?retention`, `?legal-hold`, `?torrent` | ❌ `501` | |
| `?restore` (Glacier) | ❌ `501` | No archive tier |

## Authentication

| Mechanism | Status | Notes |
|-----------|--------|-------|
| Header Signature V4 | ✅ | Path-style; `host` + `x-amz-date` + `x-amz-content-sha256` signed; 15 min skew (`MAX_SKEW`) |
| Presigned URLs | ✅ | GET, HEAD, PUT, DELETE; max 7 days (`604800`); clock-skew + expiry enforced |
| Signature V2 | ❌ | 403 — SigV4 only |
| Virtual-host style (`bucket.host/…`) | ❌ | Path-style only |
| Disabled users | ✅ | `AccessDenied` on header and presigned auth |
| Public buckets | ✅ | Panel toggle; unauthenticated GET/HEAD of objects only; listing/uploads stay private; keep names unique |

## MiniS3 extensions (beyond S3)

- `GET /health` — public JSON health check (`ok`, `version`, `time`, `disk_free_bytes`, `db`), never logged.
- `GET /favicon.ico` — panel favicon, never logged.
- `POST ?delete` semantics match AWS; empty-folder keys (`…/`) behave like WinSCP/FolderSync markers.
- Admin panel + JSON API under `/admin/` (see README "Admin API").
