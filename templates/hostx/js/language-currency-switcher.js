/*!
 * CloudHost247 — unified language / currency switcher.
 *
 * Progressive enhancement only. The markup renders as real <a href> links, so
 * with JavaScript disabled (or if this file fails to load) the switcher still
 * works: the panels are visible and every option is a plain navigation. This
 * script adds the disclosure behaviour, keyboard support and "click outside to
 * close" on top of that baseline.
 *
 * Deliberately dependency-free: no jQuery, no Bootstrap, no polyfills. WHMCS
 * ships jQuery 1.12 on this theme, but a switcher that depends on it breaks
 * the moment the theme is swapped or the asset pipeline changes.
 *
 * Keyboard contract (WAI-ARIA disclosure pattern):
 *   Enter / Space        toggle the panel
 *   ArrowDown            open and focus the first option
 *   ArrowUp              open and focus the last option
 *   ArrowDown / ArrowUp  move between options while open
 *   Home / End           first / last option
 *   Escape               close and return focus to the trigger
 *   Tab                  close and continue natural tab order
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */
(function () {
    'use strict';

    var SELECTORS = {
        root: '[data-ch247-switcher]',
        trigger: '[data-ch247-switcher-trigger]',
        panel: '[data-ch247-switcher-panel]',
        option: 'a[data-ch247-switcher-option]'
    };

    var OPEN_CLASS = 'is-open';

    /**
     * @param {HTMLElement} root
     * @returns {Array<HTMLElement>}
     */
    function triggersOf(root) {
        return Array.prototype.slice.call(root.querySelectorAll(SELECTORS.trigger));
    }

    /**
     * @param {HTMLElement} trigger
     * @returns {HTMLElement|null}
     */
    function panelFor(trigger) {
        var id = trigger.getAttribute('aria-controls');
        if (!id) {
            return trigger.parentElement ? trigger.parentElement.querySelector(SELECTORS.panel) : null;
        }

        return document.getElementById(id);
    }

    /**
     * @param {HTMLElement} panel
     * @returns {Array<HTMLElement>}
     */
    function optionsOf(panel) {
        return panel ? Array.prototype.slice.call(panel.querySelectorAll(SELECTORS.option)) : [];
    }

    /**
     * @param {HTMLElement} group
     * @returns {boolean}
     */
    function isOpen(group) {
        var trigger = group.querySelector(SELECTORS.trigger);

        return !!trigger && trigger.getAttribute('aria-expanded') === 'true';
    }

    /**
     * @param {HTMLElement} trigger
     * @param {boolean} open
     * @param {boolean} moveFocus
     * @returns {void}
     */
    function setOpen(trigger, open, moveFocus) {
        var panel = panelFor(trigger);
        if (!panel) {
            return;
        }

        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            panel.hidden = false;
            alignPanel(panel);
            trigger.classList.add(OPEN_CLASS);
            if (moveFocus !== false) {
                var options = optionsOf(panel);
                if (options.length) {
                    options[0].focus();
                }
            }
        } else {
            panel.hidden = true;
            trigger.classList.remove(OPEN_CLASS);
        }
    }

    /** Clamp position as well as width; a right-anchored panel can extend left of the viewport. */
    function alignPanel(panel) {
        panel.style.transform = '';
        var rect = panel.getBoundingClientRect();
        var width = document.documentElement.clientWidth || window.innerWidth;
        var gutter = 12;
        var offset = rect.left < gutter ? gutter - rect.left : 0;
        if (rect.right + offset > width - gutter) {
            offset -= rect.right + offset - (width - gutter);
        }
        if (offset) {
            panel.style.transform = 'translateX(' + offset + 'px)';
        }
    }

    /**
     * Close every other group in the same switcher, and every switcher on the
     * page: two open dropdowns at once is a layout bug waiting to happen in a
     * mega-menu header.
     *
     * @param {HTMLElement} except
     * @returns {void}
     */
    function closeOthers(except) {
        var triggers = document.querySelectorAll(SELECTORS.trigger);
        for (var i = 0; i < triggers.length; i++) {
            var trigger = triggers[i];
            if (trigger === except) {
                continue;
            }
            if (trigger.getAttribute('aria-expanded') === 'true') {
                setOpen(trigger, false, false);
            }
        }
    }

    /**
     * @param {HTMLElement} panel
     * @param {HTMLElement} current
     * @param {number} delta +1 for next, -1 for previous
     * @returns {HTMLElement|null}
     */
    function moveFocus(panel, current, delta) {
        var options = optionsOf(panel);
        if (!options.length) {
            return null;
        }

        var index = options.indexOf(current);
        var next = index + delta;
        if (next < 0) {
            next = options.length - 1;
        }
        if (next >= options.length) {
            next = 0;
        }
        options[next].focus();

        return options[next];
    }

    /**
     * @param {HTMLElement} root
     * @returns {void}
     */
    function enhance(root) {
        var triggers = triggersOf(root);

        triggers.forEach(function (trigger) {
            if (trigger.getAttribute('data-ch247-enhanced') === 'true') {
                return;
            }
            trigger.setAttribute('data-ch247-enhanced', 'true');

            var panel = panelFor(trigger);
            if (!panel) {
                return;
            }

            setOpen(trigger, false, false);

            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                var open = trigger.getAttribute('aria-expanded') !== 'true';
                closeOthers(open ? trigger : null);
                setOpen(trigger, open, open);
            });

            trigger.addEventListener('keydown', function (event) {
                var key = event.key;

                // Do not rely on the native button's Enter click: moving focus
                // to a link during that default activation can let the same
                // keypress activate the link and unexpectedly navigate away.
                if (key === 'Enter' || key === ' ' || key === 'Spacebar') {
                    event.preventDefault();
                    event.stopPropagation();
                    var opening = trigger.getAttribute('aria-expanded') !== 'true';
                    closeOthers(opening ? trigger : null);
                    setOpen(trigger, opening, opening);
                    return;
                }

                if (key === 'ArrowDown') {
                    event.preventDefault();
                    if (!isOpen(trigger.parentElement)) {
                        closeOthers(trigger);
                        setOpen(trigger, true, true);
                    } else {
                        moveFocus(panel, document.activeElement, 1);
                    }

                    return;
                }

                if (key === 'ArrowUp') {
                    event.preventDefault();
                    if (!isOpen(trigger.parentElement)) {
                        closeOthers(trigger);
                        setOpen(trigger, true, false);
                        var options = optionsOf(panel);
                        if (options.length) {
                            options[options.length - 1].focus();
                        }
                    } else {
                        moveFocus(panel, document.activeElement, -1);
                    }
                }
            });

            panel.addEventListener('keydown', function (event) {
                var key = event.key;

                if (key === 'Escape') {
                    event.preventDefault();
                    setOpen(trigger, false, false);
                    trigger.focus();

                    return;
                }

                if (key === 'ArrowDown') {
                    event.preventDefault();
                    moveFocus(panel, document.activeElement, 1);

                    return;
                }

                if (key === 'ArrowUp') {
                    event.preventDefault();
                    moveFocus(panel, document.activeElement, -1);

                    return;
                }

                if (key === 'Home') {
                    event.preventDefault();
                    var first = optionsOf(panel)[0];
                    if (first) {
                        first.focus();
                    }

                    return;
                }

                if (key === 'End') {
                    event.preventDefault();
                    var options = optionsOf(panel);
                    if (options.length) {
                        options[options.length - 1].focus();
                    }

                    return;
                }

                if (key === 'Tab') {
                    // Let focus leave naturally, but do not leave an orphan
                    // panel open behind it.
                    setOpen(trigger, false, false);
                }
            });
        });
    }

    /**
     * @returns {void}
     */
    function onDocumentClick(event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }
        // A click on an option is a navigation: let it go through untouched.
        if (target.closest(SELECTORS.option)) {
            return;
        }
        // Trigger clicks have already been handled by their own listener.
        // Closing a panel merely because the target is in the SAME root made
        // every mouse click open and immediately close it during bubbling.
        if (target.closest(SELECTORS.root)) {
            return;
        }
        closeOthers(null);
    }

    /**
     * @returns {void}
     */
    function init() {
        // Telling the stylesheet that JS is live is what allows the panels to
        // be hidden at all; without this line the no-JS fallback stays visible.
        document.documentElement.classList.add('ch247-js');

        var roots = document.querySelectorAll(SELECTORS.root);
        for (var i = 0; i < roots.length; i++) {
            enhance(roots[i]);
        }

        document.addEventListener('click', onDocumentClick);
        window.addEventListener('resize', function () {
            document.querySelectorAll(SELECTORS.panel).forEach(function (panel) {
                if (!panel.hidden) alignPanel(panel);
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                var open = document.querySelectorAll(SELECTORS.trigger + '[aria-expanded="true"]');
                for (var j = 0; j < open.length; j++) {
                    setOpen(open[j], false, false);
                }
            }
        });

        // The off-canvas menu and the cart re-render parts of the header; re-run
        // enhancement for any switcher injected later.
        if (typeof MutationObserver === 'function') {
            var observer = new MutationObserver(function (mutations) {
                for (var m = 0; m < mutations.length; m++) {
                    var added = mutations[m].addedNodes;
                    for (var n = 0; n < added.length; n++) {
                        var node = added[n];
                        if (!node || node.nodeType !== 1) {
                            continue;
                        }
                        if (node.matches && node.matches(SELECTORS.root)) {
                            enhance(node);
                        }
                        var nested = node.querySelectorAll ? node.querySelectorAll(SELECTORS.root) : [];
                        for (var k = 0; k < nested.length; k++) {
                            enhance(nested[k]);
                        }
                    }
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
