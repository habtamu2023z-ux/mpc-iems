<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

// Security Check: Only HR and Admin can view all balances
if (!has_role(['admin', 'hr'])) {
    flash('danger', 'You do not have permission to access this module.');
    redirect(APP_URL . '/index.php');
}

// ==========================================
// 1. BUILD QUERY WITH SEARCH FILTER
// ==========================================
$sql = "SELECT * FROM v_leave_balances_summary WHERE 1=1";
$args = [];

// Search by Name, Employee Code, or Department
if (!empty($_GET['search'])) {
    $search = '%' . trim($_GET['search']) . '%';
    $sql .= " AND (full_name LIKE ? OR employee_code LIKE ? OR department LIKE ?)";
    $args[] = $search;
    $args[] = $search;
    $args[] = $search;
}

$sql .= " ORDER BY department ASC, full_name ASC";

$st = $pdo->prepare($sql);
$st->execute($args);
$balances = $st->fetchAll();

$pageTitle = 'Leave Balances';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 1200px; margin: 30px auto;">
    
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Employee Leave Balances (የፈቃድ ቀሪ ሂሳብ)</h1>
            <p class="muted" style="margin-top: 5px;">Real-time overview of staff annual and sick leave entitlements.</p>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <a href="<?= APP_URL ?>/modules/leave/leave_list.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
                &larr; Back to Leaves
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                🖨️ Print Report
            </button>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="card" style="padding: 15px 20px; border-left-color: var(--mpc-flame); margin-bottom: 20px;">
        <form method="get" style="display: flex; gap: 10px; align-items: center;">
            <input type="text" name="search" placeholder="Search by name, ID, or department..." value="<?= e($_GET['search'] ?? '') ?>" style="flex: 1; padding: 10px;">
            <button type="submit" class="btn btn-navy">Search</button>
            <?php if (!empty($_GET['search'])): ?>
                <a href="balances.php" class="btn btn-ghost" style="border-color: #ccc;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Balances Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Employee (ሰራተኛ)</th>
                        <th>Department (ክፍል)</th>
                        <th>Fiscal Year</th>
                        <th>Annual Leave (ዓመታዊ)</th>
                        <th>Sick Leave (የህክምና)</th>
                        <th>Special (ልዩ)</th>
                        <th>Other Usage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($balances as $b): ?>
                    <tr>
                        <!-- Employee Info -->
                        <td>
                            <strong><?= e($b['full_name']) ?></strong><br>
                            <small class="muted">ID: <?= e($b['employee_code'] ?: 'N/A') ?></small>
                        </td>
                        
                        <!-- Department -->
                        <td><?= e($b['department'] ?: 'N/A') ?></td>
                        
                        <!-- Fiscal Year -->
                        <td><span class="badge badge-secondary"><?= e($b['fiscal_year']) ?></span></td>
                        
                        <!-- Annual Leave -->
                        <td>
                            <div style="font-size: 16px; font-weight: bold; color: <?= $b['annual_remaining'] > 0 ? 'var(--mpc-success)' : 'var(--mpc-red)' ?>;">
                                <?= (int)$b['annual_remaining'] ?> <span style="font-size: 12px; font-weight: normal; color: #666;">days left</span>
                            </div>
                            <small class="muted">Used: <?= (int)$b['annual_used'] ?> / <?= (int)$b['annual_entitled'] ?></small>
                        </td>
                        
                        <!-- Sick Leave -->
                        <td>
                            <div style="font-size: 13px;">
                                <span style="color: var(--mpc-blue); font-weight: bold;"><?= $b['sick_full_days'] ?></span> Full
                            </div>
                            <div style="font-size: 13px;">
                                <span style="color: var(--mpc-flame); font-weight: bold;"><?= $b['sick_half_days'] ?></span> Half
                            </div>
                        </td>
                        
                        <!-- Special Leave -->
                        <td>
                            <div style="font-size: 13px;">
                                <span style="font-weight: bold;"><?= $b['special_used'] ?></span> days used
                            </div>
                        </td>
                        
                        <!-- Other Usage (Maternity/Paternity) -->
                        <td style="font-size: 13px;">
                            <?php if ($b['maternity_used'] > 0): ?>
                                <div style="color: #db2777;">Maternity: <?= $b['maternity_used'] ?> days</div>
                            <?php endif; ?>
                            <?php if ($b['paternity_used'] > 0): ?>
                                <div style="color: #2563eb;">Paternity: <?= $b['paternity_used'] ?> days</div>
                            <?php endif; ?>
                            <?php if ($b['maternity_used'] == 0 && $b['paternity_used'] == 0): ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Empty State -->
                    <?php if (empty($balances)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 50px;">
                            <div style="font-size: 40px; margin-bottom: 15px;">📊</div>
                            <h3 class="muted">No balance records found.</h3>
                            <p class="muted" style="font-size: 14px;">Try adjusting your search criteria.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Print Styles for clean A4 output -->
<style>
    @media print {
        body { background: #fff; font-size: 11px; }
        .topbar, .footer, .btn, form { display: none !important; }
        .card { border: none !important; box-shadow: none !important; padding: 0 !important; }
        .table th, .table td { border: 1px solid #000 !important; padding: 6px !important; }
        .badge { border: 1px solid #000; }
    }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>