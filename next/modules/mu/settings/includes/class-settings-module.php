<?php

namespace Airfiber\Next\Modules\Settings;

use Airfiber\Next\Audit_Log;
use Airfiber\Next\Capabilities;
use Airfiber\Next\Debug_Logger;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Module_Registry;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\Task_Queue;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

class Settings_Module implements Module_Contract {

	public static function render( $context = array() ) {
		$budgets = Performance_Monitor::budgets();
		$events  = array_slice( Debug_Logger::recent(), 0, 12 );
		$audit   = Audit_Log::recent( 12 );
		$queue   = Task_Queue::stats();

		ob_start();
		?>
		<div class="afcn-page-head">
			<div>
				<h1 class="afcn-page-title"><?php esc_html_e( 'Core Settings', 'airfiber-centralized' ); ?></h1>
				<p class="afcn-page-description"><?php esc_html_e( 'The defaults are intentionally strict. Repeated module-code failures or budget violations are isolated instead of slowing the whole app.', 'airfiber-centralized' ); ?></p>
			</div>
		</div>

		<div class="afcn-grid">
			<div class="afcn-card afcn-col-8">
				<div class="afcn-card-header"><h2><?php esc_html_e( 'Performance budgets', 'airfiber-centralized' ); ?></h2></div>
				<div class="afcn-card-body">
					<form data-afcn-action="save-performance" data-afcn-module="settings">
						<div class="afcn-form-grid">
							<?php
							foreach ( $budgets as $key => $value ) {
								echo UI::field(
									$key,
									ucwords( str_replace( '_', ' ', $key ) ),
									array(
										'type'     => 'number',
										'value'    => $value,
										'required' => true,
									)
								); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</div>
						<div class="afcn-form-actions"><button type="submit" class="afcn-button afcn-button-primary"><?php esc_html_e( 'Save Budgets', 'airfiber-centralized' ); ?></button></div>
					</form>
				</div>
			</div>

			<div class="afcn-card afcn-col-4">
				<div class="afcn-card-header"><h2><?php esc_html_e( 'Platform', 'airfiber-centralized' ); ?></h2></div>
				<div class="afcn-card-body">
					<p><strong>Airfiber Next</strong><br><?php echo esc_html( AFCN_VERSION ); ?></p>
					<p><?php echo esc_html( sprintf( __( 'Background queue: %1$d pending, %2$d running.', 'airfiber-centralized' ), $queue['pending'], $queue['running'] ) ); ?></p>
				</div>
			</div>

			<div class="afcn-card afcn-col-12">
				<div class="afcn-card-header"><h2><?php esc_html_e( 'Recent performance warnings', 'airfiber-centralized' ); ?></h2></div>
				<div class="afcn-card-body">
					<?php if ( empty( $events ) ) : ?>
						<p class="afcn-page-description"><?php esc_html_e( 'No recent warnings or errors.', 'airfiber-centralized' ); ?></p>
					<?php else : ?>
						<div class="afcn-table-wrap">
							<table class="afcn-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Time', 'airfiber-centralized' ); ?></th>
										<th><?php esc_html_e( 'Level', 'airfiber-centralized' ); ?></th>
										<th><?php esc_html_e( 'Module', 'airfiber-centralized' ); ?></th>
										<th><?php esc_html_e( 'Cause', 'airfiber-centralized' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $events as $event ) : ?>
									<tr>
										<td><?php echo esc_html( self::event_time( $event ) ); ?></td>
										<td><?php echo UI::badge( strtoupper( $event['level'] ), 'error' === $event['level'] ? 'danger' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										<td><strong><?php echo esc_html( self::event_module( $event ) ); ?></strong></td>
										<td><?php echo esc_html( self::event_cause( $event ) ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="afcn-card afcn-col-12">
				<div class="afcn-card-header"><h2><?php esc_html_e( 'Recent admin activity', 'airfiber-centralized' ); ?></h2></div>
				<div class="afcn-card-body">
					<?php if ( empty( $audit ) ) : ?>
						<p class="afcn-page-description"><?php esc_html_e( 'No Airfiber Next administrative changes recorded yet.', 'airfiber-centralized' ); ?></p>
					<?php else : ?>
						<div class="afcn-table-wrap">
							<table class="afcn-table">
								<thead><tr><th><?php esc_html_e( 'Time', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Actor', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Action', 'airfiber-centralized' ); ?></th><th><?php esc_html_e( 'Subject', 'airfiber-centralized' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $audit as $item ) : ?>
									<tr><td><?php echo esc_html( $item['time'] ); ?></td><td><?php echo esc_html( $item['actor'] ); ?></td><td><?php echo esc_html( $item['action'] ); ?></td><td><?php echo esc_html( $item['subject'] ); ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function event_time( $event ) {
		$raw = isset( $event['time'] ) ? (string) $event['time'] : '';
		$timestamp = $raw ? strtotime( $raw ) : false;
		if ( false === $timestamp ) {
			return $raw;
		}
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	private static function event_module( $event ) {
		$context = isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array();
		$id      = isset( $context['module'] ) ? sanitize_key( $context['module'] ) : '';
		if ( ! $id ) {
			return 'Core';
		}
		$module = Module_Registry::get( $id );
		return $module && ! empty( $module['name'] ) ? $module['name'] : $id;
	}

	private static function event_cause( $event ) {
		$context = isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array();
		if ( ! empty( $context['reason'] ) ) {
			return (string) $context['reason'];
		}
		if ( ! empty( $context['error'] ) ) {
			return (string) $context['error'];
		}
		if ( ! empty( $context['phase'] ) ) {
			return sprintf( '%s during %s.', isset( $event['message'] ) ? $event['message'] : 'Runtime failure', $context['phase'] );
		}
		return isset( $event['message'] ) ? (string) $event['message'] : '';
	}

	public static function handle_action( $action, $payload = array() ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return new \WP_Error( 'afcn_forbidden', __( 'You cannot change Core settings.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}
		if ( 'save-performance' === $action ) {
			$budgets = Performance_Monitor::save_budgets( $payload );
			return array(
				'budgets' => $budgets,
				'message' => __( 'Performance budgets saved.', 'airfiber-centralized' ),
			);
		}
		return new \WP_Error( 'afcn_unknown_action', __( 'Unknown settings action.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}
}
