<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Small cached health store for external connections.
 *
 * Health data is intentionally separate from connection configuration so
 * remote latency/status updates never rewrite credentials or module settings.
 */
class Connection_Health {
	const OPTION_HEALTH = 'afcn_connection_health_v1';
	const MAX_RECORDS   = 250;

	public static function all() {
		$value = get_option( self::OPTION_HEALTH, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function get( $connection_id ) {
		$connection_id = self::clean_id( $connection_id );
		$all           = self::all();
		return $connection_id && isset( $all[ $connection_id ] ) && is_array( $all[ $connection_id ] )
			? $all[ $connection_id ]
			: self::unknown();
	}

	public static function set( $connection_id, $data = array() ) {
		$connection_id = self::clean_id( $connection_id );
		if ( ! $connection_id ) {
			return false;
		}

		$allowed_states = array( 'online', 'offline', 'warning', 'unconfigured', 'error', 'unknown' );
		$state          = isset( $data['state'] ) ? sanitize_key( $data['state'] ) : 'unknown';
		if ( ! in_array( $state, $allowed_states, true ) ) {
			$state = 'unknown';
		}

		$row = array(
			'state'      => $state,
			'message'    => isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '',
			'latency_ms' => isset( $data['latency_ms'] ) ? max( 0, round( (float) $data['latency_ms'], 2 ) ) : 0,
			'checked_at' => isset( $data['checked_at'] ) ? absint( $data['checked_at'] ) : time(),
			'details'    => isset( $data['details'] ) && is_array( $data['details'] ) ? self::sanitize_details( $data['details'] ) : array(),
		);

		$all                   = self::all();
		$all[ $connection_id ] = $row;
		$all                   = self::trim( $all );
		update_option( self::OPTION_HEALTH, $all, false );
		return $row;
	}

	public static function clear( $connection_id ) {
		$connection_id = self::clean_id( $connection_id );
		$all           = self::all();
		if ( $connection_id && isset( $all[ $connection_id ] ) ) {
			unset( $all[ $connection_id ] );
			update_option( self::OPTION_HEALTH, $all, false );
		}
		return true;
	}

	public static function unknown() {
		return array(
			'state'      => 'unknown',
			'message'    => '',
			'latency_ms' => 0,
			'checked_at' => 0,
			'details'    => array(),
		);
	}

	private static function trim( $all ) {
		if ( count( $all ) <= self::MAX_RECORDS ) {
			return $all;
		}
		uasort(
			$all,
			function ( $left, $right ) {
				return (int) ( isset( $right['checked_at'] ) ? $right['checked_at'] : 0 ) <=> (int) ( isset( $left['checked_at'] ) ? $left['checked_at'] : 0 );
			}
		);
		return array_slice( $all, 0, self::MAX_RECORDS, true );
	}

	private static function sanitize_details( $details ) {
		$output = array();
		foreach ( array_slice( $details, 0, 20, true ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! $key || is_array( $value ) || is_object( $value ) || is_resource( $value ) ) {
				continue;
			}
			$output[ $key ] = sanitize_text_field( (string) $value );
		}
		return $output;
	}

	private static function clean_id( $value ) {
		$value = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $value ) );
		return substr( $value, 0, 80 );
	}
}
