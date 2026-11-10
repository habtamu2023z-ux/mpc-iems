<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   COMPOSE OFFICIAL LETTER
   ============================================================
   PURPOSE
     Form to create a new internal memo or external letter.

   FEATURES
     - Auto-fills Document No based on letter type (internal vs external)
     - Required fields validated client-side and server-side
     - Saves as 'draft' or 'sent'
     - Wraps DB inserts in a transaction
     - Auto-generates a unique Reference Number via Ref::letter()
     - Records audit trail and initial transfer history

   DEPENDENCIES
     functions.php            — base helpers
     includes/auth_check.php  — Auth::require
     includes/csrf.php        — Csrf::check, Csrf::field
     includes/flash.php       — Flash::danger
     includes/audit.php       — Audit::created
     includes/ref_generator.php — Ref::letter
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/ref_generator.php';


/* ------------------------------------------------------------
   2. AUTH GATE
   ------------------------------------------------------------ */
$user = Auth::require();

// Restrict access: Students cannot compose general letters
if (Auth::isStudent()) {
    Flash::warning('Students do not have permission to compose official letters.');
    redirect(APP_URL . '/index.php');
}


/* ------------------------------------------------------------
   3. HANDLE POST
   ------------------------------------------------------------ */
$errors = [];

// Default values for form re-population
$form = [
    'letter_type'    => $_POST['letter_type']    ?? 'external_letter',
    'doc_no'         => $_POST['doc_no']         ?? get_setting('doc_no_external', 'OF/MPTC/058'),
    'memo_no'        => $_POST['memo_no']        ?? '',
    'recipient_type' => $_POST['recipient_type'] ?? 'external',
    'recipient_name' => $_POST['recipient_name'] ?? '',
    'recipient_am'   => $_POST['recipient_am']   ?? '',
    'recipient_org'  => $_POST['recipient_org']  ?? '',
    'subject'        => $_POST['subject']        ?? '',
    'subject_am'     => $_POST['subject_am']     ?? '',
    'body'           => $_POST['body']           ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    Csrf::check();

    // 1. Collect Data
    $letter_type    = (string)$form['letter_type'];
    $doc_no         = trim((string)$form['doc_no']);
    $memo_no        = trim((string)$form['memo_no']);
    $recipient_type = (string)$form['recipient_type'];
    
    $subject        = trim((string)$form['subject']);
    $subject_am     = trim((string)$form['subject_am']);
    $recipient_name = trim((string)$form['recipient_name']);
    $recipient_am   = trim((string)$form['recipient_am']);
    $recipient_org  = trim((string)$form['recipient_org']);
    
    $body           = trim((string)$form['body']);
    
    $action         = (string)($_POST['action'] ?? 'sent');
    $status         = ($action === 'draft') ? 'draft' : 'sent';

    // 2. Validation
    $valid_types = ['internal_memo', 'external_letter'];
    if (!in_array($letter_type, $valid_types, true)) {
        $errors[] = 'Invalid letter type selected.';
    }

    $valid_recipients = ['internal', 'external'];
    if (!in_array($recipient_type, $valid_recipients, true)) {
        $errors[] = 'Invalid recipient type selected.';
    }

    if ($doc_no === '') {
        $errors[] = 'Document No. is required.';
    }
    if ($subject === '') {
        $errors[] = 'Subject (English) is required.';
    }
    if ($body === '') {
        $errors[] = 'Letter Body is required.';
    }

    // 3. Persist
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Generate Ref
            $ref_no = Ref::letter();

            // Insert Letter
            $ins = $pdo->prepare("
                INSERT INTO letters 
                (ref_no, sender_id, letter_type, subject, subject_am, body, 
                 recipient_name, recipient_am, recipient_org, recipient_type, 
                 doc_no, memo_no, status, sent_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $sent_at = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
            
            $ins->execute([
                $ref_no, (int)$user['id'], $letter_type, 
                $subject, $subject_am ?: null, $body,
                $recipient_name ?: null, $recipient_am ?: null, $recipient_org ?: null, $recipient_type,
                $doc_no, $memo_no ?: null, $status, $sent_at
            ]);
            
            $letter_id = (int)$pdo->lastInsertId();
            
            // Log Transfer
            $pdo->prepare("
                INSERT INTO letter_transfers (letter_id, from_user, action, comment) 
                VALUES (?, ?, ?, ?)
            ")->execute([
                $letter_id, (int)$user['id'], ($status === 'sent' ? 'sent' : 'drafted'), 'Letter composed via IEMS'
            ]);
            
            $pdo->commit();

            // Audit (after commit)
            Audit::created('letters', $letter_id, [
                'ref_no'      => $ref_no,
                'type'        => $letter_type,
                'status'      => $status,
                'recipient'   => $recipient_name ?: $recipient_org
            ]);

            Flash::success("Letter <strong>" . e($ref_no) . "</strong> successfully saved.");
            
            // Redirect
            if ($status === 'sent') {
                redirect('letter_print.php?id=' . $letter_id);
            } else {
                redirect('letter_view.php?id=' . $letter_id);
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('letter_new.php failed: ' . $e->getMessage());
            Flash::danger('Database Error: Could not save the letter. Please contact IT.');
        }
    } else {
        foreach (array_slice($errors, 0, 4) as $err) {
            Flash::danger($err);
        }
    }
}


/* ------------------------------------------------------------
   4. FETCH DEFAULTS & RENDER
   ------------------------------------------------------------ */
$default_doc_ext = get_setting('doc_no_external', 'OF/MPTC/058');
$default_doc_int = get_setting('doc_no_internal', 'OF/MPTC/072');

$pageTitle = 'Compose Letter';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 900px; margin: 30px auto; padding: 0 20px;">
    
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Compose Official Letter</h1>
            <p class="muted" style="margin-top: 5px;">ይህንን ቅጽ በመሙላት አዲስ ደብዳቤ ያዘጋጁ።</p>
        </div>
        <a href="letters.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to Letters
        </a>
    </div>

    <div class="card" style="padding: 30px;">
        <form method="post" class="stack" autocomplete="off">
            <?= Csrf::field() ?>
            
            <!-- ============================================================
                 SECTION 1: Letter Type & Numbers
                 ============================================================ -->
            <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #eee; margin-bottom: 25px;">
                <h3 style="margin-top: 0; font-size: 16px; color: var(--mpc-navy); border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 15px;">
                    Letter Details (የደብዳቤው ዝርዝር)
                </h3>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="letter_type">Letter Type (የደብዳቤ አይነት) *</label>
                        <select name="letter_type" id="letter_type" required style="font-weight: bold; color: var(--mpc-blue);"
                                onchange="updateDocNumber(this.value)">
                            <option value="internal_memo" <?= $form['letter_type'] === 'internal_memo' ? 'selected' : '' ?>>Internal Memo (የውስጥ ማስታወሻ)</option>
                            <option value="external_letter" <?= $form['letter_type'] === 'external_letter' ? 'selected' : '' ?>>External Letter (ውጪ ደብዳቤ)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="doc_no">Document No. (የሰነድ ቁጥር) *</label>
                        <input type="text" name="doc_no" id="doc_no" 
                               value="<?= e($form['doc_no']) ?>" required 
                               placeholder="e.g. OF/MPTC/058">
                    </div>

                    <div class="form-group">
                        <label for="memo_no">Memo No. (ዕትም ቁጥር)</label>
                        <input type="text" name="memo_no" id="memo_no" 
                               value="<?= e($form['memo_no']) ?>" 
                               placeholder="e.g. 123">
                    </div>
                </div>
            </div>

            <!-- ============================================================
                 SECTION 2: Recipient
                 ============================================================ -->
            <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px;">
                Recipient (ተቀባይ)
            </h3>

            <div class="grid-2">
                <div class="form-group">
                    <label for="recipient_name">Recipient Name (English)</label>
                    <input type="text" name="recipient_name" id="recipient_name" 
                           value="<?= e($form['recipient_name']) ?>" 
                           placeholder="e.g. To Whom It May Concern">
                </div>
                <div class="form-group">
                    <label for="recipient_am">Recipient Name (Amharic - ለ)</label>
                    <input type="text" name="recipient_am" id="recipient_am" 
                           value="<?= e($form['recipient_am']) ?>" 
                           placeholder="ለምሥራቅ ፖሊቴክኒክ ኮሌጅ የሰልጣኝ ልማት ዳይሬክቶሬት"
                           style="font-family: 'Noto Sans Ethiopic', sans-serif;">
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="recipient_org">Recipient Organization (Optional)</label>
                    <input type="text" name="recipient_org" id="recipient_org" 
                           value="<?= e($form['recipient_org']) ?>" 
                           placeholder="e.g. Federal TVET Agency">
                </div>
                <div class="form-group">
                    <label for="recipient_type">Recipient Type</label>
                    <select name="recipient_type" id="recipient_type">
                        <option value="internal" <?= $form['recipient_type'] === 'internal' ? 'selected' : '' ?>>Internal (Staff/Department)</option>
                        <option value="external" <?= $form['recipient_type'] === 'external' ? 'selected' : '' ?>>External (Gov/NGO/Private)</option>
                    </select>
                </div>
            </div>

            <!-- ============================================================
                 SECTION 3: Subject & Body
                 ============================================================ -->
            <h3 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px;">
                Content (ይዘት)
            </h3>

            <div class="grid-2">
                <div class="form-group">
                    <label for="subject">Subject (English) *</label>
                    <input type="text" name="subject" id="subject" required 
                           value="<?= e($form['subject']) ?>" 
                           placeholder="Subject in English...">
                </div>
                <div class="form-group">
                    <label for="subject_am">Subject (Amharic - ጉዳዩ)</label>
                    <input type="text" name="subject_am" id="subject_am" 
                           value="<?= e($form['subject_am']) ?>" 
                           placeholder="ጉዳዩ፡- የቤተመጽሐፍት አገልግሎት ክፍል ሪፖርትን መለክ ይመለከታል፡፡"
                           style="font-family: 'Noto Sans Ethiopic', sans-serif;">
                </div>
            </div>

            <div class="form-group">
                <label for="body">Letter Body (የደብዳቤው ይዘት) *</label>
                <textarea name="body" id="body" rows="12" required 
                          placeholder="Write your letter here..." 
                          style="font-size: 15px; line-height: 1.8;"><?= e($form['body']) ?></textarea>
            </div>

            <!-- ============================================================
                 ACTIONS
                 ============================================================ -->
            <div style="display: flex; gap: 15px; margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px; flex-wrap: wrap;">
                <button type="submit" name="action" value="sent" class="btn btn-primary btn-lg" style="flex: 2;">
                    ✉️ Save &amp; Print Letter (አስቀምጥ እና አትም)
                </button>
                <button type="submit" name="action" value="draft" class="btn btn-ghost btn-lg" style="flex: 1; border-color: #ccc; color: #333;">
                    💾 Save as Draft (ረቂቅ)
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ============================================================
     JS: Auto-swap Document No based on Letter Type
     ============================================================ -->
<script>
    function updateDocNumber(type) {
        var docInput = document.getElementById('doc_no');
        if (!docInput || docInput.value.trim() !== '') {
            // If user has already typed something custom, don't overwrite it
            // unless it matches the exact default of the *other* type
            var otherDefault = (type === 'internal_memo') ? '<?= e($default_doc_ext) ?>' : '<?= e($default_doc_int) ?>';
            if (docInput.value.trim() !== otherDefault) {
                return;
            }
        }
        
        if (type === 'internal_memo') {
            docInput.value = '<?= e($default_doc_int) ?>';
        } else {
            docInput.value = '<?= e($default_doc_ext) ?>';
        }
    }
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>