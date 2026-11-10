/**
 * ============================================================
 * MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
 * CORE JAVASCRIPT
 * ============================================================
 * Loaded by includes/footer.php on every page.
 *
 * PUBLIC API (safe to call from inline <script> blocks):
 *   window.togglePassword(inputId)          - Toggle password input
 *   window.toggleDropdown(event, menuId)    - Open/close dropdown by ID
 *   window.dismissAlert(element)            - Fade-out and remove alert
 *   window.AppConfig                        - Global config object
 *   window.EthiopianCalendar                - Conversion helpers
 *   window.MPC                              - Namespaced utilities
 *
 * INIT BEHAVIOURS (run automatically on DOMContentLoaded):
 *   - Dropdown click-outside handling
 *   - Auto-dismiss flash alerts after 5s
 *   - data-confirm confirmation dialogs
 *   - Submit-button locking on form POST
 *   - Ethiopian display below <input type="date">
 *   - Auto-fill of .eth-date-today widgets
 *   - Escape key closes any open dropdown
 * ============================================================
 */

(function () {
    'use strict';

    /* ============================================================
       1. GLOBAL CONFIGURATION
       ============================================================ */
    const AppConfig = {
        alertTimeout:      5000,                 // ms before alerts auto-dismiss
        confirmMessage:    'Are you sure you want to perform this action?',
        processingText:    '⏳ Processing...',
        closeIcon:         '&times;',
        dateFormatLocale:  'am-ET',

        // Official Ethiopian month names (Meskerem..Pagume)
        ethMonths: [
            'መስከረም', 'ጥቅምት', 'ህዳር', 'ታህሳስ', 'ጥር', 'የካቲት',
            'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜን'
        ],
    };


    /* ============================================================
       2. UTILITIES
       ============================================================ */
    const Util = {

        /**
         * Debounce — delays fn until `wait` ms after last call.
         * Useful for search-box inputs.
         */
        debounce(fn, wait) {
            let t;
            return function (...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), wait);
            };
        },

        /**
         * Throttle — runs fn at most once per `wait` ms.
         */
        throttle(fn, wait) {
            let last = 0, t;
            return function (...args) {
                const now = Date.now();
                const remaining = wait - (now - last);
                if (remaining <= 0) {
                    clearTimeout(t);
                    t = null;
                    last = now;
                    fn.apply(this, args);
                } else if (!t) {
                    t = setTimeout(() => {
                        last = Date.now();
                        t = null;
                        fn.apply(this, args);
                    }, remaining);
                }
            };
        },

        /**
         * Format an amount with thousands separator + currency.
         * formatCurrency(1500)        → "1,500.00"
         * formatCurrency(1500, 'ETB') → "1,500.00 ETB"
         */
        formatCurrency(value, currency) {
            const n = Number(value) || 0;
            const s = n.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
            return currency ? `${s} ${currency}` : s;
        },

        /**
         * Format an integer with thousands separator.
         */
        formatNumber(value) {
            return (Number(value) || 0).toLocaleString('en-US');
        },

        /**
         * Escape a string for safe insertion into HTML.
         */
        escapeHtml(str) {
            return String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        /**
         * Read the CSRF token from a <meta name="csrf-token"> tag if present,
         * otherwise from the first hidden input on the page.
         */
        csrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta && meta.content) return meta.content;

            const input = document.querySelector('input[name="csrf"]');
            return input ? input.value : '';
        },
    };


    /* ============================================================
       3. ETHIOPIAN CALENDAR
       ============================================================
       Port of the PHP function greg_to_ethiopian() in functions.php.
       Anchored to Sept 12, 2023 = Meskerem 1, 2016 EC.

       Public API:
         EthiopianCalendar.toEthiopian(dateInput)   → {year, month, day}
         EthiopianCalendar.format(dateInput)        → "ጥቅምት 12, 2016 ዓ.ም"
         EthiopianCalendar.fromEthiopian(y, m, d)   → {year, month, day} (Gregorian)
         EthiopianCalendar.parseEthiopianDate(str)  → Gregorian components
       ============================================================ */
    const EthiopianCalendar = {

        /** Sept 12, 2023 UTC midnight = Meskerem 1, 2016 EC (in ms) */
        _epochMs: Date.UTC(2023, 8, 12),

        /** Convert a JS Date / ISO string / timestamp to UTC midnight. */
        _toUtcMs(dateInput) {
            if (dateInput instanceof Date) {
                return Date.UTC(
                    dateInput.getUTCFullYear(),
                    dateInput.getUTCMonth(),
                    dateInput.getUTCDate()
                );
            }
            if (typeof dateInput === 'number') {
                // Assume already a timestamp
                const d = new Date(dateInput);
                return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
            }
            // String — try YYYY-MM-DD first, then let Date parse
            const m = String(dateInput).match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (m) {
                return Date.UTC(+m[1], +m[2] - 1, +m[3]);
            }
            const d = new Date(dateInput);
            if (isNaN(d.getTime())) return NaN;
            return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
        },

        /**
         * Convert Gregorian → Ethiopian.
         * @returns {{year:number,month:number,day:number}|null}
         */
        toEthiopian(dateInput) {
            const ms = this._toUtcMs(dateInput);
            if (isNaN(ms)) return null;

            // Signed day difference from epoch
            const diffMs = ms - this._epochMs;
            let diffDays = Math.round(diffMs / 86400000);

            let eYear = 2016;

            if (diffDays >= 0) {
                // Future (or today)
                while (true) {
                    const daysInYear = (eYear % 4 === 3) ? 366 : 365;
                    if (diffDays >= daysInYear) {
                        diffDays -= daysInYear;
                        eYear++;
                    } else {
                        break;
                    }
                }
            } else {
                // Past
                diffDays = -diffDays;
                while (true) {
                    eYear--;
                    const daysInYear = (eYear % 4 === 3) ? 366 : 365;
                    if (diffDays > daysInYear) {
                        diffDays -= daysInYear;
                    } else {
                        diffDays = daysInYear - diffDays;
                        break;
                    }
                }
            }

            let eMonth = 1;
            while (diffDays >= 30 && eMonth < 13) {
                diffDays -= 30;
                eMonth++;
            }

            // Pagume rollover (month 13, 5 or 6 days)
            if (eMonth === 13) {
                const maxDays = (eYear % 4 === 3) ? 6 : 5;
                if (diffDays >= maxDays) {
                    diffDays -= maxDays;
                    eYear++;
                    eMonth = 1;
                }
            }

            return { year: eYear, month: eMonth, day: diffDays + 1 };
        },

        /**
         * Format as "ጥቅምት 12, 2016 ዓ.ም".
         */
        format(dateInput, opts = {}) {
            const eth = this.toEthiopian(dateInput);
            if (!eth) return '';

            const months = AppConfig.ethMonths;
            const m = eth.month;
            if (!months[m - 1]) return '';

            let out = `${months[m - 1]} ${eth.day}, ${eth.year} ዓ.ም`;

            if (opts.time) {
                const d = (dateInput instanceof Date) ? dateInput : new Date(dateInput);
                if (!isNaN(d.getTime())) {
                    out += ' · ' + d.toTimeString().slice(0, 5);
                }
            }
            return out;
        },

        /**
         * Format as "2016-01-01" (Ethiopian ISO-ish).
         */
        formatIso(dateInput) {
            const eth = this.toEthiopian(dateInput);
            if (!eth) return '';
            const pad = n => String(n).padStart(2, '0');
            return `${eth.year}-${pad(eth.month)}-${pad(eth.day)}`;
        },

        /**
         * Ethiopian → Gregorian.
         * @returns {{year:number,month:number,day:number}|null}
         */
        fromEthiopian(eYear, eMonth, eDay) {
            eYear = parseInt(eYear, 10);
            eMonth = parseInt(eMonth, 10);
            eDay = parseInt(eDay, 10);

            if (!Number.isFinite(eYear) || !Number.isFinite(eMonth) || !Number.isFinite(eDay)) {
                return null;
            }

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

            const ms = this._epochMs + (days * 86400000);
            const d = new Date(ms);
            if (isNaN(d.getTime())) return null;

            return {
                year:  d.getUTCFullYear(),
                month: d.getUTCMonth() + 1,
                day:   d.getUTCDate(),
            };
        },

        /**
         * Parse an Ethiopian date string in either format:
         *   "2016-01-01"          → ISO-ish
         *   "ጥቅምት 12, 2016"      → Month Day, Year
         * Returns Gregorian components.
         */
        parseEthiopianDate(ethStr) {
            if (!ethStr) return null;

            // ISO-ish "2016-01-01"
            const iso = String(ethStr).match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
            if (iso) {
                return this.fromEthiopian(+iso[1], +iso[2], +iso[3]);
            }

            // "Month Day, Year" using Amharic month names
            const monthMap = {};
            AppConfig.ethMonths.forEach((name, idx) => {
                monthMap[name] = idx + 1;
            });

            const named = String(ethStr).match(/^(\S+)\s+(\d{1,2}),?\s+(\d{4})/);
            if (named && monthMap[named[1]]) {
                return this.fromEthiopian(+named[3], monthMap[named[1]], +named[2]);
            }

            return null;
        },
    };


    /* ============================================================
       4. PUBLIC FUNCTIONS (called from inline onclick handlers)
       ============================================================ */

    /**
     * Toggle the visibility of a password field.
     * Called from login.php: onclick="togglePassword('password')"
     */
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';

        // If the triggering element was an eye icon, toggle its emoji too
        if (typeof event !== 'undefined' && event.target) {
            const target = event.target.closest('.toggle-password') || event.target;
            if (target.tagName === 'SPAN' && /👁|🙈/.test(target.textContent)) {
                target.textContent = isPassword ? '🙈' : '👁️';
            }
        }
    }

    /**
     * Toggle a dropdown by ID.
     * Called from header.php: onclick="toggleDropdown(event, 'userMenu')"
     */
    function toggleDropdown(evt, menuId) {
        if (evt && evt.stopPropagation) evt.stopPropagation();

        const menu = document.getElementById(menuId);
        if (!menu) return;

        const isHidden = menu.style.display === 'none' || menu.style.display === '';
        menu.style.display = isHidden ? 'block' : 'none';
    }

    /**
     * Fade out and remove an alert box.
     */
    function dismissAlert(alertEl) {
        if (!alertEl || !alertEl.parentNode) return;

        alertEl.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-10px)';

        setTimeout(() => {
            if (alertEl.parentNode) alertEl.parentNode.removeChild(alertEl);
        }, 300);
    }


    /* ============================================================
       5. INIT FUNCTIONS (run on DOMContentLoaded)
       ============================================================ */

    /**
     * Close dropdowns when clicking outside or pressing Escape.
     */
    function initDropdowns() {
        document.addEventListener('click', function (evt) {
            if (evt.target.closest('.user-dropdown')) return;
            if (evt.target.closest('.dropdown-trigger')) return;

            document.querySelectorAll('.dropdown-content').forEach(function (menu) {
                if (menu.style.display === 'block') {
                    menu.style.display = 'none';
                }
            });
        });
    }

    /**
     * Close any open dropdown when user presses Escape.
     */
    function initEscapeKeyHandler() {
        document.addEventListener('keydown', function (evt) {
            if (evt.key !== 'Escape') return;
            document.querySelectorAll('.dropdown-content').forEach(function (menu) {
                menu.style.display = 'none';
            });
        });
    }

    /**
     * Add close button + auto-dismiss timer to flash alerts.
     */
    function initAlerts() {
        document.querySelectorAll('.alert').forEach(function (alert) {
            // Auto-dismiss
            setTimeout(() => dismissAlert(alert), AppConfig.alertTimeout);

            // Ensure close button exists
            if (!alert.querySelector('.alert-close')) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'alert-close';
                btn.innerHTML = AppConfig.closeIcon;
                btn.style.cssText = 'background:none;border:none;font-size:20px;cursor:pointer;color:inherit;line-height:1;padding:0;';
                btn.addEventListener('click', () => dismissAlert(alert));
                alert.appendChild(btn);
            }
        });
    }

    /**
     * Wire up [data-confirm] handlers.
     * Usage: <a href="..." data-confirm="Are you sure?">
     */
    function initConfirmations() {
        document.addEventListener('click', function (evt) {
            const target = evt.target.closest('[data-confirm]');
            if (!target) return;

            const msg = target.getAttribute('data-confirm') || AppConfig.confirmMessage;
            if (!window.confirm(msg)) {
                evt.preventDefault();
                evt.stopPropagation();
            }
        });
    }

    /**
     * Disable the submit button on first submit to prevent double-posting.
     */
    function initFormLoaders() {
        document.querySelectorAll('form').forEach(function (form) {
            if (form.dataset.loaderBound === '1') return;
            form.dataset.loaderBound = '1';

            form.addEventListener('submit', function () {
                const btn = form.querySelector('button[type="submit"], input[type="submit"]');
                if (!btn || btn.disabled) return;

                // Defer so browser can start the POST before we disable
                setTimeout(() => {
                    if (btn.disabled) return;
                    btn.dataset.originalText = btn.innerHTML;
                    btn.innerHTML = AppConfig.processingText;
                    btn.disabled = true;
                    btn.style.opacity = '0.7';
                    btn.style.cursor = 'not-allowed';
                }, 10);
            });
        });
    }

    /**
     * Auto-calculate day counts for forms marked .date-calc-form.
     * Expects #start_date, #end_date, #days_count.
     */
    function initAutoDateCalculators() {
        document.querySelectorAll('.date-calc-form').forEach(function (form) {
            const start = form.querySelector('#start_date');
            const end   = form.querySelector('#end_date');
            const count = form.querySelector('#days_count');

            if (!start || !end || !count) return;

            const recalc = function () {
                if (!start.value || !end.value) return;

                const d1 = new Date(start.value);
                const d2 = new Date(end.value);

                if (isNaN(d1) || isNaN(d2)) return;

                if (d2 >= d1) {
                    const diff = Math.abs(d2 - d1);
                    count.value = Math.ceil(diff / 86400000) + 1;
                } else {
                    count.value = 0;
                }
            };

            start.addEventListener('change', recalc);
            end.addEventListener('change', recalc);
        });
    }

    /**
     * Show an Ethiopian translation below every <input type="date">.
     * Creates a styled <div data-eth-display> under each.
     */
    function initEthiopianDateInputs() {
        document.querySelectorAll('input[type="date"]').forEach(function (input) {
            if (input.dataset.ethBound === '1') return;
            input.dataset.ethBound = '1';

            // Skip hidden inputs (they can't be interacted with)
            if (input.type === 'hidden') return;

            // Skip if parent already has a display (module may render its own)
            if (input.parentNode && input.parentNode.querySelector('[data-eth-display]')) return;

            const display = document.createElement('div');
            display.setAttribute('data-eth-display', '1');
            display.style.cssText =
                'margin-top:8px;font-size:13px;font-weight:bold;' +
                'color:var(--mpc-blue,#4DA6E8);background:#E0F2FE;' +
                'padding:6px 10px;border-radius:6px;display:none;' +
                'border-left:3px solid var(--mpc-blue,#4DA6E8);';

            if (input.parentNode) {
                input.parentNode.appendChild(display);
            }

            const updateTranslation = function () {
                if (!input.value) {
                    display.style.display = 'none';
                    return;
                }
                const ethStr = EthiopianCalendar.format(input.value);
                if (!ethStr) {
                    display.style.display = 'none';
                    return;
                }
                display.style.display = 'block';
                display.innerHTML = '🇪🇹 በኢትዮጵያ ዘመን አቆጣጠር: ' + ethStr;
            };

            input.addEventListener('change', updateTranslation);
            input.addEventListener('input', updateTranslation);
            updateTranslation();
        });
    }

    /**
     * Fill every .eth-date-today span with today's Ethiopian date.
     * Usage: <span class="eth-date-today"></span>
     */
    function initTodayWidgets() {
        const widgets = document.querySelectorAll('.eth-date-today');
        if (widgets.length === 0) return;

        const todayStr = EthiopianCalendar.format(new Date());
        if (!todayStr) return;

        widgets.forEach(function (el) {
            el.textContent = todayStr;
        });
    }


    /* ============================================================
       6. BOOTSTRAP
       ============================================================ */
    function init() {
        initDropdowns();
        initEscapeKeyHandler();
        initAlerts();
        initConfirmations();
        initFormLoaders();
        initAutoDateCalculators();
        initEthiopianDateInputs();
        initTodayWidgets();
    }


    /* ============================================================
       7. EXPOSE PUBLIC API (before DOMContentLoaded fires)
       ============================================================ */
    window.AppConfig          = AppConfig;
    window.EthiopianCalendar  = EthiopianCalendar;
    window.togglePassword     = togglePassword;
    window.toggleDropdown     = toggleDropdown;
    window.dismissAlert       = dismissAlert;

    // Namespaced access (optional — use whichever style you prefer)
    window.MPC = {
        config:          AppConfig,
        util:            Util,
        ethCal:          EthiopianCalendar,
        togglePassword,
        toggleDropdown,
        dismissAlert,
    };


    /* ============================================================
       8. RUN ON DOM READY
       ============================================================ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();