<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   MANAGE DEPARTMENTS (Admin only)
   ============================================================
   PURPOSE
     List, filter, sort, paginate, and manage every department
     in the system. Admin-only.

   FEATURES
     - Search by code, English name, or Amharic name
     - Filter by type (academic / administrative / support)
     - Filter by status (active / inactive)
     - Sort by name / code / type / users / head / newest / oldest
     - Pagination (50 per page)
     - CSV export (respects filters + sort)
     - Summary stats (org-wide, unaffected by filters)
     - User count per department (from users table)
     - Cross-link to users.php?dept=N

   DEPENDENCIES
     functions.php          — base helpers
     includes/auth_check.php — Auth::requireRole
     includes/flash.php      — Flash::success
     includes/audit.php      — Audit::exported (CSV export event)

   SAFETY
     - Admin role enforced at entry
     - All SQL uses prepared statements
     - CSV output uses fputcsv + UTF-8 BOM (Excel-compatible)
   ============================================================ */


/* ------------------------------------------------------------
   1. AUTH
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';

$user = Auth::require();
Auth::requireRole('admin', 'Access denied.');


/* ------------------------------------------------------------
   2. COLLECT FILTERS
   ------------------------------------------------------------ */
$filters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'type'   => (string)($_GET['type']   ?? ''),   // academic|administrative|support
    'status' => (string)($_GET['status'] ?? ''),   // active|inactive
];

$sortKeyMap = [
    'type'        => "FIELD(d.type, 'academic', 'administrative', 'support'), d.name ASC",
    'name'        => 'd.name ASC',
    'name_desc'   => 'd.name DESC',
    'code'        => 'd.code ASC',
    'users'       => 'user_count DESC, d.name ASC',
    'head'        => 'u.full_name ASC, d.name ASC',
    'created'     => 'd.created_at DESC',
    'created_asc' => 'd.created_at ASC',
];
$sortKey = (string)($_GET['sort'] ?? 'type');
$orderBy = $sortKeyMap[$sortKey] ?? $sortKeyMap['type'];


/* ------------------------------------------------------------
   3. BUILD WHERE CLAUSE
   ------------------------------------------------------------ */
$whereParts = ['1=1'];
$args       = [];

if ($filters['search'] !== '') {
    $like = '%' . $filters['search'] . '%';
    $whereParts[] = '(d.code LIKE ? OR d.name LIKE ? OR d.name_am LIKE ?)';
    array_push($args, $like, $like, $like);
}
if (in_array($filters['type'], ['academic', 'administrative', 'support'], true)) {
    $whereParts[] = 'd.type = ?';
    $args[] = $filters['type'];
}
if ($filters['status'] === 'active') {
    $whereParts[] = 'd.is_active = 1';
} elseif ($filters['status'] === 'inactive') {
    $whereParts[] = 'd.is_active = 0';
}

$whereSql = implode(' AND ', $whereParts);


/* ------------------------------------------------------------
   4. CSV EXPORT (before rendering HTML)
   ------------------------------------------------------------ */
if (($_GET['export'] ?? '') === 'csv') {
    try {
        Audit::exported('departments', ['filters' => array_filter($filters), 'sort' => $sortKey]);
    } catch (Throwable $e) {
        // non-blocking
    }

    $st = $pdo->prepare("
        SELECT d.id, d.code, d.name, d.name_am, d.type, d.is_active,
               d.created_at, u.full_name AS head_name,
               (SELECT COUNT(*) FROM users u2
                WHERE u2.department_id = d.id AND u2.deleted_at IS NULL) AS user_count
        FROM departments d
        LEFT JOIN users u ON u.id = d.head_user_id
        WHERE {$whereSql}
        ORDER BY {$orderBy}
    ");
    $st->execute($args);
    $rows = $st->fetchAll();

    $filename = 'departments_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID', 'Code', 'Name (English)', 'Name (Amharic)', 'Type',
        'Head of Department', 'Users', 'Status', 'Created',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['code'],
            $r['name'],
            $r['name_am']   ?? '',
            $r['type'],
            $r['head_name'] ?? '',
            $r['user_count'],
            $r['is_active'] ? 'Active' : 'Inactive',
            $r['created_at'],
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

// Count query — needs the LEFT JOIN for head filter
$st = $pdo->prepare("
    SELECT COUNT(*)
    FROM departments d
    LEFT JOIN users u ON u.id = d.head_user_id
    WHERE {$whereSql}
");
$st->execute($args);
$total = (int)$st->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;


/* ------------------------------------------------------------
   6. FETCH DEPARTMENTS FOR CURRENT PAGE
   ------------------------------------------------------------ */
$st = $pdo->prepare("
    SELECT d.*, u.full_name AS head_name, u.position AS head_position,
           (SELECT COUNT(*) FROM users u2
            WHERE u2.department_id = d.id AND u2.deleted_at IS NULL) AS user_count
    FROM departments d
    LEFT JOIN users u ON u.id = d.head_user_id
    WHERE {$whereSql}
    ORDER BY {$orderBy}
    LIMIT {$perPage} OFFSET {$offset}
");
$st->execute($args);
$departments = $st->fetchAll();


/* ------------------------------------------------------------
   7. SUMMARY STATS (org-wide, ignores filters)
   ------------------------------------------------------------ */
try {
    $summary = $pdo->query("
        SELECT
            COUNT(*)                                        AS total,
            SUM(type = 'academic')                          AS academic,
            SUM(type = 'administrative')                    AS administrative,
            SUM(type = 'support')                           AS support,
            SUM(is_active = 1)                              AS active,
            SUM(is_active = 0)                              AS inactive
        FROM departments
    ")->fetch() ?: [
        'total' => 0, 'academic' => 0, 'administrative' => 0,
        'support' => 0, 'active' => 0, 'inactive' => 0,
    ];
} catch (Throwable $e) {
    $summary = [
        'total' => 0, 'academic' => 0, 'administrative' => 0,
        'support' => 0, 'active' => 0, 'inactive' => 0,
    ];
}


/* ------------------------------------------------------------
   8. HELPERS
   ------------------------------------------------------------ */

/** Build a URL preserving all current filters except the changed one. */
$buildUrl = function (array $overrides = []) use ($filters, $sortKey): string {
    $params = array_merge(
        [
            'search' => $filters['search'],
            'type'   => $filters['type'],
            'status' => $filters['status'],
            'sort'   => $sortKey,
        ],
        $overrides
    );
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'departments.php' . ($params ? '?' . http_build_query($params) : '');
};

/** Is any filter active? */
$hasFilters = $filters['search'] !== ''
           || $filters['type'] !== ''
           || $filters['status'] !== '';

/** Type → CSS badge class + display label. */
$typeBadge = function (string $type): array {
    return match ($type) {
        'academic'       => ['badge-info',      'Academic (ትምህርታዊ)'],
        'administrative' => ['badge-primary',   'Administrative (አስተዳደራዊ)'],
        'support'        => ['badge-warning',   'Support (ድጋፍ)'],
        default          => ['badge-secondary', ucfirst($type)],
    };
};


/* ------------------------------------------------------------
   9. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Manage Departments';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1200px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Manage Departments (የክፍሎች አስተዳደር)</h1>
            <p class="muted" style="margin-top: 5px;">Manage academic, administrative, and support units.</p>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
                &larr; Back to Users
            </a>
            <a href="<?= e($buildUrl(['export' => 'csv', 'page' => ''])) ?>"
               class="btn btn-ghost" style="border-color: #16a34a; color: #16a34a;">
                📥 Export CSV
            </a>
            <a href="department_edit.php" class="btn btn-primary">
                + Add Department (አዲስ ክፍል)
            </a>
        </div>
    </div>

    <!-- ============================================================
         SUMMARY CHIPS (org-wide)
         ============================================================ -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🏢</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= (int)$summary['total'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Total</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-blue); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🎓</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-blue); line-height: 1;"><?= (int)$summary['academic'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Academic</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #6366f1; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🏛️</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #6366f1; line-height: 1;"><?= (int)$summary['administrative'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Administrative</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-flame); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🛠️</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-flame); line-height: 1;"><?= (int)$summary['support'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Support</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-red); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">⛔</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-red); line-height: 1;"><?= (int)$summary['inactive'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Inactive</div>
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
                <input type="text" name="search"
                       placeholder="Search by code, name, or Amharic name..."
                       value="<?= e($filters['search']) ?>">
            </div>

            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                <label>Type</label>
                <select name="type">
                    <option value="">All Types</option>
                    <option value="academic"       <?= $filters['type'] === 'academic'       ? 'selected' : '' ?>>Academic</option>
                    <option value="administrative" <?= $filters['type'] === 'administrative' ? 'selected' : '' ?>>Administrative</option>
                    <option value="support"        <?= $filters['type'] === 'support'        ? 'selected' : '' ?>>Support</option>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 140px; margin-bottom: 0;">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                <label>Sort by</label>
                <select name="sort">
                    <option value="type"        <?= $sortKey === 'type'        ? 'selected' : '' ?>>Type (default)</option>
                    <option value="name"        <?= $sortKey === 'name'        ? 'selected' : '' ?>>Name (A → Z)</option>
                    <option value="name_desc"   <?= $sortKey === 'name_desc'   ? 'selected' : '' ?>>Name (Z → A)</option>
                    <option value="code"        <?= $sortKey === 'code'        ? 'selected' : '' ?>>Code</option>
                    <option value="users"       <?= $sortKey === 'users'       ? 'selected' : '' ?>>User count</option>
                    <option value="head"        <?= $sortKey === 'head'        ? 'selected' : '' ?>>Head name</option>
                    <option value="created"     <?= $sortKey === 'created'     ? 'selected' : '' ?>>Newest first</option>
                    <option value="created_asc" <?= $sortKey === 'created_asc' ? 'selected' : '' ?>>Oldest first</option>
                </select>
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($hasFilters): ?>
                    <a href="departments.php" class="btn btn-ghost" style="border-color: #ccc;">Reset</a>
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
                Showing <strong><?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?></strong> of <strong><?= (int)$total ?></strong> departments
                <?php if ($hasFilters): ?>
                    · <a href="departments.php" style="color: var(--mpc-blue);">Clear filters</a>
                <?php endif; ?>
            </p>
            <?php if ($totalPages > 1): ?>
                <p class="muted" style="margin: 0; font-size: 13px;">Page <?= $page ?> of <?= $totalPages ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ============================================================
         DEPARTMENTS TABLE
         ============================================================ -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Department Name (English)</th>
                        <th>የክፍል ስም (Amharic)</th>
                        <th>Type</th>
                        <th>Head of Dept</th>
                        <th>Users</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $d):
                        [$badgeClass, $badgeLabel] = $typeBadge((string)$d['type']);
                        $headName = (string)($d['head_name'] ?? '');
                        $initial  = $headName !== '' ? strtoupper(mb_substr($headName, 0, 1, 'UTF-8')) : '?';
                    ?>
                    <tr>
                        <!-- Code -->
                        <td>
                            <code style="font-weight: 700; color: var(--mpc-navy); font-size: 13px;">
                                <?= e($d['code']) ?>
                            </code>
                        </td>

                        <!-- English Name -->
                        <td>
                            <strong><?= e($d['name']) ?></strong>
                        </td>

                        <!-- Amharic Name -->
                        <td style="font-family: 'Noto Sans Ethiopic', sans-serif;">
                            <?= e($d['name_am'] ?: '—') ?>
                        </td>

                        <!-- Type -->
                        <td>
                            <span class="badge <?= $badgeClass ?>"><?= e($badgeLabel) ?></span>
                        </td>

                        <!-- Head of Department -->
                        <td>
                            <?php if ($headName !== ''): ?>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--mpc-flame); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0;">
                                        <?= e($initial) ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600; font-size: 13px;"><?= e($headName) ?></div>
                                        <?php if (!empty($d['head_position'])): ?>
                                            <small class="muted" style="font-size: 11px;"><?= e($d['head_position']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="muted">Not Assigned</span>
                            <?php endif; ?>
                        </td>

                        <!-- User Count (clickable) -->
                        <td>
                            <?php if ((int)$d['user_count'] > 0): ?>
                                <a href="users.php?dept=<?= (int)$d['id'] ?>"
                                   style="color: var(--mpc-blue); font-weight: 700; font-size: 14px; text-decoration: none;"
                                   title="View users in <?= e($d['name']) ?>">
                                    <?= (int)$d['user_count'] ?> →
                                </a>
                            <?php else: ?>
                                <span class="muted" style="font-size: 13px;">0</span>
                            <?php endif; ?>
                        </td>

                        <!-- Status -->
                        <td>
                            <?php if ($d['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td>
                            <a href="department_edit.php?id=<?= (int)$d['id'] ?>" class="btn btn-sm btn-primary" title="Edit department">
                                ✏️ Edit
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Empty State -->
                    <?php if (empty($departments)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 50px;">
                            <div style="font-size: 48px; margin-bottom: 15px;">🏢</div>
                            <?php if ($hasFilters): ?>
                                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No departments match these filters.</h3>
                                <p class="muted" style="font-size: 14px; margin-bottom: 20px;">
                                    Try adjusting your search or clearing the filters.
                                </p>
                                <a href="departments.php" class="btn btn-ghost" style="border-color: #ccc;">Clear Filters</a>
                            <?php else: ?>
                                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No departments yet.</h3>
                                <p class="muted" style="font-size: 14px; margin-bottom: 20px;">
                                    Get started by adding the first department.
                                </p>
                                <a href="department_edit.php" class="btn btn-primary">+ Add First Department</a>
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
        $windowSize = 2;
        $startPage  = max(1, $page - $windowSize);
        $endPage    = min($totalPages, $page + $windowSize);
        ?>

        <?php if ($page > 1): ?>
            <a href="<?= e($buildUrl(['page' => 1])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">« First</a>
            <a href="<?= e($buildUrl(['page' => $page - 1])) ?>" class="btn btn-ghost btn-sm" style="border-color: #ccc;">‹ Prev</a>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="btn btn-primary btn-sm" aria-current="page" style="cursor: default;"><?= $i ?></span>
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

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>