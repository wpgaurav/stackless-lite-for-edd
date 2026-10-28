/* Stackless for EDD: per-price-option purchase button text on download pages. */
(function ($) {
	'use strict';
	if (!$) {
		return;
	}
	function apply($form) {
		var $data = $form.find('.stackless-cart-text-data').first();
		if (!$data.length) {
			return;
		}
		var labels = $data.data('labels') || {};
		var fallback = $data.attr('data-default') || '';
		var id = String($data.data('download'));
		var $checked = $form.find('.edd_price_option_' + id + ':checked').first();
		var key = $checked.length ? String($checked.val()) : '';
		var text = key !== '' && typeof labels[key] === 'string' && labels[key] !== '' ? labels[key] : fallback;
		if (text === '') {
			return;
		}
		$form.find('.edd-add-to-cart-label').text(text);
		$form.find('input.edd-add-to-cart.edd-no-js').val(text);
	}
	$(document).on('change', '.edd_download_purchase_form .edd_price_options input', function () {
		apply($(this).closest('form'));
	});
	$(function () {
		$('.edd_download_purchase_form').each(function () {
			apply($(this));
		});
	});
})(window.jQuery);
