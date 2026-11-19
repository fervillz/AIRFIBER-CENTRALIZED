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
		workspaceEpoch++;
		const modal = dialog();
		workspaceMode = 'payment';
		modal.querySelectorAll('[data-afcn-workspace-panel]').forEach(function (panel) {
			panel.hidden = panel.dataset.afcnWorkspacePanel !== 'payment';
		});
		modal.querySelectorAll('[data-afcn-workspace-tab]').forEach(function (button) { button.classList.remove('is-active'); });
		modal.querySelector('[data-afcn-workspace-subtitle]').textContent = item.account + ' · ' + (item.plan || item.actual_profile || 'PPP');
		workspaceStatus('', '');
		if (!modal) {
			return;
		}

		modal.querySelector('[data-afcn-payment-dialog-name]').textContent = item.customer_name || item.account;
		modal.querySelector('[data-afcn-payment-dialog-account]').textContent = item.account;
		modal.querySelector('[data-afcn-payment-dialog-plan]').textContent = item.plan || item.actual_profile || 'Not set';

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

	function bindQuickPaymentDialog() {
		const modal = dialog();
		if (!modal || modal.dataset.afcnPaymentQuickWired) {
			return;
		}
		modal.dataset.afcnPaymentQuickWired = '1';

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




	// All payment and PPP editing happens in a single modal. PPP passwords
	// are requested only on an explicit Edit action, never in search results.
	let workspaceMode = 'payment';
	let workspaceBusy = false;
	let detailVersion = '';
	let commentVersion = '';
	let workspaceEpoch = 0;

	function workspaceStatus(message, type) {
		const modal = dialog();
		const node = modal && modal.querySelector('[data-afcn-workspace-status]');
		if (!node) return;
		node.textContent = message || '';
		node.className = 'afcn-workspace-message' + (message ? ' is-' + (type || 'info') : '');
	}
	function workspaceButtonState(button, phase, message) {
		const status = window.AirfiberUIStatus;
		if (!button || !status) return;
		if (phase === 'loading') status.loading(button, message, { alert: false });
		else if (phase === 'success') status.success(button, message, { transient: true, alert: false });
		else if (phase === 'error') status.error(button, message, { transient: true, alert: false });
		else status.clear(button, { alert: false });
	}
	function workspaceSetBusy(busy) {
		workspaceBusy = busy;
		const modal = dialog();
		if (!modal) return;
		modal.querySelectorAll('[data-afcn-workspace-tab],[data-afcn-workspace-trash],[data-afcn-workspace-save-ppp],[data-afcn-workspace-save-comment],[data-afcn-workspace-back],[data-afcn-dialog-close]').forEach(function (button) {
			button.disabled = busy;
		});
		modal.querySelectorAll('[data-afcn-payment-quick-method]').forEach(function (button) { button.disabled = busy; });
	}
	function workspacePanel(mode) {
		const modal = dialog();
		return modal && modal.querySelector('[data-afcn-workspace-panel="' + mode + '"]');
	}
	async function workspaceSwitch(next, instant) {
		if (workspaceBusy || next === workspaceMode) return;
		const modal = dialog();
		const old = workspacePanel(workspaceMode);
		const incoming = workspacePanel(next);
		if (!modal || !old || !incoming) return;
		const previous = workspaceMode;
		workspaceMode = next;
		modal.querySelectorAll('[data-afcn-workspace-tab]').forEach(function (button) {
			button.classList.toggle('is-active', button.dataset.afcnWorkspaceTab === next);
			button.setAttribute('aria-pressed', button.dataset.afcnWorkspaceTab === next ? 'true' : 'false');
		});
		workspaceStatus('', '');
		const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		const shouldAnimate = !instant && !reduceMotion && old.animate && incoming.animate;
		if (shouldAnimate) {
			await old.animate(
				[{ opacity: 1, transform: 'translateY(0)' }, { opacity: 0, transform: 'translateY(' + (next === 'payment' ? '16px' : '-16px') + ')' }],
				{ duration: 155, easing: 'ease-in', fill: 'forwards' }
			).finished.catch(function () {});
		}
		old.hidden = true;
		old.style.opacity = '';
		old.style.transform = '';
		incoming.hidden = false;
		if (shouldAnimate) {
			await incoming.animate(
				[{ opacity: 0, transform: 'translateY(' + (next === 'payment' ? '-16px' : '20px') + ')' }, { opacity: 1, transform: 'translateY(0)' }],
				{ duration: 215, easing: 'cubic-bezier(.2,.8,.2,1)' }
			).finished.catch(function () {});
		}
		if (previous === 'ppp' && next !== 'ppp') {
			// Clear the credential from the DOM when leaving Edit PPP.
			const pwd = modal.querySelector('[data-afcn-ppp-field="password"]');
			if (pwd) pwd.value = '';
			detailVersion = '';
		}
	}
	function selectedPayload(item) {
		return { connection_id: item.connection_id, secret_id: item.secret_id, account: item.account };
	}
	async function workspaceOpen(mode, button) {
		if (!selected || workspaceBusy) return;
		if (mode === workspaceMode) {
			await workspaceSwitch('payment');
			return;
		}
		const item = selected;
		const epoch = ++workspaceEpoch;
		workspaceSetBusy(true);
		workspaceButtonState(button, 'loading', 'Loading live MikroTik details…');
		workspaceStatus('Loading from MikroTik…', 'info');
		try {
			if (mode === 'ppp') {
				const data = await window.AirfiberNext.query('payments', 'read-ppp-details', selectedPayload(item));
				if (epoch !== workspaceEpoch || selected !== item || !dialog().open) return;
				detailVersion = data.version || '';
				dialog().querySelector('[data-afcn-ppp-customer]').value = data.customer_name || '';
				dialog().querySelectorAll('[data-afcn-ppp-field]').forEach(function (control) {
					const key = control.dataset.afcnPppField;
					let value = data.fields && data.fields[key] !== undefined ? String(data.fields[key]) : '';
					if (key === 'disabled') value = ['yes', 'true', '1'].includes(value.toLowerCase()) ? 'true' : 'false';
					if (key === 'only-one' && !['', 'yes', 'no', 'default'].includes(value)) value = '';
					control.value = value;
				});
			} else {
				const data = await window.AirfiberNext.query('payments', 'read-ppp-comment', selectedPayload(item));
				if (epoch !== workspaceEpoch || selected !== item || !dialog().open) return;
				commentVersion = data.comment_hash || '';
				dialog().querySelector('[data-afcn-workspace-comment]').value = data.comment || '';
			}
		} catch (error) {
			workspaceButtonState(button, 'error', error.message || 'Could not load PPP details.');
			workspaceStatus(error.message || 'Could not load PPP details.', 'danger');
			return;
		} finally {
			workspaceSetBusy(false);
		}
		if (epoch === workspaceEpoch && selected === item && dialog().open) {
			workspaceButtonState(button, 'success', 'Loaded');
			await workspaceSwitch(mode);
		}
	}
	async function workspaceSave(mode, button) {
		if (!selected || workspaceBusy) return;
		const modal = dialog();
		let action;
		let payload = selectedPayload(selected);
		if (mode === 'ppp') {
			if (!detailVersion) return;
			action = 'save-ppp-details';
			payload.version = detailVersion;
			payload.customer_name = modal.querySelector('[data-afcn-ppp-customer]').value;
			payload.fields = {};
			modal.querySelectorAll('[data-afcn-ppp-field]').forEach(function (control) {
				payload.fields[control.dataset.afcnPppField] = control.value;
			});
		} else {
			if (!commentVersion) return;
			action = 'save-ppp-comment';
			payload.comment_hash = commentVersion;
			payload.comment = modal.querySelector('[data-afcn-workspace-comment]').value;
			if (!payload.comment.trim() || new TextEncoder().encode(payload.comment).length > 8000) {
				workspaceStatus('Enter a comment of no more than 8000 bytes.', 'danger');
				return;
			}
		}
		workspaceSetBusy(true);
		workspaceButtonState(button, 'loading', 'Saving on MikroTik…');
		workspaceStatus('Saving to MikroTik…', 'info');
		try {
			const response = await window.AirfiberNext.action('payments', action, payload);
			workspaceButtonState(button, 'success', response.message || 'Saved');
			const label = response.message || 'PPP saved.';
			if (response.account && response.account !== selected.account) selected.account = response.account;
			workspaceStatus(label, 'success');
			setFlash(label, 'success');
			// Prevent an old cached customer result from being trusted after updates.
			clearResults();
			if (input.value.trim().length >= 3) search(input.value.trim());
			await workspaceSwitch('payment');
		} catch (error) {
			workspaceButtonState(button, 'error', error.message || 'Could not save.');
			workspaceStatus(error.message || 'Could not save PPP details.', 'danger');
		} finally {
			workspaceSetBusy(false);
		}
	}
	async function workspaceTrash(button) {
		if (!selected || workspaceBusy) return;
		// Reversible one-click action, no second popup.
		const item = selected;
		workspaceSetBusy(true);
		workspaceButtonState(button, 'loading', 'Moving PPP and scheduler to Trash…');
		workspaceStatus('Disabling PPP and cutoff scheduler…', 'info');
		try {
			const result = await window.AirfiberNext.action('payments', 'trash-ppp', selectedPayload(item));
			workspaceButtonState(button, 'success', result.message || 'Moved to Trash');
			window.AirfiberNext.closeDialog(dialog());
			clearResults();
			setFlash(result.message || 'PPP moved to Trash.', 'success');
			if (input.value.trim().length >= 3) search(input.value.trim());
		} catch (error) {
			workspaceButtonState(button, 'error', error.message || 'Could not trash PPP.');
			workspaceStatus((error.message || 'Could not move PPP to Trash.') + ' Check Trash before retrying.', 'danger');
		} finally {
			workspaceSetBusy(false);
		}
	}
	function bindWorkspace() {
		const modal = dialog();
		if (!modal || modal.dataset.afcnWorkspaceWired) return;
		modal.dataset.afcnWorkspaceWired = '1';
		modal.querySelectorAll('[data-afcn-workspace-tab]').forEach(function (button) {
			button.addEventListener('click', function () { workspaceOpen(button.dataset.afcnWorkspaceTab, button); });
		});
		modal.querySelector('[data-afcn-workspace-trash]').addEventListener('click', function () {
			workspaceTrash(this);
		});
		modal.querySelector('[data-afcn-workspace-save-ppp]').addEventListener('click', function () { workspaceSave('ppp', this); });
		modal.querySelector('[data-afcn-workspace-save-comment]').addEventListener('click', function () { workspaceSave('comment', this); });
		modal.querySelectorAll('[data-afcn-workspace-back]').forEach(function (button) {
			button.addEventListener('click', function () { workspaceSwitch('payment'); });
		});
		modal.addEventListener('close', function () {
			workspaceEpoch++;
			const pwd = modal.querySelector('[data-afcn-ppp-field="password"]');
			if (pwd) pwd.value = '';
			detailVersion = '';
			commentVersion = '';
			workspaceMode = 'payment';
			modal.querySelectorAll('[data-afcn-workspace-panel]').forEach(function (panel) {
				panel.hidden = panel.dataset.afcnWorkspacePanel !== 'payment';
			});
			workspaceSetBusy(false);
		});
	}

	function trashDialog() {
		return document.getElementById('afcn-ppp-trash-dialog');
	}
	function trashStatus(message) {
		const target = trashDialog() && trashDialog().querySelector('[data-afcn-trash-status]');
		if (target) target.textContent = message || '';
	}
	async function refreshTrash() {
		const modal = trashDialog();
		if (!modal) return;
		trashStatus('Loading Trash…');
		const list = modal.querySelector('[data-afcn-trash-list]');
		list.replaceChildren();
		try {
			const response = await window.AirfiberNext.query('payments', 'trash-list', {});
			const items = Array.isArray(response.items) ? response.items : [];
			trashStatus(items.length ? items.length + ' deleted PPP account(s)' : 'Trash is empty.');
			items.forEach(function (item) {
				const row = document.createElement('div');
				row.className = 'afcn-ppp-trash-row';
				const info = document.createElement('div');
				const name = document.createElement('strong');
				name.textContent = item.account;
				const small = document.createElement('small');
				small.textContent = item.router + ' · ' + item.date + (item.status === 'pending' ? ' · Needs review' : '');
				info.append(name, small);
				const actions = document.createElement('div');
				actions.className = 'afcn-ppp-trash-actions';
				['Restore', 'Delete permanently'].forEach(function (label, i) {
					const btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'afcn-button afcn-button-secondary';
					btn.textContent = label;
					btn.addEventListener('click', async function () {
						const action = i ? 'purge-ppp' : 'restore-ppp';
						const prompt = i
							? 'PERMANENTLY delete PPP "' + item.account + '" and its associated scheduler from MikroTik? This CANNOT be undone.'
							: 'Restore PPP "' + item.account + '" and its scheduler to their prior enabled/disabled states?';
						if (!window.confirm(prompt)) return;
						modal.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
						trashStatus(i ? 'Permanently deleting…' : 'Restoring…');
						try {
							const done = await window.AirfiberNext.action('payments', action, { trash_id: item.trash_id });
							setFlash(done.message || label + ' successful.', 'success');
							await refreshTrash();
							if (input.value.trim().length >= 3) search(input.value.trim());
						} catch (error) {
							trashStatus(error.message || 'Router action failed. Check MikroTik before retrying.');
						} finally {
							modal.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
						}
					});
					actions.appendChild(btn);
				});
				row.append(info, actions);
				list.appendChild(row);
			});
		} catch (error) {
			trashStatus(error.message || 'Could not load Trash.');
		}
	}
	function bindTrash() {
		const open = root.querySelector('[data-afcn-trash-open]');
		const modal = trashDialog();
		if (!modal) return;
		if (open) open.addEventListener('click', function () {
			window.AirfiberNext.openDialog(modal);
			refreshTrash();
		});
		modal.querySelector('[data-afcn-trash-close]').addEventListener('click', function () {
			window.AirfiberNext.closeDialog(modal);
		});
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
		bindWorkspace();
		bindTrash();

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
				openPayment(item);
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
