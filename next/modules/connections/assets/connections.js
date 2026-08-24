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

	function init(root) {
		const forms = root && root.querySelectorAll ? root.querySelectorAll('[data-afcn-connection-form]') : [];
		forms.forEach(wireConnectorForm);
	}

	document.addEventListener('afcn:module:loaded', function (event) {
		if (event.detail && event.detail.id === 'connections') {
			init(document.getElementById('afcn-module-stage') || document);
		}
	});

	init(document);
}());
