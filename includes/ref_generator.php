<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   REFERENCE NUMBER MODULE
   ============================================================
   PURPOSE
     Wrapper around generate_ref() from functions.php, plus
     semantic generators, parsers, and validators for the
     three reference formats used throughout the system.

   THE CANONICAL FUNCTION (defined in functions.php)
     generate_ref(string $prefix): string
       Returns: PREFIX-YYYYMMDD-XXXXXX (uppercase)
       Example: LTR-20261030-A1B2C3
       Randomness: 3 bytes (bin2hex) = 16.7M combinations per prefix per day

   THREE REFERENCE FORMATS
     1. LETTERS             LTR-YYYYMMDD-XXXXXX
        columns: letters.ref_no VARCHAR(40) UNIQUE
        used by: letter_new.php → generate_ref('LTR')

     2. SERVICE REQUESTS    REQ-{TYPE}-YYYYMMDD-XXXXXX
        columns: service_requests.ref_no VARCHAR(50) UNIQUE
        used by: sp_create_service_request() inside MySQL
        PHP equivalent: Ref::serviceRequest('leave')
        Byte-identical to the DB output — safe to swap.

     3. CLEARANCE           CLR-{S|E}-YYYYMMDD-XXXXXX
        columns: clearance_requests.ref_no VARCHAR(40) UNIQUE
        used by: sp_create_clearance() inside MySQL
        PHP equivalent: Ref::clearance('student'|'employee')

   USAGE
     // Generation (identical to generate_ref):
     $ref = Ref::letter();                    // LTR-20261030-A1B2C3
     $ref = Ref::serviceRequest('leave');     // REQ-LEAVE-20261030-A1B2C3
     $ref = Ref::clearance('student');        // CLR-S-20261030-A1B2C3

     // Unique generation with collision retry:
     $ref = Ref::uniqueForTable('LTR', 'letters');

     // Parsing:
     $parts = Ref::parse('REQ-LEAVE-20261030-DA9444');
     // → ['valid' => true, 'prefix' => 'REQ-LEAVE', 'kind' => 'service',
     //    'domain' => 'leave', 'date' => '2026-10-30', 'date_raw' => '20261030',
     //    'suffix' => 'DA9444', 'raw' => 'REQ-LEAVE-20261030-DA9444']

     // Type checks:
     if (Ref::isLetter($ref)) { ... }
     if (Ref::isServiceRequest($ref, 'leave')) { ... }
     if (Ref::isClearance($ref, 'student')) { ... }

     // Extract creation date without a DB query:
     $created = Ref::date($ref);              // '2026-10-30' or null

   LENGTH LIMITS
     letters.ref_no:            VARCHAR(40) → prefix ≤ 24 chars
     clearance_requests.ref_no: VARCHAR(40) → prefix ≤ 24 chars
     service_requests.ref_no:   VARCHAR(50) → prefix ≤ 34 chars

     Longest current prefix: REQ-DISCIPLINARY (16 chars) → total 32 chars. Fits.

   SAFETY
     - Direct access refused with 403
     - Safe to require_once multiple times
     - Never redefines generate_ref()
     - Unique-retry capped at 8 attempts (prevents infinite loop)
     - Parse is regex-validated (no injection surface)
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
if (!class_exists('Ref', false)) {

    final class Ref
    {
        /* ====================================================
           3.1 CONSTANTS
           ==================================================== */

        /** Letters table prefix. */
        public const PREFIX_LETTER = 'LTR';

        /** Base prefix for all service requests — combine with type. */
        public const PREFIX_SERVICE = 'REQ';

        /** Clearance prefix for student applicants. */
        public const PREFIX_CLEARANCE_STUDENT = 'CLR-S';

        /** Clearance prefix for employee applicants. */
        public const PREFIX_CLEARANCE_EMPLOYEE = 'CLR-E';

        /**
         * Valid request types — matches service_requests.request_type enum.
         * Use this in dropdowns, validation, or match() arms.
         */
        public const SERVICE_TYPES = [
            'leave',
            'maternal',
            'pension',
            'resignation',
            'promotion',
            'training',
            'overtime',
            'procurement',
            'plan',
            'disciplinary',
            'grievance',
            'other',
        ];

        /** Tables Ref::uniqueForTable() may check. */
        private const UNIQUE_TABLES = [
            'letters',
            'service_requests',
            'clearance_requests',
        ];

        /**
         * Regex for parsing any valid reference.
         * Groups: 1=prefix, 2=YYYYMMDD, 3=6-hex suffix
         *
         * Prefix is one or more letter runs separated by hyphens,
         * ensuring "REQ-LEAVE" (two runs) and "CLR-S" (two runs)
         * both work, while rejecting "A-1-..." (digit segment).
         */
        private const PARSE_PATTERN = '/^([A-Z]+(?:-[A-Z]+)*)-(\d{8})-([A-F0-9]{6})$/';


        /* ====================================================
           3.2 CORE GENERATION
           ==================================================== */

        /**
         * Alias for generate_ref() with extra safety.
         *
         * - Trims and uppercases the prefix
         * - Warns (to error_log) if prefix is longer than 34 chars
         *   (would fail on service_requests VARCHAR(50))
         * - Never redefines generate_ref()
         */
        public static function generate(string $prefix): string
        {
            $prefix = strtoupper(trim($prefix));

            if (strlen($prefix) > 34) {
                error_log("Ref::generate() prefix too long: '{$prefix}' (" . strlen($prefix) . " chars, max 34)");
            }

            return generate_ref($prefix);
        }


        /* ====================================================
           3.3 SEMANTIC GENERATORS
           ==================================================== */

        /**
         * Generate a letter reference.
         * Output: LTR-YYYYMMDD-XXXXXX
         */
        public static function letter(): string
        {
            return self::generate(self::PREFIX_LETTER);
        }

        /**
         * Generate a service request reference.
         * Output: REQ-{TYPE}-YYYYMMDD-XXXXXX
         *
         * Byte-identical to what sp_create_service_request()
         * produces inside MySQL — safe to swap without breaking
         * existing data queries.
         *
         * @throws InvalidArgumentException  If $type is not in SERVICE_TYPES
         */
        public static function serviceRequest(string $type): string
        {
            $type = strtolower(trim($type));

            if (!in_array($type, self::SERVICE_TYPES, true)) {
                throw new InvalidArgumentException(
                    "Invalid service request type '{$type}'. "
                    . "Allowed: " . implode(', ', self::SERVICE_TYPES)
                );
            }

            return self::generate(self::PREFIX_SERVICE . '-' . strtoupper($type));
        }

        /**
         * Generate a clearance reference.
         * Output: CLR-S-YYYYMMDD-XXXXXX (student)
         *         CLR-E-YYYYMMDD-XXXXXX (employee)
         *
         * Byte-identical to what sp_create_clearance() produces.
         *
         * @throws InvalidArgumentException  If $applicantType is not
         *                                    'student' or 'employee'
         */
        public static function clearance(string $applicantType): string
        {
            $type = strtolower(trim($applicantType));

            $prefix = match ($type) {
                'student'  => self::PREFIX_CLEARANCE_STUDENT,
                'employee' => self::PREFIX_CLEARANCE_EMPLOYEE,
                default    => throw new InvalidArgumentException(
                    "Clearance type must be 'student' or 'employee', got '{$applicantType}'"
                ),
            };

            return self::generate($prefix);
        }


        /* ====================================================
           3.4 UNIQUENESS (collision retry)
           ==================================================== */

        /**
         * Generate a unique reference by retrying if the caller-
         * supplied existence check returns TRUE.
         *
         * Usage:
         *   $ref = Ref::unique('LTR', function ($r) use ($pdo) {
         *       $st = $pdo->prepare("SELECT 1 FROM letters WHERE ref_no=? LIMIT 1");
         *       $st->execute([$r]);
         *       return (bool)$st->fetchColumn();
         *   });
         *
         * @throws RuntimeException  If no unique value found after $maxAttempts
         */
        public static function unique(
            string $prefix,
            callable $existsCheck,
            int $maxAttempts = 8
        ): string {
            for ($i = 0; $i < $maxAttempts; $i++) {
                $candidate = self::generate($prefix);
                if (!$existsCheck($candidate)) {
                    return $candidate;
                }
            }

            throw new RuntimeException(
                "Ref::unique() failed to find a unique value for prefix "
                . "'{$prefix}' after {$maxAttempts} attempts. "
                . "This suggests either an extremely full ID space "
                . "or a buggy existsCheck callable."
            );
        }

        /**
         * Convenience wrapper around unique() that checks the correct
         * UNIQUE column directly. Whitelisted tables only.
         *
         * @param string $prefix   e.g. 'LTR'
         * @param string $table    'letters' | 'service_requests' | 'clearance_requests'
         * @throws InvalidArgumentException  If $table is not whitelisted
         * @throws RuntimeException           On uniqueness failure
         */
        public static function uniqueForTable(
            string $prefix,
            string $table,
            int $maxAttempts = 8
        ): string {
            if (!in_array($table, self::UNIQUE_TABLES, true)) {
                throw new InvalidArgumentException(
                    "Ref::uniqueForTable() rejected table '{$table}'. "
                    . "Allowed: " . implode(', ', self::UNIQUE_TABLES)
                );
            }

            global $pdo;

            return self::unique(
                $prefix,
                function (string $candidate) use ($pdo, $table): bool {
                    $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE ref_no = ? LIMIT 1");
                    $st->execute([$candidate]);
                    return (bool)$st->fetchColumn();
                },
                $maxAttempts
            );
        }


        /* ====================================================
           3.5 PARSING
           ==================================================== */

        /**
         * Decompose a reference into its parts.
         *
         * Returns an associative array with:
         *   valid     bool       true if format matched
         *   raw       string     original input (trimmed + uppercased)
         *   prefix    ?string    e.g. 'REQ-LEAVE'
         *   kind      ?string    'letter' | 'service' | 'clearance' | 'unknown'
         *   domain    ?string    'leave' | 'student' | 'employee' | null
         *   date      ?string    'YYYY-MM-DD' or null
         *   date_raw  ?string    'YYYYMMDD' or null
         *   suffix    ?string    'DA9444' or null
         *
         * For invalid input, only 'valid' and 'raw' are populated.
         */
        public static function parse(string $ref): array
        {
            $raw = self::normalize($ref);

            $result = [
                'valid'    => false,
                'raw'      => $raw,
                'prefix'   => null,
                'kind'     => null,
                'domain'   => null,
                'date'     => null,
                'date_raw' => null,
                'suffix'   => null,
            ];

            if ($raw === '' || !preg_match(self::PARSE_PATTERN, $raw, $m)) {
                return $result;
            }

            $prefix  = $m[1];
            $dateRaw = $m[2];
            $suffix  = $m[3];

            // Format date as YYYY-MM-DD
            $date = substr($dateRaw, 0, 4)
                  . '-' . substr($dateRaw, 4, 2)
                  . '-' . substr($dateRaw, 6, 2);

            // Classify prefix
            $kind   = 'unknown';
            $domain = null;

            if ($prefix === self::PREFIX_LETTER) {
                $kind = 'letter';
            } elseif (str_starts_with($prefix, self::PREFIX_SERVICE . '-')) {
                $kind   = 'service';
                $domain = strtolower(substr($prefix, strlen(self::PREFIX_SERVICE) + 1));
            } elseif ($prefix === self::PREFIX_CLEARANCE_STUDENT) {
                $kind   = 'clearance';
                $domain = 'student';
            } elseif ($prefix === self::PREFIX_CLEARANCE_EMPLOYEE) {
                $kind   = 'clearance';
                $domain = 'employee';
            }

            return [
                'valid'    => true,
                'raw'      => $raw,
                'prefix'   => $prefix,
                'kind'     => $kind,
                'domain'   => $domain,
                'date'     => $date,
                'date_raw' => $dateRaw,
                'suffix'   => $suffix,
            ];
        }

        /**
         * Is this a well-formed reference string?
         */
        public static function validate(string $ref): bool
        {
            return preg_match(self::PARSE_PATTERN, self::normalize($ref)) === 1;
        }

        /**
         * Extract the creation date as 'YYYY-MM-DD' (from the YYYYMMDD
         * segment) without any DB query. Returns null on invalid refs.
         */
        public static function date(string $ref): ?string
        {
            $parsed = self::parse($ref);
            return $parsed['date'];
        }


        /* ====================================================
           3.6 TYPE GUARDS
           ==================================================== */

        /**
         * Is this reference a letter?
         */
        public static function isLetter(string $ref): bool
        {
            $parsed = self::parse($ref);
            return $parsed['valid'] && $parsed['kind'] === 'letter';
        }

        /**
         * Is this a service request? Optionally match a specific type.
         *
         *   Ref::isServiceRequest($ref);                   // any service
         *   Ref::isServiceRequest($ref, 'leave');          // leave only
         *   Ref::isServiceRequest($ref, ['leave','maternal']); // either
         */
        public static function isServiceRequest(string $ref, string|array|null $type = null): bool
        {
            $parsed = self::parse($ref);
            if (!$parsed['valid'] || $parsed['kind'] !== 'service') {
                return false;
            }

            if ($type === null) {
                return true;
            }

            $types = is_array($type) ? $type : [$type];
            $types = array_map(fn($t) => strtolower(trim((string)$t)), $types);

            return in_array($parsed['domain'], $types, true);
        }

        /**
         * Is this a clearance? Optionally match a specific applicant type.
         *
         *   Ref::isClearance($ref);                     // any clearance
         *   Ref::isClearance($ref, 'student');          // student only
         *   Ref::isClearance($ref, ['student','employee']);
         */
        public static function isClearance(string $ref, string|array|null $type = null): bool
        {
            $parsed = self::parse($ref);
            if (!$parsed['valid'] || $parsed['kind'] !== 'clearance') {
                return false;
            }

            if ($type === null) {
                return true;
            }

            $types = is_array($type) ? $type : [$type];
            $types = array_map(fn($t) => strtolower(trim((string)$t)), $types);

            return in_array($parsed['domain'], $types, true);
        }


        /* ====================================================
           3.7 NORMALIZE & DESCRIBE
           ==================================================== */

        /**
         * Trim whitespace and uppercase. Handles pasted refs with
         * accidental lowercase or surrounding spaces.
         */
        public static function normalize(string $ref): string
        {
            return strtoupper(trim($ref));
        }

        /**
         * Human-readable description of a reference.
         *
         *   "Letter LTR-20261030-A1B2C3 (created 2026-10-30)"
         *   "Service request (leave) REQ-LEAVE-20261030-DA9444 (created 2026-10-30)"
         *   "Clearance (student) CLR-S-20261030-B9ACFF (created 2026-10-30)"
         *   "Invalid reference 'garbage'"
         */
        public static function describe(string $ref): string
        {
            $parsed = self::parse($ref);

            if (!$parsed['valid']) {
                return "Invalid reference '" . self::normalize($ref) . "'";
            }

            $label = match ($parsed['kind']) {
                'letter'    => 'Letter',
                'service'   => 'Service request (' . ($parsed['domain'] ?: 'unknown') . ')',
                'clearance' => 'Clearance (' . ($parsed['domain'] ?: 'unknown') . ')',
                default     => 'Reference',
            };

            $out = $label . ' ' . $parsed['raw'];

            if ($parsed['date']) {
                $out .= ' (created ' . $parsed['date'] . ')';
            }

            return $out;
        }
    }
}