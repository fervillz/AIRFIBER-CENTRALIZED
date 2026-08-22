<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Circuit_Breaker {
	const OPTION           = 'afcn_module_circuit_v1';
	const VIOLATION_WINDOW = HOUR_IN_SECONDS;

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

		$message = sprintf(
			/* translators: 1: module, 2: reason */
			__( '%1$s exceeded its performance budget: %2$s', 'airfiber-centralized' ),
			$meta ? $meta['name'] : $module,
			sanitize_text_field( $reason )
		);
		$all[ $module ] = array(
			'status'         => $status,
			'violations'     => $count,
			'last_violation' => time(),
			'last_sample'    => is_array( $sample ) ? $sample : array(),
			'message'        => $message,
		);
		update_option( self::OPTION, $all, false );

		if ( 'quarantined' === $status ) {
			Debug_Logger::error( 'Module automatically quarantined.', array( 'module' => $module, 'reason' => $reason, 'sample' => $sample ) );
		} else {
			Debug_Logger::warning( 'Module performance budget exceeded.', array( 'module' => $module, 'reason' => $reason ) );
		}
	}

	public static function state( $module ) {
		$all = get_option( self::OPTION, array() );
		return isset( $all[ $module ] ) && is_array( $all[ $module ] ) ? $all[ $module ] : array( 'status' => 'healthy', 'violations' => 0, 'message' => '' );
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

	public static function recommendation( $module ) {
		$state = self::state( $module );
		if ( empty( $state['last_sample'] ) ) {
			return '';
		}
		$sample = $state['last_sample'];
		$phase  = isset( $sample['phase'] ) ? $sample['phase'] : '';
		if ( isset( $sample['db_queries'] ) && $sample['db_queries'] > 15 ) {
			return __( 'Reduce queries, cache repeated lookups, or paginate the dataset.', 'airfiber-centralized' );
		}
		if ( isset( $sample['memory_mb'] ) && $sample['memory_mb'] > 8 ) {
			return __( 'Load less data at once and split heavy features into lazy chunks.', 'airfiber-centralized' );
		}
		if ( 'bootstrap' === $phase ) {
			return __( 'Move work out of module bootstrap. Bootstrap should only register lightweight behavior.', 'airfiber-centralized' );
		}
		if ( 'render' === $phase || 'action' === $phase ) {
			return __( 'Use cache-first data, server-side pagination, and smaller on-demand feature chunks.', 'airfiber-centralized' );
		}
		return __( 'Review the module profile and move expensive work behind an on-demand request.', 'airfiber-centralized' );
	}
}
