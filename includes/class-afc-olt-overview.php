<?php

defined( 'ABSPATH' ) || exit;

/**
 * Builds a read-only, customer-aware overview from the saved OLT snapshot.
 */
class AFC_OLT_Overview {

	public static function init() {
		add_action( 'wp_ajax_afc_get_olt_overview', array( __CLASS__, 'ajax_overview' ) );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view OLT monitoring.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_test_olt_ajax', 'nonce' );
	}

	private static function customer_bindings() {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_afc_olt_pon', 'compare' => 'EXISTS' ),
					array( 'key' => '_afc_olt_onu', 'compare' => 'EXISTS' ),
				),
			)
		);
		$bindings = array();
		foreach ( $ids as $customer_id ) {
			$pon = absint( get_post_meta( $customer_id, '_afc_olt_pon', true ) );
			$onu = absint( get_post_meta( $customer_id, '_afc_olt_onu', true ) );
			if ( $pon < 1 || $onu < 1 ) continue;
			$key = $pon . ':' . $onu;
			$bindings[ $key ] = array(
				'id'          => (int) $customer_id,
				'name'        => get_the_title( $customer_id ),
				'username'    => (string) get_post_meta( $customer_id, '_afc_ppp_username', true ),
				'onu_mac'     => (string) get_post_meta( $customer_id, '_afc_olt_onu_mac', true ),
				'description' => (string) get_post_meta( $customer_id, '_afc_olt_description', true ),
			);
			if ( '' === $bindings[ $key ]['description'] ) $bindings[ $key ]['description'] = get_the_title( $customer_id );
		}
		return $bindings;
	}

	private static function learned_macs_by_location( $snapshot ) {
		$locations = array();
		if ( is_wp_error( $snapshot ) || empty( $snapshot['learned_macs'] ) || ! is_array( $snapshot['learned_macs'] ) ) return $locations;
		foreach ( $snapshot['learned_macs'] as $mac => $items ) {
			foreach ( (array) $items as $item ) {
				$pon = isset( $item['pon'] ) ? absint( $item['pon'] ) : 0;
				$onu = isset( $item['onu'] ) ? absint( $item['onu'] ) : 0;
				if ( $pon < 1 || $onu < 1 ) continue;
				$key = $pon . ':' . $onu;
				if ( ! isset( $locations[ $key ] ) ) $locations[ $key ] = array();
				if ( ! in_array( $mac, $locations[ $key ], true ) ) $locations[ $key ][] = $mac;
			}
		}
		return $locations;
	}

	private static function empty_counts() {
		return array( 'readings' => 0, 'total' => 0, 'mapped' => 0, 'unmapped' => 0, 'good' => 0, 'warning' => 0, 'critical' => 0, 'invalid' => 0, 'offline' => 0 );
	}

	private static function status_label( $status ) {
		$labels = array(
			'good' => __( 'Good', 'airfiber-centralized' ),
			'warning' => __( 'Warning', 'airfiber-centralized' ),
			'critical' => __( 'Critical', 'airfiber-centralized' ),
			'invalid' => __( 'Signal unavailable', 'airfiber-centralized' ),
			'offline' => __( 'Offline', 'airfiber-centralized' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'airfiber-centralized' );
	}

	private static function add_unique_mac( &$macs, $mac ) {
		$mac = trim( (string) $mac );
		if ( '' !== $mac && ! in_array( $mac, $macs, true ) ) $macs[] = $mac;
	}

	private static function build_payload( $snapshot, $inventory ) {
		$summary  = AFC_OLT::snapshot_summary( $snapshot );
		$settings = AFC_OLT::get_settings();
		$counts   = self::empty_counts();
		$rows     = array();
		$pons     = array();
		$invalid_samples = array();
		$snapshot_entries  = ! is_wp_error( $snapshot ) && ! empty( $snapshot['entries'] ) && is_array( $snapshot['entries'] ) ? $snapshot['entries'] : array();
		$inventory_entries = ! empty( $inventory['entries'] ) && is_array( $inventory['entries'] ) ? $inventory['entries'] : array();
		$bindings          = self::customer_bindings();
		$location_macs     = self::learned_macs_by_location( $snapshot );
		$keys              = array_unique( array_merge( array_keys( $snapshot_entries ), array_keys( $inventory_entries ), array_keys( $bindings ) ) );

		foreach ( $keys as $key ) {
			$parts = array_map( 'absint', explode( ':', $key, 2 ) );
			$pon = isset( $parts[0] ) ? $parts[0] : 0;
			$onu = isset( $parts[1] ) ? $parts[1] : 0;
			if ( $pon < 1 || $onu < 1 ) continue;
			$entry    = isset( $snapshot_entries[ $key ] ) ? $snapshot_entries[ $key ] : array();
			$onu_info = isset( $inventory_entries[ $key ] ) ? $inventory_entries[ $key ] : array();
			$customer = isset( $bindings[ $key ] ) ? $bindings[ $key ] : null;
			$macs     = array();
			$online   = array_key_exists( 'online', $onu_info ) ? $onu_info['online'] : null;
			$onu_type = isset( $onu_info['onu_type'] ) ? (string) $onu_info['onu_type'] : '';
			if ( ! empty( $onu_info['mac'] ) ) self::add_unique_mac( $macs, $onu_info['mac'] );
			if ( $customer && ! empty( $customer['onu_mac'] ) ) self::add_unique_mac( $macs, $customer['onu_mac'] );
			if ( ! empty( $location_macs[ $key ] ) ) foreach ( $location_macs[ $key ] as $mac ) self::add_unique_mac( $macs, $mac );
			$description = isset( $onu_info['description'] ) ? trim( (string) $onu_info['description'] ) : '';
			if ( '' === $description && $customer ) $description = (string) $customer['description'];
			$signal_valid = ! empty( $entry['signal_valid'] ) && isset( $entry['rx_power'] ) && null !== $entry['rx_power'];
			$power = $signal_valid ? round( (float) $entry['rx_power'], 2 ) : null;
			$raw_rx = isset( $entry['raw_rx'] ) ? $entry['raw_rx'] : null;
			$raw_text = isset( $entry['raw_rx_text'] ) ? (string) $entry['raw_rx_text'] : '';
			if ( false === $online ) {
				$status = 'offline'; $signal_valid = false; $power = null;
			} elseif ( $entry ) {
				$status = isset( $entry['status'] ) && in_array( $entry['status'], array( 'good', 'warning', 'critical', 'invalid' ), true ) ? $entry['status'] : ( $signal_valid ? 'good' : 'invalid' );
			} elseif ( true === $online ) {
				$status = 'invalid';
			} else {
				$status = 'offline';
			}
			if ( 'invalid' === $status && count( $invalid_samples ) < 8 ) $invalid_samples[] = array( 'pon' => $pon, 'onu' => $onu, 'raw' => $raw_rx, 'raw_text' => $raw_text );
			$rows[] = array(
				'pon' => $pon, 'onu' => $onu, 'description' => $description, 'mac_addresses' => array_values( $macs ), 'onu_type' => $onu_type,
				'onu_online' => $online, 'rx_power' => $power, 'raw_rx' => $raw_rx, 'raw_rx_text' => $raw_text, 'signal_valid' => $signal_valid,
				'status' => $status, 'status_label' => self::status_label( $status ), 'mapped' => null !== $customer, 'customer' => $customer,
			);
		}

		usort( $rows, function ( $a, $b ) { return $a['pon'] === $b['pon'] ? $a['onu'] <=> $b['onu'] : $a['pon'] <=> $b['pon']; } );
		$counts['readings'] = ! is_wp_error( $snapshot ) && isset( $snapshot['count'] ) ? absint( $snapshot['count'] ) : 0;
		$counts['total'] = count( $rows );
		foreach ( $rows as $row ) {
			$status = $row['status'];
			if ( isset( $counts[ $status ] ) ) $counts[ $status ]++;
			$row['mapped'] ? $counts['mapped']++ : $counts['unmapped']++;
			$pk = (string) $row['pon'];
			if ( ! isset( $pons[ $pk ] ) ) $pons[ $pk ] = array( 'pon' => (int) $row['pon'], 'total' => 0, 'mapped' => 0, 'good' => 0, 'warning' => 0, 'critical' => 0, 'invalid' => 0, 'offline' => 0, 'valid' => 0, 'weakest' => null );
			$pons[ $pk ]['total']++;
			if ( $row['mapped'] ) $pons[ $pk ]['mapped']++;
			if ( isset( $pons[ $pk ][ $status ] ) ) $pons[ $pk ][ $status ]++;
			if ( $row['signal_valid'] && null !== $row['rx_power'] ) {
				$pons[ $pk ]['valid']++;
				if ( null === $pons[ $pk ]['weakest'] || $row['rx_power'] < $pons[ $pk ]['weakest'] ) $pons[ $pk ]['weakest'] = $row['rx_power'];
			}
		}
		ksort( $pons, SORT_NUMERIC );
		return array(
			'summary' => $summary,
			'counts' => $counts,
			'pons' => array_values( $pons ),
			'onus' => $rows,
			'diagnostics' => array(
				'invalid_rx' => $counts['invalid'], 'invalid_samples' => $invalid_samples,
				'rx_oid' => ! is_wp_error( $snapshot ) && isset( $snapshot['rx_oid'] ) ? (string) $snapshot['rx_oid'] : (string) $settings['rx_oid'],
				'inventory_count' => isset( $inventory['count'] ) ? (int) $inventory['count'] : 0,
				'inventory_columns' => isset( $inventory['columns'] ) ? $inventory['columns'] : array(),
				'inventory_stale' => ! empty( $inventory['stale'] ),
				'inventory_error' => isset( $inventory['error'] ) ? $inventory['error'] : '',
			),
			'limits' => array( 'warning' => (float) $settings['warning_dbm'], 'critical' => (float) $settings['critical_dbm'] ),
			'olt' => array( 'name' => isset( $settings['name'] ) ? (string) $settings['name'] : __( 'Primary OLT', 'airfiber-centralized' ) ),
			'last_refresh' => class_exists( 'AFC_OLT_Refresh_Manager' ) ? AFC_OLT_Refresh_Manager::get_last_refresh() : array(),
		);
	}

	public static function ajax_overview() {
		self::authorize();
		$force = ! empty( $_POST['refresh'] );
		if ( $force && class_exists( 'AFC_OLT_Refresh_Manager' ) ) {
			$bundle = AFC_OLT_Refresh_Manager::refresh_full( 'optical-manual' );
			$snapshot = $bundle['snapshot'];
			$inventory = $bundle['inventory'];
		} else {
			$snapshot = class_exists( 'AFC_OLT_Refresh_Manager' ) ? AFC_OLT_Refresh_Manager::stored_snapshot() : AFC_OLT::get_snapshot( false );
			$inventory = class_exists( 'AFC_OLT_Refresh_Manager' ) ? AFC_OLT_Refresh_Manager::stored_inventory() : array( 'entries' => array() );
		}
		wp_send_json_success( self::build_payload( $snapshot, $inventory ) );
	}
}
