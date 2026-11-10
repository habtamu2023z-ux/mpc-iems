<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   SYSTEM SETTINGS (Admin only)
   ============================================================
   PURPOSE
     Central configuration for branding, contact info, and
     document numbering. Changes propagate instantly to the
     topbar, footer, letters, and print templates.

   SECTIONS
     1. Branding Images — Logo + Favicon
     2. Branding Text   — College name (Amharic + English)
     3. Contact Info    — Address, PO Box, Phones, Email
     4. Document Numbers — Internal + External defaults

   FEATURES
     - All settings editable in one form
     - File upload with size + MIME + extension validation
     - Image preview with dimensions + file size
     - "Reset to default" for each image (deletes custom file)
     - Old file cleaned up when a new one is uploaded
     - Transaction wraps all DB writes
     - Audit::track() records only changed fields (except files)
     - No-op detection ("no changes made")
     - Sidebar: "Where these appear" reference

   DEPENDENCIES
     functions.php          — base helpers, get_setting, get_logo_url, get_favicon_url
     includes/auth_check.php — Auth::requireRole
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, warning, danger
     includes/audit.php     — Audit::snapshot, Audit::track, Audit::updated

   FILES UPLOADED TO
     assets/img/logo_TIMESTAMP.ext
     assets/img/favicon_TIMESTAMP.ext
     Path stored in settings as e.g. `assets/img/logo_1698000000.png`

   SAFETY
     - Admin-only access
     - CSRF required on POST
     - File size/MIME/extension whitelisted
     - Old file deleted only after successful new upload + commit
     - No SQL error messages exposed
   ============================================================ */


/* ------------------------------------------------------------
   1. LOAD DEPENDENCIES
   ------------------------------------------------------------ */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';


/* ------------------------------------------------------------
   2. AUTH GATE
   ------------------------------------------------------------ */
$user = Auth::require();
Auth::requireRole('admin', 'You do not have permission to access System Settings.');


/* ------------------------------------------------------------
   3. CONFIGURATION FOR THIS PAGE
   ------------------------------------------------------------ */
$textKeys = [
    'org_name_en', 'org_name_am', 'org_address', 'org_pobox',
    'org_phones', 'org_email', 'doc_no_internal', 'doc_no_external',
];

$uploadConfig = [
    'logo' => [
        'setting_key'  => 'system_logo',
        'extensions'   => ['png', 'jpg', 'jpeg', 'svg'],
        'mimes'        => ['image/png', 'image/jpeg', 'image/svg+xml'],
        'max_bytes'    => 2 * 1024 * 1024,   // 2 MB
        'label'        => 'College Logo',
        'label_am'     => 'የኮሌጁ አርማ',
        'preview_fn'   => 'get_logo_url',
    ],
    'favicon' => [
        'setting_key'  => 'system_favicon',
        'extensions'   => ['png', 'ico', 'svg'],
        'mimes'        => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml'],
        'max_bytes'    => 1 * 1024 * 1024,   // 1 MB
        'label'        => 'Browser Favicon',
        'label_am'     => 'የአሳሽ አዶ',
        'preview_fn'   => 'get_favicon_url',
    ],
];

$uploadDir = __DIR__ . '/../assets/img/';


/* ------------------------------------------------------------
   4. HELPERS
   ------------------------------------------------------------ */

/**
 * Validate and save an uploaded image. Returns the relative path
 * (e.g. "assets/img/logo_1698000000.png") or null on no upload.
 * Throws RuntimeException with a user-safe message on validation failure.
 */
function mpc_save_upload(array $file, array $config, string $uploadDirAbs, string $fieldName): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Upload failed for {$fieldName} (error code: {$file['error']}).");
    }

    // Size
    if ((int)$file['size'] > $config['max_bytes']) {
        $maxMb = round($config['max_bytes'] / 1024 / 1024, 1);
        throw new RuntimeException("The {$fieldName} is too large. Maximum size is {$maxMb}MB.");
    }

    // Extension
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $config['extensions'], true)) {
        throw new RuntimeException("Invalid format for {$fieldName}. Allowed: " . strtoupper(implode(', ', $config['extensions'])) . '.');
    }

    // MIME (sniff actual content)
    if (function_exists('finfo_open') && is_uploaded_file($file['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime && !in_array($mime, $config['mimes'], true)) {
            throw new RuntimeException("The {$fieldName} file content does not match its extension.");
        }
    }

    // Prepare destination
    if (!is_dir($uploadDirAbs)) {
        if (!@mkdir($uploadDirAbs, 0775, true) && !is_dir($uploadDirAbs)) {
            throw new RuntimeException('Upload directory is not writable. Contact IT.');
        }
    }

    $safeBase  = preg_replace('/[^a-z0-9_]/i', '', $fieldName); // logo | favicon
    $filename  = $safeBase . '_' . time() . '.' . $ext;
    $destAbs   = $uploadDirAbs . $filename;
    $destRel   = 'assets/img/' . $filename;

    if (!@move_uploaded_file($file['tmp_name'], $destAbs)) {
        throw new RuntimeException("Could not save the {$fieldName} file. Check folder permissions.");
    }

    return $destRel;
}

/**
 * Delete a previously uploaded file if it is not the default asset.
 * Safe: only deletes files under assets/img/ that match the pattern
 * logo_*.ext or favicon_*.ext.
 */
function mpc_delete_old_upload(?string $path, string $base): void
{
    if (!$path) return;
    if (!preg_match('#^assets/img/' . preg_quote($base, '#') . '_\d+\.[a-z0-9]+$#i', $path)) {
        return; // Not our file — do not touch
    }
    $abs = __DIR__ . '/../' . $path;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * Compute dimensions and formatted size for an image path.
 * Returns null if the file doesn't exist.
 */
function mpc_image_meta(string $relPath): ?array
{
    $abs = __DIR__ . '/../' . ltrim($relPath, '/');
    if (!is_file($abs)) return null;

    $size = filesize($abs);
    $dims = @getimagesize($abs);

    return [
        'size_bytes' => $size,
        'size_human' => $size > 1048576
            ? round($size / 1048576, 1) . ' MB'
            : round($size / 1024) . ' KB',
        'width'      => $dims[0] ?? null,
        'height'     => $dims[1] ?? null,
    ];
}


/* ------------------------------------------------------------
   5. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    // Capture before state (used for audit diff)
    $before = [];
    foreach ($textKeys as $key) {
        $before[$key] = get_setting($key, '');
    }
    $before['system_logo']    = get_setting('system_logo', '');
    $before['system_favicon'] = get_setting('system_favicon', '');

    try {
        $pdo->beginTransaction();

        $pendingDeletes = [];   // ['logo' => 'assets/img/logo_OLD.png', ...]

        /* ---------- 1. Update text settings ---------- */
        foreach ($textKeys as $key) {
            if (!isset($_POST[$key])) continue;
            $value = trim((string)$_POST[$key]);

            $check = $pdo->prepare("SELECT 1 FROM settings WHERE setting_key = ?");
            $check->execute([$key]);
            if ($check->fetch()) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")
                    ->execute([$value, $key]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")
                    ->execute([$key, $value]);
            }
        }

        /* ---------- 2. Handle file uploads ---------- */
        foreach ($uploadConfig as $fieldName => $config) {

            // Reset to default (deletes custom file)
            $resetKey = 'reset_' . $fieldName;
            if (!empty($_POST[$resetKey])) {
                $currentPath = get_setting($config['setting_key'], '');

                $pdo->prepare("UPDATE settings SET setting_value = '' WHERE setting_key = ?")
                    ->execute([$config['setting_key']]);

                if ($currentPath) {
                    $pendingDeletes[$fieldName] = $currentPath;
                }
                continue;
            }

            // New upload?
            if (!empty($_FILES[$fieldName]['name'])) {
                $newRel = mpc_save_upload(
                    $_FILES[$fieldName],
                    $config,
                    $uploadDir,
                    $config['label']
                );

                if ($newRel) {
                    $currentPath = get_setting($config['setting_key'], '');

                    $check = $pdo->prepare("SELECT 1 FROM settings WHERE setting_key = ?");
                    $check->execute([$config['setting_key']]);
                    if ($check->fetch()) {
                        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")
                            ->execute([$newRel, $config['setting_key']]);
                    } else {
                        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")
                            ->execute([$config['setting_key'], $newRel]);
                    }

                    if ($currentPath && $currentPath !== $newRel) {
                        $pendingDeletes[$fieldName] = $currentPath;
                    }
                }
            }
        }

        $pdo->commit();

        /* ---------- 3. Post-commit: delete old files ---------- */
        foreach ($pendingDeletes as $fieldName => $oldPath) {
            mpc_delete_old_upload($oldPath, $fieldName);
        }

        /* ---------- 4. Audit ---------- */
        $after = [];
        foreach ($textKeys as $key) {
            $after[$key] = get_setting($key, '');
        }
        $after['system_logo']    = get_setting('system_logo', '');
        $after['system_favicon'] = get_setting('system_favicon', '');

        $diff = Audit::diff($before, $after);
        $changedCount = count($diff['new']);

        // File uploads don't appear in $diff unless the path changed
        // (they do change, so if a file was uploaded, it's counted)
        $fileChanged = !empty($pendingDeletes) || $before['system_logo'] !== $after['system_logo'] || $before['system_favicon'] !== $after['system_favicon'];

        if ($changedCount > 0) {
            Audit::updated('settings', 0, $diff['old'], $diff['new']);
            Flash::success(
                'System settings updated successfully. '
                . ($changedCount === 1 ? '1 field changed.' : "{$changedCount} fields changed.")
            );
        } else {
            Flash::warning('No changes were made.');
        }

        redirect('system_settings.php');

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('system_settings.php failed: ' . $ex->getMessage());

        $msg = $ex instanceof RuntimeException
            ? $ex->getMessage()
            : 'Could not save the settings. Please try again or contact IT.';

        Flash::danger($msg);
    }
}


/* ------------------------------------------------------------
   6. LOAD CURRENT VALUES FOR RENDER
   ------------------------------------------------------------ */
$current = [
    'org_name_en'      => get_setting('org_name_en', 'MISRAK POLYTECHNIC COLLEGE'),
    'org_name_am'      => get_setting('org_name_am', 'ምስራቅ ፖሊ ቴክኒክ ኮሌጅ'),
    'org_address'      => get_setting('org_address', 'Addis Ababa, Ethiopia'),
    'org_pobox'        => get_setting('org_pobox', '785'),
    'org_phones'       => get_setting('org_phones', '011-6-61-03-92'),
    'org_email'        => get_setting('org_email', 'misrakptc961@gmail.com'),
    'doc_no_internal'  => get_setting('doc_no_internal', 'OF/MPTC/072'),
    'doc_no_external'  => get_setting('doc_no_external', 'OF/MPTC/058'),
];

// Image metadata
$logoPath    = get_setting('system_logo', '');
$faviconPath = get_setting('system_favicon', '');
$logoMeta    = $logoPath    ? mpc_image_meta($logoPath)    : null;
$faviconMeta = $faviconPath ? mpc_image_meta($faviconPath) : null;


/* ------------------------------------------------------------
   7. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'System Settings';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">System Settings (የስርዓት ቅንብሮች)</h1>
            <p class="muted" style="margin-top: 5px;">Update branding, contact info, and system defaults.</p>
        </div>
        <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to Admin
        </a>
    </div>

    <div class="grid-2" style="grid-template-columns: 2fr 1fr; align-items: start;">

        <!-- ============================================================
             LEFT: SETTINGS FORM
             ============================================================ -->
        <div class="card" style="padding: 30px;">
            <form method="post" enctype="multipart/form-data" class="stack">
                <?= Csrf::field() ?>

                <!-- ============================================================
                     SECTION 1: BRANDING IMAGES
                     ============================================================ -->
                <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    🖼️ Branding Images (የምስል ቅንብሮች)
                </h3>

                <div class="grid-2">
                    <!-- Logo -->
                    <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px dashed #ccc;">
                        <label style="display: block; font-weight: 700; color: var(--mpc-navy); margin-bottom: 10px;">
                            <?= e($uploadConfig['logo']['label']) ?>
                            <span class="muted" style="font-weight: 400; font-size: 12px;"> · <?= e($uploadConfig['logo']['label_am']) ?></span>
                        </label>

                        <div style="display: flex; gap: 15px; align-items: flex-start;">
                            <div style="flex-shrink: 0;">
                                <?php if ($logoPath && $logoMeta): ?>
                                    <img src="<?= get_logo_url() ?>?v=<?= time() ?>"
                                         alt="Current logo"
                                         style="width: 80px; height: 80px; border-radius: 50%; background: #fff; padding: 3px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); object-fit: contain;">
                                    <div style="font-size: 10px; color: #6B7280; text-align: center; margin-top: 4px;">
                                        <?= $logoMeta['width'] && $logoMeta['height'] ? $logoMeta['width'] . '×' . $logoMeta['height'] : '' ?>
                                    </div>
                                <?php else: ?>
                                    <div style="width: 80px; height: 80px; border-radius: 50%; background: #E0F2FE; display: flex; align-items: center; justify-content: center; font-size: 32px; border: 2px dashed var(--mpc-blue);">
                                        🏛️
                                    </div>
                                    <div style="font-size: 10px; color: var(--mpc-muted); text-align: center; margin-top: 4px;">
                                        Default
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div style="flex: 1; min-width: 0;">
                                <input type="file" name="logo" accept=".png,.jpg,.jpeg,.svg"
                                       style="background: #fff; padding: 6px; font-size: 12px; width: 100%; border: 1px solid #ddd; border-radius: 4px;">
                                <small class="muted" style="display: block; margin-top: 5px; font-size: 11px;">
                                    Max <?= round($uploadConfig['logo']['max_bytes'] / 1024 / 1024, 1) ?>MB. PNG, JPG, or SVG.
                                    <?php if ($logoMeta): ?>
                                        <br>Current: <?= e($logoMeta['size_human']) ?>
                                    <?php endif; ?>
                                </small>

                                <?php if ($logoPath): ?>
                                    <label style="display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 11px; color: var(--mpc-red); cursor: pointer;">
                                        <input type="checkbox" name="reset_logo" value="1" style="width: auto; margin: 0; accent-color: var(--mpc-red);">
                                        Reset to default logo
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Favicon -->
                    <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px dashed #ccc;">
                        <label style="display: block; font-weight: 700; color: var(--mpc-navy); margin-bottom: 10px;">
                            <?= e($uploadConfig['favicon']['label']) ?>
                            <span class="muted" style="font-weight: 400; font-size: 12px;"> · <?= e($uploadConfig['favicon']['label_am']) ?></span>
                        </label>

                        <div style="display: flex; gap: 15px; align-items: flex-start;">
                            <div style="flex-shrink: 0;">
                                <?php if ($faviconPath && $faviconMeta): ?>
                                    <img src="<?= get_favicon_url() ?>?v=<?= time() ?>"
                                         alt="Current favicon"
                                         style="width: 45px; height: 45px; background: #fff; border-radius: 4px; padding: 3px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); object-fit: contain;">
                                    <div style="font-size: 10px; color: #6B7280; text-align: center; margin-top: 4px;">
                                        <?= $faviconMeta['width'] && $faviconMeta['height'] ? $faviconMeta['width'] . '×' . $faviconMeta['height'] : '' ?>
                                    </div>
                                <?php else: ?>
                                    <div style="width: 45px; height: 45px; background: #E0F2FE; display: flex; align-items: center; justify-content: center; font-size: 20px; border-radius: 4px; border: 2px dashed var(--mpc-blue);">
                                        ⭐
                                    </div>
                                    <div style="font-size: 10px; color: var(--mpc-muted); text-align: center; margin-top: 4px;">
                                        Default
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div style="flex: 1; min-width: 0;">
                                <input type="file" name="favicon" accept=".png,.ico,.svg"
                                       style="background: #fff; padding: 6px; font-size: 12px; width: 100%; border: 1px solid #ddd; border-radius: 4px;">
                                <small class="muted" style="display: block; margin-top: 5px; font-size: 11px;">
                                    Max <?= round($uploadConfig['favicon']['max_bytes'] / 1024 / 1024, 1) ?>MB. ICO, PNG, or SVG.
                                    <?php if ($faviconMeta): ?>
                                        <br>Current: <?= e($faviconMeta['size_human']) ?>
                                    <?php endif; ?>
                                </small>

                                <?php if ($faviconPath): ?>
                                    <label style="display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 11px; color: var(--mpc-red); cursor: pointer;">
                                        <input type="checkbox" name="reset_favicon" value="1" style="width: auto; margin: 0; accent-color: var(--mpc-red);">
                                        Reset to default favicon
                                    </label>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     SECTION 2: BRANDING TEXT
                     ============================================================ -->
                <h3 style="margin-top: 25px; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    🏛️ College Branding Text (የኮሌጅ ስም)
                </h3>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="org_name_en">
                            College Name (English)
                        </label>
                        <input type="text" name="org_name_en" id="org_name_en" required
                               maxlength="150"
                               value="<?= e($current['org_name_en']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="org_name_am">
                            College Name (Amharic - ስም)
                        </label>
                        <input type="text" name="org_name_am" id="org_name_am" required
                               maxlength="200"
                               value="<?= e($current['org_name_am']) ?>"
                               style="font-family: 'Noto Sans Ethiopic', sans-serif;">
                    </div>
                </div>

                <!-- ============================================================
                     SECTION 3: CONTACT INFORMATION
                     ============================================================ -->
                <h3 style="margin-top: 25px; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    📞 Contact Information (የመገኛ አድራሻ)
                </h3>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="org_address">
                            Physical Address (አድራሻ)
                        </label>
                        <input type="text" name="org_address" id="org_address" required
                               maxlength="255"
                               placeholder="e.g. Addis Ababa, Ethiopia"
                               value="<?= e($current['org_address']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="org_pobox">
                            P.O. Box (ፒ.ኤ.ቁ)
                        </label>
                        <input type="text" name="org_pobox" id="org_pobox"
                               maxlength="50"
                               placeholder="e.g. 785"
                               value="<?= e($current['org_pobox']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="org_phones">
                            Phone Numbers (ስልክ ቁጥር)
                        </label>
                        <input type="text" name="org_phones" id="org_phones"
                               maxlength="200"
                               placeholder="e.g. 011-6-61-03-92, 0911..."
                               value="<?= e($current['org_phones']) ?>">
                        <small class="muted">Separate multiple numbers with commas.</small>
                    </div>
                    <div class="form-group">
                        <label for="org_email">
                            Email Address (ኢሜል)
                        </label>
                        <input type="email" name="org_email" id="org_email" required
                               maxlength="150"
                               placeholder="e.g. misrakptc961@gmail.com"
                               value="<?= e($current['org_email']) ?>">
                    </div>
                </div>

                <!-- ============================================================
                     SECTION 4: DOCUMENT NUMBERING
                     ============================================================ -->
                <h3 style="margin-top: 25px; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--mpc-navy); font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    📄 Default Document Numbers (የሰነድ ቁጥሮች)
                </h3>
                <p class="muted small" style="margin-top: -10px; margin-bottom: 15px; font-size: 12px;">
                    These numbers auto-fill when composing new letters.
                </p>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="doc_no_internal">
                            Internal Memo Doc No.
                        </label>
                        <input type="text" name="doc_no_internal" id="doc_no_internal"
                               maxlength="60"
                               placeholder="e.g. OF/MPTC/072"
                               value="<?= e($current['doc_no_internal']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="doc_no_external">
                            External Letter Doc No.
                        </label>
                        <input type="text" name="doc_no_external" id="doc_no_external"
                               maxlength="60"
                               placeholder="e.g. OF/MPTC/058"
                               value="<?= e($current['doc_no_external']) ?>">
                    </div>
                </div>

                <!-- ============================================================
                     SAVE BUTTON
                     ============================================================ -->
                <div style="margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px; text-align: right;">
                    <button type="submit" class="btn btn-primary btn-lg" style="min-width: 250px;">
                        💾 Save All Settings (ቅንብሮችን አስቀምጥ)
                    </button>
                </div>
            </form>
        </div>

        <!-- ============================================================
             RIGHT: SIDEBAR
             ============================================================ -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- Where These Appear -->
            <div class="card" style="border-left-color: var(--mpc-flame);">
                <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    📍 Where These Appear
                </h3>
                <ul style="font-size: 12px; margin: 0; padding-left: 16px; color: #374151; line-height: 1.9;">
                    <li><strong>College name</strong> — Topbar brand, footer, login page, letters</li>
                    <li><strong>Logo</strong> — Topbar, sidebar, login, letters, PDF exports</li>
                    <li><strong>Favicon</strong> — Browser tab icon on every page</li>
                    <li><strong>Address, PO Box, phones, email</strong> — Footer, letters, official documents</li>
                    <li><strong>Doc numbers</strong> — Pre-filled on new letter forms</li>
                </ul>
            </div>

            <!-- Current Snapshot -->
            <div class="card">
                <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    📋 Current Snapshot
                </h3>
                <ul class="kv" style="font-size: 12px;">
                    <li>
                        <span>Logo</span>
                        <b><?= $logoPath ? 'Custom' : 'Default' ?></b>
                    </li>
                    <li>
                        <span>Favicon</span>
                        <b><?= $faviconPath ? 'Custom' : 'Default' ?></b>
                    </li>
                    <li>
                        <span>College (EN)</span>
                        <b style="max-width: 140px; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($current['org_name_en']) ?>">
                            <?= e($current['org_name_en']) ?>
                        </b>
                    </li>
                    <li>
                        <span>College (AM)</span>
                        <b style="max-width: 140px; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($current['org_name_am']) ?>">
                            <?= e($current['org_name_am']) ?>
                        </b>
                    </li>
                    <li>
                        <span>Phones</span>
                        <b style="max-width: 140px; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($current['org_phones']) ?>">
                            <?= e($current['org_phones']) ?>
                        </b>
                    </li>
                </ul>
            </div>

            <!-- Safety Note -->
            <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF;">
                <h3 style="margin-top: 0; font-size: 15px; color: var(--mpc-navy); border-bottom: 1px solid #BAE6FD; padding-bottom: 10px;">
                    🛡️ Safety Notes
                </h3>
                <ul style="font-size: 12px; margin: 0; padding-left: 16px; color: #374151; line-height: 1.8;">
                    <li>Old image files are deleted after new ones save successfully.</li>
                    <li>"Reset to default" removes custom files from disk.</li>
                    <li>All changes are logged to the audit trail.</li>
                    <li>File uploads are size + format + MIME validated.</li>
                </ul>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>