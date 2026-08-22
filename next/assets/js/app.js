(function () {
	'use strict';

	if (!window.afcnApp) {
		return;
	}

	const cfg = window.afcnApp;
	const stage = document.getElementById('afcn-module-stage');
	const nav = Array.from(document.querySelectorAll('[data-afcn-module]'));
	const metricSampleRate = 0.15;
	const state = {
		current: '',
		cache: new Map(),
		styles: new Set(),
		scripts: new Set()
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

	function loading(label) {
		stage.innerHTML = '<div class="afcn-loading-state"><span class="afcn-spinner" aria-hidden="true"></span><strong></strong></div>';
		stage.querySelector('strong').textContent = label || cfg.labels.loading;
	}

	function setActive(id) {
		nav.forEach(function (button) {
			const active = button.dataset.afcnModule === id;
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
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
			link.onerror = reject;
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
			script.onerror = reject;
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
		stage.innerHTML = '<div class="afcn-card afcn-module-error"><h2>Module unavailable</h2><p></p><button type="button" class="afcn-button afcn-button-secondary">Try again</button></div>';
		stage.querySelector('p').textContent = error.message || cfg.labels.failed;
		stage.querySelector('button').addEventListener('click', function () {
			loadModule(state.current, true);
		});
	}

	async function applyModuleHtml(id, html, detail) {
		const started = performance.now();
		stage.innerHTML = html;
		wireModule(stage);
		document.dispatchEvent(new CustomEvent('afcn:module:loaded', { detail: detail }));
		await nextPaint();
		return performance.now() - started;
	}

	async function loadModule(id, force) {
		if (!id) {
			return;
		}

		state.current = id;
		setActive(id);
		const sampleMetrics = shouldSampleMetrics();

		if (!force && state.cache.has(id)) {
			const cached = state.cache.get(id);
			const clientMs = await applyModuleHtml(id, cached.html, { id: id, cached: true });
			if (sampleMetrics) {
				reportMetric(id, 'client', clientMs);
			}
			return;
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
		} catch (error) {
			showError(error);
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
		const element = typeof target === 'string' ? document.querySelector(target) : target;
		if (element) {
			element.innerHTML = result.html || '';
			wireModule(element);
		}
		return result;
	}

	function openDialog(id) {
		const dialog = typeof id === 'string' ? document.getElementById(id) : id;
		if (dialog && typeof dialog.showModal === 'function') {
			dialog.showModal();
		}
	}

	function closeDialog(dialog) {
		const item = typeof dialog === 'string' ? document.getElementById(dialog) : dialog;
		if (item && typeof item.close === 'function') {
			item.close();
		}
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

		const button = form.querySelector('[type="submit"]');
		if (button) {
			button.disabled = true;
		}

		const payload = {};
		new FormData(form).forEach(function (value, key) {
			payload[key] = value;
		});

		try {
			const result = await moduleAction(module, action, payload);
			if (result && result.generated_password) {
				window.prompt('Generated password — copy it now:', result.generated_password);
			}
			toast((result && result.message) || cfg.labels.saved, false);
			if (result && result.refresh_nav) {
				window.location.reload();
				return;
			}
			if (!result || result.reload !== false) {
				state.cache.delete(module);
				await loadModule(module, true);
			}
		} catch (error) {
			toast(error.message || cfg.labels.failed, true);
		} finally {
			if (button) {
				button.disabled = false;
			}
		}
	}

	function wireModule(root) {
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
	}

	nav.forEach(function (button) {
		button.addEventListener('click', function () {
			const id = button.dataset.afcnModule;
			if (window.location.hash !== '#' + id) {
				history.pushState(null, '', '#' + id);
			}
			loadModule(id, false);
		});
	});

	window.addEventListener('hashchange', function () {
		const id = window.location.hash.replace(/^#/, '');
		if (nav.some(function (button) { return button.dataset.afcnModule === id; })) {
			loadModule(id, false);
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
		config: cfg
	});

	const requested = window.location.hash.replace(/^#/, '');
	const initial = nav.some(function (button) {
		return button.dataset.afcnModule === requested;
	}) ? requested : cfg.defaultModule;

	loadModule(initial, false);
}());
