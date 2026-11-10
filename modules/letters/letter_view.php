<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   LETTER VIEW & FORWARD
   ============================================================
   PURPOSE
     View letter details, document preview, forwarding, and
     movement history.

   FEATURES
     - Permission scoping (Admin/Registrar, Sender, or Recipient)
     - Safe forwarding (transaction + CSRF + validation)
     - Sends notification to the new recipient
     - Clean paper-like preview of the letter
     - Transfer history timeline
     - Quick access to Print/PDF and Archive

   DEPENDENCIES
     functions.php            — base helpers, format_date
     includes/auth_check.php  — Auth::require
     includes/csrf.php        — Csrf::check, Csrf::field
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
   2. AUTH & FETCH LETTER
   ------------------------------------------------------------ */
$user = Auth::require();
$id   = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    Flash::danger('Invalid letter ID.');
    redirect('letters.php');
}

$st = $pdo->prepare("
    SELECT l.*, 
           s.full_name AS sender_name, s.position AS sender_position, 
           d.name AS sender_dept,
           r.full_name AS current_recipient_name
    FROM letters l 
    JOIN users s ON s.id = l.sender_id 
    LEFT JOIN departments d ON d.id = s.department_id 
    LEFT JOIN users r ON r.id = l.recipient_user_id 
    WHERE l.id = ? AND l.deleted_at IS NULL
    LIMIT 1
");
$st->execute([$id]);
$letter = $st->fetch();

if (!$letter) {
    Flash::danger('Letter not found or has been deleted.');
    redirect('letters.php');
}


/* ------------------------------------------------------------
   3. PERMISSION CHECK
   ------------------------------------------------------------ */
$isAdminOrRegistrar = has_role(['admin', 'registrar']);
$isSender           = ((int)$letter['sender_id'] === (int)$user['id']);
$isRecipient        = ((int)$letter['recipient_user_id'] === (int)$user['id']);

if (!$isAdminOrRegistrar && !$isSender && !$isRecipient) {
    Flash::danger('You do not have permission to view this letter.');
    redirect('letters.php');
}


/* ------------------------------------------------------------
   4. HANDLE FORWARDING
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forward'])) {
    
    Csrf::check();
    
    $to_user = (int)($_POST['to_user'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    
    if ($to_user <= 0) {
        Flash::danger('Please select a valid staff member.');
        redirect("letter_view.php?id=$id");
    }

    // Verify target user
    $st = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL");
    $st->execute([$to_user]);
    $targetUser = $st->fetch();
    
    if (!$targetUser) {
        Flash::danger('The selected staff member is not active or does not exist.');
        redirect("letter_view.php?id=$id");
    }

    try {
        $pdo->beginTransaction();

        // 1. Log the transfer
        $pdo->prepare("
            INSERT INTO letter_transfers (letter_id, from_user, to_user, action, comment) 
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$id, (int)$user['id'], $to_user, 'forwarded', $comment]);
        
        // 2. Update the letter's current recipient
        $pdo->prepare("UPDATE letters SET recipient_user_id = ? WHERE id = ?")
            ->execute([$to_user, $id]);
        
        $pdo->commit();

        // 3. Audit & Notify
        Audit::forwarded('letters', $id, ['to_user' => $to_user, 'comment' => $comment]);
        
        Notifier::info(
            $to_user, 
            'Letter forwarded to you', 
            $letter['ref_no'] . ' - ' . $letter['subject'], 
            APP_URL . '/modules/letters/letter_view.php?id=' . $id
        );

        Flash::success('Letter successfully forwarded to <strong>' . e($targetUser['full_name']) . '</strong>.');
        redirect("letter_view.php?id=$id");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('letter_view.php forward failed: ' . $e->getMessage());
        Flash::danger('Database Error: Could not forward the letter.');
        redirect("letter_view.php?id=$id");
    }
}


/* ------------------------------------------------------------
   5. FETCH TRANSFER HISTORY & STAFF LIST
   ------------------------------------------------------------ */
$stHistory = $pdo->prepare("
    SELECT t.*, f.full_name AS from_name, r.full_name AS to_name 
    FROM letter_transfers t 
    LEFT JOIN users f ON f.id = t.from_user 
    LEFT JOIN users r ON r.id = t.to_user 
    WHERE t.letter_id = ? 
    ORDER BY t.created_at DESC
");
$stHistory->execute([$id]);
$history = $stHistory->fetchAll();

// Fetch active users for the forward dropdown (excluding yourself)
$stUsers = $pdo->prepare("
    SELECT id, full_name, position 
    FROM users 
    WHERE status = 'active' AND deleted_at IS NULL AND id != ? 
    ORDER BY full_name
");
$stUsers->execute([$user['id']]);
$staff_members = $stUsers->fetchAll();


/* ------------------------------------------------------------
   6. DISPLAY LOGIC (Dates & Settings)
   ------------------------------------------------------------ */
// Use sent_at if available, otherwise fall back to created_at
$displayDate = $letter['sent_at'] ?: $letter['created_at'];
$orgNameAm   = get_setting('org_name_am', 'ምስራቅ ፖሊ ቴክኒክ ኮሌጅ');
$orgNameEn   = get_setting('org_name_en', 'MISRAK POLYTECHNIC COLLEGE');


/* ------------------------------------------------------------
   7. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'View Letter';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">
    
    <!-- ============================================================
         TOP BAR
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Letter Details</h1>
            <p class="muted" style="margin-top: 5px;">Ref: <code><?= e($letter['ref_no']) ?></code></p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="letter_print.php?id=<?= $id ?>" target="_blank" class="btn btn-primary">
                🖨️ Print / PDF
            </a>
            <a href="letters.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
                &larr; Back to Directory
            </a>
        </div>
    </div>

    <!-- ============================================================
         MAIN LAYOUT (2/3 Preview + 1/3 Sidebar)
         ============================================================ -->
    <div class="grid-2" style="grid-template-columns: 3fr 2fr; align-items: start;">
        
        <!-- ============================================================
             LEFT COLUMN: Document Preview
             ============================================================ -->
        <div class="card" style="padding: 0; border: 1px solid #ddd; background: #f0f2f5; overflow: hidden; margin-bottom: 20px;">
            
            <div style="padding: 12px 20px; background: #fff; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                <strong style="color: var(--mpc-navy); font-size: 14px;">Preview</strong>
                <span class="badge <?= $letter['recipient_type'] === 'internal' ? 'badge-info' : 'badge-warning' ?>">
                    <?= e(ucwords(str_replace('_', ' ', $letter['letter_type']))) ?>
                </span>
            </div>
            
            <!-- Paper simulation -->
            <div style="padding: 40px; background: #fff; font-family: 'Times New Roman', Times, serif; min-height: 600px; margin: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                
                <!-- Letterhead -->
                <div style="text-align: center; border-bottom: 2px solid var(--mpc-navy); padding-bottom: 15px; margin-bottom: 30px;">
                    <h3 style="margin: 0; color: var(--mpc-navy); font-size: 22px;"><?= e($orgNameAm) ?></h3>
                    <h4 style="margin: 4px 0 0; color: var(--mpc-blue); font-size: 15px; text-transform: uppercase;"><?= e($orgNameEn) ?></h4>
                </div>

                <!-- Meta -->
                <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 30px; color: #333;">
                    <div><strong>Doc No:</strong> <?= e($letter['doc_no']) ?></div>
                    <div><strong>Date:</strong> <?= format_date($displayDate) ?></div>
                </div>

                <!-- Recipient -->
                <?php if($letter['recipient_am'] || $letter['recipient_name']): ?>
                <div style="font-size: 15px; margin-bottom: 25px; line-height: 1.6; color: #333;">
                    <strong>ለ / To:</strong><br>
                    <?= e($letter['recipient_am']) ?><br>
                    <?= e($letter['recipient_name']) ?>
                </div>
                <?php endif; ?>

                <!-- Subject -->
                <div style="font-size: 15px; font-weight: bold; text-decoration: underline; margin-bottom: 25px; line-height: 1.6; color: #000;">
                    <?= e($letter['subject_am']) ?><br>
                    <?= e($letter['subject']) ?>
                </div>

                <!-- Body -->
                <div style="font-size: 15px; line-height: 1.8; white-space: pre-wrap; text-align: justify; margin-bottom: 40px; color: #333;">
                    <?= e($letter['body']) ?>
                </div>

                <!-- Signature -->
                <div style="font-size: 15px; margin-top: 60px; color: #333;">
                    <p>Sincerely,</p>
                    <br><br>
                    <p>
                        <strong><?= e($letter['sender_name']) ?></strong><br>
                        <?= e($letter['sender_position']) ?><br>
                        <?= e($letter['sender_dept']) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- ============================================================
             RIGHT COLUMN: Metadata & Actions
             ============================================================ -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            
            <!-- Metadata -->
            <div class="card">
                <h3 style="margin-top: 0; font-size: 16px; color: var(--mpc-navy); border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    Information
                </h3>
                <ul class="kv" style="font-size: 13px;">
                    <li><span>Sender</span><b><?= e($letter['sender_name']) ?></b></li>
                    <li><span>Department</span><b><?= e($letter['sender_dept'] ?: 'N/A') ?></b></li>
                    <li><span>Status</span><b><?= status_badge($letter['status']) ?></b></li>
                    <li><span>Memo No</span><b><?= e($letter['memo_no'] ?: '—') ?></b></li>
                    <?php if ($letter['current_recipient_name']): ?>
                        <li><span>Currently With</span><b style="color: var(--mpc-blue);"><?= e($letter['current_recipient_name']) ?></b></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Forward Form -->
            <?php if ($letter['status'] !== 'archived'): ?>
            <div class="card" style="border-left-color: var(--mpc-flame);">
                <h3 style="margin-top: 0; font-size: 16px; color: var(--mpc-navy);">Forward Letter</h3>
                <p class="muted small" style="margin-bottom: 15px; font-size: 12px;">
                    Send this letter to another staff member for their review or action.
                </p>
                
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="forward" value="1">
                    
                    <div class="form-group">
                        <label for="to_user">Select Staff Member</label>
                        <select name="to_user" id="to_user" required>
                            <option value="">-- Choose --</option>
                            <?php foreach($staff_members as $staff): ?>
                                <option value="<?= $staff['id'] ?>">
                                    <?= e($staff['full_name']) ?> (<?= e($staff['position']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="comment">Comment (Optional)</label>
                        <input type="text" name="comment" id="comment" placeholder="e.g. Please review and sign.">
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        ➡️ Forward Letter
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Transfer History -->
            <div class="card">
                <h3 style="margin-top: 0; font-size: 16px; color: var(--mpc-navy); border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    Movement History
                </h3>
                
                <?php if(empty($history)): ?>
                    <p class="muted small" style="font-size: 13px;">No movement history available.</p>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 16px; margin-top: 15px;">
                        <?php foreach($history as $h): ?>
                            <div style="border-left: 3px solid var(--mpc-blue); padding-left: 12px; font-size: 13px; position: relative;">
                                <!-- Timeline Dot -->
                                <div style="position: absolute; left: -6px; top: 3px; width: 9px; height: 9px; border-radius: 50%; background: var(--mpc-blue); border: 2px solid #fff;"></div>
                                
                                <strong style="color: var(--mpc-navy);"><?= e(ucfirst($h['action'])) ?></strong>
                                <?php if($h['to_name']): ?> to <strong><?= e($h['to_name']) ?></strong><?php endif; ?>
                                
                                <div class="muted" style="font-size: 11px; margin-top: 3px;">
                                    by <?= e($h['from_name']) ?> · <?= format_date($h['created_at'], true) ?>
                                </div>
                                
                                <?php if($h['comment']): ?>
                                    <div style="font-style: italic; color: #4b5563; margin-top: 6px; background: #f9f9f9; padding: 6px 10px; border-radius: 4px;">
                                        "<?= e($h['comment']) ?>"
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>