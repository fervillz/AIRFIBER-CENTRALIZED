<?php

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the currently-live MikroTik PPP caller-id MAC to the ONU location
 * learned by the OLT and persists that relationship in afc_olt_ppp_links.
 *
 * This is intentionally attached to the existing search AJAX response so Basic
 * and Advanced search results, including their hover detail cards, receive the
 * same optical result immediately.
 */
class AFC_OLT_PPP_Auto_Link {

	public static function init() {
		add_filter( 'afc_search_ajaxify_records', array( __CLASS__, 'enrich_search_records' ), 30, 4 );
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'afc_olt_ppp_links';
	}

	private static function normalize_mac( $value ) {
		return class_exists( 'AFC_OLT_Inventory' ) ? AFC_OLT_Inventory::normalize_mac( $value ) : '';
	}

	private static function stored_link( $username, $caller_id = '' ) {
		global $wpdb;

		if ( class_exists( 'AFC_OLT_PPP_Signals' ) ) {
			AFC_OLT_PPP_Signals::ensure_schema();
		}

		$table    = self::table_name();
		$username = trim( (string) $username );
		$link     = null;

		if ( '' !== $username ) {
			$link = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE ppp_username = %s LIMIT 1", $username ),
				ARRAY_A
			);
		}

		if ( ! $link ) {
			$ppp_mac = self::normalize_mac( $caller_id );
			if ( $ppp_mac ) {
				$link = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$table} WHERE ppp_mac = %s ORDER BY updated_at DESC, id DESC LIMIT 1", $ppp_mac ),
					ARRAY_A
				);
			}
		}

		return is_array( $link ) ? $link : null;
	}

	private static function empty_signal() {
		return array(
			'mapped'       => false,
			'detected'     => false,
			'temporary'    => false,
			'persisted'    => false,
			'pon'          => 0,
			'onu'          => 0,
			'onu_mac'      => '',
			'description'  => '',
			'onu_type'     => '',
			'onu_online'   => null,
			'rx_power'     => null,
			'status'       => 'unmatched',
			'collected_at' => '',
			'stale'        => false,
			'message'      => '',
			'match_method' => '',
			'confidence'   => 0,
			'source'       => 'database',
		);
	}

	private static function sources() {
		$snapshot = class_exists( 'AFC_OLT_MAC_Link' )
			? AFC_OLT_MAC_Link::stored_snapshot()
			: AFC_OLT::get_snapshot( false );
		$inventory = class_exists( 'AFC_OLT_MAC_Link' )
			? AFC_OLT_MAC_Link::stored_inventory()
			: AFC_OLT_Inventory::get_inventory( false );

		return array( $snapshot, is_array( $inventory ) ? $inventory : array( 'entries' => array() ) );
	}

	private static function learned_mac_match( $caller_id, $snapshot ) {
		$ppp_mac = self::normalize_mac( $caller_id );
		if ( ! $ppp_mac || is_wp_error( $snapshot ) || empty( $snapshot['learned_macs'] ) ) {
			return null;
		}

		$locations = array();
		foreach ( (array) $snapshot['learned_macs'] as $learned_mac => $items ) {
			if ( 0 !== strcasecmp( self::normalize_mac( $learned_mac ), $ppp_mac ) ) {
				continue;
			}
			foreach ( (array) $items as $location ) {
				$pon = isset( $location['pon'] ) ? absint( $location['pon'] ) : 0;
				$onu = isset( $location['onu'] ) ? absint( $location['onu'] ) : 0;
				if ( $pon > 0 && $onu > 0 ) {
					$locations[ $pon . ':' . $onu ] = array( 'pon' => $pon, 'onu' => $onu );
				}
			}
		}

		if ( 1 !== count( $locations ) ) {
			return null;
		}

		$match                 = reset( $locations );
		$match['match_method'] = 'ppp_mac';
		$match['confidence']   = 100;
		$match['ppp_mac']      = $ppp_mac;
		return $match;
	}

	private static function fallback_match( $username, $caller_id, $inventory ) {
		if ( ! class_exists( 'AFC_OLT_Inventory' ) ) {
			return null;
		}
		$match = AFC_OLT_Inventory::find_match( $caller_id, $username, $inventory );
		if ( ! is_array( $match ) ) {
			return null;
		}

		$method     = isset( $match['match_method'] ) ? sanitize_key( $match['match_method'] ) : '';
		$confidence = isset( $match['confidence'] ) ? absint( $match['confidence'] ) : 0;
		if ( 'mac' === $method || 'description' === $method || ( 'description_fuzzy' === $method && $confidence >= 90 ) ) {
			return $match;
		}
		return null;
	}

	private static function signal_for_location( $match, $snapshot, $inventory ) {
		$signal = self::empty_signal();
		$pon    = isset( $match['pon'] ) ? absint( $match['pon'] ) : 0;
		$onu    = isset( $match['onu'] ) ? absint( $match['onu'] ) : 0;
		if ( ! $pon || ! $onu ) {
			return $signal;
		}

		$signal['mapped']       = true;
		$signal['detected']     = true;
		$signal['persisted']    = true;
		$signal['pon']          = $pon;
		$signal['onu']          = $onu;
		$signal['match_method'] = isset( $match['match_method'] ) ? sanitize_key( $match['match_method'] ) : 'database';
		$signal['confidence']   = isset( $match['confidence'] ) ? absint( $match['confidence'] ) : 100;
		$signal['source']       = 'database';

		$key = $pon . ':' . $onu;
		if ( ! empty( $inventory['entries'][ $key ] ) && is_array( $inventory['entries'][ $key ] ) ) {
			$onu_row               = $inventory['entries'][ $key ];
			$signal['onu_mac']     = isset( $onu_row['mac'] ) ? self::normalize_mac( $onu_row['mac'] ) : '';
			$signal['description'] = isset( $onu_row['description'] ) ? (string) $onu_row['description'] : '';
			$signal['onu_type']    = isset( $onu_row['onu_type'] ) ? (string) $onu_row['onu_type'] : '';
			$signal['onu_online']  = array_key_exists( 'online', $onu_row ) ? $onu_row['online'] : null;
		}

		if ( is_wp_error( $snapshot ) ) {
			$signal['status']  = 'unavailable';
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

		$rx = $snapshot['entries'][ $key ];
		if ( ! empty( $rx['signal_valid'] ) && isset( $rx['rx_power'] ) && null !== $rx['rx_power'] ) {
			$signal['rx_power'] = round( (float) $rx['rx_power'], 2 );
			$signal['status']   = $signal['stale'] ? 'stale' : ( isset( $rx['status'] ) ? sanitize_key( $rx['status'] ) : 'good' );
		} else {
			$signal['status'] = $signal['stale'] ? 'stale' : 'invalid';
		}

		if ( false === $signal['onu_online'] ) {
			$signal['rx_power'] = null;
			$signal['status']   = 'offline';
		}

		return $signal;
	}

	private static function signal_from_link( $link, $snapshot, $inventory ) {
		$match = array(
			'pon'          => isset( $link['pon'] ) ? absint( $link['pon'] ) : 0,
			'onu'          => isset( $link['onu'] ) ? absint( $link['onu'] ) : 0,
			'match_method' => isset( $link['match_method'] ) ? sanitize_key( $link['match_method'] ) : 'database',
			'confidence'   => isset( $link['confidence'] ) ? absint( $link['confidence'] ) : 100,
		);
		$signal = self::signal_for_location( $match, $snapshot, $inventory );

		if ( ( is_wp_error( $snapshot ) || null === $signal['rx_power'] ) && isset( $link['rx_power'] ) && is_numeric( $link['rx_power'] ) ) {
			$signal['rx_power'] = round( (float) $link['rx_power'], 2 );
			$signal['status']   = ! empty( $link['signal_status'] ) ? sanitize_key( $link['signal_status'] ) : $signal['status'];
			$signal['collected_at'] = ! empty( $link['collected_at'] ) ? (string) $link['collected_at'] : $signal['collected_at'];
		}
		if ( empty( $signal['onu_mac'] ) && ! empty( $link['olt_mac'] ) ) {
			$signal['onu_mac'] = self::normalize_mac( $link['olt_mac'] );
		}
		if ( empty( $signal['description'] ) && ! empty( $link['description'] ) ) {
			$signal['description'] = (string) $link['description'];
		}
		return $signal;
	}

	private static function persist_link( $username, $caller_id, $signal, $existing = null ) {
		if ( ! $username || empty( $signal['mapped'] ) || empty( $signal['pon'] ) || empty( $signal['onu'] ) ) {
			return false;
		}

		global $wpdb;
		if ( class_exists( 'AFC_OLT_PPP_Signals' ) ) {
			AFC_OLT_PPP_Signals::ensure_schema();
		}

		$existing = $existing ? $existing : self::stored_link( $username, $caller_id );
		$now      = current_time( 'mysql' );
		$values   = array(
			'ppp_username' => sanitize_text_field( $username ),
			'ppp_mac'      => self::normalize_mac( $caller_id ),
			'olt_mac'      => self::normalize_mac( isset( $signal['onu_mac'] ) ? $signal['onu_mac'] : '' ),
			'pon'          => absint( $signal['pon'] ),
			'onu'          => absint( $signal['onu'] ),
			'description'  => isset( $signal['description'] ) ? sanitize_text_field( $signal['description'] ) : '',
			'match_method' => isset( $signal['match_method'] ) ? sanitize_key( $signal['match_method'] ) : 'database',
			'confidence'   => isset( $signal['confidence'] ) ? absint( $signal['confidence'] ) : 100,
			'rx_power'     => isset( $signal['rx_power'] ) && null !== $signal['rx_power'] && is_numeric( $signal['rx_power'] ) ? number_format( (float) $signal['rx_power'], 2, '.', '' ) : '',
			'signal_status'=> isset( $signal['status'] ) ? sanitize_key( $signal['status'] ) : 'unavailable',
			'collected_at' => isset( $signal['collected_at'] ) ? substr( sanitize_text_field( $signal['collected_at'] ), 0, 19 ) : '',
			'matched_at'   => $existing && ! empty( $existing['matched_at'] ) ? (string) $existing['matched_at'] : $now,
			'updated_at'   => $now,
		);
		if ( $existing && ! empty( $existing['id'] ) ) {
			$values['id'] = absint( $existing['id'] );
		}

		$formats = array();
		foreach ( $values as $key => $value ) {
			$formats[] = in_array( $key, array( 'id', 'pon', 'onu', 'confidence' ), true ) ? '%d' : '%s';
		}

		return false !== $wpdb->replace( self::table_name(), $values, $formats );
	}

	public static function resolve( $username, $caller_id = '', $existing_optical = array() ) {
		$username = trim( (string) $username );
		if ( '' === $username || ! class_exists( 'AFC_OLT' ) || ! AFC_OLT::is_enabled() ) {
			return is_array( $existing_optical ) ? $existing_optical : self::empty_signal();
		}

		list( $snapshot, $inventory ) = self::sources();
		$link = self::stored_link( $username, $caller_id );
		if ( $link ) {
			$signal = self::signal_from_link( $link, $snapshot, $inventory );
			self::persist_link( $username, $caller_id, $signal, $link );
			return $signal;
		}

		if ( is_array( $existing_optical ) && ! empty( $existing_optical['mapped'] ) && ! empty( $existing_optical['pon'] ) && ! empty( $existing_optical['onu'] ) ) {
			$match = array(
				'pon'          => absint( $existing_optical['pon'] ),
				'onu'          => absint( $existing_optical['onu'] ),
				'match_method' => ! empty( $existing_optical['match_method'] ) ? sanitize_key( $existing_optical['match_method'] ) : 'customer',
				'confidence'   => ! empty( $existing_optical['confidence'] ) ? absint( $existing_optical['confidence'] ) : 100,
			);
			$signal = self::signal_for_location( $match, $snapshot, $inventory );
			if ( empty( $signal['onu_mac'] ) && ! empty( $existing_optical['onu_mac'] ) ) {
				$signal['onu_mac'] = self::normalize_mac( $existing_optical['onu_mac'] );
			}
			self::persist_link( $username, $caller_id, $signal );
			return $signal;
		}

		$match = self::learned_mac_match( $caller_id, $snapshot );
		if ( ! $match ) {
			$match = self::fallback_match( $username, $caller_id, $inventory );
		}
		if ( ! $match ) {
			return is_array( $existing_optical ) ? $existing_optical : self::empty_signal();
		}

		$signal = self::signal_for_location( $match, $snapshot, $inventory );
		self::persist_link( $username, $caller_id, $signal );
		return $signal;
	}

	public static function enrich_search_records( $records, $accounts, $providers, $context ) {
		foreach ( $records as $key => &$record ) {
			if ( empty( $record['found'] ) ) {
				continue;
			}

			$username = isset( $record['account'] ) ? (string) $record['account'] : (string) $key;
			$caller_id = ! empty( $record['session']['caller_id'] ) ? (string) $record['session']['caller_id'] : '';
			$existing = isset( $record['optical'] ) && is_array( $record['optical'] ) ? $record['optical'] : array();
			$record['optical'] = self::resolve( $username, $caller_id, $existing );
		}
		unset( $record );
		return $records;
	}
}
