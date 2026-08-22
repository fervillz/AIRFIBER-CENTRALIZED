<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight registry of connection types advertised by module manifests.
 *
 * Reading connector metadata must never bootstrap the owning module. This lets
 * the Connections Hub group cards and show available providers at near-zero
 * initial runtime cost.
 */
class Connector_Registry {
	private static $types = null;

	public static function all( $refresh = false ) {
		if ( null !== self::$types && ! $refresh ) {
			return self::$types;
		}

		$types = array();
		foreach ( Module_Registry::all( $refresh ) as $module_id => $module ) {
			foreach ( (array) ( isset( $module['connectors'] ) ? $module['connectors'] : array() ) as $connector ) {
				if ( ! is_array( $connector ) || empty( $connector['id'] ) ) {
					continue;
				}
				$id = sanitize_key( $connector['id'] );
				if ( ! $id || isset( $types[ $id ] ) ) {
					if ( $id ) {
						Debug_Logger::warning( 'Duplicate connector type ignored.', array( 'connector' => $id, 'module' => $module_id ) );
					}
					continue;
				}
				$connector['module'] = $module_id;
				$types[ $id ]        = $connector;
			}
		}

		/**
		 * Runtime extensions may add connector metadata. The filter runs only when
		 * connector information is requested; it is not part of the initial shell.
		 */
		$filtered = apply_filters( 'afcn_connector_types', $types );
		if ( is_array( $filtered ) ) {
			$types = self::normalize_runtime( $filtered );
		}

		self::$types = $types;
		return $types;
	}

	public static function get( $id ) {
		$id    = sanitize_key( $id );
		$types = self::all();
		return $id && isset( $types[ $id ] ) ? $types[ $id ] : null;
	}

	public static function for_module( $module_id ) {
		$module_id = sanitize_key( $module_id );
		$output    = array();
		foreach ( self::all() as $id => $type ) {
			if ( isset( $type['module'] ) && $module_id === $type['module'] ) {
				$output[ $id ] = $type;
			}
		}
		return $output;
	}

	public static function groups() {
		$groups = array();
		foreach ( self::all() as $type ) {
			$group = isset( $type['group'] ) ? sanitize_key( $type['group'] ) : 'other';
			if ( ! $group ) {
				$group = 'other';
			}
			$groups[ $group ] = true;
		}
		return array_keys( $groups );
	}

	public static function invalidate() {
		self::$types = null;
	}

	public static function field_schema( $connector_id ) {
		$type = self::get( $connector_id );
		return $type && isset( $type['fields'] ) && is_array( $type['fields'] ) ? $type['fields'] : array();
	}

	public static function sanitize_field_value( $field, $value ) {
		$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
		if ( 'number' === $type ) {
			return is_numeric( $value ) ? (string) $value : '';
		}
		if ( 'email' === $type ) {
			return sanitize_email( $value );
		}
		if ( 'url' === $type ) {
			return esc_url_raw( $value );
		}
		if ( 'checkbox' === $type ) {
			return empty( $value ) ? '0' : '1';
		}
		if ( 'select' === $type ) {
			$value   = sanitize_text_field( (string) $value );
			$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
			return array_key_exists( $value, $options ) ? $value : '';
		}
		return sanitize_text_field( (string) $value );
	}

	private static function normalize_runtime( $types ) {
		$output = array();
		foreach ( $types as $key => $type ) {
			if ( ! is_array( $type ) ) {
				continue;
			}
			$id = isset( $type['id'] ) ? sanitize_key( $type['id'] ) : sanitize_key( $key );
			if ( ! $id || empty( $type['name'] ) ) {
				continue;
			}
			$type['id']          = $id;
			$type['name']        = sanitize_text_field( $type['name'] );
			$type['description'] = isset( $type['description'] ) ? sanitize_text_field( $type['description'] ) : '';
			$type['group']       = isset( $type['group'] ) ? sanitize_key( $type['group'] ) : 'other';
			$type['icon']        = isset( $type['icon'] ) ? sanitize_key( $type['icon'] ) : 'plug';
			$type['module']      = isset( $type['module'] ) ? sanitize_key( $type['module'] ) : '';
			$type['test_action'] = isset( $type['test_action'] ) ? sanitize_key( $type['test_action'] ) : '';
			$type['fields']      = isset( $type['fields'] ) && is_array( $type['fields'] ) ? $type['fields'] : array();
			$output[ $id ]       = $type;
		}
		return $output;
	}
}
