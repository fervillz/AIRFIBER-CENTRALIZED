( function ( $ ) {
	'use strict';

	const cfg = window.afcOLTOIDExplorer || {};
	let aside = null;
	let toolbar = null;
	let tab = null;
	let pane = null;
	let rootInput = null;
	let resultList = null;
	let statusNode = null;
	let metaNode = null;
	let open = false;
	let observer = null;

	const LABELS = {
		'1.3.6.1': 'internet',
		'1.3.6.1.2.1': 'mib-2',
		'1.3.6.1.2.1.1': 'system',
		'1.3.6.1.4.1': 'enterprises',
		'1.3.6.1.4.1.37950': 'VSOL enterprise',
		'1.3.6.1.4.1.37950.1.1.5': 'V1600D family',
		'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1': 'OPM diagnostics entry',
		'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.3': 'temperature',
		'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.4': 'supply voltage',
		'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.5': 'TX bias',
		'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6': 'TX power',
		'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7': 'RX power',
		'1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7': 'GPON RX candidate'
	};

	function q( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function modal() {
		return q( '[data-afc-olt-modal]' );
	}

	function currentId() {
		const root = modal();
		const field = root ? q( '[name="id"]', root ) : null;
		return field ? Number( field.value || 0 ) : 0;
	}

	function currentRxOid() {
		const root = modal();
		const field = root ? q( '[name="rx_oid"]', root ) : null;
		return field ? String( field.value || '' ).trim() : '';
	}

	function labelFor( oid, fallback ) {
		return fallback || LABELS[ oid ] || '';
	}

	function setStatus( message, tone ) {
		if ( ! statusNode ) return;
		statusNode.textContent = message || '';
		statusNode.className = 'afc-oid-explorer-status is-' + ( tone || 'neutral' );
	}

	function normalizeOid( value ) {
		return String( value || '' ).replace( /[^0-9.]/g, '' ).replace( /^\.+|\.+$/g, '' ).replace( /\.{2,}/g, '.' );
	}

	function parentOid( oid ) {
		const parts = normalizeOid( oid ).split( '.' );
		if ( parts.length <= 2 ) return normalizeOid( oid );
		parts.pop();
		return parts.join( '.' );
	}

	function openExplorer() {
		if ( ! aside || ! pane ) return;
		open = true;
		aside.classList.add( 'is-oid-explorer-open' );
		if ( tab ) tab.setAttribute( 'aria-pressed', 'true' );
		if ( ! rootInput.value ) rootInput.value = cfg.defaultRoot || '1.3.6.1.4.1.37950';
	}

	function closeExplorer() {
		if ( ! aside ) return;
		open = false;
		aside.classList.remove( 'is-oid-explorer-open' );
		if ( tab ) tab.setAttribute( 'aria-pressed', 'false' );
	}

	function useOid( oid ) {
		const root = modal();
		const field = root ? q( '[name="rx_oid"]', root ) : null;
		if ( ! field ) return;
		field.value = oid;
		field.setAttribute( 'data-afc-rx-explorer-selected', '1' );
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		field.animate(
			[
				{ boxShadow: '0 0 0 0 rgba(47,158,86,0)', background: '#fff' },
				{ boxShadow: '0 0 0 4px rgba(47,158,86,.18)', background: '#eef9f1' },
				{ boxShadow: '0 0 0 0 rgba(47,158,86,0)', background: '#fff' }
			],
			{ duration: 950, easing: 'cubic-bezier(.22,1,.36,1)' }
		);
		setStatus( 'Selected ' + oid + ' as the RX signal data path. It will autosave with the OLT.', 'success' );
	}

	function sampleText( samples ) {
		if ( ! Array.isArray( samples ) || ! samples.length ) return '';
		return samples.slice( 0, 2 ).map( function ( sample ) {
			return sample && sample.value ? sample.value : '';
		} ).filter( Boolean ).join( ' · ' );
	}

	function renderChildren( data ) {
		if ( ! resultList ) return;
		resultList.innerHTML = '';
		const children = data && Array.isArray( data.children ) ? data.children : [];

		if ( metaNode ) {
			const label = labelFor( data.root, data.label );
			metaNode.textContent = ( label ? label + ' · ' : '' ) + Number( data.rows || 0 ) + ' OID row(s) · ' + ( data.version || 'SNMP' ) + ' · ' + Number( data.elapsed_ms || 0 ) + ' ms';
		}

		if ( ! children.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'afc-oid-explorer-empty';
			const samples = data && Array.isArray( data.samples ) ? data.samples : [];
			empty.textContent = samples.length
				? 'This is a leaf table. Raw samples are shown below for diagnosis.'
				: 'This branch returned data, but no child OIDs could be grouped beneath it.';
			resultList.appendChild( empty );
			samples.forEach( function ( sample ) {
				const row = document.createElement( 'article' );
				row.className = 'afc-oid-node';
				const body = document.createElement( 'div' );
				body.className = 'afc-oid-node-main';
				const oid = document.createElement( 'code' );
				oid.textContent = sample.oid || '';
				body.appendChild( oid );
				const value = document.createElement( 'small' );
				value.textContent = sample.value || '';
				body.appendChild( value );
				row.appendChild( body );
				resultList.appendChild( row );
			} );
			return;
		}

		children.forEach( function ( child ) {
			const card = document.createElement( 'article' );
			card.className = 'afc-oid-node' + ( child.rx_like ? ' is-rx-like' : '' );

			const main = document.createElement( 'button' );
			main.type = 'button';
			main.className = 'afc-oid-node-main';
			main.title = 'Open this OID branch';
			main.addEventListener( 'click', function () {
				rootInput.value = child.oid;
				scan( child.oid );
			} );

			const top = document.createElement( 'span' );
			top.className = 'afc-oid-node-title';
			const number = document.createElement( 'strong' );
			number.textContent = child.number;
			top.appendChild( number );
			const label = document.createElement( 'b' );
			label.textContent = labelFor( child.oid, child.label ) || 'OID branch';
			top.appendChild( label );
			if ( child.rx_like ) {
				const badge = document.createElement( 'em' );
				badge.textContent = 'RX-like values';
				top.appendChild( badge );
			}
			main.appendChild( top );

			const oid = document.createElement( 'code' );
			oid.textContent = child.oid;
			main.appendChild( oid );

			const sample = sampleText( child.samples );
			const info = document.createElement( 'small' );
			info.textContent = Number( child.rows || 0 ) + ' row(s)' + ( sample ? ' · ' + sample : '' );
			main.appendChild( info );
			card.appendChild( main );

			const actions = document.createElement( 'div' );
			actions.className = 'afc-oid-node-actions';
			const copy = document.createElement( 'button' );
			copy.type = 'button';
			copy.textContent = 'Copy';
			copy.addEventListener( 'click', function () {
				if ( navigator.clipboard && navigator.clipboard.writeText ) navigator.clipboard.writeText( child.oid );
				setStatus( 'Copied ' + child.oid + '.', 'success' );
			} );
			actions.appendChild( copy );

			if ( child.rx_like ) {
				const use = document.createElement( 'button' );
				use.type = 'button';
				use.className = 'is-use';
				use.textContent = 'Use as RX';
				use.addEventListener( 'click', function () { useOid( child.oid ); } );
				actions.appendChild( use );
			}
			card.appendChild( actions );
			resultList.appendChild( card );
		} );
	}

	function scan( requestedRoot ) {
		const id = currentId();
		const root = normalizeOid( requestedRoot || ( rootInput ? rootInput.value : '' ) );
		if ( ! id ) {
			setStatus( 'Save the OLT draft first. OID Explorer uses the saved SNMP connection.', 'error' );
			return;
		}
		if ( ! root || root.indexOf( '.' ) === -1 ) {
			setStatus( 'Enter a numeric OID such as 1.3.6.1.4.1.37950.', 'error' );
			return;
		}

		rootInput.value = root;
		setStatus( 'Scanning ' + root + '…', 'loading' );
		if ( resultList ) resultList.innerHTML = '<div class="afc-oid-explorer-loading"><span></span><span></span><span></span></div>';
		if ( metaNode ) metaNode.textContent = '';

		$.post( cfg.ajaxUrl, {
			action: 'afc_olt_oid_explorer_scan',
			nonce: cfg.nonce,
			id: id,
			root: root
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				const data = response && response.data ? response.data : {};
				const message = data.message || 'This OID branch could not be read.';
				const warning = data.warning ? ' ' + data.warning : '';
				setStatus( message + warning, 'error' );
				if ( resultList ) resultList.innerHTML = '<div class="afc-oid-explorer-empty">Try a different parent branch or change the OLT SNMP Read View so this branch is allowed.</div>';
				return;
			}
			setStatus( 'Scan complete. Click a branch to drill down; RX-like values are highlighted automatically.', 'success' );
			renderChildren( response.data || {} );
		} ).fail( function ( xhr ) {
			const data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
			setStatus( data.message || 'OID scan failed.', 'error' );
			if ( resultList ) resultList.innerHTML = '<div class="afc-oid-explorer-empty">The server could not finish this OID scan.</div>';
		} );
	}

	function presetButton( label, getOid ) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.textContent = label;
		button.addEventListener( 'click', function () {
			const oid = normalizeOid( typeof getOid === 'function' ? getOid() : getOid );
			if ( oid ) {
				rootInput.value = oid;
				scan( oid );
			}
		} );
		return button;
	}

	function buildPane() {
		if ( ! aside || pane ) return;
		pane = document.createElement( 'section' );
		pane.className = 'afc-olt-oid-explorer-pane';
		pane.setAttribute( 'data-afc-olt-oid-explorer', '' );

		const header = document.createElement( 'header' );
		header.className = 'afc-oid-explorer-header';
		const heading = document.createElement( 'div' );
		heading.innerHTML = '<span>LIVE SNMP TREE</span><strong>OID Explorer</strong><small>Browse what this OLT actually exposes instead of guessing OID numbers.</small>';
		header.appendChild( heading );
		const close = document.createElement( 'button' );
		close.type = 'button';
		close.textContent = '×';
		close.setAttribute( 'aria-label', 'Close OID Explorer' );
		close.addEventListener( 'click', closeExplorer );
		header.appendChild( close );
		pane.appendChild( header );

		const controls = document.createElement( 'div' );
		controls.className = 'afc-oid-explorer-controls';
		const rootRow = document.createElement( 'div' );
		rootRow.className = 'afc-oid-explorer-root-row';
		const back = document.createElement( 'button' );
		back.type = 'button';
		back.className = 'afc-oid-explorer-back';
		back.textContent = '←';
		back.title = 'Parent OID';
		back.addEventListener( 'click', function () {
			const next = parentOid( rootInput.value );
			rootInput.value = next;
			scan( next );
		} );
		rootRow.appendChild( back );
		rootInput = document.createElement( 'input' );
		rootInput.type = 'text';
		rootInput.className = 'afc-oid-explorer-root';
		rootInput.value = cfg.defaultRoot || '1.3.6.1.4.1.37950';
		rootInput.placeholder = '1.3.6.1.4.1.37950';
		rootInput.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				scan( rootInput.value );
			}
		} );
		rootRow.appendChild( rootInput );
		const scanButton = document.createElement( 'button' );
		scanButton.type = 'button';
		scanButton.className = 'afc-oid-explorer-scan';
		scanButton.textContent = 'Scan';
		scanButton.addEventListener( 'click', function () { scan( rootInput.value ); } );
		rootRow.appendChild( scanButton );
		controls.appendChild( rootRow );

		const presets = document.createElement( 'div' );
		presets.className = 'afc-oid-explorer-presets';
		presets.appendChild( presetButton( 'System', '1.3.6.1.2.1.1' ) );
		presets.appendChild( presetButton( 'VSOL', '1.3.6.1.4.1.37950' ) );
		presets.appendChild( presetButton( 'Current RX', function () { return currentRxOid(); } ) );
		controls.appendChild( presets );
		pane.appendChild( controls );

		statusNode = document.createElement( 'div' );
		statusNode.className = 'afc-oid-explorer-status is-neutral';
		statusNode.textContent = 'Choose a starting branch. The explorer uses the OLT’s saved SNMP credentials and only reads data.';
		pane.appendChild( statusNode );

		metaNode = document.createElement( 'div' );
		metaNode.className = 'afc-oid-explorer-meta';
		pane.appendChild( metaNode );

		resultList = document.createElement( 'div' );
		resultList.className = 'afc-oid-explorer-results';
		resultList.innerHTML = '<div class="afc-oid-explorer-empty">Start at VSOL, System, Current RX, or type any parent OID. Each scan groups the OLT’s real returned OIDs into clickable child branches.</div>';
		pane.appendChild( resultList );

		aside.appendChild( pane );
	}

	function installTab() {
		aside = q( '[data-afc-olt-help]' );
		toolbar = aside ? q( '[data-afc-olt-devtools-toolbar]', aside ) : null;
		if ( ! aside || ! toolbar ) return false;
		if ( q( '[data-afc-olt-oid-tab]', toolbar ) ) {
			tab = q( '[data-afc-olt-oid-tab]', toolbar );
			buildPane();
			return true;
		}

		tab = document.createElement( 'button' );
		tab.type = 'button';
		tab.className = 'afc-olt-devtools-tab afc-olt-oid-tab';
		tab.textContent = 'OID';
		tab.title = 'OID Explorer';
		tab.setAttribute( 'aria-label', 'Open OID Explorer' );
		tab.setAttribute( 'aria-pressed', 'false' );
		tab.setAttribute( 'data-afc-olt-oid-tab', '' );
		tab.addEventListener( 'click', function () {
			if ( open ) closeExplorer();
			else openExplorer();
		} );

		const spacer = q( '.afc-olt-devtools-spacer', toolbar );
		if ( spacer ) toolbar.insertBefore( tab, spacer );
		else toolbar.appendChild( tab );
		buildPane();
		return true;
	}

	function boot() {
		if ( ! cfg.ajaxUrl || ! cfg.nonce ) return;
		if ( installTab() ) return;
		observer = new MutationObserver( function () {
			if ( installTab() && observer ) {
				observer.disconnect();
				observer = null;
			}
		} );
		observer.observe( document.documentElement, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
