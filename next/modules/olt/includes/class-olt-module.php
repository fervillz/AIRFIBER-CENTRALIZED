<?php

namespace Airfiber\Next\Modules\Olt;

use Airfiber\Next\Capabilities;
use Airfiber\Next\Connection_Health;
use Airfiber\Next\Connection_Store;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

/**
 * First native OLT slice: cache-first overview plus explicit read-only SNMP test.
 *
 * Opening OLT never contacts a device. Remote work happens only from an explicit
 * connection test (or a future background task) and is recorded as external
 * latency so device/network slowness cannot quarantine the module.
 */
class Olt_Module implements Module_Contract {

	public static function render( $context = array() ) {
		$connections = Connection_Store::for_module( 'olt' );
		$summary     = self::summary( $connections );

		ob_start();
		?>
		<div class="afcn-page-head">
			<div>
				<h1 class="afcn-page-title"><?php esc_html_e( 'OLT', 'airfiber-centralized' ); ?></h1>
				<p class="afcn-page-description"><?php esc_html_e( 'Native BETA OLT monitoring starts read-only. This page renders only stored configuration and cached health; it never polls an OLT just because the page opened.', 'airfiber-centralized' ); ?></p>
			</div>
		</div>

		<div class="afcn-grid">
			<?php self::stat_card( __( 'Native OLTs', 'airfiber-centralized' ), $summary['total'] ); ?>
			<?php self::stat_card( __( 'Online', 'airfiber-centralized' ), $summary['online'] ); ?>
			<?php self::stat_card( __( 'Attention', 'airfiber-centralized' ), $summary['warning'] ); ?>
			<?php self::stat_card( __( 'Not checked', 'airfiber-centralized' ), $summary['unknown'] ); ?>

			<div class="afcn-card afcn-col-12">
				<div class="afcn-card-header">
					<h2><?php esc_html_e( 'Native BETA OLT connections', 'airfiber-centralized' ); ?></h2>
				</div>
				<div class="afcn-card-body">
					<?php if ( empty( $connections ) ) : ?>
						<div class="afcn-notice">
							<strong><?php esc_html_e( 'No native OLT connection yet.', 'airfiber-centralized' ); ?></strong>
							<p class="afcn-page-description"><?php esc_html_e( 'Open Connections → Add Connection and choose OLT (SNMP). Existing Classic OLT cards remain read-only until their BETA replacement is configured.', 'airfiber-centralized' ); ?></p>
						</div>
					<?php else : ?>
						<div class="afcn-table-wrap">
							<table class="afcn-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'OLT', 'airfiber-centralized' ); ?></th>
										<th><?php esc_html_e( 'Endpoint', 'airfiber-centralized' ); ?></th>
										<th><?php esc_html_e( 'Technology', 'airfiber-centralized' ); ?></th>
										<th><?php esc_html_e( 'Status', 'airfiber-centralized' ); ?></th>
										<th><?php esc_html_e( 'Last checked', 'airfiber-centralized' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $connections as $id => $record ) : ?>
										<?php $health = Connection_Health::get( $id ); ?>
										<tr>
											<td><strong><?php echo esc_html( $record['name'] ); ?></strong></td>
											<td><?php echo esc_html( isset( $record['endpoint'] ) ? $record['endpoint'] : '' ); ?></td>
											<td><?php echo esc_html( self::technology( $record ) ); ?></td>
											<td><?php echo UI::badge( self::health_label( $health ), self::health_tone( $health ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
											<td><?php echo esc_html( self::checked_at( $health ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<p class="afcn-page-description"><?php esc_html_e( 'Use the activity icon on the matching card in Connections to run an explicit SNMP connection test.', 'airfiber-centralized' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_chunk( $chunk, $payload = array() ) {
		if ( 'dashboard-summary' !== $chunk ) {
			return '';
		}

		$summary = self::summary( Connection_Store::for_module( 'olt' ) );
		ob_start();
		?>
		<div class="afcn-card">
			<div class="afcn-card-header"><h2><?php esc_html_e( 'OLT', 'airfiber-centralized' ); ?></h2></div>
			<div class="afcn-card-body">
				<div class="afcn-stat"><?php echo esc_html( $summary['total'] ); ?></div>
				<div class="afcn-stat-label"><?php esc_html_e( 'Native OLT connections', 'airfiber-centralized' ); ?></div>
				<p class="afcn-page-description"><?php echo esc_html( sprintf( __( '%1$d online · %2$d need attention · %3$d not checked', 'airfiber-centralized' ), $summary['online'], $summary['warning'], $summary['unknown'] ) ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( 'test-connection' !== $action ) {
			return new \WP_Error( 'afcn_olt_action_unknown', __( 'Unknown OLT action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_manage_connections() ) {
			return new \WP_Error( 'afcn_olt_forbidden', __( 'You cannot test OLT connections.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		$context = self::test_context( $payload );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$started = microtime( true );
		$result  = Olt_SNMP_Client::test( $context['record'], $context['secrets'] );
		$latency = round( ( microtime( true ) - $started ) * 1000, 2 );
		Performance_Monitor::record_external( 'olt', $latency, 'SNMP OLT test' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['latency_ms'] = $latency;
		return $result;
	}

	/**
	 * A Connections form probe supplies a sanitized temporary record and only the
	 * secrets typed in the form. Blank edit secrets fall back to Secret_Store in
	 * Olt_SNMP_Client. Normal card tests continue to load the persisted record.
	 */
	private static function test_context( $payload ) {
		$is_probe = ! empty( $payload['probe'] );
		if ( $is_probe ) {
			$record = isset( $payload['record'] ) && is_array( $payload['record'] ) ? $payload['record'] : array();
			if ( 'olt-snmp' !== ( isset( $record['type'] ) ? sanitize_key( $record['type'] ) : '' ) ) {
				return new \WP_Error( 'afcn_olt_probe_invalid', __( 'The OLT probe configuration is invalid.', 'airfiber-centralized' ), array( 'status' => 400 ) );
			}

			$record['id']     = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
			$record['module'] = 'olt';
			$secrets          = array();
			$provided         = isset( $payload['secrets'] ) && is_array( $payload['secrets'] ) ? $payload['secrets'] : array();
			foreach ( array( 'community', 'auth_passphrase', 'privacy_passphrase' ) as $key ) {
				if ( isset( $provided[ $key ] ) && is_scalar( $provided[ $key ] ) && '' !== (string) $provided[ $key ] ) {
					$secrets[ $key ] = (string) $provided[ $key ];
				}
			}
			return array( 'record' => $record, 'secrets' => $secrets );
		}

		$connection_id = isset( $payload['connection_id'] ) ? sanitize_text_field( $payload['connection_id'] ) : '';
		$record        = Connection_Store::get( $connection_id );
		if ( ! $record || 'olt' !== ( isset( $record['module'] ) ? $record['module'] : '' ) || 'olt-snmp' !== ( isset( $record['type'] ) ? $record['type'] : '' ) ) {
			return new \WP_Error( 'afcn_olt_connection_missing', __( 'The native OLT connection could not be found.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		return array( 'record' => $record, 'secrets' => array() );
	}

	private static function can_manage_connections() {
		return Capabilities::is_super_admin_user()
			|| current_user_can( 'manage_options' )
			|| current_user_can( Capabilities::MANAGE_CONNECTIONS );
	}

	private static function summary( $connections ) {
		$output = array( 'total' => count( $connections ), 'online' => 0, 'warning' => 0, 'unknown' => 0 );
		foreach ( $connections as $id => $record ) {
			$health = Connection_Health::get( $id );
			$state  = isset( $health['state'] ) ? sanitize_key( $health['state'] ) : 'unknown';
			if ( 'online' === $state ) {
				$output['online']++;
			} elseif ( in_array( $state, array( 'warning', 'error', 'offline' ), true ) ) {
				$output['warning']++;
			} else {
				$output['unknown']++;
			}
		}
		return $output;
	}

	private static function stat_card( $label, $value ) {
		?>
		<div class="afcn-card afcn-col-3">
			<div class="afcn-card-body">
				<div class="afcn-stat"><?php echo esc_html( $value ); ?></div>
				<div class="afcn-stat-label"><?php echo esc_html( $label ); ?></div>
			</div>
		</div>
		<?php
	}

	private static function technology( $record ) {
		$config = isset( $record['config'] ) && is_array( $record['config'] ) ? $record['config'] : array();
		return isset( $config['technology'] ) && 'EPON' === strtoupper( (string) $config['technology'] ) ? 'EPON' : 'GPON';
	}

	private static function health_label( $health ) {
		$state = isset( $health['state'] ) ? sanitize_key( $health['state'] ) : 'unknown';
		if ( 'online' === $state ) {
			return __( 'Online', 'airfiber-centralized' );
		}
		if ( in_array( $state, array( 'warning', 'error', 'offline' ), true ) ) {
			return __( 'Needs attention', 'airfiber-centralized' );
		}
		return __( 'Not checked', 'airfiber-centralized' );
	}

	private static function health_tone( $health ) {
		$state = isset( $health['state'] ) ? sanitize_key( $health['state'] ) : 'unknown';
		return 'online' === $state ? 'success' : ( in_array( $state, array( 'warning', 'error', 'offline' ), true ) ? 'warning' : 'info' );
	}

	private static function checked_at( $health ) {
		$timestamp = isset( $health['checked_at'] ) ? absint( $health['checked_at'] ) : 0;
		return $timestamp ? wp_date( 'Y-m-d H:i:s', $timestamp ) : __( 'Never', 'airfiber-centralized' );
	}
}
