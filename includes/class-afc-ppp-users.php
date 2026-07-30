<?php

defined( 'ABSPATH' ) || exit;

class AFC_PPP_Users {

	public static function init() {
		add_action( 'wp_ajax_afc_get_ppp_users', array( __CLASS__, 'ajax_get_users' ) );
		add_action( 'wp_ajax_afc_import_ppp_users', array( __CLASS__, 'ajax_import_users' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'airfiber-centralized' ) );
		}

		include AFC_PATH . 'templates/admin/ppp-users.php';
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage PPP users.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_ppp_users', 'nonce' );
	}

	public static function ajax_get_users() {
		self::authorize_ajax();

		$secrets = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,profile,comment,disabled,remote-address,caller-id',
			)
		);
		if ( is_wp_error( $secrets ) ) {
			wp_send_json_error( array( 'message' => $secrets->get_error_message() ) );
		}
		if ( isset( $secrets['name'] ) ) {
			$secrets = array( $secrets );
		}

		$active = AFC_MikroTik::run_command(
			array(
				'/ppp/active/print',
				'=.proplist=name,address,caller-id,uptime,service',
			)
		);
		if ( is_wp_error( $active ) ) {
			wp_send_json_error( array( 'message' => $active->get_error_message() ) );
		}
		if ( isset( $active['name'] ) ) {
			$active = array( $active );
		}

		$active_by_name = array();
		foreach ( $active as $session ) {
			if ( ! empty( $session['name'] ) ) {
				$active_by_name[ $session['name'] ] = $session;
			}
		}

		$imported = self::get_imported_usernames();
		$users    = array();
		foreach ( $secrets as $secret ) {
			$name = isset( $secret['name'] ) ? $secret['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$session = isset( $active_by_name[ $name ] ) ? $active_by_name[ $name ] : array();
			$users[] = array(
				'id'             => isset( $secret['.id'] ) ? $secret['.id'] : '',
				'name'           => $name,
				'profile'        => isset( $secret['profile'] ) ? $secret['profile'] : '',
				'comment'        => isset( $secret['comment'] ) ? $secret['comment'] : '',
				'disabled'       => isset( $secret['disabled'] ) && 'true' === $secret['disabled'],
				'remote_address' => isset( $secret['remote-address'] ) ? $secret['remote-address'] : '',
				'caller_id'      => isset( $session['caller-id'] ) ? $session['caller-id'] : ( isset( $secret['caller-id'] ) ? $secret['caller-id'] : '' ),
				'active'         => ! empty( $session ),
				'address'        => isset( $session['address'] ) ? $session['address'] : '',
				'uptime'         => isset( $session['uptime'] ) ? $session['uptime'] : '',
				'imported'       => isset( $imported[ $name ] ),
			);
		}

		wp_send_json_success( array( 'users' => $users, 'count' => count( $users ) ) );
	}

	private static function get_imported_usernames() {
		$posts = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$usernames = array();
		foreach ( $posts as $post_id ) {
			$username = get_post_meta( $post_id, '_afc_ppp_username', true );
			if ( $username ) {
				$usernames[ $username ] = $post_id;
			}
		}
		return $usernames;
	}

	public static function ajax_import_users() {
		self::authorize_ajax();

		$users = isset( $_POST['users'] ) && is_array( $_POST['users'] ) ? wp_unslash( $_POST['users'] ) : array();
		if ( empty( $users ) ) {
			wp_send_json_error( array( 'message' => __( 'No PPP users were selected.', 'airfiber-centralized' ) ) );
		}

		$existing = self::get_imported_usernames();
		$imported = 0;
		$skipped  = 0;

		foreach ( $users as $user ) {
			$name = isset( $user['name'] ) ? sanitize_text_field( $user['name'] ) : '';
			if ( '' === $name || isset( $existing[ $name ] ) ) {
				$skipped++;
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => 'afc_customer',
					'post_status' => 'publish',
					'post_title'  => $name,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				$skipped++;
				continue;
			}

			update_post_meta( $post_id, '_afc_account_number', 'AIR-' . str_pad( (string) $post_id, 6, '0', STR_PAD_LEFT ) );
			update_post_meta( $post_id, '_afc_ppp_username', $name );
			update_post_meta( $post_id, '_afc_mikrotik_id', isset( $user['id'] ) ? sanitize_text_field( $user['id'] ) : '' );
			update_post_meta( $post_id, '_afc_plan', isset( $user['profile'] ) ? sanitize_text_field( $user['profile'] ) : '' );
			update_post_meta( $post_id, '_afc_mikrotik_comment', isset( $user['comment'] ) ? sanitize_textarea_field( $user['comment'] ) : '' );
			update_post_meta( $post_id, '_afc_remote_address', isset( $user['remote_address'] ) ? sanitize_text_field( $user['remote_address'] ) : '' );
			update_post_meta( $post_id, '_afc_caller_id', isset( $user['caller_id'] ) ? sanitize_text_field( $user['caller_id'] ) : '' );
			$is_disabled = isset( $user['disabled'] ) && ( true === $user['disabled'] || 'true' === $user['disabled'] || '1' === $user['disabled'] );
			update_post_meta( $post_id, '_afc_customer_status', $is_disabled ? 'disabled' : 'active' );
			update_post_meta( $post_id, '_afc_needs_details', 1 );

			do_action( 'afc_customer_imported_from_mikrotik', $post_id, $user );
			$existing[ $name ] = $post_id;
			$imported++;
		}

		wp_send_json_success(
			array(
				'message'  => sprintf(
					__( 'Imported %1$d customer(s). Skipped %2$d existing or invalid record(s).', 'airfiber-centralized' ),
					$imported,
					$skipped
				),
				'imported' => $imported,
				'skipped'  => $skipped,
			)
		);
	}
}
