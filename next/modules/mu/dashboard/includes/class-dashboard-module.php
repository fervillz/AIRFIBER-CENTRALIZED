<?php

namespace Airfiber\Next\Modules\Dashboard;

use Airfiber\Next\Module_Contract;
use Airfiber\Next\Module_Manager;
use Airfiber\Next\Performance_Monitor;
use Airfiber\Next\Task_Queue;

defined( 'ABSPATH' ) || exit;

class Dashboard_Module implements Module_Contract {
	public static function render( $context = array() ) {
		$statuses = Module_Manager::statuses();
		$healthy  = 0;
		$warning  = 0;
		foreach ( $statuses as $status ) {
			if ( 'healthy' === $status['health']['status'] ) {
				$healthy++;
			} else {
				$warning++;
			}
		}
		$queue = Task_Queue::stats();
		$core  = Performance_Monitor::core_summary();
		ob_start();
		?>
		<div class="afcn-page-head">
			<div>
				<h1 class="afcn-page-title"><?php esc_html_e( 'Dashboard', 'airfiber-centralized' ); ?></h1>
				<p class="afcn-page-description"><?php esc_html_e( 'Airfiber Next loads a small Core first, then opens modules only when you ask for them.', 'airfiber-centralized' ); ?></p>
			</div>
			<span class="afcn-badge afcn-badge-beta">BETA</span>
		</div>
		<div class="afcn-grid afcn-grid-stats">
			<div class="afcn-card afcn-stat-card"><span><?php esc_html_e( 'Core p50', 'airfiber-centralized' ); ?></span><strong><?php echo esc_html( $core['p50_ms'] ); ?> ms</strong></div>
			<div class="afcn-card afcn-stat-card"><span><?php esc_html_e( 'Core p95', 'airfiber-centralized' ); ?></span><strong><?php echo esc_html( $core['p95_ms'] ); ?> ms</strong></div>
			<div class="afcn-card afcn-stat-card"><span><?php esc_html_e( 'Healthy modules', 'airfiber-centralized' ); ?></span><strong><?php echo esc_html( $healthy ); ?></strong></div>
			<div class="afcn-card afcn-stat-card"><span><?php esc_html_e( 'Needs attention', 'airfiber-centralized' ); ?></span><strong><?php echo esc_html( $warning ); ?></strong></div>
		</div>
		<div class="afcn-card">
			<div class="afcn-card-header"><div><h2><?php esc_html_e( 'Fast by design', 'airfiber-centralized' ); ?></h2><p><?php esc_html_e( 'Unopened feature modules stay out of the request path. Remote devices and integrations should refresh in the background and render cached data first.', 'airfiber-centralized' ); ?></p></div></div>
			<div class="afcn-grid afcn-grid-stats">
				<div class="afcn-stat-inline"><span><?php esc_html_e( 'Queued jobs', 'airfiber-centralized' ); ?></span><strong><?php echo esc_html( $queue['queued'] ); ?></strong></div>
				<div class="afcn-stat-inline"><span><?php esc_html_e( 'Failed jobs', 'airfiber-centralized' ); ?></span><strong><?php echo esc_html( $queue['failed'] ); ?></strong></div>
				<div class="afcn-stat-inline"><span><?php esc_html_e( 'Running jobs', 'airfiber-centralized' ); ?></span><strong><?php echo esc_html( $queue['running'] ); ?></strong></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_action( $action, $payload = array() ) {
		return new \WP_Error( 'afcn_dashboard_read_only', __( 'Dashboard has no write actions.', 'airfiber-centralized' ), array( 'status' => 400 ) );
	}
}
