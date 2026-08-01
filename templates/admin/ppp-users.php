<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap afc-admin-page">
	<div class="container-fluid py-4">
		<div class="page-header mb-3">
			<div class="row align-items-center">
				<div class="col">
					<div class="page-pretitle"><?php esc_html_e( 'Airfiber - Centralized', 'airfiber-centralized' ); ?></div>
					<h2 class="page-title"><?php esc_html_e( 'Billing & PPP Operations', 'airfiber-centralized' ); ?></h2>
					<p class="text-secondary mb-0"><?php esc_html_e( 'Record payments, add or edit PPP accounts, expire accounts, and restore service from one screen.', 'airfiber-centralized' ); ?></p>
				</div>
				<div class="col-auto ms-auto afc-ppp-header-actions">
					<button class="btn btn-primary" id="afc-add-ppp-account" type="button" title="<?php esc_attr_e( 'Add PPP Account', 'airfiber-centralized' ); ?>" aria-label="<?php esc_attr_e( 'Add PPP Account', 'airfiber-centralized' ); ?>">
						<span class="afc-add-ppp-basic-icon" aria-hidden="true">+</span>
						<span class="afc-add-ppp-advanced-label"><?php esc_html_e( 'Add PPP', 'airfiber-centralized' ); ?></span>
					</button>
					<button class="btn btn-outline-primary" id="afc-find-edit-ppp" type="button"><?php esc_html_e( 'Find / Edit PPP', 'airfiber-centralized' ); ?></button>
					<button class="btn btn-outline-primary afc-advanced-only" id="afc-manage-service-areas" type="button"><?php esc_html_e( 'Service Areas', 'airfiber-centralized' ); ?></button>
					<button class="btn btn-outline-secondary" id="afc-refresh-ppp" type="button"><?php esc_html_e( 'Refresh MikroTik', 'airfiber-centralized' ); ?></button>
				</div>
			</div>
		</div>

		<div id="afc-ppp-notice" aria-live="polite"></div>

		<div class="row row-cards mb-3" id="afc-ppp-summary">
			<div class="col-6 col-md-3"><div class="card card-sm"><div class="card-body"><div class="text-secondary"><?php esc_html_e( 'Total Accounts', 'airfiber-centralized' ); ?></div><div class="h2 mb-0" data-summary="total">—</div></div></div></div>
			<div class="col-6 col-md-3"><div class="card card-sm"><div class="card-body"><div class="text-secondary"><?php esc_html_e( 'Online Now', 'airfiber-centralized' ); ?></div><div class="h2 mb-0 text-success" data-summary="online">—</div></div></div></div>
			<div class="col-6 col-md-3"><div class="card card-sm"><div class="card-body"><div class="text-secondary"><?php esc_html_e( 'Expired Profile', 'airfiber-centralized' ); ?></div><div class="h2 mb-0 text-danger" data-summary="expired">—</div></div></div></div>
			<div class="col-6 col-md-3"><div class="card card-sm"><div class="card-body"><div class="text-secondary"><?php esc_html_e( 'Imported', 'airfiber-centralized' ); ?></div><div class="h2 mb-0 text-primary" data-summary="imported">—</div></div></div></div>
		</div>

		<div class="card mb-3 afc-collection-card">
			<div class="card-header">
				<div>
					<h3 class="card-title"><?php esc_html_e( 'Money Collection Print', 'airfiber-centralized' ); ?></h3>
					<p class="card-subtitle"><?php esc_html_e( 'Print all accounts due on or before the cutoff date, grouped by normalized collection area.', 'airfiber-centralized' ); ?></p>
				</div>
			</div>
			<div class="card-body">
				<div class="row g-2 align-items-end">
					<div class="col-sm-6 col-md-4 col-lg-3">
						<label class="form-label" for="afc-due-cutoff"><?php esc_html_e( 'Due until', 'airfiber-centralized' ); ?></label>
						<input class="form-control" id="afc-due-cutoff" type="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
					</div>
					<div class="col-sm-auto">
						<button class="btn btn-primary" id="afc-print-all-due" type="button"><?php esc_html_e( 'Print All Due', 'airfiber-centralized' ); ?></button>
					</div>
					<div class="col-sm-auto">
						<div class="text-secondary"><strong id="afc-due-total">0</strong> <?php esc_html_e( 'account(s) due', 'airfiber-centralized' ); ?></div>
					</div>
				</div>
				<div class="afc-area-summary mt-3" id="afc-area-summary">
					<div class="text-secondary"><?php esc_html_e( 'Collection areas will appear after MikroTik accounts load.', 'airfiber-centralized' ); ?></div>
				</div>
			</div>
		</div>

		<div class="card">
			<div class="card-header afc-operations-toolbar">
				<div class="row g-2 w-100 align-items-center">
					<div class="col-lg-5">
						<input class="form-control" id="afc-ppp-search" type="search" placeholder="<?php esc_attr_e( 'Search account, customer, phone, plan or address...', 'airfiber-centralized' ); ?>">
					</div>
					<div class="col-md-4 col-lg-3">
						<select class="form-select" id="afc-service-filter">
							<option value=""><?php esc_html_e( 'All service states', 'airfiber-centralized' ); ?></option>
							<option value="online"><?php esc_html_e( 'Online now', 'airfiber-centralized' ); ?></option>
							<option value="offline"><?php esc_html_e( 'Offline', 'airfiber-centralized' ); ?></option>
							<option value="expired"><?php esc_html_e( 'Expired profile', 'airfiber-centralized' ); ?></option>
						</select>
					</div>
					<div class="col-md-8 col-lg-4 text-lg-end">
						<button class="btn btn-outline-secondary" id="afc-import-ppp" type="button"><?php esc_html_e( 'Import Selected', 'airfiber-centralized' ); ?></button>
					</div>
				</div>
			</div>
			<div class="table-responsive">
				<table class="table table-vcenter card-table afc-operations-table" id="afc-ppp-table">
					<thead>
						<tr>
							<th><input class="form-check-input" id="afc-select-all" type="checkbox"></th>
							<th><button class="afc-sort" type="button" data-sort="name"><?php esc_html_e( 'Subscriber', 'airfiber-centralized' ); ?> <span class="afc-sort-indicator"></span></button></th>
							<th><button class="afc-sort" type="button" data-sort="payment_date"><?php esc_html_e( 'Last Payment', 'airfiber-centralized' ); ?> <span class="afc-sort-indicator"></span></button></th>
							<th><button class="afc-sort" type="button" data-sort="actual_profile"><?php esc_html_e( 'Service', 'airfiber-centralized' ); ?> <span class="afc-sort-indicator"></span></button></th>
							<th><button class="afc-sort" type="button" data-sort="active"><?php esc_html_e( 'Connection', 'airfiber-centralized' ); ?> <span class="afc-sort-indicator"></span></button></th>
							<th><?php esc_html_e( 'Contact & Location', 'airfiber-centralized' ); ?></th>
							<th class="text-end"><?php esc_html_e( 'Actions', 'airfiber-centralized' ); ?></th>
						</tr>
					</thead>
					<tbody><tr><td colspan="7" class="text-center text-secondary py-5"><?php esc_html_e( 'Loading PPP accounts...', 'airfiber-centralized' ); ?></td></tr></tbody>
				</table>
			</div>
		</div>
	</div>

	<dialog id="afc-payment-dialog" class="afc-dialog">
		<form method="dialog" id="afc-payment-form">
			<div class="afc-dialog-header">
				<div>
					<div class="text-secondary small"><?php esc_html_e( 'Record payment today', 'airfiber-centralized' ); ?></div>
					<h3 class="mb-0" id="afc-payment-customer"></h3>
				</div>
				<button class="btn-close" value="cancel" aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"></button>
			</div>
			<div class="afc-dialog-body">
				<div class="alert alert-info"><?php esc_html_e( 'This updates the MikroTik payment comment and creates a WordPress payment record. If expired, the saved plan will be restored automatically.', 'airfiber-centralized' ); ?></div>
				<div class="mb-3">
					<label class="form-label" for="afc-payment-amount"><?php esc_html_e( 'Amount', 'airfiber-centralized' ); ?></label>
					<div class="input-group"><span class="input-group-text">₱</span><input class="form-control" id="afc-payment-amount" type="number" min="0" step="0.01" required></div>
				</div>
				<div class="mb-3">
					<label class="form-label" for="afc-payment-method"><?php esc_html_e( 'Payment method', 'airfiber-centralized' ); ?></label>
					<select class="form-select" id="afc-payment-method">
						<option value="cash"><?php esc_html_e( 'Cash', 'airfiber-centralized' ); ?></option>
						<option value="gcash"><?php esc_html_e( 'GCash', 'airfiber-centralized' ); ?></option>
					</select>
				</div>
				<div class="text-secondary"><?php printf( esc_html__( 'Payment date: %s', 'airfiber-centralized' ), esc_html( current_time( 'Y-m-d' ) ) ); ?></div>
			</div>
			<div class="afc-dialog-footer">
				<button class="btn btn-link" value="cancel"><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
				<button class="btn btn-success" id="afc-confirm-payment" type="button"><?php esc_html_e( 'Confirm Paid Today', 'airfiber-centralized' ); ?></button>
			</div>
		</form>
	</dialog>

	<?php include AFC_PATH . 'templates/admin/ppp-manager.php'; ?>
</div>
