/**
 * WM Suite Activity Sentinel — Admin JavaScript & Context Inspector
 */

(function () {
	'use strict';

	var FOCUSABLE_SELECTOR = [
		'button:not([disabled])',
		'[href]',
		'input:not([disabled])',
		'select:not([disabled])',
		'textarea:not([disabled])',
		'[tabindex]:not([tabindex="-1"])'
	].join(', ');

	var BACKGROUND_SELECTORS = [
		'.wm-sentinel-admin-wrap',
		'#adminmenuwrap',
		'#wpadminbar',
		'#wpfooter'
	];

	document.addEventListener('DOMContentLoaded', function () {
		var modal = document.getElementById('wm-sentinel-context-modal');
		var modalTitle = document.getElementById('wm-sentinel-modal-title');
		var modalJson = document.getElementById('wm-sentinel-context-json');
		var closeBtn = document.querySelector('.wm-sentinel-modal-close');
		// The element that opened the dialog, so keyboard users land back where they were on close.
		var lastFocus = null;
		var backgroundStates = [];

		function isModalOpen() {
			return !!(modal && modal.style.display !== 'none');
		}

		function getFocusableElements() {
			if (!modal) {
				return [];
			}
			var nodes = modal.querySelectorAll(FOCUSABLE_SELECTOR);
			var result = [];
			for (var i = 0; i < nodes.length; i++) {
				var el = nodes[i];
				if (el.getAttribute('aria-hidden') === 'true' || el.hasAttribute('hidden')) {
					continue;
				}
				result.push(el);
			}
			return result;
		}

		function markElementInert(el) {
			backgroundStates.push({
				el: el,
				ariaHidden: el.getAttribute('aria-hidden'),
				hadInertAttr: el.hasAttribute('inert'),
				inertProp: Boolean(el.inert)
			});
			el.setAttribute('aria-hidden', 'true');
			el.inert = true;
			if (!el.hasAttribute('inert')) {
				el.setAttribute('inert', '');
			}
		}

		function setBackgroundInert(active) {
			var i;
			if (active) {
				if (backgroundStates.length > 0) {
					return;
				}
				var targets = document.querySelectorAll(BACKGROUND_SELECTORS.join(', '));
				for (i = 0; i < targets.length; i++) {
					var el = targets[i];
					if (el === modal) {
						continue;
					}
					if (modal && el.contains(modal)) {
						var children = el.children || [];
						for (var c = 0; c < children.length; c++) {
							var child = children[c];
							if (child !== modal && !child.contains(modal)) {
								markElementInert(child);
							}
						}
						continue;
					}
					markElementInert(el);
				}
				return;
			}

			for (i = 0; i < backgroundStates.length; i++) {
				var state = backgroundStates[i];
				if (state.ariaHidden === null) {
					state.el.removeAttribute('aria-hidden');
				} else {
					state.el.setAttribute('aria-hidden', state.ariaHidden);
				}
				state.el.inert = state.inertProp;
				if (!state.hadInertAttr) {
					state.el.removeAttribute('inert');
				}
			}
			backgroundStates = [];
		}

		function focusFallbackInModal() {
			var focusable = getFocusableElements();
			var target = closeBtn || focusable[0];
			if (target && typeof target.focus === 'function') {
				target.focus();
			}
		}

		function openModal(logId, eventType, contextData) {
			if (!modal) {
				return;
			}

			if (!isModalOpen()) {
				lastFocus = document.activeElement;
			}

			if (modalTitle) {
				modalTitle.textContent = 'Kontext-Inspektor: #' + logId + ' (' + eventType + ')';
			}
			if (modalJson) {
				try {
					var parsed = typeof contextData === 'string' ? JSON.parse(contextData) : contextData;
					modalJson.textContent = JSON.stringify(parsed, null, 2);
				} catch (e) {
					modalJson.textContent = contextData;
				}
			}

			setBackgroundInert(true);
			modal.style.display = 'flex';
			focusFallbackInModal();
		}

		function closeModal() {
			if (modal) {
				modal.style.display = 'none';
			}
			setBackgroundInert(false);
			var restoreTarget = lastFocus;
			lastFocus = null;
			if (restoreTarget && typeof restoreTarget.focus === 'function') {
				restoreTarget.focus();
			}
		}

		document.querySelectorAll('.wm-sentinel-view-context').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				var logId = btn.getAttribute('data-log-id');
				var eventType = btn.getAttribute('data-event');
				var context = btn.getAttribute('data-context');
				openModal(logId, eventType, context);
			});
		});

		if (closeBtn) {
			closeBtn.addEventListener('click', closeModal);
		}

		if (modal) {
			modal.addEventListener('click', function (e) {
				if (e.target === modal) {
					closeModal();
				}
			});
		}

		document.addEventListener('keydown', function (e) {
			if (!isModalOpen()) {
				return;
			}

			if (e.key === 'Escape') {
				e.preventDefault();
				closeModal();
				return;
			}

			if (e.key === 'Tab') {
				var focusable = getFocusableElements();
				if (focusable.length === 0) {
					e.preventDefault();
					return;
				}
				var first = focusable[0];
				var last = focusable[focusable.length - 1];
				var active = document.activeElement;

				if (e.shiftKey) {
					if (active === first || !modal.contains(active)) {
						e.preventDefault();
						last.focus();
					}
				} else if (active === last || !modal.contains(active)) {
					e.preventDefault();
					first.focus();
				}
			}
		});

		document.addEventListener('focusin', function (e) {
			if (!isModalOpen()) {
				return;
			}
			if (!modal.contains(e.target)) {
				focusFallbackInModal();
			}
		});
	});
})();
