<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   LOGOUT
   ============================================================
   PURPOSE
     Ends the current session cleanly and redirects the user
     to the login page with a confirmation flash message.

   BEHAVIOR (all handled by Auth::logout())
     1. Capture the user ID for audit (before teardown)
     2. Clear $_SESSION
     3. Delete the session cookie (browser-side)
     4. Destroy the server-side session
     5. Start a fresh session (so flash() works)
     6. Log a LOGOUT entry in audit_log

   AFTER logout()
     - Flash::success() writes to the fresh session
     - redirect() sends the user to /login.php
     - login.php's Flash::render() shows the confirmation

   NOTES ON THE AUDIT ENTRY
     The inserted row in audit_log will have:
       action    = 'LOGOUT'
       entity    = 'users'
       entity_id = <the user who logged out>
       user_id   = NULL
       ip_address, user_agent = captured

     The user_id column is NULL because Auth::logout() destroys
     the session before logging — so current_user() returns null
     when log_audit() runs. This is standard for logout events:
     queries should use `entity_id` to find a user's logout
     history:
       SELECT * FROM audit_log WHERE action = 'LOGOUT' AND entity_id = 5

   SAFE TO CALL WHEN NOT LOGGED IN
     Auth::logout() handles the null-user case: it still clears
     any stale session and starts fresh, just without writing an
     audit entry. Calling logout.php when not logged in simply
     redirects to the login page with the standard confirmation
     flash — no errors, no warnings.
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/flash.php';


/* ------------------------------------------------------------
   2. END THE SESSION
   Auth::logout() handles:
     - Session data clear
     - Cookie deletion (browser-side)
     - Server-side session_destroy()
     - Fresh session start (for Flash)
     - LOGOUT audit entry (if a user was logged in)
   ------------------------------------------------------------ */
Auth::logout();


/* ------------------------------------------------------------
   3. FLASH CONFIRMATION + REDIRECT
   Flash::success() writes to the fresh session created by
   Auth::logout(). On the next request to /login.php, the
   flash is picked up by Flash::render() in login.php's body.
   ------------------------------------------------------------ */
Flash::success('በተሳካ ሁኔታ ወጥተዋል። (You have been securely logged out.)');

redirect(APP_URL . '/login.php');