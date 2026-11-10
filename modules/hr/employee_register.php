<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

// Security Check: Restricted to HR and Admin
if (!has_role(['admin', 'hr'])) {
    flash('danger', 'You do not have permission to access the Employee Register.');
    redirect(APP_URL . '/index.php');
}

// ==========================================
// 1. BUILD SMART QUERY
// ==========================================
// Fetch only active, non-student users
$sql = "
    SELECT u.*, d.name AS dept_name 
    FROM users u 
    LEFT JOIN departments d ON d.id = u.department_id 
    WHERE u.employment_type != 'student' 
      AND u.status = 'active' 
      AND u.deleted_at IS NULL
";
$args = [];

// Search by Name, Code, or Department
if (!empty($_GET['search'])) {
    $search = '%' . trim($_GET['search']) . '%';
    $sql .= " AND (u.full_name LIKE ? OR u.employee_code LIKE ? OR d.name LIKE ?)";
    $args[] = $search;
    $args[] = $search;
    $args[] = $search;
}

// Filter by Employment Type
if (!empty($_GET['type'])) {
    $sql .= " AND u.employment_type = ?";
    $args[] = $_GET['type'];
}

$sql .= " ORDER BY d.name ASC, u.full_name ASC";

$st = $pdo->prepare($sql);
$st->execute($args);
$employees = $st->fetchAll();

$pageTitle = 'Employee Register';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 1200px; margin: 30px auto;">
    
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Employee Register (የሰራተኞች መዝገብ)</h1>
            <p class="muted" style="margin-top: 5px;">Official list of all active staff members at Misrak Polytechnic College.</p>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <a href="<?= APP_URL ?>/modules/hr/balances.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
                ⚖️ View Leave Balances
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                🖨️ Print Register
            </button>
        </div>
    </div>

    <!-- Filters & Search -->
    <div class="card" style="padding: 20px; margin-bottom: 20px; border-left-color: var(--mpc-blue);">
        <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            
            <div class="form-group" style="flex: 2; min-width: 250px; margin-bottom: 0;">
                <label>Search (ፈልግ)</label>
                <input type="text" name="search" placeholder="Search by name, ID, or department..." value="<?= e($_GET['search'] ?? '') ?>">
            </div>

            <div class="form-group" style="flex: 1; min-width: 180px; margin-bottom: 0;">
                <label>Employment Type (የስራ ዓይነት)</label>
                <select name="type">
                    <option value="">All Staff</option>
                    <option value="civil_servant" <?= ($_GET['type'] ?? '') === 'civil_servant' ? 'selected' : '' ?>>Civil Servant</option>
                    <option value="contract" <?= ($_GET['type'] ?? '') === 'contract' ? 'selected' : '' ?>>Contract</option>
                    <option value="daily_labor" <?= ($_GET['type'] ?? '') === 'daily_labor' ? 'selected' : '' ?>>Daily Labor</option>
                </select>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-navy">Filter</button>
                <a href="employee_register.php" class="btn btn-ghost" style="border-color: #ccc;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Print Header (Only visible when printing) -->
    <div class="print-only" style="display: none; text-align: center; margin-bottom: 20px;">
        <h2 style="color: #000; margin: 0;"><?= e(get_setting('org_name_en', 'Misrak Polytechnic College')) ?></h2>
        <h3 style="margin: 5px 0;">Employee Register (የሰራተኞች መዝገብ)</h3>
        <p>Generated on: <?= format_date(date('Y-m-d')) ?> (<?= date('M j, Y') ?>)</p>
        <hr style="border: 1px solid #000; margin-bottom: 20px;">
    </div>

    <!-- Employees Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Employee Name (ስም)</th>
                        <th>Employee ID (መለያ)</th>
                        <th>Position (የስራ ድርሻ)</th>
                        <th>Department (ክፍል)</th>
                        <th>Employment Type</th>
                        <th>Hire Date (የተቀጠሩበት)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td>
                            <strong><?= e($emp['full_name']) ?></strong><br>
                            <small class="muted"><?= e($emp['gender'] === 'female' ? 'Female' : 'Male') ?></small>
                        </td>
                        <td><code><?= e($emp['employee_code'] ?: 'N/A') ?></code></td>
                        <td><?= e($emp['position'] ?: 'N/A') ?></td>
                        <td><?= e($emp['dept_name'] ?: 'N/A') ?></td>
                        <td>
                            <?php if ($emp['employment_type'] === 'civil_servant'): ?>
                                <span class="badge badge-primary">Civil Servant</span>
                            <?php elseif ($emp['employment_type'] === 'contract'): ?>
                                <span class="badge badge-warning">Contract</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Daily Labor</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($emp['hire_date']): ?>
                                <div style="font-weight: 500;"><?= format_date($emp['hire_date']) ?></div>
                                <small class="muted"><?= date('M j, Y', strtotime($emp['hire_date'])) ?></small>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Empty State -->
                    <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 50px;">
                            <div style="font-size: 40px; margin-bottom: 15px;">📋</div>
                            <h3 class="muted">No employees found.</h3>
                            <p class="muted" style="font-size: 14px;">Try adjusting your search or filter criteria.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Print Styles -->
<style>
    @media print {
        body { background: #fff; font-size: 11px; }
        .topbar, .footer, .btn, form, .card { box-shadow: none !important; border: none !important; }
        .print-only { display: block !important; }
        .card { padding: 0 !important; margin: 0 !important; }
        .table th, .table td { border: 1px solid #000 !important; padding: 6px !important; }
        .badge { border: 1px solid #000; background: transparent !important; color: #000 !important; }
        .muted { color: #333 !important; }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>