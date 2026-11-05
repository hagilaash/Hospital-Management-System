/*
 * Pharmacy helpers. No dependencies; never sends a request on its own.
 *  - Dispense form: "Fill all remaining" puts each medicine's remaining quantity in its box, and the
 *    Dispense button is disabled after the first click so a double-click cannot submit twice.
 *    (The database refuses to dispense more than what is left, so a repeat can never dispense twice.)
 *  - The confirmation question comes from the form's data-confirm attribute, which the shared fdm.js asks.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-dispense-form]');
    if (!form) {
        return;
    }

    var fill = form.querySelector('[data-fill-remaining]');
    if (fill) {
        fill.addEventListener('click', function () {
            form.querySelectorAll('input[data-remaining]').forEach(function (input) {
                input.value = input.getAttribute('data-remaining');
            });
        });
    }

    var button = form.querySelector('[data-submit-once]');
    if (button) {
        form.addEventListener('submit', function (event) {
            // The shared confirmation (fdm.js, on document) runs AFTER this handler and may cancel the
            // submit, so decide only once the event has finished.
            window.setTimeout(function () {
                if (!event.defaultPrevented) {
                    button.disabled = true;
                }
            }, 0);
        });
        // Coming back with the Back button must not leave the button disabled.
        window.addEventListener('pageshow', function () { button.disabled = false; });
    }
})();
