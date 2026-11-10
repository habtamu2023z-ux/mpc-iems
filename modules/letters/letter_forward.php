<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   LETTER FORWARD CONTROLLER
   ============================================================
   PURPOSE
     Handle the POST request to forward a letter to another
     staff member. Keeps state mutations isolated from the view.

   FEATURES
     - Enforces POST method (prevents GET state changes)
     - CSRF protection
     - Permission scoping (Sender, Recipient, or Admin/Registrar)
     - Verifies target user is active
     - Transaction wraps UPDATE (letters) + INSERT (transfers)
     - Sends notification to the new recipient
     - Audits the action
     - Redirects back to the letter view

   USAGE
     <form method="post" action="letter_forward.php">
         <?= Csrf::field() ?>
         <input type="hidden" name="letter_id" value="...">
         ... to_user select ...
         ... comment input ...
         <button type="submit">Forward</button>
     </form>

   DEPENDENCIES
     functions.php            — base helpers
     includes/auth_check.php  — Auth::require
     includes/csrf.php        — Csrf::check
     includes/flash.php       — Flash::success, danger
     includes/audit.php       — Audit::forwarded
     includes/notification.php — Notifier::info
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/notification.php';


/* ------------------------------------------------------------
   2. AUTH & METHOD CHECK
   ------------------------------------------------------------ */
$user = Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Flash::danger('Invalid request method.');
    redirect('letters.php');
}

Csrf::check();


/* ------------------------------------------------------------
   3. COLLECT INPUTS
   ------------------------------------------------------------ */
$letter_id = (int)($_POST['letter_id'] ?? 0);
$to_user   = (int)($_POST['to_user'] ?? 0);
$comment   = trim((string)($_POST['comment'] ?? ''));

if ($letter_id <= 0) {
    Flash::danger('Invalid letter ID.');
    redirect('letters.php');
}

if ($to_user <= 0) {
    Flash::danger('Please select a valid staff member to forward to.');
    redirect("letter_view.php?id=$letter_id");
}


/* ------------------------------------------------------------
   4. FETCH & VERIFY LETTER
   ------------------------------------------------------------ */
$st = $pdo->prepare("
    SELECT id, ref_no, sender_id, recipient_user_id, status 
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

// Guard: Cannot forward archived letters
if ($letter['status'] === 'archived') {
    Flash::warning('Archived letters cannot be forwarded.');
    redirect("letter_view.php?id=$letter_id");
}

// Guard: Permission check
$isAdminOrRegistrar = has_role(['admin', 'registrar']);
$isSender           = ((int)$letter['sender_id'] === (int)$user['id']);
$isRecipient        = ((int)$letter['recipient_user_id'] === (int)$user['id']);

if (!$isAdminOrRegistrar && !$isSender && !$isRecipient) {
    Flash::danger('You do not have permission to forward this letter.');
    redirect('letters.php');
}


/* ------------------------------------------------------------
   5. VERIFY TARGET USER
   ------------------------------------------------------------ */
$st = $pdo->prepare("
    SELECT id, full_name 
    FROM users 
    WHERE id = ? AND status = 'active' AND deleted_at IS NULL
    LIMIT 1
");
$st->execute([$to_user]);
$targetUser = $st->fetch();

if (!$targetUser) {
    Flash::danger('The selected staff member is not active or does not exist.');
    redirect("letter_view.php?id=$letter_id");
}

if ($to_user === (int)$user['id']) {
    Flash::warning('You cannot forward a letter to yourself.');
    redirect("letter_view.php?id=$letter_id");
}


/* ------------------------------------------------------------
   6. EXECUTE TRANSFER
   ------------------------------------------------------------ */
try {
    $pdo->beginTransaction();

    // 1. Log the transfer
    $pdo->prepare("
        INSERT INTO letter_transfers (letter_id, from_user, to_user, action, comment) 
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $letter_id, 
        (int)$user['id'], 
        $to_user, 
        'forwarded', 
        $comment ?: 'Forwarded via IEMS'
    ]);

    // 2. Update the letter's current recipient
    $pdo->prepare("UPDATE letters SET recipient_user_id = ? WHERE id = ?")
        ->execute([$to_user, $letter_id]);

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('letter_forward.php failed: ' . $e->getMessage());
    Flash::danger('Database Error: Could not forward the letter. Please try again.');
    redirect("letter_view.php?id=$letter_id");
}


/* ------------------------------------------------------------
   7. AUDIT & NOTIFY
   ------------------------------------------------------------ */
// Audit
Audit::forwarded('letters', $letter_id, [
    'to_user_id' => $to_user,
    'to_user_name' => $targetUser['full_name'],
    'comment' => $comment
]);

// Notify the recipient
Notifier::info(
    $to_user,
    'Letter forwarded to you',
    $letter['ref_no'] . ' - by ' . Auth::fullName(),
    APP_URL . '/modules/letters/letter_view.php?id=' . $letter_id
);

Flash::success('Letter successfully forwarded to <strong>' . e($targetUser['full_name']) . '</strong>.');
redirect("letter_view.php?id=$letter_id");