(function () {
	'use strict';

	if (!window.AirfiberNext) {
		return;
	}

	const drawer = document.getElementById('afcn-utility-drawer');
	const stage = drawer ? drawer.querySelector('[data-afcn-utility-stage]') : null;
	const title = drawer ? drawer.querySelector('[data-afcn-utility-title]') : null;
	const closeButton = drawer ? drawer.querySelector('[data-afcn-utility-close]') : null;
	const loadedAssets = new Set();
	let current = '';
	let currentHtml = '';

	if (!drawer || !stage) {
		return;
	}

	function assetUrl(asset) {
		const join = String(asset.url || '').indexOf('?') === -1 ? '?' : '&';
		return String(asset.url || '') + join + 'ver=' + encodeURIComponent(asset.ver || window.AirfiberNext.config.version);
	}

	function loadAsset(type, asset) {
		const url = assetUrl(asset);
		if (!url || loadedAssets.has(url)) {
			return Promise.resolve();
		}

		loadedAssets.add(url);
		return new Promise(function (resolve, reject) {
			let node;
			if (type === 'css') {
				node = document.createElement('link');
				node.rel = 'stylesheet';
				node.href = url;
				document.head.appendChild(node);
			} else {
				node = document.createElement('script');
				node.src = url;
				node.async = false;
				document.body.appendChild(node);
			}
			node.onload = resolve;
			node.onerror = function () {
				loadedAssets.delete(url);
				node.remove();
				reject(new Error('Utility asset could not be loaded.'));
			};
		});
	}

	async function loadAssets(manifest) {
		manifest = manifest || {};
		for (const asset of (manifest.css || [])) {
			await loadAsset('css', asset);
		}
		for (const asset of (manifest.js || [])) {
			await loadAsset('js', asset);
		}
	}

	function openDrawer() {
		drawer.classList.add('is-open');
		drawer.setAttribute('aria-hidden', 'false');
	}

	function closeDrawer() {
		drawer.classList.remove('is-open');
		drawer.setAttribute('aria-hidden', 'true');
	}

	function loading() {
		stage.innerHTML = '<div class="afcn-utility-loading"><span class="afcn-spinner" aria-hidden="true"></span><strong>Loading tools…</strong></div>';
	}

	function contextFrom(element) {
		return {
			action: element.dataset.afcnUtilityAction || '',
			module: element.dataset.afcnUtilityModuleTarget || '',
			phase: element.dataset.afcnUtilityPhase || '',
			cause: element.dataset.afcnUtilityCause || ''
		};
	}

	function dispatchOpened(id, context, data) {
		document.dispatchEvent(new CustomEvent('afcn:utility:opened', {
			detail: {
				id: id,
				context: context || {},
				container: stage,
				data: data || {}
			}
		}));
	}

	async function openUtility(id, context, force) {
		id = String(id || '').replace(/[^a-z0-9-]/g, '');
		if (!id) {
			return;
		}

		openDrawer();

		if (!force && current === id && currentHtml) {
			dispatchOpened(id, context, { cached: true });
			return;
		}

		loading();
		try {
			const data = await window.AirfiberNext.request('module/' + encodeURIComponent(id));
			await loadAssets(data.assets);
			current = id;
			currentHtml = data.html || '';
			stage.innerHTML = currentHtml;
			window.AirfiberNext.wire(stage);
			if (title) {
				title.textContent = data.name || 'Tools';
			}
			dispatchOpened(id, context, data);
		} catch (error) {
			stage.innerHTML = '<div class="afcn-card afcn-module-error"><h2>Tool unavailable</h2><p></p></div>';
			stage.querySelector('p').textContent = error.message || 'The tool could not be loaded.';
		}
	}

	document.querySelectorAll('[data-afcn-utility-module]').forEach(function (button) {
		button.addEventListener('click', function () {
			openUtility(button.dataset.afcnUtilityModule, { source: 'navigation' }, false);
		});
	});

	document.addEventListener('click', function (event) {
		const trigger = event.target.closest('[data-afcn-open-utility]');
		if (!trigger) {
			return;
		}
		event.preventDefault();
		openUtility(trigger.dataset.afcnOpenUtility, contextFrom(trigger), false);
	});

	if (closeButton) {
		closeButton.addEventListener('click', closeDrawer);
	}

	window.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
			closeDrawer();
		}
	});

	window.AirfiberNextUtility = Object.freeze({
		open: openUtility,
		close: closeDrawer
	});
}());
