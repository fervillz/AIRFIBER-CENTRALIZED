<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Stores non-secret connection configuration in one bounded Core registry.
 * Provider-specific credentials live in Secret_Store instead.
 */
class Connection_Store {
	const OPTION_CONNECTIONS = 'afcn_connections_v1';
	const MAX_CONNECTIONS    = 250;

	public static function all() {
		$value = get_option( self::OPTION_CONNECTIONS, array() );
		$value = is_array( $value ) ? $value : array();
		uasort(
			$value,
			function ( $left, $right ) {
				$lp = isset( $left['position'] ) ? (int) $left['position'] : 100;
				$rp = isset( $right['position'] ) ? (int) $right['position'] : 100;
				if ( $lp === $rp ) {
					return strcasecmp( isset( $left['name'] ) ? $left['name'] : '', isset( $right['name'] ) ? $right['name'] : '' );
				}
				return $lp < $rp ? -1 : 1;
			}
		);
		return $value;
	}

	public static function get( $connection_id ) {
		$connection_id = self::clean_id( $connection_id );
		$all           = self::all();
		return $connection_id && isset( $all[ $connection_id ] ) && is_array( $all[ $connection_id ] ) ? $all[ $connection_id ] : null;
	}

	public static function for_type( $type ) {
		$type   = sanitize_key( $type );
		$output = array();
		foreach ( self::all() as $id => $connection ) {
			if ( isset( $connection['type'] ) && $type === $connection['type'] ) {
				$output[ $id ] = $connection;
			}
		}
		return $output;
	}

	public static function for_module( $module_id ) {
		$module_id = sanitize_key( $module_id );
		$output    = array();
		foreach ( self::all() as $id => $connection ) {
			if ( isset( $connection['module'] ) && $module_id === $connection['module'] ) {
				$output[ $id ] = $connection;
			}
		}
		return $output;
	}

	public static function create( $data, $secrets = array() ) {
		$all = self::all();
		if ( count( $all ) >= self::MAX_CONNECTIONS ) {
			return new \WP_Error( 'afcn_connections_limit', __( 'The connection registry has reached its safety limit.', 'airfiber-centralized' ) );
		}

		$record = self::normalize( $data );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$id = self::new_id();
		while ( isset( $all[ $id ] ) ) {
			$id = self::new_id();
		}
		$record['id']         = $id;
		$record['created_at'] = time();
		$record['updated_at'] = $record['created_at'];
		$all[ $id ]           = $record;
		update_option( self::OPTION_CONNECTIONS, $all, false );

		if ( is_array( $secrets ) && $secrets ) {
			$result = Secret_Store::set_many( $id, $secrets );
			if ( is_wp_error( $result ) ) {
				unset( $all[ $id ] );
				update_option( self::OPTION_CONNECTIONS, $all, false );
				return $result;
			}
		}

		Audit_Log::record( 'connection_created', $id, array( 'type' => $record['type'], 'module' => $record['module'] ) );
		do_action( 'afcn_connection_created', $id, $record );
		return $record;
	}

	public static function update( $connection_id, $data, $secrets = array() ) {
		$connection_id = self::clean_id( $connection_id );
		$all           = self::all();
		if ( ! $connection_id || empty( $all[ $connection_id ] ) ) {
			return new \WP_Error( 'afcn_connection_missing', __( 'Connection not found.', 'airfiber-centralized' ) );
		}

		$existing = $all[ $connection_id ];
		$data     = is_array( $data ) ? array_merge( $existing, $data ) : $existing;
		$record   = self::normalize( $data );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$record['id']         = $connection_id;
		$record['created_at'] = isset( $existing['created_at'] ) ? absint( $existing['created_at'] ) : time();
		$record['updated_at'] = time();

		if ( is_array( $secrets ) && $secrets ) {
			$result = Secret_Store::set_many( $connection_id, $secrets );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$all[ $connection_id ] = $record;
		update_option( self::OPTION_CONNECTIONS, $all, false );
		Audit_Log::record( 'connection_updated', $connection_id, array( 'type' => $record['type'], 'module' => $record['module'] ) );
		do_action( 'afcn_connection_updated', $connection_id, $record, $existing );
		return $record;
	}

	public static function delete( $connection_id ) {
		$connection_id = self::clean_id( $connection_id );
		$all           = self::all();
		if ( ! $connection_id || empty( $all[ $connection_id ] ) ) {
			return new \WP_Error( 'afcn_connection_missing', __( 'Connection not found.', 'airfiber-centralized' ) );
		}
		$existing = $all[ $connection_id ];
		unset( $all[ $connection_id ] );
		update_option( self::OPTION_CONNECTIONS, $all, false );
		Secret_Store::delete( $connection_id );
		Connection_Health::clear( $connection_id );
		Audit_Log::record( 'connection_deleted', $connection_id, array( 'type' => isset( $existing['type'] ) ? $existing['type'] : '' ) );
		do_action( 'afcn_connection_deleted', $connection_id, $existing );
		return true;
	}

	private static function normalize( $data ) {
		$data = is_array( $data ) ? $data : array();
		$type = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : '';
		$meta = Connector_Registry::get( $type );
		if ( ! $type || ! $meta ) {
			return new \WP_Error( 'afcn_connector_unknown', __( 'The selected connector type is not available.', 'airfiber-centralized' ) );
		}

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name ) {
			$name = $meta['name'];
		}

		return array(
			'type'     => $type,
			'module'   => isset( $meta['module'] ) ? sanitize_key( $meta['module'] ) : '',
			'group'    => isset( $meta['group'] ) && $meta['group'] ? sanitize_key( $meta['group'] ) : 'other',
			'name'     => $name,
			'endpoint' => isset( $data['endpoint'] ) ? sanitize_text_field( $data['endpoint'] ) : '',
			'config'   => isset( $data['config'] ) && is_array( $data['config'] ) ? self::sanitize_config( $data['config'] ) : array(),
			'position' => isset( $data['position'] ) ? max( 0, min( 10000, (int) $data['position'] ) ) : 100,
		);
	}

	private static function sanitize_config( $config, $depth = 0 ) {
		if ( $depth > 3 || ! is_array( $config ) ) {
			return array();
		}
		$output = array();
		foreach ( array_slice( $config, 0, 60, true ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$output[ $key ] = self::sanitize_config( $value, $depth + 1 );
			} elseif ( is_bool( $value ) ) {
				$output[ $key ] = $value;
			} elseif ( is_numeric( $value ) ) {
				$output[ $key ] = (string) $value;
			} elseif ( is_scalar( $value ) || null === $value ) {
				$output[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $output;
	}

	private static function new_id() {
		return 'conn_' . strtolower( wp_generate_password( 12, false, false ) );
	}

	private static function clean_id( $value ) {
		$value = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $value ) );
		return substr( $value, 0, 80 );
	}
}
