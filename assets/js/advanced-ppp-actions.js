( function () {
	'use strict';

	let frame = 0;
	let menu = null;
	let menuRow = null;
	let menuUser = null;
	let menuAnchor = null;
	let deleteDialog = null;
	let deleteUser = null;
	let quickDialog = null;
	let quickRow = null;
	let quickUser = null;

	function advanced() {
		return document.body.classList.contains( 'afc-admin-mode-advanced' ) && document.body.classList.contains( 'afc-workspace-active' );
	}

	function escapeHtml( value ) {
		const node = document.createElement( 'div' );
		node.textContent = null == value ? '' : String( value );
		return node.innerHTML;
	}

	function escapeAttr( value ) {
		return String( null == value ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function rowUser( row ) {
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function icon( name ) {
		const paths = {
			money: '<path d="M4 7h16v10H4z"/><circle cx="12" cy="12" r="2.5"/><path d="M7 7a3 3 0 0 1-3 3M17 7a3 3 0 0 0 3 3M7 17a3 3 0 0 0-3-3M17 17a3 3 0 0 1 3-3"/>',
			edit: '<path d="M4 20h4l11-11-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/>',
			calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
			signal: '<path d="M5 19v-3M10 19v-6M15 19v-9M20 19V7"/>',
			plug: '<path d="M8 3v5M16 3v5M6 8h12v2a6 6 0 0 1-6 6v5M9 21h6"/>',
			clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			trash: '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"/>',
			warning: '<path d="M12 4 3.5 20h17L12 4Z"/><path d="M12 9v5M12 17h.01"/>',
			more: '<circle cx="5" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1" fill="currentColor" stroke="none"/>'
		};
		return '<svg class="afc-ppp-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ( paths[ name ] || paths.more ) + '</svg>';
	}

	function waitFor( test, callback, attempts ) {
		attempts = undefined === attempts ? 50 : attempts;
		const result = test();
		if ( result ) {
			callback( result );
			return;
		}
		if ( attempts <= 0 ) return;
		window.setTimeout( function () { waitFor( test, callback, attempts - 1 ); }, 100 );
	}

	function ensureManageButton() {
		if ( ! advanced() ) return;
		const button = document.getElementById( 'afc-find-edit-ppp' );
		if ( ! button ) return;
		button.classList.add( 'afc-advanced-manage-ppp' );
		button.setAttribute( 'aria-label', 'Manage PPP' );
		button.setAttribute( 'title', 'Manage PPP' );
	}

	function closeQuick() {
		if ( quickDialog && quickDialog.open ) quickDialog.close();
	}

	function openEditor( username ) {
		closeMenu();
		closeQuick();
		const opener = document.getElementById( 'afc-find-edit-ppp' );
		if ( ! opener ) return;
		opener.click();
		waitFor(
			function () {
				const dialog = document.getElementById( 'afc-ppp-manage-dialog' );
				return dialog && dialog.open ? document.getElementById( 'afc-ppp-manager-search' ) : null;
			},
			function ( search ) {
				search.value = username;
				search.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				waitFor(
					function () {
						return Array.from( document.querySelectorAll( '#afc-ppp-manager-list .afc-ppp-manager-person' ) ).find( function ( button ) {
							const account = button.querySelector( 'span' );
							return account && account.textContent.split( ' · ' )[ 0 ].trim() === username;
						} );
					},
					function ( button ) { button.click(); },
					50
				);
			},
			50
		);
	}

	function openScheduler( username ) {
		closeMenu();
		closeQuick();
		if ( window.AFCUI && 'function' === typeof window.AFCUI.openPanel ) {
			window.AFCUI.openPanel( 'schedulers', 'accounts' );
		} else {
			const nav = document.querySelector( '[data-afc-app-panel="schedulers"]' );
			if ( nav ) nav.click();
		}
		waitFor(
			function () { return document.querySelector( '[data-afc-panel="schedulers"] [data-afc-scheduler-search]' ); },
			function ( search ) {
				const accounts = document.querySelector( '[data-afc-panel="schedulers"] [data-afc-scheduler-view="accounts"]' );
				if ( accounts && accounts.getAttribute( 'aria-pressed' ) !== 'true' ) accounts.click();
				search.value = username;
				search.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				const body = document.querySelector( '[data-afc-panel="schedulers"] [data-afc-scheduler-body]' );
				if ( body && /read mikrotik schedulers to begin/i.test( body.textContent || '' ) ) {
					const refresh = document.querySelector( '[data-afc-panel="schedulers"] [data-afc-scheduler-refresh]' );
					if ( refresh ) refresh.click();
				}
			},
			70
		);
	}

	function opticalError( user, cell ) {
		const optical = user && user.optical ? user.optical : {};
		let message = optical.message || '';
		if ( ! message && cell ) message = String( cell.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		if ( ! user.imported ) return message || 'Import this PPP account before matching its ONU.';
		if ( ! optical.mapped ) return message || 'PPP account has no confident ONU match yet.';
		return message || 'No RX power reading is available for this ONU.';
	}

	function openRx( row, user ) {
		closeMenu();
		closeQuick();
		const button = row && row.querySelector( '.afc-map-onu' );
		if ( button ) {
			button.click();
			return;
		}
		const cell = row && row.querySelector( 'td:nth-child(6)' );
		pageNotice( opticalError( user || rowUser( row ), cell ), 'warning' );
	}

	function pageNotice( message, type ) {
		const target = document.getElementById( 'afc-ppp-notice' );
		if ( ! target ) return;
		const alert = document.createElement( 'div' );
		alert.className = 'alert alert-' + ( type || 'info' );
		alert.textContent = message;
		target.replaceChildren( alert );
		target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function ensureDeleteDialog() {
		if ( deleteDialog ) return deleteDialog;
		deleteDialog = document.createElement( 'dialog' );
		deleteDialog.className = 'afc-ppp-delete-confirm';
		deleteDialog.innerHTML =
			'<form method="dialog">' +
			'<header><div><small>PPP ACCOUNT</small><h2>Delete PPP?</h2></div><button type="button" data-afc-delete-cancel aria-label="Close">×</button></header>' +
			'<main><p class="afc-ppp-delete-name"></p><p>This removes the PPP account, matching scheduler and linked Airfiber record.</p><label>Type the PPP username to confirm<input type="text" autocomplete="off" data-afc-delete-confirm-input></label></main>' +
			'<footer><button type="button" class="btn btn-link" data-afc-delete-cancel>Cancel</button><button type="button" class="btn btn-danger" data-afc-delete-confirm-button disabled>Delete PPP</button></footer>' +
			'</form>';
		document.body.appendChild( deleteDialog );
		const input = deleteDialog.querySelector( '[data-afc-delete-confirm-input]' );
		const submit = deleteDialog.querySelector( '[data-afc-delete-confirm-button]' );
		input.addEventListener( 'input', function () {
			submit.disabled = ! deleteUser || input.value.trim() !== String( deleteUser.name || '' );
		} );
		deleteDialog.addEventListener( 'click', function ( event ) {
			if ( event.target === deleteDialog || event.target.closest( '[data-afc-delete-cancel]' ) ) deleteDialog.close();
		} );
		submit.addEventListener( 'click', deleteConfirmed );
		return deleteDialog;
	}

	function askDelete( user ) {
		closeMenu();
		closeQuick();
		const dialog = ensureDeleteDialog();
		deleteUser = user;
		dialog.querySelector( '.afc-ppp-delete-name' ).innerHTML = '<strong>' + escapeHtml( user.customer_name || user.name ) + '</strong><span>' + escapeHtml( user.name ) + '</span>';
		const input = dialog.querySelector( '[data-afc-delete-confirm-input]' );
		input.value = '';
		dialog.querySelector( '[data-afc-delete-confirm-button]' ).disabled = true;
		if ( dialog.open ) dialog.close();
		dialog.showModal();
		window.setTimeout( function () { input.focus(); }, 80 );
	}

	function deleteConfirmed() {
		if ( ! deleteUser || ! window.afcPPPOperationsUX ) return;
		const cfg = window.afcPPPOperationsUX;
		const input = deleteDialog.querySelector( '[data-afc-delete-confirm-input]' );
		const button = deleteDialog.querySelector( '[data-afc-delete-confirm-button]' );
		if ( input.value.trim() !== String( deleteUser.name || '' ) ) return;
		button.disabled = true;
		button.textContent = 'Deleting…';
		window.jQuery.post( cfg.ajaxUrl, {
			action: 'afc_ppp_delete_account',
			nonce: cfg.nonce,
			id: deleteUser.id,
			username: deleteUser.name,
			confirmation: input.value.trim()
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				pageNotice( response && response.data && response.data.message ? response.data.message : 'The PPP account could not be deleted.', 'danger' );
				return;
			}
			deleteDialog.close();
			pageNotice( response.data.message || 'PPP account deleted.', 'success' );
			const refresh = document.getElementById( 'afc-refresh-ppp' );
			if ( refresh ) refresh.click();
		} ).fail( function () {
			pageNotice( 'The delete request failed. Check the MikroTik connection.', 'danger' );
		} ).always( function () {
			button.disabled = false;
			button.textContent = 'Delete PPP';
		} );
	}

	function rxLabel( user ) {
		const optical = user.optical || {};
		if ( ! user.imported ) return 'RX power · Import first';
		if ( null !== optical.rx_power && '' !== optical.rx_power && undefined !== optical.rx_power ) {
			return 'RX power · ' + Number( optical.rx_power ).toFixed( 2 ) + ' dBm';
		}
		return optical.mapped ? 'RX power · No reading' : 'RX power · No OLT match';
	}

	function ensureMenu() {
		if ( menu ) return menu;
		menu = document.createElement( 'div' );
		menu.className = 'afc-ppp-more-popover';
		menu.hidden = true;
		menu.setAttribute( 'role', 'menu' );
		document.body.appendChild( menu );
		menu.addEventListener( 'click', function ( event ) {
			const action = event.target.closest( '[data-afc-ppp-menu-action]' );
			if ( ! action || ! menuUser ) return;
			const type = action.getAttribute( 'data-afc-ppp-menu-action' );
			if ( type === 'service' ) {
				const serviceButton = menuRow && menuRow.querySelector( '.afc-expire, .afc-reconnect' );
				closeMenu();
				closeQuick();
				if ( serviceButton ) serviceButton.click();
				return;
			}
			if ( type === 'scheduler' ) return openScheduler( menuUser.name );
			if ( type === 'rx' ) return openRx( menuRow, menuUser );
			if ( type === 'delete' ) return askDelete( menuUser );
		} );
		return menu;
	}

	function menuHtml( user, row ) {
		const expired = String( user.actual_profile || '' ).toLowerCase() === 'expired';
		const service = expired ? 'Reconnect' : 'Expire';
		const serviceIcon = expired ? 'plug' : 'clock';
		const rxDisabled = ! user.imported;
		return '<div class="afc-ppp-more-head"><strong>' + escapeHtml( user.customer_name || user.name ) + '</strong><span>' + escapeHtml( user.name ) + '</span></div>' +
			'<button type="button" role="menuitem" data-afc-ppp-menu-action="service">' + icon( serviceIcon ) + '<span>' + service + '</span></button>' +
			'<button type="button" role="menuitem" data-afc-ppp-menu-action="scheduler">' + icon( 'calendar' ) + '<span>View scheduler</span></button>' +
			'<button type="button" role="menuitem" data-afc-ppp-menu-action="rx"' + ( rxDisabled ? ' disabled' : '' ) + '>' + icon( 'signal' ) + '<span>' + escapeHtml( rxLabel( user ) ) + '</span></button>' +
			'<div class="afc-ppp-more-divider"></div>' +
			'<button type="button" class="is-danger" role="menuitem" data-afc-ppp-menu-action="delete">' + icon( 'trash' ) + '<span>Delete PPP</span></button>';
	}

	function positionMenu() {
		if ( ! menu || menu.hidden || ! menuAnchor ) return;
		const rect = menuAnchor.getBoundingClientRect();
		menu.style.visibility = 'hidden';
		menu.style.left = '12px';
		menu.style.top = '12px';
		const width = menu.offsetWidth;
		const height = menu.offsetHeight;
		const left = Math.max( 12, Math.min( window.innerWidth - width - 12, rect.right - width ) );
		let top = rect.bottom + 7;
		if ( top + height > window.innerHeight - 12 ) top = Math.max( 12, rect.top - height - 7 );
		menu.style.left = Math.round( left ) + 'px';
		menu.style.top = Math.round( top ) + 'px';
		menu.style.visibility = 'visible';
	}

	function openMenu( anchor, row, user ) {
		const popover = ensureMenu();
		if ( ! popover.hidden && menuAnchor === anchor ) {
			closeMenu();
			return;
		}
		const dialogHost = anchor.closest( 'dialog' );
		const host = dialogHost || document.body;
		if ( popover.parentNode !== host ) host.appendChild( popover );
		menuAnchor = anchor;
		menuRow = row;
		menuUser = user;
		popover.innerHTML = menuHtml( user, row );
		popover.hidden = false;
		anchor.setAttribute( 'aria-expanded', 'true' );
		positionMenu();
	}

	function closeMenu() {
		if ( menuAnchor ) menuAnchor.setAttribute( 'aria-expanded', 'false' );
		if ( menu ) menu.hidden = true;
		menuAnchor = null;
		menuRow = null;
		menuUser = null;
	}

	function compactOpticalCell( row, user ) {
		const cell = row.querySelector( 'td:nth-child(6)' );
		if ( ! cell ) return;
		const text = String( cell.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		const optical = user.optical || {};
		const hasReading = /-?\d+(?:\.\d+)?\s*dBm/i.test( text ) ||
			( null !== optical.rx_power && '' !== optical.rx_power && undefined !== optical.rx_power );
		if ( hasReading || /loading|refreshing|checking|reading optical/i.test( text ) ) return;
		if ( cell.querySelector( '.afc-ppp-optical-warning' ) ) return;

		const message = opticalError( user, cell );
		cell._afcOpticalOriginalHtml = cell.innerHTML;
		cell.dataset.afcCompactOptical = '1';
		cell.dataset.afcOpticalError = message;

		const mapButton = cell.querySelector( '.afc-map-onu' );
		const holder = mapButton ? document.createElement( 'span' ) : null;
		if ( holder ) {
			holder.hidden = true;
			holder.className = 'afc-ppp-optical-native-action';
			holder.appendChild( mapButton );
		}

		const warning = document.createElement( 'span' );
		warning.className = 'afc-ppp-optical-warning';
		warning.tabIndex = 0;
		warning.setAttribute( 'role', 'img' );
		warning.setAttribute( 'aria-label', message );
		warning.setAttribute( 'title', message );
		warning.setAttribute( 'data-tooltip', message );
		warning.innerHTML = icon( 'warning' );
		cell.replaceChildren( warning );
		if ( holder ) cell.appendChild( holder );
	}

	function restoreOpticalCell( row ) {
		const cell = row.querySelector( 'td:nth-child(6)' );
		if ( ! cell || cell.dataset.afcCompactOptical !== '1' ) return;
		if ( cell.querySelector( '.afc-ppp-optical-warning' ) && undefined !== cell._afcOpticalOriginalHtml ) {
			cell.innerHTML = cell._afcOpticalOriginalHtml;
		}
		delete cell.dataset.afcCompactOptical;
		delete cell.dataset.afcOpticalError;
		delete cell._afcOpticalOriginalHtml;
	}

	function quickValue( value, fallback ) {
		return escapeHtml( value || fallback || '—' );
	}

	function quickOpticalHtml( user, row ) {
		const optical = user.optical || {};
		if ( null !== optical.rx_power && '' !== optical.rx_power && undefined !== optical.rx_power ) {
			return '<strong>' + escapeHtml( Number( optical.rx_power ).toFixed( 2 ) ) + ' dBm</strong>';
		}
		const cell = row ? row.querySelector( 'td:nth-child(6)' ) : null;
		const message = cell && cell.dataset.afcOpticalError ? cell.dataset.afcOpticalError : opticalError( user, cell );
		return '<span class="afc-ppp-quick-warning" title="' + escapeAttr( message ) + '">' + icon( 'warning' ) + '<span>No reading</span></span>';
	}

	function quickStatus( user ) {
		if ( user.disabled ) return '<span class="afc-ppp-quick-pill is-disabled">Disabled</span>';
		if ( String( user.actual_profile || '' ).toLowerCase() === 'expired' ) return '<span class="afc-ppp-quick-pill is-expired">Expired</span>';
		return user.active ? '<span class="afc-ppp-quick-pill is-online">Online</span>' : '<span class="afc-ppp-quick-pill">Offline</span>';
	}

	function ensureQuickDialog() {
		if ( quickDialog ) return quickDialog;
		quickDialog = document.createElement( 'dialog' );
		quickDialog.className = 'afc-ppp-quick-dialog';
		quickDialog.innerHTML =
			'<div class="afc-ppp-quick-card">' +
			'<header><div><small>PPP USER</small><h2 data-afc-quick-name></h2><p data-afc-quick-username></p></div><button type="button" data-afc-quick-close aria-label="Close">×</button></header>' +
			'<main><div class="afc-ppp-quick-status" data-afc-quick-status></div><div class="afc-ppp-quick-grid" data-afc-quick-grid></div><div class="afc-ppp-quick-contact" data-afc-quick-contact></div></main>' +
			'<footer><button type="button" class="btn btn-sm afc-ppp-quick-pay" data-afc-no-auto-icon>' + icon( 'money' ) + '<span>Pay</span></button><button type="button" class="btn btn-sm afc-ppp-quick-edit" data-afc-no-auto-icon>' + icon( 'edit' ) + '<span>Edit</span></button><button type="button" class="btn btn-sm afc-ppp-quick-more" data-afc-no-auto-icon aria-haspopup="menu" aria-expanded="false" aria-label="More PPP actions">' + icon( 'more' ) + '</button></footer>' +
			'</div>';
		document.body.appendChild( quickDialog );
		quickDialog.addEventListener( 'click', function ( event ) {
			if ( event.target === quickDialog || event.target.closest( '[data-afc-quick-close]' ) ) {
				quickDialog.close();
				return;
			}
			if ( event.target.closest( '.afc-ppp-quick-pay' ) ) {
				const nativePay = quickRow && quickRow.querySelector( '.afc-pay-today' );
				quickDialog.close();
				if ( nativePay ) nativePay.click();
				return;
			}
			if ( event.target.closest( '.afc-ppp-quick-edit' ) ) {
				if ( quickUser ) openEditor( quickUser.name );
				return;
			}
			const more = event.target.closest( '.afc-ppp-quick-more' );
			if ( more && quickRow && quickUser ) {
				event.stopPropagation();
				openMenu( more, quickRow, quickUser );
			}
		} );
		quickDialog.addEventListener( 'close', closeMenu );
		return quickDialog;
	}

	function openQuick( row, user ) {
		closeMenu();
		const dialog = ensureQuickDialog();
		quickRow = row;
		quickUser = user;
		const service = user.actual_profile || user.profile || 'No plan';
		const connection = user.active ? ( 'Online' + ( user.uptime ? ' · ' + user.uptime : '' ) ) : 'Offline';
		const payment = user.payment_date || 'No payment date';
		dialog.querySelector( '[data-afc-quick-name]' ).textContent = user.customer_name || user.name;
		dialog.querySelector( '[data-afc-quick-username]' ).textContent = user.name;
		dialog.querySelector( '[data-afc-quick-status]' ).innerHTML = quickStatus( user );
		dialog.querySelector( '[data-afc-quick-grid]' ).innerHTML =
			'<div><span>Service</span><strong>' + quickValue( service ) + '</strong></div>' +
			'<div><span>Connection</span><strong>' + quickValue( connection ) + '</strong></div>' +
			'<div><span>Last payment</span><strong>' + quickValue( payment ) + '</strong></div>' +
			'<div><span>Optical signal</span>' + quickOpticalHtml( user, row ) + '</div>';
		dialog.querySelector( '[data-afc-quick-contact]' ).innerHTML =
			'<span>' + quickValue( user.phone, 'No phone' ) + '</span><span>' + quickValue( user.address_text || user.address || user.wifi, 'No address' ) + '</span>';
		if ( dialog.open ) dialog.close();
		dialog.showModal();
	}

	function enhanceRow( row ) {
		const user = rowUser( row );
		if ( ! user ) return;
		compactOpticalCell( row, user );
		if ( row.dataset.afcAdvancedActions === '1' ) return;
		const actions = row.querySelector( '.afc-row-actions' );
		const pay = actions && actions.querySelector( '.afc-pay-today' );
		if ( ! actions || ! pay ) return;

		row.dataset.afcAdvancedActions = '1';
		row.classList.add( 'afc-ppp-clickable-row' );
		row.tabIndex = 0;
		row.setAttribute( 'aria-label', 'Open ' + ( user.customer_name || user.name ) + ' PPP actions' );

		pay.setAttribute( 'data-afc-no-auto-icon', '' );
		pay.classList.add( 'afc-ppp-row-pay' );
		pay.innerHTML = icon( 'money' ) + '<span>Pay</span>';

		const service = actions.querySelector( '.afc-expire, .afc-reconnect' );
		if ( service ) {
			service.classList.add( 'afc-ppp-row-service-native' );
			service.hidden = true;
		}

		const edit = document.createElement( 'button' );
		edit.type = 'button';
		edit.className = 'btn btn-sm afc-ppp-row-edit';
		edit.setAttribute( 'data-afc-no-auto-icon', '' );
		edit.setAttribute( 'aria-label', 'Edit ' + ( user.customer_name || user.name ) );
		edit.innerHTML = icon( 'edit' ) + '<span>Edit</span>';

		const more = document.createElement( 'button' );
		more.type = 'button';
		more.className = 'btn btn-sm afc-ppp-row-more';
		more.setAttribute( 'data-afc-no-auto-icon', '' );
		more.setAttribute( 'aria-label', 'More actions for ' + ( user.customer_name || user.name ) );
		more.setAttribute( 'aria-haspopup', 'menu' );
		more.setAttribute( 'aria-expanded', 'false' );
		more.innerHTML = icon( 'more' );

		actions.appendChild( edit );
		actions.appendChild( more );
	}

	function restoreRow( row ) {
		restoreOpticalCell( row );
		if ( row.dataset.afcAdvancedActions !== '1' ) return;
		delete row.dataset.afcAdvancedActions;
		row.classList.remove( 'afc-ppp-clickable-row' );
		row.removeAttribute( 'tabindex' );
		row.removeAttribute( 'aria-label' );
		const pay = row.querySelector( '.afc-pay-today' );
		if ( pay ) {
			pay.classList.remove( 'afc-ppp-row-pay' );
			pay.removeAttribute( 'data-afc-no-auto-icon' );
			pay.textContent = 'Paid Today';
		}
		row.querySelectorAll( '.afc-ppp-row-edit, .afc-ppp-row-more' ).forEach( function ( button ) { button.remove(); } );
		const service = row.querySelector( '.afc-ppp-row-service-native' );
		if ( service ) {
			service.hidden = false;
			service.classList.remove( 'afc-ppp-row-service-native' );
		}
	}

	function polish() {
		frame = 0;
		const rows = document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' );
		if ( advanced() ) {
			ensureManageButton();
			rows.forEach( enhanceRow );
		} else {
			closeMenu();
			closeQuick();
			rows.forEach( restoreRow );
		}
	}

	function queue() {
		if ( frame ) return;
		frame = window.requestAnimationFrame( polish );
	}

	function handleTableClick( event ) {
		if ( ! advanced() ) return;
		const row = event.target.closest( '#afc-ppp-table tbody tr[data-user]' );
		if ( ! row ) return;
		const user = rowUser( row );
		if ( ! user ) return;
		const edit = event.target.closest( '.afc-ppp-row-edit' );
		const more = event.target.closest( '.afc-ppp-row-more' );
		if ( edit ) {
			event.preventDefault();
			openEditor( user.name );
			return;
		}
		if ( more ) {
			event.preventDefault();
			event.stopPropagation();
			openMenu( more, row, user );
			return;
		}
		if ( event.target.closest( 'button, a, input, select, textarea, label, .afc-ppp-optical-warning' ) ) return;
		if ( window.getSelection && String( window.getSelection().toString() ).trim() ) return;
		openQuick( row, user );
	}

	function handleKey( event ) {
		if ( event.key === 'Escape' ) closeMenu();
		if ( ! advanced() || ( event.key !== 'Enter' && event.key !== ' ' ) ) return;
		const row = event.target.closest && event.target.closest( '#afc-ppp-table tbody tr.afc-ppp-clickable-row' );
		if ( row && event.target === row ) {
			event.preventDefault();
			const user = rowUser( row );
			if ( user ) openQuick( row, user );
		}
	}

	function boot() {
		queue();
		const observer = new MutationObserver( queue );
		observer.observe( document.body, { childList: true, subtree: true } );
		document.addEventListener( 'click', function ( event ) {
			handleTableClick( event );
			if ( menu && ! menu.hidden && ! menu.contains( event.target ) && ! event.target.closest( '.afc-ppp-row-more, .afc-ppp-quick-more' ) ) closeMenu();
		} );
		document.addEventListener( 'keydown', handleKey );
		document.addEventListener( 'afc:admin-mode-change', function () { window.setTimeout( queue, 0 ); } );
		document.addEventListener( 'afc:ajaxify-panel-loaded', function () { window.setTimeout( queue, 0 ); } );
		window.addEventListener( 'resize', closeMenu );
		window.addEventListener( 'scroll', closeMenu, true );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );