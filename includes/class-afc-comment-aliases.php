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

	/**
	 * Parse both Address: and its legacy addr: abbreviation into one address value.
	 */
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
		$keys = 'installed|grace|paymentMethod|paymentAmount|paymentDate|name|plan|cp|wifi|password|Address|addr';

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
			'addr'          => 'address',
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
				'active_id'      => isset( $session['.id'] ) ? $session['.id'] : '',
				'address'        => isset( $session['address'] ) ? $session['address'] : '',
				'uptime'         => isset( $session['uptime'] ) ? $session['uptime'] : '',
				'imported'       => isset( $imported[ $name ] ),
			);
		}

		wp_send_json_success( array( 'users' => $users, 'count' => count( $users ) ) );
	}
}
