<?php

defined( 'ABSPATH' ) || exit;

class AFC_PPP_Users {

	public static function init() {
		add_action( 'wp_ajax_afc_get_ppp_users', array( __CLASS__, 'ajax_get_users' ) );
		add_action( 'wp_ajax_afc_import_ppp_users', array( __CLASS__, 'ajax_import_users' ) );
		add_action( 'wp_ajax_afc_ppp_record_payment', array( __CLASS__, 'ajax_record_payment' ) );
		add_action( 'wp_ajax_afc_ppp_change_service', array( __CLASS__, 'ajax_change_service' ) );
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
			$session = isset( $active_by_name[ $name ] ) ? $active_by_name[ $name ] : array();
			$comment = isset( $secret['comment'] ) ? $secret['comment'] : '';
			$details = self::parse_comment( $comment );
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

	private static function get_customer_id( $username ) {
		$customers = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $username,
			)
		);
		return $customers ? (int) $customers[0] : 0;
	}

	private static function normalize_user( $user ) {
		return array(
			'id'             => isset( $user['id'] ) ? sanitize_text_field( $user['id'] ) : '',
			'name'           => isset( $user['name'] ) ? sanitize_text_field( $user['name'] ) : '',
			'customer_name'  => isset( $user['customer_name'] ) ? sanitize_text_field( $user['customer_name'] ) : '',
			'phone'          => isset( $user['phone'] ) ? sanitize_text_field( $user['phone'] ) : '',
			'profile'        => isset( $user['profile'] ) ? sanitize_text_field( $user['profile'] ) : '',
			'actual_profile' => isset( $user['actual_profile'] ) ? sanitize_text_field( $user['actual_profile'] ) : '',
			'comment'        => isset( $user['comment'] ) ? sanitize_textarea_field( $user['comment'] ) : '',
			'installed'      => isset( $user['installed'] ) ? sanitize_text_field( $user['installed'] ) : '',
			'grace'          => isset( $user['grace'] ) ? absint( $user['grace'] ) : 0,
			'payment_method' => isset( $user['payment_method'] ) ? sanitize_text_field( $user['payment_method'] ) : '',
			'payment_amount' => isset( $user['payment_amount'] ) ? (float) $user['payment_amount'] : 0,
			'payment_date'   => isset( $user['payment_date'] ) ? sanitize_text_field( $user['payment_date'] ) : '',
			'wifi'           => isset( $user['wifi'] ) ? sanitize_text_field( $user['wifi'] ) : '',
			'address_text'   => isset( $user['address_text'] ) ? sanitize_textarea_field( $user['address_text'] ) : '',
			'remote_address' => isset( $user['remote_address'] ) ? sanitize_text_field( $user['remote_address'] ) : '',
			'caller_id'      => isset( $user['caller_id'] ) ? sanitize_text_field( $user['caller_id'] ) : '',
			'active_id'      => isset( $user['active_id'] ) ? sanitize_text_field( $user['active_id'] ) : '',
			'disabled'       => isset( $user['disabled'] ) && ( true === $user['disabled'] || 'true' === $user['disabled'] || '1' === $user['disabled'] ),
		);
	}

	private static function upsert_customer( $user ) {
		$user        = self::normalize_user( $user );
		$customer_id = self::get_customer_id( $user['name'] );

		if ( ! $customer_id ) {
			$customer_id = wp_insert_post(
				array(
					'post_type'   => 'afc_customer',
					'post_status' => 'publish',
					'post_title'  => $user['customer_name'] ? $user['customer_name'] : $user['name'],
				),
				true
			);
			if ( is_wp_error( $customer_id ) ) {
				return $customer_id;
			}
			update_post_meta( $customer_id, '_afc_account_number', 'AIR-' . str_pad( (string) $customer_id, 6, '0', STR_PAD_LEFT ) );
			update_post_meta( $customer_id, '_afc_needs_details', 1 );
			do_action( 'afc_customer_imported_from_mikrotik', $customer_id, $user );
		} elseif ( $user['customer_name'] ) {
			wp_update_post( array( 'ID' => $customer_id, 'post_title' => $user['customer_name'] ) );
		}

		$meta = array(
			'_afc_ppp_username'      => $user['name'],
			'_afc_mikrotik_id'       => $user['id'],
			'_afc_plan'               => $user['profile'],
			'_afc_mikrotik_comment'  => $user['comment'],
			'_afc_customer_name'      => $user['customer_name'],
			'_afc_phone'              => $user['phone'],
			'_afc_installation_date' => $user['installed'],
			'_afc_grace_days'         => $user['grace'],
			'_afc_payment_method'     => $user['payment_method'],
			'_afc_payment_amount'     => $user['payment_amount'],
			'_afc_payment_date'       => $user['payment_date'],
			'_afc_wifi_name'          => $user['wifi'],
			'_afc_address'            => $user['address_text'],
			'_afc_remote_address'     => $user['remote_address'],
			'_afc_caller_id'          => $user['caller_id'],
			'_afc_customer_status'    => $user['disabled'] ? 'disabled' : ( 0 === strcasecmp( $user['actual_profile'], 'Expired' ) ? 'expired' : 'active' ),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $customer_id, $key, $value );
		}

		return (int) $customer_id;
	}

	private static function replace_comment_value( $comment, $key, $value ) {
		$keys    = 'installed|grace|paymentMethod|paymentAmount|paymentDate|name|plan|cp|wifi|password|Address';
		$pattern = '/(' . preg_quote( $key, '/' ) . '\s*:\s*)(.*?)(?=\s+(?:' . $keys . ')\s*:|$)/is';

		if ( preg_match( $pattern, $comment ) ) {
			return preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $value ) {
					return $matches[1] . $value;
				},
				$comment,
				1
			);
		}

		return rtrim( $comment ) . ( trim( $comment ) ? "\n" : '' ) . $key . ':' . $value;
	}

	public static function ajax_record_payment() {
		self::authorize_ajax();
		$user = isset( $_POST['user'] ) && is_array( $_POST['user'] ) ? self::normalize_user( wp_unslash( $_POST['user'] ) ) : array();
		if ( empty( $user['id'] ) || empty( $user['name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The PPP user record is incomplete.', 'airfiber-centralized' ) ) );
		}

		$date   = current_time( 'Y-m-d' );
		$amount = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : $user['payment_amount'];
		$method = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : $user['payment_method'];
		$method = $method ? $method : 'cash';

		$comment = self::replace_comment_value( $user['comment'], 'paymentDate', $date );
		$comment = self::replace_comment_value( $comment, 'paymentAmount', $amount );
		$comment = self::replace_comment_value( $comment, 'paymentMethod', $method );
		$command = array( '/ppp/secret/set', '=.id=' . $user['id'], '=comment=' . $comment );
		$restored = false;
		if ( 0 === strcasecmp( $user['actual_profile'], 'Expired' ) && $user['profile'] && 0 !== strcasecmp( $user['profile'], 'Expired' ) ) {
			$command[] = '=profile=' . $user['profile'];
			$restored  = true;
		}

		$result = AFC_MikroTik::run_command( $command );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( $restored && ! empty( $user['active_id'] ) ) {
			AFC_MikroTik::run_command( array( '/ppp/active/remove', '=.id=' . $user['active_id'] ) );
		}

		$user['comment']        = $comment;
		$user['payment_date']   = $date;
		$user['payment_amount'] = $amount;
		$user['payment_method'] = $method;
		if ( $restored ) {
			$user['actual_profile'] = $user['profile'];
		}
		$customer_id = self::upsert_customer( $user );
		if ( is_wp_error( $customer_id ) ) {
			wp_send_json_error( array( 'message' => $customer_id->get_error_message() ) );
		}

		$payment_id = wp_insert_post(
			array(
				'post_type'   => 'afc_payment',
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Payment - %s - %s', $user['name'], $date ),
			),
			true
		);
		if ( ! is_wp_error( $payment_id ) ) {
			update_post_meta( $payment_id, '_afc_customer_id', $customer_id );
			update_post_meta( $payment_id, '_afc_ppp_username', $user['name'] );
			update_post_meta( $payment_id, '_afc_payment_date', $date );
			update_post_meta( $payment_id, '_afc_payment_amount', $amount );
			update_post_meta( $payment_id, '_afc_payment_method', $method );
			update_post_meta( $payment_id, '_afc_recorded_by', get_current_user_id() );
			do_action( 'afc_payment_recorded', $payment_id, $customer_id );
		}

		wp_send_json_success(
			array(
				'message' => $restored
					? sprintf( __( 'Payment recorded for %s and the active plan was restored.', 'airfiber-centralized' ), $user['name'] )
					: sprintf( __( 'Payment recorded for %s.', 'airfiber-centralized' ), $user['name'] ),
			)
		);
	}

	public static function ajax_change_service() {
		self::authorize_ajax();
		$user   = isset( $_POST['user'] ) && is_array( $_POST['user'] ) ? self::normalize_user( wp_unslash( $_POST['user'] ) ) : array();
		$change = isset( $_POST['change'] ) ? sanitize_key( wp_unslash( $_POST['change'] ) ) : '';
		if ( empty( $user['id'] ) || ! in_array( $change, array( 'expire', 'reconnect' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'The requested PPP action is invalid.', 'airfiber-centralized' ) ) );
		}
		if ( 'reconnect' === $change && 0 !== strcasecmp( $user['actual_profile'], 'Expired' ) ) {
			wp_send_json_error( array( 'message' => __( 'Reconnect is only available for an account currently using the Expired profile.', 'airfiber-centralized' ) ) );
		}

		$profile = 'expire' === $change ? 'Expired' : $user['profile'];
		if ( ! $profile || ( 'reconnect' === $change && 0 === strcasecmp( $profile, 'Expired' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'No active plan is available to restore.', 'airfiber-centralized' ) ) );
		}

		$result = AFC_MikroTik::run_command( array( '/ppp/secret/set', '=.id=' . $user['id'], '=profile=' . $profile ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( 'reconnect' === $change && ! empty( $user['active_id'] ) ) {
			AFC_MikroTik::run_command( array( '/ppp/active/remove', '=.id=' . $user['active_id'] ) );
		}

		$customer_id = self::get_customer_id( $user['name'] );
		if ( $customer_id ) {
			update_post_meta( $customer_id, '_afc_customer_status', 'expire' === $change ? 'expired' : 'active' );
		}

		do_action( 'afc_ppp_service_changed', $user['name'], $change, $profile );
		wp_send_json_success(
			array(
				'message' => 'expire' === $change
					? sprintf( __( '%s was assigned to the Expired profile. Their current session was not disconnected.', 'airfiber-centralized' ), $user['name'] )
					: sprintf( __( '%s was restored to %s and instructed to reconnect.', 'airfiber-centralized' ), $user['name'], $profile ),
			)
		);
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

			$post_id = self::upsert_customer( $user );
			if ( is_wp_error( $post_id ) ) {
				$skipped++;
				continue;
			}
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
