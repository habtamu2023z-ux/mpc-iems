<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   ENTRY POINT — PUBLIC LANDING + LOGGED-IN DASHBOARD
   ============================================================
   TWO MODES
     1. Guest   — Public landing page (no auth required)
     2. Member  — Full dashboard with stats, pending tasks,
                  quick actions, and system status

   FLOW
     - Guest branch exits before touching any logged-in logic
     - Logged-in branch uses Auth, Workflow, Notifier, Ref wrappers
     - All stat cards have data-stat-key for dashboard.js polling
     - Pending tasks display SLA countdown via v_pending_sla

   DEPENDENCIES
     functions.php           — base helpers
     includes/auth_check.php — Auth class (check, require, isAdmin)
     includes/workflow_engine.php — Workflow (pendingForUser)
     includes/notification.php    — Notifier (linkTo, unreadCount)
     includes/ref_generator.php   — Ref (parse for request type)
     includes/header.php          — Topbar + sidebar + flash
     includes/footer.php          — Footer + JS modules
   ============================================================ */


/* ------------------------------------------------------------
   1. BOOTSTRAP
   ------------------------------------------------------------ */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/auth_check.php';


/* ============================================================
   SCENARIO 1 — GUEST (PUBLIC LANDING PAGE)
   ============================================================ */
if (!Auth::check()) {

    $orgNameEn = get_setting('org_name_en', 'Misrak Polytechnic College');
    $orgNameAm = get_setting('org_name_am', 'ምሥራቅ ፖሊ ቴክኒክ ኮሌጅ');
    $orgEmail  = get_setting('org_email', 'misrakptc961@gmail.com');
    $orgPhone  = get_setting('org_phones', '011-6-61-03-92');
    $todayEth  = format_date(date('Y-m-d'));
    $logoUrl   = get_logo_url();
    $faviconUrl= get_favicon_url();
    ?>
    <!DOCTYPE html>
    <html lang="am">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="index, follow">
        <title><?= e($orgNameAm) ?> · <?= e($orgNameEn) ?></title>
        <link rel="icon" href="<?= e($faviconUrl) ?>">
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700;800&display=swap" rel="stylesheet">

        <style>
            :root {
                --mpc-blue: #4DA6E8; --mpc-navy: #1B2A6B; --mpc-flame: #F59E0B;
                --mpc-offwhite: #F4F7FC; --mpc-text: #1A1A1A;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Noto Sans Ethiopic', system-ui, sans-serif; }

            /* Hero */
            .hero {
                background: linear-gradient(135deg, var(--mpc-navy) 0%, var(--mpc-blue) 100%);
                color: #fff; padding: 80px 20px; text-align: center;
                position: relative; overflow: hidden; border-bottom: 5px solid var(--mpc-flame);
                min-height: 85vh; display: flex; align-items: center; justify-content: center;
            }
            .hero::before, .hero::after {
                content: ''; position: absolute; border-radius: 50%; background: rgba(255,255,255,0.05);
                pointer-events: none;
            }
            .hero::before { width: 500px; height: 500px; top: -150px; left: -150px; }
            .hero::after  { width: 400px; height: 400px; bottom: -100px; right: -100px; }

            .hero-content { position: relative; z-index: 2; max-width: 850px; margin: 0 auto; }
            .hero-logo {
                width: 140px; height: 140px; background: #fff; border-radius: 50%; padding: 5px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3); margin: 0 auto 25px;
                display: block; object-fit: contain;
            }
            .hero h2 { font-size: 22px; color: var(--mpc-flame); margin: 0 0 10px 0; font-weight: 700; letter-spacing: 1px; }
            .hero h1 { font-size: 42px; color: #fff; margin: 0 0 20px 0; font-weight: 800; letter-spacing: -0.5px; line-height: 1.2; }
            .hero p { font-size: 16px; line-height: 1.8; color: #E2E8F0; margin-bottom: 40px; }

            .btn-hero {
                background: var(--mpc-flame); color: #fff; padding: 15px 45px; font-size: 18px;
                border-radius: 50px; text-decoration: none; font-weight: 700; display: inline-block;
                transition: all 0.3s; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
            }
            .btn-hero:hover { background: #D97706; transform: translateY(-3px); box-shadow: 0 8px 25px rgba(245, 158, 11, 0.6); }

            /* Features */
            .features-section { padding: 80px 20px; max-width: 1100px; margin: 0 auto; }
            .section-title { text-align: center; color: var(--mpc-navy); font-size: 28px; margin-bottom: 50px; font-weight: 800; }
            .features-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px; }

            .feature-card {
                background: #fff; border-radius: 12px; padding: 35px 25px; text-align: center;
                box-shadow: 0 5px 20px rgba(27, 42, 107, 0.06); transition: transform 0.3s;
                border-top: 5px solid; position: relative; overflow: hidden;
            }
            .feature-card:hover { transform: translateY(-8px); box-shadow: 0 15px 35px rgba(27, 42, 107, 0.12); }
            .fc-clearance { border-color: var(--mpc-blue); }
            .fc-letters   { border-color: var(--mpc-navy); }
            .fc-hr        { border-color: var(--mpc-flame); }

            .feature-icon { font-size: 45px; margin-bottom: 20px; display: inline-block; padding: 15px; border-radius: 50%; }
            .fc-clearance .feature-icon { background: #E0F2FE; color: var(--mpc-blue); }
            .fc-letters .feature-icon   { background: #E0E7FF; color: var(--mpc-navy); }
            .fc-hr .feature-icon        { background: #FEF3C7; color: var(--mpc-flame); }

            .feature-card h3 { color: var(--mpc-navy); font-size: 20px; margin-bottom: 10px; }
            .feature-card p  { color: #6B7280; font-size: 14px; line-height: 1.6; }

            @media (max-width: 768px) {
                .hero h1 { font-size: 32px; }
                .hero h2 { font-size: 18px; }
                .features-grid { grid-template-columns: 1fr; }
            }
        </style>
    </head>
    <body>
        <section class="hero">
            <div class="hero-content">
                <img src="<?= e($logoUrl) ?>" alt="<?= e($orgNameEn) ?>" class="hero-logo">
                <h2><?= e($orgNameAm) ?></h2>
                <h1><?= e($orgNameEn) ?></h1>
                <p>Welcome to the Integrated Enterprise Management System (IEMS).<br>Your centralized portal for Clearances, Official Letters, and HR Administrative Processes.</p>
                <a href="<?= APP_URL ?>/login.php" class="btn-hero">ይግቡ (Login to Portal) &rarr;</a>
                <div style="margin-top: 30px; font-size: 14px; color: #CBD5E1;">📅 <?= e($todayEth) ?></div>
            </div>
        </section>

        <section class="features-section">
            <h2 class="section-title">System Modules (የስርዓቱ ክፍሎች)</h2>
            <div class="features-grid">
                <div class="feature-card fc-clearance">
                    <div class="feature-icon">🎓</div>
                    <h3>Clearances (ክሊራንስ)</h3>
                    <p>Process student graduation, class completion, and employee release clearances with step-by-step tracking.</p>
                </div>
                <div class="feature-card fc-letters">
                    <div class="feature-icon">✉️</div>
                    <h3>Official Letters (ደብዳቤዎች)</h3>
                    <p>Compose, send, and track internal memos and external letters with the official college letterhead.</p>
                </div>
                <div class="feature-card fc-hr">
                    <div class="feature-icon">🏢</div>
                    <h3>HR Processes (የሰው ኃይል)</h3>
                    <p>Manage Leave, Maternity, Pension, Resignation, Procurement, and Departmental Plans seamlessly.</p>
                </div>
            </div>
        </section>

        <footer style="background:var(--mpc-navy);color:#94A3B8;text-align:center;padding:30px 20px;border-top:4px solid var(--mpc-blue);">
            <p>&copy; <?= (int)(greg_to_ethiopian(date('Y-m-d'))['year'] ?? date('Y') - 7) ?> ዓ.ም (<?= date('Y') ?>) <strong><?= e($orgNameEn) ?></strong>. All rights reserved.</p>
            <p style="font-size:13px; margin-top:8px;">📞 <?= e($orgPhone) ?> | ✉️ <?= e($orgEmail) ?></p>
        </footer>
    </body>
    </html>
    <?php
    exit;
}


/* ============================================================
   SCENARIO 2 — LOGGED IN (SMART DASHBOARD)
   ============================================================ */

/* Load the workflow stack (pulls in notifier, ref, policy, audit) */
require_once __DIR__ . '/includes/workflow_engine.php';
require_once __DIR__ . '/includes/ref_generator.php';
require_once __DIR__ . '/includes/notification.php';

$user = Auth::require();
$isAdmin  = Auth::isAdmin();
$isStaff  = !Auth::isStudent();

/* ------------------------------------------------------------
   2. STATS
   ------------------------------------------------------------ */
try {
    $stats = $pdo->query("SELECT * FROM v_dashboard_counts")->fetch() ?: [];
} catch (Throwable $e) {
    $stats = [
        'active_users' => 0, 'open_clearances' => 0, 'completed_clearances' => 0,
        'sent_letters' => 0, 'open_requests' => 0, 'pending_steps' => 0, 'unread_notifications' => 0,
    ];
}

/* ------------------------------------------------------------
   3. PENDING TASKS FOR THIS USER
   ------------------------------------------------------------ */
$pendingTasks = [];
try {
    $pendingTasks = Workflow::pendingForUser((int)$user['id']);
} catch (Throwable $e) {
    $pendingTasks = [];
}

/* SLA data — keyed by "kind-step_id" */
$slaInfo = [];
if (!empty($pendingTasks)) {
    try {
        $st = $pdo->query("SELECT kind, step_id, hours_waiting, sla_hours, sla_state FROM v_pending_sla");
        foreach ($st->fetchAll() as $s) {
            $slaInfo[$s['kind'] . '-' . (int)$s['step_id']] = $s;
        }
    } catch (Throwable $e) {
        $slaInfo = [];
    }
}

/* Count overdue tasks for the summary line */
$overdueCount = 0;
foreach ($pendingTasks as $t) {
    $key = $t['kind'] . '-' . (int)$t['step_id'];
    if (($slaInfo[$key]['sla_state'] ?? '') === 'BREACHED') {
        $overdueCount++;
    }
}

/* ------------------------------------------------------------
   4. DATE + DISPLAY
   ------------------------------------------------------------ */
$todayEth  = format_date(date('Y-m-d'));
$todayGreg = date('l, F j, Y');

/* ------------------------------------------------------------
   5. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<!-- ============================================================
     DASHBOARD
     ============================================================ -->
<div style="display: flex; flex-direction: column; gap: 25px; padding-top: 20px; padding-bottom: 40px; max-width: 1180px; margin: 0 auto;">

    <!-- 1. WELCOME BANNER -->
    <div style="background: linear-gradient(90deg, var(--mpc-navy), #2A3A85); border-radius: 12px; padding: 30px; color: #fff; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; box-shadow: 0 4px 15px rgba(27,42,107,0.2); position: relative; overflow: hidden;">
        <div style="position: relative; z-index: 2;">
            <h1 style="margin: 0; font-size: 28px; color: #fff;">
                እንኳን ደህና መጡ, <?= e(Auth::firstName()) ?>!
            </h1>
            <p style="margin: 8px 0 0 0; color: var(--mpc-blue); font-size: 15px; font-weight: 500;">
                <?= e(ucwords(str_replace('_', ' ', (string)($user['position'] ?? 'User')))) ?>
                <?php if (!empty($user['dept_name'])): ?>
                    · <?= e($user['dept_name']) ?>
                <?php endif; ?>
            </p>
        </div>

        <!-- Ethiopian + Gregorian date/time widget (polled by dashboard.js) -->
        <div style="text-align: right; position: relative; z-index: 2; background: rgba(255,255,255,0.1); padding: 12px 20px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.2);">
            <div style="font-size: 16px; font-weight: bold; color: var(--mpc-flame);"><?= e($todayEth) ?></div>
            <div style="font-size: 12px; color: #CBD5E1; margin-top: 4px;"><?= e($todayGreg) ?> (<?= date('h:i A') ?>)</div>
        </div>

        <!-- Decorative circle -->
        <div style="position: absolute; right: -20px; top: -20px; width: 150px; height: 150px; background: rgba(77,166,232,0.1); border-radius: 50%;"></div>
    </div>

    <!-- 2. STATS GRID (polled by dashboard.js) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">

        <div class="card" style="margin:0; border-left: 5px solid var(--mpc-blue); display: flex; align-items: center; gap: 15px;">
            <div style="font-size: 35px; background: #E0F2FE; padding: 10px; border-radius: 10px;">🎓</div>
            <div>
                <div style="font-size: 28px; font-weight: 800; color: var(--mpc-navy); line-height: 1;" data-stat-key="open_clearances">
                    <?= (int)($stats['open_clearances'] ?? 0) ?>
                </div>
                <div style="font-size: 13px; color: #6B7280; font-weight: 600; margin-top: 5px;">Open Clearances</div>
            </div>
        </div>

        <div class="card" style="margin:0; border-left: 5px solid var(--mpc-flame); display: flex; align-items: center; gap: 15px;">
            <div style="font-size: 35px; background: #FEF3C7; padding: 10px; border-radius: 10px;">⏳</div>
            <div>
                <div style="font-size: 28px; font-weight: 800; color: var(--mpc-navy); line-height: 1;" data-stat-key="pending_steps">
                    <?= (int)($stats['pending_steps'] ?? 0) ?>
                </div>
                <div style="font-size: 13px; color: #6B7280; font-weight: 600; margin-top: 5px;">Pending Steps</div>
            </div>
        </div>

        <div class="card" style="margin:0; border-left: 5px solid #16a34a; display: flex; align-items: center; gap: 15px;">
            <div style="font-size: 35px; background: #DCFCE7; padding: 10px; border-radius: 10px;">✉️</div>
            <div>
                <div style="font-size: 28px; font-weight: 800; color: var(--mpc-navy); line-height: 1;" data-stat-key="sent_letters">
                    <?= (int)($stats['sent_letters'] ?? 0) ?>
                </div>
                <div style="font-size: 13px; color: #6B7280; font-weight: 600; margin-top: 5px;">Letters Sent</div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <div class="card" style="margin:0; border-left: 5px solid var(--mpc-red); display: flex; align-items: center; gap: 15px;">
                <div style="font-size: 35px; background: #FEE2E2; padding: 10px; border-radius: 10px;">👥</div>
                <div>
                    <div style="font-size: 28px; font-weight: 800; color: var(--mpc-navy); line-height: 1;" data-stat-key="active_users">
                        <?= (int)($stats['active_users'] ?? 0) ?>
                    </div>
                    <div style="font-size: 13px; color: #6B7280; font-weight: 600; margin-top: 5px;">Active Users</div>
                </div>
            </div>
        <?php else: ?>
            <div class="card" style="margin:0; border-left: 5px solid var(--mpc-navy); display: flex; align-items: center; gap: 15px;">
                <div style="font-size: 35px; background: #E0E7FF; padding: 10px; border-radius: 10px;">🏢</div>
                <div>
                    <div style="font-size: 28px; font-weight: 800; color: var(--mpc-navy); line-height: 1;" data-stat-key="open_requests">
                        <?= (int)($stats['open_requests'] ?? 0) ?>
                    </div>
                    <div style="font-size: 13px; color: #6B7280; font-weight: 600; margin-top: 5px;">Open HR Requests</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 3. MAIN CONTENT GRID -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; align-items: start;">

        <!-- LEFT: Pending Actions -->
        <div class="card" style="margin: 0; padding: 0; overflow: hidden;">
            <div style="padding: 20px; border-bottom: 1px solid var(--mpc-border); display: flex; justify-content: space-between; align-items: center; background: #fafafa; flex-wrap: wrap; gap: 10px;">
                <h2 style="margin: 0; font-size: 18px; color: var(--mpc-navy);">Action Required (የሚጠበቁ ተግባራት)</h2>
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                    <span class="badge badge-warning" style="font-size: 13px; padding: 5px 10px;">
                        <?= count($pendingTasks) ?> Pending
                    </span>
                    <?php if ($overdueCount > 0): ?>
                        <span class="badge badge-danger" style="font-size: 13px; padding: 5px 10px;">
                            ⚠ <?= $overdueCount ?> Overdue
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="padding: 20px;">
                <?php if (empty($pendingTasks)): ?>
                    <div style="text-align: center; padding: 40px 20px;">
                        <div style="font-size: 40px; margin-bottom: 15px;">🎉</div>
                        <h3 style="color: var(--mpc-navy); margin-bottom: 5px;">All caught up!</h3>
                        <p class="muted">You have no pending tasks or approvals.</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach (array_slice($pendingTasks, 0, 6) as $task):
                            $taskKey = $task['kind'] . '-' . (int)$task['step_id'];

                            /* Determine request type from ref_no (e.g. REQ-LEAVE-...) */
                            $parsed = Ref::parse((string)$task['ref_no']);
                            $requestType = $parsed['valid']
                                ? ucfirst($parsed['domain'] ?? 'request')
                                : ($task['kind'] === 'clearance' ? 'Clearance' : 'Request');

                            /* Correct action link via Notifier::linkTo() */
                            $actionUrl = $task['kind'] === 'clearance'
                                ? Notifier::linkTo('clearance', (int)$task['record_id'])
                                : Notifier::linkTo('request',   (int)$task['record_id']);

                            /* SLA badge from v_pending_sla */
                            $sla = $slaInfo[$taskKey] ?? null;
                            $slaState = $sla['sla_state'] ?? '';
                            $slaClass = match ($slaState) {
                                'BREACHED' => 'mpc-sla-overdue',
                                'AT_RISK'  => 'mpc-sla-warning',
                                'OK'       => 'mpc-sla-ok',
                                default    => '',
                            };
                        ?>
                            <div style="border: 1px solid var(--mpc-border); border-radius: 8px; padding: 15px; display: flex; justify-content: space-between; align-items: center; gap: 12px; transition: background 0.2s;" onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background='#fff'">
                                <div style="flex: 1; min-width: 0;">
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px; flex-wrap: wrap;">
                                        <span class="badge <?= $task['kind'] === 'clearance' ? 'badge-info' : 'badge-primary' ?>">
                                            <?= e($requestType) ?>
                                        </span>
                                        <strong style="color: var(--mpc-navy); font-size: 14px;"><?= e($task['ref_no']) ?></strong>
                                        <?php if ($sla && $slaClass !== ''): ?>
                                            <span class="mpc-sla <?= $slaClass ?>">
                                                <?php if ($slaState === 'BREACHED'): ?>
                                                    ⚠ Overdue
                                                <?php else: ?>
                                                    ⏰ <?= (int)round($sla['sla_hours'] - $sla['hours_waiting']) ?>h left
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 13px; color: var(--mpc-muted);">
                                        <?php if ($task['kind'] === 'clearance'): ?>
                                            Department: <?= e($task['target_department']) ?>
                                        <?php else: ?>
                                            Role: <?= e(Workflow::roleLabel($task['target_role'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a href="<?= e($actionUrl) ?>" class="btn btn-sm btn-primary" style="white-space: nowrap;">
                                    Review &rarr;
                                </a>
                            </div>
                        <?php endforeach; ?>

                        <?php if (count($pendingTasks) > 6): ?>
                            <div style="text-align: center; margin-top: 10px;">
                                <p class="muted" style="font-size: 13px;">
                                    Showing 6 of <?= count($pendingTasks) ?> tasks.
                                    <a href="<?= APP_URL ?>/modules/requests/requests.php" style="color: var(--mpc-blue);">View all &rarr;</a>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Quick Actions + System Status -->
        <div style="display: flex; flex-direction: column; gap: 25px;">

            <div class="card" style="margin: 0;">
                <h2 style="margin-top: 0; margin-bottom: 15px; font-size: 18px; color: var(--mpc-navy);">Quick Actions (ፈጣን እርምጃዎች)</h2>
                <div style="display: flex; flex-direction: column; gap: 10px;">

                    <?php if (Auth::isStudent()): ?>
                        <a href="<?= APP_URL ?>/modules/clearance/clearance_new.php" class="btn btn-ghost" style="text-align:left; padding:12px; border:1px solid #ddd; background: #fff;">🎓 Request New Clearance</a>
                    <?php else: ?>
                        <a href="<?= APP_URL ?>/modules/clearance/clearance.php" class="btn btn-ghost" style="text-align:left; padding:12px; border:1px solid #ddd; background: #fff;">📋 Manage Clearances</a>
                    <?php endif; ?>

                    <?php if ($isStaff): ?>
                        <a href="<?= APP_URL ?>/modules/letters/letter_new.php" class="btn btn-ghost" style="text-align:left; padding:12px; border:1px solid #ddd; background: #fff;">✉️ Compose Official Letter</a>

                        <a href="<?= APP_URL ?>/modules/leave/leave_new.php" class="btn btn-ghost" style="text-align:left; padding:12px; border:1px solid #ddd; background: #fff;">🏖️ Request Leave</a>

                        <?php if (has_role(['hr', 'admin'])): ?>
                            <a href="<?= APP_URL ?>/modules/hr/employee_register.php" class="btn btn-ghost" style="text-align:left; padding:12px; border:1px solid #ddd; background: #fff;">👥 Employee Register</a>
                            <a href="<?= APP_URL ?>/modules/hr/balances.php" class="btn btn-ghost" style="text-align:left; padding:12px; border:1px solid #ddd; background: #fff;">⚖️ Leave Balances</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <a href="<?= APP_URL ?>/notifications.php" class="btn btn-ghost" style="text-align:left; padding:12px; border:1px solid #ddd; background: #fff;">🔔 Notifications</a>

                    <?php if ($isAdmin): ?>
                        <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-ghost" style="text-align:left; padding:12px; border:1px solid #ddd; background: #fff; color: var(--mpc-red); font-weight: bold;">⚙️ Admin Settings</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- System Status Widget -->
            <div class="card" style="margin: 0; background: var(--mpc-bg);">
                <h2 style="margin-top: 0; margin-bottom: 15px; font-size: 16px; color: var(--mpc-navy);">System Status</h2>
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 10px; border-bottom: 1px dashed #ccc; padding-bottom: 8px;">
                    <span class="muted">Database</span>
                    <span style="color: #16a34a; font-weight: bold;">● Online</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 10px; border-bottom: 1px dashed #ccc; padding-bottom: 8px;">
                    <span class="muted">Your Role</span>
                    <strong style="color: var(--mpc-navy);"><?= e(ucwords(str_replace('_', ' ', (string)($user['position'] ?? 'User')))) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px;">
                    <span class="muted">Last Login</span>
                    <strong><?= !empty($user['last_login_at']) ? format_date($user['last_login_at'], true) : 'First Login' ?></strong>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>