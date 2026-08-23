(function () {
	'use strict';

	let running = false;

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
	}

	function divider(root) {
		log(root, '────────────────────────────────────────', 'muted');
	}

	function init(root) {
		if (!root || root.dataset.afcnToolsWired) {
			return;
		}
		root.dataset.afcnToolsWired = '1';
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
			if (budget > 0 && requestMs > budget) {
				log(root, 'REST retest: ' + requestMs.toFixed(2) + ' ms — still above ' + budget.toFixed(2) + ' ms budget.', 'warning');
			} else {
				log(root, 'REST retest: ' + requestMs.toFixed(2) + ' ms — within the current budget.', 'success');
			}
			return true;
		} catch (error) {
			log(root, 'REST retest failed: ' + (error.message || 'Unknown error.'), 'error');
			return false;
		}
	}

	async function runFix(root, context) {
		if (running || !context || !context.module) {
			return;
		}

		running = true;
		divider(root);
		log(root, 'FIX started for ' + context.module + '.', 'info');
		if (context.cause) {
			log(root, 'Warning: ' + context.cause, 'warning');
		}

		try {
			const diagnosis = await diagnose(root, context);
			const optimized = await optimize(root, context);
			const retestOk = await retest(root, context, diagnosis);

			log(root, '4/4 Producing next-step recommendations…', 'info');
			const recommendations = (diagnosis.recommendations || []).concat(optimized.recommendations || []);
			Array.from(new Set(recommendations)).forEach(function (item) {
				log(root, 'Recommendation: ' + item, 'warning');
			});
			if (!recommendations.length) {
				log(root, 'Recommendation: collect another real navigation sample before changing code structure.', 'muted');
			}

			if (retestOk) {
				log(root, 'FIX session complete. Navigate to the module normally to collect the next real navigation sample.', 'success');
			} else {
				log(root, 'FIX session finished with a REST error. Review the lines above before changing module code.', 'warning');
			}
		} catch (error) {
			log(root, 'FIX stopped unexpectedly: ' + (error.message || 'Unknown error.'), 'error');
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
		}
	});

	init(document.querySelector('[data-afcn-tools]'));
}());
