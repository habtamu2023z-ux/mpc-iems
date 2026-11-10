/**
 * ============================================================
 * MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
 * WORKFLOW APPROVAL ENHANCEMENTS
 * ============================================================
 * Loaded by includes/footer.php AFTER app.js and validation.js:
 *   <script src="<?= APP_URL ?>/assets/js/app.js"></script>
 *   <script src="<?= APP_URL ?>/assets/js/validation.js"></script>
 *   <script src="<?= APP_URL ?>/assets/js/workflow.js"></script>
 *
 * BEHAVIOR
 *   ✓ Replaces window.prompt() in quickAction() with a modal
 *   ✓ Enhances all inline approve/reject forms with AJAX
 *   ✓ Adds live SLA countdown to elements with data-sla-due
 *   ✓ Animates [data-progress] bars on update
 *   ✓ Collapses long comment chains (>3 comments)
 *   ✓ Polls api/stats.php for pending-step badge (graceful)
 *
 * PUBLIC API
 *   MPC.workflow.showApprovalModal(opts)     → Promise<{action, comment}|null>
 *   MPC.workflow.approve(stepId, action, opts) → Promise<{ok, error?}>
 *   MPC.workflow.refresh()                    → Refresh visible widgets
 *   MPC.workflow.setAPIs({step, clearance})   → Override API endpoints
 *
 * OPTIONAL SERVER DEPENDENCIES
 *   POST /api/approve_step.php
 *     body: { csrf, step_id, action, comment }
 *     →   { ok: true, new_status: "in_progress", new_step: 2 }
 *     →   { ok: false, error: "You do not have permission" }
 *
 *   POST /api/approve_clearance.php
 *     body: { csrf, clearance_id, step_order, action, comment }
 *     →   { ok: true, new_status: "in_progress", new_step: 2 }
 *
 *   GET  /api/stats.php   (existing dashboard endpoint)
 *     →   { ok: true, stats: { pending_steps: 5, ... } }
 *
 *   If any endpoint returns 404 or fails, that feature falls
 *   back to standard form POST (existing behavior, unchanged).
 * ============================================================
 */

(function () {
    'use strict';

    if (window.__mpcWorkflowInitialized) return;
    window.__mpcWorkflowInitialized = true;


    /* ============================================================
       1. CONFIGURATION
       ============================================================ */
    const CONFIG = {
        // API endpoints — overridable via MPC.workflow.setAPIs()
        api: {
            step:      '',          // set on init from baseUrl
            clearance: '',
            stats:     '',
        },

        // Polling
        slaTickMs:       30000,    // 30s — SLA countdown refresh
        pendingPollMs:   60000,    // 60s — header pending badge poll

        // Behavior
        fallbackToForm:  true,     // If AJAX fails, allow normal form submit
        collapseAfter:   3,        // comments beyond this are hidden behind "Show all"

        // Toast lifetime (for inline success messages)
        toastMs:         3500,
    };


    /* ============================================================
       2. CSS INJECTION
       ============================================================ */
    (function injectStyles() {
        if (document.getElementById('mpc-workflow-styles')) return;

        const style = document.createElement('style');
        style.id = 'mpc-workflow-styles';
        style.textContent = `
            /* ========== MODAL ========== */
            .mpc-modal-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.55);
                backdrop-filter: blur(2px);
                -webkit-backdrop-filter: blur(2px);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                z-index: 10000;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.2s ease;
            }
            .mpc-modal-backdrop.is-open {
                opacity: 1;
                pointer-events: auto;
            }

            .mpc-modal {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 25px 50px rgba(15, 23, 42, 0.35);
                width: 100%;
                max-width: 480px;
                overflow: hidden;
                transform: scale(0.94);
                transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            }
            .mpc-modal-backdrop.is-open .mpc-modal {
                transform: scale(1);
            }

            .mpc-modal-header {
                padding: 20px 24px 16px;
                border-bottom: 1px solid #E5E7EB;
            }
            .mpc-modal-header h3 {
                margin: 0;
                color: #1B2A6B;
                font-size: 17px;
                font-weight: 700;
                line-height: 1.4;
            }
            .mpc-modal-header .mpc-modal-subtitle {
                margin-top: 4px;
                font-size: 13px;
                color: #6B7280;
                line-height: 1.5;
            }
            .mpc-modal-header .mpc-modal-subtitle code {
                background: #EEF2FF;
                color: #1B2A6B;
                padding: 1px 6px;
                border-radius: 4px;
                font-size: 12px;
            }

            .mpc-modal-body {
                padding: 20px 24px;
            }
            .mpc-modal-body label {
                display: block;
                font-size: 13px;
                font-weight: 700;
                color: #1B2A6B;
                margin-bottom: 6px;
            }
            .mpc-modal-body textarea {
                width: 100%;
                min-height: 90px;
                padding: 10px 12px;
                border: 1.5px solid #E5E7EB;
                border-radius: 6px;
                font-family: inherit;
                font-size: 14px;
                line-height: 1.5;
                resize: vertical;
                color: #1F2937;
                transition: border-color 0.15s, box-shadow 0.15s;
            }
            .mpc-modal-body textarea:focus {
                outline: none;
                border-color: #4DA6E8;
                box-shadow: 0 0 0 4px rgba(77, 166, 232, 0.15);
            }
            .mpc-modal-body .mpc-modal-hint {
                margin-top: 8px;
                font-size: 11px;
                color: #6B7280;
                line-height: 1.5;
            }

            .mpc-modal-body .mpc-modal-error {
                display: none;
                margin-top: 10px;
                padding: 10px 12px;
                background: #FEF2F2;
                border-left: 3px solid #DC2626;
                border-radius: 4px;
                font-size: 13px;
                color: #991B1B;
                font-weight: 500;
                line-height: 1.5;
            }
            .mpc-modal-body .mpc-modal-error.is-visible {
                display: block;
            }

            .mpc-modal-footer {
                padding: 16px 24px 20px;
                display: flex;
                gap: 8px;
                justify-content: flex-end;
                flex-wrap: wrap;
            }

            .mpc-modal-footer .mpc-btn {
                padding: 9px 18px;
                border-radius: 6px;
                border: 1px solid transparent;
                font-family: inherit;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                line-height: 1.4;
                transition: background 0.15s, border-color 0.15s;
                white-space: nowrap;
            }
            .mpc-modal-footer .mpc-btn:disabled {
                opacity: 0.55;
                cursor: not-allowed;
            }
            .mpc-modal-footer .mpc-btn-ghost {
                background: transparent;
                border-color: #E5E7EB;
                color: #4B5563;
            }
            .mpc-modal-footer .mpc-btn-ghost:hover:not(:disabled) {
                background: #F9FAFB;
                border-color: #D1D5DB;
            }
            .mpc-modal-footer .mpc-btn-success {
                background: #16A34A;
                color: #fff;
            }
            .mpc-modal-footer .mpc-btn-success:hover:not(:disabled) {
                background: #15803D;
            }
            .mpc-modal-footer .mpc-btn-danger {
                background: #DC2626;
                color: #fff;
            }
            .mpc-modal-footer .mpc-btn-danger:hover:not(:disabled) {
                background: #B91C1C;
            }

            /* ========== SLA BADGE ========== */
            .mpc-sla {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 8px;
                border-radius: 9999px;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.3px;
                text-transform: uppercase;
                white-space: nowrap;
                line-height: 1.2;
            }
            .mpc-sla-ok       { background: #DCFCE7; color: #166534; }
            .mpc-sla-warning  { background: #FEF3C7; color: #92400E; }
            .mpc-sla-overdue  { background: #FEE2E2; color: #991B1B; }
            .mpc-sla-done     { background: #F3F4F6; color: #374151; }

            /* ========== PROGRESS BAR ANIMATION ========== */
            .mpc-progress-animate {
                transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* ========== INLINE TOAST ========== */
            .mpc-toast {
                position: fixed;
                bottom: 24px;
                right: 24px;
                max-width: 360px;
                padding: 12px 16px;
                background: #1B2A6B;
                color: #fff;
                border-radius: 8px;
                box-shadow: 0 8px 24px rgba(15, 23, 42, 0.35);
                font-size: 13px;
                font-weight: 500;
                line-height: 1.5;
                z-index: 10001;
                display: flex;
                align-items: center;
                gap: 10px;
                transform: translateY(20px);
                opacity: 0;
                transition: transform 0.25s, opacity 0.25s;
                pointer-events: none;
            }
            .mpc-toast.is-visible {
                transform: translateY(0);
                opacity: 1;
            }
            .mpc-toast .mpc-toast-icon {
                font-size: 18px;
                flex-shrink: 0;
            }
            .mpc-toast-success { border-left: 4px solid #16A34A; }
            .mpc-toast-error   { border-left: 4px solid #DC2626; }

            /* ========== COMMENT CHAIN ========== */
            .mpc-comment-hidden {
                display: none;
            }
            .mpc-comment-toggle {
                display: inline-block;
                margin: 8px 0;
                padding: 4px 10px;
                background: #E0F2FE;
                color: #1B2A6B;
                border: none;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.15s;
                font-family: inherit;
            }
            .mpc-comment-toggle:hover {
                background: #BAE0FB;
            }

            /* ========== PENDING BADGE (header) ========== */
            .mpc-pending-badge {
                display: inline-flex;
                align-items: center;
                gap: 3px;
                padding: 2px 8px;
                background: #F59E0B;
                color: #fff;
                border-radius: 9999px;
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                margin-left: 6px;
                line-height: 1.3;
            }

            @media (max-width: 600px) {
                .mpc-modal-footer {
                    flex-direction: column-reverse;
                }
                .mpc-modal-footer .mpc-btn {
                    width: 100%;
                    justify-content: center;
                }
                .mpc-toast {
                    left: 16px;
                    right: 16px;
                    bottom: 16px;
                    max-width: none;
                }
            }
        `;
        document.head.appendChild(style);
    })();


    /* ============================================================
       3. UTILITIES
       ============================================================ */
    const util = {

        escapeHtml(s) {
            return String(s ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        csrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta && meta.content) return meta.content;
            const input = document.querySelector('input[name="csrf"]');
            return input ? input.value : '';
        },

        async fetchJson(url, opts = {}) {
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), opts.timeout || 8000);

            try {
                const res = await fetch(url, {
                    method: opts.method || 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: Object.assign(
                        {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        opts.headers || {}
                    ),
                    body: opts.body,
                    signal: ctrl.signal,
                });

                clearTimeout(timer);

                if (!res.ok) return { __httpError: res.status };

                const text = await res.text();
                try {
                    return JSON.parse(text);
                } catch {
                    return { __parseError: true, text };
                }
            } catch {
                clearTimeout(timer);
                return { __networkError: true };
            }
        },

        /** POST form-encoded body. */
        async postJson(url, data) {
            const body = new URLSearchParams();
            Object.entries(data).forEach(([k, v]) => {
                if (v !== null && v !== undefined) body.append(k, String(v));
            });

            return util.fetchJson(url, {
                method: 'POST',
                body,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
        },

        /** Derive APP_URL from current script src or a stylesheet link. */
        baseUrl() {
            const s = document.currentScript && document.currentScript.src;
            if (s) {
                const base = s.replace(/\/assets\/js\/[^/?#]+(\?.*)?$/, '');
                if (base && base !== s) return base;
            }
            const css = document.querySelector('link[href*="/assets/css/style.css"]');
            if (css) return css.href.replace(/\/assets\/css\/style\.css(\?.*)?$/, '');
            return window.location.origin;
        },

        /** Humanize a duration in ms to "2h 15m" or "45s". */
        humanDuration(ms) {
            const abs = Math.abs(ms);
            const s = Math.floor(abs / 1000);
            const m = Math.floor(s / 60);
            const h = Math.floor(m / 60);
            const d = Math.floor(h / 24);

            if (d > 0) return `${d}d ${h % 24}h`;
            if (h > 0) return `${h}h ${m % 60}m`;
            if (m > 0) return `${m}m`;
            return `${s}s`;
        },

        /** Create and show a toast. */
        toast(msg, kind = 'success') {
            const el = document.createElement('div');
            el.className = `mpc-toast mpc-toast-${kind}`;
            el.innerHTML =
                `<span class="mpc-toast-icon">${kind === 'error' ? '⚠' : '✓'}</span>` +
                `<span>${util.escapeHtml(msg)}</span>`;
            document.body.appendChild(el);

            // Trigger transition
            requestAnimationFrame(() => el.classList.add('is-visible'));

            setTimeout(() => {
                el.classList.remove('is-visible');
                setTimeout(() => el.remove(), 300);
            }, CONFIG.toastMs);
        },
    };


    /* ============================================================
       4. APPROVAL MODAL
       ============================================================ */

    /**
     * Show the approval modal. Returns a Promise that resolves with:
     *   { action: 'approve' | 'reject', comment: string }
     * or null if the user cancels.
     *
     * Options:
     *   title       "Approve Leave Request"
     *   subtitle    "Ref: REQ-LEAVE-... · Step 2: HR"
     *   defaultAction  'approve' | 'reject' | null
     *   requireComment boolean (for reject)
     */
    function showApprovalModal(opts = {}) {
        return new Promise(resolve => {
            const backdrop = document.createElement('div');
            backdrop.className = 'mpc-modal-backdrop';
            backdrop.innerHTML = `
                <div class="mpc-modal" role="dialog" aria-modal="true">
                    <div class="mpc-modal-header">
                        <h3>${util.escapeHtml(opts.title || 'Review Request')}</h3>
                        ${opts.subtitle ? `<div class="mpc-modal-subtitle">${opts.subtitle}</div>` : ''}
                    </div>
                    <div class="mpc-modal-body">
                        <label for="mpc-modal-comment">Comment (optional)</label>
                        <textarea id="mpc-modal-comment" placeholder="Add a comment..." rows="4"></textarea>
                        <div class="mpc-modal-hint">Your comment will be recorded in the audit log.</div>
                        <div class="mpc-modal-error" role="alert"></div>
                    </div>
                    <div class="mpc-modal-footer">
                        <button type="button" class="mpc-btn mpc-btn-ghost" data-action="cancel">Cancel</button>
                        <button type="button" class="mpc-btn mpc-btn-danger" data-action="reject">✗ Reject</button>
                        <button type="button" class="mpc-btn mpc-btn-success" data-action="approve">✓ Approve</button>
                    </div>
                </div>
            `;

            document.body.appendChild(backdrop);
            requestAnimationFrame(() => backdrop.classList.add('is-open'));

            const textarea = backdrop.querySelector('#mpc-modal-comment');
            const errorBox = backdrop.querySelector('.mpc-modal-error');

            const close = (result) => {
                backdrop.classList.remove('is-open');
                document.removeEventListener('keydown', onKey, true);
                setTimeout(() => {
                    backdrop.remove();
                    resolve(result);
                }, 220);
            };

            const submit = (action) => {
                const comment = textarea.value.trim();

                // Reject requires a comment (business rule)
                if (action === 'reject' && !comment) {
                    errorBox.textContent = 'Rejection requires a comment (ውድቅ ለማድረግ አስተያየት ያስፈልጋል)';
                    errorBox.classList.add('is-visible');
                    textarea.focus();
                    return;
                }
                close({ action, comment });
            };

            backdrop.querySelector('[data-action="cancel"]').addEventListener('click', () => close(null));
            backdrop.querySelector('[data-action="reject"]').addEventListener('click', () => submit('reject'));
            backdrop.querySelector('[data-action="approve"]').addEventListener('click', () => submit('approve'));

            // Click backdrop = cancel
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop) close(null);
            });

            // Keyboard: Escape closes, Ctrl+Enter submits (approve by default)
            const onKey = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close(null);
                } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    submit('approve');
                }
            };
            document.addEventListener('keydown', onKey, true);

            // Focus the textarea
            setTimeout(() => textarea.focus(), 100);
        });
    }


    /* ============================================================
       5. QUICK ACTION (replaces leave_list.php's inline version)
       ============================================================ */

    /**
     * Called by leave_list.php's `<button onclick="quickAction(...)">`.
     * We override the inline definition (which uses window.prompt) with
     * our richer modal.
     *
     * After the user submits, we POST to leave_view.php (same as the
     * original implementation) — no API needed today. If you later
     * build api/approve_step.php, see `attemptAjaxApprove()` below.
     */
    window.quickAction = async function (stepId, action, requestId) {
        const refText = `Step #${stepId}`;

        const result = await showApprovalModal({
            title: action === 'approve' ? 'Approve Request' : 'Reject Request',
            subtitle: refText,
            defaultAction: action,
        });

        if (!result) return;

        // Force the action the user originally clicked (approve vs reject button)
        const finalAction = (result.action === 'reject' || action === 'reject') ? 'reject' : 'approve';

        // Try AJAX first (silently falls back if API missing)
        const ajax = await attemptAjaxApprove({
            type: 'step',
            stepId: stepId,
            action: finalAction,
            comment: result.comment,
        });

        if (ajax && ajax.ok) {
            util.toast(`Step ${finalAction === 'approve' ? 'approved' : 'rejected'} successfully`);
            // Reload current page to show updated state
            setTimeout(() => window.location.reload(), 600);
            return;
        }

        if (ajax && ajax.error) {
            util.toast(ajax.error, 'error');
            return;
        }

        // No API — fall back to form POST (original behavior)
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'leave_view.php';
        form.style.display = 'none';
        form.innerHTML = `
            <input type="hidden" name="csrf" value="${util.escapeHtml(util.csrfToken())}">
            <input type="hidden" name="step_id" value="${stepId}">
            <input type="hidden" name="action" value="${finalAction}">
            <input type="hidden" name="comment" value="${util.escapeHtml(result.comment)}">
            <input type="hidden" name="return_to" value="leave_list.php">
        `;
        document.body.appendChild(form);
        form.submit();
    };


    /* ============================================================
       6. AJAX APPROVE (with graceful fallback)
       ============================================================ */

    /**
     * Attempt an AJAX approve/reject. Returns:
     *   { ok: true, data }   on success
     *   { ok: false, error } on server-reported error
     *   null                 if API unavailable (caller should fall back to form)
     */
    async function attemptAjaxApprove({ type, stepId, clearanceId, stepOrder, action, comment }) {
        const endpoint = type === 'clearance' ? CONFIG.api.clearance : CONFIG.api.step;
        if (!endpoint) return null;

        const payload = {
            csrf: util.csrfToken(),
            action: action,
            comment: comment || '',
        };

        if (type === 'clearance') {
            payload.clearance_id = clearanceId;
            payload.step_order = stepOrder;
        } else {
            payload.step_id = stepId;
        }

        const res = await util.postJson(endpoint, payload);

        if (!res || res.__httpError || res.__networkError || res.__parseError) {
            // API not available — tell caller to fall back
            return null;
        }

        if (res.ok === true) {
            return { ok: true, data: res };
        }

        return { ok: false, error: res.error || 'The server rejected this action.' };
    }


    /* ============================================================
       7. FORM AUTO-ENHANCEMENT (inline approve/reject forms)
       ============================================================ */

    /**
     * Detects approval forms and intercepts them for AJAX.
     * Falls back to normal form submission if API is unavailable.
     */
    function enhanceApprovalForms() {
        document.querySelectorAll('form').forEach(form => {
            if (form.__mpcApprovalBound) return;
            if (form.dataset.noWorkflow === 'true') return;

            // Detection: form has a submit button with name="action"
            // whose values include "approve" and/or "reject"
            const actionButtons = form.querySelectorAll('[name="action"]');
            const hasApprove = Array.from(actionButtons).some(b => b.value === 'approve');
            const hasReject  = Array.from(actionButtons).some(b => b.value === 'reject');
            if (!hasApprove && !hasReject) return;

            form.__mpcApprovalBound = true;

            // Detect type (clearance vs step)
            const clearanceIdInput = form.querySelector('input[name="clearance_id"]');
            const stepIdInput      = form.querySelector('input[name="step_id"]');
            const stepOrderInput   = form.querySelector('input[name="step_order"]');
            const isClearance      = !!clearanceIdInput;

            form.addEventListener('submit', async (evt) => {
                const submitter = evt.submitter;
                if (!submitter || !['approve', 'reject'].includes(submitter.value)) return;
                if (!CONFIG.fallbackToForm && !CONFIG.api.step && !CONFIG.api.clearance) return;

                // Try AJAX first
                const res = await attemptAjaxApprove({
                    type: isClearance ? 'clearance' : 'step',
                    stepId: stepIdInput ? parseInt(stepIdInput.value, 10) : null,
                    clearanceId: clearanceIdInput ? parseInt(clearanceIdInput.value, 10) : null,
                    stepOrder: stepOrderInput ? parseInt(stepOrderInput.value, 10) : null,
                    action: submitter.value,
                    comment: (form.querySelector('input[name="comment"], textarea[name="comment"]') || {}).value || '',
                });

                if (res === null) {
                    // API not available — let the form submit normally
                    return;
                }

                // We got an AJAX response — prevent form POST
                evt.preventDefault();
                evt.stopPropagation();

                if (res.ok) {
                    util.toast(`Step ${submitter.value === 'approve' ? 'approved' : 'rejected'} successfully`);
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    util.toast(res.error, 'error');
                }
            }, true); // capture phase — before app.js's loader
        });
    }


    /* ============================================================
       8. SLA COUNTDOWN
       ============================================================ */

    /**
     * For every element with [data-sla-due], render a live countdown.
     * Updates every CONFIG.slaTickMs (30s).
     */
    function renderSLA(el) {
        const dueStr = el.dataset.slaDue;
        if (!dueStr) return;

        // Parse "YYYY-MM-DD HH:MM:SS" (MySQL format) as local time
        const due = new Date(dueStr.replace(' ', 'T'));
        if (isNaN(due.getTime())) return;

        const now = Date.now();
        const ms = due.getTime() - now;
        const isDone = el.dataset.slaDone === 'true';

        el.classList.remove('mpc-sla-ok', 'mpc-sla-warning', 'mpc-sla-overdue', 'mpc-sla-done');

        if (isDone) {
            el.classList.add('mpc-sla', 'mpc-sla-done');
            el.textContent = '✓ Completed';
            return;
        }

        if (ms < 0) {
            el.classList.add('mpc-sla', 'mpc-sla-overdue');
            el.textContent = `⚠ Overdue ${util.humanDuration(ms)}`;
        } else if (ms < 24 * 3600 * 1000) {
            el.classList.add('mpc-sla', 'mpc-sla-warning');
            el.textContent = `⏰ ${util.humanDuration(ms)} left`;
        } else {
            el.classList.add('mpc-sla', 'mpc-sla-ok');
            el.textContent = `⏰ ${util.humanDuration(ms)} left`;
        }
    }

    function initSLA() {
        const els = document.querySelectorAll('[data-sla-due]');
        if (els.length === 0) return;

        els.forEach(el => {
            el.classList.add('mpc-sla');
            renderSLA(el);
        });

        // Refresh every 30s
        setInterval(() => {
            document.querySelectorAll('[data-sla-due]').forEach(renderSLA);
        }, CONFIG.slaTickMs);
    }


    /* ============================================================
       9. PROGRESS BAR ANIMATION
       ============================================================ */

    /**
     * Update a progress bar element with [data-progress-current] and
     * [data-progress-total]. Used by clearance_view.php.
     *
     * HTML: <div data-progress-current="2" data-progress-total="5"></div>
     *       <div class="progress-bar-fill" data-progress-fill></div>
     */
    function initProgressBars() {
        document.querySelectorAll('[data-progress-current]').forEach(container => {
            const current = parseInt(container.dataset.progressCurrent, 10) || 0;
            const total   = parseInt(container.dataset.progressTotal, 10) || 0;
            if (total <= 0) return;

            const fill = container.querySelector('[data-progress-fill]');
            if (!fill) return;

            const pct = Math.min(100, (current / total) * 100);
            fill.classList.add('mpc-progress-animate');
            // Defer to next frame so transition triggers
            requestAnimationFrame(() => {
                fill.style.width = pct + '%';
            });
        });
    }


    /* ============================================================
       10. COMMENT CHAIN COLLAPSE
       ============================================================ */

    /**
     * For any container with [data-comment-chain], hide comments
     * beyond CONFIG.collapseAfter and add a "Show all" button.
     */
    function initCommentChains() {
        document.querySelectorAll('[data-comment-chain]').forEach(chain => {
            if (chain.__mpcCollapsed !== undefined) return;
            chain.__mpcCollapsed = false;

            const items = chain.querySelectorAll('[data-comment-item]');
            if (items.length <= CONFIG.collapseAfter) return;

            // Hide items beyond the limit
            items.forEach((item, i) => {
                if (i >= CONFIG.collapseAfter) {
                    item.classList.add('mpc-comment-hidden');
                }
            });

            // Add "Show all N" button
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mpc-comment-toggle';
            btn.textContent = `Show all ${items.length} comments`;
            chain.appendChild(btn);

            btn.addEventListener('click', () => {
                const hidden = chain.querySelectorAll('.mpc-comment-hidden');
                if (hidden.length > 0) {
                    hidden.forEach(el => el.classList.remove('mpc-comment-hidden'));
                    btn.textContent = 'Show fewer';
                } else {
                    items.forEach((item, i) => {
                        if (i >= CONFIG.collapseAfter) {
                            item.classList.add('mpc-comment-hidden');
                        }
                    });
                    btn.textContent = `Show all ${items.length} comments`;
                }
            });
        });
    }


    /* ============================================================
       11. PENDING-STEP HEADER BADGE (optional, needs api/stats.php)
       ============================================================ */

    let pendingPollTimer = null;

    async function pollPendingCount() {
        if (!CONFIG.api.stats) return;
        // Only run if there's a header bell to attach to
        const nav = document.querySelector('.nav');
        if (!nav) return;

        const res = await util.fetchJson(CONFIG.api.stats);
        if (!res || res.__httpError || res.__networkError || res.__parseError) {
            // Disable silent
            if (pendingPollTimer) { clearInterval(pendingPollTimer); pendingPollTimer = null; }
            return;
        }

        const count = (res.stats && parseInt(res.stats.pending_steps, 10)) || 0;

        // Find or create the badge in the header nav
        let badge = nav.querySelector('.mpc-pending-badge');
        if (count <= 0) {
            if (badge) badge.remove();
            return;
        }

        if (!badge) {
            // Attach to the "Admin" link or the last nav link
            const target = nav.querySelector('a.active') || nav.querySelector('a:last-of-type');
            if (!target) return;
            badge = document.createElement('span');
            badge.className = 'mpc-pending-badge';
            badge.title = 'Pending workflow steps you can approve';
            target.appendChild(badge);
        }
        badge.textContent = `⚠ ${count}`;
    }

    function startPendingPolling() {
        if (!CONFIG.api.stats) return;
        pollPendingCount();
        pendingPollTimer = setInterval(pollPendingCount, CONFIG.pendingPollMs);
    }


    /* ============================================================
       12. PUBLIC API
       ============================================================ */

    /**
     * Refresh all workflow widgets on the page (SLA, progress, comments).
     */
    function refreshAll() {
        document.querySelectorAll('[data-sla-due]').forEach(renderSLA);
        initProgressBars();
        initCommentChains();
    }

    function setAPIs(endpoints) {
        if (endpoints.step)      CONFIG.api.step      = endpoints.step;
        if (endpoints.clearance) CONFIG.api.clearance = endpoints.clearance;
        if (endpoints.stats)     CONFIG.api.stats     = endpoints.stats;
    }


    /* ============================================================
       13. INIT
       ============================================================ */

    function init() {
        const base = util.baseUrl();
        CONFIG.api.step      = base + '/api/approve_step.php';
        CONFIG.api.clearance = base + '/api/approve_clearance.php';
        CONFIG.api.stats     = base + '/api/stats.php';

        enhanceApprovalForms();
        initSLA();
        initProgressBars();
        initCommentChains();
        startPendingPolling();

        // Re-run enhancement on DOM changes (AJAX-loaded forms)
        const observer = new MutationObserver(() => {
            enhanceApprovalForms();
            initCommentChains();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }


    /* ============================================================
       14. EXPORT
       ============================================================ */
    window.MPC = window.MPC || {};
    window.MPC.workflow = {
        showApprovalModal,
        refresh: refreshAll,
        setAPIs,
        config: CONFIG,

        // Expose for testing / advanced use
        attemptAjaxApprove,
        util,
    };


    /* ============================================================
       15. BOOT
       ============================================================ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();