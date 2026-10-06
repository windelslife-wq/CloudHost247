/*!
 * Domain Broker — progressive enhancement for the client area.
 *
 * Everything the portal does works without JavaScript: these handlers only
 * reveal panels that are already in the DOM and add a confirmation step. No
 * state is ever decided here — the server owns every status.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function closest(element, selector) {
        while (element && element.nodeType === 1) {
            if (element.matches && element.matches(selector)) {
                return element;
            }
            element = element.parentNode;
        }
        return null;
    }

    ready(function () {
        // Toggle disclosure panels (counteroffer form, cancel form, …).
        document.addEventListener('click', function (event) {
            var trigger = closest(event.target, '[data-db-toggle]');
            if (!trigger) {
                return;
            }
            var target = document.getElementById(trigger.getAttribute('data-db-toggle'));
            if (!target) {
                return;
            }
            event.preventDefault();
            var hidden = target.classList.contains('db-hidden');
            target.classList.toggle('db-hidden');
            trigger.setAttribute('aria-expanded', hidden ? 'true' : 'false');
            if (hidden) {
                var field = target.querySelector('input, textarea, select');
                if (field) {
                    field.focus();
                }
            }
        });

        // Guard against accidental double submission of financial forms.
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.getAttribute) {
                return;
            }
            if (form.getAttribute('data-db-submitting') === '1') {
                event.preventDefault();
                return;
            }
            form.setAttribute('data-db-submitting', '1');
            window.setTimeout(function () {
                form.removeAttribute('data-db-submitting');
            }, 8000);
        });

        // Normalise the domain field as it is typed.
        var domainField = document.getElementById('db-domain');
        if (domainField) {
            domainField.addEventListener('blur', function () {
                var value = domainField.value.trim().toLowerCase();
                value = value.replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/.*$/, '');
                domainField.value = value;
            });
        }
    });
}());
