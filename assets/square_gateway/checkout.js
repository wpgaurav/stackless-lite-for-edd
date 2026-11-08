/**
 * Stackless for EDD: Square in EDD's purchase form (shortcode and block checkout) and in the
 * account page's Add a card form.
 *
 * The card form is mounted from the settings in #stackless-square-form. EDD swaps the payment
 * fields over AJAX whenever the gateway changes, so the form is mounted again on
 * edd_gateway_loaded, and the old card instance is destroyed. When the shopper clicks Purchase,
 * the card is tokenized (Square runs buyer verification, 3-D Secure, from the verification
 * details) and the one-time token goes into a hidden field before EDD validates and submits its
 * form. Google Pay and Apple Pay fill the same field and press Purchase themselves.
 */
(function ($) {
	'use strict';

	var S = window.stacklessEddSquare;
	if (!S || !$) {
		return;
	}

	var GATEWAY = 'stackless_edd_square';
	var state = { container: null, card: null, config: null, request: null, wallets: [], generation: 0, passThrough: false, submitting: false };

	function root() {
		return document.getElementById('stackless-square-form');
	}

	function config() {
		var el = root();
		if (!el) {
			return null;
		}
		try {
			return JSON.parse(el.getAttribute('data-config') || '{}');
		} catch (e) {
			return null;
		}
	}

	function status(message) {
		var el = document.querySelector('#stackless-square-form .stackless-square__status');
		if (el) {
			el.textContent = message || '';
		}
	}

	function field(name) {
		var el = root();
		return el && name ? el.querySelector('input[name="' + name + '"]') : null;
	}

	function purchaseForm() {
		return document.getElementById('edd_purchase_form');
	}

	function purchaseButton() {
		return document.querySelector('#edd_purchase_form #edd_purchase_submit [type=submit]');
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
		return hidden ? hidden.value === GATEWAY : !!root();
	}

	function savedRadio() {
		var cfg = state.config || config();
		return cfg ? document.querySelector('input[name="' + cfg.fields.saved + '"]:checked') : null;
	}

	function usingSavedCard() {
		var saved = savedRadio();
		return !!(saved && saved.value && saved.value !== 'new');
	}

	function toggleNewCard() {
		var wrap = document.querySelector('#stackless-square-form .stackless-square__new');
		if (wrap) {
			wrap.hidden = usingSavedCard();
		}
	}

	function wantsSave(cfg) {
		if (cfg.requiresSave || cfg.flow === 'add') {
			return true;
		}
		var box = document.getElementById(cfg.fields.save);
		return !!(box && box.checked);
	}

	/** The cart total EDD shows now (discounts and taxes change it without reloading the form). */
	function amount(cfg) {
		if (cfg.flow !== 'checkout') {
			return cfg.amount;
		}
		var el = document.querySelector('.edd_cart_amount[data-total]');
		var total = el ? Number(el.getAttribute('data-total')) : NaN;
		if (isNaN(total)) {
			return cfg.amount;
		}
		var decimals = String(cfg.amount).indexOf('.') === -1 ? 0 : 2;
		return total.toFixed(decimals);
	}

	function billing(cfg) {
		var base = cfg.billing || {};
		if (cfg.flow !== 'checkout') {
			return base;
		}
		var get = function (name) {
			var el = document.querySelector('#edd_purchase_form [name="' + name + '"]');
			return el && el.value ? el.value : '';
		};
		return {
			givenName: get('edd_first') || base.givenName,
			familyName: get('edd_last') || base.familyName,
			email: get('edd_email') || base.email,
			addressLines: [get('card_address'), get('card_address_2')].filter(Boolean),
			city: get('card_city'),
			state: get('card_state'),
			postalCode: get('card_zip'),
			countryCode: get('billing_country')
		};
	}

	function destroy() {
		state.generation++;
		if (state.card && state.card.destroy) {
			try {
				state.card.destroy();
			} catch (e) {
				// The node it lived in is already gone.
			}
		}
		state.wallets.forEach(function (wallet) {
			if (wallet && wallet.destroy) {
				try {
					wallet.destroy();
				} catch (e) {
					// Already gone.
				}
			}
		});
		state.card = null;
		state.container = null;
		state.request = null;
		state.wallets = [];
	}

	function mount() {
		var cfg = config();
		var container = document.getElementById('stackless-square-card');
		if (!cfg || !container) {
			if (state.container && !document.body.contains(state.container)) {
				destroy();
			}
			return;
		}
		state.config = cfg;
		toggleNewCard();
		if (state.container === container && state.card) {
			return;
		}
		// EDD replaced the payment fields: the old card belongs to a removed node.
		destroy();
		state.container = container;
		var generation = state.generation;
		status(cfg.i18n.loading);
		S.payments(cfg).then(function (payments) {
			return payments.card().then(function (card) {
				if (generation !== state.generation) {
					card.destroy();
					return;
				}
				return card.attach('#stackless-square-card').then(function () {
					state.card = card;
					status('');
					mountWallets(payments, cfg, generation);
				});
			});
		}).catch(function () {
			if (generation === state.generation) {
				status(cfg.i18n.failed);
			}
		});
	}

	function mountWallets(payments, cfg, generation) {
		if (!cfg.wallets || !cfg.wallets.length || Number(amount(cfg)) <= 0) {
			return;
		}
		var request = S.paymentRequest(payments, cfg, amount(cfg));
		state.request = request;
		if (cfg.wallets.indexOf('google_pay') !== -1 && document.getElementById('stackless-square-google-pay')) {
			payments.googlePay(request).then(function (googlePay) {
				if (generation !== state.generation) {
					return;
				}
				state.wallets.push(googlePay);
				return googlePay.attach('#stackless-square-google-pay').then(function () {
					document.getElementById('stackless-square-google-pay').addEventListener('click', function (event) {
						event.preventDefault();
						payWithWallet(googlePay, 'google_pay');
					});
				});
			}).catch(function () { /* Google Pay is not available on this device. */ });
		}
		var appleButton = document.getElementById('stackless-square-apple-pay');
		if (cfg.wallets.indexOf('apple_pay') !== -1 && appleButton) {
			payments.applePay(request).then(function (applePay) {
				if (generation !== state.generation) {
					return;
				}
				state.wallets.push(applePay);
				appleButton.hidden = false;
				appleButton.addEventListener('click', function (event) {
					event.preventDefault();
					payWithWallet(applePay, 'apple_pay');
				});
			}).catch(function () { /* Apple Pay is not available here. */ });
		}
	}

	/** Discounts and taxes change the total without a new form: keep the wallet sheets in step. */
	function syncWalletAmount() {
		var cfg = state.config;
		if (!cfg || !state.request || typeof state.request.update !== 'function') {
			return;
		}
		try {
			state.request.update({ total: { amount: String(amount(cfg)), label: cfg.storeName || cfg.i18n.total } });
		} catch (e) {
			// The server charges the order total either way.
		}
	}

	function payWithWallet(wallet, method) {
		S.tokenize(wallet, null).then(function (token) {
			submitWith(token, method);
		}).catch(function (error) {
			status(error && error.message ? error.message : state.config.i18n.invalid);
		});
	}

	/** A wallet token: use it instead of a saved card and press Purchase. */
	function submitWith(token, method) {
		var cfg = state.config;
		var tokenField = field(cfg.fields.token);
		if (!tokenField) {
			return;
		}
		var fresh = document.querySelector('input[name="' + cfg.fields.saved + '"][value="new"]');
		if (fresh) {
			fresh.checked = true;
			toggleNewCard();
		}
		tokenField.value = token;
		var methodField = field(cfg.fields.method);
		if (methodField) {
			methodField.value = method;
		}
		var button = purchaseButton();
		if (button) {
			state.passThrough = true;
			button.click();
			state.passThrough = false;
		}
	}

	/** Tokenize the card form; resolves when the token field is filled. */
	function tokenizeCard() {
		var cfg = state.config || config();
		if (!state.card || !cfg) {
			status(cfg ? cfg.i18n.failed : '');
			return Promise.reject(new Error('not ready'));
		}
		var total = amount(cfg);
		var intent = S.intentFor(cfg, total, wantsSave(cfg));
		return S.tokenize(state.card, S.details(cfg, total, intent, billing(cfg))).then(function (token) {
			field(cfg.fields.token).value = token;
			field(cfg.fields.method).value = 'card';
		}).catch(function (error) {
			status(error && error.message ? error.message : cfg.i18n.invalid);
			throw error;
		});
	}

	function needsToken() {
		var cfg = state.config;
		var tokenField = cfg ? field(cfg.fields.token) : null;
		return !!(tokenField && !tokenField.value && !usingSavedCard());
	}

	/* ---- EDD checkout ------------------------------------------------------------ */

	$(document.body).on('edd_gateway_loaded', function (event, gateway) {
		if (gateway === GATEWAY || root()) {
			mount();
		} else {
			destroy();
		}
	});

	$(document.body).on('edd_discount_applied edd_discount_removed edd_quantity_updated edd_taxes_recalculated edd_cart_item_removed', function () {
		window.setTimeout(function () {
			mount();
			syncWalletAmount();
		}, 0);
	});

	$(document.body).on('change', '#stackless-square-form input[type="radio"]', function () {
		toggleNewCard();
		var cfg = state.config;
		var tokenField = cfg ? field(cfg.fields.token) : null;
		if (tokenField) {
			tokenField.value = '';
		}
	});

	// A failed attempt needs a fresh token next time.
	$(document.body).on('edd_checkout_error', function () {
		var cfg = state.config;
		var tokenField = cfg ? field(cfg.fields.token) : null;
		if (tokenField) {
			tokenField.value = '';
		}
		if (cfg && field(cfg.fields.method)) {
			field(cfg.fields.method).value = 'card';
		}
	});

	// Capture phase, so the token exists before EDD's own Purchase handler validates and submits.
	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('#edd_purchase_form #edd_purchase_submit [type=submit]') : null;
		if (!button || state.passThrough || !selected() || !root() || !needsToken()) {
			return;
		}
		var form = purchaseForm();
		if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
			return; // Let the browser show which field is missing.
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		button.disabled = true;
		status('');
		tokenizeCard().then(function () {
			button.disabled = false;
			state.passThrough = true;
			button.click();
			state.passThrough = false;
		}).catch(function () {
			button.disabled = false;
		});
	}, true);

	/* ---- Account page: add a card --------------------------------------------------- */

	$(document).on('submit', 'form.stackless-square-add-method', function (event) {
		if (state.submitting) {
			return;
		}
		event.preventDefault();
		var formEl = this;
		$(formEl).addClass('stackless-square-processing');
		tokenizeCard().then(function () {
			state.submitting = true;
			HTMLFormElement.prototype.submit.call(formEl);
		}).catch(function () {
			$(formEl).removeClass('stackless-square-processing');
		});
	});

	// A single gateway (or the account form) renders the fields with the page, without edd_gateway_loaded.
	$(mount);
})(window.jQuery);
