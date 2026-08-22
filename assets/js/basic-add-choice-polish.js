( function () {
	'use strict';

	const icons = {
		ppp: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M16.5 10a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/><path d="M3.5 18.5c.6-3 2.1-4.5 4.5-4.5s3.9 1.5 4.5 4.5"/><path d="M13.5 14.5c.8-.7 1.8-1 3-1 2.1 0 3.4 1.3 4 4"/></svg>',
		onu: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="5" rx="1.5"/><rect x="4" y="14" width="16" height="5" rx="1.5"/><path d="M8 7.5h.01M8 16.5h.01M11 7.5h5M11 16.5h5"/></svg>'
	};

	function apply() {
		const dialog = document.getElementById( 'afc-basic-add-choice-dialog' );
		if ( ! dialog ) return;
		const ppp = dialog.querySelector( '[data-afc-add-choice="ppp"] .afc-add-choice-icon' );
		const onu = dialog.querySelector( '[data-afc-add-choice="onu"] .afc-add-choice-icon' );
		if ( ppp && ppp.dataset.afcIconReady !== '1' ) {
			ppp.innerHTML = icons.ppp;
			ppp.dataset.afcIconReady = '1';
		}
		if ( onu && onu.dataset.afcIconReady !== '1' ) {
			onu.innerHTML = icons.onu;
			onu.dataset.afcIconReady = '1';
		}
	}

	function boot() {
		apply();
		new MutationObserver( apply ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
