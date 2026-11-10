/**
 * ============================================================
 * MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
 * DASHBOARD JAVASCRIPT
 * ============================================================
 * Live enhancements for the main dashboard (index.php) and
 * the notification bell in the topbar.
 *
 * LOADED BY
 *   index.php (recommended)
 *     <script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>
 *   OR footer.php (if you want live notification badge everywhere)
 *     <script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>
 *
 * PUBLIC API (callable from anywhere):
 *   MPC.dashboard.refresh()          - Force refresh now
 *   MPC.dashboard.stopPolling()      - Stop all timers
 *   MPC.dashboard.state              - Inspect internal state
 *
 * SERVER DEPENDENCIES (optional — file works without them):
 *   GET /api/stats.php
 *     → { "ok": true,
 *         "stats": {
 *           "open_clearances": 2, "pending_steps": 3,
 *           "sent_letters": 12, "active_users": 13,
 *           "open_requests": 8, "completed_clearances": 5,
 *           "unread_notifications": 4
 *         } }
 *
 *   GET /api/notifications.php?count=1
 *     → { "ok": true, "unread": 4 }
 *
 *   If either endpoint is missing (404) or fails to return the
 *   expected JSON, this file silently disables the relevant
 *   poller and logs one console.info message. It never throws
 *   or shows UI errors.
 * ============================================================
 */

(function () {
    'use strict';

    /* Capture the script's own URL synchronously, before init.
       Used to derive APP_URL without depending on a PHP constant. */
    const SCRIPT_SRC = (document.currentScript && document.currentScript.src) || '';


    /* ============================================================
       1. CONFIGURATION
       ============================================================ */
    const CONFIG = {
        clockIntervalMs:        30000,   // 30s — update clock display
        notifIntervalMs:        60000,   // 60s — poll notification badge
        statsIntervalMs:        60000,   // 60s — poll dashboard stats
        fetchTimeoutMs:          8000,   // 8s  — abort slow requests
        numberAnimMs:             500,   // 500ms count-up animation
    };

    const LABELS_FOR_STAT_KEY = {
        open_clearances:      ['Open Clearances'],
        completed_clearances: ['Completed Clearances'],
        pending_steps:        ['Pending Steps'],
        sent_letters:         ['Letters Sent', 'Sent Letters'],
        active_users:         ['Active Users'],
        open_requests:        ['Open HR Requests', 'Open Requests'],
    };


    /* ============================================================
       2. STATE
       ============================================================ */
    const state = {
        // URLs (set on init, once base URL is known)
        urls: {
            stats:  '',
            notify: '',
        },

        // DOM references
        statEls:     {},     // { 'open_clearances': DOMElement, ... }
        clockEl:     null,   // The date/time widget container
        notifBell:   null,   // <a class="notif-bell">
        notifBadge:  null,   // <span class="notif-badge">

        // Timers (kept so stopPolling() can clear them)
        clockTimer:  null,
        notifTimer:  null,
        statsTimer:  null,

        // API availability — null = unknown, true/false = known
        statsApiAvailable:  null,
        notifApiAvailable:  null,

        // Suppress repeated console messages
        loggedStatsUnavailable:  false,
        loggedNotifUnavailable:  false,
    };


    /* ============================================================
       3. URL DISCOVERY
       ============================================================ */

    /**
     * Derive APP_URL from:
     *   1. this script's own src (works if loaded via <script src>)
     *   2. the stylesheet link href (always present after DOM ready)
     *   3. window.location.origin (last resort)
     *
     * Returns a base URL WITHOUT trailing slash, e.g.:
     *   "http://localhost/mpc-iems"
     *   "https://misrak.edu.et"
     */
    function getBaseUrl() {
        if (SCRIPT_SRC) {
            const base = SCRIPT_SRC.replace(/\/assets\/js\/[^/?#]+(\?.*)?$/, '');
            if (base && base !== SCRIPT_SRC) return base;
        }

        const cssLink = document.querySelector('link[href*="/assets/css/style.css"]');
        if (cssLink) {
            return cssLink.href.replace(/\/assets\/css\/style\.css(\?.*)?$/, '');
        }

        return window.location.origin;
    }


    /* ============================================================
       4. UTILITIES
       ============================================================ */

    /**
     * Fetch JSON with a timeout. Returns null on any failure
     * (404, network down, invalid JSON, timeout) — never throws.
     */
    async function fetchJson(url, timeoutMs = CONFIG.fetchTimeoutMs) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), timeoutMs);

        try {
            const res = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: ctrl.signal,
            });

            clearTimeout(timer);

            if (!res.ok) return { __httpError: res.status };

            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                return { __parseError: true };
            }
        } catch (e) {
            clearTimeout(timer);
            return { __networkError: true };
        }
    }

    /**
     * Count-up animation for a numeric element.
     */
    function animateNumber(el, target, durationMs = CONFIG.numberAnimMs) {
        if (!el) return;

        const current = parseInt(String(el.textContent).replace(/[^\d-]/g, ''), 10) || 0;
        const tgt     = parseInt(target, 10);
        if (isNaN(tgt) || current === tgt) {
            if (!isNaN(tgt)) el.textContent = String(tgt);
            return;
        }

        const start = performance.now();
        const delta = tgt - current;

        const step = now => {
            const p = Math.min((now - start) / durationMs, 1);
            const eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
            el.textContent = String(Math.round(current + delta * eased));
            if (p < 1) requestAnimationFrame(step);
        };

        requestAnimationFrame(step);
    }


    /* ============================================================
       5. DOM DISCOVERY — find what we're going to update
       ============================================================ */

    /**
     * Locate the dashboard stat cards by matching their label text.
     * Works with the current index.php structure:
     *
     *   <div class="card">
     *     <div>🎓</div>
     *     <div>
     *       <div style="font-size:28px...">2</div>
     *       <div style="font-size:13px...">Open Clearances</div>
     *     </div>
     *   </div>
     *
     * The numeric element is identified as: the largest-font,
     * text-only div inside the card. Robust against minor
     * markup changes.
     */
    function findStatElements() {
        const map = {};
        const cards = document.querySelectorAll('.card');

        cards.forEach(card => {
            const cardText = card.textContent || '';

            // Check every known stat label against this card
            for (const [key, labels] of Object.entries(LABELS_FOR_STAT_KEY)) {
                if (map[key]) continue;
                if (!labels.some(label => cardText.includes(label))) continue;

                // Find the number: largest text-only div in the card
                let best = null;
                let bestSize = 0;

                card.querySelectorAll('div').forEach(d => {
                    if (d.children.length > 0) return;                    // must be leaf
                    if (!/^\d+$/.test(d.textContent.trim())) return;      // must be numeric
                    const size = parseInt(getComputedStyle(d).fontSize, 10) || 0;
                    if (size > bestSize) {
                        best = d;
                        bestSize = size;
                    }
                });

                if (best) {
                    map[key] = best;
                    best.setAttribute('data-stat-key', key); // For debugging
                }
                break;
            }
        });

        return map;
    }

    /**
     * Locate the header date/time widget on the dashboard.
     * The current index.php uses this structure:
     *
     *   <div style="...">
     *     <div>ህዳር 20, 2017 ዓ.ም</div>
     *     <div>Friday, October 30, 2026 (2:23 PM)</div>
     *   </div>
     *
     * Detection: any div whose direct children include one that
     * contains "ዓ.ም" AND one that looks like a Gregorian date with
     * a time "(H:MM AM|PM)".
     */
    function findClockWidget() {
        const timePattern = /\(\s*\d{1,2}:\d{2}\s*(?:AM|PM|am|pm)\s*\)/;

        for (const el of document.querySelectorAll('div')) {
            if (el.children.length < 2) continue;

            const kids = Array.from(el.children);
            const hasTime  = kids.some(c => timePattern.test(c.textContent || ''));
            const hasEth   = kids.some(c => /ዓ\s*\.\s*ም/.test(c.textContent || ''));

            if (hasTime && hasEth) return el;
        }
        return null;
    }

    /**
     * Find the notification bell in the header.
     */
    function findNotifElements() {
        state.notifBell  = document.querySelector('.notif-bell');
        state.notifBadge = document.querySelector('.notif-badge');
    }


    /* ============================================================
       6. CLOCK UPDATER
       ============================================================ */

    function updateClock() {
        if (!state.clockEl) return;

        const now = new Date();

        // Gregorian date in long form: "Friday, October 30, 2026"
        const gregDate = now.toLocaleDateString('en-US', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        });

        // Time: "2:23 PM"
        const time = now.toLocaleTimeString('en-US', {
            hour: 'numeric', minute: '2-digit', hour12: true,
        });

        // Update each child of the widget
        Array.from(state.clockEl.children).forEach(child => {
            if (/ዓ\s*\.\s*ም/.test(child.textContent || '')) {
                // Ethiopian date child — recompute using app.js's converter
                if (window.EthiopianCalendar && typeof window.EthiopianCalendar.format === 'function') {
                    const ethStr = window.EthiopianCalendar.format(now);
                    if (ethStr) child.textContent = ethStr;
                }
            } else {
                // Gregorian + time child
                child.textContent = `${gregDate} (${time})`;
            }
        });
    }


    /* ============================================================
       7. NOTIFICATION BADGE UPDATER
       ============================================================ */

    function updateNotifBadge(count) {
        if (!state.notifBell) return;

        count = parseInt(count, 10) || 0;

        if (count === 0) {
            if (state.notifBadge) {
                state.notifBadge.remove();
                state.notifBadge = null;
            }
            return;
        }

        if (!state.notifBadge) {
            // Create badge on first non-zero update
            const span = document.createElement('span');
            span.className = 'notif-badge';
            span.textContent = String(count);
            state.notifBell.appendChild(span);
            state.notifBadge = span;

            // Little bounce on first appearance
            try {
                span.animate([
                    { transform: 'scale(0.4)', opacity: 0 },
                    { transform: 'scale(1.2)', opacity: 1 },
                    { transform: 'scale(1)',   opacity: 1 },
                ], { duration: 400, easing: 'cubic-bezier(0.34, 1.56, 0.64, 1)' });
            } catch (e) { /* older browsers ignore */ }
            return;
        }

        if (state.notifBadge.textContent !== String(count)) {
            state.notifBadge.textContent = String(count);

            // Pulse to signal change
            try {
                state.notifBadge.animate([
                    { transform: 'scale(1.4)' },
                    { transform: 'scale(1)' },
                ], { duration: 300 });
            } catch (e) { /* noop */ }
        }
    }


    /* ============================================================
       8. STAT UPDATER
       ============================================================ */

    function applyStats(stats) {
        if (!stats || typeof stats !== 'object') return;

        Object.entries(state.statEls).forEach(([key, el]) => {
            const val = stats[key];
            if (val !== undefined && val !== null && !isNaN(parseInt(val, 10))) {
                animateNumber(el, val);
            }
        });

        if (stats.unread_notifications !== undefined) {
            updateNotifBadge(stats.unread_notifications);
        }
    }


    /* ============================================================
       9. POLLERS
       ============================================================ */

    async function pollNotifications() {
        if (!state.notifBell) return;
        if (state.notifApiAvailable === false) return;

        const json = await fetchJson(state.urls.notify + '?count=1');

        if (!json || json.__httpError || json.__parseError || json.__networkError) {
            // Disable polling, log once
            state.notifApiAvailable = false;
            if (!state.loggedNotifUnavailable) {
                state.loggedNotifUnavailable = true;
                if (window.console && console.info) {
                    console.info('[MPC Dashboard] Notification API not available at ' +
                        state.urls.notify + ' — live bell polling disabled.');
                }
            }
            return;
        }

        state.notifApiAvailable = true;

        if (json.ok === true && typeof json.unread === 'number') {
            updateNotifBadge(json.unread);
        }
    }

    async function pollStats() {
        if (state.statsApiAvailable === false) return;
        if (Object.keys(state.statEls).length === 0) return;

        const json = await fetchJson(state.urls.stats);

        if (!json || json.__httpError || json.__parseError || json.__networkError) {
            state.statsApiAvailable = false;
            if (!state.loggedStatsUnavailable) {
                state.loggedStatsUnavailable = true;
                if (window.console && console.info) {
                    console.info('[MPC Dashboard] Stats API not available at ' +
                        state.urls.stats + ' — live stat polling disabled.');
                }
            }
            return;
        }

        state.statsApiAvailable = true;

        if (json.ok === true && json.stats) {
            applyStats(json.stats);
        }
    }


    /* ============================================================
       10. VISIBILITY HANDLER
       ============================================================ */

    function initVisibilityHandler() {
        if (typeof document.addEventListener !== 'function') return;

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible') return;

            // User came back to the tab — refresh immediately
            updateClock();
            pollNotifications();
            pollStats();
        });
    }


    /* ============================================================
       11. INITIALIZATION
       ============================================================ */

    function init() {
        // Derive URLs
        const base = getBaseUrl();
        state.urls.stats  = base + '/api/stats.php';
        state.urls.notify = base + '/api/notifications.php';

        // Find DOM elements
        state.statEls    = findStatElements();
        state.clockEl    = findClockWidget();
        findNotifElements();

        // 1. Clock — runs everywhere the widget exists
        if (state.clockEl) {
            updateClock();
            state.clockTimer = setInterval(updateClock, CONFIG.clockIntervalMs);
        }

        // 2. Notification polling — only if the bell is on the page
        if (state.notifBell) {
            // Do one probe now
            pollNotifications().then(() => {
                if (state.notifApiAvailable) {
                    state.notifTimer = setInterval(pollNotifications, CONFIG.notifIntervalMs);
                }
            });
        }

        // 3. Stat polling — only if stat cards are on the page
        if (Object.keys(state.statEls).length > 0) {
            pollStats().then(() => {
                if (state.statsApiAvailable) {
                    state.statsTimer = setInterval(pollStats, CONFIG.statsIntervalMs);
                }
            });
        }

        // 4. Refresh when tab regains focus
        initVisibilityHandler();
    }


    /* ============================================================
       12. PUBLIC API
       ============================================================ */
    window.MPC = window.MPC || {};

    window.MPC.dashboard = {
        /** Force refresh all live data right now. */
        refresh() {
            updateClock();
            return Promise.all([
                pollNotifications(),
                pollStats(),
            ]);
        },

        /** Stop all polling timers (useful for tests or debugging). */
        stopPolling() {
            clearInterval(state.clockTimer);
            clearInterval(state.notifTimer);
            clearInterval(state.statsTimer);
            state.clockTimer = state.notifTimer = state.statsTimer = null;
        },

        /** Inspect internal state (read-only). */
        get state() {
            return state;
        },
    };


    /* ============================================================
       13. BOOT
       ============================================================ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();