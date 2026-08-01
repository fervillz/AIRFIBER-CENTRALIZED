<?php

defined( 'ABSPATH' ) || exit;

/**
 * Stores one reusable PPP customer password and optionally applies it to
 * existing customer PPP secrets. The stored custom value is encrypted with
 * the WordPress salts when OpenSSL is available.
 */
class AFC_PPP_Master_Password {

	const OPTION_KEY       = 'afc_ppp_master_password';
	const DEFAULT_PASSWORD = '55555555';
	const NONCE            = 'afc_ppp_master_password';

	public static function init() {
		add_filter( 'afc_ppp_new_password', array( __CLASS__, 'filter_new_password' ) );
		add_action( 'wp_ajax_afc_ppp_save_master_password', array( __CLASS__, 'ajax_save' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 78 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 78 );
		add_action( 'wp_footer', array( __CLASS__, 'render_frontend_dialog' ) );
		add_action( 'admin_footer-toplevel_page_airfiber-centralized', array( __CLASS__, 'render_admin_dialog' ) );
	}

	public static function filter_new_password( $generated ) {
		return self::get_effective_password();
	}

	public static function get_effective_password() {
		$stored = get_option( self::OPTION_KEY, '' );
		$value  = self::decrypt_secret( $stored );

		return '' !== $value ? $value : self::DEFAULT_PASSWORD;
	}

	private static function has_custom_password() {
		return '' !== self::decrypt_secret( get_option( self::OPTION_KEY, '' ) );
	}

	private static function encryption_key() {
		$material = function_exists( 'wp_salt' )
			? wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' )
			: ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );

		return hash( 'sha256', $material, true );
	}

	private static function encrypt_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			try {
				$iv = random_bytes( 12 );
			} catch ( Exception $exception ) {
				$iv = false;
			}

			if ( false !== $iv ) {
				$tag    = '';
				$cipher = openssl_encrypt(
					$value,
					'aes-256-gcm',
					self::encryption_key(),
					OPENSSL_RAW_DATA,
					$iv,
					$tag
				);

				if ( false !== $cipher && 16 === strlen( $tag ) ) {
					return 'gcm1:' . base64_encode( $iv . $tag . $cipher );
				}
			}
		}

		// Compatibility fallback for hosts without OpenSSL.
		return 'plain1:' . base64_encode( $value );
	}

	private static function decrypt_secret( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'gcm1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 5 ), true );
			if ( false === $raw || strlen( $raw ) < 29 ) {
				return '';
			}

			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt(
				$cipher,
				'aes-256-gcm',
				self::encryption_key(),
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);

			return false === $plain ? '' : (string) $plain;
		}

		if ( 0 === strpos( $stored, 'plain1:' ) ) {
			$plain = base64_decode( substr( $stored, 7 ), true );
			return false === $plain ? '' : (string) $plain;
		}

		// Support a legacy unencrypted value if one was manually added.
		return $stored;
	}

	private static function sanitize_password( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		return substr( trim( $value ), 0, 64 );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change PPP settings.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	public static function ajax_save() {
		self::authorize();

		$password = isset( $_POST['master_password'] )
			? self::sanitize_password( wp_unslash( $_POST['master_password'] ) )
			: '';

		if ( '' !== $password && strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'The master PPP password must contain at least 8 characters.', 'airfiber-centralized' ) ) );
		}

		if ( '' === $password ) {
			delete_option( self::OPTION_KEY );
		} else {
			update_option( self::OPTION_KEY, self::encrypt_secret( $password ), false );
		}

		$updated = 0;
		$skipped = 0;
		$errors  = array();

		if ( ! empty( $_POST['apply_existing'] ) ) {
			$result = self::apply_to_existing( self::get_effective_password() );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							__( 'The master password was saved, but existing PPP accounts could not be updated: %s', 'airfiber-centralized' ),
							$result->get_error_message()
						),
					)
				);
			}
			$updated = $result['updated'];
			$skipped = $result['skipped'];
			$errors  = $result['errors'];
		}

		$custom = self::has_custom_password();
		$message = $custom
			? __( 'Custom master PPP password saved. New PPP accounts will use it.', 'airfiber-centralized' )
			: sprintf( __( 'Master PPP password reset to the default: %s.', 'airfiber-centralized' ), self::DEFAULT_PASSWORD );

		if ( ! empty( $_POST['apply_existing'] ) ) {
			$message .= ' ' . sprintf(
				__( '%1$d existing customer account(s) updated; %2$d non-customer or unsupported secret(s) skipped.', 'airfiber-centralized' ),
				$updated,
				$skipped
			);
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'custom'  => $custom,
				'updated' => $updated,
				'skipped' => $skipped,
				'errors'  => array_slice( $errors, 0, 10 ),
			)
		);
	}

	private static function apply_to_existing( $password ) {
		$secrets = AFC_MikroTik::run_command(
			array(
				'/ppp/secret/print',
				'=.proplist=.id,name,service,profile,comment',
			)
		);
		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}
		if ( isset( $secrets['name'] ) ) {
			$secrets = array( $secrets );
		}

		$updated = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( (array) $secrets as $secret ) {
			$id      = isset( $secret['.id'] ) ? (string) $secret['.id'] : '';
			$name    = isset( $secret['name'] ) ? (string) $secret['name'] : '';
			$service = isset( $secret['service'] ) ? strtolower( trim( (string) $secret['service'] ) ) : '';
			$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
			$marked  = (bool) preg_match( '/(?:^|\s)(?:installed|name|plan|cp)\s*:/i', $comment );
			$is_customer = 'pppoe' === $service || ( in_array( $service, array( '', 'any' ), true ) && $marked );

			if ( ! $id || ! $name || ! $is_customer ) {
				$skipped++;
				continue;
			}

			$result = AFC_MikroTik::run_command(
				array(
					'/ppp/secret/set',
					'=.id=' . $id,
					'=password=' . $password,
				)
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = $name . ': ' . $result->get_error_message();
				continue;
			}
			$updated++;
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_assets();
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_assets();
	}

	private static function enqueue_assets() {
		wp_enqueue_style(
			'afc-ppp-master-password',
			AFC_URL . 'assets/css/ppp-master-password.css',
			array( 'afc-ppp-manager' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-ppp-master-password',
			AFC_URL . 'assets/js/ppp-master-password.js',
			array( 'jquery', 'afc-ppp-manager' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-ppp-master-password',
			'afcPPPMasterPassword',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE ),
				'custom'          => self::has_custom_password(),
				'defaultPassword' => self::DEFAULT_PASSWORD,
			)
		);
	}

	public static function render_frontend_dialog() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::render_dialog();
	}

	public static function render_admin_dialog() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::render_dialog();
	}

	private static function render_dialog() {
		?>
		<dialog id="afc-ppp-master-password-dialog" class="afc-dialog afc-ppp-manager-dialog afc-ppp-password-dialog">
			<form id="afc-ppp-master-password-form">
				<div class="afc-dialog-header">
					<div>
						<div class="text-secondary small"><?php esc_html_e( 'Shared customer login setting', 'airfiber-centralized' ); ?></div>
						<h3 class="mb-0"><?php esc_html_e( 'PPP Settings', 'airfiber-centralized' ); ?></h3>
					</div>
					<button class="btn-close" type="button" data-afc-ppp-password-close aria-label="<?php esc_attr_e( 'Close', 'airfiber-centralized' ); ?>"></button>
				</div>
				<div class="afc-dialog-body">
					<div id="afc-ppp-password-notice" aria-live="polite"></div>
					<div class="afc-ppp-password-status">
						<span><?php esc_html_e( 'Current setting', 'airfiber-centralized' ); ?></span>
						<strong id="afc-ppp-password-status-text"></strong>
					</div>
					<label class="form-label" for="afc-ppp-master-password-input"><?php esc_html_e( 'Master PPP password', 'airfiber-centralized' ); ?></label>
					<div class="input-group">
						<input class="form-control" id="afc-ppp-master-password-input" type="password" minlength="8" maxlength="64" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to use 55555555', 'airfiber-centralized' ); ?>">
						<button class="btn btn-outline-secondary" id="afc-toggle-master-password" type="button"><?php esc_html_e( 'Show', 'airfiber-centralized' ); ?></button>
					</div>
					<p class="text-secondary small mt-2"><?php esc_html_e( 'Saving a blank field resets the master password to 55555555. A saved custom password is encrypted and is not displayed again.', 'airfiber-centralized' ); ?></p>
					<label class="form-check mt-4 afc-ppp-password-apply">
						<input class="form-check-input" id="afc-apply-master-password-existing" type="checkbox" value="1">
						<span class="form-check-label">
							<strong><?php esc_html_e( 'Apply this password to all existing customer PPP accounts now', 'airfiber-centralized' ); ?></strong>
							<small><?php esc_html_e( 'This changes their router login immediately. Non-customer/VPN secrets are skipped.', 'airfiber-centralized' ); ?></small>
						</span>
					</label>
				</div>
				<div class="afc-dialog-footer">
					<button class="btn btn-link" type="button" data-afc-ppp-password-close><?php esc_html_e( 'Close', 'airfiber-centralized' ); ?></button>
					<button class="btn btn-primary" id="afc-save-master-ppp-password" type="button"><?php esc_html_e( 'Save PPP Password', 'airfiber-centralized' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}
}
