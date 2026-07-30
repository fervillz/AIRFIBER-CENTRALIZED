<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap afc-admin-page">
	<div class="container-fluid py-4">
		<div class="page-header mb-4">
			<div class="row align-items-center">
				<div class="col">
					<div class="page-pretitle"><?php esc_html_e( 'Airfiber - Centralized', 'airfiber-centralized' ); ?></div>
					<h2 class="page-title"><?php esc_html_e( 'MikroTik PPP Users', 'airfiber-centralized' ); ?></h2>
				</div>
				<div class="col-auto ms-auto">
					<button class="btn btn-primary" id="afc-refresh-ppp" type="button"><?php esc_html_e( 'Refresh from MikroTik', 'airfiber-centralized' ); ?></button>
				</div>
			</div>
		</div>

		<div id="afc-ppp-notice" aria-live="polite"></div>

		<div class="card">
			<div class="card-header">
				<div class="row w-100 align-items-center">
					<div class="col-md-6">
						<input class="form-control" id="afc-ppp-search" type="search" placeholder="<?php esc_attr_e( 'Search username, profile, comment, or IP...', 'airfiber-centralized' ); ?>">
					</div>
					<div class="col-md-6 text-end">
						<button class="btn btn-success" id="afc-import-ppp" type="button"><?php esc_html_e( 'Import Selected', 'airfiber-centralized' ); ?></button>
					</div>
				</div>
			</div>
			<div class="table-responsive">
				<table class="table table-vcenter card-table" id="afc-ppp-table">
					<thead>
						<tr>
							<th><input class="form-check-input" id="afc-select-all" type="checkbox"></th>
							<th><?php esc_html_e( 'PPP Username', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Plan', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Installed', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Payment Date', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Method', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Grace', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Connection', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Wi-Fi / Address', 'airfiber-centralized' ); ?></th>
							<th><?php esc_html_e( 'Import', 'airfiber-centralized' ); ?></th>
						</tr>
					</thead>
					<tbody><tr><td colspan="14" class="text-center text-secondary py-5"><?php esc_html_e( 'Loading PPP users...', 'airfiber-centralized' ); ?></td></tr></tbody>
				</table>
			</div>
		</div>
	</div>
</div>
