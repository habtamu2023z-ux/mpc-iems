<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

$id = (int)($_GET['id'] ?? 0);

// ==========================================
// FETCH CLEARANCE DETAILS
// ==========================================
$st = $pdo->prepare("
    SELECT cr.*, 
           u.full_name AS applicant_name, 
           d.name AS department_name,
           (SELECT COUNT(*) FROM clearance_approvals WHERE clearance_id = cr.id AND status = 'approved') as approved_steps,
           (SELECT COUNT(*) FROM clearance_approvals WHERE clearance_id = cr.id AND status = 'rejected') as rejected_steps,
           (SELECT COUNT(*) FROM clearance_approvals WHERE clearance_id = cr.id) as total_steps
    FROM clearance_requests cr
    JOIN users u ON u.id = cr.applicant_id
    LEFT JOIN departments d ON d.id = cr.department_id
    WHERE cr.id = ?
");
$st->execute([$id]);
$clearance = $st->fetch();

if (!$clearance) {
    flash('danger', 'Clearance record not found.');
    redirect('clearance.php');
}

// ==========================================
// HANDLE APPROVAL / REJECTION ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    
    $action = $_POST['action']; // 'approve' or 'reject'
    $step_order = (int)$_POST['step_order'];
    $comment = trim($_POST['comment'] ?? '');

    if (in_array($action, ['approve', 'reject'])) {
        // Check if the user is actually allowed to approve this step
        $can_approve = false;
        if ($user['username'] === 'admin') $can_approve = true;
        
        $stStep = $pdo->prepare("SELECT department FROM clearance_approvals WHERE clearance_id = ? AND step_order = ?");
        $stStep->execute([$id, $step_order]);
        $step_dept = $stStep->fetchColumn();

        if ($step_dept) {
            if (str_contains(strtolower($step_dept), 'department') && $user['position'] === 'Department Head') $can_approve = true;
            if (str_contains(strtolower($step_dept), 'finance') && str_contains(strtolower($user['dept_name']), 'finance')) $can_approve = true;
            if (str_contains(strtolower($step_dept), 'library') && str_contains(strtolower($user['dept_name']), 'library')) $can_approve = true;
            if (str_contains(strtolower($step_dept), 'dormitory') && str_contains(strtolower($user['dept_name']), 'dormitory')) $can_approve = true;
            if (str_contains(strtolower($step_dept), 'store') && str_contains(strtolower($user['dept_name']), 'store')) $can_approve = true;
            if (str_contains(strtolower($step_dept), 'hr') && $user['username'] === 'hr') $can_approve = true;
            if (str_contains(strtolower($step_dept), 'registrar') && $user['username'] === 'registrar') $can_approve = true;
        }

        if ($can_approve) {
            try {
                // Call Stored Procedure
                $stmt = $pdo->prepare("CALL sp_approve_clearance_step(?, ?, ?, ?, ?)");
                $stmt->execute([$id, $step_order, $user['id'], $action, $comment]);
                
                log_audit('UPDATE', 'clearance_approvals', $id, [], ['action' => $action, 'step' => $step_order]);
                flash('success', "Clearance step #$step_order has been $action" . "d successfully.");
            } catch (PDOException $e) {
                flash('danger', 'Database Error: ' . $e->getMessage());
            }
        } else {
            flash('danger', 'You do not have permission to approve this step.');
        }
        redirect("clearance_view.php?id=$id");
    }
}

// Fetch the Approval Chain
$stSteps = $pdo->prepare("
    SELECT ca.*, u.full_name AS approver_name, u.position 
    FROM clearance_approvals ca 
    LEFT JOIN users u ON u.id = ca.approver_id 
    WHERE ca.clearance_id = ? 
    ORDER BY ca.step_order ASC
");
$stSteps->execute([$id]);
$approvals = $stSteps->fetchAll();

$pageTitle = 'Clearance Details';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 900px; margin: 30px auto;">
    
    <!-- Top Bar / Actions -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Clearance: <code><?= e($clearance['ref_no']) ?></code></h1>
            <p class="muted" style="margin-top: 5px;">Submitted on <?= format_date($clearance['request_date']) ?></p>
        </div>
        <div style="display: flex; gap: 10px;">
            <?php if ($clearance['status'] === 'approved' && has_role(['admin', 'registrar'])): ?>
                <a href="clearance_generate.php?id=<?= $id ?>" class="btn btn-success" style="box-shadow: 0 4px 10px rgba(22,163,74,0.3);">
                    🖨️ Generate Certificate Letter
                </a>
            <?php endif; ?>
            <a href="clearance.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">&larr; Back</a>
        </div>
    </div>

    <div class="grid-2">
        <!-- LEFT COLUMN: Applicant Details -->
        <div>
            <div class="card">
                <h2 style="margin-top: 0; font-size: 18px; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
                    Applicant Details
                </h2>
                
                <div style="margin-bottom: 15px;">
                    <div class="muted small">Full Name</div>
                    <div style="font-size: 18px; font-weight: bold; color: var(--mpc-navy);"><?= e($clearance['applicant_name']) ?></div>
                    <div class="muted"><?= e(ucfirst($clearance['applicant_type'])) ?> · <?= e($clearance['department_name'] ?? 'N/A') ?></div>
                </div>

                <div class="grid-2" style="margin-bottom: 15px;">
                    <div>
                        <div class="muted small">Reason</div>
                        <div style="font-weight: 600;"><?= e(ucwords(str_replace('_', ' ', $clearance['reason']))) ?></div>
                    </div>
                    <div>
                        <div class="muted small">Destination</div>
                        <div style="font-weight: 600;"><?= e($clearance['destination'] ?: 'N/A') ?></div>
                        <div class="small muted"><?= e(ucfirst($clearance['destination_type'] ?? '')) ?></div>
                    </div>
                </div>

                <?php if (!empty($clearance['notes'])): ?>
                <div style="margin-bottom: 15px;">
                    <div class="muted small">Notes</div>
                    <div style="background: #f9f9f9; padding: 10px; border-radius: 6px; font-size: 13px; margin-top: 5px;">
                        <?= nl2br(e($clearance['notes'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <div>
                    <div class="muted small">Current Status</div>
                    <div style="margin-top: 5px;"><?= status_badge($clearance['status']) ?></div>
                </div>
            </div>

            <!-- Progress Widget -->
            <div class="card" style="background: var(--mpc-offwhite);">
                <h3 style="margin-top: 0; font-size: 15px;">Overall Progress</h3>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="font-size: 28px; font-weight: bold; color: var(--mpc-blue);">
                        <?= $clearance['approved_steps'] ?> / <?= $clearance['total_steps'] ?>
                    </div>
                    <div style="flex: 1; background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden;">
                        <?php 
                            $percent = ($clearance['total_steps'] > 0) ? ($clearance['approved_steps'] / $clearance['total_steps']) * 100 : 0; 
                            $barColor = ($clearance['status'] === 'rejected') ? 'var(--mpc-red)' : 'var(--mpc-blue)';
                        ?>
                        <div style="width: <?= $percent ?>%; background: <?= $barColor ?>; height: 100%; transition: width 0.5s;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Approval Chain -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="background: var(--mpc-navy); color: #fff; padding: 15px 20px;">
                <h2 style="margin: 0; font-size: 18px; color: #fff;">Approval Chain</h2>
            </div>
            
            <div style="padding: 20px;">
                <?php foreach ($approvals as $step): ?>
                    <?php 
                        // FIXED LOGIC: Allow 'pending' OR 'in_progress'
                        $is_my_turn = false;
                        if ($step['status'] === 'pending' && in_array($clearance['status'], ['pending', 'in_progress']) && $step['step_order'] == $clearance['current_step']) {
                            if ($user['username'] === 'admin') $is_my_turn = true;
                            if (str_contains(strtolower($step['department']), 'department') && $user['position'] === 'Department Head') $is_my_turn = true;
                            if (str_contains(strtolower($step['department']), 'finance') && str_contains(strtolower($user['dept_name']), 'finance')) $is_my_turn = true;
                            if (str_contains(strtolower($step['department']), 'library') && str_contains(strtolower($user['dept_name']), 'library')) $is_my_turn = true;
                            if (str_contains(strtolower($step['department']), 'dormitory') && str_contains(strtolower($user['dept_name']), 'dormitory')) $is_my_turn = true;
                            if (str_contains(strtolower($step['department']), 'store') && str_contains(strtolower($user['dept_name']), 'store')) $is_my_turn = true;
                            if (str_contains(strtolower($step['department']), 'hr') && $user['username'] === 'hr') $is_my_turn = true;
                            if (str_contains(strtolower($step['department']), 'registrar') && $user['username'] === 'registrar') $is_my_turn = true;
                        }
                    ?>

                    <!-- Step Box -->
                    <div class="step-box step-<?= $step['status'] ?>" style="margin-bottom: 15px; position: relative;">
                        
                        <div class="step-header">
                            <strong style="color: var(--mpc-navy);">
                                Step <?= $step['step_order'] ?>: <?= e($step['department']) ?>
                            </strong>
                            <?= status_badge($step['status']) ?>
                        </div>

                        <!-- Approver Info / Comment -->
                        <?php if ($step['status'] !== 'pending'): ?>
                            <div style="font-size: 13px; color: #555; margin-top: 8px;">
                                <strong><?= e($step['approver_name'] ?: 'System') ?></strong> 
                                <span class="muted">on <?= format_date($step['acted_at'], true) ?></span>
                                <?php if($step['comment']): ?>
                                    <div style="background: rgba(0,0,0,0.05); padding: 8px; border-radius: 4px; margin-top: 5px; font-style: italic;">
                                        "<?= e($step['comment']) ?>"
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Action Form -->
                        <?php if ($is_my_turn): ?>
                            <div style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;">
                                <form method="post" style="display: flex; flex-direction: column; gap: 10px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="step_order" value="<?= $step['step_order'] ?>">
                                    
                                    <input type="text" name="comment" placeholder="Add a comment (optional)..." style="padding: 8px; font-size: 13px;">
                                    
                                    <div style="display: flex; gap: 10px;">
                                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm" style="flex: 1;">
                                            ✓ Approve
                                        </button>
                                        <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm" style="flex: 1;" onclick="return confirm('Are you sure you want to REJECT this clearance?');">
                                            ✗ Reject
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($clearance['status'] === 'completed'): ?>
                    <div style="text-align: center; padding: 20px; background: #dcfce7; border-radius: 8px; margin-top: 20px;">
                        <div style="font-size: 30px; margin-bottom: 10px;">🎉</div>
                        <h3 style="color: #166534; margin: 0;">Clearance Completed!</h3>
                        <p style="color: #166534; font-size: 13px; margin-top: 5px;">The certificate letter has been generated.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>