<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="afc-olt-manager" id="afc-olt-manager">
	<div class="afc-olt-manager-heading">
		<div>
			<span class="afc-olt-manager-eyebrow"><?php esc_html_e( 'Network devices', 'airfiber-centralized' ); ?></span>
			<h2><?php esc_html_e( 'OLT', 'airfiber-centralized' ); ?></h2>
			<p><?php esc_html_e( 'Add and manage the OLT connections used by Airfiber Centralized.', 'airfiber-centralized' ); ?></p>
		</div>
		<div class="afc-olt-manager-count" data-afc-olt-count></div>
	</div>

	<div class="afc-olt-grid" data-afc-olt-list>
		<?php echo AFC_OLT_Manager::cards_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<div class="afc-olt-modal" data-afc-olt-modal hidden>
		<div class="afc-olt-modal-backdrop" data-afc-olt-close></div>
		<div class="afc-olt-dialog" role="dialog" aria-modal="true" aria-labelledby="afc-olt-dialog-title">
			<div class="afc-olt-dialog-main">
				<header class="afc-olt-dialog-header">
					<div>
						<span class="afc-olt-dialog-kicker" data-afc-olt-dialog-kicker><?php esc_html_e( 'New OLT', 'airfiber-centralized' ); ?></span>
						<h3 id="afc-olt-dialog-title" data-afc-olt-dialog-title><?php esc_html_e( 'OLT configuration', 'airfiber-centralized' ); ?></h3>
					</div>
					<div class="afc-olt-dialog-tools">
						<button type="button" class="afc-olt-help-toggle" data-afc-olt-help-toggle aria-pressed="false" title="<?php esc_attr_e( 'Show SNMP setup help', 'airfiber-centralized' ); ?>">?</button>
						<button type="button" class="afc-olt-dialog-close" data-afc-olt-close aria-label="<?php esc_attr_e( 'Close OLT configuration', 'airfiber-centralized' ); ?>">×</button>
					</div>
				</header>

				<div class="afc-olt-save-info is-neutral" data-afc-olt-save-info role="status" aria-live="polite">
					<span class="afc-olt-save-dot" aria-hidden="true"></span>
					<span data-afc-olt-save-message><?php esc_html_e( 'Start entering the OLT details. The first draft will save automatically after 5 seconds.', 'airfiber-centralized' ); ?></span>
				</div>

				<form class="afc-olt-form" data-afc-olt-form autocomplete="off">
					<input type="hidden" name="id" value="0" data-afc-olt-id>
					<input type="hidden" name="post_status" value="draft" data-afc-olt-post-status>

					<div class="afc-olt-field is-wide">
						<label for="afc-manager-olt-title"><?php esc_html_e( 'Title', 'airfiber-centralized' ); ?></label>
						<input id="afc-manager-olt-title" name="title" type="text" placeholder="<?php esc_attr_e( 'e.g. Lingion Main OLT', 'airfiber-centralized' ); ?>">
						<small><?php esc_html_e( 'This is the friendly name shown in Airfiber Centralized.', 'airfiber-centralized' ); ?></small>
					</div>

					<div class="afc-olt-field is-host">
						<label for="afc-manager-olt-host"><?php esc_html_e( 'IP address or hostname', 'airfiber-centralized' ); ?></label>
						<input id="afc-manager-olt-host" name="host" type="text" placeholder="10.13.88.5">
					</div>
					<div class="afc-olt-field is-port">
						<label for="afc-manager-olt-port"><?php esc_html_e( 'Port', 'airfiber-centralized' ); ?></label>
						<input id="afc-manager-olt-port" name="port" type="number" min="1" max="65535" value="161">
					</div>
					<div class="afc-olt-field is-version">
						<label for="afc-manager-olt-version"><?php esc_html_e( 'SNMP', 'airfiber-centralized' ); ?></label>
						<select id="afc-manager-olt-version" name="version">
							<option value="3">SNMPv3</option>
							<option value="2c">SNMPv2c</option>
						</select>
					</div>

					<div class="afc-olt-version-fields is-v2" data-afc-olt-v2 hidden>
						<div class="afc-olt-field is-wide">
							<label for="afc-manager-olt-community"><?php esc_html_e( 'Read-only community', 'airfiber-centralized' ); ?></label>
							<input id="afc-manager-olt-community" name="community" type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Dedicated read-only community', 'airfiber-centralized' ); ?>">
						</div>
					</div>

					<div class="afc-olt-version-fields is-v3" data-afc-olt-v3>
						<div class="afc-olt-field is-wide">
							<label for="afc-manager-olt-security-name"><?php esc_html_e( 'Security username', 'airfiber-centralized' ); ?></label>
							<input id="afc-manager-olt-security-name" name="security_name" type="text" value="airfiber-monitor">
						</div>
						<div class="afc-olt-field">
							<label for="afc-manager-olt-auth"><?php esc_html_e( 'SHA authentication passphrase', 'airfiber-centralized' ); ?></label>
							<input id="afc-manager-olt-auth" name="auth_passphrase" type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Minimum 8 characters', 'airfiber-centralized' ); ?>">
						</div>
						<div class="afc-olt-field">
							<label for="afc-manager-olt-privacy"><?php esc_html_e( 'DES privacy passphrase', 'airfiber-centralized' ); ?></label>
							<input id="afc-manager-olt-privacy" name="privacy_passphrase" type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Minimum 8 characters', 'airfiber-centralized' ); ?>">
						</div>
					</div>

					<div class="afc-olt-field is-wide">
						<label for="afc-manager-olt-rx-oid"><?php esc_html_e( 'RX-power OID', 'airfiber-centralized' ); ?></label>
						<input id="afc-manager-olt-rx-oid" name="rx_oid" class="is-mono" type="text" value="<?php echo esc_attr( AFC_OLT::RX_POWER_OID ); ?>">
						<small><?php esc_html_e( 'Keep the default unless the OLT firmware exposes RX power at a different OID.', 'airfiber-centralized' ); ?></small>
					</div>

					<details class="afc-olt-advanced-fields">
						<summary><?php esc_html_e( 'Advanced connection settings', 'airfiber-centralized' ); ?></summary>
						<div class="afc-olt-advanced-grid">
							<div class="afc-olt-field">
								<label for="afc-manager-olt-warning"><?php esc_html_e( 'Warning below', 'airfiber-centralized' ); ?></label>
								<input id="afc-manager-olt-warning" name="warning_dbm" type="number" min="-50" max="0" step="0.1" value="-24">
							</div>
							<div class="afc-olt-field">
								<label for="afc-manager-olt-critical"><?php esc_html_e( 'Critical below', 'airfiber-centralized' ); ?></label>
								<input id="afc-manager-olt-critical" name="critical_dbm" type="number" min="-50" max="0" step="0.1" value="-27">
							</div>
							<div class="afc-olt-field">
								<label for="afc-manager-olt-cache"><?php esc_html_e( 'Cache seconds', 'airfiber-centralized' ); ?></label>
								<input id="afc-manager-olt-cache" name="cache_ttl" type="number" min="60" max="900" value="300">
							</div>
							<div class="afc-olt-field">
								<label for="afc-manager-olt-timeout"><?php esc_html_e( 'Timeout ms', 'airfiber-centralized' ); ?></label>
								<input id="afc-manager-olt-timeout" name="timeout_ms" type="number" min="500" max="10000" step="100" value="2500">
							</div>
							<input name="retries" type="hidden" value="1">
						</div>
					</details>
				</form>

				<footer class="afc-olt-dialog-footer">
					<button type="button" class="afc-olt-btn is-ghost" data-afc-olt-save-draft><?php esc_html_e( 'Save as Draft', 'airfiber-centralized' ); ?></button>
					<button type="button" class="afc-olt-btn is-test" data-afc-olt-test><?php esc_html_e( 'Test Connection', 'airfiber-centralized' ); ?></button>
					<button type="button" class="afc-olt-btn is-primary" data-afc-olt-publish><?php esc_html_e( 'Publish', 'airfiber-centralized' ); ?></button>
				</footer>
			</div>

			<aside class="afc-olt-help" data-afc-olt-help aria-hidden="true">
				<div class="afc-olt-help-inner">
					<div class="afc-olt-help-header">
						<div><span><?php esc_html_e( 'Setup guide', 'airfiber-centralized' ); ?></span><h4><?php esc_html_e( 'Configure SNMP on the OLT', 'airfiber-centralized' ); ?></h4></div>
						<button type="button" data-afc-olt-help-close aria-label="<?php esc_attr_e( 'Close help', 'airfiber-centralized' ); ?>">×</button>
					</div>
					<ol class="afc-olt-help-steps">
						<li><strong><?php esc_html_e( 'Open the OLT SNMP page.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'In the OLT web interface, open the SNMP settings. Depending on firmware this may be under System, Network, Service, or Management.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'Enable SNMP.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Use SNMPv3 when available. Airfiber uses read-only monitoring and never sends configuration writes to the OLT.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'For SNMPv3 create a monitoring user.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Set security level to authPriv, authentication to SHA, privacy to DES, then enter the same username and passphrases in this form.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'For SNMPv2c use a dedicated read-only community.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Do not reuse a public/default community. Limit it to read-only access.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'Restrict the manager address.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Allow UDP port 161 only from this WordPress server or its private VPN/LAN address. Do not expose SNMP to the public internet.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'Save the OLT settings, then test here.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Test Connection reads the OLT system name and the RX-power table. A successful test stores the OLT-reported name for the card.', 'airfiber-centralized' ); ?></p></li>
					</ol>
					<div class="afc-olt-help-note"><strong><?php esc_html_e( 'Tip', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'The help panel stays open while you edit and remains open on your next visit until you close it.', 'airfiber-centralized' ); ?></p></div>
				</div>
			</aside>
		</div>
	</div>
</div>
