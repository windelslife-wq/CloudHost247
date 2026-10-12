/**
 * CloudHost247 Tools - Username Profile Links
 *
 * Route-split module: this file is loaded only on /tools/name-checker.
 * Execution: server. Provider availability is not queried or inferred.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "name-checker",
            exec: "server",
            fields: [{
                "name": "username",
                "label": "Username",
                "placeholder": "username",
                "hint": "Use 3–30 letters, numbers, periods, underscores or hyphens. Availability is not checked.",
                "required": true,
                "spellcheck": false
            }],
            render: function (data) {
                var wrap = CH.el('div');
                if (!data || typeof data !== 'object') {
                    wrap.appendChild(CH.el('pre', {
                        class: 'ch247-pre ch247-pre--wrap',
                        text: String(data)
                    }));
                    return wrap;
                }
                if (data.error) {
                    wrap.appendChild(CH.el('div', {
                        class: 'ch247-alert ch247-alert--warn',
                        role: 'alert',
                        text: data.error
                    }));
                    return wrap;
                }

                if (data.username) {
                    wrap.appendChild(CH.el('p', {
                        class: 'ch247-prose',
                        text: 'Profile links for “' + data.username + '”.'
                    }));
                }
                if (data.note) {
                    wrap.appendChild(CH.el('p', {
                        class: 'ch247-notice',
                        text: data.note
                    }));
                }

                if (Array.isArray(data.platforms) && data.platforms.length) {
                    var table = CH.el('table', { class: 'ch247-table' });
                    var thead = CH.el('thead');
                    var header = CH.el('tr');
                    ['Platform', 'Status', 'Manual check'].forEach(function (label) {
                        header.appendChild(CH.el('th', { scope: 'col', text: label }));
                    });
                    thead.appendChild(header);
                    table.appendChild(thead);

                    var tbody = CH.el('tbody');
                    data.platforms.forEach(function (platform) {
                        var row = CH.el('tr');
                        row.appendChild(CH.el('td', { text: String(platform.name || 'Platform') }));
                        row.appendChild(CH.el('td', { text: String(platform.status || 'Manual check required') }));
                        var action = CH.el('td');
                        var href = '';
                        try {
                            var url = new URL(String(platform.url || ''));
                            if (url.protocol === 'https:' && !url.username && !url.password) {
                                href = url.href;
                            }
                        } catch (e) {}
                        if (href) {
                            action.appendChild(CH.el('a', {
                                href: href,
                                target: '_blank',
                                rel: 'noopener noreferrer',
                                text: 'Open profile'
                            }));
                        } else {
                            action.textContent = 'Unavailable';
                        }
                        row.appendChild(action);
                        tbody.appendChild(row);
                    });
                    table.appendChild(tbody);
                    var tableWrap = CH.el('div', { class: 'ch247-table-wrap' });
                    tableWrap.appendChild(table);
                    wrap.appendChild(tableWrap);
                }
                return wrap;
            },
            submitLabel: "Generate profile links"
        });
    });
})(window, document);
