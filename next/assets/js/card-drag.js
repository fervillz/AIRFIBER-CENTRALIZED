(function () {
	'use strict';

	if (!document.body || !document.body.classList.contains('afcn-page')) {
		return;
	}

	const CARD_SELECTOR = '.afcn-card,.afcn-module-card';
	const DROP_GROUP_SELECTOR = '[data-afcn-card-drop-group]';
	const LONG_PRESS_MS = 380;
	const ARRANGE_IDLE_MS = 10000;
	const PRESS_MOVE_TOLERANCE = 9;
	const ACTIVE_DRAG_THRESHOLD = 3;
	const FLIP_DURATION_MS = 180;
	const SETTLE_DURATION_MS = 200;
	const EDGE_SCROLL_ZONE = 72;
	const EDGE_SCROLL_MAX = 18;
	const cfg = window.afcnApp || {};
	const changedContainers = new Set();
	const flipAnimations = new WeakMap();
	const reducedMotion = Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

	let arrangeMode = false;
	let hold = null;
	let drag = null;
	let pointerFrame = 0;
	let wireTimer = 0;
	let arrangeIdleTimer = 0;
	let blockClickUntil = 0;
	let restoringSavedOrder = false;
	let pointer = { x: 0, y: 0 };

	function storageKey() {
		return 'afcn.card-order.v2.user.' + String(cfg.userId || 0);
	}

	function legacyStorageKey() {
		return 'afcn.card-order.v1.user.' + String(cfg.userId || 0);
	}

	function readOrders() {
		try {
			const current = window.localStorage.getItem(storageKey());
			if (current) {
				return JSON.parse(current) || {};
			}

			const legacy = window.localStorage.getItem(legacyStorageKey());
			if (legacy) {
				const migrated = JSON.parse(legacy) || {};
				window.localStorage.setItem(storageKey(), JSON.stringify(migrated));
				return migrated;
			}
		} catch (error) {
			return {};
		}
		return {};
	}

	function writeOrders(value) {
		try {
			window.localStorage.setItem(storageKey(), JSON.stringify(value));
			return true;
		} catch (error) {
			return false;
		}
	}

	function normalizeText(value) {
		return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
	}

	function hash(value) {
		let result = 2166136261;
		const string = String(value || '');
		for (let index = 0; index < string.length; index += 1) {
			result ^= string.charCodeAt(index);
			result = Math.imul(result, 16777619);
		}
		return (result >>> 0).toString(36);
	}

	function currentScope() {
		const active = document.querySelector('.afcn-nav [data-afcn-module].is-active');
		if (active && active.dataset.afcnModule) {
			return active.dataset.afcnModule;
		}
		return window.location.hash.replace(/^#/, '') || 'dashboard';
	}

	function directCards(parent) {
		if (!parent) {
			return [];
		}
		return Array.from(parent.children).filter(function (node) {
			return node.matches && node.matches(CARD_SELECTOR);
		});
	}

	function isVisible(node) {
		return Boolean(node && !node.hidden && node.getClientRects().length);
	}

	function closestCard(target) {
		return target && target.closest ? target.closest(CARD_SELECTOR) : null;
	}

	function cardKey(card, fallbackIndex) {
		if (card.dataset.afcnCardKey) {
			return 'd:' + card.dataset.afcnCardKey;
		}
		if (card.id) {
			return 'i:' + card.id;
		}

		const moduleId = card.querySelector('input[name="module_id"]');
		if (moduleId && moduleId.value) {
			return 'm:' + moduleId.value;
		}

		const connectionId = card.querySelector('input[name="connection_id"]');
		if (connectionId && connectionId.value) {
			return 'c:' + connectionId.value;
		}

		const userButton = card.querySelector('[data-afcn-user-edit]');
		if (userButton && userButton.dataset.afcnUserEdit) {
			return 'u:' + userButton.dataset.afcnUserEdit;
		}

		if (card.dataset.afcnSearch) {
			return 's:' + hash(normalizeText(card.dataset.afcnSearch));
		}

		const label = card.querySelector('.afcn-stat-label,.afcn-module-card-title-text,.afcn-user-card-subtitle,.afcn-card-header h2,.afcn-card-header h3,h3,h2');
		if (label && normalizeText(label.textContent)) {
			return 'l:' + hash(normalizeText(label.textContent));
		}

		return 'f:' + hash(normalizeText(card.textContent).slice(0, 180) || String(fallbackIndex));
	}

	function nodeToken(node) {
		if (node.id) {
			return '#' + node.id;
		}

		const classes = Array.from(node.classList || [])
			.filter(function (name) {
				return name.indexOf('afcn-') === 0 && !name.startsWith('is-');
			})
			.sort()
			.slice(0, 3);
		const base = node.tagName.toLowerCase() + (classes.length ? '.' + classes.join('.') : '');

		if (!node.parentElement) {
			return base;
		}

		const siblings = Array.from(node.parentElement.children).filter(function (sibling) {
			if (sibling.tagName !== node.tagName) {
				return false;
			}
			const siblingClasses = Array.from(sibling.classList || [])
				.filter(function (name) {
					return name.indexOf('afcn-') === 0 && !name.startsWith('is-');
				})
				.sort()
				.slice(0, 3);
			return siblingClasses.join('.') === classes.join('.');
		});

		return base + ':' + Math.max(0, siblings.indexOf(node));
	}

	function parentToken(parent) {
		if (parent.dataset.afcnCardOrderKey) {
			return parent.dataset.afcnCardOrderKey;
		}
		if (parent.dataset.afcnCardGroup) {
			parent.dataset.afcnCardOrderKey = currentScope() + '|g:' + parent.dataset.afcnCardGroup;
			return parent.dataset.afcnCardOrderKey;
		}

		const parts = [];
		let node = parent;
		const stop = document.getElementById('afcn-module-stage') || document.body;
		while (node && node !== stop && node !== document.body && parts.length < 4) {
			parts.unshift(nodeToken(node));
			node = node.parentElement;
		}

		parent.dataset.afcnCardOrderKey = currentScope() + '|' + parts.join('>');
		return parent.dataset.afcnCardOrderKey;
	}

	/*
	 * Card parents are isolated by default. A board-like feature may explicitly
	 * opt multiple lists into cross-list dragging with the same
	 * data-afcn-card-drop-group value. This keeps semantic groups safe.
	 */
	function dropGroup(parent) {
		if (parent.dataset.afcnCardDropGroup) {
			return currentScope() + '|shared:' + parent.dataset.afcnCardDropGroup;
		}
		return currentScope() + '|local:' + parentToken(parent);
	}

	function containers(root) {
		const output = [];
		const base = root || document;

		Array.from(base.querySelectorAll(CARD_SELECTOR)).forEach(function (card) {
			if (card.parentElement && !output.includes(card.parentElement)) {
				output.push(card.parentElement);
			}
		});

		Array.from(base.querySelectorAll(DROP_GROUP_SELECTOR)).forEach(function (parent) {
			if (!output.includes(parent)) {
				output.push(parent);
			}
		});

		return output;
	}

	function compatibleContainers(parent) {
		const group = dropGroup(parent);
		return containers(document).filter(function (candidate) {
			return dropGroup(candidate) === group && isVisible(candidate);
		});
	}

	function canArrangeCard(card) {
		if (!card || !card.parentElement || !isVisible(card)) {
			return false;
		}
		const compatible = compatibleContainers(card.parentElement);
		const visibleCards = compatible.reduce(function (total, parent) {
			return total + directCards(parent).filter(isVisible).length;
		}, 0);
		return visibleCards > 1 || compatible.length > 1;
	}

	function orderAnchor(parent) {
		const children = Array.from(parent.children);
		const lastCardIndex = children.reduce(function (found, child, index) {
			return child.matches && child.matches(CARD_SELECTOR) ? index : found;
		}, -1);
		return lastCardIndex >= 0 ? (children[lastCardIndex + 1] || null) : null;
	}

	function sameCardSequence(current, desired) {
		return current.length === desired.length && current.every(function (card, index) {
			return card === desired[index];
		});
	}

	/*
	 * Restore a saved order only when the DOM is actually different.
	 *
	 * The previous implementation reinserted every card on every wire() call,
	 * even when the saved order already matched the DOM. Because the runtime also
	 * watches card childList mutations, those no-op-looking reinserts triggered
	 * the observer again every 50ms and made card markup visibly refresh/blink.
	 */
	function restoreGroup(groupContainers, saved) {
		const cardMap = new Map();
		groupContainers.forEach(function (parent) {
			directCards(parent).forEach(function (card, index) {
				cardMap.set(cardKey(card, index), card);
			});
		});

		const desiredOwner = new Map();
		groupContainers.forEach(function (parent) {
			const order = saved[parentToken(parent)];
			if (!Array.isArray(order)) {
				return;
			}
			order.forEach(function (key) {
				if (cardMap.has(key)) {
					desiredOwner.set(key, parent);
				}
			});
		});

		const plans = [];
		groupContainers.forEach(function (parent) {
			const order = saved[parentToken(parent)];
			if (!Array.isArray(order)) {
				return;
			}

			const current = directCards(parent);
			const ordered = order.map(function (key) {
				return desiredOwner.get(key) === parent ? cardMap.get(key) : null;
			}).filter(Boolean);
			const extras = current.filter(function (card, index) {
				const key = cardKey(card, index);
				return (!desiredOwner.has(key) || desiredOwner.get(key) === parent) && !ordered.includes(card);
			});
			const desired = ordered.concat(extras);
			if (!sameCardSequence(current, desired)) {
				plans.push({
					parent: parent,
					desired: desired,
					anchor: orderAnchor(parent)
				});
			}
		});

		if (!plans.length) {
			return false;
		}

		restoringSavedOrder = true;
		plans.forEach(function (plan) {
			plan.desired.forEach(function (card) {
				plan.parent.insertBefore(card, plan.anchor);
			});
		});

		/* MutationObserver callbacks run before the next timer task. */
		window.setTimeout(function () {
			restoringSavedOrder = false;
		}, 0);
		return true;
	}

	function restoreSavedOrders() {
		/*
		 * Never restore the last persisted order while the user is arranging.
		 * The current DOM is the pending preference until arrange mode saves/exits.
		 */
		if (drag || arrangeMode) {
			return;
		}

		const saved = readOrders();
		const groups = new Map();
		containers(document).forEach(function (parent) {
			const group = dropGroup(parent);
			if (!groups.has(group)) {
				groups.set(group, []);
			}
			groups.get(group).push(parent);
		});
		groups.forEach(function (parents) {
			restoreGroup(parents, saved);
		});
	}

	function saveChangedOrders() {
		if (!changedContainers.size) {
			return true;
		}
		const saved = readOrders();
		changedContainers.forEach(function (parent) {
			if (!parent || !parent.isConnected) {
				return;
			}
			saved[parentToken(parent)] = directCards(parent).map(function (card, index) {
				return cardKey(card, index);
			});
		});
		return writeOrders(saved);
	}

	function indicator() {
		let node = document.querySelector('[data-afcn-card-order-indicator]');
		if (!node) {
			node = document.createElement('div');
			node.className = 'afcn-card-order-indicator';
			node.dataset.afcnCardOrderIndicator = '1';
			node.setAttribute('role', 'status');
			node.setAttribute('aria-live', 'polite');
			node.textContent = 'Arrange cards · drag to reorder · auto-saves after 10s idle · long press to save and exit';
			node.hidden = true;
			document.body.appendChild(node);
		}
		return node;
	}

	function clearArrangeIdleTimer() {
		if (!arrangeIdleTimer) {
			return;
		}
		window.clearTimeout(arrangeIdleTimer);
		arrangeIdleTimer = 0;
	}

	function scheduleArrangeIdleTimer() {
		clearArrangeIdleTimer();
		if (!arrangeMode || drag || hold) {
			return;
		}
		arrangeIdleTimer = window.setTimeout(function () {
			arrangeIdleTimer = 0;
			if (!arrangeMode) {
				return;
			}
			if (drag || hold) {
				scheduleArrangeIdleTimer();
				return;
			}
			exitArrangeMode(true);
		}, ARRANGE_IDLE_MS);
	}

	function markArrangeableCards() {
		document.querySelectorAll('[data-afcn-card-reorder]').forEach(function (card) {
			card.removeAttribute('data-afcn-card-reorder');
		});
		containers(document).forEach(function (parent) {
			directCards(parent).forEach(function (card) {
				if (canArrangeCard(card)) {
					card.dataset.afcnCardReorder = '1';
				}
			});
		});
	}

	function enterArrangeMode() {
		if (arrangeMode) {
			return;
		}
		arrangeMode = true;
		document.body.classList.add('afcn-card-reorder-mode');
		markArrangeableCards();
		indicator().hidden = false;
		scheduleArrangeIdleTimer();
		if (window.navigator.vibrate) {
			window.navigator.vibrate(16);
		}
	}

	function clearActiveDropZone() {
		document.querySelectorAll('.is-afcn-card-drop-zone').forEach(function (parent) {
			parent.classList.remove('is-afcn-card-drop-zone');
		});
	}

	function exitArrangeMode(save) {
		if (!arrangeMode) {
			return;
		}
		clearArrangeIdleTimer();
		if (drag) {
			finishDragImmediately(true);
		}
		if (save !== false) {
			saveChangedOrders();
		}
		changedContainers.clear();
		arrangeMode = false;
		document.body.classList.remove('afcn-card-reorder-mode');
		document.querySelectorAll('[data-afcn-card-reorder]').forEach(function (card) {
			card.removeAttribute('data-afcn-card-reorder');
		});
		clearActiveDropZone();
		indicator().hidden = true;
		if (save !== false && window.AirfiberNext && window.AirfiberNext.toast) {
			window.AirfiberNext.toast('Card arrangement saved.', false);
		}
	}

	function clearHold() {
		if (!hold) {
			return;
		}
		window.clearTimeout(hold.timer);
		if (hold.card) {
			hold.card.classList.remove('is-afcn-card-pressing');
		}
		hold = null;
	}

	function pressDistance(event) {
		return hold ? Math.hypot(event.clientX - hold.startX, event.clientY - hold.startY) : 0;
	}

	function createPlaceholder(card, rect) {
		const placeholder = document.createElement('div');
		const computed = window.getComputedStyle(card);
		placeholder.className = 'afcn-card-drop-placeholder';
		placeholder.setAttribute('aria-hidden', 'true');
		placeholder.style.width = rect.width + 'px';
		placeholder.style.height = rect.height + 'px';
		placeholder.style.flex = computed.flex;
		placeholder.style.gridColumn = computed.gridColumn;
		placeholder.style.gridRow = computed.gridRow;
		placeholder.style.alignSelf = computed.alignSelf;
		placeholder.style.justifySelf = computed.justifySelf;
		return placeholder;
	}

	function captureLayout(parents) {
		const positions = new Map();
		parents.forEach(function (parent) {
			directCards(parent).forEach(function (card) {
				positions.set(card, card.getBoundingClientRect());
			});
		});
		return positions;
	}

	function animateFlip(before) {
		if (reducedMotion) {
			return;
		}
		before.forEach(function (oldRect, card) {
			if (!card.isConnected || (drag && card === drag.card)) {
				return;
			}
			const nextRect = card.getBoundingClientRect();
			const deltaX = oldRect.left - nextRect.left;
			const deltaY = oldRect.top - nextRect.top;
			if (Math.abs(deltaX) < 0.5 && Math.abs(deltaY) < 0.5 || typeof card.animate !== 'function') {
				return;
			}
			const previous = flipAnimations.get(card);
			if (previous) {
				previous.cancel();
			}
			const animation = card.animate([
				{ transform: 'translate3d(' + deltaX + 'px,' + deltaY + 'px,0)' },
				{ transform: 'translate3d(0,0,0)' }
			], {
				duration: FLIP_DURATION_MS,
				easing: 'cubic-bezier(.2,.8,.2,1)'
			});
			flipAnimations.set(card, animation);
			animation.onfinish = function () {
				if (flipAnimations.get(card) === animation) {
					flipAnimations.delete(card);
				}
			};
		});
	}

	function tryCapturePointer(card, pointerId) {
		if (!card || typeof card.setPointerCapture !== 'function') {
			return false;
		}
		try {
			card.setPointerCapture(pointerId);
			return typeof card.hasPointerCapture !== 'function' || card.hasPointerCapture(pointerId);
		} catch (error) {
			return false;
		}
	}

	function releasePointer(card, pointerId) {
		if (!card || typeof card.releasePointerCapture !== 'function') {
			return;
		}
		try {
			if (typeof card.hasPointerCapture !== 'function' || card.hasPointerCapture(pointerId)) {
				card.releasePointerCapture(pointerId);
			}
		} catch (error) {
			// Pointer capture can already be released by the browser on pointerup.
		}
	}

	function beginDrag(card, pointerId, clientX, clientY) {
		if (drag || !card || !canArrangeCard(card)) {
			return false;
		}
		clearArrangeIdleTimer();

		const originalParent = card.parentElement;
		const originalIndex = directCards(originalParent).indexOf(card);
		const rect = card.getBoundingClientRect();
		const placeholder = createPlaceholder(card, rect);
		const originalStyle = card.getAttribute('style');
		const compatible = compatibleContainers(originalParent);
		const captured = tryCapturePointer(card, pointerId);

		originalParent.insertBefore(placeholder, card);
		card.classList.remove('is-afcn-card-pressing');
		card.classList.add('is-afcn-card-dragging');
		card.setAttribute('aria-grabbed', 'true');
		card.style.position = 'fixed';
		card.style.left = '0';
		card.style.top = '0';
		card.style.width = rect.width + 'px';
		card.style.height = rect.height + 'px';
		card.style.margin = '0';
		card.style.boxSizing = 'border-box';
		card.style.zIndex = '2147483000';
		card.style.pointerEvents = 'none';
		card.style.willChange = 'transform';
		document.body.appendChild(card);

		drag = {
			card: card,
			placeholder: placeholder,
			pointerId: pointerId,
			pointerCaptured: captured,
			originalParent: originalParent,
			originalIndex: originalIndex,
			originalStyle: originalStyle,
			offsetX: Math.max(0, Math.min(rect.width, clientX - rect.left)),
			offsetY: Math.max(0, Math.min(rect.height, clientY - rect.top)),
			compatible: compatible,
			currentContainer: originalParent,
			settling: false,
			settleAnimation: null
		};
		pointer = { x: clientX, y: clientY };
		blockClickUntil = Date.now() + 650;
		updateMirrorPosition();
		setActiveDropZone(originalParent);
		return true;
	}

	function mirrorTransform(x, y, settled) {
		if (!drag) {
			return '';
		}
		const left = x - drag.offsetX;
		const top = y - drag.offsetY;
		return 'translate3d(' + left + 'px,' + top + 'px,0) scale(' + (settled ? 1 : 1.025) + ')';
	}

	function updateMirrorPosition() {
		if (drag) {
			drag.card.style.transform = mirrorTransform(pointer.x, pointer.y, false);
		}
	}

	function setActiveDropZone(parent) {
		clearActiveDropZone();
		if (parent) {
			parent.classList.add('is-afcn-card-drop-zone');
		}
	}

	function containerAtPoint(x, y) {
		if (!drag || x < 0 || y < 0 || x > window.innerWidth || y > window.innerHeight) {
			return null;
		}
		const hit = document.elementFromPoint(x, y);
		if (hit) {
			const direct = drag.compatible.find(function (parent) {
				return parent === hit || parent.contains(hit);
			});
			if (direct) {
				return direct;
			}
		}
		return drag.compatible.find(function (parent) {
			const rect = parent.getBoundingClientRect();
			return x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
		}) || null;
	}

	function isGridLayout(items) {
		if (items.length < 2) {
			return false;
		}
		const first = items[0].getBoundingClientRect();
		return items.slice(1, Math.min(items.length, 4)).some(function (item) {
			const rect = item.getBoundingClientRect();
			return Math.abs(first.top - rect.top) < Math.min(first.height, rect.height) * 0.45;
		});
	}

	function insertionReference(parent, x, y) {
		const items = directCards(parent).filter(isVisible);
		if (!items.length) {
			return null;
		}
		const firstRect = items[0].getBoundingClientRect();
		const lastRect = items[items.length - 1].getBoundingClientRect();
		if (y < firstRect.top) {
			return items[0];
		}
		if (y > lastRect.bottom) {
			return null;
		}

		if (!isGridLayout(items)) {
			return items.find(function (item) {
				const rect = item.getBoundingClientRect();
				return y < rect.top + rect.height / 2;
			}) || null;
		}

		const rows = [];
		items.forEach(function (item) {
			const rect = item.getBoundingClientRect();
			let row = rows.find(function (candidate) {
				return Math.abs(candidate.top - rect.top) < Math.max(8, rect.height * 0.35);
			});
			if (!row) {
				row = { top: rect.top, bottom: rect.bottom, items: [] };
				rows.push(row);
			}
			row.top = Math.min(row.top, rect.top);
			row.bottom = Math.max(row.bottom, rect.bottom);
			row.items.push({ element: item, rect: rect });
		});
		rows.sort(function (a, b) { return a.top - b.top; });
		const row = rows.find(function (candidate) {
			return y <= candidate.bottom;
		}) || rows[rows.length - 1];
		row.items.sort(function (a, b) { return a.rect.left - b.rect.left; });
		const before = row.items.find(function (item) {
			return x < item.rect.left + item.rect.width / 2;
		});
		return before ? before.element : row.items[row.items.length - 1].element.nextSibling;
	}

	function movePlaceholder(parent, x, y) {
		if (!drag || !parent) {
			return;
		}
		const source = drag.placeholder.parentElement;
		const reference = insertionReference(parent, x, y);
		if (reference === drag.placeholder) {
			return;
		}
		if (source === parent && (reference === drag.placeholder.nextSibling || (!reference && drag.placeholder === parent.lastElementChild))) {
			return;
		}
		const affected = source === parent ? [parent] : [source, parent].filter(Boolean);
		const before = captureLayout(affected);
		parent.insertBefore(drag.placeholder, reference);
		animateFlip(before);
		if (source) {
			changedContainers.add(source);
		}
		changedContainers.add(parent);
		drag.currentContainer = parent;
	}

	function autoScroll(y) {
		if (!drag || drag.settling) {
			return false;
		}
		let amount = 0;
		if (y < EDGE_SCROLL_ZONE) {
			amount = -Math.ceil(EDGE_SCROLL_MAX * (1 - Math.max(0, y) / EDGE_SCROLL_ZONE));
		} else if (y > window.innerHeight - EDGE_SCROLL_ZONE) {
			amount = Math.ceil(EDGE_SCROLL_MAX * ((Math.min(window.innerHeight, y) - (window.innerHeight - EDGE_SCROLL_ZONE)) / EDGE_SCROLL_ZONE));
		}
		if (!amount) {
			return false;
		}
		const before = window.scrollY;
		window.scrollBy(0, amount);
		return window.scrollY !== before;
	}

	function processPointerFrame() {
		pointerFrame = 0;
		if (!drag || drag.settling) {
			return;
		}
		updateMirrorPosition();
		const scrolling = autoScroll(pointer.y);
		const target = containerAtPoint(pointer.x, pointer.y);
		setActiveDropZone(target);
		if (target) {
			movePlaceholder(target, pointer.x, pointer.y);
		}
		if (scrolling && drag && !drag.settling && !pointerFrame) {
			pointerFrame = window.requestAnimationFrame(processPointerFrame);
		}
	}

	function schedulePointerFrame(x, y) {
		if (!drag || drag.settling) {
			return;
		}
		pointer = { x: x, y: y };
		if (!pointerFrame) {
			pointerFrame = window.requestAnimationFrame(processPointerFrame);
		}
	}

	function movePlaceholderToOriginalPosition() {
		if (!drag) {
			return;
		}
		const source = drag.placeholder.parentElement;
		const target = drag.originalParent;
		const siblings = directCards(target);
		const reference = siblings[drag.originalIndex] || null;
		const affected = source === target ? [target] : [source, target].filter(Boolean);
		const before = captureLayout(affected);
		target.insertBefore(drag.placeholder, reference);
		animateFlip(before);
		if (source) {
			changedContainers.add(source);
		}
		changedContainers.add(target);
		drag.currentContainer = target;
	}

	function cancelSettleAnimation() {
		if (!drag || !drag.settleAnimation) {
			return;
		}
		const animation = drag.settleAnimation;
		drag.settleAnimation = null;
		animation.onfinish = null;
		animation.oncancel = null;
		animation.cancel();
	}

	function restoreCardStyles(card, originalStyle) {
		if (originalStyle === null) {
			card.removeAttribute('style');
		} else {
			card.setAttribute('style', originalStyle);
		}
		card.classList.remove('is-afcn-card-dragging');
		card.removeAttribute('aria-grabbed');
		if (arrangeMode) {
			card.dataset.afcnCardReorder = '1';
		}
	}

	function placeCardAtPlaceholder() {
		if (!drag) {
			return;
		}
		const activeDrag = drag;
		cancelSettleAnimation();
		releasePointer(activeDrag.card, activeDrag.pointerId);
		activeDrag.placeholder.replaceWith(activeDrag.card);
		restoreCardStyles(activeDrag.card, activeDrag.originalStyle);
		drag = null;
		clearActiveDropZone();
		markArrangeableCards();
		scheduleArrangeIdleTimer();
	}

	function animateMirrorToPlaceholder(callback) {
		if (!drag) {
			callback();
			return;
		}
		const target = drag.placeholder.getBoundingClientRect();
		const startTransform = drag.card.style.transform || mirrorTransform(pointer.x, pointer.y, false);
		const targetTransform = 'translate3d(' + target.left + 'px,' + target.top + 'px,0) scale(1)';
		if (reducedMotion || typeof drag.card.animate !== 'function') {
			drag.card.style.transform = targetTransform;
			callback();
			return;
		}
		const activeDrag = drag;
		const animation = activeDrag.card.animate([
			{ transform: startTransform },
			{ transform: targetTransform }
		], {
			duration: SETTLE_DURATION_MS,
			easing: 'cubic-bezier(.2,.85,.25,1)',
			fill: 'forwards'
		});
		activeDrag.settleAnimation = animation;
		let completed = false;
		function complete() {
			if (completed) {
				return;
			}
			completed = true;
			animation.onfinish = null;
			animation.oncancel = null;
			if (drag === activeDrag) {
				activeDrag.settleAnimation = null;
			}
			animation.cancel();
			callback();
		}
		animation.onfinish = complete;
		animation.oncancel = complete;
	}

	function finishDrag(validDrop) {
		if (!drag || drag.settling) {
			return;
		}
		drag.settling = true;
		if (pointerFrame) {
			window.cancelAnimationFrame(pointerFrame);
			pointerFrame = 0;
		}
		const validContainer = validDrop === false ? null : containerAtPoint(pointer.x, pointer.y);
		if (validContainer) {
			movePlaceholder(validContainer, pointer.x, pointer.y);
		} else {
			movePlaceholderToOriginalPosition();
		}
		clearActiveDropZone();
		blockClickUntil = Date.now() + 500;
		animateMirrorToPlaceholder(placeCardAtPlaceholder);
	}

	function finishDragImmediately(keepCurrentPosition) {
		if (!drag) {
			return;
		}
		if (pointerFrame) {
			window.cancelAnimationFrame(pointerFrame);
			pointerFrame = 0;
		}
		cancelSettleAnimation();
		if (!keepCurrentPosition) {
			movePlaceholderToOriginalPosition();
		}
		placeCardAtPlaceholder();
	}

	document.addEventListener('pointerdown', function (event) {
		if (drag || (event.pointerType === 'mouse' && event.button !== 0)) {
			return;
		}
		const card = closestCard(event.target);
		if (!card || !canArrangeCard(card)) {
			return;
		}
		if (event.target.closest('input,textarea,select,[contenteditable="true"]')) {
			return;
		}

		if (arrangeMode) {
			clearArrangeIdleTimer();
		}
		clearHold();
		card.classList.add('is-afcn-card-pressing');
		hold = {
			card: card,
			pointerId: event.pointerId,
			startX: event.clientX,
			startY: event.clientY,
			lastX: event.clientX,
			lastY: event.clientY,
			wasArrangeMode: arrangeMode,
			timer: 0
		};

		hold.timer = window.setTimeout(function () {
			if (!hold || hold.card !== card) {
				return;
			}
			const activeHold = hold;
			blockClickUntil = Date.now() + 700;
			if (activeHold.wasArrangeMode) {
				clearHold();
				exitArrangeMode(true);
				return;
			}
			enterArrangeMode();
			hold = null;
			beginDrag(card, activeHold.pointerId, activeHold.lastX, activeHold.lastY);
		}, LONG_PRESS_MS);

		if (arrangeMode) {
			event.preventDefault();
		}
	}, true);

	document.addEventListener('pointermove', function (event) {
		if (drag && event.pointerId === drag.pointerId) {
			if (!drag.settling) {
				event.preventDefault();
				schedulePointerFrame(event.clientX, event.clientY);
			}
			return;
		}
		if (!hold || event.pointerId !== hold.pointerId) {
			return;
		}

		hold.lastX = event.clientX;
		hold.lastY = event.clientY;
		const distance = pressDistance(event);
		if (!hold.wasArrangeMode) {
			if (distance > PRESS_MOVE_TOLERANCE) {
				clearHold();
			}
			return;
		}
		if (distance > ACTIVE_DRAG_THRESHOLD) {
			const activeHold = hold;
			window.clearTimeout(activeHold.timer);
			hold = null;
			beginDrag(activeHold.card, activeHold.pointerId, event.clientX, event.clientY);
			schedulePointerFrame(event.clientX, event.clientY);
			event.preventDefault();
		}
	}, { capture: true, passive: false });

	/*
	 * Pointer Events are primary. This non-passive touch fallback prevents the
	 * browser from taking over a gesture after the long press has already lifted
	 * the card, which is especially important on mobile Safari/Chrome.
	 */
	document.addEventListener('touchmove', function (event) {
		if (drag && !drag.settling) {
			event.preventDefault();
		}
	}, { capture: true, passive: false });

	function endPointer(event) {
		if (drag && event.pointerId === drag.pointerId) {
			if (!drag.settling) {
				pointer = { x: event.clientX, y: event.clientY };
				finishDrag(true);
			}
			return;
		}
		if (hold && event.pointerId === hold.pointerId) {
			clearHold();
			if (arrangeMode) {
				scheduleArrangeIdleTimer();
			}
		}
	}

	document.addEventListener('pointerup', endPointer, true);
	document.addEventListener('pointercancel', function (event) {
		if (drag && event.pointerId === drag.pointerId) {
			finishDrag(false);
			return;
		}
		if (hold && event.pointerId === hold.pointerId) {
			clearHold();
			if (arrangeMode) {
				scheduleArrangeIdleTimer();
			}
		}
	}, true);
	document.addEventListener('lostpointercapture', function (event) {
		if (drag && event.pointerId === drag.pointerId && !drag.settling) {
			finishDrag(false);
		}
	}, true);

	document.addEventListener('dragstart', function (event) {
		if (closestCard(event.target) && (arrangeMode || hold || drag)) {
			event.preventDefault();
		}
	}, true);

	document.addEventListener('click', function (event) {
		const card = closestCard(event.target);
		if (card && (arrangeMode || Date.now() < blockClickUntil)) {
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}
		if (arrangeMode && event.target.closest('.afcn-nav [data-afcn-module],[data-afcn-view-toggle],[data-afcn-users-view-toggle]')) {
			exitArrangeMode(true);
		}
	}, true);

	document.addEventListener('contextmenu', function (event) {
		const card = closestCard(event.target);
		if (card && (arrangeMode || (hold && hold.card === card) || (drag && drag.card === card))) {
			event.preventDefault();
		}
	}, true);

	document.addEventListener('keydown', function (event) {
		if (arrangeMode && event.key === 'Escape') {
			event.preventDefault();
			exitArrangeMode(true);
		}
	});

	window.addEventListener('blur', function () {
		if (drag && !drag.settling) {
			finishDrag(false);
		} else if (hold) {
			clearHold();
			if (arrangeMode) {
				scheduleArrangeIdleTimer();
			}
		}
	});

	window.addEventListener('beforeunload', function () {
		clearArrangeIdleTimer();
		if (drag) {
			finishDragImmediately(true);
		}
		if (arrangeMode) {
			saveChangedOrders();
		}
	});

	function wire() {
		if (!arrangeMode) {
			restoreSavedOrders();
		} else {
			markArrangeableCards();
		}
	}

	function scheduleWire() {
		window.clearTimeout(wireTimer);
		wireTimer = window.setTimeout(wire, 50);
	}

	function observe(root) {
		if (!root || !window.MutationObserver) {
			return;
		}
		new MutationObserver(function (records) {
			const changed = records.some(function (record) {
				return Array.from(record.addedNodes).concat(Array.from(record.removedNodes)).some(function (node) {
					return node.nodeType === 1 && (node.matches(CARD_SELECTOR) || (node.querySelector && node.querySelector(CARD_SELECTOR)));
				});
			});
			if (changed && !drag && !restoringSavedOrder) {
				scheduleWire();
			}
		}).observe(root, { childList: true, subtree: true });
	}

	document.addEventListener('afcn:module:loaded', function () {
		if (arrangeMode) {
			exitArrangeMode(true);
		}
		scheduleWire();
	});
	document.addEventListener('afcn:chunk:loaded', scheduleWire);

	observe(document.getElementById('afcn-module-stage'));
	observe(document.querySelector('.afcn-utility-drawer-body'));
	wire();

	window.AirfiberCardOrder = Object.freeze({
		wire: wire,
		enter: enterArrangeMode,
		exit: function () { exitArrangeMode(true); },
		active: function () { return arrangeMode; }
	});
}());