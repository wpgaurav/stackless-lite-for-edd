/* Stackless for EDD: show the Service box only for service downloads. */
(function ($) {
	'use strict';
	$(function () {
		var $box = $('#_stackless_edd_service');
		var $panel = $('#stackless-edd-service');
		if (!$box.length || !$panel.length) {
			return;
		}
		function toggle() {
			$panel.toggle($box.is(':checked'));
		}
		$box.on('change', toggle);
		toggle();
	});
})(window.jQuery);
