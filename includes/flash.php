<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   FLASH MESSAGE MODULE
   ============================================================
   PURPOSE
     Safety wrapper around flash() and flash_get() from
     functions.php, plus named convenience methods, inspection
     helpers, and a single-call HTML renderer.

   THE CANONICAL FUNCTIONS (defined in functions.php)
     flash(string $type, string $message): void
       - Push a flash message into the session
     flash_get(): array
       - Read and clear all pending flash messages

   THIS FILE ADDS
     Flash::success(string $msg)    - flash('success', $msg)
     Flash::danger(string $msg)     - flash('danger',  $msg)
     Flash::warning(string $msg)    - flash('warning', $msg)
     Flash::info(string $msg)       - flash('info',    $msg)
     Flash::error(string $msg)      - alias for danger
     Flash::html(string $type, string $html)  - raw HTML, no escaping
     Flash::push(string $type, string $msg)   - function-style alias
     Flash::pull()                  - function-style alias for flash_get()
     Flash::has()                   - any messages pending? (non-consuming)
     Flash::count()                 - how many? (non-consuming)
     Flash::all()                   - read without clearing
     Flash::peek()                  - alias for all()
     Flash::clear()                 - wipe pending messages
     Flash::render(bool $consume)   - render standard HTML markup

   USAGE
     // Function style (still works, no change needed):
     flash('success', 'Saved.');
     foreach (flash_get() as $f) { echo $f['message']; }

     // Class style (equivalent + extras):
     Flash::success('Saved.');
     if (Flash::has()) echo Flash::count() . ' messages';

     // In header.php — replace the 8-line foreach block:
     <?= Flash::render() ?>

   SAFETY
     - Direct access refused with 403
     - Safe to require_once multiple times
     - Class only defined if not already present
     - Messages escaped by default (XSS safe)
     - Raw HTML only via explicit Flash::html()
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
if (!class_exists('Flash', false)) {

    final class Flash
    {
        /** Supported flash types. Anything else is normalized to 'info'. */
        public const VALID_TYPES = ['success', 'danger', 'warning', 'info'];

        /** Internal marker: this message contains trusted HTML. */
        private const RAW_KEY = '_raw';


        /* ====================================================
           3.1 FUNCTION-STYLE ALIASES
           ==================================================== */

        /**
         * Push a flash message (equivalent to base flash()).
         */
        public static function push(string $type, string $message): void
        {
            flash($type, $message);
        }

        /**
         * Read and clear all pending messages (equivalent to flash_get()).
         */
        public static function pull(): array
        {
            return flash_get();
        }


        /* ====================================================
           3.2 CONVENIENCE METHODS
           ==================================================== */

        public static function success(string $message): void
        {
            flash('success', $message);
        }

        public static function danger(string $message): void
        {
            flash('danger', $message);
        }

        public static function warning(string $message): void
        {
            flash('warning', $message);
        }

        public static function info(string $message): void
        {
            flash('info', $message);
        }

        /** Alias for danger() — often the "error" name is more natural. */
        public static function error(string $message): void
        {
            flash('danger', $message);
        }


        /* ====================================================
           3.3 TRUSTED-HTML MESSAGES
           ====================================================
           By default, messages are escaped (safe against XSS).
           Use Flash::html() when the message intentionally contains
           a trusted subset of HTML like <strong> or <code>.

           IMPORTANT: The caller is responsible for escaping any
           user-controlled content INSIDE the HTML, e.g.:

             Flash::html('success',
                 'User <strong>' . e($user['full_name']) . '</strong> deactivated.'
             );
           ==================================================== */

        public static function html(string $type, string $htmlMessage): void
        {
            flash($type, $htmlMessage);

            // Mark the last message as raw HTML
            $idx = count($_SESSION['flash'] ?? []) - 1;
            if ($idx >= 0) {
                $_SESSION['flash'][$idx][self::RAW_KEY] = true;
            }
        }


        /* ====================================================
           3.4 INSPECTION (non-consuming)
           ==================================================== */

        /**
         * All pending messages (does not clear).
         */
        public static function all(): array
        {
            return $_SESSION['flash'] ?? [];
        }

        /** Alias for all() — reads better in conditionals. */
        public static function peek(): array
        {
            return self::all();
        }

        /**
         * Are there any pending messages?
         */
        public static function has(): bool
        {
            return !empty($_SESSION['flash']);
        }

        /**
         * How many messages are pending?
         */
        public static function count(): int
        {
            return count($_SESSION['flash'] ?? []);
        }


        /* ====================================================
           3.5 CLEAR
           ==================================================== */

        /**
         * Discard all pending flash messages without rendering.
         * Useful in tests or when redirecting from an error page.
         */
        public static function clear(): void
        {
            unset($_SESSION['flash']);
        }


        /* ====================================================
           3.6 RENDER
           ==================================================== */

        /**
         * Render all pending messages as standard HTML.
         *
         * Matches the markup pattern already used in header.php:
         *   <div class="alert alert-success" role="alert">
         *     <span>Message</span>
         *     <button class="alert-close" onclick="...">&times;</button>
         *   </div>
         *
         * @param bool $consume  If true (default), messages are cleared
         *                       after rendering. If false, they remain
         *                       pending for a later render (useful for
         *                       printing or logging).
         * @return string  Ready-to-echo HTML, or '' if no messages
         */
        public static function render(bool $consume = true): string
        {
            $messages = $consume ? flash_get() : self::all();
            if (empty($messages)) return '';

            $html = '';

            foreach ($messages as $m) {
                // Normalize the type
                $type = (string)($m['type'] ?? 'info');
                if (!in_array($type, self::VALID_TYPES, true)) {
                    $type = 'info';
                }

                $rawMessage = (string)($m['message'] ?? '');
                $isRaw      = !empty($m[self::RAW_KEY]);

                // Escape by default, allow raw HTML when explicitly opted in
                $safeMessage = $isRaw
                    ? $rawMessage
                    : e($rawMessage);

                $html .= '<div class="alert alert-' . e($type) . '" role="alert" style="margin-top:20px;">'
                       .   '<span>' . $safeMessage . '</span>'
                       .   '<button type="button" class="alert-close" '
                       .       'onclick="this.parentElement.remove();" '
                       .       'aria-label="Dismiss" '
                       .       'style="background:none;border:none;font-size:20px;'
                       .       'cursor:pointer;color:inherit;line-height:1;padding:0;">'
                       .     '&times;'
                       .   '</button>'
                       . '</div>';
            }

            return $html;
        }
    }
}