/* Stackless for EDD: count outbound clicks on external download buttons. */
(function () {
	'use strict';
	var config = window.stacklessEddExternalProduct || {};
	if (!config.endpoint) {
		return;
	}
	function track(id) {
		var url = config.endpoint + encodeURIComponent(id);
		try {
			if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([], { type: 'text/plain' }))) {
				return;
			}
		} catch (e) {}
		if (window.fetch) {
			window.fetch(url, { method: 'POST', keepalive: true, credentials: 'omit' }).catch(function () {});
		}
	}
	function onClick(event) {
		var target = event.target;
		var link = target && target.closest ? target.closest('[data-stackless-external]') : null;
		if (!link) {
			return;
		}
		var id = parseInt(link.getAttribute('data-stackless-external'), 10);
		if (id > 0) {
			track(id);
		}
	}
	document.addEventListener('click', onClick, true);
	document.addEventListener('auxclick', function (event) {
		if (event.button === 1) {
			onClick(event);
		}
	}, true);
})();
