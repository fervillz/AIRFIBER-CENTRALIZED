<?php

defined( 'ABSPATH' ) || exit;
$device = isset( $snapshot['device'] ) ? $snapshot['device'] : array();
?>
<section class="afc-frontend-panel afc-advanced-only" data-afc-panel="sms" aria-hidden="true" hidden>
	<div class="wrap afc-admin-page afc-sms-center" id="afc-sms-center">
		<div class="container-fluid py-4">
			<div class="page-header mb-3">
				<div class="row align-items-center">
					<div class="col">
						<div class="page-pretitle"><?php esc_html_e( 'Airfiber - Centralized', 'airfiber-centralized' ); ?></div>
						<h2 class="page-title"><?php esc_html_e( 'SMS Center', 'airfiber-centralized' ); ?></h2>
						<p class="text-secondary mb-0"><?php esc_html_e( 'Connect the Android gateway, approve messages, and manage delivery and customer replies as conversations.', 'airfiber-centralized' ); ?></p>
					</div>
					<div class="col-auto ms-auto">
						<button class="btn btn-outline-primary" id="afc-sms-refresh" type="button"><?php esc_html_e( 'Refresh Status', 'airfiber-centralized' ); ?></button>
					</div>
				</div>
			</div>

			<div id="afc-sms-notice" aria-live="polite"></div>

			<div class="row row-cards mb-3">
				<div class="col-sm-6 col-xl-3"><div class="card card-sm"><div class="card-body"><div class="text-secondary"><?php esc_html_e( 'Gateway', 'airfiber-centralized' ); ?></div><div class="h3 mb-0" id="afc-sms-device-state"><?php echo esc_html( isset( $device['state'] ) ? $device['state'] : 'not-configured' ); ?></div></div></div></div>
				<div class="col-sm-6 col-xl-3"><div class="card card-sm"><div class="card-body"><div class="text-secondary"><?php esc_html_e( 'Queued', 'airfiber-centralized' ); ?></div><div class="h3 mb-0" data-afc-sms-count="queued">0</div></div></div></div>
				<div class="col-sm-6 col-xl-3"><div class="card card-sm"><div class="card-body"><div class="text-secondary"><?php esc_html_e( 'Sent', 'airfiber-centralized' ); ?></div><div class="h3 mb-0 text-success" data-afc-sms-count="sent">0</div></div></div></div>
				<div class="col-sm-6 col-xl-3"><div class="card card-sm"><div class="card-body"><div class="text-secondary"><?php esc_html_e( 'Delivered', 'airfiber-centralized' ); ?></div><div class="h3 mb-0 text-primary" data-afc-sms-count="delivered">0</div></div></div></div>
			</div>

			<div class="row row-cards mb-3">
				<div class="col-xl-5">
					<div class="card h-100">
						<div class="card-header"><div><h3 class="card-title"><?php esc_html_e( '1. Connect Android Gateway', 'airfiber-centralized' ); ?></h3><p class="card-subtitle"><?php esc_html_e( 'Generate a private token and paste it into the phone app. The USB bridge forwards it automatically.', 'airfiber-centralized' ); ?></p></div></div>
						<div class="card-body">
							<div class="afc-sms-device-summary mb-3">
								<div><span class="text-secondary"><?php esc_html_e( 'Last seen', 'airfiber-centralized' ); ?>:</span> <strong id="afc-sms-last-seen"><?php echo esc_html( ! empty( $device['last_seen'] ) ? $device['last_seen'] : 'Never' ); ?></strong></div>
								<div><span class="text-secondary"><?php esc_html_e( 'Device ID', 'airfiber-centralized' ); ?>:</span> <strong id="afc-sms-device-id"><?php echo esc_html( ! empty( $device['device_id'] ) ? $device['device_id'] : 'Not connected' ); ?></strong></div>
								<div class="text-secondary mt-1" id="afc-sms-device-detail"><?php echo esc_html( isset( $device['detail'] ) ? $device['detail'] : '' ); ?></div>
							</div>
							<button class="btn btn-primary" id="afc-sms-generate-token" type="button"><?php echo ! empty( $device['exists'] ) ? esc_html__( 'Rotate Device Token', 'airfiber-centralized' ) : esc_html__( 'Generate Device Token', 'airfiber-centralized' ); ?></button>
							<div class="afc-sms-secret mt-3" id="afc-sms-token-box" hidden>
								<label class="form-label" for="afc-sms-device-token"><?php esc_html_e( 'Copy this token now', 'airfiber-centralized' ); ?></label>
								<div class="input-group"><input class="form-control font-monospace" id="afc-sms-device-token" type="text" readonly><button class="btn btn-outline-secondary" id="afc-sms-copy-token" type="button"><?php esc_html_e( 'Copy', 'airfiber-centralized' ); ?></button></div>
								<div class="form-hint"><?php esc_html_e( 'Paste it into the Android Device token field. It is shown only after generation.', 'airfiber-centralized' ); ?></div>
							</div>
							<div class="mt-3"><span class="text-secondary"><?php esc_html_e( 'Saved token', 'airfiber-centralized' ); ?>:</span> <strong id="afc-sms-token-hint"><?php echo esc_html( ! empty( $device['token_hint'] ) ? $device['token_hint'] : 'None' ); ?></strong></div>
							<div class="mt-3 p-3 bg-light rounded">
								<div class="fw-bold mb-1"><?php esc_html_e( 'WordPress URL', 'airfiber-centralized' ); ?></div>
								<code id="afc-sms-wordpress-url"><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code>
							</div>
						</div>
					</div>
				</div>

				<div class="col-xl-7">
					<div class="card h-100">
						<div class="card-header"><div><h3 class="card-title"><?php esc_html_e( '2. Queue One PPP Test SMS', 'airfiber-centralized' ); ?></h3><p class="card-subtitle"><?php esc_html_e( 'Approve a message here. When automatic mode is enabled on the phone, it is sent without pressing Process queue now.', 'airfiber-centralized' ); ?></p></div></div>
						<div class="card-body">
							<div class="row g-2 mb-3">
								<div class="col"><input class="form-control" id="afc-sms-ppp-search" type="search" placeholder="<?php esc_attr_e( 'Search PPP username, customer or phone...', 'airfiber-centralized' ); ?>"></div>
								<div class="col-auto"><button class="btn btn-outline-secondary" id="afc-sms-load-ppp" type="button"><?php esc_html_e( 'Reload PPP', 'airfiber-centralized' ); ?></button></div>
							</div>
							<div class="afc-sms-ppp-list border rounded mb-3" id="afc-sms-ppp-list"><div class="text-secondary p-3"><?php esc_html_e( 'Loading PPP customers...', 'airfiber-centralized' ); ?></div></div>
							<div class="mb-3">
								<label class="form-label" for="afc-sms-message"><?php esc_html_e( 'Test message', 'airfiber-centralized' ); ?></label>
								<textarea class="form-control" id="afc-sms-message" rows="4"><?php echo esc_textarea( self::default_test_message() ); ?></textarea>
								<div class="form-hint"><?php esc_html_e( 'Available placeholders: {name}, {ppp}, {phone}.', 'airfiber-centralized' ); ?></div>
							</div>
							<div class="d-flex align-items-center gap-3 flex-wrap">
								<button class="btn btn-success" id="afc-sms-queue-test" type="button" disabled><?php esc_html_e( 'Approve & Queue Test SMS', 'airfiber-centralized' ); ?></button>
								<div class="text-secondary" id="afc-sms-selected-label"><?php esc_html_e( 'No PPP customer selected.', 'airfiber-centralized' ); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="card afc-sms-chat-card">
				<div class="afc-sms-chat-layout" id="afc-sms-chat-layout">
					<aside class="afc-sms-conversation-column" aria-label="<?php esc_attr_e( 'SMS conversations', 'airfiber-centralized' ); ?>">
						<div class="afc-sms-conversation-heading">
							<div>
								<h3 class="card-title mb-1"><?php esc_html_e( 'Messages', 'airfiber-centralized' ); ?></h3>
								<div class="text-secondary small" id="afc-sms-conversation-count"><?php esc_html_e( '0 conversations', 'airfiber-centralized' ); ?></div>
							</div>
						</div>
						<div class="afc-sms-conversation-search">
							<input class="form-control" id="afc-sms-conversation-search" type="search" placeholder="<?php esc_attr_e( 'Search name, PPP or phone...', 'airfiber-centralized' ); ?>">
						</div>
						<div class="afc-sms-conversation-list" id="afc-sms-conversations">
							<div class="afc-sms-chat-empty"><?php esc_html_e( 'No messages yet.', 'airfiber-centralized' ); ?></div>
						</div>
					</aside>

					<section class="afc-sms-chat-column" aria-label="<?php esc_attr_e( 'Selected SMS conversation', 'airfiber-centralized' ); ?>">
						<div class="afc-sms-chat-main" id="afc-sms-chat-main">
							<header class="afc-sms-chat-header">
								<div class="afc-sms-chat-avatar" id="afc-sms-chat-avatar">A</div>
								<div class="afc-sms-chat-contact">
									<strong id="afc-sms-chat-name"><?php esc_html_e( 'Select a conversation', 'airfiber-centralized' ); ?></strong>
									<span id="afc-sms-chat-meta"><?php esc_html_e( 'Delivery updates and replies will appear here.', 'airfiber-centralized' ); ?></span>
								</div>
								<button class="afc-sms-chat-close-drawer" id="afc-sms-close-drawer" type="button" aria-label="<?php esc_attr_e( 'Close conversation options', 'airfiber-centralized' ); ?>" hidden>&times;</button>
							</header>
							<div class="afc-sms-chat-timeline" id="afc-sms-chat-timeline">
								<div class="afc-sms-chat-empty"><?php esc_html_e( 'Choose a customer from the list to open the conversation.', 'airfiber-centralized' ); ?></div>
							</div>
						</div>

						<aside class="afc-sms-chat-drawer" id="afc-sms-chat-drawer" aria-hidden="true">
							<div class="afc-sms-drawer-header">
								<div>
									<div class="text-secondary small"><?php esc_html_e( 'Conversation options', 'airfiber-centralized' ); ?></div>
									<strong id="afc-sms-drawer-name"><?php esc_html_e( 'Customer', 'airfiber-centralized' ); ?></strong>
								</div>
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
</section>
