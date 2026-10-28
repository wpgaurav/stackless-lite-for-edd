/* Stackless for EDD: wishlist buttons and list actions. No build step. */
(function () {
	'use strict';

	var config = window.stacklessEddWishlist || {};
	var i18n = config.i18n || {};
	var keys = [];
	var nonce = '';

	function post(data) {
		var body = new window.FormData();
		body.append('action', 'stackless_edd_wishlist');
		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});
		return window.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				return response.json().then(function (json) {
					return { ok: response.ok && json && json.success, data: (json && json.data) || {} };
				});
			});
	}

	function setNonce(value) {
		if (!value) {
			return;
		}
		nonce = value;
		document.querySelectorAll('input[name="_stackless_edd_wishlist_nonce"]').forEach(function (input) {
			input.value = value;
		});
	}

	function field(form, name) {
		var input = form.querySelector('input[name="' + name + '"]');
		return input ? input.value || '0' : '0';
	}

	function keyFor(form) {
		return field(form, 'download_id') + ':' + field(form, 'price_id');
	}

	function paint(form) {
		var saved = keys.indexOf(keyFor(form)) !== -1;
		var button = form.querySelector('.stackless-wishlist-toggle');
		var label = form.querySelector('.stackless-wishlist-label');
		if (!button) {
			return;
		}
		button.classList.toggle('is-saved', saved);
		button.setAttribute('aria-pressed', saved ? 'true' : 'false');
		if (label) {
			label.textContent = saved ? i18n.remove : i18n.add;
		}
	}

	function paintAll() {
		document.querySelectorAll('[data-stackless-wishlist-button]').forEach(paint);
	}

	function announce(form, message) {
		var status = form.querySelector('[role="status"]') || document.querySelector('.stackless-wishlist-status');
		if (status) {
			status.textContent = message || '';
		}
	}

	function onToggle(event) {
		var form = event.target.closest('[data-stackless-wishlist-button]');
		if (!form) {
			return;
		}
		event.preventDefault();
		var button = form.querySelector('button');
		button.disabled = true;
		post({
			op: 'toggle',
			nonce: nonce || field(form, '_stackless_edd_wishlist_nonce'),
			download_id: field(form, 'download_id'),
			price_id: field(form, 'price_id')
		}).then(function (result) {
			if (result.data.keys) {
				keys = result.data.keys;
			}
			setNonce(result.data.nonce);
			paintAll();
			announce(form, result.data.message || (result.ok ? '' : i18n.error));
		}).catch(function () {
			announce(form, i18n.error);
		}).then(function () {
			button.disabled = false;
		});
	}

	function onListAction(event) {
		var form = event.target.closest('[data-stackless-wishlist-action]');
		if (!form) {
			return;
		}
		event.preventDefault();
		var op = form.getAttribute('data-stackless-wishlist-action');
		var item = form.closest('.stackless-wishlist-item');
		var list = form.closest('[data-stackless-wishlist-list]');
		var status = list ? list.querySelector('.stackless-wishlist-status') : null;
		form.querySelector('button').disabled = true;
		post({
			op: op,
			nonce: nonce || field(form, '_stackless_edd_wishlist_nonce'),
			download_id: field(form, 'download_id'),
			price_id: field(form, 'price_id')
		}).then(function (result) {
			if (status) {
				status.textContent = result.data.message || '';
			}
			if (result.data.redirect) {
				window.location.href = result.data.redirect;
				return;
			}
			if (result.ok && op === 'remove' && item) {
				item.parentNode.removeChild(item);
			}
			form.querySelector('button').disabled = false;
		}).catch(function () {
			if (status) {
				status.textContent = i18n.error;
			}
			form.querySelector('button').disabled = false;
		});
	}

	/* On a download page the button follows the price option picked in EDD's purchase form. */
	function followPrice(form) {
		var id = form.getAttribute('data-download-id');
		var checked = document.querySelector('input.edd_price_option_' + id + ':checked');
		var input = form.querySelector('input[name="price_id"]');
		if (input) {
			input.value = checked ? String(checked.value) : '0';
		}
		paint(form);
	}

	function variableForms() {
		return document.querySelectorAll('.stackless-wishlist-form--single[data-variable="1"]');
	}

	function bindPriceOptions() {
		document.addEventListener('change', function (event) {
			var target = event.target;
			if (!target || !target.matches || !target.matches('input[name="edd_options[price_id][]"]')) {
				return;
			}
			variableForms().forEach(function (form) {
				if (target.classList.contains('edd_price_option_' + form.getAttribute('data-download-id'))) {
					followPrice(form);
				}
			});
		});
		variableForms().forEach(followPrice);
	}

	function bindCopy() {
		document.addEventListener('click', function (event) {
			var button = event.target.closest('[data-stackless-copy]');
			if (!button) {
				return;
			}
			var input = button.parentNode.querySelector('[data-stackless-share-url]');
			if (!input) {
				return;
			}
			input.select();
			var done = function () {
				button.textContent = i18n.copied;
			};
			if (window.navigator.clipboard) {
				window.navigator.clipboard.writeText(input.value).then(done);
			} else {
				document.execCommand('copy');
				done();
			}
		});
	}

	function init() {
		document.addEventListener('submit', function (event) {
			if (event.target.closest('[data-stackless-wishlist-button]')) {
				onToggle(event);
			} else if (event.target.closest('[data-stackless-wishlist-action]')) {
				onListAction(event);
			}
		});
		bindPriceOptions();
		bindCopy();
		if (document.querySelector('[data-stackless-wishlist-button], [data-stackless-wishlist-list]')) {
			// Pages may be cached: fetch this visitor's saved items and a fresh nonce.
			post({ op: 'state' }).then(function (result) {
				keys = result.data.keys || [];
				setNonce(result.data.nonce);
				paintAll();
			}).catch(function () {});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
