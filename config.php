<?php
declare(strict_types=1);

(function () {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) return;

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;

        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);

        // Strip surrounding quotes
        if (strlen($val) >= 2
            && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"))) {
            $val = substr($val, 1, -1);
        }

        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
})();

define('APP_ENV', getenv('APP_ENV') ?: 'development');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'mpc_iems');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('APP_NAME',    'MPC IEMS');
define('APP_VERSION', '3.0');
define('ORG_NAME',    'Misrak Polytechnic College');
define('ORG_NAME_AM', 'ምሥራቅ ፖሊ ቴክኒክ ኮሌጅ');

if (getenv('APP_URL')) {
    define('APP_URL', rtrim(getenv('APP_URL'), '/'));
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Figure out the subfolder by comparing SCRIPT_NAME to the project root
    $scriptDir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $projectDir = str_replace('\\', '/', __DIR__);

    // Walk up from the script's directory until we find the app root on disk
    $base = '';
    $probe = $scriptDir;
    while ($probe !== '' && $probe !== '/' && $probe !== '.') {
        if (is_file(__DIR__ . str_replace($probe, '', $scriptDir) . '/config.php')) {
            // (rare fallback — not usually reached)
            break;
        }
        $probe = dirname($probe);
    }

    // Simpler, reliable approach: assume APP is served from a folder
    // equal to the last segment of __DIR__ (e.g., /mpc-iems)
    $rootSegment = '/' . basename(__DIR__);
    if ($scriptDir === $rootSegment || str_starts_with($scriptDir, $rootSegment . '/')) {
        $base = $rootSegment;
    } elseif ($scriptDir === '/' || $scriptDir === '.') {
        $base = '';
    } else {
        // Script lives deeper than one level (e.g., /modules/leave)
        // Strip known module folders to recover the root
        $parts = explode('/', trim($scriptDir, '/'));
        // Heuristic: root is first segment
        $base = '/' . ($parts[0] ?? '');
        if ($base === '/') $base = '';
    }

    define('APP_URL', $scheme . '://' . $host . $base);
}

define('BASE_PATH',  __DIR__);
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('LOG_DIR',    __DIR__ . '/logs');
define('BACKUP_DIR', __DIR__ . '/backups');

/* ------------------------------------------------------------
   7. ERROR REPORTING
   ------------------------------------------------------------ */
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', LOG_DIR . '/error.log');
}

/* ------------------------------------------------------------
   8. CHARSET (must run before any output)
   ------------------------------------------------------------ */
mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

/* ------------------------------------------------------------
   9. TIMEZONE (Ethiopian Time = East Africa Time, UTC+3)
   ------------------------------------------------------------ */
date_default_timezone_set('Africa/Addis_Ababa');

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.gc_maxlifetime', '7200'); // 2 hours

    session_name('MPC_IEMS_SESSION');
    session_start();
}

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+03:00'",
    ]);

} catch (PDOException $e) {

    // Never let a stack trace leak to the browser in production
    if (APP_ENV === 'development') {
        $safeHost = htmlspecialchars(DB_HOST, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8');
        $safeMsg  = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

        http_response_code(500);
        die(<<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Database Error</title>
<style>
  body{font-family:system-ui,sans-serif;background:#f4f7fc;margin:0;padding:40px;color:#1f2937}
  .box{max-width:720px;margin:0 auto;background:#fff;border-left:5px solid #DC2626;
       border-radius:8px;padding:30px;box-shadow:0 4px 12px rgba(0,0,0,.08)}
  h1{margin:0 0 12px;color:#DC2626;font-size:22px}
  p{margin:0 0 10px;line-height:1.6}
  code{background:#fee2e2;padding:2px 6px;border-radius:4px;color:#991b1b;font-size:13px}
  .hint{margin-top:20px;padding:14px;background:#f0f9ff;border-left:3px solid #4DA6E8;border-radius:4px;font-size:14px}
</style></head><body>
  <div class="box">
    <h1>Database Connection Failed</h1>
    <p>Could not connect to MySQL database <code>{$safeName}</code> on host <code>{$safeHost}</code>.</p>
    <p><strong>Error:</strong> {$safeMsg}</p>
    <div class="hint">
      <strong>Checklist:</strong><br>
      • Is MySQL / MariaDB running in XAMPP?<br>
      • Does the database <code>{$safeName}</code> exist? (Import <code>mpc_iems.sql</code>)<br>
      • Are the credentials in <code>config.php</code> or <code>.env</code> correct?<br>
      • Is the <code>mpc_iems</code> user granted on <code>{$safeName}</code>?
    </div>
  </div>
</body></html>
HTML);
    }

    // Production: log & show generic message
    error_log('MPC-IEMS DB failure: ' . $e->getMessage());
    http_response_code(500);
    die('A system error occurred. Please contact the administrator.');
}

unset($envFile);