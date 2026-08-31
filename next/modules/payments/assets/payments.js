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
			meta.textContent = message || '';
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

	function statusPill(item) {
		const pill = document.createElement('span');
		const expired = item.status === 'expired';
		pill.className = 'afcn-pill ' + (expired ? 'afcn-pill-warning' : 'afcn-pill-success');
		const dot = document.createElement('span');
		dot.className = 'afcn-pill-dot';
		const label = document.createElement('span');
		label.textContent = expired ? 'Expired' : 'Active';
		pill.appendChild(dot);
		pill.appendChild(label);
		return pill;
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
		name.textContent = item.customer_name || item.account;
		const secondary = document.createElement('span');
		secondary.textContent = item.account + (item.phone ? ' · ' + item.phone : '');
		main.appendChild(name);
		main.appendChild(secondary);

		const detail = document.createElement('span');
		detail.className = 'afcn-payment-result-detail';
		const plan = document.createElement('span');
		plan.textContent = item.plan || 'Plan not set';
		const address = document.createElement('small');
		address.textContent = item.address || item.router_name || '';
		detail.appendChild(plan);
		detail.appendChild(address);

		const payment = document.createElement('span');
		payment.className = 'afcn-payment-result-payment';
		const last = document.createElement('strong');
		last.textContent = item.payment_date || 'No payment date';
		const amount = document.createElement('small');
		amount.textContent = money(item.payment_amount) || item.router_name || '';
		payment.appendChild(last);
		payment.appendChild(amount);

		const state = document.createElement('span');
		state.className = 'afcn-payment-result-state';
		state.appendChild(statusPill(item));

		const arrow = document.createElement('span');
		arrow.className = 'afcn-payment-result-arrow';
		arrow.setAttribute('aria-hidden', 'true');
		arrow.textContent = '›';

		button.appendChild(main);
		button.appendChild(detail);
		button.appendChild(payment);
		button.appendChild(state);
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

	function openPayment(item) {
		selected = item;
		const modal = dialog();
		if (!modal) {
			return;
		}
		modal.querySelector('[data-afcn-payment-dialog-name]').textContent = item.customer_name || item.account;
		modal.querySelector('[data-afcn-payment-dialog-account]').textContent = 'PPP: ' + item.account;
		modal.querySelector('[data-afcn-payment-dialog-plan]').textContent = 'Plan: ' + (item.plan || 'Not set');
		modal.querySelector('[data-afcn-payment-dialog-last]').textContent = 'Last payment: ' + (item.payment_date || 'None');

		const amount = modal.querySelector('[data-afcn-payment-amount]');
		const method = modal.querySelector('[data-afcn-payment-method]');
		if (amount) {
			amount.value = Number(item.payment_amount || 0) > 0 ? String(item.payment_amount) : '';
		}
		if (method) {
			method.value = ['cash', 'gcash'].includes(item.payment_method) ? item.payment_method : 'cash';
		}
		showDialogMessage(item.status === 'expired' ? 'This account is expired. Recording payment will not reconnect it automatically.' : '', 'warning');
		window.AirfiberNext.openDialog(modal);
		window.setTimeout(function () {
			if (amount) {
				amount.focus({ preventScroll: true });
				amount.select();
			}
		}, 60);
	}

	async function recordPayment(button) {
		if (!selected || button.disabled) {
			return;
		}
		const modal = dialog();
		const amountInput = modal.querySelector('[data-afcn-payment-amount]');
		const methodInput = modal.querySelector('[data-afcn-payment-method]');
		const amount = amountInput && amountInput.value !== '' ? Number(amountInput.value) : 0;
		const method = methodInput ? methodInput.value : 'cash';

		button.disabled = true;
		button.classList.add('is-loading');
		showDialogMessage('Recording payment…', 'info');

		try {
			const response = await window.AirfiberNext.action('payments', 'record-payment', {
				connection_id: selected.connection_id,
				secret_id: selected.secret_id,
				account: selected.account,
				amount: Number.isFinite(amount) ? amount : 0,
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
			button.disabled = false;
			button.classList.remove('is-loading');
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

		root.addEventListener('click', function (event) {
			const button = event.target.closest('[data-afcn-payment-record]');
			if (button) {
				event.preventDefault();
				recordPayment(button);
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
}());
