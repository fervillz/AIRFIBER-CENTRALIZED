<?php

defined( 'ABSPATH' ) || exit;

/**
 * Exposes read-only OLT RX data for PPP accounts, including accounts that have
 * not yet been imported as afc_customer posts.
 *
 * Imported customers keep using their saved PON/ONU mapping when available.
 * Unimported or unmapped PPP accounts are correlated against the cached ONU
 * inventory by caller-ID/MAC first, then by ONU description/PPP username.
 * Nothing is persisted for these temporary matches.
 */
class AFC_OLT_PPP_Signals {

	public static function init() {
		add_action( 'wp_ajax_afc_get_olt_ppp_signals', array( __CLASS__, 'ajax_signals' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 40 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 40 );
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_script();
	}

	public static function enqueue_frontend_assets() {
		if (
			! current_user_can( 'manage_options' ) ||
			! class_exists( 'AFC_Frontend_Page' ) ||
			! AFC_Frontend_Page::is_app_request()
		) {
			return;
		}
		self::enqueue_script();
	}

	private static function enqueue_script() {
		wp_enqueue_script(
			'afc-ppp-optical-search-results',
			AFC_URL . 'assets/js/ppp-optical-search-results.js',
			array( 'jquery', 'afc-ppp-optical-async' ),
			AFC_VERSION,
			true
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view optical monitoring.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_ppp_users', 'nonce' );
	}

	private static function sanitize_accounts() {
		$raw = isset( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		$accounts = array();
		foreach ( array_slice( (array) $raw, 0, 1000 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$username = isset( $item['username'] ) ? sanitize_text_field( $item['username'] ) : '';
			if ( '' === $username ) {
				continue;
			}
			$key = strtolower( $username );
			$accounts[ $key ] = array(
				'username'    => $username,
				'caller_id'   => isset( $item['caller_id'] ) ? sanitize_text_field( $item['caller_id'] ) : '',
				'customer_id' => isset( $item['customer_id'] ) ? absint( $item['customer_id'] ) : 0,
				'imported'    => ! empty( $item['imported'] ),
			);
		}
		return array_values( $accounts );
	}

	private static function empty_signal( $status = 'unmatched' ) {
		return array(
			'mapped'       => false,
			'detected'     => false,
			'temporary'    => false,
			'pon'          => 0,
			'onu'          => 0,
			'onu_mac'      => '',
			'description'  => '',
			'onu_type'     => '',
			'onu_online'   => null,
			'rx_power'     => null,
			'status'       => $status,
			'collected_at' => '',
			'stale'        => false,
			'message'      => '',
			'match_method' => '',
			'confidence'   => 0,
		);
	}

	private static function signal_for_location( $match, $snapshot, $inventory ) {
		$pon    = isset( $match['pon'] ) ? absint( $match['pon'] ) : 0;
		$onu    = isset( $match['onu'] ) ? absint( $match['onu'] ) : 0;
		$signal = self::empty_signal( 'unavailable' );

		if ( ! $pon || ! $onu ) {
			return $signal;
		}

		$signal['mapped']       = true;
		$signal['detected']     = true;
		$signal['temporary']    = true;
		$signal['pon']          = $pon;
		$signal['onu']          = $onu;
		$signal['match_method'] = isset( $match['match_method'] ) ? sanitize_key( $match['match_method'] ) : '';
		$signal['confidence']   = isset( $match['confidence'] ) ? absint( $match['confidence'] ) : 0;

		$key = $pon . ':' . $onu;
		if ( ! empty( $inventory['entries'][ $key ] ) ) {
			$entry                 = $inventory['entries'][ $key ];
			$signal['description'] = isset( $entry['description'] ) ? (string) $entry['description'] : '';
			$signal['onu_type']    = isset( $entry['onu_type'] ) ? (string) $entry['onu_type'] : '';
			$signal['onu_online']  = array_key_exists( 'online', $entry ) ? $entry['online'] : null;
			$signal['onu_mac']     = isset( $entry['mac'] ) ? (string) $entry['mac'] : '';
		}

		if ( is_wp_error( $snapshot ) ) {
			$signal['message'] = $snapshot->get_error_message();
			return $signal;
		}

		$signal['collected_at'] = isset( $snapshot['collected_at'] ) ? (string) $snapshot['collected_at'] : '';
		$signal['stale']        = ! empty( $snapshot['stale'] );
		$signal['message']      = isset( $snapshot['error'] ) ? (string) $snapshot['error'] : '';

		if ( empty( $snapshot['entries'][ $key ] ) ) {
			$signal['status'] = $signal['stale'] ? 'stale' : 'offline';
			return $signal;
		}

		$entry = $snapshot['entries'][ $key ];
		if ( empty( $entry['signal_valid'] ) || ! isset( $entry['rx_power'] ) || null === $entry['rx_power'] ) {
			$signal['status'] = $signal['stale'] ? 'stale' : 'invalid';
			return $signal;
		}

		$signal['rx_power'] = round( (float) $entry['rx_power'], 2 );
		$signal['status']   = $signal['stale'] ? 'stale' : ( isset( $entry['status'] ) ? $entry['status'] : 'good' );

		if ( false === $signal['onu_online'] ) {
			$signal['rx_power'] = null;
			$signal['status']   = 'offline';
		}

		return $signal;
	}

	private static function enrich_saved_signal( $signal, $inventory ) {
		if ( empty( $signal['mapped'] ) || empty( $signal['pon'] ) || empty( $signal['onu'] ) ) {
			return $signal;
		}
		$key = absint( $signal['pon'] ) . ':' . absint( $signal['onu'] );
		if ( empty( $inventory['entries'][ $key ] ) ) {
			return $signal;
		}
		$entry = $inventory['entries'][ $key ];
		$signal['description'] = isset( $entry['description'] ) ? (string) $entry['description'] : '';
		$signal['onu_type']    = isset( $entry['onu_type'] ) ? (string) $entry['onu_type'] : '';
		$signal['onu_online']  = array_key_exists( 'online', $entry ) ? $entry['online'] : null;
		if ( ! empty( $entry['mac'] ) ) {
			$signal['onu_mac'] = (string) $entry['mac'];
		}
		return $signal;
	}

	public static function ajax_signals() {
		self::authorize();

		$accounts = self::sanitize_accounts();
		if ( ! $accounts ) {
			wp_send_json_success( array( 'signals' => array() ) );
		}

		$force     = ! empty( $_POST['refresh'] );
		$snapshot  = AFC_OLT::get_snapshot( $force );
		$inventory = AFC_OLT_Inventory::get_inventory( $force );
		$signals   = array();

		foreach ( $accounts as $account ) {
			$username    = $account['username'];
			$customer_id = $account['customer_id'];
			$signal      = null;

			if ( $customer_id && 'afc_customer' === get_post_type( $customer_id ) ) {
				$signal = AFC_OLT::get_customer_signal( $customer_id, $snapshot );
				$signal = self::enrich_saved_signal( $signal, $inventory );
			}

			if ( ! $signal || empty( $signal['mapped'] ) ) {
				$match = AFC_OLT_Inventory::find_match( $account['caller_id'], $username, $inventory );
				$signal = $match
					? self::signal_for_location( $match, $snapshot, $inventory )
					: self::empty_signal( 'unmatched' );
			}

			$signal['imported'] = ! empty( $account['imported'] );
			$signals[ strtolower( $username ) ] = $signal;
		}

		wp_send_json_success(
			array(
				'signals' => $signals,
				'summary' => AFC_OLT::snapshot_summary( $snapshot ),
			)
		);
	}
}
