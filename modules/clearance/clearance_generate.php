<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

// 1. Security Check: Only Admin and Registrar can generate official letters
if (!has_role(['admin', 'registrar'])) {
    flash('danger', 'You do not have permission to generate clearance letters.');
    redirect('clearance.php');
}

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    flash('danger', 'Invalid clearance ID.');
    redirect('clearance.php');
}

// 2. Fetch Clearance to verify its status
$st = $pdo->prepare("SELECT status FROM clearance_requests WHERE id = ?");
$st->execute([$id]);
$status = $st->fetchColumn();

if (!$status) {
    flash('danger', 'Clearance record not found.');
    redirect('clearance.php');
}

if ($status !== 'approved') {
    flash('warning', 'This clearance is not fully approved yet. You cannot generate a letter.');
    redirect("clearance_view.php?id=$id");
}

// 3. Execute the Stored Procedure
try {
    // Call the Stored Procedure from your database
    $stmt = $pdo->prepare("CALL sp_generate_clearance_letter(?, ?, @letter_id)");
    $stmt->execute([$id, $user['id']]);
    
    // Fetch the OUT parameter (@letter_id)
    $result = $pdo->query("SELECT @letter_id AS letter_id")->fetch();
    $letter_id = $result['letter_id'] ?? 0;

    if ($letter_id > 0) {
        // Log the action in the audit trail
        log_audit('GENERATE', 'letters', $letter_id, [], ['clearance_id' => $id]);
        
        flash('success', 'Certificate letter generated successfully!');
        
        // Redirect straight to the printable letter page
        redirect("../letters/letter_print.php?id=" . $letter_id);
    } else {
        throw new Exception("The database did not return a valid letter ID.");
    }

} catch (PDOException $e) {
    // If the stored procedure fails (e.g., missing required fields)
    flash('danger', 'Database Error: ' . $e->getMessage());
    redirect("clearance_view.php?id=$id");
} catch (Exception $e) {
    flash('danger', 'System Error: ' . $e->getMessage());
    redirect("clearance_view.php?id=$id");
}