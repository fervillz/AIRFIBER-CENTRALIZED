<?php

defined( 'ABSPATH' ) || exit;
?>
<dialog id="afc-ppp-create-dialog" class="afc-dialog afc-ppp-manager-dialog">
	<form id="afc-ppp-create-form">
		<div class="afc-dialog-header">
			<div>
				<div class="text-secondary small"><?php esc_html_e( 'New internet customer', 'airfiber-centralized' ); ?></div>
				<h3 class="mb-0"><?php esc_html_e( 'Add PPP Account', 'airfiber-centralized' ); ?></h3>
			</div>
			<button class="btn-close" type="button" data-afc-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"></button>
		</div>

		<div class="afc-dialog-body">
			<div id="afc-ppp-create-notice" aria-live="polite"></div>

			<div class="afc-basic-only afc-ppp-wizard">
				<div class="afc-ppp-wizard-progress" aria-label="<?php esc_attr_e( 'Add PPP steps', 'airfiber-centralized' ); ?>">
					<span class="is-active" data-afc-create-step-dot="1">1</span>
					<span data-afc-create-step-dot="2">2</span>
					<span data-afc-create-step-dot="3">3</span>
				</div>

				<section class="afc-ppp-wizard-step is-active" data-afc-create-step="1">
					<h4><?php esc_html_e( 'Who is the customer?', 'airfiber-centralized' ); ?></h4>
					<label class="form-label" for="afc-new-ppp-name"><?php esc_html_e( 'Full name', 'airfiber-centralized' ); ?></label>
					<input class="form-control form-control-lg" id="afc-new-ppp-name" name="customer_name" type="text" autocomplete="name" required>

					<label class="form-label mt-3" for="afc-new-ppp-phone"><?php esc_html_e( 'CP number', 'airfiber-centralized' ); ?></label>
					<input class="form-control form-control-lg" id="afc-new-ppp-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required>

					<label class="form-label mt-3" for="afc-new-ppp-address"><?php esc_html_e( 'Address', 'airfiber-centralized' ); ?></label>
					<select class="form-select afc-address-select" id="afc-new-ppp-address" name="address" data-placeholder="<?php esc_attr_e( 'Type zone, barangay, or complete address', 'airfiber-centralized' ); ?>"><option value=""></option></select>
					<p class="text-secondary small mt-2 mb-0"><?php esc_html_e( 'Choose a suggestion or type a complete custom address.', 'airfiber-centralized' ); ?></p>
				</section>

				<section class="afc-ppp-wizard-step" data-afc-create-step="2">
					<h4><?php esc_html_e( 'Choose the plan', 'airfiber-centralized' ); ?></h4>
					<div id="afc-new-ppp-plan-cards" class="afc-ppp-plan-cards"></div>
				</section>

				<section class="afc-ppp-wizard-step" data-afc-create-step="3">
					<h4><?php esc_html_e( 'Check and create', 'airfiber-centralized' ); ?></h4>
					<div class="afc-ppp-review-card">
						<strong id="afc-new-ppp-review-name"></strong>
						<span id="afc-new-ppp-review-phone"></span>
						<span id="afc-new-ppp-review-address"></span>
						<span id="afc-new-ppp-review-plan"></span>
						<code id="afc-new-ppp-review-username"></code>
					</div>
					<p class="text-secondary small mt-3 mb-0"><?php esc_html_e( 'Installed date will be today. Grace is 3 days. Payment date, next due, cutoff scheduler, and the one-day-before SMS date are created automatically.', 'airfiber-centralized' ); ?></p>
				</section>
			</div>

			<div class="afc-advanced-only afc-ppp-advanced-grid">
				<div><label class="form-label" for="afc-new-ppp-name-advanced"><?php esc_html_e( 'Full name', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-new-ppp-name-advanced" type="text"></div>
				<div><label class="form-label" for="afc-new-ppp-phone-advanced"><?php esc_html_e( 'CP number', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-new-ppp-phone-advanced" type="tel"></div>
				<div class="afc-span-2"><label class="form-label" for="afc-new-ppp-address-advanced"><?php esc_html_e( 'Address', 'airfiber-centralized' ); ?></label><select class="form-select afc-address-select" id="afc-new-ppp-address-advanced" data-placeholder="<?php esc_attr_e( 'Type zone, barangay, or complete address', 'airfiber-centralized' ); ?>"><option value=""></option></select></div>
				<div><label class="form-label" for="afc-new-ppp-profile-advanced"><?php esc_html_e( 'MikroTik profile / plan', 'airfiber-centralized' ); ?></label><select class="form-select" id="afc-new-ppp-profile-advanced"></select></div>
				<div><label class="form-label" for="afc-new-ppp-installed-advanced"><?php esc_html_e( 'Installed date', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-new-ppp-installed-advanced" name="installed" type="date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></div>
				<div class="afc-span-2"><label class="form-label" for="afc-new-ppp-username-advanced"><?php esc_html_e( 'PPP username', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-new-ppp-username-advanced" name="username" type="text" placeholder="<?php esc_attr_e( 'Leave blank to generate Name_Day_Plan', 'airfiber-centralized' ); ?>"></div>
			</div>

			<div id="afc-ppp-create-success" class="afc-ppp-success" hidden>
				<strong><?php esc_html_e( 'PPP account created', 'airfiber-centralized' ); ?></strong>
				<code id="afc-created-ppp-username"></code>
				<div class="d-flex gap-2 flex-wrap">
					<button class="btn btn-outline-primary btn-sm" id="afc-copy-installer-login" type="button"><?php esc_html_e( 'Copy installer login', 'airfiber-centralized' ); ?></button>
					<button class="btn btn-success btn-sm" type="button" data-afc-dialog-close><?php esc_html_e( 'Done', 'airfiber-centralized' ); ?></button>
				</div>
			</div>
		</div>

		<div class="afc-dialog-footer">
			<button class="btn btn-link" type="button" data-afc-dialog-close><?php esc_html_e( 'Close', 'airfiber-centralized' ); ?></button>
			<div class="afc-basic-only afc-ppp-wizard-actions">
				<button class="btn btn-outline-secondary" id="afc-ppp-create-back" type="button" hidden><?php esc_html_e( 'Back', 'airfiber-centralized' ); ?></button>
				<button class="btn btn-primary" id="afc-ppp-create-next" type="button"><?php esc_html_e( 'Next', 'airfiber-centralized' ); ?></button>
				<button class="btn btn-success" id="afc-ppp-create-submit" type="button" hidden><?php esc_html_e( 'Create PPP Account', 'airfiber-centralized' ); ?></button>
			</div>
			<button class="btn btn-success afc-advanced-only" id="afc-ppp-create-submit-advanced" type="button"><?php esc_html_e( 'Create PPP Account', 'airfiber-centralized' ); ?></button>
		</div>
	</form>
</dialog>

<dialog id="afc-ppp-manage-dialog" class="afc-dialog afc-ppp-manager-dialog afc-ppp-manage-dialog">
	<form id="afc-ppp-manage-form">
		<div class="afc-dialog-header">
			<div>
				<div class="text-secondary small"><?php esc_html_e( 'Search and update MikroTik PPP', 'airfiber-centralized' ); ?></div>
				<h3 class="mb-0"><?php esc_html_e( 'Find / Edit PPP', 'airfiber-centralized' ); ?></h3>
			</div>
			<button class="btn-close" type="button" data-afc-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"></button>
		</div>

		<div class="afc-dialog-body afc-ppp-manage-body">
			<aside class="afc-ppp-manager-list-pane">
				<input class="form-control" id="afc-ppp-manager-search" type="search" placeholder="<?php esc_attr_e( 'Type name, PPP, CP or address...', 'airfiber-centralized' ); ?>">
				<div id="afc-ppp-manager-list" class="afc-ppp-manager-list"></div>
			</aside>

			<section class="afc-ppp-manager-editor-pane">
				<div id="afc-ppp-manager-empty" class="afc-ppp-manager-empty"><?php esc_html_e( 'Choose a customer from the left.', 'airfiber-centralized' ); ?></div>
				<div id="afc-ppp-manager-editor" hidden>
					<div id="afc-ppp-manager-notice" aria-live="polite"></div>
					<input id="afc-edit-ppp-id" type="hidden">

					<div class="afc-ppp-editor-heading">
						<div><strong id="afc-edit-heading-name"></strong><small id="afc-edit-heading-username"></small></div>
						<span class="badge bg-blue-lt" id="afc-edit-heading-profile"></span>
					</div>

					<div class="afc-ppp-basic-fields">
						<div><label class="form-label" for="afc-edit-ppp-name"><?php esc_html_e( 'Full name', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-name" type="text" required></div>
						<div><label class="form-label" for="afc-edit-ppp-phone"><?php esc_html_e( 'CP number', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-phone" type="tel" required></div>
						<div class="afc-span-2"><label class="form-label" for="afc-edit-ppp-address"><?php esc_html_e( 'Address', 'airfiber-centralized' ); ?></label><select class="form-select afc-address-select" id="afc-edit-ppp-address" data-placeholder="<?php esc_attr_e( 'Type zone, barangay, or complete address', 'airfiber-centralized' ); ?>"><option value=""></option></select></div>
						<div class="afc-span-2"><label class="form-label" for="afc-edit-ppp-profile"><?php esc_html_e( 'Plan', 'airfiber-centralized' ); ?></label><select class="form-select" id="afc-edit-ppp-profile" required></select></div>
					</div>

					<details class="afc-basic-only afc-install-date-change">
						<summary><?php esc_html_e( 'Change installation date', 'airfiber-centralized' ); ?></summary>
						<label class="form-label mt-2" for="afc-edit-ppp-installed-basic"><?php esc_html_e( 'Actual installation date', 'airfiber-centralized' ); ?></label>
						<input class="form-control" id="afc-edit-ppp-installed-basic" type="date">
						<p class="text-secondary small mb-0 mt-2"><?php esc_html_e( 'This also changes the PPP username, payment date, next due, cutoff scheduler, and SMS reminder date.', 'airfiber-centralized' ); ?></p>
					</details>

					<div class="afc-advanced-only afc-ppp-advanced-grid afc-ppp-advanced-editor">
						<div><label class="form-label" for="afc-edit-ppp-username"><?php esc_html_e( 'PPP username', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-username" type="text"></div>
						<div><label class="form-label" for="afc-edit-ppp-installed"><?php esc_html_e( 'Installed date', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-installed" type="date"></div>
						<div><label class="form-label" for="afc-edit-ppp-grace"><?php esc_html_e( 'Grace days', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-grace" type="number" min="0" max="30"></div>
						<div><label class="form-label" for="afc-edit-ppp-billing-day"><?php esc_html_e( 'Billing day', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-billing-day" type="number" min="1" max="31"></div>
						<div><label class="form-label" for="afc-edit-ppp-payment-date"><?php esc_html_e( 'Payment date', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-payment-date" type="date"></div>
						<div><label class="form-label" for="afc-edit-ppp-paid-through"><?php esc_html_e( 'Paid through', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-paid-through" type="date"></div>
						<div><label class="form-label" for="afc-edit-ppp-next-due"><?php esc_html_e( 'Next due', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-next-due" type="date"></div>
						<div><label class="form-label" for="afc-edit-ppp-cutoff"><?php esc_html_e( 'Cutoff date', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-cutoff" type="date"></div>
						<div><label class="form-label" for="afc-edit-ppp-reminder"><?php esc_html_e( 'SMS reminder date', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-reminder" type="date"></div>
						<div><label class="form-label" for="afc-edit-ppp-amount"><?php esc_html_e( 'Payment amount', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-amount" type="number" min="0" step="0.01"></div>
						<div><label class="form-label" for="afc-edit-ppp-method"><?php esc_html_e( 'Payment method', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-method" type="text"></div>
						<div><label class="form-label" for="afc-edit-ppp-wifi"><?php esc_html_e( 'WiFi name', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-wifi" type="text"></div>
						<div><label class="form-label" for="afc-edit-ppp-new-password"><?php esc_html_e( 'New PPP password', 'airfiber-centralized' ); ?></label><input class="form-control" id="afc-edit-ppp-new-password" type="text" placeholder="<?php esc_attr_e( 'Leave blank to keep current password', 'airfiber-centralized' ); ?>"></div>
						<div class="afc-span-2"><label class="form-label" for="afc-edit-ppp-comment"><?php esc_html_e( 'Raw MikroTik comment', 'airfiber-centralized' ); ?></label><textarea class="form-control font-monospace" id="afc-edit-ppp-comment" rows="9"></textarea></div>
					</div>
				</div>
			</section>
		</div>

		<div class="afc-dialog-footer">
			<button class="btn btn-link" type="button" data-afc-dialog-close><?php esc_html_e( 'Close', 'airfiber-centralized' ); ?></button>
			<button class="btn btn-primary" id="afc-save-ppp-details" type="button" disabled><?php esc_html_e( 'Save PPP Details', 'airfiber-centralized' ); ?></button>
		</div>
	</form>
</dialog>

<dialog id="afc-service-areas-dialog" class="afc-dialog afc-ppp-manager-dialog afc-service-areas-dialog">
	<form id="afc-service-areas-form">
		<div class="afc-dialog-header">
			<div>
				<div class="text-secondary small"><?php esc_html_e( 'Address suggestions and future map centers', 'airfiber-centralized' ); ?></div>
				<h3 class="mb-0"><?php esc_html_e( 'ISP Service Areas', 'airfiber-centralized' ); ?></h3>
			</div>
			<button class="btn-close" type="button" data-afc-dialog-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"></button>
		</div>
		<div class="afc-dialog-body">
			<div id="afc-service-areas-notice" aria-live="polite"></div>
			<p class="text-secondary"><?php esc_html_e( 'Add the barangays covered by Airfiber. Zones are comma-separated. Optional center coordinates will later let the customer map open directly over that barangay.', 'airfiber-centralized' ); ?></p>
			<div class="afc-service-area-headings" aria-hidden="true"><span><?php esc_html_e( 'Barangay / area', 'airfiber-centralized' ); ?></span><span><?php esc_html_e( 'Zones', 'airfiber-centralized' ); ?></span><span><?php esc_html_e( 'Center latitude', 'airfiber-centralized' ); ?></span><span><?php esc_html_e( 'Center longitude', 'airfiber-centralized' ); ?></span><span></span></div>
			<div id="afc-service-area-rows" class="afc-service-area-rows"></div>
			<button class="btn btn-outline-primary btn-sm mt-3" id="afc-add-service-area-row" type="button">+ <?php esc_html_e( 'Add barangay', 'airfiber-centralized' ); ?></button>
		</div>
		<div class="afc-dialog-footer">
			<button class="btn btn-link" type="button" data-afc-dialog-close><?php esc_html_e( 'Close', 'airfiber-centralized' ); ?></button>
			<button class="btn btn-primary" id="afc-save-service-areas" type="button"><?php esc_html_e( 'Save Service Areas', 'airfiber-centralized' ); ?></button>
		</div>
	</form>
</dialog>
