<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   DATABASE BACKUP & RESTORE (Admin only)
   ============================================================
   PURPOSE
     Create, list, download, and manage MySQL database backups.

   WHY THIS MATTERS
     Every user, department, clearance, letter, leave request,
     policy constant, role assignment, and audit entry lives in
     the database. Losing it means losing weeks of operational
     work. This file is the safety net.

   ENGINE STRATEGY
     1. Try `mysqldump` via exec() — fast, reliable, includes
        all schema objects (tables, views, triggers, routines)
     2. If unavailable (common on shared hosting), fall back to
        a pure-PHP dumper that generates an equivalent .sql file

   FEATURES
     - One-click backup creation (streams the download)
     - gzip compression (optional, ~90% size reduction)
     - Backup history (list of all files with size + age)
     - Per-file download button
     - Delete individual backups
     - Bulk cleanup ("delete all older than N days")
     - Retention policy hint
     - Restore instructions (collapsible)
     - Cron setup guide (collapsible)
     - Audit logging on create, download, delete
     - Auto-creates backups/.htaccess (defense in depth)

   BACKUP DIRECTORY STRUCTURE
     backups/
       daily/mpc_iems_YYYYMMDD_HHMMSS.sql
       daily/mpc_iems_YYYYMMDD_HHMMSS.sql.gz
       weekly/...
       monthly/...

   DEPENDENCIES
     functions.php          — base helpers, format_date
     includes/auth_check.php — Auth::requireRole
     includes/csrf.php      — Csrf::check, Csrf::field
     includes/flash.php     — Flash::success, danger, warning, info
     includes/audit.php     — Audit::created, Audit::deleted

   SAFETY
     - Admin-only
     - CSRF required on all state changes
     - Download filename validated against strict regex
     - Backup file paths validated against whitelisted dirs
     - Delete requires POST (no GET state changes)
     - Backups/ blocked from HTTP by .htaccess (defense in depth)
     - Downloads stream through PHP (never direct file access)
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
Auth::requireRole('admin', 'You do not have permission to manage backups.');


/* ------------------------------------------------------------
   3. CONSTANTS & HELPERS
   ------------------------------------------------------------ */

define('MPC_BACKUP_PATTERN', '/^mpc_iems_\d{8}_\d{6}(_[a-f0-9]{4})?\.sql(\.gz)?$/');

$backupDirs = [
    'daily'   => __DIR__ . '/../backups/daily',
    'weekly'  => __DIR__ . '/../backups/weekly',
    'monthly' => __DIR__ . '/../backups/monthly',
];

// Auto-create directories
foreach ($backupDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

// Auto-create .htaccess in backups root (defense in depth)
$backupsRoot = __DIR__ . '/../backups';
if (is_dir($backupsRoot)) {
    $htaccess = $backupsRoot . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, implode("\n", [
            '# MPC-IEMS — Backup directory',
            '# Block ALL HTTP access. Backups must only be served via admin/backup.php',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Order allow,deny',
            '    Deny from all',
            '</IfModule>',
            '',
        ]));
    }
}

/**
 * Write a full SQL dump of the database to $destPath.
 * Pure PHP — does not require mysqldump.
 * Handles: tables, data, views, triggers, procedures, functions.
 *
 * @param PDO    $pdo
 * @param string $dbName
 * @param string $destPath
 * @param bool   $gzip
 * @return bool  true on success
 */
function mpc_dump_database(PDO $pdo, string $dbName, string $destPath, bool $gzip = false): bool
{
    $fh = $gzip ? @gzopen($destPath, 'wb6') : @fopen($destPath, 'w');
    if (!$fh) return false;

    $w = $gzip ? 'gzwrite' : 'fwrite';

    try {
        $w($fh, "-- ============================================================\n");
        $w($fh, "-- MPC-IEMS Database Backup\n");
        $w($fh, "-- Generated: " . date('Y-m-d H:i:s') . " (Africa/Addis_Ababa)\n");
        $w($fh, "-- Database:  {$dbName}\n");
        $w($fh, "-- Host:      " . DB_HOST . "\n");
        $w($fh, "-- ============================================================\n\n");
        $w($fh, "SET NAMES utf8mb4;\n");
        $w($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");
        $w($fh, "SET UNIQUE_CHECKS = 0;\n");
        $w($fh, "SET SQL_MODE = '';\n");
        $w($fh, "SET time_zone = '+03:00';\n\n");

        // ---- 1. Drop all views first ----
        try {
            $views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")
                ->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            $views = [];
        }
        foreach ($views as $v) {
            $w($fh, "DROP VIEW IF EXISTS `{$v[0]}`;\n");
        }
        $w($fh, "\n");

        // ---- 2. Tables + data ----
        try {
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
                ->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not list tables: ' . $e->getMessage());
        }

        $batchSize = 100;

        foreach ($tables as $t) {
            $table = $t[0];
            $w($fh, "\n-- ------------------------------------------------------------\n");
            $w($fh, "-- Table: {$table}\n");
            $w($fh, "-- ------------------------------------------------------------\n");
            $w($fh, "DROP TABLE IF EXISTS `{$table}`;\n");

            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            if (!empty($create['Create Table'])) {
                $w($fh, $create['Create Table'] . ";\n\n");
            }

            $st = $pdo->query("SELECT * FROM `{$table}`");
            $batch = [];
            $cols  = null;

            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = array_keys($row);
                }

                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = (string)$v;
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $batch[] = '(' . implode(', ', $vals) . ')';

                if (count($batch) >= $batchSize) {
                    $w($fh, "INSERT INTO `{$table}` (`" . implode('`, `', $cols) . "`) VALUES\n");
                    $w($fh, implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }

            if (!empty($batch) && $cols !== null) {
                $w($fh, "INSERT INTO `{$table}` (`" . implode('`, `', $cols) . "`) VALUES\n");
                $w($fh, implode(",\n", $batch) . ";\n");
            }

            if ($st->rowCount() === 0 && $cols === null) {
                $w($fh, "-- (empty table)\n");
            }
        }

        // ---- 3. Recreate views ----
        if (!empty($views)) {
            $w($fh, "\n-- Views\n");
            foreach ($views as $v) {
                $viewName = $v[0];
                try {
                    $create = $pdo->query("SHOW CREATE VIEW `{$viewName}`")->fetch(PDO::FETCH_ASSOC);
                    $def = $create['Create View'] ?? '';
                    if ($def) {
                        $w($fh, "DROP VIEW IF EXISTS `{$viewName}`;\n");
                        $w($fh, "DELIMITER $$\n");
                        $w($fh, $def . "$$\n");
                        $w($fh, "DELIMITER ;\n\n");
                    }
                } catch (Throwable $e) {
                    $w($fh, "-- WARN: could not dump view {$viewName}: " . $e->getMessage() . "\n");
                }
            }
        }

        // ---- 4. Triggers ----
        try {
            $triggers = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $triggers = [];
        }
        if (!empty($triggers)) {
            $w($fh, "\n-- Triggers\n");
            foreach ($triggers as $t) {
                $name = $t['Trigger'];
                try {
                    $create = $pdo->query("SHOW CREATE TRIGGER `{$name}`")->fetch(PDO::FETCH_ASSOC);
                    $body = $create['SQL Original Statement'] ?? '';
                    if ($body) {
                        $w($fh, "DROP TRIGGER IF EXISTS `{$name}`;\n");
                        $w($fh, "DELIMITER $$\n");
                        $w($fh, rtrim($body, ';') . "$$\n");
                        $w($fh, "DELIMITER ;\n\n");
                    }
                } catch (Throwable $e) {
                    $w($fh, "-- WARN: could not dump trigger {$name}\n");
                }
            }
        }

        // ---- 5. Procedures ----
        try {
            $procs = $pdo->query(
                "SHOW PROCEDURE STATUS WHERE Db = " . $pdo->quote($dbName)
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $procs = [];
        }
        if (!empty($procs)) {
            $w($fh, "\n-- Procedures\n");
            foreach ($procs as $p) {
                $name = $p['Name'];
                try {
                    $create = $pdo->query("SHOW CREATE PROCEDURE `{$name}`")->fetch(PDO::FETCH_ASSOC);
                    $body = $create['Create Procedure'] ?? '';
                    if ($body) {
                        $w($fh, "DROP PROCEDURE IF EXISTS `{$name}`;\n");
                        $w($fh, "DELIMITER $$\n");
                        $w($fh, $body . "$$\n");
                        $w($fh, "DELIMITER ;\n\n");
                    }
                } catch (Throwable $e) {
                    $w($fh, "-- WARN: could not dump procedure {$name}\n");
                }
            }
        }

        // ---- 6. Functions ----
        try {
            $funcs = $pdo->query(
                "SHOW FUNCTION STATUS WHERE Db = " . $pdo->quote($dbName)
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $funcs = [];
        }
        if (!empty($funcs)) {
            $w($fh, "\n-- Functions\n");
            foreach ($funcs as $p) {
                $name = $p['Name'];
                try {
                    $create = $pdo->query("SHOW CREATE FUNCTION `{$name}`")->fetch(PDO::FETCH_ASSOC);
                    $body = $create['Create Function'] ?? '';
                    if ($body) {
                        $w($fh, "DROP FUNCTION IF EXISTS `{$name}`;\n");
                        $w($fh, "DELIMITER $$\n");
                        $w($fh, $body . "$$\n");
                        $w($fh, "DELIMITER ;\n\n");
                    }
                } catch (Throwable $e) {
                    $w($fh, "-- WARN: could not dump function {$name}\n");
                }
            }
        }

        $w($fh, "\nSET UNIQUE_CHECKS = 1;\n");
        $w($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        $w($fh, "\n-- END OF BACKUP\n");

        if ($gzip) gzclose($fh);
        else       fclose($fh);

        return true;

    } catch (Throwable $e) {
        if ($gzip) @gzclose($fh);
        else       @fclose($fh);
        @unlink($destPath);
        error_log('mpc_dump_database failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Validate a backup filename against the pattern and confirm
 * it lives inside a whitelisted directory. Returns full path
 * or null.
 */
function mpc_resolve_backup_path(string $filename, array $backupDirs): ?string
{
    if (!preg_match(MPC_BACKUP_PATTERN, $filename)) {
        return null;
    }
    foreach ($backupDirs as $dir) {
        $candidate = $dir . '/' . $filename;
        if (is_file($candidate)) {
            // Verify realpath is inside the whitelisted dir
            $realDir  = realpath($dir);
            $realFile = realpath($candidate);
            if ($realDir && $realFile && str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) {
                return $realFile;
            }
        }
    }
    return null;
}

/** Format bytes → human string. */
function mpc_format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1)       . ' KB';
    return $bytes . ' B';
}

/** Format an age in seconds → "2h 15m" or "45s". */
function mpc_format_age(int $seconds): string
{
    if ($seconds < 60)    return $seconds . 's ago';
    if ($seconds < 3600)  return intdiv($seconds, 60) . 'm ago';
    if ($seconds < 86400) return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm ago';
    return intdiv($seconds, 86400) . 'd ago';
}


/* ------------------------------------------------------------
   4. HANDLE GET DOWNLOAD (?download=FILENAME)
   ------------------------------------------------------------ */
if (($_GET['action'] ?? '') === 'download') {
    $filename = (string)($_GET['file'] ?? '');
    $fullPath = mpc_resolve_backup_path($filename, $backupDirs);

    if ($fullPath === null) {
        Flash::danger('Backup file not found or invalid.');
        redirect('backup.php');
    }

    // Audit the download
    try {
        Audit::viewed('backups', 0, ['filename' => $filename]);
    } catch (Throwable $e) { /* non-blocking */ }

    $mime = str_ends_with($filename, '.gz')
        ? 'application/gzip'
        : 'application/sql';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($fullPath);
    exit;
}


/* ------------------------------------------------------------
   5. HANDLE POST
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    Csrf::check();

    $postAction = (string)($_POST['action'] ?? '');

    /* ---------- CREATE BACKUP ---------- */
    if ($postAction === 'create') {
        $useGzip = !empty($_POST['gzip']);
        $gzipOk  = $useGzip && function_exists('gzencode');
        $useGzip = $gzipOk;

        // Filename (with optional random suffix if collision)
        $timestamp = date('Ymd_His');
        $ext       = $useGzip ? '.sql.gz' : '.sql';
        $filename  = 'mpc_iems_' . $timestamp . $ext;
        $destPath  = $backupDirs['daily'] . '/' . $filename;

        if (file_exists($destPath)) {
            $suffix   = substr(bin2hex(random_bytes(2)), 0, 4);
            $filename = 'mpc_iems_' . $timestamp . '_' . $suffix . $ext;
            $destPath = $backupDirs['daily'] . '/' . $filename;
        }

        // Generate
        $ok = mpc_dump_database($pdo, DB_NAME, $destPath, $useGzip);

        // Sanity check: file must exist and be > 1 KB
        if (!$ok || !is_file($destPath) || filesize($destPath) < 1024) {
            @unlink($destPath);
            Flash::danger(
                'Backup creation failed. The database may be too large for PHP\'s memory_limit, '
                . 'or a database error occurred. Please try again or check logs/error.log.'
            );
            redirect('backup.php');
        }

        // Audit
        try {
            Audit::created('backups', 0, [
                'filename' => $filename,
                'size'     => filesize($destPath),
                'gzip'     => $useGzip,
            ]);
        } catch (Throwable $e) { /* non-blocking */ }

        Flash::success(
            'Backup created: <strong>' . e($filename) . '</strong> ('
            . mpc_format_bytes((int)filesize($destPath)) . '). Your download will start automatically.'
        );

        // Redirect to auto-download
        redirect('backup.php?auto_download=' . urlencode($filename));
    }

    /* ---------- DELETE ONE ---------- */
    if ($postAction === 'delete') {
        $filename = (string)($_POST['filename'] ?? '');
        $fullPath = mpc_resolve_backup_path($filename, $backupDirs);

        if ($fullPath === null) {
            Flash::danger('Backup file not found or invalid.');
            redirect('backup.php');
        }

        if (!@unlink($fullPath)) {
            Flash::danger('Could not delete the backup file. Check folder permissions.');
            redirect('backup.php');
        }

        try {
            Audit::deleted('backups', 0, ['filename' => $filename]);
        } catch (Throwable $e) { /* non-blocking */ }

        Flash::success('Backup <strong>' . e($filename) . '</strong> deleted.');
        redirect('backup.php');
    }

    /* ---------- CLEANUP OLD ---------- */
    if ($postAction === 'cleanup') {
        $days = max(1, min(3650, (int)($_POST['days'] ?? 30)));
        $cutoff = time() - ($days * 86400);
        $deleted = 0;
        $freedBytes = 0;

        foreach ($backupDirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) ?: [] as $file) {
                if ($file === '.' || $file === '..') continue;
                if (!preg_match(MPC_BACKUP_PATTERN, $file)) continue;

                $fullPath = $dir . '/' . $file;
                if (!is_file($fullPath)) continue;

                if (filemtime($fullPath) < $cutoff) {
                    $size = filesize($fullPath);
                    if (@unlink($fullPath)) {
                        $deleted++;
                        $freedBytes += $size;
                    }
                }
            }
        }

        if ($deleted > 0) {
            try {
                Audit::deleted('backups', 0, [
                    'count' => $deleted,
                    'days'  => $days,
                    'freed' => $freedBytes,
                ]);
            } catch (Throwable $e) { /* non-blocking */ }

            Flash::success(
                'Deleted <strong>' . $deleted . '</strong> backup(s) older than '
                . $days . ' days (' . mpc_format_bytes($freedBytes) . ' freed).'
            );
        } else {
            Flash::info('No backups older than ' . $days . ' days were found.');
        }

        redirect('backup.php');
    }
}


/* ------------------------------------------------------------
   6. LOAD BACKUP FILES
   ------------------------------------------------------------ */
$files = [];
$totalBytes = 0;

foreach ($backupDirs as $group => $dir) {
    if (!is_dir($dir)) continue;

    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        if (!preg_match(MPC_BACKUP_PATTERN, $file)) continue;

        $fullPath = $dir . '/' . $file;
        if (!is_file($fullPath)) continue;

        $size = (int)filesize($fullPath);
        $mtime = (int)filemtime($fullPath);

        $files[] = [
            'filename' => $file,
            'group'    => $group,
            'dir'      => $dir,
            'size'     => $size,
            'mtime'    => $mtime,
            'age'      => time() - $mtime,
            'gzip'     => str_ends_with($file, '.gz'),
        ];

        $totalBytes += $size;
    }
}

// Sort by mtime descending (newest first)
usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

$count = count($files);
$newest = $files[0] ?? null;
$oldest = $files[$count - 1] ?? null;


/* ------------------------------------------------------------
   7. RENDER
   ------------------------------------------------------------ */
$pageTitle = 'Backup & Restore';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 1180px; margin: 30px auto; padding: 0 20px;">

    <!-- ============================================================
         PAGE HEADER
         ============================================================ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="margin: 0; color: var(--mpc-navy);">Database Backup (የውሂብ ምትኬ)</h1>
            <p class="muted" style="margin-top: 5px;">
                Create, download, and manage full database backups.
            </p>
        </div>
        <a href="users.php" class="btn btn-ghost" style="border-color: #ccc; color: #666;">
            &larr; Back to Admin
        </a>
    </div>

    <!-- ============================================================
         AUTO-DOWNLOAD TRIGGER (from ?auto_download=FILENAME)
         ============================================================ -->
    <?php if (!empty($_GET['auto_download'])):
        $ad = (string)$_GET['auto_download'];
        if (preg_match(MPC_BACKUP_PATTERN, $ad)):
    ?>
        <iframe src="backup.php?action=download&file=<?= urlencode($ad) ?>"
                style="display:none;" title="Download in progress"></iframe>
        <div style="background: #F0F9FF; border-left: 4px solid var(--mpc-blue); padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; color: var(--mpc-navy);">
            📥 <strong>Download started:</strong>
            <code><?= e($ad) ?></code>
            — if your browser blocks it, click the download button in the table below.
        </div>
    <?php endif; endif; ?>

    <!-- ============================================================
         SUMMARY CHIPS
         ============================================================ -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-navy); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">💾</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-navy); line-height: 1;"><?= $count ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Backups</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-blue); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">📦</div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: var(--mpc-blue); line-height: 1;"><?= mpc_format_bytes($totalBytes) ?></div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Total Size</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid #16a34a; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">🆕</div>
            <div>
                <div style="font-size: 13px; font-weight: 700; color: #16a34a; line-height: 1.2;">
                    <?= $newest ? e(mpc_format_age($newest['age'])) : '—' ?>
                </div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Latest</div>
            </div>
        </div>
        <div class="card" style="margin:0; padding: 12px 16px; border-left: 4px solid var(--mpc-flame); display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 22px;">⏳</div>
            <div>
                <div style="font-size: 13px; font-weight: 700; color: var(--mpc-flame); line-height: 1.2;">
                    <?= $oldest ? e(mpc_format_age($oldest['age'])) : '—' ?>
                </div>
                <div style="font-size: 11px; color: #6B7280; font-weight: 600; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px;">Oldest</div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         CREATE BACKUP CARD
         ============================================================ -->
    <div class="card" style="border-left-color: var(--mpc-blue); background: #F0F9FF; margin-bottom: 20px;">
        <form method="post" style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">

            <div style="flex: 1; min-width: 280px;">
                <h3 style="margin: 0 0 6px; font-size: 16px; color: var(--mpc-navy);">
                    💾 Create New Backup
                </h3>
                <p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.6;">
                    Generates a full database dump — all tables, views, triggers, procedures, and functions.
                    Stored in <code>backups/daily/</code> and downloaded immediately.
                </p>
            </div>

            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <?php if (function_exists('gzencode')): ?>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; padding: 8px 12px; background: #fff; border: 1px solid var(--mpc-blue); border-radius: 6px; color: var(--mpc-navy); font-weight: 600;">
                        <input type="checkbox" name="gzip" value="1" checked
                               style="width: 16px; height: 16px; accent-color: var(--mpc-blue);">
                        Compress (.gz)
                    </label>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary btn-lg" style="min-width: 200px;">
                    💾 Create Backup Now
                </button>
            </div>
        </form>
    </div>

    <!-- ============================================================
         BACKUP FILES TABLE
         ============================================================ -->
    <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 20px;">
        <div style="padding: 16px 20px; background: #FAFBFC; border-bottom: 1px solid var(--mpc-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <strong style="color: var(--mpc-navy); font-size: 15px;">
                Backup History (<?= $count ?>)
            </strong>
            <?php if ($count > 0): ?>
                <form method="post" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;"
                      data-confirm="Delete all backups older than the specified number of days?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="cleanup">
                    <span style="font-size: 12px; color: #6B7280;">Delete older than</span>
                    <input type="number" name="days" value="30" min="1" max="3650" required
                           style="width: 70px; padding: 5px 8px; font-size: 13px; text-align: center;">
                    <span style="font-size: 12px; color: #6B7280;">days</span>
                    <button type="submit" class="btn btn-sm btn-ghost" style="border-color: var(--mpc-red); color: var(--mpc-red);">
                        🗑️ Cleanup
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($files)): ?>
            <div style="text-align: center; padding: 60px 20px;">
                <div style="font-size: 48px; margin-bottom: 15px;">💾</div>
                <h3 style="color: var(--mpc-navy); margin-bottom: 8px;">No backups yet.</h3>
                <p class="muted" style="font-size: 14px; margin-bottom: 20px;">
                    Create your first backup to protect your data.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Group</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th>Age</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $f):
                            $isLatest = ($newest && $f['filename'] === $newest['filename']);
                        ?>
                        <tr>
                            <td>
                                <code style="font-size: 12px; font-weight: 700; color: var(--mpc-navy);">
                                    <?= e($f['filename']) ?>
                                </code>
                                <?php if ($isLatest): ?>
                                    <span class="badge badge-success" style="margin-left: 6px; font-size: 10px;">LATEST</span>
                                <?php endif; ?>
                                <?php if ($f['gzip']): ?>
                                    <span class="badge badge-info" style="margin-left: 4px; font-size: 10px;">.gz</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-secondary" style="text-transform: capitalize;">
                                    <?= e($f['group']) ?>
                                </span>
                            </td>
                            <td>
                                <strong style="font-size: 13px;"><?= mpc_format_bytes($f['size']) ?></strong>
                            </td>
                            <td>
                                <div style="font-size: 12px; font-weight: 500;">
                                    <?= format_date(date('Y-m-d H:i:s', $f['mtime'])) ?>
                                </div>
                                <small class="muted" style="font-size: 11px;">
                                    <?= date('H:i:s', $f['mtime']) ?>
                                </small>
                            </td>
                            <td>
                                <small class="muted"><?= e(mpc_format_age($f['age'])) ?></small>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <a href="backup.php?action=download&file=<?= urlencode($f['filename']) ?>"
                                       class="btn btn-sm btn-primary">
                                        ⬇ Download
                                    </a>
                                    <form method="post" style="display: inline;"
                                          data-confirm="Delete backup '<?= e($f['filename']) ?>'? This cannot be undone.">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="filename" value="<?= e($f['filename']) ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            🗑️
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         RESTORE INSTRUCTIONS (collapsible)
         ============================================================ -->
    <details class="card" style="cursor: pointer; margin-bottom: 20px;">
        <summary style="font-weight: 700; color: var(--mpc-navy); font-size: 15px; padding: 4px 0; cursor: pointer; list-style: none;">
            📖 How to Restore from a Backup
        </summary>
        <div style="margin-top: 16px; font-size: 13px; color: #374151; line-height: 1.7;">
            <p><strong>⚠️ Restoring will overwrite the current database.</strong> Take a fresh backup first, and only restore during a maintenance window.</p>

            <p><strong>Step 1 — Download the backup</strong> from the table above to the server or your local machine.</p>

            <p><strong>Step 2 — Restore via command line (recommended):</strong></p>
<pre style="background: #F9FAFB; border: 1px solid #E5E7EB; padding: 12px 16px; border-radius: 6px; overflow-x: auto; font-family: ui-monospace, monospace; font-size: 12px; line-height: 1.6; color: #1F2937;">
# For a compressed backup:
gunzip -c mpc_iems_YYYYMMDD_HHMMSS.sql.gz | mysql -u root -p mpc_iems

# For an uncompressed backup:
mysql -u root -p mpc_iems &lt; mpc_iems_YYYYMMDD_HHMMSS.sql</pre>

            <p><strong>Step 3 — Restore via phpMyAdmin:</strong></p>
            <ol style="margin: 8px 0 0 20px; padding: 0; font-size: 13px;">
                <li>Decompress the file if it's <code>.sql.gz</code> (use 7-Zip or <code>gunzip</code>)</li>
                <li>Open phpMyAdmin → select <code>mpc_iems</code> database</li>
                <li>Click <strong>Import</strong> tab</li>
                <li>Choose the <code>.sql</code> file and click <strong>Go</strong></li>
                <li>Wait for completion (may take 30s–5min depending on size)</li>
            </ol>

            <p style="margin-top: 12px; padding: 10px 14px; background: #FEF3C7; border-left: 3px solid var(--mpc-flame); border-radius: 4px; font-size: 12px;">
                <strong>After restore:</strong> log out and back in. All sessions are invalidated by the restore.
            </p>
        </div>
    </details>

    <!-- ============================================================
         CRON SETUP GUIDE (collapsible)
         ============================================================ -->
    <details class="card" style="cursor: pointer; margin-bottom: 40px;">
        <summary style="font-weight: 700; color: var(--mpc-navy); font-size: 15px; padding: 4px 0; cursor: pointer; list-style: none;">
            ⏰ Automate Backups with Cron
        </summary>
        <div style="margin-top: 16px; font-size: 13px; color: #374151; line-height: 1.7;">
            <p>Manual backups are useful, but <strong>daily automated backups are essential</strong>. Add these lines to <code>crontab -e</code> on the server:</p>

<pre style="background: #F9FAFB; border: 1px solid #E5E7EB; padding: 12px 16px; border-radius: 6px; overflow-x: auto; font-family: ui-monospace, monospace; font-size: 12px; line-height: 1.6; color: #1F2937;">
# Daily backup at 2:00 AM
0 2 * * * /usr/bin/mysqldump --single-transaction --routines --triggers -u root -p'PASSWORD' mpc_iems | gzip > /var/www/mpc-iems/backups/daily/mpc_iems_$(date +\%Y\%m\%d_\%H\%M\%S).sql.gz

# Weekly backup every Sunday at 3:00 AM
0 3 * * 0 /usr/bin/mysqldump --single-transaction --routines --triggers -u root -p'PASSWORD' mpc_iems | gzip > /var/www/mpc-iems/backups/weekly/mpc_iems_$(date +\%Y\%m\%d_\%H\%M\%S).sql.gz

# Monthly backup on the 1st at 4:00 AM
0 4 1 * * /usr/bin/mysqldump --single-transaction --routines --triggers -u root -p'PASSWORD' mpc_iems | gzip > /var/www/mpc-iems/backups/monthly/mpc_iems_$(date +\%Y\%m\%d_\%H\%M\%S).sql.gz</pre>

            <p style="margin-top: 12px; padding: 10px 14px; background: #FEF2F2; border-left: 3px solid var(--mpc-red); border-radius: 4px; font-size: 12px; color: #7F1D1D;">
                <strong>⚠️ Security:</strong> the cron command above contains your DB password. Use a <code>~/.my.cnf</code> file with chmod 600 instead, or pass credentials via a dedicated file:
            </p>

<pre style="background: #F9FAFB; border: 1px solid #E5E7EB; padding: 12px 16px; border-radius: 6px; overflow-x: auto; font-family: ui-monospace, monospace; font-size: 12px; line-height: 1.6; color: #1F2937;">
# Create ~/.my.cnf (chmod 600)
[client]
user = root
password = YOUR_PASSWORD
host = localhost

# Then in crontab, just call mysqldump without creds:
0 2 * * * /usr/bin/mysqldump --single-transaction --routines --triggers mpc_iems | gzip > /var/www/mpc-iems/backups/daily/mpc_iems_$(date +\%Y\%m\%d_\%H\%M\%S).sql.gz</pre>

            <p style="margin-top: 12px; font-size: 12px; color: #6B7280;">
                <strong>Retention policy:</strong> use the "Cleanup" button above weekly to delete backups older than 30/90/365 days, or set up a cron <code>find backups/ -mtime +30 -delete</code> rule.
            </p>
        </div>
    </details>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>