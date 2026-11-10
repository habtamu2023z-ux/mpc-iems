<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   DEACTIVATE / REACTIVATE USER (Admin only)
   ============================================================
   PURPOSE
     Toggle a user's account status between 'active' and
     'inactive'. Deactivation blocks login but preserves all
     data (soft action, reversible).

   FLOW
     GET  user_deactivate.php?id=5&action=deactivate
       → Shows confirmation page with user details + POST form
       → NOT vulnerable to CSRF (no state change on GET)

     POST user_deactivate.php (with csrf + id + action + confirm=1)
       → Executes the status change
       → Logs to audit_log via Audit::updated()
       → Notifies target user via Notifier::
       → Flashes success to admin
       → Redirects to users.php

   FEATURES
     - CSRF protection (state change requires POST + token)
     - Self-target blocked (server-side + UI)
     - No-op detection ("already active/inactive")
     - Read-before-write inside a transaction
     - User notification (target knows what happened)
     - Audit trail with old/new status values
     - Bilingual confirmation page

   BACKWARD COMPATIBILITY
     The existing <a href="user_deactivate.php?id=5"> links in
     users.php and user_edit.php continue to work — they now
     show the confirmation page first. Users click "Confirm"
     to complete the action.

     To restore 2-click UX (modal → action), update users.php
     and user_edit.php to use POST forms with `csrf_field()`
     instead of GET links. The current file already supports
     that POST path — just remove the intermediate confirmation.

   DEPENDENCIES
     functions.php          — base helpers, status_badge, format_date
     includes/auth_check.php — Auth::requireRole, Auth::id, Auth::user
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, danger, warning, html
     includes/audit.php     — Audit::updated
     includes/notification.php — Notifier::warning, Notifier::success
     includes/header.php    — Topbar + sidebar + flash
     includes/footer.php    — Footer + JS

   SAFETY
     - Admin-only access enforced before any DB read
     - POST requires CSRF token
     - Self-target guarded before DB access
     - Transaction wraps read + update
     - No SQL error messages exposed to the user
     - Works when called from any other admin page
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notification.php';


/* ------------------------------------------------------------
   2. AUTH GATE
   ------------------------------------------------------------ */
$user = Auth::require();
Auth::requireRole('admin', 'You do not have permission to perform this action.');


/* ------------------------------------------------------------
   3. COLLECT TARGET + ACTION
   ------------------------------------------------------------ */
$id     = (int)($_REQUEST['id'] ?? 0);
$action = (string)($_REQUEST['action'] ?? 'deactivate');
$action = in_array($action, ['deactivate', 'activate'], true) ? $action : 'deactivate';

if ($id <= 0) {
    Flash::danger('Invalid user ID.');
    redirect('users.php');
}

if ($id === (int)$user['id']) {
    Flash::danger('Security Error: You cannot deactivate or activate your own account.');
    redirect('users.php');
}


/* ------------------------------------------------------------
   4. FETCH TARGET USER
   ------------------------------------------------------------ */
$st = $pdo->prepare("
    SELECT id, full_name, username, status, employment_type, dept_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ? AND u.deleted_at IS NULL
    LIMIT 1
");
$st->execute([$id]);
$target = $st->fetch();

if (!$target) {
    Flash::danger('User not found or already permanently deleted.');
    redirect('users.php');
}

$currentStatus = (string)$target['status'];
$targetStatus  = $action === 'activate' ? 'active' : 'inactive';
$isNoOp        = ($currentStatus === $targetStatus);

$actionLabelAm = $action === 'activate' ? 'እንደገና ማንቀሳቀስ' : 'አቦዝን';
$actionLabelEn = $action === 'activate' ? 'Reactivate'    : 'Deactivate';


/* ------------------------------------------------------------
   5. HANDLE POST — EXECUTE THE ACTION
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    // Guard: self-target (belt-and-braces — already checked above, but POST could be replayed)
    if ($id === (int)$user['id']) {
        Flash::danger('You cannot change your own account status.');
        redirect('users.php');
    }

    // Guard: no-op
    if ($isNoOp) {
        Flash::warning('User "' . $target['full_name'] . '" is already ' . $currentStatus . '.');
        redirect('users.php');
    }

    try {
        $pdo->beginTransaction();

        // Re-read status inside transaction (prevents TOCTOU race)
        $st = $pdo->prepare("SELECT status FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
        $st->execute([$id]);
        $freshStatus = $st->fetchColumn();

        if ($freshStatus === false) {
            $pdo->rollBack();
            Flash::danger('User no longer exists.');
            redirect('users.php');
        }

        if ($freshStatus === $targetStatus) {
            $pdo->rollBack();
            Flash::warning('User "' . $target['full_name'] . '" is already ' . $targetStatus . '.');
            redirect('users.php');
        }

        // Execute the change
        $upd = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $upd->execute([$targetStatus, $id]);

        $pdo->commit();

        // Audit (after commit)
        Audit::updated('users', $id, ['status' => $currentStatus], ['status' => $targetStatus]);

        // Notify the target user (they should know their account status changed)
        $notifTitle = $action === 'activate'
            ? 'Your account has been reactivated'
            : 'Your account has been deactivated';
        $notifBody = $action === 'activate'
            ? 'Your MPC-IEMS account is active again. You may log in.'
            : 'Your MPC-IEMS account has been deactivated. Please contact HR if this is unexpected.';

        if ($action === 'activate') {
            Notifier::success((int)$id, $notifTitle, $notifBody, APP_URL . '/login.php');
        } else {
            Notifier::warning((int)$id, $notifTitle, $notifBody, null);
        }

        // Flash to admin — uses Flash::html() so <strong> renders as bold
        $msg = $action === 'activate' ? 'reactivated' : 'deactivated';
        Flash::html(
            'success',
            'User <strong>' . e($target['full_name']) . '</strong> has been successfully ' . $msg . '.'
        );

        redirect('users.php');

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('user_deactivate.php failed: ' . $ex->getMessage());
        Flash::danger('Could not update the user. Please try again or contact IT.');
        redirect('users.php');
    }
}


/* ------------------------------------------------------------
   6. RENDER CONFIRMATION PAGE (GET or POST without action)
   ------------------------------------------------------------ */
$pageTitle = $actionLabelEn . ' User';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 640px; margin: 40px auto; padding: 0 20px;">

    <!-- ============================================================
         CONFIRMATION CARD
         ============================================================ -->
    <div class="card" style="padding: 0; overflow: hidden; border-left: none;">

        <!-- Header bar (color-coded by action) -->
        <div style="padding: 20px 24px; background: <?= $action === 'deactivate' ? '#FEF2F2' : '#F0FDF4' ?>; border-bottom: 1px solid <?= $action === 'deactivate' ? '#FECACA' : '#BBF7D0' ?>;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 32px; line-height: 1;">
                    <?= $action === 'deactivate' ? '⚠️' : '✅' ?>
                </div>
                <div>
                    <h1 style="margin: 0; font-size: 20px; color: <?= $action === 'deactivate' ? 'var(--mpc-red)' : 'var(--mpc-success)' ?>;">
                        <?= e($actionLabelAm) ?> / <?= e($actionLabelEn) ?> User?
                    </h1>
                    <p class="muted" style="margin: 4px 0 0; font-size: 13px;">
                        <?= $action === 'deactivate'
                            ? 'Please confirm you want to deactivate this account.'
                            : 'Please confirm you want to reactivate this account.' ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div style="padding: 24px;">

            <!-- User details -->
            <div style="background: #F9FAFB; border-radius: 8px; padding: 16px; margin-bottom: 20px; border-left: 4px solid var(--mpc-navy);">
                <div style="display: flex; justify-content: space-between; align-items: start; gap: 12px; flex-wrap: wrap;">
                    <div>
                        <div style="font-size: 11px; color: #6B7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                            Target User
                        </div>
                        <div style="font-size: 17px; font-weight: 700; color: var(--mpc-navy);">
                            <?= e($target['full_name']) ?>
                        </div>
                        <div class="muted" style="font-size: 13px; margin-top: 2px;">
                            @<?= e($target['username']) ?>
                            <?php if (!empty($target['dept_name'])): ?>
                                · <?= e($target['dept_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #6B7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; text-align: right;">
                            Current Status
                        </div>
                        <div style="text-align: right;">
                            <?= status_badge($currentStatus) ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- What will happen -->
            <div style="background: #F0F9FF; border-left: 4px solid var(--mpc-blue); border-radius: 6px; padding: 14px 16px; margin-bottom: 20px;">
                <div style="font-weight: 700; color: var(--mpc-navy); font-size: 13px; margin-bottom: 6px;">
                    What will happen
                </div>
                <?php if ($action === 'deactivate'): ?>
                    <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #374151; line-height: 1.7;">
                        <li>Account status changes from <code><?= e($currentStatus) ?></code> to <code>inactive</code></li>
                        <li>The user <strong>cannot log in</strong> while inactive</li>
                        <li>All their data is preserved — nothing is deleted</li>
                        <li>You can reactivate them anytime from the user list</li>
                        <li>They will receive an in-app notification</li>
                    </ul>
                <?php else: ?>
                    <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: #374151; line-height: 1.7;">
                        <li>Account status changes from <code><?= e($currentStatus) ?></code> to <code>active</code></li>
                        <li>The user <strong>can log in</strong> again</li>
                        <li>They will receive an in-app notification</li>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- No-op warning -->
            <?php if ($isNoOp): ?>
                <div class="alert alert-warning" style="margin-bottom: 20px;">
                    <span>
                        This user is already <strong><?= e($currentStatus) ?></strong>. No action will be taken.
                    </span>
                </div>
            <?php endif; ?>

            <!-- Confirm / Cancel -->
            <?php if ($isNoOp): ?>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <a href="users.php" class="btn btn-primary">Back to Users</a>
                </div>
            <?php else: ?>
                <form method="post" style="display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="<?= e($action) ?>">

                    <a href="users.php" class="btn btn-ghost" style="border-color: #ccc;">
                        Cancel
                    </a>

                    <?php if ($action === 'deactivate'): ?>
                        <button type="submit" class="btn btn-danger">
                            🚫 Yes, Deactivate
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-success">
                            ✓ Yes, Reactivate
                        </button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         SAFETY NOTE
         ============================================================ -->
    <p class="muted" style="text-align: center; font-size: 12px; margin-top: 20px;">
        All actions are logged to the audit trail (audit_log table).
        <br>
        Deactivation is reversible — the user's data is never deleted.
    </p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>