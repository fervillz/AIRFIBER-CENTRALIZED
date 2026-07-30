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
			$comment = isset( $secret['comment'] ) ? $secret['comment'] : '';
			$details = self::parse_comment( $comment );
			$users[] = array(
				'id'             => isset( $secret['.id'] ) ? $secret['.id'] : '',
				'name'           => $name,
				'profile'        => ! empty( $details['plan'] ) ? $details['plan'] : ( isset( $secret['profile'] ) ? $secret['profile'] : '' ),
				'actual_profile' => isset( $secret['profile'] ) ? $secret['profile'] : '',
				'comment'        => $comment,
				'customer_name'  => $details['name'],
				'phone'          => $details['cp'],
				'installed'      => $details['installed'],
				'grace'          => $details['grace'],
				'payment_method' => $details['payment_method'],
				'payment_amount' => $details['payment_amount'],
				'payment_date'   => $details['payment_date'],
				'wifi'           => $details['wifi'],
				'address_text'   => $details['address'],
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

	private static function parse_comment( $comment ) {
		$values = array(
			'installed'      => '',
			'grace'          => '',
			'payment_method' => '',
			'payment_amount' => '',
			'payment_date'   => '',
			'name'           => '',
			'plan'           => '',
			'cp'             => '',
			'wifi'           => '',
			'address'        => '',
		);
		$keys = 'installed|grace|paymentMethod|paymentAmount|paymentDate|name|plan|cp|wifi|password|Address';

		preg_match_all(
			'/(?:^|\s)(' . $keys . ')\s*:\s*(.*?)(?=\s+(?:' . $keys . ')\s*:|$)/is',
			trim( $comment ),
			$matches,
			PREG_SET_ORDER
		);

		$map = array(
			'paymentmethod' => 'payment_method',
			'paymentamount' => 'payment_amount',
			'paymentdate'   => 'payment_date',
			'address'       => 'address',
		);
		foreach ( $matches as $match ) {
			$key   = strtolower( $match[1] );
			$key   = isset( $map[ $key ] ) ? $map[ $key ] : $key;
			$value = trim( preg_replace( '/\s+/', ' ', $match[2] ) );
			if ( 'N/A' === strtoupper( $value ) ) {
				$value = '';
			}
			if ( 'password' !== $key && array_key_exists( $key, $values ) ) {
				$values[ $key ] = $value;
			}
		}

		return $values;
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

			$customer_name = isset( $user['customer_name'] ) ? sanitize_text_field( $user['customer_name'] ) : '';
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'afc_customer',
					'post_status' => 'publish',
					'post_title'  => $customer_name ? $customer_name : $name,
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
			update_post_meta( $post_id, '_afc_customer_name', $customer_name );
			update_post_meta( $post_id, '_afc_phone', isset( $user['phone'] ) ? sanitize_text_field( $user['phone'] ) : '' );
			update_post_meta( $post_id, '_afc_installation_date', isset( $user['installed'] ) ? sanitize_text_field( $user['installed'] ) : '' );
			update_post_meta( $post_id, '_afc_grace_days', isset( $user['grace'] ) ? absint( $user['grace'] ) : 0 );
			update_post_meta( $post_id, '_afc_payment_method', isset( $user['payment_method'] ) ? sanitize_text_field( $user['payment_method'] ) : '' );
			update_post_meta( $post_id, '_afc_payment_amount', isset( $user['payment_amount'] ) ? floatval( $user['payment_amount'] ) : 0 );
			update_post_meta( $post_id, '_afc_payment_date', isset( $user['payment_date'] ) ? sanitize_text_field( $user['payment_date'] ) : '' );
			update_post_meta( $post_id, '_afc_wifi_name', isset( $user['wifi'] ) ? sanitize_text_field( $user['wifi'] ) : '' );
			update_post_meta( $post_id, '_afc_address', isset( $user['address_text'] ) ? sanitize_textarea_field( $user['address_text'] ) : '' );
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
