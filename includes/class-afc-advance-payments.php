<?php

defined( 'ABSPATH' ) || exit;

/**
 * Configurable monthly advance-payment presets and the Advanced settings UI.
 * Monthly advances preserve existing paid coverage, update PPP billing fields,
 * and reuse the normal payment hooks so MikroTik schedulers move safely.
 */
class AFC_Advance_Payments {

	const OPTION_KEY = 'afc_advance_payment_settings';
	const NONCE      = 'afc_advance_payment_settings';

	public static function init() {
		// Runs before AFC_Billing_Cycles::ajax_quick_payment (priority 10). Only
		// requests explicitly marked as monthly advances are handled here.
		add_action( 'wp_ajax_afc_ppp_quick_payment', array( __CLASS__, 'maybe_handle_advance_payment' ), 1 );
		add_action( 'wp_ajax_afc_save_advance_payment_settings', array( __CLASS__, 'ajax_save_settings' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ), 72 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 72 );
		add_action( 'afc_frontend_app_content', array( __CLASS__, 'render_frontend_panel' ) );
	}

	public static function defaults() {
		return array(
			'presets'        => array( 1, 3, 6, 12, 24, 60 ),
			'max_months'     => 120,
			'auto_amount'    => 1,
			'warning_months' => 12,
		);
	}

	public static function get_settings() {
		$settings = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
		return self::sanitize_settings( $settings );
	}

	private static function sanitize_presets( $raw, $max_months ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,;]+/', $raw );
		}
		$raw = is_array( $raw ) ? $raw : array();

		$presets = array();
		foreach ( $raw as $value ) {
			$months = absint( $value );
			if ( $months >= 1 && $months <= $max_months ) {
				$presets[] = $months;
			}
		}
		$presets = array_values( array_unique( $presets ) );
		sort( $presets, SORT_NUMERIC );
		return array_slice( $presets, 0, 10 );
	}

	private static function sanitize_settings( $input ) {
		$defaults       = self::defaults();
		$max_months     = isset( $input['max_months'] ) ? absint( $input['max_months'] ) : $defaults['max_months'];
		$max_months     = min( 240, max( 1, $max_months ) );
		$warning_months = isset( $input['warning_months'] ) ? absint( $input['warning_months'] ) : $defaults['warning_months'];
		$warning_months = min( $max_months, max( 1, $warning_months ) );
		$presets        = self::sanitize_presets( isset( $input['presets'] ) ? $input['presets'] : $defaults['presets'], $max_months );
		if ( ! $presets ) {
			$presets = array_values(
				array_filter(
					$defaults['presets'],
					function ( $value ) use ( $max_months ) {
						return $value <= $max_months;
					}
				)
			);
		}

		return array(
			'presets'        => $presets,
			'max_months'     => $max_months,
			'auto_amount'    => empty( $input['auto_amount'] ) ? 0 : 1,
			'warning_months' => $warning_months,
		);
	}

	private static function authorize_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change payment settings.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function authorize_payment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to record payments.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( 'afc_quick_payment', 'nonce' );
	}

	private static function timezone() {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, self::timezone() );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return null;
		}
		return $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function date_for_month( $year, $month, $billing_day ) {
		$first = new DateTimeImmutable( sprintf( '%04d-%02d-01', (int) $year, (int) $month ), self::timezone() );
		$day   = min( max( 1, (int) $billing_day ), (int) $first->format( 't' ) );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), $day );
	}

	private static function add_months_due( DateTimeImmutable $source, $months, $billing_day ) {
		$target = $source->modify( 'first day of this month' )->modify( sprintf( '%+d months', (int) $months ) );
		return self::date_for_month( (int) $target->format( 'Y' ), (int) $target->format( 'm' ), $billing_day );
	}

	private static function nearest_monthly_due( DateTimeImmutable $date, $billing_day ) {
		$candidates = array();
		foreach ( array( -1, 0, 1 ) as $offset ) {
			$month = $date->modify( 'first day of this month' )->modify( sprintf( '%+d months', $offset ) );
			$due   = self::date_for_month( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $billing_day );
			$candidates[] = array(
				'date'     => $due,
				'distance' => abs( $due->getTimestamp() - $date->getTimestamp() ),
			);
		}
		usort(
			$candidates,
			function ( $first, $second ) {
				if ( $first['distance'] === $second['distance'] ) {
					return $second['date']->getTimestamp() <=> $first['date']->getTimestamp();
				}
				return $first['distance'] <=> $second['distance'];
			}
		);
		return $candidates[0]['date'];
	}

	private static function configured_key( $canonical ) {
		foreach ( AFC_Comment_Fields::get_custom_fields() as $field ) {
			if ( isset( $field['key'] ) && 0 === strcasecmp( $canonical, $field['key'] ) ) {
				return $field['key'];
			}
		}
		return $canonical;
	}

	private static function custom_value( $details, $canonical ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		foreach ( $fields as $key => $value ) {
			if ( 0 === strcasecmp( $canonical, $key ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function grace_details( $details ) {
		$raw = isset( $details['grace'] ) ? trim( (string) $details['grace'] ) : '';
		if ( '' === $raw || ! preg_match( '/^\d+$/', $raw ) ) {
			return array( 'used' => 6, 'normalize' => false );
		}
		$value = (int) $raw;
		return array(
			'used'      => min( 6, max( 0, $value ) ),
			'normalize' => $value > 6,
		);
	}

	private static function billing_day( $details ) {
		$billing_day = (int) self::custom_value( $details, 'billingDay' );
		if ( $billing_day >= 1 && $billing_day <= 31 ) {
			return $billing_day;
		}
		$installed = self::parse_date( isset( $details['installed'] ) ? $details['installed'] : '' );
		return $installed ? (int) $installed->format( 'j' ) : 0;
	}

	private static function plan_rate( $details ) {
		$plan = isset( $details['plan'] ) ? trim( (string) $details['plan'] ) : '';
		if ( $plan && preg_match_all( '/\d+(?:\.\d+)?/', $plan, $matches ) && ! empty( $matches[0] ) ) {
			$value = (float) end( $matches[0] );
			if ( $value > 0 ) {
				return $value;
			}
		}
		$amount = isset( $details['payment_amount'] ) ? trim( (string) $details['payment_amount'] ) : '';
		return is_numeric( $amount ) ? max( 0, (float) $amount ) : 0;
	}

	private static function get_current_secret( $id ) {
		$secrets = AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=.id,name,comment,profile' ) );
		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}
		if ( isset( $secrets['name'] ) ) {
			$secrets = array( $secrets );
		}
		foreach ( (array) $secrets as $secret ) {
			if ( isset( $secret['.id'] ) && (string) $secret['.id'] === (string) $id ) {
				return $secret;
			}
		}
		return new WP_Error( 'afc_ppp_missing', __( 'The PPP account no longer exists in MikroTik.', 'airfiber-centralized' ) );
	}

	private static function get_customer_id( $username ) {
		$ids = get_posts(
			array(
				'post_type'      => 'afc_customer',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_afc_ppp_username',
				'meta_value'     => $username,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function update_customer_meta( $customer_id, $comment, $date, $method, $amount, $months, $billing_day, $paid_through, $next_due, $cutoff ) {
		if ( ! $customer_id ) {
			return;
		}
		update_post_meta( $customer_id, '_afc_payment_date', $date );
		update_post_meta( $customer_id, '_afc_payment_method', $method );
		update_post_meta( $customer_id, '_afc_payment_amount', $amount );
		update_post_meta( $customer_id, '_afc_mikrotik_comment', $comment );
		update_post_meta( $customer_id, '_afc_last_advance_months', $months );
		delete_post_meta( $customer_id, '_afc_comment_field_billingcycledays' );
		update_post_meta( $customer_id, '_afc_comment_field_billingday', $billing_day );
		update_post_meta( $customer_id, '_afc_comment_field_paidthrough', $paid_through->format( 'Y-m-d' ) );
		update_post_meta( $customer_id, '_afc_comment_field_nextdue', $next_due->format( 'Y-m-d' ) );
		update_post_meta( $customer_id, '_afc_comment_field_cutoffdate', $cutoff->format( 'Y-m-d' ) );
		delete_post_meta( $customer_id, '_afc_comment_field_promisedpaydate' );
	}

	public static function maybe_handle_advance_payment() {
		$mode = isset( $_POST['advance_mode'] ) ? sanitize_key( wp_unslash( $_POST['advance_mode'] ) ) : '';
		if ( 'monthly' !== $mode ) {
			return;
		}

		self::authorize_payment();
		$settings   = self::get_settings();
		$id         = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$method     = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$months     = isset( $_POST['advance_months'] ) ? absint( wp_unslash( $_POST['advance_months'] ) ) : 1;
		$has_amount = isset( $_POST['amount'] ) && '' !== trim( (string) wp_unslash( $_POST['amount'] ) );
		$amount     = $has_amount ? (float) wp_unslash( $_POST['amount'] ) : null;

		if ( ! $id || ! $name || ! in_array( $method, array( 'cash', 'gcash' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'The payment request is incomplete.', 'airfiber-centralized' ) ), 400 );
		}
		if ( $months < 1 || $months > $settings['max_months'] ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Advance payment must be between 1 and %d months.', 'airfiber-centralized' ), $settings['max_months'] ) ), 400 );
		}
		if ( null !== $amount && $amount < 0 ) {
			wp_send_json_error( array( 'message' => __( 'The payment amount cannot be negative.', 'airfiber-centralized' ) ), 400 );
		}

		$secret = self::get_current_secret( $id );
		if ( is_wp_error( $secret ) ) {
			wp_send_json_error( array( 'message' => $secret->get_error_message() ) );
		}

		$current_name = isset( $secret['name'] ) ? sanitize_text_field( $secret['name'] ) : $name;
		$comment      = isset( $secret['comment'] ) ? (string) $secret['comment'] : '';
		$details      = AFC_Comment_Fields::parse_comment( $comment );
		$billing_day  = self::billing_day( $details );
		if ( ! $billing_day ) {
			wp_send_json_error( array( 'message' => __( 'The installation date or billing day is missing.', 'airfiber-centralized' ) ), 400 );
		}

		$payment_date = new DateTimeImmutable( current_time( 'Y-m-d' ), self::timezone() );
		$first_due    = self::parse_date( self::custom_value( $details, 'nextDue' ) );
		if ( ! $first_due ) {
			$first_due = self::nearest_monthly_due( $payment_date, $billing_day );
		}
		$paid_through = self::add_months_due( $first_due, $months - 1, $billing_day );
		$next_due     = self::add_months_due( $first_due, $months, $billing_day );
		$grace        = self::grace_details( $details );
		$cutoff       = $next_due->modify( '+' . ( $grace['used'] + 1 ) . ' days' );

		if ( null === $amount ) {
			$base_amount = self::plan_rate( $details );
			$amount      = ! empty( $settings['auto_amount'] ) ? $base_amount * $months : $base_amount;
		}
		$amount = round( max( 0, (float) $amount ), 2 );

		$new_comment = AFC_Comment_Fields::replace_value( $comment, 'paymentDate', $payment_date->format( 'Y-m-d' ) );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, 'paymentMethod', $method );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, 'paymentAmount', (string) $amount );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, self::configured_key( 'billingCycleDays' ), '' );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, self::configured_key( 'billingDay' ), (string) $billing_day );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, self::configured_key( 'paidThrough' ), $paid_through->format( 'Y-m-d' ) );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, self::configured_key( 'nextDue' ), $next_due->format( 'Y-m-d' ) );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, self::configured_key( 'cutoffDate' ), $cutoff->format( 'Y-m-d' ) );
		$new_comment = AFC_Comment_Fields::replace_value( $new_comment, self::configured_key( 'promisedPayDate' ), '' );
		if ( $grace['normalize'] ) {
			$new_comment = AFC_Comment_Fields::replace_value( $new_comment, 'grace', (string) $grace['used'] );
		}

		$result = AFC_MikroTik::run_command( array( '/ppp/secret/set', '=.id=' . $id, '=comment=' . $new_comment ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$date        = $payment_date->format( 'Y-m-d' );
		$customer_id = self::get_customer_id( $current_name );
		self::update_customer_meta( $customer_id, $new_comment, $date, $method, $amount, $months, $billing_day, $paid_through, $next_due, $cutoff );

		$payment_id = wp_insert_post(
			array(
				'post_type'   => 'afc_payment',
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Payment - %s - %s', $current_name, $date ),
			),
			true
		);
		if ( ! is_wp_error( $payment_id ) ) {
			update_post_meta( $payment_id, '_afc_customer_id', $customer_id );
			update_post_meta( $payment_id, '_afc_ppp_username', $current_name );
			update_post_meta( $payment_id, '_afc_payment_date', $date );
			update_post_meta( $payment_id, '_afc_payment_amount', $amount );
			update_post_meta( $payment_id, '_afc_payment_method', $method );
			update_post_meta( $payment_id, '_afc_payment_reference', 'gcash' === $method ? 'XXXX' : '' );
			update_post_meta( $payment_id, '_afc_billing_cycle_days', 0 );
			update_post_meta( $payment_id, '_afc_advance_months', $months );
			update_post_meta( $payment_id, '_afc_paid_through', $paid_through->format( 'Y-m-d' ) );
			update_post_meta( $payment_id, '_afc_next_due', $next_due->format( 'Y-m-d' ) );
			update_post_meta( $payment_id, '_afc_cutoff_date', $cutoff->format( 'Y-m-d' ) );
			update_post_meta( $payment_id, '_afc_recorded_by', get_current_user_id() );
			do_action( 'afc_payment_recorded', $payment_id, $customer_id );
		}
		do_action( 'afc_quick_payment_recorded', $current_name, $method, $date, $customer_id );

		$duration = 12 === $months ? '1 year' : ( 24 === $months ? '2 years' : ( 60 === $months ? '5 years' : sprintf( '%d months', $months ) ) );
		wp_send_json_success(
			array(
				'message'         => sprintf( __( '%1$s advance payment recorded for %2$s (%3$s).', 'airfiber-centralized' ), 'gcash' === $method ? 'GCash' : 'Cash', $current_name, $duration ),
				'date'            => $date,
				'method'          => $method,
				'amount'          => $amount,
				'reference'       => 'gcash' === $method ? 'XXXX' : '',
				'cycleDays'       => 0,
				'cycleLabel'      => 'Monthly',
				'advanceMonths'   => $months,
				'paidThrough'     => $paid_through->format( 'Y-m-d' ),
				'nextDue'         => $next_due->format( 'Y-m-d' ),
				'cutoffDate'      => $cutoff->format( 'Y-m-d' ),
				'promiseCleared'  => true,
				'normalizedGrace' => $grace['normalize'],
			)
		);
	}

	public static function ajax_save_settings() {
		self::authorize_settings();
		$input = array(
			'presets'        => isset( $_POST['presets'] ) ? wp_unslash( $_POST['presets'] ) : '',
			'max_months'     => isset( $_POST['max_months'] ) ? wp_unslash( $_POST['max_months'] ) : 120,
			'auto_amount'    => ! empty( $_POST['auto_amount'] ) ? 1 : 0,
			'warning_months' => isset( $_POST['warning_months'] ) ? wp_unslash( $_POST['warning_months'] ) : 12,
		);
		$settings = self::sanitize_settings( $input );
		update_option( self::OPTION_KEY, $settings, false );
		wp_send_json_success(
			array(
				'message'  => __( 'Advance payment settings saved.', 'airfiber-centralized' ),
				'settings' => $settings,
			)
		);
	}

	private static function enqueue_action_assets() {
		$settings = self::get_settings();
		wp_enqueue_style(
			'afc-advance-payments',
			AFC_URL . 'assets/css/advance-payments.css',
			array( 'afc-billing-cycle-actions' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-advance-payments',
			AFC_URL . 'assets/js/advance-payments.js',
			array( 'jquery', 'afc-billing-cycle-actions', 'afc-ppp-users' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-advance-payments',
			'afcAdvancePayments',
			array(
				'currentDate' => current_time( 'Y-m-d' ),
				'settings'    => $settings,
				'currency'    => '₱',
			)
		);
	}

	public static function enqueue_frontend_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_action_assets();
		wp_enqueue_style(
			'afc-payment-settings',
			AFC_URL . 'assets/css/payment-settings.css',
			array( 'afc-frontend-app', 'afc-advance-payments' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-payment-settings',
			AFC_URL . 'assets/js/payment-settings.js',
			array( 'afc-frontend-app', 'afc-admin-mode', 'afc-advance-payments' ),
			AFC_VERSION,
			true
		);
		wp_localize_script(
			'afc-payment-settings',
			'afcPaymentSettings',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'settings' => self::get_settings(),
				'labels'   => array( 'nav' => __( 'Payment Settings', 'airfiber-centralized' ) ),
			)
		);
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_airfiber-centralized' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::enqueue_action_assets();
	}

	private static function preset_label( $months ) {
		if ( 12 === (int) $months ) {
			return __( '1 Year', 'airfiber-centralized' );
		}
		if ( 24 === (int) $months ) {
			return __( '2 Years', 'airfiber-centralized' );
		}
		if ( 60 === (int) $months ) {
			return __( '5 Years', 'airfiber-centralized' );
		}
		return sprintf( _n( '%d Month', '%d Months', (int) $months, 'airfiber-centralized' ), (int) $months );
	}

	public static function render_frontend_panel() {
		$settings = self::get_settings();
		?>
		<section class="afc-frontend-panel afc-advanced-only afc-payment-settings-panel" data-afc-panel="payment-settings" aria-hidden="true" hidden>
			<div class="afc-payment-settings-shell" id="afc-payment-settings">
				<header class="afc-payment-settings-header">
					<div><span><?php esc_html_e( 'Advanced billing configuration', 'airfiber-centralized' ); ?></span><h1><?php esc_html_e( 'Payment Settings', 'airfiber-centralized' ); ?></h1><p><?php esc_html_e( 'Choose which advance-payment durations appear when CASH or GCash is long-pressed.', 'airfiber-centralized' ); ?></p></div>
					<div class="afc-payment-settings-status"><span></span><strong><?php esc_html_e( 'Monthly advances preserve existing paid coverage', 'airfiber-centralized' ); ?></strong></div>
				</header>

				<div class="afc-payment-settings-notice" data-afc-payment-settings-notice aria-live="polite"></div>
				<div class="afc-payment-settings-grid">
					<form data-afc-payment-settings-form>
						<label><span><?php esc_html_e( 'Advance presets', 'airfiber-centralized' ); ?></span><input type="text" name="presets" value="<?php echo esc_attr( implode( ', ', $settings['presets'] ) ); ?>" placeholder="1, 3, 6, 12, 24, 60"><small><?php esc_html_e( 'Enter month counts separated by commas. Up to 10 preset buttons are shown.', 'airfiber-centralized' ); ?></small></label>
						<div class="afc-payment-settings-two">
							<label><span><?php esc_html_e( 'Maximum custom months', 'airfiber-centralized' ); ?></span><input type="number" name="max_months" min="1" max="240" value="<?php echo esc_attr( $settings['max_months'] ); ?>"></label>
							<label><span><?php esc_html_e( 'Extra confirmation from', 'airfiber-centralized' ); ?></span><div class="afc-payment-settings-number"><input type="number" name="warning_months" min="1" max="240" value="<?php echo esc_attr( $settings['warning_months'] ); ?>"><b><?php esc_html_e( 'months', 'airfiber-centralized' ); ?></b></div></label>
						</div>
						<label class="afc-payment-settings-switch"><input type="checkbox" name="auto_amount" value="1" <?php checked( ! empty( $settings['auto_amount'] ) ); ?>><span><strong><?php esc_html_e( 'Automatically calculate the total amount', 'airfiber-centralized' ); ?></strong><small><?php esc_html_e( 'Uses the price found in the plan name, then multiplies it by the selected months. Staff can still edit the amount.', 'airfiber-centralized' ); ?></small></span></label>
						<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Payment Settings', 'airfiber-centralized' ); ?></button>
					</form>

					<aside>
						<span><?php esc_html_e( 'Long-press preview', 'airfiber-centralized' ); ?></span><h2><?php esc_html_e( 'Advance payment buttons', 'airfiber-centralized' ); ?></h2>
						<div class="afc-payment-preset-preview" data-afc-payment-preset-preview>
							<?php foreach ( $settings['presets'] as $months ) : ?><span data-months="<?php echo esc_attr( $months ); ?>"><?php echo esc_html( self::preset_label( $months ) ); ?></span><?php endforeach; ?>
						</div>
						<div class="afc-payment-settings-example"><small><?php esc_html_e( 'Example: Plan1299 × 60 months', 'airfiber-centralized' ); ?></small><strong>₱77,940</strong><p><?php esc_html_e( 'The 60 months start from the customer’s current nextDue date, so already-paid months are never lost.', 'airfiber-centralized' ); ?></p></div>
						<ul><li><?php esc_html_e( '1 month is a normal monthly payment.', 'airfiber-centralized' ); ?></li><li><?php esc_html_e( '60 months equals a 5-year advance.', 'airfiber-centralized' ); ?></li><li><?php esc_html_e( 'The resulting cutoffDate automatically moves the MikroTik scheduler.', 'airfiber-centralized' ); ?></li></ul>
					</aside>
				</div>
			</div>
		</section>
		<?php
	}
}
