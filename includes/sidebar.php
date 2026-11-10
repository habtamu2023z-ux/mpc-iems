<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   OPTIONAL NAVIGATION SIDEBAR
   ============================================================
   PURPOSE
     Role-aware sidebar navigation that complements (or replaces)
     the topbar nav. Designed to be added with a single line in
     header.php and disabled by simply removing that line.

   ACTIVATION — one line in includes/header.php
     Find this line:
       <main style="min-height: 75vh; padding-bottom: 40px;">

     Add this line just BEFORE it:
       <?php require_once __DIR__ . '/sidebar.php'; ?>

   THE SIDEBAR IS OPTIONAL
     - Not rendered on the login page (no logged-in user)
     - Closed by default on first visit
     - Toggle button auto-injected into .topbar-inner by JS
     - State persisted in localStorage
     - Toggling adds/removes class on <body> so no inline
       content shift occurs until user opens it

   BEHAVIOR
     Desktop (≥1024px):
       - Sidebar slides in from left, pushes <main> right by 260px
       - Backdrop NOT shown (content still visible)
       - State persisted across page loads

     Mobile (<1024px):
       - Sidebar slides in as overlay on top of content
       - Dark backdrop behind it
       - Clicking backdrop or pressing Escape closes it
       - Always starts closed (mobile state not persisted)

   MENU STRUCTURE (role-aware)
     Everyone:           Dashboard, Notifications, My Profile, Logout
     Everyone (staff):   Clearances, Letters, Leave
     Students:           Dashboard, Clearances, Notifications, Profile, Logout
     HR:                 + Employee Register, Leave Balances, Leave Calendar
     Admin:              + Users, Departments, Roles, Settings, Policy, SLA, Workflow Templates, Backup
     Admin/HR:           + Reports section

   DEPENDENCIES
     functions.php (indirectly via the files below)
     auth_check.php    (Auth class — required)
     workflow_engine.php (Workflow::pendingCount — for badges)

   SAFETY
     - Direct access refused with 403
     - Returns silently if no logged-in user
     - Never throws — all Workflow calls fall back to 0 on failure
     - No topbar/brand duplication (sidebar starts below topbar)
   ============================================================ */


/* ------------------------------------------------------------
   1. DIRECT ACCESS GUARD
   ------------------------------------------------------------ */
if (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Direct access forbidden.');
}


/* ------------------------------------------------------------
   2. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';

// Graceful fallback if wrappers aren't loaded yet
if (!class_exists('Auth')) {
    require_once __DIR__ . '/auth_check.php';
}
if (!class_exists('Workflow')) {
    require_once __DIR__ . '/workflow_engine.php';
}


/* ------------------------------------------------------------
   3. RETURN EARLY IF NOT LOGGED IN
   ------------------------------------------------------------ */
$sidebarUser = current_user();
if (!$sidebarUser) {
    return;
}


/* ------------------------------------------------------------
   4. COLLECT CONTEXT
   ------------------------------------------------------------ */
$isAdmin    = has_role('admin');
$isHR       = has_role('hr');
$isStudent  = Auth::isStudent();
$isStaff    = !$isStudent;
$isRegistrar= has_role('registrar');
$uid        = (int)$sidebarUser['id'];

// Pending counts (fail safe to 0 — never block rendering)
$pendingApprovals  = 0;
$unreadNotifs      = 0;
try { $pendingApprovals = Workflow::pendingCount($uid); } catch (Throwable $e) { $pendingApprovals = 0; }
try { $unreadNotifs     = Notifier::unreadCount($uid); } catch (Throwable $e) { $unreadNotifs = 0; }

// Current path for active highlight
$currentPath = $_SERVER['REQUEST_URI'] ?? '';

/**
 * Helper: detect if a menu link matches the current URL.
 * Matches on path segment, so /modules/leave/leave_list.php
 * and /modules/leave/leave_view.php both highlight "Leave".
 */
$isMenuActive = function (string $needle) use ($currentPath): bool {
    if ($needle === '') return false;
    return str_contains($currentPath, $needle);
};

// Menu builders — each returns null if user lacks access
$menu = [

    'main' => [
        'label' => 'Main',
        'items' => [
            [
                'label'  => 'Dashboard',
                'label_am' => 'ዳሽቦርድ',
                'url'    => APP_URL . '/index.php',
                'icon'   => '🏠',
                'match'  => '/index.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Notifications',
                'label_am' => 'ማሳወቂያዎች',
                'url'    => APP_URL . '/notifications.php',
                'icon'   => '🔔',
                'match'  => '/notifications.php',
                'badge'  => $unreadNotifs > 0 ? $unreadNotifs : null,
            ],
        ],
    ],

    'services' => [
        'label' => 'Services',
        'items' => array_values(array_filter([
            [
                'label'  => 'Clearances',
                'label_am' => 'ክሊራንስ',
                'url'    => APP_URL . '/modules/clearance/clearance.php',
                'icon'   => '🎓',
                'match'  => '/modules/clearance/',
                'badge'  => null,
            ],
            // Letters — staff only
            $isStaff ? [
                'label'  => 'Letters',
                'label_am' => 'ደብዳቤዎች',
                'url'    => APP_URL . '/modules/letters/letters.php',
                'icon'   => '✉️',
                'match'  => '/modules/letters/',
                'badge'  => null,
            ] : null,
            // Leave — staff only
            $isStaff ? [
                'label'  => 'Leave',
                'label_am' => 'ፈቃድ',
                'url'    => APP_URL . '/modules/leave/leave_list.php',
                'icon'   => '🏖️',
                'match'  => '/modules/leave/',
                'badge'  => null,
            ] : null,
        ])),
    ],

    'hr' => ($isHR || $isAdmin) ? [
        'label' => 'Human Resources',
        'items' => [
            [
                'label'  => 'Employee Register',
                'label_am' => 'የሰራተኞች መዝገብ',
                'url'    => APP_URL . '/modules/hr/employee_register.php',
                'icon'   => '👥',
                'match'  => '/modules/hr/employee_register.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Leave Balances',
                'label_am' => 'የፈቃድ ቀሪ',
                'url'    => APP_URL . '/modules/hr/balances.php',
                'icon'   => '⚖️',
                'match'  => '/modules/hr/balances.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Leave Calendar',
                'label_am' => 'የፈቃድ መርሃ',
                'url'    => APP_URL . '/modules/leave/leave_calendar.php',
                'icon'   => '📅',
                'match'  => '/modules/leave/leave_calendar.php',
                'badge'  => null,
            ],
        ],
    ] : null,

    'requests' => [
        'label' => 'My Requests',
        'items' => [
            [
                'label'  => 'All Requests',
                'label_am' => 'ጥያቄዎች',
                'url'    => APP_URL . '/modules/requests/requests.php',
                'icon'   => '📨',
                'match'  => '/modules/requests/',
                'badge'  => $pendingApprovals > 0 ? $pendingApprovals : null,
            ],
        ],
    ],

    'admin' => $isAdmin ? [
        'label' => 'Administration',
        'items' => [
            [
                'label'  => 'Users',
                'label_am' => 'ተጠቃሚዎች',
                'url'    => APP_URL . '/admin/users.php',
                'icon'   => '👤',
                'match'  => '/admin/user',
                'badge'  => null,
            ],
            [
                'label'  => 'Departments',
                'label_am' => 'ክፍሎች',
                'url'    => APP_URL . '/admin/departments.php',
                'icon'   => '🏢',
                'match'  => '/admin/department',
                'badge'  => null,
            ],
            [
                'label'  => 'Roles',
                'label_am' => 'ሚናዎች',
                'url'    => APP_URL . '/admin/roles.php',
                'icon'   => '🎭',
                'match'  => '/admin/roles.php',
                'badge'  => null,
            ],
            [
                'label'  => 'System Settings',
                'label_am' => 'ቅንብሮች',
                'url'    => APP_URL . '/admin/system_settings.php',
                'icon'   => '⚙️',
                'match'  => '/admin/system_settings.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Policy Constants',
                'label_am' => 'ፖሊሲ ቋሚዎች',
                'url'    => APP_URL . '/admin/policy_constants.php',
                'icon'   => '📋',
                'match'  => '/admin/policy_constants.php',
                'badge'  => null,
            ],
            [
                'label'  => 'SLA Settings',
                'label_am' => 'የSLA ቅንብሮች',
                'url'    => APP_URL . '/admin/sla_settings.php',
                'icon'   => '⏱️',
                'match'  => '/admin/sla_settings.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Workflow Templates',
                'label_am' => 'የስራ ፍሰት',
                'url'    => APP_URL . '/admin/workflow_templates.php',
                'icon'   => '🔀',
                'match'  => '/admin/workflow_templates.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Backup',
                'label_am' => 'ምትኬ',
                'url'    => APP_URL . '/admin/backup.php',
                'icon'   => '💾',
                'match'  => '/admin/backup.php',
                'badge'  => null,
            ],
        ],
    ] : null,

    'reports' => ($isAdmin || $isHR) ? [
        'label' => 'Reports',
        'items' => [
            [
                'label'  => 'Dashboard',
                'label_am' => 'ሪፖርት',
                'url'    => APP_URL . '/reports/dashboard.php',
                'icon'   => '📊',
                'match'  => '/reports/dashboard.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Leave Report',
                'label_am' => 'የፈቃድ ሪፖርት',
                'url'    => APP_URL . '/reports/report_leave.php',
                'icon'   => '📈',
                'match'  => '/reports/report_leave.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Clearance Report',
                'label_am' => 'የክሊራንስ ሪፖርት',
                'url'    => APP_URL . '/reports/report_clearance.php',
                'icon'   => '📉',
                'match'  => '/reports/report_clearance.php',
                'badge'  => null,
            ],
            [
                'label'  => 'SLA Report',
                'label_am' => 'የSLA ሪፖርት',
                'url'    => APP_URL . '/reports/report_sla.php',
                'icon'   => '⏰',
                'match'  => '/reports/report_sla.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Audit Log',
                'label_am' => 'የኦዲት መዝገብ',
                'url'    => APP_URL . '/reports/report_audit.php',
                'icon'   => '🔍',
                'match'  => '/reports/report_audit.php',
                'badge'  => null,
            ],
        ],
    ] : null,

    'account' => [
        'label' => 'Account',
        'items' => [
            [
                'label'  => 'My Profile',
                'label_am' => 'መገለጫዬ',
                'url'    => APP_URL . '/modules/hr/employee_profile.php?id=' . $uid,
                'icon'   => '👤',
                'match'  => '/modules/hr/employee_profile.php',
                'badge'  => null,
            ],
            [
                'label'  => 'Logout',
                'label_am' => 'ውጣ',
                'url'    => APP_URL . '/logout.php',
                'icon'   => '🚪',
                'match'  => '/logout.php',
                'badge'  => null,
                'danger' => true,
            ],
        ],
    ],
];

// Remove null sections (role didn't have access)
$menu = array_filter($menu, fn($s) => $s !== null && !empty($s['items']));

// User info for header of sidebar
$userFullName = (string)$sidebarUser['full_name'];
$userPosition = (string)($sidebarUser['position'] ?? '');
$userDept     = (string)($sidebarUser['dept_name'] ?? '');
$userInitials = Auth::initials();
$userRole     = $isAdmin ? 'Administrator' : ($isHR ? 'HR Officer' : ($isStudent ? 'Student' : 'Staff'));
?>

<!-- ============================================================
     SIDEBAR — Everything below is scoped to .mpc-sidebar-*
     ============================================================ -->

<style>
    /* ---------- SIDEBAR LAYOUT ---------- */
    .mpc-sidebar {
        position: fixed;
        top: 75px;                    /* below topbar (header.php: 75px height) */
        left: 0;
        bottom: 0;
        width: 260px;
        background: #fff;
        border-right: 1px solid #E5E7EB;
        box-shadow: 2px 0 8px rgba(27, 42, 107, 0.06);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        z-index: 900;
        transform: translateX(-100%);
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        font-family: 'Noto Sans Ethiopic', system-ui, sans-serif;
    }

    body.mpc-sidebar-open .mpc-sidebar {
        transform: translateX(0);
    }

    /* ---------- TOGGLE BUTTON (auto-injected into .topbar-inner) ---------- */
    .mpc-sidebar-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        background: transparent;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 6px;
        color: #fff;
        cursor: pointer;
        margin-right: 12px;
        padding: 0;
        font-size: 18px;
        line-height: 1;
        transition: background 0.15s, border-color 0.15s;
        flex-shrink: 0;
    }
    .mpc-sidebar-toggle:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(255, 255, 255, 0.4);
    }
    .mpc-sidebar-toggle:focus-visible {
        outline: 2px solid #4DA6E8;
        outline-offset: 2px;
    }

    /* Hide the toggle if user hasn't got a sidebar available */
    body:not(.mpc-sidebar-present) .mpc-sidebar-toggle {
        display: none !important;
    }

    /* ---------- BACKDROP (mobile only) ---------- */
    .mpc-sidebar-backdrop {
        display: none;
        position: fixed;
        inset: 75px 0 0 0;
        background: rgba(15, 23, 42, 0.5);
        z-index: 899;
        opacity: 0;
        transition: opacity 0.25s;
    }
    body.mpc-sidebar-open .mpc-sidebar-backdrop {
        display: block;
        opacity: 1;
    }
    @media (min-width: 1024px) {
        /* On desktop, sidebar pushes content — no backdrop needed */
        body.mpc-sidebar-open .mpc-sidebar-backdrop {
            display: none !important;
        }
    }

    /* ---------- USER CARD (top of sidebar) ---------- */
    .mpc-sidebar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 16px 18px;
        border-bottom: 1px solid #E5E7EB;
        background: #F9FAFB;
        flex-shrink: 0;
    }
    .mpc-sidebar-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: #F59E0B;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 15px;
        flex-shrink: 0;
        letter-spacing: 0.5px;
    }
    .mpc-sidebar-user-info {
        min-width: 0;
        flex: 1;
    }
    .mpc-sidebar-user-name {
        font-size: 13px;
        font-weight: 700;
        color: #1B2A6B;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.3;
    }
    .mpc-sidebar-user-role {
        font-size: 11px;
        color: #6B7280;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.3;
        margin-top: 2px;
    }

    /* ---------- SCROLLABLE NAV ---------- */
    .mpc-sidebar-nav {
        flex: 1;
        overflow-y: auto;
        padding: 8px 8px 16px;
    }
    .mpc-sidebar-nav::-webkit-scrollbar {
        width: 6px;
    }
    .mpc-sidebar-nav::-webkit-scrollbar-thumb {
        background: #D1D5DB;
        border-radius: 3px;
    }

    /* ---------- SECTION LABEL ---------- */
    .mpc-sidebar-section {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #9CA3AF;
        padding: 14px 12px 6px;
        margin: 0;
    }
    .mpc-sidebar-section:first-child {
        padding-top: 6px;
    }

    /* ---------- MENU LINK ---------- */
    .mpc-sidebar-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        margin: 1px 0;
        border-radius: 6px;
        color: #374151;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        line-height: 1.4;
        transition: background 0.15s, color 0.15s;
        position: relative;
    }
    .mpc-sidebar-link:hover {
        background: #EFF6FF;
        color: #1B2A6B;
    }
    .mpc-sidebar-link:focus-visible {
        outline: 2px solid #4DA6E8;
        outline-offset: -2px;
    }
    .mpc-sidebar-link.mpc-active {
        background: #E0F2FE;
        color: #1B2A6B;
        font-weight: 700;
    }
    .mpc-sidebar-link.mpc-active::before {
        content: '';
        position: absolute;
        left: -8px;
        top: 6px;
        bottom: 6px;
        width: 3px;
        background: #4DA6E8;
        border-radius: 0 3px 3px 0;
    }
    .mpc-sidebar-link.mpc-danger {
        color: #991B1B;
    }
    .mpc-sidebar-link.mpc-danger:hover {
        background: #FEF2F2;
        color: #7F1D1D;
    }

    .mpc-sidebar-icon {
        font-size: 15px;
        line-height: 1;
        flex-shrink: 0;
        width: 20px;
        text-align: center;
    }

    .mpc-sidebar-label {
        flex: 1;
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .mpc-sidebar-label-am {
        display: block;
        font-size: 10px;
        color: #9CA3AF;
        font-weight: 400;
        margin-top: 1px;
        line-height: 1.2;
    }
    .mpc-sidebar-link.mpc-active .mpc-sidebar-label-am {
        color: #4DA6E8;
    }

    .mpc-sidebar-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        background: #DC2626;
        color: #fff;
        border-radius: 9999px;
        font-size: 10px;
        font-weight: 700;
        line-height: 1;
        flex-shrink: 0;
    }

    /* ---------- MAIN CONTENT SHIFT (desktop only) ---------- */
    @media (min-width: 1024px) {
        body.mpc-sidebar-open .topbar,
        body.mpc-sidebar-open main {
            margin-left: 260px;
            transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        /* Keep inner topbar-inner centered within the shifted space */
        body.mpc-sidebar-open .topbar-inner {
            max-width: calc(1180px);
            margin-left: auto;
            margin-right: auto;
        }
    }

    /* ---------- MOBILE: HIDE AMHARIC SUBTITLES FOR COMPACTNESS ---------- */
    @media (max-width: 768px) {
        .mpc-sidebar {
            width: 280px;
            top: 64px; /* mobile topbar is typically shorter */
        }
        .mpc-sidebar-label-am {
            display: none;
        }
    }
</style>

<div id="mpcSidebarBackdrop" class="mpc-sidebar-backdrop" aria-hidden="true"></div>

<aside id="mpcSidebar" class="mpc-sidebar" role="navigation" aria-label="Main navigation">
    <!-- User Card -->
    <div class="mpc-sidebar-user">
        <div class="mpc-sidebar-avatar"><?= e($userInitials) ?></div>
        <div class="mpc-sidebar-user-info">
            <div class="mpc-sidebar-user-name" title="<?= e($userFullName) ?>">
                <?= e($userFullName) ?>
            </div>
            <div class="mpc-sidebar-user-role">
                <?= e($userRole) ?><?= $userDept ? ' · ' . e($userDept) : '' ?>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="mpc-sidebar-nav">
        <?php foreach ($menu as $section): ?>
            <?php if (!empty($section['label'])): ?>
                <div class="mpc-sidebar-section"><?= e($section['label']) ?></div>
            <?php endif; ?>

            <?php foreach ($section['items'] as $item):
                $active  = $isMenuActive($item['match'] ?? '');
                $classes = 'mpc-sidebar-link';
                if ($active) $classes .= ' mpc-active';
                if (!empty($item['danger'])) $classes .= ' mpc-danger';
            ?>
                <a href="<?= e($item['url']) ?>"
                   class="<?= $classes ?>"
                   <?= $active ? 'aria-current="page"' : '' ?>>
                    <span class="mpc-sidebar-icon" aria-hidden="true"><?= $item['icon'] ?></span>
                    <span class="mpc-sidebar-label">
                        <?= e($item['label']) ?>
                        <?php if (!empty($item['label_am'])): ?>
                            <span class="mpc-sidebar-label-am"><?= e($item['label_am']) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if (!empty($item['badge'])): ?>
                        <span class="mpc-sidebar-badge"><?= (int)$item['badge'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</aside>

<script>
(function () {
    'use strict';

    if (window.__mpcSidebarInit) return;
    window.__mpcSidebarInit = true;

    var body      = document.body;
    var sidebar   = document.getElementById('mpcSidebar');
    var backdrop  = document.getElementById('mpcSidebarBackdrop');
    if (!sidebar) return;

    // Marker so CSS hides the toggle when the sidebar is absent
    body.classList.add('mpc-sidebar-present');

    /* ----------------------------------------
       TOGGLE BUTTON INJECTION
       Auto-add a hamburger button into the topbar.
       ---------------------------------------- */
    var toggleBtn = document.querySelector('.mpc-sidebar-toggle');

    if (!toggleBtn) {
        var topbarInner = document.querySelector('.topbar-inner');
        if (topbarInner) {
            toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'mpc-sidebar-toggle';
            toggleBtn.setAttribute('aria-label', 'Toggle navigation');
            toggleBtn.setAttribute('aria-controls', 'mpcSidebar');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.innerHTML = '☰';
            // Insert as the first child
            topbarInner.insertBefore(toggleBtn, topbarInner.firstChild);
        }
    }

    /* ----------------------------------------
       STATE MANAGEMENT
       Persist on desktop, always start closed on mobile.
       ---------------------------------------- */
    var STORAGE_KEY = 'mpc-sidebar-open';
    var isDesktop   = function () { return window.innerWidth >= 1024; };

    function setOpen(open) {
        body.classList.toggle('mpc-sidebar-open', open);
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggleBtn.innerHTML = open ? '✕' : '☰';
        }
        if (isDesktop()) {
            try {
                localStorage.setItem(STORAGE_KEY, open ? '1' : '0');
            } catch (e) {}
        }
    }

    function isOpen() {
        return body.classList.contains('mpc-sidebar-open');
    }

    // Restore state on desktop, always closed on mobile
    if (isDesktop()) {
        try {
            if (localStorage.getItem(STORAGE_KEY) === '1') {
                setOpen(true);
            }
        } catch (e) {}
    } else {
        // Mobile: strip any stale state
        body.classList.remove('mpc-sidebar-open');
    }

    /* ----------------------------------------
       EVENT BINDING
       ---------------------------------------- */
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (evt) {
            evt.stopPropagation();
            setOpen(!isOpen());
        });
    }

    // Click backdrop closes
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setOpen(false);
        });
    }

    // Escape closes
    document.addEventListener('keydown', function (evt) {
        if (evt.key === 'Escape' && isOpen()) {
            setOpen(false);
        }
    });

    // Auto-close on mobile when a nav link is clicked
    sidebar.querySelectorAll('.mpc-sidebar-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (!isDesktop()) setOpen(false);
        });
    });

    // Reset state when the viewport crosses the desktop/mobile breakpoint
    var lastWasDesktop = isDesktop();
    window.addEventListener('resize', function () {
        var nowDesktop = isDesktop();
        if (nowDesktop !== lastWasDesktop) {
            lastWasDesktop = nowDesktop;
            if (!nowDesktop) {
                // Switched to mobile — close
                body.classList.remove('mpc-sidebar-open');
                if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
            } else {
                // Switched to desktop — restore preference
                try {
                    if (localStorage.getItem(STORAGE_KEY) === '1') {
                        setOpen(true);
                    }
                } catch (e) {}
            }
        }
    });
})();
</script>