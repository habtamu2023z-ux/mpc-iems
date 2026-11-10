<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   NOTIFICATIONS INBOX
   ============================================================
   PURPOSE
     Full notification management interface for logged-in users.
     Entry point from the header bell (<?= APP_URL ?>/notifications.php).

   FEATURES
     - List with filters (type, read state, search)
     - Pagination (50 per page)
     - Click a notification → mark as read + redirect to its target
     - Per-row delete
     - Bulk actions: mark selected as read, delete selected
     - Bulk actions: mark all as read, delete all read
     - Summary chips by type
     - Filter-aware pagination and action redirects
     - Age display ("2h ago") with full Ethiopian date on hover

   GET ROUTES
     notifications.php                      → list (default)
     notifications.php?read=N               → mark #N as read + redirect to link
     notifications.php?type=X&state=Y&q=Z   → filtered list
     notifications.php?page=N               → pagination

   POST ACTIONS (all require CSRF)
     action=mark_all_read                   → mark all user's notifications as read
     action=delete&id=N                     → delete one notification
     action=delete_read                     → delete all read notifications
     action=mark_selected_read&ids[]=...    → bulk mark selected as read
     action=delete_selected&ids[]=...       → bulk delete selected

   DEPENDENCIES
     functions.php          — base helpers, format_date
     includes/auth_check.php — Auth::require
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, danger, warning, info
     includes/audit.php     — Audit::viewed, Audit::deleted
     includes/notification.php — Notifier (read, mark, delete)

   SAFETY
     - All mutations scoped to `user_id = current_user['id']` (via Notifier)
     - CSRF on all POST state changes
     - `?read=N` cannot leak other users' notifications (scoped via findById)
     - Bulk actions validate each ID via Notifier::delete/markAsRead (no cross-user)
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/notification.php';


/* ------------------------------------------------------------
   2. AUTH
   ------------------------------------------------------------ */
$user   = Auth::require();
$userId = (int)$user['id'];


/* ------------------------------------------------------------
   3. COLLECT FILTERS (used by both redirect targets and list query)
   ------------------------------------------------------------ */
$filterType  = (string)($_GET['type']  ?? '');
$filterState = (string)($_GET['state'] ?? '');
$filterQ     = trim((string)($_GET['q'] ?? ''));

if ($filterType !== '' && !Notifier::isValidType($filterType)) $filterType = '';
if (!in_array($filterState, ['', 'unread', 'read'], true))     $filterState = '';

$buildUrl = function (array $overrides = []) use ($filterType, $filterState, $filterQ): string {
    $params = array_merge([
        'type'  => $filterType,
        'state' => $filterState,
        'q'     => $filterQ,
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'notifications.php' . ($params ? '?' . http_build_query($params) : '');
};


/* ------------------------------------------------------------
   4. GET ?read=N — MARK SINGLE AS READ + REDIRECT
   ============================================================
   Standard "click notification → go to its target" UX.
   Marking as read is a side effect of viewing the notification.
   Not vulnerable in practice: an attacker can only mark YOUR
   OWN notification as read (worst case: hides one item), and
   the action is scoped to the current user via findById().
   ------------------------------------------------------------ */
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];

    if ($nid > 0) {
        $notif = Notifier::findById($nid, $userId);
        if ($notif) {
            Notifier::markAsRead($nid, $userId);

            if (!empty($notif['link'])) {
                redirect($notif['link']);
            }
        }
    }

    // No link, or notification not found — go back to current filter view
    redirect($buildUrl());
}


/* ------------------------------------------------------------
   5. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    $postAction = (string)($_POST['action'] ?? '');

    /* ---------- MARK ALL AS READ ---------- */
    if ($postAction === 'mark_all_read') {
        $count = Notifier::markAllAsRead($userId);
        if ($count > 0) {
            Flash::success("Marked <strong>{$count}</strong> notification" . ($count === 1 ? '' : 's') . " as read.");
        } else {
            Flash::info('No unread notifications to mark.');
        }
        redirect($buildUrl());
    }

    /* ---------- DELETE ONE ---------- */
    if ($postAction === 'delete') {
        $nid = (int)($_POST['id'] ?? 0);
        if ($nid > 0) {
            if (Notifier::delete($nid, $userId)) {
                Flash::success('Notification deleted.');
            } else {
                Flash::danger('Could not delete the notification.');
            }
        }
        redirect($buildUrl());
    }

    /* ---------- DELETE ALL READ ---------- */
    if ($postAction === 'delete_read') {
        $count = Notifier::deleteAllRead($userId);
        if ($count > 0) {
            Flash::success("Deleted <strong>{$count}</strong> read notification" . ($count === 1 ? '' : 's') . ".");
        } else {
            Flash::info('No read notifications to delete.');
        }
        redirect($buildUrl());
    }

    /* ---------- MARK SELECTED AS READ ---------- */
    if ($postAction === 'mark_selected_read') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_filter($ids, fn($id) => $id > 0);

        $count = 0;
        foreach ($ids as $id) {
            if (Notifier::markAsRead($id, $userId)) $count++;
        }

        if ($count > 0) {
            Flash::success("Marked <strong>{$count}</strong> notification" . ($count === 1 ? '' : 's') . " as read.");
        } else {
            Flash::info('No notifications were updated (already read or not found).');
        }
        redirect($buildUrl());
    }

    /* ---------- DELETE SELECTED ---------- */
    if ($postAction === 'delete_selected') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_filter($ids, fn($id) => $id > 0);

        $count = 0;
        foreach ($ids as $id) {
            if (Notifier::delete($id, $userId)) $count++;
        }

        if ($count > 0) {
            Flash::success("Deleted <strong>{$count}</strong> notification" . ($count === 1 ? '' : 's') . ".");
        } else {
            Flash::danger('No notifications were deleted.');
        }
        redirect($buildUrl());
    }

    // Unknown action
    Flash::warning('Unknown action.');
    redirect($buildUrl());
}


/* ------------------------------------------------------------
   6. PAGINATION + FILTERED QUERY
   ------------------------------------------------------------ */
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

$whereParts = ['user_id = ?'];
$args       = [$userId];

if ($filterType !== '') {
    $whereParts[] = 'type = ?';
    $args[] = $filterType;
}
if ($filterState === 'unread') {
    $whereParts[] = 'is_read = 0';
} elseif ($filterState === 'read') {
    $whereParts[] = 'is_read = 1';
}
if ($filterQ !== '') {
    $like = '%' . $filterQ . '%';
    $whereParts[] = '(title LIKE ? OR body LIKE ?)';
    $args[] = $like;
    $args[] = $like;
}

$whereSql = implode(' AND ', $whereParts);

// Count
$st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$whereSql}");
$st->execute($args);
$total = (int)$st->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// Fetch — unread first, then by newest
$st = $pdo->prepare("
    SELECT * FROM notifications
    WHERE {$whereSql}
    ORDER BY is_read ASC, created_at DESC, id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$st->execute($args);
$notifications = $st->fetchAll();


/* ------------------------------------------------------------
   7. SUMMARY STATS
   ------------------------------------------------------------ */
$stats     = Notifier::stats($userId);
$totalAll  = (int)($stats['total']  ?? 0);
$unreadAll = (int)($stats['unread'] ?? 0);
$byType    = $stats['by_type'] ?? [];

$hasFilters = ($filterType !== '') || ($filterState !== '') || ($filterQ !== '');


/* ------------------------------------------------------------
   8. HELPERS
   ------------------------------------------------------------ */

/** Human-readable age: "2h ago", "3d ago", "just now". */
function mpc_notif_age(int $unixTs): string
{
    $age = time() - $unixTs;
    if ($age < 60)     return 'just now';
    if ($age < 3600)   return intdiv($age, 60) . 'm ago';
    if ($age < 86400)  return intdiv($age, 3600) . 'h ago';
    if ($age < 604800) return intdiv($age, 86400) . 'd ago';
    return intdiv($age, 604800) . 'w ago';
}


/* ------------------------------------------------------------
   9. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Notifications';
include __DIR__ . '/includes/header.php';
?>

<div style="max-width: 1000px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Notifications (ማሳወቂያዎች)</h1>
            <p class="muted" style="margin-top: 5px;">
                <?php if ($unreadAll > 0): ?>
                    <strong style="color: var(--mpc-red);"><?= $unreadAll ?></strong> unread
                    · <?= $totalAll ?> total
                <?php else: ?>
                    All caught up · <?= $totalAll ?> total
                <?php endif; ?>
            </p>
        </div>

        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php if ($unreadAll > 0): ?>
                <form method="post" style="display: inline;">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-primary">
                        ✓ Mark All Read
                    </button>
                </form>
            <?php endif; ?>
            <?php if (($byType ? array_sum($byType) : 0) - $unreadAll > 0): ?>
                <form method="post" style="display: inline;"
                      data-confirm="Delete all read notifications? This cannot be undone.">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete_read">
                    <button type="submit" class="btn btn-ghost" style="border-color: var(--mpc-red); color: var(--mpc-red);">
                        🗑️ Cleanup Read
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         SUMMARY CHIPS
         ============================================================ -->
    <?php if ($totalAll > 0): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 20px;">
        <a href="<?= e($buildUrl(['state' => '', 'type' => '', 'q' => '', 'page' => ''])) ?>"
           style="text-decoration: none;"
           class="card"
           title="All notifications">
            <div style="margin:0; padding: 10px 14px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 10px; background: <?= ($hasFilters ? '#fff' : '#F0F9FF') ?>; border-radius: 0 8px 8px 0;">
                <div style="font-size: 20px;">📬</div>
                <div>
                    <div style="font-size: 18px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= $totalAll ?></div>
                    <div style="font-size: 10px; color: #6B7280; font-weight: 700; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.5px;">Total</div>
                </div>
            </div>
        </a>

        <a href="<?= e($buildUrl(['state' => 'unread', 'page' => ''])) ?>"
           style="text-decoration: none;"
           class="card"
           title="Unread notifications">
            <div style="margin:0; padding: 10px 14px; border-left: 4px solid var(--mpc-red); display: flex; align-items: center; gap: 10px; background: <?= ($filterState === 'unread' ? '#F0F9FF' : '#fff') ?>; border-radius: 0 8px 8px 0;">
                <div style="font-size: 20px;">🔴</div>
                <div>
                    <div style="font-size: 18px; font-weight: 800; color: var(--mpc-red); line-height: 1;"><?= $unreadAll ?></div>
                    <div style="font-size: 10px; color: #6B7280; font-weight: 700; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.5px;">Unread</div>
                </div>
            </div>
        </a>

        <?php foreach (['info' => ['💬', 'var(--mpc-blue)', 'Info'],
                        'success' => ['✅', '#16a34a', 'Success'],
                        'warning' => ['⚠️', 'var(--mpc-flame)', 'Warning'],
                        'danger'  => ['❌', 'var(--mpc-red)', 'Danger']] as $type => $meta): ?>
            <?php $count = (int)($byType[$type] ?? 0); ?>
            <a href="<?= e($buildUrl(['type' => $count > 0 ? $type : '', 'page' => ''])) ?>"
               style="text-decoration: none; <?= $count === 0 ? 'opacity: 0.5; pointer-events: none;' : '' ?>"
               class="card"
               title="<?= $meta[2] ?> notifications">
                <div style="margin:0; padding: 10px 14px; border-left: 4px solid <?= $meta[1] ?>; display: flex; align-items: center; gap: 10px; background: <?= ($filterType === $type ? '#F0F9FF' : '#fff') ?>; border-radius: 0 8px 8px 0;">
                    <div style="font-size: 20px;"><?= $meta[0] ?></div>
                    <div>
                        <div style="font-size: 18px; font-weight: 800; color: <?= $meta[1] ?>; line-height: 1;"><?= $count ?></div>
                        <div style="font-size: 10px; color: #6B7280; font-weight: 700; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.5px;"><?= $meta[2] ?></div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         FILTER BAR
         ============================================================ -->
    <div class="card" style="padding: 16px 20px; margin-bottom: 20px; border-left-color: var(--mpc-flame);">
        <form method="get" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="flex: 3; min-width: 220px; margin-bottom: 0;">
                <label>Search</label>
                <input type="text" name="q" placeholder="Search notifications..."
                       value="<?= e($filterQ) ?>">
            </div>
            <div class="form-group" style="flex: 1; min-width: 130px; margin-bottom: 0;">
                <label>Type</label>
                <select name="type">
                    <option value="">All Types</option>
                    <option value="info"    <?= $filterType === 'info'    ? 'selected' : '' ?>>Info</option>
                    <option value="success" <?= $filterType === 'success' ? 'selected' : '' ?>>Success</option>
                    <option value="warning" <?= $filterType === 'warning' ? 'selected' : '' ?>>Warning</option>
                    <option value="danger"  <?= $filterType === 'danger'  ? 'selected' : '' ?>>Danger</option>
                </select>
            </div>
            <div class="form-group" style="flex: 1; min-width: 130px; margin-bottom: 0;">
                <label>State</label>
                <select name="state">
                    <option value="">All States</option>
                    <option value="unread" <?= $filterState === 'unread' ? 'selected' : '' ?>>Unread</option>
                    <option value="read"   <?= $filterState === 'read'   ? 'selected' : '' ?>>Read</option>
                </select>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($hasFilters): ?>
                    <a href="notifications.php" class="btn btn-ghost" style="border-color: #ccc;">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ============================================================
         BULK ACTIONS BAR (shown when selections exist)
         ============================================================ -->
    <div id="bulk-bar" style="display: none; background: var(--mpc-navy); color: #fff; border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <div style="font-size: 13px; font-weight: 600;">
            <span id="bulk-count">0</span> selected
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="button" onclick="submitBulk('mark_selected_read')" class="btn btn-sm" style="background: #16a34a; color: #fff; border-color: #16a34a;">
                ✓ Mark as Read
            </button>
            <button type="button" onclick="submitBulk('delete_selected')" class="btn btn-sm" style="background: var(--mpc-red); color: #fff; border-color: var(--mpc-red);">
                🗑️ Delete
            </button>
            <button type="button" onclick="clearSelection()" class="btn btn-sm" style="background: transparent; color: #fff; border-color: rgba(255,255,255,0.3);">
                Cancel
            </button>
        </div>
    </div>

    <!-- Hidden form used by bulk actions -->
    <form id="bulk-form" method="post" style="display: none;">
        <?= Csrf::field() ?>
    </form>

    <!-- ============================================================
         RESULTS COUNT
         ============================================================ -->
    <?php if ($total > 0): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding: 0 4px; flex-wrap: wrap; gap: 8px;">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #6B7280; cursor: pointer;">
                <input type="checkbox" id="select-all" onchange="toggleAll(this.checked)"
                       style="width: 16px; height: 16px; accent-color: var(--mpc-navy);">
                Select all on this page
            </label>
            <p class="muted" style="margin: 0; font-size: 13px;">
                Showing <strong><?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?></strong> of <strong><?= $total ?></strong>
                <?php if ($hasFilters): ?>
                    · <a href="notifications.php" style="color: var(--mpc-blue);">Clear filters</a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- ============================================================
         NOTIFICATIONS LIST
         ============================================================ -->
    <div class="card" style="padding: 0; overflow: hidden;">

        <?php if (empty($notifications)): ?>
            <div style="text-align: center; padding: 60px 20px;">
                <div style="font-size: 48px; margin-bottom: 15px;"><?= $hasFilters ? '🔍' : ($unreadAll === 0 && $totalAll > 0 ? '✅' : '🔔') ?></div>
                <?php if ($hasFilters): ?>
                    <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No notifications match these filters.</h3>
                    <p class="muted" style="font-size: 14px; margin-bottom: 20px;">
                        Try adjusting your search or clearing the filters.
                    </p>
                    <a href="notifications.php" class="btn btn-ghost" style="border-color: #ccc;">Clear Filters</a>
                <?php elseif ($unreadAll === 0 && $totalAll > 0): ?>
                    <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">All caught up! 🎉</h3>
                    <p class="muted" style="font-size: 14px;">
                        You have no unread notifications.
                    </p>
                <?php else: ?>
                    <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No notifications yet.</h3>
                    <p class="muted" style="font-size: 14px;">
                        You'll see approvals, assignments, and system updates here.
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $i => $n):
                $isRead    = (int)$n['is_read'] === 1;
                $type      = (string)($n['type'] ?? 'info');
                $icon      = Notifier::iconFor($type);
                $mtime     = strtotime((string)$n['created_at']);
                $age       = mpc_notif_age($mtime);
                $fullDate  = format_date($n['created_at'], true);
                $gregDate  = date('M j, Y \a\t H:i', $mtime);
                $hasLink   = !empty($n['link']);
                $typeColors = [
                    'info'    => '#4DA6E8',
                    'success' => '#16a34a',
                    'warning' => '#F59E0B',
                    'danger'  => '#DC2626',
                ];
                $accentColor = $typeColors[$type] ?? '#6B7280';
            ?>
                <div class="notif-item" style="display: flex; align-items: stretch; gap: 0; border-bottom: <?= $i === count($notifications) - 1 ? 'none' : '1px solid var(--mpc-border)' ?>; background: <?= $isRead ? '#fff' : '#F0F9FF' ?>; transition: background 0.15s;">

                    <!-- Checkbox -->
                    <div style="display: flex; align-items: flex-start; padding: 18px 6px 18px 16px; flex-shrink: 0;">
                        <input type="checkbox"
                               class="notif-checkbox"
                               data-id="<?= (int)$n['id'] ?>"
                               onchange="toggleSelect(<?= (int)$n['id'] ?>, this.checked)"
                               style="width: 16px; height: 16px; accent-color: var(--mpc-navy); cursor: pointer; margin-top: 2px;">
                    </div>

                    <!-- Clickable content -->
                    <a href="notifications.php?read=<?= (int)$n['id'] ?><?= $hasFilters ? '&' . http_build_query(array_filter(['type' => $filterType, 'state' => $filterState, 'q' => $filterQ])) : '' ?>"
                       style="flex: 1; display: flex; gap: 14px; padding: 16px 16px 16px 6px; text-decoration: none; color: inherit; min-width: 0;"
                       onmouseover="this.parentElement.style.background='#FAFBFC'"
                       onmouseout="this.parentElement.style.background='<?= $isRead ? '#fff' : '#F0F9FF' ?>'">

                        <!-- Icon -->
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: <?= $accentColor ?>20; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; border-left: 3px solid <?= $accentColor ?>;">
                            <?= $icon ?>
                        </div>

                        <!-- Body -->
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;">
                                <strong style="color: var(--mpc-navy); font-size: 14px; line-height: 1.4; <?= $isRead ? 'font-weight: 600;' : '' ?>">
                                    <?= e($n['title']) ?>
                                </strong>
                                <?php if (!$isRead): ?>
                                    <span class="badge badge-danger" style="font-size: 9px; padding: 2px 6px;">NEW</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($n['body'])): ?>
                                <div style="font-size: 13px; color: #4B5563; line-height: 1.5; margin-bottom: 6px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                                    <?= e($n['body']) ?>
                                </div>
                            <?php endif; ?>

                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 11px; color: #9CA3AF;">
                                <span title="<?= e($gregDate) ?>">
                                    <?= e($age) ?> · <?= e($fullDate) ?>
                                </span>
                                <?php if ($hasLink): ?>
                                    <span style="color: var(--mpc-blue); font-weight: 600;">→ Open target</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>

                    <!-- Per-row actions -->
                    <div style="display: flex; align-items: flex-start; padding: 16px 16px 16px 6px; flex-shrink: 0;">
                        <form method="post" style="display: inline;"
                              data-confirm="Delete this notification?"
                              onsubmit="event.stopPropagation();">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                            <button type="submit"
                                    class="btn btn-sm btn-ghost"
                                    style="border-color: #e5e7eb; color: var(--mpc-red); padding: 6px 10px;"
                                    title="Delete notification">
                                🗑️
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
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

<!-- ============================================================
     BULK SELECTION JS
     ============================================================ -->
<script>
(function() {
    'use strict';

    window.selectedIds = new Set();
    var CSRF = <?= json_encode(csrf_token()) ?>;

    window.toggleSelect = function(id, checked) {
        if (checked) {
            window.selectedIds.add(id);
        } else {
            window.selectedIds.delete(id);
        }
        updateBulkBar();
    };

    window.toggleAll = function(checked) {
        document.querySelectorAll('.notif-checkbox').forEach(function(cb) {
            cb.checked = checked;
            var id = parseInt(cb.dataset.id, 10);
            if (checked) window.selectedIds.add(id);
            else window.selectedIds.delete(id);
        });
        updateBulkBar();
    };

    window.clearSelection = function() {
        window.selectedIds.clear();
        document.querySelectorAll('.notif-checkbox').forEach(function(cb) {
            cb.checked = false;
        });
        var selectAll = document.getElementById('select-all');
        if (selectAll) selectAll.checked = false;
        updateBulkBar();
    };

    function updateBulkBar() {
        var bar = document.getElementById('bulk-bar');
        var count = document.getElementById('bulk-count');
        if (!bar || !count) return;
        if (window.selectedIds.size > 0) {
            count.textContent = window.selectedIds.size;
            bar.style.display = 'flex';
        } else {
            bar.style.display = 'none';
        }
    }

    window.submitBulk = function(action) {
        if (window.selectedIds.size === 0) return;

        if (action === 'delete_selected' && !confirm('Delete ' + window.selectedIds.size + ' selected notification(s)? This cannot be undone.')) {
            return;
        }

        var form = document.getElementById('bulk-form');
        if (!form) return;

        form.innerHTML = '';
        form.innerHTML += '<input type="hidden" name="csrf" value="' + CSRF + '">';
        form.innerHTML += '<input type="hidden" name="action" value="' + action + '">';
        window.selectedIds.forEach(function(id) {
            form.innerHTML += '<input type="hidden" name="ids[]" value="' + id + '">';
        });
        form.submit();
    };
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>