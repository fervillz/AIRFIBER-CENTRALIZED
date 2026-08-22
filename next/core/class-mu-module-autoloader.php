<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Loads must-use Airfiber Next modules from next/modules/mu/.
 *
 * This is registered before the normal Next autoloader so Core components can
 * live separately from installable modules while keeping the same namespace.
 */
class MU_Module_Autoloader {

	private static $registered = false;

	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;
		spl_autoload_register( array( __CLASS__, 'autoload' ), true, true );
	}

	public static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\Modules\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		if ( count( $parts ) < 2 ) {
			return;
		}

		$module = sanitize_key( strtolower( array_shift( $parts ) ) );
		$short  = array_pop( $parts );
		$file   = 'class-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';
		$path   = AFCN_PATH . 'modules/mu/' . $module . '/includes/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
