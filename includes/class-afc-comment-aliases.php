<?php

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility handling for legacy and abbreviated MikroTik PPP comment keys.
 */
class AFC_Comment_Aliases {

	public static function init() {
		// Replace the standard reader so abbreviated comment labels are normalized
		// before the accounts reach the collection UI.
		remove_action( 'wp_ajax_afc_get_ppp_users', array( 'AFC_PPP_Users', 'ajax_get_users' ) );
		add_action( 'wp_ajax_afc_get_ppp_users', array( __CLASS__, 'ajax_get_users' ) );
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage PPP users.', 'airfiber-centralized' ) ), 403 );
		}

		check_ajax_referer( 'afc_ppp_users', 'nonce' );
	}

	private static function parse_comment( $comment ) {
		return AFC_Comment_Fields::parse_comment( $comment );
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

	public static function ajax_get_users() {
		self::authorize_ajax();
		$force_optical = ! empty( $_POST['refresh_optical'] );
		if ( $force_optical && function_exists( 'set_time_limit' ) ) @set_time_limit( 210 );

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
				'=.proplist=.id,name,address,caller-id,uptime,service',
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
		if ( $force_optical && class_exists( 'AFC_OLT_Refresh_Manager' ) ) {
			$refresh_bundle   = AFC_OLT_Refresh_Manager::refresh_full( 'ppp-billing-manual' );
			$optical_snapshot = $refresh_bundle['snapshot'];
		} else {
			$optical_snapshot = AFC_OLT::get_snapshot( false );
		}
		$optical_summary  = AFC_OLT::snapshot_summary( $optical_snapshot );
		$users            = array();
		foreach ( $secrets as $secret ) {
			$name = isset( $secret['name'] ) ? $secret['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$session     = isset( $active_by_name[ $name ] ) ? $active_by_name[ $name ] : array();
			$comment     = isset( $secret['comment'] ) ? $secret['comment'] : '';
			$details     = self::parse_comment( $comment );
			$customer_id = isset( $imported[ $name ] ) ? (int) $imported[ $name ] : 0;
			$caller_id   = isset( $session['caller-id'] ) ? $session['caller-id'] : ( isset( $secret['caller-id'] ) ? $secret['caller-id'] : '' );
			$optical     = $customer_id > 0
				? AFC_OLT::get_customer_signal( $customer_id, $optical_snapshot )
				: array(
					'mapped'       => false,
					'pon'          => 0,
					'onu'          => 0,
					'onu_mac'      => '',
					'rx_power'     => null,
					'status'       => 'not-imported',
					'collected_at' => '',
					'stale'        => false,
					'message'      => '',
				);
			if ( $customer_id > 0 && class_exists( 'AFC_OLT_PPP_Auto_Link' ) ) {
				$optical = AFC_OLT_PPP_Auto_Link::resolve( $name, $caller_id, $optical );
			}
			if ( $customer_id > 0 && empty( $optical['mapped'] ) ) {
				$suggestion = AFC_OLT::suggest_binding( $caller_id, $optical_snapshot );
				if ( $suggestion ) {
					$optical['suggested'] = $suggestion;
				}
			}
			$users[] = array(
				'id'             => isset( $secret['.id'] ) ? $secret['.id'] : '',
				'name'           => $name,
				'profile'        => ! empty( $details['plan'] ) ? $details['plan'] : ( isset( $secret['profile'] ) ? $secret['profile'] : '' ),
				'actual_profile' => isset( $secret['profile'] ) ? $secret['profile'] : '',
				'comment'        => $comment,
				'comment_fields' => isset( $details['custom_fields'] ) ? $details['custom_fields'] : array(),
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
				'caller_id'      => $caller_id,
				'active'         => ! empty( $session ),
				'active_id'      => isset( $session['.id'] ) ? $session['.id'] : '',
				'address'        => isset( $session['address'] ) ? $session['address'] : '',
				'uptime'         => isset( $session['uptime'] ) ? $session['uptime'] : '',
				'imported'       => $customer_id > 0,
				'customer_id'    => $customer_id,
				'optical'        => $optical,
			);
		}

		wp_send_json_success(
			array(
				'users'   => $users,
				'count'   => count( $users ),
				'optical' => $optical_summary,
			)
		);
	}
}
