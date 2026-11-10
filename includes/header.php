<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   GLOBAL HEADER
   ============================================================
   PURPOSE
     Outputs the <!DOCTYPE>, <head>, and topbar navigation.
     Opens <main> for the page content, which footer.php closes.

   INCLUDED BY
     Every logged-in page after setting $pageTitle:
       $pageTitle = 'Page Name';
       include __DIR__ . '/../includes/header.php';

   EXPECTS (all optional — safe defaults applied)
     $user       array   Current user (default: current_user())
     $pageTitle  string  Page title (default: 'Dashboard')
     $showSidebar bool   Show the sidebar? (default: true)

   PROVIDES (in scope after this file runs)
     $user             array|null
     $unreadCount      int
     $orgNameEn        string
     $orgNameAm        string
     $todayEth         string
     $todayGreg        string
     $pageTitle        string

   DEPENDENCIES
     functions.php      (base helpers)
     csrf.php           (for <meta name="csrf-token">)
     flash.php          (for Flash::render())
     notification.php   (for Notifier::unreadCount())
     sidebar.php        (loaded inline if $showSidebar && $user)
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   Each wrapper has class_exists / function_exists guards,
   so double-loading is a no-op.
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/notification.php';


/* ------------------------------------------------------------
   2. COLLECT PAGE CONTEXT
   ------------------------------------------------------------ */

// $user — preserve if module already set it
$user = $user ?? current_user();

// Unread notification count (fail-safe to 0)
$unreadCount = 0;
if ($user) {
    try {
        $unreadCount = Notifier::unreadCount((int)$user['id']);
    } catch (Throwable $e) {
        $unreadCount = 0;
    }
}

// Dynamic branding
$orgNameEn = get_setting('org_name_en', 'Misrak Polytechnic College');
$orgNameAm = get_setting('org_name_am', 'ምስራቅ ፖሊ ቴክኒክ ኮሌጅ');

// Dates (Ethiopian + Gregorian)
$todayEth  = format_date(date('Y-m-d'));
$todayGreg = date('M j, Y');

// Page title fallback
$pageTitle = $pageTitle ?? 'Dashboard';

// Sidebar visibility flag
$showSidebar = $showSidebar ?? true;
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- CSRF token for JavaScript (read by workflow.js / dashboard.js) -->
    <?= Csrf::metaTag() ?>

    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>

    <!-- Dynamic Favicon -->
    <link rel="icon" href="<?= get_favicon_url() ?>">

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">

    <!-- Ethiopian Font -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ============================================================
           FALLBACK CHROME STYLES
           These guarantee the topbar renders even if style.css fails
           to load. Content styles (cards, tables, badges) live in
           style.css and are NOT duplicated here.
           ============================================================ */

        /* Page basics */
        body {
            margin: 0;
            font-family: 'Noto Sans Ethiopic', system-ui, -apple-system, sans-serif;
            background: #f4f7fc;
            color: #1f2937;
        }

        /* ========== TOPBAR ========== */
        .topbar {
            background: #1B2A6B;
            color: #fff;
            border-bottom: 3px solid #4DA6E8;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-inner {
            max-width: 1180px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 75px;
            gap: 15px;
        }

        /* ========== BRAND ========== */
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #fff;
            flex-shrink: 0;
        }
        .brand img {
            height: 45px;
            width: 45px;
            object-fit: contain;
            background: #fff;
            border-radius: 50%;
            padding: 3px;
            box-shadow: 0 0 0 2px #4DA6E8;
        }
        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .brand-am {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
        }
        .brand-en {
            font-size: 11px;
            font-weight: 600;
            color: #4DA6E8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ========== NAV ========== */
        .nav {
            display: flex;
            gap: 2px;
            flex: 1;
            justify-content: center;
            margin: 0 20px;
        }
        .nav a {
            color: #cbd5e1;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.15s, color 0.15s;
            display: flex;
            flex-direction: column;
            align-items: center;
            line-height: 1.3;
            white-space: nowrap;
        }
        .nav a span.am {
            font-size: 11px;
            opacity: 0.8;
            font-weight: 400;
        }
        .nav a:hover,
        .nav a.active {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .nav a:focus-visible {
            outline: 2px solid #4DA6E8;
            outline-offset: 2px;
        }

        /* ========== RIGHT CONTROLS ========== */
        .user-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-shrink: 0;
        }
        .date-widget {
            text-align: right;
            font-size: 11px;
            color: #cbd5e1;
            line-height: 1.3;
            margin-right: 10px;
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            padding-right: 15px;
        }
        .date-widget strong {
            color: #F59E0B;
            display: block;
            font-size: 13px;
        }

        /* Notification bell */
        .notif-bell {
            position: relative;
            color: #fff;
            text-decoration: none;
            font-size: 20px;
            line-height: 1;
            transition: opacity 0.15s;
        }
        .notif-bell:hover { opacity: 0.8; }
        .notif-bell:focus-visible {
            outline: 2px solid #4DA6E8;
            outline-offset: 4px;
            border-radius: 4px;
        }
        .notif-badge {
            position: absolute;
            top: -5px;
            right: -8px;
            background: #dc2626;
            color: #fff;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 5px;
            border-radius: 10px;
            border: 2px solid #1B2A6B;
            min-width: 18px;
            text-align: center;
            line-height: 1;
        }

        /* User dropdown */
        .user-dropdown {
            position: relative;
        }
        .user-dropdown > button {
            background: transparent;
            border: none;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0;
            font-family: inherit;
        }
        .user-dropdown > button:focus-visible {
            outline: 2px solid #4DA6E8;
            outline-offset: 4px;
            border-radius: 6px;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 55px;
            background: #fff;
            min-width: 240px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            z-index: 1001;
            overflow: hidden;
            border: 1px solid #eee;
        }
        .dropdown-content a {
            display: block;
            padding: 12px 15px;
            color: #333;
            text-decoration: none;
            font-size: 14px;
            border-bottom: 1px solid #eee;
            transition: background 0.15s;
        }
        .dropdown-content a:hover {
            background: #f9f9f9;
        }
        .dropdown-content a:last-child {
            border-bottom: none;
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 1024px) {
            .nav a span.am { display: none; }
            .date-widget   { display: none; }
        }
        @media (max-width: 768px) {
            .nav        { display: none; }
            .brand-text { display: none; }
            .topbar-inner { height: 64px; }
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            .topbar *,
            .topbar *::before,
            .topbar *::after {
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>

<?php if ($user): ?>
<!-- ============================================================
     LOGGED-IN TOPBAR
     ============================================================ -->
<header class="topbar">
    <div class="topbar-inner">

        <!-- Brand / Logo -->
        <a href="<?= APP_URL ?>/index.php" class="brand" aria-label="<?= e($orgNameEn) ?> — Dashboard">
            <img src="<?= get_logo_url() ?>" alt="<?= e($orgNameEn) ?>">
            <div class="brand-text">
                <span class="brand-am"><?= e($orgNameAm) ?></span>
                <span class="brand-en"><?= e($orgNameEn) ?></span>
            </div>
        </a>

        <!-- Navigation -->
        <nav class="nav" aria-label="Primary navigation">
            <a href="<?= APP_URL ?>/index.php" class="<?= is_active('index.php') ?>" <?= is_active('index.php') ? 'aria-current="page"' : '' ?>>
                Dashboard <span class="am">ዳሽቦርድ</span>
            </a>

            <a href="<?= APP_URL ?>/modules/clearance/clearance.php" class="<?= is_active('clearance') ?>" <?= is_active('clearance') ? 'aria-current="page"' : '' ?>>
                Clearances <span class="am">ክሊራንስ</span>
            </a>

            <?php if (!has_role('student')): ?>
                <a href="<?= APP_URL ?>/modules/letters/letters.php" class="<?= is_active('letters') ?>" <?= is_active('letters') ? 'aria-current="page"' : '' ?>>
                    Letters <span class="am">ደብዳቤዎች</span>
                </a>
                <a href="<?= APP_URL ?>/modules/leave/leave_list.php" class="<?= is_active('leave') ?>" <?= is_active('leave') ? 'aria-current="page"' : '' ?>>
                    Leave <span class="am">ፈቃድ</span>
                </a>
            <?php endif; ?>

            <!-- Requests — visible to everyone (students see their own) -->
            <a href="<?= APP_URL ?>/modules/requests/requests.php" class="<?= is_active('requests') ?>" <?= is_active('requests') ? 'aria-current="page"' : '' ?>>
                Requests <span class="am">ጥያቄዎች</span>
            </a>

            <?php if (has_role('admin')): ?>
                <a href="<?= APP_URL ?>/admin/users.php" class="<?= is_active('admin') ?>" <?= is_active('admin') ? 'aria-current="page"' : '' ?>>
                    Admin <span class="am">አስተዳደር</span>
                </a>
            <?php endif; ?>
        </nav>

        <!-- Right Side: Date, Notifications & User -->
        <div class="user-controls">

            <!-- Ethiopian Date Widget -->
            <div class="date-widget" aria-label="Current date">
                <strong><?= e($todayEth) ?></strong>
                <span><?= e($todayGreg) ?></span>
            </div>

            <!-- Notification Bell -->
            <a href="<?= APP_URL ?>/notifications.php" class="notif-bell"
               title="Notifications" aria-label="Notifications<?= $unreadCount > 0 ? ' (' . (int)$unreadCount . ' unread)' : '' ?>">
                <span aria-hidden="true">🔔</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="notif-badge" aria-hidden="true"><?= (int)$unreadCount ?></span>
                <?php endif; ?>
            </a>

            <!-- User Dropdown -->
            <div class="user-dropdown">
                <button type="button"
                        onclick="toggleUserMenu(event)"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="userMenu"
                        aria-label="User menu">
                    <div style="width:35px;height:35px;background:#F59E0B;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:16px;color:#fff;flex-shrink:0;">
                        <?= e(strtoupper(mb_substr($user['full_name'] ?? '?', 0, 1, 'UTF-8'))) ?>
                    </div>
                    <div style="display:flex;flex-direction:column;text-align:left;line-height:1.2;">
                        <span style="font-size:13px;font-weight:600;">
                            <?= e(explode(' ', (string)$user['full_name'])[0] ?? 'User') ?>
                        </span>
                        <span style="font-size:10px;color:#4DA6E8;text-transform:uppercase;">
                            <?= e($user['position'] ?? 'User') ?>
                        </span>
                    </div>
                    <span style="font-size:10px;margin-left:5px;" aria-hidden="true">▼</span>
                </button>

                <!-- Dropdown Menu -->
                <div class="dropdown-content" id="userMenu" role="menu">
                    <div style="padding:15px;border-bottom:1px solid #eee;background:#f9f9f9;">
                        <strong style="display:block;color:#1B2A6B;font-size:14px;">
                            <?= e($user['full_name'] ?? 'User') ?>
                        </strong>
                        <small style="color:#666;font-size:12px;">
                            <?= e($user['email'] ?? 'No email') ?>
                        </small>
                        <div style="margin-top:5px;font-size:11px;color:#888;">
                            ክፍል / Dept: <?= e($user['dept_name'] ?? 'N/A') ?>
                        </div>
                    </div>

                    <a href="<?= APP_URL ?>/modules/hr/employee_profile.php?id=<?= (int)$user['id'] ?>"
                       onclick="event.stopPropagation();"
                       role="menuitem">
                        👤 My Profile
                    </a>

                    <a href="<?= APP_URL ?>/notifications.php"
                       onclick="event.stopPropagation();"
                       role="menuitem">
                        🔔 Notifications<?= $unreadCount > 0 ? ' (' . (int)$unreadCount . ')' : '' ?>
                    </a>

                    <a href="<?= APP_URL ?>/logout.php"
                       onclick="event.stopPropagation();"
                       style="color:#dc2626;font-weight:bold;display:flex;align-items:center;gap:8px;"
                       role="menuitem">
                        🚪 Logout (ውጣ)
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<?php
/* ------------------------------------------------------------
   3. OPTIONAL SIDEBAR
   Renders only when $showSidebar is truthy AND user is logged
   in. Pages that want a full-width layout should set:
       $showSidebar = false;
   before including this header.
   ------------------------------------------------------------ */
if ($showSidebar) {
    require_once __DIR__ . '/sidebar.php';
}
?>

<!-- Main Content Wrapper -->
<main style="min-height: 75vh; padding-bottom: 40px;">

    <!-- Flash Messages (escaped, ARIA-compliant) -->
    <?= Flash::render() ?>

<?php else: ?>
    <!-- ============================================================
         GUEST FALLBACK
         Some pages (e.g., a public 404, or a forced redirect landing)
         include header.php without being logged in. This branch
         renders nothing but a plain <main> so footer.php can close it.
         ============================================================ -->
    <main class="container">
<?php endif; ?>

<!-- ============================================================
     USER DROPDOWN SCRIPT
     ============================================================ -->
<script>
    /**
     * Toggle the user dropdown menu.
     * Updates aria-expanded for accessibility.
     * Also closes when clicking outside (handled by app.js).
     */
    function toggleUserMenu(event) {
        if (event && event.stopPropagation) event.stopPropagation();

        var menu = document.getElementById('userMenu');
        var btn  = event && event.currentTarget ? event.currentTarget : null;
        if (!menu) return;

        var isHidden = (menu.style.display === 'none' || menu.style.display === '');
        menu.style.display = isHidden ? 'block' : 'none';

        if (btn && btn.setAttribute) {
            btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        }
    }

    /* Escape key closes the user menu */
    document.addEventListener('keydown', function (evt) {
        if (evt.key !== 'Escape') return;
        var menu = document.getElementById('userMenu');
        if (!menu || menu.style.display !== 'block') return;
        menu.style.display = 'none';
        var btn = document.querySelector('.user-dropdown > button[aria-haspopup]');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    });
</script>