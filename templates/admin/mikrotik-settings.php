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
				<form method="post" action="options.php" class="card">
					<?php settings_fields( 'afc_mikrotik' ); ?>
					<div class="card-header">
						<h3 class="card-title"><?php esc_html_e( 'RouterOS REST API', 'airfiber-centralized' ); ?></h3>
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
									<option value="https" <?php selected( $settings['protocol'], 'https' ); ?>>HTTPS</option>
									<option value="http" <?php selected( $settings['protocol'], 'http' ); ?>>HTTP</option>
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
								<input class="form-control" type="password" autocomplete="new-password" id="afc-router-password" name="afc_mikrotik_settings[password]" placeholder="<?php echo $settings['password'] ? esc_attr__( 'Saved - leave blank to keep it', 'airfiber-centralized' ) : ''; ?>">
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

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mt-3">
					<input type="hidden" name="action" value="afc_test_mikrotik">
					<?php wp_nonce_field( 'afc_test_mikrotik' ); ?>
					<button class="btn btn-success" type="submit"><?php esc_html_e( 'Test Saved Connection', 'airfiber-centralized' ); ?></button>
				</form>
			</div>
			<div class="col-lg-4">
				<div class="card">
					<div class="card-body">
						<h3 class="card-title"><?php esc_html_e( 'Before testing', 'airfiber-centralized' ); ?></h3>
						<p><?php esc_html_e( 'Enable the RouterOS www-ssl service for HTTPS REST access and allow the WordPress server to reach the selected port.', 'airfiber-centralized' ); ?></p>
						<p class="text-secondary mb-0"><?php esc_html_e( 'Use a dedicated RouterOS user. The test is read-only and requests system resource information.', 'airfiber-centralized' ); ?></p>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

