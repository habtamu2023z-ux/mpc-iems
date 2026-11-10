<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   NOTIFICATIONS MODULE
   ============================================================
   PURPOSE
     Canonical notification service for the entire application.
     Every approval event, assignment, and status change routes
     through this module.

   READ BY
     - includes/header.php       (bell badge — unread count)
     - notifications.php         (inbox page)
     - api/notifications.php     (AJAX poll — future)
     - includes/workflow_engine.php  (approval routing)

   CANONICAL FUNCTION API (kept for backward compat with
   workflow_engine.php, which was delivered earlier):
     notify(int $userId, string $title, string $body = '',
            string $type = 'info', ?string $link = null): void
     notify_role(string $roleCode, string $title, string $body = '',
                 string $type = 'info', ?string $link = null): void
     notify_department(string $deptCode, string $title, string $body = '',
                       string $type = 'info', ?string $link = null): void
     unread_notification_count(int $userId): int

   CLASS API (this file's additions)
     Notifier::send($userId, $title, $body, $type, $link)  - bool
     Notifier::toRole($roleCode, ...)                       - int (count)
     Notifier::toDepartment($deptCode, ...)                 - int (count)
     Notifier::sendToMany($userIds[], ...)                  - int (count)

     Typed shortcuts:
       Notifier::success($userId, $title, $body = '', $link = null)
       Notifier::warning($userId, $title, $body = '', $link = null)
       Notifier::danger($userId,  $title, $body = '', $link = null)
       Notifier::info($userId,    $title, $body = '', $link = null)

     Read:
       Notifier::unreadCount($userId)          - int
       Notifier::totalCount($userId)           - int
       Notifier::hasAnyUnread($userId)         - bool
       Notifier::recent($userId, $limit = 100) - array
       Notifier::findById($id, $userId)        - ?array
       Notifier::stats($userId)                - array

     Mark:
       Notifier::markAsRead($id, $userId)      - bool
       Notifier::markAllAsRead($userId)        - int (count)
       Notifier::markByLink($link, $userId)    - int (count)

     Delete / cleanup:
       Notifier::delete($id, $userId)          - bool
       Notifier::deleteAllRead($userId)        - int (count)
       Notifier::cleanup($daysOld = 90)        - int (count)

     Helpers:
       Notifier::linkTo($kind, $id)            - string
       Notifier::iconFor($type)                - string (emoji)
       Notifier::isValidType($type)            - bool

   SCHEMA DEPENDENCY
     Table: notifications
       id          BIGINT PK
       user_id     INT NOT NULL
       title       VARCHAR(200) NOT NULL
       body        VARCHAR(500) NULL
       link        VARCHAR(255) NULL
       type        ENUM('info','success','warning','danger')
       is_read     TINYINT(1) DEFAULT 0
       created_at  TIMESTAMP
       read_at     DATETIME NULL

   SAFETY
     - Direct access refused with 403
     - Safe to require_once multiple times
     - Never throws (all writes wrapped in try/catch)
     - Enforces column length limits (title 200, body 500, link 255)
     - Normalizes invalid types to 'info' (prevents ENUM errors)
     - Skips inserts for invalid user IDs (< 1)
     - Works in CLI (cron, tests)
   ============================================================ */


/* ------------------------------------------------------------
   1. DIRECT ACCESS GUARD
   ------------------------------------------------------------ */
if (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Direct access forbidden.');
}


/* ------------------------------------------------------------
   2. ENSURE BASE FUNCTIONS ARE LOADED
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';


/* ------------------------------------------------------------
   3. CLASS-BASED API
   ------------------------------------------------------------ */
if (!class_exists('Notifier', false)) {

    final class Notifier
    {
        /** Valid ENUM values in notifications.type. */
        public const TYPES = ['info', 'success', 'warning', 'danger'];

        /** Column length limits (must match schema). */
        private const MAX_TITLE = 200;
        private const MAX_BODY  = 500;
        private const MAX_LINK  = 255;


        /* ====================================================
           3.1 CORE — SEND
           ==================================================== */

        /**
         * Insert a single notification.
         *
         * @return bool  true on success, false on failure (logs to error_log)
         */
        public static function send(
            int $userId,
            string $title,
            string $body = '',
            string $type = 'info',
            ?string $link = null
        ): bool {
            // Guard
            if ($userId <= 0) return false;

            $title = trim($title);
            if ($title === '') return false;

            // Enforce length limits
            $title = self::truncate($title, self::MAX_TITLE);
            $body  = self::truncate(trim($body), self::MAX_BODY);
            $link  = $link !== null ? self::truncate(trim($link), self::MAX_LINK) : null;

            // Normalize type
            $type = self::normalizeType($type);

            try {
                global $pdo;
                $st = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, body, type, link)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $st->execute([
                    $userId,
                    $title,
                    $body !== '' ? $body : null,
                    $type,
                    $link !== '' ? $link : null,
                ]);
                return true;
            } catch (PDOException $e) {
                error_log('Notifier::send() failed: ' . $e->getMessage());
                return false;
            }
        }


        /* ====================================================
           3.2 TARGETED SEND
           ==================================================== */

        /**
         * Notify every user holding a given role.
         *
         * @return int  Number of notifications inserted
         */
        public static function toRole(
            string $roleCode,
            string $title,
            string $body = '',
            string $type = 'info',
            ?string $link = null
        ): int {
            try {
                global $pdo;
                $st = $pdo->prepare("
                    SELECT DISTINCT ur.user_id
                    FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    WHERE r.code = ? AND ur.user_id > 0
                ");
                $st->execute([$roleCode]);
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (PDOException $e) {
                error_log('Notifier::toRole() lookup failed: ' . $e->getMessage());
                return 0;
            }

            return self::sendToMany($ids, $title, $body, $type, $link);
        }

        /**
         * Notify every active user in a given department code.
         *
         * @return int  Number of notifications inserted
         */
        public static function toDepartment(
            string $deptCode,
            string $title,
            string $body = '',
            string $type = 'info',
            ?string $link = null
        ): int {
            try {
                global $pdo;
                $st = $pdo->prepare("
                    SELECT u.id
                    FROM users u
                    JOIN departments d ON d.id = u.department_id
                    WHERE d.code = ?
                      AND u.status = 'active'
                      AND u.deleted_at IS NULL
                ");
                $st->execute([$deptCode]);
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (PDOException $e) {
                error_log('Notifier::toDepartment() lookup failed: ' . $e->getMessage());
                return 0;
            }

            return self::sendToMany($ids, $title, $body, $type, $link);
        }

        /**
         * Send the same notification to multiple users.
         *
         * @param  int[] $userIds
         * @return int   Number successfully inserted
         */
        public static function sendToMany(
            array $userIds,
            string $title,
            string $body = '',
            string $type = 'info',
            ?string $link = null
        ): int {
            $userIds = array_unique(array_filter(
                array_map('intval', $userIds),
                fn($id) => $id > 0
            ));

            if (empty($userIds)) return 0;

            $sent = 0;
            foreach ($userIds as $uid) {
                if (self::send($uid, $title, $body, $type, $link)) {
                    $sent++;
                }
            }
            return $sent;
        }


        /* ====================================================
           3.3 TYPED SHORTCUTS
           ==================================================== */

        public static function success(int $userId, string $title, string $body = '', ?string $link = null): bool
        {
            return self::send($userId, $title, $body, 'success', $link);
        }

        public static function warning(int $userId, string $title, string $body = '', ?string $link = null): bool
        {
            return self::send($userId, $title, $body, 'warning', $link);
        }

        public static function danger(int $userId, string $title, string $body = '', ?string $link = null): bool
        {
            return self::send($userId, $title, $body, 'danger', $link);
        }

        public static function info(int $userId, string $title, string $body = '', ?string $link = null): bool
        {
            return self::send($userId, $title, $body, 'info', $link);
        }


        /* ====================================================
           3.4 READ METHODS
           ==================================================== */

        /**
         * Unread count for a user (used by header.php bell).
         */
        public static function unreadCount(int $userId): int
        {
            if ($userId <= 0) return 0;
            try {
                global $pdo;
                $st = $pdo->prepare("
                    SELECT COUNT(*) FROM notifications
                    WHERE user_id = ? AND is_read = 0
                ");
                $st->execute([$userId]);
                return (int)$st->fetchColumn();
            } catch (PDOException $e) {
                error_log('Notifier::unreadCount() failed: ' . $e->getMessage());
                return 0;
            }
        }

        /** Total notifications for a user (read + unread). */
        public static function totalCount(int $userId): int
        {
            if ($userId <= 0) return 0;
            try {
                global $pdo;
                $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
                $st->execute([$userId]);
                return (int)$st->fetchColumn();
            } catch (PDOException $e) {
                error_log('Notifier::totalCount() failed: ' . $e->getMessage());
                return 0;
            }
        }

        /** Convenience: any unread? (for nav badges, email suppression) */
        public static function hasAnyUnread(int $userId): bool
        {
            return self::unreadCount($userId) > 0;
        }

        /**
         * Recent notifications for a user.
         *
         * @param int $limit  Max 500 (protects against accidental full-table read)
         * @param bool $unreadOnly
         */
        public static function recent(int $userId, int $limit = 100, bool $unreadOnly = false): array
        {
            if ($userId <= 0) return [];
            $limit = max(1, min(500, $limit));

            try {
                global $pdo;
                $where = $unreadOnly ? 'AND is_read = 0' : '';
                $st = $pdo->prepare("
                    SELECT * FROM notifications
                    WHERE user_id = ? {$where}
                    ORDER BY created_at DESC, id DESC
                    LIMIT {$limit}
                ");
                $st->execute([$userId]);
                return $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Notifier::recent() failed: ' . $e->getMessage());
                return [];
            }
        }

        /**
         * Fetch one notification by ID, scoped to a user (prevents
         * cross-user data leaks).
         */
        public static function findById(int $id, int $userId): ?array
        {
            if ($id <= 0 || $userId <= 0) return null;
            try {
                global $pdo;
                $st = $pdo->prepare("
                    SELECT * FROM notifications
                    WHERE id = ? AND user_id = ?
                    LIMIT 1
                ");
                $st->execute([$id, $userId]);
                $row = $st->fetch();
                return $row ?: null;
            } catch (PDOException $e) {
                error_log('Notifier::findById() failed: ' . $e->getMessage());
                return null;
            }
        }

        /**
         * Summary stats for a user.
         * @return array{total:int, unread:int, by_type:array<string,int>}
         */
        public static function stats(int $userId): array
        {
            $out = ['total' => 0, 'unread' => 0, 'by_type' => []];
            if ($userId <= 0) return $out;

            try {
                global $pdo;
                $st = $pdo->prepare("
                    SELECT type, is_read, COUNT(*) AS cnt
                    FROM notifications
                    WHERE user_id = ?
                    GROUP BY type, is_read
                ");
                $st->execute([$userId]);

                foreach ($st->fetchAll() as $row) {
                    $cnt = (int)$row['cnt'];
                    $out['total'] += $cnt;
                    if ((int)$row['is_read'] === 0) {
                        $out['unread'] += $cnt;
                    }
                    $out['by_type'][$row['type']] = ($out['by_type'][$row['type']] ?? 0) + $cnt;
                }
            } catch (PDOException $e) {
                error_log('Notifier::stats() failed: ' . $e->getMessage());
            }

            return $out;
        }


        /* ====================================================
           3.5 MARK AS READ
           ==================================================== */

        /**
         * Mark one notification as read (scoped to owner).
         * @return bool  true if a row was updated
         */
        public static function markAsRead(int $id, int $userId): bool
        {
            if ($id <= 0 || $userId <= 0) return false;
            try {
                global $pdo;
                $st = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1, read_at = NOW()
                    WHERE id = ? AND user_id = ? AND is_read = 0
                ");
                $st->execute([$id, $userId]);
                return $st->rowCount() > 0;
            } catch (PDOException $e) {
                error_log('Notifier::markAsRead() failed: ' . $e->getMessage());
                return false;
            }
        }

        /**
         * Mark all notifications for a user as read.
         * @return int  Rows updated
         */
        public static function markAllAsRead(int $userId): int
        {
            if ($userId <= 0) return 0;
            try {
                global $pdo;
                $st = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1, read_at = NOW()
                    WHERE user_id = ? AND is_read = 0
                ");
                $st->execute([$userId]);
                return $st->rowCount();
            } catch (PDOException $e) {
                error_log('Notifier::markAllAsRead() failed: ' . $e->getMessage());
                return 0;
            }
        }

        /**
         * Mark as read every notification pointing to a given link.
         * Useful when the user navigates directly to the target page
         * (bypassing notifications.php's ?read=N click).
         */
        public static function markByLink(string $link, int $userId): int
        {
            if ($link === '' || $userId <= 0) return 0;
            try {
                global $pdo;
                $st = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1, read_at = NOW()
                    WHERE link = ? AND user_id = ? AND is_read = 0
                ");
                $st->execute([$link, $userId]);
                return $st->rowCount();
            } catch (PDOException $e) {
                error_log('Notifier::markByLink() failed: ' . $e->getMessage());
                return 0;
            }
        }


        /* ====================================================
           3.6 DELETE / CLEANUP
           ==================================================== */

        public static function delete(int $id, int $userId): bool
        {
            if ($id <= 0 || $userId <= 0) return false;
            try {
                global $pdo;
                $st = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
                $st->execute([$id, $userId]);
                return $st->rowCount() > 0;
            } catch (PDOException $e) {
                error_log('Notifier::delete() failed: ' . $e->getMessage());
                return false;
            }
        }

        /** @return int  Rows deleted */
        public static function deleteAllRead(int $userId): int
        {
            if ($userId <= 0) return 0;
            try {
                global $pdo;
                $st = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
                $st->execute([$userId]);
                return $st->rowCount();
            } catch (PDOException $e) {
                error_log('Notifier::deleteAllRead() failed: ' . $e->getMessage());
                return 0;
            }
        }

        /**
         * Global cleanup — delete READ notifications older than N days.
         * Intended for a daily cron job (not for interactive requests).
         *
         * @return int  Rows deleted (across all users)
         */
        public static function cleanup(int $daysOld = 90): int
        {
            $daysOld = max(1, min(3650, $daysOld));
            try {
                global $pdo;
                $st = $pdo->prepare("
                    DELETE FROM notifications
                    WHERE is_read = 1
                      AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                ");
                $st->execute([$daysOld]);
                return $st->rowCount();
            } catch (PDOException $e) {
                error_log('Notifier::cleanup() failed: ' . $e->getMessage());
                return 0;
            }
        }


        /* ====================================================
           3.7 HELPERS
           ==================================================== */

        /**
         * Build a canonical link for a notification target.
         *
         *   Notifier::linkTo('clearance', 5)  → APP_URL/modules/clearance/clearance_view.php?id=5
         *   Notifier::linkTo('leave', 12)     → APP_URL/modules/leave/leave_view.php?id=12
         *   Notifier::linkTo('letter', 3)     → APP_URL/modules/letters/letter_view.php?id=3
         *   Notifier::linkTo('request', 42)   → APP_URL/modules/requests/request_view.php?id=42
         */
        public static function linkTo(string $kind, int $id): string
        {
            $path = match (strtolower($kind)) {
                'clearance' => '/modules/clearance/clearance_view.php',
                'leave'     => '/modules/leave/leave_view.php',
                'letter'    => '/modules/letters/letter_view.php',
                'request'   => '/modules/requests/request_view.php',
                'maternal'  => '/modules/maternal/maternal_view.php',
                'pension'   => '/modules/pension/pension_view.php',
                'resignation' => '/modules/resignation/resignation_view.php',
                'procurement' => '/modules/procurement/procurement_view.php',
                'plan'        => '/modules/planning/plan_view.php',
                'user'        => '/admin/user_edit.php',
                'department'  => '/admin/department_edit.php',
                default       => '/notifications.php',
            };

            return APP_URL . $path . ($id > 0 ? '?id=' . $id : '');
        }

        /** Emoji icon for a notification type (used in notifications.php). */
        public static function iconFor(string $type): string
        {
            return match ($type) {
                'success' => '✅',
                'warning' => '⚠️',
                'danger'  => '❌',
                default   => '💬',
            };
        }

        /** Is this a valid notifications.type ENUM value? */
        public static function isValidType(string $type): bool
        {
            return in_array($type, self::TYPES, true);
        }

        /** Normalize an invalid type to 'info' (safety). */
        private static function normalizeType(string $type): string
        {
            $type = strtolower(trim($type));
            return self::isValidType($type) ? $type : 'info';
        }

        /** Truncate a string to a maximum byte-safe length. */
        private static function truncate(string $str, int $max): string
        {
            if (mb_strlen($str, 'UTF-8') <= $max) return $str;
            return mb_substr($str, 0, $max - 1, 'UTF-8') . '…';
        }
    }
}


/* ------------------------------------------------------------
   4. CANONICAL FUNCTION API
   Preserved for backward compatibility with workflow_engine.php
   and any existing modules. All delegate to Notifier.
   ------------------------------------------------------------ */

if (!function_exists('notify')) {
    /**
     * Create a notification for one user.
     * @deprecated since WAVE 2 — use Notifier::send() for typed return
     */
    function notify(
        int $userId,
        string $title,
        string $body = '',
        string $type = 'info',
        ?string $link = null
    ): void {
        Notifier::send($userId, $title, $body, $type, $link);
    }
}

if (!function_exists('notify_role')) {
    /**
     * Notify all users holding a given role.
     * @deprecated since WAVE 2 — use Notifier::toRole() for count return
     */
    function notify_role(
        string $roleCode,
        string $title,
        string $body = '',
        string $type = 'info',
        ?string $link = null
    ): void {
        Notifier::toRole($roleCode, $title, $body, $type, $link);
    }
}

if (!function_exists('notify_department')) {
    /**
     * Notify all active users in a given department.
     * @deprecated since WAVE 2 — use Notifier::toDepartment() for count return
     */
    function notify_department(
        string $deptCode,
        string $title,
        string $body = '',
        string $type = 'info',
        ?string $link = null
    ): void {
        Notifier::toDepartment($deptCode, $title, $body, $type, $link);
    }
}

if (!function_exists('unread_notification_count')) {
    /**
     * Count unread notifications for a user.
     * @deprecated since WAVE 2 — use Notifier::unreadCount()
     */
    function unread_notification_count(int $userId): int
    {
        return Notifier::unreadCount($userId);
    }
}