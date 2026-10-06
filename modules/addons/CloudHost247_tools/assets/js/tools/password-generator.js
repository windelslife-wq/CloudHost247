/**
 * CloudHost247 Tools - Random Password Generator
 *
 * Route-split module: this file is loaded only on /tools/password-generator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "password-generator",
            exec: "client",
            fields: [{"name": "length", "label": "Length", "type": "number", "min": 4, "max": 128, "value": 20}, {"name": "charsets", "label": "Character sets", "type": "select", "value": "all", "options": [{"value": "all", "label": "all"}, {"value": "alphanumeric", "label": "alphanumeric"}, {"value": "letters", "label": "letters"}, {"value": "hex", "label": "hex"}, {"value": "passphrase", "label": "passphrase"}]}],
            sensitive: true,
            run: function (v) {
              var len = Math.max(4, Math.min(128, Number(v.length) || 20));
              var set = v.charsets || 'all';
              if (set === 'passphrase') {
                var W = ('anchor amber anvil apple arbor arrow aspen atlas bacon badge balsa banjo barge basil beach beacon ' +
                  'bison blaze bloom board bolt brace brick bridge bronze brook cabin cable cactus camel canvas canyon cargo ' +
                  'cedar chalk chart cherry chisel cinder clover cobalt comet coral cosmos cotton crane crater crest crimson ' +
                  'crystal cyclone dagger dapple dawn delta denim desert diamond dolphin domino dragon dune eagle ember ' +
                  'ember falcon fern fjord flint forge fossil galaxy garnet geyser ginger glacier granite gravel harbor ' +
                  'harvest hazel helix hollow horizon indigo ivory jasper jungle kernel lagoon lantern lattice ledger lilac ' +
                  'linen lotus lumber lunar maple marble meadow mesa meteor mirage mosaic nectar nimbus nomad oasis obsidian ' +
                  'onyx opal orbit otter oxide pebble pepper pewter phoenix pigment pillar pine plume polar prairie prism ' +
                  'quarry quartz quiver radar rapid raven reef relic ridge ripple river rowan rustic saffron sage sapphire ' +
                  'scarlet sequoia shale shore sierra silo slate solar sparrow spruce stellar summit sunset tango teal ' +
                  'tempest thicket thistle thunder timber topaz torrent trellis tundra umber valley velvet vertex vintage ' +
                  'violet walnut willow winter zenith zephyr').split(/\s+/);
                var n = Math.max(3, Math.min(12, Math.round(len / 6)));
                var picked = [];
                var rb = new Uint32Array(n);
                (window.crypto || window.msCrypto).getRandomValues(rb);
                for (var i = 0; i < n; i++) { picked.push(W[rb[i] % W.length]); }
                var digits = new Uint32Array(1);
                (window.crypto || window.msCrypto).getRandomValues(digits);
                var pass = picked.join('-') + '-' + (digits[0] % 100);
                var bits = Math.log2(Math.pow(W.length, n) * 100);
                return {
                  password: pass, type: 'Passphrase (' + n + ' words + digits)',
                  length: pass.length, entropy_bits: Math.round(bits),
                  strength: bits >= 80 ? 'Very strong' : bits >= 60 ? 'Strong' : 'Moderate',
                  wordlist_size: W.length,
                  note: 'Generated with your browser\u2019s cryptographic random source (crypto.getRandomValues). Nothing is transmitted, stored or logged by CloudHost247. Passphrases are easier to type and remember at the same strength as a shorter random string.'
                };
              }
              var pool = {
                all: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{};:,.?',
                alphanumeric: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
                letters: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
                hex: '0123456789abcdef'
              }[set];
              if (!pool) { return { error: 'Choose a character set.' }; }
              // Rejection sampling keeps every character equally likely (no modulo bias).
              var out = '', max = Math.floor(4294967296 / pool.length) * pool.length;
              var buf = new Uint32Array(1);
              while (out.length < len) {
                (window.crypto || window.msCrypto).getRandomValues(buf);
                if (buf[0] < max) { out += pool[buf[0] % pool.length]; }
              }
              var entropy = len * Math.log2(pool.length);
              return {
                password: out, type: 'Random string', length: len,
                character_pool: pool.length + ' possible characters',
                entropy_bits: Math.round(entropy),
                strength: entropy >= 100 ? 'Very strong' : entropy >= 75 ? 'Strong' : entropy >= 50 ? 'Moderate' : 'Weak',
                combinations: '~10^' + Math.round(entropy * 0.30103),
                note: 'Generated with your browser\u2019s cryptographic random source (crypto.getRandomValues) using rejection sampling so every character is equally likely. Nothing is transmitted, stored or logged by CloudHost247, and this password is not saved in your tool history.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap',
                style: 'font-size:1.15rem;font-weight:600;', text: d.password }));
              wrap.appendChild(CH.kvList(d, ['type','length','entropy_bits','strength','character_pool','combinations','wordlist_size']));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            copyText: function (d) { return d.password; },
            submitLabel: "Run"
        });
    });
})(window, document);
