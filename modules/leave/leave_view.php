<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

if (has_role('student')) {
    flash('warning', 'Students do not have access to the HR Leave module.');
    redirect(APP_URL . '/index.php');
}

$id = (int)($_GET['id'] ?? 0);

// ==========================================
// 1. HANDLE APPROVAL / REJECTION ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    
    $action = $_POST['action']; // 'approve' or 'reject'
    $step_id = (int)$_POST['step_id'];
    $comment = trim($_POST['comment'] ?? '');

    if (in_array($action, ['approve', 'reject'])) {
        // Check if user has permission for this step
        $stStep = $pdo->prepare("SELECT role_required FROM workflow_steps WHERE id = ? AND request_id = ?");
        $stStep->execute([$step_id, $id]);
        $role_req = $stStep->fetchColumn();

        $can_approve = false;
        if ($user['username'] === 'admin') $can_approve = true;
        if ($role_req === 'department_head' && $user['position'] === 'Department Head') $can_approve = true;
        if ($role_req === 'hr' && $user['username'] === 'hr') $can_approve = true;

        if ($can_approve) {
            try {
                // 1. Approve/Reject the step
                $stmt = $pdo->prepare("CALL sp_approve_step(?, ?, ?, ?)");
                $stmt->execute([$step_id, $user['id'], $action, $comment]);
                
                // 2. Check if the entire request is now fully approved
                $stCheck = $pdo->prepare("SELECT status FROM service_requests WHERE id = ?");
                $stCheck->execute([$id]);
                $currentStatus = $stCheck->fetchColumn();

                // 3. If fully approved, deduct from HR balance automatically!
                if ($currentStatus === 'approved') {
                    $pdo->prepare("CALL sp_apply_leave_balance(?)")->execute([$id]);
                }

                log_audit('UPDATE', 'workflow_steps', $step_id, [], ['action' => $action]);
                flash('success', "Leave request step $action" . "d successfully.");
            } catch (PDOException $e) {
                flash('danger', 'Database Error: ' . $e->getMessage());
            }
        } else {
            flash('danger', 'You do not have permission to approve this step.');
        }
        
        // Redirect back to list if requested, otherwise stay on this page
        $return_url = $_POST['return_to'] ?? "leave_view.php?id=$id";
        redirect($return_url);
    }
}

// ==========================================
// 2. HANDLE MARK AS RETURNED (HR ONLY)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_returned']) && has_role(['admin', 'hr'])) {
    csrf_check();
    $pdo->prepare("UPDATE leave_details SET return_confirmed = 1, return_date = CURDATE() WHERE request_id = ?")->execute([$id]);
    log_audit('UPDATE', 'leave_details', $id, ['return_confirmed' => 0], ['return_confirmed' => 1]);
    flash('success', 'Employee marked as returned from leave.');
    redirect("leave_view.php?id=$id");
}

// ==========================================
// 3. FETCH LEAVE DETAILS
// ==========================================
$st = $pdo->prepare("
    SELECT sr.*, ld.*, 
           u.full_name AS emp_name, u.position AS emp_position, u.employee_code, d.name AS dept_name,
           h.full_name AS handover_name 
    FROM service_requests sr 
    JOIN leave_details ld ON ld.request_id = sr.id 
    JOIN users u ON u.id = sr.requester_id 
    LEFT JOIN departments d ON d.id = sr.department_id
    LEFT JOIN users h ON h.id = ld.handover_to
    WHERE sr.id = ?
");
$st->execute([$id]);
$leave = $st->fetch();

if (!$leave) {
    flash('danger', 'Leave request not found.');
    redirect('leave_list.php');
}

// Fetch Employee Leave Balances (For HR Widget)
$stBal = $pdo->prepare("
    SELECT * FROM v_leave_balances_summary 
    WHERE user_id = ? 
    ORDER BY fiscal_year DESC LIMIT 1
");
$stBal->execute([$leave['requester_id']]);
$balance = $stBal->fetch();

// Fetch Workflow Steps
$stSteps = $pdo->prepare("
    SELECT ws.*, u.full_name AS approver_name, u.position AS approver_position 
    FROM workflow_steps ws 
    LEFT JOIN users u ON u.id = ws.approver_id 
    WHERE ws.request_id = ? 
    ORDER BY ws.step_order ASC
");
$stSteps->execute([$id]);
$steps = $stSteps->fetchAll();

$pageTitle = 'Leave Details';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 1000px; margin: 30px auto;">
    
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Leave Request: <code><?= e($leave['ref_no']) ?></code></h1>
            <p class="muted" style="margin-top: 5px;">Submitted on <?= format_date($leave['created_at']) ?></p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <?= status_badge($leave['status']) ?>
            <a href="leave_list.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">&larr; Back to List</a>
        </div>
    </div>

    <div class="grid-2">
        <!-- LEFT COLUMN: Leave Details -->
        <div>
            <div class="card">
                <h2 style="margin-top: 0; font-size: 18px; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
                    Employee Details
                </h2>
                
                <div style="margin-bottom: 15px;">
                    <div class="muted small">Full Name</div>
                    <div style="font-size: 18px; font-weight: bold; color: var(--mpc-navy);"><?= e($leave['emp_name']) ?></div>
                    <div class="muted"><?= e($leave['emp_position']) ?> · ID: <?= e($leave['employee_code'] ?: 'N/A') ?></div>
                    <div class="muted"><?= e($leave['dept_name'] ?? 'No Department') ?></div>
                </div>

                <div class="grid-2" style="margin-bottom: 15px;">
                    <div>
                        <div class="muted small">Leave Type</div>
                        <div style="font-weight: 600; text-transform: capitalize;"><?= e($leave['leave_type']) ?></div>
                    </div>
                    <div>
                        <div class="muted small">Total Days</div>
                        <div style="font-weight: 600; color: var(--mpc-blue); font-size: 18px;"><?= $leave['days_count'] ?> days</div>
                    </div>
                </div>

                <div style="background: #f9f9f9; border: 1px solid #eee; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span class="muted small">Start Date:</span>
                        <strong style="color: var(--mpc-navy);"><?= format_date($leave['start_date']) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span class="muted small">End Date:</span>
                        <strong style="color: var(--mpc-navy);"><?= format_date($leave['end_date']) ?></strong>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <div class="muted small">Reason</div>
                    <div style="background: #f9f9f9; padding: 10px; border-radius: 6px; font-size: 14px; margin-top: 5px;">
                        <?= nl2br(e($leave['reason'])) ?>
                    </div>
                </div>

                <?php if (!empty($leave['substitute_plan'])): ?>
                <div style="margin-bottom: 15px;">
                    <div class="muted small">Substitute Plan</div>
                    <div style="background: #f9f9f9; padding: 10px; border-radius: 6px; font-size: 14px; margin-top: 5px;">
                        <?= nl2br(e($leave['substitute_plan'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($leave['handover_name'])): ?>
                <div style="margin-bottom: 15px;">
                    <div class="muted small">Handover To</div>
                    <div style="font-weight: 600;"><?= e($leave['handover_name']) ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($leave['attachment_path'])): ?>
                <div>
                    <div class="muted small">Attachment (Medical Certificate, etc.)</div>
                    <a href="<?= APP_URL . '/' . e($leave['attachment_path']) ?>" target="_blank" class="btn btn-sm btn-ghost" style="margin-top: 5px; border-color: var(--mpc-blue); color: var(--mpc-blue);">
                        📎 View Document
                    </a>
                </div>
                <?php endif; ?>

                <!-- HR Mark as Returned Button -->
                <?php if ($leave['status'] === 'approved' && !$leave['return_confirmed'] && has_role(['admin', 'hr'])): ?>
                    <form method="post" style="margin-top: 20px; border-top: 1px dashed #ccc; padding-top: 20px;">
                        <?= csrf_field() ?>
                        <button name="mark_returned" value="1" class="btn btn-success" style="width: 100%;" onclick="return confirm('Confirm employee has returned?')">
                            ✓ Mark as Returned (ከፈቃድ ተመልሷል)
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Workflow & HR Balance -->
        <div>
            <!-- HR BALANCE WIDGET (Only visible to HR/Admin) -->
            <?php if (has_role(['admin', 'hr']) && $balance): ?>
            <div class="card" style="border-left-color: var(--mpc-flame); background: #FFFBEB;">
                <h2 style="color: var(--mpc-flame-dark); margin-top: 0; font-size: 16px; margin-bottom: 15px;">
                    ⚖️ Employee Leave Balance
                </h2>
                <ul class="kv" style="font-size: 13px; margin: 0;">
                    <li style="border-bottom: 1px dashed #ddd; padding-bottom: 8px; margin-bottom: 8px;">
                        <span>Annual Leave Remaining</span>
                        <b style="font-size: 16px; color: var(--mpc-navy);"><?= $balance['annual_remaining'] ?> / <?= $balance['annual_entitled'] ?> days</b>
                    </li>
                    <li style="border-bottom: 1px dashed #ddd; padding-bottom: 8px; margin-bottom: 8px;">
                        <span>Sick Leave (Full Pay)</span>
                        <b><?= $balance['sick_full_days'] ?> days left</b>
                    </li>
                    <li>
                        <span>Sick Leave (Half Pay)</span>
                        <b><?= $balance['sick_half_days'] ?> days left</b>
                    </li>
                </ul>
            </div>
            <?php endif; ?>

            <!-- WORKFLOW CHAIN -->
            <div class="card" style="padding: 0; overflow: hidden;">
                <div style="background: var(--mpc-navy); color: #fff; padding: 15px 20px;">
                    <h2 style="margin: 0; font-size: 18px; color: #fff;">Approval Chain</h2>
                </div>
                
                <div style="padding: 20px;">
                    <?php foreach ($steps as $step): ?>
                        <?php 
                            // FIXED LOGIC: Allow 'submitted' OR 'in_progress'
                            $is_my_turn = false;
                            if ($step['status'] === 'pending' && in_array($leave['status'], ['submitted', 'in_progress']) && $step['step_order'] == $leave['current_step']) {
                                if ($user['username'] === 'admin') $is_my_turn = true;
                                if ($step['role_required'] === 'department_head' && $user['position'] === 'Department Head') $is_my_turn = true;
                                if ($step['role_required'] === 'hr' && $user['username'] === 'hr') $is_my_turn = true;
                            }
                        ?>

                        <div class="step-box step-<?= $step['status'] ?>" style="margin-bottom: 15px; position: relative;">
                            <div class="step-header">
                                <strong style="color: var(--mpc-navy); text-transform: capitalize;">
                                    <?= e(str_replace('_', ' ', $step['role_required'])) ?>
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

                            <!-- Action Form (Inline) -->
                            <?php if ($is_my_turn): ?>
                                <div style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px;">
                                    <form method="post" style="display: flex; flex-direction: column; gap: 10px;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                        
                                        <input type="text" name="comment" placeholder="Add a comment (optional)..." style="padding: 8px; font-size: 13px;">
                                        
                                        <div style="display: flex; gap: 10px;">
                                            <button type="submit" name="action" value="approve" class="btn btn-success btn-sm" style="flex: 1;">
                                                ✓ Approve
                                            </button>
                                            <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm" style="flex: 1;" onclick="return confirm('Are you sure you want to REJECT this leave request?');">
                                                ✗ Reject
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($leave['status'] === 'approved'): ?>
                        <div style="text-align: center; padding: 20px; background: #dcfce7; border-radius: 8px; margin-top: 20px;">
                            <div style="font-size: 30px; margin-bottom: 10px;">🎉</div>
                            <h3 style="color: #166534; margin: 0;">Leave Approved!</h3>
                            <p style="color: #166534; font-size: 13px; margin-top: 5px;">The employee's balance has been updated.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>