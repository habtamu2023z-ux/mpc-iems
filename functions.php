<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* ============================================================
   1. SECURITY & OUTPUT HELPERS
   ============================================================ */

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never {
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    }
    exit;
}

function is_post(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function old(string $key, string $default = ''): string {
    return e($_POST[$key] ?? $default);
}

function current_url(): string {
    return APP_URL . ($_SERVER['REQUEST_URI'] ?? '/');
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   2. CSRF PROTECTION
   ============================================================ */

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void {
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) {
        http_response_code(419);
        die('Security check failed. Please refresh the page and try again.');
    }
}

/* ============================================================
   3. FLASH MESSAGES
   ============================================================ */

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ============================================================
   4. AUTHENTICATION & AUTHORIZATION
   ============================================================ */

/**
 * Get the currently logged-in user (with department info).
 * Caches for the request so multiple calls don't re-hit the DB.
 */
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;

    static $cached = null;
    static $cachedId = null;
    $sessionId = (int)$_SESSION['uid'];

    if ($cached !== null && $cachedId === $sessionId) {
        return $cached;
    }

    global $pdo;
    $st = $pdo->prepare("
        SELECT u.*, d.name AS dept_name, d.code AS dept_code
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.id = ? AND u.status = 'active' AND u.deleted_at IS NULL
        LIMIT 1
    ");
    $st->execute([$sessionId]);
    $row = $st->fetch();

    if (!$row) {
        // Session belongs to a deactivated/deleted user → kill session
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        return null;
    }

    $cached   = $row;
    $cachedId = $sessionId;
    return $row;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        flash('warning', 'Your session expired. Please log in.');
        redirect(APP_URL . '/login.php');
    }
    return $u;
}

/**
 * Role check with three-tier fallback:
 *   1. user_roles + roles tables (proper RBAC)
 *   2. username string match       (legacy)
 *   3. position string match       (legacy)
 *
 * This preserves every existing caller while making roles configurable.
 */
function has_role(string|array $roles): bool {
    $user = current_user();
    if (!$user) return false;

    $roles = (array)$roles;

    // Fast path: username/position string match (covers legacy calls)
    if (in_array($user['username'], $roles, true)) return true;
    if (in_array($user['position'], $roles, true)) return true;

    // Authoritative check: user_roles + roles tables (cached per request)
    static $roleCache = [];
    $uid = (int)$user['id'];
    if (!isset($roleCache[$uid])) {
        global $pdo;
        $st = $pdo->prepare("
            SELECT r.code
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $st->execute([$uid]);
        $roleCache[$uid] = array_column($st->fetchAll(), 'code');
    }

    foreach ($roles as $r) {
        if (in_array($r, $roleCache[$uid], true)) return true;
    }
    return false;
}

function require_role(string|array $roles): void {
    if (!has_role($roles)) {
        http_response_code(403);
        die('<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>');
    }
}

/* ============================================================
   5. SETTINGS & POLICY
   ============================================================ */

function get_setting(string $key, string $default = ''): string {
    global $pdo;
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $st->execute([$key]);
        $val = $st->fetchColumn();
        $cache[$key] = ($val === false || $val === null || $val === '') ? $default : (string)$val;
    } catch (PDOException $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

function get_policy(string $code, $default = null) {
    global $pdo;
    static $cache = [];
    if (array_key_exists($code, $cache)) return $cache[$code];

    try {
        $st = $pdo->prepare("SELECT value_num, value_text FROM policy_constants WHERE code = ?");
        $st->execute([$code]);
        $row = $st->fetch();
    } catch (PDOException $e) {
        return $cache[$code] = $default;
    }

    if (!$row) {
        $cache[$code] = $default;
    } else {
        $cache[$code] = $row['value_num'] !== null ? $row['value_num'] : $row['value_text'];
    }
    return $cache[$code];
}

function get_logo_url(): string {
    $logo = get_setting('system_logo');
    if ($logo && file_exists(__DIR__ . '/' . ltrim($logo, '/'))) {
        return APP_URL . '/' . ltrim($logo, '/');
    }
    return APP_URL . '/assets/img/logo-misrak.png';
}

function get_favicon_url(): string {
    $fav = get_setting('system_favicon');
    if ($fav && file_exists(__DIR__ . '/' . ltrim($fav, '/'))) {
        return APP_URL . '/' . ltrim($fav, '/');
    }
    return APP_URL . '/assets/img/favicon.png';
}

/* ============================================================
   6. WORKFLOW & AUDIT
   ============================================================ */

function generate_ref(string $prefix): string {
    return strtoupper($prefix . '-' . date('Ymd') . '-' . bin2hex(random_bytes(3)));
}

function log_audit(
    string $action,
    string $entity,
    int $entity_id,
    array $old = [],
    array $new = []
): void {
    global $pdo;
    try {
        $user = current_user();
        $st = $pdo->prepare("
            INSERT INTO audit_log
                (user_id, action, entity, entity_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $user['id'] ?? null,
            $action,
            $entity,
            $entity_id ?: null,
            $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Never let an audit failure break the caller
        error_log('MPC-IEMS audit failure: ' . $e->getMessage());
    }
}

/**
 * Check whether the current user may act on a workflow step
 * requiring a given role code (department_head, hr, finance, etc.).
 */
function can_approve_step(string $step_role_required): bool {
    $user = current_user();
    if (!$user) return false;

    // Admin always has override
    if (has_role('admin')) return true;

    // Primary: user_roles table lookup
    if (has_role($step_role_required)) return true;

    // Fallback: legacy string matching (kept for seeded users)
    $dept = $user['dept_code'] ?? '';
    return match ($step_role_required) {
        'department_head' => $user['position'] === 'Department Head',
        'hr'              => $user['username'] === 'hr',
        'registrar'       => $user['username'] === 'registrar',
        'director'        => $user['username'] === 'director',
        'finance'         => $dept === 'FIN',
        'library'         => $dept === 'LIB',
        'store'           => $dept === 'STORE',
        'dormitory'       => $dept === 'DORM',
        'medical'         => $dept === 'CLINIC',
        'planning'        => $dept === 'PLAN',
        default           => false,
    };
}

/* ============================================================
   7. ETHIOPIAN CALENDAR
   ============================================================ */

/**
 * Convert Gregorian → Ethiopian.
 * Anchored to Sept 12, 2023 = Meskerem 1, 2016 EC.
 * Cached per request (dashboards convert the same date many times).
 */
function greg_to_ethiopian(string $date): array {
    static $cache = [];
    if (isset($cache[$date])) return $cache[$date];

    try {
        $d = new DateTime($date);
    } catch (Exception $e) {
        return $cache[$date] = ['year' => 0, 'month' => 1, 'day' => 1];
    }

    $epoch    = new DateTime('2023-09-12');
    $interval = $d->diff($epoch);
    $days     = (int)$interval->days;
    $invert   = (int)$interval->invert;
    $eYear    = 2016;

    if ($invert === 0) {
        while (true) {
            $daysInYear = ($eYear % 4 === 3) ? 366 : 365;
            if ($days >= $daysInYear) {
                $days -= $daysInYear;
                $eYear++;
            } else {
                break;
            }
        }
    } else {
        while (true) {
            $eYear--;
            $daysInYear = ($eYear % 4 === 3) ? 366 : 365;
            if ($days > $daysInYear) {
                $days -= $daysInYear;
            } else {
                $days = $daysInYear - $days;
                break;
            }
        }
    }

    $eMonth = 1;
    while ($days >= 30 && $eMonth < 13) {
        $days -= 30;
        $eMonth++;
    }

    if ($eMonth === 13) {
        $maxDays = ($eYear % 4 === 3) ? 6 : 5;
        if ($days >= $maxDays) {
            $days -= $maxDays;
            $eYear++;
            $eMonth = 1;
        }
    }

    return $cache[$date] = ['year' => $eYear, 'month' => $eMonth, 'day' => $days + 1];
}

function eth_months(): array {
    return [
        1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ህዳር', 4 => 'ታህሳስ',
        5 => 'ጥር',    6 => 'የካቲት', 7 => 'መጋቢት', 8 => 'ሚያዝያ',
        9 => 'ግንቦት', 10 => 'ሰኔ',   11 => 'ሐምሌ',  12 => 'ነሐሴ',
        13 => 'ጳጉሜን',
    ];
}

/**
 * Format a date as an Ethiopian string, e.g. "ጥቅምት 12, 2016 ዓ.ም".
 */
function format_date(?string $date, bool $showTime = false): string {
    if (!$date || $date === '0000-00-00' || trim($date) === '') return '-';

    $eth = greg_to_ethiopian($date);
    if (($eth['year'] ?? 0) === 0) return '-';

    $months = eth_months();
    $m = $eth['month'];
    if (!isset($months[$m])) return '-';

    $out = $months[$m] . ' ' . $eth['day'] . ', ' . $eth['year'] . ' ዓ.ም';

    if ($showTime) {
        $ts = strtotime($date);
        if ($ts !== false) $out .= ' · ' . date('h:i A', $ts);
    }
    return $out;
}

/**
 * Dual format: Ethiopian main + Gregorian in small gray text.
 * Used by official print templates.
 */
function format_date_dual(?string $date): string {
    if (!$date) return '-';
    $ts = strtotime($date);
    if ($ts === false) return '-';
    $eth  = format_date($date);
    $greg = date('M j, Y', $ts);
    return $eth . ' <span style="font-size:11px; color:#888;">(' . $greg . ')</span>';
}

/* ============================================================
   8. UI HELPERS
   ============================================================ */

function status_badge(string $status): string {
    $map = [
        'pending'      => 'warning',
        'in_progress'  => 'info',
        'approved'     => 'success',
        'rejected'     => 'danger',
        'completed'    => 'success',
        'cancelled'    => 'secondary',
        'draft'        => 'secondary',
        'submitted'    => 'warning',
        'sent'         => 'success',
        'archived'     => 'secondary',
        'active'       => 'success',
        'inactive'     => 'danger',
        'suspended'    => 'warning',
        'retired'      => 'info',
        'resigned'     => 'danger',
        'skipped'      => 'secondary',
    ];

    $cls   = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge badge-' . $cls . '">' . e($label) . '</span>';
}

function is_active(string $path): string {
    return str_contains($_SERVER['PHP_SELF'] ?? '', $path) ? 'active' : '';
}

/* ============================================================
   9. FILE UPLOAD
   ============================================================ */

/**
 * Secure file uploader.
 * Validates extension AND MIME type, generates random filename,
 * creates target directory if missing.
 *
 * @return string|false Relative path (e.g. "uploads/attachments/leave/x.pdf") or false on failure.
 */
function upload_file(
    array $file,
    string $targetDir,
    array $allowedTypes = ['pdf', 'jpg', 'jpeg', 'png']
): string|false {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return false;

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes, true)) return false;

    // MIME sniffing — blocks .php disguised as .jpg
    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }
    $safeMimes = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'doc'  => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];
    if ($mime !== null && isset($safeMimes[$ext]) && !in_array($mime, $safeMimes[$ext], true)) {
        return false;
    }

    $filename   = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = rtrim($targetDir, '/') . '/' . $filename;
    $fullPath   = __DIR__ . '/' . $targetPath;
    $dir        = dirname($fullPath);

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    return move_uploaded_file($file['tmp_name'], $fullPath) ? $targetPath : false;
}