(function () {
	'use strict';

	let root = null;
	let input = null;
	let results = null;
	let meta = null;
	let clearButton = null;
	let flash = null;
	let timer = 0;
	let requestId = 0;
	let selected = null;
	let holdTimer = 0;
	let editMode = false;
	let editSelected = null;
	let editOriginalHash = '';
	const items = new Map();

	function text(value) {
		return value === undefined || value === null ? '' : String(value);
	}

	function money(value) {
		const number = Number(value || 0);
		return number > 0 ? '₱' + number.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) : '';
	}

	function setMeta(message) {
		if (meta) {
			meta.textContent = message || 'Type at least 3 letters to search.';
		}
	}

	function setFlash(message, variant) {
		if (!flash) {
			return;
		}
		flash.replaceChildren();
		if (!message) {
			return;
		}
		const alert = document.createElement('div');
		alert.className = 'afcn-alert afcn-alert-' + (variant || 'info');
		const copy = document.createElement('div');
		copy.className = 'afcn-alert-copy';
		const paragraph = document.createElement('p');
		paragraph.textContent = message;
		copy.appendChild(paragraph);
		alert.appendChild(copy);
		flash.appendChild(alert);
	}

	function loading() {
		if (!results) {
			return;
		}
		results.hidden = false;
		results.replaceChildren();
		const skeleton = document.createElement('div');
		skeleton.className = 'afcn-payment-search-loading';
		for (let i = 0; i < 3; i++) {
			const row = document.createElement('div');
			row.className = 'afcn-payment-result-skeleton';
			row.innerHTML = '<span></span><span></span>';
			skeleton.appendChild(row);
		}
		results.appendChild(skeleton);
	}

	function clearResults() {
		items.clear();
		selected = null;
		if (results) {
			results.replaceChildren();
			results.hidden = true;
		}
		setMeta('');
	}

	function resultButton(item, key) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'afcn-payment-result';
		button.dataset.afcnPaymentResult = key;
		button.setAttribute('role', 'option');

		const main = document.createElement('span');
		main.className = 'afcn-payment-result-main';

		const name = document.createElement('strong');
		name.className = 'afcn-payment-result-name';
		name.textContent = item.customer_name || item.account;

		const account = document.createElement('span');
		account.className = 'afcn-payment-result-account';
		account.textContent = item.account + (item.phone ? ' · ' + item.phone : '');

		const badges = document.createElement('span');
		badges.className = 'afcn-payment-result-badges';
		const state = document.createElement('span');
		state.className = 'afcn-pill ' + (item.status === 'expired' ? 'afcn-pill-warning' : 'afcn-pill-success');
		const dot = document.createElement('span');
		dot.className = 'afcn-pill-dot';
		const stateLabel = document.createElement('span');
		stateLabel.textContent = item.status === 'expired' ? 'EXPIRED' : 'ACTIVE';
		state.appendChild(dot);
		state.appendChild(stateLabel);
		badges.appendChild(state);

		const address = document.createElement('small');
		address.className = 'afcn-payment-result-address';
		address.textContent = item.address || 'Location not set';

		main.appendChild(name);
		main.appendChild(account);
		main.appendChild(badges);
		main.appendChild(address);

		const payment = document.createElement('span');
		payment.className = 'afcn-payment-result-payment';

		const last = document.createElement('strong');
		last.className = 'afcn-payment-result-date';
		last.textContent = item.payment_date || 'No payment';

		const plan = document.createElement('span');
		plan.className = 'afcn-payment-result-side-plan';
		plan.textContent = item.plan || item.actual_profile || 'No plan';

		payment.appendChild(last);
		payment.appendChild(plan);

		const arrow = document.createElement('span');
		arrow.className = 'afcn-payment-result-arrow';
		arrow.setAttribute('aria-hidden', 'true');
		arrow.textContent = '›';

		button.appendChild(main);
		button.appendChild(payment);
		button.appendChild(arrow);
		return button;
	}

	function renderSearch(data) {
		if (!results) {
			return;
		}
		items.clear();
		results.replaceChildren();

		const found = Array.isArray(data.results) ? data.results : [];
		if (!found.length) {
			results.hidden = false;
			const empty = document.createElement('div');
			empty.className = 'afcn-payment-empty';
			empty.textContent = 'No matching customer was found.';
			results.appendChild(empty);
			setMeta('Try the PPP account, phone number, address, or another spelling.');
			return;
		}

		const list = document.createElement('div');
		list.className = 'afcn-payment-result-list';
		found.forEach(function (item) {
			const key = text(item.connection_id) + '|' + text(item.secret_id);
			items.set(key, item);
			list.appendChild(resultButton(item, key));
		});
		results.appendChild(list);
		results.hidden = false;

		let message = found.length + ' matching customer' + (found.length === 1 ? '' : 's');
		if (data.cache_hit) {
			message += ' · cached';
		} else if (data.latency_ms) {
			message += ' · ' + Math.round(Number(data.latency_ms)) + ' ms';
		}
		if (Number(data.failed_sources || 0) > 0) {
			message += ' · some routers unavailable';
		}
		setMeta(message + '.');
	}

	async function search(query) {
		const id = ++requestId;
		loading();
		setMeta('Searching…');
		try {
			const data = await window.AirfiberNext.query('payments', 'search', { q: query });
			if (id !== requestId || input.value.trim() !== query) {
				return;
			}
			renderSearch(data || {});
		} catch (error) {
			if (id !== requestId) {
				return;
			}
			results.hidden = false;
			results.replaceChildren();
			const alert = document.createElement('div');
			alert.className = 'afcn-alert afcn-alert-danger';
			const copy = document.createElement('div');
			copy.className = 'afcn-alert-copy';
			const paragraph = document.createElement('p');
			paragraph.textContent = error.message || 'Customer search failed.';
			copy.appendChild(paragraph);
			alert.appendChild(copy);
			results.appendChild(alert);
			setMeta('');
		}
	}

	function queueSearch() {
		window.clearTimeout(timer);
		setFlash('', '');
		const query = input.value.trim();
		clearButton.hidden = !query;

		if (query.length < 3) {
			requestId++;
			clearResults();
			return;
		}

		timer = window.setTimeout(function () {
			search(query);
		}, 180);
	}

	function dialog() {
		return document.getElementById('afcn-payment-dialog');
	}

	function showDialogMessage(message, variant) {
		const target = dialog() ? dialog().querySelector('[data-afcn-payment-dialog-message]') : null;
		if (!target) {
			return;
		}
		target.replaceChildren();
		if (!message) {
			return;
		}
		const alert = document.createElement('div');
		alert.className = 'afcn-alert afcn-alert-' + (variant || 'info');
		const copy = document.createElement('div');
		copy.className = 'afcn-alert-copy';
		const paragraph = document.createElement('p');
		paragraph.textContent = message;
		copy.appendChild(paragraph);
		alert.appendChild(copy);
		target.appendChild(alert);
	}

	function cycleLabel(value) {
		const cycle = Number(value || 0);
		return cycle === 15 ? '15D' : (cycle === 30 ? '30D' : 'MTH');
	}

	function paymentAmount(modal) {
		const override = Number(modal && modal.dataset.afcnPaymentAmount || 0);
		if (Number.isFinite(override) && override > 0) {
			return override;
		}
		const saved = Number(selected && selected.payment_amount || 0);
		return Number.isFinite(saved) && saved > 0 ? saved : 0;
	}

	function setQuickBusy(modal, activeButton, busy) {
		if (!modal) {
			return;
		}
		modal.querySelectorAll('[data-afcn-payment-quick-method]').forEach(function (button) {
			button.disabled = busy;
			button.classList.toggle('is-loading', busy && button === activeButton);
		});
		const expireButton = modal.querySelector('[data-afcn-payment-expire]');
		if (expireButton) expireButton.disabled = busy;
		const close = modal.querySelector('[data-afcn-dialog-close]');
		if (close) {
			close.disabled = busy;
		}
	}

	function showAmountOverride(method) {
		const modal = dialog();
		if (!modal) {
			return;
		}
		const panel = modal.querySelector('[data-afcn-payment-amount-override]');
		const amount = modal.querySelector('[data-afcn-payment-amount]');
		if (!panel || !amount) {
			return;
		}
		modal.dataset.afcnPaymentPendingMethod = method || '';
		panel.hidden = false;
		const current = paymentAmount(modal);
		amount.value = current > 0 ? String(current) : '';
		showDialogMessage('Set the amount, then tap CASH or GCash.', 'info');
		window.setTimeout(function () {
			amount.focus({ preventScroll: true });
			amount.select();
		}, 30);
	}

	function hideAmountOverride() {
		const modal = dialog();
		const panel = modal ? modal.querySelector('[data-afcn-payment-amount-override]') : null;
		if (panel) {
			panel.hidden = true;
		}
		if (modal) {
			delete modal.dataset.afcnPaymentPendingMethod;
		}
	}

	function applyAmountOverride() {
		const modal = dialog();
		const amountInput = modal ? modal.querySelector('[data-afcn-payment-amount]') : null;
		const amount = amountInput ? Number(amountInput.value) : 0;
		if (!modal || !Number.isFinite(amount) || amount <= 0) {
			showDialogMessage('Enter a payment amount greater than zero.', 'danger');
			return;
		}
		modal.dataset.afcnPaymentAmount = String(amount);
		hideAmountOverride();
		showDialogMessage(money(amount) + ' will be used for the next payment action.', 'info');
	}

	function openPayment(item) {
		selected = item;
		const modal = dialog();
		if (!modal) {
			return;
		}

		modal.querySelector('[data-afcn-payment-dialog-name]').textContent = item.customer_name || item.account;
		modal.querySelector('[data-afcn-payment-dialog-account]').textContent = item.account;
		modal.querySelector('[data-afcn-payment-dialog-plan]').textContent = item.plan || item.actual_profile || 'Not set';

		const expireButton = modal.querySelector('[data-afcn-payment-expire]');
		if (expireButton) expireButton.hidden = item.status === 'expired';
		const status = modal.querySelector('[data-afcn-payment-dialog-status]');
		if (status) {
			status.textContent = item.status === 'expired' ? 'Expired' : 'Active';
			status.className = item.status === 'expired' ? 'is-expired' : 'is-active';
		}

		modal.querySelectorAll('[data-afcn-payment-cycle-pill]').forEach(function (pill) {
			pill.textContent = cycleLabel(item.billing_cycle_days);
		});

		delete modal.dataset.afcnPaymentAmount;
		delete modal.dataset.afcnPaymentPendingMethod;
		hideAmountOverride();

		const amount = modal.querySelector('[data-afcn-payment-amount]');
		if (amount) {
			amount.value = Number(item.payment_amount || 0) > 0 ? String(item.payment_amount) : '';
		}

		showDialogMessage(
			item.status === 'expired'
				? 'This account is expired. Recording payment will not reconnect it automatically.'
				: '',
			'warning'
		);

		window.AirfiberNext.openDialog(modal);
		window.setTimeout(function () {
			const cash = modal.querySelector('[data-afcn-payment-quick-method="cash"]');
			if (cash) {
				cash.focus({ preventScroll: true });
			}
		}, 60);
	}

	async function recordPayment(method, button) {
		if (!selected || !button || button.disabled) {
			return;
		}
		const modal = dialog();
		const amount = paymentAmount(modal);
		if (!amount) {
			showAmountOverride(method);
			return;
		}

		setQuickBusy(modal, button, true);
		showDialogMessage('Recording ' + (method === 'gcash' ? 'GCash' : 'cash') + ' payment…', 'info');

		const label = button.querySelector('[data-afcn-payment-quick-label]');
		const originalLabel = label ? label.textContent : '';
		if (label) {
			label.textContent = 'Recording…';
		}

		try {
			const response = await window.AirfiberNext.action('payments', 'record-payment', {
				connection_id: selected.connection_id,
				secret_id: selected.secret_id,
				account: selected.account,
				amount: amount,
				method: method
			});
			window.AirfiberNext.closeDialog(modal);
			input.value = '';
			clearButton.hidden = true;
			clearResults();
			setFlash(response.message || 'Payment recorded.', response.service_expired ? 'warning' : 'success');
			window.AirfiberNext.toast(response.message || 'Payment recorded.', false);
			window.setTimeout(function () {
				input.focus({ preventScroll: true });
			}, 30);
		} catch (error) {
			showDialogMessage(error.message || 'The payment could not be recorded.', 'danger');
		} finally {
			setQuickBusy(modal, button, false);
			if (label) {
				label.textContent = originalLabel;
			}
		}
	}

	async function expirePaymentAccount() {
		if (!selected || selected.status === 'expired') return;
		const modal = dialog();
		const button = modal && modal.querySelector('[data-afcn-payment-expire]');
		if (!button || button.disabled) return;
		const item = selected;
		if (!window.confirm('Manually EXPIRE PPP account "' + item.account + '"? This changes its MikroTik profile to Expired and disconnects active sessions immediately. No payment or billing dates will be changed.')) return;
		setQuickBusy(modal, button, true);
		button.textContent = 'Expiring…';
		showDialogMessage('Applying expiration on MikroTik…', 'warning');
		try {
			const response = await window.AirfiberNext.action('payments', 'expire-ppp', {
				connection_id: item.connection_id,
				secret_id: item.secret_id,
				account: item.account
			});
			window.AirfiberNext.closeDialog(modal);
			selected = null;
			clearResults();
			setFlash(response.message || 'Account manually expired.', 'success');
			window.AirfiberNext.toast(response.message || 'Account manually expired.', false);
			if (input.value.trim().length >= 3) search(input.value.trim());
		} catch (error) {
			showDialogMessage(error.message || 'Could not expire PPP account. Check MikroTik state before retrying.', 'danger');
		} finally {
			button.textContent = 'Manual Expire';
			setQuickBusy(modal, button, false);
		}
	}

	function bindQuickPaymentDialog() {
		const modal = dialog();
		if (!modal || modal.dataset.afcnPaymentQuickWired) {
			return;
		}
		modal.dataset.afcnPaymentQuickWired = '1';
		const expireButton = modal.querySelector('[data-afcn-payment-expire]');
		if (expireButton) expireButton.addEventListener('click', expirePaymentAccount);

		modal.querySelectorAll('[data-afcn-payment-quick-method]').forEach(function (button) {
			button.addEventListener('pointerdown', function (event) {
				if (button.disabled || (event.pointerType === 'mouse' && event.button !== 0)) {
					return;
				}
				window.clearTimeout(holdTimer);
				button.dataset.afcnHoldTriggered = '';
				button.classList.add('is-holding');
				holdTimer = window.setTimeout(function () {
					button.dataset.afcnHoldTriggered = '1';
					button.classList.remove('is-holding');
					showAmountOverride(button.dataset.afcnPaymentQuickMethod || '');
				}, 620);
			});
			['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) {
				button.addEventListener(name, function () {
					window.clearTimeout(holdTimer);
					button.classList.remove('is-holding');
				});
			});
			button.addEventListener('click', function (event) {
				event.preventDefault();
				if (button.dataset.afcnHoldTriggered === '1') {
					button.dataset.afcnHoldTriggered = '';
					return;
				}
				recordPayment(button.dataset.afcnPaymentQuickMethod || 'cash', button);
			});
		});

		const apply = modal.querySelector('[data-afcn-payment-amount-apply]');
		if (apply) {
			apply.addEventListener('click', applyAmountOverride);
		}
		const cancel = modal.querySelector('[data-afcn-payment-amount-cancel]');
		if (cancel) {
			cancel.addEventListener('click', function () {
				hideAmountOverride();
				showDialogMessage(
					selected && selected.status === 'expired'
						? 'This account is expired. Recording payment will not reconnect it automatically.'
						: '',
					'warning'
				);
			});
		}
	}



	function editorDialog() {
		return document.getElementById('afcn-ppp-comment-dialog');
	}

	function editorMessage(message, variant) {
		const modal = editorDialog();
		const node = modal && modal.querySelector('[data-afcn-ppp-edit-message]');
		if (!node) return;
		node.textContent = message || '';
		node.className = message ? 'afcn-ppp-comment-message is-' + (variant || 'info') : 'afcn-ppp-comment-message';
	}

	function editorBusy(busy) {
		const modal = editorDialog();
		if (!modal) return;
		const save = modal.querySelector('[data-afcn-ppp-edit-save]');
		if (save) {
			save.disabled = busy;
			save.textContent = busy ? 'Working…' : 'Save comment';
		}
	}

	async function openEditor(item) {
		const modal = editorDialog();
		if (!modal || !item) return;
		editSelected = item;
		editOriginalHash = '';
		const textarea = modal.querySelector('[data-afcn-ppp-edit-text]');
		textarea.value = '';
		modal.querySelector('[data-afcn-ppp-edit-account]').textContent = item.customer_name + ' · ' + item.account;
		editorMessage('Loading the latest PPP comment from MikroTik…', 'info');
		editorBusy(true);
		window.AirfiberNext.openDialog(modal);
		try {
			const response = await window.AirfiberNext.query('payments', 'read-ppp-comment', {
				connection_id: item.connection_id,
				secret_id: item.secret_id,
				account: item.account
			});
			if (editSelected !== item || !modal.open) return;
			editOriginalHash = response.comment_hash || '';
			textarea.value = response.comment || '';
			editorMessage('Only the comment will be changed. Check billing and scheduler fields before saving.', 'info');
			textarea.focus({ preventScroll: true });
		} catch (error) {
			editorMessage(error.message || 'Could not load this PPP comment.', 'danger');
		} finally {
			if (editSelected === item && modal.open) editorBusy(!editOriginalHash);
		}
	}

	async function saveEditor() {
		const modal = editorDialog();
		if (!modal || !editSelected || !editOriginalHash) return;
		const textarea = modal.querySelector('[data-afcn-ppp-edit-text]');
		const comment = textarea.value;
		if (!comment.trim() || new TextEncoder().encode(comment).length > 8000) {
			editorMessage('Enter a comment of 8000 bytes or fewer.', 'danger');
			return;
		}
		editorBusy(true);
		editorMessage('Saving comment to MikroTik…', 'info');
		try {
			const response = await window.AirfiberNext.action('payments', 'save-ppp-comment', {
				connection_id: editSelected.connection_id,
				secret_id: editSelected.secret_id,
				account: editSelected.account,
				comment_hash: editOriginalHash,
				comment: comment
			});
			window.AirfiberNext.closeDialog(modal);
			editSelected = null;
			editOriginalHash = '';
			input.value = '';
			clearButton.hidden = true;
			clearResults();
			setFlash(response.message || 'PPP comment saved.', 'success');
			window.AirfiberNext.toast(response.message || 'PPP comment saved.', false);
			input.focus({ preventScroll: true });
		} catch (error) {
			editorMessage(error.message || 'PPP comment could not be saved.', 'danger');
		} finally {
			if (modal.open) editorBusy(false);
		}
	}

	function bindEditor() {
		const modal = editorDialog();
		if (!modal || modal.dataset.afcnPppEditWired) return;
		modal.dataset.afcnPppEditWired = '1';
		modal.querySelectorAll('[data-afcn-ppp-edit-close]').forEach(function (button) {
			button.addEventListener('click', function () {
				window.AirfiberNext.closeDialog(modal);
			});
		});
		modal.querySelector('[data-afcn-ppp-edit-save]').addEventListener('click', saveEditor);
		modal.addEventListener('close', function () {
			editSelected = null;
			editOriginalHash = '';
		});
	}

	function bindModeToggle() {
		const toggle = root && root.querySelector('[data-afcn-payment-mode-toggle]');
		if (toggle) {
			toggle.addEventListener('click', function () {
				editMode = !editMode;
				root.classList.toggle('is-editing-ppp', editMode);
				toggle.setAttribute('aria-pressed', editMode ? 'true' : 'false');
				toggle.textContent = editMode ? 'Record payment' : 'Edit PPP';
				const title = root.querySelector('.afcn-payments-head .afcn-page-title');
				if (title) title.textContent = editMode ? 'Edit PPP comment' : 'Record payment';
				input.value = '';
				clearButton.hidden = true;
				clearResults();
				input.placeholder = editMode ? 'Search PPP account to edit its comment…' : 'Type customer name, PPP account, phone or address…';
				input.focus({ preventScroll: true });
			});
		}
		const fromPayment = root && root.querySelector('[data-afcn-payment-edit-from-dialog]');
		if (fromPayment) {
			fromPayment.addEventListener('click', function () {
				const item = selected;
				const paymentModal = dialog();
				if (paymentModal && paymentModal.open) window.AirfiberNext.closeDialog(paymentModal);
				openEditor(item);
			});
		}
	}

	function init() {
		root = document.querySelector('[data-afcn-payments-root]');
		if (!root || root.dataset.afcnPaymentsWired) {
			return;
		}
		root.dataset.afcnPaymentsWired = '1';
		input = root.querySelector('[data-afcn-payment-search]');
		results = root.querySelector('[data-afcn-payment-results]');
		meta = root.querySelector('[data-afcn-payment-search-meta]');
		clearButton = root.querySelector('[data-afcn-payment-clear]');
		flash = root.querySelector('[data-afcn-payment-flash]');
		if (!input || !results || !clearButton) {
			return;
		}

		bindQuickPaymentDialog();
		bindEditor();
		bindModeToggle();

		input.addEventListener('input', queueSearch);
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				input.value = '';
				clearButton.hidden = true;
				clearResults();
				return;
			}
			if (event.key === 'ArrowDown') {
				const first = results.querySelector('[data-afcn-payment-result]');
				if (first) {
					event.preventDefault();
					first.focus();
				}
			}
		});

		clearButton.addEventListener('click', function () {
			input.value = '';
			clearButton.hidden = true;
			clearResults();
			input.focus();
		});

		results.addEventListener('click', function (event) {
			const button = event.target.closest('[data-afcn-payment-result]');
			if (!button) {
				return;
			}
			const item = items.get(button.dataset.afcnPaymentResult || '');
			if (item) {
				if (editMode) {
					openEditor(item);
				} else {
					openPayment(item);
				}
			}
		});

		window.requestAnimationFrame(function () {
			window.setTimeout(function () {
				if (!input.disabled) {
					input.focus({ preventScroll: true });
				}
			}, 50);
		});
	}

	init();
	document.addEventListener('afcn:module:loaded', function (event) {
		const detail = event.detail || {};
		if ((detail.id || detail.module) === 'payments') {
			init();
		}
	});
	// Dashboard loads Payments through the existing lazy slot, not a full module route.
	document.addEventListener('afcn:chunk:loaded', function (event) {
		const detail = event.detail || {};
		if (detail.module === 'payments' && detail.chunk === 'dashboard-operations') {
			init();
		}
	});
}());
