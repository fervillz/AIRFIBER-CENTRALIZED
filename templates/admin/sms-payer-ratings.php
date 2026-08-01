<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="afc-sms-payor-backdrop" id="afc-sms-payor-backdrop" hidden></div>
<section class="afc-sms-payor-manager" id="afc-sms-payor-manager" aria-hidden="true" hidden>
	<header class="afc-sms-payor-header">
		<div>
			<div class="text-secondary small"><?php esc_html_e( 'SMS Center', 'airfiber-centralized' ); ?></div>
			<h3><?php esc_html_e( 'Payor Ratings & Due Rules', 'airfiber-centralized' ); ?></h3>
			<span id="afc-sms-payor-count"><?php esc_html_e( 'Loading customers...', 'airfiber-centralized' ); ?></span>
		</div>
		<button class="btn-close" id="afc-sms-payor-close" type="button" aria-label="<?php esc_attr_e( 'Close payor ratings', 'airfiber-centralized' ); ?>"></button>
	</header>

	<div class="afc-sms-payor-summary">
		<label class="form-check form-switch">
			<input class="form-check-input" id="afc-sms-payor-automation" type="checkbox">
			<span class="form-check-label"><?php esc_html_e( 'Enable automatic due reminder queue', 'airfiber-centralized' ); ?></span>
		</label>
		<div class="afc-sms-payor-safety"><?php esc_html_e( 'No pre-due reminders. Replies and payments stop the current reminder flow. Weekly and monthly caps always apply.', 'airfiber-centralized' ); ?></div>
		<button class="btn btn-sm btn-outline-primary" id="afc-sms-payor-save-rules" type="button"><?php esc_html_e( 'Save rules', 'airfiber-centralized' ); ?></button>
		<button class="btn btn-sm btn-primary" id="afc-sms-payor-scan" type="button"><?php esc_html_e( 'Scan due accounts now', 'airfiber-centralized' ); ?></button>
	</div>

	<div class="afc-sms-payor-toolbar">
		<input class="form-control form-control-sm" id="afc-sms-payor-search" type="search" placeholder="<?php esc_attr_e( 'Search name, PPP or phone...', 'airfiber-centralized' ); ?>">
		<select class="form-select form-select-sm" id="afc-sms-payor-filter-rating">
			<option value="all"><?php esc_html_e( 'All ratings', 'airfiber-centralized' ); ?></option>
			<option value="5">5★</option><option value="4">4★</option><option value="3">3★</option><option value="2">2★</option><option value="1">1★</option><option value="0">0★</option>
		</select>
		<select class="form-select form-select-sm" id="afc-sms-payor-filter-state">
			<option value="all"><?php esc_html_e( 'All contact states', 'airfiber-centralized' ); ?></option>
			<option value="active"><?php esc_html_e( 'Active reminders', 'airfiber-centralized' ); ?></option>
			<option value="paused"><?php esc_html_e( 'Paused', 'airfiber-centralized' ); ?></option>
			<option value="dnt"><?php esc_html_e( 'Do Not Text', 'airfiber-centralized' ); ?></option>
		</select>
		<details class="afc-sms-payor-rule-details">
			<summary><?php esc_html_e( 'Edit rating schedule', 'airfiber-centralized' ); ?></summary>
			<div class="afc-sms-payor-rule-popover">
				<div class="afc-sms-payor-global-rules">
					<label><span><?php esc_html_e( 'Start hour', 'airfiber-centralized' ); ?></span><input class="form-control form-control-sm" id="afc-sms-rule-start-hour" type="number" min="0" max="23"></label>
					<label><span><?php esc_html_e( 'End hour', 'airfiber-centralized' ); ?></span><input class="form-control form-control-sm" id="afc-sms-rule-end-hour" type="number" min="0" max="23"></label>
					<label><span><?php esc_html_e( 'Max per scan', 'airfiber-centralized' ); ?></span><input class="form-control form-control-sm" id="afc-sms-rule-max-scan" type="number" min="1" max="100"></label>
					<label><span><?php esc_html_e( 'Max per day', 'airfiber-centralized' ); ?></span><input class="form-control form-control-sm" id="afc-sms-rule-max-day" type="number" min="1" max="300"></label>
					<label class="form-check form-switch"><input class="form-check-input" id="afc-sms-rule-pause-reply" type="checkbox"><span class="form-check-label"><?php esc_html_e( 'Pause after customer reply', 'airfiber-centralized' ); ?></span></label>
				</div>
				<div class="afc-sms-rating-rules" id="afc-sms-rating-rules"></div>
			</div>
		</details>
	</div>

	<div class="afc-sms-payor-body">
		<div class="afc-sms-payor-list" id="afc-sms-payor-list">
			<div class="afc-sms-payor-empty"><?php esc_html_e( 'Loading customer payment behaviour...', 'airfiber-centralized' ); ?></div>
		</div>

		<aside class="afc-sms-payor-editor" id="afc-sms-payor-editor" aria-hidden="true" hidden>
			<header>
				<div>
					<div class="text-secondary small"><?php esc_html_e( 'Payment behaviour', 'airfiber-centralized' ); ?></div>
					<strong id="afc-sms-payor-editor-name"><?php esc_html_e( 'Customer', 'airfiber-centralized' ); ?></strong>
					<span id="afc-sms-payor-editor-meta"></span>
				</div>
				<button class="btn-close" id="afc-sms-payor-editor-close" type="button" aria-label="<?php esc_attr_e( 'Close customer rating', 'airfiber-centralized' ); ?>"></button>
			</header>
			<div class="afc-sms-payor-editor-body">
				<input id="afc-sms-payor-customer-id" type="hidden" value="0">
				<div class="afc-sms-payor-rating-box">
					<div><span><?php esc_html_e( 'Current rating', 'airfiber-centralized' ); ?></span><strong id="afc-sms-payor-rating-label">3★ Standard</strong></div>
					<div class="afc-sms-star-picker" id="afc-sms-star-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Payor rating', 'airfiber-centralized' ); ?>">
						<button type="button" data-afc-rating="0">0</button><button type="button" data-afc-rating="1">★</button><button type="button" data-afc-rating="2">★</button><button type="button" data-afc-rating="3">★</button><button type="button" data-afc-rating="4">★</button><button type="button" data-afc-rating="5">★</button>
					</div>
					<label><span><?php esc_html_e( 'Rating mode', 'airfiber-centralized' ); ?></span><select class="form-select" id="afc-sms-payor-rating-mode"><option value="auto"><?php esc_html_e( 'Automatic from payment timing', 'airfiber-centralized' ); ?></option><option value="manual"><?php esc_html_e( 'Manual rating', 'airfiber-centralized' ); ?></option></select></label>
					<div class="afc-sms-payor-suggestion" id="afc-sms-payor-suggestion"></div>
				</div>

				<div class="afc-sms-payor-policy-preview" id="afc-sms-payor-policy-preview"></div>

				<label class="form-check form-switch">
					<input class="form-check-input" id="afc-sms-payor-paused" type="checkbox">
					<span class="form-check-label"><?php esc_html_e( 'Pause automatic reminders for this customer', 'airfiber-centralized' ); ?></span>
				</label>
				<label><span><?php esc_html_e( 'Internal note', 'airfiber-centralized' ); ?></span><textarea class="form-control" id="afc-sms-payor-note" rows="3" placeholder="Reason for pause, promise to pay, special arrangement..."></textarea></label>

				<label><span><?php esc_html_e( 'Due message selection', 'airfiber-centralized' ); ?></span>
					<select class="form-select" id="afc-sms-payor-template-mode">
						<option value="inherit"><?php esc_html_e( 'Use Message Library default', 'airfiber-centralized' ); ?></option>
						<option value="random_due"><?php esc_html_e( 'Random enabled Due Reminder', 'airfiber-centralized' ); ?></option>
						<option value="fixed"><?php esc_html_e( 'Always use one fixed template', 'airfiber-centralized' ); ?></option>
						<option value="random_all"><?php esc_html_e( 'Random from all enabled templates', 'airfiber-centralized' ); ?></option>
					</select>
				</label>
				<label id="afc-sms-payor-template-field"><span><?php esc_html_e( 'Fixed template', 'airfiber-centralized' ); ?></span><select class="form-select" id="afc-sms-payor-template-id"></select></label>

				<div class="afc-sms-payor-history" id="afc-sms-payor-history"></div>
			</div>
			<footer>
				<button class="btn btn-outline-secondary" id="afc-sms-payor-editor-cancel" type="button"><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
				<button class="btn btn-primary" id="afc-sms-payor-editor-save" type="button"><?php esc_html_e( 'Save customer policy', 'airfiber-centralized' ); ?></button>
			</footer>
		</aside>
	</div>
</section>
