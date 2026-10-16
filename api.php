<?php
/**
 * 🍋 LIMO RESTAURANT — API Blueprint (Hardening Phase) — PHP Edition
 * ==================================================================
 * معادل api.py
 *
 * اصلاحات این نسخه (v5):
 * - FIX-C: [FIX] normalize_request_path حذف شد — از normalize_api_path در main.php استفاده می‌کنیم
 * - FIX-D: [FIX] route_customer_login — چک تکراری بهینه با کوئری مستقیم
 * - BUG-002: [FIX] route_customer_edit_order → snapshot صحیح $oldRes
 * - BUG-003: [FIX] check_ordering_window → جمعه + تنظیمات خراب = fail-safe
 * - BUG-004: [FIX] route_admin_settings_update → اعتبارسنجی کامل
 * - BUG-007: [FIX] route_customer_menu → یک‌بار محاسبه weekday_index
 * - BUG-009: [FIX] route_admin_report_orders_csv → LIMIT منطقی
 * - BUG-013: [FIX] client_ip → اعتبارسنجی IP و طول
 * - FIX-01:  [NEW] route_customer_logout تعریف شد
 * - FIX-02:  [FIX] session_regenerate_id قبل از $_SESSION=[] در customer_login
 * - FIX-03:  [FIX] session_regenerate_id قبل از $_SESSION=[] در admin_login
 * - FIX-04:  [FIX] session_regenerate_id قبل از $_SESSION=[] در change_credentials
 * - FIX-05:  [FIX] last_login_at با server_now (نه datetime('now'))
 * - FIX-06:  [FIX] create_order: خواندن capacity قبل از rollback
 * - FIX-07:  [FIX] edit_order: استفاده از $o2 به‌جای $o
 * - FIX-08:  [FIX] verify_password_compat: pbkdf2 length
 * - FIX-09:  [FIX] report_orders_csv: int cast برای order_id و quantity
 */

declare(strict_types=1);

// اطمینان از بارگذاری main.php (bootstrap + helpers)
if (!function_exists('get_db')) {
    require_once __DIR__ . '/main.php';
}

// ============================================================================
// SECTION: Constants
// ============================================================================

const VALID_CATEGORIES = ['food', 'drink', 'dessert'];
const VALID_STATUSES   = ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'];
const WORKING_DAYS     = [0, 1, 2, 3, 4, 5];

const MAX_QTY              = 100000;
const MAX_LEN_SHORT        = 120;
const MAX_LEN_LONG         = 500;
const MAX_LEN_SITE_NAME    = 120;
const MAX_LEN_TIMEZONE     = 64;
const MAX_ITEMS_PER_ORDER  = 50;
const MAX_TOKEN_LEN        = 64;
const MAX_CODE_LEN         = 64;

const ADMIN_USERNAME_MIN = 4;
const ADMIN_USERNAME_MAX = 64;
const ADMIN_PASSWORD_MIN = 8;
const ADMIN_PASSWORD_MAX = 128;

const DEFAULT_PAGE_LIMIT = 50;
const MAX_PAGE_LIMIT     = 200;

const RATE_LIMIT_WINDOW_MINUTES  = 10;
const RATE_LIMIT_MAX_ATTEMPTS    = 8;
const LOGIN_ATTEMPTS_RETENTION_DAYS = 30;

const BACKUP_RETENTION_COUNT = 20;

const DEFAULT_TZ = 'Asia/Tehran';

// BUG-009: سقف ردیف‌های گزارش CSV
const MAX_REPORT_ROWS = 50000;

// Regex patterns
const ADMIN_USERNAME_PATTERN  = '/^[A-Za-z0-9_.\-]+$/';
const CUSTOMER_CODE_PATTERN   = '/^[A-Za-z0-9_\-]{2,64}$/';
const DATE_PATTERN            = '/^\d{4}-\d{2}-\d{2}$/';
const HHMM_PATTERN            = '/^(\d{1,2}):(\d{2})$/';

// ============================================================================
// SECTION: Validation Helpers
// ============================================================================

function is_int_like($v): bool
{
    if (is_int($v)) {
        return true;
    }
    if (is_float($v) && floor($v) === $v) {
        return true;
    }
    if (is_string($v) && $v !== '' && preg_match('/^-?\d+$/', $v) === 1) {
        return true;
    }
    return false;
}

/**
 * @return array{0: ?int, 1: ?string}
 */
function validate_quantity($v): array
{
    if (!is_int_like($v)) {
        return [null, 'تعداد باید عدد باشد.'];
    }
    $q = (int)$v;
    if ($q <= 0) {
        return [null, 'تعداد باید بزرگ‌تر از صفر باشد.'];
    }
    if ($q > MAX_QTY) {
        return [null, 'تعداد بیش از حد مجاز است.'];
    }
    return [$q, null];
}

function clean_str($v, int $maxLen = MAX_LEN_SHORT): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? '1' : '';
    }
    $s = trim((string)$v);
    if (function_exists('mb_substr')) {
        if (mb_strlen($s, 'UTF-8') > $maxLen) {
            $s = mb_substr($s, 0, $maxLen, 'UTF-8');
        }
    } else {
        if (strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen);
        }
    }
    return $s;
}

function parse_bool($v, bool $default = false): bool
{
    if ($v === null) {
        return $default;
    }
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) || is_float($v)) {
        return $v != 0;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['false', '0', 'no', 'off', ''], true)) {
            return false;
        }
    }
    return $default;
}

/**
 * @return array{0: int, 1: int}
 */
function parse_page_args(): array
{
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : DEFAULT_PAGE_LIMIT;
    if ($page < 1) {
        $page = 1;
    }
    if ($limit < 1) {
        $limit = DEFAULT_PAGE_LIMIT;
    }
    if ($limit > MAX_PAGE_LIMIT) {
        $limit = MAX_PAGE_LIMIT;
    }
    return [$page, $limit];
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return $decoded;
}

// ============================================================================
// SECTION: Password Helpers
// ============================================================================

/**
 * @return array{0: int, 1: string, 2: string}
 */
function password_strength_score(?string $password): array
{
    if ($password === null || $password === '') {
        return [0, 'very_weak', 'خیلی ضعیف'];
    }
    $score = 0;
    $len = strlen($password);
    if ($len >= 8)  $score++;
    if ($len >= 12) $score++;
    if (preg_match('/[a-z]/', $password))   $score++;
    if (preg_match('/[A-Z]/', $password))   $score++;
    if (preg_match('/\d/', $password))      $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
    if ($score > 5) $score = 5;

    if ($score <= 1)      { $k = 'very_weak';   $l = 'خیلی ضعیف'; }
    elseif ($score === 2) { $k = 'weak';        $l = 'ضعیف'; }
    elseif ($score === 3) { $k = 'medium';      $l = 'متوسط'; }
    elseif ($score === 4) { $k = 'strong';      $l = 'قوی'; }
    else                  { $k = 'very_strong'; $l = 'خیلی قوی'; }
    return [$score, $k, $l];
}

/**
 * @return array{0: ?string, 1: ?string}
 */
function validate_admin_username($username): array
{
    if ($username === null) {
        return [null, 'نام کاربری الزامی است.'];
    }
    $u = trim((string)$username);
    if ($u === '') {
        return [null, 'نام کاربری الزامی است.'];
    }
    $len = function_exists('mb_strlen') ? mb_strlen($u, 'UTF-8') : strlen($u);
    if ($len < ADMIN_USERNAME_MIN) {
        return [null, 'نام کاربری باید حداقل ' . ADMIN_USERNAME_MIN . ' کاراکتر باشد.'];
    }
    if ($len > ADMIN_USERNAME_MAX) {
        return [null, 'نام کاربری نباید بیشتر از ' . ADMIN_USERNAME_MAX . ' کاراکتر باشد.'];
    }
    if (preg_match(ADMIN_USERNAME_PATTERN, $u) !== 1) {
        return [null, 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد.'];
    }
    return [$u, null];
}

/**
 * @return array{0: ?string, 1: ?string}
 */
function validate_admin_password($password, ?string $currentPassword = null): array
{
    if ($password === null) {
        return [null, 'رمز عبور جدید الزامی است.'];
    }
    $pw = (string)$password;
    if ($pw === '') {
        return [null, 'رمز عبور جدید الزامی است.'];
    }
    $len = strlen($pw);
    if ($len < ADMIN_PASSWORD_MIN) {
        return [null, 'رمز عبور باید حداقل ' . ADMIN_PASSWORD_MIN . ' کاراکتر باشد.'];
    }
    if ($len > ADMIN_PASSWORD_MAX) {
        return [null, 'رمز عبور نباید بیشتر از ' . ADMIN_PASSWORD_MAX . ' کاراکتر باشد.'];
    }
    if ($currentPassword !== null && $pw === $currentPassword) {
        return [null, 'رمز عبور جدید نباید با رمز عبور فعلی یکسان باشد.'];
    }
    [$score, , ] = password_strength_score($pw);
    if ($score < 2) {
        return [null, 'رمز عبور انتخاب‌شده بسیار ضعیف است. از ترکیب حروف، عدد و کاراکتر ویژه استفاده کنید.'];
    }
    return [$pw, null];
}

/**
 * FIX-08: pbkdf2 length از خود hash استخراج می‌شود (نه 0)
 */
function verify_password_compat(string $password, string $hash): bool
{
    if (strpos($hash, '$2y$') === 0 || strpos($hash, '$argon2') === 0) {
        return password_verify($password, $hash);
    }
    if (strpos($hash, 'pbkdf2:') === 0) {
        $parts = explode('$', $hash);
        if (count($parts) !== 3) {
            return false;
        }
        [$method, $salt, $storedHash] = $parts;
        $methodParts = explode(':', $method);
        if (count($methodParts) !== 3) {
            return false;
        }
        $algo = $methodParts[1];
        $iterations = (int)$methodParts[2];
        if ($iterations <= 0) {
            return false;
        }
        $length = (int)(strlen($storedHash) / 2);
        if ($length <= 0) {
            return false;
        }
        $computed = hash_pbkdf2($algo, $password, $salt, $iterations, $length);
        return hash_equals($storedHash, $computed);
    }
    return false;
}

// ============================================================================
// SECTION: Client / CSRF Helpers
// ============================================================================

/**
 * BUG-013: آی‌پی کلاینت با اعتبارسنجی
 */
function client_ip(): string
{
    global $TRUSTED_PROXIES;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!empty($TRUSTED_PROXIES) && isset($TRUSTED_PROXIES[$remote])) {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '' && strlen($fwd) < 256) {
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return $remote;
}

function ensure_csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// ============================================================================
// SECTION: Activity Logging
// ============================================================================

function log_activity(string $actorType, ?int $actorId, string $action, array $metadata = []): void
{
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs (actor_type, actor_id, action, metadata) VALUES (?, ?, ?, ?)'
        );
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $stmt->execute([$actorType, $actorId, $action, $json]);
    } catch (Throwable $e) {
        try {
            log_error('log_activity failed', [
                'error'  => $e->getMessage(),
                'action' => $action,
                'actor'  => $actorType . ':' . ($actorId ?? 'null'),
            ]);
        } catch (Throwable $e2) {
            error_log('limo: log_activity critical failure: ' . $e->getMessage());
        }
    }
}

// ============================================================================
// SECTION: Time Helpers
// ============================================================================

function get_setting(PDO $pdo, string $key, $default = null)
{
    $row = db_one($pdo, 'SELECT value FROM settings WHERE key = ?', [$key]);
    return $row ? $row['value'] : $default;
}

function get_timezone(?PDO $pdo = null): DateTimeZone
{
    $name = DEFAULT_TZ;
    try {
        if ($pdo === null) {
            $pdo = get_db();
        }
        $v = get_setting($pdo, 'timezone');
        if ($v) {
            $name = $v;
        }
    } catch (Throwable $e) {
    }
    try {
        return new DateTimeZone($name);
    } catch (Throwable $e) {
        try {
            return new DateTimeZone(DEFAULT_TZ);
        } catch (Throwable $e2) {
            return new DateTimeZone('UTC');
        }
    }
}

function server_now(?PDO $pdo = null): DateTime
{
    $tz = get_timezone($pdo);
    return new DateTime('now', $tz);
}

function today_str(?PDO $pdo = null): string
{
    return server_now($pdo)->format('Y-m-d');
}

function weekday_index(?DateTimeInterface $d = null, ?PDO $pdo = null): int
{
    if ($d === null) {
        $d = server_now($pdo);
    }
    $pyWd = ((int)$d->format('w') + 6) % 7;
    return ($pyWd + 2) % 7;
}

function is_friday(?DateTimeInterface $d = null, ?PDO $pdo = null): bool
{
    return weekday_index($d, $pdo) === 6;
}

/**
 * @return array{0: int, 1: int}|null
 */
function parse_hhmm($s): ?array
{
    if (!$s) {
        return null;
    }
    $str = trim((string)$s);
    if (preg_match(HHMM_PATTERN, $str, $m) !== 1) {
        return null;
    }
    $hh = (int)$m[1];
    $mm = (int)$m[2];
    if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) {
        return null;
    }
    return [$hh, $mm];
}

/**
 * @return array{0: ?array, 1: ?array}
 */
function ordering_window(PDO $pdo): array
{
    $start = parse_hhmm(get_setting($pdo, 'ordering_start', '07:30'));
    $end   = parse_hhmm(get_setting($pdo, 'ordering_end', '12:00'));
    return [$start, $end];
}

/**
 * BUG-003: بررسی مجاز بودن سفارش‌گیری
 * @return array{0: bool, 1: ?string}
 */
function check_ordering_window(PDO $pdo): array
{
    $now = server_now($pdo);
    $todayIdx = weekday_index($now, $pdo);

    if ($todayIdx === 6) {
        return [false, 'امروز جمعه است و سفارش‌گیری انجام نمی‌شود.'];
    }

    if (!in_array($todayIdx, WORKING_DAYS, true)) {
        return [false, 'امروز روز سفارش‌گیری نیست. لطفاً در روزهای کاری مراجعه کنید.'];
    }

    [$start, $end] = ordering_window($pdo);
    if ($start === null || $end === null) {
        return [false, 'تنظیمات ساعات سفارش‌گیری نامعتبر است. لطفاً با مدیر تماس بگیرید.'];
    }

    $curMinutes = (int)$now->format('H') * 60 + (int)$now->format('i');
    $startMin = $start[0] * 60 + $start[1];
    $endMin   = $end[0]   * 60 + $end[1];

    if ($curMinutes < $startMin) {
        return [false, 'زمان ثبت سفارش امروز هنوز شروع نشده است.'];
    }
    if ($curMinutes > $endMin) {
        return [false, 'زمان ثبت سفارش امروز به پایان رسیده است. لطفاً در زمان کاری رستوران مراجعه کنید.'];
    }
    return [true, null];
}

// ============================================================================
// SECTION: Capacity Core
// ============================================================================

function capacity_base(PDO $pdo, int $productId, ?int $variantId): ?int
{
    if ($variantId !== null && $variantId > 0) {
        $row = db_one($pdo,
            'SELECT base_capacity, active FROM product_variants WHERE id = ? AND product_id = ?',
            [$variantId, $productId]);
        if (!$row || (int)$row['active'] !== 1) {
            return null;
        }
        return (int)$row['base_capacity'];
    }
    $row = db_one($pdo,
        'SELECT base_capacity, active FROM products WHERE id = ?',
        [$productId]);
    if (!$row || (int)$row['active'] !== 1) {
        return null;
    }
    return (int)$row['base_capacity'];
}

function capacity_base_admin(PDO $pdo, int $productId, ?int $variantId): ?int
{
    if ($variantId !== null && $variantId > 0) {
        $row = db_one($pdo,
            'SELECT base_capacity FROM product_variants WHERE id = ? AND product_id = ?',
            [$variantId, $productId]);
        return $row ? (int)$row['base_capacity'] : null;
    }
    $row = db_one($pdo,
        'SELECT base_capacity FROM products WHERE id = ?',
        [$productId]);
    return $row ? (int)$row['base_capacity'] : null;
}

function reserved_quantity(PDO $pdo, int $productId, ?int $variantId, string $orderDate): int
{
    if ($variantId !== null && $variantId > 0) {
        $row = db_one($pdo,
            "SELECT COALESCE(SUM(quantity),0) AS s FROM reservations "
            . "WHERE product_id = ? AND variant_id = ? AND order_date = ? "
            . "AND status IN ('ACTIVE','CONSUMED')",
            [$productId, $variantId, $orderDate]);
    } else {
        $row = db_one($pdo,
            "SELECT COALESCE(SUM(quantity),0) AS s FROM reservations "
            . "WHERE product_id = ? AND variant_id IS NULL AND order_date = ? "
            . "AND status IN ('ACTIVE','CONSUMED')",
            [$productId, $orderDate]);
    }
    return $row ? (int)$row['s'] : 0;
}

function available_quantity(PDO $pdo, int $productId, ?int $variantId, string $orderDate): ?int
{
    $base = capacity_base($pdo, $productId, $variantId);
    if ($base === null) {
        return null;
    }
    $used = reserved_quantity($pdo, $productId, $variantId, $orderDate);
    return max(0, $base - $used);
}

function atomic_reserve(PDO $pdo, int $productId, ?int $variantId, string $orderDate, int $quantity): bool
{
    $base = capacity_base($pdo, $productId, $variantId);
    if ($base === null) {
        return false;
    }
    $used = reserved_quantity($pdo, $productId, $variantId, $orderDate);
    $available = $base - $used;
    return $available >= $quantity;
}

function product_label(PDO $pdo, int $pid, ?int $vid): string
{
    $p = db_one($pdo, 'SELECT name FROM products WHERE id = ?', [$pid]);
    $name = $p ? $p['name'] : ('#' . $pid);
    if ($vid) {
        $v = db_one($pdo, 'SELECT name FROM product_variants WHERE id = ?', [$vid]);
        if ($v) {
            $name .= ' (' . $v['name'] . ')';
        }
    }
    return $name;
}

// ============================================================================
// SECTION: Security Decorators
// ============================================================================

function load_admin_state(PDO $pdo, int $adminId): ?array
{
    return db_one($pdo,
        'SELECT id, username, password_hash, active, must_change_credentials, session_version '
        . 'FROM admins WHERE id = ?',
        [$adminId]);
}

function load_customer_state(PDO $pdo, int $customerId): ?array
{
    return db_one($pdo,
        'SELECT id, company_name, customer_code, phone, address, active '
        . 'FROM customers WHERE id = ?',
        [$customerId]);
}

function require_admin(): void
{
    $userType = $_SESSION['user_type'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if ($userType !== 'admin' || !$userId) {
        json_err('دسترسی غیرمجاز. لطفاً وارد شوید.', 401);
    }

    $pdo = get_db();
    $row = load_admin_state($pdo, (int)$userId);
    if (!$row || (int)$row['active'] !== 1) {
        $_SESSION = [];
        @session_destroy();
        json_err('حساب ادمین غیرفعال شده است. لطفاً دوباره وارد شوید.', 401);
    }

    $sessionVer = $_SESSION['session_version'] ?? null;
    $dbVer = isset($row['session_version']) ? (int)$row['session_version'] : 1;
    if ($sessionVer === null || (int)$sessionVer !== $dbVer) {
        $_SESSION = [];
        @session_destroy();
        json_err('نشست شما منقضی شده است. لطفاً دوباره وارد شوید.', 401, 'SESSION_STALE');
    }

    if ((int)$row['must_change_credentials'] === 1) {
        $allowed = [
            '/api/admin/account',
            '/api/admin/change-credentials',
            '/api/auth/admin/logout',
            '/api/auth/session',
        ];
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $normPath = normalize_api_path($path);
        $normPath = rtrim($normPath, '/');
        if (!in_array($normPath, $allowed, true)) {
            json_err(
                'برای دسترسی کامل، ابتدا نام کاربری و رمز عبور پیش‌فرض را تغییر دهید.',
                403,
                'MUST_CHANGE_CREDENTIALS'
            );
        }
    }
}

function require_customer(): void
{
    $userType = $_SESSION['user_type'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if ($userType !== 'customer' || !$userId) {
        json_err('دسترسی غیرمجاز. لطفاً وارد شوید.', 401);
    }

    $pdo = get_db();
    $row = load_customer_state($pdo, (int)$userId);
    if (!$row || (int)$row['active'] !== 1) {
        $_SESSION = [];
        @session_destroy();
        json_err('حساب مشتری غیرفعال شده است. لطفاً دوباره وارد شوید.', 401);
    }
}

// ============================================================================
// SECTION: Rate Limiting
// ============================================================================

function is_rate_limited(string $identifier): bool
{
    $pdo = get_db();
    $cutoff = gmdate('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW_MINUTES * 60);
    $row = db_one($pdo,
        'SELECT COUNT(*) AS c FROM login_attempts '
        . 'WHERE identifier = ? AND success = 0 AND created_at >= ?',
        [$identifier, $cutoff]);
    return $row && (int)$row['c'] >= RATE_LIMIT_MAX_ATTEMPTS;
}

function record_attempt(string $identifier, bool $success): void
{
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)'
        );
        $stmt->execute([$identifier, client_ip(), $success ? 1 : 0]);
    } catch (Throwable $e) {
        log_error('record_attempt failed', ['error' => $e->getMessage()]);
    }
}

// ============================================================================
// SECTION: Menu Helpers
// ============================================================================

function menu_available_days_filter($availableDaysStr, int $todayIdx): bool
{
    if ($availableDaysStr === null || $availableDaysStr === '') {
        return true;
    }
    $parts = explode(',', (string)$availableDaysStr);
    $days = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (!preg_match('/^-?\d+$/', $p)) {
            return false;
        }
        $days[] = (int)$p;
    }
    return in_array($todayIdx, $days, true);
}

// ============================================================================
// SECTION: Order Helpers
// ============================================================================

function order_full(PDO $pdo, int $orderId, ?int $customerId = null): ?array
{
    $sql = 'SELECT * FROM orders WHERE id = ?';
    $params = [$orderId];
    if ($customerId !== null) {
        $sql .= ' AND customer_id = ?';
        $params[] = $customerId;
    }
    $o = db_one($pdo, $sql, $params);
    if (!$o) {
        return null;
    }

    $items = db_all($pdo,
        'SELECT oi.id, oi.product_id, oi.variant_id, oi.quantity, '
        . '       p.name AS product_name, p.category, '
        . '       pv.name AS variant_name '
        . 'FROM order_items oi '
        . 'JOIN products p ON p.id = oi.product_id '
        . 'LEFT JOIN product_variants pv ON pv.id = oi.variant_id '
        . 'WHERE oi.order_id = ? ORDER BY oi.id',
        [$orderId]);

    $history = db_all($pdo,
        'SELECT old_status, new_status, reason, actor_type, created_at '
        . 'FROM order_status_history WHERE order_id = ? ORDER BY id',
        [$orderId]);

    return [
        'id'               => (int)$o['id'],
        'customer_id'      => (int)$o['customer_id'],
        'status'           => $o['status'],
        'rejection_reason' => $o['rejection_reason'],
        'order_date'       => $o['order_date'],
        'created_at'       => $o['created_at'],
        'updated_at'       => $o['updated_at'],
        'approved_at'      => $o['approved_at'],
        'rejected_at'      => $o['rejected_at'],
        'cancelled_at'     => $o['cancelled_at'],
        'items' => array_map(function ($it) {
            return [
                'id'           => (int)$it['id'],
                'product_id'   => (int)$it['product_id'],
                'variant_id'   => $it['variant_id'] !== null ? (int)$it['variant_id'] : null,
                'product_name' => $it['product_name'],
                'variant_name' => $it['variant_name'],
                'category'     => $it['category'],
                'quantity'     => (int)$it['quantity'],
            ];
        }, $items),
        'history' => $history,
    ];
}

function customer_today_order(PDO $pdo, int $customerId): ?int
{
    $today = today_str($pdo);
    $row = db_one($pdo,
        "SELECT id FROM orders WHERE customer_id = ? AND order_date = ? "
        . "AND status IN ('PENDING','APPROVED') ORDER BY id DESC LIMIT 1",
        [$customerId, $today]);
    return $row ? (int)$row['id'] : null;
}

/**
 * @return array{0: ?array, 1: ?string}
 */
function validate_items_payload(PDO $pdo, $items, string $todayStr): array
{
    if (!is_array($items) || count($items) === 0) {
        return [null, 'سبد سفارش خالی است.'];
    }
    if (count($items) > MAX_ITEMS_PER_ORDER) {
        return [null, 'حداکثر ' . MAX_ITEMS_PER_ORDER . ' قلم در هر سفارش مجاز است.'];
    }

    $normalized = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            return [null, 'ساختار آیتم نامعتبر است.'];
        }
        $pid = $it['product_id'] ?? null;
        $vid = $it['variant_id'] ?? null;
        [$qty, $e] = validate_quantity($it['quantity'] ?? null);
        if ($e) {
            return [null, $e];
        }
        if (!is_int_like($pid)) {
            return [null, 'شناسه محصول نامعتبر است.'];
        }
        $pid = (int)$pid;

        if ($vid !== null && $vid !== '' && $vid !== 0 && $vid !== '0') {
            if (!is_int_like($vid)) {
                return [null, 'شناسه Variant نامعتبر است.'];
            }
            $vid = (int)$vid;
        } else {
            $vid = null;
        }

        $p = db_one($pdo,
            'SELECT id, active, available_days FROM products WHERE id = ?',
            [$pid]);
        if (!$p || (int)$p['active'] !== 1) {
            return [null, 'محصول انتخابی معتبر نیست.'];
        }

        if (!menu_available_days_filter($p['available_days'], weekday_index(null, $pdo))) {
            return [null, 'این محصول امروز قابل سفارش نیست.'];
        }

        if ($vid) {
            $v = db_one($pdo,
                'SELECT id FROM product_variants WHERE id = ? AND product_id = ? AND active = 1',
                [$vid, $pid]);
            if (!$v) {
                return [null, 'Variant انتخابی معتبر نیست.'];
            }
        } else {
            $hasV = db_one($pdo,
                'SELECT 1 AS x FROM product_variants WHERE product_id = ? AND active = 1 LIMIT 1',
                [$pid]);
            if ($hasV) {
                return [null, 'برای این محصول باید یکی از گزینه‌ها انتخاب شود.'];
            }
        }

        $normalized[] = ['product_id' => $pid, 'variant_id' => $vid, 'quantity' => $qty];
    }

    return [$normalized, null];
}

// ============================================================================
// SECTION: Routing
// ============================================================================

function current_path(): string
{
    return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
}

function current_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

// FIX-C: normalize_request_path حذف شد — از normalize_api_path در main.php استفاده می‌کنیم

// ============================================================================
// SECTION: AUTH — Customer
// ============================================================================

/**
 * نرمال‌سازی شماره موبایل ایران
 * - قالب‌های معتبر: 09XXXXXXXXX ، 989XXXXXXXXX ، +989XXXXXXXXX
 * - خروجی استاندارد: 09XXXXXXXXX (۱۱ رقم)
 * - ورودی نامعتبر → null
 */
function normalize_iran_mobile($raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $s = trim((string)$raw);

    // حذف کاراکترهای رایج جداکننده
    $s = str_replace([' ', '-', '(', ')', '.'], '', $s);

    // حذف ارقام فارسی/عربی در صورت وجود
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $s = str_replace($fa, ['0','1','2','3','4','5','6','7','8','9'], $s);
    $s = str_replace($ar, ['0','1','2','3','4','5','6','7','8','9'], $s);

    // فقط ارقام و علامت + باقی بماند
    if (preg_match('/^\+?\d+$/', $s) !== 1) {
        return null;
    }

    // قالب +989XXXXXXXXX
    if (strpos($s, '+98') === 0 && strlen($s) === 13) {
        $s = '0' . substr($s, 3);
    }
    // قالب 989XXXXXXXXX
    elseif (strpos($s, '98') === 0 && strlen($s) === 12) {
        $s = '0' . substr($s, 2);
    }
    // قالب 9XXXXXXXXX (بدون 0 ابتدایی)
    elseif (strlen($s) === 10 && $s[0] === '9') {
        $s = '0' . $s;
    }

    // حالا باید 11 رقم و با 09 شروع شود
    if (strlen($s) !== 11 || strpos($s, '09') !== 0) {
        return null;
    }
    return $s;
}

function route_customer_login(): void
{
    $payload = read_json_body();
    $code  = clean_str($payload['customer_code'] ?? null, MAX_CODE_LEN);
    $rawPhone = $payload['phone'] ?? null;

    if ($code === '') {
        json_err('کد مشتری الزامی است.', 400);
    }
    if ($rawPhone === null || trim((string)$rawPhone) === '') {
        json_err('شماره موبایل الزامی است.', 400);
    }

    // نرمال‌سازی شماره موبایل
    $phone = normalize_iran_mobile($rawPhone);
    if ($phone === null) {
        record_attempt('cust:' . $code, false);
        json_err('کد مشتری یا شماره موبایل صحیح نیست.', 401);
    }

    // Rate limiting: از ترکیب code+phone به‌عنوان identifier
    $identifier = 'cust:' . $code . ':' . $phone;
    if (is_rate_limited($identifier)) {
        json_err('تلاش‌های ناموفق زیاد. لطفاً چند دقیقه بعد دوباره تلاش کنید.', 429);
    }

    $pdo = get_db();

    // ⚠️ اعتبارسنجی به‌صورت یک مجموعه: هر دو مقدار با یک رکورد واحد تطبیق داده شوند
    $row = db_one(
        $pdo,
        'SELECT id, company_name, customer_code, phone, active '
        . 'FROM customers WHERE customer_code = ?',
        [$code]
    );

    // پیام خطای عمومی برای همه حالات ناموفق (عدم افشای اطلاعات)
    $genericFail = function () use ($identifier): void {
        record_attempt($identifier, false);
        json_err('کد مشتری یا شماره موبایل صحیح نیست.', 401);
    };

    if (!$row) {
        $genericFail();
    }
    if ((int)$row['active'] !== 1) {
        $genericFail();
    }

    // نرمال‌سازی شماره ثبت‌شده در دیتابیس و مقایسه
    $dbPhone = normalize_iran_mobile($row['phone']);
    if ($dbPhone === null || $dbPhone !== $phone) {
        $genericFail();
    }

    // FIX-D: بررسی شماره تکراری بین چند مشتری — بهینه با کوئری مستقیم
    try {
        $dup = db_one(
            $pdo,
            'SELECT id FROM customers WHERE active = 1 AND phone = ? AND id <> ? LIMIT 1',
            [$phone, (int)$row['id']]
        );
    } catch (Throwable $e) {
        log_error('customer_login dup check failed', ['error' => $e->getMessage()]);
        $genericFail();
    }

    if ($dup) {
        log_warn('customer_login ambiguous phone', [
            'customer_id' => (int)$row['id'],
        ]);
        record_attempt($identifier, false);
        json_err(
            'این شماره موبایل به چند حساب متصل است. لطفاً با پشتیبانی تماس بگیرید.',
            409,
            'AMBIGUOUS_PHONE'
        );
    }

    // ✅ ورود موفق — FIX-02: regenerate قبل از پاک‌سازی
    @session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_type'] = 'customer';
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['customer_code'] = $row['customer_code'];
    $_SESSION['_created_at'] = time();

    $csrf = ensure_csrf_token();

    record_attempt($identifier, true);
    log_activity('CUSTOMER', (int)$row['id'], 'LOGIN');

    json_ok([
        'id'            => (int)$row['id'],
        'company_name'  => $row['company_name'],
        'customer_code' => $row['customer_code'],
        'csrf_token'    => $csrf,
    ]);
}

/**
 * ============================================================================
 * Customer Logout — خروج مشتری از حساب
 * ============================================================================
 * - پاک‌سازی کامل session
 * - حذف فیزیکی کوکی session
 * - ثبت فعالیت LOGOUT (fail-safe)
 */
function route_customer_logout(): void
{
    $uid = $_SESSION['user_id'] ?? null;
    $userType = $_SESSION['user_type'] ?? null;

    if ($uid !== null && $userType === 'customer') {
        try {
            log_activity('CUSTOMER', (int)$uid, 'LOGOUT');
        } catch (Throwable $e) {
            log_error('customer_logout: log_activity failed', [
                'error'   => $e->getMessage(),
                'user_id' => (int)$uid,
            ]);
        }
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        if (!headers_sent()) {
            $cookieOptions = [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ];
            try {
                setcookie(session_name(), '', $cookieOptions);
            } catch (Throwable $e) {
                @setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $cookieOptions['path'],
                    $cookieOptions['domain'],
                    $cookieOptions['secure'],
                    $cookieOptions['httponly']
                );
            }
        }
    }

    @session_destroy();

    json_ok([
        'message'       => 'با موفقیت خارج شدید.',
        'logged_out_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

// ============================================================================
// SECTION: AUTH — Admin
// ============================================================================

function route_admin_login(): void
{
    $payload = read_json_body();
    $username = clean_str($payload['username'] ?? null, ADMIN_USERNAME_MAX);
    $password = (string)($payload['password'] ?? '');

    if ($username === '' || $password === '') {
        json_err('نام کاربری و رمز عبور الزامی است.', 400);
    }

    $identifier = 'admin:' . $username;
    if (is_rate_limited($identifier)) {
        json_err('تلاش‌های ناموفق زیاد. لطفاً چند دقیقه بعد دوباره تلاش کنید.', 429);
    }

    $pdo = get_db();
    $row = db_one($pdo,
        'SELECT id, username, password_hash, active, must_change_credentials, session_version '
        . 'FROM admins WHERE username = ?',
        [$username]);

    if (!$row || (int)$row['active'] !== 1 || !verify_password_compat($password, $row['password_hash'])) {
        record_attempt($identifier, false);
        log_activity('ADMIN', null, 'ADMIN_LOGIN_FAILED', ['username' => $username]);
        json_err('نام کاربری یا رمز عبور نادرست است.', 401);
    }

    // FIX-05: last_login_at با server_now (نه datetime('now'))
    try {
        $now = server_now($pdo)->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?');
        $stmt->execute([$now, (int)$row['id']]);
    } catch (Throwable $e) {
        log_error('last_login_at update failed', ['error' => $e->getMessage()]);
    }

    // FIX-03: session regenerate قبل از پاک‌سازی
    @session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_type'] = 'admin';
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['session_version'] = isset($row['session_version']) ? (int)$row['session_version'] : 1;
    $_SESSION['_created_at'] = time();

    $csrf = ensure_csrf_token();
    $mustChange = (int)$row['must_change_credentials'] === 1;

    record_attempt($identifier, true);
    log_activity('ADMIN', (int)$row['id'], 'ADMIN_LOGIN_SUCCESS');

    json_ok([
        'id'                      => (int)$row['id'],
        'username'                => $row['username'],
        'must_change_credentials' => $mustChange,
        'csrf_token'              => $csrf,
    ]);
}

function route_admin_logout(): void
{
    $uid = $_SESSION['user_id'] ?? null;
    $_SESSION = [];
    @session_destroy();
    if ($uid) {
        log_activity('ADMIN', (int)$uid, 'ADMIN_LOGOUT');
    }
    json_ok();
}

function route_auth_session(): void
{
    $userType = $_SESSION['user_type'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if (!$userType || !$userId) {
        json_ok(['authenticated' => false]);
    }

    $csrf = ensure_csrf_token();
    $pdo = get_db();

    if ($userType === 'customer') {
        $row = load_customer_state($pdo, (int)$userId);
        if (!$row || (int)$row['active'] !== 1) {
            $_SESSION = [];
            @session_destroy();
            json_ok(['authenticated' => false]);
        }
        json_ok([
            'authenticated' => true,
            'user_type'     => 'customer',
            'csrf_token'    => $csrf,
            'user' => [
                'id'            => (int)$row['id'],
                'company_name'  => $row['company_name'],
                'customer_code' => $row['customer_code'],
                'phone'         => $row['phone'],
                'address'       => $row['address'],
            ],
        ]);
    } elseif ($userType === 'admin') {
        $row = load_admin_state($pdo, (int)$userId);
        if (!$row || (int)$row['active'] !== 1) {
            $_SESSION = [];
            @session_destroy();
            json_ok(['authenticated' => false]);
        }

        $sessionVer = $_SESSION['session_version'] ?? null;
        $dbVer = isset($row['session_version']) ? (int)$row['session_version'] : 1;
        if ($sessionVer === null || (int)$sessionVer !== $dbVer) {
            $_SESSION = [];
            @session_destroy();
            json_ok(['authenticated' => false]);
        }

        json_ok([
            'authenticated' => true,
            'user_type'     => 'admin',
            'csrf_token'    => $csrf,
            'must_change_credentials' => (int)$row['must_change_credentials'] === 1,
            'user' => [
                'id'                      => (int)$row['id'],
                'username'                => $row['username'],
                'must_change_credentials' => (int)$row['must_change_credentials'] === 1,
            ],
        ]);
    }

    $_SESSION = [];
    @session_destroy();
    json_ok(['authenticated' => false]);
}

// ============================================================================
// SECTION: ADMIN — Account / Credentials
// ============================================================================

function route_admin_account_get(): void
{
    require_admin();
    $pdo = get_db();
    $row = db_one($pdo,
        'SELECT id, username, must_change_credentials, created_at, updated_at, last_login_at '
        . 'FROM admins WHERE id = ?',
        [(int)$_SESSION['user_id']]);
    if (!$row) {
        $_SESSION = [];
        @session_destroy();
        json_err('حساب ادمین یافت نشد.', 404);
    }
    json_ok([
        'id'                      => (int)$row['id'],
        'username'                => $row['username'],
        'must_change_credentials' => (int)$row['must_change_credentials'] === 1,
        'created_at'              => $row['created_at'],
        'updated_at'              => $row['updated_at'],
        'last_login_at'           => $row['last_login_at'],
    ]);
}

function route_admin_change_credentials(): void
{
    require_admin();

    $payload = read_json_body();
    $currentPassword = (string)($payload['current_password'] ?? '');
    if ($currentPassword === '') {
        json_err('رمز عبور فعلی الزامی است.', 422);
    }

    $rawNewUsername = $payload['new_username'] ?? null;
    $rawNewPassword = $payload['new_password'] ?? null;
    $rawConfirm     = $payload['confirm_password'] ?? null;

    $usernameProvided = $rawNewUsername !== null && trim((string)$rawNewUsername) !== '';
    $passwordProvided = $rawNewPassword !== null && (string)$rawNewPassword !== '';

    if (!$usernameProvided && !$passwordProvided) {
        json_err('هیچ تغییری ارسال نشده است.', 422);
    }

    $newUsername = null;
    if ($usernameProvided) {
        [$newUsername, $e] = validate_admin_username($rawNewUsername);
        if ($e) {
            json_err($e, 422);
        }
    }

    $newPasswordChecked = null;
    if ($passwordProvided) {
        $confirmPassword = (string)($rawConfirm ?? '');
        if ((string)$rawNewPassword !== $confirmPassword) {
            json_err('رمز عبور جدید و تکرار آن یکسان نیستند.', 422);
        }
        [$newPasswordChecked, $e] = validate_admin_password($rawNewPassword, $currentPassword);
        if ($e) {
            json_err($e, 422);
        }
    }

    $pdo = get_db();
    $row = load_admin_state($pdo, (int)$_SESSION['user_id']);
    if (!$row || (int)$row['active'] !== 1) {
        $_SESSION = [];
        @session_destroy();
        json_err('حساب ادمین یافت نشد یا غیرفعال است.', 404);
    }

    if (!verify_password_compat($currentPassword, $row['password_hash'])) {
        log_activity('ADMIN', (int)$row['id'], 'ADMIN_CREDENTIALS_CHANGE_FAILED',
            ['reason' => 'invalid_current_password']);
        json_err('رمز عبور فعلی صحیح نیست.', 401);
    }

    $oldUsername = $row['username'];
    $usernameChanged = false;
    $passwordChanged = false;

    $finalUsername = $oldUsername;
    if ($usernameProvided && $newUsername !== $oldUsername) {
        $dup = db_one($pdo,
            'SELECT id FROM admins WHERE username = ? AND id != ?',
            [$newUsername, (int)$row['id']]);
        if ($dup) {
            json_err('این نام کاربری قبلاً استفاده شده است.', 409);
        }
        $finalUsername = $newUsername;
        $usernameChanged = true;
    }

    $finalPasswordHash = $row['password_hash'];
    if ($passwordProvided && $newPasswordChecked !== null) {
        $finalPasswordHash = password_hash($newPasswordChecked, PASSWORD_DEFAULT);
        $passwordChanged = true;
    }

    if (!$usernameChanged && !$passwordChanged) {
        json_err('هیچ تغییری اعمال نشد (مقادیر جدید با فعلی یکسان هستند).', 422);
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->prepare(
            "UPDATE admins SET username = ?, password_hash = ?, "
            . "must_change_credentials = 0, session_version = session_version + 1, "
            . "updated_at = datetime('now') "
            . "WHERE id = ?"
        );
        $stmt->execute([$finalUsername, $finalPasswordHash, (int)$row['id']]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        throw $e;
    }

    if ($usernameChanged) {
        log_activity('ADMIN', (int)$row['id'], 'ADMIN_USERNAME_CHANGED',
            ['old' => $oldUsername, 'new' => $finalUsername]);
    }
    if ($passwordChanged) {
        log_activity('ADMIN', (int)$row['id'], 'ADMIN_PASSWORD_CHANGED');
    }
    log_activity('ADMIN', (int)$row['id'], 'ADMIN_CREDENTIALS_CHANGED', [
        'username_changed' => $usernameChanged,
        'password_changed' => $passwordChanged,
    ]);

    // FIX-04: session regenerate قبل از پاک‌سازی
    $newRow = load_admin_state($pdo, (int)$row['id']);
    @session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_type'] = 'admin';
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['session_version'] = isset($newRow['session_version']) ? (int)$newRow['session_version'] : 1;
    $_SESSION['_created_at'] = time();
    $newCsrf = ensure_csrf_token();

    json_ok([
        'username'                => $finalUsername,
        'must_change_credentials' => false,
        'username_changed'        => $usernameChanged,
        'password_changed'        => $passwordChanged,
        'csrf_token'              => $newCsrf,
        'message'                 => 'اطلاعات حساب با موفقیت به‌روزرسانی شد. سایر دستگاه‌ها از حساب خارج شدند.',
    ]);
}

// ============================================================================
// SECTION: CUSTOMER — Profile / Menu
// ============================================================================

function route_customer_profile(): void
{
    require_customer();
    $pdo = get_db();
    $row = db_one($pdo,
        'SELECT id, company_name, customer_code, phone, address FROM customers WHERE id = ?',
        [(int)$_SESSION['user_id']]);
    if (!$row) {
        json_err('مشتری یافت نشد.', 404);
    }
    json_ok([
        'id'            => (int)$row['id'],
        'company_name'  => $row['company_name'],
        'customer_code' => $row['customer_code'],
        'phone'         => $row['phone'],
        'address'       => $row['address'],
    ]);
}

/**
 * BUG-007: یک‌بار weekday_index محاسبه شود
 */
function route_customer_menu(): void
{
    require_customer();

    $now = server_now();
    $today = $now->format('Y-m-d');
    $todayIdx = weekday_index($now);

    $pdo = get_db();
    [$orderingAllowed, $windowMsg] = check_ordering_window($pdo);

    $products = db_all($pdo,
        'SELECT id, name, category, description, base_capacity, available_days '
        . 'FROM products WHERE active = 1 ORDER BY category, name');

    if (!empty($products)) {
        $pids = array_map(fn($p) => (int)$p['id'], $products);
        $placeholders = implode(',', array_fill(0, count($pids), '?'));

        $variantRows = db_all($pdo,
            "SELECT id, product_id, name, description, base_capacity "
            . "FROM product_variants WHERE product_id IN ($placeholders) AND active = 1 "
            . "ORDER BY product_id, name",
            $pids);

        $reservedRows = db_all($pdo,
            "SELECT product_id, variant_id, COALESCE(SUM(quantity),0) AS used "
            . "FROM reservations "
            . "WHERE product_id IN ($placeholders) AND order_date = ? "
            . "AND status IN ('ACTIVE','CONSUMED') "
            . "GROUP BY product_id, variant_id",
            array_merge($pids, [$today]));
    } else {
        $variantRows = [];
        $reservedRows = [];
    }

    $variantsByProduct = [];
    foreach ($variantRows as $v) {
        $variantsByProduct[(int)$v['product_id']][] = $v;
    }

    $reservedMap = [];
    foreach ($reservedRows as $r) {
        $key = (int)$r['product_id'] . ':' . ($r['variant_id'] !== null ? (int)$r['variant_id'] : '');
        $reservedMap[$key] = (int)$r['used'];
    }

    $result = [];
    foreach ($products as $p) {
        if (!menu_available_days_filter($p['available_days'], $todayIdx)) {
            continue;
        }

        $pid = (int)$p['id'];
        $variants = $variantsByProduct[$pid] ?? [];

        if (!empty($variants)) {
            $vlist = [];
            foreach ($variants as $v) {
                $vid = (int)$v['id'];
                $used = $reservedMap[$pid . ':' . $vid] ?? 0;
                $avail = max(0, (int)$v['base_capacity'] - $used);
                $vlist[] = [
                    'id'             => $vid,
                    'name'           => $v['name'],
                    'description'    => $v['description'],
                    'base_capacity'  => (int)$v['base_capacity'],
                    'available'      => $avail,
                ];
            }
            $result[] = [
                'id'             => $pid,
                'name'           => $p['name'],
                'category'       => $p['category'],
                'description'    => $p['description'],
                'base_capacity'  => (int)$p['base_capacity'],
                'has_variants'   => true,
                'variants'       => $vlist,
            ];
        } else {
            $used = $reservedMap[$pid . ':'] ?? 0;
            $avail = max(0, (int)$p['base_capacity'] - $used);
            $result[] = [
                'id'             => $pid,
                'name'           => $p['name'],
                'category'       => $p['category'],
                'description'    => $p['description'],
                'base_capacity'  => (int)$p['base_capacity'],
                'has_variants'   => false,
                'available'      => $avail,
            ];
        }
    }

    json_ok([
        'date'             => $today,
        'weekday'          => (int)$todayIdx,
        'is_friday'        => $todayIdx === 6,
        'ordering_allowed' => $orderingAllowed,
        'ordering_message' => $windowMsg,
        'items'            => $result,
    ]);
}

// ============================================================================
// SECTION: CUSTOMER — Orders
// ============================================================================

function route_customer_orders_list(): void
{
    require_customer();
    [$page, $limit] = parse_page_args();
    $offset = ($page - 1) * $limit;

    $pdo = get_db();
    $uid = (int)$_SESSION['user_id'];

    $total = (int)(db_value($pdo,
        'SELECT COUNT(*) AS c FROM orders WHERE customer_id = ?',
        [$uid]) ?? 0);

    $rows = db_all($pdo,
        'SELECT id, status, order_date, created_at, rejection_reason '
        . 'FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
        [$uid, $limit, $offset]);

    $orders = array_map(function ($r) {
        return [
            'id'               => (int)$r['id'],
            'status'           => $r['status'],
            'order_date'       => $r['order_date'],
            'created_at'       => $r['created_at'],
            'rejection_reason' => $r['rejection_reason'],
        ];
    }, $rows);

    json_ok([
        'orders' => $orders,
        'pagination' => [
            'page'     => $page,
            'limit'    => $limit,
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ],
    ]);
}

function route_customer_order_detail(int $oid): void
{
    require_customer();
    $pdo = get_db();
    $o = order_full($pdo, $oid, (int)$_SESSION['user_id']);
    if (!$o) {
        json_err('سفارش یافت نشد.', 404);
    }
    json_ok($o);
}

function route_customer_today_order(): void
{
    require_customer();
    $pdo = get_db();
    $oid = customer_today_order($pdo, (int)$_SESSION['user_id']);
    if (!$oid) {
        json_ok(['order' => null]);
    }
    json_ok(['order' => order_full($pdo, $oid, (int)$_SESSION['user_id'])]);
}

// ============================================================================
// SECTION: CUSTOMER — Create Order
// ============================================================================

function route_customer_create_order(): void
{
    require_customer();

    $payload = read_json_body();
    $items = $payload['items'] ?? null;
    $requestToken = clean_str($payload['request_token'] ?? null, MAX_TOKEN_LEN);
    if ($requestToken === '') {
        $requestToken = null;
    }

    $pdo = get_db();

    [$allowed, $msg] = check_ordering_window($pdo);
    if (!$allowed) {
        json_err($msg ?? 'زمان سفارش‌گیری نیست.', 409);
    }

    $todayStr = today_str($pdo);
    $uid = (int)$_SESSION['user_id'];

    if ($requestToken !== null) {
        $dup = db_one($pdo,
            'SELECT id FROM orders WHERE customer_id = ? AND request_token = ?',
            [$uid, $requestToken]);
        if ($dup) {
            json_ok(order_full($pdo, (int)$dup['id'], $uid));
        }
    }

    [$normalized, $e] = validate_items_payload($pdo, $items, $todayStr);
    if ($e) {
        json_err($e, 422);
    }

    $agg = [];
    foreach ($normalized as $it) {
        $key = $it['product_id'] . ':' . ($it['variant_id'] ?? '');
        if (!isset($agg[$key])) {
            $agg[$key] = ['product_id' => $it['product_id'], 'variant_id' => $it['variant_id'], 'quantity' => 0];
        }
        $agg[$key]['quantity'] += $it['quantity'];
    }

    $orderId = null;
    try {
        $pdo->exec('BEGIN IMMEDIATE');

        $existing = customer_today_order($pdo, $uid);
        if ($existing) {
            $pdo->exec('ROLLBACK');
            json_err('شما امروز قبلاً سفارش ثبت کرده‌اید.', 409);
        }

        if ($requestToken !== null) {
            $dupInTx = db_one($pdo,
                'SELECT id FROM orders WHERE customer_id = ? AND request_token = ?',
                [$uid, $requestToken]);
            if ($dupInTx) {
                $pdo->exec('ROLLBACK');
                json_ok(order_full($pdo, (int)$dupInTx['id'], $uid));
            }
        }

        // FIX-06: خواندن capacity قبل از ROLLBACK
        foreach ($agg as $a) {
            if (!atomic_reserve($pdo, $a['product_id'], $a['variant_id'], $todayStr, $a['quantity'])) {
                $base  = capacity_base($pdo, $a['product_id'], $a['variant_id']);
                $avail = available_quantity($pdo, $a['product_id'], $a['variant_id'], $todayStr);
                $label = product_label($pdo, $a['product_id'], $a['variant_id']);
                $pdo->exec('ROLLBACK');
                if ($base === null) {
                    json_err('محصول معتبر نیست.', 422);
                }
                json_err(
                    'ظرفیت کافی برای «' . $label . '» وجود ندارد. '
                    . 'ظرفیت باقی‌مانده: ' . $avail,
                    409
                );
            }
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO orders (customer_id, status, order_date, request_token) "
                . "VALUES (?, 'PENDING', ?, ?)"
            );
            $stmt->execute([$uid, $todayStr, $requestToken]);
            $orderId = (int)$pdo->lastInsertId();
        } catch (PDOException $ex) {
            $pdo->exec('ROLLBACK');
            if ($requestToken !== null) {
                $dup = db_one($pdo,
                    'SELECT id FROM orders WHERE request_token = ?',
                    [$requestToken]);
                if ($dup) {
                    json_ok(order_full($pdo, (int)$dup['id'], $uid));
                }
            }
            json_err('درخواست تکراری. لطفاً دوباره تلاش کنید.', 409);
        }

        $stmtItem = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, variant_id, quantity) '
            . 'VALUES (?, ?, ?, ?)'
        );
        $stmtRes = $pdo->prepare(
            "INSERT INTO reservations "
            . "(order_id, order_item_id, product_id, variant_id, quantity, status, order_date) "
            . "VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?)"
        );

        foreach ($agg as $a) {
            $stmtItem->execute([$orderId, $a['product_id'], $a['variant_id'], $a['quantity']]);
            $itemId = (int)$pdo->lastInsertId();
            $stmtRes->execute([$orderId, $itemId, $a['product_id'], $a['variant_id'], $a['quantity'], $todayStr]);
        }

        $stmtHist = $pdo->prepare(
            "INSERT INTO order_status_history (order_id, old_status, new_status, actor_type, actor_id) "
            . "VALUES (?, NULL, 'PENDING', 'CUSTOMER', ?)"
        );
        $stmtHist->execute([$orderId, $uid]);

        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        log_error('create_order failed', ['error' => $ex->getMessage()]);
        json_err('خطا در ثبت سفارش. لطفاً دوباره تلاش کنید.', 500);
    }

    log_activity('CUSTOMER', $uid, 'CREATE_ORDER', ['order_id' => $orderId]);
    json_ok(order_full($pdo, $orderId, $uid), 201);
}

// ============================================================================
// SECTION: CUSTOMER — Edit / Cancel
// ============================================================================

/**
 * BUG-002: snapshot صحیح $oldRes
 * FIX-07: استفاده از $o2 در reserved_quantity و INSERT reservations
 */
function route_customer_edit_order(int $oid): void
{
    require_customer();

    $payload = read_json_body();
    $items = $payload['items'] ?? null;

    $pdo = get_db();

    [$allowed, $msg] = check_ordering_window($pdo);
    if (!$allowed) {
        json_err($msg ?? 'زمان سفارش‌گیری نیست.', 409);
    }

    $uid = (int)$_SESSION['user_id'];
    $o = db_one($pdo,
        'SELECT * FROM orders WHERE id = ? AND customer_id = ?',
        [$oid, $uid]);
    if (!$o) {
        json_err('سفارش یافت نشد.', 404);
    }
    if ($o['status'] !== 'PENDING') {
        json_err('فقط سفارش‌های در انتظار تأیید قابل ویرایش هستند.', 409);
    }

    $todayStr = today_str($pdo);
    if ($o['order_date'] !== $todayStr) {
        json_err('ویرایش سفارش تنها در همان روز مجاز است.', 409);
    }

    [$normalized, $e] = validate_items_payload($pdo, $items, $o['order_date']);
    if ($e) {
        json_err($e, 422);
    }

    $aggNew = [];
    foreach ($normalized as $it) {
        $key = $it['product_id'] . ':' . ($it['variant_id'] ?? '');
        if (!isset($aggNew[$key])) {
            $aggNew[$key] = ['product_id' => $it['product_id'], 'variant_id' => $it['variant_id'], 'quantity' => 0];
        }
        $aggNew[$key]['quantity'] += $it['quantity'];
    }

    try {
        $pdo->exec('BEGIN IMMEDIATE');

        $o2 = db_one($pdo,
            'SELECT status, customer_id, order_date FROM orders WHERE id = ? AND customer_id = ?',
            [$oid, $uid]);
        if (!$o2) {
            $pdo->exec('ROLLBACK');
            json_err('سفارش یافت نشد.', 404);
        }
        if ($o2['status'] !== 'PENDING') {
            $pdo->exec('ROLLBACK');
            json_err('وضعیت سفارش تغییر کرده است.', 409);
        }
        if ($o2['order_date'] !== $todayStr) {
            $pdo->exec('ROLLBACK');
            json_err('ویرایش سفارش تنها در همان روز مجاز است.', 409);
        }

        $oldRes = db_all($pdo,
            "SELECT product_id, variant_id, quantity FROM reservations "
            . "WHERE order_id = ? AND status = 'ACTIVE'",
            [$oid]);

        $oldSnapshot = [];
        foreach ($oldRes as $r) {
            $oldSnapshot[] = [
                'product_id' => (int)$r['product_id'],
                'variant_id' => $r['variant_id'] !== null ? (int)$r['variant_id'] : null,
                'quantity'   => (int)$r['quantity'],
            ];
        }
        $newSnapshot = [];
        foreach ($aggNew as $a) {
            $newSnapshot[] = [
                'product_id' => $a['product_id'],
                'variant_id' => $a['variant_id'],
                'quantity'   => $a['quantity'],
            ];
        }

        $keys = [];
        foreach ($oldRes as $r) {
            $vid = $r['variant_id'] !== null ? (int)$r['variant_id'] : null;
            $keys[(int)$r['product_id'] . ':' . ($vid ?? '')] = [(int)$r['product_id'], $vid];
        }
        foreach ($aggNew as $a) {
            $keys[$a['product_id'] . ':' . ($a['variant_id'] ?? '')] = [$a['product_id'], $a['variant_id']];
        }

        foreach ($keys as $pair) {
            [$pid, $vid] = $pair;

            $oldQty = 0;
            foreach ($oldRes as $r) {
                $rVid = $r['variant_id'] !== null ? (int)$r['variant_id'] : null;
                if ((int)$r['product_id'] === $pid && $rVid === $vid) {
                    $oldQty += (int)$r['quantity'];
                }
            }

            $base = capacity_base($pdo, $pid, $vid);
            if ($base === null) {
                $pdo->exec('ROLLBACK');
                json_err('محصول نامعتبر است.', 422);
            }
            $usedTotal = reserved_quantity($pdo, $pid, $vid, $o2['order_date']);
            $usedOthers = max(0, $usedTotal - $oldQty);
            $availableForThis = max(0, $base - $usedOthers);

            $key = $pid . ':' . ($vid ?? '');
            $newQty = isset($aggNew[$key]) ? $aggNew[$key]['quantity'] : 0;
            if ($newQty > $availableForThis) {
                $pdo->exec('ROLLBACK');
                json_err(
                    'ظرفیت کافی برای «' . product_label($pdo, $pid, $vid) . '» وجود ندارد. '
                    . 'حداکثر قابل انتخاب: ' . $availableForThis,
                    409
                );
            }
        }

        db_exec($pdo, 'DELETE FROM reservations WHERE order_id = ?', [$oid]);
        db_exec($pdo, 'DELETE FROM order_items WHERE order_id = ?', [$oid]);

        $stmtItem = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, variant_id, quantity) VALUES (?, ?, ?, ?)'
        );
        $stmtRes = $pdo->prepare(
            "INSERT INTO reservations "
            . "(order_id, order_item_id, product_id, variant_id, quantity, status, order_date) "
            . "VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?)"
        );
        foreach ($aggNew as $a) {
            $stmtItem->execute([$oid, $a['product_id'], $a['variant_id'], $a['quantity']]);
            $itemId = (int)$pdo->lastInsertId();
            // FIX-07b: استفاده از $o2['order_date']
            $stmtRes->execute([$oid, $itemId, $a['product_id'], $a['variant_id'], $a['quantity'], $o2['order_date']]);
        }

        db_exec($pdo, "UPDATE orders SET updated_at = datetime('now') WHERE id = ?", [$oid]);

        $stmtHist = $pdo->prepare(
            "INSERT INTO order_status_history "
            . "(order_id, old_status, new_status, reason, actor_type, actor_id) "
            . "VALUES (?, 'PENDING', 'PENDING', ?, 'CUSTOMER', ?)"
        );
        $stmtHist->execute([
            $oid,
            json_encode([
                'action'    => 'EDIT',
                'old_items' => $oldSnapshot,
                'new_items' => $newSnapshot,
            ], JSON_UNESCAPED_UNICODE),
            $uid,
        ]);

        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        log_error('edit_order failed', ['error' => $ex->getMessage()]);
        json_err('خطا در ویرایش سفارش.', 500);
    }

    log_activity('CUSTOMER', $uid, 'EDIT_ORDER', ['order_id' => $oid]);
    json_ok(order_full($pdo, $oid, $uid));
}

function route_customer_cancel_order(int $oid): void
{
    require_customer();

    $pdo = get_db();

    [$allowed, $msg] = check_ordering_window($pdo);
    if (!$allowed) {
        json_err($msg ?? 'زمان سفارش‌گیری نیست.', 409);
    }

    $uid = (int)$_SESSION['user_id'];
    $todayStr = today_str($pdo);

    try {
        $pdo->exec('BEGIN IMMEDIATE');

        $o = db_one($pdo,
            'SELECT status, customer_id, order_date FROM orders WHERE id = ? AND customer_id = ?',
            [$oid, $uid]);
        if (!$o) {
            $pdo->exec('ROLLBACK');
            json_err('سفارش یافت نشد.', 404);
        }
        if ($o['status'] !== 'PENDING') {
            $pdo->exec('ROLLBACK');
            json_err('فقط سفارش‌های در انتظار تأیید قابل لغو هستند.', 409);
        }
        if ($o['order_date'] !== $todayStr) {
            $pdo->exec('ROLLBACK');
            json_err('لغو سفارش تنها در همان روز مجاز است.', 409);
        }

        db_exec($pdo,
            "UPDATE reservations SET status = 'RELEASED', released_at = datetime('now') "
            . "WHERE order_id = ? AND status = 'ACTIVE'",
            [$oid]);

        db_exec($pdo,
            "UPDATE orders SET status = 'CANCELLED', cancelled_at = datetime('now'), "
            . "updated_at = datetime('now') WHERE id = ?",
            [$oid]);

        $stmt = $pdo->prepare(
            "INSERT INTO order_status_history (order_id, old_status, new_status, actor_type, actor_id) "
            . "VALUES (?, 'PENDING', 'CANCELLED', 'CUSTOMER', ?)"
        );
        $stmt->execute([$oid, $uid]);

        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        log_error('cancel_order failed', ['error' => $ex->getMessage()]);
        json_err('خطا در لغو سفارش.', 500);
    }

    log_activity('CUSTOMER', $uid, 'CANCEL_ORDER', ['order_id' => $oid]);
    json_ok(order_full($pdo, $oid, $uid));
}

// ============================================================================
// SECTION: ADMIN — Dashboard
// ============================================================================

function route_admin_dashboard(): void
{
    require_admin();
    $pdo = get_db();
    $today = today_str($pdo);

    $agg = db_one($pdo,
        "SELECT "
        . "  SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END) AS pending, "
        . "  SUM(CASE WHEN status='APPROVED' AND order_date=? THEN 1 ELSE 0 END) AS approved_today, "
        . "  SUM(CASE WHEN status='REJECTED' AND order_date=? THEN 1 ELSE 0 END) AS rejected_today, "
        . "  SUM(CASE WHEN status='CANCELLED' AND order_date=? THEN 1 ELSE 0 END) AS cancelled_today "
        . "FROM orders",
        [$today, $today, $today]);

    $custAgg = db_one($pdo, "SELECT SUM(CASE WHEN active=1 THEN 1 ELSE 0 END) AS c FROM customers");
    $prodAgg = db_one($pdo, "SELECT SUM(CASE WHEN active=1 THEN 1 ELSE 0 END) AS c FROM products");

    [$orderingAllowed, $windowMsg] = check_ordering_window($pdo);
    $start = get_setting($pdo, 'ordering_start', '07:30');
    $end   = get_setting($pdo, 'ordering_end', '12:00');

    json_ok([
        'today'             => $today,
        'pending'           => (int)($agg['pending'] ?? 0),
        'approved_today'    => (int)($agg['approved_today'] ?? 0),
        'rejected_today'    => (int)($agg['rejected_today'] ?? 0),
        'cancelled_today'   => (int)($agg['cancelled_today'] ?? 0),
        'active_customers'  => (int)($custAgg['c'] ?? 0),
        'active_products'   => (int)($prodAgg['c'] ?? 0),
        'ordering_allowed'  => $orderingAllowed,
        'ordering_message'  => $windowMsg,
        'ordering_start'    => $start,
        'ordering_end'      => $end,
    ]);
}

// ============================================================================
// SECTION: ADMIN — Customers
// ============================================================================

function route_admin_customers_list(): void
{
    require_admin();

    $q = clean_str($_GET['q'] ?? null, 64);
    [$page, $limit] = parse_page_args();
    $offset = ($page - 1) * $limit;

    $pdo = get_db();
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = ' WHERE company_name LIKE ? OR customer_code LIKE ? OR phone LIKE ?';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
    }

    $total = (int)(db_value($pdo,
        "SELECT COUNT(*) AS c FROM customers" . $where,
        $params) ?? 0);

    $rows = db_all($pdo,
        "SELECT id, company_name, phone, address, customer_code, active, created_at "
        . "FROM customers" . $where . " ORDER BY id DESC LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset]));

    $customers = array_map(function ($r) {
        return [
            'id'            => (int)$r['id'],
            'company_name'  => $r['company_name'],
            'phone'         => $r['phone'],
            'address'       => $r['address'],
            'customer_code' => $r['customer_code'],
            'active'        => (int)$r['active'] === 1,
            'created_at'    => $r['created_at'],
        ];
    }, $rows);

    json_ok([
        'customers' => $customers,
        'pagination' => [
            'page'     => $page,
            'limit'    => $limit,
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ],
    ]);
}

function route_admin_customers_create(): void
{
    require_admin();

    $p = read_json_body();
    $company = clean_str($p['company_name'] ?? null, MAX_LEN_SHORT);
    $phone   = clean_str($p['phone'] ?? null, 32);
    $address = clean_str($p['address'] ?? null, MAX_LEN_LONG);
    $code    = clean_str($p['customer_code'] ?? null, MAX_CODE_LEN);

    if ($company === '') {
        json_err('نام شرکت الزامی است.', 422);
    }
    if ($code === '') {
        json_err('کد مشتری الزامی است.', 422);
    }
    if (preg_match(CUSTOMER_CODE_PATTERN, $code) !== 1) {
        json_err('فرمت کد مشتری نامعتبر است.', 422);
    }

    $pdo = get_db();
    $dup = db_one($pdo, 'SELECT id FROM customers WHERE customer_code = ?', [$code]);
    if ($dup) {
        json_err('این کد مشتری قبلاً استفاده شده است.', 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO customers (company_name, phone, address, customer_code, active) '
        . 'VALUES (?, ?, ?, ?, 1)'
    );
    $stmt->execute([$company, $phone, $address, $code]);
    $cid = (int)$pdo->lastInsertId();

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'CREATE_CUSTOMER', ['customer_id' => $cid]);

    $row = db_one($pdo,
        'SELECT id, company_name, phone, address, customer_code, active, created_at '
        . 'FROM customers WHERE id = ?',
        [$cid]);

    json_ok([
        'id'            => (int)$row['id'],
        'company_name'  => $row['company_name'],
        'phone'         => $row['phone'],
        'address'       => $row['address'],
        'customer_code' => $row['customer_code'],
        'active'        => (int)$row['active'] === 1,
        'created_at'    => $row['created_at'],
    ], 201);
}

function route_admin_customers_update(int $cid): void
{
    require_admin();

    $p = read_json_body();
    $pdo = get_db();
    $row = db_one($pdo, 'SELECT * FROM customers WHERE id = ?', [$cid]);
    if (!$row) {
        json_err('مشتری یافت نشد.', 404);
    }

    $company = clean_str($p['company_name'] ?? $row['company_name'], MAX_LEN_SHORT);
    $phone   = clean_str($p['phone'] ?? $row['phone'], 32);
    $address = clean_str($p['address'] ?? $row['address'], MAX_LEN_LONG);
    $code    = clean_str($p['customer_code'] ?? $row['customer_code'], MAX_CODE_LEN);
    $active  = parse_bool($p['active'] ?? ((int)$row['active'] === 1)) ? 1 : 0;

    if ($company === '') {
        json_err('نام شرکت الزامی است.', 422);
    }
    if (preg_match(CUSTOMER_CODE_PATTERN, $code) !== 1) {
        json_err('فرمت کد مشتری نامعتبر است.', 422);
    }

    $dup = db_one($pdo,
        'SELECT id FROM customers WHERE customer_code = ? AND id != ?',
        [$code, $cid]);
    if ($dup) {
        json_err('این کد مشتری قبلاً استفاده شده است.', 409);
    }

    db_exec($pdo,
        "UPDATE customers SET company_name=?, phone=?, address=?, customer_code=?, active=?, "
        . "updated_at=datetime('now') WHERE id=?",
        [$company, $phone, $address, $code, $active, $cid]);

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'UPDATE_CUSTOMER', ['customer_id' => $cid]);

    $row = db_one($pdo,
        'SELECT id, company_name, phone, address, customer_code, active, created_at '
        . 'FROM customers WHERE id = ?',
        [$cid]);

    json_ok([
        'id'            => (int)$row['id'],
        'company_name'  => $row['company_name'],
        'phone'         => $row['phone'],
        'address'       => $row['address'],
        'customer_code' => $row['customer_code'],
        'active'        => (int)$row['active'] === 1,
        'created_at'    => $row['created_at'],
    ]);
}

function route_admin_customers_disable(int $cid): void
{
    require_admin();
    $pdo = get_db();
    $row = db_one($pdo, 'SELECT id FROM customers WHERE id = ?', [$cid]);
    if (!$row) {
        json_err('مشتری یافت نشد.', 404);
    }
    db_exec($pdo,
        "UPDATE customers SET active = 0, updated_at = datetime('now') WHERE id = ?",
        [$cid]);
    log_activity('ADMIN', (int)$_SESSION['user_id'], 'DISABLE_CUSTOMER', ['customer_id' => $cid]);
    json_ok();
}

// ============================================================================
// SECTION: ADMIN — Products
// ============================================================================

function product_to_dict(PDO $pdo, array $row): array
{
    $variants = db_all($pdo,
        'SELECT id, name, description, base_capacity, active, independently_disabled '
        . 'FROM product_variants WHERE product_id = ? ORDER BY id',
        [(int)$row['id']]);

    return [
        'id'              => (int)$row['id'],
        'name'            => $row['name'],
        'category'        => $row['category'],
        'description'     => $row['description'],
        'base_capacity'   => (int)$row['base_capacity'],
        'active'          => (int)$row['active'] === 1,
        'available_days'  => $row['available_days'],
        'created_at'      => $row['created_at'] ?? null,
        'updated_at'      => $row['updated_at'] ?? null,
        'variants'        => array_map(function ($v) {
            return [
                'id'                     => (int)$v['id'],
                'name'                   => $v['name'],
                'description'            => $v['description'],
                'base_capacity'          => (int)$v['base_capacity'],
                'active'                 => (int)$v['active'] === 1,
                'independently_disabled' => (int)$v['independently_disabled'] === 1,
            ];
        }, $variants),
    ];
}

function route_admin_products_list(): void
{
    require_admin();
    $pdo = get_db();
    $rows = db_all($pdo,
        'SELECT id, name, category, description, base_capacity, active, available_days, '
        . 'created_at, updated_at FROM products ORDER BY category, name');

    $products = array_map(function ($r) use ($pdo) {
        return product_to_dict($pdo, $r);
    }, $rows);

    json_ok(['products' => $products]);
}

/**
 * @return array{0: ?array, 1: ?string}
 */
function validate_product_payload(array $p): array
{
    $name        = clean_str($p['name'] ?? null, MAX_LEN_SHORT);
    $category    = clean_str($p['category'] ?? null, 16);
    $description = clean_str($p['description'] ?? null, MAX_LEN_LONG);
    $active      = parse_bool($p['active'] ?? true) ? 1 : 0;
    $availableDays = $p['available_days'] ?? '0,1,2,3,4,5';

    if ($name === '') {
        return [null, 'نام محصول الزامی است.'];
    }
    if (!in_array($category, VALID_CATEGORIES, true)) {
        return [null, 'دسته‌بندی نامعتبر است.'];
    }

    $baseCap = $p['base_capacity'] ?? 0;
    if (!is_int_like($baseCap) || (int)$baseCap < 0) {
        return [null, 'ظرفیت پایه نامعتبر است.'];
    }
    $baseCap = (int)$baseCap;

    $days = [];
    if (is_array($availableDays)) {
        foreach ($availableDays as $x) {
            if (!is_int_like($x)) {
                return [null, 'روزهای فعال نامعتبر است.'];
            }
            $days[] = (int)$x;
        }
    } else {
        foreach (explode(',', (string)$availableDays) as $x) {
            $x = trim($x);
            if ($x === '') continue;
            if (!preg_match('/^-?\d+$/', $x)) {
                return [null, 'روزهای فعال نامعتبر است.'];
            }
            $days[] = (int)$x;
        }
    }
    $days = array_values(array_filter(array_unique($days), fn($d) => $d >= 0 && $d <= 6));
    sort($days);
    if (empty($days)) {
        return [null, 'حداقل یک روز فعال لازم است.'];
    }

    return [[
        'name'           => $name,
        'category'       => $category,
        'description'    => $description,
        'active'         => $active,
        'base_capacity'  => $baseCap,
        'available_days' => implode(',', $days),
    ], null];
}

function route_admin_products_create(): void
{
    require_admin();

    $p = read_json_body();
    [$data, $e] = validate_product_payload($p);
    if ($e) {
        json_err($e, 422);
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, category, description, base_capacity, active, available_days) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['name'], $data['category'], $data['description'],
        $data['base_capacity'], $data['active'], $data['available_days'],
    ]);
    $pid = (int)$pdo->lastInsertId();

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'CREATE_PRODUCT', ['product_id' => $pid]);

    $row = db_one($pdo, 'SELECT * FROM products WHERE id = ?', [$pid]);
    json_ok(product_to_dict($pdo, $row), 201);
}

function route_admin_products_update(int $pid): void
{
    require_admin();

    $p = read_json_body();
    $pdo = get_db();
    $row = db_one($pdo, 'SELECT * FROM products WHERE id = ?', [$pid]);
    if (!$row) {
        json_err('محصول یافت نشد.', 404);
    }

    $merged = [
        'name'           => $p['name']           ?? $row['name'],
        'category'       => $p['category']       ?? $row['category'],
        'description'    => $p['description']    ?? $row['description'],
        'base_capacity'  => $p['base_capacity']  ?? $row['base_capacity'],
        'active'         => $p['active']         ?? ((int)$row['active'] === 1),
        'available_days' => $p['available_days'] ?? $row['available_days'],
    ];
    [$data, $e] = validate_product_payload($merged);
    if ($e) {
        json_err($e, 422);
    }

    $oldActive = (int)$row['active'];
    $newActive = (int)$data['active'];

    try {
        $pdo->exec('BEGIN IMMEDIATE');

        db_exec($pdo,
            "UPDATE products SET name=?, category=?, description=?, base_capacity=?, "
            . "active=?, available_days=?, updated_at=datetime('now') WHERE id=?",
            [
                $data['name'], $data['category'], $data['description'],
                $data['base_capacity'], $data['active'], $data['available_days'],
                $pid,
            ]);

        if ($oldActive === 0 && $newActive === 1) {
            db_exec($pdo,
                "UPDATE product_variants SET active = 1, updated_at = datetime('now') "
                . "WHERE product_id = ? AND independently_disabled = 0",
                [$pid]);
        }

        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        throw $ex;
    }

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'UPDATE_PRODUCT', ['product_id' => $pid]);

    $row = db_one($pdo, 'SELECT * FROM products WHERE id = ?', [$pid]);
    json_ok(product_to_dict($pdo, $row));
}

function route_admin_products_disable(int $pid): void
{
    require_admin();
    $pdo = get_db();
    $row = db_one($pdo, 'SELECT id FROM products WHERE id = ?', [$pid]);
    if (!$row) {
        json_err('محصول یافت نشد.', 404);
    }

    try {
        $pdo->exec('BEGIN IMMEDIATE');
        db_exec($pdo,
            "UPDATE products SET active = 0, updated_at = datetime('now') WHERE id = ?",
            [$pid]);
        db_exec($pdo,
            "UPDATE product_variants SET active = 0, updated_at = datetime('now') "
            . "WHERE product_id = ?",
            [$pid]);
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        throw $ex;
    }

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'DISABLE_PRODUCT', ['product_id' => $pid]);
    json_ok();
}

// ============================================================================
// SECTION: ADMIN — Variants
// ============================================================================

function route_admin_variant_create(int $pid): void
{
    require_admin();

    $p = read_json_body();
    $pdo = get_db();
    $prod = db_one($pdo, 'SELECT id FROM products WHERE id = ?', [$pid]);
    if (!$prod) {
        json_err('محصول یافت نشد.', 404);
    }

    $name        = clean_str($p['name'] ?? null, MAX_LEN_SHORT);
    $description = clean_str($p['description'] ?? null, MAX_LEN_LONG);
    $base        = $p['base_capacity'] ?? 0;
    $active      = parse_bool($p['active'] ?? true) ? 1 : 0;

    if ($name === '') {
        json_err('نام Variant الزامی است.', 422);
    }
    if (!is_int_like($base) || (int)$base < 0) {
        json_err('ظرفیت نامعتبر است.', 422);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO product_variants (product_id, name, description, base_capacity, active) '
        . 'VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$pid, $name, $description, (int)$base, $active]);
    $vid = (int)$pdo->lastInsertId();

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'CREATE_VARIANT', ['variant_id' => $vid]);

    $row = db_one($pdo, 'SELECT * FROM product_variants WHERE id = ?', [$vid]);
    json_ok([
        'id'                     => (int)$row['id'],
        'product_id'             => (int)$row['product_id'],
        'name'                   => $row['name'],
        'description'            => $row['description'],
        'base_capacity'          => (int)$row['base_capacity'],
        'active'                 => (int)$row['active'] === 1,
        'independently_disabled' => (int)$row['independently_disabled'] === 1,
    ], 201);
}

function route_admin_variant_update(int $vid): void
{
    require_admin();

    $p = read_json_body();
    $pdo = get_db();
    $row = db_one($pdo, 'SELECT * FROM product_variants WHERE id = ?', [$vid]);
    if (!$row) {
        json_err('Variant یافت نشد.', 404);
    }

    $name        = clean_str($p['name'] ?? $row['name'], MAX_LEN_SHORT);
    $description = clean_str($p['description'] ?? $row['description'], MAX_LEN_LONG);
    $base        = $p['base_capacity'] ?? $row['base_capacity'];
    $active      = parse_bool($p['active'] ?? ((int)$row['active'] === 1)) ? 1 : 0;

    if ($name === '') {
        json_err('نام الزامی است.', 422);
    }
    if (!is_int_like($base) || (int)$base < 0) {
        json_err('ظرفیت نامعتبر است.', 422);
    }

    $indep = (int)$row['independently_disabled'];
    if ($active !== (int)$row['active']) {
        $indep = $active ? 0 : 1;
    }

    db_exec($pdo,
        "UPDATE product_variants SET name=?, description=?, base_capacity=?, active=?, "
        . "independently_disabled=?, updated_at=datetime('now') WHERE id=?",
        [$name, $description, (int)$base, $active, $indep, $vid]);

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'UPDATE_VARIANT', ['variant_id' => $vid]);

    $row = db_one($pdo, 'SELECT * FROM product_variants WHERE id = ?', [$vid]);
    json_ok([
        'id'                     => (int)$row['id'],
        'product_id'             => (int)$row['product_id'],
        'name'                   => $row['name'],
        'description'            => $row['description'],
        'base_capacity'          => (int)$row['base_capacity'],
        'active'                 => (int)$row['active'] === 1,
        'independently_disabled' => (int)$row['independently_disabled'] === 1,
    ]);
}

function route_admin_variant_disable(int $vid): void
{
    require_admin();
    $pdo = get_db();
    $row = db_one($pdo, 'SELECT id FROM product_variants WHERE id = ?', [$vid]);
    if (!$row) {
        json_err('Variant یافت نشد.', 404);
    }
    db_exec($pdo,
        "UPDATE product_variants SET active = 0, independently_disabled = 1, "
        . "updated_at = datetime('now') WHERE id = ?",
        [$vid]);
    log_activity('ADMIN', (int)$_SESSION['user_id'], 'DISABLE_VARIANT', ['variant_id' => $vid]);
    json_ok();
}

// ============================================================================
// SECTION: ADMIN — Orders
// ============================================================================

function route_admin_orders_list(): void
{
    require_admin();

    $status = clean_str($_GET['status'] ?? null, 16);
    $dateFilter = clean_str($_GET['date'] ?? null, 16);
    [$page, $limit] = parse_page_args();
    $offset = ($page - 1) * $limit;

    $pdo = get_db();
    $where = ' WHERE 1=1';
    $params = [];
    if ($status !== '' && in_array($status, VALID_STATUSES, true)) {
        $where .= ' AND o.status = ?';
        $params[] = $status;
    }
    if ($dateFilter !== '' && preg_match(DATE_PATTERN, $dateFilter)) {
        $where .= ' AND o.order_date = ?';
        $params[] = $dateFilter;
    }

    $total = (int)(db_value($pdo,
        "SELECT COUNT(*) AS c FROM orders o" . $where,
        $params) ?? 0);

    $sql = "SELECT o.id, o.status, o.order_date, o.created_at, o.rejection_reason, "
         . "       c.id AS customer_id, c.company_name, c.customer_code, c.phone, c.address, "
         . "       (SELECT COALESCE(SUM(quantity),0) FROM order_items oi WHERE oi.order_id = o.id) AS total_qty, "
         . "       (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS items_count "
         . "FROM orders o JOIN customers c ON c.id = o.customer_id"
         . $where
         . " ORDER BY o.id DESC LIMIT ? OFFSET ?";

    $rows = db_all($pdo, $sql, array_merge($params, [$limit, $offset]));

    $orders = array_map(function ($r) {
        return [
            'id'               => (int)$r['id'],
            'status'           => $r['status'],
            'order_date'       => $r['order_date'],
            'created_at'       => $r['created_at'],
            'rejection_reason' => $r['rejection_reason'],
            'customer_id'      => (int)$r['customer_id'],
            'company_name'     => $r['company_name'],
            'customer_code'    => $r['customer_code'],
            'phone'            => $r['phone'],
            'address'          => $r['address'],
            'total_qty'        => (int)$r['total_qty'],
            'items_count'      => (int)$r['items_count'],
        ];
    }, $rows);

    json_ok([
        'orders' => $orders,
        'pagination' => [
            'page'     => $page,
            'limit'    => $limit,
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ],
    ]);
}

function route_admin_order_detail(int $oid): void
{
    require_admin();
    $pdo = get_db();
    $o = order_full($pdo, $oid);
    if (!$o) {
        json_err('سفارش یافت نشد.', 404);
    }
    $c = db_one($pdo,
        'SELECT id, company_name, customer_code, phone, address FROM customers WHERE id = ?',
        [$o['customer_id']]);
    $o['customer'] = $c ? [
        'id'            => (int)$c['id'],
        'company_name'  => $c['company_name'],
        'customer_code' => $c['customer_code'],
        'phone'         => $c['phone'],
        'address'       => $c['address'],
    ] : null;
    json_ok($o);
}

function route_admin_order_approve(int $oid): void
{
    require_admin();

    $pdo = get_db();
    try {
        $pdo->exec('BEGIN IMMEDIATE');

        $o = db_one($pdo, 'SELECT status FROM orders WHERE id = ?', [$oid]);
        if (!$o) {
            $pdo->exec('ROLLBACK');
            json_err('سفارش یافت نشد.', 404);
        }
        if ($o['status'] !== 'PENDING') {
            $pdo->exec('ROLLBACK');
            json_err('فقط سفارش‌های در انتظار تأیید قابل تأیید هستند.', 409);
        }

        $affected = db_exec($pdo,
            "UPDATE reservations SET status='CONSUMED', consumed_at=datetime('now') "
            . "WHERE order_id = ? AND status = 'ACTIVE'",
            [$oid]);

        if ($affected === 0) {
            $pdo->exec('ROLLBACK');
            json_err('رزروهای این سفارش معتبر نیستند. تأیید لغو شد.', 409);
        }

        db_exec($pdo,
            "UPDATE orders SET status='APPROVED', approved_at=datetime('now'), "
            . "updated_at=datetime('now') WHERE id = ?",
            [$oid]);

        $stmt = $pdo->prepare(
            "INSERT INTO order_status_history (order_id, old_status, new_status, actor_type, actor_id) "
            . "VALUES (?, 'PENDING', 'APPROVED', 'ADMIN', ?)"
        );
        $stmt->execute([$oid, (int)$_SESSION['user_id']]);

        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        log_error('approve_order failed', ['error' => $ex->getMessage()]);
        json_err('خطا در تأیید سفارش.', 500);
    }

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'APPROVE_ORDER', ['order_id' => $oid]);
    json_ok(order_full($pdo, $oid));
}

function route_admin_order_reject(int $oid): void
{
    require_admin();

    $p = read_json_body();
    $reason = clean_str($p['reason'] ?? null, MAX_LEN_LONG);

    $pdo = get_db();
    try {
        $pdo->exec('BEGIN IMMEDIATE');

        $o = db_one($pdo, 'SELECT status FROM orders WHERE id = ?', [$oid]);
        if (!$o) {
            $pdo->exec('ROLLBACK');
            json_err('سفارش یافت نشد.', 404);
        }
        if ($o['status'] !== 'PENDING') {
            $pdo->exec('ROLLBACK');
            json_err('فقط سفارش‌های در انتظار تأیید قابل رد هستند.', 409);
        }

        $consumed = (int)(db_value($pdo,
            "SELECT COUNT(*) AS c FROM reservations WHERE order_id = ? AND status = 'CONSUMED'",
            [$oid]) ?? 0);
        if ($consumed > 0) {
            $pdo->exec('ROLLBACK');
            json_err('این سفارش دارای رزرو مصرف‌شده است و قابل رد نیست.', 409);
        }

        $affected = db_exec($pdo,
            "UPDATE reservations SET status='RELEASED', released_at=datetime('now') "
            . "WHERE order_id = ? AND status = 'ACTIVE'",
            [$oid]);

        if ($affected === 0) {
            $pdo->exec('ROLLBACK');
            json_err('رزروهای این سفارش معتبر نیستند. رد لغو شد.', 409);
        }

        db_exec($pdo,
            "UPDATE orders SET status='REJECTED', rejection_reason=?, "
            . "rejected_at=datetime('now'), updated_at=datetime('now') WHERE id = ?",
            [$reason, $oid]);

        $stmt = $pdo->prepare(
            "INSERT INTO order_status_history (order_id, old_status, new_status, reason, actor_type, actor_id) "
            . "VALUES (?, 'PENDING', 'REJECTED', ?, 'ADMIN', ?)"
        );
        $stmt->execute([$oid, $reason, (int)$_SESSION['user_id']]);

        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        log_error('reject_order failed', ['error' => $ex->getMessage()]);
        json_err('خطا در رد سفارش.', 500);
    }

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'REJECT_ORDER', ['order_id' => $oid]);
    json_ok(order_full($pdo, $oid));
}

// ============================================================================
// SECTION: ADMIN — Capacity
// ============================================================================

function route_admin_capacity(): void
{
    require_admin();
    $today = today_str();
    $pdo = get_db();

    $rows = db_all($pdo,
        'SELECT id, name, category, base_capacity, active FROM products ORDER BY category, name');

    $out = [];
    foreach ($rows as $p) {
        $pid = (int)$p['id'];
        $variants = db_all($pdo,
            'SELECT id, name, base_capacity, active, independently_disabled '
            . 'FROM product_variants WHERE product_id = ?',
            [$pid]);

        if (!empty($variants)) {
            $vlist = [];
            foreach ($variants as $v) {
                $vid = (int)$v['id'];
                $used = reserved_quantity($pdo, $pid, $vid, $today);
                $vlist[] = [
                    'id'                     => $vid,
                    'name'                   => $v['name'],
                    'base_capacity'          => (int)$v['base_capacity'],
                    'used'                   => $used,
                    'available'              => max(0, (int)$v['base_capacity'] - $used),
                    'active'                 => (int)$v['active'] === 1,
                    'independently_disabled' => (int)$v['independently_disabled'] === 1,
                    'orderable'              => ((int)$v['active'] === 1 && (int)$p['active'] === 1),
                ];
            }
            $out[] = [
                'id'             => $pid,
                'name'           => $p['name'],
                'category'       => $p['category'],
                'base_capacity'  => (int)$p['base_capacity'],
                'active'         => (int)$p['active'] === 1,
                'has_variants'   => true,
                'variants'       => $vlist,
            ];
        } else {
            $used = reserved_quantity($pdo, $pid, null, $today);
            $out[] = [
                'id'             => $pid,
                'name'           => $p['name'],
                'category'       => $p['category'],
                'base_capacity'  => (int)$p['base_capacity'],
                'active'         => (int)$p['active'] === 1,
                'has_variants'   => false,
                'used'           => $used,
                'available'      => max(0, (int)$p['base_capacity'] - $used),
                'orderable'      => (int)$p['active'] === 1,
            ];
        }
    }
    json_ok(['date' => $today, 'items' => $out]);
}

// ============================================================================
// SECTION: ADMIN — Reports
// ============================================================================

/**
 * FIX-09: int cast برای order_id و quantity (CSV Injection prevention)
 */
function route_admin_report_orders_csv(): void
{
    require_admin();

    $rawDate = clean_str($_GET['date'] ?? null, 16);
    if ($rawDate === '') {
        $rawDate = today_str();
    }
    if (preg_match(DATE_PATTERN, $rawDate) !== 1) {
        $dateFilter = today_str();
    } else {
        $dateFilter = $rawDate;
    }

    $pdo = get_db();
    $rows = db_all($pdo,
        'SELECT o.id AS order_id, c.company_name, c.customer_code, c.phone, c.address, '
        . '       o.order_date, o.created_at, o.status, '
        . '       p.name AS product_name, pv.name AS variant_name, oi.quantity '
        . 'FROM orders o '
        . 'JOIN customers c ON c.id = o.customer_id '
        . 'JOIN order_items oi ON oi.order_id = o.id '
        . 'JOIN products p ON p.id = oi.product_id '
        . 'LEFT JOIN product_variants pv ON pv.id = oi.variant_id '
        . 'WHERE o.order_date = ? '
        . 'ORDER BY o.id, oi.id '
        . 'LIMIT ' . MAX_REPORT_ROWS,
        [$dateFilter]);

    if (count($rows) >= MAX_REPORT_ROWS) {
        log_warn('report_orders_csv: reached MAX_REPORT_ROWS', [
            'date' => $dateFilter,
            'limit' => MAX_REPORT_ROWS,
        ]);
    }

    $statusMap = [
        'PENDING'   => 'در انتظار تأیید',
        'APPROVED'  => 'تأیید شده',
        'REJECTED'  => 'رد شده',
        'CANCELLED' => 'لغو شده',
    ];

    $safeDate = preg_replace('/[^A-Za-z0-9._\-]/', '_', $dateFilter);
    if ($safeDate === '' || $safeDate === null) {
        $safeDate = 'report';
    }
    $filename = 'orders_' . $safeDate . '.csv';

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }

    $out = fopen('php://output', 'w');
    if ($out === false) {
        json_err('خطا در ساخت فایل CSV.', 500);
    }

    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'کد سفارش', 'شرکت', 'کد مشتری', 'تلفن', 'آدرس',
        'تاریخ', 'زمان', 'وضعیت', 'محصول', 'Variant', 'تعداد',
    ]);

    foreach ($rows as $r) {
        $sanitize = function ($v) {
            $s = (string)$v;
            if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $s = "'" . $s;
            }
            return $s;
        };

        fputcsv($out, [
            (int)$r['order_id'],
            $sanitize($r['company_name']),
            $sanitize($r['customer_code']),
            $sanitize($r['phone']),
            $sanitize($r['address']),
            $r['order_date'],
            $r['created_at'],
            $statusMap[$r['status']] ?? $r['status'],
            $sanitize($r['product_name']),
            $sanitize($r['variant_name'] ?? ''),
            (int)$r['quantity'],
        ]);
    }

    fclose($out);
    exit;
}

// ============================================================================
// SECTION: ADMIN — Settings
// ============================================================================

function route_admin_settings_get(): void
{
    require_admin();
    $pdo = get_db();
    $rows = db_all($pdo, 'SELECT key, value FROM settings');
    $out = [];
    foreach ($rows as $r) {
        $out[$r['key']] = $r['value'];
    }
    json_ok($out);
}

function route_admin_settings_update(): void
{
    require_admin();

    $p = read_json_body();
    $allowedKeys = ['ordering_start', 'ordering_end', 'timezone', 'site_name'];
    $pdo = get_db();

    foreach ($allowedKeys as $k) {
        if (!array_key_exists($k, $p)) continue;
        $v = $p[$k];

        if ($k === 'ordering_start' || $k === 'ordering_end') {
            if (parse_hhmm($v) === null) {
                json_err("فرمت زمان «$k» نامعتبر است. (HH:MM)", 422);
            }
        } elseif ($k === 'timezone') {
            $tzName = clean_str($v, MAX_LEN_TIMEZONE);
            if ($tzName === '') {
                json_err('Timezone نامعتبر است.', 422);
            }
            try {
                new DateTimeZone($tzName);
            } catch (Throwable $e) {
                json_err('Timezone نامعتبر است.', 422);
            }
            $p[$k] = $tzName;
        } elseif ($k === 'site_name') {
            $siteName = clean_str($v, MAX_LEN_SITE_NAME);
            if ($siteName === '') {
                json_err('نام سایت نمی‌تواند خالی باشد.', 422);
            }
            $p[$k] = $siteName;
        }
    }

    $newStart = $p['ordering_start'] ?? get_setting($pdo, 'ordering_start', '07:30');
    $newEnd   = $p['ordering_end']   ?? get_setting($pdo, 'ordering_end', '12:00');
    $ps = parse_hhmm($newStart);
    $pe = parse_hhmm($newEnd);
    if ($ps === null) {
        json_err('فرمت زمان شروع نامعتبر است. (HH:MM)', 422);
    }
    if ($pe === null) {
        json_err('فرمت زمان پایان نامعتبر است. (HH:MM)', 422);
    }
    if ($ps[0] * 60 + $ps[1] >= $pe[0] * 60 + $pe[1]) {
        json_err('زمان شروع باید قبل از زمان پایان باشد.', 422);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now')) "
        . "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime('now')"
    );

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($p as $k => $v) {
            if (!in_array($k, $allowedKeys, true)) {
                continue;
            }
            $stmt->execute([$k, (string)$v]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        log_error('settings_update failed', ['error' => $ex->getMessage()]);
        json_err('خطا در ذخیره تنظیمات.', 500);
    }

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'UPDATE_SETTINGS');

    $rows = db_all($pdo, 'SELECT key, value FROM settings');
    $out = [];
    foreach ($rows as $r) {
        $out[$r['key']] = $r['value'];
    }
    json_ok($out);
}

// ============================================================================
// SECTION: ADMIN — Cleanup
// ============================================================================

function prune_backups(): void
{
    try {
        if (!is_dir(LIMO_BACKUP_DIR)) {
            return;
        }
        $files = [];
        foreach (scandir(LIMO_BACKUP_DIR) ?: [] as $f) {
            if (strpos($f, 'backup_') === 0 && substr($f, -3) === '.db') {
                $files[] = LIMO_BACKUP_DIR . DIRECTORY_SEPARATOR . $f;
            }
        }
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, BACKUP_RETENTION_COUNT) as $old) {
            @unlink($old);
        }
    } catch (Throwable $e) {
        log_error('prune_backups failed', ['error' => $e->getMessage()]);
    }
}

function create_backup(): ?string
{
    $backupPath = null;
    try {
        if (!is_dir(LIMO_BACKUP_DIR)) {
            @mkdir(LIMO_BACKUP_DIR, 0750, true);
        }
        $ts = gmdate('Y_m_d_H_i_s');
        $backupPath = LIMO_BACKUP_DIR . DIRECTORY_SEPARATOR . 'backup_' . $ts . '.db';

        $pdo = get_db();
        $quoted = $pdo->quote($backupPath);
        $pdo->exec("VACUUM INTO $quoted");

        prune_backups();
        return $backupPath;
    } catch (Throwable $e) {
        try {
            if ($backupPath !== null && @copy(LIMO_DB_PATH, $backupPath)) {
                prune_backups();
                return $backupPath;
            }
        } catch (Throwable $e2) {}
        log_error('create_backup failed', ['error' => $e->getMessage()]);
        return null;
    }
}

function route_admin_cleanup(): void
{
    require_admin();

    $p = read_json_body();
    $beforeDate = clean_str($p['before_date'] ?? null, 16);
    $currentPassword = (string)($p['current_password'] ?? '');

    if ($beforeDate === '') {
        json_err('تاریخ مرجع برای پاک‌سازی الزامی است.', 422);
    }
    if (preg_match(DATE_PATTERN, $beforeDate) !== 1) {
        json_err('فرمت تاریخ باید YYYY-MM-DD باشد.', 422);
    }

    $bd = DateTime::createFromFormat('Y-m-d', $beforeDate, new DateTimeZone('UTC'));
    if (!$bd || $bd->format('Y-m-d') !== $beforeDate) {
        json_err('تاریخ نامعتبر است.', 422);
    }
    $todayMidnight = server_now()->setTime(0, 0, 0);
    $bdMidnight = new DateTime($beforeDate . ' 00:00:00', new DateTimeZone('UTC'));
    if ($bdMidnight >= $todayMidnight->setTimezone(new DateTimeZone('UTC'))) {
        json_err('تاریخ مرجع باید قبل از امروز باشد.', 422);
    }

    if ($currentPassword === '') {
        json_err('برای پاک‌سازی، رمز عبور فعلی الزامی است.', 422);
    }

    $pdo = get_db();
    $adminRow = load_admin_state($pdo, (int)$_SESSION['user_id']);
    if (!$adminRow || !verify_password_compat($currentPassword, $adminRow['password_hash'])) {
        log_activity('ADMIN', (int)$_SESSION['user_id'], 'CLEANUP_FAILED',
            ['reason' => 'invalid_current_password']);
        json_err('رمز عبور فعلی صحیح نیست.', 401);
    }

    $cntOrders = (int)(db_value($pdo,
        'SELECT COUNT(*) AS c FROM orders WHERE order_date < ?',
        [$beforeDate]) ?? 0);

    if ($cntOrders === 0) {
        json_ok([
            'deleted_orders' => 0,
            'backup'         => null,
            'message'        => 'موردی برای پاک‌سازی یافت نشد.',
        ]);
    }

    $backupPath = create_backup();

    try {
        $pdo->exec('BEGIN IMMEDIATE');

        db_exec($pdo,
            'DELETE FROM reservations WHERE order_id IN '
            . '(SELECT id FROM orders WHERE order_date < ?)',
            [$beforeDate]);

        db_exec($pdo, 'DELETE FROM orders WHERE order_date < ?', [$beforeDate]);

        db_exec($pdo,
            'DELETE FROM activity_logs WHERE date(created_at) < date(?)',
            [$beforeDate]);

        $retentionCutoff = gmdate('Y-m-d H:i:s', time() - LOGIN_ATTEMPTS_RETENTION_DAYS * 86400);
        db_exec($pdo,
            'DELETE FROM login_attempts WHERE created_at < ?',
            [$retentionCutoff]);

        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        log_error('cleanup failed', ['error' => $ex->getMessage()]);
        json_err('خطا در پاک‌سازی.', 500);
    }

    log_activity('ADMIN', (int)$_SESSION['user_id'], 'CLEANUP', [
        'before_date'    => $beforeDate,
        'deleted_orders' => $cntOrders,
    ]);

    json_ok([
        'deleted_orders' => $cntOrders,
        'backup'         => $backupPath ? basename($backupPath) : null,
        'message'        => $cntOrders . ' سفارش قدیمی حذف شد. Backup ذخیره گردید.',
    ]);
}

// ============================================================================
// SECTION: PUBLIC
// ============================================================================

function route_public_info(): void
{
    $pdo = get_db();
    $today = today_str($pdo);
    $wd = weekday_index(null, $pdo);
    [$allowed, $msg] = check_ordering_window($pdo);
    json_ok([
        'date'             => $today,
        'weekday'          => (int)$wd,
        'is_friday'        => $wd === 6,
        'ordering_allowed' => $allowed,
        'ordering_message' => $msg,
        'ordering_start'   => get_setting($pdo, 'ordering_start', '07:30'),
        'ordering_end'     => get_setting($pdo, 'ordering_end', '12:00'),
        'site_name'        => get_setting($pdo, 'site_name', 'رستوران لیمو'),
    ]);
}

// ============================================================================
// SECTION: Router
// ============================================================================

function limo_routes(): array
{
    return [
        // ---- PUBLIC ----
        ['GET',  '#^/api/health$#',                  'handle_health',                []],
        ['GET',  '#^/api/public/info$#',             'route_public_info',            []],

        // ---- AUTH ----
        ['POST', '#^/api/auth/customer/login$#',     'route_customer_login',         []],
        ['POST', '#^/api/auth/customer/logout$#',    'route_customer_logout',        []],
        ['POST', '#^/api/auth/admin/login$#',        'route_admin_login',            []],
        ['POST', '#^/api/auth/admin/logout$#',       'route_admin_logout',           []],
        ['GET',  '#^/api/auth/session$#',            'route_auth_session',           []],

        // ---- ADMIN — ACCOUNT ----
        ['GET',  '#^/api/admin/account$#',           'route_admin_account_get',      []],
        ['POST', '#^/api/admin/change-credentials$#','route_admin_change_credentials',[]],

        // ---- ADMIN — DASHBOARD ----
        ['GET',  '#^/api/admin/dashboard$#',         'route_admin_dashboard',        []],

        // ---- ADMIN — CUSTOMERS ----
        ['GET',    '#^/api/admin/customers$#',       'route_admin_customers_list',   []],
        ['POST',   '#^/api/admin/customers$#',       'route_admin_customers_create', []],
        ['PUT',    '#^/api/admin/customers/(\d+)$#', 'route_admin_customers_update', [1]],
        ['DELETE', '#^/api/admin/customers/(\d+)$#', 'route_admin_customers_disable',[1]],

        // ---- ADMIN — PRODUCTS ----
        ['GET',    '#^/api/admin/products$#',        'route_admin_products_list',    []],
        ['POST',   '#^/api/admin/products$#',        'route_admin_products_create',  []],
        ['PUT',    '#^/api/admin/products/(\d+)$#',  'route_admin_products_update',  [1]],
        ['DELETE', '#^/api/admin/products/(\d+)$#',  'route_admin_products_disable', [1]],

        // ---- ADMIN — VARIANTS ----
        ['POST',   '#^/api/admin/products/(\d+)/variants$#', 'route_admin_variant_create',  [1]],
        ['PUT',    '#^/api/admin/variants/(\d+)$#',          'route_admin_variant_update',  [1]],
        ['DELETE', '#^/api/admin/variants/(\d+)$#',          'route_admin_variant_disable', [1]],

        // ---- ADMIN — ORDERS ----
        ['GET',  '#^/api/admin/orders$#',                    'route_admin_orders_list',   []],
        ['GET',  '#^/api/admin/orders/(\d+)$#',              'route_admin_order_detail',  [1]],
        ['POST', '#^/api/admin/orders/(\d+)/approve$#',      'route_admin_order_approve', [1]],
        ['POST', '#^/api/admin/orders/(\d+)/reject$#',       'route_admin_order_reject',  [1]],

        // ---- ADMIN — CAPACITY ----
        ['GET',  '#^/api/admin/capacity$#',                  'route_admin_capacity',      []],

        // ---- ADMIN — REPORTS ----
        ['GET',  '#^/api/admin/reports/orders\.csv$#',       'route_admin_report_orders_csv', []],

        // ---- ADMIN — SETTINGS ----
        ['GET',  '#^/api/admin/settings$#',                  'route_admin_settings_get',    []],
        ['PUT',  '#^/api/admin/settings$#',                  'route_admin_settings_update', []],

        // ---- ADMIN — CLEANUP ----
        ['POST', '#^/api/admin/cleanup$#',                   'route_admin_cleanup',       []],

        // ---- CUSTOMER ----
        ['GET',  '#^/api/customer/profile$#',                'route_customer_profile',      []],
        ['GET',  '#^/api/customer/menu$#',                   'route_customer_menu',         []],
        ['GET',  '#^/api/customer/orders$#',                 'route_customer_orders_list',  []],
        ['POST', '#^/api/customer/orders$#',                 'route_customer_create_order', []],
        ['GET',  '#^/api/customer/orders/today$#',           'route_customer_today_order',  []],
        ['GET',  '#^/api/customer/orders/(\d+)$#',           'route_customer_order_detail', [1]],
        ['PUT',  '#^/api/customer/orders/(\d+)$#',           'route_customer_edit_order',   [1]],
        ['POST', '#^/api/customer/orders/(\d+)/cancel$#',    'route_customer_cancel_order', [1]],
    ];
}

function limo_dispatch(): void
{
    $method = current_method();
    $rawPath = current_path();
    // FIX-C: استفاده از normalize_api_path در main.php
    $path = normalize_api_path($rawPath);

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $routes = limo_routes();
    $pathMatched = false;
    $allowedMethods = [];

    foreach ($routes as [$rMethod, $pattern, $handler, $paramIdx]) {
        if (!preg_match($pattern, $path, $m)) {
            continue;
        }
        $pathMatched = true;
        $allowedMethods[] = $rMethod;
        if ($rMethod !== $method) {
            continue;
        }

        $args = [];
        foreach ($paramIdx as $idx) {
            if (isset($m[$idx])) {
                $args[] = (int)$m[$idx];
            }
        }

        if (!is_callable($handler)) {
            log_error('Route handler not callable', ['handler' => $handler, 'path' => $path]);
            json_err('خطای داخلی سرور.', 500);
        }

        try {
            call_user_func_array($handler, $args);
        } catch (Throwable $e) {
            log_error('Route handler threw exception', [
                'handler' => $handler,
                'path'    => $path,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            json_err('خطای داخلی سرور. لطفاً دوباره تلاش کنید.', 500);
        }
        return;
    }

    if ($pathMatched) {
        if (!headers_sent() && !empty($allowedMethods)) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
        }
        json_err('متد درخواست مجاز نیست.', 405);
    }
    json_err('منبع مورد نظر یافت نشد.', 404);
}

// ============================================================================
// SECTION: Entry Point
// ============================================================================

// SEC-014: ترتیب صحیح — ابتدا دیتابیس init شود، سپس CSRF check.

// 1) اطمینان از init دیتابیس (idempotent)
limo_ensure_init();

// 2) چک CSRF
$csrfCheck = check_csrf_pipeline();
if (!$csrfCheck['ok']) {
    json_err($csrfCheck['message'], $csrfCheck['status'], $csrfCheck['code']);
}

// 3) اجرای routing
limo_dispatch();