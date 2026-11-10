<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

// Only Students, Admin, Registrar, and HR can create clearances
if (!has_role(['student', 'admin', 'registrar', 'hr', 'employee'])) {
    flash('danger', 'You do not have permission to create a clearance request.');
    redirect('clearance.php');
}

$is_staff_creator = has_role(['admin', 'registrar', 'hr']);

// ==========================================
// HANDLE FORM SUBMISSION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    
    // 1. Determine Applicant ID and Type securely
    if ($is_staff_creator) {
        $applicant_id = (int)$_POST['applicant_id'];
        // Verify the selected user actually exists
        $st = $pdo->prepare("SELECT employment_type FROM users WHERE id = ?");
        $st->execute([$applicant_id]);
        $emp_type = $st->fetchColumn();
        
        if (!$emp_type) {
            flash('danger', 'Invalid applicant selected.');
            redirect('clearance_new.php');
        }
        $applicant_type = ($emp_type === 'student') ? 'student' : 'employee';
    } else {
        // If self-requesting, force their own ID
        $applicant_id = $user['id'];
        $applicant_type = ($user['employment_type'] === 'student') ? 'student' : 'employee';
    }

    // 2. Collect other data
    $reason = $_POST['reason'];
    $destination = trim($_POST['destination']);
    $dest_type = $_POST['destination_type'];
    $notes = trim($_POST['notes']);

    // 3. Call the Stored Procedure
    try {
        $stmt = $pdo->prepare("CALL sp_create_clearance(?, ?, ?, ?, ?, ?, ?, @clr_id, @ref)");
        $stmt->execute([
            $applicant_id, $applicant_type, $reason, 
            $destination, $dest_type, $notes, $user['id']
        ]);
        
        // Fetch the OUT parameters
        $res = $pdo->query("SELECT @clr_id AS id, @ref AS ref")->fetch();
        
        // Log the action
        log_audit('CREATE', 'clearance_requests', $res['id'], [], ['ref' => $res['ref']]);

        flash('success', 'Clearance request successfully created! Ref: ' . $res['ref']);
        redirect('clearance_view.php?id=' . $res['id']);

    } catch (PDOException $e) {
        flash('danger', 'Database Error: ' . $e->getMessage());
        redirect('clearance_new.php');
    }
}

// Fetch users for the dropdown (Only if staff is creating it)
$users = [];
if ($is_staff_creator) {
    $users = $pdo->query("SELECT id, full_name, employment_type FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll();
}

$pageTitle = 'New Clearance';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 800px; margin: 30px auto;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">New Clearance (አዲስ ክሊራንስ)</h1>
            <p class="muted" style="margin-top: 5px;">Fill out the form below to initiate a clearance process.</p>
        </div>
        <a href="clearance.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to List
        </a>
    </div>

    <div class="card" style="padding: 30px;">
        <form method="post" class="stack">
            <?= csrf_field() ?>
            
            <!-- APPLICANT SELECTION (Only visible to staff) -->
            <?php if ($is_staff_creator): ?>
                <div class="form-group" style="background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px dashed #ccc;">
                    <label style="color: var(--mpc-navy); font-size: 14px; margin-bottom: 8px;">
                        Select Applicant (አመልካች ይምረጡ)
                    </label>
                    <select name="applicant_id" id="applicant_id" required style="border-color: #aaa;">
                        <option value="">-- Choose Student or Employee --</option>
                        <?php foreach ($users as $usr): ?>
                            <option value="<?= $usr['id'] ?>" data-type="<?= $usr['employment_type'] === 'student' ? 'student' : 'employee' ?>">
                                <?= e($usr['full_name']) ?> (<?= e(ucfirst($usr['employment_type'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <!-- If regular employee/student, show them their own info -->
                <div style="background: #E0F2FE; padding: 15px; border-radius: 8px; border-left: 4px solid var(--mpc-blue); margin-bottom: 20px;">
                    <strong style="color: var(--mpc-navy);">Applicant:</strong> <?= e($user['full_name']) ?><br>
                    <strong style="color: var(--mpc-navy);">Type:</strong> <?= e(ucfirst($user['employment_type'])) ?>
                </div>
            <?php endif; ?>

            <!-- CLEARANCE DETAILS -->
            <div class="grid-2">
                <div class="form-group">
                    <label>Reason (ምክንያት)</label>
                    <select name="reason" required>
                        <option value="graduation">Graduation (ምረቃ)</option>
                        <option value="class_completion">Class Completion (ክፍል ማጠናቀቅ)</option>
                        <option value="transfer_college">Transfer to College (ወደ ሌላ ኮሌጅ ማስተላለፍ)</option>
                        <option value="transfer_region">Transfer to Region (ወደ ሌላ ክልል ማስተላለፍ)</option>
                        <option value="release_private">Release to Private (ወደ ግል ድርጅት መልቀቅ)</option>
                        <option value="release_government">Release to Government (ወደ መንግስት መልቀቅ)</option>
                        <option value="pension">Pension (ጡረታ)</option>
                        <option value="resignation">Resignation (ከስራ መልቀቅ)</option>
                        <option value="other">Other (ሌላ)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Destination Type (የመድረሻ አይነት)</label>
                    <select name="destination_type">
                        <option value="government">Government (መንግስት)</option>
                        <option value="private">Private (ግል)</option>
                        <option value="college">College (ኮሌጅ)</option>
                        <option value="region">Region (ክልል)</option>
                        <option value="other">Other (ሌላ)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Destination Name (የመድረሻ ቦታ)</label>
                <input type="text" name="destination" placeholder="e.g. Ministry of Education, XYZ College..." required>
            </div>

            <div class="form-group">
                <label>Notes / Remarks (ተጨማሪ ማብራሪያ)</label>
                <textarea name="notes" rows="4" placeholder="Provide any additional context for the approvers..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" style="margin-top: 10px;">
                Submit Clearance Request (ላክ)
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>