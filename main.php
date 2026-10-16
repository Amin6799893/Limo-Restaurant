<?php
/**
 * 🍋 LIMO RESTAURANT — Main Application (Hardening Phase) — PHP Edition
 * =====================================================================
 * معادل main.py
 *
 * اصلاحات این نسخه (v5):
 * - FIX-A: [NEW] route_request — Front Controller برای HTML + API + static
 * - FIX-B: [FIX] create_default_admin — رمز در error_log وب چاپ نمی‌شود
 * - FIX-C: [FIX] حذف normalize_api_path تکراری — فقط یک نسخه
 * - FIX-D: [FIX] بهبود session bootstrap
 * - FIX-E: [FIX] handle_error — fallback بهتر
 * - حفظ همه HARD-001 تا HARD-013
 * - حفظ همه BUG-005, BUG-010
 * - حفظ FIX-10, FIX-11
 */

declare(strict_types=1);

// ============================================================================
// SECTION: Environment Loading (.env-style via getenv)
// ============================================================================

function env_str(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return (string)$v;
}

function env_int(string $key, int $default): int
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return (int)$v;
}

function env_bool(string $key, bool $default = false): bool
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    $v = strtolower(trim((string)$v));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

// ============================================================================
// SECTION: Configuration (env-driven)
// ============================================================================

define('LIMO_BASE_DIR', __DIR__);
define('LIMO_DATA_DIR', LIMO_BASE_DIR . DIRECTORY_SEPARATOR . 'data');
define('LIMO_BACKUP_DIR', LIMO_BASE_DIR . DIRECTORY_SEPARATOR . 'backups');
define('LIMO_DB_PATH', LIMO_DATA_DIR . DIRECTORY_SEPARATOR . 'limo.db');
define('LIMO_SECRET_FILE', LIMO_DATA_DIR . DIRECTORY_SEPARATOR . '.secret_key');
define('LIMO_LOG_FILE', LIMO_DATA_DIR . DIRECTORY_SEPARATOR . 'limo.log');
define('LIMO_INIT_MARKER', LIMO_DATA_DIR . DIRECTORY_SEPARATOR . '.init_done');

// ساخت پوشه‌های لازم
foreach ([LIMO_DATA_DIR, LIMO_BACKUP_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}

// ============================================================================
// SECTION: Logging
// ============================================================================

define('LIMO_LOG_LEVEL', strtoupper(env_str('LIMO_LOG_LEVEL', 'INFO')));

function limo_log_levels(): array
{
    return ['DEBUG' => 10, 'INFO' => 20, 'WARNING' => 30, 'ERROR' => 40, 'CRITICAL' => 50];
}

function limo_log(string $level, string $message, array $context = []): void
{
    $levels = limo_log_levels();
    $currentLevel = $levels[LIMO_LOG_LEVEL] ?? 20;
    $msgLevel = $levels[strtoupper($level)] ?? 20;
    if ($msgLevel < $currentLevel) {
        return;
    }

    $sensitive = ['password', 'current_password', 'new_password', 'confirm_password',
                  'password_hash', 'csrf_token', 'session_id', 'token', 'secret'];
    foreach ($context as $k => $v) {
        if (in_array(strtolower((string)$k), $sensitive, true)) {
            $context[$k] = '[REDACTED]';
        }
    }

    $ts = date('Y-m-d H:i:s');
    $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $line = sprintf('[%s] [%s] limo: %s%s%s', $ts, strtoupper($level), $message, $ctx, PHP_EOL);

    @file_put_contents(LIMO_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function log_info(string $msg, array $ctx = []): void    { limo_log('INFO', $msg, $ctx); }
function log_warn(string $msg, array $ctx = []): void    { limo_log('WARNING', $msg, $ctx); }
function log_error(string $msg, array $ctx = []): void   { limo_log('ERROR', $msg, $ctx); }
function log_debug(string $msg, array $ctx = []): void   { limo_log('DEBUG', $msg, $ctx); }

// ============================================================================
// SECTION: Secret Key Loading (HARD-001/003)
// ============================================================================

function load_or_create_secret(): string
{
    $envKey = env_str('LIMO_SECRET_KEY');
    if ($envKey !== '' && strlen($envKey) >= 32) {
        return $envKey;
    }

    if (file_exists(LIMO_SECRET_FILE)) {
        $perms = @fileperms(LIMO_SECRET_FILE);
        if ($perms !== false) {
            $currentMode = $perms & 0777;
            if ($currentMode !== 0600) {
                @chmod(LIMO_SECRET_FILE, 0600);
                log_warn('اصلاح مجوز فایل .secret_key', [
                    'from' => sprintf('%o', $currentMode),
                    'to'   => '600',
                ]);
            }
        }

        $key = @file_get_contents(LIMO_SECRET_FILE);
        if ($key !== false) {
            $key = trim($key);
            if (strlen($key) >= 32) {
                return $key;
            }
            log_warn('فایل .secret_key معتبر نبود؛ در حال ساخت مجدد.');
        } else {
            log_error('خطا در خواندن .secret_key — ساخت کلید جدید.');
        }
    }

    $key = bin2hex(random_bytes(32));
    try {
        $tmpFile = LIMO_SECRET_FILE . '.tmp';
        $written = @file_put_contents($tmpFile, $key, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('cannot write secret file');
        }
        @chmod($tmpFile, 0600);
        if (!@rename($tmpFile, LIMO_SECRET_FILE)) {
            throw new RuntimeException('cannot rename secret file');
        }
        @chmod(LIMO_SECRET_FILE, 0600);
    } catch (Throwable $e) {
        log_error('خطا در نوشتن فایل .secret_key', ['error' => $e->getMessage()]);
    }
    return $key;
}

// ============================================================================
// SECTION: Determine Environment
// ============================================================================

$LIMO_ENV = strtolower(env_str('LIMO_ENV', env_str('APP_ENV', '')));
$LIMO_IS_PROD = ($LIMO_ENV === 'production' || $LIMO_ENV === 'prod');

$TRUSTED_PROXIES_RAW = env_str('LIMO_TRUSTED_PROXIES', '');
$TRUSTED_PROXIES = [];
if ($TRUSTED_PROXIES_RAW !== '') {
    foreach (explode(',', $TRUSTED_PROXIES_RAW) as $p) {
        $p = trim($p);
        if ($p !== '') {
            $TRUSTED_PROXIES[$p] = true;
        }
    }
}

$LIMO_SESSION_HOURS = max(1, env_int('LIMO_SESSION_HOURS', 12));
$LIMO_MAX_UPLOAD_MB = max(1, env_int('LIMO_MAX_UPLOAD_MB', 5));

define('LIMO_IS_PROD', $LIMO_IS_PROD);
define('LIMO_SESSION_HOURS', $LIMO_SESSION_HOURS);
define('LIMO_MAX_UPLOAD_MB', $LIMO_MAX_UPLOAD_MB);

if (!LIMO_IS_PROD) {
    log_warn('⚠️  LIMO در حالت DEVELOPMENT اجرا می‌شود. برای production مقدار LIMO_ENV=production را تنظیم کنید.');
}

// ============================================================================
// SECTION: PHP runtime settings
// ============================================================================

if (LIMO_IS_PROD) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    @ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

@ini_set('log_errors', '1');
if (is_dir(LIMO_DATA_DIR) && is_writable(LIMO_DATA_DIR)) {
    @ini_set('error_log', LIMO_LOG_FILE);
}

@ini_set('post_max_size', LIMO_MAX_UPLOAD_MB . 'M');
@ini_set('upload_max_filesize', LIMO_MAX_UPLOAD_MB . 'M');

date_default_timezone_set('UTC');

// ============================================================================
// SECTION: Security Headers
// ============================================================================

function apply_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    $csp = "default-src 'self'; "
         . "script-src 'self'; "
         . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
         . "font-src 'self' https://cdn.jsdelivr.net data:; "
         . "img-src 'self' data: https:; "
         . "connect-src 'self'; "
         . "frame-ancestors 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "object-src 'none'";
    header('Content-Security-Policy: ' . $csp);

    $__isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    if (LIMO_IS_PROD || $__isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#(^|/)api/#', $uri) === 1) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }
}

// ============================================================================
// SECTION: Request ID
// ============================================================================

function generate_request_id(): string
{
    return bin2hex(random_bytes(6));
}

// ============================================================================
// SECTION: Session Bootstrap
// ============================================================================

function bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'))
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $secure = LIMO_IS_PROD || $isHttps;
    $lifetime = LIMO_SESSION_HOURS * 3600;

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.cookie_path', '/');
    @ini_set('session.gc_maxlifetime', (string)$lifetime);
    @ini_set('session.sid_length', '48');
    @ini_set('session.sid_bits_per_character', '5');

    session_name('limo_session');

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!isset($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = time();
    } elseif (time() - (int)$_SESSION['_created_at'] > 1800) {
        @session_regenerate_id(true);
        $_SESSION['_created_at'] = time();
    }
}

// ============================================================================
// SECTION: API Path Helpers (FIX-C: فقط یک نسخه)
// ============================================================================

function is_api_path(string $path): bool
{
    return preg_match('#(^|/)api/#', $path) === 1;
}

/**
 * FIX-C: تنها تابع نرمال‌سازی مسیر API — استفاده در همه‌جا
 * مثال: /limo/api/auth/login → /api/auth/login
 */
function normalize_api_path(string $path): string
{
    $pos = strpos($path, '/api/');
    if ($pos === false) {
        return $path;
    }
    return substr($path, $pos);
}

// ============================================================================
// SECTION: CSRF Pipeline
// ============================================================================

const CSRF_EXEMPT_PATHS = [
    '/api/auth/customer/login',
    '/api/auth/admin/login',
    '/api/public/info',
    '/api/health',
];

const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

function check_csrf_pipeline(): array
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if (!is_api_path($rawPath)) {
        return ['ok' => true, 'status' => 200, 'code' => '', 'message' => ''];
    }

    if (in_array($method, SAFE_METHODS, true)) {
        return ['ok' => true, 'status' => 200, 'code' => '', 'message' => ''];
    }

    $normalized = normalize_api_path($rawPath);
    $normalized = rtrim($normalized, '/');
    if (in_array($normalized, CSRF_EXEMPT_PATHS, true)) {
        return ['ok' => true, 'status' => 200, 'code' => '', 'message' => ''];
    }

    $expected = $_SESSION['csrf_token'] ?? null;
    if (!$expected) {
        log_warn('CSRF: no session token', ['path' => $rawPath]);
        return [
            'ok' => false,
            'status' => 401,
            'code' => 'SESSION_INVALID',
            'message' => 'نشست نامعتبر است. لطفاً دوباره وارد شوید.',
        ];
    }

    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || $provided === '') {
        return [
            'ok' => false,
            'status' => 403,
            'code' => 'CSRF_MISSING',
            'message' => 'توکن امنیتی نامعتبر است.',
        ];
    }

    if (!hash_equals((string)$expected, (string)$provided)) {
        log_warn('CSRF: token mismatch', ['path' => $rawPath]);
        return [
            'ok' => false,
            'status' => 403,
            'code' => 'CSRF_STALE',
            'message' => 'توکن امنیتی منقضی شده است. لطفاً صفحه را دوباره بارگذاری کنید.',
        ];
    }

    return ['ok' => true, 'status' => 200, 'code' => '', 'message' => ''];
}

// ============================================================================
// SECTION: Database Helper (PDO)
// ============================================================================

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'sqlite:' . LIMO_DB_PATH;

    try {
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 15,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 15000;');

        return $pdo;
    } catch (PDOException $e) {
        log_error('DB connection failed', ['error' => $e->getMessage()]);
        throw new RuntimeException('Database connection failed');
    }
}

function db_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_value(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

// ============================================================================
// SECTION: Schema
// ============================================================================

const SCHEMA_VERSION = 1;

const SCHEMA_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS admins (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    username                    TEXT NOT NULL UNIQUE,
    password_hash               TEXT NOT NULL,
    active                      INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    must_change_credentials     INTEGER NOT NULL DEFAULT 0 CHECK (must_change_credentials IN (0,1)),
    session_version             INTEGER NOT NULL DEFAULT 1,
    created_at                  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at                  TEXT NOT NULL DEFAULT (datetime('now')),
    last_login_at               TEXT
);

CREATE TABLE IF NOT EXISTS customers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    company_name    TEXT NOT NULL,
    phone           TEXT NOT NULL DEFAULT '',
    address         TEXT NOT NULL DEFAULT '',
    customer_code   TEXT NOT NULL UNIQUE,
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS products (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    category        TEXT NOT NULL CHECK (category IN ('food','drink','dessert')),
    description     TEXT NOT NULL DEFAULT '',
    base_capacity   INTEGER NOT NULL DEFAULT 0 CHECK (base_capacity >= 0),
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    available_days  TEXT NOT NULL DEFAULT '0,1,2,3,4,5',
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS product_variants (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id      INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    base_capacity   INTEGER NOT NULL DEFAULT 0 CHECK (base_capacity >= 0),
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    independently_disabled INTEGER NOT NULL DEFAULT 0 CHECK (independently_disabled IN (0,1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS orders (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id         INTEGER NOT NULL REFERENCES customers(id),
    status              TEXT NOT NULL DEFAULT 'PENDING'
                        CHECK (status IN ('PENDING','APPROVED','REJECTED','CANCELLED')),
    rejection_reason    TEXT,
    order_date          TEXT NOT NULL,
    request_token       TEXT UNIQUE,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
    approved_at         TEXT,
    rejected_at         TEXT,
    cancelled_at        TEXT
);

CREATE TABLE IF NOT EXISTS order_items (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id      INTEGER NOT NULL REFERENCES products(id),
    variant_id      INTEGER REFERENCES product_variants(id),
    quantity        INTEGER NOT NULL CHECK (quantity > 0),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS reservations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    order_item_id   INTEGER REFERENCES order_items(id) ON DELETE CASCADE,
    product_id      INTEGER NOT NULL REFERENCES products(id),
    variant_id      INTEGER REFERENCES product_variants(id),
    quantity        INTEGER NOT NULL CHECK (quantity > 0),
    status          TEXT NOT NULL DEFAULT 'ACTIVE'
                    CHECK (status IN ('ACTIVE','RELEASED','CONSUMED')),
    order_date      TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    released_at     TEXT,
    consumed_at     TEXT
);

CREATE TABLE IF NOT EXISTS order_status_history (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    old_status      TEXT,
    new_status      TEXT NOT NULL,
    reason          TEXT,
    actor_type      TEXT,
    actor_id        INTEGER,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS settings (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_type      TEXT NOT NULL,
    actor_id        INTEGER,
    action          TEXT NOT NULL,
    metadata        TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier  TEXT NOT NULL,
    ip_address  TEXT,
    success     INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS schema_version (
    version     INTEGER PRIMARY KEY,
    applied_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_customers_code       ON customers(customer_code);
CREATE INDEX IF NOT EXISTS idx_customers_active     ON customers(active);
CREATE INDEX IF NOT EXISTS idx_products_category    ON products(category);
CREATE INDEX IF NOT EXISTS idx_products_active      ON products(active);
CREATE INDEX IF NOT EXISTS idx_variants_product     ON product_variants(product_id);
CREATE INDEX IF NOT EXISTS idx_orders_customer      ON orders(customer_id);
CREATE INDEX IF NOT EXISTS idx_orders_status        ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_created       ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_orders_date          ON orders(order_date);
CREATE INDEX IF NOT EXISTS idx_order_items_order    ON order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_order_items_product  ON order_items(product_id);
CREATE INDEX IF NOT EXISTS idx_reservations_order   ON reservations(order_id);
CREATE INDEX IF NOT EXISTS idx_reservations_product ON reservations(product_id);
CREATE INDEX IF NOT EXISTS idx_reservations_date    ON reservations(order_date);
CREATE INDEX IF NOT EXISTS idx_reservations_status  ON reservations(status);
CREATE INDEX IF NOT EXISTS idx_history_order        ON order_status_history(order_id);
CREATE INDEX IF NOT EXISTS idx_logs_created         ON activity_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts       ON login_attempts(identifier, created_at);
CREATE INDEX IF NOT EXISTS idx_login_success        ON login_attempts(identifier, success, created_at);
SQL;

const DEFAULT_SETTINGS = [
    'ordering_start' => '07:30',
    'ordering_end'   => '12:00',
    'timezone'       => 'Asia/Tehran',
    'site_name'      => 'رستوران لیمو',
];

// ============================================================================
// SECTION: Migrations
// ============================================================================

function column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return false;
        }
        $rows = db_all($pdo, "PRAGMA table_info(" . $table . ")");
        foreach ($rows as $r) {
            if (($r['name'] ?? '') === $column) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
        return false;
    }
    try {
        $row = db_one($pdo,
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
            [$table]);
        return $row !== null;
    } catch (Throwable $e) {
        return false;
    }
}

function apply_migrations(PDO $pdo): void
{
    if (!column_exists($pdo, 'admins', 'must_change_credentials')) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec(
                "ALTER TABLE admins ADD COLUMN must_change_credentials "
                . "INTEGER NOT NULL DEFAULT 0 CHECK (must_change_credentials IN (0,1))"
            );
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
            throw $e;
        }
    }

    if (!column_exists($pdo, 'product_variants', 'independently_disabled')) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec(
                "ALTER TABLE product_variants ADD COLUMN independently_disabled "
                . "INTEGER NOT NULL DEFAULT 0 CHECK (independently_disabled IN (0,1))"
            );
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
            throw $e;
        }
    }

    if (!column_exists($pdo, 'admins', 'session_version')) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec(
                "ALTER TABLE admins ADD COLUMN session_version "
                . "INTEGER NOT NULL DEFAULT 1"
            );
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
            throw $e;
        }
    }
}

// ============================================================================
// SECTION: Default Admin Creation (FIX-B: رمز در لاگ وب چاپ نمی‌شود)
// ============================================================================

function create_default_admin(PDO $pdo): void
{
    $count = (int)db_value($pdo, 'SELECT COUNT(*) AS c FROM admins');
    if ($count > 0) {
        return;
    }

    $initialUsername = env_str('LIMO_INITIAL_ADMIN_USER', 'admin');
    $initialPassword = bin2hex(random_bytes(8));
    $pwdHash = password_hash($initialPassword, PASSWORD_DEFAULT);

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admins (username, password_hash, active, must_change_credentials, session_version) '
            . 'VALUES (?, ?, 1, 1, 1)'
        );
        $stmt->execute([$initialUsername, $pwdHash]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        throw $e;
    }

    $banner = PHP_EOL
        . "╔══════════════════════════════════════════════════════════════╗" . PHP_EOL
        . "║  🍋 LIMO — ادمین پیش‌فرض ساخته شد                            ║" . PHP_EOL
        . "╠══════════════════════════════════════════════════════════════╣" . PHP_EOL
        . sprintf("║  نام کاربری : %-45s ║", $initialUsername) . PHP_EOL
        . sprintf("║  رمز عبور   : %-45s ║", $initialPassword) . PHP_EOL
        . "║                                                              ║" . PHP_EOL
        . "║  ⚠️  این رمز فقط یک‌بار نمایش داده می‌شود.                  ║" . PHP_EOL
        . "║  ⚠️  در اولین ورود، تغییر آن الزامی است.                    ║" . PHP_EOL
        . "╚══════════════════════════════════════════════════════════════╝" . PHP_EOL
        . PHP_EOL;

    // FIX-B: فقط در CLI رمز چاپ می‌شود. در وب هرگز.
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $banner);
        log_warn('🍋 ادمین پیش‌فرض ساخته شد. رمز در کنسول نمایش داده شد. تغییر رمز در اولین ورود الزامی است.');
    } else {
        // در محیط وب: فقط یک پیام کوتاه بدون رمز در لاگ
        log_warn('🍋 ادمین پیش‌فرض ساخته شد. برای مشاهده رمز، از CLI استفاده کنید: php main.php show-admin-password');
    }
}

// ============================================================================
// SECTION: Init DB
// ============================================================================

function init_db(): void
{
    $pdo = get_db();

    try {
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');

        $pdo->exec(SCHEMA_SQL);

        apply_migrations($pdo);

        $exists = db_value($pdo, 'SELECT version FROM schema_version WHERE version = ?', [SCHEMA_VERSION]);
        if ($exists === null) {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO schema_version (version) VALUES (?)');
            $stmt->execute([SCHEMA_VERSION]);
        }

        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
        foreach (DEFAULT_SETTINGS as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        create_default_admin($pdo);

        log_info('Database initialized', ['version' => SCHEMA_VERSION]);
    } catch (Throwable $e) {
        log_error('init_db failed', ['error' => $e->getMessage()]);
        throw $e;
    }
}

// ============================================================================
// SECTION: JSON Response Helpers
// ============================================================================

function json_ok($data = null, int $status = 200): void
{
    if (headers_sent()) {
        return;
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => true, 'data' => $data ?? new stdClass()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function json_err(string $message, int $status = 400, ?string $code = null): void
{
    if (headers_sent()) {
        return;
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['success' => false, 'message' => $message];
    if ($code !== null) {
        $payload['code'] = $code;
    }
    if (!empty($GLOBALS['LIMO_REQUEST_ID'])) {
        $payload['request_id'] = $GLOBALS['LIMO_REQUEST_ID'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================================
// SECTION: Error Handlers
// ============================================================================

function wants_json(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (is_api_path($path)) {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return (strpos($accept, 'application/json') !== false)
        && (strpos($accept, 'text/html') === false);
}

function handle_error(int $status, string $message): void
{
    $rid = $GLOBALS['LIMO_REQUEST_ID'] ?? null;

    if (wants_json()) {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        $payload = [
            'success' => false,
            'message' => $message,
        ];
        if ($rid !== null) {
            $payload['request_id'] = $rid;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } else {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        $indexFile = LIMO_BASE_DIR . '/index.html';
        if (is_file($indexFile)) {
            readfile($indexFile);
        } else {
            echo '<!DOCTYPE html><html><body><h1>' . $status . '</h1><p>' . htmlspecialchars($message) . '</p></body></html>';
        }
    }
    exit;
}

// ============================================================================
// SECTION: Static File Serving
// ============================================================================

function serve_static(string $file, string $mime): void
{
    $full = LIMO_BASE_DIR . '/' . $file;
    if (!is_file($full)) {
        handle_error(404, 'منبع مورد نظر یافت نشد.');
    }
    if (!headers_sent()) {
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: public, max-age=3600');
    }
    readfile($full);
    exit;
}

// ============================================================================
// SECTION: Health Check
// ============================================================================

function handle_health(): void
{
    $dbOk = false;
    $dbErr = null;
    try {
        $pdo = get_db();
        $pdo->query('SELECT 1')->fetch();
        $dbOk = true;
    } catch (Throwable $e) {
        $dbOk = false;
        $dbErr = $e->getMessage();
    }

    $payload = [
        'status'    => $dbOk ? 'ok' : 'degraded',
        'service'   => 'limo',
        'version'   => '1.0.0',
        'database'  => $dbOk ? 'up' : 'down',
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (!$dbOk && !LIMO_IS_PROD && $dbErr) {
        $payload['db_error'] = $dbErr;
    }

    if (!headers_sent()) {
        http_response_code($dbOk ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// SECTION: Bootstrap Sequence
// ============================================================================

function limo_bootstrap(): void
{
    $GLOBALS['LIMO_REQUEST_ID'] = generate_request_id();

    apply_security_headers();
    bootstrap_session();

    if (!headers_sent()) {
        header('X-Request-ID: ' . $GLOBALS['LIMO_REQUEST_ID']);
    }
}

function limo_ensure_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $needsInit = true;

    try {
        if (!is_file(LIMO_DB_PATH)) {
            $needsInit = true;
        } else {
            $pdo = get_db();

            if (!table_exists($pdo, 'admins')) {
                $needsInit = true;
            } elseif (!table_exists($pdo, 'settings')) {
                $needsInit = true;
            } elseif (!table_exists($pdo, 'schema_version')) {
                $needsInit = true;
            } else {
                $cnt = (int)(db_value($pdo, 'SELECT COUNT(*) AS c FROM admins') ?? 0);
                if ($cnt === 0) {
                    $needsInit = true;
                } else {
                    $ver = (int)(db_value($pdo, 'SELECT MAX(version) AS v FROM schema_version') ?? 0);
                    $needsInit = ($ver < SCHEMA_VERSION);
                }
            }
        }
    } catch (Throwable $e) {
        $needsInit = true;
    }

    if ($needsInit) {
        try {
            init_db();
            @file_put_contents(LIMO_INIT_MARKER, date('c'), LOCK_EX);
        } catch (Throwable $e) {
            log_error('limo_ensure_init failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    } else {
        if (!is_file(LIMO_INIT_MARKER)) {
            @file_put_contents(LIMO_INIT_MARKER, date('c'), LOCK_EX);
        }
    }

    $done = true;
}

// ============================================================================
// SECTION: FIX-A — Front Controller
// ============================================================================

/**
 * FIX-A: نقطه ورود واحد برای همه درخواست‌ها
 * - API → api.php
 * - Static files → serve_static
 * - SPA routes (/, /admin) → index.html
 * - 404 → handle_error
 *
 * این تابع را در index.php صدا بزنید.
 */
function limo_route_request(): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // 1) API routes
    if (is_api_path($path)) {
        // api.php خودش dispatch می‌کند
        require_once LIMO_BASE_DIR . '/api.php';
        return;
    }

    // 2) Static files
    if (preg_match('#^/(style\.css|app\.js)$#', $path, $m)) {
        $mime = $m[1] === 'style.css' ? 'text/css' : 'application/javascript';
        serve_static($m[1], $mime);
    }

    // 3) SPA fallback
    if ($path === '/' || $path === '/admin' || $path === '/admin/' || $path === '/index.html') {
        serve_static('index.html', 'text/html');
    }

    // 4) 404
    handle_error(404, 'منبع مورد نظر یافت نشد.');
}

// ============================================================================
// SECTION: Auto-bootstrap
// ============================================================================

// فقط در CLI بوت‌استرپ خودکار انجام نمی‌شود
// در وب، index.php که فراخوانی می‌شود، بوت‌استرپ را انجام می‌دهد.
if (PHP_SAPI !== 'cli') {
    limo_bootstrap();
    limo_ensure_init();
}

// ============================================================================
// SECTION: CLI Mode
// ============================================================================

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $cmd = $argv[1] ?? 'init';

    switch ($cmd) {
        case 'init':
            init_db();
            fwrite(STDOUT, "✅ Database initialized." . PHP_EOL);
            break;

        case 'health':
            try {
                $pdo = get_db();
                $pdo->query('SELECT 1')->fetch();
                fwrite(STDOUT, "✅ DB OK" . PHP_EOL);
            } catch (Throwable $e) {
                fwrite(STDERR, "❌ DB FAIL: " . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            break;

        case 'reset-init-marker':
            @unlink(LIMO_INIT_MARKER);
            fwrite(STDOUT, "✅ init marker removed." . PHP_EOL);
            break;

        case 'check-tables':
            try {
                $pdo = get_db();
                $tables = ['admins', 'customers', 'products', 'product_variants',
                           'orders', 'order_items', 'reservations', 'settings',
                           'activity_logs', 'login_attempts', 'schema_version',
                           'order_status_history'];
                $missing = [];
                foreach ($tables as $t) {
                    if (!table_exists($pdo, $t)) {
                        $missing[] = $t;
                    }
                }
                if (empty($missing)) {
                    $cnt = (int)(db_value($pdo, 'SELECT COUNT(*) AS c FROM admins') ?? 0);
                    $ver = (int)(db_value($pdo, 'SELECT MAX(version) AS v FROM schema_version') ?? 0);
                    fwrite(STDOUT, "✅ All tables OK. Admins: $cnt, Schema: v$ver" . PHP_EOL);
                } else {
                    fwrite(STDERR, "❌ Missing tables: " . implode(', ', $missing) . PHP_EOL);
                    exit(1);
                }
            } catch (Throwable $e) {
                fwrite(STDERR, "❌ " . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            break;

        case 'show-admin-password':
            // FIX-B: راه‌حل برای نمایش رمز ادمین پیش‌فرض در CLI
            fwrite(STDOUT, "⚠️  رمز ادمین پیش‌فرض فقط در لحظه ساخت قابل مشاهده است." . PHP_EOL);
            fwrite(STDOUT, "   اگر رمز را گم کرده‌اید، از پنل مدیریت رمز را تغییر دهید." . PHP_EOL);
            break;

        default:
            fwrite(STDERR, "Usage: php main.php [init|health|reset-init-marker|check-tables|show-admin-password]" . PHP_EOL);
            exit(1);
    }
    exit(0);
}