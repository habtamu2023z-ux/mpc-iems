<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   CSRF PROTECTION MODULE
   ============================================================
   PURPOSE
     Safety wrapper around the CSRF functions already defined
     in functions.php, plus a small class-based API and some
     extras that modules occasionally need.

   THE CANONICAL FUNCTIONS (defined in functions.php)
     csrf_token(): string   - Get (or lazily create) session token
     csrf_field(): string   - Render <input type="hidden" name="csrf">
     csrf_check(): void     - Validate POST; abort with 419 on failure

   THIS FILE ADDS
     Csrf::token()          - Alias for csrf_token()
     Csrf::field()          - Alias for csrf_field()
     Csrf::check()          - Alias for csrf_check()
     Csrf::isValid()        - Non-throwing validation (returns bool)
     Csrf::metaTag()        - <meta name="csrf-token"> for JS
     Csrf::rotate()         - Regenerate token (after login, etc.)
     Csrf::invalidate()     - Clear token from session

   USAGE
     // Function style (recommended — matches existing code):
     require_once __DIR__ . '/../includes/csrf.php';
     echo csrf_field();

     // Class style (equivalent):
     Csrf::field();

     // For JavaScript integration:
     echo Csrf::metaTag();       // in <head>

   SAFETY
     - Safe to require_once multiple times
     - Safe if functions.php was already loaded
     - Safe if functions.php was NOT yet loaded (loads it)
     - Refuses direct HTTP access with 403
   ============================================================ */


/* ------------------------------------------------------------
   1. DIRECT ACCESS GUARD
   Rejects requests where this file is the SCRIPT_FILENAME.
   Runs BEFORE the require to avoid loading anything if the
   request is a direct hit to /includes/csrf.php.
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
   functions.php defines csrf_token(), csrf_field(), csrf_check(),
   plus the session bootstrap from config.php.
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';


/* ------------------------------------------------------------
   3. CLASS-BASED API
   Only defined if not already present (safety against
   accidental double-include with different require paths).
   ------------------------------------------------------------ */
if (!class_exists('Csrf', false)) {

    final class Csrf
    {
        /**
         * Get the current CSRF token (creates one if absent).
         */
        public static function token(): string
        {
            return csrf_token();
        }

        /**
         * Render the hidden form field.
         * Usage:  <form> <?= Csrf::field() ?> ... </form>
         */
        public static function field(): string
        {
            return csrf_field();
        }

        /**
         * Validate the token from $_POST['csrf'].
         * Aborts with 419 if invalid (same as csrf_check()).
         */
        public static function check(): void
        {
            csrf_check();
        }

        /**
         * Non-throwing validation. Returns true/false.
         * Useful when you want to flash an error rather than
         * hard-abort with a 419 page.
         *
         * Example:
         *   if (!Csrf::isValid()) {
         *       flash('danger', 'Session expired. Please try again.');
         *       redirect('form.php');
         *   }
         */
        public static function isValid(): bool
        {
            $sent = $_POST['csrf']
                ?? $_SERVER['HTTP_X_CSRF_TOKEN']
                ?? '';

            $expected = $_SESSION['csrf'] ?? '';

            return $expected !== ''
                && is_string($sent)
                && hash_equals($expected, (string)$sent);
        }

        /**
         * Render a <meta> tag so JavaScript can read the token.
         * Place inside <head>:
         *
         *   <?= Csrf::metaTag() ?>
         *
         * workflow.js reads from this tag when making AJAX requests.
         */
        public static function metaTag(): string
        {
            return '<meta name="csrf-token" content="'
                . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
                . '">';
        }

        /**
         * Regenerate the token. Call after login, password change,
         * or any privilege escalation to invalidate old tokens.
         *
         * Example (login.php):
         *   session_regenerate_id(true);
         *   $_SESSION['uid'] = $user['id'];
         *   Csrf::rotate();
         */
        public static function rotate(): string
        {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            return $_SESSION['csrf'];
        }

        /**
         * Clear the token from the session.
         * A fresh token is issued on the next call to token().
         */
        public static function invalidate(): void
        {
            unset($_SESSION['csrf']);
        }

        /**
         * Verify an arbitrary token string against the session.
         * Used by AJAX handlers that pass the token in a header.
         */
        public static function matches(string $token): bool
        {
            $expected = $_SESSION['csrf'] ?? '';
            return $expected !== ''
                && $token !== ''
                && hash_equals($expected, $token);
        }
    }
}