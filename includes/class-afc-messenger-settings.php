<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reusable Facebook Messenger payment-command settings.
 *
 * This class intentionally owns configuration, secret storage, reply templates,
 * authorized staff and learned aliases. The webhook/command processor can use
 * these public helpers without hard-coding Airfiber-specific wording.
 */
class AFC_Messenger_Settings {

	const NONCE           = 'afc_messenger_settings';
	const OPTION_SETTINGS = 'afc_messenger_settings';
	const OPTION_SECRETS  = 'afc_messenger_secrets';
	const OPTION_ALIASES  = 'afc_messenger_aliases';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 140 );
		add_action( 'wp_ajax_afc_messenger_settings_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_afc_messenger_settings_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_afc_messenger_settings_reset_templates', array( __CLASS__, 'ajax_reset_templates' ) );
		add_action( 'wp_ajax_afc_messenger_settings_clear_aliases', array( __CLASS__, 'ajax_clear_aliases' ) );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-messenger-settings',
			AFC_URL . 'assets/css/messenger-settings.css',
			array( 'afc-integrations' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-messenger-settings',
			AFC_URL . 'assets/js/messenger-settings.js',
			array( 'afc-integrations' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-messenger-settings',
			'afcMessengerSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage Messenger settings.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function default_templates() {
		return array(
			'payment_recorded' => "✅ Payment recorded\n\n{name}\nAccount: {ppp}\nAmount: ₱{amount}\nMethod: {method}\nStatus: {status}\nDue date: {due_date}\nRecorded by: {sender}",
			'payment_forward'  => '{ppp}',
			'status_active'    => '{ppp} active duedate-{due_date}',
			'status_expired'   => '{ppp} expired-{expiry_date} duedate-{due_date}',
			'status_disabled'  => '{ppp} disabled duedate-{due_date}',
			'duplicate'        => '{ppp} already recorded',
			'suggestion'       => '{suggested_ppp}?',
			'not_found'        => '{typed_name} notfound',
			'multiple'         => "1 {match_1}\n2 {match_2}\n3 {match_3}",
			'error'            => '{typed_name} error',
		);
	}

	public static function allowed_placeholders() {
		return array(
			'isp_name',
			'name',
			'ppp',
			'typed_name',
			'suggested_ppp',
			'amount',
			'method',
			'status',
			'due_date',
			'expiry_date',
			'payment_date',
			'payment_time',
			'sender',
			'recorded_by',
			'payment_id',
			'plan',
			'address',
			'phone',
			'match_1',
			'match_2',
			'match_3',
		);
	}

	private static function defaults() {
		$business_name = sanitize_text_field( get_bloginfo( 'name' ) );
		return array(
			'enabled'            => 0,
			'business_name'      => $business_name ? $business_name : 'Airfiber',
			'page_id'            => '',
			'app_id'             => '',
			'ai_provider'        => '',
			'verify_token'       => wp_generate_password( 32, false, false ),
			'default_method'     => 'gcash',
			'date_format'        => 'n/j/y',
			'status_command'     => 'check',
			'find_command'       => 'find',
			'recent_command'     => 'today',
			'confirm_words'      => array( 'y', 'yes' ),
			'cancel_words'       => array( 'n', 'no', 'cancel' ),
			'authorized_users'   => array(),
			'success_reply_mode' => 'silent',
			'forward_payments'   => 1,
			'forward_status'     => 0,
			'block_same_day'     => 1,
			'learn_aliases'      => 1,
			'forward_name_mode'  => 'matched',
			'templates'          => self::default_templates(),
		);
	}

	public static function get_settings() {
		$stored   = get_option( self::OPTION_SETTINGS, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();
		$settings = array_merge( $defaults, $stored );

		$settings['templates'] = array_merge(
			$defaults['templates'],
			isset( $stored['templates'] ) && is_array( $stored['templates'] ) ? $stored['templates'] : array()
		);
		$settings['authorized_users'] = isset( $stored['authorized_users'] ) && is_array( $stored['authorized_users'] )
			? $stored['authorized_users']
			: array();
		$settings['confirm_words'] = isset( $stored['confirm_words'] ) && is_array( $stored['confirm_words'] )
			? $stored['confirm_words']
			: $defaults['confirm_words'];
		$settings['cancel_words'] = isset( $stored['cancel_words'] ) && is_array( $stored['cancel_words'] )
			? $stored['cancel_words']
			: $defaults['cancel_words'];

		if ( empty( $stored['verify_token'] ) ) {
			$stored['verify_token'] = $settings['verify_token'];
			update_option( self::OPTION_SETTINGS, array_merge( $stored, array( 'verify_token' => $settings['verify_token'] ) ), false );
		}

		return $settings;
	}

	private static function encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . 'afc-messenger', true );
	}

	private static function encrypt_secret( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'afc_messenger_crypto_missing', __( 'OpenSSL is required to store Messenger credentials securely.', 'airfiber-centralized' ) );
		}

		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return new WP_Error( 'afc_messenger_encrypt_failed', __( 'The Messenger credential could not be encrypted.', 'airfiber-centralized' ) );
		}

		return array(
			'v'      => 1,
			'iv'     => base64_encode( $iv ),
			'tag'    => base64_encode( $tag ),
			'cipher' => base64_encode( $cipher ),
		);
	}

	private static function decrypt_secret( $stored ) {
		if ( ! is_array( $stored ) || empty( $stored['iv'] ) || empty( $stored['tag'] ) || empty( $stored['cipher'] ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$value = openssl_decrypt(
			base64_decode( $stored['cipher'], true ),
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			base64_decode( $stored['iv'], true ),
			base64_decode( $stored['tag'], true )
		);

		return is_string( $value ) ? $value : '';
	}

	public static function get_secret( $key ) {
		if ( ! in_array( $key, array( 'page_access_token', 'app_secret' ), true ) ) {
			return '';
		}
		$secrets = get_option( self::OPTION_SECRETS, array() );
		$secrets = is_array( $secrets ) ? $secrets : array();
		return isset( $secrets[ $key ] ) ? self::decrypt_secret( $secrets[ $key ] ) : '';
	}

	private static function normalize_alias_key( $value ) {
		$value = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $value ) ) );
		return sanitize_text_field( $value );
	}

	public static function aliases() {
		$value = get_option( self::OPTION_ALIASES, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function resolve_alias( $typed_name ) {
		$key     = self::normalize_alias_key( $typed_name );
		$aliases = self::aliases();
		return isset( $aliases[ $key ] ) ? (string) $aliases[ $key ] : '';
	}

	public static function learn_alias( $typed_name, $ppp_username ) {
		$settings = self::get_settings();
		if ( empty( $settings['learn_aliases'] ) ) {
			return false;
		}

		$key = self::normalize_alias_key( $typed_name );
		$ppp = sanitize_text_field( $ppp_username );
		if ( '' === $key || '' === $ppp ) {
			return false;
		}

		$aliases = self::aliases();
		if ( isset( $aliases[ $key ] ) && $aliases[ $key ] !== $ppp ) {
			return false;
		}
		$aliases[ $key ] = $ppp;
		update_option( self::OPTION_ALIASES, $aliases, false );
		return true;
	}

	public static function is_authorized_sender( $psid ) {
		$psid = preg_replace( '/\D+/', '', (string) $psid );
		foreach ( self::get_settings()['authorized_users'] as $user ) {
			if ( isset( $user['psid'] ) && hash_equals( (string) $user['psid'], $psid ) ) {
				return $user;
			}
		}
		return false;
	}

	public static function forward_recipients( $sender_psid ) {
		$sender_psid = preg_replace( '/\D+/', '', (string) $sender_psid );
		$recipients  = array();
		foreach ( self::get_settings()['authorized_users'] as $user ) {
			if ( ! empty( $user['psid'] ) && (string) $user['psid'] !== $sender_psid ) {
				$recipients[] = $user;
			}
		}
		return $recipients;
	}

	public static function render_template( $template_key, $data = array() ) {
		$settings  = self::get_settings();
		$templates = $settings['templates'];
		$template  = isset( $templates[ $template_key ] ) ? (string) $templates[ $template_key ] : '';
		$data      = is_array( $data ) ? $data : array();
		$data['isp_name'] = isset( $data['isp_name'] ) ? $data['isp_name'] : $settings['business_name'];

		$replacements = array();
		foreach ( self::allowed_placeholders() as $placeholder ) {
			$value = isset( $data[ $placeholder ] ) && is_scalar( $data[ $placeholder ] ) ? (string) $data[ $placeholder ] : '';
			$replacements[ '{' . $placeholder . '}' ] = $value;
		}

		return trim( strtr( $template, $replacements ) );
	}

	private static function parse_words( $value, $fallback ) {
		$items = preg_split( '/[\r\n,]+/', strtolower( (string) $value ) );
		$clean = array();
		foreach ( $items as $item ) {
			$item = preg_replace( '/[^a-z0-9_]+/', '', trim( $item ) );
			if ( '' !== $item ) {
				$clean[ $item ] = $item;
			}
		}
		return $clean ? array_values( $clean ) : $fallback;
	}

	private static function parse_authorized_users( $value ) {
		$users = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 1 === count( $parts ) ) {
				$label = 'Staff ' . ( count( $users ) + 1 );
				$psid  = $parts[0];
			} else {
				$label = $parts[0];
				$psid  = $parts[1];
			}
			$label = sanitize_text_field( $label );
			$psid  = preg_replace( '/\D+/', '', $psid );
			if ( '' === $psid ) {
				continue;
			}
			$users[ $psid ] = array(
				'label' => $label ? $label : 'Staff ' . ( count( $users ) + 1 ),
				'psid'  => $psid,
			);
			if ( count( $users ) >= 20 ) {
				break;
			}
		}
		return array_values( $users );
	}

	private static function parse_aliases( $value ) {
		$aliases = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $value );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $typed, $ppp ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$typed = self::normalize_alias_key( $typed );
			$ppp   = sanitize_text_field( $ppp );
			if ( '' !== $typed && '' !== $ppp ) {
				$aliases[ $typed ] = $ppp;
			}
			if ( count( $aliases ) >= 1000 ) {
				break;
			}
		}
		return $aliases;
	}

	private static function command( $value, $fallback ) {
		$value = preg_replace( '/[^a-z0-9_]+/', '', strtolower( (string) $value ) );
		return '' !== $value ? $value : $fallback;
	}

	private static function sanitize_template( $value, $fallback ) {
		$value = sanitize_textarea_field( wp_unslash( $value ) );
		$value = substr( $value, 0, 2000 );
		return '' !== trim( $value ) ? $value : $fallback;
	}

	private static function public_status() {
		$settings = self::get_settings();
		$aliases  = self::aliases();
		$secrets  = get_option( self::OPTION_SECRETS, array() );
		$secrets  = is_array( $secrets ) ? $secrets : array();
		$users    = array();
		foreach ( $settings['authorized_users'] as $user ) {
			$users[] = ( $user['label'] ?? 'Staff' ) . ' | ' . ( $user['psid'] ?? '' );
		}
		$alias_lines = array();
		foreach ( $aliases as $typed => $ppp ) {
			$alias_lines[] = $typed . ' = ' . $ppp;
		}

		$prepared = ! empty( $settings['page_id'] ) && ! empty( $settings['authorized_users'] );
		return array(
			'enabled'            => ! empty( $settings['enabled'] ),
			'prepared'           => $prepared,
			'businessName'       => $settings['business_name'],
			'pageId'             => $settings['page_id'],
			'appId'              => $settings['app_id'],
			'aiProvider'         => $settings['ai_provider'],
			'verifyToken'        => $settings['verify_token'],
			'defaultMethod'      => $settings['default_method'],
			'dateFormat'         => $settings['date_format'],
			'statusCommand'      => $settings['status_command'],
			'findCommand'        => $settings['find_command'],
			'recentCommand'      => $settings['recent_command'],
			'confirmWords'       => implode( ', ', $settings['confirm_words'] ),
			'cancelWords'        => implode( ', ', $settings['cancel_words'] ),
			'authorizedUsers'    => implode( "\n", $users ),
			'successReplyMode'   => $settings['success_reply_mode'],
			'forwardPayments'    => ! empty( $settings['forward_payments'] ),
			'forwardStatus'      => ! empty( $settings['forward_status'] ),
			'blockSameDay'       => ! empty( $settings['block_same_day'] ),
			'learnAliases'       => ! empty( $settings['learn_aliases'] ),
			'forwardNameMode'    => $settings['forward_name_mode'],
			'templates'          => $settings['templates'],
			'aliases'            => implode( "\n", $alias_lines ),
			'aliasCount'         => count( $aliases ),
			'hasPageAccessToken' => ! empty( $secrets['page_access_token'] ),
			'hasAppSecret'       => ! empty( $secrets['app_secret'] ),
			'placeholders'       => self::allowed_placeholders(),
			'processorActive'    => false,
		);
	}

	public static function ajax_status() {
		self::authorize();
		wp_send_json_success( self::public_status() );
	}

	public static function ajax_save() {
		self::authorize();
		$defaults      = self::defaults();
		$current       = self::get_settings();
		$date_formats  = array( 'n/j/y', 'm/d/Y', 'F j, Y', 'Y-m-d' );
		$success_modes = array( 'silent', 'simple', 'detailed' );
		$name_modes    = array( 'typed', 'matched' );
		$methods       = array( 'gcash', 'cash' );

		$settings = array(
			'enabled'            => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'business_name'      => isset( $_POST['business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['business_name'] ) ) : $current['business_name'],
			'page_id'            => isset( $_POST['page_id'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['page_id'] ) ) : '',
			'app_id'             => isset( $_POST['app_id'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['app_id'] ) ) : '',
			'ai_provider'        => isset( $_POST['ai_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_provider'] ) ) : '',
			'verify_token'       => isset( $_POST['verify_token'] ) ? preg_replace( '/[^A-Za-z0-9_.-]+/', '', wp_unslash( $_POST['verify_token'] ) ) : $current['verify_token'],
			'default_method'     => isset( $_POST['default_method'] ) && in_array( wp_unslash( $_POST['default_method'] ), $methods, true ) ? wp_unslash( $_POST['default_method'] ) : 'gcash',
			'date_format'        => isset( $_POST['date_format'] ) && in_array( wp_unslash( $_POST['date_format'] ), $date_formats, true ) ? wp_unslash( $_POST['date_format'] ) : 'n/j/y',
			'status_command'     => self::command( $_POST['status_command'] ?? '', 'check' ),
			'find_command'       => self::command( $_POST['find_command'] ?? '', 'find' ),
			'recent_command'     => self::command( $_POST['recent_command'] ?? '', 'today' ),
			'confirm_words'      => self::parse_words( $_POST['confirm_words'] ?? '', $defaults['confirm_words'] ),
			'cancel_words'       => self::parse_words( $_POST['cancel_words'] ?? '', $defaults['cancel_words'] ),
			'authorized_users'   => self::parse_authorized_users( $_POST['authorized_users'] ?? '' ),
			'success_reply_mode' => isset( $_POST['success_reply_mode'] ) && in_array( wp_unslash( $_POST['success_reply_mode'] ), $success_modes, true ) ? wp_unslash( $_POST['success_reply_mode'] ) : 'silent',
			'forward_payments'   => ! empty( $_POST['forward_payments'] ) ? 1 : 0,
			'forward_status'     => ! empty( $_POST['forward_status'] ) ? 1 : 0,
			'block_same_day'     => ! empty( $_POST['block_same_day'] ) ? 1 : 0,
			'learn_aliases'      => ! empty( $_POST['learn_aliases'] ) ? 1 : 0,
			'forward_name_mode'  => isset( $_POST['forward_name_mode'] ) && in_array( wp_unslash( $_POST['forward_name_mode'] ), $name_modes, true ) ? wp_unslash( $_POST['forward_name_mode'] ) : 'matched',
			'templates'          => array(),
		);

		if ( strlen( $settings['verify_token'] ) < 12 ) {
			$settings['verify_token'] = wp_generate_password( 32, false, false );
		}

		foreach ( $defaults['templates'] as $key => $fallback ) {
			$post_key = 'template_' . $key;
			$settings['templates'][ $key ] = isset( $_POST[ $post_key ] )
				? self::sanitize_template( $_POST[ $post_key ], $fallback )
				: $current['templates'][ $key ];
		}

		update_option( self::OPTION_SETTINGS, $settings, false );
		update_option( self::OPTION_ALIASES, self::parse_aliases( $_POST['aliases'] ?? '' ), false );

		$secrets = get_option( self::OPTION_SECRETS, array() );
		$secrets = is_array( $secrets ) ? $secrets : array();
		foreach ( array( 'page_access_token', 'app_secret' ) as $secret_key ) {
			if ( ! empty( $_POST[ 'remove_' . $secret_key ] ) ) {
				unset( $secrets[ $secret_key ] );
				continue;
			}
			if ( isset( $_POST[ $secret_key ] ) && '' !== trim( (string) wp_unslash( $_POST[ $secret_key ] ) ) ) {
				$encrypted = self::encrypt_secret( trim( (string) wp_unslash( $_POST[ $secret_key ] ) ) );
				if ( is_wp_error( $encrypted ) ) {
					wp_send_json_error( array( 'message' => $encrypted->get_error_message() ) );
				}
				$secrets[ $secret_key ] = $encrypted;
			}
		}
		update_option( self::OPTION_SECRETS, $secrets, false );

		wp_send_json_success( array_merge( self::public_status(), array( 'message' => __( 'Messenger profile, commands and reply templates were saved.', 'airfiber-centralized' ) ) ) );
	}

	public static function ajax_reset_templates() {
		self::authorize();
		$settings = self::get_settings();
		$settings['templates'] = self::default_templates();
		update_option( self::OPTION_SETTINGS, $settings, false );
		wp_send_json_success( array_merge( self::public_status(), array( 'message' => __( 'Messenger reply templates were reset to defaults.', 'airfiber-centralized' ) ) ) );
	}

	public static function ajax_clear_aliases() {
		self::authorize();
		delete_option( self::OPTION_ALIASES );
		wp_send_json_success( array_merge( self::public_status(), array( 'message' => __( 'Learned Messenger aliases were cleared.', 'airfiber-centralized' ) ) ) );
	}
}
