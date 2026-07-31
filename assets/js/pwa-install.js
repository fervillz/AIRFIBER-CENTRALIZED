( function () {
	'use strict';

	if ( ! window.afcPWA ) {
		return;
	}

	let deferredPrompt = null;
	let installButton = null;
	let helpDialog = null;
	let updateToast = null;

	function isStandalone() {
		return window.matchMedia( '(display-mode: standalone)' ).matches ||
			window.matchMedia( '(display-mode: window-controls-overlay)' ).matches ||
			true === window.navigator.standalone;
	}

	function isIOS() {
		return /iphone|ipad|ipod/i.test( window.navigator.userAgent ) &&
			! window.MSStream;
	}

	function isSecureForPWA() {
		return window.isSecureContext ||
			'localhost' === window.location.hostname ||
			'127.0.0.1' === window.location.hostname;
	}

	function installIcon() {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
			'<path d="M12 3v11m0 0 4-4m-4 4-4-4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" />' +
			'</svg>';
	}

	function createInstallButton() {
		const actions = document.querySelector( '.afc-frontend-header-actions' );
		if ( ! actions || isStandalone() ) {
			return null;
		}

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'afc-pwa-install-button';
		button.innerHTML = installIcon() + '<span></span>';
		button.querySelector( 'span' ).textContent = afcPWA.install;
		button.setAttribute( 'aria-label', afcPWA.install );
		button.addEventListener( 'click', handleInstallClick );

		actions.insertBefore( button, actions.firstChild );
		return button;
	}

	function setButtonState( state ) {
		if ( ! installButton ) {
			return;
		}

		installButton.classList.toggle( 'is-ready', 'ready' === state );
		installButton.classList.toggle( 'is-busy', 'busy' === state );
		installButton.disabled = 'busy' === state;

		const label = installButton.querySelector( 'span' );
		if ( label ) {
			label.textContent = 'busy' === state ? afcPWA.installing : afcPWA.install;
		}
	}

	function createHelpDialog() {
		const dialog = document.createElement( 'dialog' );
		dialog.className = 'afc-pwa-help-dialog';
		dialog.innerHTML =
			'<div class="afc-pwa-help-card">' +
				'<div class="afc-pwa-help-icon">A</div>' +
				'<h2></h2>' +
				'<p data-afc-pwa-help-text></p>' +
				'<button type="button" class="afc-pwa-help-close"></button>' +
			'</div>';

		dialog.querySelector( 'h2' ).textContent = afcPWA.helpTitle;
		dialog.querySelector( '.afc-pwa-help-close' ).textContent = afcPWA.close;
		dialog.querySelector( '.afc-pwa-help-close' ).addEventListener( 'click', function () {
			closeHelp();
		} );
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				closeHelp();
			}
		} );

		document.body.appendChild( dialog );
		return dialog;
	}

	function helpMessage() {
		if ( isIOS() ) {
			return afcPWA.iosHelp;
		}
		if ( ! isSecureForPWA() ) {
			return afcPWA.httpHelp;
		}
		return afcPWA.browserHelp;
	}

	function showHelp() {
		if ( ! helpDialog ) {
			helpDialog = createHelpDialog();
		}

		const text = helpDialog.querySelector( '[data-afc-pwa-help-text]' );
		if ( text ) {
			text.textContent = helpMessage();
		}

		if ( 'function' === typeof helpDialog.showModal ) {
			helpDialog.showModal();
		} else {
			helpDialog.setAttribute( 'open', 'open' );
		}
	}

	function closeHelp() {
		if ( ! helpDialog ) {
			return;
		}
		if ( 'function' === typeof helpDialog.close ) {
			helpDialog.close();
		} else {
			helpDialog.removeAttribute( 'open' );
		}
	}

	async function handleInstallClick() {
		if ( ! deferredPrompt ) {
			showHelp();
			return;
		}

		setButtonState( 'busy' );
		try {
			deferredPrompt.prompt();
			const choice = await deferredPrompt.userChoice;
			if ( choice && 'accepted' === choice.outcome ) {
				if ( installButton ) {
					installButton.hidden = true;
				}
			}
		} catch ( error ) {
			showHelp();
		} finally {
			deferredPrompt = null;
			setButtonState( 'idle' );
		}
	}

	function createUpdateToast( registration ) {
		if ( updateToast ) {
			return;
		}

		const toast = document.createElement( 'div' );
		toast.className = 'afc-pwa-update-toast';
		toast.setAttribute( 'role', 'status' );
		toast.innerHTML =
			'<span></span>' +
			'<button type="button"></button>';

		toast.querySelector( 'span' ).textContent = afcPWA.updateReady;
		toast.querySelector( 'button' ).textContent = afcPWA.reload;
		toast.querySelector( 'button' ).addEventListener( 'click', function () {
			if ( registration.waiting ) {
				registration.waiting.postMessage( { type: 'SKIP_WAITING' } );
			}
			window.location.reload();
		} );

		document.body.appendChild( toast );
		window.requestAnimationFrame( function () {
			toast.classList.add( 'is-visible' );
		} );
		updateToast = toast;
	}

	function watchForUpdates( registration ) {
		if ( registration.waiting && navigator.serviceWorker.controller ) {
			createUpdateToast( registration );
		}

		registration.addEventListener( 'updatefound', function () {
			const worker = registration.installing;
			if ( ! worker ) {
				return;
			}

			worker.addEventListener( 'statechange', function () {
				if ( 'installed' === worker.state && navigator.serviceWorker.controller ) {
					createUpdateToast( registration );
				}
			} );
		} );
	}

	async function registerServiceWorker() {
		if ( ! ( 'serviceWorker' in navigator ) || ! isSecureForPWA() ) {
			return;
		}

		try {
			const registration = await navigator.serviceWorker.register(
				afcPWA.serviceWorkerUrl,
				{ scope: afcPWA.scope }
			);
			watchForUpdates( registration );
		} catch ( error ) {
			// Installation help remains available even when registration fails.
		}
	}

	window.addEventListener( 'beforeinstallprompt', function ( event ) {
		event.preventDefault();
		deferredPrompt = event;
		setButtonState( 'ready' );
	} );

	window.addEventListener( 'appinstalled', function () {
		deferredPrompt = null;
		if ( installButton ) {
			installButton.hidden = true;
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		installButton = createInstallButton();
		setButtonState( 'idle' );
		registerServiceWorker();
	} );
}() );
