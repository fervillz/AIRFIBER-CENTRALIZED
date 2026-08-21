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
						<label for="afc-manager-olt-title"><?php esc_html_e( 'OLT display name', 'airfiber-centralized' ); ?></label>
						<input id="afc-manager-olt-title" name="title" type="text" placeholder="<?php esc_attr_e( 'e.g. Lingion Main OLT', 'airfiber-centralized' ); ?>">
						<small><?php esc_html_e( 'The friendly name you want to see inside Airfiber Centralized.', 'airfiber-centralized' ); ?></small>
					</div>

					<div class="afc-olt-field is-host">
						<label for="afc-manager-olt-host"><?php esc_html_e( 'OLT IP address', 'airfiber-centralized' ); ?></label>
						<input id="afc-manager-olt-host" name="host" type="text" placeholder="10.13.88.5">
					</div>
					<div class="afc-olt-field is-technology">
						<label for="afc-manager-olt-technology"><?php esc_html_e( 'OLT technology', 'airfiber-centralized' ); ?></label>
						<select id="afc-manager-olt-technology" name="technology">
							<option value="GPON"><?php esc_html_e( 'GPON', 'airfiber-centralized' ); ?></option>
							<option value="EPON"><?php esc_html_e( 'EPON', 'airfiber-centralized' ); ?></option>
						</select>
					</div>
					<div class="afc-olt-field is-port">
						<label class="afc-olt-label-with-help" for="afc-manager-olt-port">
							<span><?php esc_html_e( 'SNMP port', 'airfiber-centralized' ); ?></span>
							<span class="afc-olt-term-help" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'SNMP port help', 'airfiber-centralized' ); ?>" data-help="<?php esc_attr_e( 'The network port used for OLT monitoring. Most OLTs use port 161, so normally leave this as 161.', 'airfiber-centralized' ); ?>">?</span>
						</label>
						<input id="afc-manager-olt-port" name="port" type="number" min="1" max="65535" value="161">
					</div>
					<div class="afc-olt-field is-version">
						<label class="afc-olt-label-with-help" for="afc-manager-olt-version">
							<span><?php esc_html_e( 'Monitoring type', 'airfiber-centralized' ); ?></span>
							<span class="afc-olt-term-help" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'SNMP help', 'airfiber-centralized' ); ?>" data-help="<?php esc_attr_e( 'SNMP is the standard method Airfiber uses to read the OLT name, ONU status and RX signal. SNMPv3 is recommended because it uses a login and encryption.', 'airfiber-centralized' ); ?>">?</span>
						</label>
						<select id="afc-manager-olt-version" name="version">
							<option value="3"><?php esc_html_e( 'SNMPv3 (recommended)', 'airfiber-centralized' ); ?></option>
							<option value="2c"><?php esc_html_e( 'SNMPv2c', 'airfiber-centralized' ); ?></option>
						</select>
					</div>

					<div class="afc-olt-version-fields is-v2" data-afc-olt-v2 hidden>
						<div class="afc-olt-field is-wide">
							<label class="afc-olt-label-with-help" for="afc-manager-olt-community">
								<span><?php esc_html_e( 'Monitoring password', 'airfiber-centralized' ); ?></span>
								<span class="afc-olt-term-help" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'SNMPv2 community help', 'airfiber-centralized' ); ?>" data-help="<?php esc_attr_e( 'SNMPv2c calls this a community string. Enter the same read-only value you created in the OLT SNMP settings.', 'airfiber-centralized' ); ?>">?</span>
							</label>
							<input id="afc-manager-olt-community" name="community" type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Same read-only password used on the OLT', 'airfiber-centralized' ); ?>">
						</div>
					</div>

					<div class="afc-olt-version-fields is-v3" data-afc-olt-v3>
						<div class="afc-olt-field is-wide">
							<label class="afc-olt-label-with-help" for="afc-manager-olt-security-name">
								<span><?php esc_html_e( 'OLT monitoring username', 'airfiber-centralized' ); ?></span>
								<span class="afc-olt-term-help" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Monitoring username help', 'airfiber-centralized' ); ?>" data-help="<?php esc_attr_e( 'This is the SNMPv3 user or security name created inside the OLT. It is only used for monitoring. Enter exactly the same username here.', 'airfiber-centralized' ); ?>">?</span>
							</label>
							<input id="afc-manager-olt-security-name" name="security_name" type="text" value="airfiber-monitor">
						</div>
						<div class="afc-olt-field">
							<label class="afc-olt-label-with-help" for="afc-manager-olt-auth">
								<span><?php esc_html_e( 'Login password', 'airfiber-centralized' ); ?></span>
								<span class="afc-olt-term-help" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Authentication password help', 'airfiber-centralized' ); ?>" data-help="<?php esc_attr_e( 'This is called the SHA authentication passphrase in SNMPv3. It proves Airfiber is allowed to read the OLT. Use the same password configured on the OLT.', 'airfiber-centralized' ); ?>">?</span>
							</label>
							<input id="afc-manager-olt-auth" name="auth_passphrase" type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Minimum 8 characters', 'airfiber-centralized' ); ?>">
						</div>
						<div class="afc-olt-field">
							<label class="afc-olt-label-with-help" for="afc-manager-olt-privacy">
								<span><?php esc_html_e( 'Encryption password', 'airfiber-centralized' ); ?></span>
								<span class="afc-olt-term-help" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Encryption password help', 'airfiber-centralized' ); ?>" data-help="<?php esc_attr_e( 'This is called the DES privacy passphrase in this OLT firmware. It encrypts SNMP monitoring traffic. Use the same privacy password configured on the OLT.', 'airfiber-centralized' ); ?>">?</span>
							</label>
							<input id="afc-manager-olt-privacy" name="privacy_passphrase" type="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Minimum 8 characters', 'airfiber-centralized' ); ?>">
						</div>
					</div>

					<div class="afc-olt-field is-wide">
						<label class="afc-olt-label-with-help" for="afc-manager-olt-rx-oid">
							<span><?php esc_html_e( 'RX signal data path', 'airfiber-centralized' ); ?></span>
							<span class="afc-olt-term-help" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'RX OID help', 'airfiber-centralized' ); ?>" data-help="<?php esc_attr_e( 'The technical name is OID. It is the numeric address where this OLT stores ONU RX signal readings. Normally leave the default value unchanged.', 'airfiber-centralized' ); ?>">?</span>
						</label>
						<input id="afc-manager-olt-rx-oid" name="rx_oid" class="is-mono" type="text" value="<?php echo esc_attr( AFC_OLT_Manager::GPON_RX_OID ); ?>">
						<small><?php esc_html_e( 'Usually leave this unchanged. Airfiber uses it to find the ONU RX signal readings.', 'airfiber-centralized' ); ?></small>
					</div>

					<details class="afc-olt-advanced-fields">
						<summary><?php esc_html_e( 'Advanced connection settings', 'airfiber-centralized' ); ?></summary>
						<div class="afc-olt-advanced-grid">
							<div class="afc-olt-field">
								<label for="afc-manager-olt-warning"><?php esc_html_e( 'Weak signal warning', 'airfiber-centralized' ); ?></label>
								<input id="afc-manager-olt-warning" name="warning_dbm" type="number" min="-50" max="0" step="0.1" value="-24">
							</div>
							<div class="afc-olt-field">
								<label for="afc-manager-olt-critical"><?php esc_html_e( 'Critical signal level', 'airfiber-centralized' ); ?></label>
								<input id="afc-manager-olt-critical" name="critical_dbm" type="number" min="-50" max="0" step="0.1" value="-27">
							</div>
							<div class="afc-olt-field">
								<label for="afc-manager-olt-cache"><?php esc_html_e( 'RX cache time (seconds)', 'airfiber-centralized' ); ?></label>
								<input id="afc-manager-olt-cache" name="cache_ttl" type="number" min="60" max="900" value="300">
							</div>
							<div class="afc-olt-field">
								<label for="afc-manager-olt-timeout"><?php esc_html_e( 'Connection timeout (ms)', 'airfiber-centralized' ); ?></label>
								<input id="afc-manager-olt-timeout" name="timeout_ms" type="number" min="500" max="10000" step="100" value="2500">
							</div>
							<input name="retries" type="hidden" value="1">
						</div>
					</details>
				</form>

				<footer class="afc-olt-dialog-footer">
					<button type="button" class="afc-olt-btn is-ghost" data-afc-olt-secondary><?php esc_html_e( 'Save Draft', 'airfiber-centralized' ); ?></button>
					<button type="button" class="afc-olt-btn is-test" data-afc-olt-test><?php esc_html_e( 'Test Connection', 'airfiber-centralized' ); ?></button>
					<button type="button" class="afc-olt-btn is-primary" data-afc-olt-publish><?php esc_html_e( 'Publish OLT', 'airfiber-centralized' ); ?></button>
				</footer>
			</div>

			<aside class="afc-olt-help" data-afc-olt-help aria-hidden="true">
				<div class="afc-olt-help-inner">
					<div class="afc-olt-help-header">
						<div><span><?php esc_html_e( 'Setup guide', 'airfiber-centralized' ); ?></span><h4><?php esc_html_e( 'Configure OLT monitoring', 'airfiber-centralized' ); ?></h4></div>
						<button type="button" data-afc-olt-help-close aria-label="<?php esc_attr_e( 'Close help', 'airfiber-centralized' ); ?>">×</button>
					</div>
					<ol class="afc-olt-help-steps">
						<li><strong><?php esc_html_e( 'Open the SNMP settings on the OLT.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Log in to the OLT web interface and look for SNMP. Depending on the firmware it may be under System, Network, Service, or Management.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'Enable monitoring.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Enable SNMP and use SNMPv3 when it is available. Airfiber only reads monitoring information; it does not send configuration changes to the OLT.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'Create a monitoring login for SNMPv3.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Create a dedicated user. If the OLT asks for security level, choose authPriv (login + encryption). Set authentication to SHA and privacy/encryption to DES. Then copy the same username, login password and encryption password into this form.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'If you must use SNMPv2c, create a read-only password.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'The OLT may call this a community string. Use a dedicated read-only value and enter the same value in the Monitoring password field here.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'Allow only this server to connect.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'If the OLT lets you restrict the manager IP, allow UDP port 161 only from this WordPress server or its private VPN/LAN address. Do not expose SNMP to the public internet.', 'airfiber-centralized' ); ?></p></li>
						<li><strong><?php esc_html_e( 'Save, then test the connection.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Click Test Connection. Airfiber will try to read the OLT name and RX signal table. When it succeeds, the OLT-reported name and connection status are stored for the card.', 'airfiber-centralized' ); ?></p></li>
					</ol>
					<div class="afc-olt-help-note"><strong><?php esc_html_e( 'Tip', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'The help panel stays open while you edit and remains open on your next visit until you close it.', 'airfiber-centralized' ); ?></p></div>
				</div>
			</aside>
		</div>
	</div>
</div>
