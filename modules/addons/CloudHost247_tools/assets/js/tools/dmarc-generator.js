/**
 * CloudHost247 Tools - DMARC Record Generator
 *
 * Route-split module: this file is loaded only on /tools/dmarc-generator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "dmarc-generator",
            exec: "client",
            fields: [{"name": "domain", "label": "Domain name", "placeholder": "example.com", "hint": "Enter a domain without http:// or a path.", "width": "full", "required": true, "spellcheck": false}, {"name": "policy", "label": "Policy", "type": "select", "value": "none", "options": [{"value": "none", "label": "none"}, {"value": "quarantine", "label": "quarantine"}, {"value": "reject", "label": "reject"}]}, {"name": "rua", "label": "Aggregate report email", "type": "email", "placeholder": "dmarc@example.com", "hint": "Where DMARC XML reports are sent. Strongly recommended."}, {"name": "pct", "label": "Percentage of mail to apply to", "type": "number", "min": 1, "max": 100, "value": 100}],
            run: function (v) {
              var d = String(v.domain || '').trim().toLowerCase().replace(/^https?:\/\//, '').split('/')[0];
              if (!/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/.test(d)) {
                return { error: 'Enter a valid domain name, for example example.com.' };
              }
              var policy = v.policy || 'none';
              var rua = String(v.rua || '').trim();
              var pct = Number(v.pct); if (!isFinite(pct) || pct < 1 || pct > 100) { pct = 100; }
              var parts = ['v=DMARC1', 'p=' + policy];
              if (rua) {
                if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(rua)) { return { error: 'The aggregate report address must be a valid email address.' }; }
                parts.push('rua=mailto:' + rua);
              }
              if (pct !== 100) { parts.push('pct=' + pct); }
              parts.push('sp=' + policy, 'adkim=r', 'aspf=r', 'fo=1');
              var record = parts.join('; ');
              return {
                host: '_dmarc.' + d,
                type: 'TXT',
                value: record,
                ttl: '3600',
                policy_meaning: {
                  none: 'Monitor only. Nothing is rejected or quarantined \u2014 you simply receive reports. Always start here.',
                  quarantine: 'Failing mail is delivered to spam/junk. Move here once reports show your legitimate sources all pass.',
                  reject: 'Failing mail is rejected outright. The strongest policy, and the goal \u2014 but only after monitoring.'
                }[policy],
                next_steps: 'Publish this as a TXT record at _dmarc.' + d + ', wait for aggregate reports, confirm every legitimate sending source passes SPF or DKIM alignment, then tighten p=none to quarantine and finally reject.',
                warning: policy === 'reject'
                  ? 'Starting at p=reject without a monitoring period will silently break legitimate mail from any source you have not yet aligned. Start at p=none.'
                  : null,
                note: 'DMARC builds on SPF and DKIM: publish and verify both first, or DMARC has nothing to align against.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              if (d.warning) {
                wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--warn' }, [
                  CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: '\u26A0' }),
                  CH.el('div', {}, [CH.el('strong', { class: 'ch247-alert__title', text: 'Careful' }),
                    CH.el('p', { class: 'ch247-alert__body', text: d.warning })])
                ]));
              }
              wrap.appendChild(CH.dataTable([
                { key: 'host', label: 'Host / name' }, { key: 'type', label: 'Type' },
                { key: 'ttl', label: 'TTL' }, { key: 'value', label: 'Value' }
              ], [d], 'DNS record to publish'));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.value }));
              wrap.appendChild(CH.kvList(d, ['policy_meaning', 'next_steps', 'note']));
              return wrap;
            },
            copyText: function (d) { return d.value; },
            submitLabel: "Run DMARC Record Generator"
        });
    });
})(window, document);
