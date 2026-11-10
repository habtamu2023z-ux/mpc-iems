<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

// 1. Build the Secure Query using the Database View
$sql = "SELECT * FROM v_clearance_status WHERE 1=1";
$args = [];

// 2. Role-Based Filtering
// Students ONLY see their own clearances.
// Admin, Registrar, HR, Finance, etc., see ALL clearances.
if (has_role('student')) {
    $sql .= " AND applicant_name = ?";
    $args[] = $user['full_name'];
}

$sql .= " ORDER BY id DESC";

$st = $pdo->prepare($sql);
$st->execute($args);
$clearances = $st->fetchAll();

$pageTitle = 'Clearances';
include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin: 30px 0 20px; flex-wrap: wrap; gap: 15px;">
    <div>
        <h1 style="margin: 0; color: var(--mpc-navy);">Clearances (ክሊራንስ)</h1>
        <p class="muted" style="margin-top: 5px;">Manage student graduation and employee release processes.</p>
    </div>
    
    <!-- Only Students and Admin/Registrar can create new clearances -->
    <?php if (has_role('student') || has_role(['admin', 'registrar'])): ?>
        <a href="clearance_new.php" class="btn btn-primary">
            + New Clearance (አዲስ ፍቃድ)
        </a>
    <?php endif; ?>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Ref No (ቁጥር)</th>
                    <th>Applicant (አመልካች)</th>
                    <th>Reason (ምክንያት)</th>
                    <th>Date (ቀን)</th>
                    <th>Progress (ሂደት)</th>
                    <th>Status (ሁኔታ)</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clearances as $c): ?>
                <tr>
                    <!-- Reference Number -->
                    <td>
                        <code style="font-weight: bold; color: var(--mpc-navy); font-size: 13px;">
                            <?= e($c['ref_no']) ?>
                        </code>
                    </td>
                    
                    <!-- Applicant Info -->
                    <td>
                        <strong><?= e($c['applicant_name']) ?></strong><br>
                        <small class="muted"><?= e(ucfirst($c['applicant_type'])) ?></small>
                    </td>
                    
                    <!-- Reason -->
                    <td>
                        <?= e(ucwords(str_replace('_', ' ', $c['reason']))) ?>
                    </td>
                    
                    <!-- Date (Using Ethiopian Calendar) -->
                    <td>
                        <div style="font-weight: 500;"><?= format_date($c['request_date']) ?></div>
                        <small class="muted"><?= date('M j, Y', strtotime($c['request_date'])) ?></small>
                    </td>
                    
                    <!-- Progress -->
                    <td>
                        <div style="font-size: 13px; font-weight: 600; color: var(--mpc-navy);">
                            <?= (int)$c['approved_steps'] ?> / <?= (int)$c['total_steps'] ?> Steps
                        </div>
                        <!-- Mini Progress Bar -->
                        <div style="width: 100%; background: #eee; height: 6px; border-radius: 3px; margin-top: 5px;">
                            <?php 
                                $percent = ($c['total_steps'] > 0) ? ($c['approved_steps'] / $c['total_steps']) * 100 : 0;
                                $barColor = ($c['status'] === 'rejected') ? 'var(--mpc-red)' : 'var(--mpc-blue)';
                            ?>
                            <div style="width: <?= $percent ?>%; background: <?= $barColor ?>; height: 100%; border-radius: 3px; transition: width 0.3s;"></div>
                        </div>
                    </td>
                    
                    <!-- Status Badge -->
                    <td>
                        <?= status_badge($c['status']) ?>
                    </td>
                    
                    <!-- Action -->
                    <td>
                        <a href="clearance_view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary">
                            Open (ክፈት)
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Empty State -->
                <?php if (empty($clearances)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px;">
                        <div style="font-size: 40px; margin-bottom: 15px;">📂</div>
                        <h3 class="muted">No clearances found.</h3>
                        <p class="muted" style="font-size: 14px;">There are no clearance records to display at this time.</p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>