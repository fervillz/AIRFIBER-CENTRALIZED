<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps every Customer Inquiry template consistent with Airfiber's required
 * customer-information fields.
 */
class AFC_SMS_Inquiry_Fields {

	const VERSION        = '1.1.0';
	const OPTION_VERSION = 'afc_sms_inquiry_fields_version';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 4 );
		add_action( 'wp_ajax_afc_sms_template_save', array( __CLASS__, 'normalize_save_request' ), 1 );
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
		$_POST['body'] = wp_slash( self::normalize_body( wp_unslash( $_POST['body'] ) ) );
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
				array( 'body' => $body, 'updated_at' => $now ),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	public static function normalize_body( $body ) {
		$body = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $body ) );

		// PPP usernames are created internally from Name_DueDay_Plan and should
		// never be requested from an inquiry customer.
		$body = preg_replace(
			'/^\s*(?:ppp(?:\s*\/\s*account)?\s*(?:username|user|name)?|account\s*(?:username|user|name))\s*:.*(?:\n|$)/im',
			'',
			$body
		);

		$field_patterns = array(
			'/^\s*(?:name|full\s*name)\s*:.*(?:\n|$)/im',
			'/^\s*(?:cp|cp\s*number|contact\s*number|mobile\s*number)\s*:.*(?:\n|$)/im',
			'/^\s*(?:wi-?fi\s*name|wifi\s*name)\s*:.*(?:\n|$)/im',
			'/^\s*(?:wi-?fi\s*(?:password|pass)|wifi\s*(?:password|pass))\s*:.*(?:\n|$)/im',
			'/^\s*(?:complete\s*address|address)\s*:.*(?:\n|$)/im',
			'/^\s*(?:nearest\s*landmark|landmark)\s*:.*(?:\n|$)/im',
			'/^\s*(?:preferred\s*plan|internet\s*plan|plan)\s*:.*(?:\n|$)/im',
		);
		foreach ( $field_patterns as $pattern ) {
			$body = preg_replace( $pattern, '', $body );
		}

		$body = trim( preg_replace( "/\n{3,}/", "\n\n", $body ) );
		$required = implode(
			"\n",
			array(
				'Full name:',
				'CP number:',
				'WiFi name:',
				'WiFi pass:',
				'Address:',
				'Landmark:',
				'Plan:',
			)
		);

		return trim( $body . ( '' === $body ? '' : "\n\n" ) . $required );
	}
}
