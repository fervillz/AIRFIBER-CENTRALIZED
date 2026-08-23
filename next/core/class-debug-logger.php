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

	/**
	 * Return the complete recent event history, including resolved entries.
	 */
	public static function recent() {
		$events = get_option( self::OPTION, array() );
		return is_array( $events ) ? array_reverse( $events ) : array();
	}

	/**
	 * Return only warnings/errors that still need attention.
	 *
	 * Resolved events stay in the bounded debug history for audit/troubleshooting,
	 * but no longer belong in the active "Recent performance warnings" table.
	 */
	public static function recent_open() {
		return array_values(
			array_filter(
				self::recent(),
				function ( $event ) {
					return is_array( $event ) && empty( $event['resolved_at'] );
				}
			)
		);
	}

	/**
	 * Stable identifier for both new events and legacy rows written before event
	 * ids existed. The fallback intentionally uses only immutable event fields.
	 */
	public static function event_id( $event ) {
		if ( ! is_array( $event ) ) {
			return '';
		}
		if ( ! empty( $event['id'] ) ) {
			return sanitize_key( (string) $event['id'] );
		}

		$immutable = array(
			'time'    => isset( $event['time'] ) ? (string) $event['time'] : '',
			'level'   => isset( $event['level'] ) ? (string) $event['level'] : '',
			'message' => isset( $event['message'] ) ? (string) $event['message'] : '',
			'context' => isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array(),
		);
		return substr( hash( 'sha256', wp_json_encode( $immutable ) ), 0, 24 );
	}

	/**
	 * Mark a warning as resolved without deleting its history.
	 */
	public static function resolve( $event_id ) {
		$event_id = sanitize_key( (string) $event_id );
		if ( '' === $event_id ) {
			return false;
		}

		$events = get_option( self::OPTION, array() );
		if ( ! is_array( $events ) || empty( $events ) ) {
			return false;
		}

		$changed = false;
		foreach ( $events as &$event ) {
			if ( ! is_array( $event ) || self::event_id( $event ) !== $event_id ) {
				continue;
			}
			$event['resolved_at'] = gmdate( 'c' );
			$event['resolved_by'] = get_current_user_id();
			$changed              = true;
			break;
		}
		unset( $event );

		if ( $changed ) {
			update_option( self::OPTION, array_slice( $events, -self::LIMIT ), false );
		}
		return $changed;
	}

	/**
	 * Metric schema v1 mislabeled the full module REST round trip as "client".
	 * Remove only those known-invalid historical warnings during the one-time
	 * performance metric migration; preserve all other warnings and errors.
	 */
	public static function purge_legacy_client_warnings() {
		$events = get_option( self::OPTION, array() );
		if ( ! is_array( $events ) || empty( $events ) ) {
			return;
		}

		$filtered = array_values(
			array_filter(
				$events,
				function ( $event ) {
					if ( ! is_array( $event ) || 'Module performance budget exceeded.' !== ( $event['message'] ?? '' ) ) {
						return true;
					}
					$reason = isset( $event['context']['reason'] ) ? (string) $event['context']['reason'] : '';
					return 0 !== strpos( $reason, 'client took ' );
				}
			)
		);

		if ( count( $filtered ) !== count( $events ) ) {
			update_option( self::OPTION, array_slice( $filtered, -self::LIMIT ), false );
		}
	}

	private static function write( $level, $message, $context ) {
		$events = get_option( self::OPTION, array() );
		$event  = array(
			'id'      => str_replace( '-', '', wp_generate_uuid4() ),
			'time'    => gmdate( 'c' ),
			'level'   => sanitize_key( $level ),
			'message' => sanitize_text_field( $message ),
			'context' => self::sanitize_context( $context ),
		);
		$events[] = $event;
		$events   = array_slice( $events, -self::LIMIT );
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
