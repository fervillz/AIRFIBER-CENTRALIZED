( function () {
	'use strict';

	let frame = 0;

	function setText( element, value ) {
		if ( element && element.textContent !== value ) {
			element.textContent = value;
		}
	}

	function isBasic() {
		return document.body.classList.contains( 'afc-admin-mode-basic' );
	}

	function ensureHeadingCopy( heading ) {
		if ( ! heading ) {
			return null;
		}
		let copy = heading.querySelector( ':scope > .afc-ux-heading-copy' );
		if ( copy ) {
			return copy;
		}
		copy = document.createElement( 'div' );
		copy.className = 'afc-ux-heading-copy';
		Array.from( heading.children ).forEach( function ( child ) {
			if ( ! child.classList.contains( 'afc-ux-basic-actions' ) ) {
				copy.appendChild( child );
			}
		} );
		heading.insertBefore( copy, heading.firstChild );
		return copy;
	}

	function compactBasicPayment() {
		const app = document.getElementById( 'afc-basic-payment-app' );
		if ( ! app ) {
			return;
		}

		const heading = app.querySelector( '.afc-basic-payment-heading' );
		const copy = ensureHeadingCopy( heading );
		if ( copy ) {
			setText( copy.querySelector( 'h1' ), 'Record payment' );
			setText( copy.querySelector( 'p' ), '' );
		}

		let actions = heading ? heading.querySelector( ':scope > .afc-ux-basic-actions' ) : null;
		if ( heading && ! actions ) {
			actions = document.createElement( 'div' );
			actions.className = 'afc-ux-basic-actions';
			heading.appendChild( actions );
		}

		const add = document.getElementById( 'afc-add-ppp-account' );
		const edit = document.getElementById( 'afc-find-edit-ppp' );
		const advancedActions = document.querySelector( '.afc-ppp-header-actions' );

		if ( isBasic() && actions ) {
			if ( add && add.parentNode !== actions ) {
				actions.appendChild( add );
			}
			if ( edit && edit.parentNode !== actions ) {
				actions.appendChild( edit );
			}
			if ( add ) {
				add.classList.add( 'btn-success' );
				add.classList.remove( 'btn-primary' );
				setText( add.querySelector( '.afc-add-ppp-advanced-label' ), 'Add PPP' );
			}
			if ( edit ) {
				setText( edit, 'Manage' );
			}
		} else if ( advancedActions ) {
			if ( add && add.parentNode !== advancedActions ) {
				advancedActions.insertBefore( add, advancedActions.firstChild );
			}
			if ( edit && edit.parentNode !== advancedActions ) {
				advancedActions.insertBefore( edit, add ? add.nextSibling : advancedActions.firstChild );
			}
			if ( add ) {
				add.classList.remove( 'btn-success' );
				add.classList.add( 'btn-primary' );
			}
			if ( edit ) {
				setText( edit, 'Find / Edit PPP' );
			}
		}
	}

	function compactOperations() {
		const page = document.querySelector( '.afc-admin-page .container-fluid' );
		if ( ! page ) {
			return;
		}

		const header = page.querySelector( '.page-header' );
		if ( header ) {
			setText( header.querySelector( '.page-title' ), 'Billing & PPP' );
			setText( header.querySelector( 'p' ), 'Payments, customers and service.' );
		}

		const headerActions = page.querySelector( '.afc-ppp-header-actions' );
		if ( headerActions ) {
			let tools = headerActions.querySelector( '.afc-ux-tools' );
			if ( ! tools ) {
				tools = document.createElement( 'details' );
				tools.className = 'afc-ux-tools afc-advanced-only';
				tools.innerHTML = '<summary>Tools</summary><div class="afc-ux-tools-menu"></div>';
				headerActions.appendChild( tools );
			}
			const menu = tools.querySelector( '.afc-ux-tools-menu' );
			[ 'afc-manage-service-areas', 'afc-refresh-optical', 'afc-refresh-ppp' ].forEach( function ( id ) {
				const button = document.getElementById( id );
				if ( button && menu && button.parentNode !== menu ) {
					menu.appendChild( button );
				}
			} );
		}

		const collection = page.querySelector( '.afc-collection-card' );
		if ( collection && ! collection.parentElement.classList.contains( 'afc-ux-collapsible' ) ) {
			const details = document.createElement( 'details' );
			details.className = 'afc-ux-collapsible afc-advanced-only';
			const summary = document.createElement( 'summary' );
			summary.textContent = 'Collection print';
			collection.parentNode.insertBefore( details, collection );
			details.appendChild( summary );
			details.appendChild( collection );
		}

		compactBasicPayment();
		syncNoticeStack();
	}

	function syncNoticeStack() {
		const page = document.querySelector( '.afc-admin-page .container-fluid' );
		if ( ! page ) {
			return;
		}
		let stack = page.querySelector( '.afc-ux-notice-stack' );
		if ( ! stack ) {
			stack = document.createElement( 'div' );
			stack.className = 'afc-ux-notice-stack';
		}

		const app = document.getElementById( 'afc-basic-payment-app' );
		const header = page.querySelector( '.page-header' );
		if ( isBasic() && app ) {
			if ( stack.parentNode !== app ) {
				app.insertBefore( stack, app.firstChild );
			}
		} else if ( header && stack.previousElementSibling !== header ) {
			header.insertAdjacentElement( 'afterend', stack );
		}

		[ 'afc-ppp-notice', 'afc-optical-status' ].forEach( function ( id ) {
			const notice = document.getElementById( id );
			if ( notice && notice.parentNode !== stack ) {
				stack.appendChild( notice );
			}
		} );
	}

	function moveDialogNotice( dialog, notice ) {
		if ( ! dialog || ! notice ) {
			return;
		}
		const body = dialog.querySelector( '.afc-dialog-body' );
		if ( body && notice.parentNode !== body ) {
			body.insertBefore( notice, body.firstChild );
		}
		notice.classList.add( 'afc-ux-dialog-notice' );
	}

	function compactDialogs() {
		const payment = document.getElementById( 'afc-payment-dialog' );
		if ( payment ) {
			setText( payment.querySelector( '.alert-info' ), 'Updates payment history and MikroTik. Expired service is restored automatically.' );
		}
		const binding = document.getElementById( 'afc-olt-binding-dialog' );
		if ( binding ) {
			setText( binding.querySelector( '.alert-info' ), 'Enter the customer ONU location from the OLT.' );
		}
		const create = document.getElementById( 'afc-ppp-create-dialog' );
		moveDialogNotice( create, document.getElementById( 'afc-ppp-create-notice' ) );

		const manage = document.getElementById( 'afc-ppp-manage-dialog' );
		moveDialogNotice( manage, document.getElementById( 'afc-ppp-manager-notice' ) );

		const service = document.getElementById( 'afc-service-areas-dialog' );
		if ( service ) {
			const intro = service.querySelector( '.afc-dialog-body > p.text-secondary' );
			setText( intro, 'Add covered areas. Separate zones with commas.' );
			moveDialogNotice( service, document.getElementById( 'afc-service-areas-notice' ) );
		}

		groupAdvancedPPPSettings();
	}

	function findFieldBox( grid, inputId ) {
		const input = grid ? grid.querySelector( '#' + inputId ) : null;
		return input ? input.closest( ':scope > div, div' ) : null;
	}

	function settingsSection( grid, title, open ) {
		const details = document.createElement( 'details' );
		details.className = 'afc-ux-settings-section';
		if ( open ) {
			details.open = true;
		}
		const summary = document.createElement( 'summary' );
		summary.textContent = title;
		const inner = document.createElement( 'div' );
		inner.className = 'afc-ux-settings-grid';
		details.appendChild( summary );
		details.appendChild( inner );
		grid.appendChild( details );
		return inner;
	}

	function groupAdvancedPPPSettings() {
		const grid = document.querySelector( '#afc-ppp-manage-dialog .afc-ppp-advanced-editor' );
		if ( ! grid || grid.classList.contains( 'afc-ux-grouped' ) ) {
			return;
		}
		grid.classList.add( 'afc-ux-grouped' );

		const billing = settingsSection( grid, 'Billing', true );
		const network = settingsSection( grid, 'Account & Wi-Fi', false );
		const raw = settingsSection( grid, 'Raw MikroTik data', false );

		[ 'afc-edit-ppp-installed', 'afc-edit-ppp-grace', 'afc-edit-ppp-billing-day', 'afc-edit-ppp-payment-date', 'afc-edit-ppp-paid-through', 'afc-edit-ppp-next-due', 'afc-edit-ppp-cutoff', 'afc-edit-ppp-reminder', 'afc-edit-ppp-amount', 'afc-edit-ppp-method' ].forEach( function ( id ) {
			const box = findFieldBox( grid, id );
			if ( box ) { billing.appendChild( box ); }
		} );
		[ 'afc-edit-ppp-username', 'afc-edit-ppp-wifi', 'afc-edit-ppp-new-password' ].forEach( function ( id ) {
			const box = findFieldBox( grid, id );
			if ( box ) { network.appendChild( box ); }
		} );
		const rawBox = findFieldBox( grid, 'afc-edit-ppp-comment' );
		if ( rawBox ) { raw.appendChild( rawBox ); }
	}

	function compactSchedulers() {
		const root = document.getElementById( 'afc-scheduler-center' );
		if ( ! root ) {
			return;
		}
		const header = root.querySelector( '.afc-scheduler-header' );
		if ( header ) {
			setText( header.querySelector( 'p' ), 'Create and keep PPP cutoff schedulers in sync.' );
		}
		const notice = root.querySelector( '.afc-scheduler-notice' );
		if ( notice && header && notice.previousElementSibling !== header ) {
			header.insertAdjacentElement( 'afterend', notice );
		}

		const copy = {
			accounts: 'Search and manage scheduler status.',
			bulk: 'Create missing schedulers or update old ones.',
			settings: 'Timing, profile and automation.'
		};
		Object.keys( copy ).forEach( function ( panel ) {
			const section = root.querySelector( '[data-afc-scheduler-panel="' + panel + '"]' );
			if ( section ) {
				setText( section.querySelector( '.afc-scheduler-section-head p' ), copy[ panel ] );
			}
		} );

		const settings = root.querySelector( '[data-afc-scheduler-panel="settings"]' );
		if ( settings ) {
			wrapSchedulerBlock( settings.querySelector( '.afc-scheduler-script-card' ), 'Safety script' );
			wrapSchedulerBlock( settings.querySelector( '.afc-scheduler-safety-grid' ), 'How scheduler safety works' );
		}
	}

	function wrapSchedulerBlock( block, title ) {
		if ( ! block || block.parentElement.classList.contains( 'afc-ux-scheduler-details' ) ) {
			return;
		}
		const details = document.createElement( 'details' );
		details.className = 'afc-ux-scheduler-details';
		const summary = document.createElement( 'summary' );
		summary.textContent = title;
		block.parentNode.insertBefore( details, block );
		details.appendChild( summary );
		details.appendChild( block );
	}

	function polish() {
		frame = 0;
		compactOperations();
		compactDialogs();
		compactSchedulers();
	}

	function queuePolish() {
		if ( frame ) {
			return;
		}
		frame = window.requestAnimationFrame( polish );
	}

	function boot() {
		polish();
		const observer = new MutationObserver( queuePolish );
		observer.observe( document.body, { childList: true, subtree: true } );
		document.addEventListener( 'afc:admin-mode-change', function () {
			window.setTimeout( queuePolish, 0 );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
