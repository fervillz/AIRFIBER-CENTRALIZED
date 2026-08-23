<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * One-time Airfiber owner bootstrap.
 *
 * Public releases never ship a known Super Admin username/password. Instead, an
 * existing WordPress Administrator may explicitly promote the current account or
 * create a separate owner while no Airfiber Super Admin exists.
 */
class Owner_Setup {
	const SUGGESTED_USERNAME = 'bordocs';

	public static function is_required() {
		return ! Capabilities::has_super_admin();
	}

	public static function can_setup() {
		return is_user_logged_in() && current_user_can( 'manage_options' ) && self::is_required();
	}

	public static function promote_current_user() {
		if ( ! self::can_setup() ) {
			return new \WP_Error( 'afcn_owner_setup_unavailable', __( 'Airfiber owner setup is no longer available.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}

		$user_id = get_current_user_id();
		$result  = self::claim_user( $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Audit_Log::record( 'super_admin_configured', (string) $user_id, array( 'method' => 'promote_current' ) );
		return array(
			'user_id'     => $user_id,
			'message'     => __( 'This account is now the Airfiber Super Admin.', 'airfiber-centralized' ),
			'refresh_nav' => true,
		);
	}

	public static function create_owner( $payload ) {
		if ( ! self::can_setup() ) {
			return new \WP_Error( 'afcn_owner_setup_unavailable', __( 'Airfiber owner setup is no longer available.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}

		$username = isset( $payload['username'] ) ? sanitize_user( $payload['username'], true ) : self::SUGGESTED_USERNAME;
		$email    = isset( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
		$name     = isset( $payload['display_name'] ) ? sanitize_text_field( $payload['display_name'] ) : '';
		$password = isset( $payload['password'] ) ? (string) $payload['password'] : '';

		if ( '' === $username || ! validate_username( $username ) ) {
			return new \WP_Error( 'afcn_owner_invalid_username', __( 'Enter a valid owner username.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( username_exists( $username ) ) {
			return new \WP_Error( 'afcn_owner_username_exists', __( 'That username already exists. Promote the current account or choose another owner username.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'afcn_owner_invalid_email', __( 'Enter a valid owner email address.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new \WP_Error( 'afcn_owner_email_exists', __( 'That email address already belongs to a WordPress user.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}

		$generated = false;
		if ( '' === trim( $password ) ) {
			$password  = wp_generate_password( 24, true, true );
			$generated = true;
		}
		if ( strlen( $password ) < 12 ) {
			return new \WP_Error( 'afcn_owner_weak_password', __( 'Use an owner password with at least 12 characters.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'display_name' => $name ? $name : $username,
				'user_pass'    => $password,
				'role'         => 'administrator',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$result = self::claim_user( $user_id );
		if ( is_wp_error( $result ) ) {
			self::delete_failed_owner( $user_id );
			return $result;
		}

		Audit_Log::record( 'super_admin_configured', (string) $user_id, array( 'method' => 'create_owner' ) );

		$response = array(
			'user_id'     => (int) $user_id,
			'message'     => __( 'Airfiber owner account created and set as Super Admin.', 'airfiber-centralized' ),
			'refresh_nav' => true,
		);
		if ( $generated ) {
			$response['generated_password'] = $password;
		}
		return $response;
	}

	private static function claim_user( $user_id ) {
		$user_id = absint( $user_id );
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user ) {
			return new \WP_Error( 'afcn_owner_user_missing', __( 'The owner account could not be found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		if ( Capabilities::has_super_admin() ) {
			return new \WP_Error( 'afcn_owner_already_configured', __( 'An Airfiber Super Admin is already configured.', 'airfiber-centralized' ), array( 'status' => 409 ) );
		}

		// add_option gives us a simple atomic claim if two Administrators happen to
		// submit the first-run setup at nearly the same time.
		if ( ! add_option( Capabilities::OPTION_SUPER_ADMIN_USER_ID, $user_id, '', false ) ) {
			$existing = absint( get_option( Capabilities::OPTION_SUPER_ADMIN_USER_ID, 0 ) );
			if ( $existing !== $user_id ) {
				return new \WP_Error( 'afcn_owner_setup_race', __( 'Another Administrator completed owner setup first.', 'airfiber-centralized' ), array( 'status' => 409 ) );
			}
		}

		$wp_user = new \WP_User( $user_id );
		$wp_user->add_cap( Capabilities::SUPER_ADMIN, true );
		if ( ! Capabilities::is_super_admin_user( $user_id ) ) {
			delete_option( Capabilities::OPTION_SUPER_ADMIN_USER_ID );
			return new \WP_Error( 'afcn_owner_capability_failed', __( 'Airfiber could not grant Super Admin authority.', 'airfiber-centralized' ), array( 'status' => 500 ) );
		}

		return true;
	}

	private static function delete_failed_owner( $user_id ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( absint( $user_id ) );
	}
}
