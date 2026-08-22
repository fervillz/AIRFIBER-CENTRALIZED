<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Module_Registry {
	const OPTION_CACHE = 'afcn_module_registry_v1';
	const CACHE_SCHEMA = 1;

	private static $modules = null;

	public static function all( $refresh = false ) {
		if ( null !== self::$modules && ! $refresh ) {
			return self::$modules;
		}

		if ( ! $refresh ) {
			$cached = get_option( self::OPTION_CACHE, array() );
			if (
				is_array( $cached )
				&& isset( $cached['schema'], $cached['core_version'], $cached['modules'] )
				&& self::CACHE_SCHEMA === (int) $cached['schema']
				&& (string) AFCN_VERSION === (string) $cached['core_version']
				&& is_array( $cached['modules'] )
			) {
				self::$modules = $cached['modules'];
				return self::$modules;
			}
		}

		$modules = self::discover_from_disk();
		update_option(
			self::OPTION_CACHE,
			array(
				'schema'       => self::CACHE_SCHEMA,
				'core_version' => AFCN_VERSION,
				'built_at'     => time(),
				'modules'      => $modules,
			),
			false
		);
		self::$modules = $modules;
		return $modules;
	}

	public static function get( $id ) {
		$id      = sanitize_key( $id );
		$modules = self::all();
		return isset( $modules[ $id ] ) ? $modules[ $id ] : null;
	}

	public static function invalidate() {
		self::$modules = null;
		delete_option( self::OPTION_CACHE );
	}

	private static function discover_from_disk() {
		$modules = array();
		$files   = glob( AFCN_PATH . 'modules/*/module.json' );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		foreach ( $files as $file ) {
			$raw = file_get_contents( $file );
			if ( false === $raw ) {
				continue;
			}
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				Debug_Logger::warning( 'Invalid module manifest JSON.', array( 'file' => basename( dirname( $file ) ) ) );
				continue;
			}
			$folder = sanitize_key( basename( dirname( $file ) ) );
			$module = self::normalize( $data, $folder );
			if ( is_wp_error( $module ) ) {
				Debug_Logger::warning( 'Module manifest rejected.', array( 'module' => $folder, 'reason' => $module->get_error_message() ) );
				continue;
			}
			$modules[ $module['id'] ] = $module;
		}

		uasort(
			$modules,
			function ( $left, $right ) {
				if ( $left['position'] === $right['position'] ) {
					return strcasecmp( $left['name'], $right['name'] );
				}
				return $left['position'] < $right['position'] ? -1 : 1;
			}
		);
		return $modules;
	}

	private static function normalize( $data, $folder ) {
		$id    = isset( $data['id'] ) ? sanitize_key( $data['id'] ) : '';
		$name  = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$class = isset( $data['class'] ) ? ltrim( sanitize_text_field( $data['class'] ), '\\' ) : '';
		if ( '' === $id || $id !== $folder ) {
			return new \WP_Error( 'afcn_manifest_id', 'Manifest id must match its folder name.' );
		}
		if ( '' === $name || '' === $class ) {
			return new \WP_Error( 'afcn_manifest_required', 'Manifest name and class are required.' );
		}
		$expected_class = __NAMESPACE__ . '\\Modules\\' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $folder ) ) ) . '\\';
		if ( 0 !== strpos( $class, $expected_class ) ) {
			return new \WP_Error( 'afcn_manifest_class', 'Module class must live inside its Airfiber Next module namespace.' );
		}

		$assets  = isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array();
		$file    = AFCN_PATH . 'modules/' . $folder . '/module.json';
		$real    = realpath( $file );
		$systems = array( 'dashboard', 'users', 'modules', 'settings' );
		return array(
			'id'              => $id,
			'name'            => $name,
			'description'     => isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '',
			'version'         => isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '0.0.0',
			'class'           => $class,
			'position'        => isset( $data['position'] ) ? (int) $data['position'] : 100,
			'icon'            => isset( $data['icon'] ) ? sanitize_key( $data['icon'] ) : 'box',
			'capability'      => isset( $data['capability'] ) ? sanitize_key( $data['capability'] ) : Capabilities::ACCESS,
			'system'          => ! empty( $data['system'] ) && in_array( $id, $systems, true ),
			'default_enabled' => ! isset( $data['default_enabled'] ) || (bool) $data['default_enabled'],
			'assets'          => array(
				'css' => isset( $assets['css'] ) && is_array( $assets['css'] ) ? array_values( array_map( 'sanitize_text_field', $assets['css'] ) ) : array(),
				'js'  => isset( $assets['js'] ) && is_array( $assets['js'] ) ? array_values( array_map( 'sanitize_text_field', $assets['js'] ) ) : array(),
			),
			'requires'        => isset( $data['requires'] ) && is_array( $data['requires'] ) ? $data['requires'] : array(),
			'events'          => isset( $data['events'] ) && is_array( $data['events'] ) ? array_values( array_map( 'sanitize_key', $data['events'] ) ) : array(),
			'path'            => dirname( $real ? $real : $file ),
		);
	}
}
