<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Event_Bus {
	public static function dispatch( $event ) {
		$args  = array_slice( func_get_args(), 1 );
		$event = sanitize_key( $event );
		foreach ( Module_Registry::all() as $id => $module ) {
			if ( ! in_array( $event, (array) $module['events'], true ) || ! Module_Manager::is_enabled( $id, $module ) ) {
				continue;
			}
			$loaded = Module_Manager::load( $id, false );
			if ( is_wp_error( $loaded ) || ! method_exists( $loaded['class'], 'on_event' ) ) {
				continue;
			}
			try {
				call_user_func_array( array( $loaded['class'], 'on_event' ), array_merge( array( $event ), $args ) );
			} catch ( \Throwable $error ) {
				Circuit_Breaker::record_failure( $id, 'event', $error );
			}
		}
		do_action_ref_array( 'afcn_event_' . $event, $args );
	}

	public static function filter( $event, $value ) {
		$args  = array_slice( func_get_args(), 2 );
		$event = sanitize_key( $event );
		foreach ( Module_Registry::all() as $id => $module ) {
			if ( ! in_array( $event, (array) $module['events'], true ) || ! Module_Manager::is_enabled( $id, $module ) ) {
				continue;
			}
			$loaded = Module_Manager::load( $id, false );
			if ( is_wp_error( $loaded ) || ! method_exists( $loaded['class'], 'filter_event' ) ) {
				continue;
			}
			try {
				$value = call_user_func_array( array( $loaded['class'], 'filter_event' ), array_merge( array( $event, $value ), $args ) );
			} catch ( \Throwable $error ) {
				Circuit_Breaker::record_failure( $id, 'filter', $error );
			}
		}
		return apply_filters_ref_array( 'afcn_filter_' . $event, array_merge( array( $value ), $args ) );
	}
}
