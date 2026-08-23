<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Capabilities {
	const ACCESS                      = 'afcn_access';
	const MANAGE_USERS                = 'afcn_manage_users';
	const MANAGE_MODULES              = 'afcn_manage_modules';
	const MANAGE_SETTINGS             = 'afcn_manage_settings';
	const MANAGE_CONNECTIONS          = 'afcn_manage_connections';
	const SUPER_ADMIN                 = 'afcn_super_admin';
	const OPTION_VERSION              = 'afcn_roles_version';
	const OPTION_SUPER_ADMIN_USER_ID  = 'afcn_super_admin_user_id';

	/**
	 * Normal Airfiber capabilities. Super Admin is intentionally excluded so a
	 * WordPress Administrator does not become an Airfiber Super Admin by default.
	 */
	public static function all() {
		return array(
			self::ACCESS,
			self::MANAGE_USERS,
			self::MANAGE_MODULES,
			self::MANAGE_SETTINGS,
			self::MANAGE_CONNECTIONS,
		);
	}

	/**
	 * Return the explicitly configured owner id, if one exists.
	 *
	 * wp-config.php remains the strongest deployment-level override. The stored
	 * option is used by the one-time in-app owner setup flow.
	 */
	public static function configured_super_admin_user_id() {
		if ( defined( 'AFCN_SUPER_ADMIN_USER_ID' ) ) {
			return absint( AFCN_SUPER_ADMIN_USER_ID );
		}
		return absint( get_option( self::OPTION_SUPER_ADMIN_USER_ID, 0 ) );
	}

	/**
	 * Whether this installation already has an Airfiber owner.
	 *
	 * This is intentionally a setup-time check rather than something called on
	 * every module request. It understands the explicit option/constant, direct
	 * user capability and the supported filter integration.
	 */
	public static function has_super_admin() {
		$configured = self::configured_super_admin_user_id();
		if ( $configured && get_user_by( 'id', $configured ) ) {
			return true;
		}

		$ids = apply_filters( 'afcn_super_admin_user_ids', array() );
		$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
		foreach ( $ids as $user_id ) {
			if ( get_user_by( 'id', $user_id ) ) {
				return true;
			}
		}

		global $wpdb;
		$direct = get_users(
			array(
				'fields'       => 'ids',
				'number'       => 1,
				'meta_key'     => $wpdb->prefix . 'capabilities',
				'meta_value'   => self::SUPER_ADMIN,
				'meta_compare' => 'LIKE',
			)
		);
		return ! empty( $direct );
	}

	/**
	 * Super Admin is explicit, never inferred from the WordPress administrator role.
	 *
	 * Supported sources, in order of intent:
	 * - AFCN_SUPER_ADMIN_USER_ID in wp-config.php
	 * - one-time owner setup stored in OPTION_SUPER_ADMIN_USER_ID
	 * - direct user-level afcn_super_admin capability
	 * - afcn_super_admin_user_ids filter
	 *
	 * Core ships with no hidden account/backdoor and no default password.
	 */
	public static function is_super_admin_user( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$configured = self::configured_super_admin_user_id();
		if ( $configured && $configured === $user_id ) {
			return true;
		}

		$user = get_user_by( 'id', $user_id );
		if ( $user && user_can( $user, self::SUPER_ADMIN ) ) {
			return true;
		}

		$ids = apply_filters( 'afcn_super_admin_user_ids', array() );
		$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();
		return in_array( $user_id, $ids, true );
	}

	public static function ensure_roles() {
		if ( (string) get_option( self::OPTION_VERSION, '' ) === (string) AFCN_VERSION ) {
			return;
		}

		$admin_caps = array(
			'read'                   => true,
			self::ACCESS             => true,
			self::MANAGE_USERS       => true,
			self::MANAGE_MODULES     => true,
			self::MANAGE_SETTINGS    => true,
			self::MANAGE_CONNECTIONS => true,
		);
		$operator_caps = array(
			'read'       => true,
			self::ACCESS => true,
		);

		if ( ! get_role( 'airfiber_admin' ) ) {
			add_role( 'airfiber_admin', __( 'Airfiber Administrator', 'airfiber-centralized' ), $admin_caps );
		}
		if ( ! get_role( 'airfiber_operator' ) ) {
			add_role( 'airfiber_operator', __( 'Airfiber Operator', 'airfiber-centralized' ), $operator_caps );
		}

		$airfiber_admin = get_role( 'airfiber_admin' );
		if ( $airfiber_admin ) {
			foreach ( $admin_caps as $capability => $grant ) {
				$airfiber_admin->add_cap( $capability, $grant );
			}
		}

		$operator = get_role( 'airfiber_operator' );
		if ( $operator ) {
			foreach ( $operator_caps as $capability => $grant ) {
				$operator->add_cap( $capability, $grant );
			}
		}

		// WordPress administrators receive normal Airfiber administration only.
		// Removing the role-level capability does not remove an explicitly granted
		// user-level owner capability.
		$wp_admin = get_role( 'administrator' );
		if ( $wp_admin ) {
			foreach ( self::all() as $capability ) {
				$wp_admin->add_cap( $capability, true );
			}
			$wp_admin->remove_cap( self::SUPER_ADMIN );
		}

		update_option( self::OPTION_VERSION, AFCN_VERSION, false );
	}

	public static function assignable_roles() {
		return array(
			'airfiber_admin'    => __( 'Airfiber Administrator', 'airfiber-centralized' ),
			'airfiber_operator' => __( 'Airfiber Operator', 'airfiber-centralized' ),
		);
	}
}
