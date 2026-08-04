<?php

defined( 'ABSPATH' ) || exit;
$device = isset( $snapshot['device'] ) ? $snapshot['device'] : array();
?>
<section class="afc-frontend-panel afc-advanced-only" data-afc-panel="sms" aria-hidden="true" hidden>
	<div class="wrap afc-admin-page afc-sms-center" id="afc-sms-center">
		<div class="container-fluid py-3">
			<div class="page-header afc-sms-page-header mb-2">
				<div class="row align-items-center g-2">
					<div class="col">
						<div class="page-pretitle"><?php esc_html_e( 'Airfiber - Centralized', 'airfiber-centralized' ); ?></div>
						<h2 class="page-title"><?php esc_html_e( 'SMS Center', 'airfiber-centralized' ); ?></h2>
					</div>
					<div class="col-auto ms-auto">
						<button class="btn btn-sm btn-outline-secondary" id="afc-sms-open-library" type="button"><?php esc_html_e( 'Message Library', 'airfiber-centralized' ); ?></button>
						<button class="btn btn-sm btn-outline-secondary" id="afc-sms-open-payors" type="button"><?php esc_html_e( 'Payor Ratings', 'airfiber-centralized' ); ?></button>
						<button class="btn btn-sm btn-outline-primary" id="afc-sms-refresh" type="button"><?php esc_html_e( 'Refresh', 'airfiber-centralized' ); ?></button>
					</div>
				</div>
			</div>

			<div id="afc-sms-notice" aria-live="polite"></div>

			<div class="card afc-sms-overview-card mb-2">
				<div class="afc-sms-overview-row">
					<button class="afc-sms-overview-status afc-sms-overview-filter" type="button" data-afc-sms-filter="gateway" aria-pressed="false">
						<span class="text-secondary"><?php esc_html_e( 'Gateway', 'airfiber-centralized' ); ?></span>
						<strong id="afc-sms-device-state"><?php echo esc_html( isset( $device['state'] ) ? $device['state'] : 'not-configured' ); ?></strong>
						<small id="afc-sms-last-seen"><?php echo esc_html( ! empty( $device['last_seen'] ) ? $device['last_seen'] : 'Never' ); ?></small>
					</button>
					<button class="afc-sms-overview-count afc-sms-overview-filter" type="button" data-afc-sms-filter="queued" aria-pressed="false"><span><?php esc_html_e( 'Queued', 'airfiber-centralized' ); ?></span><strong data-afc-sms-count="queued">0</strong></button>
					<button class="afc-sms-overview-count afc-sms-overview-filter" type="button" data-afc-sms-filter="sent" aria-pressed="false"><span><?php esc_html_e( 'Sent', 'airfiber-centralized' ); ?></span><strong data-afc-sms-count="sent">0</strong></button>
					<button class="afc-sms-overview-count afc-sms-overview-filter" type="button" data-afc-sms-filter="delivered" aria-pressed="false"><span><?php esc_html_e( 'Delivered', 'airfiber-centralized' ); ?></span><strong data-afc-sms-count="delivered">0</strong></button>
					<details class="afc-sms-gateway-details">
						<summary><?php esc_html_e( 'Gateway settings', 'airfiber-centralized' ); ?></summary>
						<div class="afc-sms-gateway-popover">
							<div><span><?php esc_html_e( 'Device ID', 'airfiber-centralized' ); ?></span><strong id="afc-sms-device-id"><?php echo esc_html( ! empty( $device['device_id'] ) ? $device['device_id'] : 'Not connected' ); ?></strong></div>
							<div><span><?php esc_html_e( 'Status detail', 'airfiber-centralized' ); ?></span><strong id="afc-sms-device-detail"><?php echo esc_html( isset( $device['detail'] ) ? $device['detail'] : '' ); ?></strong></div>
							<div><span><?php esc_html_e( 'Saved token', 'airfiber-centralized' ); ?></span><strong id="afc-sms-token-hint"><?php echo esc_html( ! empty( $device['token_hint'] ) ? $device['token_hint'] : 'None' ); ?></strong></div>
							<div><span><?php esc_html_e( 'WordPress URL', 'airfiber-centralized' ); ?></span><code id="afc-sms-wordpress-url"><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code></div>
							<button class="btn btn-sm btn-primary" id="afc-sms-generate-token" type="button"><?php echo ! empty( $device['exists'] ) ? esc_html__( 'Rotate Device Token', 'airfiber-centralized' ) : esc_html__( 'Generate Device Token', 'airfiber-centralized' ); ?></button>
							<div class="afc-sms-secret mt-2" id="afc-sms-token-box" hidden>
								<label class="form-label" for="afc-sms-device-token"><?php esc_html_e( 'Copy this token now', 'airfiber-centralized' ); ?></label>
								<div class="input-group input-group-sm"><input class="form-control font-monospace" id="afc-sms-device-token" type="text" readonly><button class="btn btn-outline-secondary" id="afc-sms-copy-token" type="button"><?php esc_html_e( 'Copy', 'airfiber-centralized' ); ?></button></div>
							</div>
						</div>
					</details>
				</div>
			</div>

			<div class="card afc-sms-chat-card">
				<div class="afc-sms-chat-layout" id="afc-sms-chat-layout">
					<aside class="afc-sms-conversation-column" aria-label="<?php esc_attr_e( 'SMS conversations', 'airfiber-centralized' ); ?>">
						<div class="afc-sms-conversation-heading">
							<div>
								<h3 class="card-title mb-0"><?php esc_html_e( 'Customers', 'airfiber-centralized' ); ?></h3>
								<div class="text-secondary small" id="afc-sms-conversation-count"><?php esc_html_e( 'Loading customers...', 'airfiber-centralized' ); ?></div>
							</div>
							<button class="btn btn-sm btn-primary" id="afc-sms-new-message" type="button"><?php esc_html_e( 'New SMS', 'airfiber-centralized' ); ?></button>
						</div>
						<div class="afc-sms-filter-bar" aria-label="<?php esc_attr_e( 'SMS customer filters', 'airfiber-centralized' ); ?>">
							<button class="afc-sms-filter-button is-active" type="button" data-afc-sms-filter="all" aria-pressed="true"><span><?php esc_html_e( 'All', 'airfiber-centralized' ); ?></span><strong data-afc-sms-filter-count>0</strong></button>
							<button class="afc-sms-filter-button" type="button" data-afc-sms-filter="queued" aria-pressed="false"><span><?php esc_html_e( 'Queued', 'airfiber-centralized' ); ?></span><strong data-afc-sms-filter-count>0</strong></button>
							<button class="afc-sms-filter-button" type="button" data-afc-sms-filter="delivered" aria-pressed="false"><span><?php esc_html_e( 'Delivered', 'airfiber-centralized' ); ?></span><strong data-afc-sms-filter-count>0</strong></button>
							<button class="afc-sms-filter-button" type="button" data-afc-sms-filter="due-soon" aria-pressed="false"><span><?php esc_html_e( 'Due soon', 'airfiber-centralized' ); ?></span><strong data-afc-sms-filter-count>0</strong></button>
							<button class="afc-sms-filter-button" type="button" data-afc-sms-filter="prepared" aria-pressed="false"><span><?php esc_html_e( 'Prepared', 'airfiber-centralized' ); ?></span><strong data-afc-sms-filter-count>0</strong></button>
						</div>
						<div class="afc-sms-conversation-search">
							<input class="form-control form-control-sm" id="afc-sms-conversation-search" type="search" placeholder="<?php esc_attr_e( 'Search name, PPP, phone or status...', 'airfiber-centralized' ); ?>">
						</div>
						<div class="afc-sms-conversation-list" id="afc-sms-conversations">
							<div class="afc-sms-chat-empty"><?php esc_html_e( 'Loading all PPP customers...', 'airfiber-centralized' ); ?></div>
						</div>
					</aside>

					<section class="afc-sms-chat-column" aria-label="<?php esc_attr_e( 'Selected SMS conversation', 'airfiber-centralized' ); ?>">
						<div class="afc-sms-chat-main" id="afc-sms-chat-main">
							<header class="afc-sms-chat-header">
								<div class="afc-sms-chat-avatar" id="afc-sms-chat-avatar">A</div>
								<div class="afc-sms-chat-contact">
									<strong id="afc-sms-chat-name"><?php esc_html_e( 'Select a customer', 'airfiber-centralized' ); ?></strong>
									<span id="afc-sms-chat-meta"><?php esc_html_e( 'Messages, prepared reminders and delivery updates appear here.', 'airfiber-centralized' ); ?></span>
								</div>
								<button class="btn btn-sm btn-primary afc-sms-compose-selected" id="afc-sms-compose-selected" type="button" hidden><?php esc_html_e( 'Queue SMS', 'airfiber-centralized' ); ?></button>
								<button class="afc-sms-chat-close-drawer" id="afc-sms-close-drawer" type="button" aria-label="<?php esc_attr_e( 'Close conversation options', 'airfiber-centralized' ); ?>" hidden>&times;</button>
							</header>
							<div class="afc-sms-chat-timeline" id="afc-sms-chat-timeline">
								<div class="afc-sms-chat-empty"><?php esc_html_e( 'Choose a customer from the list.', 'airfiber-centralized' ); ?></div>
							</div>
						</div>

						<div class="afc-sms-composer-backdrop" id="afc-sms-composer-backdrop" hidden></div>
						<section class="afc-sms-composer" id="afc-sms-composer" aria-hidden="true" hidden>
							<header class="afc-sms-composer-header">
								<div>
									<div class="text-secondary small"><?php esc_html_e( 'Approve an outgoing message', 'airfiber-centralized' ); ?></div>
									<strong id="afc-sms-composer-recipient"><?php esc_html_e( 'Choose a PPP customer', 'airfiber-centralized' ); ?></strong>
									<span id="afc-sms-selected-label"><?php esc_html_e( 'No PPP customer selected.', 'airfiber-centralized' ); ?></span>
								</div>
								<button class="btn-close" id="afc-sms-composer-close" type="button" aria-label="<?php esc_attr_e( 'Close message composer', 'airfiber-centralized' ); ?>"></button>
							</header>
							<div class="afc-sms-composer-body">
								<div class="afc-sms-composer-search-row">
									<input class="form-control form-control-sm" id="afc-sms-ppp-search" type="search" placeholder="<?php esc_attr_e( 'Change recipient: search PPP, name or phone...', 'airfiber-centralized' ); ?>">
									<button class="btn btn-sm btn-outline-secondary" id="afc-sms-load-ppp" type="button"><?php esc_html_e( 'Reload', 'airfiber-centralized' ); ?></button>
								</div>
								<div class="afc-sms-ppp-list border rounded" id="afc-sms-ppp-list"><div class="text-secondary p-3"><?php esc_html_e( 'Loading PPP customers...', 'airfiber-centralized' ); ?></div></div>
								<label class="form-label mt-3" for="afc-sms-message"><?php esc_html_e( 'Message', 'airfiber-centralized' ); ?></label>
								<textarea class="form-control" id="afc-sms-message" rows="5"><?php echo esc_textarea( self::default_test_message() ); ?></textarea>
								<div class="form-hint"><?php esc_html_e( 'Available placeholders: {name}, {ppp}, {phone}. The Android gateway sends it automatically when automatic mode is enabled.', 'airfiber-centralized' ); ?></div>
							</div>
							<footer class="afc-sms-composer-footer">
								<button class="btn btn-outline-secondary" id="afc-sms-composer-cancel" type="button"><?php esc_html_e( 'Cancel', 'airfiber-centralized' ); ?></button>
								<button class="btn btn-success" id="afc-sms-queue-test" type="button" disabled><?php esc_html_e( 'Approve & Queue SMS', 'airfiber-centralized' ); ?></button>
							</footer>
						</section>

						<aside class="afc-sms-chat-drawer" id="afc-sms-chat-drawer" aria-hidden="true">
							<div class="afc-sms-drawer-header">
								<div><div class="text-secondary small"><?php esc_html_e( 'Conversation options', 'airfiber-centralized' ); ?></div><strong id="afc-sms-drawer-name"><?php esc_html_e( 'Customer', 'airfiber-centralized' ); ?></strong></div>
								<button class="btn-close" id="afc-sms-drawer-close" type="button" aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"></button>
							</div>
							<nav class="afc-sms-drawer-tabs" aria-label="<?php esc_attr_e( 'Conversation views', 'airfiber-centralized' ); ?>">
								<button type="button" data-afc-sms-drawer-view="conversation"><?php esc_html_e( 'Conversation', 'airfiber-centralized' ); ?></button>
								<button type="button" data-afc-sms-drawer-view="delivery"><?php esc_html_e( 'Queue & Delivery', 'airfiber-centralized' ); ?></button>
								<button type="button" data-afc-sms-drawer-view="details"><?php esc_html_e( 'Customer Details', 'airfiber-centralized' ); ?></button>
							</nav>
							<div class="afc-sms-drawer-content" id="afc-sms-drawer-content"></div>
						</aside>
					</section>
				</div>
			</div>
		</div>
	</div>

	<?php
	// Keep both management tools inside the Ajaxified SMS panel.
	include AFC_PATH . 'templates/admin/sms-template-library.php';
	include AFC_PATH . 'templates/admin/sms-payer-ratings.php';
	?>
</section>
