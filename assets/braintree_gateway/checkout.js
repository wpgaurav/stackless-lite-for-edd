/**
 * Stackless for EDD: Braintree in EDD's purchase form (shortcode and block checkout, pay links
 * included) and in the account page's Add a card form.
 *
 * EDD swaps the payment fields over AJAX when the gateway changes, so the form is mounted again
 * on edd_gateway_loaded. A click on Purchase first gets a nonce verified with 3-D Secure, then
 * presses Purchase again so EDD validates and submits with it.
 */
(function ($) {
	'use strict';

	var B = window.stacklessEddBraintree;
	if (!B || !$) {
		return;
	}

	var GATEWAY = 'stackless_edd_braintree';
	var ADDRESS = ['billing_country', 'state', 'card_state', 'card_zip', 'card_address', 'card_address_2', 'card_city'];
	var state = { root: null, controller: null, config: null, working: false, passThrough: false, submitting: false };

	function root() {
		return document.getElementById('stackless-braintree-form');
	}

	function status(message) {
		var el = document.querySelector('#stackless-braintree-form .stackless-braintree__status');
		if (el) {
			el.textContent = message || '';
		}
	}

	function field(name) {
		var el = root();
		return el && state.config ? el.querySelector('input[name="' + state.config.fields[name] + '"]') : null;
	}

	function formValue(name) {
		var el = document.querySelector('#edd_purchase_form [name="' + name + '"]');
		return el && el.value ? el.value : '';
	}

	function purchaseForm() {
		return document.getElementById('edd_purchase_form');
	}

	/** The purchase form posts the chosen gateway in payment-mode (radio or select) or edd-gateway (hidden). */
	function selected() {
		var form = purchaseForm();
		if (!form) {
			return false;
		}
		var radio = form.querySelector('input[name="payment-mode"]:checked');
		if (radio) {
			return radio.value === GATEWAY;
		}
		var select = document.querySelector('select#edd-gateway');
		if (select) {
			return select.value === GATEWAY;
		}
		var hidden = form.querySelector('input[name="edd-gateway"]');
		// A zero-total cart posts "manual"; the fields are printed only when this gateway was chosen.
		return hidden && hidden.value !== 'manual' ? hidden.value === GATEWAY : !!root();
	}

	function savedCard() {
		if (!state.config || !state.config.fields.saved) {
			return '';
		}
		var saved = document.querySelector('input[name="' + state.config.fields.saved + '"]:checked');
		return saved && saved.value && saved.value !== 'new' ? saved.value : '';
	}

	function toggleNewCard() {
		var wrap = document.querySelector('#stackless-braintree-form .stackless-braintree__new');
		if (wrap) {
			wrap.hidden = !!savedCard();
		}
	}

	function clearNonce() {
		['nonce', 'device'].forEach(function (name) {
			var input = field(name);
			if (input) {
				input.value = '';
			}
		});
	}

	function wantsSave() {
		var box = state.config ? document.getElementById(state.config.fields.save) : null;
		return !!(box && box.checked);
	}

	/** EDD works out tax from the address in each request, so the session request carries it. */
	function addressValues() {
		var values = {};
		if (state.config && state.config.flow === 'checkout') {
			// Fees can depend on the chosen method, which EDD reads from the request.
			values['payment-mode'] = GATEWAY;
			ADDRESS.forEach(function (name) {
				var value = formValue(name);
				if (value) {
					values[name] = value;
				}
			});
		}
		return values;
	}

	function billing() {
		var base = state.config.billing || {};
		if (state.config.flow !== 'checkout') {
			return base;
		}
		return {
			first: formValue('edd_first') || base.first,
			last: formValue('edd_last') || base.last,
			email: formValue('edd_email') || base.email,
			line1: formValue('card_address'),
			line2: formValue('card_address_2'),
			city: formValue('card_city'),
			region: formValue('card_state'),
			postcode: formValue('card_zip'),
			country: formValue('billing_country')
		};
	}

	function destroy() {
		if (state.controller) {
			state.controller.destroy();
		}
		state.controller = null;
		state.root = null;
	}

	function mount() {
		var el = root();
		if (!el) {
			destroy();
			return;
		}
		if (state.root === el && state.controller) {
			return;
		}
		// EDD replaced the payment fields: the old iframes belong to a removed node.
		destroy();
		try {
			state.config = JSON.parse(el.getAttribute('data-config') || '{}');
		} catch (e) {
			return;
		}
		state.root = el;
		toggleNewCard();
		status(state.config.i18n.loading);
		var controller = B.create(state.config, el, { values: addressValues });
		state.controller = controller;
		controller.ready.then(function () {
			if (controller === state.controller) {
				status('');
			}
		}).catch(function (error) {
			if (controller === state.controller) {
				status(B.message(error, state.config));
			}
		});
	}

	function needsNonce() {
		if (!root() || !state.config) {
			return false;
		}
		if (savedCard() && !state.config.secure) {
			return false;
		}
		var input = field('nonce');
		return !!input && !input.value;
	}

	/** @return {Promise} Resolves when the nonce field is filled. */
	function prepare() {
		if (!state.controller) {
			return Promise.reject(new Error('not ready'));
		}
		var started = state.root;
		status('');
		return state.controller.pay({ saved: savedCard(), save: wantsSave(), billing: billing() }).then(function (result) {
			if (started !== root()) {
				throw new Error('replaced');
			}
			field('nonce').value = result.nonce;
			var device = field('device');
			if (device) {
				device.value = result.device;
			}
		});
	}

	/* ---- EDD checkout ------------------------------------------------------------ */

	$(document.body).on('edd_gateway_loaded', mount);

	// The total may have changed, so a verification done for the old one no longer fits.
	$(document.body).on('edd_discount_applied edd_discount_removed edd_quantity_updated edd_taxes_recalculated edd_cart_item_removed', function () {
		clearNonce();
		window.setTimeout(mount, 0);
	});

	$(document.body).on('change', '#stackless-braintree-form input[type="radio"], #stackless-braintree-form input[type="checkbox"]', function () {
		toggleNewCard();
		clearNonce();
	});

	// A failed attempt needs a fresh nonce next time.
	$(document.body).on('edd_checkout_error', clearNonce);

	// Capture phase, so the nonce exists before EDD's own Purchase handler validates and submits.
	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('#edd_purchase_form #edd_purchase_submit [type=submit]') : null;
		if (!button || state.passThrough || !selected()) {
			return;
		}
		if (state.working) {
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}
		if (!needsNonce()) {
			return;
		}
		var form = purchaseForm();
		if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
			return; // Let the browser show which field is missing.
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		state.working = true;
		button.disabled = true;
		prepare().then(function () {
			state.working = false;
			button.disabled = false;
			state.passThrough = true;
			button.click();
			state.passThrough = false;
		}).catch(function (error) {
			state.working = false;
			button.disabled = false;
			status(B.message(error, state.config));
		});
	}, true);

	/* ---- Account page: add a card --------------------------------------------------- */

	$(document).on('submit', 'form.stackless-braintree-add-method', function (event) {
		if (state.submitting) {
			return;
		}
		event.preventDefault();
		if (state.working) {
			return;
		}
		var formEl = this;
		state.working = true;
		$(formEl).addClass('stackless-braintree-processing');
		prepare().then(function () {
			state.submitting = true;
			HTMLFormElement.prototype.submit.call(formEl);
		}).catch(function (error) {
			state.working = false;
			$(formEl).removeClass('stackless-braintree-processing');
			status(B.message(error, state.config));
		});
	});

	// A single gateway (or the account form) renders the fields with the page, without edd_gateway_loaded.
	$(mount);
})(window.jQuery);
