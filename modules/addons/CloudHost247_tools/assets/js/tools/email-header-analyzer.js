/**
 * CloudHost247 Tools - Trace Email / Header Analyzer
 *
 * Route-split module: this file is loaded only on /tools/email-header-analyzer.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "email-header-analyzer",
            exec: "client",
            fields: [{"name": "headers", "label": "Raw email headers", "type": "textarea", "placeholder": "Paste the full message headers here...", "rows": 12, "width": "full", "required": true, "spellcheck": false}],
            run: function (v) {
              var raw = String(v.headers || '').trim();
              if (!raw) { return { error: 'Paste the full raw message headers.' }; }
              if (raw.length > 200000) { return { error: 'Those headers are too large (limit 200,000 characters).' }; }
              // Unfold continuation lines (RFC 5322 folding).
              var unfolded = raw.replace(/\r?\n[ \t]+/g, ' ');
              var headers = [];
              unfolded.split(/\r?\n/).forEach(function (line) {
                var i = line.indexOf(':');
                if (i > 0) { headers.push({ name: line.slice(0, i).trim(), value: line.slice(i + 1).trim() }); }
              });
              if (!headers.length) { return { error: 'No headers could be parsed. Paste the raw headers, including lines such as "Received:".' }; }
              var get = function (n) {
                var f = headers.filter(function (h) { return h.name.toLowerCase() === n.toLowerCase(); });
                return f.length ? f[f.length - 1].value : null;
              };
              var all = function (n) {
                return headers.filter(function (h) { return h.name.toLowerCase() === n.toLowerCase(); }).map(function (h) { return h.value; });
              };
            
              // Received headers are prepended, so reverse for chronological order.
              var received = all('Received').reverse();
              var hops = received.map(function (r, i) {
                var from = (/from\s+([^\s;()]+)/i.exec(r) || [])[1] || 'unknown';
                var by = (/by\s+([^\s;()]+)/i.exec(r) || [])[1] || 'unknown';
                var ipm = /\[?((?:\d{1,3}\.){3}\d{1,3})\]?/.exec(r);
                var dm = r.lastIndexOf(';');
                var when = dm > -1 ? r.slice(dm + 1).trim() : '';
                var ts = when ? Date.parse(when) : NaN;
                return { hop: i + 1, from: from, by: by, ip: ipm ? ipm[1] : '\u2014', time: when || '\u2014', _ts: ts };
              });
              for (var i = 1; i < hops.length; i++) {
                if (!isNaN(hops[i]._ts) && !isNaN(hops[i - 1]._ts)) {
                  var d2 = (hops[i]._ts - hops[i - 1]._ts) / 1000;
                  hops[i].delay = (d2 >= 0 ? d2 : 0) + 's';
                } else { hops[i].delay = '\u2014'; }
              }
              if (hops.length) { hops[0].delay = '\u2014'; }
            
              var authResults = all('Authentication-Results').join(' ');
              var authCheck = function (name, re) {
                var m = re.exec(authResults);
                if (!m) { return { name: name, status: 'na', detail: 'No ' + name + ' result reported by the receiving server.' }; }
                var verdict = m[1].toLowerCase();
                return { name: name,
                  status: verdict === 'pass' ? 'pass' : (verdict === 'none' ? 'warn' : 'fail'),
                  detail: name + '=' + verdict };
              };
            
              var checks = [
                authCheck('SPF', /spf=(\w+)/i),
                authCheck('DKIM', /dkim=(\w+)/i),
                authCheck('DMARC', /dmarc=(\w+)/i)
              ];
              var from = get('From'), replyTo = get('Reply-To'), returnPath = get('Return-Path');
              var dom = function (s) { var m = /@([^\s>;,]+)/.exec(s || ''); return m ? m[1].toLowerCase().replace(/>$/, '') : null; };
              if (replyTo && dom(replyTo) && dom(from) && dom(replyTo) !== dom(from)) {
                checks.push({ name: 'Reply-To mismatch', status: 'warn',
                  detail: 'Reply-To (' + dom(replyTo) + ') differs from From (' + dom(from) + '). Common in legitimate mailing lists, but also a classic phishing signal.' });
              }
              if (returnPath && dom(returnPath) && dom(from) && dom(returnPath) !== dom(from)) {
                checks.push({ name: 'Return-Path mismatch', status: 'info',
                  detail: 'Return-Path (' + dom(returnPath) + ') differs from From (' + dom(from) + '). Normal for ESPs and mailing lists.' });
              }
              checks.push({ name: 'Hop count', status: hops.length > 8 ? 'warn' : 'info',
                detail: hops.length + ' Received hop(s) recorded.' });
            
              return {
                subject: get('Subject') || '(none)',
                from: from || '(none)', to: get('To') || '(none)',
                date: get('Date') || '(none)',
                reply_to: replyTo || '(none)', return_path: returnPath || '(none)',
                message_id: get('Message-ID') || '(none)',
                mailer: get('X-Mailer') || get('User-Agent') || '(not stated)',
                spam_score: get('X-Spam-Score') || get('X-Spam-Status') || '(not stated)',
                hop_count: hops.length, hops: hops, checks: checks,
                header_count: headers.length,
                all_headers: headers,
                note: 'Parsed entirely in your browser \u2014 the headers are never sent to CloudHost247. Received headers are added by each server in turn and only the ones added by servers you trust are reliable: a spammer can forge everything below the first hop your own mail system added.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.kvList(d, ['subject','from','to','date','reply_to','return_path','message_id','mailer','spam_score','hop_count','header_count']));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Authentication and checks' }));
              wrap.appendChild(CH.checksList(d.checks));
              if (d.hops.length) {
                wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Delivery path (oldest first)' }));
                wrap.appendChild(CH.dataTable([
                  { key: 'hop', label: '#' }, { key: 'from', label: 'From' }, { key: 'by', label: 'Received by' },
                  { key: 'ip', label: 'IP' }, { key: 'delay', label: 'Delay' }, { key: 'time', label: 'Timestamp' }
                ], d.hops, 'Message delivery path'));
              }
              var det = CH.el('details', { class: 'ch247-section' });
              det.appendChild(CH.el('summary', { text: 'All ' + d.header_count + ' headers' }));
              det.appendChild(CH.dataTable([{ key: 'name', label: 'Header' }, { key: 'value', label: 'Value' }],
                d.all_headers, 'Every parsed header'));
              wrap.appendChild(det);
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            submitLabel: "Run"
        });
    });
})(window, document);
