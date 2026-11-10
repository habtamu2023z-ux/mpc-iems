<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   LETTER ARCHIVE CONTROLLER
   ============================================================
   PURPOSE
     Handle the POST request to archive a letter. Keeps state
     mutations isolated and secure (no GET state changes).

   FEATURES
     - Enforces POST method (prevents CSRF via image tags/links)
     - CSRF protection
     - Role scoping (Admin / Registrar only)
     - Transaction wraps UPDATE (letters) + INSERT (transfers)
     - No-op detection (already archived)
     - Logs to audit trail via Audit::archived()
     - Redirects back to the directory

   USAGE (in letters.php)
     <form method="post" action="letter_archive.php" style="display:inline;"
           onsubmit="return confirm('Archive this letter?');">
         <?= Csrf::field() ?>
         <input type="hidden" name="letter_id" value="<?= $l['id'] ?>">
         <button type="submit" class="btn btn-sm ...">📁 Archive</button>
     </form>

   DEPENDENCIES
     functions.php            — base helpers
     includes/auth_check.php  — Auth::require, Auth::requireRole
     includes/csrf.php        — Csrf::check
     includes/flash.php       — Flash::success, danger, warning
     includes/audit.php       — Audit::archived
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/audit.php';


/* ------------------------------------------------------------
   2. AUTH & METHOD CHECK
   ------------------------------------------------------------ */
$user = Auth::require();

// Only Admin and Registrar can archive letters
Auth::requireRole(['admin', 'registrar'], 'You do not have permission to archive letters.');

// Enforce POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Flash::danger('Invalid request method. Archive actions require a POST request.');
    redirect('letters.php');
}

// Validate CSRF token
Csrf::check();


/* ------------------------------------------------------------
   3. COLLECT INPUTS
   ------------------------------------------------------------ */
$letter_id = (int)($_POST['letter_id'] ?? 0);

if ($letter_id <= 0) {
    Flash::danger('Invalid letter ID.');
    redirect('letters.php');
}


/* ------------------------------------------------------------
   4. FETCH & VERIFY LETTER
   ------------------------------------------------------------ */
$st = $pdo->prepare("
    SELECT id, ref_no, status 
    FROM letters 
    WHERE id = ? AND deleted_at IS NULL
    LIMIT 1
");
$st->execute([$letter_id]);
$letter = $st->fetch();

if (!$letter) {
    Flash::danger('Letter not found or has been deleted.');
    redirect('letters.php');
}

// Guard: Already archived?
if ($letter['status'] === 'archived') {
    Flash::warning('This letter is already archived.');
    redirect('letters.php');
}


/* ------------------------------------------------------------
   5. EXECUTE ARCHIVE
   ------------------------------------------------------------ */
try {
    $pdo->beginTransaction();

    // 1. Update Letter Status
    $pdo->prepare("
        UPDATE letters 
        SET status = 'archived', archived_at = NOW() 
        WHERE id = ?
    ")->execute([$letter_id]);

    // 2. Log Transfer
    $pdo->prepare("
        INSERT INTO letter_transfers (letter_id, from_user, action, comment) 
        VALUES (?, ?, 'archived', 'Moved to archives')
    ")->execute([$letter_id, (int)$user['id']]);

    $pdo->commit();

    // 3. Audit
    Audit::archived('letters', $letter_id, ['ref_no' => $letter['ref_no']]);

    Flash::success('Letter <strong>' . e($letter['ref_no']) . '</strong> has been moved to the archives.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('letter_archive.php failed: ' . $e->getMessage());
    Flash::danger('Database Error: Could not archive the letter. Please try again.');
}


/* ------------------------------------------------------------
   6. REDIRECT
   ------------------------------------------------------------ */
redirect('letters.php');