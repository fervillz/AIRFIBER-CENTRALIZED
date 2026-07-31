( function () {
	'use strict';

	if ( ! window.afcCommentFields ) {
		return;
	}

	let fields = Array.isArray( afcCommentFields.fields )
		? afcCommentFields.fields.map( function ( field ) { return Object.assign( {}, field ); } )
		: [];
	let saving = false;

	function panel() {
		return document.querySelector( '[data-afc-panel="comment-fields"]' );
	}

	function list() {
		return document.getElementById( 'afc-comment-fields-list' );
	}

	function customFields() {
		return fields.filter( function ( field ) { return ! field.core; } );
	}

	function keyExists( key, exceptField ) {
		const normalized = String( key || '' ).toLowerCase();
		return fields.some( function ( field ) {
			return field !== exceptField && String( field.key || '' ).toLowerCase() === normalized;
		} );
	}

	function showNotice( message, type ) {
		const notice = document.getElementById( 'afc-comment-fields-notice' );
		if ( ! notice ) {
			return;
		}
		notice.className = 'afc-comment-fields-notice' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		notice.textContent = message || '';
	}

	function inputField( labelText, value, readOnly, className ) {
		const wrapper = document.createElement( 'label' );
		wrapper.className = 'afc-comment-field-control ' + ( className || '' );

		const label = document.createElement( 'span' );
		label.textContent = labelText;
		wrapper.appendChild( label );

		const input = document.createElement( 'input' );
		input.type = 'text';
		input.value = value || '';
		input.readOnly = Boolean( readOnly );
		wrapper.appendChild( input );

		return { wrapper: wrapper, input: input };
	}

	function typeField( field, readOnly ) {
		const wrapper = document.createElement( 'label' );
		wrapper.className = 'afc-comment-field-control afc-comment-field-type';

		const label = document.createElement( 'span' );
		label.textContent = 'Type';
		wrapper.appendChild( label );

		const select = document.createElement( 'select' );
		Object.keys( afcCommentFields.types || {} ).forEach( function ( type ) {
			const option = document.createElement( 'option' );
			option.value = type;
			option.textContent = afcCommentFields.types[ type ];
			option.selected = type === field.type;
			select.appendChild( option );
		} );
		select.disabled = Boolean( readOnly );
		wrapper.appendChild( select );

		return { wrapper: wrapper, select: select };
	}

	function moveCustomField( field, direction ) {
		const core = fields.filter( function ( item ) { return item.core; } );
		const custom = customFields();
		const index = custom.indexOf( field );
		const target = index + direction;
		if ( index < 0 || target < 0 || target >= custom.length ) {
			return;
		}

		custom.splice( index, 1 );
		custom.splice( target, 0, field );
		fields = core.concat( custom );
		render();
	}

	function removeField( field ) {
		fields = fields.filter( function ( item ) { return item !== field; } );
		render();
	}

	function fieldRow( field ) {
		const row = document.createElement( 'article' );
		row.className = 'afc-comment-field-row ' + ( field.core ? 'is-core' : 'is-custom' );

		const top = document.createElement( 'div' );
		top.className = 'afc-comment-field-row-top';

		const badge = document.createElement( 'span' );
		badge.className = 'afc-comment-field-badge';
		badge.textContent = field.core ? afcCommentFields.labels.core : afcCommentFields.labels.custom;
		top.appendChild( badge );

		if ( ! field.core ) {
			const actions = document.createElement( 'div' );
			actions.className = 'afc-comment-field-row-actions';

			const up = document.createElement( 'button' );
			up.type = 'button';
			up.className = 'afc-comment-field-move';
			up.textContent = '↑';
			up.setAttribute( 'aria-label', 'Move field up' );
			up.addEventListener( 'click', function () { moveCustomField( field, -1 ); } );
			actions.appendChild( up );

			const down = document.createElement( 'button' );
			down.type = 'button';
			down.className = 'afc-comment-field-move';
			down.textContent = '↓';
			down.setAttribute( 'aria-label', 'Move field down' );
			down.addEventListener( 'click', function () { moveCustomField( field, 1 ); } );
			actions.appendChild( down );

			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'afc-comment-field-remove';
			remove.textContent = afcCommentFields.labels.remove;
			remove.addEventListener( 'click', function () { removeField( field ); } );
			actions.appendChild( remove );

			top.appendChild( actions );
		}
		row.appendChild( top );

		const controls = document.createElement( 'div' );
		controls.className = 'afc-comment-field-controls';

		const keyControl = inputField( 'Comment key', field.key, field.core, 'afc-comment-field-key' );
		keyControl.input.placeholder = 'exampleField';
		keyControl.input.addEventListener( 'input', function () {
			field.key = keyControl.input.value.replace( /[^A-Za-z0-9_-]/g, '' ).slice( 0, 40 );
			if ( keyControl.input.value !== field.key ) {
				keyControl.input.value = field.key;
			}
			renderPreview();
			renderSuggestions();
		} );
		controls.appendChild( keyControl.wrapper );

		const labelControl = inputField( 'Label', field.label, field.core, 'afc-comment-field-label' );
		labelControl.input.placeholder = 'Readable label';
		labelControl.input.addEventListener( 'input', function () {
			field.label = labelControl.input.value.slice( 0, 60 );
		} );
		controls.appendChild( labelControl.wrapper );

		const typeControl = typeField( field, field.core );
		typeControl.select.addEventListener( 'change', function () {
			field.type = typeControl.select.value;
			renderPreview();
		} );
		controls.appendChild( typeControl.wrapper );

		const defaultControl = inputField( 'Default value', field.default, field.core, 'afc-comment-field-default' );
		defaultControl.input.placeholder = 'Optional';
		defaultControl.input.addEventListener( 'input', function () {
			field.default = defaultControl.input.value.slice( 0, 120 );
			renderPreview();
		} );
		controls.appendChild( defaultControl.wrapper );

		row.appendChild( controls );
		return row;
	}

	function previewValue( field ) {
		if ( field.default ) {
			return field.default;
		}

		const examples = {
			installed: '2025-09-10',
			grace: '6',
			paymentMethod: 'cash',
			paymentAmount: '1299',
			paymentDate: '2026-07-08',
			name: 'CustomerName',
			plan: 'Plan1299',
			cp: '09XXXXXXXXX',
			wifi: 'WiFiName',
			password: '••••••••',
			Address: 'Z5,Lingion,MF'
		};
		if ( Object.prototype.hasOwnProperty.call( examples, field.key ) ) {
			return examples[ field.key ];
		}
		if ( 'date' === field.type ) {
			return 'YYYY-MM-DD';
		}
		if ( 'number' === field.type ) {
			return '0';
		}
		if ( 'boolean' === field.type ) {
			return 'yes';
		}
		return '<value>';
	}

	function renderPreview() {
		const preview = document.getElementById( 'afc-comment-fields-preview' );
		if ( ! preview ) {
			return;
		}

		preview.textContent = fields
			.filter( function ( field ) { return field.key; } )
			.map( function ( field ) { return field.key + ':' + previewValue( field ); } )
			.join( '\n' );
	}

	function addField( source ) {
		if ( customFields().length >= Number( afcCommentFields.maxFields || 30 ) ) {
			showNotice( 'The custom field limit has been reached.', 'warning' );
			return;
		}

		const candidate = Object.assign( {
			key: '',
			label: afcCommentFields.labels.newField,
			type: 'text',
			default: '',
			core: false
		}, source || {} );

		if ( candidate.key && keyExists( candidate.key ) ) {
			showNotice( afcCommentFields.labels.duplicate, 'warning' );
			return;
		}

		fields.push( candidate );
		render();

		const rows = list() ? list().querySelectorAll( '.afc-comment-field-row.is-custom' ) : [];
		const last = rows.length ? rows[ rows.length - 1 ] : null;
		const input = last ? last.querySelector( '.afc-comment-field-key input' ) : null;
		if ( input && ! candidate.key ) {
			input.focus();
		}
	}

	function renderSuggestions() {
		const container = document.getElementById( 'afc-comment-fields-suggestions' );
		if ( ! container ) {
			return;
		}
		container.innerHTML = '';

		( afcCommentFields.suggestions || [] ).forEach( function ( suggestion ) {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'afc-comment-field-suggestion';
			button.disabled = keyExists( suggestion.key );
			button.innerHTML = '<strong></strong><span></span>';
			button.querySelector( 'strong' ).textContent = suggestion.label;
			button.querySelector( 'span' ).textContent = suggestion.key + ':' + ( suggestion.type === 'date' ? 'YYYY-MM-DD' : '<value>' );
			button.addEventListener( 'click', function () { addField( suggestion ); } );
			container.appendChild( button );
		} );
	}

	function render() {
		const container = list();
		if ( ! container ) {
			return;
		}
		container.innerHTML = '';
		fields.forEach( function ( field ) { container.appendChild( fieldRow( field ) ); } );
		renderSuggestions();
		renderPreview();
	}

	function validate() {
		const seen = Object.create( null );
		for ( const field of customFields() ) {
			if ( ! /^[A-Za-z][A-Za-z0-9_-]*$/.test( field.key || '' ) ) {
				showNotice( 'Every custom field needs a valid key beginning with a letter.', 'warning' );
				return false;
			}
			const key = field.key.toLowerCase();
			if ( seen[ key ] ) {
				showNotice( afcCommentFields.labels.duplicate, 'warning' );
				return false;
			}
			seen[ key ] = true;
		}
		return true;
	}

	async function save() {
		if ( saving || ! validate() ) {
			return;
		}

		const button = document.getElementById( 'afc-comment-fields-save' );
		saving = true;
		showNotice( '', '' );
		if ( button ) {
			button.disabled = true;
			button.classList.add( 'is-loading' );
			button.textContent = afcCommentFields.labels.saving;
		}

		const body = new URLSearchParams();
		body.set( 'action', 'afc_save_comment_fields' );
		body.set( 'nonce', afcCommentFields.nonce );
		body.set( 'fields', JSON.stringify( customFields() ) );

		try {
			const response = await fetch( afcCommentFields.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} );
			const data = await response.json();
			if ( ! data.success ) {
				throw new Error( data.data && data.data.message ? data.data.message : afcCommentFields.labels.failed );
			}

			fields = Array.isArray( data.data.fields )
				? data.data.fields.map( function ( field ) { return Object.assign( {}, field ); } )
				: fields;
			render();
			showNotice( data.data.message || afcCommentFields.labels.saved, 'success' );
		} catch ( error ) {
			showNotice( error.message || afcCommentFields.labels.failed, 'danger' );
		} finally {
			saving = false;
			if ( button ) {
				button.disabled = false;
				button.classList.remove( 'is-loading' );
				button.textContent = afcCommentFields.labels.save;
			}
		}
	}

	function addNavigation() {
		const nav = document.querySelector( '.afc-frontend-nav' );
		if ( ! nav || nav.querySelector( '[data-afc-app-panel="comment-fields"]' ) ) {
			return;
		}

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'afc-advanced-only';
		button.setAttribute( 'data-afc-app-panel', 'comment-fields' );
		button.setAttribute( 'aria-pressed', 'false' );
		button.textContent = afcCommentFields.labels.nav;
		nav.appendChild( button );
	}

	function initialize() {
		if ( ! panel() ) {
			return;
		}

		addNavigation();
		render();

		const add = document.getElementById( 'afc-comment-fields-add' );
		const saveButton = document.getElementById( 'afc-comment-fields-save' );
		if ( add ) {
			add.addEventListener( 'click', function () { addField(); } );
		}
		if ( saveButton ) {
			saveButton.addEventListener( 'click', save );
		}

		document.addEventListener( 'afc:admin-mode-change', function ( event ) {
			if ( event.detail && 'basic' === event.detail.mode && ! panel().hidden ) {
				const operations = document.querySelector( '[data-afc-app-panel="operations"]' );
				if ( operations ) {
					operations.click();
				}
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initialize );
	} else {
		initialize();
	}
}() );
