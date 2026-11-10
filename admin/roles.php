<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   ROLE MANAGEMENT (Admin only)
   ============================================================
   PURPOSE
     Full CRUD for the `roles` table — the RBAC system that
     powers has_role() and can_approve_step() throughout the app.

   THREE MODES (determined by URL)
     1. list   GET roles.php               → table of all roles
     2. new    GET roles.php?action=new    → create form
     3. edit   GET roles.php?id=N          → edit form for role N

   STATE CHANGES (all require POST + CSRF)
     - action=create → INSERT new role, redirect to list
     - action=update → UPDATE existing role, redirect to list
     - action=delete → DELETE role, redirect to list

   GUARD RAILS
     - Admin-only (Auth::requireRole)
     - is_system roles cannot be deleted (would break the app)
     - Roles with assigned users cannot be deleted (would orphan user_roles)
     - Code is immutable after creation (server-side, not just readonly)
     - is_system cannot be toggled on edit (would change deletion semantics mid-life)
     - Post-delete/post-save warnings on user impact

   DEPENDENCIES
     functions.php          — base helpers, format_date
     includes/auth_check.php — Auth::requireRole
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, danger, warning
     includes/audit.php     — Audit::snapshot, Audit::track, Audit::created

   SAFETY
     - Transaction wraps create/update/delete
     - All writes audited
     - No SQL errors exposed to user
     - Delete requires POST + CSRF (no GET state changes)
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
Auth::requireRole('admin', 'You do not have permission to manage roles.');


/* ------------------------------------------------------------
   3. DETERMINE MODE
   ------------------------------------------------------------ */
$mode = 'list';
$id   = (int)($_GET['id'] ?? 0);

if (($_GET['action'] ?? '') === 'new') {
    $mode = 'new';
} elseif ($id > 0) {
    $mode = 'edit';
}


/* ------------------------------------------------------------
   4. HANDLE POST — CREATE / UPDATE / DELETE
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    $postAction = (string)($_POST['action'] ?? '');

    /* ---------- DELETE ---------- */
    if ($postAction === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId <= 0) {
            Flash::danger('Invalid role ID.');
            redirect('roles.php');
        }

        // Load role
        $st = $pdo->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
        $st->execute([$delId]);
        $role = $st->fetch();

        if (!$role) {
            Flash::danger('Role not found.');
            redirect('roles.php');
        }

        // Guard: system role
        if ((int)$role['is_system'] === 1) {
            Flash::danger(
                "The role '" . e($role['name']) . "' is a system role and cannot be deleted. "
                . "Doing so would break permissions across the application."
            );
            redirect('roles.php');
        }

        // Guard: users still assigned
        $st = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
        $st->execute([$delId]);
        $assigned = (int)$st->fetchColumn();

        if ($assigned > 0) {
            Flash::danger(
                "Cannot delete '" . e($role['name']) . "' — " . $assigned . " user(s) are still assigned. "
                . "Remove the role from those users first."
            );
            redirect('user_roles.php?role_id=' . $delId);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$delId]);
            $pdo->commit();

            Audit::deleted('roles', $delId, [
                'code' => $role['code'],
                'name' => $role['name'],
            ]);

            Flash::success("Role '" . e($role['name']) . "' deleted successfully.");
            redirect('roles.php');

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('roles.php delete failed: ' . $ex->getMessage());
            Flash::danger('Could not delete the role. Please try again.');
            redirect('roles.php');
        }
    }

    /* ---------- CREATE / UPDATE ---------- */
    if ($postAction === 'create' || $postAction === 'update') {

        $isEdit = ($postAction === 'update');
        $editId = $isEdit ? (int)($_POST['id'] ?? 0) : 0;

        // Load existing (edit only)
        $existing = null;
        if ($isEdit) {
            $st = $pdo->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
            $st->execute([$editId]);
            $existing = $st->fetch();
            if (!$existing) {
                Flash::danger('Role not found.');
                redirect('roles.php');
            }
        }

        // Capture before state for audit
        $before = $isEdit ? Audit::snapshot('roles', $editId) : null;

        // Collect
        $code        = trim((string)($_POST['code']        ?? ''));
        $name        = trim((string)($_POST['name']        ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $isSystem    = !empty($_POST['is_system']) ? 1 : 0;

        // Normalize code
        $code = strtolower(preg_replace('/[^a-z0-9_]/i', '', $code));

        // Server-side immutability of code on edit
        if ($isEdit) {
            $code     = (string)$existing['code'];
            $isSystem = (int)$existing['is_system'];   // Cannot toggle after creation
        }

        // Validate
        $errors = [];

        if ($code === '') {
            $errors[] = 'Role code is required.';
        } elseif (strlen($code) < 2 || strlen($code) > 40) {
            $errors[] = 'Role code must be 2–40 characters.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            $errors[] = 'Role code must start with a letter and contain only lowercase letters, digits, or underscores.';
        }

        if ($name === '') {
            $errors[] = 'Role name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Role name must be 100 characters or fewer.';
        }

        if ($description !== '' && mb_strlen($description) > 255) {
            $errors[] = 'Description must be 255 characters or fewer.';
        }

        // Code uniqueness (create only — code is immutable on edit)
        if (!$isEdit && $code !== '' && empty($errors)) {
            $st = $pdo->prepare("SELECT id FROM roles WHERE code = ? LIMIT 1");
            $st->execute([$code]);
            if ($st->fetchColumn()) {
                $errors[] = "Role code '{$code}' is already in use.";
            }
        }

        if (!empty($errors)) {
            foreach (array_slice($errors, 0, 4) as $err) {
                Flash::danger($err);
            }
            // Re-render form with submitted values
            $formData = [
                'id'          => $editId,
                'code'        => $_POST['code']        ?? '',
                'name'        => $_POST['name']        ?? '',
                'description' => $_POST['description'] ?? '',
                'is_system'   => $isSystem,
            ];
            // Override $existing so the form renders with user input
            // (edit mode uses $existing; create uses $formData)
            if (!$isEdit) {
                $mode = 'new';
            }
            // Fall through to render (no redirect)
        } else {
            try {
                $pdo->beginTransaction();

                if ($isEdit) {
                    $pdo->prepare("
                        UPDATE roles SET name = ?, description = ?
                        WHERE id = ?
                    ")->execute([
                        $name,
                        $description !== '' ? $description : null,
                        $editId,
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO roles (code, name, description, is_system)
                        VALUES (?, ?, ?, ?)
                    ")->execute([
                        $code,
                        $name,
                        $description !== '' ? $description : null,
                        $isSystem,
                    ]);
                    $editId = (int)$pdo->lastInsertId();
                }

                $pdo->commit();

                // Audit
                if ($isEdit) {
                    $after = Audit::snapshot('roles', $editId);
                    if ($before && $after) {
                        $diff = Audit::track('roles', $editId, $before, $after);
                        if (empty($diff['old']) && empty($diff['new'])) {
                            Flash::warning('No changes were made.');
                        } else {
                            $changed = count($diff['new']);
                            Flash::success(
                                "Role '<strong>" . e($name) . "</strong>' updated ({$changed} field"
                                . ($changed === 1 ? '' : 's') . " changed)."
                            );
                        }
                    } else {
                        Flash::success("Role updated successfully.");
                    }
                } else {
                    Audit::created('roles', $editId, [
                        'code'      => $code,
                        'name'      => $name,
                        'is_system' => $isSystem,
                    ]);
                    Flash::success(
                        "Role '<strong>" . e($name) . "</strong>' created with code <code>" . e($code) . "</code>."
                    );
                }

                redirect('roles.php');

            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('roles.php save failed: ' . $ex->getMessage());

                if ($ex instanceof PDOException && $ex->getCode() === '23000') {
                    Flash::danger("A role with this code already exists.");
                } else {
                    Flash::danger('Could not save the role. Please try again or contact IT.');
                }
            }
        }
    }
}


/* ------------------------------------------------------------
   5. HANDLE FILTERS / SORT (list mode)
   ------------------------------------------------------------ */
$filters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'system' => (string)($_GET['system'] ?? ''),   // '' | 'yes' | 'no'
];

$sortKeyMap = [
    'default'   => "r.is_system DESC, r.name ASC",
    'name'      => 'r.name ASC',
    'name_desc' => 'r.name DESC',
    'code'      => 'r.code ASC',
    'users'     => 'user_count DESC, r.name ASC',
    'created'   => 'r.created_at DESC',
];
$sortKey = (string)($_GET['sort'] ?? 'default');
$orderBy = $sortKeyMap[$sortKey] ?? $sortKeyMap['default'];

$whereParts = ['1=1'];
$args       = [];

if ($filters['search'] !== '') {
    $like = '%' . $filters['search'] . '%';
    $whereParts[] = '(r.code LIKE ? OR r.name LIKE ? OR r.description LIKE ?)';
    array_push($args, $like, $like, $like);
}
if ($filters['system'] === 'yes') {
    $whereParts[] = 'r.is_system = 1';
} elseif ($filters['system'] === 'no') {
    $whereParts[] = 'r.is_system = 0';
}

$whereSql = implode(' AND ', $whereParts);


/* ------------------------------------------------------------
   6. PAGINATION (list mode)
   ------------------------------------------------------------ */
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

$st = $pdo->prepare("SELECT COUNT(*) FROM roles r WHERE {$whereSql}");
$st->execute($args);
$total = (int)$st->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;


/* ------------------------------------------------------------
   7. FETCH ROLES (list mode)
   ------------------------------------------------------------ */
$roles = [];
if ($mode === 'list') {
    $st = $pdo->prepare("
        SELECT r.*,
               (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
        FROM roles r
        WHERE {$whereSql}
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $st->execute($args);
    $roles = $st->fetchAll();
}


/* ------------------------------------------------------------
   8. SUMMARY STATS (list mode)
   ------------------------------------------------------------ */
$summary = ['total' => 0, 'system' => 0, 'custom' => 0, 'assigned' => 0];
if ($mode === 'list') {
    try {
        $summary = $pdo->query("
            SELECT
                COUNT(*)                          AS total,
                SUM(is_system = 1)                AS system,
                SUM(is_system = 0)                AS custom,
                (SELECT COUNT(*) FROM user_roles) AS assigned
            FROM roles
        ")->fetch() ?: $summary;
    } catch (Throwable $e) { /* silent */ }
}


/* ------------------------------------------------------------
   9. HELPERS
   ------------------------------------------------------------ */
$buildUrl = function (array $overrides = []) use ($filters, $sortKey): string {
    $params = array_merge([
        'search' => $filters['search'],
        'system' => $filters['system'],
        'sort'   => $sortKey,
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'roles.php' . ($params ? '?' . http_build_query($params) : '');
};

$hasFilters = $filters['search'] !== '' || $filters['system'] !== '';


/* ------------------------------------------------------------
   10. LOAD EDIT TARGET (edit mode)
   ------------------------------------------------------------ */
$editRole = null;
$editUserCount = 0;
if ($mode === 'edit') {
    $st = $pdo->prepare("
        SELECT r.*,
               (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
        FROM roles r
        WHERE r.id = ?
        LIMIT 1
    ");
    $st->execute([$id]);
    $editRole = $st->fetch();

    if (!$editRole) {
        Flash::danger('Role not found.');
        redirect('roles.php');
    }

    $editUserCount = (int)$editRole['user_count'];
}


/* ------------------------------------------------------------
   11. RENDER
   ------------------------------------------------------------ */
$pageTitle = $mode === 'new' ? 'New Role' : ($mode === 'edit' ? 'Edit Role' : 'Roles');
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

<?php if ($mode === 'list'): ?>

    <!-- ============================================================
         LIST VIEW
         ============================================================ -->

    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Roles (ሚናዎች)</h1>
            <p class="muted" style="margin-top: 5px;">
                Role definitions used throughout the permission system.
            </p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="user_roles.php" class="btn btn-ghost" style="border-color: var(--mpc-navy); color: var(--mpc-navy);">
                👥 Assign Roles to Users
            </a>
            <a href="roles.php?action=new" class="btn btn-primary">
                + New Role (አዲስ ሚና)
            </a>
        </div>
    </div>

    <!-- Summary chips -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🎭</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= (int)$summary['total'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Total</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #6366f1; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🔒</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #6366f1; line-height: 1;"><?= (int)$summary['system'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">System</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-flame); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">✏️</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-flame); line-height: 1;"><?= (int)$summary['custom'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Custom</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #16a34a; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">👥</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #16a34a; line-height: 1;"><?= (int)$summary['assigned'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Assignments</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card" style="padding: 20px; margin-bottom: 20px; border-left-color: var(--mpc-flame);">
        <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="flex: 3; min-width: 220px; margin-bottom: 0;">
                <label>Search (ፈልግ)</label>
                <input type="text" name="search" placeholder="Search by code, name, or description..."
                       value="<?= e($filters['search']) ?>">
            </div>
            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                <label>Type</label>
                <select name="system">
                    <option value="">All Roles</option>
                    <option value="yes" <?= $filters['system'] === 'yes' ? 'selected' : '' ?>>System only</option>
                    <option value="no"  <?= $filters['system'] === 'no'  ? 'selected' : '' ?>>Custom only</option>
                </select>
            </div>
            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                <label>Sort by</label>
                <select name="sort">
                    <option value="default"   <?= $sortKey === 'default'   ? 'selected' : '' ?>>System first</option>
                    <option value="name"      <?= $sortKey === 'name'      ? 'selected' : '' ?>>Name (A → Z)</option>
                    <option value="name_desc" <?= $sortKey === 'name_desc' ? 'selected' : '' ?>>Name (Z → A)</option>
                    <option value="code"      <?= $sortKey === 'code'      ? 'selected' : '' ?>>Code</option>
                    <option value="users"     <?= $sortKey === 'users'     ? 'selected' : '' ?>>User count</option>
                    <option value="created"   <?= $sortKey === 'created'   ? 'selected' : '' ?>>Newest first</option>
                </select>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($hasFilters): ?>
                    <a href="roles.php" class="btn btn-ghost" style="border-color: #ccc;">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Results count -->
    <?php if ($total > 0): ?>
        <p class="muted" style="margin: 0 0 10px; font-size: 13px; padding: 0 4px;">
            Showing <strong><?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?></strong> of <strong><?= (int)$total ?></strong> roles
        </p>
    <?php endif; ?>

    <!-- Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Users</th>
                        <th>Type</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r):
                        $isSystem   = (int)$r['is_system'] === 1;
                        $userCount  = (int)$r['user_count'];
                    ?>
                    <tr>
                        <td><code style="font-weight: 700; color: var(--mpc-navy); font-size: 13px;"><?= e($r['code']) ?></code></td>
                        <td><strong><?= e($r['name']) ?></strong></td>
                        <td>
                            <?php if (!empty($r['description'])): ?>
                                <span class="muted" style="font-size: 13px;"><?= e($r['description']) ?></span>
                            <?php else: ?>
                                <span class="muted" style="opacity: 0.5;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($userCount > 0): ?>
                                <a href="user_roles.php?role_id=<?= (int)$r['id'] ?>"
                                   style="color: var(--mpc-blue); font-weight: 700; font-size: 14px; text-decoration: none;"
                                   title="View users with this role">
                                    <?= $userCount ?> →
                                </a>
                            <?php else: ?>
                                <span class="muted" style="font-size: 13px;">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isSystem): ?>
                                <span class="badge badge-primary">🔒 System</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Custom</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="muted"><?= !empty($r['created_at']) ? format_date($r['created_at']) : '—' ?></small>
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <a href="roles.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary" title="Edit role">
                                    ✏️ Edit
                                </a>
                                <?php if (!$isSystem && $userCount === 0): ?>
                                    <form method="post" style="display: inline;"
                                          data-confirm="Delete role '<?= e($r['name']) ?>'? This cannot be undone.">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete role">
                                            🗑️ Delete
                                        </button>
                                    </form>
                                <?php elseif ($isSystem): ?>
                                    <span class="muted" style="font-size: 11px; padding: 6px 10px; border: 1px dashed #ddd; border-radius: 4px;" title="System roles cannot be deleted">
                                        🔒 Locked
                                    </span>
                                <?php else: ?>
                                    <span class="muted" style="font-size: 11px; padding: 6px 10px; border: 1px dashed #ddd; border-radius: 4px;" title="Remove the role from all users first">
                                        <?= $userCount ?> users
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($roles)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 50px;">
                            <div style="font-size: 48px; margin-bottom: 15px;">🎭</div>
                            <?php if ($hasFilters): ?>
                                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No roles match these filters.</h3>
                                <a href="roles.php" class="btn btn-ghost" style="border-color: #ccc; margin-top: 12px;">Clear Filters</a>
                            <?php else: ?>
                                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No roles defined.</h3>
                                <a href="roles.php?action=new" class="btn btn-primary" style="margin-top: 12px;">+ Create First Role</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; flex-wrap: wrap;">
        <?php if ($page > 1): ?>
            <a href="<?= e($buildUrl(['page' => 1])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">« First</a>
            <a href="<?= e($buildUrl(['page' => $page - 1])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">‹ Prev</a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <?php if ($i === $page): ?>
                <span class="btn btn-primary btn-sm" style="cursor: default;"><?= $i ?></span>
            <?php else: ?>
                <a href="<?= e($buildUrl(['page' => $i])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="<?= e($buildUrl(['page' => $page + 1])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">Next ›</a>
            <a href="<?= e($buildUrl(['page' => $totalPages])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">Last »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php else: ?>

    <!-- ============================================================
         NEW / EDIT FORM
         ============================================================ -->

    <?php
    $isEdit = ($mode === 'edit');
    $form   = $isEdit ? $editRole : [
        'id'          => 0,
        'code'        => '',
        'name'        => '',
        'description' => '',
        'is_system'   => 0,
    ];

    // Override from POST if validation failed (fall through from handler)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$isEdit) {
        $form['code']        = $_POST['code']        ?? '';
        $form['name']        = $_POST['name']        ?? '';
        $form['description'] = $_POST['description'] ?? '';
        $form['is_system']   = isset($_POST['is_system']) ? 1 : 0;
    }
    ?>

    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">
                <?= $isEdit ? 'Edit Role' : 'Create New Role' ?>
            </h1>
            <p class="muted" style="margin-top: 5px;">
                <?= $isEdit
                    ? 'Update the name or description. Code and type are immutable.'
                    : 'Define a new role that can be assigned to users.' ?>
            </p>
        </div>
        <a href="roles.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to Roles
        </a>
    </div>

    <div class="grid-2" style="grid-template-columns: 2fr 1fr; align-items: start;">

        <!-- Form -->
        <div class="card" style="padding: 30px;">
            <form method="post" class="stack">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                <?php endif; ?>

                <!-- Code -->
                <div class="form-group">
                    <label for="code">
                        Role Code *
                        <?php if ($isEdit): ?>
                            <span class="badge badge-warning" style="margin-left: 6px; font-size: 10px;">Immutable</span>
                        <?php endif; ?>
                    </label>
                    <input type="text" name="code" id="code"
                           required
                           minlength="2" maxlength="40"
                           pattern="[a-z][a-z0-9_]*"
                           placeholder="e.g. records_officer, sports_coordinator"
                           value="<?= e($form['code']) ?>"
                           <?= $isEdit ? 'readonly style="background:#f3f4f6; cursor: not-allowed;"' : '' ?>>
                    <?php if ($isEdit): ?>
                        <small class="muted">Code cannot be changed after creation.</small>
                    <?php else: ?>
                        <small class="muted">Lowercase letters, digits, underscores. Must start with a letter (2–40 chars).</small>
                    <?php endif; ?>
                </div>

                <!-- Name -->
                <div class="form-group">
                    <label for="name">Role Name *</label>
                    <input type="text" name="name" id="name"
                           required maxlength="100"
                           placeholder="e.g. Records Officer"
                           value="<?= e($form['name']) ?>">
                    <small class="muted">Human-readable label, shown in the UI.</small>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description"
                              maxlength="255" rows="3"
                              placeholder="What this role grants access to..."><?= e($form['description']) ?></textarea>
                    <small class="muted">Optional. Shown on the roles list.</small>
                </div>

                <!-- System flag -->
                <div class="form-group" style="background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px solid #eee;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: <?= $isEdit ? 'not-allowed' : 'pointer' ?>; margin-bottom: 0;">
                        <input type="checkbox" name="is_system" value="1"
                               <?= $form['is_system'] ? 'checked' : '' ?>
                               <?= $isEdit ? 'disabled' : '' ?>
                               style="width: 20px; height: 20px; accent-color: var(--mpc-navy);">
                        <span>
                            <strong>System Role</strong>
                            <br><small class="muted" style="font-weight: normal;">
                                <?php if ($isEdit): ?>
                                    This is a <strong><?= $form['is_system'] ? 'system' : 'custom' ?></strong> role. The flag cannot be changed after creation.
                                <?php else: ?>
                                    System roles are protected — they cannot be deleted, and are used by hard-coded permission checks throughout the app. Only check this for infrastructure roles.
                                <?php endif; ?>
                            </small>
                        </span>
                    </label>
                </div>

                <!-- Actions -->
                <div style="margin-top: 25px; border-top: 1px solid #eee; padding-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary btn-lg" style="flex: 2;">
                        <?= $isEdit ? '💾 Save Changes' : '➕ Create Role' ?>
                    </button>
                    <a href="roles.php" class="btn btn-ghost btn-lg" style="flex: 1; border-color: #ccc; color: #333;">
                        Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- Sidebar -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <?php if ($isEdit && $editUserCount > 0): ?>
                <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #BAE6FD; padding-bottom: 10px;">
                        👥 Assigned Users
                    </h3>
                    <p style="font-size: 13px; margin: 0; color: #374151;">
                        This role is currently assigned to <strong><?= $editUserCount ?></strong> user<?= $editUserCount === 1 ? '' : 's' ?>.
                    </p>
                    <a href="user_roles.php?role_id=<?= (int)$form['id'] ?>"
                       class="btn btn-sm btn-ghost"
                       style="width: 100%; margin-top: 12px; border-color: #BAE6FD; color: var(--mpc-blue);">
                        View &amp; Manage Assignments →
                    </a>
                </div>
            <?php endif; ?>

            <div class="card" style="border-left-color: var(--mpc-flame); background: #FFFBEB;">
                <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-flame-dark); border-bottom: 1px solid #FDE68A; padding-bottom: 10px;">
                    💡 How Roles Work
                </h3>
                <ul style="font-size: 12px; margin: 0; padding-left: 16px; color: #374151; line-height: 1.8;">
                    <li>A user can have <strong>multiple roles</strong></li>
                    <li><code>has_role('code')</code> checks if the user has that role</li>
                    <li>Roles control <strong>approval steps</strong>, admin pages, and menu items</li>
                    <li>Assign roles to users via <a href="user_roles.php" style="color: var(--mpc-blue);">User Roles</a></li>
                    <li>Deleting a role with assigned users is <strong>blocked</strong></li>
                </ul>
            </div>

            <?php if ($isEdit && $form['is_system']): ?>
                <div class="card" style="border-left-color: #6366f1; background: #F5F3FF;">
                    <h3 style="margin-top: 0; font-size: 15px; color: #4F46E5; border-bottom: 1px solid #DDD6FE; padding-bottom: 10px;">
                        🔒 System Role
                    </h3>
                    <p style="font-size: 12px; margin: 0; color: #4B5563; line-height: 1.7;">
                        This role is used by hard-coded permission checks. It cannot be deleted even when no users are assigned. To hide it without breaking the app, rename it — but the <code>code</code> stays the same.
                    </p>
                </div>
            <?php endif; ?>

        </div>
    </div>

<?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>