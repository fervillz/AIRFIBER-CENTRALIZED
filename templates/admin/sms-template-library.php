<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="afc-sms-library-backdrop" id="afc-sms-library-backdrop" hidden></div>
<section class="afc-sms-library" id="afc-sms-library" aria-hidden="true" hidden>
	<header class="afc-sms-library-header">
		<div>
			<div class="text-secondary small"><?php esc_html_e( 'SMS Center', 'airfiber-centralized' ); ?></div>
			<h3><?php esc_html_e( 'Message Library', 'airfiber-centralized' ); ?></h3>
			<span id="afc-sms-library-count"><?php esc_html_e( 'Loading templates...', 'airfiber-centralized' ); ?></span>
		</div>
		<button class="btn-close" id="afc-sms-library-close" type="button" aria-label="<?php esc_attr_e( 'Close message library', 'airfiber-centralized' ); ?>"></button>
	</header>

	<div class="afc-sms-library-settings">
		<label>
			<span><?php esc_html_e( 'Payment number', 'airfiber-centralized' ); ?></span>
			<input class="form-control form-control-sm" id="afc-sms-library-payment-number" type="text" inputmode="tel" placeholder="09978230630">
		</label>
		<label>
			<span><?php esc_html_e( 'Default mode', 'airfiber-centralized' ); ?></span>
			<select class="form-select form-select-sm" id="afc-sms-library-default-mode">
				<option value="manual"><?php esc_html_e( 'Manual message', 'airfiber-centralized' ); ?></option>
				<option value="fixed"><?php esc_html_e( 'Fixed template', 'airfiber-centralized' ); ?></option>
				<option value="random_category"><?php esc_html_e( 'Random from category', 'airfiber-centralized' ); ?></option>
				<option value="random_all"><?php esc_html_e( 'Random from all enabled', 'airfiber-centralized' ); ?></option>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'Default category', 'airfiber-centralized' ); ?></span>
			<select class="form-select form-select-sm" id="afc-sms-library-default-category"></select>
		</label>
		<button class="btn btn-sm btn-outline-primary" id="afc-sms-library-save-settings" type="button"><?php esc_html_e( 'Save defaults', 'airfiber-centralized' ); ?></button>
	</div>

	<div class="afc-sms-library-toolbar">
		<select class="form-select form-select-sm" id="afc-sms-library-category"></select>
		<input class="form-control form-control-sm" id="afc-sms-library-search" type="search" placeholder="<?php esc_attr_e( 'Search title or message...', 'airfiber-centralized' ); ?>">
		<button class="btn btn-sm btn-primary" id="afc-sms-library-add" type="button"><?php esc_html_e( 'Add message', 'airfiber-centralized' ); ?></button>
		<button class="btn btn-sm btn-outline-secondary" id="afc-sms-library-restore" type="button"><?php esc_html_e( 'Restore starters', 'airfiber-centralized' ); ?></button>
	</div>

	<div class="afc-sms-library-body">
		<nav class="afc-sms-library-categories" id="afc-sms-library-categories" aria-label="<?php esc_attr_e( 'Message categories', 'airfiber-centralized' ); ?>"></nav>
		<div class="afc-sms-library-list" id="afc-sms-library-list">
			<div class="afc-sms-library-empty"><?php esc_html_e( 'Loading message templates...', 'airfiber-centralized' ); ?></div>
		</div>

		<aside class="afc-sms-template-editor" id="afc-sms-template-editor" aria-hidden="true" hidden>
			<header>
				<div>
					<div class="text-secondary small"><?php esc_html_e( 'Template editor', 'airfiber-centralized' ); ?></div>
					<strong id="afc-sms-template-editor-title"><?php esc_html_e( 'New message', 'airfiber-centralized' ); ?></strong>
				</div>
				<button class="btn-close" id="afc-sms-template-editor-close" type="button" aria-label="<?php esc_attr_e( 'Close template editor', 'airfiber-centralized' ); ?>"></button>
			</header>
			<div class="afc-sms-template-editor-body">
				<input id="afc-sms-template-id" type="hidden" value="0">
				<label>
					<span><?php esc_html_e( 'Category', 'airfiber-centralized' ); ?></span>
					<select class="form-select" id="afc-sms-template-category"></select>
				</label>
				<label>
					<span><?php esc_html_e( 'Title', 'airfiber-centralized' ); ?></span>
					<input class="form-control" id="afc-sms-template-title" type="text" maxlength="190">
				</label>
				<label>
					<span><?php esc_html_e( 'Message', 'airfiber-centralized' ); ?></span>
					<textarea class="form-control" id="afc-sms-template-body" rows="10"></textarea>
				</label>
				<div class="form-hint">
					<?php esc_html_e( 'Placeholders: {name}, {ppp}, {phone}, {due_date}, {amount}, {payment_number}.', 'airfiber-centralized' ); ?>
				</div>
				<label class="form-check form-switch mt-3">
					<input class="form-check-input" id="afc-sms-template-enabled" type="checkbox" checked>
					<span class="form-check-label"><?php esc_html_e( 'Enabled for fixed and random selection', 'airfiber-centralized' ); ?></span>
				</label>
			</div>
			<footer>
				<button class="btn btn-outline-danger" id="afc-sms-template-editor-delete" type="button" hidden><?php esc_html_e( 'Delete', 'airfiber-centralized' ); ?></button>
				<span></span>
				<button class="btn btn-outline-secondary" id="afc-sms-template-editor-cancel" type="button"><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
				<button class="btn btn-primary" id="afc-sms-template-editor-save" type="button"><?php esc_html_e( 'Save message', 'airfiber-centralized' ); ?></button>
			</footer>
		</aside>
	</div>
</section>
