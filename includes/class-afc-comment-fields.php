<?php

defined( 'ABSPATH' ) || exit;

/**
 * Defines the schema used to read and safely update structured MikroTik PPP
 * comments. Core fields remain protected; administrators may add custom fields
 * for billing and future automation without rewriting existing PPP secrets.
 */
class AFC_Comment_Fields {

	const OPTION_KEY = 'afc_comment_fields';
	const NONCE      = 'afc_comment_fields';
	const MAX_FIELDS = 30;

	public static function init() {
		add_action( 'wp_ajax_afc_save_comment_fields', array( __CLASS__, 'ajax_save_fields' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 40 );
		add_action( 'afc_frontend_app_content', array( __CLASS__, 'render_frontend_panel' ) );
	}

	public static function core_fields() {
		return array(
			array( 'key' => 'installed', 'label' => __( 'Installed Date', 'airfiber-centralized' ), 'type' => 'date', 'default' => '', 'core' => true ),
			array( 'key' => 'grace', 'label' => __( 'Grace Days', 'airfiber-centralized' ), 'type' => 'number', 'default' => '6', 'core' => true ),
			array( 'key' => 'paymentMethod', 'label' => __( 'Payment Method', 'airfiber-centralized' ), 'type' => 'text', 'default' => 'cash', 'core' => true ),
			array( 'key' => 'paymentAmount', 'label' => __( 'Payment Amount', 'airfiber-centralized' ), 'type' => 'number', 'default' => '', 'core' => true ),
			array( 'key' => 'paymentDate', 'label' => __( 'Payment Date', 'airfiber-centralized' ), 'type' => 'date', 'default' => '', 'core' => true ),
			array( 'key' => 'name', 'label' => __( 'Customer Name', 'airfiber-centralized' ), 'type' => 'text', 'default' => '', 'core' => true ),
			array( 'key' => 'plan', 'label' => __( 'Plan', 'airfiber-centralized' ), 'type' => 'text', 'default' => '', 'core' => true ),
			array( 'key' => 'cp', 'label' => __( 'Contact Number', 'airfiber-centralized' ), 'type' => 'text', 'default' => '', 'core' => true ),
			array( 'key' => 'wifi', 'label' => __( 'Wi-Fi Name', 'airfiber-centralized' ), 'type' => 'text', 'default' => '', 'core' => true ),
			array( 'key' => 'password', 'label' => __( 'Wi-Fi Password', 'airfiber-centralized' ), 'type' => 'text', 'default' => '', 'core' => true ),
			array( 'key' => 'Address', 'label' => __( 'Address', 'airfiber-centralized' ), 'type' => 'text', 'default' => '', 'core' => true ),
		);
	}

	public static function suggested_fields() {
		return array(
			array( 'key' => 'billingDay', 'label' => __( 'Billing Day', 'airfiber-centralized' ), 'type' => 'number', 'default' => '' ),
			array( 'key' => 'paidThrough', 'label' => __( 'Paid Through', 'airfiber-centralized' ), 'type' => 'date', 'default' => '' ),
			array( 'key' => 'nextDue', 'label' => __( 'Next Due', 'airfiber-centralized' ), 'type' => 'date', 'default' => '' ),
			array( 'key' => 'cutoffDate', 'label' => __( 'Cutoff Date', 'airfiber-centralized' ), 'type' => 'date', 'default' => '' ),
		);
	}

	public static function allowed_types() {
		return array(
			'text'    => __( 'Text', 'airfiber-centralized' ),
			'number'  => __( 'Number', 'airfiber-centralized' ),
			'date'    => __( 'Date', 'airfiber-centralized' ),
			'boolean' => __( 'Yes / No', 'airfiber-centralized' ),
		);
	}

	private static function sanitize_field_key( $key ) {
		$key = trim( wp_strip_all_tags( (string) $key ) );
		$key = preg_replace( '/[^A-Za-z0-9_-]/', '', $key );
		$key = substr( $key, 0, 40 );

		return preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $key ) ? $key : '';
	}

	private static function reserved_keys() {
		$keys = array( 'addr' );
		foreach ( self::core_fields() as $field ) {
			$keys[] = strtolower( $field['key'] );
		}
		return array_unique( $keys );
	}

	public static function sanitize_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$allowed  = self::allowed_types();
		$reserved = self::reserved_keys();
		$seen     = array();
		$clean    = array();

		foreach ( array_slice( $fields, 0, self::MAX_FIELDS ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key       = self::sanitize_field_key( isset( $field['key'] ) ? $field['key'] : '' );
			$lower_key = strtolower( $key );
			if ( ! $key || in_array( $lower_key, $reserved, true ) || isset( $seen[ $lower_key ] ) ) {
				continue;
			}

			$label = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
			$label = $label ? substr( $label, 0, 60 ) : $key;
			$type  = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
			$type  = isset( $allowed[ $type ] ) ? $type : 'text';

			$default = isset( $field['default'] ) ? sanitize_text_field( $field['default'] ) : '';
			$default = substr( $default, 0, 120 );

			$clean[] = array(
				'key'     => $key,
				'label'   => $label,
				'type'    => $type,
				'default' => $default,
				'core'    => false,
			);
			$seen[ $lower_key ] = true;
		}

		return $clean;
	}

	public static function get_custom_fields() {
		return self::sanitize_fields( get_option( self::OPTION_KEY, array() ) );
	}

	public static function get_fields() {
		return array_merge( self::core_fields(), self::get_custom_fields() );
	}

	public static function get_comment_keys() {
		$keys = array();
		foreach ( self::get_fields() as $field ) {
			$keys[] = $field['key'];
		}
		$keys[] = 'addr';

		usort(
			$keys,
			function ( $first, $second ) {
				return strlen( $second ) - strlen( $first );
			}
		);

		return array_values( array_unique( $keys ) );
	}

	public static function get_keys_pattern() {
		return implode(
			'|',
			array_map(
				function ( $key ) {
					return preg_quote( $key, '/' );
				},
				self::get_comment_keys()
			)
		);
	}

	/**
	 * Parse the current core values and expose custom values under custom_fields.
	 * Password remains deliberately excluded from returned account data.
	 */
	public static function parse_comment( $comment ) {
		$values = array(
			'installed'      => '',
			'grace'          => '',
			'payment_method' => '',
			'payment_amount' => '',
			'payment_date'   => '',
			'name'           => '',
			'plan'           => '',
			'cp'             => '',
			'wifi'           => '',
			'address'        => '',
			'custom_fields'  => array(),
		);

		$key_lookup = array();
		$core_map   = array(
			'installed'     => 'installed',
			'grace'         => 'grace',
			'paymentmethod' => 'payment_method',
			'paymentamount' => 'payment_amount',
			'paymentdate'   => 'payment_date',
			'name'          => 'name',
			'plan'          => 'plan',
			'cp'            => 'cp',
			'wifi'          => 'wifi',
			'address'       => 'address',
			'addr'          => 'address',
		);

		foreach ( self::get_fields() as $field ) {
			$key_lookup[ strtolower( $field['key'] ) ] = $field;
			if ( empty( $field['core'] ) ) {
				$values['custom_fields'][ $field['key'] ] = '';
			}
		}

		$keys = self::get_keys_pattern();
		preg_match_all(
			'/(?:^|\s)(' . $keys . ')\s*:\s*(.*?)(?=\s+(?:' . $keys . ')\s*:|$)/is',
			trim( (string) $comment ),
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			$raw_key   = $match[1];
			$lower_key = strtolower( $raw_key );
			$value     = trim( preg_replace( '/\s+/', ' ', $match[2] ) );

			if ( 'N/A' === strtoupper( $value ) ) {
				$value = '';
			}
			if ( 'password' === $lower_key ) {
				continue;
			}
			if ( isset( $core_map[ $lower_key ] ) ) {
				$values[ $core_map[ $lower_key ] ] = $value;
				continue;
			}
			if ( isset( $key_lookup[ $lower_key ] ) && empty( $key_lookup[ $lower_key ]['core'] ) ) {
				$values['custom_fields'][ $key_lookup[ $lower_key ]['key'] ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Replace one value while recognizing every configured field as a boundary.
	 * This prevents payment or address updates from swallowing adjacent custom
	 * fields in the MikroTik comment.
	 */
	public static function replace_value( $comment, $key, $value ) {
		$key         = self::sanitize_field_key( $key );
		$keys        = self::get_keys_pattern();
		$key_pattern = 0 === strcasecmp( $key, 'Address' ) ? '(?:Address|addr)' : preg_quote( $key, '/' );
		$pattern     = '/(' . $key_pattern . '\s*:\s*)(.*?)(?=\s+(?:' . $keys . ')\s*:|$)/is';

		if ( $key && preg_match( $pattern, (string) $comment ) ) {
			return preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $value ) {
					return $matches[1] . $value;
				},
				(string) $comment,
				1
			);
		}

		return rtrim( (string) $comment ) . ( trim( (string) $comment ) ? "\n" : '' ) . $key . ':' . $value;
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-comment-fields',
			AFC_URL . 'assets/css/comment-fields.css',
			array( 'afc-frontend-app' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-comment-fields',
			AFC_URL . 'assets/js/comment-fields.js',
			array( 'afc-frontend-app', 'afc-admin-mode' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-comment-fields',
			'afcCommentFields',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'fields'      => self::get_fields(),
				'coreCount'   => count( self::core_fields() ),
				'types'       => self::allowed_types(),
				'suggestions' => self::suggested_fields(),
				'maxFields'   => self::MAX_FIELDS,
				'labels'      => array(
					'nav'       => __( 'Comment Fields', 'airfiber-centralized' ),
					'saving'    => __( 'Saving…', 'airfiber-centralized' ),
					'save'      => __( 'Save Fields', 'airfiber-centralized' ),
					'saved'     => __( 'Comment fields saved.', 'airfiber-centralized' ),
					'failed'    => __( 'The comment fields could not be saved.', 'airfiber-centralized' ),
					'remove'    => __( 'Remove', 'airfiber-centralized' ),
					'core'      => __( 'Core', 'airfiber-centralized' ),
					'custom'    => __( 'Custom', 'airfiber-centralized' ),
					'newField'  => __( 'New Field', 'airfiber-centralized' ),
					'duplicate' => __( 'Each field key must be unique.', 'airfiber-centralized' ),
				),
			)
		);
	}

	public static function render_frontend_panel() {
		?>
		<section class="afc-frontend-panel afc-advanced-only afc-comment-fields-panel" data-afc-panel="comment-fields" aria-hidden="true" hidden>
			<div class="afc-comment-fields-shell">
				<header class="afc-comment-fields-header">
					<div>
						<span class="afc-comment-fields-kicker"><?php esc_html_e( 'Advanced configuration', 'airfiber-centralized' ); ?></span>
						<h1><?php esc_html_e( 'PPP Comment Fields', 'airfiber-centralized' ); ?></h1>
						<p><?php esc_html_e( 'Define additional key:value fields that Airfiber should recognize in MikroTik PPP comments. Saving this schema does not change existing PPP users.', 'airfiber-centralized' ); ?></p>
					</div>
					<button class="btn btn-primary" id="afc-comment-fields-save" type="button"><?php esc_html_e( 'Save Fields', 'airfiber-centralized' ); ?></button>
				</header>

				<div id="afc-comment-fields-notice" class="afc-comment-fields-notice" aria-live="polite"></div>

				<div class="afc-comment-fields-layout">
					<div class="afc-comment-fields-card">
						<div class="afc-comment-fields-card-head">
							<div>
								<h2><?php esc_html_e( 'Field schema', 'airfiber-centralized' ); ?></h2>
								<p><?php esc_html_e( 'Core fields are locked. Custom fields can be reordered or removed.', 'airfiber-centralized' ); ?></p>
							</div>
							<button class="btn btn-outline-primary" id="afc-comment-fields-add" type="button">+ <?php esc_html_e( 'Add Field', 'airfiber-centralized' ); ?></button>
						</div>
						<div class="afc-comment-fields-list" id="afc-comment-fields-list"></div>
					</div>

					<aside class="afc-comment-fields-side">
						<div class="afc-comment-fields-card">
							<h2><?php esc_html_e( 'Suggested billing fields', 'airfiber-centralized' ); ?></h2>
							<p><?php esc_html_e( 'Add these individually now; their calculation rules can be connected later.', 'airfiber-centralized' ); ?></p>
							<div class="afc-comment-fields-suggestions" id="afc-comment-fields-suggestions"></div>
						</div>

						<div class="afc-comment-fields-card">
							<h2><?php esc_html_e( 'Comment preview', 'airfiber-centralized' ); ?></h2>
							<p><?php esc_html_e( 'This shows the recognized structure only. It is not written to MikroTik when the schema is saved.', 'airfiber-centralized' ); ?></p>
							<pre id="afc-comment-fields-preview"></pre>
						</div>
					</aside>
				</div>
			</div>
		</section>
		<?php
	}

	public static function ajax_save_fields() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change comment fields.', 'airfiber-centralized' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
		$raw = isset( $_POST['fields'] ) ? json_decode( wp_unslash( $_POST['fields'] ), true ) : array();
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'The field configuration is invalid.', 'airfiber-centralized' ) ), 400 );
		}

		$fields = self::sanitize_fields( $raw );
		update_option( self::OPTION_KEY, $fields, false );

		wp_send_json_success(
			array(
				'message' => __( 'Comment fields saved.', 'airfiber-centralized' ),
				'fields'  => self::get_fields(),
			)
		);
	}
}
