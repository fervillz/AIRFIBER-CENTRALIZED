<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Capabilities {
	const ACCESS          = 'afcn_access';
	const MANAGE_USERS    = 'afcn_manage_users';
	const MANAGE_MODULES  = 'afcn_manage_modules';
	const MANAGE_SETTINGS = 'afcn_manage_settings';

	public static function all() {
		return array(
			self::ACCESS,
			self::MANAGE_USERS,
			self::MANAGE_MODULES,
			self::MANAGE_SETTINGS,
		);
	}

	public static function ensure_roles() {
		$admin_caps = array(
			'read'                 => true,
			self::ACCESS           => true,
			self::MANAGE_USERS     => true,
			self::MANAGE_MODULES   => true,
			self::MANAGE_SETTINGS  => true,
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

		$wp_admin = get_role( 'administrator' );
		if ( $wp_admin ) {
			foreach ( self::all() as $capability ) {
				$wp_admin->add_cap( $capability, true );
			}
		}
	}

	public static function assignable_roles() {
		return array(
			'airfiber_admin'    => __( 'Airfiber Administrator', 'airfiber-centralized' ),
			'airfiber_operator' => __( 'Airfiber Operator', 'airfiber-centralized' ),
		);
	}
}
