<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Debug_Logger {
	const OPTION = 'afcn_debug_events_v1';
	const LIMIT  = 100;

	public static function info( $message, $context = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::write( 'info', $message, $context );
		}
	}

	public static function warning( $message, $context = array() ) {
		self::write( 'warning', $message, $context );
	}

	public static function error( $message, $context = array() ) {
		self::write( 'error', $message, $context );
	}

	public static function recent() {
		$events = get_option( self::OPTION, array() );
		return is_array( $events ) ? array_reverse( $events ) : array();
	}

	private static function write( $level, $message, $context ) {
		$events   = get_option( self::OPTION, array() );
		$events[] = array(
			'time'    => gmdate( 'c' ),
			'level'   => sanitize_key( $level ),
			'message' => sanitize_text_field( $message ),
			'context' => self::sanitize_context( $context ),
		);
		$events = array_slice( $events, -self::LIMIT );
		update_option( self::OPTION, $events, false );
	}

	private static function sanitize_context( $context, $depth = 0 ) {
		if ( $depth > 3 || ! is_array( $context ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $context, 0, 30, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( preg_match( '/pass|secret|token|nonce|cookie|authorization|community/i', $key ) ) {
				$out[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = self::sanitize_context( $value, $depth + 1 );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}
}
