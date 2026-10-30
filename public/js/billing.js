/*
 * HMS billing helpers. No dependencies; never sends a request.
 *  1. New-bill form: add item rows and show an ESTIMATE of the totals while typing.
 *     The estimate is for the cashier only: the server recalculates every amount.
 *  2. Payment form: "Pay full balance" button, and a guard against double-clicking Record payment.
 *  3. Invoice: the Print button.
 * Money is handled in whole cents (integers), never floats.
 */
(function () {
    'use strict';

    function parseCents(text) {
        var m = /^\s*(\d{1,8})(?:\.(\d{1,2}))?\s*$/.exec(text || '');
        if (!m) {
            return null;
        }
        return parseInt(m[1], 10) * 100 + (m[2] ? parseInt((m[2] + '0').slice(0, 2), 10) : 0);
    }

    function formatCents(cents) {
        var whole = Math.floor(cents / 100);
        var fraction = cents % 100;
        return String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (fraction < 10 ? '0' : '') + fraction;
    }

    // ---- 1. new bill ---------------------------------------------------
    var form = document.querySelector('[data-bill-form]');
    if (form) {
        var body = form.querySelector('[data-items-body]');
        var discountInput = form.querySelector('.js-discount');
        var taxInput = form.querySelector('.js-tax');
        var preview = {};
        ['subtotal', 'discount', 'tax', 'total'].forEach(function (key) {
            preview[key] = form.querySelector('[data-preview="' + key + '"]');
        });

        var recalculate = function () {
            var subtotal = 0;
            var valid = true;

            body.querySelectorAll('tr').forEach(function (row) {
                var qtyInput = row.querySelector('.js-qty');
                var priceInput = row.querySelector('.js-price');
                var out = row.querySelector('.js-line-total');
                var descInput = row.querySelector('input[name="item_description[]"]');
                var empty = descInput.value.trim() === '' && qtyInput.value.trim() === '' && priceInput.value.trim() === '';

                if (empty) {
                    out.textContent = '\u2014';
                    return;
                }
                var qtyText = qtyInput.value.trim();
                var qty = qtyText === '' ? 1 : (/^\d{1,3}$/.test(qtyText) ? parseInt(qtyText, 10) : null);
                var price = parseCents(priceInput.value);
                if (qty === null || qty < 1 || price === null) {
                    out.textContent = '\u2014';
                    valid = false;
                    return;
                }
                var line = qty * price;
                subtotal += line;
                out.textContent = formatCents(line);
            });

            var discount = discountInput.value.trim() === '' ? 0 : parseCents(discountInput.value);
            var taxHundredths = taxInput.value.trim() === '' ? 0 : parseCents(taxInput.value); // percent * 100
            if (discount === null || taxHundredths === null || taxHundredths > 10000 || discount > subtotal) {
                valid = false;
            }

            if (!valid || subtotal === 0) {
                Object.keys(preview).forEach(function (key) { preview[key].textContent = '\u2014'; });
                return;
            }
            var tax = Math.round(((subtotal - discount) * taxHundredths) / 10000);
            preview.subtotal.textContent = formatCents(subtotal);
            preview.discount.textContent = '\u2212 ' + formatCents(discount);
            preview.tax.textContent = formatCents(tax);
            preview.total.textContent = formatCents(subtotal - discount + tax);
        };

        form.addEventListener('input', recalculate);

        var addButton = form.querySelector('[data-add-row]');
        if (addButton) {
            addButton.addEventListener('click', function () {
                var rows = body.querySelectorAll('tr');
                if (rows.length >= 50) {
                    return;
                }
                var clone = rows[rows.length - 1].cloneNode(true);
                clone.classList.remove('field-invalid');
                clone.querySelectorAll('.field-error').forEach(function (node) { node.remove(); });
                clone.querySelectorAll('input').forEach(function (input) {
                    input.value = '';
                    var label = input.getAttribute('aria-label');
                    if (label) {
                        input.setAttribute('aria-label', label.replace(/\d+$/, String(rows.length + 1)));
                    }
                });
                clone.querySelector('.js-line-total').textContent = '\u2014';
                body.appendChild(clone);
                clone.querySelector('input').focus();
            });
        }

        recalculate();
    }

    // ---- 2. payment form -------------------------------------------------
    var fill = document.querySelector('[data-fill-balance]');
    if (fill) {
        fill.addEventListener('click', function () {
            var amount = document.getElementById('amount');
            if (amount) {
                amount.value = amount.getAttribute('data-balance') || '';
                amount.focus();
            }
        });
    }

    var paymentForm = document.querySelector('[data-payment-form]');
    if (paymentForm) {
        var submit = paymentForm.querySelector('[data-submit-once]');
        paymentForm.addEventListener('submit', function () {
            if (submit) {
                // Disable after the browser has taken the form data, so a double click cannot send it twice.
                window.setTimeout(function () { submit.disabled = true; }, 0);
            }
        });
        // Coming back with the Back button must not leave the button disabled.
        window.addEventListener('pageshow', function () {
            if (submit) {
                submit.disabled = false;
            }
        });
    }

    // ---- 3. invoice ------------------------------------------------------------
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () { window.print(); });
    });
})();