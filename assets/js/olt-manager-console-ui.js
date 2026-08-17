( function () {
	'use strict';

	let modal = null;
	let dialog = null;
	let mainPane = null;
	let aside = null;
	let guide = null;
	let consolePane = null;
	let consoleBody = null;
	let resizer = null;
	let guideOpen = false;
	let consoleOpen = false;
	let consoleClosing = false;
	let guideTab = null;
	let consoleTab = null;
	let resizeObserver = null;
	let closeTimer = 0;

	const CONSOLE_TRANSITION_MS = 520;

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

	function readSavedConsoleHeight() {
		try {
			const saved = window.localStorage.getItem( 'afcOLTConsoleHeight' );
			const match = saved && saved.match( /^(\d+(?:\.\d+)?)px$/ );
			return match ? Number( match[1] ) : 0;
		} catch ( error ) {
			return 0;
		}
	}

	function saveConsoleHeight() {
		if ( ! consolePane ) return;
		try {
			window.localStorage.setItem( 'afcOLTConsoleHeight', Math.round( consolePane.getBoundingClientRect().height ) + 'px' );
		} catch ( error ) {
			// Optional preference only.
		}
	}

	function syncSidebar() {
		if ( ! dialog || ! aside ) return;
		const visible = guideOpen || consoleOpen || consoleClosing;
		dialog.classList.toggle( 'is-help-open', visible );
		aside.classList.toggle( 'is-guide-hidden', ! guideOpen );
		aside.setAttribute( 'aria-hidden', visible ? 'false' : 'true' );
		if ( guideTab ) guideTab.setAttribute( 'aria-pressed', guideOpen ? 'true' : 'false' );
		if ( consoleTab ) consoleTab.setAttribute( 'aria-pressed', consoleOpen ? 'true' : 'false' );
		const headerHelp = q( '[data-afc-olt-help-toggle]', modal );
		if ( headerHelp ) headerHelp.setAttribute( 'aria-pressed', guideOpen ? 'true' : 'false' );
	}

	function lockDialogGeometry() {
		if ( ! dialog || ! mainPane || dialog.dataset.afcConsoleGeometryLocked === '1' ) return;

		const dialogRect = dialog.getBoundingClientRect();
		const mainRect = mainPane.getBoundingClientRect();
		if ( dialogRect.height < 1 || mainRect.width < 1 ) return;

		/*
		 * Freeze the exact pre-console height so opening the lower console cannot
		 * make the editor jump. Preserve the left editor width too; the dialog may
		 * grow to make room for the sidebar instead of squeezing the form.
		 */
		dialog.dataset.afcConsoleGeometryLocked = '1';
		dialog.style.setProperty( '--afc-olt-locked-height', Math.round( dialogRect.height ) + 'px' );
		dialog.style.height = Math.round( dialogRect.height ) + 'px';

		if ( window.innerWidth > 780 ) {
			const sidebarWidth = 380;
			const viewportWidth = Math.max( 320, window.innerWidth - 36 );
			const targetWidth = Math.min( viewportWidth, Math.round( mainRect.width ) + sidebarWidth );
			dialog.style.setProperty( '--afc-olt-locked-main-width', Math.round( mainRect.width ) + 'px' );
			dialog.style.width = targetWidth + 'px';
		}
	}

	function unlockDialogGeometry() {
		if ( ! dialog ) return;
		dialog.dataset.afcConsoleGeometryLocked = '0';
		dialog.style.removeProperty( 'height' );
		dialog.style.removeProperty( 'width' );
		dialog.style.removeProperty( '--afc-olt-locked-height' );
		dialog.style.removeProperty( '--afc-olt-locked-main-width' );
	}

	function consoleHeightBounds() {
		if ( ! aside ) return { min: 140, max: 360, preferred: 260 };
		const asideRect = aside.getBoundingClientRect();
		const toolbar = q( '[data-afc-olt-devtools-toolbar]', aside );
		const toolbarHeight = toolbar ? toolbar.getBoundingClientRect().height : 43;
		const usable = Math.max( 180, asideRect.height - toolbarHeight );
		const min = Math.min( 160, Math.max( 120, usable * 0.28 ) );
		const guideReserve = guideOpen ? Math.min( 170, usable * 0.34 ) : 70;
		const max = Math.max( min, usable - guideReserve );
		const preferred = Math.max( min, Math.min( max, usable * 0.50 ) );
		return { min: min, max: max, preferred: preferred };
	}

	function measureConsoleGeometry( preferSaved ) {
		if ( ! aside || ! consolePane ) return;
		const asideRect = aside.getBoundingClientRect();
		if ( asideRect.height < 1 || asideRect.width < 1 ) return;

		aside.style.setProperty( '--afc-olt-sidebar-height', Math.round( asideRect.height ) + 'px' );
		aside.style.setProperty( '--afc-olt-sidebar-width', Math.round( asideRect.width ) + 'px' );

		const bounds = consoleHeightBounds();
		const saved = preferSaved ? readSavedConsoleHeight() : consolePane.getBoundingClientRect().height;
		const wanted = saved > 0 ? saved : bounds.preferred;
		const height = Math.max( bounds.min, Math.min( bounds.max, wanted ) );
		aside.style.setProperty( '--afc-olt-console-height', Math.round( height ) + 'px' );
	}

	function setGuide( open, persist ) {
		guideOpen = Boolean( open );
		if ( persist ) writeBool( 'afcOLTManagerHelpOpen', guideOpen );
		syncSidebar();
		if ( consoleOpen ) window.requestAnimationFrame( function () { measureConsoleGeometry( false ); } );
	}

	function openConsole() {
		if ( ! aside || ! consolePane || consoleOpen ) return;
		window.clearTimeout( closeTimer );
		consoleClosing = false;
		lockDialogGeometry();
		consoleOpen = true;
		syncSidebar();

		/* Mount below the sidebar first, measure the real rendered sidebar, then
		 * animate upward on the next frame. This avoids layout jumps. */
		aside.classList.add( 'is-console-present' );
		aside.classList.remove( 'is-console-open' );

		window.requestAnimationFrame( function () {
			measureConsoleGeometry( true );
			moveTestLog();
			window.requestAnimationFrame( function () {
				aside.classList.add( 'is-console-open' );
			} );
		} );
	}

	function closeConsole() {
		if ( ! aside || ! consoleOpen ) return;
		consoleOpen = false;
		consoleClosing = true;
		aside.classList.remove( 'is-console-open' );
		if ( consoleTab ) consoleTab.setAttribute( 'aria-pressed', 'false' );

		window.clearTimeout( closeTimer );
		closeTimer = window.setTimeout( function () {
			consoleClosing = false;
			aside.classList.remove( 'is-console-present' );
			syncSidebar();
			if ( ! guideOpen ) unlockDialogGeometry();
		}, CONSOLE_TRANSITION_MS );
	}

	function setConsole( open ) {
		if ( open ) openConsole();
		else closeConsole();
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
			writeBool( 'afcOLTManagerHelpOpen', false );
			if ( consoleOpen ) closeConsole();
			else {
				consoleClosing = false;
				syncSidebar();
				unlockDialogGeometry();
			}
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

		const closeConsoleButton = document.createElement( 'button' );
		closeConsoleButton.type = 'button';
		closeConsoleButton.className = 'afc-olt-console-close';
		closeConsoleButton.textContent = '×';
		closeConsoleButton.title = 'Close console';
		closeConsoleButton.setAttribute( 'aria-label', 'Close connection console' );
		closeConsoleButton.addEventListener( 'click', function () { setConsole( false ); } );
		consoleHeader.appendChild( closeConsoleButton );
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

		function onMove( event ) {
			if ( ! startY ) return;
			const bounds = consoleHeightBounds();
			const next = Math.max( bounds.min, Math.min( bounds.max, startHeight + ( startY - event.clientY ) ) );
			aside.style.setProperty( '--afc-olt-console-height', Math.round( next ) + 'px' );
		}

		function onUp() {
			if ( ! startY ) return;
			startY = 0;
			resizer.classList.remove( 'is-dragging' );
			aside.classList.remove( 'is-console-resizing' );
			document.body.style.removeProperty( 'user-select' );
			document.removeEventListener( 'pointermove', onMove );
			document.removeEventListener( 'pointerup', onUp );
			saveConsoleHeight();
		}

		resizer.addEventListener( 'pointerdown', function ( event ) {
			if ( ! guideOpen || ! consoleOpen ) return;
			event.preventDefault();
			startY = event.clientY;
			startHeight = consolePane.getBoundingClientRect().height;
			resizer.classList.add( 'is-dragging' );
			aside.classList.add( 'is-console-resizing' );
			document.body.style.setProperty( 'user-select', 'none' );
			document.addEventListener( 'pointermove', onMove );
			document.addEventListener( 'pointerup', onUp );
		} );
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
				window.clearTimeout( closeTimer );
				consoleOpen = false;
				consoleClosing = false;
				aside.classList.remove( 'is-console-open', 'is-console-present' );
				unlockDialogGeometry();
				return;
			}

			const test = event.target.closest( '[data-afc-olt-test]' );
			if ( test ) {
				setConsole( true );
				window.setTimeout( moveTestLog, 0 );
			}
		}, true );
	}

	function bindGeometryObservers() {
		if ( ! aside || typeof ResizeObserver === 'undefined' ) return;
		resizeObserver = new ResizeObserver( function () {
			if ( consoleOpen ) measureConsoleGeometry( false );
		} );
		resizeObserver.observe( aside );
	}

	function boot() {
		modal = q( '[data-afc-olt-modal]' );
		if ( ! modal ) return;
		dialog = q( '.afc-olt-dialog', modal );
		mainPane = q( '.afc-olt-dialog-main', modal );
		aside = q( '[data-afc-olt-help]', modal );
		guide = q( '.afc-olt-help-inner', aside );
		if ( ! dialog || ! mainPane || ! aside || ! guide ) return;

		guideOpen = readBool( 'afcOLTManagerHelpOpen' );
		buildSidebarTools();
		bindResize();
		bindGeometryObservers();
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
