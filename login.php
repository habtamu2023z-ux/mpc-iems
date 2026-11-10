<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   LOGIN PAGE
   ============================================================
   PURPOSE
     Authenticate users and establish their session. Split-screen
     layout with Ethiopian branding on the left, form on the right.

   FEATURES
     - CSRF protection on every POST
     - Rate limiting: 5 failed attempts → 60-second lockout
     - Session fixation prevention (via Auth::login())
     - Account status check (inactive/retired blocked)
     - Generic error for bad credentials (no username enumeration)
     - Bilingual error messages (Amharic + English)
     - Password visibility toggle
     - Dev quick-login panel (only when APP_ENV === 'development')
     - Flash message rendering (e.g., after logout)

   DEPENDENCIES
     functions.php      — base helpers
     includes/csrf.php  — CSRF wrapper
     includes/flash.php — flash wrapper
     includes/auth_check.php — Auth class
     assets/css/style.css  — page reset + alert styling
     assets/css/login.css  — split-screen layout + form styling
     assets/js/app.js      — password toggle

   VISUALS
     All styles from assets/css/login.css. If that file is missing,
     the page falls back to style.css's .login-body (centered flex,
     gradient background) — still usable, just not split-screen.
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/auth_check.php';


/* ------------------------------------------------------------
   2. REDIRECT IF ALREADY LOGGED IN
   ------------------------------------------------------------ */
if (Auth::check()) {
    redirect(APP_URL . '/index.php');
}


/* ------------------------------------------------------------
   3. HANDLE POST
   ------------------------------------------------------------ */
$error          = '';
$username_input = '';
$showForm       = true;  // set to false when rate-limited

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- CSRF check ----
    Csrf::check();

    // ---- Rate limit check ----
    $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $waitSeconds = $lockedUntil - time();
        $error = "በጣም ብዙ ሙከራዎች ተደርገዋል። እባክዎ <strong>{$waitSeconds}</strong> ሰከንድ ይጠብቁ።"
               . "<br><small style='font-weight:normal;'>Too many failed attempts. Please wait {$waitSeconds} seconds.</small>";
        $showForm = false;
    } else {
        // ---- Collect input ----
        $username_input = trim((string)($_POST['username'] ?? ''));
        $password       = (string)($_POST['password'] ?? '');

        if ($username_input === '' || $password === '') {
            $error = "እባክዎ የተጠቃሚ ስም እና የይለፍ ቃል ያስገቡ።"
                   . "<br><small style='font-weight:normal;'>Please enter both username and password.</small>";
        } else {
            // ---- Fetch user ----
            $stmt = $pdo->prepare("
                SELECT id, full_name, password_hash, status, deleted_at
                FROM users
                WHERE username = ?
                LIMIT 1
            ");
            $stmt->execute([$username_input]);
            $loginUser = $stmt->fetch();

            // ---- Verify ----
            if (
                $loginUser
                && $loginUser['deleted_at'] === null
                && password_verify($password, (string)$loginUser['password_hash'])
            ) {
                if ($loginUser['status'] !== 'active') {
                    $error = "መለያዎ በአሁኑ ወቅት ንቁ አይደለም (Inactive)። እባክዎ የሰው ኃይል ክፍልን ያግኙ።"
                           . "<br><small style='font-weight:normal;'>Your account is currently inactive. Please contact HR.</small>";
                } else {
                    // ---- SUCCESS ----
                    Auth::login((int)$loginUser['id']);

                    // Clear rate-limit state
                    unset($_SESSION['login_fail'], $_SESSION['login_locked_until']);

                    // Honour the intended URL if a module set one
                    $target = $_SESSION['intended_url'] ?? (APP_URL . '/index.php');
                    unset($_SESSION['intended_url']);

                    Flash::success('እንኳን ደህና መጡ, ' . Auth::firstName() . '!');
                    redirect($target);
                }
            } else {
                // ---- FAILURE ----
                // Increment failure counter (session-based)
                $fails = (int)($_SESSION['login_fail'] ?? 0) + 1;
                $_SESSION['login_fail'] = $fails;

                if ($fails >= 5) {
                    $_SESSION['login_locked_until'] = time() + 60;
                    $_SESSION['login_fail'] = 0;
                }

                // Generic message (prevents username enumeration)
                $error = "የተሳሳተ የተጠቃሚ ስም ወይም የይለፍ ቃል።"
                       . "<br><small style='font-weight:normal;'>Invalid username or password.</small>";
            }
        }
    }
}


/* ------------------------------------------------------------
   4. BRANDING & DISPLAY DATA
   ------------------------------------------------------------ */
$orgNameEn  = get_setting('org_name_en', 'Misrak Polytechnic College');
$orgNameAm  = get_setting('org_name_am', 'ምሥራቅ ፖሊ ቴክኒክ ኮሌጅ');
$todayEth   = format_date(date('Y-m-d'));
$logoUrl    = get_logo_url();
$faviconUrl = get_favicon_url();
$isDev      = defined('APP_ENV') && APP_ENV === 'development';
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">

    <!-- CSRF token (available to JS on this page, if ever needed) -->
    <?= Csrf::metaTag() ?>

    <title>ይግቡ · Sign In · <?= e($orgNameAm) ?></title>

    <link rel="icon" href="<?= e($faviconUrl) ?>">

    <!-- Base styles (reset, alerts, flash styling) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">

    <!-- Login-specific split-screen layout + form styles -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/login.css">

    <!-- Ethiopian Font -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="login-body">

    <!-- ============================================================
         LEFT PANEL — ETHIOPIAN BRANDING
         ============================================================ -->
    <div class="login-split-left">
        <div class="branding-content">
            <img src="<?= e($logoUrl) ?>" alt="<?= e($orgNameEn) ?>">
            <h1><?= e($orgNameAm) ?></h1>
            <h2><?= e($orgNameEn) ?></h2>
            <p>የተቋሙ የመረጃ አስተዳደር ስርዓት (IEMS) እንኳን በደህና መጡ። ክሊራንስ፣ ደብዳቤዎች እና የሰው ኃይል አገልግሎቶችን ለማግኘት እባክዎ ይግቡ።</p>
            <div class="eth-date-box">
                📅 <?= e($todayEth) ?>
            </div>
        </div>
    </div>

    <!-- ============================================================
         RIGHT PANEL — LOGIN FORM
         ============================================================ -->
    <div class="login-split-right">
        <div class="login-form-container">

            <!-- Back to home -->
            <a href="<?= APP_URL ?>/index.php" class="back-link">
                &larr; ወደ መጀመሪያ ገፅ ተመለስ (Back to Home)
            </a>

            <div class="login-header">
                <h3>ይግቡ / Sign In</h3>
                <p>እባክዎ የተጠቃሚ መረጃዎን ያስገቡ።</p>
            </div>

            <!-- Flash messages (e.g., "logged out successfully") -->
            <?= Flash::render() ?>

            <!-- Error message -->
            <?php if ($error !== ''): ?>
                <div class="alert-danger" role="alert">
                    <?= $error /* pre-escaped + contains trusted <strong>/<small> */ ?>
                </div>
            <?php endif; ?>

            <?php if ($showForm): ?>
                <!-- ============================================================
                     LOGIN FORM
                     ============================================================ -->
                <form method="post" action="" novalidate>
                    <?= Csrf::field() ?>

                    <div class="form-group">
                        <label for="username">
                            የተጠቃሚ ስም
                            <span class="en">Username</span>
                        </label>
                        <div class="input-wrapper">
                            <input
                                type="text"
                                id="username"
                                name="username"
                                required
                                autofocus
                                autocomplete="username"
                                placeholder="እባክዎ ያስገቡ..."
                                value="<?= e($username_input) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">
                            የይለፍ ቃል
                            <span class="en">Password</span>
                        </label>
                        <div class="input-wrapper">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••">
                            <span
                                class="toggle-password"
                                role="button"
                                tabindex="0"
                                aria-label="Toggle password visibility"
                                onclick="togglePassword('password')"
                                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();togglePassword('password');}">
                                👁️
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        ይግቡ (Sign In) &rarr;
                    </button>
                </form>
            <?php else: ?>
                <!-- Rate-limited state -->
                <div style="text-align:center; padding:20px 0;">
                    <p class="muted" style="font-size:13px;">
                        ለደህንነት ሲባል መግቢያው ለጊዜው ተቆልፏል።
                        <br>
                        <span style="font-size:12px;">Login is temporarily locked for security.</span>
                    </p>
                    <a href="<?= APP_URL ?>/login.php" class="btn-submit" style="display:inline-block; width:auto; padding:12px 30px; margin-top:15px; text-decoration:none;">
                        🔄 Try Again
                    </a>
                </div>
            <?php endif; ?>

            <!-- ============================================================
                 DEV QUICK-LOGIN PANEL
                 Only shown when APP_ENV === 'development'
                 ============================================================ -->
            <?php if ($isDev && $showForm): ?>
                <div class="dev-panel">
                    <p>⚡ QUICK LOGIN (DEV MODE)</p>
                    <div class="dev-tags">
                        <button type="button" onclick="fillDemo('admin')">admin</button>
                        <button type="button" onclick="fillDemo('hr')">hr</button>
                        <button type="button" onclick="fillDemo('registrar')">registrar</button>
                        <button type="button" onclick="fillDemo('dept_head')">dept_head</button>
                        <button type="button" onclick="fillDemo('employee1')">employee1</button>
                        <button type="button" onclick="fillDemo('student1')">student1</button>
                    </div>
                    <p style="margin-top:10px; font-weight:normal; font-size:11px;">
                        Password for all accounts: <code>password</code>
                    </p>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ============================================================
         SCRIPTS
         ============================================================ -->
    <script src="<?= APP_URL ?>/assets/js/app.js"></script>
    <script>
        /**
         * Fill the login form with demo credentials.
         * Only wired up in development mode.
         */
        function fillDemo(username) {
            var u = document.querySelector('input[name="username"]');
            var p = document.getElementById('password');
            if (u) u.value = username;
            if (p) p.value = 'password';
        }

        /* Auto-submit the form when Enter is pressed in either field.
           (Browser handles this natively for text inputs inside forms,
           but this ensures the behavior even if a browser quirk exists.) */
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.querySelector('form');
            if (!form) return;
            form.addEventListener('keydown', function (evt) {
                if (evt.key === 'Enter' && evt.target.tagName === 'INPUT') {
                    evt.preventDefault();
                    form.submit();
                }
            });
        });
    </script>
</body>
</html>