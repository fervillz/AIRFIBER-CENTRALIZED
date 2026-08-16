<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap afc-admin-page">
	<div class="container-xl py-4">
		<div class="page-header mb-4">
			<div>
				<div class="page-pretitle"><?php esc_html_e( 'Airfiber - Centralized', 'airfiber-centralized' ); ?></div>
				<h2 class="page-title"><?php esc_html_e( 'OLT Optical Monitoring', 'airfiber-centralized' ); ?></h2>
				<p class="text-secondary mb-0"><?php esc_html_e( 'Read ONU receive power through restricted, read-only SNMP.', 'airfiber-centralized' ); ?></p>
			</div>
		</div>

		<?php settings_errors( AFC_OLT::OPTION_KEY ); ?>

		<div class="row row-cards">
			<div class="col-lg-8">
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="card">
					<?php settings_fields( 'afc_olt' ); ?>
					<div class="card-header">
						<div>
							<h3 class="card-title"><?php esc_html_e( 'Primary OLT Connection', 'airfiber-centralized' ); ?></h3>
							<p class="card-subtitle"><?php esc_html_e( 'SNMPv3 authPriv is recommended. All monitoring operations are read-only.', 'airfiber-centralized' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<label class="form-check form-switch mb-4">
							<input class="form-check-input" type="checkbox" name="afc_olt_settings[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?>>
							<span class="form-check-label"><?php esc_html_e( 'Enable optical monitoring', 'airfiber-centralized' ); ?></span>
						</label>

						<div class="mb-3">
							<label class="form-label" for="afc-olt-name"><?php esc_html_e( 'OLT name', 'airfiber-centralized' ); ?></label>
							<input class="form-control" id="afc-olt-name" name="afc_olt_settings[name]" value="<?php echo esc_attr( $settings['name'] ); ?>" required>
						</div>

						<div class="row">
							<div class="col-md-6 mb-3">
								<label class="form-label" for="afc-olt-host"><?php esc_html_e( 'IP address or hostname', 'airfiber-centralized' ); ?></label>
								<input class="form-control" id="afc-olt-host" name="afc_olt_settings[host]" value="<?php echo esc_attr( $settings['host'] ); ?>" placeholder="10.13.88.5" required>
							</div>
							<div class="col-md-3 mb-3">
								<label class="form-label" for="afc-olt-port"><?php esc_html_e( 'SNMP port', 'airfiber-centralized' ); ?></label>
								<input class="form-control" type="number" min="1" max="65535" id="afc-olt-port" name="afc_olt_settings[port]" value="<?php echo esc_attr( $settings['port'] ); ?>" required>
							</div>
							<div class="col-md-3 mb-3">
								<label class="form-label" for="afc-olt-version"><?php esc_html_e( 'SNMP version', 'airfiber-centralized' ); ?></label>
								<select class="form-select" id="afc-olt-version" name="afc_olt_settings[version]">
									<option value="3" <?php selected( $settings['version'], '3' ); ?>>SNMPv3</option>
									<option value="2c" <?php selected( $settings['version'], '2c' ); ?>>SNMPv2c</option>
								</select>
							</div>
						</div>

						<div class="afc-olt-v2-fields">
							<div class="mb-3">
								<label class="form-label" for="afc-olt-community"><?php esc_html_e( 'Read-only community', 'airfiber-centralized' ); ?></label>
								<input class="form-control" type="password" autocomplete="new-password" id="afc-olt-community" name="afc_olt_settings[community]" value="" placeholder="<?php echo esc_attr( AFC_OLT::has_saved_secret( 'community' ) ? __( 'Saved securely — leave blank to keep', 'airfiber-centralized' ) : __( 'Enter the dedicated read-only community', 'airfiber-centralized' ) ); ?>">
							</div>
						</div>

						<div class="afc-olt-v3-fields">
							<div class="mb-3">
								<label class="form-label" for="afc-olt-security-name"><?php esc_html_e( 'Security username', 'airfiber-centralized' ); ?></label>
								<input class="form-control" autocomplete="off" id="afc-olt-security-name" name="afc_olt_settings[security_name]" value="<?php echo esc_attr( $settings['security_name'] ); ?>">
							</div>
							<div class="row">
								<div class="col-md-6 mb-3">
									<label class="form-label" for="afc-olt-auth-passphrase"><?php esc_html_e( 'SHA authentication passphrase', 'airfiber-centralized' ); ?></label>
									<input class="form-control" type="password" autocomplete="new-password" id="afc-olt-auth-passphrase" name="afc_olt_settings[auth_passphrase]" value="" placeholder="<?php echo esc_attr( AFC_OLT::has_saved_secret( 'auth_passphrase' ) ? __( 'Saved securely — leave blank to keep', 'airfiber-centralized' ) : __( 'Minimum 8 characters', 'airfiber-centralized' ) ); ?>">
								</div>
								<div class="col-md-6 mb-3">
									<label class="form-label" for="afc-olt-privacy-passphrase"><?php esc_html_e( 'DES privacy passphrase', 'airfiber-centralized' ); ?></label>
									<input class="form-control" type="password" autocomplete="new-password" id="afc-olt-privacy-passphrase" name="afc_olt_settings[privacy_passphrase]" value="" placeholder="<?php echo esc_attr( AFC_OLT::has_saved_secret( 'privacy_passphrase' ) ? __( 'Saved securely — leave blank to keep', 'airfiber-centralized' ) : __( 'Minimum 8 characters', 'airfiber-centralized' ) ); ?>">
								</div>
							</div>
							<div class="alert alert-info py-2">
								<?php esc_html_e( 'Security level: authPriv · Authentication: SHA · Privacy: DES. These match the options exposed by this OLT firmware.', 'airfiber-centralized' ); ?>
							</div>
						</div>

						<div class="mb-3">
							<label class="form-label" for="afc-olt-rx-oid"><?php esc_html_e( 'RX-power column OID', 'airfiber-centralized' ); ?></label>
							<input class="form-control font-monospace" id="afc-olt-rx-oid" name="afc_olt_settings[rx_oid]" value="<?php echo esc_attr( $settings['rx_oid'] ); ?>" required>
							<small class="form-hint"><?php esc_html_e( 'The default is the V1600D optical diagnostics RX-power column.', 'airfiber-centralized' ); ?></small>
						</div>

						<div class="row">
							<div class="col-md-3 mb-3">
								<label class="form-label" for="afc-olt-warning"><?php esc_html_e( 'Warning below', 'airfiber-centralized' ); ?></label>
								<div class="input-group"><input class="form-control" type="number" min="-50" max="0" step="0.1" id="afc-olt-warning" name="afc_olt_settings[warning_dbm]" value="<?php echo esc_attr( $settings['warning_dbm'] ); ?>"><span class="input-group-text">dBm</span></div>
							</div>
							<div class="col-md-3 mb-3">
								<label class="form-label" for="afc-olt-critical"><?php esc_html_e( 'Critical below', 'airfiber-centralized' ); ?></label>
								<div class="input-group"><input class="form-control" type="number" min="-50" max="0" step="0.1" id="afc-olt-critical" name="afc_olt_settings[critical_dbm]" value="<?php echo esc_attr( $settings['critical_dbm'] ); ?>"><span class="input-group-text">dBm</span></div>
							</div>
							<div class="col-md-3 mb-3">
								<label class="form-label" for="afc-olt-cache"><?php esc_html_e( 'Cache duration', 'airfiber-centralized' ); ?></label>
								<div class="input-group"><input class="form-control" type="number" min="60" max="900" id="afc-olt-cache" name="afc_olt_settings[cache_ttl]" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>"><span class="input-group-text">sec</span></div>
							</div>
							<div class="col-md-3 mb-3">
								<label class="form-label" for="afc-olt-timeout"><?php esc_html_e( 'Timeout', 'airfiber-centralized' ); ?></label>
								<div class="input-group"><input class="form-control" type="number" min="500" max="10000" step="100" id="afc-olt-timeout" name="afc_olt_settings[timeout_ms]" value="<?php echo esc_attr( $settings['timeout_ms'] ); ?>"><span class="input-group-text">ms</span></div>
								<input type="hidden" name="afc_olt_settings[retries]" value="<?php echo esc_attr( $settings['retries'] ); ?>">
							</div>
						</div>
					</div>
					<div class="card-footer d-flex justify-content-between align-items-center">
						<span class="text-secondary small"><?php esc_html_e( 'Secrets are encrypted using the WordPress authentication key.', 'airfiber-centralized' ); ?></span>
						<button class="btn btn-primary" type="submit"><?php esc_html_e( 'Save OLT Connection', 'airfiber-centralized' ); ?></button>
					</div>
				</form>

				<div id="afc-olt-test-result" class="mt-3" aria-live="polite"></div>
				<button id="afc-test-olt" class="btn btn-success mt-2" type="button">
					<?php esc_html_e( 'Test Saved Connection', 'airfiber-centralized' ); ?>
				</button>
			</div>

			<div class="col-lg-4">
				<div class="card">
					<div class="card-header"><h3 class="card-title"><?php esc_html_e( 'Server readiness', 'airfiber-centralized' ); ?></h3></div>
					<div class="card-body">
						<?php if ( AFC_OLT::is_snmp_available( $settings['version'] ) ) : ?>
							<div class="d-flex align-items-center gap-2 mb-2"><span class="status status-green"></span><strong><?php esc_html_e( 'PHP SNMP is available', 'airfiber-centralized' ); ?></strong></div>
						<?php else : ?>
							<div class="alert alert-warning"><?php esc_html_e( 'Install and enable the PHP SNMP extension on this WordPress server before testing.', 'airfiber-centralized' ); ?></div>
						<?php endif; ?>
						<p><?php esc_html_e( 'Allow UDP/161 to the OLT only from this server or its private VPN address. Never expose SNMP publicly.', 'airfiber-centralized' ); ?></p>
						<p class="text-secondary mb-0"><?php esc_html_e( 'The dashboard uses one bulk OID walk and caches the complete result. It never polls once per customer.', 'airfiber-centralized' ); ?></p>
					</div>
				</div>

				<div class="card mt-3">
					<div class="card-header"><h3 class="card-title"><?php esc_html_e( 'Last connection test', 'airfiber-centralized' ); ?></h3></div>
					<div class="card-body">
						<?php if ( ! empty( $last_status ) ) : ?>
							<span class="badge <?php echo 'success' === $last_status['status'] ? 'bg-success-lt' : 'bg-danger-lt'; ?>">
								<?php echo esc_html( ucfirst( $last_status['status'] ) ); ?>
							</span>
							<p class="mt-2 mb-1"><?php echo esc_html( $last_status['message'] ); ?></p>
							<p class="text-secondary mb-0"><?php echo esc_html( $last_status['time'] ); ?></p>
						<?php else : ?>
							<p class="text-secondary mb-0"><?php esc_html_e( 'No OLT connection test has been recorded yet.', 'airfiber-centralized' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
