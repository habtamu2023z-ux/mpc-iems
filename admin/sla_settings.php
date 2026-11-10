<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   SLA SETTINGS & MONITORING (Admin only)
   ============================================================
   PURPOSE
     Two roles in one page:
     1. Edit the 8 SLA threshold constants
     2. Monitor live SLA health (pending / at-risk / breached)

   WHY THIS EXISTS vs. policy_constants.php
     policy_constants.php is the broad policy editor. This file
     is the SLA-focused operations center — it shows the LIVE
     status of every pending request so admins can see problems
     before they become complaints.

   FEATURES
     - Live dashboard: total pending / at-risk (< 24h) / breached
     - Per-type breakdown: 8 SLA categories with live counts
     - Oldest breach age displayed prominently
     - Editable thresholds with per-row impact preview
     - "Apply to pending" option (uses Workflow::recalculateSLA)
     - Recent at-risk + breached request table (top 30)
     - Direct links to the affected request/clearance
     - Cross-link to policy_constants.php for deeper context
     - Full audit trail per save

   DEPENDENCIES
     functions.php            — base helpers
     includes/auth_check.php  — Auth::requireRole
     includes/csrf.php        — Csrf::check, Csrf::field
     includes/flash.php       — Flash::success, warning, danger, info
     includes/audit.php       — Audit::updated
     includes/policy.php      — Policy::all, Policy::clearCache
     includes/workflow_engine.php — Workflow::recalculateSLA
     includes/notification.php — Notifier::linkTo

   SAFETY
     - Admin-only
     - CSRF required on POST
     - Numeric validation with per-constant bounds
     - Transaction wraps all SLA updates
     - No-op detection (no writes, no audit when nothing changed)
     - error_log on failure, generic message to user
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/policy.php';
require_once __DIR__ . '/../includes/workflow_engine.php';
require_once __DIR__ . '/../includes/notification.php';


/* ------------------------------------------------------------
   2. AUTH GATE
   ------------------------------------------------------------ */
$user = Auth::require();
Auth::requireRole('admin', 'You do not have permission to manage SLA settings.');


/* ------------------------------------------------------------
   3. SLA METADATA
   Maps each SLA constant to its category, matching rules, and
   display metadata.
   ------------------------------------------------------------ */
$slaMeta = [
    'SLA_CLEARANCE_DEPT_DAYS' => [
        'label'    => 'Clearance — Department Step',
        'label_am' => 'የክሊራንስ ክፍል',
        'icon'     => '🎓',
        'color'    => 'var(--mpc-blue)',
        'unit'     => 'days',
        'min'      => 0, 'max' => 90, 'step' => 1,
        'help'     => 'Each clearance approval step (Department, Library, Finance, etc.) must be acted on within N days.',
        'kind'     => 'clearance',
    ],
    'SLA_LEAVE_SHORT_DAYS' => [
        'label'    => 'Short Leave (≤ 5 days)',
        'label_am' => 'አጭር ፈቃድ',
        'icon'     => '🏖️',
        'color'    => 'var(--mpc-blue)',
        'unit'     => 'days',
        'min'      => 0, 'max' => 90, 'step' => 1,
        'help'     => 'Approval target for leave requests of 5 days or fewer.',
        'kind'     => 'leave_short',
    ],
    'SLA_LEAVE_LONG_DAYS' => [
        'label'    => 'Long Leave (> 5 days)',
        'label_am' => 'ረጅም ፈቃድ',
        'icon'     => '🏖️',
        'color'    => 'var(--mpc-blue)',
        'unit'     => 'days',
        'min'      => 0, 'max' => 90, 'step' => 1,
        'help'     => 'Approval target for leave requests longer than 5 days.',
        'kind'     => 'leave_long',
    ],
    'SLA_MATERNAL_DAYS' => [
        'label'    => 'Maternal / Paternity',
        'label_am' => 'የወሊድ / አባት ፈቃድ',
        'icon'     => '👶',
        'color'    => 'var(--mpc-flame)',
        'unit'     => 'days',
        'min'      => 0, 'max' => 90, 'step' => 1,
        'help'     => 'Approval target for maternity and paternity requests.',
        'kind'     => 'maternal',
    ],
    'SLA_PENSION_DAYS' => [
        'label'    => 'Pension Out',
        'label_am' => 'ጡረታ',
        'icon'     => '👴',
        'color'    => 'var(--mpc-navy)',
        'unit'     => 'days',
        'min'      => 0, 'max' => 90, 'step' => 1,
        'help'     => 'Approval target for pension applications.',
        'kind'     => 'pension',
    ],
    'SLA_RESIGNATION_DAYS' => [
        'label'    => 'Resignation',
        'label_am' => 'ከስራ መልቀቅ',
        'icon'     => '📤',
        'color'    => 'var(--mpc-red)',
        'unit'     => 'days',
        'min'      => 0, 'max' => 90, 'step' => 1,
        'help'     => 'Approval target for resignation requests.',
        'kind'     => 'resignation',
    ],
    'SLA_PROCUREMENT_DAYS' => [
        'label'    => 'Procurement (Routine)',
        'label_am' => 'ግዥ',
        'icon'     => '🛒',
        'color'    => '#6366f1',
        'unit'     => 'days',
        'min'      => 0, 'max' => 90, 'step' => 1,
        'help'     => 'Approval target for routine procurement requests.',
        'kind'     => 'procurement',
    ],
    'SLA_PLANNING_DAYS' => [
        'label'    => 'Planning',
        'label_am' => 'ዕቅድ',
        'icon'     => '📋',
        'color'    => '#0891b2',
        'unit'     => 'days',
        'min'      => 0, 'max' => 90, 'step' => 1,
        'help'     => 'Approval target for annual / quarterly plans.',
        'kind'     => 'plan',
    ],
];


/* ------------------------------------------------------------
   4. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    $applyToPending = !empty($_POST['apply_to_pending']);

    // Load current values
    $before = [];
    foreach (array_keys($slaMeta) as $code) {
        $before[$code] = Policy::float($code, 0);
    }

    try {
        $pdo->beginTransaction();

        $changed = [];
        $errors  = [];

        foreach ($slaMeta as $code => $meta) {
            $inputKey = 'value_' . $code;
            if (!isset($_POST[$inputKey])) continue;

            $raw = trim((string)$_POST[$inputKey]);

            if ($raw === '' || !is_numeric($raw)) {
                $errors[] = "{$meta['label']}: must be a number.";
                continue;
            }

            $newVal = (float)$raw;
            if ($newVal < $meta['min'] || $newVal > $meta['max']) {
                $errors[] = "{$meta['label']}: must be between {$meta['min']} and {$meta['max']} {$meta['unit']}.";
                continue;
            }

            $oldVal = $before[$code];

            if (abs($oldVal - $newVal) > 0.0001) {
                $pdo->prepare("UPDATE policy_constants SET value_num = ? WHERE code = ?")
                    ->execute([$newVal, $code]);
                $changed[] = $code;
            }
        }

        if (!empty($errors)) {
            $pdo->rollBack();
            foreach (array_slice($errors, 0, 4) as $err) {
                Flash::danger($err);
            }
            redirect('sla_settings.php');
        }

        if (empty($changed)) {
            $pdo->rollBack();
            Flash::warning('No SLA changes were made.');
            redirect('sla_settings.php');
        }

        $pdo->commit();

        // Refresh policy cache so new values apply immediately
        Policy::clearCache();

        // Audit each changed SLA
        try {
            $after = [];
            foreach ($changed as $code) {
                $after[$code] = Policy::float($code, 0);
            }
            foreach ($changed as $code) {
                $row = $pdo->prepare("SELECT id FROM policy_constants WHERE code = ? LIMIT 1");
                $row->execute([$code]);
                $pcId = (int)$row->fetchColumn();

                Audit::updated(
                    'policy_constants',
                    $pcId,
                    ['value_num' => $before[$code]],
                    ['value_num' => $after[$code]]
                );
            }
        } catch (Throwable $e) {
            error_log('sla_settings audit failed: ' . $e->getMessage());
        }

        // Optional: recalculate pending SLAs
        $recalcMsg = '';
        if ($applyToPending) {
            try {
                $affected = Workflow::recalculateSLA();
                $recalcMsg = " {$affected} pending request(s) had their SLA recalculated.";
            } catch (Throwable $e) {
                error_log('sla_settings recalc failed: ' . $e->getMessage());
                $recalcMsg = ' SLA recalculation failed — new values apply to future requests only.';
            }
        } else {
            $recalcMsg = ' New values apply to requests created from now on.';
        }

        Flash::success(
            'SLA thresholds updated: <strong>' . count($changed) . '</strong> change(s).'
            . $recalcMsg
        );

        redirect('sla_settings.php');

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('sla_settings save failed: ' . $ex->getMessage());
        Flash::danger('Could not save the SLA settings. Please try again.');
        redirect('sla_settings.php');
    }
}


/* ------------------------------------------------------------
   5. COMPUTE LIVE SLA HEALTH PER TYPE
   ------------------------------------------------------------ */

/** For service-request SLAs: query from service_requests using stored sla_due_at. */
function mpc_sla_health_service(PDO $pdo, string $requestType, ?string $leaveFilter = null): array
{
    $health = ['total' => 0, 'at_risk' => 0, 'breached' => 0, 'oldest_breach_hours' => 0];

    try {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN sr.sla_due_at < NOW()
                         AND sr.sla_due_at >= NOW() - INTERVAL 1 DAY THEN 1 ELSE 0 END) AS at_risk_recent,
                SUM(CASE WHEN sr.sla_due_at < NOW() THEN 1 ELSE 0 END) AS breached,
                SUM(CASE WHEN sr.sla_due_at >= NOW()
                         AND sr.sla_due_at < NOW() + INTERVAL 1 DAY THEN 1 ELSE 0 END) AS at_risk_upcoming,
                MAX(CASE WHEN sr.sla_due_at < NOW()
                         THEN TIMESTAMPDIFF(HOUR, sr.sla_due_at, NOW())
                         ELSE 0 END) AS oldest_breach_hours
            FROM service_requests sr
        ";

        $where = " WHERE sr.status IN ('submitted', 'in_progress')";
        $args = [];

        if ($leaveFilter === 'short') {
            $sql .= " JOIN leave_details ld ON ld.request_id = sr.id ";
            $where .= " AND sr.request_type = 'leave' AND ld.days_count <= 5";
        } elseif ($leaveFilter === 'long') {
            $sql .= " JOIN leave_details ld ON ld.request_id = sr.id ";
            $where .= " AND sr.request_type = 'leave' AND ld.days_count > 5";
        } else {
            $where .= " AND sr.request_type = ?";
            $args[] = $requestType;
        }

        $st = $pdo->prepare($sql . $where);
        $st->execute($args);
        $row = $st->fetch() ?: [];

        $health['total']    = (int)($row['total'] ?? 0);
        $health['breached'] = (int)($row['breached'] ?? 0);
        $health['at_risk']  = (int)($row['at_risk_recent'] ?? 0) + (int)($row['at_risk_upcoming'] ?? 0);
        $health['oldest_breach_hours'] = (int)($row['oldest_breach_hours'] ?? 0);

    } catch (Throwable $e) {
        error_log('mpc_sla_health_service failed: ' . $e->getMessage());
    }

    return $health;
}

/** For clearance SLA: compute from approval.created_at + SLA hours (no stored due date). */
function mpc_sla_health_clearance(PDO $pdo, int $slaDays): array
{
    $health = ['total' => 0, 'at_risk' => 0, 'breached' => 0, 'oldest_breach_hours' => 0];

    if ($slaDays < 0) $slaDays = 0;
    $slaHours = $slaDays * 24;

    try {
        $st = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DATE_ADD(ca.created_at, INTERVAL ? HOUR) < NOW()
                         THEN 1 ELSE 0 END) AS breached,
                SUM(CASE WHEN DATE_ADD(ca.created_at, INTERVAL ? HOUR) >= NOW()
                         AND DATE_ADD(ca.created_at, INTERVAL ? HOUR) < NOW() + INTERVAL 1 DAY
                         THEN 1 ELSE 0 END) AS at_risk,
                MAX(CASE WHEN DATE_ADD(ca.created_at, INTERVAL ? HOUR) < NOW()
                         THEN TIMESTAMPDIFF(HOUR, DATE_ADD(ca.created_at, INTERVAL ? HOUR), NOW())
                         ELSE 0 END) AS oldest_breach_hours
            FROM clearance_approvals ca
            JOIN clearance_requests cr ON cr.id = ca.clearance_id
            WHERE ca.status = 'pending'
              AND cr.status IN ('pending', 'in_progress')
        ");
        $st->execute([$slaHours, $slaHours, $slaHours, $slaHours, $slaHours]);
        $row = $st->fetch() ?: [];

        $health['total']    = (int)($row['total'] ?? 0);
        $health['breached'] = (int)($row['breached'] ?? 0);
        $health['at_risk']  = (int)($row['at_risk'] ?? 0);
        $health['oldest_breach_hours'] = (int)($row['oldest_breach_hours'] ?? 0);

    } catch (Throwable $e) {
        error_log('mpc_sla_health_clearance failed: ' . $e->getMessage());
    }

    return $health;
}

// Compute health for all 8 SLA types
$health = [];
foreach ($slaMeta as $code => $meta) {
    $slaValue = (int)Policy::float($code, 3);

    if ($meta['kind'] === 'clearance') {
        $health[$code] = mpc_sla_health_clearance($pdo, $slaValue);
    } elseif ($meta['kind'] === 'leave_short') {
        $health[$code] = mpc_sla_health_service($pdo, 'leave', 'short');
    } elseif ($meta['kind'] === 'leave_long') {
        $health[$code] = mpc_sla_health_service($pdo, 'leave', 'long');
    } else {
        $health[$code] = mpc_sla_health_service($pdo, $meta['kind']);
    }
}

// Totals
$totals = ['pending' => 0, 'at_risk' => 0, 'breached' => 0, 'oldest_breach_hours' => 0];
foreach ($health as $h) {
    $totals['pending']  += $h['total'];
    $totals['at_risk']  += $h['at_risk'];
    $totals['breached'] += $h['breached'];
    if ($h['oldest_breach_hours'] > $totals['oldest_breach_hours']) {
        $totals['oldest_breach_hours'] = $h['oldest_breach_hours'];
    }
}


/* ------------------------------------------------------------
   6. RECENT AT-RISK + BREACHED REQUESTS (TOP 30)
   ------------------------------------------------------------ */
$urgentItems = [];

try {
    // Service requests: at-risk or breached
    $st = $pdo->query("
        SELECT
            'request' AS kind,
            sr.id AS record_id,
            sr.ref_no,
            sr.request_type,
            sr.title,
            sr.sla_due_at,
            u.full_name AS requester_name,
            TIMESTAMPDIFF(HOUR, sr.sla_due_at, NOW()) AS overdue_hours,
            CASE WHEN sr.sla_due_at < NOW() THEN 'BREACHED' ELSE 'AT_RISK' END AS sla_state
        FROM service_requests sr
        JOIN users u ON u.id = sr.requester_id
        WHERE sr.status IN ('submitted', 'in_progress')
          AND sr.sla_due_at IS NOT NULL
          AND sr.sla_due_at < NOW() + INTERVAL 1 DAY
        ORDER BY sr.sla_due_at ASC
        LIMIT 20
    ");
    $serviceUrgent = $st->fetchAll();

    // Clearances: at-risk or breached (compute from created_at + SLA)
    $clearanceSlaHours = (int)(Policy::float('SLA_CLEARANCE_DEPT_DAYS', 3) * 24);
    $st = $pdo->prepare("
        SELECT
            'clearance' AS kind,
            cr.id AS record_id,
            cr.ref_no,
            'clearance' AS request_type,
            CONCAT('Clearance — ', ca.department) AS title,
            DATE_ADD(ca.created_at, INTERVAL ? HOUR) AS sla_due_at,
            u.full_name AS requester_name,
            TIMESTAMPDIFF(HOUR, DATE_ADD(ca.created_at, INTERVAL ? HOUR), NOW()) AS overdue_hours,
            CASE WHEN DATE_ADD(ca.created_at, INTERVAL ? HOUR) < NOW()
                 THEN 'BREACHED' ELSE 'AT_RISK' END AS sla_state
        FROM clearance_approvals ca
        JOIN clearance_requests cr ON cr.id = ca.clearance_id
        JOIN users u ON u.id = cr.applicant_id
        WHERE ca.status = 'pending'
          AND cr.status IN ('pending', 'in_progress')
          AND DATE_ADD(ca.created_at, INTERVAL ? HOUR) < NOW() + INTERVAL 1 DAY
        ORDER BY ca.created_at ASC
        LIMIT 10
    ");
    $st->execute([$clearanceSlaHours, $clearanceSlaHours, $clearanceSlaHours, $clearanceSlaHours]);
    $clearanceUrgent = $st->fetchAll();

    $urgentItems = array_merge($serviceUrgent, $clearanceUrgent);

    // Sort merged by sla_due_at ascending (most overdue first)
    usort($urgentItems, function ($a, $b) {
        return strtotime($a['sla_due_at']) <=> strtotime($b['sla_due_at']);
    });

    // Cap at 30
    $urgentItems = array_slice($urgentItems, 0, 30);

} catch (Throwable $e) {
    error_log('sla_settings urgent query failed: ' . $e->getMessage());
}


/* ------------------------------------------------------------
   7. HELPERS
   ------------------------------------------------------------ */
function mpc_humanize_hours(int $hours): string
{
    if ($hours <= 0) return '—';
    if ($hours < 24) return $hours . 'h';
    $days = intdiv($hours, 24);
    $rem  = $hours % 24;
    return $days . 'd' . ($rem > 0 ? ' ' . $rem . 'h' : '');
}


/* ------------------------------------------------------------
   8. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'SLA Settings';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">SLA Settings &amp; Monitoring</h1>
            <p class="muted" style="margin-top: 5px;">
                Live approval-time health + SLA thresholds for every request type.
            </p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="policy_constants.php" class="btn btn-ghost" style="border-color: var(--mpc-navy); color: var(--mpc-navy);">
                📋 All Policy Constants
            </a>
            <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
                &larr; Back to Admin
            </a>
        </div>
    </div>

    <!-- ============================================================
         LIVE HEALTH SUMMARY
         ============================================================ -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 14px 18px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 12px;">
            <div style="font-size: 24px;">⏳</div>
            <div>
                <div style="font-size: 24px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= (int)$totals['pending'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Pending Requests</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 14px 18px; border-left: 4px solid var(--mpc-flame); display: flex; align-items: center; gap: 12px;">
            <div style="font-size: 24px;">⚠️</div>
            <div>
                <div style="font-size: 24px; font-weight: 800; color: var(--mpc-flame); line-height: 1;"><?= (int)$totals['at_risk'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px;">At Risk (&lt; 24h)</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 14px 18px; border-left: 4px solid var(--mpc-red); display: flex; align-items: center; gap: 12px;">
            <div style="font-size: 24px;">🚨</div>
            <div>
                <div style="font-size: 24px; font-weight: 800; color: var(--mpc-red); line-height: 1;"><?= (int)$totals['breached'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Breached</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 14px 18px; border-left: 4px solid #16a34a; display: flex; align-items: center; gap: 12px;">
            <div style="font-size: 24px;">🕐</div>
            <div>
                <div style="font-size: 16px; font-weight: 800; color: #16a34a; line-height: 1.2;">
                    <?= mpc_humanize_hours($totals['oldest_breach_hours']) ?>
                </div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Oldest Breach</div>
            </div>
        </div>
    </div>

    <!-- Compliance banner -->
    <?php if ($totals['breached'] > 0): ?>
        <div class="card" style="border-left-color: var(--mpc-red); background: #FEF2F2; margin-bottom: 20px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="font-size: 24px; line-height: 1;">🚨</div>
                <div>
                    <h3 style="margin: 0 0 6px; color: var(--mpc-red); font-size: 15px;">
                        <?= (int)$totals['breached'] ?> SLA Breach<?= $totals['breached'] === 1 ? '' : 'es' ?> Currently Active
                    </h3>
                    <p style="margin: 0; font-size: 13px; color: #7F1D1D; line-height: 1.6;">
                        <?= (int)$totals['breached'] ?> pending request<?= $totals['breached'] === 1 ? ' is' : 's are' ?> past their approval deadline.
                        <?php if ($totals['oldest_breach_hours'] > 0): ?>
                            The oldest has been waiting <?= mpc_humanize_hours($totals['oldest_breach_hours']) ?> past due.
                        <?php endif; ?>
                        Review the items below to take action.
                    </p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="border-left-color: #16a34a; background: #F0FDF4; margin-bottom: 20px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="font-size: 24px; line-height: 1;">✅</div>
                <div>
                    <h3 style="margin: 0 0 4px; color: #166534; font-size: 15px;">
                        All SLAs Currently Met
                    </h3>
                    <p style="margin: 0; font-size: 13px; color: #166534; line-height: 1.6;">
                        No pending requests have breached their SLA thresholds.
                        <?php if ($totals['at_risk'] > 0): ?>
                            <strong><?= (int)$totals['at_risk'] ?></strong> will breach within 24 hours.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ============================================================
         EDIT FORM
         ============================================================ -->
    <form method="post">
        <?= Csrf::field() ?>

        <!-- ============================================================
             SLA THRESHOLD EDITOR (with per-row health)
             ============================================================ -->
        <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 20px; border-left: 5px solid var(--mpc-red);">
            <div style="padding: 16px 20px; background: #FAFBFC; border-bottom: 1px solid var(--mpc-border);">
                <h2 style="margin: 0; font-size: 17px; color: var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 22px;">⏰</span>
                    SLA Thresholds
                    <span style="font-size: 13px; color: #6B7280; font-weight: 500; margin-left: 8px;">
                        · {{ የSLA ገደቦች }}
                    </span>
                </h2>
                <p style="margin: 6px 0 0; font-size: 12px; color: #6B7280;">
                    Edit any threshold. Live health counts update on save. Old values apply to existing requests unless you check "apply to pending" below.
                </p>
            </div>

            <div style="padding: 0;">
                <?php foreach ($slaMeta as $code => $meta):
                    $slaValue = (int)Policy::float($code, 3);
                    $h        = $health[$code];
                    $rowColor = $meta['color'];

                    // Row health badge
                    $badge = null;
                    if ($h['breached'] > 0) {
                        $badge = ['class' => 'badge-danger', 'text' => '🚨 ' . $h['breached'] . ' breached'];
                    } elseif ($h['at_risk'] > 0) {
                        $badge = ['class' => 'badge-warning', 'text' => '⚠️ ' . $h['at_risk'] . ' at risk'];
                    } elseif ($h['total'] > 0) {
                        $badge = ['class' => 'badge-success', 'text' => '✓ on track'];
                    }
                ?>
                <div style="display: grid; grid-template-columns: 1fr 130px 130px 180px; gap: 16px; padding: 16px 20px; border-bottom: 1px solid var(--mpc-border); align-items: center;"
                     onmouseover="this.style.background='#FAFBFC'" onmouseout="this.style.background=''">

                    <!-- Icon + label -->
                    <div style="min-width: 0; display: flex; align-items: flex-start; gap: 12px;">
                        <div style="font-size: 26px; line-height: 1; flex-shrink: 0; margin-top: 2px;"><?= $meta['icon'] ?></div>
                        <div style="min-width: 0;">
                            <div style="display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; margin-bottom: 3px;">
                                <strong style="color: var(--mpc-navy); font-size: 14px;"><?= e($meta['label']) ?></strong>
                                <span class="muted" style="font-size: 11px;">· <?= e($meta['label_am']) ?></span>
                            </div>
                            <div style="font-size: 11px; color: #6B7280; line-height: 1.5;">
                                <?= e($meta['help']) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Current live count -->
                    <div style="text-align: center;">
                        <div style="font-size: 11px; color: #9CA3AF; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Pending</div>
                        <div style="font-size: 18px; font-weight: 800; color: var(--mpc-navy); line-height: 1;">
                            <?= (int)$h['total'] ?>
                        </div>
                        <?php if ($h['total'] > 0): ?>
                            <div style="font-size: 10px; color: #6B7280; margin-top: 3px;">
                                oldest breach <?= mpc_humanize_hours($h['oldest_breach_hours']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Health badge -->
                    <div style="text-align: center;">
                        <div style="font-size: 11px; color: #9CA3AF; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Status</div>
                        <?php if ($badge): ?>
                            <span class="badge <?= $badge['class'] ?>" style="font-size: 10px;">
                                <?= $badge['text'] ?>
                            </span>
                        <?php else: ?>
                            <span class="muted" style="font-size: 11px;">—</span>
                        <?php endif; ?>
                    </div>

                    <!-- Editable input -->
                    <div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number"
                                   name="value_<?= e($code) ?>"
                                   id="value_<?= e($code) ?>"
                                   value="<?= e($slaValue) ?>"
                                   min="<?= e($meta['min']) ?>"
                                   max="<?= e($meta['max']) ?>"
                                   step="<?= e($meta['step']) ?>"
                                   required
                                   style="flex: 1; text-align: right; font-weight: 700; color: var(--mpc-navy); font-size: 16px; padding: 8px 10px;">
                            <span style="font-size: 12px; color: #6B7280; white-space: nowrap;">
                                <?= e($meta['unit']) ?>
                            </span>
                        </div>
                        <div style="font-size: 10px; color: #9CA3AF; text-align: right; margin-top: 2px;">
                            Range: <?= e($meta['min']) ?>–<?= e($meta['max']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ============================================================
             APPLY TO PENDING OPTION
             ============================================================ -->
        <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF; margin-bottom: 20px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <input type="checkbox"
                       name="apply_to_pending"
                       value="1"
                       id="apply_to_pending"
                       style="width: 20px; height: 20px; margin-top: 2px; accent-color: var(--mpc-blue); cursor: pointer; flex-shrink: 0;">
                <label for="apply_to_pending" style="cursor: pointer; margin: 0;">
                    <div style="font-weight: 700; color: var(--mpc-navy); font-size: 14px; margin-bottom: 4px;">
                        Apply new SLAs to <?= (int)$totals['pending'] ?> pending request<?= $totals['pending'] === 1 ? '' : 's' ?>
                    </div>
                    <div style="font-size: 12px; color: #374151; line-height: 1.6;">
                        If checked, the new thresholds will recalculate the SLA due date on
                        <strong>all <?= (int)$totals['pending'] ?> currently pending request<?= $totals['pending'] === 1 ? '' : 's' ?></strong>.
                        Leave unchecked to apply new SLAs only to requests created from now on.
                    </div>
                </label>
            </div>
        </div>

        <!-- Save button -->
        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 40px; flex-wrap: wrap;">
            <a href="users.php" class="btn btn-ghost" style="border-color: #ccc;">Cancel</a>
            <button type="submit" class="btn btn-primary btn-lg" style="min-width: 240px;">
                💾 Save SLA Settings
            </button>
        </div>
    </form>

    <!-- ============================================================
         URGENT ITEMS TABLE
         ============================================================ -->
    <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 40px;">
        <div style="padding: 16px 20px; background: #FAFBFC; border-bottom: 1px solid var(--mpc-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <h2 style="margin: 0; font-size: 17px; color: var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 22px;">🚨</span>
                At-Risk &amp; Breached Requests
                <?php if (!empty($urgentItems)): ?>
                    <span class="badge badge-danger" style="font-size: 11px;"><?= count($urgentItems) ?></span>
                <?php endif; ?>
            </h2>
            <span style="font-size: 11px; color: #6B7280;">
                Sorted by most urgent first (top 30)
            </span>
        </div>

        <?php if (empty($urgentItems)): ?>
            <div style="text-align: center; padding: 60px 20px;">
                <div style="font-size: 48px; margin-bottom: 15px;">✅</div>
                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">Nothing to worry about.</h3>
                <p class="muted" style="font-size: 14px; margin: 0;">
                    No requests are currently at risk or past SLA.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Ref #</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Requester</th>
                            <th>SLA Due</th>
                            <th>Variance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($urgentItems as $item):
                            $isBreached = $item['sla_state'] === 'BREACHED';
                            $overdueHours = (int)$item['overdue_hours'];

                            // Compute variance to display
                            if ($isBreached) {
                                $varianceText = mpc_humanize_hours($overdueHours) . ' overdue';
                                $varianceColor = 'var(--mpc-red)';
                            } else {
                                $hoursLeft = max(0, -$overdueHours); // negative overdue_hours = hours remaining
                                $varianceText = mpc_humanize_hours($hoursLeft) . ' left';
                                $varianceColor = 'var(--mpc-flame)';
                            }

                            // Link
                            $link = $item['kind'] === 'clearance'
                                ? Notifier::linkTo('clearance', (int)$item['record_id'])
                                : Notifier::linkTo('request', (int)$item['record_id']);

                            // Type badge
                            $typeLabel = $item['request_type'] === 'clearance'
                                ? 'Clearance'
                                : ucfirst(str_replace('_', ' ', (string)$item['request_type']));
                        ?>
                        <tr>
                            <td>
                                <?php if ($isBreached): ?>
                                    <span class="badge badge-danger">🚨 Breached</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">⚠️ At Risk</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="font-size: 12px; font-weight: 700; color: var(--mpc-navy);">
                                    <?= e($item['ref_no']) ?>
                                </code>
                            </td>
                            <td>
                                <span class="badge badge-info" style="font-size: 10px; text-transform: capitalize;">
                                    <?= e($typeLabel) ?>
                                </span>
                            </td>
                            <td>
                                <div style="max-width: 280px; font-size: 13px; color: #374151; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($item['title']) ?>">
                                    <?= e($item['title']) ?>
                                </div>
                            </td>
                            <td style="font-size: 13px;"><?= e($item['requester_name']) ?></td>
                            <td>
                                <div style="font-size: 12px;"><?= format_date(date('Y-m-d H:i:s', strtotime($item['sla_due_at'])), true) ?></div>
                            </td>
                            <td>
                                <strong style="color: <?= $varianceColor ?>; font-size: 13px;">
                                    <?= e($varianceText) ?>
                                </strong>
                            </td>
                            <td>
                                <a href="<?= e($link) ?>" class="btn btn-sm btn-primary">
                                    Review →
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         INFO BANNER
         ============================================================ -->
    <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <div style="font-size: 22px; line-height: 1;">ℹ️</div>
            <div style="font-size: 13px; color: #374151; line-height: 1.7;">
                <strong style="color: var(--mpc-navy);">How SLA tracking works:</strong>
                When a request is created, the system records its due date by adding the current SLA threshold to the
                submission time. Changing the SLA here does <strong>not</strong> modify already-stored due dates unless you
                check "apply to pending."
                <br><br>
                <strong>Clearance SLA</strong> is computed live from each approval step's creation time using the current
                <code>SLA_CLEARANCE_DEPT_DAYS</code> value — clearances don't have a stored SLA column, so they always
                reflect the current threshold.
                <br><br>
                <em style="color: #6B7280;">
                    For all other policy constants (leave days, retirement age, procurement threshold, etc.),
                    see <a href="policy_constants.php" style="color: var(--mpc-blue);">Policy Constants</a>.
                </em>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>