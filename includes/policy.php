<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   POLICY CONSTANTS MODULE
   ============================================================
   PURPOSE
     Read-only wrapper around get_policy() from functions.php,
     plus typed accessors, named shortcuts for all 23 constants,
     and business-rule helpers built on top of them.

   THE CANONICAL FUNCTION (defined in functions.php)
     get_policy(string $code, $default = null)
       - Reads value_num (preferred) or value_text from
         the policy_constants table
       - Cached per-request (static $cache inside the function)

   THIS FILE ADDS
     Policy::get()                    - Alias for get_policy()
     Policy::int/float/string/bool()  - Typed accessors
     Policy::all() / values()         - Bulk read (single query)
     Policy::has()                    - Existence check
     Policy::describe()               - Full metadata for a code
     Policy::effectiveFrom()          - Legal effective date
     Policy::humanize()               - MATERNITY_DAYS → "Maternity Days"
     Policy::clearCache()             - Flush bulk-read cache

     Named shortcuts (one per constant):
       Policy::maternityDays()
       Policy::paternityDays($civilServant = false)
       Policy::annualLeaveBaseDays()
       Policy::annualLeaveMaxDays()
       Policy::sickLeaveFullDays()
       Policy::sickLeaveHalfDays()
       Policy::sickLeaveUnpaidDays()
       Policy::specialLeaveDays()
       Policy::retirementAge()
       Policy::earlyRetirementAge()
       Policy::earlyRetirementMinYears()
       Policy::noticePeriodDays()
       Policy::procurementDirectorThreshold()
       Policy::workingHoursPerDay()
       Policy::slaDays($requestType)
       Policy::slaHours($requestType)

     Business-rule helpers:
       Policy::retirementEligible($age, $years)
       Policy::procurementNeedsDirector($amount)
       Policy::annualLeaveForYears($years)     - Tenure-based entitlement

   USAGE
     // Old style (still works — unchanged):
     $days = get_policy('MATERNITY_DAYS', 120);

     // New style (typed):
     $days = Policy::maternityDays();                    // int
     $age  = Policy::retirementAge();                    // int
     $ok   = Policy::retirementEligible(60, 29.6);       // bool

     // SLA:
     $hours = Policy::slaHours('leave');                 // int

     // UI:
     $meta = Policy::describe('MATERNITY_DAYS');
     // → ['value' => 120, 'legal_basis' => 'Labour Proclamation 1156/2019',
     //    'effective_from' => '2019-01-01', 'description' => 'Total paid...']

   SAFETY
     - Direct access refused with 403
     - Safe to require_once multiple times
     - Never throws (all reads wrapped in try/catch)
     - No write operations (that's admin/policy_constants.php's job)
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
if (!class_exists('Policy', false)) {

    final class Policy
    {
        /** Bulk-read cache for Policy::all(). */
        private static ?array $allCache = null;


        /* ====================================================
           3.1 CORE ALIASES
           ==================================================== */

        /**
         * Alias for get_policy(). Signature identical.
         */
        public static function get(string $code, $default = null)
        {
            return get_policy($code, $default);
        }

        /**
         * Read as integer. Truncates floats and numeric strings.
         */
        public static function int(string $code, int $default = 0): int
        {
            $v = get_policy($code, $default);
            if (is_numeric($v)) return (int)$v;
            return $default;
        }

        /**
         * Read as float.
         */
        public static function float(string $code, float $default = 0.0): float
        {
            $v = get_policy($code, $default);
            if (is_numeric($v)) return (float)$v;
            return $default;
        }

        /**
         * Read as trimmed string.
         */
        public static function string(string $code, string $default = ''): string
        {
            $v = get_policy($code, $default);
            return trim((string)($v ?? $default));
        }

        /**
         * Read as boolean. Treats "1", "true", "yes", "on", 1, true as TRUE.
         */
        public static function bool(string $code, bool $default = false): bool
        {
            $v = get_policy($code, $default);
            if (is_bool($v)) return $v;
            if (is_numeric($v)) return ((int)$v) !== 0;

            $s = strtolower(trim((string)$v));
            return in_array($s, ['1', 'true', 'yes', 'on', 'y'], true);
        }


        /* ====================================================
           3.2 BULK READ
           ==================================================== */

        /**
         * Read the entire policy_constants table in one query.
         *
         * @return array<string, array{
         *   value: mixed, value_num: ?float, value_text: ?string,
         *   description: ?string, legal_basis: ?string, effective_from: ?string
         * }>
         */
        public static function all(): array
        {
            if (self::$allCache !== null) return self::$allCache;

            global $pdo;
            try {
                $rows = $pdo->query("
                    SELECT code, value_num, value_text, description, legal_basis, effective_from
                    FROM policy_constants
                ")->fetchAll();

                $out = [];
                foreach ($rows as $r) {
                    $out[$r['code']] = [
                        'value'          => $r['value_num'] !== null ? $r['value_num'] : $r['value_text'],
                        'value_num'      => $r['value_num'],
                        'value_text'     => $r['value_text'],
                        'description'    => $r['description'],
                        'legal_basis'    => $r['legal_basis'],
                        'effective_from' => $r['effective_from'],
                    ];
                }

                return self::$allCache = $out;
            } catch (PDOException $e) {
                error_log('Policy::all() failed: ' . $e->getMessage());
                return self::$allCache = [];
            }
        }

        /**
         * Just the code => value pairs (no metadata).
         */
        public static function values(): array
        {
            $out = [];
            foreach (self::all() as $code => $meta) {
                $out[$code] = $meta['value'];
            }
            return $out;
        }

        /**
         * Does a constant exist in the DB?
         */
        public static function has(string $code): bool
        {
            return array_key_exists($code, self::all());
        }

        /**
         * Full metadata for one constant.
         * Returns null if not present.
         */
        public static function describe(string $code): ?array
        {
            return self::all()[$code] ?? null;
        }

        /**
         * Legal effective date (YYYY-MM-DD) or null.
         */
        public static function effectiveFrom(string $code): ?string
        {
            $meta = self::describe($code);
            return $meta['effective_from'] ?? null;
        }

        /**
         * Turn MATERNITY_DAYS into "Maternity Days". Useful for UI labels.
         */
        public static function humanize(string $code): string
        {
            return ucwords(strtolower(str_replace('_', ' ', $code)));
        }

        /**
         * Flush the bulk-read cache.
         * Call after admin/policy_constants.php writes a new value.
         */
        public static function clearCache(): void
        {
            self::$allCache = null;
        }


        /* ====================================================
           3.3 NAMED SHORTCUTS — LEAVE POLICIES
           ==================================================== */

        /** Total paid maternity days (Labour Proclamation 1156/2019). */
        public static function maternityDays(int $default = 120): int
        {
            return self::int('MATERNITY_DAYS', $default);
        }

        /**
         * Paid paternity days.
         * Civil servants get more (Proclamation 1353/2024) than
         * private-sector employees (Proclamation 1156/2019).
         */
        public static function paternityDays(bool $civilServant = true, int $default = 0): int
        {
            if ($civilServant) {
                return self::int('PATERNITY_DAYS_CIVIL', $default > 0 ? $default : 10);
            }
            return self::int('PATERNITY_DAYS', $default > 0 ? $default : 3);
        }

        /** Base annual leave entitlement for new employees. */
        public static function annualLeaveBaseDays(int $default = 20): int
        {
            return self::int('ANNUAL_LEAVE_BASE_DAYS', $default);
        }

        /** Maximum annual leave days (with seniority). */
        public static function annualLeaveMaxDays(int $default = 30): int
        {
            return self::int('ANNUAL_LEAVE_MAX_DAYS', $default);
        }

        /** Sick leave — full-pay days. */
        public static function sickLeaveFullDays(int $default = 90): int
        {
            return self::int('SICK_LEAVE_FULL_DAYS', $default);
        }

        /** Sick leave — half-pay days. */
        public static function sickLeaveHalfDays(int $default = 90): int
        {
            return self::int('SICK_LEAVE_HALF_DAYS', $default);
        }

        /** Sick leave — unpaid days. */
        public static function sickLeaveUnpaidDays(int $default = 60): int
        {
            return self::int('SICK_LEAVE_UNPAID_DAYS', $default);
        }

        /** Special (mourning/marriage) leave days. */
        public static function specialLeaveDays(int $default = 7): int
        {
            return self::int('SPECIAL_LEAVE_DAYS', $default);
        }


        /* ====================================================
           3.4 NAMED SHORTCUTS — RETIREMENT & RESIGNATION
           ==================================================== */

        /** Standard retirement age. */
        public static function retirementAge(int $default = 60): int
        {
            return self::int('RETIREMENT_AGE', $default);
        }

        /** Earliest early-retirement age (with enough years). */
        public static function earlyRetirementAge(int $default = 55): int
        {
            return self::int('EARLY_RETIREMENT_AGE', $default);
        }

        /** Minimum years of service for early retirement. */
        public static function earlyRetirementMinYears(int $default = 25): int
        {
            return self::int('EARLY_RETIREMENT_MIN_YEARS', $default);
        }

        /** Resignation notice period (days). */
        public static function noticePeriodDays(int $default = 30): int
        {
            return self::int('NOTICE_PERIOD_DAYS', $default);
        }


        /* ====================================================
           3.5 NAMED SHORTCUTS — PROCUREMENT & WORKING HOURS
           ==================================================== */

        /** Above this ETB amount, procurement needs director approval. */
        public static function procurementDirectorThreshold(float $default = 25000.0): float
        {
            return self::float('PROCUREMENT_DIRECTOR_THRESHOLD', $default);
        }

        /** Standard working hours per day. */
        public static function workingHoursPerDay(float $default = 8.0): float
        {
            return self::float('WORKING_HOURS_PER_DAY', $default);
        }


        /* ====================================================
           3.6 SLA LOOKUPS
           ==================================================== */

        /**
         * SLA target in days for a given request type.
         *
         * Recognized types (case-insensitive):
         *   'clearance'            → SLA_CLEARANCE_DEPT_DAYS
         *   'leave' / 'leave_short'→ SLA_LEAVE_SHORT_DAYS
         *   'leave_long'           → SLA_LEAVE_LONG_DAYS
         *   'maternal'             → SLA_MATERNAL_DAYS
         *   'pension'              → SLA_PENSION_DAYS
         *   'resignation'          → SLA_RESIGNATION_DAYS
         *   'procurement'          → SLA_PROCUREMENT_DAYS
         *   'plan' / 'planning'    → SLA_PLANNING_DAYS
         *
         * Unknown types return the default (3 days).
         */
        public static function slaDays(string $requestType, int $default = 3): int
        {
            $key = strtolower(trim($requestType));

            return match ($key) {
                'clearance'                     => self::int('SLA_CLEARANCE_DEPT_DAYS', $default),
                'leave', 'leave_short'          => self::int('SLA_LEAVE_SHORT_DAYS',     $default),
                'leave_long'                    => self::int('SLA_LEAVE_LONG_DAYS',      $default),
                'maternal', 'maternity'         => self::int('SLA_MATERNAL_DAYS',        $default),
                'pension'                       => self::int('SLA_PENSION_DAYS',         $default),
                'resignation'                   => self::int('SLA_RESIGNATION_DAYS',     $default),
                'procurement'                   => self::int('SLA_PROCUREMENT_DAYS',     $default),
                'plan', 'planning'              => self::int('SLA_PLANNING_DAYS',        $default),
                default                         => $default,
            };
        }

        /**
         * SLA target in hours for a given request type.
         *
         * Matches the calculation used by the v_pending_sla view,
         * which hardcodes 72 hours for a 3-day SLA. That's
         * wall-clock hours (days × 24), not working hours.
         */
        public static function slaHours(string $requestType, int $defaultDays = 3): int
        {
            return self::slaDays($requestType, $defaultDays) * 24;
        }


        /* ====================================================
           3.7 BUSINESS-RULE HELPERS
           ==================================================== */

        /**
         * Determine if an employee is eligible to retire.
         *
         * Two paths:
         *   1. Standard retirement: age >= RETIREMENT_AGE
         *   2. Early retirement: age >= EARLY_RETIREMENT_AGE
         *      AND years_of_service >= EARLY_RETIREMENT_MIN_YEARS
         *
         * Used by modules/pension/pension_new.php.
         */
        public static function retirementEligible(int $age, float $yearsOfService): bool
        {
            // Standard retirement age
            if ($age >= self::retirementAge()) {
                return true;
            }

            // Early retirement with years of service
            if ($age >= self::earlyRetirementAge()
                && $yearsOfService >= self::earlyRetirementMinYears()) {
                return true;
            }

            return false;
        }

        /**
         * Does a procurement request of this amount need director approval?
         * Used by modules/procurement/procurement_new.php to decide
         * which workflow steps to attach.
         */
        public static function procurementNeedsDirector(float $totalAmount): bool
        {
            return $totalAmount >= self::procurementDirectorThreshold();
        }

        /**
         * Compute annual leave entitlement based on years of service.
         *
         * Ethiopian Civil Service rule (Proclamation 1353/2024):
         *   Year 1:             20 days (base)
         *   Each additional yr: +1 day
         *   Cap:                30 days (max)
         *
         * @param  int $years  Full years of service completed
         * @return int         Days entitled for the current fiscal year
         */
        public static function annualLeaveForYears(int $years): int
        {
            $base = self::annualLeaveBaseDays();
            $max  = self::annualLeaveMaxDays();

            if ($years <= 1) return $base;

            $entitled = $base + ($years - 1);
            return min($entitled, $max);
        }

        /**
         * Total sick leave allowance (full + half + unpaid).
         * Useful for HR balance widgets.
         */
        public static function sickLeaveTotalDays(): int
        {
            return self::sickLeaveFullDays()
                 + self::sickLeaveHalfDays()
                 + self::sickLeaveUnpaidDays();
        }

        /**
         * All "leave" policy values in a single associative array.
         * Convenient for balance calculators.
         */
        public static function leaveSummary(): array
        {
            return [
                'annual_base'    => self::annualLeaveBaseDays(),
                'annual_max'     => self::annualLeaveMaxDays(),
                'sick_full'      => self::sickLeaveFullDays(),
                'sick_half'      => self::sickLeaveHalfDays(),
                'sick_unpaid'    => self::sickLeaveUnpaidDays(),
                'special'        => self::specialLeaveDays(),
                'maternity'      => self::maternityDays(),
                'paternity'      => self::paternityDays(false),
                'paternity_civil'=> self::paternityDays(true),
            ];
        }
    }
}