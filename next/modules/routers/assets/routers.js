(function () {
	'use strict';

	function stage() {
		return document.getElementById('afcn-module-stage') || document;
	}

	function routerRoot() {
		return stage().querySelector('[data-afcn-routers-root]');
	}

	function setNavigationActive(connectionId) {
		document.querySelectorAll('[data-afcn-module="routers"]').forEach(function (button) {
			const buttonContext = button.dataset.afcnModuleContext || '';
			const submenu = Boolean(button.closest('.afcn-nav-submenu'));
			const active = !submenu || (connectionId && buttonContext === connectionId);
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	}

	function scheduleDefaultScope(detail) {
		if (!detail || detail.dataset.afcnDefaultScopeScheduled) {
			return;
		}
		const button = detail.querySelector('[data-afcn-router-scope-load][data-afcn-scope="interfaces"]');
		if (!button || button.dataset.afcnScopeLoaded) {
			return;
		}
		detail.dataset.afcnDefaultScopeScheduled = '1';

		const run = function () {
			if (detail.hidden) {
				delete detail.dataset.afcnDefaultScopeScheduled;
				return;
			}
			loadScope(button, { page: 1, search: '', refresh: false, auto: true });
		};

		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				if ('requestIdleCallback' in window) {
					window.requestIdleCallback(run, { timeout: 700 });
				} else {
					window.setTimeout(run, 160);
				}
			});
		});
	}

	function selectRouter(connectionId, updateHistory) {
		const root = routerRoot();
		if (!root) {
			return;
		}

		const browser = root.querySelector('[data-afcn-router-browser]');
		const browserHead = root.querySelector('[data-afcn-router-browser-head]');
		let selected = false;
		let selectedDetail = null;
		root.querySelectorAll('[data-afcn-router-detail]').forEach(function (detail) {
			const active = Boolean(connectionId) && detail.dataset.afcnRouterDetail === connectionId;
			detail.hidden = !active;
			if (active) {
				selectedDetail = detail;
			}
			selected = selected || active;
		});
		if (browser) {
			browser.hidden = selected;
		}
		if (browserHead) {
			browserHead.hidden = selected;
		}
		if (selected && window.AirfiberCardOrder && typeof window.AirfiberCardOrder.wire === 'function') {
			window.AirfiberCardOrder.wire();
		}
		if (selectedDetail) {
			scheduleDefaultScope(selectedDetail);
		}

		const context = selected ? connectionId : '';
		setNavigationActive(context);
		if (updateHistory) {
			const hash = '#routers' + (context ? '/' + encodeURIComponent(context) : '');
			if (window.location.hash !== hash) {
				history.pushState(null, '', hash);
			}
		}
	}

	function syncReadAll(form) {
		const master = form.querySelector('[data-afcn-router-read-all]');
		if (!master) {
			return;
		}
		form.querySelectorAll('[data-afcn-router-scope-option]').forEach(function (option) {
			if (master.checked) {
				option.checked = true;
			}
			option.disabled = master.checked;
		});
	}

	function wireRouterForm(form) {
		if (form.dataset.afcnRouterFormWired) {
			return;
		}
		form.dataset.afcnRouterFormWired = '1';
		const master = form.querySelector('[data-afcn-router-read-all]');
		if (master) {
			master.addEventListener('change', function () {
				syncReadAll(form);
			});
		}
		syncReadAll(form);
	}

	function appendCell(row, tag, value) {
		const cell = document.createElement(tag);
		cell.textContent = value === undefined || value === null || value === '' ? '—' : String(value);
		row.appendChild(cell);
	}

	function renderScope(output, result, button, options) {
		options = options || {};
		output.replaceChildren();

		const pagination = result.pagination || {};
		const rows = Array.isArray(result.rows) ? result.rows : [];
		const page = Number(pagination.page || 1);
		const pages = Number(pagination.pages || 1);
		const total = Number(pagination.total || 0);
		const from = Number(pagination.from || 0);
		const to = Number(pagination.to || 0);
		const searchValue = String(result.search || options.search || '');
		const totalLabel = String(total) + (result.truncated ? '+' : '');

		const browser = document.createElement('div');
		browser.className = 'afcn-data-browser';

		const toolbar = document.createElement('div');
		toolbar.className = 'afcn-data-toolbar';

		const summary = document.createElement('div');
		summary.className = 'afcn-data-summary';
		let summaryText = total ? (from + '–' + to + ' of ' + totalLabel) : '0 rows';
		if (result.cache_hit) {
			summaryText += ' · cached';
		} else {
			summaryText += ' · ' + Number(result.latency_ms || 0).toFixed(0) + ' ms';
		}
		summary.textContent = summaryText;

		const search = document.createElement('label');
		search.className = 'afcn-data-search';
		const searchInput = document.createElement('input');
		searchInput.type = 'search';
		searchInput.value = searchValue;
		searchInput.placeholder = 'Search ' + (result.label || 'rows') + '…';
		searchInput.setAttribute('aria-label', 'Search ' + (result.label || 'router data'));
		search.appendChild(searchInput);

		toolbar.appendChild(summary);
		toolbar.appendChild(search);
		browser.appendChild(toolbar);

		let searchTimer = 0;
		searchInput.addEventListener('input', function () {
			window.clearTimeout(searchTimer);
			const value = searchInput.value;
			searchTimer = window.setTimeout(function () {
				loadScope(button, { page: 1, search: value, refresh: false, source: 'search', focusSearch: true });
			}, 220);
		});

		if (!rows.length) {
			const empty = document.createElement('div');
			empty.className = 'afcn-data-empty';
			empty.textContent = searchValue ? 'No rows match this search.' : 'The router returned no rows for this scope.';
			browser.appendChild(empty);
		} else {
			const wrap = document.createElement('div');
			wrap.className = 'afcn-data-table-wrap';
			const table = document.createElement('table');
			table.className = 'afcn-data-table';
			const head = document.createElement('thead');
			const headRow = document.createElement('tr');
			(result.columns || []).forEach(function (column) {
				appendCell(headRow, 'th', column.label || column.key);
			});
			head.appendChild(headRow);
			table.appendChild(head);

			const body = document.createElement('tbody');
			rows.forEach(function (item) {
				const row = document.createElement('tr');
				(result.columns || []).forEach(function (column) {
					appendCell(row, 'td', item[column.key]);
				});
				body.appendChild(row);
			});
			table.appendChild(body);
			wrap.appendChild(table);
			browser.appendChild(wrap);
		}

		const pager = document.createElement('div');
		pager.className = 'afcn-data-pagination';

		const previous = document.createElement('button');
		previous.type = 'button';
		previous.className = 'afcn-button afcn-button-secondary afcn-button-small';
		previous.textContent = 'Previous';
		previous.disabled = page <= 1;
		previous.addEventListener('click', function () {
			loadScope(button, { page: page - 1, search: searchValue, refresh: false, source: 'page' });
		});

		const pageStatus = document.createElement('span');
		pageStatus.className = 'afcn-data-page-status';
		pageStatus.textContent = 'Page ' + page + ' of ' + pages;

		const next = document.createElement('button');
		next.type = 'button';
		next.className = 'afcn-button afcn-button-secondary afcn-button-small';
		next.textContent = 'Next';
		next.disabled = page >= pages;
		next.addEventListener('click', function () {
			loadScope(button, { page: page + 1, search: searchValue, refresh: false, source: 'page' });
		});

		pager.appendChild(previous);
		pager.appendChild(pageStatus);
		pager.appendChild(next);
		browser.appendChild(pager);
		output.appendChild(browser);

		if (options.focusSearch) {
			window.setTimeout(function () {
				searchInput.focus();
				searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
			}, 0);
		}
	}

	async function loadScope(button, options) {
		options = options || {};
		if (!window.AirfiberNext || typeof window.AirfiberNext.query !== 'function') {
			return;
		}
		const detail = button.closest('[data-afcn-router-detail]');
		const results = detail ? detail.querySelector('[data-afcn-router-scope-results]') : null;
		const output = results ? results.querySelector('[data-afcn-router-scope-output]') : null;
		const title = results ? results.querySelector('[data-afcn-router-scope-result-title]') : null;
		if (!output || !results) {
			return;
		}

		const page = Math.max(1, Number(options.page || 1));
		const search = String(options.search || '');
		const refresh = Boolean(options.refresh);
		const source = options.source || (options.auto ? 'auto' : 'button');
		const requestId = String((Number(results.dataset.afcnScopeRequestId || 0) + 1));
		results.dataset.afcnScopeRequestId = requestId;

		if (title) {
			title.textContent = button.dataset.afcnScopeLabel || 'Router details';
		}
		results.hidden = false;
		results.classList.add('is-loading');
		results.setAttribute('aria-busy', 'true');

		const status = window.AirfiberNext.status || window.AirfiberUIStatus;
		const showButtonStatus = source === 'button' || source === 'auto';
		if (showButtonStatus) {
			button.disabled = true;
			if (status) {
				status.loading(button, refresh ? 'Refreshing this scope from RouterOS…' : 'Loading this scope…', { alert: false });
			}
		}

		try {
			const result = await window.AirfiberNext.query('routers', 'scope', {
				connection_id: button.dataset.afcnConnectionId || '',
				scope: button.dataset.afcnScope || '',
				page: page,
				search: search,
				refresh: refresh ? 1 : 0
			});
			if (results.dataset.afcnScopeRequestId !== requestId) {
				return;
			}
			renderScope(output, result || {}, button, options);
			button.dataset.afcnScopeLoaded = '1';
			button.textContent = 'Refresh';
			if (showButtonStatus && status) {
				status.success(button, result && result.cache_hit ? 'Cached router data loaded.' : 'Router data loaded.', { alert: false, transient: true, delay: 1400 });
			}
		} catch (error) {
			if (results.dataset.afcnScopeRequestId !== requestId) {
				return;
			}
			output.replaceChildren();
			const notice = document.createElement('div');
			notice.className = 'afcn-notice afcn-notice-danger';
			notice.textContent = error.message || 'Router data could not be loaded.';
			output.appendChild(notice);
			if (showButtonStatus && status) {
				status.error(button, notice.textContent, { alert: false });
			}
			if (!options.auto) {
				window.AirfiberNext.toast(notice.textContent, true);
			}
		} finally {
			if (results.dataset.afcnScopeRequestId === requestId) {
				results.classList.remove('is-loading');
				results.removeAttribute('aria-busy');
			}
			if (showButtonStatus) {
				button.disabled = false;
			}
		}
	}

	function text(node) {
		return node ? String(node.textContent || '').replace(/\s+/g, ' ').trim() : '';
	}

	function wireRouterSelectButtons(root) {
		root.querySelectorAll('[data-afcn-router-select]').forEach(function (button) {
			if (button.dataset.afcnRouterWired) {
				return;
			}
			button.dataset.afcnRouterWired = '1';
			button.addEventListener('click', function () {
				selectRouter(button.dataset.afcnRouterSelect || '', true);
			});
		});
	}

	function ensureRouterList(browser) {
		let list = browser.querySelector('[data-afcn-router-list]');
		if (list) {
			return list;
		}

		list = document.createElement('div');
		list.className = 'afcn-connections-list';
		list.dataset.afcnRouterList = '1';
		list.hidden = true;
		list.innerHTML = '<div class="afcn-table-wrap"><table class="afcn-table afcn-connections-table"><thead><tr><th>Name</th><th>Provider</th><th>Endpoint</th><th>Scopes</th><th>Status</th><th>Actions</th></tr></thead><tbody></tbody></table></div>';

		const empty = browser.querySelector('[data-afcn-connections-empty]');
		if (empty) {
			browser.insertBefore(list, empty);
		} else {
			browser.appendChild(list);
		}
		return list;
	}

	function routerStatusContent(card) {
		const trigger = card.querySelector('.afcn-connection-card-bottom .afcn-tooltip-trigger');
		if (!trigger) {
			return document.createTextNode('—');
		}
		const clone = trigger.cloneNode(true);
		clone.classList.add('afcn-connection-list-status');
		return clone;
	}

	function buildRouterList(browser, list) {
		const tbody = list.querySelector('tbody');
		if (!tbody) {
			return;
		}

		tbody.innerHTML = '';
		browser.querySelectorAll('[data-afcn-router-card]').forEach(function (card) {
			const row = document.createElement('tr');
			const name = text(card.querySelector('h3')) || 'Router';
			const provider = text(card.querySelector('.afcn-connection-provider')) || 'MikroTik RouterOS';
			const endpoint = text(card.querySelector('.afcn-connection-subtitle')) || '—';
			const scopes = text(card.querySelector('.afcn-connection-meta')) || '—';
			const actions = card.querySelector('.afcn-connection-actions');

			row.hidden = card.hidden;
			row.__afcnSourceCard = card;

			const nameCell = document.createElement('td');
			nameCell.className = 'afcn-connection-list-name';
			const strong = document.createElement('strong');
			strong.textContent = name;
			nameCell.appendChild(strong);
			row.appendChild(nameCell);

			[provider, endpoint, scopes].forEach(function (value) {
				const cell = document.createElement('td');
				cell.textContent = value;
				row.appendChild(cell);
			});

			const statusCell = document.createElement('td');
			statusCell.appendChild(routerStatusContent(card));
			row.appendChild(statusCell);

			const actionCell = document.createElement('td');
			if (actions) {
				actionCell.appendChild(actions.cloneNode(true));
				actionCell.querySelectorAll('[data-afcn-router-select]').forEach(function (button) {
					delete button.dataset.afcnRouterWired;
				});
			}
			row.appendChild(actionCell);
			tbody.appendChild(row);
		});

		wireRouterSelectButtons(list);
		if (window.AirfiberNext && typeof window.AirfiberNext.wire === 'function') {
			window.AirfiberNext.wire(list);
		}
	}

	function syncRouterList(list) {
		list.querySelectorAll('tbody tr').forEach(function (row) {
			if (row.__afcnSourceCard) {
				row.hidden = row.__afcnSourceCard.hidden;
			}
		});
	}

	function wireRouterBrowser(root) {
		const browser = root.querySelector('[data-afcn-router-browser]');
		if (!browser || browser.dataset.afcnRouterViewWired || !window.AirfiberViewMode) {
			return;
		}
		browser.dataset.afcnRouterViewWired = '1';

		const cards = browser.querySelector('[data-afcn-router-card-view]');
		const list = ensureRouterList(browser);
		if (!cards) {
			return;
		}

		const controller = window.AirfiberViewMode.attach(root, {
			key: 'routers',
			cards: cards,
			list: list,
			title: '.afcn-page-title',
			beforeList: function () {
				buildRouterList(browser, list);
			}
		});
		if (!controller) {
			return;
		}

		browser.addEventListener('click', function (event) {
			if (event.target.closest('[data-afcn-connection-filter]')) {
				window.setTimeout(function () {
					syncRouterList(list);
				}, 0);
			}
		});

		const search = browser.querySelector('[data-afcn-connection-search]');
		if (search) {
			search.addEventListener('input', function () {
				window.setTimeout(function () {
					syncRouterList(list);
				}, 70);
			});
		}
	}


	function init(root) {
		root = root && root.querySelectorAll ? root : stage();
		const current = root.querySelector('[data-afcn-routers-root]') || routerRoot();
		if (!current) {
			return;
		}

		current.querySelectorAll('[data-afcn-router-form]').forEach(wireRouterForm);
		wireRouterSelectButtons(current);
		wireRouterBrowser(current);
		current.querySelectorAll('[data-afcn-router-scope-load]').forEach(function (button) {
			if (button.dataset.afcnRouterWired) {
				return;
			}
			button.dataset.afcnRouterWired = '1';
			button.addEventListener('click', function () {
				loadScope(button, { page: 1, search: '', refresh: true, source: 'button' });
			});
		});
	}

	document.addEventListener('afcn:module:loaded', function (event) {
		if (event.detail && event.detail.id === 'routers') {
			init(stage());
		}
	});

	document.addEventListener('afcn:navigation:context', function (event) {
		if (event.detail && event.detail.module === 'routers') {
			selectRouter(String(event.detail.context || ''), false);
		}
	});

	window.addEventListener('popstate', function () {
		if (window.location.hash.indexOf('#routers') === 0) {
			const raw = window.location.hash.replace(/^#routers\/?/, '');
			let connectionId = '';
			try {
				connectionId = decodeURIComponent(raw);
			} catch (error) {
				connectionId = '';
			}
			selectRouter(connectionId, false);
		}
	});

	init(document);
}());
