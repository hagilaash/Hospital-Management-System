/*
 * Laboratory helpers. No dependencies; never sends a request on its own.
 *  - Buttons marked data-submit-once are disabled after the form has really been submitted, so a double
 *    click cannot send it twice. (The database also refuses a second sample or a second result.)
 *  - The confirmation questions come from the forms' data-confirm attribute, which the shared fdm.js asks.
 *  - The report's Print button.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var button = form && form.querySelector ? form.querySelector('[data-submit-once]') : null;
        if (!button) {
            return;
        }
        // The shared confirmation (fdm.js, on document) runs AFTER this handler and may cancel the submit,
        // so decide only once the event has finished.
        window.setTimeout(function () {
            if (!event.defaultPrevented) {
                button.disabled = true;
            }
        }, 0);
    });

    // Coming back with the Back button must not leave a button disabled.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-submit-once]').forEach(function (button) { button.disabled = false; });
    });

    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () { window.print(); });
    });
})();