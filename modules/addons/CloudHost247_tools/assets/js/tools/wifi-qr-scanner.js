/**
 * CloudHost247 Tools - WiFi QR Scanner
 *
 * Route-split module: this file is loaded only on /tools/wifi-qr-scanner.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "wifi-qr-scanner",
            exec: "client",
            fields: [{"name": "mode", "label": "Mode", "type": "select", "options": [{"value": "build", "label": "build"}, {"value": "parse", "label": "parse"}], "value": "build"}, {"name": "ssid", "label": "Network name (SSID)"}, {"name": "password", "label": "Password", "type": "password"}, {"name": "auth", "label": "Security", "type": "select", "options": [{"value": "WPA", "label": "WPA"}, {"value": "WEP", "label": "WEP"}, {"value": "NOPASS", "label": "NOPASS"}], "value": "WPA"}, {"name": "hidden", "label": "Hidden network", "type": "checkbox"}, {"name": "uri", "label": "Or paste a WIFI: payload", "placeholder": "WIFI:T:WPA;S:MyNet;P:pass;;", "width": "full"}],
            sensitive: true,
            run: function (v) {
              var esc = function (s) { return String(s).replace(/([\\;,:"])/g, '\\$1'); };
              if ((v.mode || 'build') === 'build') {
                var ssid = String(v.ssid || '');
                if (!ssid.trim()) { return { error: 'Enter the network name (SSID).' }; }
                if (new TextEncoder().encode(ssid).length > 32) { return { error: 'An SSID cannot be longer than 32 bytes.' }; }
                var auth = (v.auth || 'WPA').toUpperCase();
                var pw = String(v.password || '');
                if (auth !== 'NOPASS' && !pw) { return { error: 'Enter the network password, or choose the open-network option.' }; }
                if (auth === 'WPA' && pw && (pw.length < 8 || pw.length > 63)) {
                  return { error: 'A WPA/WPA2 passphrase must be between 8 and 63 characters.' };
                }
                var uri = 'WIFI:T:' + auth + ';S:' + esc(ssid) + ';';
                if (auth !== 'NOPASS') { uri += 'P:' + esc(pw) + ';'; }
                if (v.hidden) { uri += 'H:true;'; }
                uri += ';';
                return { mode: 'build', ssid: ssid, auth: auth, uri: uri,
                  security_warning: 'Anyone who can photograph this code gets your Wi-Fi password. For public areas use a separate guest network.',
                  note: 'Built in your browser. The password is never sent to CloudHost247, never logged, and is not saved in your tool history.' };
              }
              var uriIn = String(v.uri || v.text || '').trim();
              if (!uriIn) { return { error: 'Paste a WIFI: payload, or scan a QR code image.' }; }
              if (!/^WIFI:/i.test(uriIn)) { return { error: 'That is not a Wi-Fi QR payload. Wi-Fi codes start with "WIFI:".' }; }
              var body = uriIn.slice(5), fields = [], buf = '';
              for (var i = 0; i < body.length; i++) {
                if (body[i] === '\\' && i + 1 < body.length) { buf += body[i + 1]; i++; continue; }
                if (body[i] === ';') { if (buf) { fields.push(buf); } buf = ''; continue; }
                buf += body[i];
              }
              if (buf) { fields.push(buf); }
              var p = {};
              fields.forEach(function (f) { var k = f.indexOf(':'); if (k > -1) { p[f.slice(0, k).toUpperCase()] = f.slice(k + 1); } });
              if (!p.S) { return { error: 'The payload contains no network name (SSID).' }; }
              var a = (p.T || 'NOPASS').toUpperCase();
              var warnings = [];
              if (a === 'WEP') { warnings.push('WEP can be broken in minutes with free tools. Move this network to WPA2 or WPA3.'); }
              if (a === 'NOPASS') { warnings.push('This network is unencrypted \u2014 traffic can be read by anyone nearby.'); }
              if (p.P && p.P.length < 12 && a === 'WPA') { warnings.push('The passphrase is short. 12+ characters resists offline cracking far better.'); }
              return { mode: 'parse', ssid: p.S, auth: a,
                auth_label: { WPA: 'WPA/WPA2 Personal', 'WPA2-EAP': 'WPA2 Enterprise', WEP: 'WEP (insecure)', NOPASS: 'Open network' }[a] || a,
                password: p.P || '(none)', hidden: String(p.H).toLowerCase() === 'true',
                warnings: warnings,
                note: 'Parsed in your browser. CloudHost247 never stores or logs Wi-Fi credentials.' };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.kvList(d, ['mode','ssid','auth','auth_label','hidden','uri','password']));
              (d.warnings || []).forEach(function (w) {
                wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--warn' }, [
                  CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: '\u26A0' }),
                  CH.el('p', { class: 'ch247-alert__body', text: w })
                ]));
              });
              if (d.security_warning) {
                wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--warn' }, [
                  CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: '\u26A0' }),
                  CH.el('p', { class: 'ch247-alert__body', text: d.security_warning })
                ]));
              }
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            copyText: function (d) { return d.uri || d.ssid; },
            submitLabel: "Run WiFi QR Scanner"
        });
    });
})(window, document);
