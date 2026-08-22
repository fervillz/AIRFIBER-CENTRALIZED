<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Performance_Monitor {
	const OPTION_BUDGETS = 'afcn_performance_budgets_v1';

	private static $samples       = array();
	private static $hooked        = false;
	private static $force_flush   = false;
	private static $budget_cache  = null;

	public static function budgets() {
		if ( null !== self::$budget_cache ) {
			return self::$budget_cache;
		}

		$defaults = array(
			'bootstrap_ms' => 30,
			'render_ms'    => 120,
			'query_ms'     => 180,
			'action_ms'    => 250,
			'background_ms'=> 1000,
			'client_ms'    => 160,
			'external_ms'  => 800,
			'memory_mb'    => 8,
			'db_queries'   => 15,
			'css_kb'       => 40,
			'js_kb'        => 100,
		);
		$saved = get_option( self::OPTION_BUDGETS, array() );

		if ( is_array( $saved ) ) {
			foreach ( $defaults as $key => $value ) {
				if ( isset( $saved[ $key ] ) && is_numeric( $saved[ $key ] ) ) {
					$defaults[ $key ] = max( 1, (float) $saved[ $key ] );
				}
			}
		}

		self::$budget_cache = apply_filters( 'afcn_performance_budgets', $defaults );
		return self::$budget_cache;
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
		self::$budget_cache = null;
		Audit_Log::record( 'performance_budgets_updated', 'core' );
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

	public static function record_assets( $module, $assets ) {
		self::ensure_shutdown();

		foreach ( array( 'css', 'js' ) as $type ) {
			$bytes = 0;
			foreach ( isset( $assets[ $type ] ) ? (array) $assets[ $type ] : array() as $asset ) {
				$bytes += isset( $asset['bytes'] ) ? (int) $asset['bytes'] : 0;
			}
			if ( $bytes <= 0 ) {
				continue;
			}
			self::capture(
				array(
					'module'      => sanitize_key( $module ),
					'phase'       => $type,
					'duration_ms' => 0,
					'memory_mb'   => 0,
					'db_queries'  => 0,
					'asset_kb'    => round( $bytes / 1024, 2 ),
					'time'        => time(),
				)
			);
		}
	}

	/**
	 * Return every budget exceeded by a sample. Keeping all reasons makes the
	 * diagnostics useful when, for example, a render is slow because it also
	 * performs too many database queries.
	 */
	public static function violation_reasons( $sample ) {
		$budgets = self::budgets();
		$phase   = isset( $sample['phase'] ) ? sanitize_key( $sample['phase'] ) : '';
		$reasons = array();

		if ( in_array( $phase, array( 'css', 'js' ), true ) ) {
			$asset_key = $phase . '_kb';
			if ( isset( $sample['asset_kb'], $budgets[ $asset_key ] ) && (float) $sample['asset_kb'] > (float) $budgets[ $asset_key ] ) {
				$reasons[] = sprintf(
					'%s assets %.2f KB (budget %.2f KB)',
					strtoupper( $phase ),
					(float) $sample['asset_kb'],
					(float) $budgets[ $asset_key ]
				);
			}
			return $reasons;
		}

		$key = $phase . '_ms';
		if ( isset( $sample['duration_ms'], $budgets[ $key ] ) && (float) $sample['duration_ms'] > (float) $budgets[ $key ] ) {
			$reasons[] = sprintf(
				'%s took %.2f ms (budget %.2f ms)',
				$phase,
				(float) $sample['duration_ms'],
				(float) $budgets[ $key ]
			);
		}
		if ( isset( $sample['memory_mb'], $budgets['memory_mb'] ) && (float) $sample['memory_mb'] > (float) $budgets['memory_mb'] ) {
			$reasons[] = sprintf(
				'memory %.2f MB (budget %.2f MB)',
				(float) $sample['memory_mb'],
				(float) $budgets['memory_mb']
			);
		}
		if ( isset( $sample['db_queries'], $budgets['db_queries'] ) && (int) $sample['db_queries'] > (int) $budgets['db_queries'] ) {
			$reasons[] = sprintf(
				'%d database queries (budget %d)',
				(int) $sample['db_queries'],
				(int) $budgets['db_queries']
			);
		}

		return $reasons;
	}

	private static function capture( $sample ) {
		self::$samples[] = $sample;
		$reasons         = self::violation_reasons( $sample );
		$phase           = isset( $sample['phase'] ) ? $sample['phase'] : '';

		if ( ! empty( $reasons ) && 'core' !== $sample['module'] ) {
			self::$force_flush = true;
			if ( 'external' !== $phase ) {
				Circuit_Breaker::record_violation( $sample['module'], $sample, implode( '; ', $reasons ) );
			}
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
		self::$samples     = array();
		self::$force_flush = false;
	}
}
