(function () {
	'use strict';

	function statusManager() {
		return window.AirfiberNext && window.AirfiberNext.status ? window.AirfiberNext.status : window.AirfiberUIStatus;
	}

	function fieldValue(form, name) {
		const field = form.elements.namedItem(name);
		if (!field) {
			return '';
		}
		return String(field.value || '');
	}

	function syncConditionalFields(form) {
		form.querySelectorAll('[data-afcn-show-when-field]').forEach(function (wrapper) {
			const controller = wrapper.dataset.afcnShowWhenField || '';
			const expected = wrapper.dataset.afcnShowWhenValue || '';
			const visible = fieldValue(form, controller) === expected;
			const panel = wrapper.closest('[data-afcn-connector-fields]');
			const panelActive = !panel || !panel.hidden;

			wrapper.hidden = !visible;
			wrapper.querySelectorAll('input,select,textarea').forEach(function (field) {
				field.disabled = !visible || !panelActive;
			});
		});
	}

	function activePanel(form) {
		const select = form.querySelector('[data-afcn-connector-type]');
		const panels = Array.from(form.querySelectorAll('[data-afcn-connector-fields]'));
		if (!select) {
			return panels.length ? panels[0] : null;
		}
		return panels.find(function (panel) {
			return panel.dataset.afcnConnectorFields === select.value;
		}) || null;
	}

	function syncConnectorPanels(form) {
		const select = form.querySelector('[data-afcn-connector-type]');
		if (!select) {
			syncConditionalFields(form);
			return;
		}

		form.querySelectorAll('[data-afcn-connector-fields]').forEach(function (panel) {
			const active = panel.dataset.afcnConnectorFields === select.value;
			panel.hidden = !active;
			panel.querySelectorAll('input,select,textarea').forEach(function (field) {
				field.disabled = !active;
			});
		});
		syncConditionalFields(form);
	}

	function resetProbe(form) {
		const button = form.querySelector('[data-afcn-connection-probe]');
		if (!button || button.dataset.afcnProbing === '1') {
			return;
		}
		button.dataset.afcnConnected = '0';
		button.textContent = 'Connect';
		const status = statusManager();
		if (status) {
			status.clear(button);
		}
	}

	function syncProbeAvailability(form) {
		const button = form.querySelector('[data-afcn-connection-probe]');
		if (!button || button.dataset.afcnProbing === '1') {
			return;
		}
		const panel = activePanel(form);
		const available = !!panel && panel.dataset.afcnConnectorTestable === '1';
		button.disabled = !available;

		const status = statusManager();
		if (!status) {
			return;
		}
		if (!available) {
			status.set(button, 'disabled', 'Choose a connection type that supports testing.');
			return;
		}
		if (button.dataset.afcnConnected === '1') {
			status.success(button, 'Connected.', { alert: false });
		} else if (button.dataset.afcnStatus === 'disabled') {
			status.clear(button, { alert: false });
		}
	}

	function payloadFrom(form) {
		const payload = {};
		new FormData(form).forEach(function (value, key) {
			payload[key] = value;
		});
		return payload;
	}

	async function probe(form, button) {
		if (!window.AirfiberNext || typeof window.AirfiberNext.action !== 'function') {
			return;
		}

		const status = statusManager();
		button.dataset.afcnProbing = '1';
		button.disabled = true;
		button.textContent = 'Connecting…';
		if (status) {
			status.loading(button, 'Connecting to the OLT…');
		}

		try {
			const result = await window.AirfiberNext.action('connections', 'probe-connection', payloadFrom(form));
			const message = (result && result.message) || 'Connection succeeded.';
			button.dataset.afcnConnected = '1';
			button.textContent = 'Connected';
			if (status) {
				status.success(button, message);
			}
			window.AirfiberNext.toast(message, false);
		} catch (error) {
			const message = error.message || 'Connection failed.';
			button.dataset.afcnConnected = '0';
			button.textContent = 'Connect';
			if (status) {
				status.error(button, message);
			}
			window.AirfiberNext.toast(message, true);
		} finally {
			button.dataset.afcnProbing = '0';
			syncProbeAvailability(form);
		}
	}

	function wireConnectorForm(form) {
		if (form.dataset.afcnConnectorWired) {
			return;
		}
		form.dataset.afcnConnectorWired = '1';

		const typeSelect = form.querySelector('[data-afcn-connector-type]');
		const probeButton = form.querySelector('[data-afcn-connection-probe]');

		form.addEventListener('change', function () {
			syncConnectorPanels(form);
			resetProbe(form);
			syncProbeAvailability(form);
		});
		form.addEventListener('input', function (event) {
			if (probeButton && !probeButton.contains(event.target)) {
				resetProbe(form);
			}
		});

		if (typeSelect) {
			typeSelect.addEventListener('change', function () {
				syncConnectorPanels(form);
			});
		}

		if (probeButton) {
			probeButton.addEventListener('click', function () {
				probe(form, probeButton);
			});
		}

		syncConnectorPanels(form);
		syncProbeAvailability(form);
	}

	function text(node) {
		return node ? String(node.textContent || '').replace(/\s+/g, ' ').trim() : '';
	}

	function ensureConnectionsList(browser) {
		let list = browser.querySelector('[data-afcn-connections-list]');
		if (list) {
			return list;
		}

		list = document.createElement('div');
		list.className = 'afcn-connections-list';
		list.dataset.afcnConnectionsList = '1';
		list.hidden = true;
		list.innerHTML = '<div class="afcn-table-wrap"><table class="afcn-table afcn-connections-table"><thead><tr><th>Name</th><th>Provider</th><th>Endpoint</th><th>Group</th><th>Status</th><th>Source</th><th>Actions</th></tr></thead><tbody></tbody></table></div>';

		const empty = browser.querySelector('[data-afcn-connections-empty]');
		if (empty) {
			browser.insertBefore(list, empty);
		} else {
			browser.appendChild(list);
		}
		return list;
	}

	function sourceLabel(card) {
		return card.querySelector('.afcn-connection-source') ? 'CLASSIC' : 'BETA';
	}

	function groupLabel(card) {
		const group = card.closest('[data-afcn-connection-group]');
		return text(group ? group.querySelector('.afcn-connection-group-heading h2') : null);
	}

	function statusContent(card) {
		const trigger = card.querySelector('.afcn-connection-card-bottom .afcn-tooltip-trigger');
		if (!trigger) {
			return document.createTextNode('—');
		}
		const clone = trigger.cloneNode(true);
		clone.classList.add('afcn-connection-list-status');
		return clone;
	}

	function buildConnectionsList(browser, list) {
		const tbody = list.querySelector('tbody');
		if (!tbody) {
			return;
		}

		tbody.innerHTML = '';
		browser.querySelectorAll('[data-afcn-connection-card]').forEach(function (card) {
			const row = document.createElement('tr');
			const name = text(card.querySelector('h3')) || 'Connection';
			const provider = text(card.querySelector('.afcn-connection-provider')) || '—';
			const endpoint = text(card.querySelector('.afcn-connection-subtitle')) || '—';
			const meta = text(card.querySelector('.afcn-connection-meta'));
			const actions = card.querySelector('.afcn-connection-actions');

			row.hidden = card.hidden;
			row.__afcnSourceCard = card;

			const nameCell = document.createElement('td');
			nameCell.className = 'afcn-connection-list-name';
			const strong = document.createElement('strong');
			strong.textContent = name;
			nameCell.appendChild(strong);
			if (meta) {
				const small = document.createElement('small');
				small.textContent = meta;
				nameCell.appendChild(small);
			}
			row.appendChild(nameCell);

			[provider, endpoint, groupLabel(card)].forEach(function (value) {
				const cell = document.createElement('td');
				cell.textContent = value || '—';
				row.appendChild(cell);
			});

			const statusCell = document.createElement('td');
			statusCell.appendChild(statusContent(card));
			row.appendChild(statusCell);

			const sourceCell = document.createElement('td');
			const source = document.createElement('span');
			source.className = sourceLabel(card) === 'CLASSIC' ? 'afcn-connection-source' : 'afcn-connection-provider';
			source.textContent = sourceLabel(card);
			sourceCell.appendChild(source);
			row.appendChild(sourceCell);

			const actionCell = document.createElement('td');
			if (actions) {
				actionCell.appendChild(actions.cloneNode(true));
			}
			row.appendChild(actionCell);
			tbody.appendChild(row);
		});

		if (window.AirfiberNext && typeof window.AirfiberNext.wire === 'function') {
			window.AirfiberNext.wire(list);
		}
	}

	function syncConnectionsList(list) {
		list.querySelectorAll('tbody tr').forEach(function (row) {
			if (row.__afcnSourceCard) {
				row.hidden = row.__afcnSourceCard.hidden;
			}
		});
	}

	function wireConnectionsView(root) {
		const browser = root.querySelector('[data-afcn-connections-browser]');
		if (!browser || browser.dataset.afcnViewWired || !window.AirfiberViewMode) {
			return;
		}
		browser.dataset.afcnViewWired = '1';
		const list = ensureConnectionsList(browser);
		const groups = browser.querySelector('.afcn-connection-groups');
		if (!groups) {
			return;
		}

		const controller = window.AirfiberViewMode.attach(root, {
			key: 'connections',
			cards: groups,
			list: list,
			title: '.afcn-page-title',
			beforeList: function () {
				buildConnectionsList(browser, list);
			}
		});

		if (!controller) {
			return;
		}

		browser.addEventListener('click', function (event) {
			if (event.target.closest('[data-afcn-connection-filter]')) {
				window.setTimeout(function () {
					syncConnectionsList(list);
				}, 0);
			}
		});

		const search = browser.querySelector('[data-afcn-connection-search]');
		if (search) {
			search.addEventListener('input', function () {
				window.setTimeout(function () {
					syncConnectionsList(list);
				}, 70);
			});
		}
	}

	function init(root) {
		if (!root || !root.querySelectorAll) {
			return;
		}
		root.querySelectorAll('[data-afcn-connection-form]').forEach(wireConnectorForm);
		wireConnectionsView(root);
	}

	document.addEventListener('afcn:module:loaded', function (event) {
		if (event.detail && event.detail.id === 'connections') {
			init(document.getElementById('afcn-module-stage') || document);
		}
	});

	init(document);
}());
