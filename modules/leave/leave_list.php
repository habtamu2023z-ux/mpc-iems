<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

if (has_role('student')) {
    flash('warning', 'Students do not have access to the HR Leave module.');
    redirect(APP_URL . '/index.php');
}

// ==========================================
// 1. BUILD THE SMART QUERY
// ==========================================
// We added subqueries to fetch the pending step ID and Role
$sql = "
    SELECT sr.*, ld.leave_type, ld.start_date, ld.end_date, ld.days_count, ld.return_confirmed, 
           u.full_name AS emp_name, d.name AS dept_name,
           (SELECT id FROM workflow_steps WHERE request_id = sr.id AND status = 'pending' ORDER BY step_order ASC LIMIT 1) as pending_step_id,
           (SELECT role_required FROM workflow_steps WHERE request_id = sr.id AND status = 'pending' ORDER BY step_order ASC LIMIT 1) as pending_role
    FROM service_requests sr
    JOIN leave_details ld ON ld.request_id = sr.id
    JOIN users u ON u.id = sr.requester_id
    LEFT JOIN departments d ON d.id = sr.department_id
    WHERE sr.request_type = 'leave'
";
$args = [];

// Role-based filtering
if (!has_role(['admin', 'hr'])) {
    if ($user['position'] === 'Department Head') {
        $sql .= " AND (sr.requester_id = ? OR sr.department_id = ?)";
        $args = [$user['id'], $user['department_id']];
    } else {
        $sql .= " AND sr.requester_id = ?";
        $args = [$user['id']];
    }
}
$sql .= " ORDER BY sr.created_at DESC";

$st = $pdo->prepare($sql);
$st->execute($args);
$leaves = $st->fetchAll();

$pageTitle = 'Leave Requests';
include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin: 30px 0 20px; flex-wrap: wrap; gap: 15px;">
    <div>
        <h1 style="margin: 0; color: var(--mpc-navy);">Leave Requests (የፈቃድ ጥያቄዎች)</h1>
        <p class="muted" style="margin-top: 5px;">Manage staff annual, sick, and special leaves.</p>
    </div>
    
    <div style="display: flex; gap: 10px;">
        <?php if (has_role(['admin', 'hr'])): ?>
            <a href="leave_calendar.php" class="btn btn-ghost" style="border-color: var(--mpc-navy); color: var(--mpc-navy);">
                📅 View Calendar
            </a>
        <?php endif; ?>

        <a href="leave_new.php" class="btn btn-primary">
            + Request Leave (አዲስ ፈቃድ ጠይቅ)
        </a>
    </div>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Ref No (ቁጥር)</th>
                    <th>Employee (ሰራተኛ)</th>
                    <th>Type (አይነት)</th>
                    <th>Dates (ቀናት)</th>
                    <th>Days (ቀናት)</th>
                    <th>Status (ሁኔታ)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaves as $r): ?>
                <tr>
                    <!-- Reference Number -->
                    <td>
                        <code style="font-weight: bold; color: var(--mpc-navy); font-size: 13px;">
                            <?= e($r['ref_no']) ?>
                        </code>
                    </td>
                    
                    <!-- Employee -->
                    <td>
                        <strong><?= e($r['emp_name']) ?></strong><br>
                        <small class="muted"><?= e($r['dept_name'] ?? 'N/A') ?></small>
                    </td>
                    
                    <!-- Leave Type -->
                    <td>
                        <span class="badge badge-info"><?= e(ucfirst($r['leave_type'])) ?></span>
                    </td>
                    
                    <!-- Dates (Ethiopian Calendar) -->
                    <td>
                        <div style="font-size: 13px; font-weight: 500;">
                            <?= format_date($r['start_date']) ?>
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            to <?= format_date($r['end_date']) ?>
                        </div>
                    </td>
                    
                    <!-- Days Count -->
                    <td>
                        <strong style="font-size: 16px; color: var(--mpc-blue);"><?= $r['days_count'] ?></strong>
                    </td>
                    
                    <!-- Status -->
                    <td>
                        <?= status_badge($r['status']) ?>
                        <?php if ($r['return_confirmed']): ?>
                            <br><small style="color: var(--mpc-success); font-weight: bold;">✓ Returned</small>
                        <?php endif; ?>
                    </td>
                    
                    <!-- Actions -->
                    <td>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                            
                            <!-- View Button -->
                            <a href="leave_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-ghost" style="border-color: var(--mpc-navy); color: var(--mpc-navy);">
                                👁️ View
                            </a>

                            <!-- NEW: Quick Approve/Reject Buttons -->
                            <?php 
                            // Show buttons only if the request is in progress, 
                            // there is a pending step, and the current user has the right role
                            if ($r['status'] === 'in_progress' && $r['pending_step_id'] && can_approve_step($r['pending_role'])): 
                            ?>
                                <button type="button" class="btn btn-sm btn-success" onclick="quickAction(<?= $r['pending_step_id'] ?>, 'approve', <?= $r['id'] ?>)">
                                    ✓ Approve
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="quickAction(<?= $r['pending_step_id'] ?>, 'reject', <?= $r['id'] ?>)">
                                    ✗ Reject
                                </button>
                            <?php endif; ?>

                            <!-- HR Quick Action: Mark Returned -->
                            <?php if ($r['status'] === 'approved' && !$r['return_confirmed'] && has_role(['admin', 'hr'])): ?>
                                <a href="leave_return.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('Confirm employee has returned from leave?');">
                                    ✓ Returned
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Empty State -->
                <?php if (empty($leaves)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 50px;">
                        <div style="font-size: 40px; margin-bottom: 15px;">🏖️</div>
                        <h3 class="muted">No leave requests found.</h3>
                        <p class="muted" style="font-size: 14px; margin-bottom: 20px;">There are no active or past leave records to display.</p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Smart Quick Action Script -->
<script>
function quickAction(stepId, action, requestId) {
    // Ask for an optional comment
    let comment = prompt(`Enter a comment for this ${action} (Optional):`);
    
    // If user clicks "Cancel" on the prompt, stop the action
    if (comment === null) return;

    // Create a temporary form to submit the data securely
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = 'leave_view.php'; // Re-use the existing backend logic
    
    form.innerHTML = `
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="step_id" value="${stepId}">
        <input type="hidden" name="action" value="${action}">
        <input type="hidden" name="comment" value="${comment}">
        <input type="hidden" name="return_to" value="leave_list.php">
    `;
    
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>