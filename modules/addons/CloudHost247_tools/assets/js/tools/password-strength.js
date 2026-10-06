/**
 * CloudHost247 Tools - Password Strength Checker
 *
 * Route-split module: this file is loaded only on /tools/password-strength.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "password-strength",
            exec: "client",
            fields: [{"name": "password", "label": "Password", "type": "password", "hint": "Processed entirely in your browser. Never sent to CloudHost247.", "autocomplete": "new-password", "required": true}],
            sensitive: true,
            run: function (v) {
              var p = String(v.password || '');
              if (!p) { return { error: 'Enter a password to analyse.' }; }
              var sets = [
                [/[a-z]/, 26, 'lowercase letters'], [/[A-Z]/, 26, 'uppercase letters'],
                [/[0-9]/, 10, 'digits'], [/[^a-zA-Z0-9]/, 33, 'symbols']
              ];
              var pool = 0, used = [];
              sets.forEach(function (s) { if (s[0].test(p)) { pool += s[1]; used.push(s[2]); } });
              var entropy = p.length * Math.log2(pool || 1);
            
              var issues = [], good = [];
              if (p.length < 8) { issues.push('Shorter than 8 characters \u2014 trivially brute-forced.'); }
              else if (p.length < 12) { issues.push('Under 12 characters. Length matters more than complexity.'); }
              else { good.push('Good length (' + p.length + ' characters).'); }
              if (used.length >= 3) { good.push('Mixes ' + used.length + ' character types.'); }
              else { issues.push('Only uses ' + used.join(' and ') + '. Mixing more types widens the search space.'); }
              if (/(.)\1{2,}/.test(p)) { issues.push('Contains a character repeated three or more times in a row.'); }
              if (/^[0-9]+$/.test(p)) { issues.push('Digits only \u2014 cracked almost instantly.'); }
              if (/(012|123|234|345|456|567|678|789|890)/.test(p)) { issues.push('Contains a numeric sequence.'); }
              if (/(abc|bcd|cde|def|qwe|wer|asd|zxc|qwerty|asdf)/i.test(p)) { issues.push('Contains a keyboard or alphabet pattern.'); }
              var COMMON = ['password','123456','123456789','qwerty','abc123','letmein','monkey','dragon','111111','iloveyou',
                'admin','welcome','login','princess','sunshine','master','football','shadow','baseball','trustno1','passw0rd'];
              var lower = p.toLowerCase();
              var hit = COMMON.filter(function (c) { return lower === c || lower.indexOf(c) !== -1; });
              if (hit.length) { issues.push('Contains a very common password or word: "' + hit[0] + '".'); }
              if (/(19|20)\d{2}/.test(p)) { issues.push('Contains what looks like a year \u2014 a common and guessable pattern.'); }
            
              // Effective entropy after penalties, used for the crack-time estimate.
              var effective = entropy;
              if (hit.length) { effective = Math.min(effective, 12); }
              if (/^[0-9]+$/.test(p)) { effective = Math.min(effective, p.length * 3.3); }
              issues.forEach(function () { effective -= 4; });
              effective = Math.max(4, effective);
            
              // 10^11 guesses/sec - a realistic offline rate against a fast hash on GPUs.
              var seconds = Math.pow(2, effective) / 2 / 1e11;
              var human = seconds < 1 ? 'less than a second'
                : seconds < 60 ? Math.round(seconds) + ' seconds'
                : seconds < 3600 ? Math.round(seconds / 60) + ' minutes'
                : seconds < 86400 ? Math.round(seconds / 3600) + ' hours'
                : seconds < 2592000 ? Math.round(seconds / 86400) + ' days'
                : seconds < 31536000 ? Math.round(seconds / 2592000) + ' months'
                : seconds < 3153600000 ? Math.round(seconds / 31536000) + ' years'
                : seconds < 3.15e12 ? Math.round(seconds / 31536000 / 1000) + ' thousand years'
                : 'billions of years';
            
              var score = Math.max(0, Math.min(100, Math.round((effective / 100) * 100)));
              var verdict = score >= 80 ? 'Very strong' : score >= 60 ? 'Strong' : score >= 40 ? 'Moderate' : score >= 20 ? 'Weak' : 'Very weak';
            
              return {
                length: p.length,
                character_types: used.join(', ') || 'none',
                pool_size: pool,
                raw_entropy_bits: Math.round(entropy),
                effective_entropy_bits: Math.round(effective),
                estimated_offline_crack_time: human,
                score: score, verdict: verdict,
                checks: good.map(function (g) { return { name: 'Strength', status: 'pass', detail: g }; })
                  .concat(issues.map(function (i) { return { name: 'Weakness', status: 'fail', detail: i }; })),
                note: 'Analysed entirely in your browser. The password is never transmitted to CloudHost247, never logged, and is not saved in your tool history. The crack-time estimate assumes an offline attack at 100 billion guesses per second against a fast hash; a properly salted bcrypt or Argon2 hash would take far longer. Treat this as a guide, not a guarantee.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('div', { class: 'ch247-score' }, [
                CH.el('span', { class: 'ch247-score__value', text: d.score + '/100' }),
                CH.el('span', { class: 'ch247-score__label', text: d.verdict })
              ]));
              wrap.appendChild(CH.kvList(d, ['length','character_types','pool_size','raw_entropy_bits',
                'effective_entropy_bits','estimated_offline_crack_time']));
              wrap.appendChild(CH.checksList(d.checks));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            submitLabel: "Run"
        });
    });
})(window, document);
