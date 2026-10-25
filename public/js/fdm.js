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
        var button = event.submitter;
        // A confirmation can sit on the form or on the specific submit button that was pressed.
        var question = (button && button.getAttribute && button.getAttribute('data-confirm'))
            || (form && form.getAttribute && form.getAttribute('data-confirm'));
        if (question && !window.confirm(question)) {
            event.preventDefault();
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