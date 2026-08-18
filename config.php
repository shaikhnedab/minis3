<?php
// MiniS3 configuration. Edit values here if needed.

declare(strict_types=1);

define('APP_ROOT', __DIR__);

// Where object data, the SQLite database and in-progress multipart uploads live.
// Keep this inside the web root; access is denied via .htaccess / nginx config.
define('DATA_DIR', APP_ROOT . '/data');

define('DB_PATH', DATA_DIR . '/app.sqlite');

define('UPLOADS_DIR', DATA_DIR . '/_uploads');

// Region reported to clients. Signature verification uses whatever region the
// client signs with, so this is mostly informational.
define('REGION', 'us-east-1');

define('SERVICE', 's3');

// Allowed clock skew for signature verification (seconds).
define('MAX_SKEW', 900);

// Default / maximum keys returned by ListObjects* when max-keys is not given.
define('DEFAULT_MAX_KEYS', 1000);

// Multipart upload constraints (same as AWS S3).
define('MIN_PART_BYTES', 5 * 1024 * 1024);
define('MAX_PARTS', 10000);

// Log every request to the database (visible in the admin Logs tab).
define('LOG_REQUESTS', true);

define('APP_NAME', 'MiniS3');

// App version - bumped with every commit (see README "Releases").
define('APP_VERSION', '1.0.1');

date_default_timezone_set('UTC');
