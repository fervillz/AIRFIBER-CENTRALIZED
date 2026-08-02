<?php

defined( 'ABSPATH' ) || exit;

$dashboard_layout = isset( $dashboard_layout ) && is_array( $dashboard_layout ) ? $dashboard_layout : array();
?>
<section class="afc-frontend-panel afc-dashboard-panel" data-afc-panel="dashboard" aria-hidden="true" hidden>
	<div class="afc-dashboard-shell" id="afc-main-dashboard">
		<header class="afc-dashboard-header">
			<div>
				<span class="afc-dashboard-kicker"><?php esc_html_e( 'Airfiber operations', 'airfiber-centralized' ); ?></span>
				<h1><?php esc_html_e( 'Good day. Here is what needs attention.', 'airfiber-centralized' ); ?></h1>
				<p><?php esc_html_e( 'Your daily payment, subscriber, scheduler, SMS, and MikroTik information in one place.', 'airfiber-centralized' ); ?></p>
			</div>
			<div class="afc-dashboard-header-actions">
				<span class="afc-dashboard-save-state" data-afc-dashboard-save-state><?php esc_html_e( 'Drag cards to arrange', 'airfiber-centralized' ); ?></span>
				<button type="button" class="afc-dashboard-refresh" data-afc-dashboard-refresh><span aria-hidden="true">↻</span><?php esc_html_e( 'Refresh', 'airfiber-centralized' ); ?></button>
			</div>
		</header>

		<div class="afc-dashboard-router-alert is-loading" data-afc-dashboard-router-alert>
			<span class="afc-dashboard-router-dot"></span>
			<div><strong><?php esc_html_e( 'Checking MikroTik…', 'airfiber-centralized' ); ?></strong><small><?php esc_html_e( 'Reading router and ISP port health.', 'airfiber-centralized' ); ?></small></div>
		</div>

		<div class="afc-dashboard-grid" data-afc-dashboard-grid>
			<?php foreach ( $dashboard_layout as $dashboard_widget_id ) : ?>
				<?php AFC_Main_Dashboard::render_widget( $dashboard_widget_id ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
