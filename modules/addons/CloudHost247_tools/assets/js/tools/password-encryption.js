/**
 * CloudHost247 Tools - Password Encryption
 *
 * Route-split module: this file is loaded only on /tools/password-encryption.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "password-encryption",
            exec: "client",
            fields: [{"name": "password", "label": "Password", "type": "password", "hint": "Processed entirely in your browser. Never sent to CloudHost247.", "autocomplete": "new-password", "required": true}, {"name": "algorithm", "label": "Algorithm", "type": "select", "value": "sha256", "options": [{"value": "md5", "label": "md5"}, {"value": "sha1", "label": "sha1"}, {"value": "sha256", "label": "sha256"}, {"value": "sha512", "label": "sha512"}, {"value": "base64", "label": "base64"}, {"value": "bcrypt-info", "label": "bcrypt-info"}]}],
            sensitive: true,
            run: function (v) {
              var p = String(v.password || '');
              if (!p) { return { error: 'Enter a value to hash or encode.' }; }
              var algo = v.algorithm || 'sha256';
              if (algo === 'base64') {
                return Promise.resolve({
                  algorithm: 'Base64',
                  category: 'ENCODING \u2014 not encryption, not hashing',
                  result: CH.hash.b64encode(p),
                  reversible: 'Yes \u2014 anyone can decode this instantly',
                  suitable_for_passwords: 'No. Never.',
                  note: 'Base64 is an encoding scheme for moving binary data through text channels. It provides zero confidentiality: decoding needs no key. If you are storing passwords this way, they are effectively in plain text.'
                });
              }
              if (algo === 'bcrypt-info') {
                return Promise.resolve({
                  algorithm: 'bcrypt / Argon2id',
                  category: 'PASSWORD HASHING \u2014 the correct choice for storing passwords',
                  result: 'Not generated in the browser on purpose.',
                  reversible: 'No \u2014 one-way by design',
                  suitable_for_passwords: 'Yes \u2014 this is what you should use',
                  note: 'Password hashes must be generated server-side with a per-password random salt and a deliberately slow work factor. Generating one in a browser would give you a hash with a salt the server never saw, which is useless. In PHP use password_hash($password, PASSWORD_DEFAULT) and verify with password_verify(). Argon2id is the current recommendation; bcrypt remains acceptable.'
                });
              }
              return CH.hash.subtle(algo, p).catch(function () {
                if (algo === 'md5') { return CH.hash.md5(p); }
                throw new Error('This browser cannot compute ' + algo + ' (SubtleCrypto needs a secure context).');
              }).then(function (digest) {
                if (algo === 'md5') { digest = CH.hash.md5(p); }
                var weak = algo === 'md5' || algo === 'sha1';
                return {
                  algorithm: algo.toUpperCase(),
                  category: 'CRYPTOGRAPHIC HASH \u2014 one-way, but too fast for passwords',
                  result: digest,
                  length: digest.length * 4 + ' bits',
                  reversible: 'No, but fast hashes fall to brute force and rainbow tables',
                  suitable_for_passwords: 'No \u2014 use bcrypt or Argon2id instead',
                  collision_status: weak ? 'BROKEN \u2014 practical collisions exist. Do not use for signatures or integrity.' : 'No known practical collisions',
                  note: 'Computed in your browser; the input is never sent to CloudHost247 and is not saved in your history. Understand the three categories: ENCODING (Base64) is reversible by anyone; HASHING (SHA-256) is one-way; ENCRYPTION (AES) is reversible with a key. A general-purpose hash like SHA-256 is far too fast for password storage \u2014 a GPU computes billions per second. Use a deliberately slow password hash (bcrypt, scrypt, Argon2id) with a unique salt.'
                };
              });
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
            submitLabel: "Run Password Encryption"
        });
    });
})(window, document);
