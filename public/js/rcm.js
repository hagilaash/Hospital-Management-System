/*
 * RCM helpers. No dependencies; never sends a request on its own.
 *  - Finalize form: the button stays disabled until "I have reviewed ..." is ticked,
 *    and after the first click it is disabled so a double-click cannot submit twice.
 *    (The server and the database prevent a duplicate finalization regardless of this script.)
 *  - The confirmation question ("Finalize ...? This cannot be undone.") comes from the
 *    form's data-confirm attribute, which the shared fdm.js asks before submitting.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-finalize-form]');
    if (!form) {
        return;
    }

    var button = form.querySelector('[data-submit-once]');
    var check = form.querySelector('[data-require-check]');

    if (check && button) {
        var sync = function () { button.disabled = !check.checked; };
        check.addEventListener('change', sync);
        sync();
    }

    form.addEventListener('submit', function (event) {
        if (!button) {
            return;
        }
        // The shared confirmation (fdm.js, on document) runs AFTER this handler and may cancel the
        // submit. So decide only once the event has finished: disable the button if, and only if,
        // the form really is being submitted (and after the browser has taken the form data).
        window.setTimeout(function () {
            if (!event.defaultPrevented) {
                button.disabled = true;
            }
        }, 0);
    });

    // Coming back with the Back button must not leave the button disabled.
    window.addEventListener('pageshow', function () {
        if (button) {
            button.disabled = check ? !check.checked : false;
        }
    });
})();