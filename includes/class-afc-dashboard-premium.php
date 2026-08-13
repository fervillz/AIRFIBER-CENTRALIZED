<?php

defined( 'ABSPATH' ) || exit;

/**
 * Premium dashboard presentation data.
 *
 * Keeps the visual dashboard layer independent from the existing operational
 * cards so the current payment, PPP, SMS, scheduler and network workflows are
 * unchanged. This endpoint is intentionally compact: one payment-history query
 * plus lightweight PPP secret/active snapshots.
 */
class AFC_Dashboard_Premium {

	const NONCE = 'afc_dashboard_premium';

	public static function init() {
		add_action( 'wp_ajax_afc_dashboard_premium_snapshot', array( __CLASS__, 'ajax_snapshot' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 111 );
		add_action( 'wp_head', array( __CLASS__, 'theme_bootstrap' ), 2 );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-dashboard-premium',
			AFC_URL . 'assets/css/dashboard-premium.css',
			array( 'afc-main-dashboard', 'afc-advanced-workspace' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-dashboard-premium',
			AFC_URL . 'assets/js/dashboard-premium.js',
			array( 'afc-main-dashboard', 'afc-advanced-workspace' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-dashboard-premium',
			'afcDashboardPremium',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'labels'  => array(
					'loading' => __( 'Updating dashboard…', 'airfiber-centralized' ),
					'failed'  => __( 'Dashboard summary could not be refreshed.', 'airfiber-centralized' ),
				),
			)
		);
	}

	/**
	 * Apply the stored theme before the application paints to avoid a light-mode
	 * flash when a user has selected dark mode.
	 */
	public static function theme_bootstrap() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<script id="afc-dashboard-theme-bootstrap">
		(function(){try{var s=localStorage.getItem('afcDashboardTheme');var d=s||(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-afc-theme',d);}catch(e){}}());
		</script>
		<?php
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this dashboard.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function result_rows( $result ) {
		if ( is_wp_error( $result ) ) return $result;
		if ( ! is_array( $result ) ) return array();
		if ( isset( $result['name'] ) ) return array( $result );
		return $result;
	}

	private static function router_bool( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( 'true', 'yes', '1' ), true );
	}

	private static function custom_value( $details, $wanted ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		foreach ( $fields as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, (string) $wanted ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return null;
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	private static function payment_history() {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today    = new DateTimeImmutable( current_time( 'Y-m-d' ), $timezone );
		$start    = $today->modify( '-13 days' );
		$days     = array();

		for ( $index = 0; $index < 14; $index++ ) {
			$date = $start->modify( '+' . $index . ' days' );
			$key = $date->format( 'Y-m-d' );
			$days[ $key ] = array(
				'date'   => $key,
				'label'  => wp_date( 'D', $date->getTimestamp(), $timezone ),
				'amount' => 0.0,
				'count'  => 0,
			);
		}

		$ids = get_posts(
			array(
				'post_type'      => 'afc_payment',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_afc_payment_date',
						'value'   => array( $start->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);

		foreach ( $ids as $payment_id ) {
			$date = trim( (string) get_post_meta( $payment_id, '_afc_payment_date', true ) );
			if ( ! isset( $days[ $date ] ) ) continue;
			$days[ $date ]['amount'] += (float) get_post_meta( $payment_id, '_afc_payment_amount', true );
			$days[ $date ]['count']++;
		}

		$rows = array_values( $days );
		foreach ( $rows as &$row ) $row['amount'] = round( $row['amount'], 2 );
		unset( $row );

		$previous = array_slice( $rows, 0, 7 );
		$current  = array_slice( $rows, 7, 7 );
		$previous_total = array_sum( array_column( $previous, 'amount' ) );
		$current_total  = array_sum( array_column( $current, 'amount' ) );
		$change = null;
		if ( $previous_total > 0 ) {
			$change = round( ( ( $current_total - $previous_total ) / $previous_total ) * 100, 1 );
		} elseif ( $current_total > 0 ) {
			$change = 100.0;
		}

		$today_key = $today->format( 'Y-m-d' );
		return array(
			'series'         => $current,
			'total'          => round( $current_total, 2 ),
			'previous_total' => round( $previous_total, 2 ),
			'change_percent' => $change,
			'today_total'    => isset( $days[ $today_key ] ) ? round( $days[ $today_key ]['amount'], 2 ) : 0,
			'today_count'    => isset( $days[ $today_key ] ) ? (int) $days[ $today_key ]['count'] : 0,
		);
	}

	private static function sms_queue_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'afc_sms_jobs';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) return 0;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function ppp_snapshot() {
		$secrets = self::result_rows( AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=name,profile,comment,disabled' ) ) );
		$active  = self::result_rows( AFC_MikroTik::run_command( array( '/ppp/active/print', '=.proplist=name' ) ) );

		if ( is_wp_error( $secrets ) ) {
			return array(
				'available' => false,
				'online'    => 0,
				'offline'   => 0,
				'expired'   => 0,
				'total'     => 0,
				'due_today' => 0,
				'due_7'     => 0,
				'error'     => $secrets->get_error_message(),
			);
		}

		$active_map = array();
		if ( ! is_wp_error( $active ) ) {
			foreach ( $active as $row ) {
				$name = isset( $row['name'] ) ? strtolower( trim( (string) $row['name'] ) ) : '';
				if ( $name ) $active_map[ $name ] = true;
			}
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today = new DateTimeImmutable( current_time( 'Y-m-d' ), $timezone );
		$online = 0;
		$offline = 0;
		$expired = 0;
		$due_today = 0;
		$due_7 = 0;

		foreach ( $secrets as $secret ) {
			if ( self::router_bool( isset( $secret['disabled'] ) ? $secret['disabled'] : '' ) ) continue;
			$name = isset( $secret['name'] ) ? strtolower( trim( (string) $secret['name'] ) ) : '';
			$profile = isset( $secret['profile'] ) ? trim( (string) $secret['profile'] ) : '';
			$is_expired = 0 === strcasecmp( $profile, 'Expired' );
			if ( $is_expired ) {
				$expired++;
				continue;
			}

			if ( $name && isset( $active_map[ $name ] ) ) $online++;
			else $offline++;

			$details = AFC_Comment_Fields::parse_comment( isset( $secret['comment'] ) ? (string) $secret['comment'] : '' );
			$due = self::parse_date( self::custom_value( $details, 'nextDue' ) );
			if ( ! $due ) continue;
			$days = (int) $today->diff( $due )->format( '%r%a' );
			if ( 0 === $days ) $due_today++;
			if ( $days >= 0 && $days <= 7 ) $due_7++;
		}

		return array(
			'available' => true,
			'online'    => $online,
			'offline'   => $offline,
			'expired'   => $expired,
			'total'     => $online + $offline + $expired,
			'due_today' => $due_today,
			'due_7'     => $due_7,
			'error'     => is_wp_error( $active ) ? $active->get_error_message() : '',
		);
	}

	public static function ajax_snapshot() {
		self::authorize();
		$payments = self::payment_history();
		$ppp      = self::ppp_snapshot();

		wp_send_json_success(
			array(
				'kpis' => array(
					'online'       => (int) $ppp['online'],
					'due_today'    => (int) $ppp['due_today'],
					'due_7'        => (int) $ppp['due_7'],
					'expired'      => (int) $ppp['expired'],
					'today_amount' => (float) $payments['today_total'],
					'today_count'  => (int) $payments['today_count'],
					'sms_queue'    => self::sms_queue_count(),
				),
				'collections' => $payments,
				'health'      => array(
					'online'  => (int) $ppp['online'],
					'offline' => (int) $ppp['offline'],
					'expired' => (int) $ppp['expired'],
					'total'   => (int) $ppp['total'],
				),
				'router_available' => ! empty( $ppp['available'] ),
				'router_error'     => isset( $ppp['error'] ) ? (string) $ppp['error'] : '',
				'generated'        => current_time( 'mysql' ),
			)
		);
	}
}
