<?php

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<div class="container-xl py-4">
		<div class="page-header mb-4">
			<div>
				<div class="page-pretitle"><?php esc_html_e( 'Airfiber - Centralized', 'airfiber-centralized' ); ?></div>
				<h2 class="page-title"><?php esc_html_e( 'MikroTik Connection', 'airfiber-centralized' ); ?></h2>
			</div>
		</div>

		<?php if ( $notice ) : ?>
			<div class="alert <?php echo 'success' === $notice['type'] ? 'alert-success' : 'alert-danger'; ?>" role="alert">
				<?php echo esc_html( $notice['message'] ); ?>
			</div>
		<?php endif; ?>

		<div class="row">
			<div class="col-lg-8">
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="card">
					<?php settings_fields( 'afc_mikrotik' ); ?>
					<div class="card-header">
						<h3 class="card-title"><?php esc_html_e( 'RouterOS API', 'airfiber-centralized' ); ?></h3>
					</div>
					<div class="card-body">
						<div class="mb-3">
							<label class="form-label" for="afc-router-name"><?php esc_html_e( 'Router name', 'airfiber-centralized' ); ?></label>
							<input class="form-control" id="afc-router-name" name="afc_mikrotik_settings[name]" value="<?php echo esc_attr( $settings['name'] ); ?>" required>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label class="form-label" for="afc-router-host"><?php esc_html_e( 'IP address or hostname', 'airfiber-centralized' ); ?></label>
								<input class="form-control" id="afc-router-host" name="afc_mikrotik_settings[host]" value="<?php echo esc_attr( $settings['host'] ); ?>" placeholder="192.168.88.1" required>
							</div>
							<div class="col-md-3 mb-3">
								<label class="form-label" for="afc-router-protocol"><?php esc_html_e( 'Protocol', 'airfiber-centralized' ); ?></label>
								<select class="form-select" id="afc-router-protocol" name="afc_mikrotik_settings[protocol]">
									<option value="api" <?php selected( $settings['protocol'], 'api' ); ?>>API (TCP)</option>
									<option value="api-ssl" <?php selected( $settings['protocol'], 'api-ssl' ); ?>>API-SSL (TLS)</option>
								</select>
							</div>
							<div class="col-md-3 mb-3">
								<label class="form-label" for="afc-router-port"><?php esc_html_e( 'Port', 'airfiber-centralized' ); ?></label>
								<input class="form-control" type="number" min="1" max="65535" id="afc-router-port" name="afc_mikrotik_settings[port]" value="<?php echo esc_attr( $settings['port'] ); ?>" required>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label class="form-label" for="afc-router-user"><?php esc_html_e( 'Username', 'airfiber-centralized' ); ?></label>
								<input class="form-control" autocomplete="off" id="afc-router-user" name="afc_mikrotik_settings[username]" value="<?php echo esc_attr( $settings['username'] ); ?>" required>
							</div>
							<div class="col-md-6 mb-3">
								<label class="form-label" for="afc-router-password"><?php esc_html_e( 'Password', 'airfiber-centralized' ); ?></label>
								<div class="input-group">
									<input
										class="form-control"
										type="password"
										autocomplete="new-password"
										id="afc-router-password"
										name="afc_mikrotik_settings[password]"
										value="<?php echo $settings['password'] ? 'airfiber-saved-password' : ''; ?>"
										<?php disabled( ! empty( $settings['password'] ) ); ?>
									>
									<?php if ( $settings['password'] ) : ?>
										<button class="btn btn-outline-secondary" id="afc-change-password" type="button">
											<?php esc_html_e( 'Change', 'airfiber-centralized' ); ?>
										</button>
									<?php endif; ?>
								</div>
								<?php if ( $settings['password'] ) : ?>
									<small class="form-hint text-success" id="afc-password-status">
										<?php esc_html_e( 'Password saved securely. The dots are only a mask.', 'airfiber-centralized' ); ?>
									</small>
								<?php endif; ?>
							</div>
						</div>
						<label class="form-check">
							<input class="form-check-input" type="checkbox" name="afc_mikrotik_settings[verify_ssl]" value="1" <?php checked( $settings['verify_ssl'], 1 ); ?>>
							<span class="form-check-label"><?php esc_html_e( 'Verify the router SSL certificate', 'airfiber-centralized' ); ?></span>
						</label>
					</div>
					<div class="card-footer text-end">
						<button class="btn btn-primary" type="submit"><?php esc_html_e( 'Save Connection', 'airfiber-centralized' ); ?></button>
					</div>
				</form>

				<div id="afc-mikrotik-test-result" class="mt-3" aria-live="polite"></div>
				<button id="afc-test-mikrotik" class="btn btn-success mt-2" type="button">
					<?php esc_html_e( 'Test Saved Connection', 'airfiber-centralized' ); ?>
				</button>
			</div>
			<div class="col-lg-4">
				<div class="card">
					<div class="card-body">
						<h3 class="card-title"><?php esc_html_e( 'Before testing', 'airfiber-centralized' ); ?></h3>
						<p><?php esc_html_e( 'Enable the RouterOS API service and allow the WordPress server to reach port 8728, or use API-SSL on port 8729.', 'airfiber-centralized' ); ?></p>
						<p class="text-secondary mb-0"><?php esc_html_e( 'Use a dedicated RouterOS user. The test is read-only and requests system resource information.', 'airfiber-centralized' ); ?></p>
					</div>
				</div>
				<div class="card mt-3">
					<div class="card-header"><h3 class="card-title"><?php esc_html_e( 'Last Connection Test', 'airfiber-centralized' ); ?></h3></div>
					<div class="card-body">
						<?php if ( ! empty( $last_status ) ) : ?>
							<span class="badge <?php echo 'success' === $last_status['status'] ? 'bg-success-lt' : 'bg-danger-lt'; ?>">
								<?php echo esc_html( ucfirst( $last_status['status'] ) ); ?>
							</span>
							<p class="mt-2 mb-1"><?php echo esc_html( $last_status['message'] ); ?></p>
							<p class="text-secondary mb-0"><?php echo esc_html( $last_status['time'] ); ?></p>
						<?php else : ?>
							<p class="text-secondary mb-0"><?php esc_html_e( 'No connection test has been recorded yet.', 'airfiber-centralized' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
