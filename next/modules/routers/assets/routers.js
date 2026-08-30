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

	function selectRouter(connectionId, updateHistory) {
		const root = routerRoot();
		if (!root) {
			return;
		}

		const browser = root.querySelector('[data-afcn-router-browser]');
		const browserHead = root.querySelector('[data-afcn-router-browser-head]');
		let selected = false;
		root.querySelectorAll('[data-afcn-router-detail]').forEach(function (detail) {
			const active = Boolean(connectionId) && detail.dataset.afcnRouterDetail === connectionId;
			detail.hidden = !active;
			selected = selected || active;
		});
		if (browser) {
			browser.hidden = selected;
		}
		if (browserHead) {
			browserHead.hidden = selected;
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

	function renderScope(output, result) {
		output.replaceChildren();

		const meta = document.createElement('p');
		meta.className = 'afcn-page-description';
		const count = Array.isArray(result.rows) ? result.rows.length : 0;
		meta.textContent = count + (count === 1 ? ' row' : ' rows') + ' · ' + Number(result.latency_ms || 0).toFixed(0) + ' ms' + (result.truncated ? ' · bounded result' : '');
		output.appendChild(meta);

		if (!count) {
			const empty = document.createElement('div');
			empty.className = 'afcn-notice';
			empty.textContent = 'The router returned no rows for this scope.';
			output.appendChild(empty);
			return;
		}

		const wrap = document.createElement('div');
		wrap.className = 'afcn-table-wrap';
		const table = document.createElement('table');
		table.className = 'afcn-table';
		const head = document.createElement('thead');
		const headRow = document.createElement('tr');
		(result.columns || []).forEach(function (column) {
			appendCell(headRow, 'th', column.label || column.key);
		});
		head.appendChild(headRow);
		table.appendChild(head);

		const body = document.createElement('tbody');
		result.rows.forEach(function (item) {
			const row = document.createElement('tr');
			(result.columns || []).forEach(function (column) {
				appendCell(row, 'td', item[column.key]);
			});
			body.appendChild(row);
		});
		table.appendChild(body);
		wrap.appendChild(table);
		output.appendChild(wrap);
	}

	async function loadScope(button) {
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
		if (title) {
			title.textContent = button.dataset.afcnScopeLabel || 'Router details';
		}
		results.hidden = false;
		const status = window.AirfiberNext.status || window.AirfiberUIStatus;
		button.disabled = true;
		if (status) {
			status.loading(button, 'Reading this scope from RouterOS…', { alert: false });
		}

		try {
			const result = await window.AirfiberNext.query('routers', 'scope', {
				connection_id: button.dataset.afcnConnectionId || '',
				scope: button.dataset.afcnScope || ''
			});
			renderScope(output, result || {});
			if (status) {
				status.success(button, 'Router data loaded.', { alert: false, transient: true, delay: 1600 });
			}
		} catch (error) {
			output.replaceChildren();
			const notice = document.createElement('div');
			notice.className = 'afcn-notice afcn-notice-danger';
			notice.textContent = error.message || 'Router data could not be loaded.';
			output.appendChild(notice);
			if (status) {
				status.error(button, notice.textContent, { alert: false });
			}
			window.AirfiberNext.toast(notice.textContent, true);
		} finally {
			button.disabled = false;
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
				loadScope(button);
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
