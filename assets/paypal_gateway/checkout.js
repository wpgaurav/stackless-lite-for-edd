/**
 * Stackless for EDD: PayPal buttons in EDD's purchase form, and on the account page's
 * PayPal tab (save an account without a purchase).
 *
 * EDD loads the payment fields over AJAX when the buyer picks a gateway, then triggers
 * edd_gateway_loaded. After PayPal approves, the order id (or vault setup token) goes into a
 * hidden field and EDD's Purchase button is clicked: EDD validates the form and creates the
 * order, and the server captures and checks the amount. Without JavaScript, Purchase falls
 * back to a PayPal redirect. On the account page the approved setup token is posted with the
 * tab's own form.
 *
 * Since 2.0.0 the form can also show PayPal's card fields (or Fastlane for US guests) and
 * Apple Pay / Google Pay buttons. With card fields filled in, Purchase first runs the card
 * payment (3-D Secure included) and then submits with the approved PayPal order.
 */
jQuery(function ($) {
	'use strict';

	var data = window.stacklessEddPayPalData;
	if (!data || !window.stacklessEddPayPal) {
		return;
	}

	var approval = null; // { type: 'order'|'setup'|'fastlane', id: string, mode: string, currency: string, funding: string }
	var extras = { card: null, fastlane: null, busy: false, passThrough: false }; // card fields / Fastlane controllers for the rendered form

	function box() {
		return document.getElementById('stackless-paypal-buttons');
	}

	function isAccount() {
		var el = box();
		return !!el && el.getAttribute('data-context') === 'add-payment-method';
	}

	function form() {
		return document.getElementById(isAccount() ? 'stackless-paypal-add-method' : 'edd_purchase_form');
	}

	function purchaseButton() {
		return $('#edd_purchase_submit').find('[type="submit"]');
	}

	function status(message) {
		$('.stackless-paypal__status').text(message || '');
	}

	function writeHidden() {
		$('input[name="' + data.fields.order + '"]').val(approval && approval.type === 'order' ? approval.id : '');
		$('input[name="' + data.fields.setup + '"]').val(approval && approval.type === 'setup' ? approval.id : '');
		$('input[name="' + data.fields.fastlane + '"]').val(approval && approval.type === 'fastlane' ? approval.id : '');
		$('input[name="' + data.fields.funding + '"]').val(approval && approval.funding ? approval.funding : '');
	}

	function cardReady() {
		return !!(extras.card || extras.fastlane);
	}

	/** Billing address for card verification, from EDD's address fields when the form has them. */
	function billingAddress() {
		var get = function (name) {
			var $field = $('#edd_purchase_form [name="' + name + '"]');
			return $field.length ? String($field.val() || '') : '';
		};
		if (!$('#edd_purchase_form [name="billing_country"]').length) {
			return undefined;
		}
		return {
			addressLine1: get('card_address'),
			addressLine2: get('card_address_2'),
			adminArea2: get('card_city'),
			adminArea1: get('card_state'),
			postalCode: get('card_zip'),
			countryCode: get('billing_country')
		};
	}

	function emailValue() {
		return String($('#edd_purchase_form [name="edd_email"]').val() || '');
	}

	/** Card fields (or Fastlane for guests) and Apple Pay / Google Pay, once per rendered form. */
	function renderExtras(d, key) {
		var cardBox = document.getElementById('stackless-paypal-card');
		var fieldsEl = document.getElementById('stackless-paypal-card-fields');
		var walletsEl = document.getElementById('stackless-paypal-wallets');
		if (d.mode !== 'order' || isAccount()) {
			extras.card = null;
			extras.fastlane = null;
			if (cardBox) {
				cardBox.hidden = true;
			}
			return;
		}
		var common = {
			currency: d.currency,
			intent: d.intent,
			vault: d.vault === '1',
			context: d.context
		};
		if (fieldsEl && fieldsEl.getAttribute('data-rendered') !== key) {
			fieldsEl.setAttribute('data-rendered', key);
			extras.card = null;
			extras.fastlane = null;
			var start = data.fastlane ? window.stacklessEddPayPal.fastlane(data, Object.assign({ el: fieldsEl }, common)) : Promise.resolve(null);
			start.then(function (fast) {
				if (fast) {
					extras.fastlane = fast;
					fast.lookup(emailValue());
					return true;
				}
				return window.stacklessEddPayPal.cardFields(data, Object.assign({ el: fieldsEl }, common)).then(function (card) {
					extras.card = card;
					return !!card;
				});
			}).then(function (shown) {
				if (cardBox) {
					cardBox.hidden = !shown;
				}
				toggle();
			}).catch(function () {
				if (cardBox) {
					cardBox.hidden = true;
				}
				toggle();
			});
		}
		var amount = cartTotal(parseFloat(d.total) || 0);
		var walletKey = key + '|' + amount;
		if (walletsEl && walletsEl.getAttribute('data-rendered') !== walletKey) {
			walletsEl.setAttribute('data-rendered', walletKey);
			window.stacklessEddPayPal.wallets(data, Object.assign({}, common, {
				el: walletsEl,
				amount: amount,
				// Apple Pay and Google Pay take one-time payments only.
				vault: d.vault === '1' || d.wallets !== '1',
				onApproved: function (orderId, funding) {
					approval = { type: 'order', id: orderId, mode: d.mode, currency: d.currency, funding: funding };
					writeHidden();
					status(data.i18n.approved);
					toggle();
					submitPurchase();
				},
				onError: function (message) {
					status(message);
				}
			})).catch(function () {
				walletsEl.innerHTML = '';
			});
		}
	}

	/** Click EDD's Purchase button past our own interceptor: EDD validates and submits. */
	function submitPurchase() {
		var button = purchaseButton().get(0);
		if (!button) {
			return;
		}
		extras.passThrough = true;
		button.click();
		extras.passThrough = false;
	}

	/**
	 * Purchase with the card fields (or Fastlane) filled in: run the card payment first, then
	 * submit the form with the approved PayPal order. Returns false to hold EDD's submit.
	 */
	function beforeSubmit() {
		if (extras.passThrough || !box() || isAccount() || approval || !cardReady()) {
			return true;
		}
		if (extras.busy) {
			return false;
		}
		var f = form();
		if (f && typeof f.checkValidity === 'function' && !f.checkValidity()) {
			return true; // Let the browser show which field is missing.
		}
		extras.busy = true;
		status('');
		var d = box().dataset;
		var pay = extras.fastlane
			? extras.fastlane.token(billingAddress()).then(function (id) {
				approval = { type: 'fastlane', id: id, mode: d.mode, currency: d.currency, funding: 'fastlane' };
			})
			: extras.card.submit(billingAddress()).then(function (id) {
				approval = { type: 'order', id: id, mode: d.mode, currency: d.currency, funding: 'card' };
			});
		pay.then(function () {
			extras.busy = false;
			writeHidden();
			status(data.i18n.approved);
			submitPurchase();
		}).catch(function (error) {
			extras.busy = false;
			approval = null;
			writeHidden();
			status(error && error.message && error.message.length < 200 ? error.message : data.i18n.cardIncomplete);
		});
		return false;
	}

	function toggle() {
		if (isAccount()) {
			return;
		}
		var el = box();
		var ready = el && el.getAttribute('data-ready') === '1';
		if (el && ready && !approval && !cardReady()) {
			purchaseButton().hide();
		} else {
			purchaseButton().show();
		}
	}

	/** Current cart total from EDD's cart table (it updates over AJAX), else the fallback. */
	function cartTotal(fallback) {
		var $amount = $('.edd_cart_total .edd_cart_amount').first();
		var total = $amount.length ? parseFloat($amount.attr('data-total')) : NaN;
		return isNaN(total) ? fallback : total;
	}

	function render() {
		var el = box();
		if (!el) {
			approval = null;
			return;
		}
		var d = el.dataset;
		var key = [d.context, d.mode, d.vault, d.intent, d.currency].join('|');

		// A saved approval only survives refreshes that keep the same flow and currency.
		if (approval && (approval.mode !== d.mode || approval.currency !== d.currency)) {
			approval = null;
		}
		writeHidden();

		if (el.getAttribute('data-rendered') === key) {
			toggle();
			return;
		}
		el.setAttribute('data-rendered', key);
		el.removeAttribute('data-ready');

		if ((data.currencies || []).indexOf(String(d.currency).toUpperCase()) === -1) {
			status(data.i18n.unavailable);
			toggle();
			return;
		}
		status(approval ? data.i18n.approved : (d.mode === 'setup' ? data.i18n.saving : ''));

		renderExtras(d, key);

		window.stacklessEddPayPal.render(data, el, {
			mode: d.mode,
			vault: d.vault === '1',
			intent: d.intent,
			currency: d.currency,
			context: d.context,
			amount: cartTotal(parseFloat(d.total) || 0),
			messagesEl: document.getElementById('stackless-paypal-messages'),
			beforeClick: function () {
				var f = form();
				return !(f && typeof f.reportValidity === 'function' && !f.reportValidity());
			},
			onApproved: function (type, id, funding) {
				approval = { type: type, id: id, mode: d.mode, currency: d.currency, funding: type === 'order' ? (funding || 'paypal') : '' };
				writeHidden();
				if (isAccount()) {
					status(data.i18n.savingAccount);
					form().submit();
					return;
				}
				status(data.i18n.approved);
				toggle();
				submitPurchase();
			},
			onError: function (message) {
				status(message);
			},
			onCancel: function () {
				status(data.i18n.cancelled);
			}
		}).then(function (buttons) {
			if (buttons) {
				el.setAttribute('data-ready', '1');
			}
			toggle();
		}).catch(function () {
			el.removeAttribute('data-rendered');
			status(data.i18n.error);
			toggle();
		});
	}

	/** The cart changed over AJAX (discount, quantity, removed item). */
	function cartChanged() {
		var el = box();
		if (!el) {
			return;
		}
		var total = cartTotal(NaN);
		if (isNaN(total)) {
			return;
		}
		// Moving between "nothing to pay today" and a payment changes the PayPal flow.
		if (el.dataset.vault === '1' && (total <= 0) !== (el.dataset.mode === 'setup')) {
			window.location.reload();
			return;
		}
		// Re-render so Pay Later offers and wallet totals follow the new total; an approval is
		// re-checked on the server.
		if (!approval) {
			el.removeAttribute('data-rendered');
			render();
		}
	}

	// Capture phase, so the card payment runs before EDD's own Purchase handler validates and submits.
	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('#edd_purchase_form #edd_purchase_submit [type=submit]') : null;
		if (!button || beforeSubmit() !== false) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
	}, true);

	$(document.body).on('change', '#edd_purchase_form [name="edd_email"]', function () {
		if (extras.fastlane) {
			extras.fastlane.lookup($(this).val());
		}
	});
	$(document.body).on('edd_checkout_error', function () {
		// A declined or expired card approval can't be reused; the buyer pays again.
		if (approval && (approval.funding === 'card' || approval.type === 'fastlane')) {
			approval = null;
			writeHidden();
		}
	});

	$(document.body).on('edd_gateway_loaded', function (event, gateway) {
		if (gateway === data.gatewayId || box()) {
			render();
		} else {
			approval = null;
		}
	});
	$(document.body).on('edd_cart_item_removed edd_quantity_updated edd_discount_applied edd_discount_removed', cartChanged);
	$(document.body).on('edd_checkout_error', toggle);

	render();
});
