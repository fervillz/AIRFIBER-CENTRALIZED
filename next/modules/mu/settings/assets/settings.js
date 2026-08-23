(function () {
	'use strict';

	function resolveRow(eventId) {
		if (!eventId) {
			return;
		}

		const root = document.querySelector('[data-afcn-performance-warnings]');
		if (!root) {
			return;
		}

		const row = root.querySelector('[data-afcn-performance-warning="' + CSS.escape(String(eventId)) + '"]');
		if (row) {
			row.remove();
		}

		const table = root.querySelector('[data-afcn-performance-table]');
		const empty = root.querySelector('[data-afcn-performance-empty]');
		const remaining = root.querySelector('[data-afcn-performance-warning]');
		if (!remaining) {
			if (table) {
				table.hidden = true;
			}
			if (empty) {
				empty.hidden = false;
			}
		}
	}

	document.addEventListener('afcn:performance-warning:resolved', function (event) {
		resolveRow(event.detail && event.detail.id ? event.detail.id : '');
	});
}());
