<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Convention helpers for Airfiber Next module folders, namespaces and classes.
 *
 * Keeping this mapping in one place lets module.json stay small while Core can
 * still discover module metadata without executing module PHP.
 */
class Module_Naming {

	public static function namespace_segment( $module_id ) {
		$words = self::words( $module_id );
		return implode( '', array_map( array( __CLASS__, 'title_word' ), $words ) );
	}

	public static function class_stem( $module_id ) {
		$words = self::words( $module_id );
		return implode( '_', array_map( array( __CLASS__, 'title_word' ), $words ) );
	}

	public static function default_class( $module_id ) {
		return __NAMESPACE__ . '\\Modules\\' . self::namespace_segment( $module_id ) . '\\' . self::class_stem( $module_id ) . '_Module';
	}

	public static function namespace_prefix( $module_id ) {
		return __NAMESPACE__ . '\\Modules\\' . self::namespace_segment( $module_id ) . '\\';
	}

	public static function folder_from_namespace( $segment ) {
		$segment = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', (string) $segment );
		return sanitize_key( strtolower( (string) $segment ) );
	}

	private static function words( $module_id ) {
		$module_id = sanitize_key( $module_id );
		$parts     = preg_split( '/[-_]+/', $module_id );
		$parts     = is_array( $parts ) ? array_filter( $parts, 'strlen' ) : array();
		return $parts ? array_values( $parts ) : array( 'module' );
	}

	private static function title_word( $word ) {
		return ucfirst( strtolower( (string) $word ) );
	}
}
