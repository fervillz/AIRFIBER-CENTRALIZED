<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral update metadata and auto-update preferences.
 *
 * Update sources hook into afcn_module_update_catalog. Core deliberately does
 * not hard-code a package server so module discovery stays fast and local.
 */
class Module_Updates {
	const OPTION_AUTO = 'afcn_module_auto_updates_v1';

	private static $catalog = null;

	public static function catalog( $refresh = false ) {
		if ( null !== self::$catalog && ! $refresh ) {
			return self::$catalog;
		}

		$raw = apply_filters( 'afcn_module_update_catalog', array() );
		$out = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $module_id => $item ) {
				$module_id = sanitize_key( $module_id );
				if ( ! $module_id || ! is_array( $item ) ) {
					continue;
				}

				$version = isset( $item['version'] ) ? sanitize_text_field( $item['version'] ) : '';
				if ( '' === $version ) {
					continue;
				}

				$out[ $module_id ] = array(
					'version' => $version,
					'url'     => isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
					'notes'   => isset( $item['notes'] ) ? sanitize_text_field( $item['notes'] ) : '',
				);
			}
		}

		self::$catalog = $out;
		return self::$catalog;
	}

	public static function info( $module_id ) {
		$module_id = sanitize_key( $module_id );
		$catalog   = self::catalog();
		return isset( $catalog[ $module_id ] ) ? $catalog[ $module_id ] : null;
	}

	public static function supports_updates( $module ) {
		if ( ! empty( $module['system'] ) ) {
			return false;
		}

		if ( ! empty( $module['updates'] ) ) {
			return true;
		}

		return null !== self::info( $module['id'] );
	}

	public static function has_update( $module ) {
		$info = self::info( $module['id'] );
		if ( ! $info || empty( $info['version'] ) ) {
			return false;
		}

		return version_compare( (string) $info['version'], (string) $module['version'], '>' );
	}

	public static function auto_update_enabled( $module_id ) {
		$module_id = sanitize_key( $module_id );
		$map       = get_option( self::OPTION_AUTO, array() );
		return is_array( $map ) && ! empty( $map[ $module_id ] );
	}

	public static function set_auto_update( $module_id, $enabled ) {
		$module_id = sanitize_key( $module_id );
		$module    = Module_Registry::get( $module_id );

		if ( ! $module || ! self::supports_updates( $module ) ) {
			return new \WP_Error( 'afcn_updates_unsupported', __( 'This module does not expose an update provider.', 'airfiber-centralized' ) );
		}

		$map               = get_option( self::OPTION_AUTO, array() );
		$map               = is_array( $map ) ? $map : array();
		$map[ $module_id ] = (bool) $enabled;
		update_option( self::OPTION_AUTO, $map, false );

		Audit_Log::record( $enabled ? 'module_auto_update_enabled' : 'module_auto_update_disabled', $module_id );
		return true;
	}
}
