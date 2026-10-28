/* Stackless for EDD: consent checkboxes on the checkout (shortcode and EDD Checkout block). */
(function () {
    'use strict';
    var labels = window.stacklessEddCheckoutEnhancements || {};

    // EDD redraws the purchase form when the payment method changes (and after some AJAX updates).
    // Remember ticked consents so a redraw never silently clears them.
    var ticked = {};
    function restore() {
        document.querySelectorAll('[data-stackless-consent]').forEach(function (input) {
            if (Object.prototype.hasOwnProperty.call(ticked, input.name)) {
                input.checked = ticked[input.name];
            }
        });
    }
    function clearError(row) {
        if (!row) {
            return;
        }
        row.classList.remove('has-error');
        var input = row.querySelector('input');
        if (input) {
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
        }
        var error = row.querySelector('.stackless-consent-error');
        if (error) {
            error.remove();
        }
    }
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (input && input.matches && input.matches('[data-stackless-consent]')) {
            ticked[input.name] = input.checked;
            if (input.checked) {
                clearError(input.closest('.stackless-consent'));
            }
        }
    });

    // Point at the box that is missing before the round trip; the server still refuses the order without it.
    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('#edd-purchase-button, #edd_purchase_form [type="submit"]') : null;
        if (!button) {
            return;
        }
        var form = button.form || document.getElementById('edd_purchase_form');
        if (!form) {
            return;
        }
        var missing = [];
        form.querySelectorAll('[data-stackless-consent][data-required]').forEach(function (input) {
            var row = input.closest('.stackless-consent');
            clearError(row);
            if (input.checked) {
                return;
            }
            missing.push(input);
            row.classList.add('has-error');
            var error = document.createElement('span');
            error.className = 'stackless-consent-error';
            error.id = input.id + '-error';
            error.setAttribute('role', 'alert');
            error.textContent = labels.required || 'Please tick this box to continue.';
            row.appendChild(error);
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', error.id);
        });
        if (!missing.length) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        missing[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
        missing[0].focus({ preventScroll: true });
    }, true);

    var queued = false;
    function onMutation() {
        if (queued) {
            return;
        }
        queued = true;
        // Background tabs pause animation frames; fall back to a timeout there.
        (document.hidden || !window.requestAnimationFrame ? function (fn) { window.setTimeout(fn, 50); } : window.requestAnimationFrame.bind(window))(function () {
            queued = false;
            restore();
        });
    }
    if ('MutationObserver' in window) {
        new MutationObserver(onMutation).observe(document.body, { childList: true, subtree: true });
    }
    if (window.jQuery) {
        window.jQuery(document.body).on('edd_gateway_loaded edd_discount_applied edd_discount_removed edd_quantity_updated', restore);
    }
}());
