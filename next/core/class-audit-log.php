<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded administrative audit trail. This is intentionally separate from
 * transient developer/debug diagnostics.
 */
class Audit_Log {
	const OPTION = 'afcn_audit_log_v1';
	const LIMIT  = 250;

	public static function record( $action, $subject = '', $context = array() ) {
		$events   = get_option( self::OPTION, array() );
		$events   = is_array( $events ) ? $events : array();
		$events[] = array(
			'time'    => gmdate( 'c' ),
			'actor'   => get_current_user_id(),
			'action'  => sanitize_key( $action ),
			'subject' => sanitize_text_field( $subject ),
			'context' => self::sanitize_context( $context ),
		);
		update_option( self::OPTION, array_slice( $events, -self::LIMIT ), false );
	}

	public static function recent( $limit = 30 ) {
		$events = get_option( self::OPTION, array() );
		$events = is_array( $events ) ? array_reverse( $events ) : array();
		return array_slice( $events, 0, max( 1, min( 100, (int) $limit ) ) );
	}

	private static function sanitize_context( $context, $depth = 0 ) {
		if ( ! is_array( $context ) || $depth > 3 ) {
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
