<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps every Customer Inquiry template consistent with Airfiber's required
 * customer-information fields.
 */
class AFC_SMS_Inquiry_Fields {

	const VERSION        = '1.0.0';
	const OPTION_VERSION = 'afc_sms_inquiry_fields_version';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 4 );

		// Normalize inquiry templates before AFC_SMS_Templates saves them.
		add_action( 'wp_ajax_afc_sms_template_save', array( __CLASS__, 'normalize_save_request' ), 1 );

		// Restoring deleted starter templates happens inside an AJAX callback that
		// exits immediately. Normalize the restored rows during shutdown.
		add_action( 'wp_ajax_afc_sms_template_restore', array( __CLASS__, 'normalize_after_restore' ), 1 );
	}

	public static function maybe_migrate() {
		if ( self::VERSION === get_option( self::OPTION_VERSION ) ) {
			return;
		}

		self::normalize_existing_templates();
		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	public static function normalize_save_request() {
		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
		if ( 'inquiry' !== $category || ! isset( $_POST['body'] ) ) {
			return;
		}

		$body          = wp_unslash( $_POST['body'] );
		$_POST['body'] = wp_slash( self::normalize_body( $body ) );
	}

	public static function normalize_after_restore() {
		add_action( 'shutdown', array( __CLASS__, 'normalize_existing_templates' ), 1 );
	}

	public static function normalize_existing_templates() {
		global $wpdb;

		$table = $wpdb->prefix . 'afc_sms_templates';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $found ) {
			return;
		}

		$rows = $wpdb->get_results( "SELECT id, body FROM {$table} WHERE category = 'inquiry'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now  = current_time( 'mysql' );

		foreach ( (array) $rows as $row ) {
			$body = self::normalize_body( $row->body );
			if ( $body === (string) $row->body ) {
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'body'       => $body,
					'updated_at' => $now,
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	public static function normalize_body( $body ) {
		$body = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $body ) );

		// Convert common variants to one consistent set of labels.
		$replacements = array(
			'/^(\s*)(?:name|full\s*name)\s*:/im'                                  => '$1Full name:',
			'/^(\s*)(?:cp|cp\s*number|contact\s*number|mobile\s*number)\s*:/im' => '$1CP number:',
			'/^(\s*)(?:wi-?fi\s*name|wifi\s*name)\s*:/im'                       => '$1WiFi name:',
			'/^(\s*)(?:wi-?fi\s*(?:password|pass)|wifi\s*(?:password|pass))\s*:/im' => '$1WiFi pass:',
			'/^(\s*)(?:complete\s*address|address)\s*:/im'                       => '$1Address:',
		);

		foreach ( $replacements as $pattern => $replacement ) {
			$body = preg_replace( $pattern, $replacement, $body );
		}

		$required = array(
			'Full name:',
			'CP number:',
			'WiFi name:',
			'WiFi pass:',
			'Address:',
		);
		$missing = array();

		foreach ( $required as $field ) {
			if ( ! preg_match( '/^\s*' . preg_quote( $field, '/' ) . '/im', $body ) ) {
				$missing[] = $field;
			}
		}

		if ( $missing ) {
			$body .= ( '' === $body ? '' : "\n\n" ) . implode( "\n", $missing );
		}

		return trim( $body );
	}
}
