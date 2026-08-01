( function () {
	'use strict';

	const config = window.afcSmsPayerRatings || {};
	let customers = [];
	let rules = config.rules || {};
	let templateOptions = [];
	let editingCustomer = null;
	let selectedRating = 3;
	let observer = null;

	function byId( id ) {
		return document.getElementById( id );
	}

	function text( input, fallback ) {
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
				throw new Error( 'Airfiber returned an invalid payor-rating response.' );
			} );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				const message = response && response.data && response.data.message ? response.data.message : 'The payor-rating request failed.';
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
		}, 5000 );
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

	function ratingStars( rating ) {
		const number = Math.max( 0, Math.min( 5, Number( rating ) || 0 ) );
		return number === 0 ? '0★' : '★'.repeat( number ) + '☆'.repeat( 5 - number );
	}

	function profileRule( rating ) {
		const profiles = rules.profiles || {};
		return profiles[ rating ] || profiles[ String( rating ) ] || {
			label: 'Rating ' + rating,
			first_delay_days: 0,
			repeat_days: 3,
			max_7_days: 2,
			max_30_days: 4,
		};
	}

	function injectHeaderButton() {
		if ( byId( 'afc-sms-open-payors' ) ) return;
		const refresh = byId( 'afc-sms-refresh' );
		if ( ! refresh || ! refresh.parentElement ) return;
		const button = document.createElement( 'button' );
		button.id = 'afc-sms-open-payors';
		button.type = 'button';
		button.className = 'btn btn-sm btn-outline-secondary me-2';
		button.textContent = 'Payor Ratings';
		refresh.parentElement.insertBefore( button, refresh );
	}

	function openManager() {
		const manager = byId( 'afc-sms-payor-manager' );
		const backdrop = byId( 'afc-sms-payor-backdrop' );
		if ( ! manager || ! backdrop ) return;
		manager.hidden = false;
		backdrop.hidden = false;
		manager.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'afc-sms-payor-open' );
		loadCustomers();
	}

	function closeManager() {
		closeEditor();
		const manager = byId( 'afc-sms-payor-manager' );
		const backdrop = byId( 'afc-sms-payor-backdrop' );
		if ( manager ) {
			manager.hidden = true;
			manager.setAttribute( 'aria-hidden', 'true' );
		}
		if ( backdrop ) backdrop.hidden = true;
		document.body.classList.remove( 'afc-sms-payor-open' );
	}

	function loadCustomers() {
		return ajax( 'afc_sms_payors_list' ).then( function ( response ) {
			customers = Array.isArray( response.customers ) ? response.customers : [];
			rules = response.rules || rules;
			templateOptions = Array.isArray( response.templates ) ? response.templates : [];
			populateRuleControls();
			renderRules();
			renderCustomers();
			applyConversationRatings();
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} );
	}

	function populateRuleControls() {
		if ( byId( 'afc-sms-payor-automation' ) ) byId( 'afc-sms-payor-automation' ).checked = !! Number( rules.automation_enabled );
		if ( byId( 'afc-sms-rule-start-hour' ) ) byId( 'afc-sms-rule-start-hour' ).value = rules.send_start_hour == null ? 9 : rules.send_start_hour;
		if ( byId( 'afc-sms-rule-end-hour' ) ) byId( 'afc-sms-rule-end-hour' ).value = rules.send_end_hour == null ? 18 : rules.send_end_hour;
		if ( byId( 'afc-sms-rule-max-scan' ) ) byId( 'afc-sms-rule-max-scan' ).value = rules.max_per_scan || 30;
		if ( byId( 'afc-sms-rule-max-day' ) ) byId( 'afc-sms-rule-max-day' ).value = rules.max_per_day || 100;
		if ( byId( 'afc-sms-rule-pause-reply' ) ) byId( 'afc-sms-rule-pause-reply' ).checked = !! Number( rules.pause_on_reply );
	}

	function renderRules() {
		const target = byId( 'afc-sms-rating-rules' );
		if ( ! target ) return;
		target.replaceChildren();
		for ( let rating = 5; rating >= 0; rating-- ) {
			const rule = profileRule( rating );
			const row = document.createElement( 'div' );
			row.className = 'afc-sms-rating-rule';
			row.innerHTML =
				'<div class="afc-sms-rating-rule-name"><strong></strong><span></span></div>' +
				'<label><span>First after due</span><input class="form-control form-control-sm" type="number" min="0" max="30"></label>' +
				'<label><span>Repeat every</span><input class="form-control form-control-sm" type="number" min="1" max="30"></label>' +
				'<label><span>Max / 7 days</span><input class="form-control form-control-sm" type="number" min="1" max="7"></label>' +
				'<label><span>Max / 30 days</span><input class="form-control form-control-sm" type="number" min="1" max="30"></label>';
			row.querySelector( 'strong' ).textContent = ratingStars( rating );
			row.querySelector( '.afc-sms-rating-rule-name span' ).textContent = rule.label;
			const inputs = row.querySelectorAll( 'input' );
			inputs[ 0 ].id = 'afc-sms-rule-' + rating + '-first';
			inputs[ 0 ].value = rule.first_delay_days;
			inputs[ 1 ].id = 'afc-sms-rule-' + rating + '-repeat';
			inputs[ 1 ].value = rule.repeat_days;
			inputs[ 2 ].id = 'afc-sms-rule-' + rating + '-max7';
			inputs[ 2 ].value = rule.max_7_days;
			inputs[ 3 ].id = 'afc-sms-rule-' + rating + '-max30';
			inputs[ 3 ].value = rule.max_30_days;
			target.appendChild( row );
		}
	}

	function stateLabel( customer ) {
		if ( customer.do_not_text ) return 'Do Not Text';
		if ( customer.contact_paused ) return 'Paused';
		return 'Active';
	}

	function renderCustomers() {
		const target = byId( 'afc-sms-payor-list' );
		if ( ! target ) return;
		const query = text( byId( 'afc-sms-payor-search' ) && byId( 'afc-sms-payor-search' ).value ).toLowerCase();
		const ratingFilter = text( byId( 'afc-sms-payor-filter-rating' ) && byId( 'afc-sms-payor-filter-rating' ).value, 'all' );
		const stateFilter = text( byId( 'afc-sms-payor-filter-state' ) && byId( 'afc-sms-payor-filter-state' ).value, 'all' );
		const filtered = customers.filter( function ( customer ) {
			const searchMatch = ! query || [ customer.name, customer.ppp_username, customer.phone, customer.contact_note ].join( ' ' ).toLowerCase().includes( query );
			const ratingMatch = ratingFilter === 'all' || Number( ratingFilter ) === Number( customer.rating );
			const state = customer.do_not_text ? 'dnt' : ( customer.contact_paused ? 'paused' : 'active' );
			return searchMatch && ratingMatch && ( stateFilter === 'all' || stateFilter === state );
		} );
		if ( byId( 'afc-sms-payor-count' ) ) byId( 'afc-sms-payor-count' ).textContent = customers.length + ' customers · ' + customers.filter( function ( item ) { return item.rating >= 4; } ).length + ' reliable payors';
		target.replaceChildren();
		if ( ! filtered.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'afc-sms-payor-empty';
			empty.textContent = 'No customer matches this filter.';
			target.appendChild( empty );
			return;
		}
		filtered.forEach( function ( customer ) {
			const row = document.createElement( 'button' );
			row.type = 'button';
			row.className = 'afc-sms-payor-row' + ( customer.contact_paused || customer.do_not_text ? ' is-paused' : '' );
			row.dataset.afcPayorEdit = customer.customer_id;
			row.innerHTML =
				'<span class="afc-sms-payor-name"><strong></strong><small></small></span>' +
				'<span class="afc-sms-payor-rating"><strong></strong><small></small></span>' +
				'<span class="afc-sms-payor-due"><strong></strong><small>Next due</small></span>' +
				'<span class="afc-sms-payor-state"></span>' +
				'<span class="afc-sms-payor-arrow">›</span>';
			row.querySelector( '.afc-sms-payor-name strong' ).textContent = customer.name || customer.ppp_username;
			row.querySelector( '.afc-sms-payor-name small' ).textContent = [ customer.phone, customer.ppp_username ].filter( Boolean ).join( ' · ' );
			row.querySelector( '.afc-sms-payor-rating strong' ).textContent = ratingStars( customer.rating );
			row.querySelector( '.afc-sms-payor-rating small' ).textContent = customer.rating_label + ( customer.rating_mode === 'auto' ? ' · Auto' : ' · Manual' );
			row.querySelector( '.afc-sms-payor-due strong' ).textContent = customer.next_due || 'Not set';
			const state = row.querySelector( '.afc-sms-payor-state' );
			state.textContent = stateLabel( customer );
			state.className += customer.do_not_text ? ' is-danger' : ( customer.contact_paused ? ' is-warning' : ' is-active' );
			target.appendChild( row );
		} );
	}

	function fillTemplateSelect( selectedId ) {
		const select = byId( 'afc-sms-payor-template-id' );
		if ( ! select ) return;
		select.replaceChildren();
		templateOptions.forEach( function ( item ) {
			const option = document.createElement( 'option' );
			option.value = item.id;
			option.textContent = ( item.category_label || item.category ) + ' · ' + item.title;
			select.appendChild( option );
		} );
		if ( selectedId && Array.from( select.options ).some( function ( item ) { return Number( item.value ) === Number( selectedId ); } ) ) select.value = String( selectedId );
	}

	function openEditor( customer ) {
		if ( ! customer ) return;
		editingCustomer = customer;
		selectedRating = Number( customer.rating );
		const editor = byId( 'afc-sms-payor-editor' );
		if ( ! editor ) return;
		byId( 'afc-sms-payor-customer-id' ).value = customer.customer_id;
		byId( 'afc-sms-payor-editor-name' ).textContent = customer.name || customer.ppp_username;
		byId( 'afc-sms-payor-editor-meta' ).textContent = [ customer.phone, customer.ppp_username, customer.next_due ? 'due ' + customer.next_due : '' ].filter( Boolean ).join( ' · ' );
		byId( 'afc-sms-payor-rating-mode' ).value = customer.rating_mode || 'auto';
		byId( 'afc-sms-payor-paused' ).checked = !! customer.contact_paused;
		byId( 'afc-sms-payor-note' ).value = customer.contact_note || '';
		byId( 'afc-sms-payor-template-mode' ).value = customer.template_mode || 'inherit';
		fillTemplateSelect( customer.template_id );
		byId( 'afc-sms-payor-template-field' ).hidden = byId( 'afc-sms-payor-template-mode' ).value !== 'fixed';
		byId( 'afc-sms-payor-suggestion' ).textContent = 'Automatic suggestion: ' + ratingStars( customer.suggested_rating ) + ' from ' + customer.payments_observed + ' observed payment' + ( customer.payments_observed === 1 ? '' : 's' ) + '.';
		byId( 'afc-sms-payor-history' ).innerHTML =
			'<div><span>On-time payments</span><strong>' + customer.ontime_payments + ' / ' + customer.payments_observed + '</strong></div>' +
			'<div><span>Last payment timing</span><strong>' + ( customer.last_days_late > 0 ? customer.last_days_late + ' day(s) late' : ( customer.payments_observed ? 'On time / early' : 'No recorded timing yet' ) ) + '</strong></div>' +
			'<div><span>Last reminder</span><strong>' + ( customer.last_reminder_at || 'None' ) + '</strong></div>' +
			'<div><span>Last reply</span><strong>' + ( customer.last_reply_at || 'None' ) + '</strong></div>';
		renderStarPicker();
		renderPolicyPreview();
		editor.hidden = false;
		editor.setAttribute( 'aria-hidden', 'false' );
	}

	function closeEditor() {
		editingCustomer = null;
		const editor = byId( 'afc-sms-payor-editor' );
		if ( editor ) {
			editor.hidden = true;
			editor.setAttribute( 'aria-hidden', 'true' );
		}
	}

	function renderStarPicker() {
		document.querySelectorAll( '[data-afc-rating]' ).forEach( function ( button ) {
			const rating = Number( button.dataset.afcRating );
			button.classList.toggle( 'is-selected', rating === selectedRating );
			button.setAttribute( 'aria-checked', rating === selectedRating ? 'true' : 'false' );
		} );
		const rule = profileRule( selectedRating );
		if ( byId( 'afc-sms-payor-rating-label' ) ) byId( 'afc-sms-payor-rating-label' ).textContent = ratingStars( selectedRating ) + ' ' + rule.label;
	}

	function renderPolicyPreview() {
		const target = byId( 'afc-sms-payor-policy-preview' );
		if ( ! target ) return;
		const rule = profileRule( selectedRating );
		target.innerHTML =
			'<strong>Reminder policy</strong>' +
			'<span>First reminder: ' + rule.first_delay_days + ' day(s) after due</span>' +
			'<span>Follow-up: no sooner than every ' + rule.repeat_days + ' day(s)</span>' +
			'<span>Maximum: ' + rule.max_7_days + ' per 7 days and ' + rule.max_30_days + ' per 30 days</span>';
	}

	function saveCustomer() {
		if ( ! editingCustomer ) return;
		const button = byId( 'afc-sms-payor-editor-save' );
		setBusy( button, true, 'Saving…' );
		ajax( 'afc_sms_payor_save', {
			customer_id: editingCustomer.customer_id,
			rating: selectedRating,
			rating_mode: byId( 'afc-sms-payor-rating-mode' ).value,
			contact_paused: byId( 'afc-sms-payor-paused' ).checked ? 1 : 0,
			contact_note: byId( 'afc-sms-payor-note' ).value,
			template_mode: byId( 'afc-sms-payor-template-mode' ).value,
			template_id: byId( 'afc-sms-payor-template-id' ).value,
		} ).then( function ( response ) {
			const updated = response.customer;
			const index = customers.findIndex( function ( item ) { return Number( item.customer_id ) === Number( updated.customer_id ); } );
			if ( index >= 0 ) customers[ index ] = updated;
			notice( response.message || 'Customer policy saved.', 'success' );
			closeEditor();
			renderCustomers();
			applyConversationRatings();
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
		} );
	}

	function collectRules() {
		const data = {
			automation_enabled: byId( 'afc-sms-payor-automation' ).checked ? 1 : 0,
			send_start_hour: byId( 'afc-sms-rule-start-hour' ).value,
			send_end_hour: byId( 'afc-sms-rule-end-hour' ).value,
			max_per_scan: byId( 'afc-sms-rule-max-scan' ).value,
			max_per_day: byId( 'afc-sms-rule-max-day' ).value,
			pause_on_reply: byId( 'afc-sms-rule-pause-reply' ).checked ? 1 : 0,
		};
		for ( let rating = 0; rating <= 5; rating++ ) {
			data[ 'rating_' + rating + '_first' ] = byId( 'afc-sms-rule-' + rating + '-first' ).value;
			data[ 'rating_' + rating + '_repeat' ] = byId( 'afc-sms-rule-' + rating + '-repeat' ).value;
			data[ 'rating_' + rating + '_max7' ] = byId( 'afc-sms-rule-' + rating + '-max7' ).value;
			data[ 'rating_' + rating + '_max30' ] = byId( 'afc-sms-rule-' + rating + '-max30' ).value;
		}
		return data;
	}

	function saveRules() {
		const button = byId( 'afc-sms-payor-save-rules' );
		setBusy( button, true, 'Saving…' );
		ajax( 'afc_sms_payor_rules_save', collectRules() ).then( function ( response ) {
			rules = response.rules || rules;
			renderRules();
			notice( response.message || 'Rules saved.', 'success' );
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
		} );
	}

	function scanNow() {
		if ( ! window.confirm( 'Review due accounts and queue only customers eligible under their rating rules?' ) ) return;
		const button = byId( 'afc-sms-payor-scan' );
		setBusy( button, true, 'Scanning…' );
		ajax( 'afc_sms_due_scan_now' ).then( function ( response ) {
			notice( response.message || 'Due scan complete.', response.result && response.result.queued ? 'success' : 'info' );
			loadCustomers();
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
		} );
	}

	function normalizedPhone( input ) {
		const digits = text( input ).replace( /\D+/g, '' );
		return digits.length >= 10 ? digits.slice( -10 ) : digits;
	}

	function applyConversationRatings() {
		const list = byId( 'afc-sms-conversations' );
		if ( ! list || ! customers.length ) return;
		list.querySelectorAll( '.afc-sms-conversation-item' ).forEach( function ( row ) {
			const identity = row.querySelector( '.afc-sms-list-identity' );
			if ( ! identity ) return;
			const meta = text( identity.querySelector( 'small' ) && identity.querySelector( 'small' ).textContent ).toLowerCase();
			const customer = customers.find( function ( item ) {
				return ( item.ppp_username && meta.includes( item.ppp_username.toLowerCase() ) ) || ( item.phone && normalizedPhone( meta ).includes( normalizedPhone( item.phone ) ) );
			} );
			const old = identity.querySelector( '.afc-sms-conversation-rating' );
			if ( old ) old.remove();
			if ( ! customer ) return;
			const badge = document.createElement( 'span' );
			badge.className = 'afc-sms-conversation-rating rating-' + customer.rating;
			badge.textContent = ratingStars( customer.rating );
			badge.title = customer.rating_label + ( customer.contact_paused ? ' · reminders paused' : '' );
			identity.appendChild( badge );
		} );
	}

	function observeConversationList() {
		const list = byId( 'afc-sms-conversations' );
		if ( ! list || observer ) return;
		observer = new MutationObserver( function () {
			window.requestAnimationFrame( applyConversationRatings );
		} );
		observer.observe( list, { childList: true, subtree: true } );
	}

	function bind() {
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '#afc-sms-open-payors' ) ) openManager();
			if ( event.target.closest( '#afc-sms-payor-close, #afc-sms-payor-backdrop' ) ) closeManager();
			if ( event.target.closest( '#afc-sms-payor-editor-close, #afc-sms-payor-editor-cancel' ) ) closeEditor();
			if ( event.target.closest( '#afc-sms-payor-editor-save' ) ) saveCustomer();
			if ( event.target.closest( '#afc-sms-payor-save-rules' ) ) saveRules();
			if ( event.target.closest( '#afc-sms-payor-scan' ) ) scanNow();
			const edit = event.target.closest( '[data-afc-payor-edit]' );
			if ( edit ) openEditor( customers.find( function ( item ) { return Number( item.customer_id ) === Number( edit.dataset.afcPayorEdit ); } ) );
			const rating = event.target.closest( '[data-afc-rating]' );
			if ( rating ) {
				selectedRating = Number( rating.dataset.afcRating );
				byId( 'afc-sms-payor-rating-mode' ).value = 'manual';
				renderStarPicker();
				renderPolicyPreview();
			}
		} );
		document.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '#afc-sms-payor-template-mode' ) ) byId( 'afc-sms-payor-template-field' ).hidden = event.target.value !== 'fixed';
			if ( event.target.matches( '#afc-sms-payor-rating-mode' ) && editingCustomer && event.target.value === 'auto' ) {
				selectedRating = Number( editingCustomer.suggested_rating );
				renderStarPicker();
				renderPolicyPreview();
			}
			if ( event.target.matches( '#afc-sms-payor-filter-rating, #afc-sms-payor-filter-state' ) ) renderCustomers();
		} );
		const search = byId( 'afc-sms-payor-search' );
		if ( search ) search.addEventListener( 'input', renderCustomers );
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && byId( 'afc-sms-payor-manager' ) && ! byId( 'afc-sms-payor-manager' ).hidden ) closeManager();
		} );
	}

	function boot() {
		injectHeaderButton();
		bind();
		observeConversationList();
		loadCustomers();
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
