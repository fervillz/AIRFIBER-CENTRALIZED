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
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to view OLT monitoring.', 'airfiber-centralized' ) ),
				403
			);
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
					array(
						'key'     => '_afc_olt_pon',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_afc_olt_onu',
						'compare' => 'EXISTS',
					),
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

			$key = $pon . ':' . $onu;
			$bindings[ $key ] = array(
				'id'       => (int) $customer_id,
				'name'     => get_the_title( $customer_id ),
				'username' => (string) get_post_meta( $customer_id, '_afc_ppp_username', true ),
				'onu_mac'  => (string) get_post_meta( $customer_id, '_afc_olt_onu_mac', true ),
			);
		}

		return $bindings;
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
			'offline'  => 0,
		);
	}

	private static function status_label( $status ) {
		$labels = array(
			'good'     => __( 'Good', 'airfiber-centralized' ),
			'warning'  => __( 'Warning', 'airfiber-centralized' ),
			'critical' => __( 'Critical', 'airfiber-centralized' ),
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

		$payload = array(
			'summary' => $summary,
			'counts'  => $counts,
			'pons'    => array(),
			'onus'    => array(),
			'limits'  => array(
				'warning'  => (float) $settings['warning_dbm'],
				'critical' => (float) $settings['critical_dbm'],
			),
			'olt'     => array(
				'name' => isset( $settings['name'] ) ? (string) $settings['name'] : __( 'Primary OLT', 'airfiber-centralized' ),
			),
		);

		if ( is_wp_error( $snapshot ) || empty( $snapshot['entries'] ) ) {
			return $payload;
		}

		$bindings = self::customer_bindings();
		$seen     = array();

		foreach ( $snapshot['entries'] as $key => $entry ) {
			$pon = isset( $entry['pon'] ) ? absint( $entry['pon'] ) : 0;
			$onu = isset( $entry['onu'] ) ? absint( $entry['onu'] ) : 0;
			if ( $pon < 1 || $onu < 1 ) {
				continue;
			}

			$key      = $pon . ':' . $onu;
			$status   = isset( $entry['status'] ) && in_array( $entry['status'], array( 'good', 'warning', 'critical' ), true ) ? $entry['status'] : 'good';
			$customer = isset( $bindings[ $key ] ) ? $bindings[ $key ] : null;
			$power    = isset( $entry['rx_power'] ) && is_numeric( $entry['rx_power'] ) ? round( (float) $entry['rx_power'], 2 ) : null;
			$seen[ $key ] = true;

			$rows[] = array(
				'pon'          => $pon,
				'onu'          => $onu,
				'rx_power'     => $power,
				'status'       => $status,
				'status_label' => self::status_label( $status ),
				'mapped'       => null !== $customer,
				'customer'     => $customer,
			);
		}

		/* A saved binding without a current RX entry is useful operationally: show it as offline. */
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

			$rows[] = array(
				'pon'          => $pon,
				'onu'          => $onu,
				'rx_power'     => null,
				'status'       => 'offline',
				'status_label' => self::status_label( 'offline' ),
				'mapped'       => true,
				'customer'     => $customer,
			);
		}

		usort(
			$rows,
			function ( $first, $second ) {
				if ( $first['pon'] === $second['pon'] ) {
					return $first['onu'] <=> $second['onu'];
				}
				return $first['pon'] <=> $second['pon'];
			}
		);

		$counts['readings'] = isset( $snapshot['count'] ) ? absint( $snapshot['count'] ) : count( $snapshot['entries'] );
		$counts['total']    = count( $rows );

		foreach ( $rows as $row ) {
			$status = $row['status'];
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
			if ( $row['mapped'] ) {
				$counts['mapped']++;
			} else {
				$counts['unmapped']++;
			}

			$pon = (string) $row['pon'];
			if ( ! isset( $pons[ $pon ] ) ) {
				$pons[ $pon ] = array(
					'pon'      => (int) $row['pon'],
					'total'    => 0,
					'mapped'   => 0,
					'good'     => 0,
					'warning'  => 0,
					'critical' => 0,
					'offline'  => 0,
					'weakest'  => null,
				);
			}

			$pons[ $pon ]['total']++;
			if ( $row['mapped'] ) {
				$pons[ $pon ]['mapped']++;
			}
			if ( isset( $pons[ $pon ][ $status ] ) ) {
				$pons[ $pon ][ $status ]++;
			}
			if ( null !== $row['rx_power'] && ( null === $pons[ $pon ]['weakest'] || $row['rx_power'] < $pons[ $pon ]['weakest'] ) ) {
				$pons[ $pon ]['weakest'] = $row['rx_power'];
			}
		}

		ksort( $pons, SORT_NUMERIC );
		$payload['counts'] = $counts;
		$payload['pons']   = array_values( $pons );
		$payload['onus']   = $rows;

		return $payload;
	}

	public static function ajax_overview() {
		self::authorize();
		$force    = ! empty( $_POST['refresh'] );
		$snapshot = AFC_OLT::get_snapshot( $force );
		wp_send_json_success( self::build_payload( $snapshot ) );
	}
}
