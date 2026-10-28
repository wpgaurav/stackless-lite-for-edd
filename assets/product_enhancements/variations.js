/* Stackless for EDD: switch detail values with the selected variable price option. */
(function ($) {
	'use strict';
	if (!$) {
		return;
	}
	function apply($table, values) {
		$table.find('[data-stackless-detail]').each(function () {
			var $cell = $(this);
			var key = String($cell.data('stackless-detail'));
			var text = values && Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : String($cell.attr('data-stackless-default') || '');
			$cell.text(text);
			$cell.closest('tr').prop('hidden', text === '');
		});
	}
	function sync(downloadId) {
		var $tables = $('[data-stackless-product-details="' + downloadId + '"]');
		if (!$tables.length) {
			return;
		}
		var prices = $tables.first().data('stackless-prices') || {};
		// One option picked (radio, or a single checkbox in multi-purchase mode) shows its values.
		var $checked = $('input.edd_price_option_' + downloadId + ':checked');
		var values = null;
		if ($checked.length === 1) {
			var priceId = String($checked.val());
			values = Object.prototype.hasOwnProperty.call(prices, priceId) ? prices[priceId] : null;
		}
		$tables.each(function () {
			apply($(this), values);
		});
	}
	$(document).on('change', 'input[name="edd_options[price_id][]"]', function () {
		var match = /(?:^|\s)edd_price_option_(\d+)(?:\s|$)/.exec(this.className || '');
		if (match) {
			sync(match[1]);
		}
	});
	$(function () {
		$('[data-stackless-product-details]').each(function () {
			sync(String($(this).data('stackless-product-details')));
		});
	});
})(window.jQuery);
