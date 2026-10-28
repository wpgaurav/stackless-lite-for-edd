/* Stackless for EDD: Wishlist block (editor). The list renders on the server. */
(function (wp) {
	'use strict';
	if (!wp || !wp.blocks || !wp.element) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps : function (props) { return props || {}; };

	wp.blocks.registerBlockType('stackless-edd/wishlist', {
		apiVersion: 3,
		title: __('Wishlist', 'stackless-lite-edd'),
		description: __('Shows the visitor\'s saved downloads with move to cart, remove and share.', 'stackless-lite-edd'),
		category: 'easy-digital-downloads',
		icon: 'heart',
		supports: { html: false, align: ['wide', 'full'] },
		edit: function () {
			return el('div', useBlockProps({ className: 'stackless-wishlist-placeholder' }),
				el('p', null, el('strong', null, __('Wishlist', 'stackless-lite-edd'))),
				el('p', null, __('Visitors see their saved downloads here. Shared links open a read-only copy of the list.', 'stackless-lite-edd'))
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
