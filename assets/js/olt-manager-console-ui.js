( function () {
	'use strict';

	let modal = null;
	let dialog = null;
	let aside = null;
	let guide = null;
	let consolePane = null;
	let consoleBody = null;
	let resizer = null;
	let guideOpen = false;
	let consoleOpen = false;
	let guideTab = null;
	let consoleTab = null;

	function q( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function readBool( key ) {
		try {
			return window.localStorage.getItem( key ) === '1';
		} catch ( error ) {
			return false;
		}
	}

	function writeBool( key, value ) {
		try {
			window.localStorage.setItem( key, value ? '1' : '0' );
		} catch ( error ) {
			// The editor still works when localStorage is unavailable.
		}
	}

	function syncSidebar() {
		if ( ! dialog || ! aside ) return;
		const visible = guideOpen || consoleOpen;
		dialog.classList.toggle( 'is-help-open', visible );
		aside.classList.toggle( 'is-console-open', consoleOpen );
		aside.classList.toggle( 'is-guide-hidden', ! guideOpen );
		aside.setAttribute( 'aria-hidden', visible ? 'false' : 'true' );
		if ( guideTab ) guideTab.setAttribute( 'aria-pressed', guideOpen ? 'true' : 'false' );
		if ( consoleTab ) consoleTab.setAttribute( 'aria-pressed', consoleOpen ? 'true' : 'false' );
		const headerHelp = q( '[data-afc-olt-help-toggle]', modal );
		if ( headerHelp ) headerHelp.setAttribute( 'aria-pressed', guideOpen ? 'true' : 'false' );
	}

	function setGuide( open, persist ) {
		guideOpen = Boolean( open );
		if ( persist ) writeBool( 'afcOLTManagerHelpOpen', guideOpen );
		syncSidebar();
	}

	function setConsole( open ) {
		consoleOpen = Boolean( open );
		syncSidebar();
	}

	function moveTestLog() {
		if ( ! modal || ! consoleBody ) return;
		const log = q( '[data-afc-olt-test-log]', modal );
		if ( ! log || log.parentNode === consoleBody ) return;
		consoleBody.appendChild( log );
	}

	function clearConsole() {
		moveTestLog();
		const body = q( '[data-afc-olt-test-log-body]', consoleBody );
		if ( body ) body.innerHTML = '';
	}

	function buildSidebarTools() {
		if ( ! aside || q( '[data-afc-olt-devtools-toolbar]', aside ) ) return;

		const toolbar = document.createElement( 'div' );
		toolbar.className = 'afc-olt-devtools-toolbar';
		toolbar.setAttribute( 'data-afc-olt-devtools-toolbar', '' );

		guideTab = document.createElement( 'button' );
		guideTab.type = 'button';
		guideTab.className = 'afc-olt-devtools-tab';
		guideTab.textContent = '?';
		guideTab.title = 'Setup guide';
		guideTab.setAttribute( 'aria-label', 'Show setup guide' );
		guideTab.addEventListener( 'click', function () {
			setGuide( ! guideOpen, true );
		} );
		toolbar.appendChild( guideTab );

		consoleTab = document.createElement( 'button' );
		consoleTab.type = 'button';
		consoleTab.className = 'afc-olt-devtools-tab';
		consoleTab.textContent = '>_';
		consoleTab.title = 'Connection console';
		consoleTab.setAttribute( 'aria-label', 'Show connection console' );
		consoleTab.addEventListener( 'click', function () {
			setConsole( ! consoleOpen );
			window.setTimeout( moveTestLog, 0 );
		} );
		toolbar.appendChild( consoleTab );

		const spacer = document.createElement( 'span' );
		spacer.className = 'afc-olt-devtools-spacer';
		toolbar.appendChild( spacer );

		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'afc-olt-devtools-close';
		close.textContent = '×';
		close.title = 'Close sidebar';
		close.setAttribute( 'aria-label', 'Close OLT sidebar' );
		close.addEventListener( 'click', function () {
			guideOpen = false;
			consoleOpen = false;
			writeBool( 'afcOLTManagerHelpOpen', false );
			syncSidebar();
		} );
		toolbar.appendChild( close );

		aside.insertBefore( toolbar, aside.firstChild );

		resizer = document.createElement( 'div' );
		resizer.className = 'afc-olt-console-resizer';
		resizer.setAttribute( 'data-afc-olt-console-resizer', '' );
		resizer.setAttribute( 'role', 'separator' );
		resizer.setAttribute( 'aria-orientation', 'horizontal' );
		resizer.setAttribute( 'aria-label', 'Resize connection console' );

		consolePane = document.createElement( 'section' );
		consolePane.className = 'afc-olt-console-pane';
		consolePane.setAttribute( 'data-afc-olt-console-pane', '' );

		const consoleHeader = document.createElement( 'div' );
		consoleHeader.className = 'afc-olt-console-header';
		const title = document.createElement( 'strong' );
		title.textContent = 'CONNECTION CONSOLE';
		consoleHeader.appendChild( title );
		const hint = document.createElement( 'small' );
		hint.textContent = 'SNMP test output';
		consoleHeader.appendChild( hint );
		const headerSpacer = document.createElement( 'span' );
		headerSpacer.className = 'afc-olt-console-header-spacer';
		consoleHeader.appendChild( headerSpacer );

		const clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'afc-olt-console-clear';
		clear.textContent = 'clear';
		clear.title = 'Clear console';
		clear.addEventListener( 'click', clearConsole );
		consoleHeader.appendChild( clear );

		const closeConsole = document.createElement( 'button' );
		closeConsole.type = 'button';
		closeConsole.className = 'afc-olt-console-close';
		closeConsole.textContent = '×';
		closeConsole.title = 'Close console';
		closeConsole.setAttribute( 'aria-label', 'Close connection console' );
		closeConsole.addEventListener( 'click', function () { setConsole( false ); } );
		consoleHeader.appendChild( closeConsole );
		consolePane.appendChild( consoleHeader );

		consoleBody = document.createElement( 'div' );
		consoleBody.className = 'afc-olt-console-body';
		consoleBody.setAttribute( 'data-afc-olt-console-body', '' );
		consolePane.appendChild( consoleBody );

		guide.insertAdjacentElement( 'afterend', resizer );
		resizer.insertAdjacentElement( 'afterend', consolePane );
	}

	function bindResize() {
		if ( ! resizer || ! aside ) return;
		let startY = 0;
		let startHeight = 0;
		let asideHeight = 0;

		function onMove( event ) {
			if ( ! startY ) return;
			const maxHeight = Math.max( 140, asideHeight - 170 );
			const next = Math.max( 120, Math.min( maxHeight, startHeight + ( startY - event.clientY ) ) );
			aside.style.setProperty( '--afc-olt-console-height', Math.round( next ) + 'px' );
		}

		function onUp() {
			if ( ! startY ) return;
			startY = 0;
			resizer.classList.remove( 'is-dragging' );
			document.body.style.removeProperty( 'user-select' );
			document.removeEventListener( 'pointermove', onMove );
			document.removeEventListener( 'pointerup', onUp );
			try {
				window.localStorage.setItem( 'afcOLTConsoleHeight', getComputedStyle( consolePane ).height );
			} catch ( error ) {
				// Optional preference only.
			}
		}

		resizer.addEventListener( 'pointerdown', function ( event ) {
			if ( ! guideOpen || ! consoleOpen ) return;
			event.preventDefault();
			startY = event.clientY;
			startHeight = consolePane.getBoundingClientRect().height;
			asideHeight = aside.getBoundingClientRect().height;
			resizer.classList.add( 'is-dragging' );
			document.body.style.setProperty( 'user-select', 'none' );
			document.addEventListener( 'pointermove', onMove );
			document.addEventListener( 'pointerup', onUp );
		} );

		try {
			const saved = window.localStorage.getItem( 'afcOLTConsoleHeight' );
			if ( saved && /^\d+(?:\.\d+)?px$/.test( saved ) ) aside.style.setProperty( '--afc-olt-console-height', saved );
		} catch ( error ) {
			// Optional preference only.
		}
	}

	function interceptLegacyHelpControls() {
		modal.addEventListener( 'click', function ( event ) {
			const helpToggle = event.target.closest( '[data-afc-olt-help-toggle]' );
			if ( helpToggle ) {
				event.preventDefault();
				event.stopImmediatePropagation();
				setGuide( ! guideOpen, true );
				return;
			}

			const helpClose = event.target.closest( '[data-afc-olt-help-close]' );
			if ( helpClose ) {
				event.preventDefault();
				event.stopImmediatePropagation();
				setGuide( false, true );
				return;
			}

			const modalClose = event.target.closest( '[data-afc-olt-close]' );
			if ( modalClose ) {
				consoleOpen = false;
				window.setTimeout( syncSidebar, 220 );
				return;
			}

			const test = event.target.closest( '[data-afc-olt-test]' );
			if ( test ) {
				setConsole( true );
				window.setTimeout( moveTestLog, 0 );
			}
		}, true );
	}

	function boot() {
		modal = q( '[data-afc-olt-modal]' );
		if ( ! modal ) return;
		dialog = q( '.afc-olt-dialog', modal );
		aside = q( '[data-afc-olt-help]', modal );
		guide = q( '.afc-olt-help-inner', aside );
		if ( ! dialog || ! aside || ! guide ) return;

		guideOpen = readBool( 'afcOLTManagerHelpOpen' );
		buildSidebarTools();
		bindResize();
		interceptLegacyHelpControls();
		syncSidebar();
		moveTestLog();

		const observer = new MutationObserver( function () {
			moveTestLog();
		} );
		observer.observe( modal, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
