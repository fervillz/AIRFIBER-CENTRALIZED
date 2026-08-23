(function () {
	'use strict';

	const storageKey = 'afcn-users-view';

	function parseData(root) {
		const node = root.querySelector('[data-afcn-users-data]');
		if (!node) {
			return { users: [], modules: [] };
		}
		try {
			return JSON.parse(node.textContent || '{}');
		} catch (error) {
			return { users: [], modules: [] };
		}
	}

	function setView(root, view) {
		const grid = root.querySelector('[data-afcn-user-grid]');
		const list = root.querySelector('[data-afcn-user-list]');
		const toggle = root.querySelector('[data-afcn-users-view-toggle]');
		const useList = view === 'list';

		if (grid) {
			grid.hidden = useList;
		}
		if (list) {
			list.hidden = !useList;
		}
		if (toggle) {
			toggle.classList.toggle('is-list', useList);
			toggle.setAttribute('aria-label', useList ? 'Show cards' : 'Show list');
		}

		try {
			window.localStorage.setItem(storageKey, useList ? 'list' : 'cards');
		} catch (error) {
			// Local storage is only a convenience. The view still works without it.
		}
	}

	function selectedModuleIds(form) {
		return Array.from(form.querySelectorAll('[data-afcn-access-module]:checked')).map(function (input) {
			return input.value;
		});
	}

	function syncVisibleModules(form) {
		const field = form.querySelector('[name="visible_modules"]');
		if (field) {
			field.value = selectedModuleIds(form).join(',');
		}
	}

	function wireAccessForm(form) {
		if (!form || form.dataset.afcnAccessWired) {
			return;
		}
		form.dataset.afcnAccessWired = '1';
		form.querySelectorAll('[data-afcn-access-module]').forEach(function (input) {
			input.addEventListener('change', function () {
				syncVisibleModules(form);
			});
		});
		syncVisibleModules(form);
	}

	function configureEditDialog(root, data, userId) {
		const dialog = root.querySelector('#afcn-edit-user-dialog');
		const form = dialog ? dialog.querySelector('form') : null;
		const user = (data.users || []).find(function (item) {
			return Number(item.id) === Number(userId);
		});
		if (!dialog || !form || !user) {
			return;
		}

		form.reset();
		form.querySelector('[name="user_id"]').value = user.id;
		form.querySelector('[name="display_name"]').value = user.display_name || '';
		form.querySelector('[name="email"]').value = user.email || '';
		form.querySelector('[name="password"]').value = '';

		const role = form.querySelector('[name="role"]');
		if (role) {
			role.value = user.role_key === 'airfiber_operator' ? 'airfiber_operator' : 'airfiber_admin';
			role.disabled = Boolean(user.is_wp_admin || user.is_super_admin);
		}

		const profileLocked = Boolean(user.is_wp_admin);
		['display_name', 'email', 'password'].forEach(function (name) {
			const field = form.querySelector('[name="' + name + '"]');
			if (field) {
				field.disabled = profileLocked;
			}
		});

		const visible = new Set(user.visible_modules || []);
		form.querySelectorAll('[data-afcn-access-module]').forEach(function (input) {
			input.checked = Boolean(user.is_super_admin || visible.has(input.value));
			input.disabled = Boolean(user.is_super_admin);
			input.closest('.afcn-user-access-option').classList.toggle('is-locked', Boolean(user.is_super_admin));
		});

		const muSection = form.querySelector('[data-afcn-user-mu-section]');
		if (muSection) {
			muSection.hidden = !user.is_super_admin;
			muSection.querySelectorAll('input').forEach(function (input) {
				input.checked = true;
				input.disabled = true;
			});
		}

		const note = form.querySelector('[data-afcn-user-edit-note]');
		if (note) {
			if (user.is_super_admin) {
				note.textContent = 'Super Admin always has every Airfiber menu and Core area.';
			} else if (user.is_wp_admin) {
				note.textContent = 'WordPress Administrator profile details stay managed in WordPress. Airfiber module visibility can still be changed here.';
			} else {
				note.textContent = 'Uncheck a module to hide it and block direct Airfiber access for this user.';
			}
		}

		syncVisibleModules(form);
		if (typeof dialog.showModal === 'function') {
			dialog.showModal();
		}
	}

	function init(root) {
		if (!root || root.dataset.afcnUsersWired) {
			return;
		}
		root.dataset.afcnUsersWired = '1';
		const data = parseData(root);
		const toggle = root.querySelector('[data-afcn-users-view-toggle]');

		let view = 'cards';
		try {
			view = window.localStorage.getItem(storageKey) === 'list' ? 'list' : 'cards';
		} catch (error) {
			view = 'cards';
		}
		setView(root, view);

		if (toggle) {
			toggle.addEventListener('click', function () {
				setView(root, toggle.classList.contains('is-list') ? 'cards' : 'list');
			});
		}

		root.querySelectorAll('[data-afcn-user-edit]').forEach(function (button) {
			button.addEventListener('click', function () {
				configureEditDialog(root, data, button.dataset.afcnUserEdit);
			});
		});

		wireAccessForm(root.querySelector('#afcn-add-user-dialog form'));
		wireAccessForm(root.querySelector('#afcn-edit-user-dialog form'));
	}

	function boot() {
		init(document.querySelector('[data-afcn-users]'));
	}

	document.addEventListener('afcn:module:loaded', function (event) {
		if (event.detail && event.detail.id === 'users') {
			boot();
		}
	});

	boot();
}());
