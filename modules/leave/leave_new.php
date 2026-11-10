<?php
require_once __DIR__ . '/../../functions.php';
$user = require_login();

if (has_role('student')) {
    flash('warning', 'Students do not have access to the HR Leave module.');
    redirect(APP_URL . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $days_count = (int)$_POST['days_count'];
    $reason = trim($_POST['reason']);
    $handover_to = !empty($_POST['handover_to']) ? (int)$_POST['handover_to'] : null;
    $substitute_plan = trim($_POST['substitute_plan']);
    
    if (empty($start_date) || empty($end_date) || $days_count <= 0 || empty($reason)) {
        flash('danger', 'Please fill in all required fields and ensure dates are valid.');
    } else {
        try {
            $attachment_path = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/attachments/leave/';
                if (!is_dir(__DIR__ . '/../../' . $upload_dir)) {
                    mkdir(__DIR__ . '/../../' . $upload_dir, 0777, true);
                }
                $attachment_path = upload_file($_FILES['attachment'], $upload_dir, ['pdf', 'jpg', 'png', 'jpeg']);
            }

            $steps_json = json_encode([
                ["role" => "department_head"],
                ["role" => "hr"]
            ]);
            
            $title = "Leave Request: " . ucfirst($leave_type) . " ($days_count days)";
            $summary = "From $start_date to $end_date";

            // 1. Call the Stored Procedure (This handles its own Transaction & Commit)
            $stmt = $pdo->prepare("CALL sp_create_service_request('leave', ?, ?, ?, 'normal', ?, @req_id, @ref)");
            $stmt->execute([$user['id'], $title, $summary, $steps_json]);
            
            // Fetch the generated ID and Ref No
            $res = $pdo->query("SELECT @req_id AS id, @ref AS ref")->fetch();
            $request_id = $res['id'];
            $ref_no = $res['ref'];

            // 2. Insert Leave Details (No transaction block needed)
            $ins = $pdo->prepare("
                INSERT INTO leave_details 
                (request_id, leave_type, start_date, end_date, days_count, reason, handover_to, substitute_plan, attachment_path) 
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $ins->execute([$request_id, $leave_type, $start_date, $end_date, $days_count, $reason, $handover_to, $substitute_plan, $attachment_path]);
            
            // Log and Redirect
            log_audit('CREATE', 'service_requests', $request_id, [], ['ref' => $ref_no]);

            flash('success', "Leave request submitted successfully. Ref: $ref_no");
            redirect("leave_list.php");

        } catch (PDOException $e) {
            // If an error occurs, we just show the message. 
            // The database won't be corrupted because the SP is safe.
            flash('danger', 'Database Error: ' . $e->getMessage());
        }
    }
}

$stColleagues = $pdo->prepare("SELECT id, full_name, position FROM users WHERE department_id = ? AND id != ? AND status = 'active' ORDER BY full_name");
$stColleagues->execute([$user['department_id'], $user['id']]);
$colleagues = $stColleagues->fetchAll();

// FETCH TODAY'S ETHIOPIAN DATE FOR DEFAULTS
$todayEth = greg_to_ethiopian(date('Y-m-d'));
$currentEthYear = $todayEth['year'];
$currentEthMonth = $todayEth['month'];
$currentEthDay = $todayEth['day'];

$pageTitle = 'Request Leave';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 800px; margin: 30px auto;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Request Leave (የፈቃድ ጥያቄ)</h1>
            <p class="muted" style="margin-top: 5px;">Submit a new leave application for approval.</p>
        </div>
        <a href="leave_list.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to List
        </a>
    </div>

    <div class="card" style="padding: 30px;">
        <form method="post" enctype="multipart/form-data" class="stack">
            <?= csrf_field() ?>
            
            <div class="grid-2">
                <div class="form-group">
                    <label>Leave Type (የፈቃድ አይነት)</label>
                    <select name="leave_type" required style="font-weight: bold;">
                        <option value="annual">Annual Leave (ዓመታዊ ፈቃድ)</option>
                        <option value="sick">Sick Leave (የህክምና ፈቃድ)</option>
                        <option value="casual">Casual Leave (ጊዜያዊ ፈቃድ)</option>
                        <option value="study">Study Leave (የትምህርት ፈቃድ)</option>
                        <option value="maternity">Maternity Leave (የእናቶች ፈቃድ)</option>
                        <option value="unpaid">Unpaid Leave (ያለክፍያ ፈቃድ)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Handover To (ስራ የሚያስረክቡት)</label>
                    <select name="handover_to">
                        <option value="">-- Select Colleague (Optional) --</option>
                        <?php foreach ($colleagues as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['full_name']) ?> (<?= e($c['position']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- CUSTOM ETHIOPIAN DATE PICKER               -->
            <!-- ========================================== -->
            <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px dashed var(--mpc-blue); margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h3 style="margin: 0; font-size: 16px; color: var(--mpc-navy);">
                        📅 Select Dates (ቀናትን ይምረጡ - በኢትዮጵያ ዘመን አቆጣጠር)
                    </h3>
                    
                    <!-- Max 30 Days Checkbox -->
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: bold; color: var(--mpc-blue); cursor: pointer; background: #fff; padding: 5px 12px; border-radius: 20px; border: 1px solid var(--mpc-blue);">
                        <input type="checkbox" id="unknown_end_date" style="width: auto; margin: 0;">
                        End Date Unknown (Max 30 Days)
                    </label>
                </div>
                
                <div class="grid-2">
                    <!-- Start Date -->
                    <div class="form-group">
                        <label>Start Date (የመጀመሪያ ቀን)</label>
                        <div style="display: flex; gap: 5px;">
                            <select id="start_day" class="eth-day" style="flex: 1;" required></select>
                            <select id="start_month" class="eth-month" style="flex: 2;" required>
                                <option value="1" <?= $currentEthMonth == 1 ? 'selected' : '' ?>>መስከረም</option>
                                <option value="2" <?= $currentEthMonth == 2 ? 'selected' : '' ?>>ጥቅምት</option>
                                <option value="3" <?= $currentEthMonth == 3 ? 'selected' : '' ?>>ህዳር</option>
                                <option value="4" <?= $currentEthMonth == 4 ? 'selected' : '' ?>>ታህሳስ</option>
                                <option value="5" <?= $currentEthMonth == 5 ? 'selected' : '' ?>>ጥር</option>
                                <option value="6" <?= $currentEthMonth == 6 ? 'selected' : '' ?>>የካቲት</option>
                                <option value="7" <?= $currentEthMonth == 7 ? 'selected' : '' ?>>መጋቢት</option>
                                <option value="8" <?= $currentEthMonth == 8 ? 'selected' : '' ?>>ሚያዝያ</option>
                                <option value="9" <?= $currentEthMonth == 9 ? 'selected' : '' ?>>ግንቦት</option>
                                <option value="10" <?= $currentEthMonth == 10 ? 'selected' : '' ?>>ሰኔ</option>
                                <option value="11" <?= $currentEthMonth == 11 ? 'selected' : '' ?>>ሐምሌ</option>
                                <option value="12" <?= $currentEthMonth == 12 ? 'selected' : '' ?>>ነሐሴ</option>
                                <option value="13" <?= $currentEthMonth == 13 ? 'selected' : '' ?>>ጳጉሜን</option>
                            </select>
                            <select id="start_year" class="eth-year" style="flex: 1.5;" required>
                                <?php for($y = $currentEthYear - 1; $y <= $currentEthYear + 1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $currentEthYear ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <input type="hidden" name="start_date" id="start_date" required>
                        <div id="start_date_display" class="muted" style="font-size: 12px; margin-top: 5px;"></div>
                    </div>

                    <!-- End Date -->
                    <div class="form-group" id="end_date_container">
                        <label>End Date (የመጨረሻ ቀን)</label>
                        <div style="display: flex; gap: 5px;">
                            <select id="end_day" class="eth-day" style="flex: 1;" required></select>
                            <select id="end_month" class="eth-month" style="flex: 2;" required>
                                <option value="1" <?= $currentEthMonth == 1 ? 'selected' : '' ?>>መስከረም</option>
                                <option value="2" <?= $currentEthMonth == 2 ? 'selected' : '' ?>>ጥቅምት</option>
                                <option value="3" <?= $currentEthMonth == 3 ? 'selected' : '' ?>>ህዳር</option>
                                <option value="4" <?= $currentEthMonth == 4 ? 'selected' : '' ?>>ታህሳስ</option>
                                <option value="5" <?= $currentEthMonth == 5 ? 'selected' : '' ?>>ጥር</option>
                                <option value="6" <?= $currentEthMonth == 6 ? 'selected' : '' ?>>የካቲት</option>
                                <option value="7" <?= $currentEthMonth == 7 ? 'selected' : '' ?>>መጋቢት</option>
                                <option value="8" <?= $currentEthMonth == 8 ? 'selected' : '' ?>>ሚያዝያ</option>
                                <option value="9" <?= $currentEthMonth == 9 ? 'selected' : '' ?>>ግንቦት</option>
                                <option value="10" <?= $currentEthMonth == 10 ? 'selected' : '' ?>>ሰኔ</option>
                                <option value="11" <?= $currentEthMonth == 11 ? 'selected' : '' ?>>ሐምሌ</option>
                                <option value="12" <?= $currentEthMonth == 12 ? 'selected' : '' ?>>ነሐሴ</option>
                                <option value="13" <?= $currentEthMonth == 13 ? 'selected' : '' ?>>ጳጉሜን</option>
                            </select>
                            <select id="end_year" class="eth-year" style="flex: 1.5;" required>
                                <?php for($y = $currentEthYear - 1; $y <= $currentEthYear + 1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == $currentEthYear ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <input type="hidden" name="end_date" id="end_date" required>
                        <div id="end_date_display" class="muted" style="font-size: 12px; margin-top: 5px;"></div>
                    </div>
                </div>
            </div>

            <!-- Total Days -->
            <div class="form-group" style="background: #E0F2FE; padding: 15px; border-radius: 8px; border-left: 4px solid var(--mpc-blue);">
                <label style="color: var(--mpc-navy); margin-bottom: 0;">Total Days (ጠቅላላ ቀናት)</label>
                <input type="number" name="days_count" id="days_count" readonly style="background: #fff; font-weight: bold; font-size: 24px; color: var(--mpc-blue); border: none; padding: 5px 0; cursor: not-allowed;" required>
            </div>

            <div class="form-group">
                <label>Reason for Leave (የፈቃዱ ምክንያት)</label>
                <textarea name="reason" rows="3" required placeholder="Briefly explain the reason for your leave..."></textarea>
            </div>

            <div class="form-group">
                <label>Substitute Plan (ስራ ለማስተላለፍ የተዘጋጀ እቅድ)</label>
                <textarea name="substitute_plan" rows="3" placeholder="How will your tasks be handled while you are away?"></textarea>
            </div>

            <div class="form-group">
                <label>Attachment (አባሪ ፋይል - Optional)</label>
                <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png" style="background: #fff; padding: 8px;">
                <small class="muted">Required for Sick Leave (Medical Certificate).</small>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" style="margin-top: 10px;">
                Submit Request (ጥያቄውን አስገባ)
            </button>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- FIXED ETHIOPIAN DATE PICKER LOGIC          -->
<!-- ========================================== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const todayEthDay = <?= $currentEthDay ?>;
    const unknownEndDateCheckbox = document.getElementById('unknown_end_date');
    const endSelects = document.querySelectorAll('#end_day, #end_month, #end_year');

    // 1. Populate days dynamically based on month (Pagume has 5 or 6 days)
    function populateDays(dayId, monthId, yearId, defaultDay = null) {
        const daySel = document.getElementById(dayId);
        const month = parseInt(document.getElementById(monthId).value);
        const year = parseInt(document.getElementById(yearId).value);
        
        let currentVal = daySel.value;
        let maxDays = 30;
        if (month === 13) {
            maxDays = (year % 4 === 3) ? 6 : 5;
        }
        
        daySel.innerHTML = '';
        for (let i = 1; i <= maxDays; i++) {
            let opt = document.createElement('option');
            opt.value = i;
            opt.innerHTML = i;
            daySel.appendChild(opt);
        }

        if (defaultDay !== null) {
            daySel.value = defaultDay; 
        } else if (currentVal && currentVal <= maxDays) {
            daySel.value = currentVal; 
        } else {
            daySel.value = 1; 
        }
    }

    // 2. Convert Ethiopian to Gregorian (BULLETPROOF RELATIVE ALGORITHM)
    function ethToGreg(eYear, eMonth, eDay) {
        let days = 0;
        if (eYear >= 2016) {
            for (let y = 2016; y < eYear; y++) {
                days += (y % 4 === 3) ? 366 : 365;
            }
        } else {
            for (let y = eYear; y < 2016; y++) {
                days -= (y % 4 === 3) ? 366 : 365;
            }
        }
        days += (eMonth - 1) * 30;
        days += (eDay - 1);
        
        const epochGC = Date.UTC(2023, 8, 12); // Sept 12, 2023 = Meskerem 1, 2016
        const targetMs = epochGC + (days * 86400000);
        const d = new Date(targetMs);
        
        if (isNaN(d.getTime())) return ''; 

        const y = d.getUTCFullYear();
        const m = String(d.getUTCMonth() + 1).padStart(2, '0');
        const dd = String(d.getUTCDate()).padStart(2, '0');
        return `${y}-${m}-${dd}`;
    }

    // 3. Convert Gregorian to Ethiopian
    function gregToEth(gregDateStr) {
        const d = new Date(gregDateStr);
        if (isNaN(d.getTime())) return { year: 2016, month: 1, day: 1 };
        
        const epochGC = Date.UTC(2023, 8, 12);
        let diffDays = Math.round((d.getTime() - epochGC) / 86400000);
        
        let eYear = 2016;
        if (diffDays >= 0) {
            while (true) {
                let daysInYear = (eYear % 4 === 3) ? 366 : 365;
                if (diffDays >= daysInYear) {
                    diffDays -= daysInYear;
                    eYear++;
                } else {
                    break;
                }
            }
        } else {
            while (true) {
                eYear--;
                let daysInYear = (eYear % 4 === 3) ? 366 : 365;
                if (diffDays < -daysInYear) {
                    diffDays += daysInYear;
                } else {
                    diffDays += daysInYear;
                    break;
                }
            }
        }
        
        let eMonth = 1;
        while (diffDays >= 30 && eMonth < 13) {
            diffDays -= 30;
            eMonth++;
        }
        
        if (eMonth === 13) {
            let maxDays = (eYear % 4 === 3) ? 6 : 5;
            if (diffDays >= maxDays) {
                diffDays -= maxDays;
                eYear++;
                eMonth = 1;
            }
        }
        
        let eDay = diffDays + 1;
        return { year: eYear, month: eMonth, day: eDay };
    }

    // 4. Main Update Function
    function updateDates() {
        // -- Start Date --
        const sMonth = document.getElementById('start_month').value;
        const sYear = document.getElementById('start_year').value;
        const sDay = document.getElementById('start_day').value;
        
        if (sDay) {
            const gregStart = ethToGreg(sYear, sMonth, sDay);
            document.getElementById('start_date').value = gregStart;
            document.getElementById('start_date_display').innerHTML = `Gregorian: <strong>${gregStart}</strong>`;
        }

        // -- End Date --
        const hiddenStart = document.getElementById('start_date').value;
        
        if (unknownEndDateCheckbox.checked && hiddenStart) {
            // MAX 30 DAYS LOGIC
            const d1 = new Date(hiddenStart);
            const d2 = new Date(d1.getTime() + 29 * 86400000); 
            
            const y = d2.getUTCFullYear();
            const m = String(d2.getUTCMonth() + 1).padStart(2, '0');
            const d = String(d2.getUTCDate()).padStart(2, '0');
            const gregEnd = `${y}-${m}-${d}`;
            
            document.getElementById('end_date').value = gregEnd;
            document.getElementById('end_date_display').innerHTML = `Gregorian: <strong>${gregEnd}</strong> (Max 30 Days)`;
            
            const ethEnd = gregToEth(gregEnd);
            document.getElementById('end_year').value = ethEnd.year;
            document.getElementById('end_month').value = ethEnd.month;
            populateDays('end_day', 'end_month', 'end_year', ethEnd.day);
            
            document.getElementById('days_count').value = 30;
            
        } else {
            // MANUAL LOGIC
            const eMonth = document.getElementById('end_month').value;
            const eYear = document.getElementById('end_year').value;
            const eDay = document.getElementById('end_day').value;

            if (eDay) {
                const gregEnd = ethToGreg(eYear, eMonth, eDay);
                document.getElementById('end_date').value = gregEnd;
                document.getElementById('end_date_display').innerHTML = `Gregorian: <strong>${gregEnd}</strong>`;
            }

            const hiddenEnd = document.getElementById('end_date').value;

            if (hiddenStart && hiddenEnd) {
                const d1 = new Date(hiddenStart);
                const d2 = new Date(hiddenEnd);
                if (!isNaN(d1) && !isNaN(d2) && d2 >= d1) {
                    const diffTime = Math.abs(d2 - d1);
                    const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24)) + 1;
                    document.getElementById('days_count').value = diffDays;
                } else {
                    document.getElementById('days_count').value = 0;
                }
            }
        }
    }

    // 5. Handle Checkbox Toggle
    unknownEndDateCheckbox.addEventListener('change', function() {
        if (this.checked) {
            endSelects.forEach(el => {
                el.disabled = true;
                el.style.opacity = '0.6';
                el.style.cursor = 'not-allowed';
            });
            updateDates();
        } else {
            endSelects.forEach(el => {
                el.disabled = false;
                el.style.opacity = '1';
                el.style.cursor = 'auto';
            });
            updateDates();
        }
    });

    // 6. Attach Event Listeners
    document.querySelectorAll('.eth-day, .eth-month, .eth-year').forEach(el => {
        el.addEventListener('change', function() {
            if (this.classList.contains('eth-month') || this.classList.contains('eth-year')) {
                if (this.id.startsWith('start')) {
                    populateDays('start_day', 'start_month', 'start_year');
                } else {
                    populateDays('end_day', 'end_month', 'end_year');
                }
            }
            updateDates();
        });
    });

    // 7. Initialize on Page Load
    populateDays('start_day', 'start_month', 'start_year', todayEthDay);
    populateDays('end_day', 'end_month', 'end_year', todayEthDay);
    updateDates();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>