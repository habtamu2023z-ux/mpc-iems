<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

// Security: Only Admin and HR can access the calendar
if (!has_role(['admin', 'hr'])) {
    flash('danger', 'You do not have permission to access the Leave Calendar.');
    redirect(APP_URL . '/index.php');
}

// ==========================================
// 1. DETERMINE THE VIEWING DATE
// ==========================================
// Default to today if no date is selected
$view_date_greg = $_GET['date'] ?? date('Y-m-d'); 
$view_date_eth = format_date($view_date_greg);

// ==========================================
// 2. FETCH LEAVE DATA FOR THE SELECTED DATE
// ==========================================
$sql = "
    SELECT sr.ref_no, ld.leave_type, ld.start_date, ld.end_date, ld.days_count, 
           u.full_name AS emp_name, u.position, d.name AS dept_name 
    FROM service_requests sr
    JOIN leave_details ld ON ld.request_id = sr.id
    JOIN users u ON u.id = sr.requester_id
    LEFT JOIN departments d ON d.id = sr.department_id
    WHERE sr.request_type = 'leave' 
      AND sr.status = 'approved' 
      AND ld.return_confirmed = 0
      AND ? BETWEEN ld.start_date AND ld.end_date
    ORDER BY d.name ASC, u.full_name ASC
";

$st = $pdo->prepare($sql);
$st->execute([$view_date_greg]);
$leaves = $st->fetchAll();

// Calculate total staff on leave
$totalOnLeave = count($leaves);

$pageTitle = 'Leave Calendar';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 1100px; margin: 30px auto;">
    
    <!-- Top Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Leave Calendar (የፈቃድ መርሃ ግብር)</h1>
            <p class="muted" style="margin-top: 5px;">View staff availability and active leaves across the college.</p>
        </div>
        <a href="leave_list.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to Leave List
        </a>
    </div>

    <!-- Smart Date Selector Widget -->
    <div class="card" style="border-left: 4px solid var(--mpc-flame); background: #fff;">
        <form method="get" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
            <div style="display: flex; flex-direction: column;">
                <label style="font-size: 13px; margin-bottom: 5px;">Select Date (ቀን ይምረጡ)</label>
                <input type="date" name="date" value="<?= e($view_date_greg) ?>" onchange="this.form.submit()" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit;">
            </div>
            
            <div style="background: var(--mpc-navy); color: #fff; padding: 10px 20px; border-radius: 8px; font-weight: bold;">
                📅 <?= e($view_date_eth) ?>
            </div>

            <div style="margin-left: auto; display: flex; gap: 15px; align-items: center;">
                <div style="text-align: right;">
                    <div class="muted small">Total Staff on Leave</div>
                    <div style="font-size: 24px; font-weight: bold; color: var(--mpc-blue);"><?= $totalOnLeave ?> Employees</div>
                </div>
                <button type="button" onclick="window.print()" class="btn btn-ghost" style="border-color: var(--mpc-navy); color: var(--mpc-navy);">
                    🖨️ Print
                </button>
            </div>
        </form>
    </div>

    <!-- Leave List Table -->
    <div class="card" style="padding: 0; overflow: hidden; margin-top: 25px;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Employee (ሰራተኛ)</th>
                        <th>Department (ክፍል)</th>
                        <th>Leave Type (የፈቃድ አይነት)</th>
                        <th>Start Date (መጀመሪያ)</th>
                        <th>End Date (መጨረሻ)</th>
                        <th>Total Days (ቀናት)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaves as $l): ?>
                    <tr>
                        <td>
                            <strong><?= e($l['emp_name']) ?></strong><br>
                            <small class="muted"><?= e($l['position']) ?></small>
                        </td>
                        <td><?= e($l['dept_name'] ?: 'N/A') ?></td>
                        <td>
                            <span class="badge badge-info"><?= e(ucfirst($l['leave_type'])) ?></span>
                        </td>
                        <td>
                            <div style="font-weight: 500;"><?= format_date($l['start_date']) ?></div>
                            <small class="muted"><?= date('M j, Y', strtotime($l['start_date'])) ?></small>
                        </td>
                        <td>
                            <div style="font-weight: 500;"><?= format_date($l['end_date']) ?></div>
                            <small class="muted"><?= date('M j, Y', strtotime($l['end_date'])) ?></small>
                        </td>
                        <td>
                            <strong style="color: var(--mpc-blue);"><?= $l['days_count'] ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Empty State -->
                    <?php if (empty($leaves)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 50px;">
                            <div style="font-size: 50px; margin-bottom: 15px;">🎉</div>
                            <h3 style="color: var(--mpc-navy); margin-bottom: 5px;">Full Staff Attendance!</h3>
                            <p class="muted" style="font-size: 14px;">No employees are on approved leave for this date.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Print Only Header -->
    <div class="print-only" style="display: none; text-align: center; margin-bottom: 20px;">
        <h2 style="color: #000; margin: 0;"><?= e(get_setting('org_name_en', 'Misrak Polytechnic College')) ?></h2>
        <h3 style="margin: 5px 0;">Leave Calendar Report (የፈቃድ መርሃ ግብር)</h3>
        <p>Date: <?= e($view_date_eth) ?> (<?= date('M j, Y', strtotime($view_date_greg)) ?>)</p>
        <hr style="border: 1px solid #000; margin-bottom: 20px;">
    </div>

</div>

<!-- Print Styles -->
<style>
    @media print {
        body { background: #fff; font-size: 12px; }
        .topbar, .footer, .btn, form, .card { box-shadow: none !important; border: none !important; }
        .print-only { display: block !important; }
        .muted { color: #555 !important; }
        .table th, .table td { border: 1px solid #000 !important; padding: 8px !important; }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>