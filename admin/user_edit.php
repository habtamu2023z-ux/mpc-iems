<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   EDIT EXISTING USER (Admin only)
   ============================================================
   PURPOSE
     Edit any user's details, with guard-rails for the editing
     admin's own account and full audit logging.

   FEATURES
     - Loads existing user (aborts if not found or soft-deleted)
     - Password change is optional — leave blank to keep current
     - If password changes: confirmation required + strength rules
     - Username uniqueness (case-insensitive, excludes self)
     - Employee/Student code uniqueness (excludes self)
     - Self-edit safety: admin can't change own status or type
     - Type change to staff auto-creates missing hr_balances row
     - Audit::track() records only the fields that actually changed
     - Balance widget on the right (read-only)
     - Metadata card: created, last login, deleted status

   DEPENDENCIES
     functions.php          — base helpers
     includes/auth_check.php — Auth::requireRole, Auth::id
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, danger, warning
     includes/audit.php     — Audit::snapshot, Audit::track
     includes/policy.php    — Policy::annualLeaveBaseDays, etc.

   SAFETY
     - Admin-only access enforced before any DB read
     - CSRF required on POST
     - Self-deactivation blocked server-side
     - Self-type-change blocked server-side
     - No SQL error messages exposed
     - Password hash never appears in audit trail
     - Transaction wraps user update + balance creation
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/policy.php';


/* ------------------------------------------------------------
   2. AUTH GATE
   ------------------------------------------------------------ */
$user = Auth::require();
Auth::requireRole('admin', 'Access denied.');


/* ------------------------------------------------------------
   3. LOAD TARGET USER
   ------------------------------------------------------------ */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    Flash::danger('Invalid user ID.');
    redirect('users.php');
}

$st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$st->execute([$id]);
$editUser = $st->fetch();

if (!$editUser) {
    Flash::danger('User not found or has been deleted.');
    redirect('users.php');
}

$isSelf = ((int)$editUser['id'] === (int)$user['id']);


/* ------------------------------------------------------------
   4. HELPERS
   ------------------------------------------------------------ */

function mpc_edit_current_fiscal_year(PDO $pdo): string
{
    try {
        $row = $pdo->query("
            SELECT fiscal_year, COUNT(*) AS cnt
            FROM hr_balances
            GROUP BY fiscal_year
            ORDER BY cnt DESC, fiscal_year DESC
            LIMIT 1
        ")->fetch();
        if ($row && !empty($row['fiscal_year'])) {
            return (string)$row['fiscal_year'];
        }
    } catch (Throwable $e) {
        // fall through
    }
    $now   = time();
    $year  = (int)date('Y', $now);
    $july8 = mktime(0, 0, 0, 7, 8, $year);
    if ($now >= $july8) {
        return $year . '-' . str_pad((string)(($year + 1) % 100), 2, '0', STR_PAD_LEFT);
    }
    return ($year - 1) . '-' . str_pad((string)($year % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * Fetch the user's current-year balance row (used in the sidebar widget).
 */
function mpc_edit_user_balance(PDO $pdo, int $userId): ?array
{
    try {
        $st = $pdo->prepare("
            SELECT * FROM v_leave_balances_summary
            WHERE user_id = ?
            ORDER BY fiscal_year DESC
            LIMIT 1
        ");
        $st->execute([$userId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}


/* ------------------------------------------------------------
   5. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    // ---- Capture before state for audit diff ----
    $before = Audit::snapshot('users', $id);

    // ---- Collect inputs ----
    $username         = trim((string)($_POST['username']         ?? ''));
    $password         =       (string)($_POST['password']         ?? '');
    $password_confirm =       (string)($_POST['password_confirm'] ?? '');
    $full_name        = trim((string)($_POST['full_name']        ?? ''));
    $email            = trim((string)($_POST['email']            ?? ''));
    $phone            = trim((string)($_POST['phone']            ?? ''));
    $gender           = (string)($_POST['gender']                ?? 'male');
    $emp_type         = (string)($_POST['employment_type']       ?? '');
    $status           = (string)($_POST['status']                ?? 'active');
    $dept_id          = (int)   ($_POST['department_id']         ?? 0);
    $position         = trim((string)($_POST['position']         ?? ''));

    $employee_code    = trim((string)($_POST['employee_code']    ?? ''));
    $student_code     = trim((string)($_POST['student_code']     ?? ''));
    $hire_date        = trim((string)($_POST['hire_date']        ?? ''));
    $birth_date       = trim((string)($_POST['birth_date']       ?? ''));
    $salary_raw       = trim((string)($_POST['salary']           ?? ''));
    $salary           = $salary_raw !== '' ? (float)$salary_raw : null;

    // ---- Self-edit guards (server-side, not just UI) ----
    if ($isSelf) {
        // Cannot change own type
        if ($emp_type !== (string)$editUser['employment_type']) {
            $emp_type = (string)$editUser['employment_type'];
            Flash::warning('You cannot change your own user type. This field was reset.');
        }
        // Cannot change own status
        if ($status !== (string)$editUser['status']) {
            $status = (string)$editUser['status'];
            Flash::warning('You cannot change your own account status. This field was reset.');
        }
    }

    // ---- Normalize: keep only relevant fields ----
    if ($emp_type === 'student') {
        $employee_code = '';
        $hire_date     = '';
        $salary        = null;
    } else {
        $student_code = '';
    }

    $dept_id       = $dept_id > 0             ? $dept_id       : null;
    $email         = $email        !== ''     ? $email         : null;
    $phone         = $phone        !== ''     ? $phone         : null;
    $position      = $position     !== ''     ? $position      : null;
    $employee_code = $employee_code!== ''     ? $employee_code : null;
    $student_code  = $student_code !== ''     ? $student_code  : null;
    $hire_date     = $hire_date    !== ''     ? $hire_date     : null;
    $birth_date    = $birth_date   !== ''     ? $birth_date    : null;

    // ---- Validate ----
    $errors = [];

    if ($username === '')  $errors[] = 'Username is required.';
    if ($full_name === '') $errors[] = 'Full name is required.';
    if (!in_array($emp_type, ['civil_servant', 'contract', 'daily_labor', 'student'], true)) {
        $errors[] = 'Please select a valid user type.';
    }
    if (!in_array($status, ['active', 'inactive', 'suspended', 'retired', 'resigned'], true)) {
        $errors[] = 'Please select a valid status.';
    }

    if ($username !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Username must be 3–60 characters and contain only letters, digits, dots, underscores, or hyphens.';
    }

    // Password only validated if provided
    if ($password !== '') {
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one letter and one number.';
        }
        if ($password !== $password_confirm) {
            $errors[] = 'Passwords do not match.';
        }
    }

    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($phone !== null) {
        $cleanPhone = preg_replace('/[\s\-\.\(\)]/', '', $phone);
        if (!preg_match('/^(?:\+?251|0)(?:9|7)\d{8}$/', $cleanPhone)
            && !preg_match('/^(?:\+?251|0)(?:1|2|3|4|5|6|8)\d{8}$/', $cleanPhone)) {
            $errors[] = 'Please enter a valid Ethiopian phone number.';
        }
    }

    if ($birth_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        $errors[] = 'Birth date must be in YYYY-MM-DD format.';
    }
    if ($hire_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date)) {
        $errors[] = 'Hire date must be in YYYY-MM-DD format.';
    }

    // ---- Uniqueness (excluding self) ----
    if ($username !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ? LIMIT 1");
        $st->execute([$username, $id]);
        if ($st->fetchColumn()) {
            $errors[] = 'Username is already taken by another user.';
        }
    }
    if ($employee_code !== null) {
        $st = $pdo->prepare("SELECT id FROM users WHERE employee_code = ? AND id != ? LIMIT 1");
        $st->execute([$employee_code, $id]);
        if ($st->fetchColumn()) {
            $errors[] = 'Employee code is already in use by another user.';
        }
    }
    if ($student_code !== null) {
        $st = $pdo->prepare("SELECT id FROM users WHERE student_code = ? AND id != ? LIMIT 1");
        $st->execute([$student_code, $id]);
        if ($st->fetchColumn()) {
            $errors[] = 'Student code is already in use by another user.';
        }
    }

    // ---- Persist ----
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // 1. Update user
            if ($password !== '') {
                // With password change
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("
                    UPDATE users SET
                        username = ?, password_hash = ?, full_name = ?, email = ?, phone = ?,
                        gender = ?, employment_type = ?, status = ?, department_id = ?, position = ?,
                        employee_code = ?, student_code = ?, hire_date = ?, birth_date = ?, salary = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $username, $hash, $full_name, $email, $phone,
                    $gender, $emp_type, $status, $dept_id, $position,
                    $employee_code, $student_code, $hire_date, $birth_date, $salary,
                    $id,
                ]);
            } else {
                // Without password change
                $upd = $pdo->prepare("
                    UPDATE users SET
                        username = ?, full_name = ?, email = ?, phone = ?,
                        gender = ?, employment_type = ?, status = ?, department_id = ?, position = ?,
                        employee_code = ?, student_code = ?, hire_date = ?, birth_date = ?, salary = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $username, $full_name, $email, $phone,
                    $gender, $emp_type, $status, $dept_id, $position,
                    $employee_code, $student_code, $hire_date, $birth_date, $salary,
                    $id,
                ]);
            }

            // 2. If transitioning to staff and no hr_balances row exists, create one
            if ($emp_type !== 'student') {
                $stBal = $pdo->prepare("SELECT COUNT(*) FROM hr_balances WHERE user_id = ?");
                $stBal->execute([$id]);
                if ((int)$stBal->fetchColumn() === 0) {
                    $fiscalYear     = mpc_edit_current_fiscal_year($pdo);
                    $annualEntitled = Policy::annualLeaveBaseDays();
                    $sickFull       = Policy::sickLeaveFullDays();
                    $sickHalf       = Policy::sickLeaveHalfDays();
                    $sickUnpaid     = Policy::sickLeaveUnpaidDays();

                    $pdo->prepare("
                        INSERT INTO hr_balances
                        (user_id, fiscal_year, annual_entitled, sick_full_days, sick_half_days, sick_unpaid_days)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$id, $fiscalYear, $annualEntitled, $sickFull, $sickHalf, $sickUnpaid]);
                }
            }

            $pdo->commit();

            // 3. Audit AFTER commit — only logs fields that changed
            $after = Audit::snapshot('users', $id);
            if ($before && $after) {
                $diff = Audit::track('users', $id, $before, $after);
                if (empty($diff['old']) && empty($diff['new'])) {
                    Flash::warning('No changes were made.');
                } else {
                    $changedCount = count($diff['new']);
                    Flash::success("User '{$full_name}' updated successfully ({$changedCount} field" . ($changedCount === 1 ? '' : 's') . " changed).");
                }
            } else {
                Flash::success("User '{$full_name}' updated successfully.");
            }

            // Refresh the loaded user
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $editUser = $st->fetch() ?: $editUser;

            redirect('users.php');

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('user_edit.php update failed: ' . $ex->getMessage());

            if ($ex instanceof PDOException && $ex->getCode() === '23000') {
                Flash::danger('A user with this username, employee code, or student code already exists.');
            } else {
                Flash::danger('Could not update the user. Please try again or contact IT.');
            }
            // Fall through — form re-renders with submitted values
        }
    } else {
        // Show validation errors (up to 4)
        $show = array_slice($errors, 0, 4);
        foreach ($show as $err) {
            Flash::danger($err);
        }
        if (count($errors) > 4) {
            Flash::warning('…and ' . (count($errors) - 4) . ' more error(s). Please review the form.');
        }
    }
}


/* ------------------------------------------------------------
   6. FETCH DEPARTMENTS + BALANCE FOR RENDER
   ------------------------------------------------------------ */
try {
    $departments = $pdo->query("
        SELECT id, name FROM departments
        WHERE is_active = 1
        ORDER BY name ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $departments = [];
}

$balance = mpc_edit_user_balance($pdo, $id);


/* ------------------------------------------------------------
   7. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Edit User';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">
                Edit User: <?= e($editUser['full_name']) ?>
                <?php if ($isSelf): ?>
                    <span class="badge badge-primary" style="vertical-align: middle; margin-left: 8px; font-size: 11px;">This is you</span>
                <?php endif; ?>
            </h1>
            <p class="muted" style="margin-top: 5px;">Update account details and permissions.</p>
        </div>
        <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to List
        </a>
    </div>

    <!-- ============================================================
         LAYOUT: FORM (2/3) + SIDEBAR (1/3)
         ============================================================ -->
    <div class="grid-2" style="grid-template-columns: 2fr 1fr; align-items: start;">

        <!-- ============================================================
             LEFT: EDIT FORM
             ============================================================ -->
        <div class="card" style="padding: 30px;">
            <form method="post" class="stack" autocomplete="off">
                <?= Csrf::field() ?>

                <!-- ============================================================
                     SECTION 1: Account Details
                     ============================================================ -->
                <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px;">
                    Account Details (የመለያ መረጃ)
                </h3>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="username">
                            Username (የተጠቃሚ ስም) *
                        </label>
                        <input type="text" name="username" id="username" required
                               minlength="3" maxlength="60"
                               pattern="[a-zA-Z0-9._\-]{3,60}"
                               autocomplete="off"
                               value="<?= e($_POST['username'] ?? $editUser['username']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="emp_type">
                            User Type (የተጠቃሚ አይነት) *
                            <?php if ($isSelf): ?>
                                <span class="badge badge-warning" style="margin-left: 6px; font-size: 10px;">Locked</span>
                            <?php endif; ?>
                        </label>
                        <select name="employment_type" id="emp_type" required
                                onchange="toggleFields()"
                                <?= $isSelf ? 'disabled' : '' ?>>
                            <option value="civil_servant" <?= ($editUser['employment_type'] === 'civil_servant') ? 'selected' : '' ?>>
                                Civil Servant (ቋሚ ሰራተኛ)
                            </option>
                            <option value="contract" <?= ($editUser['employment_type'] === 'contract') ? 'selected' : '' ?>>
                                Contract Employee (ኮንትራት)
                            </option>
                            <option value="daily_labor" <?= ($editUser['employment_type'] === 'daily_labor') ? 'selected' : '' ?>>
                                Daily Labor (የቀን ሰራተኛ)
                            </option>
                            <option value="student" <?= ($editUser['employment_type'] === 'student') ? 'selected' : '' ?>>
                                Student (ተማሪ)
                            </option>
                        </select>
                        <?php if ($isSelf): ?>
                            <input type="hidden" name="employment_type" value="<?= e($editUser['employment_type']) ?>">
                            <small class="muted">You cannot change your own user type.</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="status">
                            Account Status (ሁኔታ)
                            <?php if ($isSelf): ?>
                                <span class="badge badge-warning" style="margin-left: 6px; font-size: 10px;">Locked</span>
                            <?php endif; ?>
                        </label>
                        <select name="status" id="status" required <?= $isSelf ? 'disabled' : '' ?>>
                            <?php foreach (['active', 'inactive', 'suspended', 'retired', 'resigned'] as $s): ?>
                                <option value="<?= $s ?>" <?= $editUser['status'] === $s ? 'selected' : '' ?>>
                                    <?= ucfirst($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isSelf): ?>
                            <input type="hidden" name="status" value="<?= e($editUser['status']) ?>">
                            <small class="muted">You cannot change your own account status.</small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="gender">
                            Gender (ጾታ)
                        </label>
                        <select name="gender" id="gender">
                            <option value="male"   <?= $editUser['gender'] === 'male'   ? 'selected' : '' ?>>Male (ወንድ)</option>
                            <option value="female" <?= $editUser['gender'] === 'female' ? 'selected' : '' ?>>Female (ሴት)</option>
                        </select>
                    </div>
                </div>

                <!-- ============================================================
                     SECTION: Password Reset (optional)
                     ============================================================ -->
                <div style="background: #F0F9FF; padding: 15px; border-radius: 8px; border-left: 3px solid var(--mpc-blue);">
                    <h4 style="margin: 0 0 10px; color: var(--mpc-navy); font-size: 14px;">
                        🔑 Password Reset (የይለፍ ቃል ቀይር)
                    </h4>
                    <p class="muted" style="margin: 0 0 12px; font-size: 12px;">
                        Leave blank to keep the current password. Only fill in if the user needs a new password.
                    </p>

                    <div class="grid-2">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="password">
                                New Password
                            </label>
                            <input type="password" name="password" id="password"
                                   minlength="8"
                                   data-strength
                                   autocomplete="new-password"
                                   placeholder="Leave blank to keep current">
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="password_confirm">
                                Confirm New Password
                            </label>
                            <input type="password" name="password_confirm" id="password_confirm"
                                   minlength="8"
                                   data-match="password"
                                   autocomplete="new-password"
                                   placeholder="Repeat new password">
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     SECTION 2: Personal Information
                     ============================================================ -->
                <h3 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px;">
                    Personal Information (የግል መረጃ)
                </h3>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="full_name">
                            Full Name (ሙሉ ስም) *
                        </label>
                        <input type="text" name="full_name" id="full_name" required
                               maxlength="150"
                               value="<?= e($_POST['full_name'] ?? $editUser['full_name']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">
                            Email Address
                        </label>
                        <input type="email" name="email" id="email"
                               maxlength="150"
                               value="<?= e($_POST['email'] ?? ($editUser['email'] ?? '')) ?>">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="phone">
                            Phone Number
                        </label>
                        <input type="text" name="phone" id="phone"
                               maxlength="30"
                               placeholder="e.g. 0911223344"
                               value="<?= e($_POST['phone'] ?? ($editUser['phone'] ?? '')) ?>">
                        <small class="muted">Ethiopian format: 0911223344, 0711223344, or +251...</small>
                    </div>

                    <div class="form-group">
                        <label for="birth_date">
                            Birth Date (የትውልድ ቀን)
                        </label>
                        <input type="date" name="birth_date" id="birth_date"
                               value="<?= e($_POST['birth_date'] ?? ($editUser['birth_date'] ?? '')) ?>">
                    </div>
                </div>

                <!-- ============================================================
                     SECTION 3: Employment / Academic Info
                     ============================================================ -->
                <h3 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px;">
                    Employment / Academic Info (የስራ መረጃ)
                </h3>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="department_id">
                            Department (ክፍል)
                        </label>
                        <select name="department_id" id="department_id">
                            <option value="">-- No Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"
                                    <?= ((int)($editUser['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="position">
                            Position / Level (የስራ ድርሻ)
                        </label>
                        <input type="text" name="position" id="position"
                               maxlength="120"
                               value="<?= e($_POST['position'] ?? ($editUser['position'] ?? '')) ?>">
                    </div>
                </div>

                <!-- STAFF FIELDS -->
                <div id="staff_fields" class="grid-2"
                     style="display: none; background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px dashed #ccc;">
                    <div class="form-group">
                        <label for="employee_code">
                            Employee Code (የሰራተኛ መለያ)
                        </label>
                        <input type="text" name="employee_code" id="employee_code"
                               maxlength="40"
                               value="<?= e($_POST['employee_code'] ?? ($editUser['employee_code'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label for="hire_date">
                            Hire Date (የተቀጠሩበት ቀን)
                        </label>
                        <input type="date" name="hire_date" id="hire_date"
                               value="<?= e($_POST['hire_date'] ?? ($editUser['hire_date'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label for="salary">
                            Monthly Salary (ETB)
                        </label>
                        <input type="number" step="0.01" min="0" name="salary" id="salary"
                               value="<?= e($_POST['salary'] ?? ($editUser['salary'] ?? '')) ?>">
                    </div>
                </div>

                <!-- STUDENT FIELDS -->
                <div id="student_fields" class="form-group"
                     style="display: none; background: #E0F2FE; padding: 15px; border-radius: 8px; border: 1px dashed var(--mpc-blue);">
                    <label for="student_code">
                        Student Code (የተማሪ መለያ)
                    </label>
                    <input type="text" name="student_code" id="student_code"
                           maxlength="40"
                           value="<?= e($_POST['student_code'] ?? ($editUser['student_code'] ?? '')) ?>">
                </div>

                <!-- ACTIONS -->
                <div style="margin-top: 25px; border-top: 1px solid #eee; padding-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary btn-lg" style="flex: 2;">
                        💾 Save Changes (ለውጦችን አስቀምጥ)
                    </button>
                    <a href="users.php" class="btn btn-ghost btn-lg" style="flex: 1; border-color: #ccc; color: #333;">
                        Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
             RIGHT: SIDEBAR
             ============================================================ -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- Metadata card -->
            <div class="card">
                <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    ℹ️ Account Info
                </h3>
                <ul class="kv" style="font-size: 13px;">
                    <li>
                        <span>User ID</span>
                        <b>#<?= (int)$editUser['id'] ?></b>
                    </li>
                    <li>
                        <span>Created</span>
                        <b><?= !empty($editUser['created_at']) ? format_date($editUser['created_at'], true) : '—' ?></b>
                    </li>
                    <li>
                        <span>Last Login</span>
                        <b><?= !empty($editUser['last_login_at']) ? format_date($editUser['last_login_at'], true) : 'Never' ?></b>
                    </li>
                    <li>
                        <span>Status</span>
                        <b><?= status_badge($editUser['status']) ?></b>
                    </li>
                </ul>
            </div>

            <!-- Balance widget (only for staff) -->
            <?php if ($editUser['employment_type'] !== 'student'): ?>
                <div class="card" style="border-left-color: var(--mpc-flame); background: #FFFBEB;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-flame-dark); border-bottom: 1px solid #FDE68A; padding-bottom: 10px;">
                        ⚖️ Leave Balance
                    </h3>

                    <?php if ($balance): ?>
                        <ul class="kv" style="font-size: 13px;">
                            <li>
                                <span>Fiscal Year</span>
                                <b><?= e($balance['fiscal_year']) ?></b>
                            </li>
                            <li>
                                <span>Annual Remaining</span>
                                <b style="font-size: 15px; color: <?= (int)$balance['annual_remaining'] > 0 ? 'var(--mpc-success)' : 'var(--mpc-red)' ?>;">
                                    <?= (int)$balance['annual_remaining'] ?> / <?= (int)$balance['annual_entitled'] ?> days
                                </b>
                            </li>
                            <li>
                                <span>Used</span>
                                <b><?= (int)$balance['annual_used'] ?> days</b>
                            </li>
                            <li>
                                <span>Sick (Full)</span>
                                <b><?= (int)$balance['sick_full_days'] ?> days</b>
                            </li>
                            <li>
                                <span>Sick (Half)</span>
                                <b><?= (int)$balance['sick_half_days'] ?> days</b>
                            </li>
                            <?php if ((int)$balance['maternity_used'] > 0): ?>
                                <li>
                                    <span>Maternity Used</span>
                                    <b style="color: #db2777;"><?= (int)$balance['maternity_used'] ?> days</b>
                                </li>
                            <?php endif; ?>
                            <?php if ((int)$balance['paternity_used'] > 0): ?>
                                <li>
                                    <span>Paternity Used</span>
                                    <b style="color: #2563eb;"><?= (int)$balance['paternity_used'] ?> days</b>
                                </li>
                            <?php endif; ?>
                        </ul>
                        <a href="<?= APP_URL ?>/modules/hr/balances.php?search=<?= urlencode($editUser['full_name']) ?>"
                           class="btn btn-sm btn-ghost"
                           style="width: 100%; margin-top: 12px; border-color: #FDE68A; color: var(--mpc-flame-dark);">
                            View All Balances →
                        </a>
                    <?php else: ?>
                        <p class="muted" style="font-size: 13px; margin: 0;">
                            No balance record found. Saving this form will auto-create one from current policy constants.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Danger zone (only for non-self) -->
            <?php if (!$isSelf): ?>
                <div class="card" style="border-left-color: var(--mpc-red); background: #FEF2F2;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-red); border-bottom: 1px solid #FECACA; padding-bottom: 10px;">
                        ⚠️ Danger Zone
                    </h3>
                    <p class="muted" style="font-size: 12px; margin-bottom: 12px;">
                        Deactivating a user blocks their login but preserves all their data. You can reactivate them anytime.
                    </p>
                    <?php if ($editUser['status'] === 'active'): ?>
                        <a href="user_deactivate.php?id=<?= (int)$editUser['id'] ?>"
                           class="btn btn-danger btn-sm"
                           style="width: 100%;"
                           data-confirm="Deactivate <?= e($editUser['full_name']) ?>? They will not be able to log in.">
                            🚫 Deactivate User
                        </a>
                    <?php else: ?>
                        <a href="user_deactivate.php?id=<?= (int)$editUser['id'] ?>&action=activate"
                           class="btn btn-success btn-sm"
                           style="width: 100%;"
                           data-confirm="Reactivate <?= e($editUser['full_name']) ?>?">
                            ✓ Reactivate User
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- ============================================================
     FIELD TOGGLE SCRIPT
     ============================================================ -->
<script>
    function toggleFields() {
        var typeField = document.getElementById('emp_type');
        if (!typeField) return;

        var type          = typeField.value;
        var staffFields   = document.getElementById('staff_fields');
        var studentFields = document.getElementById('student_fields');

        if (type === 'student') {
            staffFields.style.display   = 'none';
            studentFields.style.display = 'block';
        } else if (type === 'civil_servant' || type === 'contract' || type === 'daily_labor') {
            staffFields.style.display   = 'grid';
            studentFields.style.display = 'none';
        } else {
            staffFields.style.display   = 'none';
            studentFields.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', toggleFields);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>