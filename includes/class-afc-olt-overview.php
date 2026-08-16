<?php

defined( 'ABSPATH' ) || exit;

/**
 * Builds a read-only, customer-aware overview from the cached OLT snapshot.
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
			if ( $pon < 1 || $onu < 1 ) {
				continue;
			}
			$bindings[ $pon . ':' . $onu ] = array(
				'id'          => (int) $customer_id,
				'name'        => get_the_title( $customer_id ),
				'username'    => (string) get_post_meta( $customer_id, '_afc_ppp_username', true ),
				'onu_mac'     => (string) get_post_meta( $customer_id, '_afc_olt_onu_mac', true ),
				'description' => get_the_title( $customer_id ),
			);
		}
		return $bindings;
	}

	private static function learned_macs_by_location( $snapshot ) {
		$locations = array();
		if ( is_wp_error( $snapshot ) || empty( $snapshot['learned_macs'] ) || ! is_array( $snapshot['learned_macs'] ) ) {
			return $locations;
		}
		foreach ( $snapshot['learned_macs'] as $mac => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$pon = isset( $item['pon'] ) ? absint( $item['pon'] ) : 0;
				$onu = isset( $item['onu'] ) ? absint( $item['onu'] ) : 0;
				if ( $pon < 1 || $onu < 1 ) {
					continue;
				}
				$key = $pon . ':' . $onu;
				if ( ! isset( $locations[ $key ] ) ) {
					$locations[ $key ] = array();
				}
				if ( ! in_array( $mac, $locations[ $key ], true ) ) {
					$locations[ $key ][] = $mac;
				}
			}
		}
		return $locations;
	}

	private static function empty_counts() {
		return array(
			'readings' => 0,
			'total'    => 0,
			'mapped'   => 0,
			'unmapped' => 0,
			'good'     => 0,
			'warning'  => 0,
			'critical' => 0,
			'invalid'  => 0,
			'offline'  => 0,
		);
	}

	private static function status_label( $status ) {
		$labels = array(
			'good'     => __( 'Good', 'airfiber-centralized' ),
			'warning'  => __( 'Warning', 'airfiber-centralized' ),
			'critical' => __( 'Critical', 'airfiber-centralized' ),
			'invalid'  => __( 'Invalid RX', 'airfiber-centralized' ),
			'offline'  => __( 'Offline', 'airfiber-centralized' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'airfiber-centralized' );
	}

	private static function build_payload( $snapshot ) {
		$summary  = AFC_OLT::snapshot_summary( $snapshot );
		$settings = AFC_OLT::get_settings();
		$counts   = self::empty_counts();
		$rows     = array();
		$pons     = array();
		$invalid_samples = array();

		$payload = array(
			'summary' => $summary,
			'counts'  => $counts,
			'pons'    => array(),
			'onus'    => array(),
			'diagnostics' => array(
				'invalid_rx'      => 0,
				'invalid_samples' => array(),
				'rx_oid'          => isset( $snapshot['rx_oid'] ) ? (string) $snapshot['rx_oid'] : (string) $settings['rx_oid'],
			),
			'limits' => array(
				'warning'  => (float) $settings['warning_dbm'],
				'critical' => (float) $settings['critical_dbm'],
			),
			'olt' => array(
				'name' => isset( $settings['name'] ) ? (string) $settings['name'] : __( 'Primary OLT', 'airfiber-centralized' ),
			),
		);

		if ( is_wp_error( $snapshot ) || empty( $snapshot['entries'] ) ) {
			return $payload;
		}

		$bindings      = self::customer_bindings();
		$location_macs = self::learned_macs_by_location( $snapshot );
		$seen          = array();

		foreach ( $snapshot['entries'] as $entry ) {
			$pon = isset( $entry['pon'] ) ? absint( $entry['pon'] ) : 0;
			$onu = isset( $entry['onu'] ) ? absint( $entry['onu'] ) : 0;
			if ( $pon < 1 || $onu < 1 ) {
				continue;
			}

			$key      = $pon . ':' . $onu;
			$customer = isset( $bindings[ $key ] ) ? $bindings[ $key ] : null;
			$macs     = isset( $location_macs[ $key ] ) ? $location_macs[ $key ] : array();
			if ( $customer && ! empty( $customer['onu_mac'] ) && ! in_array( $customer['onu_mac'], $macs, true ) ) {
				array_unshift( $macs, $customer['onu_mac'] );
			}

			$signal_valid = ! empty( $entry['signal_valid'] ) && isset( $entry['rx_power'] ) && null !== $entry['rx_power'];
			$power        = $signal_valid ? round( (float) $entry['rx_power'], 2 ) : null;
			$status       = isset( $entry['status'] ) && in_array( $entry['status'], array( 'good', 'warning', 'critical', 'invalid' ), true ) ? $entry['status'] : ( $signal_valid ? 'good' : 'invalid' );
			$raw_rx       = isset( $entry['raw_rx'] ) ? $entry['raw_rx'] : null;
			$raw_text     = isset( $entry['raw_rx_text'] ) ? (string) $entry['raw_rx_text'] : '';
			$seen[ $key ] = true;

			if ( 'invalid' === $status && count( $invalid_samples ) < 8 ) {
				$invalid_samples[] = array( 'pon' => $pon, 'onu' => $onu, 'raw' => $raw_rx, 'raw_text' => $raw_text );
			}

			$rows[] = array(
				'pon'          => $pon,
				'onu'          => $onu,
				'description'  => $customer ? (string) $customer['description'] : '',
				'mac_addresses'=> array_values( $macs ),
				'rx_power'     => $power,
				'raw_rx'       => $raw_rx,
				'raw_rx_text'  => $raw_text,
				'signal_valid' => $signal_valid,
				'status'       => $status,
				'status_label' => self::status_label( $status ),
				'mapped'       => null !== $customer,
				'customer'     => $customer,
			);
		}

		foreach ( $bindings as $key => $customer ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$parts = array_map( 'absint', explode( ':', $key, 2 ) );
			$pon   = isset( $parts[0] ) ? $parts[0] : 0;
			$onu   = isset( $parts[1] ) ? $parts[1] : 0;
			if ( $pon < 1 || $onu < 1 ) {
				continue;
			}
			$macs = isset( $location_macs[ $key ] ) ? $location_macs[ $key ] : array();
			if ( ! empty( $customer['onu_mac'] ) && ! in_array( $customer['onu_mac'], $macs, true ) ) {
				array_unshift( $macs, $customer['onu_mac'] );
			}
			$rows[] = array(
				'pon'           => $pon,
				'onu'           => $onu,
				'description'   => (string) $customer['description'],
				'mac_addresses' => array_values( $macs ),
				'rx_power'      => null,
				'raw_rx'        => null,
				'raw_rx_text'   => '',
				'signal_valid'  => false,
				'status'        => 'offline',
				'status_label'  => self::status_label( 'offline' ),
				'mapped'        => true,
				'customer'      => $customer,
			);
		}

		usort( $rows, function ( $a, $b ) {
			return $a['pon'] === $b['pon'] ? $a['onu'] <=> $b['onu'] : $a['pon'] <=> $b['pon'];
		} );

		$counts['readings'] = isset( $snapshot['count'] ) ? absint( $snapshot['count'] ) : count( $snapshot['entries'] );
		$counts['total']    = count( $rows );

		foreach ( $rows as $row ) {
			$status = $row['status'];
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
			$row['mapped'] ? $counts['mapped']++ : $counts['unmapped']++;

			$pon_key = (string) $row['pon'];
			if ( ! isset( $pons[ $pon_key ] ) ) {
				$pons[ $pon_key ] = array(
					'pon'      => (int) $row['pon'],
					'total'    => 0,
					'mapped'   => 0,
					'good'     => 0,
					'warning'  => 0,
					'critical' => 0,
					'invalid'  => 0,
					'offline'  => 0,
					'valid'    => 0,
					'weakest'  => null,
				);
			}
			$pons[ $pon_key ]['total']++;
			if ( $row['mapped'] ) {
				$pons[ $pon_key ]['mapped']++;
			}
			if ( isset( $pons[ $pon_key ][ $status ] ) ) {
				$pons[ $pon_key ][ $status ]++;
			}
			if ( $row['signal_valid'] && null !== $row['rx_power'] ) {
				$pons[ $pon_key ]['valid']++;
				if ( null === $pons[ $pon_key ]['weakest'] || $row['rx_power'] < $pons[ $pon_key ]['weakest'] ) {
					$pons[ $pon_key ]['weakest'] = $row['rx_power'];
				}
			}
		}

		ksort( $pons, SORT_NUMERIC );
		$payload['counts']                          = $counts;
		$payload['pons']                            = array_values( $pons );
		$payload['onus']                            = $rows;
		$payload['diagnostics']['invalid_rx']       = $counts['invalid'];
		$payload['diagnostics']['invalid_samples']  = $invalid_samples;
		return $payload;
	}

	public static function ajax_overview() {
		self::authorize();
		$force    = ! empty( $_POST['refresh'] );
		$snapshot = AFC_OLT::get_snapshot( $force );
		wp_send_json_success( self::build_payload( $snapshot ) );
	}
}
