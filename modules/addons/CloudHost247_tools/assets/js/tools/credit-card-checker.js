/**
 * CloudHost247 Tools - Credit Card Checker
 *
 * Route-split module: this file is loaded only on /tools/credit-card-checker.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "credit-card-checker",
            exec: "client",
            fields: [{"name": "number", "label": "Card number", "placeholder": "4532 0151 1283 0366", "hint": "Checked locally in your browser with the Luhn algorithm. Never transmitted, stored or logged.", "autocomplete": "off", "inputmode": "numeric", "required": true}],
            sensitive: true,
            run: function (v) {
              var raw = String(v.number || '');
              var digits = raw.replace(/[\s-]/g, '');
              if (!digits) { return { error: 'Enter a card number.' }; }
              if (!/^\d+$/.test(digits)) { return { error: 'A card number contains digits only (spaces and hyphens are ignored).' }; }
              if (digits.length < 12 || digits.length > 19) { return { error: 'Card numbers are between 12 and 19 digits. You entered ' + digits.length + '.' }; }
            
              // Luhn checksum (ISO/IEC 7812).
              var sum = 0, alt = false;
              for (var i = digits.length - 1; i >= 0; i--) {
                var n = Number(digits[i]);
                if (alt) { n *= 2; if (n > 9) { n -= 9; } }
                sum += n; alt = !alt;
              }
              var luhnOk = sum % 10 === 0;
            
              var NETWORKS = [
                ['Visa', /^4\d{12}(\d{3})?(\d{3})?$/, [13, 16, 19]],
                ['Mastercard', /^(5[1-5]\d{14}|2(2[2-9]\d{12}|[3-6]\d{13}|7[01]\d{12}|720\d{12}))$/, [16]],
                ['American Express', /^3[47]\d{13}$/, [15]],
                ['Discover', /^(6011\d{12}|65\d{14}|64[4-9]\d{13}|622(12[6-9]|1[3-9]\d|[2-8]\d{2}|9[01]\d|92[0-5])\d{10})$/, [16]],
                ['Diners Club', /^3(0[0-5]\d{11}|[68]\d{12})$/, [14]],
                ['JCB', /^35(2[89]|[3-8]\d)\d{12}$/, [16]],
                ['UnionPay', /^62\d{14,17}$/, [16, 17, 18, 19]],
                ['Maestro', /^(5018|5020|5038|5893|6304|6759|6761|6762|6763)\d{8,15}$/, [12,13,14,15,16,17,18,19]]
              ];
              var network = 'Unknown', expectedLen = null;
              for (var k = 0; k < NETWORKS.length; k++) {
                if (NETWORKS[k][1].test(digits)) { network = NETWORKS[k][0]; expectedLen = NETWORKS[k][2]; break; }
              }
              var lengthOk = expectedLen === null ? null : expectedLen.indexOf(digits.length) !== -1;
            
              var checks = [
                { name: 'Luhn checksum', status: luhnOk ? 'pass' : 'fail',
                  detail: luhnOk ? 'The check digit is mathematically consistent.' : 'The check digit does not validate \u2014 this number contains a typo or is not a real card number.' },
                { name: 'Length', status: lengthOk === null ? 'info' : (lengthOk ? 'pass' : 'fail'),
                  detail: expectedLen === null ? digits.length + ' digits (no network matched, so no expected length).'
                    : digits.length + ' digits; ' + network + ' expects ' + expectedLen.join(' or ') + '.' },
                { name: 'Network prefix', status: network === 'Unknown' ? 'warn' : 'pass',
                  detail: network === 'Unknown' ? 'No major network matches this prefix.' : 'Prefix matches ' + network + '.' },
                { name: 'Active / funded / usable', status: 'na',
                  detail: 'NOT CHECKED. This tool performs no network request of any kind. It cannot and does not verify that the card exists, is active, has funds, or can be charged.' }
              ];
            
              return {
                masked: digits.slice(0, 6) + '\u2022'.repeat(Math.max(0, digits.length - 10)) + digits.slice(-4),
                digits: digits.length,
                network: network,
                iin_bin: digits.slice(0, 6),
                format_valid: luhnOk && lengthOk !== false,
                checks: checks,
                warning: 'This checks format only: the Luhn check digit, the length, and the issuer prefix. It does NOT verify that the card is active, funded or usable, and it never will.',
                privacy: 'The number you typed stayed in your browser. It was not transmitted to CloudHost247, not logged, not stored, and is not saved in your tool history. Only the masked form is shown above. Never enter a card number you do not own into any online tool.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--' + (d.format_valid ? 'info' : 'error') }, [
                CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: d.format_valid ? '\u2713' : '\u2715' }),
                CH.el('div', {}, [
                  CH.el('strong', { class: 'ch247-alert__title', text: d.format_valid ? 'Format is valid' : 'Format is not valid' }),
                  CH.el('p', { class: 'ch247-alert__body', text: d.warning })
                ])
              ]));
              wrap.appendChild(CH.kvList(d, ['masked','network','iin_bin','digits']));
              wrap.appendChild(CH.checksList(d.checks));
              wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--warn' }, [
                CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: '\u26A0' }),
                CH.el('div', {}, [CH.el('strong', { class: 'ch247-alert__title', text: 'Privacy' }),
                  CH.el('p', { class: 'ch247-alert__body', text: d.privacy })])
              ]));
              return wrap;
            },
            copyText: function (d) { return 'Network: ' + d.network + '\nFormat valid: ' + d.format_valid; },
            submitLabel: "Run Credit Card Checker"
        });
    });
})(window, document);
