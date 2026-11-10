/**
 * ============================================================
 * MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
 * CLIENT-SIDE FORM VALIDATION
 * ============================================================
 * Loaded by includes/footer.php AFTER app.js:
 *   <script src="<?= APP_URL ?>/assets/js/app.js"></script>
 *   <script src="<?= APP_URL ?>/assets/js/validation.js"></script>
 *
 * BEHAVIOR
 *   - Auto-enhances every <form> except those with
 *     data-no-validate="true" or inside .login-body.
 *   - Adds inline error messages in Amharic + English.
 *   - Uses capture-phase submission to run BEFORE app.js's
 *     form-loader, so failed validation doesn't disable the
 *     submit button.
 *   - All field-level rules are OPT-IN via HTML attributes;
 *     existing `<input required>` still works.
 *
 * PUBLIC API (callable from console or inline script):
 *   MPC.validate.check(input)                → boolean
 *   MPC.validate.checkForm(form)             → boolean
 *   MPC.validate.email(str)                  → boolean
 *   MPC.validate.phone(str)                  → boolean
 *   MPC.validate.password(str)               → {valid, score, label, issues}
 *   MPC.validate.passwordMatch(a, b)         → boolean
 *   MPC.validate.fileSize(file, maxBytes)    → boolean
 *   MPC.validate.fileType(file, exts)        → boolean
 *
 * SUPPORTED data-* ATTRIBUTES
 *   data-strength        on password inputs → shows strength meter
 *   data-match="field"   → must match value of field with that name/id
 *   data-min-size / data-max-size  → for <input type="file">
 *                                     accepts "2MB", "500KB", "1024"
 *   data-accept-types    → for <input type="file">
 *                          accepts ".pdf,.jpg,.png"
 *   data-after="field"   → for date inputs, must be on/after field
 *   data-before="field"  → for date inputs, must be on/before field
 *
 * AUTO-DETECTED VALIDATION
 *   type="email"         → email format
 *   type="tel"           → Ethiopian phone number
 *   type="url"           → URL format
 *   name="*phone*"       → Ethiopian phone number
 *   required             → not empty (whitespace counts as empty)
 *   minlength / maxlength → length bounds
 *   min / max / step     → numeric bounds
 *   pattern=""           → regex
 * ============================================================
 */

(function () {
    'use strict';

    /* Guard against double-init */
    if (window.__mpcValidateInitialized) return;
    window.__mpcValidateInitialized = true;


    /* ============================================================
       1. CONFIGURATION
       ============================================================ */
    const CONFIG = {
        errorClass:         'mpc-invalid',
        errorContainerId:   'mpc-err',
        validClass:         'mpc-valid',
        strengthShowLabels: true,
        scrollToFirstError: true,
        autoEnhanceAll:     true,   // set false to require data-validate on form
        skipSelectors:      ['.login-body form', 'form[data-no-validate="true"]', 'form.novalidate'],
    };

    /** Bilingual error messages. Placeholders: {n}, {max}, {min}, {label}. */
    const MSG = {
        required:      { am: 'ይህ መስክ ያስፈልጋል',                          en: 'This field is required' },
        email:         { am: 'ትክክለኛ ኢሜይል ያስገቡ',                        en: 'Enter a valid email' },
        phone:         { am: 'ትክክለኛ የኢትዮጵያ ስልክ ቁጥር ያስገቡ',             en: 'Enter a valid Ethiopian phone number' },
        url:           { am: 'ትክክለኛ አድራሻ ያስገቡ',                        en: 'Enter a valid URL' },
        number:        { am: 'ቁጥር ያስገቡ',                              en: 'Enter a number' },
        integer:       { am: 'ሙሉ ቁጥር ያስገቡ',                           en: 'Enter a whole number' },
        minLength:     { am: 'ቢያንስ {n} ፊደሎች ያስፈልጋሉ',                  en: 'At least {n} characters required' },
        maxLength:     { am: 'ከ {n} ፊደሎች መብለጥ የለበትም',                  en: 'Must be {n} characters or fewer' },
        min:           { am: 'ቢያንስ {n} መሆን አለበት',                       en: 'Must be at least {n}' },
        max:           { am: 'ከ {n} መብለጥ የለበትም',                        en: 'Must be at most {n}' },
        step:          { am: 'ትክክለኛ እርምጃ ያስፈልጋል',                     en: 'Invalid step value' },
        pattern:       { am: 'ትክክለኛ ቅርጸት አይደለም',                      en: 'Invalid format' },
        date:          { am: 'ትክክለኛ ቀን ያስገቡ',                          en: 'Enter a valid date' },
        dateAfter:     { am: 'ከ {label} በኋላ ወይም እኩል መሆን አለበት',          en: 'Must be on or after {label}' },
        dateBefore:    { am: 'ከ {label} በፊት ወይም እኩል መሆን አለበት',          en: 'Must be on or before {label}' },
        passwordMatch: { am: 'የይለፍ ቃላቱ አይመሳሰሉም',                      en: 'Passwords do not match' },
        passwordWeak:  { am: 'የይለፍ ቃሉ በጣም ደካማ ነው',                    en: 'Password is too weak' },
        passwordShort: { am: 'የይለፍ ቃል ቢያንስ 8 ፊደሎች ያስፈልጉታል',           en: 'Password must be at least 8 characters' },
        fileSize:      { am: 'ፋይሉ በጣም ትልቅ ነው (ከፍተኛው {max})',            en: 'File is too large (max {max})' },
        fileType:      { am: 'የፋይል አይነት ተቀባይነት የለውም',                 en: 'File type not allowed' },
        formSummary:   { am: '{n} ስህተት(ዎች) ተገኝተዋል — እባክዎ ያስተካክሉ',      en: '{n} error(s) found — please fix them' },
    };


    /* ============================================================
       2. CSS INJECTION (once)
       ============================================================ */
    (function injectStyles() {
        if (document.getElementById('mpc-validate-styles')) return;

        const style = document.createElement('style');
        style.id = 'mpc-validate-styles';
        style.textContent = `
            /* --- Invalid state --- */
            .mpc-invalid {
                border-color: #DC2626 !important;
                background-color: #FEF2F2 !important;
            }
            .mpc-invalid:focus {
                box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.15) !important;
            }

            /* --- Valid state (brief) --- */
            .mpc-valid {
                border-color: #16A34A !important;
            }

            /* --- Error message --- */
            .mpc-err-msg {
                display: flex;
                align-items: center;
                gap: 6px;
                margin-top: 6px;
                font-size: 12px;
                font-weight: 600;
                color: #991B1B;
                line-height: 1.4;
            }
            .mpc-err-msg::before {
                content: '⚠';
                flex-shrink: 0;
            }
            .mpc-err-msg .sep {
                opacity: 0.5;
                font-weight: 400;
            }
            .mpc-err-msg .en {
                font-weight: 500;
                color: #B91C1C;
                opacity: 0.85;
            }
            @media (max-width: 480px) {
                .mpc-err-msg { flex-wrap: wrap; }
            }

            /* --- Password strength meter --- */
            .mpc-strength {
                margin-top: 8px;
            }
            .mpc-strength-bar {
                height: 6px;
                background: #E5E7EB;
                border-radius: 9999px;
                overflow: hidden;
                position: relative;
            }
            .mpc-strength-fill {
                height: 100%;
                width: 0%;
                background: #DC2626;
                border-radius: 9999px;
                transition: width 0.25s, background 0.25s;
            }
            .mpc-strength-label {
                display: flex;
                justify-content: space-between;
                font-size: 11px;
                font-weight: 600;
                margin-top: 4px;
                color: #6B7280;
            }
            .mpc-strength-label .mpc-strength-score {
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .mpc-strength[data-score="1"] .mpc-strength-fill { background: #DC2626; }
            .mpc-strength[data-score="2"] .mpc-strength-fill { background: #F59E0B; }
            .mpc-strength[data-score="3"] .mpc-strength-fill { background: #4DA6E8; }
            .mpc-strength[data-score="4"] .mpc-strength-fill { background: #16A34A; }

            /* --- Form-level error summary --- */
            .mpc-form-summary {
                display: flex;
                align-items: flex-start;
                gap: 10px;
                padding: 12px 16px;
                margin-bottom: 16px;
                background: #FEF2F2;
                border-left: 4px solid #DC2626;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 600;
                color: #991B1B;
                line-height: 1.5;
            }
            .mpc-form-summary::before {
                content: '⚠';
                font-size: 20px;
                flex-shrink: 0;
                line-height: 1;
            }
            .mpc-form-summary .en {
                font-weight: 500;
                opacity: 0.85;
                margin-top: 2px;
            }

            /* --- File input error --- */
            input[type="file"].mpc-invalid {
                border-color: #DC2626 !important;
                background-color: #FEF2F2 !important;
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
                .replace(/"/g, '&quot;');
        },

        /** Parse "2MB", "500KB", "1024" → bytes */
        parseSize(str) {
            if (typeof str === 'number') return str;
            const m = String(str).trim().match(/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB)?$/i);
            if (!m) return 0;
            const n = parseFloat(m[1]);
            const unit = (m[2] || 'B').toUpperCase();
            const mult = { B: 1, KB: 1024, MB: 1024 * 1024, GB: 1024 * 1024 * 1024 }[unit];
            return Math.floor(n * mult);
        },

        /** Format bytes → "2 MB" */
        formatSize(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            if (bytes >= 1048576)    return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024)       return Math.round(bytes / 1024) + ' KB';
            return bytes + ' B';
        },

        /** Resolve a form field by name or #id or [name=x] */
        resolveField(form, selectorOrName) {
            if (!selectorOrName) return null;
            if (selectorOrName.startsWith('#') || selectorOrName.startsWith('.') || selectorOrName.startsWith('[')) {
                return form.querySelector(selectorOrName);
            }
            return form.elements[selectorOrName]
                || form.querySelector(`[name="${selectorOrName}"]`)
                || document.getElementById(selectorOrName);
        },

        /** Replace {placeholders} in a message template. */
        renderMessage(template, vars) {
            return String(template).replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`);
        },

        /** Render "Amharic · English" message with proper markup. */
        renderBilingual(templateKey, vars = {}) {
            const m = MSG[templateKey];
            if (!m) return templateKey;
            const am = util.renderMessage(m.am, vars);
            const en = util.renderMessage(m.en, vars);
            return `<span class="am">${util.escapeHtml(am)}</span>` +
                   `<span class="sep">·</span>` +
                   `<span class="en">${util.escapeHtml(en)}</span>`;
        },
    };


    /* ============================================================
       4. VALIDATORS
       ============================================================ */
    const validators = {

        email(v) {
            if (!v) return true;
            return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);
        },

        /**
         * Ethiopian phone. Accepts:
         *   0911223344    (mobile, Ethio Telecom)
         *   0711223344    (mobile, Safaricom)
         *   0111223344    (landline Addis)
         *   0311223344    (landline regional)
         *   +251911223344 (international mobile)
         *   251911223344  (no plus)
         *   with -, space, or . separators
         */
        phone(v) {
            if (!v) return true;
            const clean = String(v).replace(/[\s\-\.\(\)]/g, '');
            return /^(?:\+?251|0)(?:9|7)\d{8}$/.test(clean)
                || /^(?:\+?251|0)(?:1|2|3|4|5|6|8)\d{8}$/.test(clean);
        },

        url(v) {
            if (!v) return true;
            try {
                new URL(v);
                return true;
            } catch {
                return /^https?:\/\/.+\..+/.test(v);
            }
        },

        /**
         * Password scoring.
         * @returns {{valid:boolean, score:0|1|2|3|4, label:string, issues:string[]}}
         */
        password(v) {
            const issues = [];
            let score = 0;

            if (!v) {
                return { valid: false, score: 0, label: 'Empty', issues: ['empty'] };
            }

            // Length
            if (v.length >= 8)  score++;
            if (v.length >= 12) score++;
            if (v.length < 8)   issues.push('short');

            // Char classes
            const hasLower  = /[a-z]/.test(v);
            const hasUpper  = /[A-Z]/.test(v);
            const hasDigit  = /\d/.test(v);
            const hasSymbol = /[^A-Za-z0-9]/.test(v);
            const classes = [hasLower, hasUpper, hasDigit, hasSymbol].filter(Boolean).length;
            if (classes >= 3) score++;
            if (classes >= 4) score++;

            // Common weak passwords
            const common = [
                'password', '12345678', '123456789', 'qwerty',
                'admin', 'admin123', 'misrak', 'letmein',
                'password1', 'abc12345', 'welcome',
            ];
            if (common.includes(v.toLowerCase())) {
                score = Math.min(score, 1);
                issues.push('common');
            }

            // Cap score at 4
            score = Math.max(0, Math.min(4, score));

            const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
            const labelsAm = ['', 'ደካማ', 'መካከለኛ', 'ጥሩ', 'ጠንካራ'];
            return {
                valid: v.length >= 8 && score >= 2,
                score,
                label: `${labelsAm[score] || ''} · ${labels[score] || ''}`,
                issues,
            };
        },

        passwordMatch(a, b) {
            return a === b;
        },

        fileSize(file, maxBytes) {
            if (!file) return true;
            return file.size <= maxBytes;
        },

        fileType(file, allowedExts) {
            if (!file || !allowedExts || allowedExts.length === 0) return true;
            const ext = '.' + (file.name.split('.').pop() || '').toLowerCase();
            return allowedExts.map(e => e.toLowerCase()).includes(ext);
        },
    };


    /* ============================================================
       5. FIELD DECORATION & UI
       ============================================================ */

    /** Find or create the error message container for a field. */
    function getOrCreateErrorContainer(field) {
        const containerId = field.id || field.name || Math.random().toString(36).slice(2);
        let errEl = document.getElementById(`${CONFIG.errorContainerId}-${containerId}`);

        if (!errEl) {
            errEl = document.createElement('div');
            errEl.id = `${CONFIG.errorContainerId}-${containerId}`;
            errEl.className = 'mpc-err-msg';
            errEl.setAttribute('role', 'alert');
            errEl.style.display = 'none';
            field.insertAdjacentElement('afterend', errEl);
        }
        return errEl;
    }

    /** Show inline error for a field. */
    function showError(field, templateKey, vars = {}) {
        const errEl = getOrCreateErrorContainer(field);
        errEl.innerHTML = util.renderBilingual(templateKey, vars);
        errEl.style.display = 'flex';
        field.classList.add(CONFIG.errorClass);
        field.classList.remove(CONFIG.validClass);
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', errEl.id);
    }

    /** Clear error for a field. */
    function clearError(field, markValid = false) {
        const containerId = field.id || field.name;
        if (!containerId) return;

        const errEl = document.getElementById(`${CONFIG.errorContainerId}-${containerId}`);
        if (errEl) {
            errEl.innerHTML = '';
            errEl.style.display = 'none';
        }

        field.classList.remove(CONFIG.errorClass);
        field.removeAttribute('aria-invalid');

        if (markValid && field.value) {
            field.classList.add(CONFIG.validClass);
            setTimeout(() => field.classList.remove(CONFIG.validClass), 1200);
        }
    }

    /** Show form-level summary at the top of the form. */
    function showFormSummary(form, count) {
        let summary = form.querySelector('.mpc-form-summary');

        if (!summary) {
            summary = document.createElement('div');
            summary.className = 'mpc-form-summary';
            summary.setAttribute('role', 'alert');
            form.insertBefore(summary, form.firstChild);
        }

        const m = MSG.formSummary;
        const am = util.renderMessage(m.am, { n: count });
        const en = util.renderMessage(m.en, { n: count });

        summary.innerHTML =
            `<div>` +
                `<div>${util.escapeHtml(am)}</div>` +
                `<div class="en">${util.escapeHtml(en)}</div>` +
            `</div>`;

        summary.style.display = 'flex';
    }

    /** Remove the form-level summary. */
    function clearFormSummary(form) {
        const summary = form.querySelector('.mpc-form-summary');
        if (summary) summary.style.display = 'none';
    }

    /** Inject a password strength meter after a password input. */
    function attachStrengthMeter(field) {
        if (field.__mpcStrength) return;

        const wrap = document.createElement('div');
        wrap.className = 'mpc-strength';
        wrap.dataset.score = '0';
        wrap.innerHTML =
            `<div class="mpc-strength-bar"><div class="mpc-strength-fill"></div></div>` +
            `<div class="mpc-strength-label">` +
                `<span class="mpc-strength-score"></span>` +
                `<span class="mpc-strength-hint"></span>` +
            `</div>`;

        field.insertAdjacentElement('afterend', wrap);
        field.__mpcStrength = wrap;

        const fill  = wrap.querySelector('.mpc-strength-fill');
        const score = wrap.querySelector('.mpc-strength-score');

        const update = () => {
            const r = validators.password(field.value);
            wrap.dataset.score = String(r.score);
            fill.style.width = (r.score * 25) + '%';
            score.textContent = r.score > 0 ? r.label : '';
        };

        field.addEventListener('input', update);
        field.addEventListener('change', update);
        update();
    }


    /* ============================================================
       6. FIELD VALIDATION LOGIC
       ============================================================ */

    /**
     * Validate a single field. Returns true if valid, false if not.
     * On invalid, calls showError(). On valid, calls clearError().
     */
    function validateField(field) {
        // Skip non-validated elements
        if (field.disabled || field.type === 'hidden' || field.type === 'submit' ||
            field.type === 'button' || field.type === 'reset' || field.type === 'checkbox' ||
            field.type === 'radio' || field.tagName === 'FIELDSET') {
            return true;
        }
        if (field.closest('fieldset[disabled]')) return true;
        if (field.dataset.noValidate === 'true') return true;

        const value = (field.value || '').trim();
        const tag = field.tagName;
        const type = field.type;

        /* ---------- 1. Required ---------- */
        const isRequired = field.hasAttribute('required');
        if (isRequired && value === '' && type !== 'file') {
            showError(field, 'required');
            return false;
        }

        /* ---------- 2. File-specific checks ---------- */
        if (type === 'file') {
            const file = field.files && field.files[0];
            if (!file) {
                if (isRequired) {
                    showError(field, 'required');
                    return false;
                }
                clearError(field);
                return true;
            }

            // Size check
            const maxSizeAttr = field.dataset.maxSize;
            if (maxSizeAttr) {
                const maxBytes = util.parseSize(maxSizeAttr);
                if (maxBytes > 0 && !validators.fileSize(file, maxBytes)) {
                    showError(field, 'fileSize', { max: util.formatSize(maxBytes) });
                    return false;
                }
            }

            // Type check
            const acceptTypes = field.dataset.acceptTypes || field.getAttribute('accept');
            if (acceptTypes) {
                const exts = acceptTypes.split(',').map(s => s.trim()).filter(Boolean);
                if (exts.length > 0 && exts[0].startsWith('.')) {
                    if (!validators.fileType(file, exts)) {
                        showError(field, 'fileType');
                        return false;
                    }
                }
            }

            clearError(field, true);
            return true;
        }

        // If empty and not required, skip further validation
        if (value === '') {
            clearError(field);
            return true;
        }

        /* ---------- 3. Email ---------- */
        if (type === 'email' || field.dataset.validate === 'email') {
            if (!validators.email(value)) {
                showError(field, 'email');
                return false;
            }
        }

        /* ---------- 4. Phone (Ethiopian) ---------- */
        if (type === 'tel' || field.dataset.validate === 'phone' ||
            /\bphone\b/i.test(field.name || '')) {
            if (!validators.phone(value)) {
                showError(field, 'phone');
                return false;
            }
        }

        /* ---------- 5. URL ---------- */
        if (type === 'url' || field.dataset.validate === 'url') {
            if (!validators.url(value)) {
                showError(field, 'url');
                return false;
            }
        }

        /* ---------- 6. Length ---------- */
        const minLen = parseInt(field.getAttribute('minlength') || '0', 10);
        const maxLen = parseInt(field.getAttribute('maxlength') || '0', 10);

        if (minLen > 0 && value.length < minLen) {
            showError(field, 'minLength', { n: minLen });
            return false;
        }
        if (maxLen > 0 && value.length > maxLen) {
            showError(field, 'maxLength', { n: maxLen });
            return false;
        }

        /* ---------- 7. Numeric bounds ---------- */
        if (type === 'number' || field.dataset.validate === 'number') {
            const num = Number(value);
            if (isNaN(num)) {
                showError(field, 'number');
                return false;
            }
            const step = field.getAttribute('step');
            if (step === '1' && !Number.isInteger(num)) {
                showError(field, 'integer');
                return false;
            }
            const minAttr = field.getAttribute('min');
            const maxAttr = field.getAttribute('max');
            if (minAttr !== null && num < Number(minAttr)) {
                showError(field, 'min', { n: minAttr });
                return false;
            }
            if (maxAttr !== null && num > Number(maxAttr)) {
                showError(field, 'max', { n: maxAttr });
                return false;
            }
        }

        /* ---------- 8. Pattern ---------- */
        const patternAttr = field.getAttribute('pattern');
        if (patternAttr) {
            try {
                const re = new RegExp('^(?:' + patternAttr + ')$');
                if (!re.test(value)) {
                    showError(field, 'pattern');
                    return false;
                }
            } catch (e) { /* bad regex in HTML — skip */ }
        }

        /* ---------- 9. Password strength ---------- */
        if (field.hasAttribute('data-strength') && type === 'password') {
            const r = validators.password(value);
            if (!r.valid) {
                showError(field, r.issues.includes('short') ? 'passwordShort' : 'passwordWeak');
                return false;
            }
        }

        /* ---------- 10. Password match ---------- */
        const matchSelector = field.dataset.match;
        if (matchSelector) {
            const other = util.resolveField(field.form, matchSelector);
            if (other && other.value !== field.value) {
                showError(field, 'passwordMatch');
                return false;
            }
        }

        /* ---------- 11. Date range ---------- */
        if (type === 'date') {
            const afterSel  = field.dataset.after;
            const beforeSel = field.dataset.before;

            if (afterSel) {
                const afterField = util.resolveField(field.form, afterSel);
                if (afterField && afterField.value && field.value < afterField.value) {
                    const label = afterField.dataset.label || afterField.name || 'start';
                    showError(field, 'dateAfter', { label });
                    return false;
                }
            }

            if (beforeSel) {
                const beforeField = util.resolveField(field.form, beforeSel);
                if (beforeField && beforeField.value && field.value > beforeField.value) {
                    const label = beforeField.dataset.label || beforeField.name || 'end';
                    showError(field, 'dateBefore', { label });
                    return false;
                }
            }
        }

        /* ---------- All good ---------- */
        clearError(field, true);
        return true;
    }


    /* ============================================================
       7. FORM VALIDATION
       ============================================================ */

    /** Validate every field in a form. Returns {valid, errors}. */
    function validateForm(form) {
        if (!form) return { valid: true, errors: [] };

        clearFormSummary(form);

        const errors = [];
        const fields = form.querySelectorAll('input, select, textarea');

        fields.forEach(field => {
            if (!validateField(field)) {
                errors.push(field);
            }
        });

        if (errors.length > 0) {
            showFormSummary(form, errors.length);

            if (CONFIG.scrollToFirstError && errors[0]) {
                try {
                    errors[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    errors[0].focus({ preventScroll: true });
                } catch (e) {
                    errors[0].focus();
                }
            }
            return { valid: false, errors };
        }

        return { valid: true, errors: [] };
    }


    /* ============================================================
       8. FORM ENHANCEMENT
       ============================================================ */

    /** Should this form be auto-validated? */
    function shouldEnhance(form) {
        if (form.dataset.noValidate === 'true') return false;

        for (const sel of CONFIG.skipSelectors) {
            try {
                if (form.matches(sel) || form.closest(sel)) return false;
            } catch (e) { /* invalid selector in old browser */ }
        }

        // If any input already has our attributes, always enhance
        if (form.querySelector('[data-strength],[data-match],[data-max-size],[data-accept-types],[data-after],[data-before]')) {
            return true;
        }

        // Otherwise, enhance only if CONFIG says so
        return CONFIG.autoEnhanceAll;
    }

    /** Wire up per-field listeners. */
    function enhanceField(field, form) {
        if (field.__mpcEnhanced) return;
        field.__mpcEnhanced = true;

        // Strength meter for password inputs
        if (field.type === 'password' && field.hasAttribute('data-strength')) {
            attachStrengthMeter(field);
        }

        // Validate on blur (first time user leaves the field)
        field.addEventListener('blur', () => {
            if (field.value || field.hasAttribute('required')) {
                validateField(field);
            }
        });

        // Re-validate on change/input AFTER first error is shown
        // (don't nag while typing the first time)
        const revalidate = () => {
            if (field.classList.contains(CONFIG.errorClass) || field.getAttribute('aria-invalid') === 'true') {
                validateField(field);
            }
        };
        field.addEventListener('input', revalidate);
        field.addEventListener('change', revalidate);
    }

    /** Add the form to our enhancement set. */
    function enhanceForm(form) {
        if (form.__mpcEnhanced) return;
        form.__mpcEnhanced = true;

        if (!shouldEnhance(form)) return;

        // Prevent browser's native tooltip validation UI
        // (we show our own bilingual errors instead)
        form.setAttribute('novalidate', '');

        // Enhance each field
        form.querySelectorAll('input, select, textarea').forEach(f => enhanceField(f, form));
    }


    /* ============================================================
       9. SUBMISSION HANDLER (capture phase)
       ============================================================
       Binds on document with capture=true so this runs BEFORE
       app.js's form-loader listener. If validation fails, we
       stopPropagation() — the loader never runs, and the submit
       button stays enabled so the user can fix and retry.
       ============================================================ */

    function handleSubmitCapture(evt) {
        const form = evt.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.__mpcEnhanced) return;

        const { valid, errors } = validateForm(form);

        if (!valid) {
            evt.preventDefault();
            evt.stopPropagation();
            return;
        }
    }


    /* ============================================================
       10. INIT
       ============================================================ */

    function init() {
        // Enhance all forms on the page
        document.querySelectorAll('form').forEach(enhanceForm);

        // Bind capture-phase submit listener (runs before app.js)
        document.addEventListener('submit', handleSubmitCapture, true);

        // Enhance any dynamically added forms (e.g., modals, AJAX)
        const observer = new MutationObserver(muts => {
            muts.forEach(m => {
                m.addedNodes.forEach(node => {
                    if (!(node instanceof Element)) return;

                    if (node.tagName === 'FORM') {
                        enhanceForm(node);
                    } else {
                        node.querySelectorAll?.('form').forEach(enhanceForm);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }


    /* ============================================================
       11. PUBLIC API
       ============================================================ */
    window.MPC = window.MPC || {};
    window.MPC.validate = {
        /** Validate a single field and show/clear its error. */
        check(field) {
            return validateField(field);
        },

        /** Validate every field in a form. Returns boolean. */
        checkForm(form) {
            return validateForm(form).valid;
        },

        /** Pure validators (no DOM). */
        email:         validators.email,
        phone:         validators.phone,
        url:           validators.url,
        password:      validators.password,
        passwordMatch: validators.passwordMatch,
        fileSize:      validators.fileSize,
        fileType:      validators.fileType,

        /** Utility: parse "2MB" → 2097152. */
        parseSize:  util.parseSize,
        formatSize: util.formatSize,

        /** Clear all errors in a form (useful after reset). */
        clear(form) {
            if (!form) return;
            form.querySelectorAll(`.${CONFIG.errorClass}`).forEach(f => clearError(f));
            clearFormSummary(form);
        },

        /** Expose the config object for runtime tweaking. */
        config: CONFIG,
    };


    /* ============================================================
       12. BOOT
       ============================================================ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();