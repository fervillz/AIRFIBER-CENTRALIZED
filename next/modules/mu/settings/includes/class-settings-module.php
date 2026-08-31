<?php

namespace Airfiber\Next\Modules\Settings;

use Airfiber\Next\Audit_Log;
use Airfiber\Next\Capabilities;
use Airfiber\Next\Debug_Logger;
use Airfiber\Next\Icon;
use Airfiber\Next\Module_Contract;
use Airfiber\Next\Module_Manager;
use Airfiber\Next\Module_Registry;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\Task_Queue;
use Airfiber\Next\UI;

defined( 'ABSPATH' ) || exit;

class Settings_Module implements Module_Contract {

	public static function render( $context = array() ) {
		$status = self::diagnostic_status();

		ob_start();
		?>
		<div class="afcn-settings" data-afcn-settings>
			<div class="afcn-page-head">
				<div>
					<h1 class="afcn-page-title"><?php esc_html_e( 'Core Settings', 'airfiber-centralized' ); ?></h1>
					<p class="afcn-page-description"><?php esc_html_e( 'Open only what you need. Settings details and diagnostics are loaded on demand.', 'airfiber-centralized' ); ?></p>
				</div>
			</div>

			<div class="afcn-settings-grid">
				<?php self::render_card( 'budgets', 'activity', __( 'Performance budgets', 'airfiber-centralized' ), __( 'Timing, memory, query and asset limits.', 'airfiber-centralized' ) ); ?>
				<?php self::render_card( 'platform', 'server', __( 'Platform', 'airfiber-centralized' ), __( 'Version and background queue status.', 'airfiber-centralized' ) ); ?>

				<article class="afcn-card afcn-settings-card" data-afcn-settings-card="performance">
					<button type="button" class="afcn-settings-card-main" data-afcn-settings-panel="performance">
						<span class="afcn-settings-card-icon"><?php echo Icon::svg( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="afcn-settings-card-copy">
							<span class="afcn-settings-card-title"><?php esc_html_e( 'Performance warnings', 'airfiber-centralized' ); ?></span>
							<span class="afcn-settings-card-description" data-afcn-settings-warning-summary><?php echo esc_html( self::warning_summary( $status ) ); ?></span>
						</span>
					</button>
					<div class="afcn-settings-card-actions">
						<?php
						echo UI::indicator_button(
							'wrench',
							__( 'Fix all fixable performance warnings', 'airfiber-centralized' ),
							array(
								array( 'value' => (string) $status['warning_count'], 'variant' => 'warning' ),
								array( 'value' => (string) $status['error_count'], 'variant' => 'danger' ),
							),
							array(
								'class' => 'afcn-settings-fix-all',
								'attrs' => array(
									'data-afcn-open-utility'   => 'tools',
									'data-afcn-utility-action' => 'fix-all',
									'data-afcn-settings-fix-all' => '1',
									'disabled' => empty( $status['fixable_count'] ),
								),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
				</article>

				<?php self::render_card( 'activity', 'list', __( 'Recent admin activity', 'airfiber-centralized' ), __( 'Recent Airfiber configuration changes.', 'airfiber-centralized' ) ); ?>

				<article class="afcn-card afcn-settings-card" data-afcn-settings-card="console">
					<button type="button" class="afcn-settings-card-main" data-afcn-open-utility="tools">
						<span class="afcn-settings-card-icon"><?php echo Icon::svg( 'terminal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="afcn-settings-card-copy">
							<span class="afcn-settings-card-title"><?php esc_html_e( 'Developer Console', 'airfiber-centralized' ); ?></span>
							<span class="afcn-settings-card-description"><?php esc_html_e( 'Lazy diagnostics and safe runtime optimization.', 'airfiber-centralized' ); ?></span>
						</span>
					</button>
					<div class="afcn-settings-card-actions">
						<?php
						echo UI::indicator_button(
							'terminal',
							__( 'Open Developer Console', 'airfiber-centralized' ),
							array( array( 'value' => '', 'variant' => $status['console_level'] ) ),
							array(
								'class' => 'afcn-settings-console-indicator',
								'attrs' => array( 'data-afcn-open-utility' => 'tools' ),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
				</article>
			</div>

			<dialog class="afcn-dialog afcn-dialog-large afcn-settings-dialog" id="afcn-settings-dialog">
				<div class="afcn-dialog-shell">
					<div class="afcn-dialog-header">
						<div>
							<h2 data-afcn-settings-dialog-title><?php esc_html_e( 'Settings', 'airfiber-centralized' ); ?></h2>
							<p data-afcn-settings-dialog-subtitle></p>
						</div>
						<button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"><?php echo Icon::svg( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					</div>
					<div class="afcn-dialog-body" data-afcn-settings-dialog-body></div>
				</div>
			</dialog>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_query( $query, $payload = array() ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return new \WP_Error( 'afcn_forbidden', __( 'You cannot view Core settings.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}

		if ( 'status' === $query ) {
			return self::diagnostic_status();
		}

		if ( 'panel' === $query ) {
			$panel = isset( $payload['panel'] ) ? sanitize_key( $payload['panel'] ) : '';
			return self::panel_response( $panel );
		}

		return new \WP_Error( 'afcn_unknown_query', __( 'Unknown settings query.', 'airfiber-centralized' ), array( 'status' => 404 ) );
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

	private static function render_card( $panel, $icon, $title, $description ) {
		?>
		<article class="afcn-card afcn-settings-card" data-afcn-settings-card="<?php echo esc_attr( $panel ); ?>">
			<button type="button" class="afcn-settings-card-main" data-afcn-settings-panel="<?php echo esc_attr( $panel ); ?>">
				<span class="afcn-settings-card-icon"><?php echo Icon::svg( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="afcn-settings-card-copy">
					<span class="afcn-settings-card-title"><?php echo esc_html( $title ); ?></span>
					<span class="afcn-settings-card-description"><?php echo esc_html( $description ); ?></span>
				</span>
			</button>
		</article>
		<?php
	}

	private static function panel_response( $panel ) {
		$panels = array(
			'budgets' => array(
				'title'    => __( 'Performance budgets', 'airfiber-centralized' ),
				'subtitle' => __( 'Core thresholds used to detect expensive module behavior.', 'airfiber-centralized' ),
				'html'     => self::budgets_panel(),
			),
			'platform' => array(
				'title'    => __( 'Platform', 'airfiber-centralized' ),
				'subtitle' => __( 'Airfiber Next runtime and queue status.', 'airfiber-centralized' ),
				'html'     => self::platform_panel(),
			),
			'performance' => array(
				'title'    => __( 'Performance warnings', 'airfiber-centralized' ),
				'subtitle' => __( 'Only unresolved warnings and errors are shown.', 'airfiber-centralized' ),
				'html'     => self::performance_panel(),
			),
			'activity' => array(
				'title'    => __( 'Recent admin activity', 'airfiber-centralized' ),
				'subtitle' => __( 'Latest Airfiber Next administrative changes.', 'airfiber-centralized' ),
				'html'     => self::activity_panel(),
			),
		);

		if ( ! isset( $panels[ $panel ] ) ) {
			return new \WP_Error( 'afcn_settings_panel_unknown', __( 'Unknown settings panel.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}

		return $panels[ $panel ];
	}

	private static function budgets_panel() {
		$budgets = Performance_Monitor::budgets();
		ob_start();
		?>
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
			<div class="afcn-form-actions"><?php echo UI::button( __( 'Save Budgets', 'airfiber-centralized' ), array( 'type' => 'submit', 'variant' => 'primary' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function platform_panel() {
		$queue = Task_Queue::stats();
		return UI::detail_list(
			array(
				__( 'Airfiber Next', 'airfiber-centralized' ) => AFCN_VERSION,
				__( 'Queue pending', 'airfiber-centralized' ) => (string) $queue['pending'],
				__( 'Queue running', 'airfiber-centralized' ) => (string) $queue['running'],
			)
		);
	}

	private static function performance_panel() {
		$status = self::diagnostic_status();
		if ( empty( $status['events'] ) ) {
			return UI::empty_state(
				__( 'No unresolved performance warnings', 'airfiber-centralized' ),
				__( 'New warnings or errors will appear here automatically.', 'airfiber-centralized' ),
				array( 'icon' => 'check-circle' )
			);
		}

		ob_start();
		?>
		<div class="afcn-settings-warning-list">
			<?php foreach ( $status['events'] as $event ) : ?>
				<div class="afcn-settings-warning-item" data-afcn-performance-warning="<?php echo esc_attr( $event['id'] ); ?>">
					<div class="afcn-settings-warning-copy">
						<div class="afcn-settings-warning-line">
							<?php echo UI::pill( strtoupper( $event['level'] ), 'error' === $event['level'] ? 'danger' : 'warning', array( 'dot' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="afcn-emphasis"><?php echo esc_html( $event['module_name'] ); ?></span>
							<span class="afcn-settings-warning-time"><?php echo esc_html( $event['time'] ); ?></span>
						</div>
						<p><?php echo esc_html( $event['cause'] ); ?></p>
					</div>
					<?php if ( ! empty( $event['fixable'] ) ) : ?>
						<?php
						echo UI::indicator_button(
							'wrench',
							__( 'Fix this warning', 'airfiber-centralized' ),
							array(),
							array(
								'attrs' => array(
									'data-afcn-open-utility'          => 'tools',
									'data-afcn-utility-action'        => 'fix',
									'data-afcn-utility-warning'       => $event['id'],
									'data-afcn-utility-module-target' => $event['module'],
									'data-afcn-utility-phase'         => $event['phase'],
									'data-afcn-utility-cause'         => $event['cause'],
								),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function activity_panel() {
		$audit = Audit_Log::recent( 20 );
		if ( empty( $audit ) ) {
			return UI::empty_state(
				__( 'No administrative activity yet', 'airfiber-centralized' ),
				__( 'Airfiber Next configuration changes will appear here.', 'airfiber-centralized' ),
				array( 'icon' => 'list' )
			);
		}

		$items = array();
		foreach ( $audit as $item ) {
			$items[] = array(
				'label' => isset( $item['action'] ) ? (string) $item['action'] : __( 'Admin action', 'airfiber-centralized' ),
				'meta'  => trim( ( isset( $item['actor'] ) ? $item['actor'] : '' ) . ' · ' . ( isset( $item['subject'] ) ? $item['subject'] : '' ) ),
				'value' => isset( $item['time'] ) ? $item['time'] : '',
			);
		}
		return UI::list_items( $items, array( 'compact' => true ) );
	}

	private static function diagnostic_status() {
		$events   = array();
		$warnings = 0;
		$errors   = 0;
		$can_fix  = self::can_fix();
		$recent   = Debug_Logger::recent();

		foreach ( $recent as $event ) {
			if ( ! is_array( $event ) || ! empty( $event['resolved_at'] ) ) {
				continue;
			}
			$level = isset( $event['level'] ) ? sanitize_key( $event['level'] ) : '';
			if ( ! in_array( $level, array( 'warning', 'error' ), true ) ) {
				continue;
			}

			$module_id = self::event_module_id( $event );
			$phase     = self::event_phase( $event );
			$fixable   = $can_fix && $module_id && 'core' !== $module_id && self::is_fixable_phase( $phase );
			if ( 'error' === $level ) {
				$errors++;
			} else {
				$warnings++;
			}

			$events[] = array(
				'id'          => Debug_Logger::event_id( $event ),
				'level'       => $level,
				'module'      => $module_id,
				'module_name' => self::event_module( $event ),
				'phase'       => $phase,
				'cause'       => self::event_cause( $event ),
				'time'        => self::event_time( $event ),
				'fixable'     => $fixable,
			);
		}

		$latest_level  = ! empty( $recent[0]['level'] ) ? sanitize_key( $recent[0]['level'] ) : 'success';
		$console_level = 'error' === $latest_level ? 'danger' : ( 'warning' === $latest_level ? 'warning' : 'success' );
		$fixable_count = 0;
		foreach ( $events as $event ) {
			if ( ! empty( $event['fixable'] ) ) {
				$fixable_count++;
			}
		}

		return array(
			'warning_count' => $warnings,
			'error_count'   => $errors,
			'total'         => $warnings + $errors,
			'fixable_count' => $fixable_count,
			'console_level' => $console_level,
			'events'        => array_slice( $events, 0, 40 ),
		);
	}

	private static function is_fixable_phase( $phase ) {
		return in_array(
			sanitize_key( (string) $phase ),
			array( 'bootstrap', 'render', 'query', 'action', 'background', 'client', 'transport', 'asset_load', 'navigation', 'external' ),
			true
		);
	}

	private static function warning_summary( $status ) {
		if ( empty( $status['total'] ) ) {
			return __( 'No unresolved warnings or errors.', 'airfiber-centralized' );
		}
		return sprintf(
			__( '%1$d warnings · %2$d errors', 'airfiber-centralized' ),
			(int) $status['warning_count'],
			(int) $status['error_count']
		);
	}

	private static function can_fix() {
		$tools = Module_Registry::get( 'tools' );
		return Capabilities::is_super_admin_user()
			&& $tools
			&& Module_Manager::is_enabled( 'tools', $tools )
			&& Module_Manager::dependencies_met( $tools );
	}

	private static function event_time( $event ) {
		$raw       = isset( $event['time'] ) ? (string) $event['time'] : '';
		$timestamp = $raw ? strtotime( $raw ) : false;
		return false === $timestamp ? $raw : wp_date( 'Y-m-d H:i:s', $timestamp );
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
}
