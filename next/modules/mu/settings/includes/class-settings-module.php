<?php

namespace Airfiber\Next\Modules\Settings;

use Airfiber\Next\Audit_Log;
use Airfiber\Next\Capabilities;
use Airfiber\Next\Debug_Logger;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Module_Manager;
use Airfiber\Next\Module_Registry;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\Task_Queue;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

class Settings_Module implements Module_Contract {

	public static function render( $context = array() ) {
		$budgets = Performance_Monitor::budgets();
		$events  = array_slice( Debug_Logger::recent_open(), 0, 12 );
		$audit   = Audit_Log::recent( 12 );
		$queue   = Task_Queue::stats();
		$tools   = Module_Registry::get( 'tools' );
		$can_fix = Capabilities::is_super_admin_user()
			&& $tools
			&& Module_Manager::is_enabled( 'tools', $tools )
			&& Module_Manager::dependencies_met( $tools );

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

			<div class="afcn-card afcn-col-12" data-afcn-performance-warnings>
				<div class="afcn-card-header"><h2><?php esc_html_e( 'Recent performance warnings', 'airfiber-centralized' ); ?></h2></div>
				<div class="afcn-card-body">
					<p class="afcn-page-description" data-afcn-performance-empty<?php echo empty( $events ) ? '' : ' hidden'; ?>><?php esc_html_e( 'No unresolved performance warnings.', 'airfiber-centralized' ); ?></p>
					<div class="afcn-table-wrap" data-afcn-performance-table<?php echo empty( $events ) ? ' hidden' : ''; ?>>
						<table class="afcn-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Time', 'airfiber-centralized' ); ?></th>
									<th><?php esc_html_e( 'Level', 'airfiber-centralized' ); ?></th>
									<th><?php esc_html_e( 'Module', 'airfiber-centralized' ); ?></th>
									<th><?php esc_html_e( 'Cause', 'airfiber-centralized' ); ?></th>
									<?php if ( $can_fix ) : ?><th><?php esc_html_e( 'Action', 'airfiber-centralized' ); ?></th><?php endif; ?>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $events as $event ) : ?>
								<?php
								$event_id  = Debug_Logger::event_id( $event );
								$module_id = self::event_module_id( $event );
								$cause     = self::event_cause( $event );
								$phase     = self::event_phase( $event );
								?>
								<tr data-afcn-performance-warning="<?php echo esc_attr( $event_id ); ?>">
									<td><?php echo esc_html( self::event_time( $event ) ); ?></td>
									<td><?php echo UI::badge( strtoupper( $event['level'] ), 'error' === $event['level'] ? 'danger' : 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td><strong><?php echo esc_html( self::event_module( $event ) ); ?></strong></td>
									<td><?php echo esc_html( $cause ); ?></td>
									<?php if ( $can_fix ) : ?>
										<td>
											<?php if ( $module_id && 'core' !== $module_id ) : ?>
												<button type="button" class="afcn-button afcn-button-secondary afcn-button-small" data-afcn-open-utility="tools" data-afcn-utility-action="fix" data-afcn-utility-warning="<?php echo esc_attr( $event_id ); ?>" data-afcn-utility-module-target="<?php echo esc_attr( $module_id ); ?>" data-afcn-utility-phase="<?php echo esc_attr( $phase ); ?>" data-afcn-utility-cause="<?php echo esc_attr( $cause ); ?>"><?php esc_html_e( 'FIX', 'airfiber-centralized' ); ?></button>
											<?php else : ?>
												<span class="afcn-page-description">—</span>
											<?php endif; ?>
										</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
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
		$raw       = isset( $event['time'] ) ? (string) $event['time'] : '';
		$timestamp = $raw ? strtotime( $raw ) : false;
		if ( false === $timestamp ) {
			return $raw;
		}
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	private static function event_context( $event ) {
		return isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array();
	}

	private static function event_module_id( $event ) {
		$context = self::event_context( $event );
		return isset( $context['module'] ) ? sanitize_key( $context['module'] ) : '';
	}

	private static function event_phase( $event ) {
		$context = self::event_context( $event );
		return isset( $context['phase'] ) ? sanitize_key( $context['phase'] ) : '';
	}

	private static function event_module( $event ) {
		$id = self::event_module_id( $event );
		if ( ! $id ) {
			return 'Core';
		}
		$module = Module_Registry::get( $id );
		return $module && ! empty( $module['name'] ) ? $module['name'] : $id;
	}

	private static function event_cause( $event ) {
		$context = self::event_context( $event );
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
