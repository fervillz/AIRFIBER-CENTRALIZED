( function () {
	'use strict';

	const paths = {
		home: '<path d="m3 11 9-8 9 8"/><path d="M5 10v11h14V10M9 21v-7h6v7"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		refresh: '<path d="M20 11a8 8 0 1 0 2 5.3"/><path d="M20 4v7h-7"/>',
		save: '<path d="M5 4h12l2 2v14H5z"/><path d="M8 4v6h8V4M8 20v-6h8v6"/>',
		settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21h-4v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1A1.7 1.7 0 0 0 4.6 15 1.7 1.7 0 0 0 3 14H3v-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6V3h4v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
		money: '<path d="M4 7h16v10H4z"/><circle cx="12" cy="12" r="2.5"/><path d="M7 7a3 3 0 0 1-3 3M17 7a3 3 0 0 0 3 3M7 17a3 3 0 0 0-3-3M17 17a3 3 0 0 1 3-3"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		check: '<path d="m5 12 4 4L19 6"/>',
		printer: '<path d="M7 8V4h10v4M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v6H7z"/>',
		trash: '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"/>',
		download: '<path d="M12 3v12m0 0 5-5m-5 5-5-5M5 20h14"/>',
		upload: '<path d="M12 16V4m0 0 5 5m-5-5-5 5M5 20h14"/>',
		plug: '<path d="M8 3v5M16 3v5M6 8h12v2a6 6 0 0 1-6 6v5M9 21h6"/>',
		eye: '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/>',
		link: '<path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1M14 11a5 5 0 0 0-7.1 0l-2 2A5 5 0 0 0 12 20.1l1.1-1.1"/>',
		user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		play: '<path d="m8 5 11 7-11 7z"/>',
		stop: '<rect x="6" y="6" width="12" height="12" rx="1"/>',
	};

	function icon( name, label ) {
		const path = paths[ name ] || paths.info;
		return '<svg class="afc-ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + path + '</svg>' + ( label ? '<span class="screen-reader-text">' + String( label ) + '</span>' : '' );
	}

	function infer( value ) {
		const label = String( value || '' ).trim().toLowerCase();
		if ( /\b(dashboard|home|overview)\b/.test( label ) ) return 'home';
		if ( /\b(operations?)\b/.test( label ) ) return 'list';
		if ( /\b(integrations?|external service)\b/.test( label ) ) return 'link';
		if ( /\b(schedulers?|billing automation)\b/.test( label ) ) return 'calendar';
		if ( /\b(mikrotik|router)\b/.test( label ) ) return 'plug';
		if ( /\b(messenger)\b/.test( label ) ) return 'mail';
		if ( /\b(add|new|create)\b|^\+$/.test( label ) ) return 'plus';
		if ( /\b(search|find|lookup)\b/.test( label ) ) return 'search';
		if ( /\b(refresh|reload|retry|sync|reconcile|prepare)\b/.test( label ) ) return 'refresh';
		if ( /\b(save|apply)\b/.test( label ) ) return 'save';
		if ( /\b(settings?|manage|options?|configure|setup|rules?)\b/.test( label ) ) return 'settings';
		if ( /\b(payment|paid|pay|record)\b/.test( label ) ) return 'money';
		if ( /\b(sms|message|send|queue|inbox)\b/.test( label ) ) return 'mail';
		if ( /\b(expire|due|cutoff|schedule|time)\b/.test( label ) ) return 'clock';
		if ( /\b(reconnect|connect|connection)\b/.test( label ) ) return 'plug';
		if ( /\b(print)\b/.test( label ) ) return 'printer';
		if ( /\b(delete|remove|clear|trash)\b/.test( label ) ) return 'trash';
		if ( /\b(download|backup|export)\b/.test( label ) ) return 'download';
		if ( /\b(upload|import)\b/.test( label ) ) return 'upload';
		if ( /\b(test|check|approve|confirm|done|enable)\b/.test( label ) ) return 'check';
		if ( /\b(view|preview|show|open)\b/.test( label ) ) return 'eye';
		if ( /\b(user|customer|subscriber|account)\b/.test( label ) ) return 'user';
		return '';
	}

	function shouldDecorate( button ) {
		if ( ! button || button.hasAttribute( 'data-afc-no-auto-icon' ) ) return false;
		if ( button.dataset.afcIconDone === '1' && button.querySelector( '.afc-ui-icon' ) ) return false;
		if ( button.dataset.afcIconDone === '1' ) delete button.dataset.afcIconDone;
		if ( button.classList.contains( 'btn-close' ) || button.matches( '[aria-label="Close"], [data-afc-actions-close], [data-afc-google-help-close]' ) ) return false;
		if ( button.querySelector( '.afc-ui-icon' ) ) return false;
		if ( button.children.length && button.querySelector( 'svg, img, .dashicons, [class*="icon"]' ) ) return false;
		return Boolean( button.getAttribute( 'data-afc-icon' ) || infer( button.textContent ) );
	}

	function decorateButton( button ) {
		if ( ! shouldDecorate( button ) ) return;
		const name = button.getAttribute( 'data-afc-icon' ) || infer( button.textContent );
		if ( ! name ) return;
		button.insertAdjacentHTML( 'afterbegin', icon( name ) );
		button.classList.add( 'afc-has-ui-icon' );
		button.dataset.afcIconDone = '1';
	}

	function decorate( root ) {
		const scope = root || document;
		if ( scope.matches && scope.matches( 'button, a.btn' ) ) decorateButton( scope );
		scope.querySelectorAll( 'button, a.btn' ).forEach( decorateButton );
	}

	window.AFCIcons = Object.freeze( { icon: icon, decorate: decorate, infer: infer, names: Object.keys( paths ) } );

	function boot() {
		decorate( document );
		const observer = new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) { if ( node.nodeType === 1 ) decorate( node ); } );
				if ( mutation.target && mutation.target.nodeType === 1 && mutation.target.matches && mutation.target.matches( 'button, a.btn' ) ) decorateButton( mutation.target );
			} );
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
