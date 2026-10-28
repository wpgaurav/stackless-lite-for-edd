/**
 * Stackless for EDD: opens Paddle's overlay checkout (the original gateway or a copy) right
 * over the EDD checkout.
 *
 * EDD validates the purchase form over AJAX and then submits it. When a Paddle gateway is
 * chosen, this script sends that submission itself, gets the order's Paddle transaction
 * back and opens the overlay. If anything goes wrong it lets the form submit normally, and
 * the buyer lands on the store's Paddle pay page instead.
 */
(function ($, cfg) {
	'use strict';

	if (!$ || !cfg || !cfg.gateways || !cfg.gateways.length) {
		return;
	}

	var paddleReady = null;
	var initializedWith = '';
	var completed = false;
	var active = null;

	function isPaddle(id) {
		return cfg.gateways.indexOf(id) !== -1;
	}

	function chosenGateway(form) {
		var field = form.querySelector('input[name="edd-gateway"]');
		return field ? String(field.value) : '';
	}

	function go(url) {
		if (url) {
			window.location.href = url;
		}
	}

	/** Paddle.js, loaded once and only when a Paddle gateway is in play. */
	function loadPaddle() {
		if (window.Paddle) {
			return $.Deferred().resolve(window.Paddle).promise();
		}
		if (paddleReady) {
			return paddleReady;
		}
		var deferred = $.Deferred();
		var script = document.createElement('script');
		script.src = cfg.paddleJs;
		script.async = true;
		script.onload = function () {
			if (window.Paddle) {
				deferred.resolve(window.Paddle);
			} else {
				deferred.reject();
			}
		};
		script.onerror = function () {
			paddleReady = null;
			deferred.reject();
		};
		document.head.appendChild(script);
		paddleReady = deferred.promise();
		return paddleReady;
	}

	function onEvent(event) {
		if (!event || !event.name || !active) {
			return;
		}
		if (event.name === 'checkout.completed') {
			completed = true;
			var target = active.successUrl;
			// Paddle redirects to successUrl itself; this is the fallback.
			window.setTimeout(function () {
				go(target);
			}, 3000);
		} else if (event.name === 'checkout.closed' && !completed) {
			// Back to checkout with the same pending order, like the pay page's "Back to the store".
			go(active.cancelUrl);
		} else if (event.name === 'checkout.error') {
			go(active.payUrl);
		}
	}

	function open(data) {
		var account = data.token + '|' + (data.sandbox ? 'sandbox' : 'live');
		// Paddle.js runs one account per page: another copy's account opens on the pay page.
		if (initializedWith && initializedWith !== account) {
			go(data.payUrl);
			return;
		}
		loadPaddle().then(function (Paddle) {
			if (!initializedWith) {
				if (data.sandbox) {
					Paddle.Environment.set('sandbox');
				}
				Paddle.Initialize({ token: data.token, eventCallback: onEvent });
				initializedWith = account;
			}
			active = data;
			completed = false;
			var options = {
				transactionId: data.transactionId,
				settings: {
					displayMode: 'overlay',
					successUrl: data.successUrl,
					allowLogout: false
				}
			};
			if (data.locale) {
				options.settings.locale = data.locale;
			}
			if (data.email) {
				options.customer = { email: data.email };
			}
			// Inside a modal or on-page checkout frame the host fits the frame to the screen first.
			document.dispatchEvent(new CustomEvent('stackless-edd:overlay'));
			Paddle.Checkout.open(options);
		}, function () {
			go(data.payUrl);
		});
	}

	function submitNatively(form) {
		form.setAttribute('data-stackless-paddle-native', '1');
		HTMLFormElement.prototype.submit.call(form);
	}

	// EDD calls $(form).submit() once its AJAX validation passed: take that submission over.
	$(document).on('submit', '#edd_purchase_form', function (e) {
		var form = this;
		if (form.getAttribute('data-stackless-paddle-native') === '1' || !isPaddle(chosenGateway(form))) {
			return;
		}
		e.preventDefault();
		$.ajax({
			url: form.getAttribute('action') || window.location.href,
			type: 'POST',
			data: $(form).serialize() + '&' + encodeURIComponent(cfg.flag) + '=1',
			dataType: 'json'
		}).done(function (response) {
			var data = response && response.data ? response.data : null;
			if (response && response.success && data && data.transactionId && data.token) {
				open(data);
			} else if (data && data.redirect) {
				// Checkout prints the errors EDD kept for this visit.
				go(data.redirect);
			} else {
				submitNatively(form);
			}
		}).fail(function () {
			submitNatively(form);
		});
	});

	// EDD re-renders the purchase form whenever the gateway changes: load Paddle.js as soon as one is picked.
	$(document.body).on('edd_gateway_loaded', function (e, gateway) {
		if (isPaddle(String(gateway || ''))) {
			loadPaddle();
		}
	});
})(window.jQuery, window.stacklessEddPaddle);
