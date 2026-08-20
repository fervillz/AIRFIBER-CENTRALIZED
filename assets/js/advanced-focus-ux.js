( function () {
	'use strict';

	let frame = 0;
	let observer = null;

	function advanced() {
		return document.body.classList.contains( 'afc-admin-mode-advanced' ) && document.body.classList.contains( 'afc-workspace-active' );
	}

	function panelKey( panel ) {
		return panel ? ( panel.getAttribute( 'data-afc-panel' ) || '' ) : '';
	}

	function ensureAttention( panel ) {
		const head = panel.querySelector( ':scope > .afc-workspace-pagehead' );
		if ( ! head ) return;

		let stack = panel.querySelector( ':scope > .afc-focus-attention' );
		if ( ! stack ) {
			stack = document.createElement( 'div' );
			stack.className = 'afc-focus-attention';
			head.insertAdjacentElement( 'afterend', stack );
		}

		const selectors = [
			'.afc-ux-notice-stack',
			'.afc-dashboard-router-alert',
			'.afc-scheduler-notice',
			'#afc-ppp-notice',
			'#afc-optical-status',
			'.afc-sms-notice',
			'.afc-network-alert',
			'[data-afc-alert]'
		];

		panel.querySelectorAll( selectors.join( ',' ) ).forEach( function ( node ) {
			if ( node === stack || node.closest( 'dialog' ) || node.closest( '.afc-ui-modal' ) ) return;
			if ( node.closest( '.afc-focus-attention' ) ) return;
			stack.appendChild( node );
		} );
	}

	function markStaticCopy( panel ) {
		const selectors = [
			'.afc-scheduler-section-head > p',
			'.afc-dashboard-card-title > small',
			'.afc-integration-card header > p',
			'.afc-messenger-section header > p'
		];
		panel.querySelectorAll( selectors.join( ',' ) ).forEach( function ( node ) {
			node.classList.add( 'afc-focus-static-copy' );
		} );
	}

	function markSecondaryColumns( panel ) {
		const key = panelKey( panel );
		const labels = {
			operations: [ 'last payment', 'contact & location' ],
			schedulers: [ 'billing source' ]
		}[ key ] || [];
		if ( ! labels.length ) return false;

		let found = false;
		panel.querySelectorAll( 'table' ).forEach( function ( table ) {
			const headers = Array.from( table.querySelectorAll( 'thead th' ) );
			headers.forEach( function ( header, index ) {
				const label = String( header.textContent || '' ).replace( /\s+/g, ' ' ).trim().toLowerCase();
				if ( ! labels.some( function ( expected ) { return label.indexOf( expected ) !== -1; } ) ) return;
				found = true;
				header.classList.add( 'afc-focus-secondary-column' );
				table.querySelectorAll( 'tbody tr' ).forEach( function ( row ) {
					if ( row.children[ index ] ) row.children[ index ].classList.add( 'afc-focus-secondary-column' );
				} );
			} );
		} );
		return found;
	}

	function savedDetailsState( key ) {
		try { return sessionStorage.getItem( 'afcFocusDetails:' + key ) === '1'; } catch ( error ) { return false; }
	}

	function setDetailsState( panel, state ) {
		const key = panelKey( panel );
		panel.classList.toggle( 'afc-focus-show-details', Boolean( state ) );
		const button = panel.querySelector( ':scope > .afc-workspace-pagehead [data-afc-focus-details]' );
		if ( button ) {
			button.setAttribute( 'aria-pressed', state ? 'true' : 'false' );
			button.textContent = state ? 'Hide details' : 'Details';
		}
		try { sessionStorage.setItem( 'afcFocusDetails:' + key, state ? '1' : '0' ); } catch ( error ) {}
	}

	function ensureDetailsToggle( panel, hasSecondaryColumns ) {
		const key = panelKey( panel );
		if ( ! hasSecondaryColumns || [ 'operations', 'schedulers' ].indexOf( key ) === -1 ) return;
		const actions = panel.querySelector( ':scope > .afc-workspace-pagehead .afc-workspace-actions' );
		if ( ! actions ) return;
		let button = actions.querySelector( '[data-afc-focus-details]' );
		if ( ! button ) {
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'afc-focus-details-toggle';
			button.setAttribute( 'data-afc-focus-details', '' );
			button.addEventListener( 'click', function () {
				setDetailsState( panel, ! panel.classList.contains( 'afc-focus-show-details' ) );
			} );
			actions.appendChild( button );
		}
		setDetailsState( panel, savedDetailsState( key ) );
	}

	function compactOperations( panel ) {
		const summary = panel.querySelector( '#afc-ppp-summary' );
		if ( summary ) summary.classList.add( 'afc-focus-duplicate-summary' );

		const search = panel.querySelector( '#afc-ppp-search' );
		if ( search ) search.setAttribute( 'placeholder', 'Search customer, PPP, plan or address…' );

		const manage = panel.querySelector( '#afc-find-edit-ppp' );
		if ( manage && ! manage.dataset.afcFocusLabel ) {
			manage.dataset.afcFocusLabel = '1';
			manage.textContent = 'Manage PPP';
		}
	}

	function compactSchedulers( panel ) {
		panel.querySelectorAll( '.afc-scheduler-section-head p' ).forEach( function ( node ) {
			node.classList.add( 'afc-focus-static-copy' );
		} );
	}

	function compactOptical( panel ) {
		const title = panel.querySelector( '.afc-olt-manager-heading h2' );
		if ( title && title.textContent.trim() === 'OLT' ) title.textContent = 'OLT connections';
	}

	function closeTechnicalDisclosure( panel ) {
		panel.querySelectorAll( 'details' ).forEach( function ( details ) {
			if ( details.dataset.afcFocusInitialized === '1' ) return;
			details.dataset.afcFocusInitialized = '1';
			const label = String( details.querySelector( 'summary' ) && details.querySelector( 'summary' ).textContent || '' ).toLowerCase();
			if ( /advanced|raw|help|safety|how .* works|technical|debug|script/.test( label ) ) details.open = false;
		} );
	}

	function enhancePanel( panel ) {
		if ( ! advanced() ) return;
		const key = panelKey( panel );
		if ( ! key ) return;

		ensureAttention( panel );
		markStaticCopy( panel );
		closeTechnicalDisclosure( panel );

		if ( key === 'operations' ) compactOperations( panel );
		if ( key === 'schedulers' ) compactSchedulers( panel );
		if ( key === 'optical' || key === 'olt' ) compactOptical( panel );

		const hasSecondary = markSecondaryColumns( panel );
		ensureDetailsToggle( panel, hasSecondary );
	}

	function polish() {
		frame = 0;
		if ( ! advanced() ) return;
		document.querySelectorAll( '#afc-frontend-app [data-afc-panel]' ).forEach( enhancePanel );
	}

	function queue() {
		if ( frame ) return;
		frame = window.requestAnimationFrame( polish );
	}

	function boot() {
		polish();
		observer = new MutationObserver( queue );
		observer.observe( document.body, { childList: true, subtree: true } );
		document.addEventListener( 'afc:admin-mode-change', function () { window.setTimeout( queue, 0 ); } );
		document.addEventListener( 'afc:ajaxify-panel-loaded', function () { window.setTimeout( queue, 0 ); } );
		document.addEventListener( 'afc:ajaxify-fragment-loaded', function () { window.setTimeout( queue, 0 ); } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
