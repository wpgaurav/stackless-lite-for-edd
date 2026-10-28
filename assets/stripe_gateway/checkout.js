/**
 * Stackless for EDD: Stripe Payment Element in EDD's purchase form (shortcode and block checkout)
 * and in the account page's Add payment method form.
 *
 * Flow: mount the Payment Element in deferred mode (amount + currency, no intent yet) → when the
 * shopper clicks Purchase, create a ConfirmationToken → put its id in a hidden field → let EDD
 * validate and submit its form → the server creates and confirms the intent. If the bank needs
 * 3D Secure the shopper comes back with a #stackless-edd-stripe-confirm/... fragment, handled
 * here with stripe.handleNextAction(). EDD reloads the payment fields over AJAX whenever the
 * gateway changes, so the element is mounted again on edd_gateway_loaded.
 */
jQuery(function ($) {
	'use strict';

	var S = window.stacklessEddStripe;
	if (!S || !S.stripe()) {
		return;
	}
	var cfg = S.config;
	var gatewayId = cfg.gatewayId;
	var fields = cfg.fields || {};
	var state = { elements: null, element: null, mountedOn: null, signature: '', passThrough: false, submitting: false };

	function container() {
		return document.getElementById('stackless-stripe-payment-element');
	}

	function context() {
		var el = container();
		if (!el) {
			return null;
		}
		return {
			flow: el.getAttribute('data-flow') || 'checkout',
			mode: el.getAttribute('data-mode') === 'setup' ? 'setup' : 'payment',
			amount: parseInt(el.getAttribute('data-amount'), 10) || 0,
			currency: el.getAttribute('data-currency') || 'usd',
			requiresSave: el.getAttribute('data-requires-save') === '1',
			capture: el.getAttribute('data-capture') === 'manual' ? 'manual' : 'automatic'
		};
	}

	function wantsSave(ctx) {
		if (ctx.requiresSave || ctx.mode === 'setup') {
			return true;
		}
		var box = document.getElementById(fields.save);
		return !!(box && box.checked);
	}

	function elementsOptions(ctx) {
		var options = {
			mode: ctx.mode,
			currency: ctx.currency,
			appearance: S.appearance(container())
		};
		if (ctx.mode === 'payment') {
			options.amount = Math.max(ctx.amount, 1);
			options.captureMethod = ctx.capture;
			if (wantsSave(ctx)) {
				options.setupFutureUsage = 'off_session';
			}
		}
		return options;
	}

	function purchaseForm() {
		return document.getElementById('edd_purchase_form');
	}

	function addForm() {
		return document.querySelector('form.stackless-stripe-add-method');
	}

	/** The purchase form posts the chosen gateway in edd-gateway (radio, select or hidden input). */
	function selected() {
		var form = purchaseForm();
		if (!form) {
			return false;
		}
		var radio = form.querySelector('input[name="payment-mode"]:checked');
		if (radio) {
			return radio.value === gatewayId;
		}
		var select = document.querySelector('select#edd-gateway');
		if (select) {
			return select.value === gatewayId;
		}
		var hidden = form.querySelector('input[name="edd-gateway"]');
		return hidden ? hidden.value === gatewayId : !!container();
	}

	function usingSavedToken() {
		var token = $('input[name="' + fields.token + '"]:checked').val();
		return !!token && token !== 'new';
	}

	function tokenField() {
		return $('input.stackless-stripe-confirmation-token');
	}

	function mount() {
		var el = container();
		var ctx = context();
		if (!el || !ctx) {
			return;
		}
		var signature = [ctx.mode, ctx.currency, ctx.capture].join('|');
		try {
			// EDD replaced the payment fields: the old element's container is gone.
			if (state.mountedOn && !document.body.contains(state.mountedOn)) {
				try {
					state.element.destroy();
				} catch (e) {
					// Already gone.
				}
				state.elements = null;
				state.element = null;
				state.mountedOn = null;
			}
			if (!state.elements || state.signature !== signature) {
				if (state.element) {
					state.element.destroy();
				}
				state.elements = S.stripe().elements(elementsOptions(ctx));
				state.element = state.elements.create('payment', { layout: { type: 'tabs', defaultCollapsed: false } });
				state.element.on('change', function () {
					showFieldError('');
				});
				state.signature = signature;
				state.mountedOn = null;
			} else if (ctx.mode === 'payment') {
				state.elements.update({ amount: Math.max(ctx.amount, 1) });
				syncSave(ctx);
			}
			if (state.mountedOn !== el) {
				if (state.mountedOn) {
					state.element.unmount();
				}
				state.element.mount(el);
				state.mountedOn = el;
			}
		} catch (e) {
			showFieldError(S.errorMessage(e));
		}
		toggleNewMethod();
	}

	/** Keeps the Element's setupFutureUsage in step with the "save" checkbox (must match the server). */
	function syncSave(ctx) {
		try {
			state.elements.update({ setupFutureUsage: wantsSave(ctx) ? 'off_session' : null });
		} catch (e) {
			// Older Stripe.js builds reject null; the server still decides whether to save.
		}
	}

	/** Cart changed (discount, quantity, taxes): charge the new total. */
	function syncAmount() {
		var el = container();
		var amountEl = document.querySelector('.edd_cart_amount');
		if (!el || !amountEl || !state.elements) {
			return;
		}
		var ctx = context();
		var total = amountEl.getAttribute('data-total');
		if (!ctx || ctx.mode !== 'payment' || total === null || total === '') {
			return;
		}
		var amount = S.toStripeAmount(total, ctx.currency);
		if (amount > 0 && amount !== ctx.amount) {
			el.setAttribute('data-amount', String(amount));
			try {
				state.elements.update({ amount: amount });
			} catch (e) {
				// The server charges the order total either way.
			}
		}
	}

	function toggleNewMethod() {
		var wrap = document.querySelector('.stackless-stripe-new-method');
		if (wrap) {
			wrap.hidden = usingSavedToken();
		}
	}

	function showFieldError(message) {
		$('.stackless-stripe-errors').text(message || '');
	}

	function showError(message) {
		var form = purchaseForm() || addForm();
		$('.stackless-stripe-notice').remove();
		var notice = $('<div class="edd_errors edd-alert edd-alert-error stackless-stripe-notice" role="alert"><p class="edd_error">' + S.escapeHtml(message) + '</p></div>');
		var anchor = $('#edd_purchase_submit');
		if (anchor.length) {
			anchor.before(notice);
		} else if (form) {
			$(form).prepend(notice);
		}
		unblock();
		$('#edd-purchase-button').prop('disabled', false);
		var top = notice.offset();
		if (top) {
			$('html, body').animate({ scrollTop: top.top - 100 }, 300);
		}
	}

	function block() {
		$('#edd_checkout_wrap, form.stackless-stripe-add-method').addClass('stackless-stripe-processing');
	}

	function unblock() {
		$('.stackless-stripe-processing').removeClass('stackless-stripe-processing');
	}

	function billingDetails() {
		var val = function (name) {
			var field = document.querySelector('#edd_purchase_form [name="' + name + '"]');
			return field && field.value ? field.value : undefined;
		};
		var name = [val('edd_first'), val('edd_last')].filter(Boolean).join(' ');
		var details = {
			name: name || undefined,
			email: val('edd_email'),
			address: {
				line1: val('card_address'),
				line2: val('card_address_2'),
				city: val('card_city'),
				state: val('card_state'),
				postal_code: val('card_zip'),
				country: val('billing_country')
			}
		};
		Object.keys(details.address).forEach(function (key) {
			if (details.address[key] === undefined) {
				delete details.address[key];
			}
		});
		if (!Object.keys(details.address).length) {
			delete details.address;
		}
		Object.keys(details).forEach(function (key) {
			if (details[key] === undefined) {
				delete details[key];
			}
		});
		return details;
	}

	/** elements.submit() must run synchronously inside the click for wallets. */
	function createToken() {
		if (!state.elements) {
			return Promise.reject(new Error(S.i18n('enterDetails')));
		}
		var ctx = context();
		if (ctx && ctx.mode === 'payment') {
			syncSave(ctx);
		}
		return state.elements.submit().then(function (result) {
			if (result && result.error) {
				throw result.error;
			}
			return S.stripe().createConfirmationToken({
				elements: state.elements,
				params: { payment_method_data: { billing_details: billingDetails() } }
			});
		}).then(function (result) {
			if (result.error) {
				throw result.error;
			}
			tokenField().val(result.confirmationToken.id);
			return result.confirmationToken.id;
		});
	}

	/* -------------------------------------------------------------------
	 * EDD checkout
	 * ----------------------------------------------------------------- */

	$(document.body).on('edd_gateway_loaded', function (event, gateway) {
		if (gateway === gatewayId || container()) {
			mount();
		}
	});

	$(document.body).on('edd_discount_applied edd_discount_removed edd_quantity_updated edd_taxes_recalculated edd_cart_item_removed', function () {
		window.setTimeout(syncAmount, 0);
	});

	$(document.body).on('change', 'input[name="' + fields.token + '"]', function () {
		toggleNewMethod();
		tokenField().val('');
	});

	$(document.body).on('change', '#' + fields.save, function () {
		var ctx = context();
		if (state.elements && ctx && ctx.mode === 'payment') {
			syncSave(ctx);
		}
	});

	$(document.body).on('edd_checkout_error', function () {
		// A ConfirmationToken is single-use: get a fresh one on the next attempt.
		tokenField().val('');
	});

	// Capture phase, so the token exists before EDD's own Purchase handler validates and submits.
	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('#edd_purchase_form #edd_purchase_submit [type=submit]') : null;
		if (!button || state.passThrough || !selected() || !container() || usingSavedToken() || tokenField().val()) {
			return;
		}
		var form = purchaseForm();
		if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
			return; // Let the browser show which field is missing.
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		$('.stackless-stripe-notice').remove();
		button.disabled = true;
		createToken().then(function () {
			button.disabled = false;
			state.passThrough = true;
			button.click();
			state.passThrough = false;
		}).catch(function (error) {
			button.disabled = false;
			showError(S.errorMessage(error));
		});
	}, true);

	/* -------------------------------------------------------------------
	 * Account page: add a payment method
	 * ----------------------------------------------------------------- */

	$(document).on('submit', 'form.stackless-stripe-add-method', function (event) {
		if (state.submitting) {
			return;
		}
		if (tokenField().val()) {
			state.submitting = true;
			return;
		}
		event.preventDefault();
		var formEl = this;
		block();
		createToken().then(function () {
			state.submitting = true;
			HTMLFormElement.prototype.submit.call(formEl);
		}).catch(function (error) {
			showError(S.errorMessage(error));
		});
	});

	/* -------------------------------------------------------------------
	 * 3D Secure / redirect confirmation fragments
	 * ----------------------------------------------------------------- */

	function handleHash() {
		var action = S.parseConfirmHash(window.location.hash);
		if (!action) {
			return;
		}
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', window.location.pathname + window.location.search);
		}
		block();
		S.confirmAndVerify(action).then(function (response) {
			window.location.href = response.redirect;
		}).catch(function (error) {
			tokenField().val('');
			state.submitting = false;
			showError(S.errorMessage(error));
		});
	}

	window.addEventListener('hashchange', handleHash);

	// A single gateway (or the account form) renders the fields with the page, without edd_gateway_loaded.
	if (container()) {
		mount();
	}
	handleHash();
});
