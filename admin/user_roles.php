<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   USER ROLE ASSIGNMENTS (Admin only)
   ============================================================
   PURPOSE
     Assign and unassign roles to users via the user_roles
     junction table. Three modes: list, per-user edit,
     per-role edit.

   THREE MODES
     list       user_roles.php              → all users + role counts
     user_edit  user_roles.php?user_id=N    → toggle roles for user N
     role_edit  user_roles.php?role_id=N    → toggle users for role N

   FEATURES
     - Bulk save: toggle many checkboxes, one transaction
     - Diff-based writes: only INSERT missing / DELETE extra
     - Audit: one Audit::created/deleted per assignment change
     - Self-lockout prevention: cannot remove your own admin role
     - Last-admin prevention: cannot remove the last admin user
     - Cross-links: to roles.php, users.php, employee_profile.php
     - Search + filter in list mode
     - Warning badges: users with no roles, roles with no users

   DEPENDENCIES
     functions.php          — base helpers, status_badge, format_date
     includes/auth_check.php — Auth::requireRole
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, danger, warning, info
     includes/audit.php     — Audit::created, Audit::deleted

   SAFETY
     - Admin-only
     - CSRF required on POST
     - Transaction wraps each save
     - Self and last-admin guards enforced server-side
     - No SQL errors exposed
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
Auth::requireRole('admin', 'You do not have permission to manage role assignments.');


/* ------------------------------------------------------------
   3. LOOK UP THE ADMIN ROLE ID (cached, used for guards)
   ------------------------------------------------------------ */
$adminRoleId = 0;
try {
    $adminRoleId = (int)$pdo->query(
        "SELECT id FROM roles WHERE code = 'admin' LIMIT 1"
    )->fetchColumn();
} catch (Throwable $e) {
    $adminRoleId = 0;
}


/* ------------------------------------------------------------
   4. DETERMINE MODE + LOAD TARGET
   ------------------------------------------------------------ */
$userId = (int)($_GET['user_id'] ?? 0);
$roleId = (int)($_GET['role_id'] ?? 0);

$mode = 'list';
if ($userId > 0)      $mode = 'user_edit';
elseif ($roleId > 0)  $mode = 'role_edit';

$targetUser = null;
$targetRole = null;

if ($mode === 'user_edit') {
    $st = $pdo->prepare("
        SELECT u.*, d.name AS dept_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.id = ? AND u.deleted_at IS NULL
        LIMIT 1
    ");
    $st->execute([$userId]);
    $targetUser = $st->fetch();
    if (!$targetUser) {
        Flash::danger('User not found.');
        redirect('user_roles.php');
    }
} elseif ($mode === 'role_edit') {
    $st = $pdo->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
    $st->execute([$roleId]);
    $targetRole = $st->fetch();
    if (!$targetRole) {
        Flash::danger('Role not found.');
        redirect('roles.php');
    }
}


/* ------------------------------------------------------------
   5. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    $postAction = (string)($_POST['action'] ?? '');

    /* ---------- SAVE USER ROLES ---------- */
    if ($postAction === 'save_user_roles') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            Flash::danger('Invalid user ID.');
            redirect('user_roles.php');
        }

        // Verify user
        $st = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$targetId]);
        $targetUserRow = $st->fetch();
        if (!$targetUserRow) {
            Flash::danger('User not found.');
            redirect('user_roles.php');
        }

        // Collect checked role IDs
        $newRoleIds = array_values(array_unique(array_map(
            'intval',
            $_POST['role_ids'] ?? []
        )));
        $newRoleIds = array_filter($newRoleIds, fn($r) => $r > 0);

        // Verify all role IDs exist
        if (!empty($newRoleIds)) {
            $placeholders = implode(',', array_fill(0, count($newRoleIds), '?'));
            $st = $pdo->prepare("SELECT id FROM roles WHERE id IN ({$placeholders})");
            $st->execute($newRoleIds);
            $validRoleIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $newRoleIds = array_values(array_intersect($newRoleIds, $validRoleIds));
        }

        // Current assignments
        $st = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
        $st->execute([$targetId]);
        $currentRoleIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        // ---- Self-lockout guard ----
        if ($targetId === (int)$user['id']
            && $adminRoleId > 0
            && in_array($adminRoleId, $currentRoleIds, true)
            && !in_array($adminRoleId, $newRoleIds, true)
        ) {
            Flash::danger(
                'Security Error: You cannot remove your own <strong>admin</strong> role. '
                . 'Doing so would lock you out of this page.'
            );
            redirect('user_roles.php?user_id=' . $targetId);
        }

        // ---- Last-admin guard ----
        if ($adminRoleId > 0
            && in_array($adminRoleId, $currentRoleIds, true)
            && !in_array($adminRoleId, $newRoleIds, true)
        ) {
            // Check how many OTHER users have admin
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM user_roles
                WHERE role_id = ? AND user_id != ?
            ");
            $st->execute([$adminRoleId, $targetId]);
            $otherAdmins = (int)$st->fetchColumn();

            if ($otherAdmins === 0) {
                Flash::danger(
                    'Cannot remove the admin role from this user — they are the last remaining administrator. '
                    . 'Assign the admin role to another user first.'
                );
                redirect('user_roles.php?user_id=' . $targetId);
            }
        }

        // Compute diff
        $toAdd    = array_diff($newRoleIds, $currentRoleIds);
        $toRemove = array_diff($currentRoleIds, $newRoleIds);

        if (empty($toAdd) && empty($toRemove)) {
            Flash::warning('No changes were made.');
            redirect('user_roles.php?user_id=' . $targetId);
        }

        try {
            $pdo->beginTransaction();

            if (!empty($toAdd)) {
                $ins = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role_id, assigned_by)
                    VALUES (?, ?, ?)
                ");
                foreach ($toAdd as $rid) {
                    $ins->execute([$targetId, $rid, (int)$user['id']]);
                }
            }

            if (!empty($toRemove)) {
                $del = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
                foreach ($toRemove as $rid) {
                    $del->execute([$targetId, $rid]);
                }
            }

            $pdo->commit();

            // Audit each add/remove
            foreach ($toAdd as $rid) {
                Audit::created('user_roles', 0, ['user_id' => $targetId, 'role_id' => $rid]);
            }
            foreach ($toRemove as $rid) {
                Audit::deleted('user_roles', 0, ['user_id' => $targetId, 'role_id' => $rid]);
            }

            $parts = [];
            if (!empty($toAdd))    $parts[] = count($toAdd)    . ' role(s) added';
            if (!empty($toRemove)) $parts[] = count($toRemove) . ' role(s) removed';

            Flash::success(
                'Roles for <strong>' . e($targetUserRow['full_name']) . '</strong> updated: '
                . implode(', ', $parts) . '.'
            );
            redirect('user_roles.php?user_id=' . $targetId);

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('user_roles.php save_user_roles failed: ' . $ex->getMessage());
            Flash::danger('Could not save the role assignments. Please try again.');
            redirect('user_roles.php?user_id=' . $targetId);
        }
    }

    /* ---------- SAVE ROLE USERS ---------- */
    if ($postAction === 'save_role_users') {
        $targetId = (int)($_POST['role_id'] ?? 0);
        if ($targetId <= 0) {
            Flash::danger('Invalid role ID.');
            redirect('user_roles.php');
        }

        // Verify role
        $st = $pdo->prepare("SELECT id, name FROM roles WHERE id = ? LIMIT 1");
        $st->execute([$targetId]);
        $targetRoleRow = $st->fetch();
        if (!$targetRoleRow) {
            Flash::danger('Role not found.');
            redirect('roles.php');
        }

        // Collect checked user IDs
        $newUserIds = array_values(array_unique(array_map(
            'intval',
            $_POST['user_ids'] ?? []
        )));
        $newUserIds = array_filter($newUserIds, fn($u) => $u > 0);

        // Verify all user IDs exist
        if (!empty($newUserIds)) {
            $placeholders = implode(',', array_fill(0, count($newUserIds), '?'));
            $st = $pdo->prepare("SELECT id FROM users WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
            $st->execute($newUserIds);
            $validUserIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $newUserIds = array_values(array_intersect($newUserIds, $validUserIds));
        }

        // Current assignments
        $st = $pdo->prepare("SELECT user_id FROM user_roles WHERE role_id = ?");
        $st->execute([$targetId]);
        $currentUserIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        // ---- Self-lockout guard (admin role only) ----
        if ($targetId === $adminRoleId
            && in_array((int)$user['id'], $currentUserIds, true)
            && !in_array((int)$user['id'], $newUserIds, true)
        ) {
            Flash::danger(
                'Security Error: You cannot remove yourself from the <strong>admin</strong> role. '
                . 'Doing so would lock you out of this page.'
            );
            redirect('user_roles.php?role_id=' . $targetId);
        }

        // ---- Last-admin guard ----
        if ($targetId === $adminRoleId) {
            $remaining = array_diff($currentUserIds, array_diff($currentUserIds, $newUserIds));
            // Above: users who currently have the role AND will still have it after save
            $remainingCount = count(array_intersect($currentUserIds, $newUserIds));

            if ($remainingCount === 0 && !empty($currentUserIds)) {
                Flash::danger(
                    'Cannot remove the last administrator. The admin role must have at least one user.'
                );
                redirect('user_roles.php?role_id=' . $targetId);
            }
        }

        // Compute diff
        $toAdd    = array_diff($newUserIds, $currentUserIds);
        $toRemove = array_diff($currentUserIds, $newUserIds);

        if (empty($toAdd) && empty($toRemove)) {
            Flash::warning('No changes were made.');
            redirect('user_roles.php?role_id=' . $targetId);
        }

        try {
            $pdo->beginTransaction();

            if (!empty($toAdd)) {
                $ins = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role_id, assigned_by)
                    VALUES (?, ?, ?)
                ");
                foreach ($toAdd as $uid) {
                    $ins->execute([$uid, $targetId, (int)$user['id']]);
                }
            }

            if (!empty($toRemove)) {
                $del = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
                foreach ($toRemove as $uid) {
                    $del->execute([$uid, $targetId]);
                }
            }

            $pdo->commit();

            foreach ($toAdd as $uid) {
                Audit::created('user_roles', 0, ['user_id' => $uid, 'role_id' => $targetId]);
            }
            foreach ($toRemove as $uid) {
                Audit::deleted('user_roles', 0, ['user_id' => $uid, 'role_id' => $targetId]);
            }

            $parts = [];
            if (!empty($toAdd))    $parts[] = count($toAdd)    . ' user(s) assigned';
            if (!empty($toRemove)) $parts[] = count($toRemove) . ' user(s) unassigned';

            Flash::success(
                'Users with role <strong>' . e($targetRoleRow['name']) . '</strong> updated: '
                . implode(', ', $parts) . '.'
            );
            redirect('user_roles.php?role_id=' . $targetId);

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('user_roles.php save_role_users failed: ' . $ex->getMessage());
            Flash::danger('Could not save the role assignments. Please try again.');
            redirect('user_roles.php?role_id=' . $targetId);
        }
    }
}


/* ------------------------------------------------------------
   6. LOAD DATA PER MODE
   ------------------------------------------------------------ */

$allRoles = [];
try {
    $allRoles = $pdo->query("
        SELECT * FROM roles
        ORDER BY is_system DESC, name ASC
    ")->fetchAll();
} catch (Throwable $e) { /* silent */ }

// ---- USER EDIT MODE: load this user's current roles ----
$userCurrentRoleIds = [];
if ($mode === 'user_edit') {
    $st = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
    $st->execute([$userId]);
    $userCurrentRoleIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// ---- ROLE EDIT MODE: load users for this role + all users ----
$roleCurrentUserIds = [];
$allUsers = [];
if ($mode === 'role_edit') {
    $st = $pdo->prepare("SELECT user_id FROM user_roles WHERE role_id = ?");
    $st->execute([$roleId]);
    $roleCurrentUserIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    try {
        $allUsers = $pdo->query("
            SELECT u.id, u.full_name, u.username, u.position, u.status,
                   d.name AS dept_name
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.deleted_at IS NULL
            ORDER BY u.status ASC, u.full_name ASC
        ")->fetchAll();
    } catch (Throwable $e) { /* silent */ }
}

// ---- LIST MODE: users with their role lists ----
$listUsers   = [];
$listFilters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'has_role' => (int)($_GET['has_role'] ?? 0),
    'dept'   => (int)($_GET['dept'] ?? 0),
];

if ($mode === 'list') {
    $whereParts = ['u.deleted_at IS NULL'];
    $args       = [];

    if ($listFilters['search'] !== '') {
        $like = '%' . $listFilters['search'] . '%';
        $whereParts[] = '(u.full_name LIKE ? OR u.username LIKE ?)';
        array_push($args, $like, $like);
    }
    if ($listFilters['dept'] > 0) {
        $whereParts[] = 'u.department_id = ?';
        $args[] = $listFilters['dept'];
    }
    if ($listFilters['has_role'] > 0) {
        $whereParts[] = 'EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = ?)';
        $args[] = $listFilters['has_role'];
    }

    $whereSql = implode(' AND ', $whereParts);

    try {
        $st = $pdo->prepare("
            SELECT u.id, u.full_name, u.username, u.position, u.status,
                   d.name AS dept_name,
                   (SELECT COUNT(*) FROM user_roles ur WHERE ur.user_id = u.id) AS role_count
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE {$whereSql}
            ORDER BY u.full_name ASC
            LIMIT 500
        ");
        $st->execute($args);
        $listUsers = $st->fetchAll();

        // Load all role assignments in one query (avoids N+1)
        if (!empty($listUsers)) {
            $userIds = array_column($listUsers, 'id');
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $st = $pdo->prepare("
                SELECT ur.user_id, r.id AS role_id, r.code, r.name, r.is_system
                FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id IN ({$placeholders})
                ORDER BY r.is_system DESC, r.name ASC
            ");
            $st->execute($userIds);
            $assignments = [];
            foreach ($st->fetchAll() as $row) {
                $assignments[(int)$row['user_id']][] = $row;
            }
            foreach ($listUsers as &$u) {
                $u['roles'] = $assignments[(int)$u['id']] ?? [];
            }
            unset($u);
        }
    } catch (Throwable $e) {
        error_log('user_roles list query failed: ' . $e->getMessage());
    }
}

// Departments for filter dropdown
$departments = [];
try {
    $departments = $pdo->query("
        SELECT id, name FROM departments
        WHERE is_active = 1
        ORDER BY name ASC
    ")->fetchAll();
} catch (Throwable $e) { /* silent */ }

// Summary for list mode
$summary = ['users' => 0, 'with_roles' => 0, 'without_roles' => 0, 'assignments' => 0];
if ($mode === 'list') {
    try {
        $summary = $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS users,
                (SELECT COUNT(DISTINCT user_id) FROM user_roles) AS with_roles,
                (SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL
                 AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id)) AS without_roles,
                (SELECT COUNT(*) FROM user_roles) AS assignments
        ")->fetch() ?: $summary;
    } catch (Throwable $e) { /* silent */ }
}


/* ------------------------------------------------------------
   7. HELPERS
   ------------------------------------------------------------ */

/** Badge color per role code — deterministic for consistent UX. */
function mpc_role_badge_class(string $code, int $isSystem): string
{
    if ($isSystem) {
        return 'badge-primary';
    }
    // Deterministic color for custom roles
    $hash = crc32($code) % 4;
    return ['badge-info', 'badge-warning', 'badge-success', 'badge-secondary'][$hash];
}


/* ------------------------------------------------------------
   8. RENDER
   ------------------------------------------------------------ */
$pageTitle = $mode === 'user_edit' ? 'Edit User Roles'
           : ($mode === 'role_edit' ? 'Edit Role Users' : 'User Roles');
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

<?php if ($mode === 'list'): ?>

    <!-- ============================================================
         LIST MODE
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">User Roles (የተጠቃሚ ሚናዎች)</h1>
            <p class="muted" style="margin-top: 5px;">
                Assign and manage role assignments for every user.
            </p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="roles.php" class="btn btn-ghost" style="border-color: var(--mpc-navy); color: var(--mpc-navy);">
                🎭 Manage Role Definitions
            </a>
            <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
                👥 Back to Users
            </a>
        </div>
    </div>

    <!-- Summary -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">👥</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= (int)$summary['users'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase;">Users</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #16a34a; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">✅</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #16a34a; line-height: 1;"><?= (int)$summary['with_roles'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase;">With Roles</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-red); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">⚠️</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-red); line-height: 1;"><?= (int)$summary['without_roles'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase;">No Roles</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #6366f1; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🔗</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #6366f1; line-height: 1;"><?= (int)$summary['assignments'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase;">Assignments</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card" style="padding: 20px; margin-bottom: 20px; border-left-color: var(--mpc-flame);">
        <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="flex: 3; min-width: 220px; margin-bottom: 0;">
                <label>Search</label>
                <input type="text" name="search" placeholder="Search by name or username..."
                       value="<?= e($listFilters['search']) ?>">
            </div>
            <div class="form-group" style="flex: 1; min-width: 180px; margin-bottom: 0;">
                <label>Has Role</label>
                <select name="has_role">
                    <option value="">Any</option>
                    <?php foreach ($allRoles as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $listFilters['has_role'] === (int)$r['id'] ? 'selected' : '' ?>>
                            <?= e($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex: 1; min-width: 180px; margin-bottom: 0;">
                <label>Department</label>
                <select name="dept">
                    <option value="">Any</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $listFilters['dept'] === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="user_roles.php" class="btn btn-ghost" style="border-color: #ccc;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Username</th>
                        <th>Department</th>
                        <th>Roles</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listUsers as $u):
                        $roleCount = (int)$u['role_count'];
                        $initials  = strtoupper(mb_substr($u['full_name'], 0, 1, 'UTF-8'));
                    ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 34px; height: 34px; border-radius: 50%; background: var(--mpc-flame); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; flex-shrink: 0;">
                                    <?= e($initials) ?>
                                </div>
                                <div>
                                    <strong><?= e($u['full_name']) ?></strong>
                                    <?php if ($u['id'] === $user['id']): ?>
                                        <span class="badge badge-primary" style="margin-left: 6px; font-size: 10px;">You</span>
                                    <?php endif; ?>
                                    <br><small class="muted"><?= e($u['position'] ?: '—') ?></small>
                                </div>
                            </div>
                        </td>
                        <td><code><?= e($u['username']) ?></code></td>
                        <td><?= e($u['dept_name'] ?: '—') ?></td>
                        <td>
                            <?php if ($roleCount === 0): ?>
                                <span class="badge badge-danger" title="This user cannot do anything without roles">⚠ No roles</span>
                            <?php else: ?>
                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    <?php foreach ($u['roles'] as $role): ?>
                                        <a href="user_roles.php?role_id=<?= (int)$role['role_id'] ?>"
                                           class="badge <?= mpc_role_badge_class($role['code'], (int)$role['is_system']) ?>"
                                           style="text-decoration: none; cursor: pointer;"
                                           title="<?= e($role['code']) ?>">
                                            <?= e($role['name']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="user_roles.php?user_id=<?= (int)$u['id'] ?>"
                               class="btn btn-sm btn-primary">
                                ⚙️ Manage
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($listUsers)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 50px;">
                            <div style="font-size: 48px; margin-bottom: 15px;">👥</div>
                            <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No users match these filters.</h3>
                            <a href="user_roles.php" class="btn btn-ghost" style="border-color: #ccc; margin-top: 12px;">Clear Filters</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($mode === 'user_edit'): ?>

    <!-- ============================================================
         USER EDIT MODE
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">
                Edit Roles: <?= e($targetUser['full_name']) ?>
                <?php if ($userId === (int)$user['id']): ?>
                    <span class="badge badge-primary" style="vertical-align: middle; margin-left: 8px; font-size: 11px;">This is you</span>
                <?php endif; ?>
            </h1>
            <p class="muted" style="margin-top: 5px;">
                Toggle roles below and save. Changes apply immediately.
            </p>
        </div>
        <a href="user_roles.php" class="btn btn-ghost" style="border-color: #ccc;">
            &larr; Back to List
        </a>
    </div>

    <div class="grid-2" style="grid-template-columns: 2fr 1fr; align-items: start;">

        <!-- Form -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 16px 20px; background: #FAFBFC; border-bottom: 1px solid var(--mpc-border); display: flex; justify-content: space-between; align-items: center;">
                <strong style="color: var(--mpc-navy); font-size: 15px;">Available Roles (<?= count($allRoles) ?>)</strong>
                <div style="display: flex; gap: 8px;">
                    <button type="button" onclick="toggleAllRoles(true)" class="btn btn-sm btn-ghost" style="border-color: #ccc;">Select All</button>
                    <button type="button" onclick="toggleAllRoles(false)" class="btn btn-sm btn-ghost" style="border-color: #ccc;">Clear All</button>
                </div>
            </div>

            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_user_roles">
                <input type="hidden" name="user_id" value="<?= (int)$userId ?>">

                <div style="padding: 8px 20px;">
                    <?php foreach ($allRoles as $role):
                        $isAssigned = in_array((int)$role['id'], $userCurrentRoleIds, true);
                        $isSystem   = (int)$role['is_system'] === 1;
                        $isAdmin    = (int)$role['id'] === $adminRoleId;
                        $isSelfAdmin = ($userId === (int)$user['id'] && $isAdmin);

                        // Determine if checkbox should be disabled
                        $canToggle = !$isSelfAdmin;
                    ?>
                        <label style="display: flex; align-items: center; gap: 12px; padding: 12px 8px; border-bottom: 1px solid var(--mpc-border); cursor: <?= $canToggle ? 'pointer' : 'not-allowed' ?>; transition: background 0.15s;" onmouseover="this.style.background='#F9FAFB'" onmouseout="this.style.background=''">
                            <input type="checkbox"
                                   name="role_ids[]"
                                   value="<?= (int)$role['id'] ?>"
                                   <?= $isAssigned ? 'checked' : '' ?>
                                   <?= $canToggle ? '' : 'disabled' ?>
                                   class="role-checkbox"
                                   style="width: 18px; height: 18px; accent-color: var(--mpc-blue); flex-shrink: 0;">

                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <strong style="color: var(--mpc-navy); font-size: 14px;"><?= e($role['name']) ?></strong>
                                    <code style="background: #F3F4F6; color: #6B7280; font-size: 10px; padding: 2px 6px; border-radius: 3px;"><?= e($role['code']) ?></code>
                                    <?php if ($isSystem): ?>
                                        <span class="badge badge-primary" style="font-size: 9px;">🔒 System</span>
                                    <?php endif; ?>
                                    <?php if ($isSelfAdmin): ?>
                                        <span class="badge badge-danger" style="font-size: 9px;">Locked (self)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($role['description'])): ?>
                                    <div class="muted" style="font-size: 12px; margin-top: 2px;">
                                        <?= e($role['description']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="padding: 20px; background: #FAFBFC; border-top: 1px solid var(--mpc-border); display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <a href="user_roles.php" class="btn btn-ghost" style="border-color: #ccc;">Cancel</a>
                    <button type="submit" class="btn btn-primary">💾 Save Roles</button>
                </div>
            </form>
        </div>

        <!-- Sidebar -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- Target user card -->
            <div class="card">
                <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    👤 Target User
                </h3>
                <ul class="kv" style="font-size: 13px;">
                    <li><span>Name</span><b><?= e($targetUser['full_name']) ?></b></li>
                    <li><span>Username</span><b><code><?= e($targetUser['username']) ?></code></b></li>
                    <li><span>Department</span><b><?= e($targetUser['dept_name'] ?: '—') ?></b></li>
                    <li><span>Position</span><b><?= e($targetUser['position'] ?: '—') ?></b></li>
                    <li><span>Status</span><b><?= status_badge($targetUser['status']) ?></b></li>
                    <li><span>Current Roles</span><b><?= count($userCurrentRoleIds) ?></b></li>
                </ul>
                <a href="users.php?search=<?= urlencode($targetUser['username']) ?>"
                   class="btn btn-sm btn-ghost" style="width: 100%; margin-top: 12px; border-color: #ccc; color: var(--mpc-blue);">
                    View in Users List →
                </a>
            </div>

            <?php if ($userId === (int)$user['id']): ?>
                <div class="card" style="border-left-color: var(--mpc-red); background: #FEF2F2;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-red); border-bottom: 1px solid #FECACA; padding-bottom: 10px;">
                        🔒 Self-Edit Protection
                    </h3>
                    <p style="font-size: 12px; margin: 0; color: #7F1D1D; line-height: 1.7;">
                        You are editing your own account. Your <strong>admin</strong> role is locked and cannot be unchecked — this prevents accidental lockout. Other roles can be freely toggled.
                    </p>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        function toggleAllRoles(checked) {
            document.querySelectorAll('.role-checkbox').forEach(cb => {
                if (!cb.disabled) cb.checked = checked;
            });
        }
    </script>

<?php elseif ($mode === 'role_edit'): ?>

    <!-- ============================================================
         ROLE EDIT MODE
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">
                Users with Role: <?= e($targetRole['name']) ?>
            </h1>
            <p class="muted" style="margin-top: 5px;">
                <code style="background: #F3F4F6; padding: 2px 6px; border-radius: 3px;"><?= e($targetRole['code']) ?></code>
                · Toggle users below and save.
            </p>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="roles.php?id=<?= (int)$roleId ?>" class="btn btn-ghost" style="border-color: var(--mpc-navy); color: var(--mpc-navy);">
                ✏️ Edit Role Definition
            </a>
            <a href="user_roles.php" class="btn btn-ghost" style="border-color: #ccc;">
                &larr; Back to List
            </a>
        </div>
    </div>

    <div class="grid-2" style="grid-template-columns: 2fr 1fr; align-items: start;">

        <!-- Form -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 16px 20px; background: #FAFBFC; border-bottom: 1px solid var(--mpc-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <strong style="color: var(--mpc-navy); font-size: 15px;">
                    Users (<?= count($allUsers) ?>) · <span style="color: var(--mpc-blue);"><?= count($roleCurrentUserIds) ?> assigned</span>
                </strong>
                <div style="display: flex; gap: 8px;">
                    <button type="button" onclick="toggleAllUsers(true)" class="btn btn-sm btn-ghost" style="border-color: #ccc;">Select All</button>
                    <button type="button" onclick="toggleAllUsers(false)" class="btn btn-sm btn-ghost" style="border-color: #ccc;">Clear All</button>
                </div>
            </div>

            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_role_users">
                <input type="hidden" name="role_id" value="<?= (int)$roleId ?>">

                <div style="padding: 8px 20px; max-height: 600px; overflow-y: auto;">
                    <?php foreach ($allUsers as $u):
                        $isAssigned = in_array((int)$u['id'], $roleCurrentUserIds, true);
                        $isSelfAdmin = ($roleId === $adminRoleId && (int)$u['id'] === (int)$user['id']);
                        $canToggle = !$isSelfAdmin;
                    ?>
                        <label style="display: flex; align-items: center; gap: 12px; padding: 10px 8px; border-bottom: 1px solid var(--mpc-border); cursor: <?= $canToggle ? 'pointer' : 'not-allowed' ?>;">
                            <input type="checkbox"
                                   name="user_ids[]"
                                   value="<?= (int)$u['id'] ?>"
                                   <?= $isAssigned ? 'checked' : '' ?>
                                   <?= $canToggle ? '' : 'disabled' ?>
                                   class="user-checkbox"
                                   style="width: 18px; height: 18px; accent-color: var(--mpc-blue); flex-shrink: 0;">

                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <strong style="color: var(--mpc-navy); font-size: 13px;"><?= e($u['full_name']) ?></strong>
                                    <code style="background: #F3F4F6; color: #6B7280; font-size: 10px; padding: 2px 6px; border-radius: 3px;"><?= e($u['username']) ?></code>
                                    <?php if ($u['id'] === $user['id']): ?>
                                        <span class="badge badge-primary" style="font-size: 9px;">You</span>
                                    <?php endif; ?>
                                    <?php if ($isSelfAdmin): ?>
                                        <span class="badge badge-danger" style="font-size: 9px;">Locked</span>
                                    <?php endif; ?>
                                </div>
                                <div class="muted" style="font-size: 11px; margin-top: 2px;">
                                    <?= e($u['position'] ?: '—') ?>
                                    <?php if (!empty($u['dept_name'])): ?> · <?= e($u['dept_name']) ?><?php endif; ?>
                                    · <?= status_badge($u['status']) ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="padding: 20px; background: #FAFBFC; border-top: 1px solid var(--mpc-border); display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <a href="user_roles.php" class="btn btn-ghost" style="border-color: #ccc;">Cancel</a>
                    <button type="submit" class="btn btn-primary">💾 Save Assignments</button>
                </div>
            </form>
        </div>

        <!-- Sidebar -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <div class="card">
                <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    🎭 Target Role
                </h3>
                <ul class="kv" style="font-size: 13px;">
                    <li><span>Code</span><b><code><?= e($targetRole['code']) ?></code></b></li>
                    <li><span>Name</span><b><?= e($targetRole['name']) ?></b></li>
                    <li><span>Type</span><b><?= (int)$targetRole['is_system'] === 1 ? '🔒 System' : 'Custom' ?></b></li>
                    <li><span>Assigned</span><b><?= count($roleCurrentUserIds) ?> user(s)</b></li>
                </ul>
                <?php if (!empty($targetRole['description'])): ?>
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee; font-size: 12px; color: #4B5563;">
                        <?= e($targetRole['description']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($roleId === $adminRoleId && in_array((int)$user['id'], $roleCurrentUserIds, true)): ?>
                <div class="card" style="border-left-color: var(--mpc-red); background: #FEF2F2;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-red); border-bottom: 1px solid #FECACA; padding-bottom: 10px;">
                        🔒 Self-Edit Protection
                    </h3>
                    <p style="font-size: 12px; margin: 0; color: #7F1D1D; line-height: 1.7;">
                        You are viewing the <strong>admin</strong> role and are currently assigned to it. Your checkbox is locked — you cannot remove yourself from the admin role. This prevents lockout.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($roleId === $adminRoleId && count($roleCurrentUserIds) === 1): ?>
                <div class="card" style="border-left-color: var(--mpc-flame); background: #FFFBEB;">
                    <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-flame-dark); border-bottom: 1px solid #FDE68A; padding-bottom: 10px;">
                        ⚠️ Only Admin
                    </h3>
                    <p style="font-size: 12px; margin: 0; color: #78350F; line-height: 1.7;">
                        This role has only <strong>1 user</strong>. If you remove them, you'll leave the system with no administrators. Add another user to the admin role first.
                    </p>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        function toggleAllUsers(checked) {
            document.querySelectorAll('.user-checkbox').forEach(cb => {
                if (!cb.disabled) cb.checked = checked;
            });
        }
    </script>

<?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>