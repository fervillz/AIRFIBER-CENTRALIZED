<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Performance_Monitor {
	const OPTION_BUDGETS = 'afcn_performance_budgets_v1';

	private static $samples = array();
	private static $hooked  = false;
	private static $force_flush = false;

	public static function budgets() {
		$defaults = array(
			'bootstrap_ms' => 30,
			'render_ms'    => 120,
			'action_ms'    => 250,
			'client_ms'    => 160,
			'external_ms'  => 800,
			'memory_mb'    => 8,
			'db_queries'   => 15,
		);
		$saved = get_option( self::OPTION_BUDGETS, array() );
		if ( is_array( $saved ) ) {
			foreach ( $defaults as $key => $value ) {
				if ( isset( $saved[ $key ] ) && is_numeric( $saved[ $key ] ) ) {
					$defaults[ $key ] = max( 1, (float) $saved[ $key ] );
				}
			}
		}
		return apply_filters( 'afcn_performance_budgets', $defaults );
	}

	public static function save_budgets( $values ) {
		$allowed = array_keys( self::budgets() );
		$out     = array();
		foreach ( $allowed as $key ) {
			if ( isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ) {
				$out[ $key ] = max( 1, min( 60000, (float) $values[ $key ] ) );
			}
		}
		update_option( self::OPTION_BUDGETS, $out, false );
		return self::budgets();
	}

	public static function start( $module, $phase ) {
		self::ensure_shutdown();
		global $wpdb;
		return array(
			'module'  => sanitize_key( $module ),
			'phase'   => sanitize_key( $phase ),
			'start'   => microtime( true ),
			'memory'  => memory_get_usage( true ),
			'queries' => isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0,
		);
	}

	public static function finish( $token, $extra = array() ) {
		global $wpdb;
		$sample = array(
			'module'      => isset( $token['module'] ) ? $token['module'] : 'core',
			'phase'       => isset( $token['phase'] ) ? $token['phase'] : 'unknown',
			'duration_ms' => round( ( microtime( true ) - $token['start'] ) * 1000, 2 ),
			'memory_mb'   => round( max( 0, memory_get_usage( true ) - $token['memory'] ) / 1048576, 2 ),
			'db_queries'  => max( 0, ( isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0 ) - $token['queries'] ),
			'time'        => time(),
		);
		if ( is_array( $extra ) ) {
			$sample = array_merge( $sample, $extra );
		}
		self::capture( $sample );
		return $sample;
	}

	public static function record_client( $module, $duration_ms ) {
		self::ensure_shutdown();
		self::capture(
			array(
				'module'      => sanitize_key( $module ),
				'phase'       => 'client',
				'duration_ms' => round( max( 0, (float) $duration_ms ), 2 ),
				'memory_mb'   => 0,
				'db_queries'  => 0,
				'time'        => time(),
			)
		);
	}

	public static function record_external( $module, $duration_ms, $label = '' ) {
		self::ensure_shutdown();
		self::capture(
			array(
				'module'      => sanitize_key( $module ),
				'phase'       => 'external',
				'duration_ms' => round( max( 0, (float) $duration_ms ), 2 ),
				'memory_mb'   => 0,
				'db_queries'  => 0,
				'label'       => sanitize_text_field( $label ),
				'time'        => time(),
			)
		);
	}

	private static function capture( $sample ) {
		self::$samples[] = $sample;
		$budgets = self::budgets();
		$phase   = isset( $sample['phase'] ) ? $sample['phase'] : '';
		$key     = $phase . '_ms';
		$reason  = '';
		if ( isset( $budgets[ $key ] ) && $sample['duration_ms'] > $budgets[ $key ] ) {
			$reason = sprintf( '%s took %.2f ms (budget %.2f ms)', $phase, $sample['duration_ms'], $budgets[ $key ] );
		} elseif ( isset( $sample['memory_mb'] ) && $sample['memory_mb'] > $budgets['memory_mb'] ) {
			$reason = sprintf( 'memory %.2f MB (budget %.2f MB)', $sample['memory_mb'], $budgets['memory_mb'] );
		} elseif ( isset( $sample['db_queries'] ) && $sample['db_queries'] > $budgets['db_queries'] ) {
			$reason = sprintf( '%d database queries (budget %d)', $sample['db_queries'], $budgets['db_queries'] );
		}
		if ( '' !== $reason && 'core' !== $sample['module'] ) {
			self::$force_flush = true;
			Circuit_Breaker::record_violation( $sample['module'], $sample, $reason );
		}
	}

	private static function ensure_shutdown() {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 9999 );
	}

	public static function flush() {
		if ( empty( self::$samples ) ) {
			return;
		}
		$sample_this_request = self::$force_flush || wp_rand( 1, 100 ) <= 20;
		if ( $sample_this_request ) {
			Module_Health::record_samples( self::$samples );
		}
		self::$samples = array();
		self::$force_flush = false;
	}
}
