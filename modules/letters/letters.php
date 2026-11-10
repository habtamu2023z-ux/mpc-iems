<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   OFFICIAL LETTERS DIRECTORY
   ============================================================
   PURPOSE
     List, filter, sort, and manage all official letters.

   FEATURES
     - Role-based visibility (Admins see all, Staff see own)
     - Search by Ref No, Subject, Sender, or Recipient
     - Filter by Letter Type and Status
     - Sort by Date, Subject, or Ref No
     - Pagination (25 per page)
     - Summary chips (Total, Draft, Sent, Archived)
     - Quick action buttons (View, Print, Archive)

   DEPENDENCIES
     functions.php          — base helpers
     includes/auth_check.php — Auth class
     includes/csrf.php      — Csrf class
     includes/flash.php     — Flash class
     includes/notification.php — Notifier (optional, for future)
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES & AUTH
   ------------------------------------------------------------ */
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = Auth::require();

// Students do not have access to the general letters directory
if (Auth::isStudent()) {
    Flash::warning('Students do not have access to the general letters directory.');
    redirect(APP_URL . '/index.php');
}

$isAdminOrRegistrar = has_role(['admin', 'registrar']);


/* ------------------------------------------------------------
   2. COLLECT FILTERS & SORTING
   ------------------------------------------------------------ */
$filters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'type'   => (string)($_GET['type'] ?? ''),
    'status' => (string)($_GET['status'] ?? ''),
];

$sortKeyMap = [
    'newest'   => 'l.created_at DESC',
    'oldest'   => 'l.created_at ASC',
    'subject'  => 'l.subject ASC',
    'ref'      => 'l.ref_no DESC',
];
$sortKey = (string)($_GET['sort'] ?? 'newest');
$orderBy = $sortKeyMap[$sortKey] ?? $sortKeyMap['newest'];


/* ------------------------------------------------------------
   3. BUILD QUERY
   ------------------------------------------------------------ */
$whereParts = ['l.deleted_at IS NULL'];
$args       = [];

// Role-based visibility
if (!$isAdminOrRegistrar) {
    $whereParts[] = '(l.sender_id = ? OR l.recipient_user_id = ?)';
    $args[] = (int)$user['id'];
    $args[] = (int)$user['id'];
}

// Search
if ($filters['search'] !== '') {
    $like = '%' . $filters['search'] . '%';
    $whereParts[] = '(l.ref_no LIKE ? OR l.subject LIKE ? OR s.full_name LIKE ? OR l.recipient_name LIKE ?)';
    array_push($args, $like, $like, $like, $like);
}

// Type
if ($filters['type'] !== '') {
    $whereParts[] = 'l.letter_type = ?';
    $args[] = $filters['type'];
}

// Status
if ($filters['status'] !== '') {
    $whereParts[] = 'l.status = ?';
    $args[] = $filters['status'];
}

$whereSql = implode(' AND ', $whereParts);


/* ------------------------------------------------------------
   4. PAGINATION
   ------------------------------------------------------------ */
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));

$st = $pdo->prepare("
    SELECT COUNT(*) 
    FROM letters l 
    JOIN users s ON s.id = l.sender_id 
    WHERE {$whereSql}
");
$st->execute($args);
$total = (int)$st->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;


/* ------------------------------------------------------------
   5. FETCH LETTERS
   ------------------------------------------------------------ */
$st = $pdo->prepare("
    SELECT l.*, s.full_name AS sender_name, r.full_name AS recipient_user_name 
    FROM letters l 
    JOIN users s ON s.id = l.sender_id 
    LEFT JOIN users r ON r.id = l.recipient_user_id 
    WHERE {$whereSql}
    ORDER BY {$orderBy}
    LIMIT {$perPage} OFFSET {$offset}
");
$st->execute($args);
$letters = $st->fetchAll();


/* ------------------------------------------------------------
   6. SUMMARY STATS (Scoped to user's visibility)
   ------------------------------------------------------------ */
$statsWhere = $isAdminOrRegistrar ? '1=1' : '(sender_id = ? OR recipient_user_id = ?)';
$statsArgs  = $isAdminOrRegistrar ? [] : [$user['id'], $user['id']];

try {
    $st = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(status = 'draft') AS draft,
            SUM(status = 'sent') AS sent,
            SUM(status = 'archived') AS archived
        FROM letters
        WHERE deleted_at IS NULL AND {$statsWhere}
    ");
    $st->execute($statsArgs);
    $summary = $st->fetch() ?: ['total' => 0, 'draft' => 0, 'sent' => 0, 'archived' => 0];
} catch (Throwable $e) {
    $summary = ['total' => 0, 'draft' => 0, 'sent' => 0, 'archived' => 0];
}


/* ------------------------------------------------------------
   7. HELPERS
   ------------------------------------------------------------ */
$buildUrl = function (array $overrides = []) use ($filters, $sortKey): string {
    $params = array_merge([
        'search' => $filters['search'],
        'type'   => $filters['type'],
        'status' => $filters['status'],
        'sort'   => $sortKey,
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'letters.php' . ($params ? '?' . http_build_query($params) : '');
};

$hasFilters = $filters['search'] !== '' || $filters['type'] !== '' || $filters['status'] !== '';

// Type badge colors
$typeBadge = function (string $type): array {
    return match ($type) {
        'internal_memo'       => ['badge-info',      'Internal Memo'],
        'external_letter'     => ['badge-warning',   'External Letter'],
        'clearance_letter'    => ['badge-success',   'Clearance Letter'],
        'certificate_letter'  => ['badge-success',   'Certificate'],
        'transfer_letter'     => ['badge-primary',   'Transfer Letter'],
        'pension_letter'      => ['badge-secondary', 'Pension Letter'],
        'resignation_letter'  => ['badge-danger',    'Resignation Letter'],
        'maternal_letter'     => ['badge-info',      'Maternal Letter'],
        'service_certificate' => ['badge-secondary', 'Service Certificate'],
        default               => ['badge-secondary', ucwords(str_replace('_', ' ', $type))],
    };
};


/* ------------------------------------------------------------
   8. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Official Letters';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Official Letters (ደብዳቤዎች)</h1>
            <p class="muted" style="margin-top: 5px;">Manage internal memos, external communications, and certificates.</p>
        </div>
        <a href="letter_new.php" class="btn btn-primary">
            + Compose Letter (አዲስ ደብዳቤ)
        </a>
    </div>

    <!-- ============================================================
         SUMMARY CHIPS
         ============================================================ -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">✉️</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= (int)$summary['total'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Total Letters</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #9ca3af; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">📝</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #4b5563; line-height: 1;"><?= (int)$summary['draft'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Drafts</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #16a34a; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">📤</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #16a34a; line-height: 1;"><?= (int)$summary['sent'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Sent</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #6366f1; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">📁</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #6366f1; line-height: 1;"><?= (int)$summary['archived'] ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Archived</div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         FILTERS
         ============================================================ -->
    <div class="card" style="padding: 20px; margin-bottom: 20px; border-left-color: var(--mpc-flame);">
        <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            
            <div class="form-group" style="flex: 3; min-width: 220px; margin-bottom: 0;">
                <label>Search</label>
                <input type="text" name="search" placeholder="Ref no, subject, or recipient..."
                       value="<?= e($filters['search']) ?>">
            </div>

            <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
                <label>Letter Type</label>
                <select name="type">
                    <option value="">All Types</option>
                    <option value="internal_memo"       <?= $filters['type'] === 'internal_memo'       ? 'selected' : '' ?>>Internal Memo</option>
                    <option value="external_letter"     <?= $filters['type'] === 'external_letter'     ? 'selected' : '' ?>>External Letter</option>
                    <option value="clearance_letter"    <?= $filters['type'] === 'clearance_letter'    ? 'selected' : '' ?>>Clearance Letter</option>
                    <option value="certificate_letter"  <?= $filters['type'] === 'certificate_letter'  ? 'selected' : '' ?>>Certificate</option>
                    <option value="transfer_letter"     <?= $filters['type'] === 'transfer_letter'     ? 'selected' : '' ?>>Transfer Letter</option>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 130px; margin-bottom: 0;">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="draft"    <?= $filters['status'] === 'draft'    ? 'selected' : '' ?>>Draft</option>
                    <option value="sent"     <?= $filters['status'] === 'sent'     ? 'selected' : '' ?>>Sent</option>
                    <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 130px; margin-bottom: 0;">
                <label>Sort by</label>
                <select name="sort">
                    <option value="newest"  <?= $sortKey === 'newest'  ? 'selected' : '' ?>>Newest first</option>
                    <option value="oldest"  <?= $sortKey === 'oldest'  ? 'selected' : '' ?>>Oldest first</option>
                    <option value="subject" <?= $sortKey === 'subject' ? 'selected' : '' ?>>Subject (A-Z)</option>
                    <option value="ref"     <?= $sortKey === 'ref'     ? 'selected' : '' ?>>Ref No</option>
                </select>
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($hasFilters): ?>
                    <a href="letters.php" class="btn btn-ghost" style="border-color: #ccc;">Reset</a>
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
                Showing <strong><?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?></strong> of <strong><?= $total ?></strong> letters
            </p>
            <?php if ($totalPages > 1): ?>
                <p class="muted" style="margin: 0; font-size: 13px;">Page <?= $page ?> of <?= $totalPages ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ============================================================
         TABLE
         ============================================================ -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ref No (ቁጥር)</th>
                        <th>Type (አይነት)</th>
                        <th>Subject (ርዕስ)</th>
                        <th>Recipient (ተቀባይ)</th>
                        <th>Date (ቀን)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($letters as $l): 
                        [$typeColor, $typeLabel] = $typeBadge($l['letter_type']);
                    ?>
                    <tr>
                        <!-- Ref Number -->
                        <td>
                            <code style="font-weight: bold; color: var(--mpc-navy); font-size: 13px;">
                                <?= e($l['ref_no']) ?>
                            </code>
                            <?php if($l['doc_no']): ?>
                                <br><small class="muted">Doc: <?= e($l['doc_no']) ?></small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Type -->
                        <td>
                            <span class="badge <?= $typeColor ?>"><?= e($typeLabel) ?></span>
                        </td>
                        
                        <!-- Subject -->
                        <td>
                            <strong><?= e($l['subject']) ?></strong>
                            <?php if($l['subject_am']): ?>
                                <br><small class="muted"><?= e($l['subject_am']) ?></small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Recipient -->
                        <td>
                            <?php if ($l['recipient_type'] === 'internal' && $l['recipient_user_name']): ?>
                                <?= e($l['recipient_user_name']) ?>
                            <?php else: ?>
                                <?= e($l['recipient_name'] ?: 'External / General') ?>
                                <?php if($l['recipient_org']): ?>
                                    <br><small class="muted"><?= e($l['recipient_org']) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Date (Ethiopian) -->
                        <td>
                            <div style="font-weight: 500;"><?= format_date($l['created_at']) ?></div>
                            <small class="muted"><?= date('M j, Y', strtotime($l['created_at'])) ?></small>
                        </td>
                        
                        <!-- Status -->
                        <td>
                            <?= status_badge($l['status']) ?>
                        </td>
                        
                        <!-- Actions -->
                        <td>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <a href="letter_view.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-primary" title="View Details">
                                    👁️ View
                                </a>
                                
                                <a href="letter_print.php?id=<?= $l['id'] ?>" target="_blank" class="btn btn-sm btn-ghost" style="border-color: #ccc; color: #333;" title="Print / PDF">
                                    🖨️ Print
                                </a>

                                <?php if ($l['status'] !== 'archived' && has_role(['admin', 'registrar'])): ?>
                                    <a href="letter_archive.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-ghost" style="border-color: var(--mpc-red); color: var(--mpc-red);" onclick="return confirm('Archive this letter? It will be hidden from the active list.');" title="Archive">
                                        📁
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Empty State -->
                    <?php if (empty($letters)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 50px;">
                            <div style="font-size: 40px; margin-bottom: 15px;">✉️</div>
                            <?php if ($hasFilters): ?>
                                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No letters match these filters.</h3>
                                <p class="muted" style="font-size: 14px; margin-bottom: 20px;">Try adjusting your search or clearing the filters.</p>
                                <a href="letters.php" class="btn btn-ghost" style="border-color: #ccc;">Clear Filters</a>
                            <?php else: ?>
                                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No letters found.</h3>
                                <p class="muted" style="font-size: 14px; margin-bottom: 20px;">Your inbox and outbox are currently empty.</p>
                                <a href="letter_new.php" class="btn btn-primary">Compose your first letter</a>
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

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>