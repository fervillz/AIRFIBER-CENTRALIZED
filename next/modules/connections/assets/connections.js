(function () {
	'use strict';

	function wireConnectorForm(form) {
		if (form.dataset.afcnConnectorWired) {
			return;
		}
		form.dataset.afcnConnectorWired = '1';
		const select = form.querySelector('[data-afcn-connector-type]');
		if (!select) {
			return;
		}
		const panels = Array.from(form.querySelectorAll('[data-afcn-connector-fields]'));

		function sync() {
			const chosen = select.value;
			panels.forEach(function (panel) {
				const active = panel.dataset.afcnConnectorFields === chosen;
				panel.hidden = !active;
				panel.querySelectorAll('input,select,textarea').forEach(function (field) {
					field.disabled = !active;
				});
			});
		}

		select.addEventListener('change', sync);
		sync();
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
