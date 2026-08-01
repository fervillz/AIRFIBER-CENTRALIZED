<?php

defined( 'ABSPATH' ) || exit;

/**
 * Android SMS gateway queue, device authentication, and frontend SMS Center.
 */
class AFC_SMS_Center {

	const DB_VERSION        = '1.0.0';
	const OPTION_DB_VERSION = 'afc_sms_db_version';
	const OPTION_DEVICE_ID  = 'afc_sms_active_device_id';
	const NONCE_ACTION      = 'afc_sms_center';
	const REST_NAMESPACE    = 'airfiber/v1';
	const REST_BASE         = 'sms';

	private static $authenticated_device = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 40 );
		add_action( 'afc_frontend_app_content', array( __CLASS__, 'render_frontend_panel' ) );

		add_action( 'wp_ajax_afc_sms_get_state', array( __CLASS__, 'ajax_get_state' ) );
		add_action( 'wp_ajax_afc_sms_generate_token', array( __CLASS__, 'ajax_generate_token' ) );
		add_action( 'wp_ajax_afc_sms_list_ppp', array( __CLASS__, 'ajax_list_ppp' ) );
		add_action( 'wp_ajax_afc_sms_queue_test', array( __CLASS__, 'ajax_queue_test' ) );
		add_action( 'wp_ajax_afc_sms_cancel_job', array( __CLASS__, 'ajax_cancel_job' ) );
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$devices         = self::table( 'devices' );
		$jobs            = self::table( 'jobs' );
		$events          = self::table( 'events' );
		$incoming        = self::table( 'incoming' );

		$sql_devices = "CREATE TABLE {$devices} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			token_hash char(64) NOT NULL DEFAULT '',
			token_hint varchar(24) NOT NULL DEFAULT '',
			device_id varchar(190) NOT NULL DEFAULT '',
			state varchar(40) NOT NULL DEFAULT 'offline',
			detail text NULL,
			metadata longtext NULL,
			last_seen datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			revoked_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY device_id (device_id),
			KEY last_seen (last_seen)
		) {$charset_collate};";

		$sql_jobs = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dedupe_key varchar(191) NOT NULL,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ppp_username varchar(190) NOT NULL DEFAULT '',
			customer_name varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			message text NOT NULL,
			reminder_type varchar(80) NOT NULL DEFAULT 'manual',
			status varchar(32) NOT NULL DEFAULT 'queued',
			device_id varchar(190) NOT NULL DEFAULT '',
			last_detail text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			claimed_at datetime NULL,
			submitted_at datetime NULL,
			sent_at datetime NULL,
			delivered_at datetime NULL,
			failed_at datetime NULL,
			cancelled_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY status_created (status,created_at),
			KEY ppp_username (ppp_username),
			KEY device_id (device_id)
		) {$charset_collate};";

		$sql_events = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			device_id varchar(190) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT '',
			detail text NULL,
			event_time varchar(80) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY status_created (status,created_at)
		) {$charset_collate};";

		$sql_incoming = "CREATE TABLE {$incoming} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			device_id varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			message text NOT NULL,
			received_at varchar(80) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY phone (phone),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_devices );
		dbDelta( $sql_jobs );
		dbDelta( $sql_events );
		dbDelta( $sql_incoming );

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			self::install();
		}
	}

	private static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'afc_sms_' . $name;
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-sms-center',
			AFC_URL . 'assets/css/sms-center.css',
			array( 'afc-frontend-app' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-sms-center',
			AFC_URL . 'assets/js/sms-center.js',
			array( 'jquery', 'afc-frontend-app' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-sms-center',
			'afcSmsCenter',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'defaultMessage' => self::default_test_message(),
				'wpUrl'          => untrailingslashit( home_url() ),
				'labels'         => array(
					'confirmRotate' => __( 'Generate a new device token? The old token will stop working immediately.', 'airfiber-centralized' ),
					'confirmQueue'  => __( 'Queue this test SMS for the selected PPP customer?', 'airfiber-centralized' ),
					'copied'        => __( 'Copied.', 'airfiber-centralized' ),
				),
			)
		);
	}

	public static function render_frontend_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$snapshot = self::dashboard_data();
		include AFC_PATH . 'templates/admin/sms-center.php';
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage SMS.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	public static function ajax_get_state() {
		self::authorize_ajax();
		wp_send_json_success( self::dashboard_data() );
	}

	public static function ajax_generate_token() {
		self::authorize_ajax();

		global $wpdb;
		$now        = current_time( 'mysql' );
		$plain      = self::generate_token();
		$hash       = hash( 'sha256', $plain );
		$hint       = '…' . substr( $plain, -10 );
		$devices    = self::table( 'devices' );
		$device_id  = absint( get_option( self::OPTION_DEVICE_ID ) );
		$device_row = $device_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$devices} WHERE id = %d", $device_id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $device_row ) {
			$wpdb->update(
				$devices,
				array(
					'token_hash' => $hash,
					'token_hint' => $hint,
					'state'      => 'token-created',
					'detail'     => 'Waiting for Android connection.',
					'updated_at' => $now,
				),
				array( 'id' => $device_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$devices,
				array(
					'name'       => 'Airfiber Android Gateway',
					'token_hash' => $hash,
					'token_hint' => $hint,
					'state'      => 'token-created',
					'detail'     => 'Waiting for Android connection.',
					'created_at' => $now,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$device_id = (int) $wpdb->insert_id;
			update_option( self::OPTION_DEVICE_ID, $device_id, false );
		}

		wp_send_json_success(
			array(
				'token'   => $plain,
				'device'  => self::device_data(),
				'message' => __( 'Device token generated. Copy it now; WordPress stores only its hash.', 'airfiber-centralized' ),
			)
		);
	}

	public static function ajax_list_ppp() {
		self::authorize_ajax();
		$result = self::ppp_candidates();
		wp_send_json_success( $result );
	}

	public static function ajax_queue_test() {
		self::authorize_ajax();

		$username = isset( $_POST['ppp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['ppp_username'] ) ) : '';
		$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		if ( '' === $username ) {
			wp_send_json_error( array( 'message' => __( 'Select a PPP customer first.', 'airfiber-centralized' ) ) );
		}

		$candidate = self::find_ppp_candidate( $username );
		if ( ! $candidate ) {
			wp_send_json_error( array( 'message' => __( 'The selected PPP customer could not be found.', 'airfiber-centralized' ) ) );
		}
		if ( ! empty( $candidate['do_not_text'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This customer is marked Do Not Text.', 'airfiber-centralized' ) ) );
		}

		$phone = self::normalize_phone( $candidate['phone'] );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'This PPP customer does not have a valid Philippine mobile number.', 'airfiber-centralized' ) ) );
		}

		if ( '' === trim( $message ) ) {
			$message = self::default_test_message();
		}
		$message = self::apply_message_tokens( $message, $candidate, $phone );
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 1200 );
		} else {
			$message = substr( $message, 0, 1200 );
		}

		global $wpdb;
		$now        = current_time( 'mysql' );
		$dedupe_key = 'manual-test|' . strtolower( $username ) . '|' . gmdate( 'YmdHis' ) . '|' . strtolower( wp_generate_password( 8, false, false ) );
		$inserted   = $wpdb->insert(
			self::table( 'jobs' ),
			array(
				'dedupe_key'    => $dedupe_key,
				'customer_id'   => absint( $candidate['customer_id'] ),
				'ppp_username'  => $username,
				'customer_name' => $candidate['customer_name'],
				'phone'         => $phone,
				'message'       => $message,
				'reminder_type' => 'manual-test',
				'status'        => 'queued',
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'The test SMS could not be added to the queue.', 'airfiber-centralized' ) ) );
		}

		$job = self::get_job( (int) $wpdb->insert_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'The SMS was queued, but its record could not be reloaded.', 'airfiber-centralized' ) ) );
		}
		self::record_event( $job->id, '', 'queued', 'Queued manually from SMS Center.', current_time( 'mysql' ) );

		wp_send_json_success(
			array(
				'message' => sprintf( __( 'Test SMS queued for %s (%s).', 'airfiber-centralized' ), $candidate['customer_name'], $phone ),
				'job'     => self::prepare_job_for_admin( $job ),
			)
		);
	}

	public static function ajax_cancel_job() {
		self::authorize_ajax();
		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid SMS job.', 'airfiber-centralized' ) ) );
		}

		global $wpdb;
		$now     = current_time( 'mysql' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'jobs' ) . " SET status = 'cancelled', cancelled_at = %s, updated_at = %s, last_detail = %s WHERE id = %d AND status = 'queued'",
				$now,
				$now,
				'Cancelled from SMS Center.',
				$job_id
			)
		);
		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Only queued messages can be cancelled.', 'airfiber-centralized' ) ) );
		}
		self::record_event( $job_id, '', 'cancelled', 'Cancelled from SMS Center.', $now );
		wp_send_json_success( array( 'message' => __( 'Queued SMS cancelled.', 'airfiber-centralized' ) ) );
	}

	private static function generate_token() {
		try {
			$random = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $error ) {
			$random = wp_generate_password( 64, false, false );
		}
		return 'af_sms_' . $random;
	}

	public static function default_test_message() {
		return 'Hi {name}, this is a test message from Airfiber for PPP account {ppp}. No payment is required. This number is for notifications only.';
	}

	private static function apply_message_tokens( $message, $candidate, $phone ) {
		$name = trim( (string) $candidate['customer_name'] );
		if ( '' === $name ) {
			$name = (string) $candidate['ppp_username'];
		}
		return strtr(
			$message,
			array(
				'{name}'  => $name,
				'{ppp}'   => (string) $candidate['ppp_username'],
				'{phone}' => $phone,
			)
		);
	}

	private static function ppp_candidates() {
		$imported = self::imported_customer_map();
		$users    = array();
		$warning  = '';
		$source   = 'mikrotik';

		if ( class_exists( 'AFC_MikroTik' ) ) {
			$secrets = AFC_MikroTik::run_command(
				array(
					'/ppp/secret/print',
					'=.proplist=.id,name,profile,comment,disabled',
				)
			);
		} else {
			$secrets = new WP_Error( 'afc_sms_no_mikrotik', __( 'MikroTik integration is unavailable.', 'airfiber-centralized' ) );
		}

		if ( is_wp_error( $secrets ) ) {
			$warning = $secrets->get_error_message();
			$source  = 'wordpress';
		} else {
			if ( isset( $secrets['name'] ) ) {
				$secrets = array( $secrets );
			}
			foreach ( (array) $secrets as $secret ) {
				$username = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
				if ( '' === $username ) {
					continue;
				}
				$comment  = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
				$parsed   = self::parse_ppp_comment( $comment );
				$customer = isset( $imported[ strtolower( $username ) ] ) ? $imported[ strtolower( $username ) ] : array();
				$name     = $parsed['name'] ? $parsed['name'] : ( isset( $customer['customer_name'] ) ? $customer['customer_name'] : $username );
				$phone    = $parsed['cp'] ? $parsed['cp'] : ( isset( $customer['phone'] ) ? $customer['phone'] : '' );
				$users[]  = array(
					'ppp_username'    => $username,
					'customer_name'   => $name,
					'phone'           => $phone,
					'phone_normalized' => self::normalize_phone( $phone ),
					'profile'         => isset( $secret['profile'] ) ? (string) $secret['profile'] : '',
					'disabled'        => isset( $secret['disabled'] ) && 'true' === (string) $secret['disabled'],
					'customer_id'     => isset( $customer['customer_id'] ) ? absint( $customer['customer_id'] ) : 0,
					'do_not_text'     => ! empty( $customer['do_not_text'] ),
				);
			}
		}

		if ( ! $users ) {
			foreach ( $imported as $customer ) {
				$users[] = array(
					'ppp_username'    => $customer['ppp_username'],
					'customer_name'   => $customer['customer_name'],
					'phone'           => $customer['phone'],
					'phone_normalized' => self::normalize_phone( $customer['phone'] ),
					'profile'         => $customer['profile'],
					'disabled'        => false,
					'customer_id'     => $customer['customer_id'],
					'do_not_text'     => $customer['do_not_text'],
				);
			}
		}

		usort(
			$users,
			function ( $a, $b ) {
				return strcasecmp( $a['customer_name'] . ' ' . $a['ppp_username'], $b['customer_name'] . ' ' . $b['ppp_username'] );
			}
		);

		return array(
			'users'   => array_values( $users ),
			'count'   => count( $users ),
			'source'  => $source,
			'warning' => $warning,
		);
	}

	private static function find_ppp_candidate( $username ) {
		$result = self::ppp_candidates();
		foreach ( $result['users'] as $user ) {
			if ( 0 === strcasecmp( $username, $user['ppp_username'] ) ) {
				return $user;
			}
		}
		return null;
	}

	private static function imported_customer_map() {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$map = array();
		foreach ( $ids as $customer_id ) {
			$username = trim( (string) get_post_meta( $customer_id, '_afc_ppp_username', true ) );
			if ( '' === $username ) {
				continue;
			}
			$name = trim( (string) get_post_meta( $customer_id, '_afc_customer_name', true ) );
			if ( '' === $name ) {
				$name = get_the_title( $customer_id );
			}
			$map[ strtolower( $username ) ] = array(
				'customer_id'   => (int) $customer_id,
				'ppp_username'  => $username,
				'customer_name' => $name ? $name : $username,
				'phone'         => (string) get_post_meta( $customer_id, '_afc_phone', true ),
				'profile'       => (string) get_post_meta( $customer_id, '_afc_plan', true ),
				'do_not_text'   => self::meta_truthy( get_post_meta( $customer_id, '_afc_do_not_text', true ) ) || self::meta_truthy( get_post_meta( $customer_id, '_afc_sms_opt_out', true ) ),
			);
		}
		return $map;
	}

	private static function meta_truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	private static function parse_ppp_comment( $comment ) {
		$values = array( 'name' => '', 'cp' => '' );
		$keys   = 'installed|grace|paymentMethod|paymentAmount|paymentDate|name|plan|cp|wifi|password|Address';
		preg_match_all(
			'/(?:^|\s)(' . $keys . ')\s*:\s*(.*?)(?=\s+(?:' . $keys . ')\s*:|$)/is',
			trim( (string) $comment ),
			$matches,
			PREG_SET_ORDER
		);
		foreach ( $matches as $match ) {
			$key   = strtolower( $match[1] );
			$value = trim( preg_replace( '/\s+/', ' ', $match[2] ) );
			if ( isset( $values[ $key ] ) && 'N/A' !== strtoupper( $value ) ) {
				$values[ $key ] = $value;
			}
		}
		return $values;
	}

	private static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '09' ) ) {
			return '+63' . substr( $digits, 1 );
		}
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '639' ) ) {
			return '+' . $digits;
		}
		if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) {
			return '+63' . $digits;
		}
		return '';
	}

	private static function dashboard_data() {
		global $wpdb;
		$jobs_table = self::table( 'jobs' );
		$events     = self::table( 'events' );
		$incoming   = self::table( 'incoming' );

		$jobs       = $wpdb->get_results( "SELECT * FROM {$jobs_table} ORDER BY id DESC LIMIT 40" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$event_rows = $wpdb->get_results( "SELECT * FROM {$events} ORDER BY id DESC LIMIT 30" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$replies    = $wpdb->get_results( "SELECT * FROM {$incoming} ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts     = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$jobs_table} GROUP BY status", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$prepared_jobs = array();
		foreach ( (array) $jobs as $job ) {
			$prepared_jobs[] = self::prepare_job_for_admin( $job );
		}
		$prepared_events = array();
		foreach ( (array) $event_rows as $event ) {
			$prepared_events[] = array(
				'id'         => (int) $event->id,
				'job_id'     => (int) $event->job_id,
				'status'     => (string) $event->status,
				'detail'     => (string) $event->detail,
				'device_id'  => (string) $event->device_id,
				'created_at' => (string) $event->created_at,
			);
		}
		$prepared_replies = array();
		foreach ( (array) $replies as $reply ) {
			$prepared_replies[] = array(
				'id'          => (int) $reply->id,
				'phone'       => (string) $reply->phone,
				'message'     => (string) $reply->message,
				'received_at' => (string) $reply->received_at,
				'created_at'  => (string) $reply->created_at,
			);
		}

		$prepared_counts = array();
		foreach ( (array) $counts as $status => $row ) {
			$prepared_counts[ $status ] = (int) $row->total;
		}

		return array(
			'device'   => self::device_data(),
			'jobs'     => $prepared_jobs,
			'events'   => $prepared_events,
			'replies'  => $prepared_replies,
			'counts'   => $prepared_counts,
			'restBase' => rest_url( self::REST_NAMESPACE . '/' . self::REST_BASE ),
		);
	}

	private static function device_data() {
		global $wpdb;
		$device_id = absint( get_option( self::OPTION_DEVICE_ID ) );
		if ( ! $device_id ) {
			return array(
				'exists'    => false,
				'state'     => 'not-configured',
				'detail'    => __( 'Generate a device token to connect the Android gateway.', 'airfiber-centralized' ),
				'last_seen' => '',
			);
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'devices' ) . ' WHERE id = %d', $device_id ) );
		if ( ! $row ) {
			return array( 'exists' => false, 'state' => 'not-configured', 'detail' => '', 'last_seen' => '' );
		}
		return array(
			'exists'     => true,
			'id'         => (int) $row->id,
			'name'       => (string) $row->name,
			'token_hint' => (string) $row->token_hint,
			'device_id'  => (string) $row->device_id,
			'state'      => (string) $row->state,
			'detail'     => (string) $row->detail,
			'last_seen'  => (string) $row->last_seen,
			'updated_at' => (string) $row->updated_at,
		);
	}

	private static function get_job( $job_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'jobs' ) . ' WHERE id = %d', $job_id ) );
	}

	private static function prepare_job_for_admin( $job ) {
		return array(
			'id'            => (int) $job->id,
			'ppp_username'  => (string) $job->ppp_username,
			'customer_name' => (string) $job->customer_name,
			'phone'         => (string) $job->phone,
			'message'       => (string) $job->message,
			'reminder_type' => (string) $job->reminder_type,
			'status'        => (string) $job->status,
			'device_id'     => (string) $job->device_id,
			'last_detail'   => (string) $job->last_detail,
			'created_at'    => (string) $job->created_at,
			'updated_at'    => (string) $job->updated_at,
			'can_cancel'    => 'queued' === $job->status,
		);
	}

	public static function register_rest_routes() {
		$auth = array( __CLASS__, 'rest_authenticate' );
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/queue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_queue' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/claim',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_claim' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/incoming',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_incoming' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/heartbeat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_heartbeat' ),
				'permission_callback' => $auth,
			)
		);
	}

	public static function rest_authenticate( $request ) {
		global $wpdb;
		$header = trim( (string) $request->get_header( 'authorization' ) );
		if ( '' === $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = trim( (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}
		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = trim( (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return new WP_Error( 'afc_sms_token_missing', __( 'The Airfiber device token is missing.', 'airfiber-centralized' ), array( 'status' => 401 ) );
		}
		$plain = trim( $matches[1] );
		$hash  = hash( 'sha256', $plain );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'devices' ) . ' WHERE token_hash = %s AND revoked_at IS NULL LIMIT 1',
				$hash
			)
		);
		if ( ! $row || ! hash_equals( (string) $row->token_hash, $hash ) ) {
			return new WP_Error( 'afc_sms_token_invalid', __( 'The Airfiber device token is invalid or revoked.', 'airfiber-centralized' ), array( 'status' => 403 ) );
		}
		self::$authenticated_device = $row;
		return true;
	}

	public static function rest_queue( $request ) {
		global $wpdb;
		$limit     = max( 1, min( 100, absint( $request->get_param( 'limit' ) ) ) );
		$device_id = sanitize_text_field( (string) $request->get_param( 'device_id' ) );
		self::touch_device( $device_id, 'online', 'Queue requested by Android gateway.', array() );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table( 'jobs' ) . " WHERE status = 'queued' ORDER BY id ASC LIMIT %d",
				$limit
			)
		);
		$jobs = array();
		foreach ( (array) $rows as $row ) {
			$jobs[] = array(
				'id'            => (string) $row->id,
				'dedupe_key'    => (string) $row->dedupe_key,
				'phone'         => (string) $row->phone,
				'message'       => (string) $row->message,
				'customer'      => (string) $row->customer_name,
				'reminder_type' => (string) $row->reminder_type,
			);
		}
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'jobs' => $jobs, 'count' => count( $jobs ) ) ) );
	}

	public static function rest_claim( $request ) {
		global $wpdb;
		$job_id     = absint( $request->get_param( 'job_id' ) );
		$dedupe_key = sanitize_text_field( (string) $request->get_param( 'dedupe_key' ) );
		$device_id  = sanitize_text_field( (string) $request->get_param( 'device_id' ) );
		if ( ! $job_id || '' === $dedupe_key || '' === $device_id ) {
			return new WP_Error( 'afc_sms_claim_invalid', __( 'The SMS claim request is incomplete.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		$now     = current_time( 'mysql' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'jobs' ) . " SET status = 'claimed', device_id = %s, claimed_at = %s, updated_at = %s, last_detail = %s WHERE id = %d AND dedupe_key = %s AND status = 'queued'",
				$device_id,
				$now,
				$now,
				'Claimed by Android gateway.',
				$job_id,
				$dedupe_key
			)
		);
		if ( $updated ) {
			self::record_event( $job_id, $device_id, 'claimed', 'Claimed by Android gateway.', $now );
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'claimed' => true ) ) );
		}
		$job     = self::get_job( $job_id );
		$claimed = $job && $job->dedupe_key === $dedupe_key && $job->device_id === $device_id && in_array( $job->status, array( 'claimed', 'submitted', 'sent', 'delivered' ), true );
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'claimed' => $claimed ) ) );
	}

	public static function rest_status( $request ) {
		$job_id     = absint( $request->get_param( 'job_id' ) );
		$status     = sanitize_key( (string) $request->get_param( 'status' ) );
		$detail     = sanitize_textarea_field( (string) $request->get_param( 'detail' ) );
		$event_time = sanitize_text_field( (string) $request->get_param( 'event_time' ) );
		$device_id  = sanitize_text_field( (string) $request->get_param( 'device_id' ) );
		$allowed    = array( 'claimed', 'submitted', 'sent', 'delivered', 'failed' );
		if ( ! $job_id || ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'afc_sms_status_invalid', __( 'The SMS status update is invalid.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}

		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'afc_sms_job_missing', __( 'The SMS job does not exist.', 'airfiber-centralized' ), array( 'status' => 404 ) );
		}
		self::record_event( $job_id, $device_id, $status, $detail, $event_time );
		self::apply_job_status( $job, $status, $detail, $device_id );
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'accepted' => true ) ) );
	}

	public static function rest_incoming( $request ) {
		global $wpdb;
		$phone       = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$message     = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$received_at = sanitize_text_field( (string) $request->get_param( 'received_at' ) );
		$device_id   = sanitize_text_field( (string) $request->get_param( 'device_id' ) );
		if ( '' === $phone || '' === $message ) {
			return new WP_Error( 'afc_sms_incoming_invalid', __( 'Incoming SMS data is incomplete.', 'airfiber-centralized' ), array( 'status' => 400 ) );
		}
		$wpdb->insert(
			self::table( 'incoming' ),
			array(
				'device_id'   => $device_id,
				'phone'       => $phone,
				'message'     => $message,
				'received_at' => $received_at,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'stored' => true ) ) );
	}

	public static function rest_heartbeat( $request ) {
		$device_id = sanitize_text_field( (string) $request->get_param( 'device_id' ) );
		$state     = sanitize_key( (string) $request->get_param( 'state' ) );
		$detail    = sanitize_textarea_field( (string) $request->get_param( 'detail' ) );
		$metadata  = array(
			'manufacturer'    => sanitize_text_field( (string) $request->get_param( 'manufacturer' ) ),
			'model'           => sanitize_text_field( (string) $request->get_param( 'model' ) ),
			'android'         => sanitize_text_field( (string) $request->get_param( 'android' ) ),
			'app_version'     => sanitize_text_field( (string) $request->get_param( 'app_version' ) ),
			'subscription_id' => sanitize_text_field( (string) $request->get_param( 'subscription_id' ) ),
		);
		self::touch_device( $device_id, $state ? $state : 'online', $detail, $metadata );
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'accepted'    => true,
					'server_time' => current_time( 'mysql' ),
				),
			)
		);
	}

	private static function touch_device( $device_id, $state, $detail, $metadata ) {
		global $wpdb;
		if ( ! self::$authenticated_device ) {
			return;
		}
		$now  = current_time( 'mysql' );
		$data = array(
			'device_id' => $device_id,
			'state'     => $state,
			'detail'    => $detail,
			'last_seen' => $now,
			'updated_at' => $now,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s' );
		if ( ! empty( $metadata ) ) {
			$data['metadata'] = wp_json_encode( $metadata );
			$formats[]        = '%s';
		}
		$wpdb->update(
			self::table( 'devices' ),
			$data,
			array( 'id' => (int) self::$authenticated_device->id ),
			$formats,
			array( '%d' )
		);
	}

	private static function record_event( $job_id, $device_id, $status, $detail, $event_time ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'events' ),
			array(
				'job_id'     => absint( $job_id ),
				'device_id'  => sanitize_text_field( (string) $device_id ),
				'status'     => sanitize_key( (string) $status ),
				'detail'     => sanitize_textarea_field( (string) $detail ),
				'event_time' => sanitize_text_field( (string) $event_time ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function apply_job_status( $job, $status, $detail, $device_id ) {
		global $wpdb;
		$current = (string) $job->status;
		if ( in_array( $current, array( 'delivered', 'cancelled' ), true ) ) {
			return;
		}
		$rank = array( 'queued' => 0, 'claimed' => 1, 'submitted' => 2, 'sent' => 3, 'delivered' => 4 );
		if ( 'failed' !== $status && isset( $rank[ $current ], $rank[ $status ] ) && $rank[ $status ] < $rank[ $current ] ) {
			return;
		}
		if ( 'failed' === $current && 'delivered' !== $status ) {
			return;
		}

		$now  = current_time( 'mysql' );
		$data = array(
			'status'      => $status,
			'device_id'   => $device_id,
			'last_detail' => $detail,
			'updated_at'  => $now,
		);
		$formats         = array( '%s', '%s', '%s', '%s' );
		$timestamp_field = array(
			'claimed'   => 'claimed_at',
			'submitted' => 'submitted_at',
			'sent'      => 'sent_at',
			'delivered' => 'delivered_at',
			'failed'    => 'failed_at',
		);
		if ( isset( $timestamp_field[ $status ] ) ) {
			$data[ $timestamp_field[ $status ] ] = $now;
			$formats[] = '%s';
		}
		$wpdb->update( self::table( 'jobs' ), $data, array( 'id' => (int) $job->id ), $formats, array( '%d' ) );
	}
}
