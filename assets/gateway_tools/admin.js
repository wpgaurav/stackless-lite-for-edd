/**
 * Stackless for EDD: Payment Gateway Tools in wp-admin.
 *
 * 1. Label: every gateway Stackless adds has "(Stackless)" in its admin label. Wherever that
 *    label is shown as text (Downloads → Settings → Payments, order screens, Stackless
 *    screens), the marker is swapped for a "Stackless" label, and a copy's " · EU account"
 *    suffix for a copy badge. Gateway selects (plain-text options) get " · Stackless".
 *    A MutationObserver keeps up with screens that render late.
 * 2. Copies: Duplicate, Rename and Remove buttons in a copyable gateway's settings.
 */
(function () {
	'use strict';

	var config = window.stacklessEddGatewayTools;
	if (!config) {
		return;
	}
	var marker = config.marker;

	function badge(text, kind, title) {
		var span = document.createElement('span');
		span.className = kind === 'copy' ? 'stackless-gateway-copy' : 'stackless-gateway-label';
		span.textContent = text;
		if (title) {
			span.title = title;
		}
		return span;
	}

	function skip(node) {
		var parent = node.parentNode;
		if (!parent || parent.nodeType !== 1) {
			return true;
		}
		var tag = parent.tagName;
		return tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA' || tag === 'OPTION' || tag === 'TITLE' ||
			parent.isContentEditable || parent.closest('.stackless-gateway-label, .stackless-gateway-copy');
	}

	function labelText(node) {
		var text = node.nodeValue;
		var at = text.indexOf(marker);
		if (at === -1 || skip(node)) {
			return;
		}
		var before = text.slice(0, at).replace(/\s+$/, '');
		var after = text.slice(at + marker.length);
		// A screen may write the full name back into a node that already has badges after it.
		var next = node.nextSibling;
		while (next && next.nodeType === 1 && (next.classList.contains('stackless-gateway-label') || next.classList.contains('stackless-gateway-copy'))) {
			var drop = next;
			next = next.nextSibling;
			drop.parentNode.removeChild(drop);
		}
		// The text node stays in place; the badges go after it.
		var fragment = document.createDocumentFragment();
		fragment.appendChild(badge(config.label, 'label', config.i18n.labelTitle));
		var copy = /^\s*·\s*(.+?)\s*$/.exec(after);
		if (copy) {
			fragment.appendChild(badge(copy[1], 'copy', config.i18n.copyTitle));
		} else if (after) {
			fragment.appendChild(document.createTextNode(after));
		}
		node.nodeValue = before + ' ';
		node.parentNode.insertBefore(fragment, node.nextSibling);
	}

	function scan(root) {
		if (!root || (root.nodeType !== 1 && root.nodeType !== 9)) {
			if (root && root.nodeType === 3) {
				labelText(root);
			}
			return;
		}
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
			acceptNode: function (node) {
				return node.nodeValue.indexOf(marker) !== -1 ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
			}
		});
		var found = [];
		while (walker.nextNode()) {
			found.push(walker.currentNode);
		}
		found.forEach(labelText);
		labelOptions(root);
	}

	/** Gateway selects (orders filter, order details): options keyed by a Stackless gateway id. */
	function labelOptions(root) {
		var gateways = config.gateways || {};
		if (!root || !root.querySelectorAll) {
			return;
		}
		Array.prototype.forEach.call(root.querySelectorAll('select[name="gateway"] option, select[name="edd-payment-gateway"] option'), function (option) {
			var gateway = gateways[option.value];
			if (!gateway) {
				return;
			}
			var text = option.textContent;
			if (text.indexOf(marker) !== -1) {
				text = text.replace(marker, '').replace(/\s+·?\s*$/, '').replace(/\s{2,}/g, ' ').trim();
			}
			if (text.indexOf(' · ' + config.label) === -1) {
				option.textContent = text + ' · ' + config.label + (gateway.badge ? ' · ' + gateway.badge : '');
			}
		});
	}

	function observe() {
		scan(document.body);
		var queued = [];
		var timer = 0;
		new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				if (mutation.type === 'characterData') {
					queued.push(mutation.target);
				}
				Array.prototype.forEach.call(mutation.addedNodes, function (node) {
					queued.push(node);
				});
			});
			if (!timer) {
				timer = window.setTimeout(function () {
					timer = 0;
					var batch = queued;
					queued = [];
					batch.forEach(function (node) {
						if (node.isConnected) {
							scan(node);
						}
					});
				}, 60);
			}
		}).observe(document.body, { childList: true, subtree: true, characterData: true });
	}

	function post(data) {
		var body = new FormData();
		body.append('action', config.action);
		body.append('nonce', config.nonce);
		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});
		return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) {
			return response.json();
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest && event.target.closest('[data-stackless-copy-task]');
		if (!button) {
			return;
		}
		event.preventDefault();
		var task = button.getAttribute('data-stackless-copy-task');
		var id = button.getAttribute('data-stackless-copy-id');
		var label = '';
		if (task === 'duplicate' || task === 'rename') {
			label = window.prompt(task === 'duplicate' ? config.i18n.duplicatePrompt : config.i18n.renamePrompt, '');
			if (label === null) {
				return;
			}
		} else if (task === 'remove' && !window.confirm(config.i18n.removeConfirm)) {
			return;
		}
		button.disabled = true;
		post({ task: task, id: id, label: label }).then(function (result) {
			if (!result || !result.success) {
				window.alert((result && result.data && result.data.message) || config.i18n.failed);
				button.disabled = false;
				return;
			}
			var url = result.data && result.data.url;
			if ((task === 'duplicate' || task === 'remove') && url) {
				window.location.href = url;
			} else {
				window.location.reload();
			}
		}).catch(function () {
			window.alert(config.i18n.failed);
			button.disabled = false;
		});
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', observe);
	} else {
		observe();
	}
})();
