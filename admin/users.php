<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   USER MANAGEMENT (Admin only)
   ============================================================
   PURPOSE
     List, filter, sort, paginate, and manage every user in
     the system (staff + students). Admin-only.

   FEATURES
     - Search by name / username / employee_code / student_code
     - Filter by type (staff / student)
     - Filter by status (active / inactive / suspended / retired / resigned)
     - Filter by department
     - Sort by status / name / department / last login / newest / oldest
     - Pagination (50 per page)
     - CSV export (respects current filters + sort)
     - Summary stats (org-wide, not affected by filters)
     - "You" badge on the current user's row
     - Self-deactivation button hidden (server-side guard in user_deactivate.php)
     - Edit + activate/deactivate actions preserved

   DEPENDENCIES
     functions.php          — base helpers
     includes/auth_check.php — Auth::requireRole
     includes/flash.php      — Flash::success
     includes/audit.php      — Audit::exported (CSV export event)

   SAFETY
     - Admin role enforced at entry
     - All SQL uses prepared statements
     - CSV output uses fputcsv (no injection surface)
     - Excel-compatible (UTF-8 BOM prepended)
   ============================================================ */


/* ------------------------------------------------------------
   1. AUTH
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';

$user = Auth::require();
Auth::requireRole('admin', 'You do not have permission to access the User Management module.');


/* ------------------------------------------------------------
   2. COLLECT FILTERS
   ------------------------------------------------------------ */
$filters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'type'   => (string)($_GET['type']   ?? ''),   // staff|student
    'status' => (string)($_GET['status'] ?? ''),   // active|inactive|...
    'dept'   => (int)   ($_GET['dept']   ?? 0),
];

$sortKeyMap = [
    'status'      => "FIELD(u.status, 'active', 'inactive', 'suspended', 'retired', 'resigned'), u.full_name ASC",
    'name'        => 'u.full_name ASC',
    'name_desc'   => 'u.full_name DESC',
    'dept'        => 'd.name ASC, u.full_name ASC',
    'login'       => 'u.last_login_at DESC',
    'created'     => 'u.created_at DESC',
    'created_asc' => 'u.created_at ASC',
];
$sortKey = (string)($_GET['sort'] ?? 'status');
$orderBy = $sortKeyMap[$sortKey] ?? $sortKeyMap['status'];


/* ------------------------------------------------------------
   3. BUILD WHERE CLAUSE
   ------------------------------------------------------------ */
$whereParts = ['u.deleted_at IS NULL'];
$args       = [];

if ($filters['search'] !== '') {
    $like = '%' . $filters['search'] . '%';
    $whereParts[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.employee_code LIKE ? OR u.student_code LIKE ?)';
    array_push($args, $like, $like, $like, $like);
}
if ($filters['type'] === 'staff') {
    $whereParts[] = "u.employment_type != 'student'";
} elseif ($filters['type'] === 'student') {
    $whereParts[] = "u.employment_type = 'student'";
}
if ($filters['status'] !== '') {
    $whereParts[] = 'u.status = ?';
    $args[] = $filters['status'];
}
if ($filters['dept'] > 0) {
    $whereParts[] = 'u.department_id = ?';
    $args[] = $filters['dept'];
}

$whereSql = implode(' AND ', $whereParts);


/* ------------------------------------------------------------
   4. CSV EXPORT (returns before rendering HTML)
   ------------------------------------------------------------ */
if (($_GET['export'] ?? '') === 'csv') {
    // Log the export event (best-effort; wrapped in try/catch inside Audit::)
    try {
        Audit::exported('users', ['filters' => array_filter($filters), 'sort' => $sortKey]);
    } catch (Throwable $e) {
        // Non-blocking — export still proceeds
    }

    $st = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.email, u.phone,
               u.employee_code, u.student_code, u.employment_type, u.position,
               u.gender, u.status, u.hire_date, u.last_login_at,
               d.name AS dept_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE {$whereSql}
        ORDER BY {$orderBy}
    ");
    $st->execute($args);
    $rows = $st->fetchAll();

    $filename = 'users_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    // UTF-8 BOM for Excel compatibility with Amharic
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID', 'Username', 'Full Name', 'Email', 'Phone',
        'Employee Code', 'Student Code', 'Type', 'Position',
        'Gender', 'Status', 'Hire Date', 'Last Login', 'Department',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['username'],
            $r['full_name'],
            $r['email']         ?? '',
            $r['phone']         ?? '',
            $r['employee_code'] ?? '',
            $r['student_code']  ?? '',
            $r['employment_type'],
            $r['position']      ?? '',
            $r['gender'],
            $r['status'],
            $r['hire_date']     ?? '',
            $r['last_login_at'] ?? '',
            $r['dept_name']     ?? '',
        ]);
    }
    fclose($out);
    exit;
}


/* ------------------------------------------------------------
   5. PAGINATION
   ------------------------------------------------------------ */
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

$st = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE {$whereSql}");
$st->execute($args);
$total = (int)$st->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;


/* ------------------------------------------------------------
   6. FETCH USERS FOR THE CURRENT PAGE
   ------------------------------------------------------------ */
$st = $pdo->prepare("
    SELECT u.*, d.name AS dept_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE {$whereSql}
    ORDER BY {$orderBy}
    LIMIT {$perPage} OFFSET {$offset}
");
$st->execute($args);
$users = $st->fetchAll();


/* ------------------------------------------------------------
   7. SUMMARY STATS (org-wide, ignores filters)
   ------------------------------------------------------------ */
try {
    $summary = $pdo->query("
        SELECT
            COUNT(*)                              AS total,
            SUM(status = 'active')                AS active,
            SUM(status != 'active')               AS inactive,
            SUM(employment_type = 'student')      AS students,
            SUM(employment_type != 'student')     AS staff
        FROM users
        WHERE deleted_at IS NULL
    ")->fetch() ?: ['total' => 0, 'active' => 0, 'inactive' => 0, 'students' => 0, 'staff' => 0];
} catch (Throwable $e) {
    $summary = ['total' => 0, 'active' => 0, 'inactive' => 0, 'students' => 0, 'staff' => 0];
}


/* ------------------------------------------------------------
   8. DEPARTMENTS FOR FILTER DROPDOWN
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
   9. HELPERS
   ------------------------------------------------------------ */

/** Build a URL preserving all current filters except the changed one. */
$buildUrl = function (array $overrides = []) use ($filters, $sortKey): string {
    $params = array_merge(
        [
            'search' => $filters['search'],
            'type'   => $filters['type'],
            'status' => $filters['status'],
            'dept'   => $filters['dept'] ?: '',
            'sort'   => $sortKey,
        ],
        $overrides
    );

    // Strip empty values
    $params = array_filter($params, fn($v) => $v !== '' && $v !== 0 && $v !== '0' && $v !== null);

    return 'users.php' . ($params ? '?' . http_build_query($params) : '');
};

/** Is any filter active? */
$hasFilters = $filters['search'] !== ''
           || $filters['type'] !== ''
           || $filters['status'] !== ''
           || $filters['dept'] > 0;

/* ------------------------------------------------------------
   10. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'User Management';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1200px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">User Management (የተጠቃሚዎች አስተዳደር)</h1>
            <p class="muted" style="margin-top: 5px;">Manage all college staff, employees, and students.</p>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="departments.php" class="btn btn-ghost" style="border-color: var(--mpc-navy); color: var(--mpc-navy);">
                🏢 Manage Departments
            </a>
            <a href="<?= e($buildUrl(['export' => 'csv', 'page' => ''])) ?>" class="btn btn-ghost" style="border-color: #16a34a; color: #16a34a;">
                📥 Export CSV
            </a>
            <a href="user_new.php" class="btn btn-primary">
                + Add New User (አዲስ ተጠቃሚ)
            </a>
        </div>
    </div>

    <!-- ============================================================
         SUMMARY CHIPS (org-wide, ignores filters)
         ============================================================ -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">👥</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= (int)$summary['total'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Total</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #16a34a; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">✅</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #16a34a; line-height: 1;"><?= (int)$summary['active'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Active</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-red); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">⛔</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-red); line-height: 1;"><?= (int)$summary['inactive'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Inactive</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-blue); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">👔</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-blue); line-height: 1;"><?= (int)$summary['staff'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Staff</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-flame); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🎓</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-flame); line-height: 1;"><?= (int)$summary['students'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Students</div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         FILTERS
         ============================================================ -->
    <div class="card" style="padding: 20px; margin-bottom: 20px; border-left-color: var(--mpc-flame);">
        <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">

            <div class="form-group" style="flex: 3; min-width: 220px; margin-bottom: 0;">
                <label>Search (ፈልግ)</label>
                <input type="text" name="search" placeholder="Search by name, username, employee/student ID..."
                       value="<?= e($filters['search']) ?>">
            </div>

            <div class="form-group" style="flex: 1; min-width: 130px; margin-bottom: 0;">
                <label>Type</label>
                <select name="type">
                    <option value="">All Types</option>
                    <option value="staff"   <?= $filters['type'] === 'staff'   ? 'selected' : '' ?>>Staff</option>
                    <option value="student" <?= $filters['type'] === 'student' ? 'selected' : '' ?>>Student</option>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 130px; margin-bottom: 0;">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['active', 'inactive', 'suspended', 'retired', 'resigned'] as $s): ?>
                        <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>>
                            <?= ucfirst($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                <label>Department</label>
                <select name="dept">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $filters['dept'] === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 140px; margin-bottom: 0;">
                <label>Sort by</label>
                <select name="sort">
                    <option value="status"      <?= $sortKey === 'status'      ? 'selected' : '' ?>>Status (active first)</option>
                    <option value="name"        <?= $sortKey === 'name'        ? 'selected' : '' ?>>Name (A → Z)</option>
                    <option value="name_desc"   <?= $sortKey === 'name_desc'   ? 'selected' : '' ?>>Name (Z → A)</option>
                    <option value="dept"        <?= $sortKey === 'dept'        ? 'selected' : '' ?>>Department</option>
                    <option value="login"       <?= $sortKey === 'login'       ? 'selected' : '' ?>>Last login</option>
                    <option value="created"     <?= $sortKey === 'created'     ? 'selected' : '' ?>>Newest first</option>
                    <option value="created_asc" <?= $sortKey === 'created_asc' ? 'selected' : '' ?>>Oldest first</option>
                </select>
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($hasFilters): ?>
                    <a href="users.php" class="btn btn-ghost" style="border-color: #ccc;">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ============================================================
         RESULTS COUNT
         ============================================================ -->
    <?php if ($total > 0): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding: 0 4px; flex-wrap: wrap; gap: 8px;">
            <p class="muted" style="margin: 0; font-size: 13px;">
                Showing <strong><?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?></strong> of <strong><?= (int)$total ?></strong> users
                <?php if ($hasFilters): ?>
                    · <a href="users.php" style="color: var(--mpc-blue);">Clear filters</a>
                <?php endif; ?>
            </p>
            <?php if ($totalPages > 1): ?>
                <p class="muted" style="margin: 0; font-size: 13px;">Page <?= $page ?> of <?= $totalPages ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ============================================================
         USERS TABLE
         ============================================================ -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name (ስም)</th>
                        <th>Username (የተጠቃሚ ስም)</th>
                        <th>Role / Position (የስራ ድርሻ)</th>
                        <th>Department (ክፍል)</th>
                        <th>Type (አይነት)</th>
                        <th>Status (ሁኔታ)</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $isSelf = ((int)$u['id'] === (int)$user['id']);
                    ?>
                    <tr>
                        <!-- Name & ID -->
                        <td>
                            <strong><?= e($u['full_name']) ?></strong>
                            <?php if ($isSelf): ?>
                                <span class="badge badge-primary" style="margin-left: 6px; font-size: 10px;">You</span>
                            <?php endif; ?>
                            <br>
                            <small class="muted">
                                <?php if ($u['employment_type'] === 'student'): ?>
                                    ID: <?= e($u['student_code'] ?? '—') ?>
                                <?php else: ?>
                                    ID: <?= e($u['employee_code'] ?? '—') ?>
                                <?php endif; ?>
                            </small>
                        </td>

                        <!-- Username -->
                        <td><code><?= e($u['username']) ?></code></td>

                        <!-- Position + Email -->
                        <td>
                            <?= e($u['position'] ?: 'N/A') ?><br>
                            <small class="muted"><?= e($u['email'] ?: 'No email') ?></small>
                        </td>

                        <!-- Department -->
                        <td><?= e($u['dept_name'] ?: 'N/A') ?></td>

                        <!-- Type Badge -->
                        <td>
                            <?php if ($u['employment_type'] === 'student'): ?>
                                <span class="badge badge-info">Student</span>
                            <?php elseif ($u['employment_type'] === 'civil_servant'): ?>
                                <span class="badge badge-secondary">Civil Servant</span>
                            <?php elseif ($u['employment_type'] === 'contract'): ?>
                                <span class="badge badge-warning">Contract</span>
                            <?php else: ?>
                                <span class="badge badge-secondary"><?= e(ucfirst($u['employment_type'])) ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Status Badge -->
                        <td><?= status_badge($u['status']) ?></td>

                        <!-- Last Login (Ethiopian + Gregorian tooltip) -->
                        <td>
                            <?php if ($u['last_login_at']): ?>
                                <div style="font-weight: 500; font-size: 13px;">
                                    <?= format_date($u['last_login_at'], true) ?>
                                </div>
                                <small class="muted" title="<?= date('M j, Y \a\t H:i', strtotime($u['last_login_at'])) ?>">
                                    <?= date('M j, Y', strtotime($u['last_login_at'])) ?>
                                </small>
                            <?php else: ?>
                                <span class="muted">Never</span>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <a href="user_edit.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-primary" title="Edit user">
                                    ✏️ Edit
                                </a>

                                <?php if ($isSelf): ?>
                                    <!-- Cannot deactivate self -->
                                    <span class="muted" style="font-size: 12px; padding: 6px 12px;" title="You cannot deactivate your own account">
                                        🚫 (You)
                                    </span>
                                <?php elseif ($u['status'] === 'active'): ?>
                                    <a href="user_deactivate.php?id=<?= (int)$u['id'] ?>"
                                       class="btn btn-sm btn-danger"
                                       data-confirm="Are you sure you want to deactivate <?= e($u['full_name']) ?>? They will not be able to log in."
                                       title="Deactivate user">
                                        🚫 Deactivate
                                    </a>
                                <?php else: ?>
                                    <a href="user_deactivate.php?id=<?= (int)$u['id'] ?>&action=activate"
                                       class="btn btn-sm btn-success"
                                       data-confirm="Reactivate <?= e($u['full_name']) ?>?"
                                       title="Reactivate user">
                                        ✓ Activate
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Empty State -->
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 50px;">
                            <div style="font-size: 48px; margin-bottom: 15px;">👥</div>
                            <?php if ($hasFilters): ?>
                                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No users match these filters.</h3>
                                <p class="muted" style="font-size: 14px; margin-bottom: 20px;">
                                    Try adjusting your search or clearing the filters.
                                </p>
                                <a href="users.php" class="btn btn-ghost" style="border-color: #ccc;">Clear Filters</a>
                            <?php else: ?>
                                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No users yet.</h3>
                                <p class="muted" style="font-size: 14px; margin-bottom: 20px;">
                                    Get started by adding the first user.
                                </p>
                                <a href="user_new.php" class="btn btn-primary">+ Add First User</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================
         PAGINATION
         ============================================================ -->
    <?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; flex-wrap: wrap;">
        <?php
        $windowSize = 2; // show 2 pages on either side of current
        $startPage  = max(1, $page - $windowSize);
        $endPage    = min($totalPages, $page + $windowSize);
        ?>

        <!-- First -->
        <?php if ($page > 1): ?>
            <a href="<?= e($buildUrl(['page' => 1])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">« First</a>
            <a href="<?= e($buildUrl(['page' => $page - 1])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">‹ Prev</a>
        <?php endif; ?>

        <!-- Numbers -->
        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="btn btn-primary btn-sm" aria-current="page" style="cursor: default;"><?= $i ?></span>
            <?php else: ?>
                <a href="<?= e($buildUrl(['page' => $i])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <!-- Next / Last -->
        <?php if ($page < $totalPages): ?>
            <a href="<?= e($buildUrl(['page' => $page + 1])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">Next ›</a>
            <a href="<?= e($buildUrl(['page' => $totalPages])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">Last »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>