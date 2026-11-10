<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   WORKFLOW TEMPLATES (Admin only)
   ============================================================
   PURPOSE
     Customize the approval chain for each of the 12 service
     request types. Overrides are stored in the `settings` table
     under key `workflow_template_<type>` as JSON.

   INTEGRATION PATCH REQUIRED
     `Workflow::templateFor()` in includes/workflow_engine.php
     must be updated to check settings overrides before falling
     back to the hardcoded TEMPLATES constant. The patch is
     documented at the bottom of this page and rendered on-screen
     if not yet applied.

   STORAGE
     settings.setting_key = 'workflow_template_leave'
     settings.setting_value = '[{"role":"department_head"},{"role":"hr"}]'

     Absence of the row = use default (Workflow::TEMPLATES[$type])

   FEATURES
     - Per-type customization (12 types)
     - Visual chain preview (Dept Head → HR → Director)
     - Per-step optional department scoping
     - Reorder (up/down), add, remove steps
     - Reset to default per type
     - Impact preview (pending request count)
     - Full audit trail per save
     - Bilingual labels (Amharic + English)
     - Setup detection + inline patch documentation

   WHAT IS NOT EDITABLE HERE
     - Clearance workflows (5-step department chain) are
       hardcoded in the sp_create_clearance stored procedure.
       Changing them requires a DB migration.

   DEPENDENCIES
     functions.php            — base helpers
     includes/auth_check.php  — Auth::requireRole
     includes/csrf.php        — Csrf::check, Csrf::field
     includes/flash.php       — Flash::success, danger, warning, info
     includes/audit.php       — Audit::updated, Audit::deleted
     includes/workflow_engine.php — Workflow::TEMPLATES, ROLE_LABELS

   SAFETY
     - Admin-only
     - CSRF required on all POST
     - Transaction wraps each save
     - Role codes validated against Workflow::ROLE_LABELS
     - At least 1 step required
     - Max 15 steps
     - No SQL errors exposed
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/workflow_engine.php';


/* ------------------------------------------------------------
   2. AUTH GATE
   ------------------------------------------------------------ */
$user = Auth::require();
Auth::requireRole('admin', 'You do not have permission to manage workflow templates.');


/* ------------------------------------------------------------
   3. CONFIGURATION — REQUEST TYPES
   ------------------------------------------------------------ */
$typeLabels = [
    'leave'        => ['label' => 'Leave Requests',       'label_am' => 'የፈቃድ ጥያቄዎች',    'icon' => '🏖️', 'color' => 'var(--mpc-blue)'],
    'maternal'     => ['label' => 'Maternal / Paternity', 'label_am' => 'የወሊድ/አባት ፈቃድ',  'icon' => '👶', 'color' => 'var(--mpc-flame)'],
    'pension'      => ['label' => 'Pension',              'label_am' => 'ጡረታ',             'icon' => '👴', 'color' => 'var(--mpc-navy)'],
    'resignation'  => ['label' => 'Resignation',          'label_am' => 'ከስራ መልቀቅ',     'icon' => '📤', 'color' => 'var(--mpc-red)'],
    'promotion'    => ['label' => 'Promotion',            'label_am' => 'ማስተዋወቅ',        'icon' => '⬆️', 'color' => '#16a34a'],
    'training'     => ['label' => 'Training',             'label_am' => 'ስልጠና',           'icon' => '📚', 'color' => '#6366f1'],
    'overtime'     => ['label' => 'Overtime',             'label_am' => 'ተጨማሪ ስራ',       'icon' => '⏰', 'color' => '#0891b2'],
    'procurement'  => ['label' => 'Procurement',          'label_am' => 'ግዥ',              'icon' => '🛒', 'color' => '#6366f1'],
    'plan'         => ['label' => 'Planning',             'label_am' => 'ዕቅድ',            'icon' => '📋', 'color' => '#0891b2'],
    'disciplinary' => ['label' => 'Disciplinary',         'label_am' => 'የዲሲፕሊን ጉዳይ',   'icon' => '⚠️', 'color' => 'var(--mpc-red)'],
    'grievance'    => ['label' => 'Grievance',            'label_am' => 'ቅሬታ',            'icon' => '📢', 'color' => 'var(--mpc-flame)'],
    'other'        => ['label' => 'Other',                'label_am' => 'ሌላ',             'icon' => '📄', 'color' => '#6B7280'],
];

$availableRoles = Workflow::ROLE_LABELS;


/* ------------------------------------------------------------
   4. HELPERS
   ------------------------------------------------------------ */

/**
 * Read the current template for a type. Returns [steps, is_override].
 * If the settings row is missing/invalid, falls back to default.
 */
function mpc_load_template(string $type, array $availableRoles): array
{
    $override = get_setting('workflow_template_' . $type, '');
    if ($override !== '') {
        $decoded = json_decode($override, true);
        if (is_array($decoded) && !empty($decoded)) {
            // Validate every step
            $valid = true;
            foreach ($decoded as $step) {
                if (!is_array($step)
                    || empty($step['role'])
                    || !isset($availableRoles[$step['role']])
                ) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                return [$decoded, true];
            }
        }
    }
    return [Workflow::TEMPLATES[$type] ?? Workflow::TEMPLATES['other'], false];
}

/** Detect whether the integration patch has been applied to workflow_engine.php. */
function mpc_detect_patch_applied(): bool
{
    $file = __DIR__ . '/../includes/workflow_engine.php';
    if (!is_file($file)) return false;
    $content = (string)@file_get_contents($file);
    return str_contains($content, '// settings-override-patch applied');
}

/** Human-readable chain: "Dept Head → HR → Director". */
function mpc_chain_label(array $steps, array $availableRoles): string
{
    if (empty($steps)) return '<em style="color:#9CA3AF;">(empty chain)</em>';
    $parts = [];
    foreach ($steps as $step) {
        $label = $availableRoles[$step['role']] ?? $step['role'];
        $parts[] = '<strong>' . e($label) . '</strong>';
    }
    return implode(' <span style="color:#9CA3AF; font-weight: 700;">→</span> ', $parts);
}


/* ------------------------------------------------------------
   5. LOAD CURRENT TEMPLATES + IMPACT COUNTS
   ------------------------------------------------------------ */
$templates = [];
foreach (array_keys($typeLabels) as $type) {
    [$steps, $isOverride] = mpc_load_template($type, $availableRoles);

    $pending = 0;
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM service_requests
            WHERE request_type = ? AND status IN ('submitted','in_progress')
        ");
        $st->execute([$type]);
        $pending = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* silent */ }

    $templates[$type] = [
        'steps'       => $steps,
        'is_override' => $isOverride,
        'pending'     => $pending,
    ];
}


/* ------------------------------------------------------------
   6. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    $postAction = (string)($_POST['action'] ?? '');
    $type       = (string)($_POST['type']   ?? '');

    if (!isset($typeLabels[$type])) {
        Flash::danger('Invalid request type.');
        redirect('workflow_templates.php');
    }

    $settingKey = 'workflow_template_' . $type;
    $typeLabel  = $typeLabels[$type]['label'];

    /* ---------- RESET ---------- */
    if ($postAction === 'reset') {
        $oldValue = get_setting($settingKey, '');
        if ($oldValue === '') {
            Flash::info("The {$typeLabel} template is already using the default.");
            redirect('workflow_templates.php#' . $type);
        }

        try {
            $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$settingKey]);

            $oldSteps = json_decode($oldValue, true) ?: [];
            Audit::deleted('workflow_templates', 0, [
                'type'  => $type,
                'steps' => $oldSteps,
            ]);

            Flash::success("Workflow template for <strong>" . e($typeLabel) . "</strong> reset to default.");
        } catch (Throwable $ex) {
            error_log('workflow_templates reset failed: ' . $ex->getMessage());
            Flash::danger('Could not reset the template.');
        }

        redirect('workflow_templates.php#' . $type);
    }

    /* ---------- SAVE ---------- */
    if ($postAction === 'save') {

        $roleInputs = (array)($_POST['step_role']       ?? []);
        $deptInputs = (array)($_POST['step_department'] ?? []);

        $steps  = [];
        $errors = [];

        foreach ($roleInputs as $i => $roleCode) {
            $roleCode = trim((string)$roleCode);
            if ($roleCode === '') continue;

            if (!isset($availableRoles[$roleCode])) {
                $errors[] = "Step " . ($i + 1) . ": invalid role '{$roleCode}'.";
                continue;
            }

            $step = ['role' => $roleCode];

            $deptId = (int)($deptInputs[$i] ?? 0);
            if ($deptId > 0) {
                $step['department'] = $deptId;
            }

            $steps[] = $step;
        }

        if (empty($steps)) {
            $errors[] = 'At least one step is required.';
        } elseif (count($steps) > 15) {
            $errors[] = 'Maximum 15 steps allowed.';
        }

        if (!empty($errors)) {
            foreach (array_slice($errors, 0, 4) as $err) {
                Flash::danger($err);
            }
            redirect('workflow_templates.php#' . $type);
        }

        $newJson    = json_encode($steps, JSON_UNESCAPED_UNICODE);
        $oldValue   = get_setting($settingKey, '');
        $defaultJson = json_encode(Workflow::TEMPLATES[$type] ?? [], JSON_UNESCAPED_UNICODE);

        // No-op detection
        if ($oldValue === $newJson) {
            Flash::warning('No changes were made.');
            redirect('workflow_templates.php#' . $type);
        }

        try {
            $pdo->beginTransaction();

            $check = $pdo->prepare("SELECT 1 FROM settings WHERE setting_key = ?");
            $check->execute([$settingKey]);

            if ($check->fetch()) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")
                    ->execute([$newJson, $settingKey]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")
                    ->execute([$settingKey, $newJson]);
            }

            $pdo->commit();

            Audit::updated('workflow_templates', 0,
                ['type' => $type, 'steps' => $oldValue !== '' ? json_decode($oldValue, true) : 'default'],
                ['type' => $type, 'steps' => $steps]
            );

            if ($newJson === $defaultJson) {
                Flash::success(
                    "Workflow template for <strong>" . e($typeLabel) . "</strong> saved. "
                    . "<em>(Note: this matches the default — you can Reset to remove the override.)</em>"
                );
            } else {
                Flash::success(
                    "Workflow template for <strong>" . e($typeLabel) . "</strong> updated with "
                    . count($steps) . " step(s)."
                );
            }

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('workflow_templates save failed: ' . $ex->getMessage());
            Flash::danger('Could not save the template. Please try again.');
        }

        redirect('workflow_templates.php#' . $type);
    }
}


/* ------------------------------------------------------------
   7. LOAD DEPARTMENTS FOR STEP SCOPING
   ------------------------------------------------------------ */
$departments = [];
try {
    $departments = $pdo->query("
        SELECT id, name FROM departments
        WHERE is_active = 1
        ORDER BY name ASC
    ")->fetchAll();
} catch (Throwable $e) { /* silent */ }


/* ------------------------------------------------------------
   8. DETECT PATCH STATUS
   ------------------------------------------------------------ */
$patchApplied = mpc_detect_patch_applied();

// Count customized types
$customizedCount = 0;
foreach ($templates as $t) {
    if ($t['is_override']) $customizedCount++;
}


/* ------------------------------------------------------------
   9. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Workflow Templates';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">
                Workflow Templates (የስራ ፍሰት አብነቶች)
            </h1>
            <p class="muted" style="margin-top: 5px;">
                Customize the approval chain for each request type.
            </p>
        </div>
        <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to Admin
        </a>
    </div>

    <!-- ============================================================
         PATCH STATUS BANNER
         ============================================================ -->
    <?php if (!$patchApplied): ?>
        <div class="card" style="border-left-color: var(--mpc-red); background: #FEF2F2; margin-bottom: 20px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="font-size: 24px; line-height: 1;">⚠️</div>
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 6px; color: var(--mpc-red); font-size: 15px;">
                        Setup Required — Templates won't take effect until the integration patch is applied
                    </h3>
                    <p style="margin: 0 0 12px; font-size: 13px; color: #7F1D1D; line-height: 1.6;">
                        You can save templates on this page, and they will be stored in the database.
                        But <code>Workflow::templateFor()</code> is still using the hardcoded defaults
                        until a small patch is applied to <code>includes/workflow_engine.php</code>.
                    </p>
                    <details style="font-size: 13px;">
                        <summary style="cursor: pointer; color: var(--mpc-red); font-weight: 700;">
                            Show the patch (click to expand)
                        </summary>
                        <div style="margin-top: 12px; background: #fff; border: 1px solid #FECACA; border-radius: 6px; padding: 14px;">
                            <p style="margin: 0 0 10px; font-size: 12px; color: #7F1D1D;">
                                In <code>includes/workflow_engine.php</code>, replace the
                                <code>templateFor()</code> method with the following version:
                            </p>
<pre style="background: #F9FAFB; border: 1px solid #E5E7EB; padding: 12px; border-radius: 6px; overflow-x: auto; font-family: ui-monospace, monospace; font-size: 11px; line-height: 1.6; color: #1F2937;">public static function templateFor(string $type, array $context = []): array
{
    // settings-override-patch applied
    $type = strtolower(trim($type));

    if (!in_array($type, self::VALID_TYPES, true)) {
        return self::TEMPLATES['other'];
    }

    // 1) Check for settings override
    $override = get_setting('workflow_template_' . $type, '');
    if ($override !== '') {
        $decoded = json_decode($override, true);
        if (is_array($decoded) &amp;&amp; !empty($decoded)) {
            $valid = true;
            foreach ($decoded as $step) {
                if (!is_array($step)
                    || empty($step['role'])
                    || !isset(self::ROLE_LABELS[$step['role']])
                ) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) return $decoded;
        }
    }

    // 2) Fall back to default
    $steps = self::TEMPLATES[$type] ?? self::TEMPLATES['other'];

    if ($type === 'procurement' &amp;&amp; isset($context['amount'])) {
        if (Policy::procurementNeedsDirector((float)$context['amount'])) {
            $steps[] = ['role' => 'director'];
        }
    }

    return $steps;
}</pre>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #7F1D1D;">
                                <strong>After saving the file:</strong> reload this page. The setup banner should disappear,
                                and your templates will take effect on the next request created.
                            </p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ============================================================
         SUMMARY CHIPS
         ============================================================ -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🔀</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= count($typeLabels) ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Request Types</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #16a34a; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">✅</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #16a34a; line-height: 1;"><?= count($typeLabels) - $customizedCount ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Using Default</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-flame); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">✏️</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-flame); line-height: 1;"><?= $customizedCount ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Customized</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-blue); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;"><?= $patchApplied ? '🔗' : '⚠️' ?></div>
            <div>
                <div style="font-size: 13px; font-weight: 700; color: <?= $patchApplied ? 'var(--mpc-blue)' : 'var(--mpc-red)' ?>; line-height: 1.2;">
                    <?= $patchApplied ? 'Active' : 'Not Applied' ?>
                </div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Integration</div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         INFO BANNER
         ============================================================ -->
    <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF; margin-bottom: 20px;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <div style="font-size: 22px; line-height: 1;">ℹ️</div>
            <div style="font-size: 13px; color: #374151; line-height: 1.7;">
                <strong style="color: var(--mpc-navy);">How workflow templates work:</strong>
                New requests of each type follow the chain shown below. Changes apply to
                <strong>new requests</strong> — already-pending requests keep their original chain
                (changing in-flight chains would corrupt approval history).
                <br>
                <em style="color: #6B7280;">Clearance workflows use a fixed department chain (Department → Library → Finance → etc.) that is defined in the stored procedure and cannot be edited here.</em>
            </div>
        </div>
    </div>

    <!-- ============================================================
         TYPE CARDS
         ============================================================ -->
    <?php foreach ($typeLabels as $type => $meta):
        $tmpl       = $templates[$type];
        $steps      = $tmpl['steps'];
        $isOverride = $tmpl['is_override'];
        $pending    = $tmpl['pending'];
        $typeLabel  = $meta['label'];
        $typeLabelAm= $meta['label_am'];
        $icon       = $meta['icon'];
        $color      = $meta['color'];
    ?>
    <div class="card" id="<?= e($type) ?>" style="padding: 0; overflow: hidden; border-left: 5px solid <?= e($color) ?>; margin-bottom: 16px;">

        <!-- Card header -->
        <div style="padding: 16px 20px; background: #FAFBFC; border-bottom: 1px solid var(--mpc-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <span style="font-size: 26px; line-height: 1;"><?= $icon ?></span>
                <div>
                    <div style="font-weight: 700; color: var(--mpc-navy); font-size: 15px;">
                        <?= e($typeLabel) ?>
                        <span style="font-size: 12px; color: #6B7280; font-weight: 500;">· <?= e($typeLabelAm) ?></span>
                    </div>
                    <div style="display: flex; gap: 6px; margin-top: 3px; flex-wrap: wrap;">
                        <?php if ($isOverride): ?>
                            <span class="badge badge-warning" style="font-size: 10px;">✏️ Custom</span>
                        <?php else: ?>
                            <span class="badge badge-success" style="font-size: 10px;">✓ Default</span>
                        <?php endif; ?>
                        <?php if ($pending > 0): ?>
                            <span class="badge badge-info" style="font-size: 10px;">
                                <?= $pending ?> pending
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="font-size: 11px; color: #9CA3AF;">
                <code style="background: #F3F4F6; padding: 2px 6px; border-radius: 3px;"><?= e($type) ?></code>
            </div>
        </div>

        <!-- Visual chain preview -->
        <div style="padding: 16px 20px; background: #fff;">
            <div style="font-size: 11px; color: #6B7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                Current approval chain
            </div>
            <div style="font-size: 14px; line-height: 1.8; color: #374151;">
                <?= mpc_chain_label($steps, $availableRoles) ?>
            </div>
            <?php if ($pending > 0 && $isOverride): ?>
                <div style="margin-top: 8px; font-size: 11px; color: var(--mpc-flame);">
                    ⚠️ <?= $pending ?> existing pending request(s) still use the previous chain.
                </div>
            <?php endif; ?>
        </div>

        <!-- Expandable editor -->
        <details style="border-top: 1px solid var(--mpc-border);">
            <summary style="cursor: pointer; padding: 12px 20px; background: #F9FAFB; font-size: 13px; font-weight: 700; color: var(--mpc-navy); list-style: none; user-select: none;">
                ⚙️ Customize this workflow
            </summary>

            <div style="padding: 20px;" data-type="<?= e($type) ?>">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="type" value="<?= e($type) ?>">

                    <div class="steps-container" id="steps-<?= e($type) ?>">
                        <?php foreach ($steps as $i => $step):
                            $stepRole = $step['role'] ?? 'department_head';
                            $stepDept = (int)($step['department'] ?? 0);
                        ?>
                            <div class="step-row" style="display: flex; align-items: center; gap: 8px; padding: 10px 0; border-bottom: 1px dashed var(--mpc-border); flex-wrap: wrap;">
                                <span class="step-num" style="width: 26px; height: 26px; border-radius: 50%; background: var(--mpc-navy); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0;">
                                    <?= $i + 1 ?>
                                </span>
                                <select name="step_role[]" required style="flex: 1; min-width: 160px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                    <?php foreach ($availableRoles as $code => $label): ?>
                                        <option value="<?= e($code) ?>" <?= $stepRole === $code ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="step_department[]" style="flex: 1; min-width: 160px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                    <option value="0">— Not scoped —</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>" <?= $stepDept === (int)$d['id'] ? 'selected' : '' ?>>
                                            <?= e($d['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="display: flex; gap: 4px;">
                                    <button type="button" onclick="moveStep(this, -1)" class="btn btn-sm btn-ghost" style="border-color: #ccc; padding: 6px 8px;" title="Move up">↑</button>
                                    <button type="button" onclick="moveStep(this, 1)" class="btn btn-sm btn-ghost" style="border-color: #ccc; padding: 6px 8px;" title="Move down">↓</button>
                                    <button type="button" onclick="removeStep(this)" class="btn btn-sm btn-ghost" style="border-color: var(--mpc-red); color: var(--mpc-red); padding: 6px 8px;" title="Remove step">×</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <button type="button" onclick="addStep(this)" class="btn btn-sm btn-ghost" style="border-color: var(--mpc-blue); color: var(--mpc-blue);">
                            + Add Step
                        </button>
                        <span style="font-size: 11px; color: #6B7280;">
                            Optional "Department" scoping is only useful for Department Head steps (assigns the step to one specific department's head).
                        </span>
                    </div>

                    <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--mpc-border); display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;">
                        <button type="submit" name="action" value="save" class="btn btn-primary">
                            💾 Save Changes
                        </button>
                        <?php if ($isOverride): ?>
                            <button type="submit" name="action" value="reset"
                                    class="btn btn-danger"
                                    data-confirm="Reset the <?= e($typeLabel) ?> workflow to the default chain? Your custom steps will be lost.">
                                ↺ Reset to Default
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-ghost" style="border-color: #ccc; color: #9CA3AF; cursor: not-allowed;" disabled title="Already using default">
                                ✓ Using Default
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
    </div>
    <?php endforeach; ?>

    <!-- ============================================================
         HIDDEN STEP TEMPLATE (used by JS addStep)
         ============================================================ -->
    <template id="step-template">
        <div class="step-row" style="display: flex; align-items: center; gap: 8px; padding: 10px 0; border-bottom: 1px dashed var(--mpc-border); flex-wrap: wrap;">
            <span class="step-num" style="width: 26px; height: 26px; border-radius: 50%; background: var(--mpc-navy); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0;">?</span>
            <select name="step_role[]" required style="flex: 1; min-width: 160px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                <?php foreach ($availableRoles as $code => $label): ?>
                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="step_department[]" style="flex: 1; min-width: 160px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                <option value="0">— Not scoped —</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="display: flex; gap: 4px;">
                <button type="button" onclick="moveStep(this, -1)" class="btn btn-sm btn-ghost" style="border-color: #ccc; padding: 6px 8px;" title="Move up">↑</button>
                <button type="button" onclick="moveStep(this, 1)" class="btn btn-sm btn-ghost" style="border-color: #ccc; padding: 6px 8px;" title="Move down">↓</button>
                <button type="button" onclick="removeStep(this)" class="btn btn-sm btn-ghost" style="border-color: var(--mpc-red); color: var(--mpc-red); padding: 6px 8px;" title="Remove step">×</button>
            </div>
        </div>
    </template>

    <!-- ============================================================
         JS — ADD / REMOVE / REORDER STEPS
         ============================================================ -->
    <script>
    (function() {
        'use strict';

        function getContainer(el) {
            return el.closest('[data-type]');
        }

        function renumber(container) {
            if (!container) return;
            var rows = container.querySelectorAll('.step-row');
            rows.forEach(function(row, i) {
                var num = row.querySelector('.step-num');
                if (num) num.textContent = i + 1;
            });
        }

        window.addStep = function(btn) {
            var container = getContainer(btn);
            if (!container) return;

            var stepsContainer = container.querySelector('.steps-container');
            var template = document.getElementById('step-template');
            if (!stepsContainer || !template) return;

            var newRow = document.createElement('div');
            newRow.innerHTML = template.innerHTML.trim();
            var rowEl = newRow.firstElementChild;
            stepsContainer.appendChild(rowEl);
            renumber(container);

            // Focus the new role select
            var sel = rowEl.querySelector('select[name="step_role[]"]');
            if (sel) sel.focus();
        };

        window.removeStep = function(btn) {
            var container = getContainer(btn);
            if (!container) return;

            var rows = container.querySelectorAll('.step-row');
            if (rows.length <= 1) {
                alert('At least one step is required.');
                return;
            }
            var row = btn.closest('.step-row');
            if (row) row.remove();
            renumber(container);
        };

        window.moveStep = function(btn, dir) {
            var container = getContainer(btn);
            if (!container) return;

            var row = btn.closest('.step-row');
            if (!row) return;

            if (dir === -1) {
                var prev = row.previousElementSibling;
                if (prev && prev.classList.contains('step-row')) {
                    row.parentNode.insertBefore(row, prev);
                }
            } else {
                var next = row.nextElementSibling;
                if (next && next.classList.contains('step-row')) {
                    row.parentNode.insertBefore(next, row);
                }
            }
            renumber(container);
        };

        // Auto-open the editor when arriving via #anchor
        if (window.location.hash) {
            var hash = window.location.hash.substring(1);
            var target = document.getElementById(hash);
            if (target) {
                var details = target.querySelector('details');
                if (details) {
                    details.open = true;
                    setTimeout(function() {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 100);
                }
            }
        }
    })();
    </script>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>