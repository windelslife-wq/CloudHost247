/**
 * CloudHost247 Services Suite — client behaviours.
 * Countdown timers render into .chs-countdown[data-ends=seconds].
 */
(function () {
    'use strict';

    function typeCardFor(input) {
        var node = input && input.parentNode;
        while (node && node !== document) {
            if ((' ' + node.className + ' ').indexOf(' chs-type-card ') !== -1) return node;
            node = node.parentNode;
        }
        return null;
    }

    function syncTypeCards() {
        var inputs = document.querySelectorAll('.chs-type-card input');
        for (var i = 0; i < inputs.length; i++) {
            var card = typeCardFor(inputs[i]);
            if (!card) continue;
            if (inputs[i].checked) card.classList.add('chs-checked');
            else card.classList.remove('chs-checked');
            inputs[i].addEventListener('change', function () {
                var changedCard = typeCardFor(this);
                if (!changedCard) return;
                if (this.checked) changedCard.classList.add('chs-checked');
                else changedCard.classList.remove('chs-checked');
            });
        }
    }

    function tick() {
        var nodes = document.querySelectorAll('.chs-countdown[data-ends]');
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            var remaining = parseInt(node.getAttribute('data-ends'), 10);
            if (isNaN(remaining)) { continue; }
            if (remaining <= 0) {
                node.textContent = 'Closing…';
                continue;
            }
            node.setAttribute('data-ends', String(remaining - 1));
            var d = Math.floor(remaining / 86400);
            var h = Math.floor((remaining % 86400) / 3600);
            var m = Math.floor((remaining % 3600) / 60);
            var s = remaining % 60;
            node.textContent = (d > 0 ? d + 'd ' : '')
                + ('0' + h).slice(-2) + 'h ' + ('0' + m).slice(-2) + 'm ' + ('0' + s).slice(-2) + 's';
            if (remaining < 3600) {
                node.classList.add('chs-countdown-urgent');
                node.style.color = '#b91c1c';
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { syncTypeCards(); tick(); setInterval(tick, 1000); });
    } else {
        syncTypeCards();
        tick();
        setInterval(tick, 1000);
    }
})();
