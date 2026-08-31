(function () {
	'use strict';

	let running = false;

	function statusManager() {
		return window.AirfiberNext && window.AirfiberNext.status ? window.AirfiberNext.status : window.AirfiberUIStatus;
	}

	function consoleNode(root) {
		return root ? root.querySelector('[data-afcn-tools-console]') : null;
	}

	function stamp() {
		const now = new Date();
		return now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
	}

	function log(root, message, level) {
		const consoleElement = consoleNode(root);
		if (!consoleElement) {
			return;
		}
		const line = document.createElement('div');
		line.className = 'afcn-tools-line is-' + (level || 'info');
		const label = document.createElement('span');
		const code = document.createElement('code');
		label.textContent = stamp();
		code.textContent = String(message || '');
		line.appendChild(label);
		line.appendChild(code);
		consoleElement.appendChild(line);
		consoleElement.scrollTop = consoleElement.scrollHeight;
		document.dispatchEvent(new CustomEvent('afcn:console:status', {
			detail: { level: level || 'info', message: String(message || '') }
		}));
	}

	function divider(root) {
		log(root, '────────────────────────────────────────', 'muted');
	}

	function init(root) {
		if (!root || root.dataset.afcnToolsWired) {
			return;
		}
		root.dataset.afcnToolsWired = '1';
		document.dispatchEvent(new CustomEvent('afcn:console:status', {
			detail: { level: 'info', message: 'Tools console ready.' }
		}));
		const clear = root.querySelector('[data-afcn-tools-clear]');
		if (clear) {
			clear.addEventListener('click', function () {
				const consoleElement = consoleNode(root);
				if (consoleElement) {
					consoleElement.innerHTML = '';
					log(root, 'Console cleared.', 'muted');
				}
			});
		}
	}

	async function diagnose(root, context) {
		log(root, '1/4 Inspecting health, budgets and lazy assets…', 'info');
		try {
			const result = await window.AirfiberNext.action('tools', 'diagnose-performance', {
				module: context.module,
				phase: context.phase || '',
				cause: context.cause || ''
			});
			(result.plan || []).forEach(function (item) {
				log(root, 'Plan: ' + item, 'muted');
			});
			log(root, result.scope || 'Safe automatic scope confirmed.', 'muted');
			return result;
		} catch (error) {
			log(root, 'Diagnostic inspection was unavailable: ' + (error.message || 'Unknown error.') + ' Continuing with the safe warm-up and REST retest.', 'warning');
			return { budget: 500, recommendations: [] };
		}
	}

	async function optimize(root, context) {
		log(root, '2/4 Applying safe runtime optimizations and controlled warm-up…', 'info');
		try {
			const result = await window.AirfiberNext.action('tools', 'optimize-performance', {
				module: context.module
			});
			(result.steps || []).forEach(function (step) {
				log(root, step.message, step.level || 'info');
			});
			return result;
		} catch (error) {
			log(root, 'Safe warm-up could not complete: ' + (error.message || 'Unknown error.') + ' Continuing with the REST retest.', 'warning');
			return { recommendations: [] };
		}
	}

	async function retest(root, context, diagnosis) {
		log(root, '3/4 Retesting the module REST request through AJAX…', 'info');
		try {
			const started = performance.now();
			await window.AirfiberNext.request('module/' + encodeURIComponent(context.module));
			const requestMs = performance.now() - started;
			const budget = Number(diagnosis.budget || 500);
			const withinBudget = budget <= 0 || requestMs <= budget;
			if (!withinBudget) {
				log(root, 'REST retest: ' + requestMs.toFixed(2) + ' ms — still above ' + budget.toFixed(2) + ' ms budget.', 'warning');
			} else {
				log(root, 'REST retest: ' + requestMs.toFixed(2) + ' ms — within the current budget.', 'success');
			}
			return { ok: true, withinBudget: withinBudget, requestMs: requestMs, budget: budget };
		} catch (error) {
			log(root, 'REST retest failed: ' + (error.message || 'Unknown error.'), 'error');
			return { ok: false, withinBudget: false, message: error.message || 'REST retest failed.' };
		}
	}

	async function resolveWarning(root, context, retestResult) {
		if (!context.warning || !retestResult.ok || !retestResult.withinBudget) {
			return false;
		}

		try {
			await window.AirfiberNext.action('tools', 'resolve-performance-warning', {
				warning: context.warning
			});
			document.dispatchEvent(new CustomEvent('afcn:performance-warning:resolved', {
				detail: { id: context.warning }
			}));
			log(root, 'Original warning marked resolved. It will return only if a new slow sample is recorded.', 'success');
			return true;
		} catch (error) {
			log(root, 'Retest passed, but the old warning could not be marked resolved: ' + (error.message || 'Unknown error.'), 'warning');
			return false;
		}
	}

	async function runFix(root, context, options) {
		options = options || {};
		const manageRunning = options.manageRunning !== false;
		if ((manageRunning && running) || !context || !context.module) {
			return false;
		}

		const status = statusManager();
		const sourceButton = options.sourceButton || context.sourceButton || null;
		if (manageRunning) {
			running = true;
		}
		if (status && sourceButton && manageRunning) {
			status.loading(sourceButton, 'Running performance FIX…', { alert: false });
		}
		divider(root);
		log(root, 'FIX started for ' + context.module + '.', 'info');
		if (context.cause) {
			log(root, 'Warning: ' + context.cause, 'warning');
		}

		let passed = false;
		try {
			const diagnosis = await diagnose(root, context);
			const optimized = await optimize(root, context);
			const retestResult = await retest(root, context, diagnosis);

			log(root, '4/4 Producing next-step recommendations…', 'info');
			const recommendations = (diagnosis.recommendations || []).concat(optimized.recommendations || []);
			Array.from(new Set(recommendations)).forEach(function (item) {
				log(root, 'Recommendation: ' + item, 'warning');
			});
			if (!recommendations.length) {
				log(root, 'Recommendation: collect another real navigation sample before changing code structure.', 'muted');
			}

			if (retestResult.ok && retestResult.withinBudget) {
				const resolved = await resolveWarning(root, context, retestResult);
				passed = resolved || !context.warning;
				log(root, 'FIX session complete. A new warning will appear automatically if the issue happens again.', 'success');
				if (status && sourceButton && manageRunning) {
					if (passed) {
						status.success(sourceButton, 'Performance retest passed.', { alert: false });
					} else {
						status.warning(sourceButton, 'Retest passed, but the original warning could not be marked resolved.', { alert: false });
					}
				}
			} else if (retestResult.ok) {
				const message = 'FIX finished, but the warning remains above budget.';
				log(root, message, 'warning');
				if (status && sourceButton && manageRunning) {
					status.warning(sourceButton, message, { alert: false });
				}
			} else {
				const message = retestResult.message || 'FIX finished with a REST error.';
				log(root, message + ' The warning remains open.', 'warning');
				if (status && sourceButton && manageRunning) {
					status.error(sourceButton, message, { alert: false });
				}
			}
		} catch (error) {
			const message = error.message || 'Unknown error.';
			log(root, 'FIX stopped unexpectedly: ' + message, 'error');
			if (status && sourceButton && manageRunning) {
				status.error(sourceButton, message, { alert: false });
			}
		} finally {
			if (manageRunning) {
				running = false;
			}
		}
		return passed;
	}

	async function runFixAll(root, context) {
		if (running) {
			return;
		}
		running = true;
		const status = statusManager();
		const sourceButton = context && context.sourceButton ? context.sourceButton : null;
		if (status && sourceButton) {
			status.loading(sourceButton, 'Fixing performance warnings…', { alert: false });
		}
		divider(root);
		log(root, 'FIX ALL started. Loading current fixable warnings…', 'info');

		let fixed = 0;
		let total = 0;
		try {
			const data = await window.AirfiberNext.query('settings', 'status', {});
			const events = Array.isArray(data.events) ? data.events.filter(function (item) {
				return item && item.fixable && item.module;
			}) : [];
			total = events.length;

			if (!total) {
				log(root, 'No fixable warnings are currently open.', 'success');
			} else {
				for (let i = 0; i < events.length; i++) {
					const item = events[i];
					log(root, 'FIX ALL ' + (i + 1) + '/' + total + ': ' + (item.module_name || item.module), 'info');
					const passed = await runFix(root, {
						warning: item.id || '',
						module: item.module || '',
						phase: item.phase || '',
						cause: item.cause || ''
					}, { manageRunning: false });
					if (passed) {
						fixed++;
					}
				}
			}

			const remaining = await window.AirfiberNext.query('settings', 'status', {});
			document.dispatchEvent(new CustomEvent('afcn:settings:status:refresh', {
				detail: remaining
			}));
			const message = 'FIX ALL complete: ' + fixed + '/' + total + ' resolved. ' + Number(remaining.total || 0) + ' warning/error item(s) remain.';
			log(root, message, Number(remaining.total || 0) > 0 ? 'warning' : 'success');
			if (status && sourceButton) {
				if (Number(remaining.total || 0) > 0) {
					status.warning(sourceButton, message, { alert: false });
				} else {
					status.success(sourceButton, message, { alert: false });
				}
			}
		} catch (error) {
			const message = error.message || 'FIX ALL failed.';
			log(root, 'FIX ALL stopped: ' + message, 'error');
			if (status && sourceButton) {
				status.error(sourceButton, message, { alert: false });
			}
		} finally {
			running = false;
		}
	}


	document.addEventListener('afcn:utility:opened', function (event) {
		if (!event.detail || event.detail.id !== 'tools') {
			return;
		}
		const root = event.detail.container ? event.detail.container.querySelector('[data-afcn-tools]') : null;
		init(root);
		const context = event.detail.context || {};
		if (context.action === 'fix') {
			runFix(root, context);
		} else if (context.action === 'fix-all') {
			runFixAll(root, context);
		}
	});

	init(document.querySelector('[data-afcn-tools]'));
}());
