<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the soft-delete state for installable Airfiber Next modules.
 * MU/Core components are never trashable.
 */
class Module_Trash {
	const OPTION_TRASH = 'afcn_trashed_modules_v1';

	public static function all() {
		$value = get_option( self::OPTION_TRASH, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function is_trashed( $module_id ) {
		$module_id = sanitize_key( $module_id );
		$trash     = self::all();
		return ! empty( $trash[ $module_id ] );
	}

	public static function trash( $module_id ) {
		$module_id = sanitize_key( $module_id );
		$module    = Module_Registry::get( $module_id );
		if ( ! $module ) {
			return new \WP_Error( 'afcn_module_missing', __( 'Module not found.', 'airfiber-centralized' ) );
		}
		if ( ! empty( $module['system'] ) ) {
			return new \WP_Error( 'afcn_mu_module', __( 'Core MU components cannot be moved to Trash.', 'airfiber-centralized' ) );
		}
		if ( Module_Manager::is_enabled( $module_id, $module ) ) {
			return new \WP_Error( 'afcn_module_active', __( 'Deactivate this module before moving it to Trash.', 'airfiber-centralized' ) );
		}
		$trash               = self::all();
		$trash[ $module_id ] = time();
		update_option( self::OPTION_TRASH, $trash, false );
		Audit_Log::record( 'module_trashed', $module_id );
		return true;
	}

	public static function restore( $module_id ) {
		$module_id = sanitize_key( $module_id );
		$trash     = self::all();
		if ( isset( $trash[ $module_id ] ) ) {
			unset( $trash[ $module_id ] );
			update_option( self::OPTION_TRASH, $trash, false );
			Audit_Log::record( 'module_restored', $module_id );
		}
		return true;
	}
}
