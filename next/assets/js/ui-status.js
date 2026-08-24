(function () {
	'use strict';

	const STATUS_CLASSES = ['loading', 'success', 'warning', 'error', 'disabled'];
	const transientTimers = new WeakMap();
	const dialogBaselines = new WeakMap();

	function normalizeStatus(status) {
		status = String(status || '').toLowerCase();
		return STATUS_CLASSES.includes(status) ? status : '';
	}

	function tooltipVariant(status) {
		if (status === 'success') {
			return 'success';
		}
		if (status === 'error') {
			return 'danger';
		}
		if (status === 'disabled') {
			return 'light';
		}
		return 'info';
	}

	function statusIcon(status) {
		if (status === 'success') {
			return '✓';
		}
		if (status === 'warning') {
			return '⚠';
		}
		if (status === 'error') {
			return '!';
		}
		if (status === 'disabled') {
			return '–';
		}
		return '';
	}

	function tooltipDirection(button) {
		return button.closest('.afcn-header,.afcn-dialog-header') ? 'down' : 'up';
	}

	function ensureStatusTooltip(button) {
		let tooltip = button.querySelector(':scope > [data-afcn-button-status-tooltip]');
		if (tooltip) {
			return tooltip;
		}

		tooltip = document.createElement('span');
		tooltip.dataset.afcnButtonStatusTooltip = '1';
		tooltip.setAttribute('aria-hidden', 'true');

		const trigger = document.createElement('span');
		trigger.className = 'afcn-tooltip-trigger';

		const icon = document.createElement('span');
		icon.className = 'afcn-button-status-icon';
		icon.setAttribute('aria-hidden', 'true');
		trigger.appendChild(icon);

		const panel = document.createElement('span');
		panel.className = 'afcn-tooltip-panel';
		panel.setAttribute('role', 'tooltip');

		const text = document.createElement('span');
		text.className = 'afcn-tooltip-text';
		panel.appendChild(text);

		tooltip.appendChild(trigger);
		tooltip.appendChild(panel);
		button.appendChild(tooltip);
		return tooltip;
	}

	function clearTimer(button) {
		const timer = transientTimers.get(button);
		if (timer) {
			window.clearTimeout(timer);
			transientTimers.delete(button);
		}
	}

	function setButtonState(button, status, message, options) {
		if (!button || button.tagName !== 'BUTTON') {
			return;
		}

		status = normalizeStatus(status);
		options = options || {};
		clearTimer(button);
		STATUS_CLASSES.forEach(function (item) {
			button.classList.remove('is-afcn-' + item);
		});

		if (!status) {
			button.removeAttribute('data-afcn-status');
			button.removeAttribute('aria-busy');
			const existing = button.querySelector(':scope > [data-afcn-button-status-tooltip]');
			if (existing) {
				existing.remove();
			}
			return;
		}

		button.dataset.afcnStatus = status;
		button.classList.add('is-afcn-' + status);
		if (status === 'loading') {
			button.setAttribute('aria-busy', 'true');
		} else {
			button.removeAttribute('aria-busy');
		}

		const tooltip = ensureStatusTooltip(button);
		const direction = tooltipDirection(button);
		const variant = tooltipVariant(status);
		tooltip.className = 'afcn-tooltip afcn-tooltip-' + direction + ' afcn-tooltip-' + variant + ' afcn-button-status-tooltip is-' + status;
		tooltip.querySelector('.afcn-tooltip-text').textContent = String(message || defaultMessage(status));
		const icon = tooltip.querySelector('.afcn-button-status-icon');
		icon.textContent = statusIcon(status);
		icon.classList.toggle('is-spinner', status === 'loading');

		if (options.transient) {
			const delay = Number(options.delay || 1800);
			transientTimers.set(button, window.setTimeout(function () {
				if (button.dataset.afcnStatus === status) {
					setButtonState(button, '', '');
					if (options.alert !== false) {
						clearModalAlert(button);
					}
				}
			}, delay));
		}
	}

	function defaultMessage(status) {
		switch (status) {
			case 'loading': return 'Currently saving, kindly wait.';
			case 'success': return 'Completed successfully.';
			case 'warning': return 'This action needs attention.';
			case 'error': return 'The action could not be completed.';
			case 'disabled': return 'This action is currently unavailable.';
			default: return '';
		}
	}

	function dialogFrom(target) {
		if (!target || !target.closest) {
			return null;
		}
		return target.closest('dialog.afcn-dialog');
	}

	function dialogCancelButtons(dialog) {
		return dialog ? Array.from(dialog.querySelectorAll('.afcn-dialog-footer [data-afcn-dialog-close]')) : [];
	}

	function dialogFormSnapshot(dialog) {
		if (!dialog) {
			return '[]';
		}

		const controls = Array.from(dialog.querySelectorAll('input,select,textarea')).filter(function (control) {
			const type = String(control.type || '').toLowerCase();
			return !['hidden', 'button', 'submit', 'reset'].includes(type);
		});

		return JSON.stringify(controls.map(function (control, index) {
			let value;
			const type = String(control.type || '').toLowerCase();

			if (type === 'checkbox' || type === 'radio') {
				value = [control.checked ? 1 : 0, String(control.value || '')];
			} else if (control.tagName === 'SELECT' && control.multiple) {
				value = Array.from(control.options).filter(function (option) {
					return option.selected;
				}).map(function (option) {
					return String(option.value || '');
				});
			} else {
				value = String(control.value || '');
			}

			return [index, control.name || control.id || '', type || control.tagName.toLowerCase(), value];
		}));
	}

	function setDialogCancelVisible(dialog, visible) {
		dialogCancelButtons(dialog).forEach(function (button) {
			button.hidden = !visible;
			button.setAttribute('aria-hidden', visible ? 'false' : 'true');
		});
	}

	function snapshotDialog(dialog) {
		if (!dialog) {
			return;
		}
		dialogBaselines.set(dialog, dialogFormSnapshot(dialog));
		dialog.dataset.afcnDialogDirty = '0';
		setDialogCancelVisible(dialog, false);
	}

	function syncDialogDirty(dialog) {
		if (!dialog || !dialogBaselines.has(dialog)) {
			return;
		}
		const dirty = dialogFormSnapshot(dialog) !== dialogBaselines.get(dialog);
		dialog.dataset.afcnDialogDirty = dirty ? '1' : '0';
		setDialogCancelVisible(dialog, dirty);
	}

	function scheduleDialogSnapshot(dialog) {
		const takeSnapshot = function () {
			if (dialog && dialog.hasAttribute('open')) {
				snapshotDialog(dialog);
			}
		};

		if (typeof window.requestAnimationFrame === 'function') {
			window.requestAnimationFrame(takeSnapshot);
		} else {
			window.setTimeout(takeSnapshot, 0);
		}
	}

	function commitDialog(target) {
		const dialog = dialogFrom(target);
		if (dialog) {
			snapshotDialog(dialog);
		}
	}

	function setDialogState(target, status) {
		const dialog = dialogFrom(target);
		if (!dialog) {
			return;
		}

		status = normalizeStatus(status);
		STATUS_CLASSES.forEach(function (item) {
			dialog.classList.remove('is-afcn-dialog-' + item);
		});

		if (!status) {
			dialog.removeAttribute('data-afcn-dialog-status');
			return;
		}

		dialog.dataset.afcnDialogStatus = status;
		dialog.classList.add('is-afcn-dialog-' + status);
	}

	function ensureDialogAlert(dialog) {
		if (!dialog) {
			return null;
		}
		let alert = dialog.querySelector('[data-afcn-dialog-alert]');
		if (alert) {
			return alert;
		}
		alert = document.createElement('div');
		alert.className = 'afcn-dialog-alert';
		alert.dataset.afcnDialogAlert = '1';
		alert.setAttribute('role', 'status');
		alert.setAttribute('aria-live', 'polite');
		alert.hidden = true;

		const header = dialog.querySelector('.afcn-dialog-header');
		if (header) {
			header.insertAdjacentElement('afterend', alert);
		} else {
			const form = dialog.querySelector('form');
			if (form) {
				form.insertAdjacentElement('afterbegin', alert);
			}
		}
		return alert;
	}

	function modalAlert(target, status, message) {
		const dialog = dialogFrom(target);
		if (!dialog) {
			return;
		}
		const alert = ensureDialogAlert(dialog);
		if (!alert) {
			return;
		}
		status = normalizeStatus(status);
		setDialogState(dialog, status);
		alert.className = 'afcn-dialog-alert' + (status ? ' is-' + status : '');
		alert.textContent = String(message || defaultMessage(status));
		alert.hidden = !status || !alert.textContent;
	}

	function clearModalAlert(target) {
		const dialog = dialogFrom(target);
		if (!dialog) {
			return;
		}
		setDialogState(dialog, '');
		const alert = dialog.querySelector('[data-afcn-dialog-alert]');
		if (alert) {
			alert.hidden = true;
			alert.textContent = '';
			alert.className = 'afcn-dialog-alert';
		}
	}

	function apply(button, status, message, options) {
		setButtonState(button, status, message, options);
		if (!options || options.alert !== false) {
			modalAlert(button, status, message);
		}
	}

	function loading(button, message, options) {
		apply(button, 'loading', message || defaultMessage('loading'), options);
	}

	function success(button, message, options) {
		apply(button, 'success', message || defaultMessage('success'), options);
		if (button && button.type === 'submit' && dialogFrom(button)) {
			commitDialog(button);
		}
	}

	function warning(button, message, options) {
		apply(button, 'warning', message || defaultMessage('warning'), options);
	}

	function error(button, message, options) {
		apply(button, 'error', message || defaultMessage('error'), options);
	}

	function clear(button, options) {
		setButtonState(button, '', '');
		if (!options || options.alert !== false) {
			clearModalAlert(button);
		}
	}

	function transientSuccess(button, message) {
		success(button, message || 'Done.', { transient: true, delay: 1500 });
	}

	function genericLoadingMessage(button) {
		const text = String(button.textContent || '').trim().toLowerCase();
		if (text.includes('connect') || text.includes('test')) {
			return 'Connecting…';
		}
		if (text.includes('refresh')) {
			return 'Refreshing…';
		}
		if (text.includes('delete') || text.includes('trash') || text.includes('remove')) {
			return 'Processing…';
		}
		return 'Working…';
	}

	function isManagedAsyncButton(button) {
		if (button.matches('[data-afcn-connection-probe],[data-afcn-module]')) {
			return true;
		}
		const form = button.closest('form[data-afcn-action]');
		return !!(form && (button.type === 'submit' || !button.type));
	}

	function prepareDialog(dialog) {
		if (!dialog || dialog.dataset.afcnStatusWired) {
			return;
		}
		dialog.dataset.afcnStatusWired = '1';
		ensureDialogAlert(dialog);
		setDialogCancelVisible(dialog, false);

		const openObserver = new MutationObserver(function (mutations) {
			if (mutations.some(function (mutation) { return mutation.attributeName === 'open'; }) && dialog.hasAttribute('open')) {
				scheduleDialogSnapshot(dialog);
			}
		});
		openObserver.observe(dialog, { attributes: true, attributeFilter: ['open'] });

		if (dialog.hasAttribute('open')) {
			scheduleDialogSnapshot(dialog);
		}

		dialog.addEventListener('cancel', function (event) {
			event.preventDefault();
		});

		dialog.addEventListener('click', function (event) {
			if (event.target !== dialog) {
				return;
			}
			const rect = dialog.getBoundingClientRect();
			const outside = event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
			if (outside && typeof dialog.close === 'function') {
				dialog.close();
			}
		});

		function handleFieldChange(event) {
			if (!event.target.matches('input,select,textarea')) {
				return;
			}
			dialog.querySelectorAll('button[type="submit"]').forEach(function (button) {
				if (button.dataset.afcnStatus !== 'loading') {
					setButtonState(button, '', '');
				}
			});
			if (!dialog.querySelector('button.is-afcn-loading')) {
				clearModalAlert(event.target);
			}
			syncDialogDirty(dialog);
		}

		dialog.addEventListener('input', handleFieldChange);
		dialog.addEventListener('change', handleFieldChange);
		dialog.addEventListener('reset', function () {
			window.setTimeout(function () {
				syncDialogDirty(dialog);
			}, 0);
		});

		dialog.addEventListener('close', function () {
			clearModalAlert(dialog);
			dialogBaselines.delete(dialog);
			dialog.dataset.afcnDialogDirty = '0';
			setDialogCancelVisible(dialog, false);
			document.dispatchEvent(new CustomEvent('afcn:dialog:closed', {
				detail: {
					dialog: dialog,
					refreshModule: dialog.dataset.afcnRefreshModule || '',
					refreshPage: dialog.dataset.afcnRefreshPage === '1'
				}
			}));
		});
	}

	function wire(root) {
		if (!root || !root.querySelectorAll) {
			return;
		}
		root.querySelectorAll('dialog.afcn-dialog').forEach(prepareDialog);
		root.querySelectorAll('button:disabled').forEach(function (button) {
			if (!button.dataset.afcnStatus) {
				setButtonState(button, 'disabled', defaultMessage('disabled'));
			}
		});
	}

	document.addEventListener('submit', function (event) {
		if (event.target && event.target.closest && event.target.closest('dialog.afcn-dialog')) {
			event.preventDefault();
		}
	}, true);

	document.addEventListener('click', function (event) {
		const button = event.target && event.target.closest ? event.target.closest('button') : null;
		if (!button || !button.closest('body.afcn-page') || button.disabled || button.matches('[data-afcn-dialog-close]')) {
			return;
		}
		if (isManagedAsyncButton(button)) {
			return;
		}

		loading(button, genericLoadingMessage(button));
		window.setTimeout(function () {
			if (button.dataset.afcnStatus === 'loading') {
				transientSuccess(button, 'Done.');
			}
		}, 180);
	}, true);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			wire(document);
		});
	} else {
		wire(document);
	}

	window.AirfiberUIStatus = Object.freeze({
		set: setButtonState,
		loading: loading,
		success: success,
		warning: warning,
		error: error,
		clear: clear,
		modalAlert: modalAlert,
		clearModalAlert: clearModalAlert,
		setDialogState: setDialogState,
		snapshotDialog: snapshotDialog,
		syncDialogDirty: syncDialogDirty,
		prepareDialog: prepareDialog,
		wire: wire
	});
}());
