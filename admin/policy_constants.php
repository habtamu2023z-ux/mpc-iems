<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   POLICY CONSTANTS (Admin only)
   ============================================================
   PURPOSE
     Edit the 23 policy values that drive every HR decision:
     leave entitlements, retirement age, SLA thresholds,
     procurement limits, working hours.

   WHY THIS MATTERS
     Ethiopian labour law changes. When Civil Servants
     Proclamation 1353/2024 changed maternity from 120 → 90
     days for some categories, this page is the ONLY way the
     college can respond without a DBA.

   FEATURES
     - 6 category sections with Amharic + English labels
     - Each row shows code, description, legal basis,
       effective-from date, current value, unit, min/max bounds
     - Live impact preview on SLA rows (pending request count)
     - Optional "apply new SLA to pending requests" checkbox
     - Audit::updated() per changed constant (only changed ones)
     - Policy::clearCache() after commit
     - Transaction safety — full rollback on validation failure
     - No-op detection (no change → no write, no audit)
     - Works even if a constant is missing from DB (auto-insert)

   DEPENDENCIES
     functions.php            — base helpers
     includes/auth_check.php  — Auth::requireRole
     includes/csrf.php        — Csrf::check, Csrf::field
     includes/flash.php       — Flash::success, warning, danger, info
     includes/audit.php       — Audit::updated
     includes/policy.php      — Policy::all, Policy::clearCache
     includes/workflow_engine.php — Workflow::recalculateSLA

   SAFETY
     - Admin-only access enforced before any DB read
     - CSRF required on POST
     - Numeric validation with per-constant min/max bounds
     - Transaction wraps all updates
     - error_log on failure, generic message to user
     - All updates audited per-field
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


/* ------------------------------------------------------------
   2. AUTH GATE
   ------------------------------------------------------------ */
$user = Auth::require();
Auth::requireRole('admin', 'You do not have permission to edit policy constants.');


/* ------------------------------------------------------------
   3. CONSTANT METADATA
   Defines labels, units, bounds, and grouping for each of the
   23 policy constants. Code → metadata.
   ------------------------------------------------------------ */
$policyMeta = [

    /* ===== LEAVE ===== */
    'MATERNITY_DAYS' => [
        'label' => 'Maternity Leave', 'label_am' => 'የወሊድ ፈቃድ',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Total paid maternity days. Currently 120 under Labour Proclamation 1156/2019.',
    ],
    'PATERNITY_DAYS' => [
        'label' => 'Paternity Leave (Private)', 'label_am' => 'የአባት ፈቃድ (የግል)',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Paid paternity days for private-sector employees.',
    ],
    'PATERNITY_DAYS_CIVIL' => [
        'label' => 'Paternity Leave (Civil Servant)', 'label_am' => 'የአባት ፈቃድ (መንግስት)',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Paid paternity days for civil servants. Higher than private-sector rate.',
    ],
    'ANNUAL_LEAVE_BASE_DAYS' => [
        'label' => 'Annual Leave Base', 'label_am' => 'ዓመታዊ ፈቃድ መሰረት',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Annual leave entitlement for a first-year employee.',
    ],
    'ANNUAL_LEAVE_MAX_DAYS' => [
        'label' => 'Annual Leave Maximum', 'label_am' => 'ዓመታዊ ፈቃድ ከፍተኛ',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Maximum annual leave after tenure increases.',
    ],
    'SICK_LEAVE_FULL_DAYS' => [
        'label' => 'Sick Leave (Full Pay)', 'label_am' => 'የህክምና ፈቃድ (ሙሉ ክፍያ)',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Sick leave days at 100% salary.',
    ],
    'SICK_LEAVE_HALF_DAYS' => [
        'label' => 'Sick Leave (Half Pay)', 'label_am' => 'የህክምና ፈቃድ (ግማሽ ክፍያ)',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Sick leave days at 50% salary (after full-pay exhausted).',
    ],
    'SICK_LEAVE_UNPAID_DAYS' => [
        'label' => 'Sick Leave (Unpaid)', 'label_am' => 'የህክምና ፈቃድ (ያለክፍያ)',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Sick leave days without pay (after half-pay exhausted).',
    ],
    'SPECIAL_LEAVE_DAYS' => [
        'label' => 'Special Leave', 'label_am' => 'ልዩ ፈቃድ',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'leave',
        'help' => 'Special leave for mourning or marriage events.',
    ],

    /* ===== RETIREMENT ===== */
    'RETIREMENT_AGE' => [
        'label' => 'Standard Retirement Age', 'label_am' => 'የጡረታ ዕድሜ',
        'unit' => 'years', 'min' => 18, 'max' => 100, 'step' => 1, 'group' => 'retirement',
        'help' => 'Mandatory retirement age for all employees.',
    ],
    'EARLY_RETIREMENT_AGE' => [
        'label' => 'Early Retirement Age', 'label_am' => 'ቅድመ ጡረታ ዕድሜ',
        'unit' => 'years', 'min' => 18, 'max' => 100, 'step' => 1, 'group' => 'retirement',
        'help' => 'Minimum age to apply for early retirement.',
    ],
    'EARLY_RETIREMENT_MIN_YEARS' => [
        'label' => 'Early Retirement Min. Service', 'label_am' => 'ቅድመ ጡረታ አገልግሎት',
        'unit' => 'years', 'min' => 0, 'max' => 100, 'step' => 1, 'group' => 'retirement',
        'help' => 'Minimum years of service to qualify for early retirement.',
    ],

    /* ===== RESIGNATION ===== */
    'NOTICE_PERIOD_DAYS' => [
        'label' => 'Resignation Notice Period', 'label_am' => 'የማሳወቂያ ጊዜ',
        'unit' => 'days', 'min' => 0, 'max' => 365, 'step' => 1, 'group' => 'resignation',
        'help' => 'Required advance notice before final working day.',
    ],

    /* ===== PROCUREMENT ===== */
    'PROCUREMENT_DIRECTOR_THRESHOLD' => [
        'label' => 'Director Approval Threshold', 'label_am' => 'የዳይሬክተር ማጽደቂያ ገደብ',
        'unit' => 'ETB', 'min' => 0, 'max' => 10000000, 'step' => 1000, 'group' => 'procurement',
        'help' => 'Requests at or above this amount require director approval.',
    ],

    /* ===== WORKING HOURS ===== */
    'WORKING_HOURS_PER_DAY' => [
        'label' => 'Working Hours per Day', 'label_am' => 'የስራ ሰዓት (በቀን)',
        'unit' => 'hours', 'min' => 0.5, 'max' => 24, 'step' => 0.5, 'group' => 'working_hours',
        'help' => 'Standard working hours per day. Used for overtime calculations.',
    ],

    /* ===== SLA ===== */
    'SLA_CLEARANCE_DEPT_DAYS' => [
        'label' => 'SLA: Clearance Department Step', 'label_am' => 'የክሊራንስ SLA',
        'unit' => 'days', 'min' => 0, 'max' => 90, 'step' => 1, 'group' => 'sla',
        'help' => 'Each clearance approval step must be acted on within N days.',
    ],
    'SLA_LEAVE_SHORT_DAYS' => [
        'label' => 'SLA: Short Leave (≤5 days)', 'label_am' => 'SLA: አጭር ፈቃድ',
        'unit' => 'days', 'min' => 0, 'max' => 90, 'step' => 1, 'group' => 'sla',
        'help' => 'Approval target for leave requests of 5 days or fewer.',
    ],
    'SLA_LEAVE_LONG_DAYS' => [
        'label' => 'SLA: Long Leave (>5 days)', 'label_am' => 'SLA: ረጅም ፈቃድ',
        'unit' => 'days', 'min' => 0, 'max' => 90, 'step' => 1, 'group' => 'sla',
        'help' => 'Approval target for leave requests longer than 5 days.',
    ],
    'SLA_MATERNAL_DAYS' => [
        'label' => 'SLA: Maternal Request', 'label_am' => 'SLA: የወሊድ ጥያቄ',
        'unit' => 'days', 'min' => 0, 'max' => 90, 'step' => 1, 'group' => 'sla',
        'help' => 'Approval target for maternity/paternity requests.',
    ],
    'SLA_PENSION_DAYS' => [
        'label' => 'SLA: Pension Out', 'label_am' => 'SLA: ጡረታ',
        'unit' => 'days', 'min' => 0, 'max' => 90, 'step' => 1, 'group' => 'sla',
        'help' => 'Approval target for pension applications.',
    ],
    'SLA_RESIGNATION_DAYS' => [
        'label' => 'SLA: Resignation', 'label_am' => 'SLA: ስራ መልቀቅ',
        'unit' => 'days', 'min' => 0, 'max' => 90, 'step' => 1, 'group' => 'sla',
        'help' => 'Approval target for resignation requests.',
    ],
    'SLA_PROCUREMENT_DAYS' => [
        'label' => 'SLA: Procurement (Routine)', 'label_am' => 'SLA: ግዥ',
        'unit' => 'days', 'min' => 0, 'max' => 90, 'step' => 1, 'group' => 'sla',
        'help' => 'Approval target for routine procurement requests.',
    ],
    'SLA_PLANNING_DAYS' => [
        'label' => 'SLA: Planning', 'label_am' => 'SLA: ዕቅድ',
        'unit' => 'days', 'min' => 0, 'max' => 90, 'step' => 1, 'group' => 'sla',
        'help' => 'Approval target for annual/quarterly plans.',
    ],
];

$groups = [
    'leave'         => ['title' => 'Leave Policies',   'title_am' => 'የፈቃድ ፖሊሲዎች', 'icon' => '🏖️', 'color' => 'var(--mpc-blue)'],
    'retirement'    => ['title' => 'Retirement',       'title_am' => 'ጡረታ',          'icon' => '👴', 'color' => 'var(--mpc-navy)'],
    'resignation'   => ['title' => 'Resignation',      'title_am' => 'ከስራ መልቀቅ',  'icon' => '📤', 'color' => 'var(--mpc-flame)'],
    'procurement'   => ['title' => 'Procurement',      'title_am' => 'ግዥ',           'icon' => '🛒', 'color' => '#6366f1'],
    'working_hours' => ['title' => 'Working Hours',    'title_am' => 'የስራ ሰዓት',     'icon' => '⏱️', 'color' => '#0891b2'],
    'sla'           => ['title' => 'SLA Thresholds',   'title_am' => 'የSLA ገደቦች',   'icon' => '⏰', 'color' => 'var(--mpc-red)'],
    'other'         => ['title' => 'Other Constants',  'title_am' => 'ሌሎች ቋሚዎች',    'icon' => '📋', 'color' => '#6B7280'],
];


/* ------------------------------------------------------------
   4. IMPACT COUNTS (computed once per page load, cached)
   For each SLA constant, count how many pending requests would
   be affected if the SLA changed.
   ------------------------------------------------------------ */
$impactCounts = [
    'SLA_CLEARANCE_DEPT_DAYS' => 0, 'SLA_LEAVE_SHORT_DAYS' => 0,
    'SLA_LEAVE_LONG_DAYS' => 0, 'SLA_MATERNAL_DAYS' => 0,
    'SLA_PENSION_DAYS' => 0, 'SLA_RESIGNATION_DAYS' => 0,
    'SLA_PROCUREMENT_DAYS' => 0, 'SLA_PLANNING_DAYS' => 0,
];

try {
    $impactCounts['SLA_CLEARANCE_DEPT_DAYS'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM clearance_approvals WHERE status = 'pending'"
    )->fetchColumn();
} catch (Throwable $e) { /* silent */ }

try {
    $impactCounts['SLA_LEAVE_SHORT_DAYS'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM service_requests sr
         JOIN leave_details ld ON ld.request_id = sr.id
         WHERE sr.status IN ('submitted','in_progress') AND ld.days_count <= 5"
    )->fetchColumn();
} catch (Throwable $e) { /* silent */ }

try {
    $impactCounts['SLA_LEAVE_LONG_DAYS'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM service_requests sr
         JOIN leave_details ld ON ld.request_id = sr.id
         WHERE sr.status IN ('submitted','in_progress') AND ld.days_count > 5"
    )->fetchColumn();
} catch (Throwable $e) { /* silent */ }

foreach ([
    'SLA_MATERNAL_DAYS'    => 'maternal',
    'SLA_PENSION_DAYS'     => 'pension',
    'SLA_RESIGNATION_DAYS' => 'resignation',
    'SLA_PROCUREMENT_DAYS' => 'procurement',
    'SLA_PLANNING_DAYS'    => 'plan',
] as $const => $type) {
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM service_requests
             WHERE request_type = ? AND status IN ('submitted','in_progress')"
        );
        $st->execute([$type]);
        $impactCounts[$const] = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* silent */ }
}


/* ------------------------------------------------------------
   5. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    $applySLA = !empty($_POST['apply_sla_to_pending']);

    // Capture current DB state (for diff)
    $beforeMap = [];
    try {
        foreach ($pdo->query("SELECT id, code, value_num, value_text FROM policy_constants")->fetchAll() as $row) {
            $beforeMap[$row['code']] = $row;
        }
    } catch (Throwable $e) {
        Flash::danger('Could not read current policy values. Please try again.');
        redirect('policy_constants.php');
    }

    try {
        $pdo->beginTransaction();

        $changed = [];
        $errors  = [];

        foreach ($policyMeta as $code => $meta) {
            $inputKey = 'value_' . $code;
            if (!isset($_POST[$inputKey])) continue;

            $raw = trim((string)$_POST[$inputKey]);

            // Validate
            if ($raw === '' || !is_numeric($raw)) {
                $errors[] = "{$meta['label']} ({$code}): must be a number.";
                continue;
            }
            $newValue = (float)$raw;
            if ($newValue < $meta['min'] || $newValue > $meta['max']) {
                $errors[] = "{$meta['label']} ({$code}): must be between {$meta['min']} and {$meta['max']} {$meta['unit']}.";
                continue;
            }

            $oldValue = isset($beforeMap[$code])
                ? (float)($beforeMap[$code]['value_num'] ?? 0)
                : null;

            // Compare with tolerance for float (avoids spurious 8.0 vs 8)
            if ($oldValue === null || abs($oldValue - $newValue) > 0.0001) {
                if (isset($beforeMap[$code])) {
                    $pdo->prepare(
                        "UPDATE policy_constants SET value_num = ?, value_text = NULL WHERE code = ?"
                    )->execute([$newValue, $code]);
                } else {
                    // Constant is missing from DB — insert it
                    $pdo->prepare(
                        "INSERT INTO policy_constants
                         (code, value_num, description, legal_basis, effective_from)
                         VALUES (?, ?, ?, 'MPC Internal Policy', CURDATE())"
                    )->execute([$code, $newValue, $meta['label']]);
                }
                $changed[] = $code;
            }
        }

        // Any validation errors → abort entire transaction
        if (!empty($errors)) {
            $pdo->rollBack();
            foreach (array_slice($errors, 0, 4) as $err) {
                Flash::danger($err);
            }
            if (count($errors) > 4) {
                Flash::warning('…and ' . (count($errors) - 4) . ' more error(s).');
            }
            redirect('policy_constants.php');
        }

        // No changes → rollback, no audit
        if (empty($changed)) {
            $pdo->rollBack();
            Flash::warning('No changes were made.');
            redirect('policy_constants.php');
        }

        $pdo->commit();

        // Refresh Policy cache so new values apply immediately
        Policy::clearCache();

        // Audit each change (per-field)
        try {
            $afterMap = [];
            foreach ($pdo->query("SELECT id, code, value_num FROM policy_constants")->fetchAll() as $row) {
                $afterMap[$row['code']] = $row;
            }
            foreach ($changed as $code) {
                if (isset($afterMap[$code]['id'])) {
                    $oldVal = isset($beforeMap[$code]) ? $beforeMap[$code]['value_num'] : null;
                    $newVal = $afterMap[$code]['value_num'];
                    Audit::updated(
                        'policy_constants',
                        (int)$afterMap[$code]['id'],
                        ['value_num' => $oldVal],
                        ['value_num' => $newVal]
                    );
                }
            }
        } catch (Throwable $e) {
            // Non-blocking — the change succeeded, audit is best-effort
            error_log('policy audit failed: ' . $e->getMessage());
        }

        // Check if any SLA constants changed
        $slaChanged = false;
        foreach ($changed as $c) {
            if (str_starts_with($c, 'SLA_')) {
                $slaChanged = true;
                break;
            }
        }

        // Optional SLA recalculation
        if ($slaChanged && $applySLA) {
            try {
                $affected = Workflow::recalculateSLA();
                Flash::success(
                    'Policy constants updated: <strong>' . count($changed) . '</strong> change(s). '
                    . $affected . ' pending request(s) had their SLA recalculated.'
                );
            } catch (Throwable $e) {
                error_log('SLA recalc failed: ' . $e->getMessage());
                Flash::success(
                    'Policy constants updated: <strong>' . count($changed) . '</strong> change(s). '
                    . 'SLA recalculation failed — new values apply to future requests only.'
                );
            }
        } else {
            Flash::success(
                'Policy constants updated: <strong>' . count($changed) . '</strong> change(s) applied.'
            );
            if ($slaChanged && !$applySLA) {
                Flash::info(
                    'SLA thresholds changed but pending requests were NOT recalculated. '
                    . 'New values apply to requests created from now on.'
                );
            }
        }

        redirect('policy_constants.php');

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('policy_constants.php failed: ' . $ex->getMessage());
        Flash::danger(
            $ex instanceof RuntimeException
                ? $ex->getMessage()
                : 'Could not save the policy constants. Please try again or contact IT.'
        );
        redirect('policy_constants.php');
    }
}


/* ------------------------------------------------------------
   6. LOAD CURRENT VALUES
   ------------------------------------------------------------ */
$allCurrent = Policy::all();

// Build group → [code, ...] mapping
$grouped = [];
foreach ($policyMeta as $code => $meta) {
    $grouped[$meta['group']][] = $code;
}

// Handle extras (constants in DB but not in $policyMeta)
$extras = array_diff(array_keys($allCurrent), array_keys($policyMeta));
if (!empty($extras)) {
    $grouped['other'] = array_values($extras);
}


/* ------------------------------------------------------------
   7. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Policy Constants';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">
                Policy Constants (ፖሊሲ ቋሚዎች)
            </h1>
            <p class="muted" style="margin-top: 5px;">
                Edit the HR, retirement, and SLA thresholds that drive the entire system.
            </p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
                &larr; Back to Admin
            </a>
            <a href="system_settings.php" class="btn btn-ghost" style="border-color: var(--mpc-blue); color: var(--mpc-blue);">
                ⚙️ System Settings
            </a>
        </div>
    </div>

    <!-- ============================================================
         COMPLIANCE BANNER
         ============================================================ -->
    <div class="card" style="border-left-color: var(--mpc-flame); background: #FFFBEB; margin-bottom: 20px;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <div style="font-size: 24px; line-height: 1;">⚠️</div>
            <div>
                <h3 style="margin: 0 0 6px; color: var(--mpc-flame-dark); font-size: 15px;">
                    Compliance-Critical Settings
                </h3>
                <p style="margin: 0; font-size: 13px; color: #78350F; line-height: 1.6;">
                    These values affect every HR decision in the system — leave balances, retirement eligibility,
                    SLA targets, and procurement approvals. Changes take effect immediately and are logged to the audit trail.
                    Only change them when mandated by Ethiopian labour law or college policy.
                </p>
            </div>
        </div>
    </div>

    <!-- ============================================================
         EDIT FORM
         ============================================================ -->
    <form method="post">
        <?= Csrf::field() ?>

        <?php foreach ($groups as $groupKey => $groupInfo): ?>
            <?php if (empty($grouped[$groupKey])) continue; ?>

            <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 20px; border-left: 5px solid <?= e($groupInfo['color']) ?>;">

                <!-- Group header -->
                <div style="padding: 16px 20px; background: #FAFBFC; border-bottom: 1px solid var(--mpc-border);">
                    <h2 style="margin: 0; font-size: 17px; color: var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 22px;"><?= $groupInfo['icon'] ?></span>
                        <div>
                            <?= e($groupInfo['title']) ?>
                            <span style="font-size: 13px; color: #6B7280; font-weight: 500; margin-left: 8px;">
                                · <?= e($groupInfo['title_am']) ?>
                            </span>
                        </div>
                    </h2>
                </div>

                <!-- Rows -->
                <div style="padding: 0;">
                    <?php foreach ($grouped[$groupKey] as $code):
                        $meta   = $policyMeta[$code] ?? null;
                        $current = $allCurrent[$code] ?? null;

                        if (!$meta) {
                            // Extra constant not in our definitions
                            $meta = [
                                'label' => $code, 'label_am' => '',
                                'unit' => '', 'min' => -999999, 'max' => 999999, 'step' => 1,
                                'group' => 'other', 'help' => '',
                            ];
                        }

                        $currentValue = $current ? $current['value'] : '';
                        $legalBasis   = $current['legal_basis']    ?? '';
                        $description  = $current['description']    ?? $meta['help'];
                        $effectiveFrom = $current['effective_from'] ?? '';

                        // Impact preview for SLA constants
                        $impact = $impactCounts[$code] ?? null;
                        $showImpact = $impact !== null && $impact > 0;
                    ?>
                        <div style="display: grid; grid-template-columns: 1fr 200px; gap: 20px; padding: 16px 20px; border-bottom: 1px solid var(--mpc-border); align-items: center;"
                             onmouseover="this.style.background='#FAFBFC'" onmouseout="this.style.background=''">

                            <!-- Left: metadata -->
                            <div style="min-width: 0;">
                                <div style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                                    <strong style="color: var(--mpc-navy); font-size: 14px;">
                                        <?= e($meta['label']) ?>
                                    </strong>
                                    <?php if ($meta['label_am']): ?>
                                        <span class="muted" style="font-size: 12px;">
                                            · <?= e($meta['label_am']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <code style="background: #F3F4F6; color: #6B7280; font-size: 10px; padding: 2px 6px; border-radius: 3px;">
                                        <?= e($code) ?>
                                    </code>
                                </div>

                                <?php if ($description): ?>
                                    <div style="font-size: 12px; color: #4B5563; line-height: 1.5; margin-bottom: 4px;">
                                        <?= e($description) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($legalBasis): ?>
                                    <div style="font-size: 11px; color: #6B7280;">
                                        <strong>Legal basis:</strong> <?= e($legalBasis) ?>
                                        <?php if ($effectiveFrom): ?>
                                            · effective <?= date('M j, Y', strtotime($effectiveFrom)) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($showImpact): ?>
                                    <div style="margin-top: 8px; padding: 6px 10px; background: #FEF3C7; border-left: 3px solid var(--mpc-flame); border-radius: 4px; font-size: 11px; color: #92400E;">
                                        ⚠️ <strong><?= $impact ?></strong> pending request<?= $impact === 1 ? '' : 's' ?> currently affected by this SLA
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Right: value input -->
                            <div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <input type="number"
                                           name="value_<?= e($code) ?>"
                                           id="value_<?= e($code) ?>"
                                           value="<?= e($currentValue) ?>"
                                           min="<?= e($meta['min']) ?>"
                                           max="<?= e($meta['max']) ?>"
                                           step="<?= e($meta['step']) ?>"
                                           required
                                           style="flex: 1; text-align: right; font-weight: 700; color: var(--mpc-navy); font-size: 15px;">
                                    <span style="font-size: 11px; color: #6B7280; white-space: nowrap; min-width: 40px;">
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
        <?php endforeach; ?>

        <!-- ============================================================
             SLA RECALCULATION OPTION
             ============================================================ -->
        <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF; margin-bottom: 20px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <input type="checkbox"
                       name="apply_sla_to_pending"
                       value="1"
                       id="apply_sla_to_pending"
                       style="width: 20px; height: 20px; margin-top: 2px; accent-color: var(--mpc-blue); cursor: pointer; flex-shrink: 0;">
                <label for="apply_sla_to_pending" style="cursor: pointer; margin: 0;">
                    <div style="font-weight: 700; color: var(--mpc-navy); font-size: 14px; margin-bottom: 4px;">
                        Apply new SLA thresholds to already-pending requests
                    </div>
                    <div style="font-size: 12px; color: #374151; line-height: 1.6;">
                        If any SLA constant changes, this will recalculate the deadline on all pending
                        requests using the new values. Leave unchecked to apply new SLAs only to
                        requests created from now on.
                    </div>
                </label>
            </div>
        </div>

        <!-- ============================================================
             SAVE BUTTON
             ============================================================ -->
        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 40px; flex-wrap: wrap;">
            <a href="users.php" class="btn btn-ghost" style="border-color: #ccc;">
                Cancel
            </a>
            <button type="submit" class="btn btn-primary btn-lg" style="min-width: 260px;">
                💾 Save Policy Constants
            </button>
        </div>
    </form>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>