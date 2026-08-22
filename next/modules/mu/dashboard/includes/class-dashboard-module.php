<?php

namespace Airfiber\Next\Modules\Dashboard;

use Airfiber\Next\Module_Contract;
use Airfiber\Next\Module_Manager;
use Airfiber\Next\Module_Slots;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\UI;
use Airfiber\Next\User_Manager;

defined( 'ABSPATH' ) || exit;

class Dashboard_Module implements Module_Contract {
	public static function render( $context = array() ) {
		$statuses    = Module_Manager::statuses();
		$total       = count( $statuses );
		$enabled     = 0;
		$quarantined = 0;
		$warnings    = 0;
		foreach ( $statuses as $status ) {
			if ( ! empty( $status['enabled'] ) ) {
				$enabled++;
			}
			$health = isset( $status['health']['status'] ) ? $status['health']['status'] : 'healthy';
			if ( 'quarantined' === $health ) {
				$quarantined++;
			} elseif ( in_array( $health, array( 'warning', 'degraded' ), true ) ) {
				$warnings++;
			}
		}
		$user    = User_Manager::current_user_summary();
		$budgets = Performance_Monitor::budgets();
		ob_start();
		?>
		<div class="afcn-page-head">
			<div>
				<h1 class="afcn-page-title"><?php esc_html_e( 'Airfiber Next', 'airfiber-centralized' ); ?></h1>
				<p class="afcn-page-description"><?php esc_html_e( 'Fast by design. Modules stay dormant until they are actually needed.', 'airfiber-centralized' ); ?></p>
			</div>
			<?php echo UI::badge( AFCN_VERSION, 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<div class="afcn-grid">
			<div class="afcn-card afcn-col-3"><div class="afcn-card-body"><div class="afcn-stat"><?php echo esc_html( $total ); ?></div><div class="afcn-stat-label"><?php esc_html_e( 'Discovered modules', 'airfiber-centralized' ); ?></div></div></div>
			<div class="afcn-card afcn-col-3"><div class="afcn-card-body"><div class="afcn-stat"><?php echo esc_html( $enabled ); ?></div><div class="afcn-stat-label"><?php esc_html_e( 'Enabled modules', 'airfiber-centralized' ); ?></div></div></div>
			<div class="afcn-card afcn-col-3"><div class="afcn-card-body"><div class="afcn-stat"><?php echo esc_html( $warnings ); ?></div><div class="afcn-stat-label"><?php esc_html_e( 'Performance warnings', 'airfiber-centralized' ); ?></div></div></div>
			<div class="afcn-card afcn-col-3"><div class="afcn-card-body"><div class="afcn-stat"><?php echo esc_html( $quarantined ); ?></div><div class="afcn-stat-label"><?php esc_html_e( 'Quarantined modules', 'airfiber-centralized' ); ?></div></div></div>
			<div class="afcn-card afcn-col-8">
				<div class="afcn-card-header"><h2><?php esc_html_e( 'Performance contract', 'airfiber-centralized' ); ?></h2></div>
				<div class="afcn-card-body">
					<p><?php esc_html_e( 'An unopened module should have near-zero runtime cost. PHP, assets, data and external calls are loaded on demand.', 'airfiber-centralized' ); ?></p>
					<div class="afcn-health-metrics">
						<span>Bootstrap ≤ <?php echo esc_html( $budgets['bootstrap_ms'] ); ?> ms</span>
						<span>Render ≤ <?php echo esc_html( $budgets['render_ms'] ); ?> ms</span>
						<span>Action ≤ <?php echo esc_html( $budgets['action_ms'] ); ?> ms</span>
						<span>Queries ≤ <?php echo esc_html( $budgets['db_queries'] ); ?></span>
						<span>Memory ≤ <?php echo esc_html( $budgets['memory_mb'] ); ?> MB</span>
					</div>
				</div>
			</div>
			<div class="afcn-card afcn-col-4">
				<div class="afcn-card-header"><h2><?php esc_html_e( 'Signed in', 'airfiber-centralized' ); ?></h2></div>
				<div class="afcn-card-body">
					<strong><?php echo esc_html( $user['display_name'] ); ?></strong>
					<p class="afcn-page-description"><?php echo esc_html( $user['email'] ); ?></p>
				</div>
			</div>
		</div>
		<?php echo Module_Slots::render( 'dashboard.summary', array( 'grid' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php
		return ob_get_clean();
	}

	public static function handle_action( $action, $payload = array() ) {
		return new \WP_Error( 'afcn_dashboard_action', __( 'Dashboard has no direct actions.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}
}
