<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   CREATE NEW USER (Admin only)
   ============================================================
   PURPOSE
     Form and handler for creating new users (staff or students).

   FEATURES
     - Server-side validation: required fields, uniqueness,
       format (email, phone), password strength, password match
     - Client-side validation via validation.js:
       data-strength on password, data-match on confirm, phone
       auto-validation via name="phone", email auto-validation
     - HR balance row auto-created for new staff (from current
       policy_constants via Policy class)
     - Fiscal year derived from existing hr_balances rows (or
       computed from date if table is empty)
     - Transaction wraps user insert + balance insert
     - Audit::created() logged only after commit
     - Type switching (staff vs. student) preserves toggleFields()

   DEPENDENCIES
     functions.php          — base helpers
     includes/auth_check.php — Auth::requireRole
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, Flash::danger
     includes/audit.php     — Audit::created
     includes/policy.php    — Policy::annualLeaveBaseDays, etc.

   SAFETY
     - Admin-only access enforced before any DB access
     - CSRF required on POST
     - No SQL error messages exposed to the user
     - No password hashing leak in audit log
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
   3. HELPERS
   ------------------------------------------------------------ */

/**
 * Determine the fiscal year label to use for a new hr_balances row.
 * Prefers the most common existing label in the table (to keep data
 * consistent with seeded rows), falling back to a computed value
 * from the current date if the table is empty.
 */
function mpc_current_fiscal_year(PDO $pdo): string
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

    // Compute: fiscal year starts Hamle 1 (approx July 8 GC)
    $now   = time();
    $year  = (int)date('Y', $now);
    $july8 = mktime(0, 0, 0, 7, 8, $year);
    if ($now >= $july8) {
        return $year . '-' . str_pad((string)(($year + 1) % 100), 2, '0', STR_PAD_LEFT);
    }
    return ($year - 1) . '-' . str_pad((string)($year % 100), 2, '0', STR_PAD_LEFT);
}


/* ------------------------------------------------------------
   4. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    // ---- Collect inputs (trimmed) ----
    $username         = trim((string)($_POST['username']         ?? ''));
    $password         =       (string)($_POST['password']         ?? '');
    $password_confirm =       (string)($_POST['password_confirm'] ?? '');
    $full_name        = trim((string)($_POST['full_name']        ?? ''));
    $email            = trim((string)($_POST['email']            ?? ''));
    $phone            = trim((string)($_POST['phone']            ?? ''));
    $gender           = (string)($_POST['gender']                ?? 'male');
    $emp_type         = (string)($_POST['employment_type']       ?? '');
    $dept_id          = (int)   ($_POST['department_id']         ?? 0);
    $position         = trim((string)($_POST['position']         ?? ''));

    $employee_code    = trim((string)($_POST['employee_code']    ?? ''));
    $student_code     = trim((string)($_POST['student_code']     ?? ''));
    $hire_date        = trim((string)($_POST['hire_date']        ?? ''));
    $birth_date       = trim((string)($_POST['birth_date']       ?? ''));
    $salary_raw       = trim((string)($_POST['salary']           ?? ''));
    $salary           = $salary_raw !== '' ? (float)$salary_raw : null;

    // ---- Normalize: only keep fields relevant to the type ----
    if ($emp_type === 'student') {
        $employee_code = '';
        $hire_date     = '';
        $salary        = null;
    } else {
        $student_code = '';
    }

    // Nullable conversions
    $dept_id      = $dept_id > 0            ? $dept_id      : null;
    $email        = $email        !== ''    ? $email        : null;
    $phone        = $phone        !== ''    ? $phone        : null;
    $position     = $position     !== ''    ? $position     : null;
    $employee_code= $employee_code!== ''    ? $employee_code: null;
    $student_code = $student_code !== ''    ? $student_code : null;
    $hire_date    = $hire_date    !== ''    ? $hire_date    : null;
    $birth_date   = $birth_date   !== ''    ? $birth_date   : null;

    // ---- Validate ----
    $errors = [];

    // Required
    if ($username === '')  $errors[] = 'Username is required.';
    if ($password === '')  $errors[] = 'Password is required.';
    if ($full_name === '') $errors[] = 'Full name is required.';
    if (!in_array($emp_type, ['civil_servant', 'contract', 'daily_labor', 'student'], true)) {
        $errors[] = 'Please select a valid user type.';
    }

    // Username format (letters, digits, dots, underscores, hyphens)
    if ($username !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Username must be 3–60 characters and contain only letters, digits, dots, underscores, or hyphens.';
    }

    // Password strength (server-side, matches client-side rules)
    if ($password !== '' && strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== '' && (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))) {
        $errors[] = 'Password must contain at least one letter and one number.';
    }

    // Password match
    if ($password !== '' && $password_confirm !== '' && $password !== $password_confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Email format
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Phone format (Ethiopian)
    if ($phone !== null) {
        $cleanPhone = preg_replace('/[\s\-\.\(\)]/', '', $phone);
        if (!preg_match('/^(?:\+?251|0)(?:9|7)\d{8}$/', $cleanPhone)
            && !preg_match('/^(?:\+?251|0)(?:1|2|3|4|5|6|8)\d{8}$/', $cleanPhone)) {
            $errors[] = 'Please enter a valid Ethiopian phone number (e.g. 0911223344 or +251911223344).';
        }
    }

    // Dates (basic)
    if ($birth_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        $errors[] = 'Birth date must be in YYYY-MM-DD format.';
    }
    if ($hire_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date)) {
        $errors[] = 'Hire date must be in YYYY-MM-DD format.';
    }

    // Uniqueness checks (skip if already had format errors — avoids DB round-trip)
    $skipDbChecks = false;
    foreach ($errors as $e) {
        if (str_contains($e, 'Username must be')) { $skipDbChecks = true; break; }
    }

    if (!$skipDbChecks && $username !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $st->execute([$username]);
        if ($st->fetchColumn()) {
            $errors[] = 'Username is already taken. Please choose another.';
        }
    }

    if (!$skipDbChecks && $employee_code !== null) {
        $st = $pdo->prepare("SELECT id FROM users WHERE employee_code = ? LIMIT 1");
        $st->execute([$employee_code]);
        if ($st->fetchColumn()) {
            $errors[] = 'Employee code is already in use.';
        }
    }

    if (!$skipDbChecks && $student_code !== null) {
        $st = $pdo->prepare("SELECT id FROM users WHERE student_code = ? LIMIT 1");
        $st->execute([$student_code]);
        if ($st->fetchColumn()) {
            $errors[] = 'Student code is already in use.';
        }
    }

    // ---- Persist ----
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // 1. Insert user
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $ins = $pdo->prepare("
                INSERT INTO users
                (username, password_hash, full_name, email, phone, gender, employment_type,
                 department_id, position, employee_code, student_code, hire_date, birth_date,
                 salary, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $ins->execute([
                $username, $hash, $full_name, $email, $phone, $gender, $emp_type,
                $dept_id, $position, $employee_code, $student_code, $hire_date, $birth_date,
                $salary,
            ]);

            $new_id = (int)$pdo->lastInsertId();

            // 2. Initialize HR balance for staff (not students)
            if ($emp_type !== 'student') {
                $fiscalYear      = mpc_current_fiscal_year($pdo);
                $annualEntitled  = Policy::annualLeaveBaseDays();
                $sickFull        = Policy::sickLeaveFullDays();
                $sickHalf        = Policy::sickLeaveHalfDays();
                $sickUnpaid      = Policy::sickLeaveUnpaidDays();

                $pdo->prepare("
                    INSERT INTO hr_balances
                    (user_id, fiscal_year, annual_entitled, sick_full_days, sick_half_days, sick_unpaid_days)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$new_id, $fiscalYear, $annualEntitled, $sickFull, $sickHalf, $sickUnpaid]);
            }

            $pdo->commit();

            // 3. Audit (after commit — only logs if persisted)
            Audit::created('users', $new_id, [
                'username'        => $username,
                'full_name'       => $full_name,
                'employment_type' => $emp_type,
            ]);

            Flash::success("User '{$full_name}' created successfully with username '{$username}'.");
            redirect('users.php');

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('user_new.php create failed: ' . $ex->getMessage());

            if ($ex instanceof PDOException && $ex->getCode() === '23000') {
                Flash::danger('A user with this username, employee code, or student code already exists.');
            } else {
                Flash::danger('Could not create the user. Please try again or contact IT.');
            }
            // Fall through to re-render the form with entered values
        }
    } else {
        // Show validation errors (up to 4 to avoid overwhelming)
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
   5. FETCH DEPARTMENTS FOR DROPDOWN
   ------------------------------------------------------------ */
try {
    $departments = $pdo->query("
        SELECT id, name
        FROM departments
        WHERE is_active = 1
        ORDER BY name ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $departments = [];
}


/* ------------------------------------------------------------
   6. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Add New User';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 900px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Add New User (አዲስ ተጠቃሚ)</h1>
            <p class="muted" style="margin-top: 5px;">Create a new staff or student account.</p>
        </div>
        <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to List
        </a>
    </div>

    <!-- ============================================================
         FORM
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
                           placeholder="e.g. abebe.k"
                           autocomplete="off"
                           value="<?= e($_POST['username'] ?? '') ?>">
                    <small class="muted">3–60 chars. Letters, digits, dots, underscores, hyphens.</small>
                </div>

                <div class="form-group">
                    <label for="emp_type">
                        User Type (የተጠቃሚ አይነት) *
                    </label>
                    <select name="employment_type" id="emp_type" required onchange="toggleFields()">
                        <option value="">-- Select Type --</option>
                        <option value="civil_servant" <?= ($_POST['employment_type'] ?? '') === 'civil_servant' ? 'selected' : '' ?>>
                            Civil Servant (ቋሚ ሰራተኛ)
                        </option>
                        <option value="contract" <?= ($_POST['employment_type'] ?? '') === 'contract' ? 'selected' : '' ?>>
                            Contract Employee (ኮንትራት)
                        </option>
                        <option value="daily_labor" <?= ($_POST['employment_type'] ?? '') === 'daily_labor' ? 'selected' : '' ?>>
                            Daily Labor (የቀን ሰራተኛ)
                        </option>
                        <option value="student" <?= ($_POST['employment_type'] ?? '') === 'student' ? 'selected' : '' ?>>
                            Student (ተማሪ)
                        </option>
                    </select>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="password">
                        Password (የይለፍ ቃል) *
                    </label>
                    <input type="password" name="password" id="password" required
                           minlength="8"
                           data-strength
                           autocomplete="new-password"
                           placeholder="Min 8 chars, 1 letter + 1 number">
                    <small class="muted">At least 8 characters with at least one letter and one number.</small>
                </div>

                <div class="form-group">
                    <label for="password_confirm">
                        Confirm Password *
                    </label>
                    <input type="password" name="password_confirm" id="password_confirm" required
                           minlength="8"
                           data-match="password"
                           autocomplete="new-password"
                           placeholder="Repeat password">
                    <small class="muted">Must match the password above.</small>
                </div>
            </div>

            <!-- Status (always active on create) -->
            <div class="form-group" style="background: #F0F9FF; padding: 12px 15px; border-radius: 6px; border-left: 3px solid var(--mpc-blue);">
                <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--mpc-navy);">
                    <span style="font-size: 16px;">ℹ️</span>
                    <span>
                        <strong>Status:</strong> New accounts are created as <strong>Active</strong> by default.
                        You can deactivate them later from the user list.
                    </span>
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
                           placeholder="e.g. Abebe Kebede"
                           value="<?= e($_POST['full_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="gender">
                        Gender (ጾታ)
                    </label>
                    <select name="gender" id="gender">
                        <option value="male"   <?= ($_POST['gender'] ?? 'male') === 'male'   ? 'selected' : '' ?>>Male (ወንድ)</option>
                        <option value="female" <?= ($_POST['gender'] ?? 'male') === 'female' ? 'selected' : '' ?>>Female (ሴት)</option>
                    </select>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="email">
                        Email Address
                    </label>
                    <input type="email" name="email" id="email"
                           maxlength="150"
                           placeholder="e.g. abebe@misrak.edu.et"
                           value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="phone">
                        Phone Number
                    </label>
                    <input type="text" name="phone" id="phone"
                           maxlength="30"
                           placeholder="e.g. 0911223344"
                           value="<?= e($_POST['phone'] ?? '') ?>">
                    <small class="muted">Ethiopian format: 0911223344, 0711223344, or +251...</small>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="birth_date">
                        Birth Date (የትውልድ ቀን)
                    </label>
                    <input type="date" name="birth_date" id="birth_date"
                           value="<?= e($_POST['birth_date'] ?? '') ?>">
                </div>
                <div class="form-group"><!-- spacer for grid --></div>
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
                            <option value="<?= (int)$d['id'] ?>" <?= ((int)($_POST['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
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
                           placeholder="e.g. Lecturer / Level 4"
                           value="<?= e($_POST['position'] ?? '') ?>">
                </div>
            </div>

            <!-- ============================================================
                 STAFF FIELDS (only when non-student)
                 ============================================================ -->
            <div id="staff_fields" class="grid-2"
                 style="display: none; background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px dashed #ccc;">
                <div class="form-group">
                    <label for="employee_code">
                        Employee Code (የሰራተኛ መለያ)
                    </label>
                    <input type="text" name="employee_code" id="employee_code"
                           maxlength="40"
                           placeholder="e.g. MPC-0101"
                           value="<?= e($_POST['employee_code'] ?? '') ?>">
                    <small class="muted">Must be unique if provided.</small>
                </div>

                <div class="form-group">
                    <label for="hire_date">
                        Hire Date (የተቀጠሩበት ቀን)
                    </label>
                    <input type="date" name="hire_date" id="hire_date"
                           value="<?= e($_POST['hire_date'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="salary">
                        Monthly Salary (ETB)
                    </label>
                    <input type="number" step="0.01" min="0" name="salary" id="salary"
                           placeholder="e.g. 15000"
                           value="<?= e($_POST['salary'] ?? '') ?>">
                </div>
            </div>

            <!-- ============================================================
                 STUDENT FIELDS (only when student)
                 ============================================================ -->
            <div id="student_fields" class="form-group"
                 style="display: none; background: #E0F2FE; padding: 15px; border-radius: 8px; border: 1px dashed var(--mpc-blue);">
                <label for="student_code">
                    Student Code (የተማሪ መለያ)
                </label>
                <input type="text" name="student_code" id="student_code"
                       maxlength="40"
                       placeholder="e.g. MPC-STU-2023-001"
                       value="<?= e($_POST['student_code'] ?? '') ?>">
                <small class="muted">Must be unique if provided.</small>
            </div>

            <!-- ============================================================
                 ACTIONS
                 ============================================================ -->
            <div style="margin-top: 25px; border-top: 1px solid #eee; padding-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary btn-lg" style="flex: 2;">
                    Create User (ተጠቃሚ ፍጠር)
                </button>
                <a href="users.php" class="btn btn-ghost btn-lg" style="flex: 1; border-color: #ccc; color: #333;">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     FIELD TOGGLE SCRIPT
     Shows staff fields for civil_servant/contract/daily_labor,
     shows student field for student, hides both otherwise.
     ============================================================ -->
<script>
    function toggleFields() {
        var type           = document.getElementById('emp_type').value;
        var staffFields    = document.getElementById('staff_fields');
        var studentFields  = document.getElementById('student_fields');

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

    // Run on page load — shows correct fields based on current selection
    // (important after validation errors when $_POST still holds a type)
    document.addEventListener('DOMContentLoaded', toggleFields);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>