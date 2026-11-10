<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

// Security Check: Restricted to HR and Admin
if (!has_role(['admin', 'hr'])) {
    flash('danger', 'You do not have permission to access Employee Profiles.');
    redirect(APP_URL . '/index.php');
}

$id = (int)($_GET['id'] ?? 0);

// ==========================================
// 1. FETCH EMPLOYEE DETAILS
// ==========================================
$st = $pdo->prepare("
    SELECT u.*, d.name AS dept_name, d.code AS dept_code 
    FROM users u 
    LEFT JOIN departments d ON d.id = u.department_id 
    WHERE u.id = ?
");
$st->execute([$id]);
$emp = $st->fetch();

if (!$emp) {
    flash('danger', 'Employee not found.');
    redirect('employee_register.php');
}

// ==========================================
// 2. FETCH LEAVE BALANCES
// ==========================================
$stBal = $pdo->prepare("SELECT * FROM v_leave_balances_summary WHERE user_id = ? ORDER BY fiscal_year DESC LIMIT 1");
$stBal->execute([$id]);
$balance = $stBal->fetch();

// ==========================================
// 3. FETCH RECENT LEAVE HISTORY (Last 5)
// ==========================================
$stLeaves = $pdo->prepare("
    SELECT sr.ref_no, sr.status, ld.leave_type, ld.start_date, ld.end_date, ld.days_count 
    FROM service_requests sr 
    JOIN leave_details ld ON ld.request_id = sr.id 
    WHERE sr.requester_id = ? AND sr.request_type = 'leave' 
    ORDER BY sr.created_at DESC LIMIT 5
");
$stLeaves->execute([$id]);
$leaves = $stLeaves->fetchAll();

// ==========================================
// 4. FETCH RECENT CLEARANCE HISTORY (Last 5)
// ==========================================
$stClr = $pdo->prepare("
    SELECT ref_no, reason, status, request_date 
    FROM clearance_requests 
    WHERE applicant_id = ? 
    ORDER BY created_at DESC LIMIT 5
");
$stClr->execute([$id]);
$clearances = $stClr->fetchAll();

$pageTitle = 'Employee Profile';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 1100px; margin: 30px auto;">
    
    <!-- Top Navigation -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <a href="employee_register.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to Register
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            🖨️ Print Profile
        </button>
    </div>

    <div class="grid-2" style="grid-template-columns: 1fr 2fr; gap: 25px;">
        
        <!-- LEFT COLUMN: Personal Info & Balances -->
        <div>
            <!-- Profile Card -->
            <div class="card" style="text-align: center; border-top: 5px solid var(--mpc-navy);">
                <div style="width: 80px; height: 80px; background: var(--mpc-flame); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: bold; margin: 0 auto 15px;">
                    <?= strtoupper(substr($emp['full_name'], 0, 1)) ?>
                </div>
                <h2 style="margin: 0; color: var(--mpc-navy);"><?= e($emp['full_name']) ?></h2>
                <p class="muted" style="margin-top: 5px;"><?= e($emp['position']) ?></p>
                
                <div style="margin-top: 15px;">
                    <?= status_badge($emp['status']) ?>
                </div>

                <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

                <ul class="kv" style="text-align: left; font-size: 13px;">
                    <li><span>Employee ID</span><b><?= e($emp['employee_code'] ?: 'N/A') ?></b></li>
                    <li><span>Department</span><b><?= e($emp['dept_name'] ?: 'N/A') ?></b></li>
                    <li><span>Employment</span><b style="text-transform: capitalize;"><?= e(str_replace('_', ' ', $emp['employment_type'])) ?></b></li>
                    <li><span>Email</span><b><?= e($emp['email'] ?: '-') ?></b></li>
                    <li><span>Phone</span><b><?= e($emp['phone'] ?: '-') ?></b></li>
                    <li><span>Hire Date</span><b><?= format_date($emp['hire_date']) ?></b></li>
                </ul>
            </div>

            <!-- Leave Balances Widget -->
            <?php if ($balance): ?>
            <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF;">
                <h3 style="margin-top: 0; font-size: 16px; color: var(--mpc-navy); display: flex; align-items: center; gap: 8px;">
                    ⚖️ Leave Balances (<?= e($balance['fiscal_year']) ?>)
                </h3>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span class="muted">Annual Leave</span>
                    <span style="font-size: 20px; font-weight: bold; color: <?= $balance['annual_remaining'] > 0 ? 'var(--mpc-success)' : 'var(--mpc-red)' ?>;">
                        <?= (int)$balance['annual_remaining'] ?> days left
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 13px;">
                    <span class="muted">Sick Leave (Full)</span>
                    <strong><?= $balance['sick_full_days'] ?> days</strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px;">
                    <span class="muted">Sick Leave (Half)</span>
                    <strong><?= $balance['sick_half_days'] ?> days</strong>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN: History Tables -->
        <div>
            <!-- Recent Leaves -->
            <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 25px;">
                <div style="background: var(--mpc-navy); color: #fff; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; color: #fff; font-size: 16px;">Recent Leaves (የፈቃድ ታሪክ)</h3>
                    <a href="<?= APP_URL ?>/modules/leave/leave_list.php" class="small" style="color: var(--mpc-blue);">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Dates</th>
                                <th>Days</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($leaves as $l): ?>
                            <tr>
                                <td style="text-transform: capitalize;"><?= e($l['leave_type']) ?></td>
                                <td>
                                    <div style="font-size: 12px; font-weight: 500;"><?= format_date($l['start_date']) ?></div>
                                    <div style="font-size: 11px;" class="muted">to <?= format_date($l['end_date']) ?></div>
                                </td>
                                <td><strong><?= $l['days_count'] ?></strong></td>
                                <td><?= status_badge($l['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($leaves)): ?>
                                <tr><td colspan="4" class="text-center muted" style="padding: 20px;">No leave history found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Clearances -->
            <div class="card" style="padding: 0; overflow: hidden;">
                <div style="background: var(--mpc-navy); color: #fff; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; color: #fff; font-size: 16px;">Recent Clearances (ክሊራንስ ታሪክ)</h3>
                    <a href="<?= APP_URL ?>/modules/clearance/clearance.php" class="small" style="color: var(--mpc-blue);">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ref No</th>
                                <th>Reason</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($clearances as $c): ?>
                            <tr>
                                <td><code><?= e($c['ref_no']) ?></code></td>
                                <td style="text-transform: capitalize;"><?= e(str_replace('_', ' ', $c['reason'])) ?></td>
                                <td><?= format_date($c['request_date']) ?></td>
                                <td><?= status_badge($c['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($clearances)): ?>
                                <tr><td colspan="4" class="text-center muted" style="padding: 20px;">No clearance history found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Print Styles for clean A4 output -->
<style>
    @media print {
        body { background: #fff; font-size: 11px; }
        .topbar, .footer, .btn { display: none !important; }
        .grid-2 { display: block !important; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 20px !important; break-inside: avoid; }
        .table th, .table td { border: 1px solid #000 !important; padding: 6px !important; }
        .badge { border: 1px solid #000; background: transparent !important; color: #000 !important; }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>