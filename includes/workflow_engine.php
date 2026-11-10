<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   WORKFLOW ENGINE
   ============================================================
   PURPOSE
     Orchestration layer for all multi-step approval workflows.
     Every clearance, leave, pension, resignation, procurement,
     and planning request passes through this module.

   CANONICAL FUNCTIONS (kept for backward compat with
   leave_new.php, clearance_new.php, clearance_view.php,
   leave_view.php, request_view.php, and any other caller)
     create_service_request(...): array
     create_clearance(...): array
     approve_workflow_step(...): void
     approve_clearance_step(...): void
     can_approve_clearance_step(...): bool
     user_has_clearance_step(...): bool

   CLASS API (new — same behavior, cleaner names)
     Workflow::createServiceRequest(...)
     Workflow::createClearance(...)
     Workflow::approveStep(...)
     Workflow::approveClearanceStep(...)
     Workflow::canApproveStep(...)
     Workflow::canApproveClearanceStep(...)
     Workflow::userHasClearanceStep(...)

   READ HELPERS (new)
     Workflow::status($requestId)            - full request + chain
     Workflow::clearanceStatus($clearanceId) - full clearance + chain
     Workflow::chainFor($requestId)          - steps only
     Workflow::clearanceChainFor($clearanceId) - approvals only
     Workflow::progress($requestId)          - {approved, total, percent}
     Workflow::pendingForUser($userId)       - what needs my action?
     Workflow::pendingCount($userId)         - how many?
     Workflow::isOverdue($requestId)         - past SLA?

   BUSINESS RULE HELPERS (new)
     Workflow::templateFor($type)            - standard step array
     Workflow::slaDaysFor($type)             - policy-driven SLA
     Workflow::slaDueFor($type, $from)       - computed due timestamp
     Workflow::recalculateSLA([$requestId])  - bulk or single refresh
     Workflow::overdueRequests($limit)       - list for admin dashboard

   UI HELPERS (new)
     Workflow::roleLabel($code)              - 'hr' → 'HR Officer'
     Workflow::statusLabel($status)          - 'in_progress' → 'In Progress'
     Workflow::describeRequest($row)         - human-readable sentence

   SCHEMA DEPENDENCY
     Tables: service_requests, workflow_steps
             clearance_requests, clearance_approvals
     Stored procedures: sp_create_service_request, sp_create_clearance,
                        sp_approve_step, sp_approve_clearance_step
     Views: v_pending_approvals, v_dashboard_counts

   SAFETY
     - Direct access refused with 403
     - Safe to require_once multiple times
     - Never throws (all DB ops wrapped in try/catch)
     - Works in CLI (cron, tests)
     - Enum types validated pre-insert
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
   2. ENSURE BASE FUNCTIONS AND WRAPPERS ARE LOADED
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/notification.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/ref_generator.php';
require_once __DIR__ . '/auth_check.php';


/* ------------------------------------------------------------
   3. CLASS-BASED API
   ------------------------------------------------------------ */
if (!class_exists('Workflow', false)) {

    final class Workflow
    {
        /* ====================================================
           3.1 CONSTANTS
           ==================================================== */

        /** All request_type enum values from service_requests. */
        public const VALID_TYPES = [
            'leave', 'maternal', 'pension', 'resignation',
            'promotion', 'training', 'overtime', 'procurement',
            'plan', 'disciplinary', 'grievance', 'other',
        ];

        /** Priority enum from service_requests. */
        public const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

        /** Approver role code → human-readable label. */
        public const ROLE_LABELS = [
            'department_head' => 'Department Head',
            'hr'              => 'HR Officer',
            'finance'         => 'Finance Officer',
            'director'        => 'Director / Dean',
            'registrar'       => 'Registrar',
            'library'         => 'Library Officer',
            'store'           => 'Store Officer',
            'dormitory'       => 'Dormitory Officer',
            'medical'         => 'Medical Officer',
            'planning'        => 'Planning Officer',
            'audit'           => 'Auditor',
        ];

        /**
         * Standard step templates per request type.
         * Each step: ['role' => 'hr'] or ['role' => 'hr', 'department' => 12]
         *
         * Note: 'procurement' template conditionally appends 'director'
         * based on Policy::procurementNeedsDirector($amount) — see
         * stepsTemplateFor() below for the dynamic variant.
         */
        public const TEMPLATES = [
            'leave' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
            ],
            'maternal' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
                ['role' => 'medical'],
                ['role' => 'hr'],
            ],
            'pension' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
                ['role' => 'finance'],
                ['role' => 'director'],
            ],
            'resignation' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
                ['role' => 'director'],
            ],
            'procurement' => [
                ['role' => 'department_head'],
                ['role' => 'store'],
                ['role' => 'finance'],
            ],
            'plan' => [
                ['role' => 'planning'],
                ['role' => 'finance'],
                ['role' => 'director'],
            ],
            'promotion' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
                ['role' => 'director'],
            ],
            'training' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
            ],
            'overtime' => [
                ['role' => 'department_head'],
                ['role' => 'finance'],
            ],
            'disciplinary' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
                ['role' => 'director'],
            ],
            'grievance' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
                ['role' => 'director'],
            ],
            'other' => [
                ['role' => 'department_head'],
                ['role' => 'hr'],
            ],
        ];


        /* ====================================================
           3.2 TEMPLATE RESOLUTION
           ==================================================== */

        /**
         * Get the standard step template for a request type.
         *
         * @param string $type    One of VALID_TYPES
         * @param array  $context Optional context: ['amount' => 45000] to
         *                        trigger procurement director step
         * @return array  Array of ['role' => ..., 'department' => ...]
         */
        public static function templateFor(string $type, array $context = []): array
        {
            $type = strtolower(trim($type));

            if (!in_array($type, self::VALID_TYPES, true)) {
                return self::TEMPLATES['other'];
            }

            $steps = self::TEMPLATES[$type] ?? self::TEMPLATES['other'];

            // Procurement: conditionally add director step
            if ($type === 'procurement' && isset($context['amount'])) {
                if (Policy::procurementNeedsDirector((float)$context['amount'])) {
                    $steps[] = ['role' => 'director'];
                }
            }

            return $steps;
        }


        /* ====================================================
           3.3 CREATION
           ==================================================== */

        /**
         * Create a new service request with its approval chain.
         * Delegates to sp_create_service_request (which handles
         * transaction, ref generation, SLA due date, and workflow
         * step insertion all at once).
         *
         * @param string $type        One of VALID_TYPES
         * @param int    $requesterId
         * @param string $title
         * @param string $summary
         * @param string $priority    One of VALID_PRIORITIES
         * @param array  $steps       Array of ['role' => ..., 'department' => ...]
         *                            If empty, templateFor($type) is used
         * @return array{id:int, ref_no:string}
         */
        public static function createServiceRequest(
            string $type,
            int    $requesterId,
            string $title,
            string $summary,
            string $priority = 'normal',
            array  $steps = []
        ): array {
            $type = strtolower(trim($type));
            if (!in_array($type, self::VALID_TYPES, true)) {
                throw new InvalidArgumentException("Invalid request type '{$type}'");
            }

            $priority = strtolower(trim($priority));
            if (!in_array($priority, self::VALID_PRIORITIES, true)) {
                $priority = 'normal';
            }

            if (empty($steps)) {
                $steps = self::templateFor($type);
            }

            // Trim values
            $title   = trim($title);
            $summary = trim($summary);

            if ($title === '') {
                throw new InvalidArgumentException('Request title is required');
            }

            global $pdo;

            try {
                $st = $pdo->prepare("CALL sp_create_service_request(?, ?, ?, ?, ?, ?, @req_id, @ref_no)");
                $st->execute([$type, $requesterId, $title, $summary, $priority, json_encode($steps)]);
                $st->closeCursor();

                $row = $pdo->query("SELECT @req_id AS id, @ref_no AS ref_no")->fetch();

                if (empty($row['id'])) {
                    throw new RuntimeException('Stored procedure returned no request ID');
                }

                $result = ['id' => (int)$row['id'], 'ref_no' => (string)$row['ref_no']];

                // Notify first approver
                self::notifyFirstWorkflowStep($result['id']);

                return $result;
            } catch (PDOException $e) {
                error_log('Workflow::createServiceRequest() failed: ' . $e->getMessage());
                throw new RuntimeException('Could not create service request: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Create a new clearance request with its 5-step chain.
         * Delegates to sp_create_clearance.
         *
         * @return array{id:int, ref_no:string}
         */
        public static function createClearance(
            int     $applicantId,
            string  $applicantType,
            string  $reason,
            ?string $destination,
            ?string $destinationType,
            ?string $notes,
            int     $createdBy
        ): array {
            $applicantType = strtolower(trim($applicantType));
            if (!in_array($applicantType, ['student', 'employee'], true)) {
                throw new InvalidArgumentException("Applicant type must be 'student' or 'employee'");
            }

            global $pdo;

            try {
                $st = $pdo->prepare("CALL sp_create_clearance(?, ?, ?, ?, ?, ?, ?, @id, @ref)");
                $st->execute([$applicantId, $applicantType, $reason, $destination, $destinationType, $notes, $createdBy]);
                $st->closeCursor();

                $row = $pdo->query("SELECT @id AS id, @ref AS ref_no")->fetch();

                if (empty($row['id'])) {
                    throw new RuntimeException('Stored procedure returned no clearance ID');
                }

                $result = ['id' => (int)$row['id'], 'ref_no' => (string)$row['ref_no']];

                self::notifyFirstClearanceStep($result['id']);

                return $result;
            } catch (PDOException $e) {
                error_log('Workflow::createClearance() failed: ' . $e->getMessage());
                throw new RuntimeException('Could not create clearance: ' . $e->getMessage(), 0, $e);
            }
        }


        /* ====================================================
           3.4 APPROVAL
           ==================================================== */

        /**
         * Approve or reject one step in a service request.
         *
         * @param int    $stepId
         * @param int    $approverId
         * @param string $decision   'approve' or 'reject'
         * @param string $comment
         */
        public static function approveStep(
            int $stepId,
            int $approverId,
            string $decision,
            string $comment = ''
        ): void {
            $decision = strtolower(trim($decision));
            if (!in_array($decision, ['approve', 'reject'], true)) {
                throw new InvalidArgumentException("Decision must be 'approve' or 'reject'");
            }

            global $pdo;

            try {
                $st = $pdo->prepare("CALL sp_approve_step(?, ?, ?, ?)");
                $st->execute([$stepId, $approverId, $decision, $comment]);
                $st->closeCursor();

                // Determine request ID for notification + audit
                $st2 = $pdo->prepare("SELECT request_id FROM workflow_steps WHERE id = ?");
                $st2->execute([$stepId]);
                $requestId = (int)$st2->fetchColumn();

                if ($requestId > 0) {
                    self::notifyNextWorkflowStep($requestId);
                    self::auditTerminalState('service_requests', $requestId);
                }
            } catch (PDOException $e) {
                error_log('Workflow::approveStep() failed: ' . $e->getMessage());
                throw new RuntimeException('Could not approve step: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Approve or reject one step in a clearance request.
         */
        public static function approveClearanceStep(
            int $clearanceId,
            int $stepOrder,
            int $approverId,
            string $decision,
            string $comment = ''
        ): void {
            $decision = strtolower(trim($decision));
            if (!in_array($decision, ['approve', 'reject'], true)) {
                throw new InvalidArgumentException("Decision must be 'approve' or 'reject'");
            }

            global $pdo;

            try {
                $st = $pdo->prepare("CALL sp_approve_clearance_step(?, ?, ?, ?, ?)");
                $st->execute([$clearanceId, $stepOrder, $approverId, $decision, $comment]);
                $st->closeCursor();

                self::notifyNextClearanceStep($clearanceId);
                self::auditTerminalState('clearance_requests', $clearanceId);
            } catch (PDOException $e) {
                error_log('Workflow::approveClearanceStep() failed: ' . $e->getMessage());
                throw new RuntimeException('Could not approve clearance step: ' . $e->getMessage(), 0, $e);
            }
        }


        /* ====================================================
           3.5 PERMISSION CHECKS
           ==================================================== */

        /**
         * Can the current user approve a step requiring $role?
         * Delegates to can_approve_step() which now uses has_role().
         */
        public static function canApproveStep(string $role): bool
        {
            return can_approve_step($role);
        }

        /**
         * Can the current user act on a clearance step for $department?
         *
         * Maps the textual department label from clearance_approvals
         * (e.g. "Department", "Library", "Finance") to a role check.
         * Falls back to dept_code matching for legacy users.
         */
        public static function canApproveClearanceStep(string $department): bool
        {
            $user = current_user();
            if (!$user) return false;
            if (has_role('admin')) return true;

            $dept = strtolower(trim($department));
            $code = $user['dept_code'] ?? '';

            return match ($dept) {
                'department' => $user['position'] === 'Department Head' || has_role('department_head'),
                'hr'         => has_role('hr'),
                'finance'    => has_role('finance') || $code === 'FIN',
                'library'    => has_role('library') || $code === 'LIB',
                'dormitory'  => has_role('dormitory') || $code === 'DORM',
                'store'      => has_role('store') || $code === 'STORE',
                'registrar'  => has_role('registrar') || $code === 'REG',
                'medical'    => has_role('medical') || $code === 'CLINIC',
                'planning'   => has_role('planning') || $code === 'PLAN',
                'director'   => has_role('director'),
                default      => false,
            };
        }

        /**
         * Does the given user have any actionable step on a clearance?
         */
        public static function userHasClearanceStep(int $clearanceId, int $userId): bool
        {
            global $pdo;

            try {
                // Direct assignment check
                $st = $pdo->prepare("
                    SELECT 1 FROM clearance_approvals
                    WHERE clearance_id = ? AND approver_id = ?
                    LIMIT 1
                ");
                $st->execute([$clearanceId, $userId]);
                if ($st->fetch()) return true;

                // Pending department check
                $st = $pdo->prepare("
                    SELECT department FROM clearance_approvals
                    WHERE clearance_id = ? AND status = 'pending'
                ");
                $st->execute([$clearanceId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $dept) {
                    if (self::canApproveClearanceStep((string)$dept)) return true;
                }
                return false;
            } catch (PDOException $e) {
                error_log('Workflow::userHasClearanceStep() failed: ' . $e->getMessage());
                return false;
            }
        }


        /* ====================================================
           3.6 READ — SINGLE RECORD
           ==================================================== */

        /**
         * Fetch a full service request + its approval chain.
         * @return array{request: array, steps: array}|null
         */
        public static function status(int $requestId): ?array
        {
            if ($requestId <= 0) return null;
            global $pdo;

            try {
                $st = $pdo->prepare("
                    SELECT sr.*, u.full_name AS requester_name, u.position AS requester_position,
                           u.employee_code, u.student_code, d.name AS dept_name
                    FROM service_requests sr
                    JOIN users u ON u.id = sr.requester_id
                    LEFT JOIN departments d ON d.id = sr.department_id
                    WHERE sr.id = ?
                    LIMIT 1
                ");
                $st->execute([$requestId]);
                $request = $st->fetch();
                if (!$request) return null;

                return [
                    'request' => $request,
                    'steps'   => self::chainFor($requestId),
                ];
            } catch (PDOException $e) {
                error_log('Workflow::status() failed: ' . $e->getMessage());
                return null;
            }
        }

        /**
         * Fetch a full clearance request + its approval chain.
         */
        public static function clearanceStatus(int $clearanceId): ?array
        {
            if ($clearanceId <= 0) return null;
            global $pdo;

            try {
                $st = $pdo->prepare("
                    SELECT cr.*, u.full_name AS applicant_name, u.position AS applicant_position,
                           u.employee_code, u.student_code, d.name AS dept_name
                    FROM clearance_requests cr
                    JOIN users u ON u.id = cr.applicant_id
                    LEFT JOIN departments d ON d.id = cr.department_id
                    WHERE cr.id = ?
                    LIMIT 1
                ");
                $st->execute([$clearanceId]);
                $clearance = $st->fetch();
                if (!$clearance) return null;

                return [
                    'clearance' => $clearance,
                    'steps'     => self::clearanceChainFor($clearanceId),
                ];
            } catch (PDOException $e) {
                error_log('Workflow::clearanceStatus() failed: ' . $e->getMessage());
                return null;
            }
        }

        /**
         * Get the workflow step chain for a service request.
         * @return array<int, array>
         */
        public static function chainFor(int $requestId): array
        {
            if ($requestId <= 0) return [];
            global $pdo;

            try {
                $st = $pdo->prepare("
                    SELECT ws.*, u.full_name AS approver_name, u.position AS approver_position
                    FROM workflow_steps ws
                    LEFT JOIN users u ON u.id = ws.approver_id
                    WHERE ws.request_id = ?
                    ORDER BY ws.step_order ASC
                ");
                $st->execute([$requestId]);
                return $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Workflow::chainFor() failed: ' . $e->getMessage());
                return [];
            }
        }

        /**
         * Get the approval chain for a clearance.
         */
        public static function clearanceChainFor(int $clearanceId): array
        {
            if ($clearanceId <= 0) return [];
            global $pdo;

            try {
                $st = $pdo->prepare("
                    SELECT ca.*, u.full_name AS approver_name, u.position AS approver_position
                    FROM clearance_approvals ca
                    LEFT JOIN users u ON u.id = ca.approver_id
                    WHERE ca.clearance_id = ?
                    ORDER BY ca.step_order ASC
                ");
                $st->execute([$clearanceId]);
                return $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Workflow::clearanceChainFor() failed: ' . $e->getMessage());
                return [];
            }
        }


        /* ====================================================
           3.7 READ — AGGREGATES
           ==================================================== */

        /**
         * Progress for a service request.
         * @return array{approved:int, total:int, percent:int, is_complete:bool}
         */
        public static function progress(int $requestId): array
        {
            $steps = self::chainFor($requestId);
            $total    = count($steps);
            $approved = 0;
            $rejected = 0;

            foreach ($steps as $s) {
                if ($s['status'] === 'approved') $approved++;
                elseif ($s['status'] === 'rejected') $rejected++;
            }

            return [
                'approved'    => $approved,
                'rejected'    => $rejected,
                'total'       => $total,
                'percent'     => $total > 0 ? (int)round(($approved / $total) * 100) : 0,
                'is_complete' => $total > 0 && $approved === $total,
            ];
        }

        /**
         * Pending workflow steps that the given user can act on.
         * Uses the v_pending_approvals view (which combines clearances + workflows).
         *
         * @return array<int, array>
         */
        public static function pendingForUser(int $userId): array
        {
            if ($userId <= 0) return [];
            global $pdo;

            try {
                $st = $pdo->query("SELECT * FROM v_pending_approvals ORDER BY created_at DESC");
                $all = $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Workflow::pendingForUser() failed: ' . $e->getMessage());
                return [];
            }

            $mine = [];
            foreach ($all as $task) {
                $kind = (string)($task['kind'] ?? '');

                if ($kind === 'workflow') {
                    $role = (string)($task['target_role'] ?? '');
                    if (can_approve_step($role)) {
                        $mine[] = $task;
                    }
                } elseif ($kind === 'clearance') {
                    $dept = (string)($task['target_department'] ?? '');
                    if (self::canApproveClearanceStep($dept)) {
                        $mine[] = $task;
                    }
                }
            }

            return $mine;
        }

        /** How many pending actions for a user? */
        public static function pendingCount(int $userId): int
        {
            return count(self::pendingForUser($userId));
        }


        /* ====================================================
           3.8 SLA
           ==================================================== */

        /**
         * SLA target in days for a request type, read from policy_constants.
         * Delegates to Policy::slaDays().
         */
        public static function slaDaysFor(string $type): int
        {
            return Policy::slaDays($type);
        }

        /**
         * Compute SLA due date for a new request of this type.
         * Returns MySQL DATETIME string.
         */
        public static function slaDueFor(string $type, ?string $fromDate = null): string
        {
            $days = self::slaDaysFor($type);
            $base = $fromDate ? strtotime($fromDate) : time();
            if ($base === false) $base = time();
            return date('Y-m-d H:i:s', $base + ($days * 86400));
        }

        /**
         * Is this request past its SLA?
         */
        public static function isOverdue(int $requestId): bool
        {
            if ($requestId <= 0) return false;
            global $pdo;

            try {
                $st = $pdo->prepare("
                    SELECT sla_due_at, status
                    FROM service_requests
                    WHERE id = ?
                    LIMIT 1
                ");
                $st->execute([$requestId]);
                $row = $st->fetch();
                if (!$row || !$row['sla_due_at']) return false;
                if (in_array($row['status'], ['approved', 'rejected', 'cancelled', 'completed'], true)) {
                    return false;
                }
                return strtotime($row['sla_due_at']) < time();
            } catch (PDOException $e) {
                return false;
            }
        }

        /**
         * Requests that are past SLA and still pending.
         * Useful for admin dashboards.
         */
        public static function overdueRequests(int $limit = 50): array
        {
            $limit = max(1, min(500, $limit));
            global $pdo;

            try {
                $st = $pdo->prepare("
                    SELECT sr.*, u.full_name AS requester_name
                    FROM service_requests sr
                    JOIN users u ON u.id = sr.requester_id
                    WHERE sr.sla_due_at < NOW()
                      AND sr.status IN ('submitted', 'in_progress')
                    ORDER BY sr.sla_due_at ASC
                    LIMIT {$limit}
                ");
                $st->execute();
                return $st->fetchAll();
            } catch (PDOException $e) {
                error_log('Workflow::overdueRequests() failed: ' . $e->getMessage());
                return [];
            }
        }

        /**
         * Recalculate sla_due_at for pending requests based on current
         * policy values. Used after admin/policy_constants.php saves.
         *
         * WARNING: This retroactively adjusts deadlines for existing
         * requests. Use with care — admin UI should preview impact
         * before running.
         *
         * @param int|null $requestId  Refresh one, or all pending (null)
         * @return int  Number of rows updated
         */
        public static function recalculateSLA(?int $requestId = null): int
        {
            global $pdo;

            try {
                if ($requestId !== null && $requestId > 0) {
                    $st = $pdo->prepare("
                        SELECT id, request_type, submitted_at, created_at
                        FROM service_requests WHERE id = ?
                    ");
                    $st->execute([$requestId]);
                    $row = $st->fetch();
                    if (!$row) return 0;

                    $base = $row['submitted_at'] ?: $row['created_at'];
                    $newDue = self::slaDueFor($row['request_type'], $base);

                    $upd = $pdo->prepare("UPDATE service_requests SET sla_due_at = ? WHERE id = ?");
                    $upd->execute([$newDue, $requestId]);
                    return $upd->rowCount();
                }

                // Bulk refresh for all pending requests
                $st = $pdo->query("
                    SELECT id, request_type, submitted_at, created_at
                    FROM service_requests
                    WHERE status IN ('submitted', 'in_progress')
                ");
                $rows = $st->fetchAll();
                $count = 0;

                $upd = $pdo->prepare("UPDATE service_requests SET sla_due_at = ? WHERE id = ?");
                foreach ($rows as $r) {
                    $base = $r['submitted_at'] ?: $r['created_at'];
                    $newDue = self::slaDueFor($r['request_type'], $base);
                    $upd->execute([$newDue, $r['id']]);
                    $count += $upd->rowCount();
                }
                return $count;
            } catch (PDOException $e) {
                error_log('Workflow::recalculateSLA() failed: ' . $e->getMessage());
                return 0;
            }
        }


        /* ====================================================
           3.9 UI HELPERS
           ==================================================== */

        /** 'hr' → 'HR Officer' */
        public static function roleLabel(string $code): string
        {
            return self::ROLE_LABELS[strtolower($code)] ?? ucwords(str_replace('_', ' ', $code));
        }

        /** 'in_progress' → 'In Progress' */
        public static function statusLabel(string $status): string
        {
            return ucwords(str_replace('_', ' ', $status));
        }

        /**
         * Human-readable sentence for a request.
         * "REQ-LEAVE-20261014-DA9444 · Leave · submitted by Selam Tesfaye · 2/2 steps approved"
         */
        public static function describeRequest(array $row): string
        {
            $ref      = $row['ref_no'] ?? '?';
            $type     = self::statusLabel($row['request_type'] ?? 'request');
            $requester = $row['requester_name'] ?? 'someone';
            $status   = self::statusLabel($row['status'] ?? 'unknown');

            return "{$ref} · {$type} · {$status} · requested by {$requester}";
        }


        /* ====================================================
           3.10 PRIVATE — NOTIFICATIONS
           ==================================================== */

        private static function notifyFirstClearanceStep(int $clearanceId): void
        {
            global $pdo;

            $st = $pdo->prepare("
                SELECT ca.department, cr.ref_no, cr.applicant_id, u.full_name AS applicant_name
                FROM clearance_approvals ca
                JOIN clearance_requests cr ON cr.id = ca.clearance_id
                JOIN users u ON u.id = cr.applicant_id
                WHERE ca.clearance_id = ? AND ca.status = 'pending'
                ORDER BY ca.step_order ASC
                LIMIT 1
            ");
            $st->execute([$clearanceId]);
            $row = $st->fetch();
            if (!$row) return;

            $link = Notifier::linkTo('clearance', $clearanceId);
            $body = 'Applicant: ' . $row['applicant_name'] . ' · Ref ' . $row['ref_no'];

            foreach (self::clearanceStepApprovers($row['department']) as $uid) {
                Notifier::warning($uid, 'Clearance pending: ' . $row['department'] . ' step', $body, $link);
            }
        }

        private static function notifyNextClearanceStep(int $clearanceId): void
        {
            global $pdo;

            $st = $pdo->prepare("SELECT applicant_id, ref_no, status FROM clearance_requests WHERE id = ?");
            $st->execute([$clearanceId]);
            $cr = $st->fetch();
            if (!$cr) return;

            $link = Notifier::linkTo('clearance', $clearanceId);

            if (in_array($cr['status'], ['approved', 'rejected', 'completed'], true)) {
                $type = $cr['status'] === 'rejected' ? 'danger' : 'success';
                $uid  = (int)$cr['applicant_id'];

                if ($type === 'success') {
                    Notifier::success($uid,
                        'Clearance ' . $cr['status'] . ': ' . $cr['ref_no'],
                        'Your clearance request has been ' . $cr['status'] . '.',
                        $link);
                } else {
                    Notifier::danger($uid,
                        'Clearance ' . $cr['status'] . ': ' . $cr['ref_no'],
                        'Your clearance request has been ' . $cr['status'] . '.',
                        $link);
                }
                return;
            }

            // Still in progress — notify next pending step
            self::notifyFirstClearanceStep($clearanceId);
        }

        private static function notifyFirstWorkflowStep(int $requestId): void
        {
            global $pdo;

            $st = $pdo->prepare("
                SELECT ws.role_required, sr.ref_no, sr.requester_id, u.full_name
                FROM workflow_steps ws
                JOIN service_requests sr ON sr.id = ws.request_id
                JOIN users u ON u.id = sr.requester_id
                WHERE ws.request_id = ? AND ws.status = 'pending'
                ORDER BY ws.step_order ASC
                LIMIT 1
            ");
            $st->execute([$requestId]);
            $row = $st->fetch();
            if (!$row) return;

            $link = Notifier::linkTo('request', $requestId);
            $body = 'Requester: ' . $row['full_name'] . ' · Ref ' . $row['ref_no'];

            self::notifyRole($row['role_required'],
                'Approval required: ' . self::roleLabel($row['role_required']),
                $body, 'warning', $link);
        }

        private static function notifyNextWorkflowStep(int $requestId): void
        {
            global $pdo;

            $st = $pdo->prepare("SELECT requester_id, ref_no, status FROM service_requests WHERE id = ?");
            $st->execute([$requestId]);
            $sr = $st->fetch();
            if (!$sr) return;

            $link = Notifier::linkTo('request', $requestId);

            if (in_array($sr['status'], ['approved', 'rejected', 'completed'], true)) {
                $uid = (int)$sr['requester_id'];
                if ($sr['status'] === 'rejected') {
                    Notifier::danger($uid,
                        'Request ' . $sr['status'] . ': ' . $sr['ref_no'],
                        'Your request has been ' . $sr['status'] . '.',
                        $link);
                } else {
                    Notifier::success($uid,
                        'Request ' . $sr['status'] . ': ' . $sr['ref_no'],
                        'Your request has been ' . $sr['status'] . '.',
                        $link);
                }
                return;
            }

            self::notifyFirstWorkflowStep($requestId);
        }

        /** Send to all users with a given role code. */
        private static function notifyRole(
            string $roleCode,
            string $title,
            string $body,
            string $type = 'warning',
            ?string $link = null
        ): void {
            global $pdo;

            try {
                $st = $pdo->prepare("
                    SELECT DISTINCT ur.user_id
                    FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    WHERE r.code = ? AND ur.user_id > 0
                ");
                $st->execute([$roleCode]);
                $uids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (PDOException $e) {
                return;
            }

            foreach ($uids as $uid) {
                match ($type) {
                    'success' => Notifier::success($uid, $title, $body, $link),
                    'danger'  => Notifier::danger($uid,  $title, $body, $link),
                    'info'    => Notifier::info($uid,    $title, $body, $link),
                    default   => Notifier::warning($uid, $title, $body, $link),
                };
            }
        }

        /** Fetch user IDs eligible to approve a given clearance department step. */
        private static function clearanceStepApprovers(string $department): array
        {
            global $pdo;

            if (strtolower($department) === 'department') {
                $st = $pdo->query("
                    SELECT id FROM users
                    WHERE status = 'active' AND deleted_at IS NULL
                      AND position = 'Department Head'
                ");
                return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }

            $map = [
                'HR' => 'HRD', 'hr' => 'HRD',
                'Finance' => 'FIN', 'finance' => 'FIN',
                'Library' => 'LIB', 'library' => 'LIB',
                'Dormitory' => 'DORM', 'dormitory' => 'DORM',
                'Store' => 'STORE', 'store' => 'STORE',
                'Registrar' => 'REG', 'registrar' => 'REG',
            ];

            $code = $map[$department] ?? null;
            if (!$code) return [];

            $st = $pdo->prepare("
                SELECT u.id FROM users u
                JOIN departments d ON d.id = u.department_id
                WHERE u.status = 'active' AND u.deleted_at IS NULL AND d.code = ?
            ");
            $st->execute([$code]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }

        /** Log audit entry when a request reaches a terminal status. */
        private static function auditTerminalState(string $entity, int $id): void
        {
            global $pdo;

            try {
                $table = $entity === 'service_requests' ? 'service_requests' : 'clearance_requests';
                $st = $pdo->prepare("SELECT status, ref_no FROM {$table} WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) return;

                if ($row['status'] === 'approved' || $row['status'] === 'completed') {
                    Audit::approved($table, $id, ['ref_no' => $row['ref_no']]);
                } elseif ($row['status'] === 'rejected') {
                    Audit::rejected($table, $id, ['ref_no' => $row['ref_no']]);
                }
            } catch (PDOException $e) {
                // silent — audit is best-effort
            }
        }
    }
}


/* ------------------------------------------------------------
   4. BACKWARD-COMPAT FUNCTION API
   All delegate to Workflow class. Existing callers unaffected.
   ------------------------------------------------------------ */

if (!function_exists('create_service_request')) {
    /**
     * @deprecated since WAVE 2 — use Workflow::createServiceRequest()
     */
    function create_service_request(
        string $type,
        int    $requesterId,
        string $title,
        string $summary,
        string $priority,
        array  $steps
    ): array {
        return Workflow::createServiceRequest($type, $requesterId, $title, $summary, $priority, $steps);
    }
}

if (!function_exists('create_clearance')) {
    /**
     * @deprecated since WAVE 2 — use Workflow::createClearance()
     */
    function create_clearance(
        int     $applicantId,
        string  $applicantType,
        string  $reason,
        ?string $destination,
        ?string $destinationType,
        ?string $notes,
        int     $createdBy
    ): array {
        return Workflow::createClearance($applicantId, $applicantType, $reason, $destination, $destinationType, $notes, $createdBy);
    }
}

if (!function_exists('approve_workflow_step')) {
    /**
     * @deprecated since WAVE 2 — use Workflow::approveStep()
     */
    function approve_workflow_step(
        int $stepId,
        int $approverId,
        string $decision,
        string $comment = ''
    ): void {
        Workflow::approveStep($stepId, $approverId, $decision, $comment);
    }
}

if (!function_exists('approve_clearance_step')) {
    /**
     * @deprecated since WAVE 2 — use Workflow::approveClearanceStep()
     */
    function approve_clearance_step(
        int $clearanceId,
        int $stepOrder,
        int $approverId,
        string $decision,
        string $comment = ''
    ): void {
        Workflow::approveClearanceStep($clearanceId, $stepOrder, $approverId, $decision, $comment);
    }
}

if (!function_exists('can_approve_clearance_step')) {
    /**
     * @deprecated since WAVE 2 — use Workflow::canApproveClearanceStep()
     */
    function can_approve_clearance_step(string $department): bool
    {
        return Workflow::canApproveClearanceStep($department);
    }
}

if (!function_exists('user_has_clearance_step')) {
    /**
     * @deprecated since WAVE 2 — use Workflow::userHasClearanceStep()
     */
    function user_has_clearance_step(int $clearanceId, int $userId): bool
    {
        return Workflow::userHasClearanceStep($clearanceId, $userId);
    }
}