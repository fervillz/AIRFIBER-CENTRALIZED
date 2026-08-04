<?php

defined( 'ABSPATH' ) || exit;

/**
 * Live due-today SMS preparation, manual due-message resend, and compact
 * delivery activity for the Advanced workspace sidebar.
 */
class AFC_SMS_Due_Runtime {

	const NONCE            = 'afc_sms_due_runtime';
	const OPTION_LAST_SCAN = 'afc_sms_due_runtime_last_scan';
	const TRANSIENT_LOCK   = 'afc_sms_due_runtime_lock';

	private static $queue_diagnostic = array();

	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'before_rest_callbacks' ), 11, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'after_rest_callbacks' ), 11, 3 );
		add_action( 'wp_ajax_afc_sms_resend_due', array( __CLASS__, 'ajax_resend_due' ) );
		add_action( 'wp_ajax_afc_sms_due_activity', array( __CLASS__, 'ajax_activity' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 1008 );
		add_filter( 'afc_ajaxify_asset_group', array( __CLASS__, 'asset_group' ), 10, 2 );
	}

	public static function asset_group( $group, $handle ) {
		if ( in_array( $handle, array( 'afc-workspace-sms-activity', 'afc-workspace-sms-activity-style' ), true ) ) {
			return 'workspace';
		}
		return $group;
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-workspace-sms-activity-style',
			AFC_URL . 'assets/css/workspace-sms-activity.css',
			array( 'afc-advanced-workspace' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-workspace-sms-activity',
			AFC_URL . 'assets/js/workspace-sms-activity.js',
			array( 'afc-advanced-workspace' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-workspace-sms-activity',
			'afcSmsDueActivity',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	private static function is_queue_request( $request ) {
		return $request instanceof WP_REST_Request
			&& '/airfiber/v1/sms/queue' === (string) $request->get_route();
	}

	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function truth( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	private static function phone( $value ) {
		return class_exists( 'AFC_SMS_Payer_Ratings' )
			? AFC_SMS_Payer_Ratings::phone( $value )
			: '';
	}

	private static function custom_value( $details, $canonical ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		foreach ( $fields as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, (string) $canonical ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function detail_value( $details, $key, $custom = '' ) {
		if ( isset( $details[ $key ] ) && '' !== trim( (string) $details[ $key ] ) ) {
			return trim( (string) $details[ $key ] );
		}
		return $custom ? self::custom_value( $details, $custom ) : '';
	}

	private static function secret_data( $secret ) {
		$comment = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details = class_exists( 'AFC_Comment_Fields' )
			? AFC_Comment_Fields::parse_comment( $comment )
			: array();
		$account = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
		$name    = self::detail_value( $details, 'name' );
		$phone   = self::detail_value( $details, 'cp' );

		return array(
			'account'        => $account,
			'name'           => $name ? $name : $account,
			'phone'          => self::phone( $phone ),
			'phone_raw'      => $phone,
			'comment'        => $comment,
			'next_due'       => self::custom_value( $details, 'nextDue' ),
			'cutoff'         => self::custom_value( $details, 'cutoffDate' ),
			'payment_date'   => self::detail_value( $details, 'payment_date' ),
			'payment_amount' => self::detail_value( $details, 'payment_amount' ),
			'installed'      => self::detail_value( $details, 'installed' ),
			'profile'        => isset( $secret['profile'] ) ? trim( (string) $secret['profile'] ) : '',
			'disabled'       => isset( $secret['disabled'] ) && self::truth( $secret['disabled'] ),
		);
	}

	private static function router_users() {
		if ( ! class_exists( 'AFC_MikroTik' ) ) {
			return new WP_Error( 'afc_sms_due_router_missing', 'MikroTik is not available.' );
		}
		$result = AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=name,profile,comment,disabled' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $result['name'] ) ) {
			$result = array( $result );
		}
		$users = array();
		foreach ( (array) $result as $secret ) {
			$data = self::secret_data( $secret );
			if ( $data['account'] ) {
				$users[ strtolower( $data['account'] ) ] = $data;
			}
		}
		return $users;
	}

	private static function customer_id( $account ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_afc_ppp_username'
				WHERE p.post_type='afc_customer' AND LOWER(pm.meta_value)=LOWER(%s)
				ORDER BY p.ID DESC LIMIT 1",
				$account
			)
		);
	}

	private static function ensure_customer( $data ) {
		$customer_id = self::customer_id( $data['account'] );
		if ( ! $customer_id ) {
			$customer_id = wp_insert_post(
				array(
					'post_type'   => 'afc_customer',
					'post_status' => 'publish',
					'post_title'  => $data['name'] ? $data['name'] : $data['account'],
				),
				true
			);
			if ( is_wp_error( $customer_id ) ) {
				return $customer_id;
			}
			update_post_meta( $customer_id, '_afc_ppp_username', $data['account'] );
			update_post_meta( $customer_id, '_afc_account_number', 'AIR-' . str_pad( (string) $customer_id, 6, '0', STR_PAD_LEFT ) );
		}

		$meta = array(
			'_afc_customer_name'            => $data['name'],
			'_afc_phone'                    => $data['phone_raw'],
			'_afc_mikrotik_comment'         => $data['comment'],
			'_afc_comment_field_nextdue'    => $data['next_due'],
			'_afc_comment_field_cutoffdate' => $data['cutoff'],
			'_afc_payment_date'             => $data['payment_date'],
			'_afc_payment_amount'           => $data['payment_amount'],
			'_afc_installation_date'        => $data['installed'],
			'_afc_plan'                     => $data['profile'],
			'_afc_customer_status'          => 0 === strcasecmp( $data['profile'], 'Expired' ) ? 'expired' : 'active',
		);
		foreach ( $meta as $key => $value ) {
			if ( '' !== (string) $value ) {
				update_post_meta( $customer_id, $key, $value );
			}
		}
		return (int) $customer_id;
	}

	private static function send_window() {
		$rules = class_exists( 'AFC_SMS_Payer_Ratings' ) ? AFC_SMS_Payer_Ratings::rules() : array();
		$hour  = (int) current_time( 'G' );
		$start = isset( $rules['send_start_hour'] ) ? max( 0, min( 23, (int) $rules['send_start_hour'] ) ) : 9;
		$end   = isset( $rules['send_end_hour'] ) ? max( 0, min( 23, (int) $rules['send_end_hour'] ) ) : 18;
		if ( $start < $end ) {
			$inside = $hour >= $start && $hour < $end;
		} elseif ( $start > $end ) {
			$inside = $hour >= $start || $hour < $end;
		} else {
			$inside = false;
		}
		return array(
			'hour'   => $hour,
			'start'  => $start,
			'end'    => $end,
			'inside' => $inside,
			'label'  => sprintf( '%02d:00–%02d:00', $start, $end ),
		);
	}

	private static function template_body( $customer_id, $data ) {
		$fallback = 'Hi {name}, your internet bill is due today ({due_date}). Please settle your account to avoid service interruption. Thank you.';
		if ( ! class_exists( 'AFC_SMS_Templates' ) ) {
			return strtr( $fallback, array( '{name}' => $data['name'], '{due_date}' => $data['next_due'] ) );
		}

		$settings = AFC_SMS_Templates::get_settings();
		$profile  = class_exists( 'AFC_SMS_Payer_Ratings' ) ? AFC_SMS_Payer_Ratings::profile( $customer_id ) : array();
		$mode     = isset( $profile['template_mode'] ) ? $profile['template_mode'] : 'inherit';
		$template = isset( $profile['template_id'] ) ? (int) $profile['template_id'] : 0;
		if ( 'inherit' === $mode ) {
			$mode     = isset( $settings['default_mode'] ) ? $settings['default_mode'] : 'random_category';
			$template = isset( $settings['default_template_id'] ) ? (int) $settings['default_template_id'] : 0;
		}
		if ( in_array( $mode, array( 'manual', 'random_due', 'random_all' ), true ) ) {
			$mode = 'random_category';
		}
		$chosen = AFC_SMS_Templates::choose_template( 'due', $mode, $template );
		$body   = $chosen && ! empty( $chosen['body'] ) ? $chosen['body'] : $fallback;
		return AFC_SMS_Templates::apply_tokens(
			$body,
			array(
				'name'           => $data['name'],
				'ppp'            => $data['account'],
				'phone'          => $data['phone'],
				'due_date'       => $data['next_due'],
				'amount'         => $data['payment_amount'] ? $data['payment_amount'] : 'regular monthly bill',
				'payment_number' => isset( $settings['payment_number'] ) ? $settings['payment_number'] : '',
			)
		);
	}

	private static function blocked_reason( $customer_id, $manual = false ) {
		if ( self::truth( get_post_meta( $customer_id, '_afc_do_not_text', true ) ) || self::truth( get_post_meta( $customer_id, '_afc_sms_opt_out', true ) ) ) {
			return 'do-not-text';
		}
		if ( ! $manual && '1' === (string) get_post_meta( $customer_id, '_afc_sms_contact_paused', true ) ) {
			return 'paused';
		}
		return '';
	}

	private static function queue_job( $customer_id, $data, $manual = false ) {
		global $wpdb;
		$due   = self::date( $data['next_due'] );
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		if ( ! $due ) {
			return new WP_Error( 'afc_sms_due_missing_date', 'This account has no valid next due date.' );
		}
		if ( $manual ? $due > $today : $due != $today ) {
			return new WP_Error( 'afc_sms_due_not_due', $manual ? 'This account is not due yet.' : 'This account is not due today.' );
		}
		if ( ! $data['phone'] ) {
			return new WP_Error( 'afc_sms_due_no_phone', 'This account has no valid Philippine mobile number.' );
		}
		$blocked = self::blocked_reason( $customer_id, $manual );
		if ( $blocked ) {
			return new WP_Error( 'afc_sms_due_' . $blocked, 'do-not-text' === $blocked ? 'This customer is marked Do Not Text.' : 'Automatic SMS is paused for this customer.' );
		}

		$jobs   = $wpdb->prefix . 'afc_sms_jobs';
		$events = $wpdb->prefix . 'afc_sms_events';
		$key    = $manual
			? 'due-manual-resend|' . strtolower( $data['account'] ) . '|' . current_time( 'YmdHis' ) . '|' . wp_generate_password( 5, false, false )
			: 'due-today|' . strtolower( $data['account'] ) . '|' . $due->format( 'Y-m-d' );
		if ( ! $manual ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$jobs} WHERE dedupe_key=%s LIMIT 1", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $existing ) {
				return array( 'queued' => false, 'duplicate' => true, 'job_id' => $existing );
			}
		}

		$now     = current_time( 'mysql' );
		$message = self::template_body( $customer_id, $data );
		$type    = $manual ? 'due-manual-resend' : 'due-today-auto';
		$detail  = $manual
			? 'Due SMS manually resent from customer account options.'
			: 'PPP account reached nextDue today and was queued for the Android gateway.';
		$inserted = $wpdb->insert(
			$jobs,
			array(
				'dedupe_key'    => $key,
				'customer_id'   => $customer_id,
				'ppp_username'  => $data['account'],
				'customer_name' => $data['name'],
				'phone'         => $data['phone'],
				'message'       => $message,
				'reminder_type' => $type,
				'status'        => 'queued',
				'device_id'     => '',
				'last_detail'   => $detail,
				'created_by'    => $manual ? get_current_user_id() : 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'afc_sms_due_queue_failed', 'The due SMS could not be added to the Android queue.' );
		}

		$job_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$events,
			array(
				'job_id'     => $job_id,
				'device_id'  => '',
				'status'     => 'queued',
				'detail'     => sprintf( '%s became due on %s. %s', $data['account'], $due->format( 'Y-m-d' ), $detail ),
				'event_time' => $now,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		update_post_meta( $customer_id, '_afc_sms_last_reminder_at', $now );
		return array( 'queued' => true, 'duplicate' => false, 'job_id' => $job_id, 'message' => $message );
	}

	private static function diagnostic( $status, $message, $extra = array() ) {
		return array_merge(
			array(
				'status'     => sanitize_key( $status ),
				'message'    => (string) $message,
				'checked_at' => current_time( 'mysql' ),
			),
			$extra
		);
	}

	public static function prepare_queue( $force = false, $source = 'gateway-poll' ) {
		$window = self::send_window();
		if ( ! $force && ! $window['inside'] ) {
			$result = self::diagnostic(
				'outside-window',
				sprintf( 'Due-today SMS preparation runs during %s. Use Process Queue Now to check manually outside that window.', $window['label'] ),
				array( 'source' => $source, 'window' => $window['label'], 'within_window' => false )
			);
			update_option( self::OPTION_LAST_SCAN, $result, false );
			return $result;
		}

		if ( ! $force && get_transient( self::TRANSIENT_LOCK ) ) {
			$last = (array) get_option( self::OPTION_LAST_SCAN, array() );
			if ( $last ) {
				$last['status'] = 'recently-checked';
				return $last;
			}
		}
		set_transient( self::TRANSIENT_LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$users = self::router_users();
		if ( is_wp_error( $users ) ) {
			$result = self::diagnostic( 'router-error', 'The due scan could not read the live MikroTik PPP list: ' . $users->get_error_message(), array( 'source' => $source ) );
			update_option( self::OPTION_LAST_SCAN, $result, false );
			return $result;
		}

		$today      = current_time( 'Y-m-d' );
		$candidates = 0;
		$queued     = 0;
		$duplicates = 0;
		$no_phone   = 0;
		$blocked    = 0;
		$disabled   = 0;

		foreach ( $users as $data ) {
			if ( $data['disabled'] ) {
				$disabled++;
				continue;
			}
			if ( $data['next_due'] !== $today ) {
				continue;
			}
			$candidates++;
			$customer_id = self::ensure_customer( $data );
			if ( is_wp_error( $customer_id ) ) {
				$blocked++;
				continue;
			}
			$result = self::queue_job( $customer_id, $data, false );
			if ( is_wp_error( $result ) ) {
				if ( 'afc_sms_due_no_phone' === $result->get_error_code() ) {
					$no_phone++;
				} else {
					$blocked++;
				}
				continue;
			}
			if ( ! empty( $result['duplicate'] ) ) {
				$duplicates++;
			} elseif ( ! empty( $result['queued'] ) ) {
				$queued++;
			}
		}

		if ( $queued ) {
			$message = sprintf( '%d due-today SMS message%s added to the Android queue.', $queued, 1 === $queued ? ' was' : 's were' );
			$status  = 'queued';
		} elseif ( ! $candidates ) {
			$message = 'The live MikroTik scan completed, but no active PPP account has nextDue set to today.';
			$status  = 'none-due-today';
		} elseif ( $duplicates === $candidates ) {
			$message = sprintf( '%d due-today account%s already had an SMS created for this due date.', $candidates, 1 === $candidates ? '' : 's' );
			$status  = 'already-created';
		} else {
			$message = sprintf( '%d due-today account%s found; none could be newly queued. Missing phone: %d. Blocked or paused: %d.', $candidates, 1 === $candidates ? '' : 's', $no_phone, $blocked );
			$status  = 'checked';
		}

		$result = self::diagnostic(
			$status,
			$message,
			array(
				'source'         => $source,
				'window'         => $window['label'],
				'within_window'  => $window['inside'] || $force,
				'forced'         => (bool) $force,
				'candidates'     => $candidates,
				'queued_new'     => $queued,
				'duplicates'     => $duplicates,
				'missing_phone'  => $no_phone,
				'blocked'        => $blocked,
				'disabled_users' => $disabled,
			)
		);
		update_option( self::OPTION_LAST_SCAN, $result, false );
		return $result;
	}

	public static function before_rest_callbacks( $response, $handler, $request ) {
		if ( self::is_queue_request( $request ) && ! is_wp_error( $response ) ) {
			$force = 'force' === sanitize_key( (string) $request->get_param( 'prepare_due' ) );
			self::$queue_diagnostic = self::prepare_queue( $force, $force ? 'gateway-manual' : 'gateway-poll' );
		}
		return $response;
	}

	public static function after_rest_callbacks( $response, $handler, $request ) {
		if ( ! self::is_queue_request( $request ) || is_wp_error( $response ) ) {
			return $response;
		}
		$response = rest_ensure_response( $response );
		$data     = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			$data['data'] = array();
		}
		$data['data']['due_scan'] = self::$queue_diagnostic
			? self::$queue_diagnostic
			: (array) get_option( self::OPTION_LAST_SCAN, array() );
		$response->set_data( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	private static function request_data( $account ) {
		$comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';
		$secret  = array(
			'name'     => $account,
			'profile'  => isset( $_POST['actual_profile'] ) ? sanitize_text_field( wp_unslash( $_POST['actual_profile'] ) ) : '',
			'comment'  => $comment,
			'disabled' => 'false',
		);
		$data = self::secret_data( $secret );
		if ( isset( $_POST['customer_name'] ) && '' !== trim( (string) $_POST['customer_name'] ) ) {
			$data['name'] = sanitize_text_field( wp_unslash( $_POST['customer_name'] ) );
		}
		if ( isset( $_POST['phone'] ) && '' !== trim( (string) $_POST['phone'] ) ) {
			$data['phone_raw'] = sanitize_text_field( wp_unslash( $_POST['phone'] ) );
			$data['phone']     = self::phone( $data['phone_raw'] );
		}
		if ( isset( $_POST['payment_amount'] ) && '' !== trim( (string) $_POST['payment_amount'] ) ) {
			$data['payment_amount'] = sanitize_text_field( wp_unslash( $_POST['payment_amount'] ) );
		}
		return $data;
	}

	public static function ajax_resend_due() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to resend due SMS messages.' ), 403 );
		}
		check_ajax_referer( AFC_SMS_PreCutoff::NONCE, 'nonce' );
		$account = isset( $_POST['account'] ) ? sanitize_text_field( wp_unslash( $_POST['account'] ) ) : '';
		if ( ! $account ) {
			wp_send_json_error( array( 'message' => 'Choose a PPP account first.' ) );
		}

		$data  = null;
		$users = self::router_users();
		if ( ! is_wp_error( $users ) && isset( $users[ strtolower( $account ) ] ) ) {
			$data = $users[ strtolower( $account ) ];
		}
		if ( ! $data ) {
			$data = self::request_data( $account );
		}
		$customer_id = self::ensure_customer( $data );
		if ( is_wp_error( $customer_id ) ) {
			wp_send_json_error( array( 'message' => $customer_id->get_error_message() ) );
		}
		$result = self::queue_job( $customer_id, $data, true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$signals = class_exists( 'AFC_SMS_PreCutoff' )
			? AFC_SMS_PreCutoff::signals_for_accounts( array( $account ) )
			: array();
		wp_send_json_success(
			array(
				'message' => sprintf( 'Due SMS for %s was added again to the Android queue.', $account ),
				'jobId'   => $result['job_id'],
				'signal'  => isset( $signals[ $account ] ) ? $signals[ $account ] : array(),
			)
		);
	}

	private static function activity_label( $status ) {
		$labels = array(
			'queued'    => 'Queued on web',
			'claimed'   => 'Received by phone',
			'submitted' => 'Submitted by phone',
			'sent'      => 'Sent by phone',
			'delivered' => 'Delivered',
			'failed'    => 'Failed',
			'cancelled' => 'Cancelled',
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}

	private static function display_time( $value ) {
		$value = trim( (string) $value );
		if ( ! $value ) {
			return '';
		}
		try {
			$date = new DateTimeImmutable( $value, self::timezone() );
			return wp_date( 'M j, g:i a', $date->getTimestamp(), self::timezone() );
		} catch ( Exception $exception ) {
			return $value;
		}
	}

	public static function activity( $limit = 6 ) {
		global $wpdb;
		$limit = max( 1, min( 12, (int) $limit ) );
		$table = $wpdb->prefix . 'afc_sms_jobs';
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,ppp_username,customer_name,reminder_type,status,device_id,last_detail,created_at,claimed_at,submitted_at,sent_at,delivered_at,failed_at,cancelled_at
				FROM {$table}
				WHERE reminder_type LIKE 'due-%%' OR reminder_type LIKE 'precutoff-%%'
				ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $row ) {
			$status = sanitize_key( $row['status'] );
			$items[] = array(
				'id'                => (int) $row['id'],
				'ppp'               => (string) $row['ppp_username'],
				'name'              => (string) $row['customer_name'],
				'type'              => (string) $row['reminder_type'],
				'status'            => $status,
				'statusLabel'       => self::activity_label( $status ),
				'detail'            => (string) $row['last_detail'],
				'device'            => (string) $row['device_id'],
				'queuedAt'          => self::display_time( $row['created_at'] ),
				'phoneReceivedAt'   => self::display_time( $row['claimed_at'] ),
				'phoneSubmittedAt'  => self::display_time( $row['submitted_at'] ),
				'sentAt'            => self::display_time( $row['sent_at'] ),
				'deliveredAt'       => self::display_time( $row['delivered_at'] ),
				'failedAt'          => self::display_time( $row['failed_at'] ),
				'cancelledAt'       => self::display_time( $row['cancelled_at'] ),
			);
		}
		return $items;
	}

	public static function ajax_activity() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to view SMS activity.' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
		wp_send_json_success(
			array(
				'items'    => self::activity( 6 ),
				'lastScan' => (array) get_option( self::OPTION_LAST_SCAN, array() ),
			)
		);
	}
}
