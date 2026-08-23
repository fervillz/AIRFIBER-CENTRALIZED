<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class User_Manager {

	public static function can_access() {
		return Capabilities::is_super_admin_user() || current_user_can( 'manage_options' ) || current_user_can( Capabilities::ACCESS );
	}

	public static function can_manage_users() {
		return Capabilities::is_super_admin_user() || current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_USERS );
	}

	public static function is_super_admin( $user_id = 0 ) {
		return Capabilities::is_super_admin_user( $user_id );
	}

	public static function current_user_summary() {
		$user = wp_get_current_user();
		return array(
			'id'             => (int) $user->ID,
			'display_name'   => $user->display_name,
			'email'          => $user->user_email,
			'roles'          => array_values( (array) $user->roles ),
			'is_super_admin' => self::is_super_admin( $user->ID ),
			'can_users'      => self::can_manage_users(),
			'can_modules'    => self::is_super_admin( $user->ID ) || current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_MODULES ),
			'can_settings'   => self::is_super_admin( $user->ID ) || current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_SETTINGS ),
		);
	}

	public static function list_users() {
		$users = get_users(
			array(
				'role__in' => array( 'administrator', 'airfiber_admin', 'airfiber_operator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$output = array();
		foreach ( $users as $user ) {
			$output[] = self::format_user( $user );
		}
		return $output;
	}

	public static function create_user( $payload ) {
		if ( ! self::can_manage_users() ) {
			return new \WP_Error( 'afcn_forbidden', __( 'You cannot create Airfiber users.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$username = isset( $payload['username'] ) ? sanitize_user( $payload['username'], true ) : '';
		$email    = isset( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
		$name     = isset( $payload['display_name'] ) ? sanitize_text_field( $payload['display_name'] ) : '';
		$role     = isset( $payload['role'] ) ? sanitize_key( $payload['role'] ) : 'airfiber_operator';
		$password = isset( $payload['password'] ) ? (string) $payload['password'] : '';
		$roles    = Capabilities::assignable_roles();

		if ( '' === $username || ! validate_username( $username ) ) {
			return new \WP_Error( 'afcn_invalid_username', __( 'Enter a valid username.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'afcn_invalid_email', __( 'Enter a valid email address.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( ! isset( $roles[ $role ] ) ) {
			return new \WP_Error( 'afcn_invalid_role', __( 'Choose a valid Airfiber role.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( username_exists( $username ) || email_exists( $email ) ) {
			return new \WP_Error( 'afcn_user_exists', __( 'That username or email address already exists.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}

		$generated = false;
		if ( '' === trim( $password ) ) {
			$password  = wp_generate_password( 20, true, true );
			$generated = true;
		}
		if ( strlen( $password ) < 10 ) {
			return new \WP_Error( 'afcn_weak_password', __( 'Use a password with at least 10 characters.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'display_name' => $name ? $name : $username,
				'user_pass'    => $password,
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$visible_modules = self::parse_visible_modules( $payload );
		if ( null !== $visible_modules ) {
			User_Access::set_visible_modules( $user_id, $visible_modules );
		}

		$result = array( 'user' => self::format_user( get_user_by( 'id', $user_id ) ) );
		if ( $generated ) {
			$result['generated_password'] = $password;
		}

		Audit_Log::record( 'user_created', (string) $user_id, array( 'role' => $role ) );
		return $result;
	}

	public static function update_user( $user_id, $payload ) {
		if ( ! self::can_manage_users() ) {
			return new \WP_Error( 'afcn_forbidden', __( 'You cannot edit Airfiber users.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user ) {
			return new \WP_Error( 'afcn_user_missing', __( 'User not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		$is_super_admin = self::is_super_admin( $user->ID );
		$is_wp_admin    = in_array( 'administrator', (array) $user->roles, true );

		if ( $is_super_admin && ! self::is_super_admin() ) {
			return new \WP_Error( 'afcn_super_admin_protected', __( 'Only the Airfiber Super Admin can edit the Super Admin account.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$role = '';
		if ( ! $is_wp_admin && ! $is_super_admin && isset( $payload['role'] ) ) {
			$role  = sanitize_key( $payload['role'] );
			$roles = Capabilities::assignable_roles();
			if ( ! isset( $roles[ $role ] ) ) {
				return new \WP_Error( 'afcn_invalid_role', __( 'Choose a valid Airfiber role.', 'airfiber-centralized' ), array( 'status' => 400 ) );
			}
		}

		// WordPress Administrator identity/profile fields stay owned by WordPress.
		if ( ! $is_wp_admin ) {
			$changes = array( 'ID' => (int) $user->ID );
			if ( isset( $payload['display_name'] ) ) {
				$changes['display_name'] = sanitize_text_field( $payload['display_name'] );
			}
			if ( isset( $payload['email'] ) ) {
				$email = sanitize_email( $payload['email'] );
				if ( ! is_email( $email ) ) {
					return new \WP_Error( 'afcn_invalid_email', __( 'Enter a valid email address.', 'airfiber-centralized' ), array( 'status' => 400 ) );
				}
				$changes['user_email'] = $email;
			}
			if ( isset( $payload['password'] ) && '' !== trim( (string) $payload['password'] ) ) {
				if ( strlen( (string) $payload['password'] ) < 10 ) {
					return new \WP_Error( 'afcn_weak_password', __( 'Use a password with at least 10 characters.', 'airfiber-centralized' ), array( 'status' => 400 ) );
				}
				$changes['user_pass'] = (string) $payload['password'];
			}

			$updated = wp_update_user( $changes );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( $role ) {
			$wp_user = new \WP_User( $user->ID );
			$wp_user->set_role( $role );
		}

		$visible_modules = self::parse_visible_modules( $payload );
		if ( null !== $visible_modules && ! $is_super_admin ) {
			User_Access::set_visible_modules( $user->ID, $visible_modules );
		}

		Audit_Log::record(
			'user_updated',
			(string) $user->ID,
			array(
				'role'       => $role,
				'visibility' => null !== $visible_modules ? 'updated' : 'unchanged',
			)
		);

		return array( 'user' => self::format_user( get_user_by( 'id', $user->ID ) ) );
	}

	public static function delete_user( $user_id ) {
		if ( ! self::can_manage_users() ) {
			return new \WP_Error( 'afcn_forbidden', __( 'You cannot delete Airfiber users.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$user_id = absint( $user_id );
		if ( $user_id === get_current_user_id() ) {
			return new \WP_Error( 'afcn_self_delete', __( 'You cannot delete your own account here.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'afcn_user_missing', __( 'User not found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		if ( self::is_super_admin( $user_id ) ) {
			return new \WP_Error( 'afcn_super_admin_protected', __( 'The Airfiber Super Admin account cannot be deleted here.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return new \WP_Error( 'afcn_wp_admin_protected', __( 'WordPress administrators cannot be deleted from Airfiber BETA.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( ! wp_delete_user( $user_id ) ) {
			return new \WP_Error( 'afcn_delete_failed', __( 'The user could not be deleted.', 'airfiber-centralized' ), array( 'status' => 500 ) );
		}

		Audit_Log::record( 'user_deleted', (string) $user_id );
		return array( 'deleted' => $user_id );
	}

	private static function parse_visible_modules( $payload ) {
		if ( ! is_array( $payload ) || ! array_key_exists( 'visible_modules', $payload ) ) {
			return null;
		}

		$value = $payload['visible_modules'];
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', $value ) ) ) );
	}

	private static function format_user( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return array();
		}

		$is_super_admin = self::is_super_admin( $user->ID );
		$is_wp_admin    = in_array( 'administrator', (array) $user->roles, true );
		$role_key       = 'airfiber_operator';
		$role_label     = __( 'Operator', 'airfiber-centralized' );

		if ( $is_super_admin ) {
			$role_key   = 'super_admin';
			$role_label = __( 'Super Admin', 'airfiber-centralized' );
		} elseif ( $is_wp_admin || in_array( 'airfiber_admin', (array) $user->roles, true ) ) {
			$role_key   = 'airfiber_admin';
			$role_label = __( 'Administrator', 'airfiber-centralized' );
		}

		return array(
			'id'              => (int) $user->ID,
			'username'        => $user->user_login,
			'email'           => $user->user_email,
			'display_name'    => $user->display_name,
			'roles'           => array_values( (array) $user->roles ),
			'role_key'        => $role_key,
			'role_label'      => $role_label,
			'is_wp_admin'     => $is_wp_admin,
			'is_super_admin'  => $is_super_admin,
			'visible_modules' => User_Access::visible_module_ids( $user->ID, $is_super_admin ),
		);
	}
}
