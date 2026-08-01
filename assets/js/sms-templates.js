( function () {
	'use strict';

	const config = window.afcSmsTemplates || {};
	let templates = [];
	let categories = config.categories || {};
	let settings = config.settings || {};
	let activeCategory = settings.default_category || 'due';
	let editingId = 0;
	let lastAppliedTemplateId = 0;

	function byId( id ) {
		return document.getElementById( id );
	}

	function value( input, fallback ) {
		const normalized = input == null ? '' : String( input );
		return normalized || ( fallback || '' );
	}

	function ajax( action, data ) {
		const body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', config.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.set( key, data[ key ] == null ? '' : String( data[ key ] ) );
		} );
		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( 'Airfiber returned an invalid template response.' );
			} );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				const message = response && response.data && response.data.message ? response.data.message : 'The template request failed.';
				throw new Error( message );
			}
			return response.data || {};
		} );
	}

	function notice( message, type ) {
		const target = byId( 'afc-sms-notice' );
		if ( ! target || ! message ) return;
		target.replaceChildren();
		const item = document.createElement( 'div' );
		item.className = 'alert alert-' + ( type || 'info' ) + ' py-2 mb-2';
		item.textContent = message;
		target.appendChild( item );
		window.setTimeout( function () {
			if ( item.parentElement ) item.remove();
		}, 4500 );
	}

	function setBusy( button, busy, label ) {
		if ( ! button ) return;
		if ( busy ) {
			button.dataset.originalLabel = button.textContent;
			button.textContent = label || 'Working…';
			button.disabled = true;
		} else {
			button.textContent = button.dataset.originalLabel || button.textContent;
			button.disabled = false;
		}
	}

	function option( key, label ) {
		const item = document.createElement( 'option' );
		item.value = key;
		item.textContent = label;
		return item;
	}

	function categoryLabel( key ) {
		return categories[ key ] || key.replace( /_/g, ' ' );
	}

	function fillCategorySelect( select, includeAll ) {
		if ( ! select ) return;
		const previous = select.value;
		select.replaceChildren();
		if ( includeAll ) select.appendChild( option( 'all', 'All categories' ) );
		Object.keys( categories ).forEach( function ( key ) {
			select.appendChild( option( key, categoryLabel( key ) ) );
		} );
		if ( previous && Array.from( select.options ).some( function ( item ) { return item.value === previous; } ) ) select.value = previous;
	}

	function enabledTemplates( category ) {
		return templates.filter( function ( item ) {
			return item.enabled && ( ! category || category === 'all' || item.category === category );
		} );
	}

	function injectHeaderButton() {
		if ( byId( 'afc-sms-open-library' ) ) return;
		const refresh = byId( 'afc-sms-refresh' );
		if ( ! refresh || ! refresh.parentElement ) return;
		const button = document.createElement( 'button' );
		button.id = 'afc-sms-open-library';
		button.type = 'button';
		button.className = 'btn btn-sm btn-outline-secondary me-2';
		button.textContent = 'Message Library';
		refresh.parentElement.insertBefore( button, refresh );
	}

	function injectComposerTools() {
		if ( byId( 'afc-sms-template-compose-tools' ) ) return;
		const message = byId( 'afc-sms-message' );
		if ( ! message ) return;
		const body = message.closest( '.afc-sms-composer-body' );
		if ( ! body ) return;
		const label = body.querySelector( 'label[for="afc-sms-message"]' );
		const tools = document.createElement( 'div' );
		tools.id = 'afc-sms-template-compose-tools';
		tools.className = 'afc-sms-template-compose-tools';
		tools.innerHTML =
			'<div class="afc-sms-template-source-row">' +
				'<label><span>Message source</span><select class="form-select form-select-sm" id="afc-sms-compose-mode">' +
					'<option value="manual">Manual message</option>' +
					'<option value="fixed">Fixed template</option>' +
					'<option value="random_category">Random from category</option>' +
					'<option value="random_all">Random from all enabled</option>' +
				'</select></label>' +
				'<label><span>Category</span><select class="form-select form-select-sm" id="afc-sms-compose-category"></select></label>' +
				'<label class="afc-sms-compose-template-field"><span>Template</span><select class="form-select form-select-sm" id="afc-sms-compose-template"></select></label>' +
				'<button class="btn btn-sm btn-outline-primary" id="afc-sms-apply-template" type="button">Apply</button>' +
				'<button class="btn btn-sm btn-outline-secondary" id="afc-sms-compose-library" type="button">Library</button>' +
			'</div>' +
			'<div class="afc-sms-template-values-row">' +
				'<label><span>Due date</span><input class="form-control form-control-sm" id="afc-sms-compose-due-date" type="text" placeholder="today"></label>' +
				'<label><span>Amount</span><input class="form-control form-control-sm" id="afc-sms-compose-amount" type="text" placeholder="regular monthly bill"></label>' +
				'<label><span>Payment number</span><input class="form-control form-control-sm" id="afc-sms-compose-payment-number" type="text" inputmode="tel"></label>' +
			'</div>';
		body.insertBefore( tools, label || message );
	}

	function populateControls() {
		fillCategorySelect( byId( 'afc-sms-library-category' ), true );
		fillCategorySelect( byId( 'afc-sms-library-default-category' ), false );
		fillCategorySelect( byId( 'afc-sms-template-category' ), false );
		fillCategorySelect( byId( 'afc-sms-compose-category' ), false );

		if ( byId( 'afc-sms-library-category' ) ) byId( 'afc-sms-library-category' ).value = activeCategory;
		if ( byId( 'afc-sms-library-payment-number' ) ) byId( 'afc-sms-library-payment-number' ).value = settings.payment_number || '';
		if ( byId( 'afc-sms-library-default-mode' ) ) byId( 'afc-sms-library-default-mode' ).value = settings.default_mode || 'random_category';
		if ( byId( 'afc-sms-library-default-category' ) ) byId( 'afc-sms-library-default-category' ).value = settings.default_category || 'due';
		if ( byId( 'afc-sms-compose-mode' ) ) byId( 'afc-sms-compose-mode' ).value = settings.default_mode || 'random_category';
		if ( byId( 'afc-sms-compose-category' ) ) byId( 'afc-sms-compose-category' ).value = settings.default_category || 'due';
		if ( byId( 'afc-sms-compose-payment-number' ) ) byId( 'afc-sms-compose-payment-number' ).value = settings.payment_number || '';
		updateComposerTemplateSelect();
		updateComposerMode();
	}

	function updateComposerTemplateSelect() {
		const select = byId( 'afc-sms-compose-template' );
		const category = value( byId( 'afc-sms-compose-category' ) && byId( 'afc-sms-compose-category' ).value, 'due' );
		if ( ! select ) return;
		const previous = select.value || String( settings.default_template_id || '' );
		select.replaceChildren();
		enabledTemplates( category ).forEach( function ( item ) {
			select.appendChild( option( String( item.id ), item.title ) );
		} );
		if ( previous && Array.from( select.options ).some( function ( item ) { return item.value === previous; } ) ) select.value = previous;
	}

	function updateComposerMode() {
		const mode = value( byId( 'afc-sms-compose-mode' ) && byId( 'afc-sms-compose-mode' ).value, 'manual' );
		const field = document.querySelector( '.afc-sms-compose-template-field' );
		if ( field ) field.hidden = mode !== 'fixed';
	}

	function chooseTemplate() {
		const mode = value( byId( 'afc-sms-compose-mode' ) && byId( 'afc-sms-compose-mode' ).value, 'manual' );
		const category = value( byId( 'afc-sms-compose-category' ) && byId( 'afc-sms-compose-category' ).value, 'due' );
		if ( mode === 'manual' ) return null;
		if ( mode === 'fixed' ) {
			const id = Number( byId( 'afc-sms-compose-template' ) && byId( 'afc-sms-compose-template' ).value );
			return templates.find( function ( item ) { return item.id === id && item.enabled; } ) || enabledTemplates( category )[ 0 ] || null;
		}
		const pool = mode === 'random_all' ? enabledTemplates( 'all' ) : enabledTemplates( category );
		return pool.length ? pool[ Math.floor( Math.random() * pool.length ) ] : null;
	}

	function replaceExtraTokens( body ) {
		const dueDate = value( byId( 'afc-sms-compose-due-date' ) && byId( 'afc-sms-compose-due-date' ).value, 'today' );
		const amount = value( byId( 'afc-sms-compose-amount' ) && byId( 'afc-sms-compose-amount' ).value, 'your regular monthly bill' );
		const payment = value( byId( 'afc-sms-compose-payment-number' ) && byId( 'afc-sms-compose-payment-number' ).value, settings.payment_number || '09978230630' );
		return value( body )
			.replace( /\{due_date\}/gi, dueDate )
			.replace( /\{amount\}/gi, amount )
			.replace( /\{payment_number\}/gi, payment );
	}

	function applyTemplate( showNotice ) {
		const template = chooseTemplate();
		if ( ! template ) {
			lastAppliedTemplateId = 0;
			if ( showNotice && value( byId( 'afc-sms-compose-mode' ) && byId( 'afc-sms-compose-mode' ).value ) !== 'manual' ) notice( 'No enabled template is available for that selection.', 'warning' );
			return null;
		}
		const message = byId( 'afc-sms-message' );
		if ( message ) message.value = replaceExtraTokens( template.body );
		lastAppliedTemplateId = template.id;
		if ( showNotice ) notice( 'Applied “' + template.title + '”.', 'success' );
		ajax( 'afc_sms_template_used', { template_id: template.id } ).catch( function () {} );
		return template;
	}

	function renderCategories() {
		const target = byId( 'afc-sms-library-categories' );
		if ( ! target ) return;
		target.replaceChildren();
		const items = Object.assign( { all: 'All messages' }, categories );
		Object.keys( items ).forEach( function ( key ) {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = key === activeCategory ? 'is-active' : '';
			button.dataset.afcTemplateCategory = key;
			const count = key === 'all' ? templates.length : templates.filter( function ( item ) { return item.category === key; } ).length;
			button.innerHTML = '<span>' + items[ key ] + '</span><strong>' + count + '</strong>';
			target.appendChild( button );
		} );
	}

	function renderList() {
		const target = byId( 'afc-sms-library-list' );
		if ( ! target ) return;
		const query = value( byId( 'afc-sms-library-search' ) && byId( 'afc-sms-library-search' ).value ).toLowerCase();
		const filtered = templates.filter( function ( item ) {
			const categoryMatch = activeCategory === 'all' || item.category === activeCategory;
			const searchMatch = ! query || ( item.title + ' ' + item.body ).toLowerCase().includes( query );
			return categoryMatch && searchMatch;
		} );
		if ( byId( 'afc-sms-library-count' ) ) byId( 'afc-sms-library-count' ).textContent = templates.length + ' templates · ' + enabledTemplates( 'all' ).length + ' enabled';
		target.replaceChildren();
		if ( ! filtered.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'afc-sms-library-empty';
			empty.textContent = 'No message matches this filter.';
			target.appendChild( empty );
			return;
		}
		filtered.forEach( function ( item ) {
			const card = document.createElement( 'article' );
			card.className = 'afc-sms-template-card' + ( item.enabled ? '' : ' is-disabled' );
			card.innerHTML =
				'<div class="afc-sms-template-card-head"><div><strong></strong><span></span></div><label class="form-check form-switch"><input class="form-check-input" type="checkbox"><span class="form-check-label">Enabled</span></label></div>' +
				'<p></p><footer><small></small><div><button class="btn btn-sm btn-outline-primary" type="button">Use</button><button class="btn btn-sm btn-outline-secondary" type="button">Edit</button></div></footer>';
			card.querySelector( 'strong' ).textContent = item.title;
			card.querySelector( '.afc-sms-template-card-head span' ).textContent = categoryLabel( item.category );
			card.querySelector( 'p' ).textContent = item.body;
			card.querySelector( 'small' ).textContent = 'Used ' + item.use_count + ' time' + ( item.use_count === 1 ? '' : 's' );
			const toggle = card.querySelector( 'input' );
			toggle.checked = item.enabled;
			toggle.dataset.afcTemplateToggle = item.id;
			const buttons = card.querySelectorAll( 'button' );
			buttons[ 0 ].dataset.afcTemplateUse = item.id;
			buttons[ 1 ].dataset.afcTemplateEdit = item.id;
			target.appendChild( card );
		} );
	}

	function renderAll() {
		renderCategories();
		renderList();
		updateComposerTemplateSelect();
	}

	function loadTemplates() {
		return ajax( 'afc_sms_templates_list' ).then( function ( response ) {
			templates = Array.isArray( response.templates ) ? response.templates : [];
			categories = response.categories || categories;
			settings = response.settings || settings;
			populateControls();
			renderAll();
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} );
	}

	function openLibrary() {
		const library = byId( 'afc-sms-library' );
		const backdrop = byId( 'afc-sms-library-backdrop' );
		if ( ! library || ! backdrop ) return;
		library.hidden = false;
		backdrop.hidden = false;
		library.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'afc-sms-library-open' );
		loadTemplates();
	}

	function closeLibrary() {
		closeEditor();
		const library = byId( 'afc-sms-library' );
		const backdrop = byId( 'afc-sms-library-backdrop' );
		if ( library ) {
			library.hidden = true;
			library.setAttribute( 'aria-hidden', 'true' );
		}
		if ( backdrop ) backdrop.hidden = true;
		document.body.classList.remove( 'afc-sms-library-open' );
	}

	function openEditor( item ) {
		editingId = item ? item.id : 0;
		const editor = byId( 'afc-sms-template-editor' );
		if ( ! editor ) return;
		byId( 'afc-sms-template-id' ).value = String( editingId );
		byId( 'afc-sms-template-category' ).value = item ? item.category : ( activeCategory === 'all' ? 'due' : activeCategory );
		byId( 'afc-sms-template-title' ).value = item ? item.title : '';
		byId( 'afc-sms-template-body' ).value = item ? item.body : '';
		byId( 'afc-sms-template-enabled' ).checked = item ? item.enabled : true;
		byId( 'afc-sms-template-editor-title' ).textContent = item ? 'Edit message' : 'New message';
		byId( 'afc-sms-template-editor-delete' ).hidden = ! item;
		editor.hidden = false;
		editor.setAttribute( 'aria-hidden', 'false' );
	}

	function closeEditor() {
		editingId = 0;
		const editor = byId( 'afc-sms-template-editor' );
		if ( editor ) {
			editor.hidden = true;
			editor.setAttribute( 'aria-hidden', 'true' );
		}
	}

	function saveEditor() {
		const button = byId( 'afc-sms-template-editor-save' );
		setBusy( button, true, 'Saving…' );
		ajax( 'afc_sms_template_save', {
			template_id: editingId,
			category: byId( 'afc-sms-template-category' ).value,
			title: byId( 'afc-sms-template-title' ).value,
			body: byId( 'afc-sms-template-body' ).value,
			enabled: byId( 'afc-sms-template-enabled' ).checked ? 1 : 0,
		} ).then( function ( response ) {
			notice( response.message || 'Template saved.', 'success' );
			closeEditor();
			return loadTemplates();
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
		} );
	}

	function deleteEditor() {
		if ( ! editingId || ! window.confirm( 'Delete this SMS template?' ) ) return;
		const button = byId( 'afc-sms-template-editor-delete' );
		setBusy( button, true, 'Deleting…' );
		ajax( 'afc_sms_template_delete', { template_id: editingId } ).then( function ( response ) {
			notice( response.message || 'Template deleted.', 'success' );
			closeEditor();
			return loadTemplates();
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
		} );
	}

	function toggleTemplate( input ) {
		ajax( 'afc_sms_template_toggle', {
			template_id: input.dataset.afcTemplateToggle,
			enabled: input.checked ? 1 : 0,
		} ).then( loadTemplates ).catch( function ( error ) {
			input.checked = ! input.checked;
			notice( error.message, 'danger' );
		} );
	}

	function saveSettings() {
		const button = byId( 'afc-sms-library-save-settings' );
		setBusy( button, true, 'Saving…' );
		ajax( 'afc_sms_template_settings', {
			payment_number: byId( 'afc-sms-library-payment-number' ).value,
			default_mode: byId( 'afc-sms-library-default-mode' ).value,
			default_category: byId( 'afc-sms-library-default-category' ).value,
			default_template_id: byId( 'afc-sms-compose-template' ) ? byId( 'afc-sms-compose-template' ).value : 0,
		} ).then( function ( response ) {
			settings = response.settings || settings;
			populateControls();
			notice( response.message || 'Defaults saved.', 'success' );
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
		} );
	}

	function useTemplate( id ) {
		const item = templates.find( function ( template ) { return template.id === Number( id ); } );
		if ( ! item ) return;
		if ( byId( 'afc-sms-compose-mode' ) ) byId( 'afc-sms-compose-mode' ).value = 'fixed';
		if ( byId( 'afc-sms-compose-category' ) ) byId( 'afc-sms-compose-category' ).value = item.category;
		updateComposerTemplateSelect();
		if ( byId( 'afc-sms-compose-template' ) ) byId( 'afc-sms-compose-template' ).value = String( item.id );
		updateComposerMode();
		const message = byId( 'afc-sms-message' );
		if ( message ) message.value = replaceExtraTokens( item.body );
		lastAppliedTemplateId = item.id;
		ajax( 'afc_sms_template_used', { template_id: item.id } ).catch( function () {} );
		closeLibrary();
	}

	function bind() {
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '#afc-sms-open-library, #afc-sms-compose-library' ) ) openLibrary();
			if ( event.target.closest( '#afc-sms-library-close, #afc-sms-library-backdrop' ) ) closeLibrary();
			if ( event.target.closest( '#afc-sms-library-add' ) ) openEditor( null );
			if ( event.target.closest( '#afc-sms-template-editor-close, #afc-sms-template-editor-cancel' ) ) closeEditor();
			if ( event.target.closest( '#afc-sms-template-editor-save' ) ) saveEditor();
			if ( event.target.closest( '#afc-sms-template-editor-delete' ) ) deleteEditor();
			if ( event.target.closest( '#afc-sms-library-save-settings' ) ) saveSettings();
			if ( event.target.closest( '#afc-sms-apply-template' ) ) applyTemplate( true );
			const category = event.target.closest( '[data-afc-template-category]' );
			if ( category ) {
				activeCategory = category.dataset.afcTemplateCategory;
				if ( byId( 'afc-sms-library-category' ) ) byId( 'afc-sms-library-category' ).value = activeCategory;
				renderAll();
			}
			const edit = event.target.closest( '[data-afc-template-edit]' );
			if ( edit ) openEditor( templates.find( function ( item ) { return item.id === Number( edit.dataset.afcTemplateEdit ); } ) || null );
			const use = event.target.closest( '[data-afc-template-use]' );
			if ( use ) useTemplate( use.dataset.afcTemplateUse );
			if ( event.target.closest( '#afc-sms-library-restore' ) && window.confirm( 'Restore any missing starter templates?' ) ) {
				ajax( 'afc_sms_template_restore' ).then( function ( response ) {
					notice( response.message, 'success' );
					loadTemplates();
				} ).catch( function ( error ) { notice( error.message, 'danger' ); } );
			}
			if ( event.target.closest( '#afc-sms-new-message, #afc-sms-compose-selected' ) ) {
				window.setTimeout( function () {
					if ( value( byId( 'afc-sms-compose-mode' ) && byId( 'afc-sms-compose-mode' ).value ) !== 'manual' ) applyTemplate( false );
				}, 30 );
			}
		}, true );

		document.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-afc-template-toggle]' ) ) toggleTemplate( event.target );
			if ( event.target.matches( '#afc-sms-compose-mode' ) ) updateComposerMode();
			if ( event.target.matches( '#afc-sms-compose-category' ) ) updateComposerTemplateSelect();
			if ( event.target.matches( '#afc-sms-library-category' ) ) {
				activeCategory = event.target.value;
				renderAll();
			}
		} );

		const search = byId( 'afc-sms-library-search' );
		if ( search ) search.addEventListener( 'input', renderList );
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && byId( 'afc-sms-library' ) && ! byId( 'afc-sms-library' ).hidden ) closeLibrary();
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '#afc-sms-queue-test' ) ) {
				const mode = value( byId( 'afc-sms-compose-mode' ) && byId( 'afc-sms-compose-mode' ).value, 'manual' );
				if ( mode !== 'manual' ) applyTemplate( false );
			}
		}, true );
	}

	function boot() {
		injectHeaderButton();
		injectComposerTools();
		populateControls();
		bind();
		loadTemplates();
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
