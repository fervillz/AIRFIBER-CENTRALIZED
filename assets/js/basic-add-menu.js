( function ( $ ) {
	'use strict';

	const cfg = window.afcGPONStandalone || {};
	const storageKey = 'afcLastGponOlt';
	let allowPppClick = false;
	let activePlan = null;

	function mode() {
		const shell = document.getElementById( 'afc-frontend-app' );
		if ( shell && shell.dataset.afcMode ) return shell.dataset.afcMode;
		return document.body.classList.contains( 'afc-admin-mode-advanced' ) ? 'advanced' : 'basic';
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( null == value ? '' : String( value ) ).html();
	}

	function errorMessage( response, fallback ) {
		return response && response.data && response.data.message ? response.data.message : fallback;
	}

	function normalizeSerial( value ) {
		const compact = String( value || '' ).replace( /[^a-z0-9]/gi, '' );
		return compact.slice( 0, 4 ).toUpperCase() + compact.slice( 4 ).toLowerCase();
	}

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, Object.assign( { action: action, nonce: cfg.nonce }, data || {} ) );
	}

	function ensureStyles() {
		if ( document.getElementById( 'afc-basic-add-menu-style' ) ) return;
		const style = document.createElement( 'style' );
		style.id = 'afc-basic-add-menu-style';
		style.textContent =
			'.afc-add-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:16px}' +
			'.afc-add-choice-card{appearance:none;border:1px solid #d8dee8;border-radius:18px;background:#fff;padding:22px;text-align:left;cursor:pointer;transition:.18s ease;min-height:150px}' +
			'.afc-add-choice-card:hover{border-color:#206bc4;box-shadow:0 10px 28px rgba(32,107,196,.12);transform:translateY(-1px)}' +
			'.afc-add-choice-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:#eef5ff;color:#206bc4;font-size:24px;font-weight:700;margin-bottom:14px}' +
			'.afc-add-choice-card strong{display:block;font-size:18px;margin-bottom:5px}.afc-add-choice-card span{display:block;color:#667085;font-size:13px;line-height:1.45}' +
			'.afc-onu-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.afc-onu-form-grid .is-wide{grid-column:1/-1}' +
			'.afc-onu-plan-list{display:grid;gap:9px;margin:14px 0}.afc-onu-plan-step{display:flex;gap:12px;align-items:flex-start;border:1px solid #e4e8ef;border-radius:12px;padding:12px;background:#fff}' +
			'.afc-onu-plan-step b{display:grid;place-items:center;min-width:30px;height:30px;border-radius:9px;background:#eef8f0;color:#2fb344}.afc-onu-plan-step.is-reuse b{background:#f1f3f5;color:#667085}' +
			'.afc-onu-plan-step strong{display:block}.afc-onu-plan-step small{display:block;color:#667085;margin-top:2px}.afc-onu-summary{padding:12px;border-radius:12px;background:#f6f8fb;margin-bottom:12px}' +
			'.afc-onu-status{min-height:20px;margin-top:10px;font-size:13px}.afc-onu-status.is-error{color:#d63939}.afc-onu-status.is-success{color:#2fb344}.afc-onu-status.is-loading{color:#206bc4}' +
			'@media(max-width:640px){.afc-add-choice-grid,.afc-onu-form-grid{grid-template-columns:1fr}.afc-onu-form-grid .is-wide{grid-column:auto}}';
		document.head.appendChild( style );
	}

	function removeOldStandaloneButton() {
		const old = document.getElementById( 'afc-add-onu' );
		if ( old ) old.remove();
	}

	function dialogShell( id, title, subtitle, body, footer ) {
		return '<dialog id="' + id + '" class="afc-dialog afc-ios-dialog">' +
			'<div class="afc-dialog-header"><div><div class="text-secondary small">' + escapeHtml( subtitle ) + '</div><h3 class="mb-0">' + escapeHtml( title ) + '</h3></div>' +
			'<button class="btn-close" type="button" data-afc-add-close aria-label="Close"></button></div>' +
			'<div class="afc-dialog-body">' + body + '</div>' +
			'<div class="afc-dialog-footer">' + footer + '</div></dialog>';
	}

	function ensureDialogs() {
		ensureStyles();
		if ( ! document.getElementById( 'afc-basic-add-choice-dialog' ) ) {
			document.body.insertAdjacentHTML( 'beforeend', dialogShell(
				'afc-basic-add-choice-dialog',
				'What do you want to add?',
				'New connection',
				'<div class="afc-add-choice-grid">' +
					'<button class="afc-add-choice-card" type="button" data-afc-add-choice="ppp"><span class="afc-add-choice-icon">P</span><strong>PPP Account</strong><span>Create the customer PPP login using the current Basic wizard.</span></button>' +
					'<button class="afc-add-choice-card" type="button" data-afc-add-choice="onu"><span class="afc-add-choice-icon">+</span><strong>GPON ONU</strong><span>Add the ONU directly to a GPON OLT, then create TCONT, GEM, service and service ports.</span></button>' +
				'</div>',
				'<button class="btn btn-link" type="button" data-afc-add-close>Cancel</button>'
			) );
		}

		if ( ! document.getElementById( 'afc-basic-onu-dialog' ) ) {
			document.body.insertAdjacentHTML( 'beforeend', dialogShell(
				'afc-basic-onu-dialog',
				'Add GPON ONU',
				'Standalone OLT provisioning',
				'<div data-afc-onu-form-view>' +
					'<div class="afc-onu-form-grid">' +
						'<div class="is-wide"><label class="form-label">GPON OLT</label><select class="form-select" data-afc-onu-olt required></select><small class="form-hint">Your last selected OLT is remembered on this browser.</small></div>' +
						'<div><label class="form-label">PON</label><input class="form-control" type="number" min="1" max="128" data-afc-onu-pon required placeholder="1"></div>' +
						'<div><label class="form-label">ONU ID</label><input class="form-control" type="number" min="1" max="128" data-afc-onu-id placeholder="Auto"></div>' +
						'<div class="is-wide"><label class="form-label">ONU name</label><input class="form-control" type="text" maxlength="64" data-afc-onu-name required placeholder="Customer / location name"></div>' +
						'<div class="is-wide"><label class="form-label">ONU serial number</label><input class="form-control font-monospace" type="text" maxlength="32" data-afc-onu-serial required placeholder="ABCD12345678"><small class="form-hint">First 4 characters uppercase; remaining characters lowercase.</small></div>' +
						'<div><label class="form-label">VLAN preset</label><select class="form-select" data-afc-onu-vlan-preset><option value="510,399">510 + 399</option><option value="499,434">499 + 434</option><option value="custom">Custom</option></select></div>' +
						'<div><label class="form-label">VLANs</label><input class="form-control" type="text" data-afc-onu-vlans value="510,399" required placeholder="510,399"></div>' +
					'</div>' +
					'<div class="afc-onu-status" data-afc-onu-status></div>' +
				'</div>' +
				'<div data-afc-onu-plan-view hidden><div class="afc-onu-summary" data-afc-onu-summary></div><div class="afc-onu-plan-list" data-afc-onu-plan></div><div class="afc-onu-status" data-afc-onu-plan-status></div></div>' +
				'<div data-afc-onu-success hidden></div>',
				'<button class="btn btn-link me-auto" type="button" data-afc-onu-back hidden>Back</button>' +
				'<button class="btn btn-link" type="button" data-afc-add-close>Cancel</button>' +
				'<button class="btn btn-primary" type="button" data-afc-onu-preview>Continue</button>' +
				'<button class="btn btn-success" type="button" data-afc-onu-execute hidden>Provision ONU</button>' +
				'<button class="btn btn-primary" type="button" data-afc-onu-done hidden>Done</button>'
			) );
		}
	}

	function closeDialog( dialog ) {
		if ( dialog && dialog.open ) dialog.close();
	}

	function fillOlts() {
		const select = document.querySelector( '[data-afc-onu-olt]' );
		if ( ! select ) return;
		select.innerHTML = '';
		( cfg.nodes || [] ).forEach( function ( node ) {
			const option = document.createElement( 'option' );
			option.value = node.id;
			option.textContent = node.name + ( node.host ? ' · ' + node.host : '' );
			select.appendChild( option );
		} );
		let remembered = '';
		try { remembered = window.localStorage.getItem( storageKey ) || ''; } catch ( error ) {}
		if ( remembered && Array.from( select.options ).some( function ( option ) { return option.value === remembered; } ) ) select.value = remembered;
	}

	function resetOnuWizard() {
		activePlan = null;
		const dialog = document.getElementById( 'afc-basic-onu-dialog' );
		if ( ! dialog ) return;
		fillOlts();
		dialog.querySelector( '[data-afc-onu-pon]' ).value = '';
		dialog.querySelector( '[data-afc-onu-id]' ).value = '';
		dialog.querySelector( '[data-afc-onu-name]' ).value = '';
		dialog.querySelector( '[data-afc-onu-serial]' ).value = '';
		dialog.querySelector( '[data-afc-onu-vlan-preset]' ).value = '510,399';
		dialog.querySelector( '[data-afc-onu-vlans]' ).value = '510,399';
		dialog.querySelector( '[data-afc-onu-form-view]' ).hidden = false;
		dialog.querySelector( '[data-afc-onu-plan-view]' ).hidden = true;
		dialog.querySelector( '[data-afc-onu-success]' ).hidden = true;
		dialog.querySelector( '[data-afc-onu-preview]' ).hidden = false;
		dialog.querySelector( '[data-afc-onu-execute]' ).hidden = true;
		dialog.querySelector( '[data-afc-onu-back]' ).hidden = true;
		dialog.querySelector( '[data-afc-onu-done]' ).hidden = true;
		dialog.querySelector( '[data-afc-onu-status]' ).textContent = '';
	}

	function openChoice() {
		ensureDialogs();
		const dialog = document.getElementById( 'afc-basic-add-choice-dialog' );
		if ( dialog && ! dialog.open ) dialog.showModal();
	}

	function openOnu() {
		ensureDialogs();
		resetOnuWizard();
		const dialog = document.getElementById( 'afc-basic-onu-dialog' );
		if ( dialog && ! dialog.open ) dialog.showModal();
	}

	function previewOnu() {
		const dialog = document.getElementById( 'afc-basic-onu-dialog' );
		const olt = dialog.querySelector( '[data-afc-onu-olt]' ).value;
		const pon = dialog.querySelector( '[data-afc-onu-pon]' ).value;
		const onu = dialog.querySelector( '[data-afc-onu-id]' ).value;
		const name = String( dialog.querySelector( '[data-afc-onu-name]' ).value || '' ).trim();
		const serialInput = dialog.querySelector( '[data-afc-onu-serial]' );
		const serial = normalizeSerial( serialInput.value );
		const vlans = dialog.querySelector( '[data-afc-onu-vlans]' ).value;
		const status = dialog.querySelector( '[data-afc-onu-status]' );
		const button = dialog.querySelector( '[data-afc-onu-preview]' );

		serialInput.value = serial;
		if ( ! olt || ! pon || ! name || serial.length < 8 || ! vlans ) {
			status.className = 'afc-onu-status is-error';
			status.textContent = 'Complete OLT, PON, ONU name, serial number and VLANs.';
			return;
		}
		try { window.localStorage.setItem( storageKey, olt ); } catch ( error ) {}

		button.disabled = true;
		status.className = 'afc-onu-status is-loading';
		status.textContent = 'Connecting to the OLT and checking the ONU configuration…';
		post( 'afc_gpon_standalone_preview', { olt_id: olt, pon: pon, onu: onu, onu_name: name, serial: serial, vlans: vlans } )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					status.className = 'afc-onu-status is-error';
					status.textContent = errorMessage( response, 'Could not prepare the ONU.' );
					return;
				}
				activePlan = response.data.plan;
				renderPlan( activePlan );
			} )
			.fail( function ( xhr ) {
				status.className = 'afc-onu-status is-error';
				status.textContent = errorMessage( xhr.responseJSON, 'Could not connect to the GPON OLT.' );
			} )
			.always( function () { button.disabled = false; } );
	}

	function renderPlan( plan ) {
		const dialog = document.getElementById( 'afc-basic-onu-dialog' );
		dialog.querySelector( '[data-afc-onu-form-view]' ).hidden = true;
		dialog.querySelector( '[data-afc-onu-plan-view]' ).hidden = false;
		dialog.querySelector( '[data-afc-onu-preview]' ).hidden = true;
		dialog.querySelector( '[data-afc-onu-execute]' ).hidden = false;
		dialog.querySelector( '[data-afc-onu-back]' ).hidden = false;
		dialog.querySelector( '[data-afc-onu-summary]' ).innerHTML = '<strong>' + escapeHtml( plan.onu_name || 'ONU' ) + '</strong><br>' +
			escapeHtml( plan.olt_name ) + ' · PON ' + escapeHtml( plan.pon ) + ' / ONU ' + escapeHtml( plan.onu ) + '<br><code>' + escapeHtml( plan.serial ) + '</code> · VLAN ' + escapeHtml( ( plan.vlans || [] ).join( ', ' ) );
		dialog.querySelector( '[data-afc-onu-plan]' ).innerHTML = ( plan.steps || [] ).map( function ( step ) {
			const reuse = step.action !== 'create';
			return '<div class="afc-onu-plan-step' + ( reuse ? ' is-reuse' : '' ) + '"><b>' + ( reuse ? '✓' : '+' ) + '</b><div><strong>' + escapeHtml( step.label ) + '</strong><small>' + escapeHtml( step.summary ) + '</small></div></div>';
		} ).join( '' );
		dialog.querySelector( '[data-afc-onu-plan-status]' ).className = 'afc-onu-status';
		dialog.querySelector( '[data-afc-onu-plan-status]' ).textContent = 'Nothing has been changed yet. Submit below to add the ONU and line configuration.';
	}

	function executeOnu() {
		if ( ! activePlan || ! activePlan.token ) return;
		const dialog = document.getElementById( 'afc-basic-onu-dialog' );
		const button = dialog.querySelector( '[data-afc-onu-execute]' );
		const status = dialog.querySelector( '[data-afc-onu-plan-status]' );
		button.disabled = true;
		button.textContent = 'Provisioning…';
		status.className = 'afc-onu-status is-loading';
		status.textContent = 'Adding ONU → TCONT → GEM port → Service → Service ports…';

		post( 'afc_gpon_standalone_execute', { token: activePlan.token } )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					status.className = 'afc-onu-status is-error';
					status.textContent = errorMessage( response, 'ONU provisioning stopped.' );
					button.disabled = false;
					button.textContent = 'Retry Provisioning';
					return;
				}
				const target = response.data.target || {};
				dialog.querySelector( '[data-afc-onu-plan-view]' ).hidden = true;
				dialog.querySelector( '[data-afc-onu-success]' ).hidden = false;
				dialog.querySelector( '[data-afc-onu-success]' ).innerHTML = '<div class="afc-onu-summary"><strong>ONU added successfully</strong><br>' +
					escapeHtml( target.onu_name || '' ) + '<br>' + escapeHtml( target.olt_name || '' ) + ' · PON ' + escapeHtml( target.pon ) + ' / ONU ' + escapeHtml( target.onu ) + '<br><code>' + escapeHtml( target.serial || '' ) + '</code></div>' +
					'<div class="afc-onu-status is-success">TCONT, GEM, service and service ports were processed. PPP remains separate and can auto-link later from MAC information.</div>';
				dialog.querySelector( '[data-afc-onu-execute]' ).hidden = true;
				dialog.querySelector( '[data-afc-onu-back]' ).hidden = true;
				dialog.querySelector( '[data-afc-onu-done]' ).hidden = false;
				$( '#afc-refresh-optical' ).trigger( 'click' );
			} )
			.fail( function ( xhr ) {
				status.className = 'afc-onu-status is-error';
				status.textContent = errorMessage( xhr.responseJSON, 'ONU provisioning failed.' );
				button.disabled = false;
				button.textContent = 'Retry Provisioning';
			} );
	}

	function boot() {
		ensureDialogs();
		removeOldStandaloneButton();
		const observer = new MutationObserver( removeOldStandaloneButton );
		observer.observe( document.body, { childList: true, subtree: true } );

		document.addEventListener( 'click', function ( event ) {
			const trigger = event.target.closest && event.target.closest( '#afc-add-ppp-account, #afc-basic-add-ppp' );
			if ( ! trigger || 'basic' !== mode() ) return;
			if ( allowPppClick ) {
				allowPppClick = false;
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			openChoice();
		}, true );

		$( document ).on( 'click', '[data-afc-add-close]', function () { closeDialog( this.closest( 'dialog' ) ); } );
		$( document ).on( 'click', '[data-afc-add-choice="ppp"]', function () {
			closeDialog( document.getElementById( 'afc-basic-add-choice-dialog' ) );
			const trigger = document.getElementById( 'afc-add-ppp-account' ) || document.getElementById( 'afc-basic-add-ppp' );
			if ( trigger ) {
				allowPppClick = true;
				trigger.click();
			}
		} );
		$( document ).on( 'click', '[data-afc-add-choice="onu"]', function () {
			closeDialog( document.getElementById( 'afc-basic-add-choice-dialog' ) );
			openOnu();
		} );
		$( document ).on( 'change', '[data-afc-onu-olt]', function () {
			try { window.localStorage.setItem( storageKey, this.value ); } catch ( error ) {}
		} );
		$( document ).on( 'change', '[data-afc-onu-vlan-preset]', function () {
			if ( this.value !== 'custom' ) document.querySelector( '[data-afc-onu-vlans]' ).value = this.value;
		} );
		$( document ).on( 'blur', '[data-afc-onu-serial]', function () { this.value = normalizeSerial( this.value ); } );
		$( document ).on( 'click', '[data-afc-onu-preview]', previewOnu );
		$( document ).on( 'click', '[data-afc-onu-back]', function () {
			activePlan = null;
			const dialog = document.getElementById( 'afc-basic-onu-dialog' );
			dialog.querySelector( '[data-afc-onu-form-view]' ).hidden = false;
			dialog.querySelector( '[data-afc-onu-plan-view]' ).hidden = true;
			dialog.querySelector( '[data-afc-onu-preview]' ).hidden = false;
			dialog.querySelector( '[data-afc-onu-execute]' ).hidden = true;
			this.hidden = true;
		} );
		$( document ).on( 'click', '[data-afc-onu-execute]', executeOnu );
		$( document ).on( 'click', '[data-afc-onu-done]', function () { closeDialog( document.getElementById( 'afc-basic-onu-dialog' ) ); } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
