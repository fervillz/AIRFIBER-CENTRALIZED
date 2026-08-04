( function () {
	'use strict';

	const config = window.afcMessengerSettings || {};
	let state = null;

	const templateFields = [
		[ 'payment_recorded', 'Detailed payment reply', 'Used when sender success replies are set to Detailed.' ],
		[ 'payment_forward', 'Forwarded payment notice', 'Sent to the other authorized Messenger users.' ],
		[ 'status_active', 'Active status', 'Reply for check commands when the account is active.' ],
		[ 'status_expired', 'Expired status', 'Reply for check commands when the account is expired.' ],
		[ 'status_disabled', 'Disabled status', 'Reply when the PPP account is disabled.' ],
		[ 'duplicate', 'Duplicate payment', 'Reply when the same account was already recorded today.' ],
		[ 'suggestion', 'Suggested match', 'Shown when one likely spelling or alias match is found.' ],
		[ 'not_found', 'No match', 'Shown when no account or useful suggestion can be found.' ],
		[ 'multiple', 'Multiple matches', 'Shown when the typed name matches several accounts.' ],
		[ 'error', 'Processing error', 'Compact fallback when a payment or status request fails.' ],
	];

	const previewData = {
		isp_name: 'Airfiber',
		name: 'John Doe',
		ppp: 'johndoe',
		typed_name: 'johndoe',
		suggested_ppp: 'jonedoe',
		amount: '1000',
		method: 'GCash',
		status: 'active',
		due_date: '9/4/26',
		expiry_date: '8/4/26',
		payment_date: '8/4/26',
		payment_time: '7:51 AM',
		sender: 'Fernando',
		recorded_by: 'Fernando',
		payment_id: 'AFC-2026-00125',
		plan: '1000 Mbps',
		address: 'Manolo Fortich',
		phone: '09XXXXXXXXX',
		match_1: 'jonedoe',
		match_2: 'johnsmith',
		match_3: 'johnrivera',
	};

	function escapeHtml( value ) {
		const node = document.createElement( 'div' );
		node.textContent = value == null ? '' : String( value );
		return node.innerHTML;
	}

	function templateMarkup() {
		return templateFields.map( function ( item ) {
			return '<article class="afc-messenger-template" data-afc-messenger-template="' + item[ 0 ] + '">' +
				'<div><label for="afc-messenger-template-' + item[ 0 ] + '">' + item[ 1 ] + '</label><small>' + item[ 2 ] + '</small></div>' +
				'<textarea id="afc-messenger-template-' + item[ 0 ] + '" rows="' + ( item[ 0 ] === 'payment_recorded' ? '8' : '3' ) + '" data-afc-messenger-template-input="' + item[ 0 ] + '"></textarea>' +
				'<pre data-afc-messenger-template-preview="' + item[ 0 ] + '"></pre>' +
			'</article>';
		} ).join( '' );
	}

	function markup() {
		return '<div class="afc-messenger-message" data-afc-messenger-message hidden></div>' +
			'<section class="afc-messenger-section">' +
				'<header><div><small>BUSINESS PROFILE</small><h3>Page and ISP identity</h3><p>These settings are reusable, so another ISP can install the same plugin and use its own Page, commands and wording.</p></div><label class="afc-messenger-switch"><input type="checkbox" data-afc-messenger-enabled><span></span><b>Enable after webhook setup</b></label></header>' +
				'<div class="afc-messenger-fields afc-messenger-fields-3">' +
					'<label><span>Business name</span><input type="text" data-afc-messenger-business-name autocomplete="organization"></label>' +
					'<label><span>Facebook Page ID</span><input type="text" inputmode="numeric" data-afc-messenger-page-id autocomplete="off"></label>' +
					'<label><span>Meta App ID</span><input type="text" inputmode="numeric" data-afc-messenger-app-id autocomplete="off"></label>' +
					'<label><span>Existing AI integration</span><input type="text" data-afc-messenger-ai-provider placeholder="ManyChat, custom webhook, etc."></label>' +
					'<label><span>Page access token</span><input type="password" data-afc-messenger-page-token autocomplete="new-password" placeholder="Leave blank to keep stored token"><em data-afc-messenger-page-token-state>No token stored</em></label>' +
					'<label><span>Meta App secret</span><input type="password" data-afc-messenger-app-secret autocomplete="new-password" placeholder="Leave blank to keep stored secret"><em data-afc-messenger-app-secret-state>No secret stored</em></label>' +
				'</div>' +
				'<div class="afc-messenger-secret-actions"><label><input type="checkbox" data-afc-messenger-remove-page-token> Remove stored Page token</label><label><input type="checkbox" data-afc-messenger-remove-app-secret> Remove stored App secret</label></div>' +
				'<div class="afc-messenger-verify-row"><label><span>Webhook verify token</span><input type="text" data-afc-messenger-verify-token autocomplete="off"></label><button type="button" class="btn btn-outline-secondary" data-afc-messenger-generate-token>Generate</button><button type="button" class="btn btn-outline-secondary" data-afc-messenger-copy-token>Copy</button></div>' +
				'<div class="afc-messenger-setup-note">The settings and template engine are prepared here. Incoming Facebook webhook processing will be connected in the next Messenger implementation step, after confirming how the existing Page AI integration routes messages.</div>' +
			'</section>' +
			'<section class="afc-messenger-section">' +
				'<header><div><small>AUTHORIZED STAFF</small><h3>Who may record and receive payments</h3><p>Use one line per person: <code>Name | Page-scoped Messenger ID</code>. Payment notices will be forwarded to every other authorized user.</p></div></header>' +
				'<textarea rows="4" data-afc-messenger-authorized-users placeholder="Fernando | 1234567890&#10;Wife | 9876543210"></textarea>' +
			'</section>' +
			'<section class="afc-messenger-section">' +
				'<header><div><small>COMMANDS</small><h3>Simple mobile-friendly chat rules</h3><p>A message containing only a customer or PPP name records payment. Command words stay configurable for other ISPs.</p></div></header>' +
				'<div class="afc-messenger-fields afc-messenger-fields-3">' +
					'<label><span>Status command</span><input type="text" data-afc-messenger-status-command placeholder="check"></label>' +
					'<label><span>Find command</span><input type="text" data-afc-messenger-find-command placeholder="find"></label>' +
					'<label><span>Recent command</span><input type="text" data-afc-messenger-recent-command placeholder="today"></label>' +
					'<label><span>Confirmation words</span><input type="text" data-afc-messenger-confirm-words placeholder="y, yes"></label>' +
					'<label><span>Cancellation words</span><input type="text" data-afc-messenger-cancel-words placeholder="n, no, cancel"></label>' +
					'<label><span>Default payment method</span><select data-afc-messenger-default-method><option value="gcash">GCash</option><option value="cash">Cash</option></select></label>' +
					'<label><span>Date format</span><select data-afc-messenger-date-format><option value="n/j/y">8/4/26</option><option value="m/d/Y">08/04/2026</option><option value="F j, Y">August 4, 2026</option><option value="Y-m-d">2026-08-04</option></select></label>' +
					'<label><span>Sender success reply</span><select data-afc-messenger-success-mode><option value="silent">Silent</option><option value="simple">Simple matched name</option><option value="detailed">Detailed template</option></select></label>' +
					'<label><span>Name forwarded</span><select data-afc-messenger-forward-name-mode><option value="matched">Matched PPP name</option><option value="typed">Name exactly as typed</option></select></label>' +
				'</div>' +
				'<div class="afc-messenger-toggles">' +
					'<label><input type="checkbox" data-afc-messenger-forward-payments> Forward payments to the other authorized users</label>' +
					'<label><input type="checkbox" data-afc-messenger-forward-status> Forward status checks too</label>' +
					'<label><input type="checkbox" data-afc-messenger-block-same-day> Block a second payment for the same account on the same day</label>' +
					'<label><input type="checkbox" data-afc-messenger-learn-aliases> Learn confirmed misspellings and nicknames</label>' +
				'</div>' +
			'</section>' +
			'<section class="afc-messenger-section">' +
				'<header><div><small>REPLY TEMPLATES</small><h3>Customize every Messenger response</h3><p>Only text and approved placeholders are allowed. No PHP, HTML or executable code is accepted.</p></div><button type="button" class="btn btn-outline-secondary" data-afc-messenger-reset-templates>Reset templates</button></header>' +
				'<div class="afc-messenger-placeholders" data-afc-messenger-placeholders></div>' +
				'<div class="afc-messenger-template-list">' + templateMarkup() + '</div>' +
			'</section>' +
			'<section class="afc-messenger-section">' +
				'<header><div><small>LEARNED NAMES</small><h3>Aliases and confirmed spelling corrections</h3><p>One mapping per line: <code>typed name = PPP username</code>. Confirmed Messenger suggestions will be added here automatically later.</p></div><span class="afc-messenger-alias-count" data-afc-messenger-alias-count>0 aliases</span></header>' +
				'<textarea rows="6" data-afc-messenger-aliases placeholder="johndoe = jonedoe"></textarea>' +
				'<div class="afc-messenger-alias-actions"><button type="button" class="btn btn-outline-danger" data-afc-messenger-clear-aliases>Clear aliases</button></div>' +
			'</section>' +
			'<div class="afc-messenger-savebar"><div><strong>Messenger preparation</strong><span data-afc-messenger-save-summary>Save the Page profile, staff, commands and reusable templates.</span></div><button type="button" class="btn btn-primary" data-afc-messenger-save>Save Messenger settings</button></div>';
	}

	function card() {
		return document.querySelector( '.afc-messenger-card' );
	}

	function inject() {
		const target = card();
		if ( ! target || target.dataset.afcMessengerPrepared === '1' ) return false;
		target.dataset.afcMessengerPrepared = '1';
		target.classList.add( 'is-settings-ready' );

		const headerSmall = target.querySelector( 'header small' );
		const headerStatus = target.querySelector( '.afc-integration-status' );
		const body = target.querySelector( '.afc-integration-body' );
		if ( headerSmall ) headerSmall.textContent = 'PAYMENT COMMANDS';
		if ( headerStatus ) {
			headerStatus.className = 'afc-integration-status is-muted';
			headerStatus.setAttribute( 'data-afc-messenger-status', '' );
			headerStatus.textContent = 'Setup needed';
		}
		if ( body ) body.innerHTML = markup();
		bind( target );
		return true;
	}

	function request( action, body ) {
		const data = body || new FormData();
		data.set( 'action', action );
		data.set( 'nonce', config.nonce || '' );
		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} ).then( function ( response ) { return response.json(); } );
	}

	function setMessage( message, type ) {
		const node = document.querySelector( '[data-afc-messenger-message]' );
		if ( ! node ) return;
		node.hidden = ! message;
		node.className = 'afc-messenger-message' + ( type ? ' is-' + type : '' );
		node.textContent = message || '';
	}

	function value( selector, nextValue ) {
		const node = document.querySelector( selector );
		if ( node ) node.value = nextValue == null ? '' : nextValue;
	}

	function checked( selector, nextValue ) {
		const node = document.querySelector( selector );
		if ( node ) node.checked = Boolean( nextValue );
	}

	function renderPreview( key ) {
		const input = document.querySelector( '[data-afc-messenger-template-input="' + key + '"]' );
		const preview = document.querySelector( '[data-afc-messenger-template-preview="' + key + '"]' );
		if ( ! input || ! preview ) return;
		let output = input.value || '';
		Object.keys( previewData ).forEach( function ( placeholder ) {
			output = output.split( '{' + placeholder + '}' ).join( previewData[ placeholder ] );
		} );
		preview.textContent = output || 'Preview appears here.';
	}

	function render( data ) {
		state = data || {};
		value( '[data-afc-messenger-business-name]', state.businessName );
		value( '[data-afc-messenger-page-id]', state.pageId );
		value( '[data-afc-messenger-app-id]', state.appId );
		value( '[data-afc-messenger-ai-provider]', state.aiProvider );
		value( '[data-afc-messenger-verify-token]', state.verifyToken );
		value( '[data-afc-messenger-default-method]', state.defaultMethod );
		value( '[data-afc-messenger-date-format]', state.dateFormat );
		value( '[data-afc-messenger-status-command]', state.statusCommand );
		value( '[data-afc-messenger-find-command]', state.findCommand );
		value( '[data-afc-messenger-recent-command]', state.recentCommand );
		value( '[data-afc-messenger-confirm-words]', state.confirmWords );
		value( '[data-afc-messenger-cancel-words]', state.cancelWords );
		value( '[data-afc-messenger-authorized-users]', state.authorizedUsers );
		value( '[data-afc-messenger-success-mode]', state.successReplyMode );
		value( '[data-afc-messenger-forward-name-mode]', state.forwardNameMode );
		value( '[data-afc-messenger-aliases]', state.aliases );

		checked( '[data-afc-messenger-enabled]', state.enabled );
		checked( '[data-afc-messenger-forward-payments]', state.forwardPayments );
		checked( '[data-afc-messenger-forward-status]', state.forwardStatus );
		checked( '[data-afc-messenger-block-same-day]', state.blockSameDay );
		checked( '[data-afc-messenger-learn-aliases]', state.learnAliases );
		checked( '[data-afc-messenger-remove-page-token]', false );
		checked( '[data-afc-messenger-remove-app-secret]', false );

		const tokenState = document.querySelector( '[data-afc-messenger-page-token-state]' );
		const secretState = document.querySelector( '[data-afc-messenger-app-secret-state]' );
		if ( tokenState ) tokenState.textContent = state.hasPageAccessToken ? 'Encrypted token stored' : 'No token stored';
		if ( secretState ) secretState.textContent = state.hasAppSecret ? 'Encrypted secret stored' : 'No secret stored';
		value( '[data-afc-messenger-page-token]', '' );
		value( '[data-afc-messenger-app-secret]', '' );

		const status = document.querySelector( '[data-afc-messenger-status]' );
		if ( status ) {
			status.textContent = state.processorActive ? 'Connected' : ( state.prepared ? 'Prepared' : 'Setup needed' );
			status.className = 'afc-integration-status' + ( state.processorActive ? ' is-connected' : ( state.prepared ? ' is-prepared' : ' is-muted' ) );
		}

		const count = document.querySelector( '[data-afc-messenger-alias-count]' );
		if ( count ) count.textContent = Number( state.aliasCount || 0 ) + ( Number( state.aliasCount || 0 ) === 1 ? ' alias' : ' aliases' );

		const placeholders = document.querySelector( '[data-afc-messenger-placeholders]' );
		if ( placeholders ) {
			placeholders.innerHTML = ( state.placeholders || [] ).map( function ( item ) {
				return '<code>{' + escapeHtml( item ) + '}</code>';
			} ).join( '' );
		}

		templateFields.forEach( function ( item ) {
			value( '[data-afc-messenger-template-input="' + item[ 0 ] + '"]', state.templates && state.templates[ item[ 0 ] ] ? state.templates[ item[ 0 ] ] : '' );
			renderPreview( item[ 0 ] );
		} );

		const summary = document.querySelector( '[data-afc-messenger-save-summary]' );
		if ( summary ) summary.textContent = state.prepared
			? 'Profile and authorized staff are prepared. The webhook processor is not active yet.'
			: 'Add the Page ID and at least one authorized Messenger user.';
	}

	function setBusy( busy ) {
		document.querySelectorAll( '[data-afc-messenger-save], [data-afc-messenger-reset-templates], [data-afc-messenger-clear-aliases]' ).forEach( function ( button ) {
			button.disabled = busy;
		} );
		const target = card();
		if ( target ) target.classList.toggle( 'is-busy', busy );
	}

	function formData() {
		const body = new FormData();
		function add( key, selector ) {
			const node = document.querySelector( selector );
			body.set( key, node ? node.value.trim() : '' );
		}
		function addCheck( key, selector ) {
			const node = document.querySelector( selector );
			body.set( key, node && node.checked ? '1' : '' );
		}

		addCheck( 'enabled', '[data-afc-messenger-enabled]' );
		add( 'business_name', '[data-afc-messenger-business-name]' );
		add( 'page_id', '[data-afc-messenger-page-id]' );
		add( 'app_id', '[data-afc-messenger-app-id]' );
		add( 'ai_provider', '[data-afc-messenger-ai-provider]' );
		add( 'verify_token', '[data-afc-messenger-verify-token]' );
		add( 'page_access_token', '[data-afc-messenger-page-token]' );
		add( 'app_secret', '[data-afc-messenger-app-secret]' );
		addCheck( 'remove_page_access_token', '[data-afc-messenger-remove-page-token]' );
		addCheck( 'remove_app_secret', '[data-afc-messenger-remove-app-secret]' );
		add( 'authorized_users', '[data-afc-messenger-authorized-users]' );
		add( 'default_method', '[data-afc-messenger-default-method]' );
		add( 'date_format', '[data-afc-messenger-date-format]' );
		add( 'status_command', '[data-afc-messenger-status-command]' );
		add( 'find_command', '[data-afc-messenger-find-command]' );
		add( 'recent_command', '[data-afc-messenger-recent-command]' );
		add( 'confirm_words', '[data-afc-messenger-confirm-words]' );
		add( 'cancel_words', '[data-afc-messenger-cancel-words]' );
		add( 'success_reply_mode', '[data-afc-messenger-success-mode]' );
		add( 'forward_name_mode', '[data-afc-messenger-forward-name-mode]' );
		addCheck( 'forward_payments', '[data-afc-messenger-forward-payments]' );
		addCheck( 'forward_status', '[data-afc-messenger-forward-status]' );
		addCheck( 'block_same_day', '[data-afc-messenger-block-same-day]' );
		addCheck( 'learn_aliases', '[data-afc-messenger-learn-aliases]' );
		add( 'aliases', '[data-afc-messenger-aliases]' );

		templateFields.forEach( function ( item ) {
			add( 'template_' + item[ 0 ], '[data-afc-messenger-template-input="' + item[ 0 ] + '"]' );
		} );
		return body;
	}

	function randomToken() {
		const bytes = new Uint8Array( 24 );
		window.crypto.getRandomValues( bytes );
		return Array.from( bytes ).map( function ( value ) { return value.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
	}

	function bind( target ) {
		target.addEventListener( 'input', function ( event ) {
			const input = event.target.closest( '[data-afc-messenger-template-input]' );
			if ( input ) renderPreview( input.getAttribute( 'data-afc-messenger-template-input' ) );
		} );

		target.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-messenger-generate-token]' ) ) {
				value( '[data-afc-messenger-verify-token]', randomToken() );
				setMessage( 'A new verify token was generated. Save the settings before using it in Meta.', '' );
				return;
			}
			if ( event.target.closest( '[data-afc-messenger-copy-token]' ) ) {
				const token = document.querySelector( '[data-afc-messenger-verify-token]' );
				if ( token && navigator.clipboard ) {
					navigator.clipboard.writeText( token.value ).then( function () { setMessage( 'Verify token copied.', 'success' ); } );
				}
				return;
			}
			if ( event.target.closest( '[data-afc-messenger-save]' ) ) {
				setBusy( true );
				setMessage( 'Saving Messenger settings securely…', '' );
				request( 'afc_messenger_settings_save', formData() ).then( function ( response ) {
					if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Messenger settings could not be saved.' );
					render( response.data );
					setMessage( response.data.message || 'Messenger settings saved.', 'success' );
				} ).catch( function ( error ) {
					setMessage( error.message, 'error' );
				} ).finally( function () { setBusy( false ); } );
				return;
			}
			if ( event.target.closest( '[data-afc-messenger-reset-templates]' ) ) {
				if ( ! window.confirm( 'Reset all Messenger reply templates to their defaults?' ) ) return;
				setBusy( true );
				request( 'afc_messenger_settings_reset_templates' ).then( function ( response ) {
					if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Templates could not be reset.' );
					render( response.data );
					setMessage( response.data.message, 'success' );
				} ).catch( function ( error ) { setMessage( error.message, 'error' ); } ).finally( function () { setBusy( false ); } );
				return;
			}
			if ( event.target.closest( '[data-afc-messenger-clear-aliases]' ) ) {
				if ( ! window.confirm( 'Clear every learned Messenger alias?' ) ) return;
				setBusy( true );
				request( 'afc_messenger_settings_clear_aliases' ).then( function ( response ) {
					if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Aliases could not be cleared.' );
					render( response.data );
					setMessage( response.data.message, 'success' );
				} ).catch( function ( error ) { setMessage( error.message, 'error' ); } ).finally( function () { setBusy( false ); } );
			}
		} );
	}

	function load() {
		setMessage( 'Loading Messenger preparation settings…', '' );
		request( 'afc_messenger_settings_status' ).then( function ( response ) {
			if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Messenger settings could not be loaded.' );
			render( response.data );
			setMessage( '', '' );
		} ).catch( function ( error ) { setMessage( error.message, 'error' ); } );
	}

	function boot() {
		if ( inject() ) {
			load();
			return;
		}
		let attempts = 0;
		const timer = window.setInterval( function () {
			attempts += 1;
			if ( inject() ) {
				window.clearInterval( timer );
				load();
			} else if ( attempts > 40 ) {
				window.clearInterval( timer );
			}
		}, 150 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
