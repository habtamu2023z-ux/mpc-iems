<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   GLOBAL FOOTER
   ============================================================
   PURPOSE
     Closes the <main> opened by header.php, renders the site
     footer, and loads the four JavaScript modules required by
     every page.

   INCLUDED BY
     Every page after its content:
       include __DIR__ . '/../includes/footer.php';

   CLOSES
     <main> (opened in header.php, both logged-in and guest branches)
     <body> (opened in header.php)
     <html> (opened in header.php)

   LOADS (in strict order)
     1. assets/js/app.js         — AppConfig, EthiopianCalendar,
                                    MPC.util, togglePassword, etc.
     2. assets/js/dashboard.js   — MPC.dashboard (live clock, stats)
     3. assets/js/validation.js  — MPC.validate (form validation)
     4. assets/js/workflow.js    — MPC.workflow (approval modal, SLA)

   ORDER MATTERS
     app.js defines `window.MPC`, `window.AppConfig`, and
     `window.EthiopianCalendar`. The other three files extend
     `window.MPC` and depend on those globals. Loading app.js
     last would cause silent failures ("EthiopianCalendar is
     not defined") in dashboard.js.

   SAFETY
     - Safe to include on guest pages (no auth required)
     - Safe to include on every page without state pollution
     - All get_setting() calls use cached values (no extra queries)
     - greg_to_ethiopian() wrapped in try/catch — 2024+ safety
   ============================================================ */


/* ------------------------------------------------------------
   1. COLLECT FOOTER VARIABLES
   Each falls back to hardcoded defaults if the setting is
   missing from the DB.
   ------------------------------------------------------------ */
$footerOrgEn   = get_setting('org_name_en', 'Misrak Polytechnic College');
$footerOrgAm   = get_setting('org_name_am', 'ምሥራቅ ፖሊ ቴክኒክ ኮሌጅ');
$footerAddress = get_setting('org_address', 'Addis Ababa, Ethiopia');
$footerPobox   = get_setting('org_pobox', '785');
$footerPhones  = get_setting('org_phones', '011-6-61-03-92');
$footerEmail   = get_setting('org_email', 'misrakptc961@gmail.com');

/* Ethiopian year for copyright — safe fallback on conversion failure */
try {
    $footerYearEth = greg_to_ethiopian(date('Y-m-d'))['year'] ?? (int)date('Y') - 7;
} catch (Throwable $e) {
    $footerYearEth = (int)date('Y') - 7;
}
$footerYearGreg = date('Y');

/* Should Letters/Leave quick-links be shown? Students don't have them */
$footerShowStaffLinks = !function_exists('has_role') || !has_role('student');

/* Sidebar state class — the sidebar.php script adds it at runtime,
   but adding it here too means the toggle button is styled correctly
   even before sidebar.php's script runs. */
$footerBodyClass = class_exists('Auth') && Auth::check() ? 'mpc-sidebar-present' : '';
?>
</main>

<!-- ============================================================
     SITE FOOTER
     ============================================================ -->
<footer class="footer no-print" style="background:#1B2A6B;color:#94A3B8;margin-top:50px;border-top:4px solid #4DA6E8;font-family:'Noto Sans Ethiopic',system-ui,sans-serif;">

    <div style="max-width:1180px;margin:0 auto;padding:40px 20px 20px;display:flex;flex-wrap:wrap;justify-content:space-between;gap:30px;">

        <!-- ───── Column 1: Brand & Mission ───── -->
        <div style="flex:1;min-width:250px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:15px;">
                <img src="<?= get_logo_url() ?>"
                     alt="<?= e($footerOrgEn) ?>"
                     style="width:50px;height:50px;background:#fff;border-radius:50%;padding:3px;flex-shrink:0;">
                <div>
                    <h3 style="color:#fff;margin:0;font-size:18px;line-height:1.3;">
                        <?= e($footerOrgAm) ?>
                    </h3>
                    <h4 style="color:#4DA6E8;margin:0;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;line-height:1.3;">
                        <?= e($footerOrgEn) ?>
                    </h4>
                </div>
            </div>
            <p style="font-size:13px;line-height:1.6;margin-bottom:15px;color:#94A3B8;">
                Integrated Enterprise Management System (IEMS). Dedicated to excellence in TVET education,
                administrative efficiency, and transparent workflows.
            </p>
        </div>

        <!-- ───── Column 2: Quick Links ───── -->
        <div style="flex:1;min-width:150px;">
            <h4 style="color:#fff;margin-bottom:15px;font-size:15px;margin-top:0;">
                ፈጣን አገናኞች (Quick Links)
            </h4>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px;font-size:13px;">
                <li>
                    <a href="<?= APP_URL ?>/index.php"
                       style="color:#94A3B8;text-decoration:none;transition:0.2s;"
                       onmouseover="this.style.color='#4DA6E8'"
                       onmouseout="this.style.color='#94A3B8'">
                        ዳሽቦርድ (Dashboard)
                    </a>
                </li>
                <li>
                    <a href="<?= APP_URL ?>/modules/clearance/clearance.php"
                       style="color:#94A3B8;text-decoration:none;transition:0.2s;"
                       onmouseover="this.style.color='#4DA6E8'"
                       onmouseout="this.style.color='#94A3B8'">
                        ክሊራንስ (Clearances)
                    </a>
                </li>
                <?php if ($footerShowStaffLinks): ?>
                    <li>
                        <a href="<?= APP_URL ?>/modules/letters/letters.php"
                           style="color:#94A3B8;text-decoration:none;transition:0.2s;"
                           onmouseover="this.style.color='#4DA6E8'"
                           onmouseout="this.style.color='#94A3B8'">
                            ደብዳቤዎች (Letters)
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/modules/leave/leave_list.php"
                           style="color:#94A3B8;text-decoration:none;transition:0.2s;"
                           onmouseover="this.style.color='#4DA6E8'"
                           onmouseout="this.style.color='#94A3B8'">
                            ፈቃድ (Leave)
                        </a>
                    </li>
                <?php endif; ?>
                <li>
                    <a href="<?= APP_URL ?>/modules/requests/requests.php"
                       style="color:#94A3B8;text-decoration:none;transition:0.2s;"
                       onmouseover="this.style.color='#4DA6E8'"
                       onmouseout="this.style.color='#94A3B8'">
                        ጥያቄዎች (Requests)
                    </a>
                </li>
            </ul>
        </div>

        <!-- ───── Column 3: Contact Info ───── -->
        <div style="flex:1;min-width:250px;">
            <h4 style="color:#fff;margin-bottom:15px;font-size:15px;margin-top:0;">
                አግኙን (Contact Us)
            </h4>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:12px;font-size:13px;color:#94A3B8;">
                <li style="display:flex;gap:10px;align-items:flex-start;">
                    <span aria-hidden="true">📍</span>
                    <span>
                        <?= e($footerAddress) ?>
                        <?php if ($footerPobox !== ''): ?>
                            · P.O. Box <?= e($footerPobox) ?>
                        <?php endif; ?>
                    </span>
                </li>
                <li style="display:flex;gap:10px;align-items:flex-start;">
                    <span aria-hidden="true">📞</span>
                    <span><?= e($footerPhones) ?></span>
                </li>
                <li style="display:flex;gap:10px;align-items:flex-start;">
                    <span aria-hidden="true">✉️</span>
                    <a href="mailto:<?= e($footerEmail) ?>"
                       style="color:#4DA6E8;text-decoration:none;word-break:break-word;">
                        <?= e($footerEmail) ?>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- ───── Bottom Copyright Bar ───── -->
    <div style="border-top:1px solid rgba(255,255,255,0.1);text-align:center;padding:20px;font-size:12px;color:#94A3B8;">
        <p style="margin:0;">
            &copy; <?= (int)$footerYearEth ?> ዓ.ም (<?= (int)$footerYearGreg ?> G.C.)
            <strong style="color:#cbd5e1;"><?= e($footerOrgEn) ?></strong>.
            All rights reserved.
        </p>
        <p style="margin:5px 0 0 0;color:#64748B;font-size:11px;">
            MPC-IEMS Version 3.0
            <span style="opacity:0.5;margin:0 6px;">·</span>
            ለኢትዮጵያ ቴክኒክና ሙያ ትምህርት ደረጃዎች የተዘጋጀ
        </p>
    </div>
</footer>


<!-- ============================================================
     JAVASCRIPT — LOADED IN STRICT ORDER
     ============================================================

     app.js MUST be first. It defines:
       window.AppConfig
       window.EthiopianCalendar
       window.MPC = { config, util, ethCal, ... }
       window.togglePassword / toggleDropdown / dismissAlert

     The three following files each extend window.MPC:
       dashboard.js  → MPC.dashboard
       validation.js → MPC.validate
       workflow.js   → MPC.workflow

     ============================================================ -->

<!-- 1. Core — must be first -->
<script src="<?= APP_URL ?>/assets/js/app.js"></script>

<!-- 2. Dashboard — live clock, notification polling, stats -->
<script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>

<!-- 3. Validation — form field validators, password strength -->
<script src="<?= APP_URL ?>/assets/js/validation.js"></script>

<!-- 4. Workflow — approval modal, SLA countdown, AJAX approve -->
<script src="<?= APP_URL ?>/assets/js/workflow.js"></script>


<!-- ============================================================
     BODY CLASS SYNC (footer scope)
     Ensures sidebar-present class is set even on pages where
     sidebar.php's inline script hasn't yet executed (rare,
     e.g., if sidebar.php is disabled but CSS is loaded).
     ============================================================ -->
<script>
(function () {
    'use strict';

    // Set the sidebar-present marker only if sidebar is in the DOM
    if (document.getElementById('mpcSidebar')) {
        document.body.classList.add('mpc-sidebar-present');
    } else {
        document.body.classList.remove('mpc-sidebar-present');
    }

    // Debug helper — logs once in development
    if (window.console && console.info) {
        var bits = [];
        if (window.AppConfig)               bits.push('AppConfig');
        if (window.EthiopianCalendar)       bits.push('EthiopianCalendar');
        if (window.MPC && window.MPC.validate) bits.push('validate');
        if (window.MPC && window.MPC.workflow) bits.push('workflow');
        if (window.MPC && window.MPC.dashboard) bits.push('dashboard');

        // Only log if something is missing (avoids noise on healthy pages)
        if (bits.length < 5) {
            console.warn('[MPC] Loaded modules: ' + (bits.join(', ') || 'none'));
        }
    }
})();
</script>

</body>
</html>