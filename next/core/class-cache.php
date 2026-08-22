<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Cache {
	public static function get( $module, $key, $default = null ) {
		$value = get_transient( self::key( $module, $key ) );
		return false === $value ? $default : $value;
	}

	public static function set( $module, $key, $value, $ttl = 60 ) {
		return set_transient( self::key( $module, $key ), $value, max( 1, (int) $ttl ) );
	}

	public static function delete( $module, $key ) {
		return delete_transient( self::key( $module, $key ) );
	}

	public static function remember( $module, $key, $ttl, $callback ) {
		$cached = self::get( $module, $key, null );
		if ( null !== $cached ) {
			return $cached;
		}
		$value = call_user_func( $callback );
		self::set( $module, $key, $value, $ttl );
		return $value;
	}

	public static function put_stale( $module, $key, $value, $fresh_seconds = 60, $stale_seconds = 300 ) {
		$now = time();
		return self::set(
			$module,
			$key,
			array(
				'value'       => $value,
				'fresh_until' => $now + max( 1, (int) $fresh_seconds ),
				'stale_until' => $now + max( (int) $fresh_seconds, (int) $stale_seconds ),
			),
			max( (int) $fresh_seconds, (int) $stale_seconds )
		);
	}

	public static function get_stale( $module, $key ) {
		$envelope = self::get( $module, $key, null );
		if ( ! is_array( $envelope ) || ! array_key_exists( 'value', $envelope ) ) {
			return array( 'hit' => false, 'stale' => true, 'value' => null );
		}
		$now = time();
		return array(
			'hit'   => isset( $envelope['stale_until'] ) && $now <= (int) $envelope['stale_until'],
			'stale' => ! isset( $envelope['fresh_until'] ) || $now > (int) $envelope['fresh_until'],
			'value' => $envelope['value'],
		);
	}

	private static function key( $module, $key ) {
		return 'afcn_' . substr( md5( sanitize_key( $module ) . ':' . (string) $key ), 0, 28 );
	}
}
