( function () {
	'use strict';

	const instances = new WeakMap();
	let activeInstance = null;
	let tooltipSequence = 0;

	const defaults = {
		content: '',
		placement: 'top',
		offset: 10,
		interactive: false,
		showDelay: 80,
		hideDelay: 140,
		hideOnClick: true,
		className: '',
		shouldShow: null,
		onAction: null
	};

	function clamp( value, minimum, maximum ) {
		return Math.min( Math.max( value, minimum ), maximum );
	}

	function clearTimer( instance, timerName ) {
		if ( instance[ timerName ] ) {
			window.clearTimeout( instance[ timerName ] );
			instance[ timerName ] = null;
		}
	}

	function resolveContent( instance ) {
		return 'function' === typeof instance.options.content
			? instance.options.content( instance.trigger )
			: instance.options.content;
	}

	function renderContent( instance ) {
		const value = resolveContent( instance );
		instance.content.innerHTML = '';

		if ( value instanceof window.Node ) {
			instance.content.appendChild( value );
		} else {
			instance.content.innerHTML = String( value || '' );
		}
	}

	function removePositionListeners( instance ) {
		window.removeEventListener( 'resize', instance.position );
		window.removeEventListener( 'scroll', instance.position, true );
	}

	function addPositionListeners( instance ) {
		removePositionListeners( instance );
		window.addEventListener( 'resize', instance.position );
		window.addEventListener( 'scroll', instance.position, true );
	}

	function positionTooltip( instance ) {
		if ( ! instance.visible || ! instance.trigger.isConnected ) {
			return;
		}

		const triggerRect = instance.trigger.getBoundingClientRect();
		const tooltipRect = instance.tooltip.getBoundingClientRect();
		const viewportPadding = 10;
		const offset = Number( instance.options.offset ) || 10;
		let placement = instance.options.placement || 'top';
		let top;

		if (
			'top' === placement &&
			triggerRect.top - tooltipRect.height - offset < viewportPadding &&
			triggerRect.bottom + tooltipRect.height + offset <= window.innerHeight - viewportPadding
		) {
			placement = 'bottom';
		}

		if ( 'bottom' === placement ) {
			top = triggerRect.bottom + offset;
		} else {
			placement = 'top';
			top = triggerRect.top - tooltipRect.height - offset;
		}

		const centeredLeft = triggerRect.left + ( triggerRect.width / 2 ) - ( tooltipRect.width / 2 );
		const left = clamp(
			centeredLeft,
			viewportPadding,
			Math.max( viewportPadding, window.innerWidth - tooltipRect.width - viewportPadding )
		);
		const triggerCenter = triggerRect.left + ( triggerRect.width / 2 );
		const arrowLeft = clamp( triggerCenter - left, 14, Math.max( 14, tooltipRect.width - 14 ) );

		instance.tooltip.dataset.placement = placement;
		instance.tooltip.style.left = Math.round( left ) + 'px';
		instance.tooltip.style.top = Math.round( top ) + 'px';
		instance.tooltip.style.setProperty( '--afc-tooltip-arrow-left', Math.round( arrowLeft ) + 'px' );
	}

	function hideInstance( instance, immediate ) {
		if ( ! instance ) {
			return;
		}

		clearTimer( instance, 'showTimer' );
		clearTimer( instance, 'hideTimer' );

		const hide = function () {
			instance.visible = false;
			instance.tooltip.classList.remove( 'is-visible' );
			instance.tooltip.setAttribute( 'aria-hidden', 'true' );
			removePositionListeners( instance );
			if ( activeInstance === instance ) {
				activeInstance = null;
			}
		};

		if ( immediate ) {
			hide();
		} else {
			instance.hideTimer = window.setTimeout( hide, Number( instance.options.hideDelay ) || 0 );
		}
	}

	function showInstance( instance, immediate ) {
		if ( ! instance || ! instance.trigger.isConnected ) {
			return;
		}

		if (
			'function' === typeof instance.options.shouldShow &&
			! instance.options.shouldShow( instance.trigger )
		) {
			return;
		}

		clearTimer( instance, 'showTimer' );
		clearTimer( instance, 'hideTimer' );

		const show = function () {
			if ( activeInstance && activeInstance !== instance ) {
				hideInstance( activeInstance, true );
			}

			renderContent( instance );
			instance.visible = true;
			instance.tooltip.dataset.interactive = instance.options.interactive ? 'true' : 'false';
			instance.tooltip.className = 'afc-tooltip' + ( instance.options.className ? ' ' + instance.options.className : '' );
			instance.tooltip.classList.add( 'is-visible' );
			instance.tooltip.setAttribute( 'aria-hidden', 'false' );
			instance.tooltip.setAttribute( 'role', instance.options.interactive ? 'dialog' : 'tooltip' );
			activeInstance = instance;
			positionTooltip( instance );
			addPositionListeners( instance );
		};

		if ( immediate ) {
			show();
		} else {
			instance.showTimer = window.setTimeout( show, Number( instance.options.showDelay ) || 0 );
		}
	}

	function scheduleHide( instance ) {
		if ( instance.options.interactive ) {
			window.setTimeout( function () {
				if ( instance.trigger.matches( ':hover' ) || instance.tooltip.matches( ':hover' ) ) {
					return;
				}
				if ( instance.trigger.contains( document.activeElement ) || instance.tooltip.contains( document.activeElement ) ) {
					return;
				}
				hideInstance( instance, false );
			}, 0 );
			return;
		}
		hideInstance( instance, false );
	}

	function createInstance( trigger, options ) {
		const tooltip = document.createElement( 'div' );
		const content = document.createElement( 'div' );
		const arrow = document.createElement( 'span' );
		const id = 'afc-tooltip-' + ( ++tooltipSequence );

		tooltip.id = id;
		tooltip.className = 'afc-tooltip';
		tooltip.setAttribute( 'aria-hidden', 'true' );
		content.className = 'afc-tooltip-content';
		arrow.className = 'afc-tooltip-arrow';
		arrow.setAttribute( 'aria-hidden', 'true' );
		tooltip.appendChild( content );
		tooltip.appendChild( arrow );
		document.body.appendChild( tooltip );

		const instance = {
			trigger: trigger,
			tooltip: tooltip,
			content: content,
			options: Object.assign( {}, defaults, options || {} ),
			visible: false,
			showTimer: null,
			hideTimer: null,
			position: null,
			listeners: []
		};

		instance.position = function () {
			positionTooltip( instance );
		};

		const listen = function ( element, eventName, handler, listenerOptions ) {
			element.addEventListener( eventName, handler, listenerOptions );
			instance.listeners.push( [ element, eventName, handler, listenerOptions ] );
		};

		listen( trigger, 'mouseenter', function () { showInstance( instance, false ); } );
		listen( trigger, 'mouseleave', function () { scheduleHide( instance ); } );
		listen( trigger, 'focusin', function () { showInstance( instance, true ); } );
		listen( trigger, 'focusout', function () { scheduleHide( instance ); } );
		listen( trigger, 'click', function () {
			if ( instance.options.hideOnClick ) {
				hideInstance( instance, true );
			}
		} );

		listen( tooltip, 'mouseenter', function () {
			if ( instance.options.interactive ) {
				clearTimer( instance, 'hideTimer' );
			}
		} );
		listen( tooltip, 'mouseleave', function () { scheduleHide( instance ); } );
		listen( tooltip, 'focusin', function () { clearTimer( instance, 'hideTimer' ); } );
		listen( tooltip, 'focusout', function () { scheduleHide( instance ); } );
		listen( tooltip, 'click', function ( event ) {
			const action = event.target.closest( '[data-afc-tooltip-action]' );
			if ( ! action || ! tooltip.contains( action ) ) {
				return;
			}
			if ( 'function' === typeof instance.options.onAction ) {
				instance.options.onAction( {
					event: event,
					action: action,
					trigger: trigger,
					tooltip: tooltip
				} );
			}
			hideInstance( instance, true );
		} );

		trigger.setAttribute( 'aria-describedby', id );
		instances.set( trigger, instance );
		return instance;
	}

	function attach( trigger, options ) {
		if ( ! trigger ) {
			return null;
		}

		let instance = instances.get( trigger );
		if ( instance ) {
			instance.options = Object.assign( {}, instance.options, options || {} );
			if ( instance.visible ) {
				renderContent( instance );
				positionTooltip( instance );
			}
			return instance;
		}

		return createInstance( trigger, options );
	}

	function destroy( trigger ) {
		const instance = instances.get( trigger );
		if ( ! instance ) {
			return;
		}

		hideInstance( instance, true );
		instance.listeners.forEach( function ( listener ) {
			listener[0].removeEventListener( listener[1], listener[2], listener[3] );
		} );
		instance.tooltip.remove();
		trigger.removeAttribute( 'aria-describedby' );
		instances.delete( trigger );
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && activeInstance ) {
			hideInstance( activeInstance, true );
		}
	} );

	document.addEventListener( 'pointerdown', function ( event ) {
		if (
			activeInstance &&
			! activeInstance.trigger.contains( event.target ) &&
			! activeInstance.tooltip.contains( event.target )
		) {
			hideInstance( activeInstance, true );
		}
	}, true );

	window.AFCTooltip = {
		attach: attach,
		show: function ( trigger ) { showInstance( instances.get( trigger ), true ); },
		hide: function ( trigger ) { hideInstance( instances.get( trigger ), true ); },
		hideAll: function () { hideInstance( activeInstance, true ); },
		destroy: destroy
	};
}() );
