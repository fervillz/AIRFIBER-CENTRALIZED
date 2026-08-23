<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user Airfiber navigation visibility.
 *
 * Capabilities decide what a user is allowed to do. This class only narrows
 * which normal feature modules are visible to that user. MU/Core pages remain
 * capability-driven and Super Admin always sees everything.
 */
class User_Access {
	const META_KEY = 'afcn_user_access_v1';

	/**
	 * Whether the user may see/use one module.
	 */
	public static function can_view_module( $user_id, $module ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! is_array( $module ) || empty( $module['id'] ) ) {
			return false;
		}

		if ( Capabilities::is_super_admin_user( $user_id ) ) {
			return true;
		}
		if ( isset( $module['capability'] ) && Capabilities::SUPER_ADMIN === $module['capability'] ) {
			return false;
		}

		// MU/Core pages are controlled by their normal capability. Their internal
		// MU status is not exposed as an editable visibility option to Admins.
		if ( ! empty( $module['system'] ) ) {
			return true;
		}

		$policy = self::policy( $user_id );
		if ( null === $policy ) {
			return true;
		}

		return in_array( sanitize_key( $module['id'] ), $policy['modules'], true );
	}

	/**
	 * Return null for the default "all normal modules" policy.
	 */
	public static function policy( $user_id ) {
		$raw = get_user_meta( absint( $user_id ), self::META_KEY, true );
		if ( '' === $raw || ! is_array( $raw ) ) {
			return null;
		}

		$modules = isset( $raw['modules'] ) && is_array( $raw['modules'] ) ? $raw['modules'] : array();
		return array(
			'modules' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $modules ) ) ) ),
			'areas'   => isset( $raw['areas'] ) && is_array( $raw['areas'] ) ? $raw['areas'] : array(),
		);
	}

	/**
	 * Effective checked module ids for the Users UI.
	 */
	public static function visible_module_ids( $user_id, $include_mu = false ) {
		$user_id = absint( $user_id );
		$policy  = self::policy( $user_id );
		$output  = array();

		foreach ( self::assignable_modules( $include_mu ) as $id => $module ) {
			if ( Capabilities::is_super_admin_user( $user_id ) || ! empty( $module['system'] ) ) {
				$output[] = $id;
				continue;
			}
			if ( null === $policy || in_array( $id, $policy['modules'], true ) ) {
				$output[] = $id;
			}
		}

		return $output;
	}

	/**
	 * Modules that may be exposed in the visibility editor.
	 *
	 * Developer/Super-Admin modules are authority-only and are intentionally not
	 * exposed as visibility checkboxes for any target user.
	 */
	public static function assignable_modules( $include_mu = false ) {
		$output = array();
		foreach ( Module_Registry::all() as $id => $module ) {
			if ( Module_Trash::is_trashed( $id ) || ! Module_Manager::is_enabled( $id, $module ) || ! Module_Manager::dependencies_met( $module ) ) {
				continue;
			}
			if ( ! $include_mu && ! empty( $module['system'] ) ) {
				continue;
			}
			if ( isset( $module['capability'] ) && Capabilities::SUPER_ADMIN === $module['capability'] ) {
				continue;
			}
			$output[ $id ] = $module;
		}
		return $output;
	}

	/**
	 * Persist a normal user's feature-module allow list.
	 *
	 * Selecting every currently available module clears the stored policy instead
	 * of freezing a list. That keeps the default "all normal modules" behavior
	 * future-proof when new modules are installed later.
	 */
	public static function set_visible_modules( $user_id, $module_ids ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new \WP_Error( 'afcn_user_missing', __( 'User not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		if ( Capabilities::is_super_admin_user( $user_id ) ) {
			return true;
		}

		$allowed = array_keys( self::assignable_modules( false ) );
		$wanted  = is_array( $module_ids ) ? $module_ids : array();
		$wanted  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $wanted ) ) ) );
		$wanted  = array_values( array_intersect( $wanted, $allowed ) );

		$sorted_allowed = $allowed;
		$sorted_wanted  = $wanted;
		sort( $sorted_allowed, SORT_STRING );
		sort( $sorted_wanted, SORT_STRING );
		if ( $sorted_allowed === $sorted_wanted ) {
			self::clear_policy( $user_id );
			return true;
		}

		update_user_meta(
			$user_id,
			self::META_KEY,
			array(
				'modules' => $wanted,
				'areas'   => array(), // Reserved for future nested/menu-area visibility.
			)
		);
		return true;
	}

	public static function clear_policy( $user_id ) {
		delete_user_meta( absint( $user_id ), self::META_KEY );
	}
}
