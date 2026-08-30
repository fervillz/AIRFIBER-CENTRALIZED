(function () {
	'use strict';

	if (!window.afcnApp) {
		return;
	}

	const cfg = window.afcnApp;
	const stage = document.getElementById('afcn-module-stage');
	const nav = Array.from(document.querySelectorAll('[data-afcn-module]'));
	const uiStatus = window.AirfiberUIStatus || null;
	const metricSampleRate = 0.15;
	const state = {
		current: '',
		context: '',
		cache: new Map(),
		styles: new Set(),
		scripts: new Set(),
		slotObserver: null
	};

	function path(value) {
		return String(value || '').replace(/^\/+/, '');
	}

	async function api(endpoint, options) {
		const opts = Object.assign({ method: 'GET', headers: {} }, options || {});
		opts.headers['X-WP-Nonce'] = cfg.nonce;

		if (opts.body && typeof opts.body !== 'string') {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(opts.body);
		}

		const response = await fetch(cfg.restUrl + path(endpoint), opts);
		let data = {};
		try {
			data = await response.json();
		} catch (error) {
			data = {};
		}

		if (!response.ok) {
			const error = new Error(data.message || cfg.labels.failed);
			error.status = response.status;
			throw error;
		}
		return data;
	}

	function reportMetric(module, metric, duration) {
		if (!Number.isFinite(duration) || duration <= 0) {
			return;
		}
		api('client-metric', {
			method: 'POST',
			body: {
				module: module,
				metric: metric,
				duration_ms: duration
			}
		}).catch(function () {});
	}

	function shouldSampleMetrics() {
		return Math.random() < metricSampleRate;
	}

	function nextPaint() {
		return new Promise(function (resolve) {
			if (document.hidden || typeof window.requestAnimationFrame !== 'function') {
				window.setTimeout(resolve, 0);
				return;
			}
			window.requestAnimationFrame(function () {
				resolve();
			});
		});
	}

	function resetSlotObserver() {
		if (!state.slotObserver) {
			return;
		}
		state.slotObserver.disconnect();
		state.slotObserver = null;
	}

	function loading(label) {
		resetSlotObserver();
		stage.innerHTML = '<div class="afcn-loading-state"><span class="afcn-spinner" aria-hidden="true"></span><strong></strong></div>';
		stage.querySelector('strong').textContent = label || cfg.labels.loading;
	}

	function setActive(id, context) {
		nav.forEach(function (button) {
			const buttonContext = button.dataset.afcnModuleContext || '';
			const inSubmenu = Boolean(button.closest('.afcn-nav-submenu'));
			const active = button.dataset.afcnModule === id && (!inSubmenu || (context && buttonContext === context));
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	}

	function routeFromHash() {
		const raw = window.location.hash.replace(/^#/, '');
		const separator = raw.indexOf('/');
		if (separator === -1) {
			return { id: raw, context: '' };
		}
		let context = '';
		try {
			context = decodeURIComponent(raw.slice(separator + 1));
		} catch (error) {
			context = '';
		}
		return { id: raw.slice(0, separator), context: context };
	}

	function routeHash(id, context) {
		return '#' + id + (context ? '/' + encodeURIComponent(context) : '');
	}

	function dispatchNavigationContext(id, context) {
		document.dispatchEvent(new CustomEvent('afcn:navigation:context', {
			detail: { module: id, context: context || '' }
		}));
	}

	function assetUrl(asset) {
		const join = asset.url.indexOf('?') === -1 ? '?' : '&';
		return asset.url + join + 'ver=' + encodeURIComponent(asset.ver || cfg.version);
	}

	function loadStyle(asset) {
		const url = assetUrl(asset);
		if (state.styles.has(url)) {
			return Promise.resolve();
		}
		state.styles.add(url);

		return new Promise(function (resolve, reject) {
			const link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = url;
			link.onload = resolve;
			link.onerror = function () {
				state.styles.delete(url);
				link.remove();
				reject(new Error('Module stylesheet could not be loaded.'));
			};
			document.head.appendChild(link);
		});
	}

	function loadScript(asset) {
		const url = assetUrl(asset);
		if (state.scripts.has(url)) {
			return Promise.resolve();
		}
		state.scripts.add(url);

		return new Promise(function (resolve, reject) {
			const script = document.createElement('script');
			script.src = url;
			script.async = false;
			script.onload = resolve;
			script.onerror = function () {
				state.scripts.delete(url);
				script.remove();
				reject(new Error('Module script could not be loaded.'));
			};
			document.body.appendChild(script);
		});
	}

	async function loadAssets(manifest) {
		manifest = manifest || {};
		for (const css of (manifest.css || [])) {
			await loadStyle(css);
		}
		for (const js of (manifest.js || [])) {
			await loadScript(js);
		}
	}

	function showError(error) {
		resetSlotObserver();
		stage.innerHTML = '<div class="afcn-card afcn-module-error"><h2>Module unavailable</h2><p></p><button type="button" class="afcn-button afcn-button-secondary">Try again</button></div>';
		stage.querySelector('p').textContent = error.message || cfg.labels.failed;
		wireModule(stage);
		stage.querySelector('button').addEventListener('click', function () {
			loadModule(state.current, true);
		});
	}

	async function applyModuleHtml(id, html, detail) {
		const started = performance.now();
		resetSlotObserver();
		stage.innerHTML = html;
		wireModule(stage);
		document.dispatchEvent(new CustomEvent('afcn:module:loaded', { detail: detail }));
		await nextPaint();
		return performance.now() - started;
	}

	async function loadModule(id, force, context) {
		if (!id) {
			return false;
		}

		if (context === undefined || context === null) {
			context = state.current === id ? state.context : '';
		}
		state.current = id;
		state.context = String(context || '');
		setActive(id, state.context);
		const sampleMetrics = shouldSampleMetrics();

		if (!force && state.cache.has(id)) {
			const cached = state.cache.get(id);
			const clientMs = await applyModuleHtml(id, cached.html, { id: id, cached: true });
			if (sampleMetrics) {
				reportMetric(id, 'client', clientMs);
			}
			dispatchNavigationContext(id, state.context);
			return true;
		}

		const navigationStarted = performance.now();
		loading();

		try {
			const transportStarted = performance.now();
			const data = await api('module/' + encodeURIComponent(id));
			const transportMs = performance.now() - transportStarted;

			const assetStarted = performance.now();
			await loadAssets(data.assets);
			const assetLoadMs = performance.now() - assetStarted;

			state.cache.set(id, { html: data.html });
			const clientMs = await applyModuleHtml(id, data.html, { id: id, data: data });
			const navigationMs = performance.now() - navigationStarted;

			if (sampleMetrics) {
				reportMetric(id, 'transport', transportMs);
				reportMetric(id, 'asset_load', assetLoadMs);
				reportMetric(id, 'client', clientMs);
				reportMetric(id, 'navigation', navigationMs);
			}
			dispatchNavigationContext(id, state.context);
			return true;
		} catch (error) {
			showError(error);
			return false;
		}
	}

	function toast(message, error) {
		const region = document.getElementById('afcn-toast-region');
		if (!region) {
			return;
		}
		const item = document.createElement('div');
		item.className = 'afcn-toast' + (error ? ' is-error' : '');
		item.textContent = message;
		region.appendChild(item);
		window.setTimeout(function () {
			item.remove();
		}, 3500);
	}

	function params(query) {
		const search = new URLSearchParams();
		Object.keys(query || {}).forEach(function (key) {
			const value = query[key];
			if (value !== undefined && value !== null) {
				search.append(key, value);
			}
		});
		const value = search.toString();
		return value ? '?' + value : '';
	}

	function moduleQuery(module, query, payload) {
		return api('module/' + encodeURIComponent(module) + '/query/' + encodeURIComponent(query) + params(payload));
	}

	function moduleAction(module, action, payload) {
		return api('module/' + encodeURIComponent(module) + '/action/' + encodeURIComponent(action), {
			method: 'POST',
			body: payload || {}
		});
	}

	async function loadChunk(module, chunk, target, payload) {
		const result = await api('module/' + encodeURIComponent(module) + '/chunk/' + encodeURIComponent(chunk) + params(payload));
		await loadAssets(result.assets);
		const element = typeof target === 'string' ? document.querySelector(target) : target;
		if (element) {
			element.innerHTML = result.html || '';
			wireModule(element);
			document.dispatchEvent(new CustomEvent('afcn:chunk:loaded', {
				detail: {
					module: module,
					chunk: chunk,
					target: element,
					data: result
				}
			}));
		}
		return result;
	}

	function slotError(item, error) {
		const label = item.dataset.afcnSlotLabel || item.dataset.afcnSlotModule || 'Module';
		item.innerHTML = '<div class="afcn-card afcn-slot-error"><div class="afcn-card-body"><strong></strong><p class="afcn-page-description"></p><button type="button" class="afcn-button afcn-button-secondary afcn-button-small">Try again</button></div></div>';
		item.querySelector('strong').textContent = label;
		item.querySelector('p').textContent = error.message || cfg.labels.failed;
		wireModule(item);
		item.querySelector('button').addEventListener('click', function () {
			item.dataset.afcnSlotState = '';
			loadSlotItem(item);
		});
	}

	async function loadSlotItem(item) {
		if (!item || item.dataset.afcnSlotState === 'loading' || item.dataset.afcnSlotState === 'loaded') {
			return;
		}

		const module = item.dataset.afcnSlotModule;
		const chunk = item.dataset.afcnSlotChunk;
		if (!module || !chunk) {
			return;
		}

		item.dataset.afcnSlotState = 'loading';
		item.setAttribute('aria-busy', 'true');
		try {
			await loadChunk(module, chunk, item, {});
			item.dataset.afcnSlotState = 'loaded';
			item.removeAttribute('aria-busy');
		} catch (error) {
			item.dataset.afcnSlotState = 'error';
			item.removeAttribute('aria-busy');
			slotError(item, error);
		}
	}

	function observeSlotItem(item) {
		if (!item || item.dataset.afcnSlotObserved || item.dataset.afcnSlotState === 'loaded') {
			return;
		}
		item.dataset.afcnSlotObserved = '1';

		if (typeof window.IntersectionObserver !== 'function') {
			loadSlotItem(item);
			return;
		}

		if (!state.slotObserver) {
			state.slotObserver = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (!entry.isIntersecting) {
						return;
					}
					state.slotObserver.unobserve(entry.target);
					loadSlotItem(entry.target);
				});
			}, { rootMargin: '200px 0px' });
		}

		state.slotObserver.observe(item);
	}

	function wireSlots(root) {
		root.querySelectorAll('[data-afcn-slot-item]').forEach(observeSlotItem);
	}

	function openDialog(id) {
		const dialog = typeof id === 'string' ? document.getElementById(id) : id;
		if (dialog && typeof dialog.showModal === 'function') {
			if (uiStatus) {
				uiStatus.prepareDialog(dialog);
			}
			dialog.showModal();
		}
	}

	function closeDialog(dialog) {
		const item = typeof dialog === 'string' ? document.getElementById(dialog) : dialog;
		if (item && typeof item.close === 'function') {
			item.close();
		}
	}

	function actionLoadingMessage(action) {
		action = String(action || '').toLowerCase();
		if (action.includes('connect') || action.includes('test') || action.includes('probe')) {
			return 'Connecting…';
		}
		if (action.includes('refresh')) {
			return 'Refreshing…';
		}
		if (action.includes('delete') || action.includes('trash') || action.includes('remove')) {
			return 'Processing…';
		}
		return 'Currently saving, kindly wait.';
	}

	function submitButton(form) {
		const active = document.activeElement;
		if (active && active.tagName === 'BUTTON' && active.type === 'submit' && form.contains(active)) {
			return active;
		}
		return form.querySelector('[type="submit"]');
	}

	async function submitAction(form) {
		const module = form.dataset.afcnModule || state.current;
		const action = form.dataset.afcnAction;
		if (!module || !action) {
			return;
		}
		if (form.dataset.afcnConfirm && !window.confirm(form.dataset.afcnConfirm)) {
			return;
		}

		const button = submitButton(form);
		const dialog = form.closest('dialog.afcn-dialog');
		if (button && uiStatus) {
			uiStatus.loading(button, actionLoadingMessage(action));
		}
		if (button) {
			button.disabled = true;
		}

		const payload = {};
		new FormData(form).forEach(function (value, key) {
			payload[key] = value;
		});

		try {
			const result = await moduleAction(module, action, payload);
			const message = (result && result.message) || cfg.labels.saved;
			if (result && result.generated_password) {
				window.prompt('Generated password — copy it now:', result.generated_password);
			}
			if (button && uiStatus) {
				uiStatus.success(button, message);
			}
			toast(message, false);

			if (dialog) {
				if (result && result.refresh_nav) {
					dialog.dataset.afcnRefreshPage = '1';
				} else if (!result || result.reload !== false) {
					dialog.dataset.afcnRefreshModule = module;
				}
				return;
			}

			if (result && result.refresh_nav) {
				window.location.reload();
				return;
			}
			if (!result || result.reload !== false) {
				state.cache.delete(module);
				await loadModule(module, true);
			}
		} catch (error) {
			const message = error.message || cfg.labels.failed;
			if (button && uiStatus) {
				uiStatus.error(button, message);
			}
			toast(message, true);
		} finally {
			if (button) {
				button.disabled = false;
			}
		}
	}

	function wireModule(root) {
		if (uiStatus) {
			uiStatus.wire(root);
		}

		root.querySelectorAll('form[data-afcn-action]').forEach(function (form) {
			if (form.dataset.afcnWired) {
				return;
			}
			form.dataset.afcnWired = '1';
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				submitAction(form);
			});
		});

		root.querySelectorAll('[data-afcn-dialog-open]').forEach(function (button) {
			if (button.dataset.afcnWired) {
				return;
			}
			button.dataset.afcnWired = '1';
			button.addEventListener('click', function () {
				openDialog(button.dataset.afcnDialogOpen);
			});
		});

		root.querySelectorAll('[data-afcn-dialog-close]').forEach(function (button) {
			if (button.dataset.afcnWired) {
				return;
			}
			button.dataset.afcnWired = '1';
			button.addEventListener('click', function () {
				closeDialog(button.closest('dialog'));
			});
		});

		wireSlots(root);
	}

	nav.forEach(function (button) {
		button.addEventListener('click', async function () {
			const id = button.dataset.afcnModule;
			const context = button.dataset.afcnModuleContext || '';
			const targetHash = routeHash(id, context);
			if (window.location.hash !== targetHash) {
				history.pushState(null, '', targetHash);
			}
			if (uiStatus) {
				uiStatus.loading(button, 'Loading…', { alert: false });
			}
			const loaded = await loadModule(id, false, context);
			if (uiStatus) {
				if (loaded) {
					uiStatus.success(button, 'Loaded.', { alert: false, transient: true, delay: 1000 });
				} else {
					uiStatus.error(button, 'This module could not be loaded.', { alert: false });
				}
			}
		});
	});

	document.addEventListener('afcn:dialog:closed', function (event) {
		const detail = event.detail || {};
		if (detail.refreshPage) {
			window.location.reload();
			return;
		}
		if (detail.refreshModule) {
			state.cache.delete(detail.refreshModule);
			loadModule(detail.refreshModule, true);
		}
	});

	window.addEventListener('hashchange', function () {
		const route = routeFromHash();
		if (nav.some(function (button) { return button.dataset.afcnModule === route.id; })) {
			loadModule(route.id, false, route.context);
		}
	});

	window.AirfiberNext = Object.freeze({
		request: api,
		loadModule: loadModule,
		query: moduleQuery,
		action: moduleAction,
		loadChunk: loadChunk,
		toast: toast,
		openDialog: openDialog,
		closeDialog: closeDialog,
		wire: wireModule,
		status: uiStatus,
		config: cfg
	});

	if (uiStatus) {
		uiStatus.wire(document);
	}

	const requested = routeFromHash();
	const initial = nav.some(function (button) {
		return button.dataset.afcnModule === requested.id;
	}) ? requested.id : cfg.defaultModule;

	loadModule(initial, false, initial === requested.id ? requested.context : '');
}());
