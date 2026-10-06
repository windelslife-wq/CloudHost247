/**
 * CloudHost247 Tools - What Is My User Agent
 *
 * Route-split module: this file is loaded only on /tools/user-agent.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "user-agent",
            exec: "client",
            fields: [],
            autoRun: true,
            run: function () {
              var ua = navigator.userAgent || '';
              var pick = function (re) { var m = re.exec(ua); return m ? m[1] : null; };
              var browser = 'Unknown', version = null;
              if (/Edg\//.test(ua))            { browser = 'Microsoft Edge'; version = pick(/Edg\/([\d.]+)/); }
              else if (/OPR\/|Opera/.test(ua)) { browser = 'Opera';          version = pick(/(?:OPR|Opera)[\/ ]([\d.]+)/); }
              else if (/Firefox\//.test(ua))   { browser = 'Firefox';        version = pick(/Firefox\/([\d.]+)/); }
              else if (/Chrome\//.test(ua))    { browser = 'Chrome';         version = pick(/Chrome\/([\d.]+)/); }
              else if (/Safari\//.test(ua))    { browser = 'Safari';         version = pick(/Version\/([\d.]+)/); }
              var os = 'Unknown';
              if (/Windows NT 10/.test(ua)) { os = 'Windows 10 or 11'; }
              else if (/Windows NT/.test(ua)) { os = 'Windows ' + (pick(/Windows NT ([\d.]+)/) || ''); }
              else if (/Android/.test(ua))  { os = 'Android ' + (pick(/Android ([\d.]+)/) || ''); }
              else if (/iPhone|iPad|iPod/.test(ua)) { os = 'iOS ' + ((pick(/OS ([\d_]+)/) || '').replace(/_/g, '.')); }
              else if (/Mac OS X/.test(ua)) { os = 'macOS ' + ((pick(/Mac OS X ([\d_]+)/) || '').replace(/_/g, '.')); }
              else if (/Linux/.test(ua))    { os = 'Linux'; }
              return {
                user_agent: ua,
                browser: browser, browser_version: version || 'not reported',
                operating_system: os,
                platform: navigator.platform || 'not reported',
                languages: (navigator.languages || [navigator.language]).join(', '),
                cookies_enabled: navigator.cookieEnabled,
                do_not_track: navigator.doNotTrack === '1' ? 'enabled' : 'not enabled',
                screen: window.screen ? window.screen.width + ' x ' + window.screen.height + ' @ ' + (window.devicePixelRatio || 1) + 'x' : 'unknown',
                viewport: window.innerWidth + ' x ' + window.innerHeight,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                cpu_threads: navigator.hardwareConcurrency || 'not reported',
                touch_points: navigator.maxTouchPoints || 0,
                note: 'Read from your browser locally; nothing is sent to CloudHost247. Modern browsers freeze and reduce the user-agent string to limit fingerprinting, so the version shown may be less precise than the real one.'
              };
            },
            render: function (d) {
                var wrap = CH.el('div');
                if (!d || typeof d !== 'object') {
                    wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: String(d) }));
                    return wrap;
                }
                if (typeof d.score === 'number' && d.verdict) {
                    wrap.appendChild(CH.el('div', { class: 'ch247-score' }, [
                        CH.el('span', { class: 'ch247-score__value', text: String(d.score) }),
                        CH.el('span', { class: 'ch247-score__label', text: d.verdict })
                    ]));
                }

                var scalars = {};
                var tables = [];
                var lists = [];
                Object.keys(d).forEach(function (k) {
                    if (k === 'checks' || k === 'note' || k === 'notes' || k === 'raw'
                        || k === 'score' || k === 'verdict') { return; }
                    var v = d[k];
                    if (Array.isArray(v)) {
                        if (!v.length) { return; }
                        if (v[0] && typeof v[0] === 'object') { tables.push([k, v]); }
                        else { lists.push([k, v]); }
                    } else if (v && typeof v === 'object') {
                        tables.push([k, [v]]);
                    } else {
                        scalars[k] = v;
                    }
                });

                if (Object.keys(scalars).length) { wrap.appendChild(CH.kvList(scalars)); }

                tables.forEach(function (pair) {
                    var rows = pair[1];
                    var keys = {};
                    rows.forEach(function (r) { Object.keys(r || {}).forEach(function (k) { keys[k] = 1; }); });
                    var cols = Object.keys(keys).map(function (k) {
                        return { key: k, label: CH.humanLabel(k) };
                    });
                    wrap.appendChild(CH.dataTable(cols, rows, CH.humanLabel(pair[0])));
                });

                lists.forEach(function (pair) {
                    wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: CH.humanLabel(pair[0]) }));
                    var ul = CH.el('ul', { class: 'ch247-list' });
                    pair[1].forEach(function (item) {
                        ul.appendChild(CH.el('li', { text: String(item) }));
                    });
                    wrap.appendChild(ul);
                });

                if (Array.isArray(d.checks) && d.checks.length) {
                    wrap.appendChild(CH.checksList(d.checks));
                }

                if (d.raw) {
                    var det = CH.el('details', { class: 'ch247-section' });
                    det.appendChild(CH.el('summary', { text: 'Raw response' }));
                    det.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.raw }));
                    wrap.appendChild(det);
                }

                [].concat(d.note || [], d.notes || []).forEach(function (n) {
                    wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: n }));
                });

                return wrap;
            },
            submitLabel: "Run What Is My User Agent"
        });
    });
})(window, document);
