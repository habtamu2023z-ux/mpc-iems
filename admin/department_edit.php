<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   CREATE / EDIT DEPARTMENT (Admin only)
   ============================================================
   PURPOSE
     Handles both new department creation and existing
     department editing based on ?id=N in the URL.

   FEATURES
     - Single file for create + edit (existing pattern preserved)
     - Code immutability: enforced server-side on edit (not just
       `readonly` in HTML)
     - Explicit uniqueness check on create with clear message
     - Head-of-department dropdown grouped by department
     - Validation: required fields, code format, type enum
     - Transaction wraps insert/update
     - Audit::created() on new, Audit::track() diff on edit
     - Post-save warnings:
         * Deactivating a department with active users
         * Assigning a head who already heads another department
     - Sidebar shows current metadata + immutability note

   DEPENDENCIES
     functions.php          — base helpers, format_date
     includes/auth_check.php — Auth::requireRole
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, danger, warning
     includes/audit.php     — Audit::snapshot, Audit::track, Audit::created

   BACKWARD COMPATIBILITY
     - Form field names unchanged: code, name, name_am, type,
       head_user_id, is_active
     - Redirects to departments.php on success (same as before)
     - "Code cannot be changed after creation" note preserved
     - `readonly` on code field preserved in edit mode

   SAFETY
     - Admin-only access enforced before any DB access
     - CSRF required on POST
     - Code enforced server-side (edit mode ignores POST code)
     - Transaction wraps insert/update
     - No SQL errors exposed to user
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';


/* ------------------------------------------------------------
   2. AUTH GATE
   ------------------------------------------------------------ */
$user = Auth::require();
Auth::requireRole('admin', 'Access denied.');


/* ------------------------------------------------------------
   3. RESOLVE MODE + LOAD EXISTING DEPARTMENT
   ------------------------------------------------------------ */
$id      = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

$dept = [
    'code'         => '',
    'name'         => '',
    'name_am'      => '',
    'type'         => 'academic',
    'head_user_id' => '',
    'is_active'    => 1,
];

if ($is_edit) {
    $st = $pdo->prepare("SELECT * FROM departments WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $fetched = $st->fetch();

    if (!$fetched) {
        Flash::danger('Department not found.');
        redirect('departments.php');
    }
    $dept = $fetched;
}


/* ------------------------------------------------------------
   4. FETCH DATA FOR RENDER
   ------------------------------------------------------------ */

// Users eligible to be Head of Department (active, not deleted)
// Grouped by their own department for the optgroup display
try {
    $allUsers = $pdo->query("
        SELECT u.id, u.full_name, u.position, u.department_id,
               d.name AS dept_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.status = 'active' AND u.deleted_at IS NULL
        ORDER BY d.name ASC, u.full_name ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $allUsers = [];
}

$usersByDept = [];
foreach ($allUsers as $u) {
    $key = $u['dept_name'] ?: '— No Department —';
    $usersByDept[$key][] = $u;
}

// How many users are currently assigned to this department?
$deptUserCount = 0;
if ($is_edit) {
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE department_id = ? AND deleted_at IS NULL
        ");
        $st->execute([$id]);
        $deptUserCount = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $deptUserCount = 0;
    }
}


/* ------------------------------------------------------------
   5. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    // Capture before state (edit only)
    $before = $is_edit ? Audit::snapshot('departments', $id) : null;

    // ---- Collect inputs ----
    $code         = trim((string)($_POST['code']         ?? ''));
    $name         = trim((string)($_POST['name']         ?? ''));
    $name_am      = trim((string)($_POST['name_am']      ?? ''));
    $type         = (string)($_POST['type']              ?? 'academic');
    $head_user_id = (int)   ($_POST['head_user_id']      ?? 0);
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    // ---- Server-side immutability of `code` in edit mode ----
    // Even if the admin bypasses `readonly` via DevTools,
    // the original code is preserved.
    if ($is_edit) {
        $code = (string)$dept['code'];
    }

    // ---- Normalize ----
    $code = strtoupper($code);   // Match seed data format: IT, HRD, FIN
    $head_user_id = $head_user_id > 0 ? $head_user_id : null;

    // ---- Validate ----
    $errors = [];

    if ($code === '') {
        $errors[] = 'Department code is required.';
    } elseif (!preg_match('/^[A-Z0-9_]{2,20}$/', $code)) {
        $errors[] = 'Code must be 2–20 characters using uppercase letters, digits, or underscores (e.g. IT, HRD, FIN).';
    }

    if ($name === '') {
        $errors[] = 'English department name is required.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'English name must be 150 characters or fewer.';
    }

    if ($name_am !== '' && mb_strlen($name_am) > 200) {
        $errors[] = 'Amharic name must be 200 characters or fewer.';
    }

    if (!in_array($type, ['academic', 'administrative', 'support'], true)) {
        $errors[] = 'Please select a valid department type.';
    }

    // Uniqueness (create only — code is immutable on edit)
    if (!$is_edit && $code !== '' && empty($errors)) {
        $st = $pdo->prepare("SELECT id FROM departments WHERE code = ? LIMIT 1");
        $st->execute([$code]);
        if ($st->fetchColumn()) {
            $errors[] = "Department code '{$code}' is already in use.";
        }
    }

    // Head user must exist (if provided)
    if ($head_user_id !== null) {
        $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$head_user_id]);
        if (!$st->fetchColumn()) {
            $errors[] = 'The selected head of department was not found.';
        }
    }

    // ---- Persist ----
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($is_edit) {
                $upd = $pdo->prepare("
                    UPDATE departments SET
                        name = ?, name_am = ?, type = ?, head_user_id = ?, is_active = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $name,
                    $name_am !== '' ? $name_am : null,
                    $type,
                    $head_user_id,
                    $is_active,
                    $id,
                ]);
                $dept_id = $id;
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO departments
                        (code, name, name_am, type, head_user_id, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $code,
                    $name,
                    $name_am !== '' ? $name_am : null,
                    $type,
                    $head_user_id,
                    $is_active,
                ]);
                $dept_id = (int)$pdo->lastInsertId();
            }

            $pdo->commit();

            /* ---------- AUDIT (after commit) ---------- */
            if ($is_edit) {
                $after = Audit::snapshot('departments', $dept_id);
                if ($before && $after) {
                    $diff = Audit::track('departments', $dept_id, $before, $after);
                    if (empty($diff['old']) && empty($diff['new'])) {
                        Flash::warning('No changes were made.');
                    } else {
                        $changed = count($diff['new']);
                        Flash::success(
                            "Department '<strong>" . e($name) . "</strong>' updated successfully ({$changed} field"
                            . ($changed === 1 ? '' : 's') . " changed)."
                        );
                    }
                } else {
                    Flash::success("Department updated successfully.");
                }
            } else {
                Audit::created('departments', $dept_id, [
                    'code'      => $code,
                    'name'      => $name,
                    'type'      => $type,
                    'is_active' => $is_active,
                ]);
                Flash::success(
                    "Department '<strong>" . e($name) . "</strong>' created successfully with code <code>"
                    . e($code) . "</code>."
                );
            }

            /* ---------- POST-SAVE WARNINGS ---------- */

            // Deactivation warning (edit only, when going active → inactive with users)
            if (
                $is_edit
                && $is_active === 0
                && !empty($before['is_active'])
                && (int)$before['is_active'] === 1
                && $deptUserCount > 0
            ) {
                Flash::warning(
                    "Note: this department still has <strong>{$deptUserCount}</strong> assigned user(s). "
                    . "They remain assigned, but the department will be hidden from active dropdowns."
                );
            }

            // Multi-headship detection (any save with a head assigned)
            if ($head_user_id !== null) {
                $st = $pdo->prepare("
                    SELECT name FROM departments
                    WHERE head_user_id = ? AND id != ?
                ");
                $st->execute([$head_user_id, $dept_id]);
                $otherHeads = $st->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($otherHeads)) {
                    Flash::warning(
                        "Note: the selected user is also head of: <strong>"
                        . implode('</strong>, <strong>', array_map('e', $otherHeads))
                        . "</strong>."
                    );
                }
            }

            redirect('departments.php');

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('department_edit.php failed: ' . $ex->getMessage());

            if ($ex instanceof PDOException && $ex->getCode() === '23000') {
                Flash::danger("A department with this code already exists.");
            } else {
                Flash::danger('Could not save the department. Please try again or contact IT.');
            }
            // Fall through to re-render
        }
    } else {
        foreach (array_slice($errors, 0, 4) as $err) {
            Flash::danger($err);
        }
        if (count($errors) > 4) {
            Flash::warning('…and ' . (count($errors) - 4) . ' more error(s). Please review the form.');
        }
    }
}


/* ------------------------------------------------------------
   6. RENDER
   ------------------------------------------------------------ */
$pageTitle = $is_edit ? 'Edit Department' : 'New Department';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1000px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">
                <?= $is_edit ? 'Edit Department' : 'Add New Department' ?>
            </h1>
            <p class="muted" style="margin-top: 5px;">
                <?= $is_edit
                    ? 'Update the department name, type, head, or status.'
                    : 'Create a new academic, administrative, or support unit.' ?>
            </p>
        </div>
        <a href="departments.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to List
        </a>
    </div>

    <!-- ============================================================
         LAYOUT: FORM (2/3) + SIDEBAR (1/3)
         ============================================================ -->
    <div class="grid-2" style="grid-template-columns: 2fr 1fr; align-items: start;">

        <!-- ============================================================
             LEFT: FORM
             ============================================================ -->
        <div class="card" style="padding: 30px;">
            <form method="post" class="stack" autocomplete="off">
                <?= Csrf::field() ?>

                <!-- ============================================================
                     SECTION 1: Identification
                     ============================================================ -->
                <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px;">
                    Department Identity (የክፍሉ መለያ)
                </h3>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="code">
                            Department Code (ኮድ) *
                            <?php if ($is_edit): ?>
                                <span class="badge badge-warning" style="margin-left: 6px; font-size: 10px;">Immutable</span>
                            <?php endif; ?>
                        </label>
                        <input type="text" name="code" id="code" required
                               minlength="2" maxlength="20"
                               pattern="[A-Za-z0-9_]{2,20}"
                               placeholder="e.g. IT, HRD, FIN"
                               value="<?= e($dept['code']) ?>"
                               <?= $is_edit ? 'readonly style="background:#f3f4f6; cursor: not-allowed;"' : '' ?>>
                        <?php if ($is_edit): ?>
                            <small class="muted">Code cannot be changed after creation.</small>
                        <?php else: ?>
                            <small class="muted">Uppercase letters, digits, or underscores (2–20 chars).</small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="type">
                            Type (አይነት) *
                        </label>
                        <select name="type" id="type" required>
                            <option value="academic"       <?= $dept['type'] === 'academic'       ? 'selected' : '' ?>>Academic (ትምህርታዊ)</option>
                            <option value="administrative" <?= $dept['type'] === 'administrative' ? 'selected' : '' ?>>Administrative (አስተዳደራዊ)</option>
                            <option value="support"        <?= $dept['type'] === 'support'        ? 'selected' : '' ?>>Support (ድጋፍ)</option>
                        </select>
                    </div>
                </div>

                <!-- ============================================================
                     SECTION 2: Names
                     ============================================================ -->
                <h3 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px;">
                    Names (ስሞች)
                </h3>

                <div class="form-group">
                    <label for="name">
                        Department Name (English) *
                    </label>
                    <input type="text" name="name" id="name" required
                           maxlength="150"
                           placeholder="e.g. Information Technology"
                           value="<?= e($dept['name']) ?>">
                </div>

                <div class="form-group">
                    <label for="name_am">
                        የክፍል ስም (Amharic)
                    </label>
                    <input type="text" name="name_am" id="name_am"
                           maxlength="200"
                           placeholder="e.g. ኢንፎርሜሽን ቴክኖሎጂ"
                           value="<?= e($dept['name_am']) ?>"
                           style="font-family: 'Noto Sans Ethiopic', sans-serif;">
                </div>

                <!-- ============================================================
                     SECTION 3: Head + Status
                     ============================================================ -->
                <h3 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px;">
                    Leadership &amp; Status (አመራርና ሁኔታ)
                </h3>

                <div class="form-group">
                    <label for="head_user_id">
                        Head of Department (የክፍሉ ኃላፊ)
                    </label>
                    <select name="head_user_id" id="head_user_id">
                        <option value="">-- Not Assigned --</option>
                        <?php foreach ($usersByDept as $deptLabel => $users): ?>
                            <optgroup label="<?= e($deptLabel) ?>">
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"
                                        <?= (int)$dept['head_user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                                        <?= e($u['full_name']) ?><?= $u['position'] ? ' (' . e($u['position']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted">Users are grouped by their current department. You may assign any active user.</small>
                </div>

                <div class="form-group" style="background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px solid #eee;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 0;">
                        <input type="checkbox" name="is_active" value="1"
                               <?= (int)$dept['is_active'] === 1 ? 'checked' : '' ?>
                               style="width: 20px; height: 20px; accent-color: var(--mpc-blue);">
                        <span>
                            <strong>Department is Active</strong> (ክፍሉ ገባሪ ነው)
                            <?php if ($is_edit && $deptUserCount > 0): ?>
                                <br><small class="muted" style="font-weight: normal;">
                                    <?= $deptUserCount ?> user<?= $deptUserCount === 1 ? '' : 's' ?> currently assigned to this department.
                                </small>
                            <?php endif; ?>
                        </span>
                    </label>
                </div>

                <!-- ============================================================
                     ACTIONS
                     ============================================================ -->
                <div style="margin-top: 25px; border-top: 1px solid #eee; padding-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary btn-lg" style="flex: 2;">
                        <?= $is_edit ? '💾 Save Changes' : '➕ Create Department' ?>
                    </button>
                    <a href="departments.php" class="btn btn-ghost btn-lg" style="flex: 1; border-color: #ccc; color: #333;">
                        Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
             RIGHT: SIDEBAR
             ============================================================ -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <?php if ($is_edit): ?>
                <!-- Metadata (edit only) -->
                <div class="card">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        ℹ️ Department Info
                    </h3>
                    <ul class="kv" style="font-size: 13px;">
                        <li>
                            <span>Department ID</span>
                            <b>#<?= (int)$dept['id'] ?></b>
                        </li>
                        <li>
                            <span>Code</span>
                            <b><code><?= e($dept['code']) ?></code></b>
                        </li>
                        <li>
                            <span>Current Type</span>
                            <b>
                                <span class="badge badge-<?= $dept['type'] === 'academic' ? 'info' : ($dept['type'] === 'administrative' ? 'primary' : 'warning') ?>">
                                    <?= e(ucfirst($dept['type'])) ?>
                                </span>
                            </b>
                        </li>
                        <li>
                            <span>Assigned Users</span>
                            <b><?= $deptUserCount ?></b>
                        </li>
                        <li>
                            <span>Created</span>
                            <b><?= !empty($dept['created_at']) ? format_date($dept['created_at']) : '—' ?></b>
                        </li>
                        <li>
                            <span>Updated</span>
                            <b><?= !empty($dept['updated_at']) ? format_date($dept['updated_at'], true) : '—' ?></b>
                        </li>
                    </ul>

                    <?php if ($deptUserCount > 0): ?>
                        <a href="users.php?dept=<?= (int)$dept['id'] ?>"
                           class="btn btn-sm btn-ghost"
                           style="width: 100%; margin-top: 12px; border-color: #ccc; color: var(--mpc-blue);">
                            View <?= $deptUserCount ?> User<?= $deptUserCount === 1 ? '' : 's' ?> →
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Why code is immutable -->
            <?php if ($is_edit): ?>
                <div class="card" style="border-left-color: var(--mpc-flame); background: #FFFBEB;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-flame-dark); border-bottom: 1px solid #FDE68A; padding-bottom: 10px;">
                        🔒 Why Is the Code Locked?
                    </h3>
                    <p class="muted" style="font-size: 12px; margin: 0; line-height: 1.7;">
                        The department code (<code><?= e($dept['code']) ?></code>) is used as a permanent identifier throughout the system — in clearance steps, audit logs, and reports. Changing it would break historic references. If you need a different code, create a new department and reassign users.
                    </p>
                </div>
            <?php else: ?>
                <!-- Guidance for creation -->
                <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #BAE6FD; padding-bottom: 10px;">
                        💡 Code Guidelines
                    </h3>
                    <ul style="font-size: 12px; margin: 0; padding-left: 16px; color: #374151; line-height: 1.8;">
                        <li><strong>2–20 characters</strong> — uppercase preferred</li>
                        <li>Use <strong>letters, digits, underscores</strong></li>
                        <li>Keep codes short — they appear in many places</li>
                        <li>Examples: <code>IT</code>, <code>HRD</code>, <code>FIN</code>, <code>LIB</code></li>
                        <li>Codes <strong>cannot be changed</strong> after creation</li>
                    </ul>
                </div>

                <div class="card" style="border-left-color: var(--mpc-flame); background: #FFFBEB;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-flame-dark); border-bottom: 1px solid #FDE68A; padding-bottom: 10px;">
                        🏷️ Department Types
                    </h3>
                    <ul style="font-size: 12px; margin: 0; padding-left: 16px; color: #374151; line-height: 1.8;">
                        <li><strong>Academic</strong> — teaching departments (IT, Mechanical, Civil...)</li>
                        <li><strong>Administrative</strong> — management offices (Registrar, HR, Finance...)</li>
                        <li><strong>Support</strong> — service units (Library, Dormitory, Store, Clinic)</li>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Danger zone (edit only) -->
            <?php if ($is_edit && $deptUserCount > 0): ?>
                <div class="card" style="border-left-color: var(--mpc-red); background: #FEF2F2;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-red); border-bottom: 1px solid #FECACA; padding-bottom: 10px;">
                        ⚠️ Headcount Notice
                    </h3>
                    <p class="muted" style="font-size: 12px; margin: 0; line-height: 1.7;">
                        This department has <strong><?= $deptUserCount ?></strong> assigned user<?= $deptUserCount === 1 ? '' : 's' ?>.
                        Deactivating it will not unassign them, but they will lose access to department-specific pages (like department-filtered views and clearance flows) until it is reactivated.
                    </p>
                    <a href="users.php?dept=<?= (int)$dept['id'] ?>"
                       class="btn btn-sm btn-ghost"
                       style="width: 100%; margin-top: 12px; border-color: #FECACA; color: var(--mpc-red);">
                        Review Users →
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>