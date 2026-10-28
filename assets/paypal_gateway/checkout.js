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
 */
jQuery(function ($) {
	'use strict';

	var data = window.stacklessEddPayPalData;
	if (!data || !window.stacklessEddPayPal) {
		return;
	}

	var approval = null; // { type: 'order'|'setup', id: string, mode: string, currency: string }

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
	}

	function toggle() {
		if (isAccount()) {
			return;
		}
		var el = box();
		var ready = el && el.getAttribute('data-ready') === '1';
		if (el && ready && !approval) {
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
			onApproved: function (type, id) {
				approval = { type: type, id: id, mode: d.mode, currency: d.currency };
				writeHidden();
				if (isAccount()) {
					status(data.i18n.savingAccount);
					form().submit();
					return;
				}
				status(data.i18n.approved);
				toggle();
				purchaseButton().first().trigger('click');
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
		// Re-render so Pay Later offers follow the new total; an approval is re-checked on the server.
		if (!approval) {
			el.removeAttribute('data-rendered');
			render();
		}
	}

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
