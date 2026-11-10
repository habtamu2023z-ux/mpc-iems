<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

// 1. Security Check: Only Admin and HR can mark an employee as returned
if (!has_role(['admin', 'hr'])) {
    flash('danger', 'You do not have permission to perform this action.');
    redirect('leave_list.php');
}

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    flash('danger', 'Invalid leave request ID.');
    redirect('leave_list.php');
}

try {
    // 2. Verify the leave request exists and is currently approved
    $st = $pdo->prepare("
        SELECT sr.status, ld.return_confirmed 
        FROM service_requests sr 
        JOIN leave_details ld ON ld.request_id = sr.id 
        WHERE sr.id = ?
    ");
    $st->execute([$id]);
    $leave = $st->fetch();

    if (!$leave) {
        flash('danger', 'Leave request not found.');
    } elseif ($leave['status'] !== 'approved') {
        flash('warning', 'Only approved leaves can be marked as returned.');
    } elseif ($leave['return_confirmed'] == 1) {
        flash('info', 'This employee has already been marked as returned.');
    } else {
        // 3. Update the database
        $update = $pdo->prepare("
            UPDATE leave_details 
            SET return_confirmed = 1, return_date = CURDATE() 
            WHERE request_id = ?
        ");
        $update->execute([$id]);

        // 4. Log the action for auditing
        log_audit('UPDATE', 'leave_details', $id, ['return_confirmed' => 0], ['return_confirmed' => 1]);

        flash('success', 'Employee successfully marked as returned from leave.');
    }

} catch (PDOException $e) {
    flash('danger', 'Database Error: ' . $e->getMessage());
}

// 5. Redirect back to the list
redirect('leave_list.php');