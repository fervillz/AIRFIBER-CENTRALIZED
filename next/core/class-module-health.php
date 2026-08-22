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

	public static function summary( $module ) {
		$module   = sanitize_key( $module );
		$stored   = get_option( self::OPTION, array() );
		$samples  = isset( $stored[ $module ] ) && is_array( $stored[ $module ] ) ? $stored[ $module ] : array();
		$times    = array();
		$external = array();
		$memory   = array();
		$queries  = array();
		$assets   = array();
		foreach ( $samples as $sample ) {
			$phase = isset( $sample['phase'] ) ? $sample['phase'] : '';
			if ( isset( $sample['duration_ms'] ) ) {
				if ( 'external' === $phase ) {
					$external[] = (float) $sample['duration_ms'];
				} elseif ( ! in_array( $phase, array( 'css', 'js' ), true ) ) {
					$times[] = (float) $sample['duration_ms'];
				}
			}
			if ( isset( $sample['memory_mb'] ) ) {
				$memory[] = (float) $sample['memory_mb'];
			}
			if ( isset( $sample['db_queries'] ) ) {
				$queries[] = (int) $sample['db_queries'];
			}
			if ( isset( $sample['asset_kb'] ) ) {
				$assets[] = (float) $sample['asset_kb'];
			}
		}
		$state = Circuit_Breaker::state( $module );
		return array(
			'status'          => isset( $state['status'] ) ? $state['status'] : 'healthy',
			'samples'         => count( $samples ),
			'p50_ms'          => self::percentile( $times, 50 ),
			'p95_ms'          => self::percentile( $times, 95 ),
			'external_p95_ms' => self::percentile( $external, 95 ),
			'max_memory_mb'   => empty( $memory ) ? 0 : round( max( $memory ), 2 ),
			'max_queries'     => empty( $queries ) ? 0 : max( $queries ),
			'max_asset_kb'    => empty( $assets ) ? 0 : round( max( $assets ), 2 ),
			'violations'      => isset( $state['violations'] ) ? (int) $state['violations'] : 0,
			'message'         => isset( $state['message'] ) ? $state['message'] : '',
			'recommendation'  => Circuit_Breaker::recommendation( $module ),
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
