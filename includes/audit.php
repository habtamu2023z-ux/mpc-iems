<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   AUDIT LOGGING MODULE
   ============================================================
   PURPOSE
     Safety wrapper around log_audit() from functions.php,
     plus diff-tracking, snapshot helpers, action constants,
     and read methods for building audit viewers.

   THE CANONICAL FUNCTION (defined in functions.php)
     log_audit(string $action, string $entity, int $entity_id,
               array $old = [], array $new = []): void
       - Inserts a row into audit_log with current user,
         IP, user agent, and timestamp

   THIS FILE ADDS
     Audit::log(...)              - Alias for log_audit()
     Audit::created(...)          - Convenience: action = 'CREATE'
     Audit::updated(...)          - action = 'UPDATE'
     Audit::deleted(...)          - action = 'DELETE'
     Audit::viewed(...)           - action = 'VIEW'
     Audit::loggedIn(...)         - action = 'LOGIN'
     Audit::loggedOut(...)        - action = 'LOGOUT'
     Audit::approved(...)         - action = 'APPROVE'
     Audit::rejected(...)         - action = 'REJECT'
     Audit::cancelled(...)        - action = 'CANCEL'
     Audit::archived(...)         - action = 'ARCHIVE'
     Audit::forwarded(...)        - action = 'FORWARD'
     Audit::exported(...)         - action = 'EXPORT'
     Audit::diff($old, $new)      - Pure: compute changed fields
     Audit::snapshot($table, $id) - Read current row for old-values
     Audit::track($entity, $id, $before, $after) - Diff + log in one call
     Audit::recent($limit)        - Read: most recent entries
     Audit::forEntity($entity, $id) - Read: history of one record
     Audit::forUser($userId)      - Read: what a user did
     Audit::search($filters)      - Read: flexible query
     Audit::count($filters)       - Read: count matching entries
     Audit::describe($row)        - Human-readable summary

   ACTION CONSTANTS
     Use Audit::ACTION_CREATE rather than the string 'CREATE' to
     prevent typos and enable IDE autocomplete. All 12 constants
     match values already in the audit_log table.

   USAGE
     // Simple (unchanged from log_audit):
     Audit::created('users', $newId, [], ['username' => 'abebe']);
     Audit::updated('departments', $id, ['name' => 'Old'], ['name' => 'New']);

     // With diff tracking (recommended for updates):
     $before = Audit::snapshot('users', $id);
     // ... perform the UPDATE ...
     $after  = Audit::snapshot('users', $id);
     Audit::track('users', $id, $before, $after);
     // ↑ Only logs if $before !== $after, and records ONLY the
     //   fields that actually changed.

     // Read (for future admin/audit_viewer.php):
     $entries = Audit::recent(50);
     $history = Audit::forEntity('users', $id);
     $auditOf = Audit::forUser($userId);

   SAFETY
     - Direct access refused with 403
     - Safe to require_once multiple times
     - Never throws (audit failures are logged to error_log)
     - Works in CLI (cron scripts, tests)
     - snapshot() validates table name against SQL injection
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
if (!class_exists('Audit', false)) {

    final class Audit
    {
        /* ====================================================
           3.1 ACTION CONSTANTS
           These match the values used throughout the codebase
           so existing log_audit('UPDATE', ...) calls remain
           consistent with the newer Audit::updated(...) calls.
           ==================================================== */
        public const ACTION_CREATE   = 'CREATE';
        public const ACTION_UPDATE   = 'UPDATE';
        public const ACTION_DELETE   = 'DELETE';
        public const ACTION_VIEW     = 'VIEW';
        public const ACTION_LOGIN    = 'LOGIN';
        public const ACTION_LOGOUT   = 'LOGOUT';
        public const ACTION_APPROVE  = 'APPROVE';
        public const ACTION_REJECT   = 'REJECT';
        public const ACTION_CANCEL   = 'CANCEL';
        public const ACTION_ARCHIVE  = 'ARCHIVE';
        public const ACTION_FORWARD  = 'FORWARD';
        public const ACTION_EXPORT   = 'EXPORT';

        /** Tables that Audit::snapshot() may read from. */
        private const SNAPSHOT_TABLES = [
            'users',
            'departments',
            'letters',
            'clearance_requests',
            'clearance_approvals',
            'service_requests',
            'workflow_steps',
            'hr_balances',
            'policy_constants',
            'roles',
            'notifications',
            'plan_items',
            'procurement_items',
        ];


        /* ====================================================
           3.2 LOGGING — ALIAS + SHORTCUTS
           ==================================================== */

        /**
         * Alias for log_audit(). Kept identical in signature.
         */
        public static function log(
            string $action,
            string $entity,
            int $entity_id,
            array $old = [],
            array $new = []
        ): void {
            log_audit($action, $entity, $entity_id, $old, $new);
        }

        public static function created(string $entity, int $id, array $data = []): void
        {
            self::log(self::ACTION_CREATE, $entity, $id, [], $data);
        }

        public static function updated(string $entity, int $id, array $old = [], array $new = []): void
        {
            self::log(self::ACTION_UPDATE, $entity, $id, $old, $new);
        }

        public static function deleted(string $entity, int $id, array $data = []): void
        {
            self::log(self::ACTION_DELETE, $entity, $id, $data, []);
        }

        public static function viewed(string $entity, int $id, array $context = []): void
        {
            self::log(self::ACTION_VIEW, $entity, $id, [], $context);
        }

        public static function loggedIn(int $userId): void
        {
            self::log(self::ACTION_LOGIN, 'users', $userId);
        }

        public static function loggedOut(int $userId): void
        {
            self::log(self::ACTION_LOGOUT, 'users', $userId);
        }

        public static function approved(string $entity, int $id, array $context = []): void
        {
            self::log(self::ACTION_APPROVE, $entity, $id, [], $context);
        }

        public static function rejected(string $entity, int $id, array $context = []): void
        {
            self::log(self::ACTION_REJECT, $entity, $id, [], $context);
        }

        public static function cancelled(string $entity, int $id, array $context = []): void
        {
            self::log(self::ACTION_CANCEL, $entity, $id, [], $context);
        }

        public static function archived(string $entity, int $id, array $context = []): void
        {
            self::log(self::ACTION_ARCHIVE, $entity, $id, [], $context);
        }

        public static function forwarded(string $entity, int $id, array $context = []): void
        {
            self::log(self::ACTION_FORWARD, $entity, $id, [], $context);
        }

        public static function exported(string $entity, array $context = []): void
        {
            self::log(self::ACTION_EXPORT, $entity, 0, [], $context);
        }


        /* ====================================================
           3.3 DIFF — PURE FIELD-LEVEL CHANGE COMPUTATION
           ==================================================== */

        /**
         * Compute the changed fields between two arrays.
         *
         * @param array $old  Snapshot before change
         * @param array $new  Snapshot after change
         * @param bool  $strict  If true, uses === (distinguishes
         *                       "10" from 10). If false (default),
         *                       uses == (treats them as equal).
         * @return array{old: array, new: array}  Only the fields
         *         that differ. Both may be empty if no changes.
         */
        public static function diff(array $old, array $new, bool $strict = false): array
        {
            $oldDiff = [];
            $newDiff = [];

            $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

            foreach ($allKeys as $key) {
                // Skip internal columns that always change
                if (in_array($key, ['updated_at', 'created_at', 'last_login_at'], true)) {
                    continue;
                }

                $ov = $old[$key] ?? null;
                $nv = $new[$key] ?? null;

                // Skip password hashes in audit logs (security)
                if ($key === 'password_hash') {
                    if ($ov !== $nv) {
                        $oldDiff[$key] = '****';
                        $newDiff[$key] = '****';
                    }
                    continue;
                }

                $equal = $strict ? ($ov === $nv) : ($ov == $nv);

                if (!$equal) {
                    $oldDiff[$key] = $ov;
                    $newDiff[$key] = $nv;
                }
            }

            return ['old' => $oldDiff, 'new' => $newDiff];
        }


        /* ====================================================
           3.4 SNAPSHOT — READ BEFORE UPDATE
           ==================================================== */

        /**
         * Fetch the current state of a row for later diff comparison.
         * Returns null if not found or table not permitted.
         *
         * @param string $table  Whitelisted table name
         * @param int    $id     Primary key value
         * @param array  $columns Specific columns (default: *)
         */
        public static function snapshot(string $table, int $id, array $columns = ['*']): ?array
        {
            // Validate table against the whitelist
            if (!in_array($table, self::SNAPSHOT_TABLES, true)) {
                error_log("Audit::snapshot() rejected table '{$table}' — not in whitelist");
                return null;
            }

            // Validate and build column list (avoid SQL injection)
            $safeColumns = [];
            foreach ($columns as $col) {
                if ($col === '*') {
                    $safeColumns[] = '*';
                } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                    $safeColumns[] = $col;
                }
            }
            if (empty($safeColumns)) $safeColumns = ['*'];

            $colList = implode(', ', $safeColumns);

            try {
                global $pdo;
                $st = $pdo->prepare("SELECT {$colList} FROM {$table} WHERE id = ? LIMIT 1");
                $st->execute([$id]);
                $row = $st->fetch();
                return $row ?: null;
            } catch (PDOException $e) {
                error_log('Audit::snapshot() failed: ' . $e->getMessage());
                return null;
            }
        }


        /* ====================================================
           3.5 TRACK — DIFF + LOG IN ONE CALL
           ==================================================== */

        /**
         * Compare two snapshots and log only if something changed.
         *
         * Typical usage:
         *   $before = Audit::snapshot('users', $id);
         *   // ... run UPDATE query ...
         *   $after  = Audit::snapshot('users', $id);
         *   Audit::track('users', $id, $before, $after);
         *
         * If nothing meaningful changed, this is a no-op (no log row).
         *
         * @return array  The diff (empty arrays if no change)
         */
        public static function track(
            string $entity,
            int $entity_id,
            ?array $before,
            ?array $after
        ): array {
            if ($before === null || $after === null) {
                return ['old' => [], 'new' => []];
            }

            $diff = self::diff($before, $after);

            if (empty($diff['old']) && empty($diff['new'])) {
                return $diff; // no change, no log
            }

            self::updated($entity, $entity_id, $diff['old'], $diff['new']);
            return $diff;
        }


        /* ====================================================
           3.6 READ METHODS
           ==================================================== */

        /**
         * Most recent audit entries.
         *
         * @param int   $limit
         * @param array $filters  Optional: action, entity, user_id
         * @return array
         */
        public static function recent(int $limit = 50, array $filters = []): array
        {
            $limit = max(1, min(1000, $limit));
            [$where, $args] = self::buildWhere($filters);

            try {
                global $pdo;
                $sql = "SELECT al.*, u.full_name AS user_name, u.username
                        FROM audit_log al
                        LEFT JOIN users u ON u.id = al.user_id
                        {$where}
                        ORDER BY al.created_at DESC
                        LIMIT {$limit}";
                $st = $pdo->prepare($sql);
                $st->execute($args);
                return $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Audit::recent() failed: ' . $e->getMessage());
                return [];
            }
        }

        /**
         * All audit entries for a specific record.
         */
        public static function forEntity(string $entity, int $entity_id, int $limit = 100): array
        {
            $limit = max(1, min(1000, $limit));

            try {
                global $pdo;
                $sql = "SELECT al.*, u.full_name AS user_name, u.username
                        FROM audit_log al
                        LEFT JOIN users u ON u.id = al.user_id
                        WHERE al.entity = ? AND al.entity_id = ?
                        ORDER BY al.created_at DESC
                        LIMIT {$limit}";
                $st = $pdo->prepare($sql);
                $st->execute([$entity, $entity_id]);
                return $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Audit::forEntity() failed: ' . $e->getMessage());
                return [];
            }
        }

        /**
         * All audit entries for a specific user (what did they do?).
         */
        public static function forUser(int $user_id, int $limit = 100): array
        {
            $limit = max(1, min(1000, $limit));

            try {
                global $pdo;
                $sql = "SELECT al.*, u.full_name AS user_name, u.username
                        FROM audit_log al
                        LEFT JOIN users u ON u.id = al.user_id
                        WHERE al.user_id = ?
                        ORDER BY al.created_at DESC
                        LIMIT {$limit}";
                $st = $pdo->prepare($sql);
                $st->execute([$user_id]);
                return $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Audit::forUser() failed: ' . $e->getMessage());
                return [];
            }
        }

        /**
         * Flexible search with any combination of filters.
         *
         * Supported filters:
         *   action     (string or array of strings)
         *   entity     (string or array of strings)
         *   entity_id  (int)
         *   user_id    (int)
         *   from       (date string YYYY-MM-DD)
         *   to         (date string YYYY-MM-DD)
         *   search     (searches old_values + new_values as text)
         */
        public static function search(array $filters = [], int $limit = 100): array
        {
            $limit = max(1, min(1000, $limit));
            [$where, $args] = self::buildWhere($filters);

            try {
                global $pdo;
                $sql = "SELECT al.*, u.full_name AS user_name, u.username
                        FROM audit_log al
                        LEFT JOIN users u ON u.id = al.user_id
                        {$where}
                        ORDER BY al.created_at DESC
                        LIMIT {$limit}";
                $st = $pdo->prepare($sql);
                $st->execute($args);
                return $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Audit::search() failed: ' . $e->getMessage());
                return [];
            }
        }

        /**
         * Count matching entries.
         */
        public static function count(array $filters = []): int
        {
            [$where, $args] = self::buildWhere($filters);

            try {
                global $pdo;
                $st = $pdo->prepare("SELECT COUNT(*) FROM audit_log al {$where}");
                $st->execute($args);
                return (int)$st->fetchColumn();
            } catch (PDOException $e) {
                error_log('Audit::count() failed: ' . $e->getMessage());
                return 0;
            }
        }

        /**
         * Build a WHERE clause from a filter array.
         * @return array{0: string, 1: array}
         */
        private static function buildWhere(array $filters): array
        {
            $where = [];
            $args  = [];

            if (!empty($filters['action'])) {
                $actions = is_array($filters['action']) ? $filters['action'] : [$filters['action']];
                $placeholders = implode(',', array_fill(0, count($actions), '?'));
                $where[] = "al.action IN ({$placeholders})";
                foreach ($actions as $a) $args[] = $a;
            }

            if (!empty($filters['entity'])) {
                $entities = is_array($filters['entity']) ? $filters['entity'] : [$filters['entity']];
                $placeholders = implode(',', array_fill(0, count($entities), '?'));
                $where[] = "al.entity IN ({$placeholders})";
                foreach ($entities as $e) $args[] = $e;
            }

            if (isset($filters['entity_id'])) {
                $where[] = "al.entity_id = ?";
                $args[] = (int)$filters['entity_id'];
            }

            if (isset($filters['user_id'])) {
                $where[] = "al.user_id = ?";
                $args[] = (int)$filters['user_id'];
            }

            if (!empty($filters['from'])) {
                $where[] = "al.created_at >= ?";
                $args[] = $filters['from'] . ' 00:00:00';
            }

            if (!empty($filters['to'])) {
                $where[] = "al.created_at <= ?";
                $args[] = $filters['to'] . ' 23:59:59';
            }

            if (!empty($filters['search'])) {
                $like = '%' . $filters['search'] . '%';
                $where[] = "(al.old_values LIKE ? OR al.new_values LIKE ?)";
                $args[] = $like;
                $args[] = $like;
            }

            $clause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
            return [$clause, $args];
        }


        /* ====================================================
           3.7 DESCRIPTION — HUMAN-READABLE SUMMARY
           ==================================================== */

        /**
         * Turn a raw audit row into a human-readable sentence.
         *
         * Example output:
         *   "Admin created user #5 (username: abebe)"
         *   "hr updated leave #12 (status: submitted → approved)"
         *   "Logged in"
         */
        public static function describe(array $row): string
        {
            $action = (string)($row['action'] ?? 'unknown');
            $entity = (string)($row['entity'] ?? '');
            $entityId = (int)($row['entity_id'] ?? 0);
            $userName = (string)($row['user_name'] ?? $row['username'] ?? 'System');

            // Decode old/new values
            $oldVals = self::decodeJson($row['old_values'] ?? null);
            $newVals = self::decodeJson($row['new_values'] ?? null);

            // Build a compact change summary (up to 3 fields)
            $changes = [];
            $keys = array_unique(array_merge(array_keys($oldVals), array_keys($newVals)));
            foreach ($keys as $k) {
                $ov = $oldVals[$k] ?? null;
                $nv = $newVals[$k] ?? null;
                if ($ov === $nv) continue;

                if (is_array($nv) || is_array($ov)) {
                    $changes[] = $k . ' changed';
                } elseif ($ov === null || $ov === '') {
                    $changes[] = $k . ': ' . self::shorten($nv);
                } elseif ($nv === null || $nv === '') {
                    $changes[] = $k . ': ' . self::shorten($ov) . ' → (empty)';
                } else {
                    $changes[] = $k . ': ' . self::shorten($ov) . ' → ' . self::shorten($nv);
                }

                if (count($changes) >= 3) {
                    $changes[] = '...';
                    break;
                }
            }

            $verb = strtolower($action);
            $object = trim($entity . ($entityId ? " #{$entityId}" : ''));

            $base = $userName . ' ' . $verb;
            if ($object !== '') {
                $base .= ' ' . $object;
            }

            if (!empty($changes)) {
                $base .= ' (' . implode(', ', $changes) . ')';
            }

            return $base;
        }

        /** Decode a JSON column, tolerating nulls and invalid JSON. */
        private static function decodeJson($value): array
        {
            if ($value === null || $value === '') return [];
            $decoded = json_decode((string)$value, true);
            return is_array($decoded) ? $decoded : [];
        }

        /** Truncate a value for display in describe(). */
        private static function shorten($value, int $max = 30): string
        {
            if ($value === null) return '(null)';
            if (is_bool($value)) return $value ? 'true' : 'false';
            $str = (string)$value;
            return strlen($str) > $max ? substr($str, 0, $max - 1) . '…' : $str;
        }
    }
}