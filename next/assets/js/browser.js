(function () {
	'use strict';

	function debounce(callback, delay) {
		let timer = 0;
		return function () {
			const args = arguments;
			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				callback.apply(null, args);
			}, delay);
		};
	}

	function initModuleBrowser(root) {
		const browser = root && root.querySelector ? root.querySelector('[data-afcn-module-browser]') : null;
		if (!browser || browser.dataset.afcnBrowserWired) {
			return;
		}

		browser.dataset.afcnBrowserWired = '1';
		const filters = Array.from(browser.querySelectorAll('[data-afcn-module-filter]'));
		const search = browser.querySelector('[data-afcn-module-search]');
		const grid = browser.querySelector('[data-afcn-module-grid]');
		const empty = browser.querySelector('[data-afcn-module-empty]');
		const loadMore = browser.querySelector('[data-afcn-module-load-more]');
		const useAjax = browser.dataset.afcnAjax === '1';
		let active = 'all';
		let page = 1;
		let requestSequence = 0;

		function setActive(button) {
			filters.forEach(function (item) {
				item.classList.toggle('is-active', item === button);
				item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
			});
		}

		function applyLocal() {
			const query = search ? search.value.trim().toLowerCase() : '';
			const cards = Array.from(browser.querySelectorAll('[data-afcn-module-card]'));
			let shown = 0;

			cards.forEach(function (card) {
				const groups = (card.dataset.afcnGroups || '').split(/\s+/);
				const haystack = (card.dataset.afcnSearch || '').toLowerCase();
				const visible = groups.includes(active) && (!query || haystack.includes(query));
				card.hidden = !visible;
				if (visible) {
					shown += 1;
				}
			});

			if (empty) {
				empty.hidden = shown > 0;
			}
		}

		async function applyRemote(append) {
			if (!window.AirfiberNext || !grid) {
				return;
			}

			const sequence = ++requestSequence;
			const query = search ? search.value.trim() : '';
			browser.classList.add('is-loading');
			browser.setAttribute('aria-busy', 'true');

			try {
				const result = await window.AirfiberNext.query('modules', 'browser', {
					filter: active,
					search: query,
					page: page
				});
				if (sequence !== requestSequence) {
					return;
				}

				if (append) {
					grid.insertAdjacentHTML('beforeend', result.html || '');
				} else {
					grid.innerHTML = result.html || '';
				}

				if (window.AirfiberNext.wire) {
					window.AirfiberNext.wire(grid);
				}
				if (empty) {
					empty.hidden = Number(result.total || 0) > 0;
				}
				if (loadMore) {
					loadMore.hidden = !result.has_more;
				}
			} catch (error) {
				if (window.AirfiberNext.toast) {
					window.AirfiberNext.toast(error.message || 'Modules could not be filtered.', true);
				}
			} finally {
				if (sequence === requestSequence) {
					browser.classList.remove('is-loading');
					browser.removeAttribute('aria-busy');
				}
			}
		}

		function apply(resetPage) {
			if (!useAjax) {
				applyLocal();
				return;
			}
			if (resetPage) {
				page = 1;
			}
			applyRemote(false);
		}

		filters.forEach(function (button) {
			button.setAttribute('aria-pressed', button.classList.contains('is-active') ? 'true' : 'false');
			button.addEventListener('click', function () {
				active = button.dataset.afcnModuleFilter || 'all';
				setActive(button);
				apply(true);
			});
		});

		if (search) {
			search.addEventListener('input', debounce(function () {
				apply(true);
			}, useAjax ? 180 : 40));
		}

		if (loadMore) {
			loadMore.addEventListener('click', function () {
				page += 1;
				applyRemote(true);
			});
		}

		browser.addEventListener('click', function (event) {
			const button = event.target.closest('[data-afcn-open-module]');
			if (!button || !window.AirfiberNext) {
				return;
			}
			const moduleId = button.dataset.afcnOpenModule;
			if (!moduleId) {
				return;
			}
			if (window.location.hash !== '#' + moduleId) {
				history.pushState(null, '', '#' + moduleId);
			}
			window.AirfiberNext.loadModule(moduleId, false);
		});

		if (!useAjax) {
			applyLocal();
		}
	}

	function initConnectionsBrowser(root) {
		const browser = root && root.querySelector ? root.querySelector('[data-afcn-connections-browser]') : null;
		if (!browser || browser.dataset.afcnBrowserWired) {
			return;
		}

		browser.dataset.afcnBrowserWired = '1';
		const filters = Array.from(browser.querySelectorAll('[data-afcn-connection-filter]'));
		const search = browser.querySelector('[data-afcn-connection-search]');
		const empty = browser.querySelector('[data-afcn-connections-empty]');
		let active = 'all';

		function apply() {
			const query = search ? search.value.trim().toLowerCase() : '';
			const cards = Array.from(browser.querySelectorAll('[data-afcn-connection-card]'));
			const groups = Array.from(browser.querySelectorAll('[data-afcn-connection-group]'));
			let shown = 0;

			cards.forEach(function (card) {
				const state = card.dataset.afcnState || 'unconfigured';
				const haystack = (card.dataset.afcnSearch || '').toLowerCase();
				const visible = (active === 'all' || state === active) && (!query || haystack.includes(query));
				card.hidden = !visible;
				if (visible) {
					shown += 1;
				}
			});

			groups.forEach(function (group) {
				group.hidden = !group.querySelector('[data-afcn-connection-card]:not([hidden])');
			});
			if (empty) {
				empty.hidden = shown > 0;
			}
		}

		filters.forEach(function (button) {
			button.setAttribute('aria-pressed', button.classList.contains('is-active') ? 'true' : 'false');
			button.addEventListener('click', function () {
				active = button.dataset.afcnConnectionFilter || 'all';
				filters.forEach(function (item) {
					item.classList.toggle('is-active', item === button);
					item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
				});
				apply();
			});
		});
		if (search) {
			search.addEventListener('input', debounce(apply, 40));
		}
		apply();
	}

	function init(root) {
		initModuleBrowser(root);
		initConnectionsBrowser(root);
	}

	document.addEventListener('afcn:module:loaded', function () {
		init(document.getElementById('afcn-module-stage') || document);
	});

	const stage = document.getElementById('afcn-module-stage');
	if (stage && window.MutationObserver) {
		new MutationObserver(function () {
			init(stage);
		}).observe(stage, { childList: true });
	}

	init(document);
}());
