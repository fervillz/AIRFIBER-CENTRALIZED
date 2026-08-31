(function () {
	'use strict';

	let root = null;
	let dialog = null;
	let dialogBody = null;
	let dialogTitle = null;
	let dialogSubtitle = null;
	let currentPanel = '';
	let refreshTimer = 0;
	const panelCache = new Map();

	function escapeText(value) {
		return value === undefined || value === null ? '' : String(value);
	}

	function loadingPanel() {
		const shell = document.createElement('div');
		shell.className = 'afcn-settings-dialog-loading';
		for (let i = 0; i < 4; i++) {
			const line = document.createElement('span');
			line.style.setProperty('--afcn-skeleton-width', (92 - (i * 13)) + '%');
			shell.appendChild(line);
		}
		return shell;
	}

	function errorPanel(message) {
		const alert = document.createElement('div');
		alert.className = 'afcn-alert afcn-alert-danger';
		const copy = document.createElement('div');
		copy.className = 'afcn-alert-copy';
		const text = document.createElement('p');
		text.textContent = message || 'The settings panel could not be loaded.';
		copy.appendChild(text);
		alert.appendChild(copy);
		return alert;
	}

	function setDialogHeading(title, subtitle) {
		if (dialogTitle) {
			dialogTitle.textContent = title || 'Settings';
		}
		if (dialogSubtitle) {
			dialogSubtitle.textContent = subtitle || '';
		}
	}

	async function openPanel(panel, force) {
		if (!dialog || !dialogBody || !panel) {
			return;
		}
		currentPanel = panel;
		setDialogHeading('Settings', '');
		dialogBody.replaceChildren(loadingPanel());
		if (!dialog.open) {
			window.AirfiberNext.openDialog(dialog);
		}

		if (!force && panel !== 'performance' && panelCache.has(panel)) {
			const cached = panelCache.get(panel);
			setDialogHeading(cached.title, cached.subtitle);
			dialogBody.innerHTML = cached.html || '';
			window.AirfiberNext.wire(dialog);
			return;
		}

		try {
			const data = await window.AirfiberNext.query('settings', 'panel', { panel: panel });
			if (currentPanel !== panel) {
				return;
			}
			const safe = {
				title: escapeText(data.title || 'Settings'),
				subtitle: escapeText(data.subtitle || ''),
				html: String(data.html || '')
			};
			if (panel !== 'performance') {
				panelCache.set(panel, safe);
			}
			setDialogHeading(safe.title, safe.subtitle);
			dialogBody.innerHTML = safe.html;
			window.AirfiberNext.wire(dialog);
		} catch (error) {
			setDialogHeading('Settings unavailable', '');
			dialogBody.replaceChildren(errorPanel(error.message));
		}
	}

	function performanceVariant(warning, errors) {
		if (errors > 0) {
			return 'danger';
		}
		if (warning >= 3) {
			return 'orange';
		}
		return warning > 0 ? 'warning' : '';
	}

	function updatePerformanceIndicator(warning, errors) {
		if (!root) {
			return;
		}
		const stack = root.querySelector('.afcn-settings-fix-all .afcn-indicator-stack');
		if (!stack) {
			return;
		}
		const variant = performanceVariant(warning, errors);
		let badge = stack.querySelector('.afcn-indicator-badge');

		if (!variant) {
			if (badge) {
				badge.remove();
			}
			const separator = stack.querySelector('.afcn-indicator-separator');
			if (separator) {
				separator.remove();
			}
			return;
		}

		if (!badge) {
			badge = document.createElement('span');
			badge.className = 'afcn-indicator-badge is-dot';
			stack.appendChild(badge);
		}
		badge.textContent = '';
		badge.classList.remove(
			'afcn-indicator-success',
			'afcn-indicator-warning',
			'afcn-indicator-orange',
			'afcn-indicator-danger',
			'afcn-indicator-error',
			'afcn-indicator-info',
			'afcn-indicator-neutral'
		);
		badge.classList.add('afcn-indicator-' + variant, 'is-dot');
	}

	function updateWarningStatus(data) {
		if (!root || !data) {
			return;
		}
		const warning = Number(data.warning_count || 0);
		const errors = Number(data.error_count || 0);
		const fixable = Number(data.fixable_count || 0);

		const summary = root.querySelector('[data-afcn-settings-warning-summary]');
		const fixAll = root.querySelector('[data-afcn-settings-fix-all]');

		updatePerformanceIndicator(warning, errors);

		if (summary) {
			if (errors > 0) {
				summary.textContent = 'Errors need attention.';
			} else if (warning >= 3) {
				summary.textContent = 'Several performance warnings need attention.';
			} else if (warning > 0) {
				summary.textContent = 'Performance warning needs attention.';
			} else {
				summary.textContent = 'No unresolved warnings or errors.';
			}
		}
		if (fixAll) {
			fixAll.disabled = fixable < 1;
			fixAll.setAttribute('aria-label', fixable > 0 ? 'Fix all fixable performance warnings' : 'No fixable performance warnings');
		}

		updateConsoleIndicator(data.console_level || 'success');
	}

	function updateConsoleIndicator(level) {
		if (!root) {
			return;
		}
		const badge = root.querySelector('.afcn-settings-console-indicator .afcn-indicator-badge');
		if (!badge) {
			return;
		}
		const variant = level === 'error' || level === 'danger'
			? 'danger'
			: (level === 'warning' ? 'warning' : 'success');
		badge.classList.remove('afcn-indicator-success', 'afcn-indicator-warning', 'afcn-indicator-danger', 'afcn-indicator-error', 'afcn-indicator-info', 'afcn-indicator-neutral');
		badge.classList.add('afcn-indicator-' + variant);
	}

	async function refreshStatus() {
		try {
			const data = await window.AirfiberNext.query('settings', 'status', {});
			updateWarningStatus(data || {});
			if (dialog && dialog.open && currentPanel === 'performance') {
				await openPanel('performance', true);
			}
		} catch (error) {
			// Status refresh is supplemental. Do not interrupt the operator.
		}
	}

	function scheduleStatusRefresh() {
		window.clearTimeout(refreshTimer);
		refreshTimer = window.setTimeout(refreshStatus, 260);
	}

	function init() {
		root = document.querySelector('[data-afcn-settings]');
		if (!root || root.dataset.afcnSettingsWired) {
			return;
		}
		root.dataset.afcnSettingsWired = '1';
		dialog = document.getElementById('afcn-settings-dialog');
		dialogBody = dialog ? dialog.querySelector('[data-afcn-settings-dialog-body]') : null;
		dialogTitle = dialog ? dialog.querySelector('[data-afcn-settings-dialog-title]') : null;
		dialogSubtitle = dialog ? dialog.querySelector('[data-afcn-settings-dialog-subtitle]') : null;

		root.addEventListener('click', function (event) {
			const panel = event.target.closest('[data-afcn-settings-panel]');
			if (!panel) {
				return;
			}
			event.preventDefault();
			openPanel(panel.dataset.afcnSettingsPanel || '', false);
		});
	}

	document.addEventListener('afcn:performance-warning:resolved', scheduleStatusRefresh);
	document.addEventListener('afcn:settings:status:refresh', function (event) {
		window.clearTimeout(refreshTimer);
		const data = event.detail || {};
		if (Object.prototype.hasOwnProperty.call(data, 'warning_count')) {
			updateWarningStatus(data);
			if (dialog && dialog.open && currentPanel === 'performance') {
				openPanel('performance', true);
			}
			return;
		}
		scheduleStatusRefresh();
	});
	document.addEventListener('afcn:console:status', function (event) {
		updateConsoleIndicator(event.detail && event.detail.level ? event.detail.level : 'success');
	});
	document.addEventListener('afcn:module:loaded', function (event) {
		const detail = event.detail || {};
		if ((detail.id || detail.module) === 'settings') {
			init();
		}
	});

	init();
}());
