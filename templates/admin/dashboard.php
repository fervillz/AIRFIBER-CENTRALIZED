<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<div class="container-xl py-4">
		<div class="page-header mb-4">
			<div class="row align-items-center">
				<div class="col">
					<div class="page-pretitle"><?php esc_html_e( 'Airfiber - Centralized', 'airfiber-centralized' ); ?></div>
					<h2 class="page-title"><?php esc_html_e( 'Operations Dashboard', 'airfiber-centralized' ); ?></h2>
				</div>
				<div class="col-auto ms-auto">
					<a class="btn btn-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=afc_payment' ) ); ?>">
						<?php esc_html_e( 'Record Payment', 'airfiber-centralized' ); ?>
					</a>
					<a class="btn btn-outline-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=afc_customer' ) ); ?>">
						<?php esc_html_e( 'Add Customer', 'airfiber-centralized' ); ?>
					</a>
				</div>
			</div>
		</div>

		<div class="row row-deck row-cards">
			<?php
			$cards = array(
				array( __( 'Customers', 'airfiber-centralized' ), $counts['customers'], 'bg-primary' ),
				array( __( 'Payments', 'airfiber-centralized' ), $counts['payments'], 'bg-success' ),
				array( __( 'Due Soon', 'airfiber-centralized' ), $counts['due_soon'], 'bg-warning' ),
				array( __( 'Expired', 'airfiber-centralized' ), $counts['expired'], 'bg-danger' ),
			);
			foreach ( $cards as $card ) :
				?>
				<div class="col-sm-6 col-lg-3">
					<div class="card">
						<div class="card-status-top <?php echo esc_attr( $card[2] ); ?>"></div>
						<div class="card-body">
							<div class="subheader"><?php echo esc_html( $card[0] ); ?></div>
							<div class="h1 mb-0"><?php echo esc_html( number_format_i18n( $card[1] ) ); ?></div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="row row-cards mt-1">
			<div class="col-lg-8">
				<div class="card">
					<div class="card-header"><h3 class="card-title"><?php esc_html_e( 'Recent Payments', 'airfiber-centralized' ); ?></h3></div>
					<div class="empty">
						<p class="empty-title"><?php esc_html_e( 'No payments recorded yet', 'airfiber-centralized' ); ?></p>
						<p class="empty-subtitle text-secondary"><?php esc_html_e( 'Recorded cash and GCash payments will appear here.', 'airfiber-centralized' ); ?></p>
					</div>
				</div>
			</div>
			<div class="col-lg-4">
				<div class="card">
					<div class="card-header"><h3 class="card-title"><?php esc_html_e( 'System Status', 'airfiber-centralized' ); ?></h3></div>
					<div class="list-group list-group-flush">
						<div class="list-group-item d-flex justify-content-between"><span><?php esc_html_e( 'Billing scheduler', 'airfiber-centralized' ); ?></span><span class="badge bg-yellow-lt"><?php esc_html_e( 'Not configured', 'airfiber-centralized' ); ?></span></div>
						<div class="list-group-item d-flex justify-content-between"><span><?php esc_html_e( 'MikroTik API', 'airfiber-centralized' ); ?></span><span class="badge bg-secondary-lt"><?php esc_html_e( 'Not connected', 'airfiber-centralized' ); ?></span></div>
						<div class="list-group-item d-flex justify-content-between"><span><?php esc_html_e( 'SMS provider', 'airfiber-centralized' ); ?></span><span class="badge bg-secondary-lt"><?php esc_html_e( 'Not connected', 'airfiber-centralized' ); ?></span></div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

