<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Circuit_Breaker {
	const OPTION           = 'afcn_module_circuit_v1';
	const VIOLATION_WINDOW = 3600;

	public static function record_violation( $module, $sample, $reason ) {
		$module = sanitize_key( $module );
		$all    = get_option( self::OPTION, array() );
		$state  = isset( $all[ $module ] ) && is_array( $all[ $module ] ) ? $all[ $module ] : array();
		$recent = isset( $state['last_violation'] ) && (int) $state['last_violation'] >= time() - self::VIOLATION_WINDOW;
		$count  = $recent && isset( $state['violations'] ) ? (int) $state['violations'] + 1 : 1;
		$status = 'healthy';

		if ( $count >= 3 ) {
			$status = 'warning';
		}
		if ( $count >= 6 ) {
			$status = 'degraded';
		}

		$meta = Module_Registry::get( $module );
		if ( $count >= 12 && ( ! $meta || empty( $meta['system'] ) ) ) {
			$status = 'quarantined';
		}
		$status = self::more_severe( $status, isset( $state['status'] ) ? $state['status'] : 'healthy' );

		$message = sprintf(
			__( '%1$s exceeded its performance budget: %2$s', 'airfiber-centralized' ),
			$meta ? $meta['name'] : $module,
			sanitize_text_field( $reason )
		);
		$all[ $module ] = array_merge(
			$state,
			array(
				'status'         => $status,
				'violations'     => $count,
				'last_violation' => time(),
				'last_sample'    => is_array( $sample ) ? $sample : array(),
				'message'        => $message,
			)
		);
		update_option( self::OPTION, $all, false );

		$context = array(
			'module' => $module,
			'reason' => $reason,
			'sample' => is_array( $sample ) ? $sample : array(),
		);
		if ( 'quarantined' === $status ) {
			Debug_Logger::error( 'Module automatically quarantined.', $context );
		} else {
			Debug_Logger::warning( 'Module performance budget exceeded.', $context );
		}
	}

	public static function record_failure( $module, $phase, $error ) {
		$module = sanitize_key( $module );
		$phase  = sanitize_key( $phase );
		$all    = get_option( self::OPTION, array() );
		$state  = isset( $all[ $module ] ) && is_array( $all[ $module ] ) ? $all[ $module ] : array();
		$recent = isset( $state['last_failure'] ) && (int) $state['last_failure'] >= time() - self::VIOLATION_WINDOW;
		$count  = $recent && isset( $state['failures'] ) ? (int) $state['failures'] + 1 : 1;
		$meta   = Module_Registry::get( $module );
		$status = 1 === $count ? 'warning' : 'degraded';

		if ( $count >= 3 && ( ! $meta || empty( $meta['system'] ) ) ) {
			$status = 'quarantined';
		}
		$status = self::more_severe( $status, isset( $state['status'] ) ? $state['status'] : 'healthy' );
		$message = sprintf(
			__( '%1$s failed while running %2$s.', 'airfiber-centralized' ),
			$meta ? $meta['name'] : $module,
			$phase
		);
		$all[ $module ] = array_merge(
			$state,
			array(
				'status'       => $status,
				'failures'     => $count,
				'last_failure' => time(),
				'message'      => $message,
			)
		);
		update_option( self::OPTION, $all, false );
		Debug_Logger::error(
			'Module runtime failure.',
			array(
				'module' => $module,
				'phase'  => $phase,
				'type'   => is_object( $error ) ? get_class( $error ) : 'error',
				'error'  => is_object( $error ) && method_exists( $error, 'getMessage' ) ? $error->getMessage() : '',
			)
		);
	}

	public static function state( $module ) {
		$all = get_option( self::OPTION, array() );
		return isset( $all[ $module ] ) && is_array( $all[ $module ] ) ? $all[ $module ] : array(
			'status'     => 'healthy',
			'violations' => 0,
			'failures'   => 0,
			'message'    => '',
		);
	}

	public static function is_quarantined( $module ) {
		$state = self::state( $module );
		return isset( $state['status'] ) && 'quarantined' === $state['status'];
	}

	public static function reset( $module ) {
		$module = sanitize_key( $module );
		$all    = get_option( self::OPTION, array() );
		unset( $all[ $module ] );
		update_option( self::OPTION, $all, false );
		return true;
	}

	/**
	 * Metric schema v1 used "client" for the complete module REST round trip.
	 * Those states are not valid browser-performance signals. Remove them once,
	 * while preserving any module state that also contains runtime failures.
	 */
	public static function reset_legacy_client_states() {
		$all     = get_option( self::OPTION, array() );
		$changed = false;

		if ( ! is_array( $all ) ) {
			return;
		}

		foreach ( $all as $module => $state ) {
			$phase = isset( $state['last_sample']['phase'] ) ? sanitize_key( $state['last_sample']['phase'] ) : '';
			if ( 'client' === $phase && empty( $state['failures'] ) ) {
				unset( $all[ $module ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $all, false );
		}
	}

	public static function recommendation( $module ) {
		$state = self::state( $module );
		if ( ! empty( $state['failures'] ) ) {
			return __( 'This module has runtime failures. Inspect the debug log before re-enabling or resetting its health.', 'airfiber-centralized' );
		}
		if ( empty( $state['last_sample'] ) ) {
			return '';
		}

		$sample  = $state['last_sample'];
		$phase   = isset( $sample['phase'] ) ? $sample['phase'] : '';
		$budgets = Performance_Monitor::budgets();

		if ( isset( $sample['db_queries'], $budgets['db_queries'] ) && $sample['db_queries'] > $budgets['db_queries'] ) {
			return __( 'Reduce queries, cache repeated lookups, or paginate the dataset.', 'airfiber-centralized' );
		}
		if ( isset( $sample['memory_mb'], $budgets['memory_mb'] ) && $sample['memory_mb'] > $budgets['memory_mb'] ) {
			return __( 'Load less data at once and split heavy features into lazy chunks.', 'airfiber-centralized' );
		}
		if ( in_array( $phase, array( 'css', 'js' ), true ) ) {
			return __( 'Split optional assets into smaller lazy chunks and avoid shipping code before its feature is opened.', 'airfiber-centralized' );
		}
		if ( 'bootstrap' === $phase ) {
			return __( 'Move work out of module bootstrap. Bootstrap should only register lightweight behavior.', 'airfiber-centralized' );
		}
		if ( 'client' === $phase ) {
			return __( 'Reduce synchronous DOM work and event wiring during the module swap. Defer non-visible client work until after first paint.', 'airfiber-centralized' );
		}
		if ( 'render' === $phase || 'action' === $phase ) {
			return __( 'Use cache-first data, server-side pagination, and smaller on-demand feature chunks.', 'airfiber-centralized' );
		}
		return __( 'Review the module profile and move expensive work behind an on-demand request.', 'airfiber-centralized' );
	}

	private static function more_severe( $left, $right ) {
		$rank = array( 'healthy' => 0, 'warning' => 1, 'degraded' => 2, 'quarantined' => 3 );
		return ( $rank[ $right ] ?? 0 ) > ( $rank[ $left ] ?? 0 ) ? $right : $left;
	}
}
