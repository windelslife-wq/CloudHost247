/**
 * Logo Studio — live concept board.
 *
 * Concepts are rendered by the SAME server-side engine that produces the
 * saved/exported SVG, so what you preview is byte-for-byte what you download.
 */
(function () {
    'use strict';

    var grid = document.getElementById('chs-concept-grid');
    if (!grid) { return; }

    var form = document.getElementById('chs-logo-form');
    var saveBar = document.getElementById('chs-studio-actions');
    var conceptInput = document.getElementById('chs-concept-input');
    var moduleLink = grid.getAttribute('data-modulelink');
    var concepts = ['wordmark', 'monogram', 'combo', 'badge'];
    var selected = null;
    var debounce = null;
    var seq = 0;

    function values() {
        return {
            company: document.getElementById('chs-company').value,
            industry: document.getElementById('chs-industry').value,
            style: document.getElementById('chs-style').value,
            palette: (form.querySelector('input[name="palette"]:checked') || {}).value || 'ocean'
        };
    }

    function request(concept, mySeq) {
        var v = values();
        var qs = moduleLink + '&action=logoapi&concept=' + encodeURIComponent(concept)
            + '&company=' + encodeURIComponent(v.company)
            + '&industry=' + encodeURIComponent(v.industry)
            + '&style=' + encodeURIComponent(v.style)
            + '&palette=' + encodeURIComponent(v.palette);
        return fetch(qs, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (mySeq !== seq) { return null; } // a newer render superseded this one
                return data.ok ? data.svg : null;
            })
            .catch(function () { return null; });
    }

    function card(concept) {
        var el = document.createElement('div');
        el.className = 'chs-concept-card';
        el.setAttribute('data-concept', concept);
        el.setAttribute('role', 'button');
        el.setAttribute('tabindex', '0');
        el.innerHTML = '<div class="chs-concept-name">' + concept + '</div><div class="chs-concept-art">…</div>';
        el.addEventListener('click', function () { select(el, concept); });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(el, concept); }
        });
        return el;
    }

    function select(el, concept) {
        var cards = grid.querySelectorAll('.chs-concept-card');
        for (var i = 0; i < cards.length; i++) { cards[i].classList.remove('chs-selected'); }
        el.classList.add('chs-selected');
        conceptInput.value = concept;
        saveBar.style.display = 'block';
    }

    function render() {
        seq++;
        var mySeq = seq;
        grid.innerHTML = '';
        saveBar.style.display = 'none';
        selected = null;
        var cards = {};
        concepts.forEach(function (c) { cards[c] = card(c); grid.appendChild(cards[c]); });
        concepts.forEach(function (c) {
            request(c, mySeq).then(function (svg) {
                if (svg === null) {
                    cards[c].querySelector('.chs-concept-art').innerHTML =
                        '<span class="text-muted">Preview unavailable</span>';
                    return;
                }
                var art = cards[c].querySelector('.chs-concept-art');
                art.innerHTML = svg;
                if (!selected && c === 'wordmark') { select(cards[c], c); }
            });
        });
    }

    function queue() {
        clearTimeout(debounce);
        debounce = setTimeout(render, 350);
    }

    ['chs-company', 'chs-industry', 'chs-style'].forEach(function (id) {
        var el = document.getElementById(id);
        el.addEventListener('input', queue);
        el.addEventListener('change', queue);
    });
    var paletteLabels = document.querySelectorAll('.chs-palette input');
    for (var i = 0; i < paletteLabels.length; i++) {
        paletteLabels[i].addEventListener('change', function (e) {
            var all = document.querySelectorAll('.chs-palette');
            for (var j = 0; j < all.length; j++) { all[j].classList.remove('chs-selected'); }
            e.target.closest('.chs-palette').classList.add('chs-selected');
            queue();
        });
    }

    render();
})();
