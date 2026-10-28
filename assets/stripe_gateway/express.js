/**
 * Stackless for EDD: Stripe Express Checkout Element (Apple Pay, Google Pay, Link).
 *
 * Auto-mounted on .stackless-stripe-express on download pages (under the purchase button) and
 * above the checkout. The wallet's billing address is synced into the EDD cart for taxes, and
 * the order is placed through EDD's own purchase processing with a ConfirmationToken.
 */
(function (window, document) {
	'use strict';

	var S = window.stacklessEddStripe;
	if (!S) {
		return;
	}
	var cfg = S.config;
	var actions = cfg.expressActions || {};

	function productForm(el) {
		return el.closest('form.edd_download_purchase_form') || document.querySelector('form.edd_download_purchase_form');
	}

	/** Download, chosen price options and quantity from the EDD purchase form. */
	function productSelection(el, config) {
		var form = productForm(el);
		var selection = { product_id: config.productId, quantity: 1, price_ids: [], estimate: 0 };
		if (!form) {
			return selection;
		}
		var qty = form.querySelector('input[name="edd_download_quantity"]');
		selection.quantity = Math.max(1, parseInt(qty && qty.value, 10) || 1);
		var options = form.querySelectorAll('input[name="edd_options[price_id][]"]');
		Array.prototype.forEach.call(options, function (input) {
			if (input.type === 'hidden' || input.checked) {
				selection.price_ids.push(parseInt(input.value, 10) || 0);
				var price = parseFloat(input.getAttribute('data-price'));
				if (!isNaN(price)) {
					selection.estimate += price;
				}
			}
		});
		return selection;
	}

	function selectionKey(selection) {
		return JSON.stringify([selection.product_id, selection.price_ids, selection.quantity]);
	}

	/**
	 * @param {HTMLElement} el      Mount point.
	 * @param {Object}      config  { context, amount, currency, requiresSave, capture, productId, productType }
	 */
	function mount(el, config) {
		var stripe = S.stripe();
		if (!stripe || !el || el.getAttribute('data-stackless-mounted') === '1') {
			return null;
		}
		el.setAttribute('data-stackless-mounted', '1');

		var isProduct = config.context === 'product';
		var unitAmount = parseInt(config.amount, 10) || 0;
		var state = {
			amount: Math.max(unitAmount, 1),
			prepared: !isProduct,
			lineItems: [],
			known: {}
		};
		var wrap = el.closest('.stackless-stripe-express-wrap');
		var errorBox = wrap ? wrap.querySelector('.stackless-stripe-express-errors') : null;

		function showError(message) {
			if (errorBox) {
				errorBox.textContent = message || '';
			}
		}

		var elementsOptions = {
			mode: 'payment',
			amount: state.amount,
			currency: config.currency,
			appearance: S.appearance(el)
		};
		if (config.requiresSave) {
			elementsOptions.setupFutureUsage = 'off_session';
		}
		if (config.capture === 'manual' && !config.requiresSave) {
			elementsOptions.captureMethod = 'manual';
		}
		var elements = stripe.elements(elementsOptions);
		var buttonType = cfg.buttonType || 'buy';
		var element = elements.create('expressCheckout', {
			buttonType: { applePay: buttonType, googlePay: buttonType },
			buttonHeight: 44,
			paymentMethods: { applePay: 'auto', googlePay: 'auto', link: 'auto', amazonPay: 'never', paypal: 'never', klarna: 'never' },
			layout: { maxColumns: 3, maxRows: 1, overflow: 'auto' }
		});

		function setAmount(amount) {
			amount = parseInt(amount, 10) || 0;
			if (amount > 0 && amount !== state.amount) {
				state.amount = amount;
				elements.update({ amount: amount });
			}
		}

		function payload(extra) {
			var data = { nonce: cfg.expressNonce, context: config.context };
			if (isProduct) {
				var selection = productSelection(el, config);
				data.product_id = selection.product_id;
				data.quantity = selection.quantity;
				data.price_ids = selection.price_ids;
				if (!state.prepared) {
					data.prepare = 1;
				}
			}
			Object.keys(extra || {}).forEach(function (key) {
				data[key] = extra[key];
			});
			return data;
		}

		function update(extra) {
			return S.post(actions.update, payload(extra)).then(function (response) {
				if (!response || !response.success) {
					throw new Error((response && response.data && response.data.message) || S.i18n('genericError'));
				}
				state.prepared = true;
				var data = response.data;
				state.lineItems = data.lineItems || [];
				if (isProduct) {
					state.known[selectionKey(productSelection(el, config))] = data.amount;
				}
				setAmount(data.amount);
				return data;
			});
		}

		function estimateProductAmount() {
			var selection = productSelection(el, config);
			var known = state.known[selectionKey(selection)];
			var estimate = selection.estimate > 0 ? S.toStripeAmount(selection.estimate * selection.quantity, config.currency) : unitAmount * selection.quantity;
			setAmount(known || estimate);
		}

		element.on('ready', function (event) {
			var available = event && event.availablePaymentMethods;
			var any = !!available && Object.keys(available).some(function (key) {
				return available[key];
			});
			if (wrap) {
				wrap.hidden = !any;
			}
		});

		element.on('click', function (event) {
			showError('');
			if (isProduct) {
				var selection = productSelection(el, config);
				if (config.productType !== 'simple' && !selection.price_ids.length) {
					showError(S.i18n('chooseOptions'));
					return;
				}
				estimateProductAmount();
			}
			var options = {
				emailRequired: true,
				phoneNumberRequired: !!cfg.requirePhone,
				billingAddressRequired: true,
				business: { name: cfg.storeName || '' }
			};
			if (state.lineItems.length && !isProduct) {
				options.lineItems = state.lineItems;
			}
			event.resolve(options);
		});

		element.on('cancel', function () {
			if (isProduct && state.prepared) {
				state.prepared = false;
				S.post(actions.cancel, { nonce: cfg.expressNonce });
			}
		});

		element.on('confirm', function (event) {
			var sheetAmount = state.amount;
			var billing = event.billingDetails || {};
			var fail = function (error) {
				event.paymentFailed({ reason: 'fail' });
				showError(S.errorMessage(error));
			};

			elements.submit().then(function (result) {
				if (result && result.error) {
					throw result.error;
				}
				// Final server total with the wallet's billing address (taxes): it must equal what the wallet showed.
				return update({ address: billing.address || {} });
			}).then(function (data) {
				if (data.amount !== sheetAmount) {
					throw new Error(S.i18n('totalChanged'));
				}
				return stripe.createConfirmationToken({ elements: elements, params: { payment_method_data: { billing_details: billing } } });
			}).then(function (result) {
				if (result.error) {
					throw result.error;
				}
				return S.post(actions.checkout, payload({
					confirmation_token: result.confirmationToken.id,
					billing: { name: billing.name || '', email: billing.email || '', phone: billing.phone || '', address: billing.address || {} }
				}));
			}).then(function (response) {
				if (!response || response.result !== 'success' || !response.redirect) {
					var div = document.createElement('div');
					div.innerHTML = (response && (response.message || response.messages)) || '';
					throw new Error((div.textContent || '').trim() || S.i18n('genericError'));
				}
				var hashAt = response.redirect.indexOf('#');
				var action = hashAt !== -1 ? S.parseConfirmHash(response.redirect.slice(hashAt)) : null;
				if (!action) {
					window.location.href = response.redirect;
					return null;
				}
				return S.confirmAndVerify(action).then(function (verified) {
					window.location.href = verified.redirect;
				});
			}).catch(fail);
		});

		element.mount(el);

		var form = isProduct ? productForm(el) : null;
		if (form) {
			form.addEventListener('change', estimateProductAmount);
		}

		return {
			element: element,
			elements: elements,
			setAmount: setAmount,
			refresh: function () {
				return update({}).catch(function () {});
			},
			destroy: function () {
				element.destroy();
				el.removeAttribute('data-stackless-mounted');
			}
		};
	}

	function mountAll() {
		Array.prototype.forEach.call(document.querySelectorAll('.stackless-stripe-express[data-config]'), function (el) {
			if (el.getAttribute('data-stackless-mounted') === '1') {
				return;
			}
			var config;
			try {
				config = JSON.parse(el.getAttribute('data-config'));
			} catch (e) {
				return;
			}
			var handle = mount(el, config);
			if (handle && config.context === 'checkout' && window.jQuery) {
				// The cart total changes with discounts, quantities, removed items and taxes.
				window.jQuery(document.body).on('edd_discount_applied edd_discount_removed edd_quantity_updated edd_cart_item_removed edd_taxes_recalculated', handle.refresh);
			}
		});
	}

	window.stacklessEddStripeExpress = { mount: mount, mountAll: mountAll };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mountAll);
	} else {
		mountAll();
	}
})(window, document);
