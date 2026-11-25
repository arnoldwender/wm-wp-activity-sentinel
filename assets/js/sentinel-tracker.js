/**
 * WM Suite Activity Sentinel — Client-Side Interaction & Click Tracker
 *
 * Captures user clicks, menu navigation, button triggers, and modal openings
 * and transmits telemetry asynchronously to the cryptographic hash chain.
 *
 * @package WM_Activity_Sentinel
 */

(function () {
	'use strict';

	if (typeof window === 'undefined' || !window.wmSentinelTrackerConfig) {
		return;
	}

	const config = window.wmSentinelTrackerConfig;
	const eventQueue = [];
	let flushTimeout = null;
	const recentClicks = new Map();

	/**
	 * Helper: Clean and truncate text
	 */
	function cleanText(text, maxLen = 100) {
		if (!text || typeof text !== 'string') return '';
		const cleaned = text.replace(/\s+/g, ' ').trim();
		return cleaned.length > maxLen ? cleaned.substring(0, maxLen) + '…' : cleaned;
	}

	/**
	 * Helper: Get meaningful label from element
	 */
	function getElementLabel(el) {
		if (!el) return '';
		return (
			el.getAttribute('aria-label') ||
			el.getAttribute('title') ||
			el.innerText ||
			el.value ||
			el.getAttribute('placeholder') ||
			el.getAttribute('name') ||
			el.id ||
			el.className ||
			''
		);
	}

	/**
	 * Helper: Build CSS selector path for forensic context
	 */
	function getElementPath(el) {
		const path = [];
		let curr = el;
		let depth = 0;
		while (curr && curr.nodeType === Node.ELEMENT_NODE && depth < 5) {
			let selector = curr.nodeName.toLowerCase();
			if (curr.id) {
				selector += '#' + curr.id;
				path.unshift(selector);
				break;
			} else if (curr.className && typeof curr.className === 'string') {
				const classes = curr.className.split(/\s+/).filter(c => c && !c.startsWith('hover') && !c.startsWith('focus')).slice(0, 2);
				if (classes.length) {
					selector += '.' + classes.join('.');
				}
			}
			path.unshift(selector);
			curr = curr.parentNode;
			depth++;
		}
		return path.join(' > ');
	}

	/**
	 * Determine if element is an interactive target worth logging
	 */
	function resolveInteractiveTarget(target) {
		if (!target || target === document.body || target === document.documentElement) {
			return null;
		}

		// Direct check or closest ancestor
		return target.closest(
			'a, button, input[type="submit"], input[type="button"], input[type="reset"], ' +
			'.button, .page-title-action, .row-actions a, [role="button"], [role="tab"], ' +
			'#adminmenu a, #wpadminbar a, details summary, select, input[type="checkbox"], input[type="radio"], ' +
			'[data-sentinel-track], .components-button, .tablenav .button'
		);
	}

	/**
	 * Classify action and generate human-readable description
	 */
	function classifyInteraction(el) {
		const tag = el.tagName.toLowerCase();
		const text = cleanText(getElementLabel(el));
		const href = el.getAttribute('href') || '';
		const id = el.id || '';
		const classes = typeof el.className === 'string' ? el.className : '';

		let actionType = 'ui_click';
		let category = 'ui_interaction';
		let description = '';

		// 1. Admin Menu Navigation
		if (el.closest('#adminmenu')) {
			actionType = 'admin_menu_click';
			category = 'navigation';
			description = 'Admin-Menü geklickt: ' + (text || href);
		}
		// 2. Top Admin Bar
		else if (el.closest('#wpadminbar')) {
			actionType = 'admin_bar_click';
			category = 'navigation';
			description = 'Admin-Bar geklickt: ' + (text || href);
		}
		// 3. Tab Switch
		else if (classes.includes('nav-tab') || el.getAttribute('role') === 'tab') {
			actionType = 'tab_switch_click';
			category = 'navigation';
			description = 'Tab gewechselt: ' + text;
		}
		// 4. Row Action in Tables (Edit, Delete, Activate, View)
		else if (el.closest('.row-actions') || classes.includes('row-action')) {
			actionType = 'table_action_click';
			category = 'action';
			description = 'Tabellen-Aktion geklickt: ' + text + (href ? ' (' + cleanText(href, 60) + ')' : '');
		}
		// 5. Action Buttons (Submit, Save, Add New, Publish, Export, Delete)
		else if (tag === 'button' || tag === 'input' || classes.includes('button') || classes.includes('page-title-action')) {
			actionType = 'button_pressed';
			category = 'action';
			description = 'Button betätigt: ' + (text || el.value || tag);
		}
		// 6. File Downloads or Direct Export Links
		else if (
			el.hasAttribute('download') ||
			/\.(zip|csv|json|sql|pdf|tar\.gz|xml)($|\?)/i.test(href) ||
			href.includes('export')
		) {
			actionType = 'download_link_clicked';
			category = 'export';
			description = 'Download/Export-Link geklickt: ' + (text || href);
		}
		// 7. General Link
		else if (tag === 'a') {
			actionType = 'link_clicked';
			category = 'navigation';
			description = 'Link geöffnet: ' + text + (href ? ' (' + cleanText(href, 60) + ')' : '');
		}
		// 8. General Interactive Element
		else {
			actionType = 'interactive_element_click';
			category = 'ui_interaction';
			description = 'Element geklickt: ' + (text || tag);
		}

		return {
			actionType,
			category,
			description,
			metadata: {
				element_tag: tag.toUpperCase(),
				element_id: id || null,
				element_text: text || null,
				element_href: href ? cleanText(href, 200) : null,
				element_classes: cleanText(classes, 150) || null,
				action_description: description,
				dom_path: getElementPath(el),
				page_url: window.location.pathname + window.location.search,
				timestamp_js: new Date().toISOString(),
			},
		};
	}

	/**
	 * Handle click events
	 */
	function handleClick(e) {
		try {
			const target = resolveInteractiveTarget(e.target);
			if (!target) return;

			// Deduplication: Avoid double events on same element within 1.5s
			const now = Date.now();
			const signature = (target.id || target.tagName) + '::' + cleanText(target.innerText || target.getAttribute('href') || '');
			if (recentClicks.has(signature) && (now - recentClicks.get(signature)) < 1500) {
				return;
			}
			recentClicks.set(signature, now);

			// Clean up map periodically
			if (recentClicks.size > 100) {
				const cutoff = now - 5000;
				for (const [k, ts] of recentClicks.entries()) {
					if (ts < cutoff) recentClicks.delete(k);
				}
			}

			const classified = classifyInteraction(target);
			const intensity = config.telemetryIntensity || 'all';

			// Filter based on telemetry intensity
			if (intensity === 'critical_only') {
				const isDownloadOrExport = classified.category === 'export' || classified.actionType === 'download_link_clicked';
				const textCombined = (classified.description + ' ' + (classified.metadata.element_text || '') + ' ' + (classified.metadata.element_href || '')).toLowerCase();
				const isDestructive = /(löschen|delete|trash|entfernen|reset|export|herunterladen|uninstall|drop|truncate|revoke)/i.test(textCombined);
				if (!isDownloadOrExport && !isDestructive) {
					return;
				}
			} else if (intensity === 'actions_only') {
				const isAction = ['admin_menu_click', 'admin_bar_click', 'tab_switch_click', 'table_action_click', 'button_pressed', 'download_link_clicked'].includes(classified.actionType);
				if (!isAction) {
					return;
				}
			}

			eventQueue.push({
				event_type: classified.actionType,
				category: classified.category,
				severity: 'info',
				metadata: classified.metadata,
			});

			scheduleFlush();
		} catch (err) {
			// Silent catch to prevent interference with user operations
			if (config.debug) {
				console.warn('[WM Sentinel Tracker]', err);
			}
		}
	}

	/**
	 * Schedule asynchronous batch transmission
	 */
	function scheduleFlush() {
		if (flushTimeout) return;
		flushTimeout = setTimeout(flushQueue, 2000);
	}

	/**
	 * Resolve valid REST nonce from config or WordPress environment
	 */
	function getActiveNonce() {
		return (
			config.nonce ||
			(window.wpApiSettings && window.wpApiSettings.nonce) ||
			(window.wp && window.wp.apiFetch && window.wp.apiFetch.nonceMiddleware && window.wp.apiFetch.nonceMiddleware.nonce) ||
			''
		);
	}

	/**
	 * Build URL with _wpnonce query param for sendBeacon and fetch compatibility
	 */
	function getBeaconUrl() {
		const nonce = getActiveNonce();
		if (!nonce) {
			return config.restUrl;
		}
		const sep = config.restUrl.indexOf('?') !== -1 ? '&' : '?';
		return config.restUrl + sep + '_wpnonce=' + encodeURIComponent(nonce);
	}

	/**
	 * Transmit queued interaction telemetry to backend
	 */
	function flushQueue() {
		if (flushTimeout) {
			clearTimeout(flushTimeout);
			flushTimeout = null;
		}

		if (eventQueue.length === 0) return;

		const eventsToSend = eventQueue.splice(0, 20);
		const payload = JSON.stringify({ events: eventsToSend });
		const nonce = getActiveNonce();
		const url = getBeaconUrl();

		const headers = {
			'Content-Type': 'application/json',
		};
		if (nonce) {
			headers['X-WP-Nonce'] = nonce;
		}

		// Use fetch with authorization nonce and credentials
		if (typeof fetch === 'function') {
			fetch(url, {
				method: 'POST',
				headers: headers,
				body: payload,
				credentials: 'same-origin',
				keepalive: true,
			}).catch(function (err) {
				if (config.debug) {
					console.warn('[WM Sentinel Tracker Flush]', err);
				}
			});
		} else if (navigator.sendBeacon) {
			const blob = new Blob([payload], { type: 'application/json' });
			navigator.sendBeacon(url, blob);
		}
	}

	/**
	 * Immediate flush on page leave
	 */
	function handleUnload() {
		if (eventQueue.length === 0) return;

		const payload = JSON.stringify({ events: eventQueue.splice(0, 20) });
		const url = getBeaconUrl();
		if (navigator.sendBeacon) {
			const blob = new Blob([payload], { type: 'application/json' });
			navigator.sendBeacon(url, blob);
		} else {
			flushQueue();
		}
	}

	// Register event listeners
	document.addEventListener('click', handleClick, true);
	window.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			handleUnload();
		}
	});
	window.addEventListener('pagehide', handleUnload);
	window.addEventListener('beforeunload', handleUnload);

})();
