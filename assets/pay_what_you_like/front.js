/* Stackless for EDD: Pay What You Like on purchase forms and the checkout. No build step. */
jQuery(function ($) {
	'use strict';
	var cfg = window.stacklessEddPayWhatYouLike || {};
	var i18n = cfg.i18n || {};
	var decimalSep = cfg.decimalSep || '.';
	var timer = null;
	var $body = $(document.body);

	// Same reading rules as the server (Amounts::parse): "12.50", "12,50", "1,234.50", "1.234,50".
	function toMinor(raw, decimals) {
		var value = String(raw == null ? '' : raw).replace(/[\s  ']+/g, '');
		if (value === '' || value.indexOf('-') !== -1) return null;
		value = value.replace(/^[^\d.,]+|[^\d.,]+$/g, '');
		if (value === '' || !/^[\d.,]+$/.test(value)) return null;
		var intPart = value, frac = '';
		var pos = Math.max(value.lastIndexOf('.'), value.lastIndexOf(','));
		if (pos >= 0) {
			var sep = value.charAt(pos), after = value.slice(pos + 1);
			var single = value.split(sep).length === 2;
			var isDecimal = sep === decimalSep ? single && !(decimals === 0 && after.length === 3) : single && after.length !== 3;
			if (isDecimal) { intPart = value.slice(0, pos); frac = after; }
		}
		if (/[.,]/.test(intPart) && !/^\d{1,3}([.,])\d{3}(\1\d{3})*$/.test(intPart)) return null;
		intPart = intPart.replace(/[.,]/g, '') || '0';
		if (!/^\d+$/.test(intPart) || (frac !== '' && !/^\d+$/.test(frac))) return null;
		if (frac.length > decimals) {
			if (/[1-9]/.test(frac.slice(decimals))) return null;
			frac = frac.slice(0, decimals);
		}
		if (intPart.replace(/^0+/, '').length > 12) return null;
		while (frac.length < decimals) frac += '0';
		return parseInt(intPart, 10) * Math.pow(10, decimals) + (decimals ? parseInt(frac, 10) : 0);
	}

	function fieldOf(node) { return $(node).closest('[data-stackless-pwyl]'); }

	function setStatus($field, message) {
		$field.find('[data-stackless-pwyl-status]').text(message || '').toggleClass('is-error', !!message);
		$field.find('[data-stackless-pwyl-amount]').attr('aria-invalid', message ? 'true' : 'false');
	}

	function check($field) {
		if (!$field.length || $field.prop('hidden')) return '';
		var decimals = parseInt($field.attr('data-decimals'), 10) || 0;
		var $input = $field.find('[data-stackless-pwyl-amount]');
		var amount = toMinor($input.val(), decimals);
		var min = toMinor($field.attr('data-min'), decimals);
		var max = toMinor($field.attr('data-max'), decimals);
		var message = '';
		if (amount === null) message = i18n.invalid || '';
		else if (min !== null && amount < min) message = (i18n.tooLow || '%s').replace('%s', $field.attr('data-min-label') || $field.attr('data-min'));
		else if (max !== null && amount > max) message = (i18n.tooHigh || '%s').replace('%s', $field.attr('data-max-label') || $field.attr('data-max'));
		$field.find('[data-stackless-pwyl-preset]').each(function () {
			this.setAttribute('aria-pressed', toMinor(this.getAttribute('data-stackless-pwyl-preset'), decimals) === amount ? 'true' : 'false');
		});
		setStatus($field, message);
		return message;
	}

	function firstInvalid($scope) {
		var bad = null;
		$scope.find('[data-stackless-pwyl]').each(function () {
			if (!bad && check($(this))) bad = $(this).find('[data-stackless-pwyl-amount]').get(0);
		});
		return bad;
	}

	/* ---------------------------------------------------------------- Checkout box */

	function updateTotals(response) {
		$('.edd_cart_subtotal_amount').each(function () { $(this).text(response.subtotal); });
		$('.edd_cart_tax_amount').each(function () {
			$(this).text(response.taxes).data('tax', response.taxes_raw).attr('data-tax', response.taxes_raw);
		});
		if (response.discounts) $('.edd_cart_discount').html(response.discounts);
		$('.edd_cart_amount').each(function () {
			$(this).text(response.total)
				.data('subtotal', response.subtotal_raw).attr('data-subtotal', response.subtotal_raw)
				.data('total', response.total_raw).attr('data-total', response.total_raw);
		});
		if (response.line_price) {
			$('[data-stackless-pwyl-line="' + response.line + '"]').replaceWith(response.line_price);
		}
		if (response.complete_purchase) $('#edd-purchase-button').val(response.complete_purchase);
		$body.trigger('edd_quantity_updated', [response]);
		$body.trigger('stackless_edd_pwyl_updated', [response]);
	}

	function save($field) {
		var key = $field.attr('data-key');
		if (key === undefined || !cfg.ajaxUrl) return;
		var before = parseFloat($('.edd_cart_amount').first().attr('data-total'));
		$field.addClass('is-busy');
		$.ajax({
			type: 'POST',
			url: cfg.ajaxUrl,
			dataType: 'json',
			xhrFields: { withCredentials: true },
			data: { action: cfg.action, nonce: cfg.nonce, key: key, amount: $field.find('[data-stackless-pwyl-amount]').val() }
		}).done(function (json) {
			if (!json || !json.success) {
				setStatus($field, (json && json.data && json.data.message) || i18n.failed || '');
				return;
			}
			var response = json.data || {};
			var after = parseFloat(response.total_raw);
			// Free and paid checkouts show different payment forms: reload when the total crosses zero.
			if (!isNaN(before) && !isNaN(after) && (before > 0) !== (after > 0)) {
				window.location.reload();
				return;
			}
			if (!$('.edd_cart_amount').length) {
				window.location.reload();
				return;
			}
			updateTotals(response);
		}).fail(function (xhr) {
			var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
			setStatus($field, (data && data.message) || i18n.failed || '');
		}).always(function () {
			$field.removeClass('is-busy');
		});
	}

	function commit($field, now) {
		clearTimeout(timer);
		if (check($field)) return;
		if ($field.attr('data-context') !== 'checkout') return;
		timer = setTimeout(function () { save($field); }, now ? 0 : 700);
	}

	$body.on('input', '[data-stackless-pwyl-amount]', function () {
		var $field = fieldOf(this);
		if ($field.attr('data-context') === 'checkout') commit($field, false);
		else check($field);
	});
	$body.on('change', '[data-stackless-pwyl-amount]', function () {
		var $field = fieldOf(this);
		if ($field.attr('data-context') === 'checkout') commit($field, true);
		else check($field);
	});
	// Enter updates the total instead of submitting the box's fallback form.
	$body.on('keydown', '[data-stackless-pwyl-amount]', function (event) {
		var $field = fieldOf(this);
		if (event.key !== 'Enter' || $field.attr('data-context') !== 'checkout') return;
		event.preventDefault();
		commit($field, true);
	});
	$body.on('submit', '[data-stackless-pwyl-form]', function (event) {
		event.preventDefault();
		$(this).find('[data-stackless-pwyl]').each(function () { commit($(this), true); });
	});
	$body.on('click', '[data-stackless-pwyl-preset]', function (event) {
		event.preventDefault();
		var $field = fieldOf(this);
		$field.find('[data-stackless-pwyl-amount]').val(this.getAttribute('data-stackless-pwyl-preset'));
		if ($field.attr('data-context') === 'checkout') commit($field, true);
		else check($field);
	});

	/* ---------------------------------------------------------------- Purchase forms */

	// Runs before EDD's add-to-cart handler (capture phase): no add while an amount is out of range.
	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('.edd-add-to-cart') : null;
		var form = button ? button.closest('form') : null;
		if (!form || !form.querySelector('[data-stackless-pwyl]')) return;
		var bad = firstInvalid($(form));
		if (!bad) return;
		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();
		bad.focus();
	}, true);
	$body.on('submit', 'form.edd_download_purchase_form', function (event) {
		var bad = firstInvalid($(this));
		if (bad) { event.preventDefault(); event.stopImmediatePropagation(); bad.focus(); }
	});

	// A refused AJAX add: show the server's message under the amount and give the button back.
	$(document).ajaxError(function (event, xhr, settings) {
		var data = settings && typeof settings.data === 'string' ? new URLSearchParams(settings.data) : null;
		var message = xhr && xhr.responseJSON && xhr.responseJSON.stackless_edd_pwyl_error;
		if (!data || data.get('action') !== 'edd_add_to_cart' || !message) return;
		var $button = $('.edd-add-to-cart[data-download-id="' + data.get('download_id') + '"][data-edd-loading]');
		var $form = $button.closest('form');
		$button.removeAttr('data-edd-loading').prop('disabled', false);
		setStatus($form.find('[data-stackless-pwyl]').first(), message);
	});

	// Variable downloads with radio options: each price brings its own limits (or none for a fixed price).
	function applyOption($field, priceId) {
		var prices = {};
		try { prices = JSON.parse($field.attr('data-prices') || '{}') || {}; } catch (e) { prices = {}; }
		var data = prices[String(priceId)];
		var $input = $field.find('[data-stackless-pwyl-amount]');
		if (!data) { $field.prop('hidden', true); $input.prop('disabled', true); return; }
		$field.attr({ 'data-min': data.min, 'data-max': data.max, 'data-decimals': data.decimals, 'data-min-label': data.minLabel, 'data-max-label': data.maxLabel });
		$input.prop('disabled', false).val(data.value);
		$field.find('[data-stackless-pwyl-help]').text(data.help || '');
		var $presets = $field.find('[data-stackless-pwyl-presets]').empty();
		(data.presets || []).forEach(function (preset) {
			$('<button type="button" class="stackless-pwyl__preset"></button>')
				.attr({ 'data-stackless-pwyl-preset': preset.value, 'aria-pressed': preset.pressed ? 'true' : 'false' })
				.text(preset.label)
				.appendTo($presets);
		});
		$presets.prop('hidden', !(data.presets || []).length);
		$field.prop('hidden', false);
		setStatus($field, '');
	}

	$('[data-stackless-pwyl][data-variable]').each(function () {
		var $field = $(this);
		var download = $field.attr('data-download');
		var $form = $field.closest('form');
		var $checked = $form.find('.edd_price_option_' + download + ':checked');
		applyOption($field, $checked.length ? $checked.val() : '');
	});
	$body.on('change', '.edd_download_purchase_form input[type="radio"][name="edd_options[price_id][]"]', function () {
		var $field = $(this).closest('form').find('[data-stackless-pwyl][data-variable]');
		if ($field.length) applyOption($field, $(this).val());
	});
});
