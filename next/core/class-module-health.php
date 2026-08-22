<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Module_Health {
	const OPTION = 'afcn_module_metrics_v1';
	const LIMIT  = 30;

	public static function record_samples( $samples ) {
		if ( ! is_array( $samples ) || empty( $samples ) ) {
			return;
		}
		$stored = get_option( self::OPTION, array() );
		foreach ( $samples as $sample ) {
			if ( ! is_array( $sample ) || empty( $sample['module'] ) ) {
				continue;
			}
			$module = sanitize_key( $sample['module'] );
			if ( ! isset( $stored[ $module ] ) || ! is_array( $stored[ $module ] ) ) {
				$stored[ $module ] = array();
			}
			$stored[ $module ][] = $sample;
			$stored[ $module ]   = array_slice( $stored[ $module ], -self::LIMIT );
		}
		update_option( self::OPTION, $stored, false );
	}

	public static function purge_phase_samples( $phase ) {
		$phase  = sanitize_key( $phase );
		$stored = get_option( self::OPTION, array() );
		$changed = false;

		if ( ! is_array( $stored ) ) {
			return;
		}

		foreach ( $stored as $module => $samples ) {
			if ( ! is_array( $samples ) ) {
				continue;
			}
			$filtered = array_values(
				array_filter(
					$samples,
					function ( $sample ) use ( $phase ) {
						return ! is_array( $sample ) || ! isset( $sample['phase'] ) || $phase !== sanitize_key( $sample['phase'] );
					}
				)
			);
			if ( count( $filtered ) !== count( $samples ) ) {
				$stored[ $module ] = $filtered;
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $stored, false );
		}
	}

	public static function summary( $module ) {
		$module     = sanitize_key( $module );
		$stored     = get_option( self::OPTION, array() );
		$samples    = isset( $stored[ $module ] ) && is_array( $stored[ $module ] ) ? $stored[ $module ] : array();
		$runtime    = array();
		$client     = array();
		$transport  = array();
		$asset_load = array();
		$navigation = array();
		$external   = array();
		$memory     = array();
		$queries    = array();
		$assets     = array();

		foreach ( $samples as $sample ) {
			$phase = isset( $sample['phase'] ) ? sanitize_key( $sample['phase'] ) : '';
			if ( isset( $sample['duration_ms'] ) ) {
				$duration = (float) $sample['duration_ms'];
				if ( in_array( $phase, array( 'bootstrap', 'render', 'query', 'action', 'background', 'activate', 'deactivate' ), true ) ) {
					$runtime[] = $duration;
				} elseif ( 'client' === $phase ) {
					$client[] = $duration;
				} elseif ( 'transport' === $phase ) {
					$transport[] = $duration;
				} elseif ( 'asset_load' === $phase ) {
					$asset_load[] = $duration;
				} elseif ( 'navigation' === $phase ) {
					$navigation[] = $duration;
				} elseif ( 'external' === $phase ) {
					$external[] = $duration;
				}
			}

			if ( in_array( $phase, array( 'bootstrap', 'render', 'query', 'action', 'background', 'activate', 'deactivate' ), true ) ) {
				if ( isset( $sample['memory_mb'] ) ) {
					$memory[] = (float) $sample['memory_mb'];
				}
				if ( isset( $sample['db_queries'] ) ) {
					$queries[] = (int) $sample['db_queries'];
				}
			}

			if ( isset( $sample['asset_kb'] ) ) {
				$assets[] = (float) $sample['asset_kb'];
			}
		}

		$state = Circuit_Breaker::state( $module );
		return array(
			'status'             => isset( $state['status'] ) ? $state['status'] : 'healthy',
			'samples'            => count( $samples ),
			'p50_ms'             => self::percentile( $runtime, 50 ),
			'p95_ms'             => self::percentile( $runtime, 95 ),
			'client_p95_ms'      => self::percentile( $client, 95 ),
			'transport_p95_ms'   => self::percentile( $transport, 95 ),
			'asset_load_p95_ms'  => self::percentile( $asset_load, 95 ),
			'navigation_p95_ms'  => self::percentile( $navigation, 95 ),
			'external_p95_ms'    => self::percentile( $external, 95 ),
			'max_memory_mb'      => empty( $memory ) ? 0 : round( max( $memory ), 2 ),
			'max_queries'        => empty( $queries ) ? 0 : max( $queries ),
			'max_asset_kb'       => empty( $assets ) ? 0 : round( max( $assets ), 2 ),
			'violations'         => isset( $state['violations'] ) ? (int) $state['violations'] : 0,
			'failures'           => isset( $state['failures'] ) ? (int) $state['failures'] : 0,
			'message'            => isset( $state['message'] ) ? $state['message'] : '',
			'recommendation'     => Circuit_Breaker::recommendation( $module ),
		);
	}

	private static function percentile( $values, $percentile ) {
		if ( empty( $values ) ) {
			return 0;
		}
		sort( $values, SORT_NUMERIC );
		$index = (int) ceil( ( $percentile / 100 ) * count( $values ) ) - 1;
		$index = max( 0, min( count( $values ) - 1, $index ) );
		return round( (float) $values[ $index ], 2 );
	}
}
