<?php

defined( 'ABSPATH' ) || exit;

/**
 * Simple PPP onboarding and editing for Basic mode, with a complete editor for
 * Advanced mode. MikroTik, WordPress customer, billing dates, cutoff scheduler,
 * and pre-due SMS date are kept synchronized as one operation.
 */
class AFC_PPP_Manager {

	use AFC_PPP_Manager_Router_Trait;
	use AFC_PPP_Manager_Reminders_Trait;
	use AFC_PPP_Manager_Core_Trait;
	use AFC_PPP_Manager_Create_Trait;
	use AFC_PPP_Manager_Save_Trait;

	const NONCE                = 'afc_ppp_manager';
	const CRON_HOOK            = 'afc_ppp_pre_due_reminder_scan';
	const SCRIPT_MARKER         = 'AFC-MANAGED-SCHEDULER v1';
	const SERVICE_AREAS_OPTION = 'afc_ppp_service_areas';

	/**
	 * New installations are prepaid rolling accounts. The due date is exactly
	 * 30 days after activation and the scheduler runs just after the due date,
	 * rather than adding the collection grace as free service days.
	 *
	 * A class method overrides the identically named Core trait method.
	 */
	private static function dates_from_installation( DateTimeImmutable $installed, $grace = 3 ) {
		$next_due = $installed->modify( '+30 days' );
		$cutoff   = $next_due->modify( '+1 day' );

		return array(
			'installed'       => $installed->format( 'Y-m-d' ),
			'paymentDate'     => $installed->format( 'Y-m-d' ),
			'billingDay'      => (int) $installed->format( 'j' ),
			'billingCycleDays'=> 30,
			'paidThrough'     => $installed->format( 'Y-m-d' ),
			'nextDue'         => $next_due->format( 'Y-m-d' ),
			'cutoffDate'      => $cutoff->format( 'Y-m-d' ),
			'dueReminderDate' => $next_due->modify( '-1 day' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * Keep the Core trait formatter, while adding rolling-cycle and WiFi
	 * password fields used by the newer onboarding flow.
	 */
	private static function build_comment( $base, $values ) {
		$comment = (string) $base;
		$map = array(
			'installed'       => 'installed',
			'grace'           => 'grace',
			'paymentMethod'   => 'paymentMethod',
			'paymentAmount'   => 'paymentAmount',
			'paymentDate'     => 'paymentDate',
			'name'            => 'name',
			'plan'            => 'plan',
			'cp'              => 'cp',
			'wifi'            => 'wifi',
			'password'        => 'password',
			'Address'         => 'Address',
			'billingDay'      => 'billingDay',
			'billingCycleDays'=> 'billingCycleDays',
			'paidThrough'     => 'paidThrough',
			'nextDue'         => 'nextDue',
			'cutoffDate'      => 'cutoffDate',
			'dueReminderDate' => 'dueReminderDate',
		);

		foreach ( $map as $source => $comment_key ) {
			if ( array_key_exists( $source, $values ) ) {
				$comment = AFC_Comment_Fields::replace_value(
					$comment,
					$comment_key,
					sanitize_text_field( (string) $values[ $source ] )
				);
			}
		}

		return AFC_Comment_Fields::normalize_comment( $comment );
	}

	public static function init() {
		add_action( 'wp_ajax_afc_ppp_manager_bootstrap', array( __CLASS__, 'ajax_bootstrap' ) );
		add_action( 'wp_ajax_afc_ppp_manager_create', array( __CLASS__, 'ajax_create' ) );
		add_action( 'wp_ajax_afc_ppp_manager_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_afc_ppp_manager_save_service_areas', array( __CLASS__, 'ajax_save_service_areas' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 76 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 76 );

		add_action( 'init', array( __CLASS__, 'ensure_schema_and_schedule' ), 25 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_pre_due_scan' ) );
		add_action( 'afc_payment_recorded', array( __CLASS__, 'refresh_reminder_after_payment' ), 15, 2 );
		add_action( 'afc_quick_payment_recorded', array( __CLASS__, 'refresh_reminder_after_quick_payment' ), 15, 4 );
	}

	public static function ensure_schema_and_schedule() {
		self::ensure_comment_field();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		while ( $timestamp = wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	private static function ensure_comment_field() {
		if ( ! class_exists( 'AFC_Comment_Fields' ) ) {
			return;
		}
		$fields = get_option( AFC_Comment_Fields::OPTION_KEY, array() );
		$fields = is_array( $fields ) ? $fields : array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['key'] ) && 0 === strcasecmp( 'dueReminderDate', (string) $field['key'] ) ) {
				return;
			}
		}
		$fields[] = array(
			'key'     => 'dueReminderDate',
			'label'   => __( 'Due Reminder Date', 'airfiber-centralized' ),
			'type'    => 'date',
			'default' => '',
		);
		update_option( AFC_Comment_Fields::OPTION_KEY, AFC_Comment_Fields::sanitize_fields( $fields ), false );
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
			'afc-select2',
			'https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css',
			array(),
			'4.0.13'
		);
		wp_enqueue_script(
			'afc-select2',
			'https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.full.min.js',
			array( 'jquery' ),
			'4.0.13',
			true
		);
		wp_enqueue_style(
			'afc-ppp-manager',
			AFC_URL . 'assets/css/ppp-manager.css',
			array( 'afc-admin-compat', 'afc-select2' ),
			AFC_VERSION
		);
		wp_enqueue_style(
			'afc-ppp-manager-fixes',
			AFC_URL . 'assets/css/ppp-manager-fixes.css',
			array( 'afc-ppp-manager' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-ppp-manager',
			AFC_URL . 'assets/js/ppp-manager.js',
			array( 'jquery', 'afc-ppp-users', 'afc-select2' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-ppp-manager',
			'afcPPPManager',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'currentDate'  => current_time( 'Y-m-d' ),
				'mode'         => class_exists( 'AFC_Admin_Mode' ) ? AFC_Admin_Mode::current_mode() : 'basic',
				'serviceAreas' => self::get_service_areas(),
			)
		);
	}

	private static function default_service_areas() {
		$zones = array( '1', '2', '3', '4', '5', '6', '7' );
		return array(
			array( 'name' => 'Lingion', 'zones' => $zones, 'latitude' => '', 'longitude' => '' ),
			array( 'name' => 'Dalirig', 'zones' => $zones, 'latitude' => '', 'longitude' => '' ),
			array( 'name' => 'Sto. Nino', 'zones' => $zones, 'latitude' => '', 'longitude' => '' ),
		);
	}

	public static function get_service_areas() {
		$stored = get_option( self::SERVICE_AREAS_OPTION, null );
		if ( null === $stored ) {
			return self::default_service_areas();
		}
		return self::sanitize_service_areas( $stored );
	}

	private static function sanitize_coordinate( $value, $minimum, $maximum ) {
		$value = trim( (string) $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$number = (float) $value;
		if ( $number < $minimum || $number > $maximum ) {
			return '';
		}
		return rtrim( rtrim( number_format( $number, 7, '.', '' ), '0' ), '.' );
	}

	private static function sanitize_service_areas( $areas ) {
		if ( ! is_array( $areas ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();
		foreach ( array_slice( $areas, 0, 50 ) as $area ) {
			if ( ! is_array( $area ) ) {
				continue;
			}
			$name = isset( $area['name'] ) ? sanitize_text_field( $area['name'] ) : '';
			$name = trim( preg_replace( '/\s+/', ' ', $name ) );
			$name = substr( $name, 0, 80 );
			$key  = strtolower( remove_accents( $name ) );
			if ( '' === $name || isset( $seen[ $key ] ) ) {
				continue;
			}

			$raw_zones = isset( $area['zones'] ) ? $area['zones'] : array();
			if ( is_string( $raw_zones ) ) {
				$raw_zones = preg_split( '/[,\r\n]+/', $raw_zones );
			}
			$zones = array();
			foreach ( array_slice( (array) $raw_zones, 0, 50 ) as $zone ) {
				$zone = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', sanitize_text_field( $zone ) ) );
				if ( '' !== $zone && ! in_array( $zone, $zones, true ) ) {
					$zones[] = $zone;
				}
			}

			$clean[] = array(
				'name'      => $name,
				'zones'     => $zones,
				'latitude'  => self::sanitize_coordinate( isset( $area['latitude'] ) ? $area['latitude'] : '', -90, 90 ),
				'longitude' => self::sanitize_coordinate( isset( $area['longitude'] ) ? $area['longitude'] : '', -180, 180 ),
			);
			$seen[ $key ] = true;
		}
		return $clean;
	}

	public static function ajax_save_service_areas() {
		self::authorize();
		$raw   = isset( $_POST['areas'] ) ? wp_unslash( $_POST['areas'] ) : '[]';
		$areas = json_decode( $raw, true );
		if ( ! is_array( $areas ) ) {
			wp_send_json_error( array( 'message' => __( 'The service-area list is invalid.', 'airfiber-centralized' ) ), 400 );
		}
		$areas = self::sanitize_service_areas( $areas );
		update_option( self::SERVICE_AREAS_OPTION, $areas, false );
		wp_send_json_success(
			array(
				'message' => __( 'Service areas saved. Address suggestions were refreshed.', 'airfiber-centralized' ),
				'areas'   => $areas,
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage PPP accounts.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}
}
