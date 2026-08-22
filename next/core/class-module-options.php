<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Small namespaced option store for one Airfiber module.
 */
class Module_Options {
	public static function all( $module ) {
		$value = get_option( self::option_name( $module ), array() );
		return is_array( $value ) ? $value : array();
	}

	public static function get( $module, $key, $default = null ) {
		$values = self::all( $module );
		$key    = sanitize_key( $key );
		return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
	}

	public static function set( $module, $key, $value ) {
		$values         = self::all( $module );
		$values[ sanitize_key( $key ) ] = $value;
		return update_option( self::option_name( $module ), $values, false );
	}

	public static function delete( $module, $key ) {
		$values = self::all( $module );
		$key    = sanitize_key( $key );
		if ( ! array_key_exists( $key, $values ) ) {
			return true;
		}
		unset( $values[ $key ] );
		return update_option( self::option_name( $module ), $values, false );
	}

	public static function clear( $module ) {
		return delete_option( self::option_name( $module ) );
	}

	private static function option_name( $module ) {
		return 'afcn_mod_' . substr( sanitize_key( $module ), 0, 32 ) . '_options';
	}
}
