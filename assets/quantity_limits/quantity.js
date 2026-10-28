/* Stackless for EDD: quantity limits on purchase forms and in the checkout cart. */
(function ($) {
	'use strict';
	if (!$) {
		return;
	}

	function clamp(quantity, limits) {
		var step = Math.max(1, limits.step || 1);
		quantity = Math.max(limits.min || 1, quantity);
		if (quantity % step !== 0) {
			quantity = Math.ceil(quantity / step) * step;
		}
		if (limits.max > 0 && quantity > limits.max) {
			quantity = Math.max(limits.min || 1, Math.floor(limits.max / step) * step);
		}
		return quantity;
	}

	function applyAttributes(input, limits) {
		input.setAttribute('min', limits.min);
		if (limits.max > 0) {
			input.setAttribute('max', limits.max);
		} else {
			input.removeAttribute('max');
		}
		input.setAttribute('step', limits.step);
	}

	/* Variable downloads in single price mode: the one quantity box follows the chosen option. */
	$(document).on('change', 'form.edd_download_purchase_form input[type="radio"][name="edd_options[price_id][]"]', function () {
		var $form = $(this).closest('form');
		var $wrap = $form.find('.edd_download_quantity_wrapper[data-stackless-qty-limits]').first();
		if (!$wrap.length) {
			return;
		}
		var map = $wrap.data('stacklessQtyLimits') || {};
		var limits = map[String(this.value)];
		var input = $wrap.find('input.edd-item-quantity').get(0);
		if (!limits || !input) {
			return;
		}
		applyAttributes(input, limits);
		if (!input.checkValidity()) {
			input.value = limits.min;
		}
		var $note = $wrap.find('.stackless-qty-limits');
		if ($note.length) {
			var text = limits.note ? String(limits.note) : '';
			$note.text(text).prop('hidden', text === '');
		}
	});

	/* An active quantity box: visible, and for per-option boxes, its option is ticked. */
	function isActive(input, form, downloadId) {
		if (input.disabled || input.offsetParent === null) {
			return false;
		}
		var match = /^edd_download_quantity_(\d+)$/.exec(input.name || '');
		if (!match) {
			return true;
		}
		var option = form.querySelector('.edd_price_option_' + downloadId + '[value="' + match[1] + '"]');
		return !option || option.checked;
	}

	/* Runs before EDD's own add-to-cart handler (capture phase) and stops it for invalid quantities. */
	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('.edd-add-to-cart') : null;
		var form = button ? button.closest('form.edd_download_purchase_form') : null;
		if (!form) {
			return;
		}
		var downloadId = button.getAttribute('data-download-id') || '';
		var inputs = form.querySelectorAll('input.edd-item-quantity');
		for (var i = 0; i < inputs.length; i++) {
			if (isActive(inputs[i], form, downloadId) && typeof inputs[i].checkValidity === 'function' && !inputs[i].checkValidity()) {
				event.preventDefault();
				event.stopPropagation();
				if (typeof inputs[i].reportValidity === 'function') {
					inputs[i].reportValidity();
				}
				return;
			}
		}
		$(form).find('.stackless-qty-limits-error').remove();
	}, true);

	/* AJAX add to cart refused on the server: say why and put the buttons back. */
	$(document.body).on('edd_cart_item_added', function (event, response) {
		var data = response && response.stackless_quantity_limits;
		if (!data || !data.error) {
			return;
		}
		var $forms = $('.edd_download_purchase_form .edd-add-to-cart[data-download-id="' + parseInt(data.download_id, 10) + '"]').closest('form');
		$forms.each(function () {
			var $form = $(this);
			$form.find('.stackless-qty-limits-error').remove();
			$form.find('.edd_purchase_submit_wrapper').after($('<p class="stackless-qty-limits-error" role="alert"></p>').text(String(data.error)));
			$form.find('.edd-cart-added-alert').hide();
			if (!data.in_cart) {
				$form.find('.edd_go_to_checkout').hide();
				$form.find('.edd-add-to-cart.edd-has-js').show().removeAttr('data-edd-loading').prop('disabled', false);
				$form.find('.edd_download_quantity_wrapper').show();
			}
		});
	});

	/* Checkout cart: limits on each quantity box. Other lines of the same download count too. */
	function cartLimits(row) {
		var data = row ? row.querySelector('.stackless-qty-limits-data') : null;
		if (!data) {
			return null;
		}
		return {
			key: data.getAttribute('data-key'),
			min: parseInt(data.getAttribute('data-min'), 10) || 1,
			max: parseInt(data.getAttribute('data-max'), 10) || 0,
			step: parseInt(data.getAttribute('data-step'), 10) || 1,
			others: parseInt(data.getAttribute('data-others'), 10) || 0
		};
	}

	function applyCart() {
		var rows = document.querySelectorAll('#edd_checkout_cart .edd_cart_item');
		for (var i = 0; i < rows.length; i++) {
			var limits = cartLimits(rows[i]);
			var input = rows[i].querySelector('input.edd-item-quantity');
			if (!limits || !input) {
				continue;
			}
			applyAttributes(input, {
				min: Math.max(1, limits.min - limits.others),
				max: limits.max > 0 ? Math.max(1, limits.max - limits.others) : 0,
				step: limits.others === 0 ? limits.step : 1
			});
		}
	}

	/* Capture phase: the value is corrected before EDD's change handler sends it. */
	document.addEventListener('change', function (event) {
		var input = event.target;
		if (!input || !input.classList || !input.classList.contains('edd-item-quantity') || !input.closest) {
			return;
		}
		var limits = cartLimits(input.closest('.edd_cart_item'));
		if (!limits) {
			return;
		}
		var value = parseInt(input.value, 10) || 0;
		var corrected = Math.max(1, clamp(value + limits.others, limits) - limits.others);
		if (corrected !== value) {
			input.value = corrected;
		}
	}, true);

	$(document.body).on('edd_quantity_updated', function (event, response) {
		var data = response && response.stackless_quantity_limits;
		if (!response || typeof response !== 'object') {
			return;
		}
		$('#edd_checkout_cart .stackless-qty-limits-error').remove();
		if (!data) {
			return;
		}
		var $input = $('input[name="edd-cart-download-' + parseInt(data.key, 10) + '-quantity"]');
		if ($input.length) {
			$input.val(data.quantity);
			$input.closest('.edd_cart_item').find('.edd_cart_item_name').append($('<p class="stackless-qty-limits-error" role="alert"></p>').text(String(data.message || '')));
		}
	});

	$(document.body).on('edd_taxes_recalculated edd_cart_item_removed edd_discount_applied edd_discount_removed', applyCart);
	document.addEventListener('edd:cart-updated', applyCart);
	$(applyCart);
})(window.jQuery);
