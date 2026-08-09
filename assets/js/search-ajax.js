( function () {
	'use strict';

	const modules = new Map();

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function matchesInput( input, selector ) {
		return Boolean( input && input.matches && input.matches( selector ) );
	}

	function post( options, payload, signal ) {
		const body = new URLSearchParams();
		body.set( 'action', options.action || '' );
		body.set( 'nonce', options.nonce || '' );

		Object.keys( payload || {} ).forEach( function ( key ) {
			const value = payload[ key ];
			body.set( key, typeof value === 'string' ? value : JSON.stringify( value ) );
		} );

		return window.fetch( options.ajaxUrl || window.ajaxurl || '', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			signal: signal,
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function schedule( module, input ) {
		window.clearTimeout( module.timer );
		module.generation += 1;
		const generation = module.generation;
		const value = text( input && input.value ).trim();
		const minChars = Number( module.options.minChars == null ? 3 : module.options.minChars );
		const delay = Number( module.options.delay == null ? 1000 : module.options.delay );

		if ( module.controller ) {
			module.controller.abort();
			module.controller = null;
		}

		if ( value.length < minChars ) {
			if ( typeof module.options.onClear === 'function' ) module.options.onClear( input, value );
			return;
		}

		module.timer = window.setTimeout( function () {
			const collected = typeof module.options.collect === 'function'
				? module.options.collect( input, value )
				: {};
			if ( collected === false || collected == null ) return;

			module.controller = new AbortController();
			const context = {
				input: input,
				value: value,
				generation: generation,
				signal: module.controller.signal,
				collected: collected,
			};

			if ( typeof module.options.onStart === 'function' ) module.options.onStart( context );

			const request = typeof module.options.request === 'function'
				? module.options.request( context )
				: post( module.options, collected, module.controller.signal );

			Promise.resolve( request ).then( function ( response ) {
				if ( generation !== module.generation ) return;
				if ( typeof module.options.onSuccess === 'function' ) module.options.onSuccess( response, context );
			} ).catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) return;
				if ( generation !== module.generation ) return;
				if ( typeof module.options.onError === 'function' ) module.options.onError( error, context );
			} ).finally( function () {
				if ( generation === module.generation ) module.controller = null;
			} );
		}, delay );
	}

	function register( name, options ) {
		if ( ! name || ! options || ! options.selector ) return null;
		if ( modules.has( name ) ) return modules.get( name ).api;

		const module = {
			options: options,
			timer: null,
			generation: 0,
			controller: null,
			api: null,
		};

		module.api = {
			schedule: function ( input ) { schedule( module, input ); },
			refresh: function () {
				const input = document.querySelector( options.selector );
				if ( input ) schedule( module, input );
			},
			cancel: function () {
				window.clearTimeout( module.timer );
				module.generation += 1;
				if ( module.controller ) module.controller.abort();
				module.controller = null;
			},
		};

		modules.set( name, module );
		return module.api;
	}

	document.addEventListener( 'input', function ( event ) {
		modules.forEach( function ( module ) {
			if ( matchesInput( event.target, module.options.selector ) ) schedule( module, event.target );
		} );
	}, true );

	window.AFCSearchAjax = {
		register: register,
		post: post,
		get: function ( name ) { return modules.has( name ) ? modules.get( name ).api : null; },
	};
}() );
