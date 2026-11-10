<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   AUTHENTICATION & AUTHORIZATION MODULE
   ============================================================
   PURPOSE
     Safety wrapper around current_user(), require_login(),
     has_role(), and can_approve_step() from functions.php.
     Adds semantic shortcuts, one-line access denial, and
     centralized session setup/teardown.

   THE CANONICAL FUNCTIONS (defined in functions.php)
     current_user(): ?array
     require_login(): array
     has_role(string|array $roles): bool
     require_role(string|array $roles): void
     can_approve_step(string $step_role_required): bool

   THIS FILE ADDS
     -- Aliases --
     Auth::user()                    - current_user()
     Auth::require()                 - require_login()
     Auth::role($roles)              - has_role()
     Auth::canApproveStep($role)     - can_approve_step()

     -- Status --
     Auth::check()                   - bool: logged in?
     Auth::guest()                   - bool: NOT logged in?

     -- Type shortcuts --
     Auth::isAdmin()                 - bool
     Auth::isStudent()               - bool
     Auth::isStaff()                 - bool: any non-student role
     Auth::canApproveAny($roles)     - bool: can approve any of these?

     -- User attributes (safe accessors) --
     Auth::id()                      - int|null
     Auth::fullName()                - string (empty if not logged in)
     Auth::firstName()               - string (first word of name)
     Auth::initial()                 - string (single letter)
     Auth::initials()                - string (first + last initial)
     Auth::position()                - string (empty if none)
     Auth::departmentId()            - int|null
     Auth::departmentCode()          - string (empty if none)
     Auth::departmentName()          - string (empty if none)

     -- Access control --
     Auth::requireRole($roles, $msg, $redirect)
                                     - flash + redirect on denial
     Auth::enforceRole($roles)       - hard 403 on denial

     -- Session lifecycle --
     Auth::login($userId)            - setup session + log + timestamp
     Auth::logout()                  - clean teardown, fresh session

   USAGE
     // Old style (still works):
     $user = require_login();
     if (!has_role('admin')) {
         flash('danger', 'Access denied.');
         redirect(APP_URL . '/index.php');
     }

     // New style (one line):
     $user = Auth::require();
     Auth::requireRole('admin');

     // Type-specific:
     if (Auth::isStudent()) { redirect('student_dashboard.php'); }

     // User info:
     echo "Welcome, " . Auth::firstName();
     echo "ID: " . Auth::id();
     echo "Dept: " . Auth::departmentCode();

     // Login flow (login.php):
     Auth::login($user['id']);
     Flash::success("Welcome back, " . Auth::firstName() . "!");
     redirect(APP_URL . '/index.php');

     // Logout flow (logout.php):
     Auth::logout();
     Flash::success('You have been logged out.');
     redirect(APP_URL . '/login.php');

   SAFETY
     - Direct access refused with 403
     - Safe to require_once multiple times
     - Never throws
     - Works in CLI (cron, tests)
     - All user attribute accessors handle null (return empty/null)
     - requireRole() uses flash+redirect; enforceRole() uses 403
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
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/audit.php';


/* ------------------------------------------------------------
   3. CLASS-BASED API
   ------------------------------------------------------------ */
if (!class_exists('Auth', false)) {

    final class Auth
    {
        /* ====================================================
           3.1 CANONICAL ALIASES
           Identical behavior to the base functions.
           ==================================================== */

        /** Alias for current_user(). Returns null if not logged in. */
        public static function user(): ?array
        {
            return current_user();
        }

        /** Alias for require_login(). Redirects to login if not authenticated. */
        public static function require(): array
        {
            return require_login();
        }

        /** Alias for has_role(). Accepts string or array. */
        public static function role(string|array $roles): bool
        {
            return has_role($roles);
        }

        /** Alias for can_approve_step(). */
        public static function canApproveStep(string $step_role_required): bool
        {
            return can_approve_step($step_role_required);
        }


        /* ====================================================
           3.2 STATUS CHECKS
           ==================================================== */

        /** Is the user currently logged in? */
        public static function check(): bool
        {
            return current_user() !== null;
        }

        /** Is the user NOT logged in? Alias for !check(). */
        public static function guest(): bool
        {
            return current_user() === null;
        }


        /* ====================================================
           3.3 ROLE TYPE SHORTCUTS
           ==================================================== */

        /** Does the user have the admin role? */
        public static function isAdmin(): bool
        {
            return has_role('admin');
        }

        /** Is the user a student? */
        public static function isStudent(): bool
        {
            $u = current_user();
            if (!$u) return false;
            return ($u['employment_type'] ?? '') === 'student' || has_role('student');
        }

        /** Is the user any kind of staff (not a student)? */
        public static function isStaff(): bool
        {
            return self::check() && !self::isStudent();
        }

        /**
         * Can the current user approve ANY of the given role codes?
         * Useful for dashboard "Action Required" widgets.
         *
         *   Auth::canApproveAny(['department_head', 'hr'])
         */
        public static function canApproveAny(string|array $roles): bool
        {
            $roles = (array)$roles;
            foreach ($roles as $r) {
                if (can_approve_step((string)$r)) return true;
            }
            return false;
        }


        /* ====================================================
           3.4 USER ATTRIBUTES
           All accessors return safe defaults when not logged in.
           ==================================================== */

        /** Current user ID, or null if not logged in. */
        public static function id(): ?int
        {
            $u = current_user();
            return $u ? (int)$u['id'] : null;
        }

        /** Current user's full name (empty string if not logged in). */
        public static function fullName(): string
        {
            $u = current_user();
            return $u ? (string)$u['full_name'] : '';
        }

        /**
         * First word of the user's full name.
         * "Abebe Kebede" → "Abebe"
         * "Dr. Yohannes Haile" → "Dr."
         */
        public static function firstName(): string
        {
            $name = trim(self::fullName());
            if ($name === '') return '';
            $parts = preg_split('/\s+/', $name);
            return $parts[0] ?? '';
        }

        /** First character of the full name, uppercased (for avatar bubbles). */
        public static function initial(): string
        {
            $name = self::fullName();
            return $name !== '' ? strtoupper(mb_substr($name, 0, 1, 'UTF-8')) : '?';
        }

        /**
         * Two-letter initials — first + last word.
         * "Abebe Kebede" → "AK"
         * "Dr. Yohannes Haile" → "DH"
         * "Meron" → "M"
         */
        public static function initials(): string
        {
            $name = trim(self::fullName());
            if ($name === '') return '?';

            $parts = preg_split('/\s+/', $name);
            if (count($parts) === 1) {
                return strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8'));
            }
            return strtoupper(
                mb_substr($parts[0], 0, 1, 'UTF-8')
                . mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8')
            );
        }

        /** Current user's position (empty string if not set). */
        public static function position(): string
        {
            $u = current_user();
            return $u ? (string)($u['position'] ?? '') : '';
        }

        /** Current user's department ID, or null. */
        public static function departmentId(): ?int
        {
            $u = current_user();
            if (!$u || empty($u['department_id'])) return null;
            return (int)$u['department_id'];
        }

        /** Current user's department code (empty string if not set). */
        public static function departmentCode(): string
        {
            $u = current_user();
            return $u ? (string)($u['dept_code'] ?? '') : '';
        }

        /** Current user's department name (empty string if not set). */
        public static function departmentName(): string
        {
            $u = current_user();
            return $u ? (string)($u['dept_name'] ?? '') : '';
        }


        /* ====================================================
           3.5 ACCESS CONTROL
           ==================================================== */

        /**
         * Require one or more roles. On failure:
         *   1. Flash a danger message
         *   2. Redirect (default: dashboard)
         *
         * Replaces the 4-line pattern used throughout the codebase:
         *   if (!has_role('admin')) {
         *       flash('danger', 'Access denied.');
         *       redirect(APP_URL . '/index.php');
         *   }
         *
         * @param string|array $roles       Required role(s) — ANY of them grants access
         * @param string       $message     Flash message on denial
         * @param string|null  $redirectUrl Where to redirect on denial
         */
        public static function requireRole(
            string|array $roles,
            string $message = 'Access denied. (ፍቃድ የለዎትም)',
            ?string $redirectUrl = null
        ): void {
            if (has_role($roles)) return;

            flash('danger', $message);
            redirect($redirectUrl ?? (APP_URL . '/index.php'));
        }

        /**
         * Enforce role(s) with a hard 403 page. No redirect.
         * Delegates to require_role() from functions.php.
         */
        public static function enforceRole(string|array $roles): void
        {
            require_role($roles);
        }


        /* ====================================================
           3.6 SESSION LIFECYCLE
           ==================================================== */

        /**
         * Establish an authenticated session for a user.
         *
         * Handles:
         *   1. Session fixation prevention (regenerate ID)
         *   2. Set $_SESSION['uid']
         *   3. Update last_login_at timestamp
         *   4. Log the login event
         *
         * The caller is responsible for:
         *   - Flashing a welcome message
         *   - Redirecting to the post-login destination
         */
        public static function login(int $userId): void
        {
            if ($userId <= 0) {
                throw new InvalidArgumentException('Auth::login() requires a positive user ID');
            }

            // 1. Prevent session fixation
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            // 2. Set the authenticated user
            $_SESSION['uid'] = $userId;

            // 3. Update last_login_at (silently — don't fail login on this)
            try {
                global $pdo;
                $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
                    ->execute([$userId]);
            } catch (PDOException $e) {
                error_log('Auth::login() last_login_at update failed: ' . $e->getMessage());
            }

            // 4. Log the event (Audit::loggedIn is defined in audit.php)
            Audit::loggedIn($userId);
        }

        /**
         * End the current session cleanly.
         *
         * Handles:
         *   1. Clear all session data
         *   2. Delete the session cookie
         *   3. Destroy the session
         *   4. Start a fresh session (so the caller can flash a message)
         *
         * After calling this, the caller may do:
         *   Flash::success('You have been logged out.');
         *   redirect(APP_URL . '/login.php');
         */
        public static function logout(): void
        {
            $wasLoggedIn = self::id();

            // 1. Clear session data
            $_SESSION = [];

            // 2. Delete cookie
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'] ?? '/',
                    $params['domain'] ?? '',
                    (bool)($params['secure'] ?? false),
                    (bool)($params['httponly'] ?? true)
                );
            }

            // 3. Destroy server-side session
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            // 4. Start a fresh empty session so flash() works
            if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
                session_start();
            }

            // 5. Log the logout event (Audit handles null user_id gracefully)
            if ($wasLoggedIn !== null) {
                Audit::loggedOut($wasLoggedIn);
            }
        }
    }
}