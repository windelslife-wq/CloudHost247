/**
 * CloudHost247 Tools - core runtime.
 *
 * Provides the shared component layer every tool page uses:
 *   ToolPage, ToolHeader, ToolInput, ToolButton, ToolResult, ToolError,
 *   ToolLoading, ToolHistory, ToolCopyButton, ToolDownloadButton,
 *   ToolShareButton, RelatedTools, ToolFAQ
 *
 * Each tool page loads this file plus exactly one per-tool module from
 * assets/js/tools/<slug>.js - never a bundle of unrelated tools.
 *
 * Accessibility notes:
 *  - every control has a programmatic label
 *  - results and errors are announced through aria-live regions
 *  - status is conveyed by text and icon, never by colour alone
 *  - focus moves to the result region after a run, and back on reset
 *  - all animation respects prefers-reduced-motion
 */
(function (window, document) {
    'use strict';

    var CH247 = window.CH247Tools || {};
    window.CH247Tools = CH247;

    // -----------------------------------------------------------------
    //  Utilities
    // -----------------------------------------------------------------

    /** Escape a value for safe insertion into HTML. */
    function esc(value) {
        if (value === null || value === undefined) { return ''; }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) { return; }
            if (key === 'class') { node.className = value; }
            else if (key === 'text') { node.textContent = value; }
            else if (key === 'html') { node.innerHTML = value; }
            else if (key.indexOf('on') === 0 && typeof value === 'function') {
                node.addEventListener(key.slice(2).toLowerCase(), value);
            } else { node.setAttribute(key, value === true ? '' : value); }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined) { return; }
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function humanBytes(bytes) {
        if (!bytes && bytes !== 0) { return ''; }
        var units = ['B', 'KB', 'MB', 'GB'], i = 0, n = Number(bytes);
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n : n.toFixed(2)) + ' ' + units[i];
    }

    /** Lazily load a script once; returns a promise. */
    var scriptCache = {};
    function loadScript(src) {
        if (scriptCache[src]) { return scriptCache[src]; }
        scriptCache[src] = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.async = true;
            s.onload = function () { resolve(); };
            s.onerror = function () {
                delete scriptCache[src];
                reject(new Error('Failed to load ' + src));
            };
            document.head.appendChild(s);
        });
        return scriptCache[src];
    }
    CH247.loadScript = loadScript;

    CH247.esc = esc;
    CH247.el = el;
    CH247.humanBytes = humanBytes;

    // -----------------------------------------------------------------
    //  ToolHistory - per-tool run history in localStorage
    // -----------------------------------------------------------------

    var ToolHistory = {
        KEY_PREFIX: 'ch247:tools:history:',
        LIMIT: 20,

        available: function () {
            try {
                var k = '__ch247_probe__';
                window.localStorage.setItem(k, '1');
                window.localStorage.removeItem(k);
                return true;
            } catch (e) { return false; }
        },

        read: function (slug) {
            if (!this.available()) { return []; }
            try {
                return JSON.parse(window.localStorage.getItem(this.KEY_PREFIX + slug) || '[]');
            } catch (e) { return []; }
        },

        /** Sensitive tools opt out entirely - nothing is ever persisted. */
        add: function (slug, entry, opts) {
            if (!this.available() || (opts && opts.sensitive)) { return; }
            var list = this.read(slug);
            list.unshift({ at: Date.now(), summary: entry.summary, input: entry.input });
            list = list.slice(0, this.LIMIT);
            try {
                window.localStorage.setItem(this.KEY_PREFIX + slug, JSON.stringify(list));
            } catch (e) { /* quota - history is best effort */ }
        },

        clear: function (slug) {
            if (!this.available()) { return; }
            window.localStorage.removeItem(this.KEY_PREFIX + slug);
        },

        clearAll: function () {
            if (!this.available()) { return; }
            var keys = [];
            for (var i = 0; i < window.localStorage.length; i++) {
                var k = window.localStorage.key(i);
                if (k && k.indexOf(this.KEY_PREFIX) === 0) { keys.push(k); }
            }
            keys.forEach(function (k) { window.localStorage.removeItem(k); });
        }
    };
    CH247.ToolHistory = ToolHistory;

    // -----------------------------------------------------------------
    //  Clipboard / download / share
    // -----------------------------------------------------------------

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = el('textarea', { 'aria-hidden': 'true' });
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); resolve(); }
            catch (e) { reject(e); }
            finally { document.body.removeChild(ta); }
        });
    }

    /** ToolCopyButton */
    function ToolCopyButton(getText, label) {
        var button = el('button', {
            type: 'button',
            class: 'ch247-btn ch247-btn--ghost ch247-copy',
            'data-action': 'copy'
        }, [el('span', { class: 'ch247-btn__label', text: label || 'Copy' })]);

        var status = el('span', { class: 'ch247-sr-only', role: 'status', 'aria-live': 'polite' });

        button.addEventListener('click', function () {
            var text = typeof getText === 'function' ? getText() : getText;
            if (!text) { return; }
            copyText(text).then(function () {
                button.querySelector('.ch247-btn__label').textContent = 'Copied';
                button.classList.add('is-copied');
                status.textContent = 'Copied to clipboard';
                window.setTimeout(function () {
                    button.querySelector('.ch247-btn__label').textContent = label || 'Copy';
                    button.classList.remove('is-copied');
                }, 2000);
            }).catch(function () {
                status.textContent = 'Copy failed. Select the text and press Control C.';
            });
        });

        var wrap = el('span', { class: 'ch247-copy-wrap' }, [button, status]);
        wrap.button = button;
        return wrap;
    }

    /** ToolDownloadButton */
    function ToolDownloadButton(getContent, filename, mime, label) {
        return el('button', {
            type: 'button',
            class: 'ch247-btn ch247-btn--ghost',
            'data-action': 'download',
            onclick: function () {
                var content = typeof getContent === 'function' ? getContent() : getContent;
                if (!content) { return; }
                var blob = new Blob([content], { type: mime || 'text/plain;charset=utf-8' });
                var url = URL.createObjectURL(blob);
                var a = el('a', { href: url, download: filename || 'cloudhost247-result.txt' });
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            }
        }, [el('span', { class: 'ch247-btn__label', text: label || 'Download' })]);
    }

    /**
     * ToolShareButton - shares the tool URL with the input prefilled.
     * Never includes values from a sensitive field.
     */
    function ToolShareButton(getParams, sensitive) {
        var status = el('span', { class: 'ch247-sr-only', role: 'status', 'aria-live': 'polite' });
        var button = el('button', {
            type: 'button',
            class: 'ch247-btn ch247-btn--ghost',
            'data-action': 'share',
            onclick: function () {
                var url = window.location.origin + window.location.pathname;
                if (!sensitive) {
                    var params = typeof getParams === 'function' ? getParams() : {};
                    var qs = Object.keys(params || {})
                        .filter(function (k) { return params[k] !== '' && params[k] !== null; })
                        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
                        .join('&');
                    if (qs) { url += '?' + qs; }
                }
                if (navigator.share) {
                    navigator.share({ title: document.title, url: url }).catch(function () {});
                } else {
                    copyText(url).then(function () { status.textContent = 'Link copied to clipboard'; });
                }
            }
        }, [el('span', { class: 'ch247-btn__label', text: 'Share' })]);

        return el('span', {}, [button, status]);
    }

    CH247.ToolCopyButton = ToolCopyButton;
    CH247.ToolDownloadButton = ToolDownloadButton;
    CH247.ToolShareButton = ToolShareButton;

    // -----------------------------------------------------------------
    //  Result rendering
    // -----------------------------------------------------------------

    var STATUS_META = {
        pass: { icon: '\u2713', label: 'Pass' },
        fail: { icon: '\u2715', label: 'Fail' },
        warn: { icon: '\u26A0', label: 'Warning' },
        info: { icon: '\u2139', label: 'Info' },
        na:   { icon: '\u2013', label: 'Not applicable' }
    };

    /** Status chip - uses icon + text so meaning never depends on colour. */
    function statusChip(status) {
        var meta = STATUS_META[status] || STATUS_META.info;
        return el('span', { class: 'ch247-chip ch247-chip--' + (status || 'info') }, [
            el('span', { class: 'ch247-chip__icon', 'aria-hidden': 'true', text: meta.icon }),
            el('span', { class: 'ch247-chip__label', text: meta.label })
        ]);
    }

    /** Key/value definition list. */
    function kvList(obj, order) {
        var dl = el('dl', { class: 'ch247-kv' });
        (order || Object.keys(obj)).forEach(function (key) {
            if (!(key in obj)) { return; }
            var value = obj[key];
            if (value === null || value === undefined || value === '' ||
                (Array.isArray(value) && !value.length)) { return; }
            dl.appendChild(el('dt', { text: humanLabel(key) }));
            var dd = el('dd');
            if (Array.isArray(value)) {
                dd.appendChild(el('ul', { class: 'ch247-list' }, value.map(function (v) {
                    return el('li', { text: typeof v === 'object' ? JSON.stringify(v) : String(v) });
                })));
            } else if (typeof value === 'object') {
                dd.appendChild(el('pre', { class: 'ch247-pre', text: JSON.stringify(value, null, 2) }));
            } else if (typeof value === 'boolean') {
                dd.appendChild(statusChip(value ? 'pass' : 'fail'));
            } else {
                dd.textContent = String(value);
            }
            dl.appendChild(dd);
        });
        return dl;
    }

    function humanLabel(key) {
        return String(key)
            .replace(/_/g, ' ')
            .replace(/\b\w/g, function (c) { return c.toUpperCase(); })
            .replace(/\bIp\b/g, 'IP').replace(/\bUrl\b/g, 'URL')
            .replace(/\bDns\b/g, 'DNS').replace(/\bTtl\b/g, 'TTL')
            .replace(/\bSsl\b/g, 'SSL').replace(/\bApi\b/g, 'API')
            .replace(/\bSeo\b/g, 'SEO').replace(/\bMx\b/g, 'MX')
            .replace(/\bId\b/g, 'ID').replace(/\bUtf8\b/g, 'UTF-8');
    }
    CH247.humanLabel = humanLabel;

    /**
     * Responsive data table. Each cell carries a data-label so the table
     * can reflow to stacked cards on narrow screens without losing headers.
     */
    function dataTable(columns, rows, caption) {
        var table = el('table', { class: 'ch247-table' });
        if (caption) {
            table.appendChild(el('caption', { class: 'ch247-table__caption', text: caption }));
        }
        var thead = el('thead');
        thead.appendChild(el('tr', {}, columns.map(function (c) {
            return el('th', { scope: 'col', text: c.label });
        })));
        table.appendChild(thead);

        var tbody = el('tbody');
        rows.forEach(function (row) {
            var tr = el('tr');
            columns.forEach(function (c) {
                var value = typeof c.value === 'function' ? c.value(row) : row[c.key];
                var td = el('td', { 'data-label': c.label });
                if (window.Node && value instanceof window.Node) { td.appendChild(value); }
                else if (c.key === 'status' || c.status) { td.appendChild(statusChip(value)); }
                else { td.textContent = value === null || value === undefined ? '\u2014' : String(value); }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);

        return el('div', { class: 'ch247-table-wrap', tabindex: '0', role: 'region',
            'aria-label': caption || 'Results table' }, [table]);
    }

    /** Checks list - the shared renderer for every diagnostic tool. */
    function checksList(checks) {
        return el('ul', { class: 'ch247-checks' }, checks.map(function (check) {
            return el('li', { class: 'ch247-check ch247-check--' + (check.status || 'info') }, [
                el('div', { class: 'ch247-check__head' }, [
                    statusChip(check.status),
                    el('span', { class: 'ch247-check__name', text: check.name })
                ]),
                el('p', { class: 'ch247-check__detail', text: check.detail || '' })
            ]);
        }));
    }

    CH247.statusChip = statusChip;
    CH247.kvList = kvList;
    CH247.dataTable = dataTable;
    CH247.checksList = checksList;

    // -----------------------------------------------------------------
    //  ToolPage - the controller each per-tool module instantiates
    // -----------------------------------------------------------------

    /**
     * @param {object} config
     *   slug        tool slug (required)
     *   exec        'client' | 'server' | 'hybrid'
     *   sensitive   true to disable history, share params and analytics
     *   fields      input descriptors (see buildField)
     *   submitLabel button text
     *   run         function(values, ctx) -> result | Promise<result>
     *               (client tools only; server tools post to the API)
     *   render      function(data, ctx) -> Node | string  renders results
     *   summary     function(values, data) -> string for the history entry
     *   validate    function(values) -> string|null  client-side validation
     *   autoRun     run once on load (e.g. "My IP")
     */
    function ToolPage(config) {
        if (!(this instanceof ToolPage)) { return new ToolPage(config); }
        this.cfg = config || {};
        this.slug = this.cfg.slug;
        this.exec = this.cfg.exec || 'server';
        this.sensitive = !!this.cfg.sensitive;
        this.root = document.querySelector('[data-ch247-tool="' + this.slug + '"]');
        if (!this.root) { return; }
        this.init();
    }

    ToolPage.prototype.init = function () {
        var self = this;

        this.formEl    = this.root.querySelector('[data-ch247-form]');
        this.fieldsEl  = this.root.querySelector('[data-ch247-fields]');
        this.resultEl  = this.root.querySelector('[data-ch247-result]');
        this.errorEl   = this.root.querySelector('[data-ch247-error]');
        this.loadingEl = this.root.querySelector('[data-ch247-loading]');
        this.historyEl = this.root.querySelector('[data-ch247-history]');
        this.csrf      = this.root.getAttribute('data-csrf') || '';

        if (this.fieldsEl && this.cfg.fields) {
            this.cfg.fields.forEach(function (field) {
                self.fieldsEl.appendChild(self.buildField(field));
            });
        }

        if (this.formEl) {
            this.formEl.addEventListener('submit', function (event) {
                event.preventDefault();
                self.submit();
            });
            this.formEl.addEventListener('reset', function () {
                window.setTimeout(function () { self.reset(); }, 0);
            });
        }

        this.renderHistory();
        this.prefillFromQuery();

        // Tools with bespoke UI behaviour (file pickers, autosave) hook here.
        if (typeof this.cfg.mount === 'function') {
            try { this.cfg.mount(this); }
            catch (e) { this.showError('This tool could not initialise: ' + (e.message || e)); }
        }

        if (this.cfg.autoRun) { this.submit(); }
    };

    /** ToolInput - builds one labelled, described, accessible control. */
    ToolPage.prototype.buildField = function (field) {
        var id = 'ch247-' + this.slug + '-' + field.name;
        var describedBy = [];
        var control;

        if (field.type === 'select') {
            control = el('select', { id: id, name: field.name, class: 'ch247-input' },
                (field.options || []).map(function (opt) {
                    return el('option', {
                        value: opt.value,
                        selected: opt.value === field.value ? true : null,
                        text: opt.label
                    });
                }));
        } else if (field.type === 'textarea') {
            control = el('textarea', {
                id: id, name: field.name, class: 'ch247-input ch247-input--area',
                rows: field.rows || 8, placeholder: field.placeholder || '',
                spellcheck: field.spellcheck === false ? 'false' : null
            });
            if (field.value) { control.value = field.value; }
        } else if (field.type === 'checkbox') {
            control = el('input', { type: 'checkbox', id: id, name: field.name,
                class: 'ch247-checkbox', checked: field.value ? true : null });
        } else {
            control = el('input', {
                type: field.type || 'text', id: id, name: field.name, class: 'ch247-input',
                placeholder: field.placeholder || '', value: field.value || '',
                min: field.min, max: field.max, step: field.step,
                autocomplete: field.autocomplete || (this.sensitive ? 'off' : null),
                inputmode: field.inputmode || null,
                spellcheck: field.spellcheck === false ? 'false' : null
            });
        }

        if (field.required) { control.setAttribute('required', ''); control.setAttribute('aria-required', 'true'); }

        var hint = null;
        if (field.hint) {
            hint = el('p', { class: 'ch247-field__hint', id: id + '-hint', text: field.hint });
            describedBy.push(id + '-hint');
        }
        if (describedBy.length) { control.setAttribute('aria-describedby', describedBy.join(' ')); }

        var label = el('label', { class: 'ch247-field__label', for: id }, [
            document.createTextNode(field.label),
            field.required ? el('span', { class: 'ch247-field__req', 'aria-hidden': 'true', text: ' *' }) : null,
            field.required ? el('span', { class: 'ch247-sr-only', text: ' (required)' }) : null
        ]);

        var wrap = el('div', {
            class: 'ch247-field ch247-field--' + (field.type || 'text') + (field.width ? ' ch247-field--' + field.width : '')
        }, field.type === 'checkbox'
            ? [el('div', { class: 'ch247-field__inline' }, [control, label]), hint]
            : [label, control, hint]);

        return wrap;
    };

    ToolPage.prototype.values = function () {
        var out = {};
        if (!this.formEl) { return out; }
        (this.cfg.fields || []).forEach(function (field) {
            var node = this.formEl.elements[field.name];
            if (!node) { return; }
            out[field.name] = node.type === 'checkbox' ? node.checked : node.value;
        }, this);
        return out;
    };

    ToolPage.prototype.prefillFromQuery = function () {
        if (this.sensitive) { return; }
        var params = new URLSearchParams(window.location.search);
        var touched = false;
        (this.cfg.fields || []).forEach(function (field) {
            if (!params.has(field.name)) { return; }
            var node = this.formEl && this.formEl.elements[field.name];
            if (!node) { return; }
            if (node.type === 'checkbox') { node.checked = params.get(field.name) === '1'; }
            else { node.value = params.get(field.name); }
            touched = true;
        }, this);
        if (touched && this.cfg.autoRunOnPrefill !== false) { this.submit(); }
    };

    ToolPage.prototype.setLoading = function (isLoading) {
        if (this.loadingEl) {
            this.loadingEl.hidden = !isLoading;
            this.loadingEl.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        }
        var submit = this.formEl && this.formEl.querySelector('[type="submit"]');
        if (submit) {
            submit.disabled = isLoading;
            submit.setAttribute('aria-disabled', isLoading ? 'true' : 'false');
        }
        this.root.classList.toggle('is-loading', !!isLoading);
    };

    /** ToolError */
    ToolPage.prototype.showError = function (message, code) {
        if (!this.errorEl) { return; }
        this.errorEl.hidden = false;
        this.errorEl.innerHTML = '';
        this.errorEl.appendChild(el('div', { class: 'ch247-alert ch247-alert--error', role: 'alert' }, [
            el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: '\u2715' }),
            el('div', {}, [
                el('strong', { class: 'ch247-alert__title', text: 'Could not complete' }),
                el('p', { class: 'ch247-alert__body', text: message })
            ])
        ]));
        if (this.resultEl) { this.resultEl.hidden = true; }
        this.errorEl.setAttribute('tabindex', '-1');
        this.errorEl.focus({ preventScroll: prefersReducedMotion() });
    };

    ToolPage.prototype.clearError = function () {
        if (this.errorEl) { this.errorEl.hidden = true; this.errorEl.innerHTML = ''; }
    };

    ToolPage.prototype.reset = function () {
        this.clearError();
        if (this.resultEl) { this.resultEl.hidden = true; this.resultEl.innerHTML = ''; }
        var first = this.formEl && this.formEl.querySelector('.ch247-input');
        if (first) { first.focus(); }
    };

    /** ToolResult */
    ToolPage.prototype.showResult = function (data, values) {
        if (!this.resultEl) { return; }
        this.clearError();
        this.resultEl.hidden = false;
        this.resultEl.innerHTML = '';

        var body = el('div', { class: 'ch247-result__body' });
        var rendered = this.cfg.render ? this.cfg.render(data, this) : kvList(data);
        if (typeof rendered === 'string') { body.innerHTML = rendered; }
        else if (rendered) { body.appendChild(rendered); }

        var self = this;
        var textFor = function () {
            if (self.cfg.copyText) { return self.cfg.copyText(data, values); }
            return body.innerText;
        };

        var actions = el('div', { class: 'ch247-result__actions' }, [
            ToolCopyButton(textFor, 'Copy result'),
            ToolDownloadButton(function () {
                return self.cfg.downloadText ? self.cfg.downloadText(data, values) : textFor();
            }, (self.slug || 'result') + '.txt', 'text/plain;charset=utf-8', 'Download'),
            ToolShareButton(function () { return values; }, self.sensitive)
        ]);

        this.resultEl.appendChild(el('div', { class: 'ch247-result' }, [
            el('div', { class: 'ch247-result__head' }, [
                el('h2', { class: 'ch247-result__title', text: 'Results' }),
                actions
            ]),
            body
        ]));

        this.resultEl.setAttribute('tabindex', '-1');
        this.resultEl.focus({ preventScroll: prefersReducedMotion() });

        if (!this.sensitive) {
            ToolHistory.add(this.slug, {
                summary: this.cfg.summary ? this.cfg.summary(values, data) : JSON.stringify(values).slice(0, 80),
                input: values
            }, { sensitive: this.sensitive });
            this.renderHistory();
        }
    };

    ToolPage.prototype.submit = function () {
        var self = this;
        var values = this.values();

        if (this.cfg.validate) {
            var problem = this.cfg.validate(values);
            if (problem) { this.showError(problem); return; }
        }

        this.clearError();
        this.setLoading(true);

        var finish = function (data) {
            self.setLoading(false);
            self.showResult(data, values);
        };
        var failed = function (message) {
            self.setLoading(false);
            self.showError(message);
        };

        if (this.exec === 'client' && typeof this.cfg.run === 'function') {
            try {
                var out = this.cfg.run(values, this);
                if (out && typeof out.then === 'function') {
                    out.then(finish).catch(function (e) { failed(e.message || String(e)); });
                } else if (out && out.error) {
                    failed(out.error);
                } else {
                    finish(out);
                }
            } catch (e) {
                failed(e.message || String(e));
            }
            return;
        }

        this.call(values).then(finish).catch(function (e) { failed(e.message || String(e)); });
    };

    /** POST to the tool API endpoint. */
    ToolPage.prototype.call = function (values, action) {
        var self = this;
        var payload = Object.assign({}, values);
        if (action) { payload.action = action; }
        payload.csrf_token = this.csrf;

        return window.fetch('/tools/api/' + encodeURIComponent(this.slug), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('The server returned an unreadable response.');
            }).then(function (json) {
                if (!json || json.success !== true) {
                    var msg = (json && json.error && json.error.message) ||
                        'Request failed with status ' + response.status + '.';
                    throw new Error(msg);
                }
                if (json.meta && json.meta.rate_limit) { self.showQuota(json.meta.rate_limit); }
                return json.data;
            });
        });
    };

    ToolPage.prototype.showQuota = function (quota) {
        var node = this.root.querySelector('[data-ch247-quota]');
        if (!node) { return; }
        node.hidden = quota.remaining > 5;
        node.textContent = quota.remaining + ' of ' + quota.limit + ' requests remaining this minute.';
    };

    /** ToolHistory rendering, with a Clear History control. */
    ToolPage.prototype.renderHistory = function () {
        if (!this.historyEl || this.sensitive) {
            if (this.historyEl) { this.historyEl.hidden = true; }
            return;
        }
        var self = this;
        var entries = ToolHistory.read(this.slug);
        this.historyEl.innerHTML = '';
        if (!entries.length) { this.historyEl.hidden = true; return; }
        this.historyEl.hidden = false;

        this.historyEl.appendChild(el('div', { class: 'ch247-history__head' }, [
            el('h2', { class: 'ch247-history__title', text: 'Recent runs' }),
            el('button', {
                type: 'button', class: 'ch247-btn ch247-btn--ghost ch247-btn--sm',
                text: 'Clear history',
                onclick: function () {
                    ToolHistory.clear(self.slug);
                    self.renderHistory();
                    var live = document.getElementById('ch247-live');
                    if (live) { live.textContent = 'History cleared.'; }
                }
            })
        ]));

        this.historyEl.appendChild(el('ul', { class: 'ch247-history__list' }, entries.map(function (entry) {
            return el('li', { class: 'ch247-history__item' }, [
                el('button', {
                    type: 'button', class: 'ch247-history__replay',
                    text: entry.summary || '(no summary)',
                    onclick: function () {
                        Object.keys(entry.input || {}).forEach(function (key) {
                            var node = self.formEl && self.formEl.elements[key];
                            if (!node) { return; }
                            if (node.type === 'checkbox') { node.checked = !!entry.input[key]; }
                            else { node.value = entry.input[key]; }
                        });
                        self.submit();
                    }
                }),
                el('time', { class: 'ch247-history__time',
                    datetime: new Date(entry.at).toISOString(),
                    text: new Date(entry.at).toLocaleString() })
            ]);
        })));
    };

    CH247.ToolPage = ToolPage;

    // -----------------------------------------------------------------
    //  Page furniture: FAQ, nav, filters
    // -----------------------------------------------------------------

    function initFaq() {
        document.querySelectorAll('[data-ch247-faq] .ch247-faq__q').forEach(function (button) {
            button.addEventListener('click', function () {
                var expanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                var panel = document.getElementById(button.getAttribute('aria-controls'));
                if (panel) { panel.hidden = expanded; }
            });
        });
    }

    /** Mega menu + mobile accordion, both keyboard accessible. */
    function initNav() {
        var trigger = document.querySelector('[data-ch247-megamenu-trigger]');
        var menu = document.querySelector('[data-ch247-megamenu]');
        if (trigger && menu) {
            var close = function () {
                trigger.setAttribute('aria-expanded', 'false');
                menu.hidden = true;
            };
            var open = function () {
                trigger.setAttribute('aria-expanded', 'true');
                menu.hidden = false;
            };
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                trigger.getAttribute('aria-expanded') === 'true' ? close() : open();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && trigger.getAttribute('aria-expanded') === 'true') {
                    close(); trigger.focus();
                }
            });
            document.addEventListener('click', function (e) {
                if (trigger.getAttribute('aria-expanded') !== 'true') { return; }
                if (!menu.contains(e.target) && !trigger.contains(e.target)) { close(); }
            });
        }

        document.querySelectorAll('[data-ch247-accordion-trigger]').forEach(function (button) {
            button.addEventListener('click', function () {
                var expanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                var panel = document.getElementById(button.getAttribute('aria-controls'));
                if (panel) { panel.hidden = expanded; }
            });
        });
    }

    /** Live filter on the index and category pages. */
    function initFilter() {
        var input = document.querySelector('[data-ch247-filter]');
        if (!input) { return; }
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-ch247-tool-card]'));
        var count = document.querySelector('[data-ch247-filter-count]');
        var groups = Array.prototype.slice.call(document.querySelectorAll('[data-ch247-tool-group]'));

        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            var shown = 0;
            cards.forEach(function (card) {
                var haystack = (card.getAttribute('data-search') || '').toLowerCase();
                var match = !q || haystack.indexOf(q) !== -1;
                card.hidden = !match;
                if (match) { shown++; }
            });
            groups.forEach(function (group) {
                var any = group.querySelectorAll('[data-ch247-tool-card]:not([hidden])').length;
                group.hidden = any === 0;
            });
            if (count) {
                count.textContent = q
                    ? shown + (shown === 1 ? ' tool matches' : ' tools match') + ' "' + input.value.trim() + '"'
                    : shown + ' tools';
            }
        });
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else { fn(); }
    }

    ready(function () {
        initFaq();
        initNav();
        initFilter();
        if (!document.getElementById('ch247-live')) {
            document.body.appendChild(el('div', {
                id: 'ch247-live', class: 'ch247-sr-only', role: 'status', 'aria-live': 'polite'
            }));
        }
    });


    // -----------------------------------------------------------------
    //  IP address helpers (shared by the IP and IPv6 tools)
    // -----------------------------------------------------------------

    var ip = {};

    ip.isV4 = function (value) {
        var m = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(String(value).trim());
        if (!m) { return false; }
        for (var i = 1; i <= 4; i++) {
            if (Number(m[i]) > 255 || (m[i].length > 1 && m[i][0] === '0')) { return false; }
        }
        return true;
    };

    ip.v4ToLong = function (value) {
        return String(value).split('.').reduce(function (acc, o) { return (acc * 256) + Number(o); }, 0);
    };

    ip.longToV4 = function (n) {
        n = n >>> 0;
        return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join('.');
    };

    /** Parse an IPv6 string into 8 groups of 16 bits, or null. */
    ip.parseV6 = function (value) {
        value = String(value).trim().replace(/^\[|\]$/g, '');
        if (!value || value.indexOf(':') === -1) { return null; }
        if ((value.match(/::/g) || []).length > 1) { return null; }

        // An embedded IPv4 tail becomes two hex groups.
        var v4 = /(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/.exec(value);
        if (v4) {
            if (!ip.isV4(v4[1])) { return null; }
            var o = v4[1].split('.').map(Number);
            value = value.slice(0, v4.index) +
                ((o[0] << 8 | o[1]).toString(16)) + ':' + ((o[2] << 8 | o[3]).toString(16));
        }

        var halves = value.split('::');
        if (halves.length > 2) { return null; }
        var head = halves[0] ? halves[0].split(':') : [];
        var tail = halves.length === 2 ? (halves[1] ? halves[1].split(':') : []) : null;

        var groups;
        if (tail === null) {
            if (head.length !== 8) { return null; }
            groups = head;
        } else {
            if (head.length + tail.length > 7) { return null; }
            groups = head.concat(new Array(8 - head.length - tail.length).fill('0'), tail);
        }

        var out = [];
        for (var i = 0; i < 8; i++) {
            var g = groups[i];
            if (g === '' || !/^[0-9a-fA-F]{1,4}$/.test(g)) { return null; }
            out.push(parseInt(g, 16));
        }
        return out;
    };

    ip.isV6 = function (value) { return ip.parseV6(value) !== null; };

    /** 8 groups -> full 39-character notation. */
    ip.expandV6 = function (groups) {
        return groups.map(function (g) {
            return ('000' + g.toString(16)).slice(-4);
        }).join(':');
    };

    /** 8 groups -> RFC 5952 canonical compressed form. */
    ip.compressV6 = function (groups) {
        var parts = groups.map(function (g) { return g.toString(16); });
        var bestStart = -1, bestLen = 0, curStart = -1, curLen = 0;
        for (var i = 0; i < 8; i++) {
            if (groups[i] === 0) {
                if (curStart === -1) { curStart = i; curLen = 1; } else { curLen++; }
                if (curLen > bestLen) { bestLen = curLen; bestStart = curStart; }
            } else { curStart = -1; curLen = 0; }
        }
        if (bestLen < 2) { return parts.join(':'); }
        var head = parts.slice(0, bestStart).join(':');
        var tail = parts.slice(bestStart + bestLen).join(':');
        return head + '::' + tail;
    };

    ip.v6ToHex = function (groups) {
        return groups.map(function (g) { return ('000' + g.toString(16)).slice(-4); }).join('');
    };

    /** ip6.arpa reverse pointer. */
    ip.v6Arpa = function (groups) {
        return ip.v6ToHex(groups).split('').reverse().join('.') + '.ip6.arpa';
    };

    /** BigInt value of an IPv6 address. */
    ip.v6ToBig = function (groups) {
        return groups.reduce(function (acc, g) { return (acc << 16n) | BigInt(g); }, 0n);
    };

    ip.bigToV6 = function (big) {
        var groups = [];
        for (var i = 7; i >= 0; i--) {
            groups[i] = Number(big & 0xffffn);
            big >>= 16n;
        }
        return groups;
    };

    CH247.ip = ip;

    // -----------------------------------------------------------------
    //  Hashing (SubtleCrypto where available, pure-JS MD5 fallback)
    // -----------------------------------------------------------------

    var hash = {};

    hash.subtle = function (algo, text) {
        if (!window.crypto || !window.crypto.subtle) {
            return Promise.reject(new Error('This browser does not expose SubtleCrypto over an insecure origin.'));
        }
        var map = { sha1: 'SHA-1', sha256: 'SHA-256', sha384: 'SHA-384', sha512: 'SHA-512' };
        return window.crypto.subtle
            .digest(map[algo], new TextEncoder().encode(text))
            .then(function (buf) {
                return Array.prototype.map.call(new Uint8Array(buf), function (b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
            });
    };

    /** Compact MD5 - needed because SubtleCrypto deliberately omits it. */
    hash.md5 = function (str) {
        function rl(n, c) { return (n << c) | (n >>> (32 - c)); }
        function au(x, y) {
            var l = (x & 0xFFFF) + (y & 0xFFFF);
            return (((x >> 16) + (y >> 16) + (l >> 16)) << 16) | (l & 0xFFFF);
        }
        function cmn(q, a, b, x, s, t) { return au(rl(au(au(a, q), au(x, t)), s), b); }
        function ff(a,b,c,d,x,s,t){return cmn((b&c)|(~b&d),a,b,x,s,t);}
        function gg(a,b,c,d,x,s,t){return cmn((b&d)|(c&~d),a,b,x,s,t);}
        function hh(a,b,c,d,x,s,t){return cmn(b^c^d,a,b,x,s,t);}
        function ii(a,b,c,d,x,s,t){return cmn(c^(b|~d),a,b,x,s,t);}

        // UTF-8 encode first so non-ASCII hashes match the server.
        var bytes = new TextEncoder().encode(str);
        var n = bytes.length;
        var words = [];
        for (var i = 0; i < n; i++) { words[i >> 2] = (words[i >> 2] || 0) | (bytes[i] << ((i % 4) * 8)); }
        words[n >> 2] = (words[n >> 2] || 0) | (0x80 << ((n % 4) * 8));
        var len = (((n + 8) >> 6) + 1) * 16;
        for (var j = words.length; j < len; j++) { words[j] = 0; }
        words[len - 2] = n * 8;

        var a = 1732584193, b = -271733879, c = -1732584194, d = 271733878;
        for (var k = 0; k < words.length; k += 16) {
            var oa=a, ob=b, oc=c, od=d, x=words.slice(k, k+16);
            a=ff(a,b,c,d,x[0],7,-680876936);   d=ff(d,a,b,c,x[1],12,-389564586);
            c=ff(c,d,a,b,x[2],17,606105819);   b=ff(b,c,d,a,x[3],22,-1044525330);
            a=ff(a,b,c,d,x[4],7,-176418897);   d=ff(d,a,b,c,x[5],12,1200080426);
            c=ff(c,d,a,b,x[6],17,-1473231341); b=ff(b,c,d,a,x[7],22,-45705983);
            a=ff(a,b,c,d,x[8],7,1770035416);   d=ff(d,a,b,c,x[9],12,-1958414417);
            c=ff(c,d,a,b,x[10],17,-42063);     b=ff(b,c,d,a,x[11],22,-1990404162);
            a=ff(a,b,c,d,x[12],7,1804603682);  d=ff(d,a,b,c,x[13],12,-40341101);
            c=ff(c,d,a,b,x[14],17,-1502002290);b=ff(b,c,d,a,x[15],22,1236535329);
            a=gg(a,b,c,d,x[1],5,-165796510);   d=gg(d,a,b,c,x[6],9,-1069501632);
            c=gg(c,d,a,b,x[11],14,643717713);  b=gg(b,c,d,a,x[0],20,-373897302);
            a=gg(a,b,c,d,x[5],5,-701558691);   d=gg(d,a,b,c,x[10],9,38016083);
            c=gg(c,d,a,b,x[15],14,-660478335); b=gg(b,c,d,a,x[4],20,-405537848);
            a=gg(a,b,c,d,x[9],5,568446438);    d=gg(d,a,b,c,x[14],9,-1019803690);
            c=gg(c,d,a,b,x[3],14,-187363961);  b=gg(b,c,d,a,x[8],20,1163531501);
            a=gg(a,b,c,d,x[13],5,-1444681467); d=gg(d,a,b,c,x[2],9,-51403784);
            c=gg(c,d,a,b,x[7],14,1735328473);  b=gg(b,c,d,a,x[12],20,-1926607734);
            a=hh(a,b,c,d,x[5],4,-378558);      d=hh(d,a,b,c,x[8],11,-2022574463);
            c=hh(c,d,a,b,x[11],16,1839030562); b=hh(b,c,d,a,x[14],23,-35309556);
            a=hh(a,b,c,d,x[1],4,-1530992060);  d=hh(d,a,b,c,x[4],11,1272893353);
            c=hh(c,d,a,b,x[7],16,-155497632);  b=hh(b,c,d,a,x[10],23,-1094730640);
            a=hh(a,b,c,d,x[13],4,681279174);   d=hh(d,a,b,c,x[0],11,-358537222);
            c=hh(c,d,a,b,x[3],16,-722521979);  b=hh(b,c,d,a,x[6],23,76029189);
            a=hh(a,b,c,d,x[9],4,-640364487);   d=hh(d,a,b,c,x[12],11,-421815835);
            c=hh(c,d,a,b,x[15],16,530742520);  b=hh(b,c,d,a,x[2],23,-995338651);
            a=ii(a,b,c,d,x[0],6,-198630844);   d=ii(d,a,b,c,x[7],10,1126891415);
            c=ii(c,d,a,b,x[14],15,-1416354905);b=ii(b,c,d,a,x[5],21,-57434055);
            a=ii(a,b,c,d,x[12],6,1700485571);  d=ii(d,a,b,c,x[3],10,-1894986606);
            c=ii(c,d,a,b,x[10],15,-1051523);   b=ii(b,c,d,a,x[1],21,-2054922799);
            a=ii(a,b,c,d,x[8],6,1873313359);   d=ii(d,a,b,c,x[15],10,-30611744);
            c=ii(c,d,a,b,x[6],15,-1560198380); b=ii(b,c,d,a,x[13],21,1309151649);
            a=ii(a,b,c,d,x[4],6,-145523070);   d=ii(d,a,b,c,x[11],10,-1120210379);
            c=ii(c,d,a,b,x[2],15,718787259);   b=ii(b,c,d,a,x[9],21,-343485551);
            a=au(a,oa); b=au(b,ob); c=au(c,oc); d=au(d,od);
        }
        return [a,b,c,d].map(function (v) {
            var out = '';
            for (var i = 0; i < 4; i++) { out += ('0' + ((v >> (i * 8)) & 255).toString(16)).slice(-2); }
            return out;
        }).join('');
    };

    /** UTF-8 safe base64. */
    hash.b64encode = function (str) {
        var bytes = new TextEncoder().encode(str);
        var bin = '';
        bytes.forEach(function (b) { bin += String.fromCharCode(b); });
        return window.btoa(bin);
    };

    hash.b64decode = function (str) {
        var bin = window.atob(String(str).replace(/\s+/g, ''));
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        return new TextDecoder().decode(bytes);
    };

    CH247.hash = hash;

    CH247.ready = ready;
})(window, document);
