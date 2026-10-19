/*
 * HMS front desk helpers. No dependencies.
 *  1. Ask before submitting a form marked data-confirm="question".
 *  2. Reload a page marked data-auto-refresh="seconds" (the live queue), but
 *     never while the tab is hidden or while the user is typing or has a
 *     cancel form open, so nothing they entered is lost.
 * State changes are always normal POST forms; this file never sends requests.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form && form.hasAttribute && form.hasAttribute('data-confirm')) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        }
    });

    var box = document.querySelector('[data-auto-refresh]');
    if (box) {
        var seconds = parseInt(box.getAttribute('data-auto-refresh'), 10);
        if (seconds >= 10) {
            window.setInterval(function () {
                var active = document.activeElement;
                var typing = active && /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName);
                var formOpen = document.querySelector('details[open]');
                if (!document.hidden && !typing && !formOpen) {
                    window.location.reload();
                }
            }, seconds * 1000);
        }
    }
})();
