(function () {
	'use strict';

	const registry = new WeakMap();

	function icon(name) {
		const paths = {
			list: '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"></path>',
			grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect>'
		};
		return '<svg class="afcn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (paths[name] || paths.grid) + '</svg>';
	}

	function storageKey(key) {
		return 'afcn-view-mode.' + String(key || 'default');
	}

	function readView(key) {
		try {
			return window.localStorage.getItem(storageKey(key)) === 'list' ? 'list' : 'cards';
		} catch (error) {
			return 'cards';
		}
	}

	function saveView(key, view) {
		try {
			window.localStorage.setItem(storageKey(key), view === 'list' ? 'list' : 'cards');
		} catch (error) {
			// View preference is only a convenience. Switching still works without it.
		}
	}

	function createToggle(title) {
		if (!title || !title.parentElement) {
			return null;
		}

		let row = title.closest('.afcn-view-title-row,.afcn-users-title-row');
		if (!row) {
			row = document.createElement('div');
			row.className = 'afcn-view-title-row';
			title.parentElement.insertBefore(row, title);
			row.appendChild(title);
		}

		const wrapper = document.createElement('span');
		wrapper.className = 'afcn-tooltip afcn-tooltip-down';
		wrapper.innerHTML = '<span class="afcn-tooltip-trigger"><button type="button" class="afcn-view-toggle" data-afcn-view-toggle aria-label="Show list"><span class="afcn-view-list-icon">' + icon('list') + '</span><span class="afcn-view-grid-icon">' + icon('grid') + '</span></button></span><span class="afcn-tooltip-panel" role="tooltip">Toggle cards / list</span>';
		row.appendChild(wrapper);
		return wrapper.querySelector('[data-afcn-view-toggle]');
	}

	function resolve(root, value) {
		if (!value) {
			return null;
		}
		if (value.nodeType === 1) {
			return value;
		}
		return root.querySelector(value);
	}

	function setView(controller, view, persist) {
		const useList = view === 'list';
		if (useList && typeof controller.beforeList === 'function') {
			controller.beforeList();
		}

		controller.cards.hidden = useList;
		controller.list.hidden = !useList;
		controller.toggle.classList.toggle('is-list', useList);
		controller.toggle.setAttribute('aria-label', useList ? 'Show cards' : 'Show list');
		controller.root.dataset.afcnView = useList ? 'list' : 'cards';

		if (persist !== false) {
			saveView(controller.key, useList ? 'list' : 'cards');
		}
		if (typeof controller.onChange === 'function') {
			controller.onChange(useList ? 'list' : 'cards');
		}
	}

	function existingController(root, key) {
		const controller = registry.get(root);
		if (!controller) {
			return null;
		}
		if (controller.key === key && controller.cards.isConnected && controller.list.isConnected && controller.toggle.isConnected) {
			return controller;
		}
		registry.delete(root);
		delete root.dataset.afcnView;
		return null;
	}

	function attach(root, options) {
		if (!root || !root.querySelector) {
			return null;
		}

		options = options || {};
		const key = options.key || root.dataset.afcnViewKey || 'default';
		const existing = existingController(root, key);
		if (existing) {
			return existing;
		}

		const cards = resolve(root, options.cards);
		const list = resolve(root, options.list);
		let toggle = resolve(root, options.toggle || '[data-afcn-view-toggle]');
		if (!toggle && options.title) {
			toggle = createToggle(resolve(root, options.title));
		}
		if (!cards || !list || !toggle) {
			return null;
		}

		const controller = {
			root: root,
			key: key,
			cards: cards,
			list: list,
			toggle: toggle,
			beforeList: options.beforeList || null,
			onChange: options.onChange || null,
			set: function (view) {
				setView(controller, view, true);
			},
			get: function () {
				return controller.root.dataset.afcnView === 'list' ? 'list' : 'cards';
			}
		};

		toggle.addEventListener('click', function () {
			setView(controller, controller.get() === 'list' ? 'cards' : 'list', true);
		});
		registry.set(root, controller);
		setView(controller, readView(controller.key), false);
		return controller;
	}

	window.AirfiberViewMode = Object.freeze({ attach: attach });
}());
