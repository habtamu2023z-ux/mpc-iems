<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   LETTER PRINT / PDF GENERATOR
   ============================================================
   PURPOSE
     Renders a print-ready (or PDF-ready) view of a letter using
     the exact A4 templates designed in PdfExport.

   FEATURES
     - Role-based access control (Sender, Recipient, Admin)
     - Uses dynamic organization branding from Settings
     - Matches the exact Word templates for Internal/External
     - Handles both Internal Memos and External Letters
     - Auto-triggers the browser print dialog on load

   DEPENDENCIES
     functions.php            — base helpers
     includes/auth_check.php  — Auth::require
     includes/pdf_export.php  — PdfExport::letter, PdfExport::render
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/pdf_export.php';


/* ------------------------------------------------------------
   2. AUTH & FETCH LETTER
   ------------------------------------------------------------ */
$user = Auth::require();
$id   = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('Invalid letter ID.');
}

$st = $pdo->prepare("
    SELECT l.*, 
           s.full_name AS sender_name, 
           s.position AS sender_position, 
           d.name AS sender_dept
    FROM letters l 
    JOIN users s ON s.id = l.sender_id 
    LEFT JOIN departments d ON d.id = s.department_id 
    WHERE l.id = ? AND l.deleted_at IS NULL
    LIMIT 1
");
$st->execute([$id]);
$letter = $st->fetch();

if (!$letter) {
    die('Letter not found.');
}


/* ------------------------------------------------------------
   3. PERMISSION CHECK
   ------------------------------------------------------------ */
$isAdminOrRegistrar = has_role(['admin', 'registrar']);
$isSender           = ((int)$letter['sender_id'] === (int)$user['id']);
$isRecipient        = ((int)$letter['recipient_user_id'] === (int)$user['id']);

if (!$isAdminOrRegistrar && !$isSender && !$isRecipient) {
    die('You do not have permission to view this letter.');
}


/* ------------------------------------------------------------
   4. BUILD & RENDER
   ------------------------------------------------------------ */
// Determine the display date (prefer sent_at, fall back to created_at)
$displayDate = $letter['sent_at'] ?: $letter['created_at'];
$isInternal  = ($letter['letter_type'] === 'internal_memo');

// Build the A4 document using the exact template engine
$html = PdfExport::letter([
    // Recipient
    'to'             => $letter['recipient_name'],
    'to_am'          => $letter['recipient_am'],
    'to_org'         => $letter['recipient_org'],
    
    // Content
    'subject'        => $letter['subject'],
    'subject_am'     => $letter['subject_am'],
    'body'           => $letter['body'],
    
    // Meta
    'ref_no'         => $letter['ref_no'],
    'doc_no'         => $letter['doc_no'],
    'memo_no'        => $letter['memo_no'],
    'date'           => $displayDate,
    'is_internal'    => $isInternal,
    
    // Signer
    'signer_name'    => $letter['sender_name'],
    'signer_position'=> $letter['sender_position'],
    'signer_dept'    => $letter['sender_dept'],
]);

// Render the HTML inline with auto-print enabled
// The PdfExport::render() method automatically injects the onload="window.print()" 
// and serves the correct HTML headers.
PdfExport::render($html);