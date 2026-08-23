<?php

namespace Airfiber\Next\Modules\Tools;

use Airfiber\Next\Assets;
use Airfiber\Next\Module_Health;
use Airfiber\Next\Module_Manager;
use Airfiber\Next\Module_Registry;
use Airfiber\Next\Performance_Monitor;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only diagnostics plus deliberately conservative runtime optimizations.
 *
 * This class never rewrites PHP/JS/CSS or changes database structure. If a
 * warning needs code restructuring, it reports a recommendation for a developer
 * instead of self-modifying a live installation.
 */
class Performance_Doctor {

	public static function diagnose( $module_id, $phase = '', $cause = '' ) {
		$module_id = sanitize_key( $module_id );
		$phase     = sanitize_key( $phase );
		$cause     = sanitize_text_field( $cause );
		$meta      = Module_Registry::get( $module_id );

		if ( ! $meta ) {
			return new \WP_Error( 'afcn_tools_module_missing', __( 'The target module could not be found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		$health = Module_Health::summary( $module_id );
		$assets = Assets::module_manifest( $meta );
		$budget = Performance_Monitor::budgets();
		$totals = self::asset_totals( $assets );
		$plan   = array(
			__( 'Read the latest module health and rolling p95 metrics.', 'airfiber-centralized' ),
			__( 'Verify the module lazy-asset manifest and asset sizes.', 'airfiber-centralized' ),
			__( 'Prime the compiled registry and run one controlled server-side module render.', 'airfiber-centralized' ),
			__( 'Retest the REST request from the browser without changing the current page.', 'airfiber-centralized' ),
		);

		return array(
			'module'          => $module_id,
			'name'            => isset( $meta['name'] ) ? $meta['name'] : $module_id,
			'phase'           => $phase,
			'cause'           => $cause,
			'health'          => $health,
			'assets'          => $totals,
			'budget'          => self::phase_budget( $phase, $budget ),
			'plan'            => $plan,
			'recommendations' => self::recommendations( $phase, $health, $totals, $budget ),
			'scope'           => __( 'Automatic fixes are limited to safe runtime checks and warm-up. Source-code restructuring is reported as a recommendation, never rewritten on a live site.', 'airfiber-centralized' ),
		);
	}

	public static function optimize( $module_id ) {
		$module_id = sanitize_key( $module_id );
		if ( ! $module_id || 'tools' === $module_id ) {
			return new \WP_Error( 'afcn_tools_invalid_target', __( 'Choose a normal Airfiber module to optimize.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$meta = Module_Registry::get( $module_id );
		if ( ! $meta ) {
			return new \WP_Error( 'afcn_tools_module_missing', __( 'The target module could not be found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		$steps = array();

		$started = microtime( true );
		Module_Registry::all();
		$steps[] = array(
			'level'   => 'success',
			'message' => sprintf( __( 'Compiled module registry is warm (%s ms).', 'airfiber-centralized' ), self::elapsed( $started ) ),
		);

		$started = microtime( true );
		$assets  = Assets::module_manifest( $meta );
		$totals  = self::asset_totals( $assets );
		$steps[] = array(
			'level'   => 'success',
			'message' => sprintf(
				__( 'Lazy assets verified: %1$s CSS / %2$s JS (%3$s ms).', 'airfiber-centralized' ),
				$totals['css_kb'] . ' KB',
				$totals['js_kb'] . ' KB',
				self::elapsed( $started )
			),
		);

		$started   = microtime( true );
		$render    = Module_Manager::render( $module_id );
		$render_ms = self::elapsed( $started );
		if ( is_wp_error( $render ) ) {
			$steps[] = array(
				'level'   => 'error',
				'message' => sprintf( __( 'Controlled module render failed: %s', 'airfiber-centralized' ), $render->get_error_message() ),
			);
		} else {
			$steps[] = array(
				'level'   => 'success',
				'message' => sprintf( __( 'Controlled server render completed in %s ms.', 'airfiber-centralized' ), $render_ms ),
			);
		}

		return array(
			'module'          => $module_id,
			'render_ms'       => $render_ms,
			'steps'           => $steps,
			'health'          => Module_Health::summary( $module_id ),
			'recommendations' => self::recommendations( '', Module_Health::summary( $module_id ), $totals, Performance_Monitor::budgets() ),
		);
	}

	private static function asset_totals( $assets ) {
		$out = array( 'css_kb' => 0, 'js_kb' => 0, 'css_files' => 0, 'js_files' => 0 );
		foreach ( array( 'css', 'js' ) as $type ) {
			$bytes = 0;
			$files = isset( $assets[ $type ] ) ? (array) $assets[ $type ] : array();
			foreach ( $files as $asset ) {
				$bytes += isset( $asset['bytes'] ) ? (int) $asset['bytes'] : 0;
			}
			$out[ $type . '_kb' ]    = round( $bytes / 1024, 2 );
			$out[ $type . '_files' ] = count( $files );
		}
		return $out;
	}

	private static function phase_budget( $phase, $budgets ) {
		$key = sanitize_key( $phase ) . '_ms';
		return isset( $budgets[ $key ] ) ? (float) $budgets[ $key ] : 0;
	}

	private static function recommendations( $phase, $health, $assets, $budgets ) {
		$out   = array();
		$phase = sanitize_key( $phase );

		if ( in_array( $phase, array( 'transport', 'navigation' ), true ) ) {
			$out[] = __( 'Compare the controlled server render with the browser REST retest. If render is fast but REST remains slow, inspect web-server/PHP/network delivery before restructuring module code.', 'airfiber-centralized' );
		}
		if ( 'client' === $phase ) {
			$out[] = __( 'Reduce DOM work, split large views into lazy chunks, and avoid wiring large node trees before they are visible.', 'airfiber-centralized' );
		}
		if ( 'asset_load' === $phase || (float) $assets['css_kb'] > (float) $budgets['css_kb'] || (float) $assets['js_kb'] > (float) $budgets['js_kb'] ) {
			$out[] = __( 'Split optional CSS/JavaScript by feature and keep only the first-view assets in the module manifest.', 'airfiber-centralized' );
		}
		if ( (int) $health['max_queries'] > (int) $budgets['db_queries'] ) {
			$out[] = __( 'Reduce database queries with cache-first reads, narrower queries, or server-side pagination.', 'airfiber-centralized' );
		}
		if ( (float) $health['max_memory_mb'] > (float) $budgets['memory_mb'] ) {
			$out[] = __( 'Avoid building large result sets in memory; fetch identifiers or bounded pages and lazy-load details.', 'airfiber-centralized' );
		}
		if ( empty( $out ) ) {
			$out[] = __( 'No structural issue is proven yet. Collect another navigation sample after the safe warm-up before changing code.', 'airfiber-centralized' );
		}

		return array_values( array_unique( $out ) );
	}

	private static function elapsed( $started ) {
		return number_format( ( microtime( true ) - $started ) * 1000, 2, '.', '' );
	}
}
