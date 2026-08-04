<?php

defined( 'ABSPATH' ) || exit;

/**
 * Main operational dashboard for the frontend Airfiber application.
 */
class AFC_Main_Dashboard {

	const NONCE      = 'afc_main_dashboard';
	const LAYOUT_KEY = '_afc_main_dashboard_layout_v1';
	const LIMIT      = 6;

	public static function init() {
		add_action( 'wp_ajax_afc_dashboard_data', array( __CLASS__, 'ajax_data' ) );
		add_action( 'wp_ajax_afc_dashboard_save_layout', array( __CLASS__, 'ajax_save_layout' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 110 );
		add_action( 'afc_frontend_app_content', array( __CLASS__, 'render_panel' ), 1 );
	}

	public static function widget_ids() {
		return array( 'payment', 'new-ppp', 'recent-expired', 'due-soon', 'recent-payments', 'sent-sms', 'new-installs', 'network' );
	}

	public static function layout() {
		$allowed = self::widget_ids();
		$saved   = get_user_meta( get_current_user_id(), self::LAYOUT_KEY, true );
		$saved   = is_array( $saved ) ? array_values( array_intersect( $saved, $allowed ) ) : array();
		return array_values( array_unique( array_merge( $saved, $allowed ) ) );
	}

	public static function enqueue_assets() {
		if ( ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script( 'afc-sortablejs', 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js', array(), '1.15.6', true );
		wp_enqueue_style( 'afc-main-dashboard', AFC_URL . 'assets/css/main-dashboard.css', array( 'afc-frontend-app', 'afc-basic-payments' ), AFC_VERSION );
		wp_enqueue_script( 'afc-main-dashboard', AFC_URL . 'assets/js/main-dashboard.js', array( 'afc-frontend-app', 'afc-basic-payments', 'afc-sortablejs' ), AFC_VERSION, true );

		wp_localize_script(
			'afc-main-dashboard',
			'afcMainDashboard',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE ),
				'layout'         => self::layout(),
				'refreshSeconds' => 30,
				'labels'         => array(
					'saving' => __( 'Saving layout…', 'airfiber-centralized' ),
					'saved'  => __( 'Layout saved', 'airfiber-centralized' ),
					'failed' => __( 'Dashboard data could not be loaded.', 'airfiber-centralized' ),
				),
			)
		);
	}

	public static function render_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$dashboard_layout = self::layout();
		include AFC_PATH . 'templates/frontend-dashboard.php';
	}

	private static function drag_handle() {
		?>
		<button type="button" class="afc-dashboard-drag" aria-label="<?php esc_attr_e( 'Drag to reorder this card', 'airfiber-centralized' ); ?>" title="<?php esc_attr_e( 'Drag to reorder', 'airfiber-centralized' ); ?>">
			<span></span><span></span><span></span><span></span><span></span><span></span>
		</button>
		<?php
	}

	private static function card_header( $kicker, $title, $icon ) {
		?>
		<header class="afc-dashboard-card-head">
			<div class="afc-dashboard-card-title">
				<span class="afc-dashboard-card-icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
				<div><small><?php echo esc_html( $kicker ); ?></small><h2><?php echo esc_html( $title ); ?></h2></div>
			</div>
			<?php self::drag_handle(); ?>
		</header>
		<?php
	}

	private static function render_list_card( $id, $kicker, $title, $icon, $group, $panel ) {
		?>
		<article class="afc-dashboard-card afc-dashboard-list-card" data-afc-dashboard-widget="<?php echo esc_attr( $id ); ?>">
			<?php self::card_header( $kicker, $title, $icon ); ?>
			<div class="afc-dashboard-list" data-afc-dashboard-list="<?php echo esc_attr( $group ); ?>">
				<div class="afc-dashboard-list-loading"><span></span><?php esc_html_e( 'Loading…', 'airfiber-centralized' ); ?></div>
			</div>
			<footer class="afc-dashboard-card-footer">
				<span data-afc-dashboard-count="<?php echo esc_attr( $group ); ?>">—</span>
				<button type="button" data-afc-dashboard-open-panel="<?php echo esc_attr( $panel ); ?>"><?php esc_html_e( 'Open', 'airfiber-centralized' ); ?> →</button>
			</footer>
		</article>
		<?php
	}

	public static function render_widget( $id ) {
		switch ( $id ) {
			case 'payment':
				?>
				<article class="afc-dashboard-card afc-dashboard-payment-card" data-afc-dashboard-widget="payment">
					<?php self::card_header( __( 'Most used', 'airfiber-centralized' ), __( 'Record a payment', 'airfiber-centralized' ), '₱' ); ?>
					<div class="afc-dashboard-payment-mount" data-afc-dashboard-payment-mount>
						<div class="afc-dashboard-skeleton-line is-wide"></div>
						<p><?php esc_html_e( 'Loading the customer payment search…', 'airfiber-centralized' ); ?></p>
					</div>
				</article>
				<?php
				break;

			case 'new-ppp':
				?>
				<article class="afc-dashboard-card afc-dashboard-new-ppp" data-afc-dashboard-widget="new-ppp">
					<?php self::card_header( __( 'Quick action', 'airfiber-centralized' ), __( 'New PPP account', 'airfiber-centralized' ), '+' ); ?>
					<div class="afc-dashboard-new-ppp-body">
						<div class="afc-dashboard-new-ppp-mark" aria-hidden="true">+</div>
						<p><?php esc_html_e( 'Add a subscriber, billing details, plan, Wi-Fi details, and MikroTik PPP secret.', 'airfiber-centralized' ); ?></p>
						<button type="button" class="afc-dashboard-primary-action" data-afc-dashboard-add-ppp><?php esc_html_e( 'Create New PPP', 'airfiber-centralized' ); ?></button>
					</div>
				</article>
				<?php
				break;

			case 'recent-expired':
				self::render_list_card( $id, __( 'Service attention', 'airfiber-centralized' ), __( 'Recently expired', 'airfiber-centralized' ), '×', 'expired', 'schedulers' );
				break;
			case 'due-soon':
				self::render_list_card( $id, __( 'Next 7 days', 'airfiber-centralized' ), __( 'Due soon', 'airfiber-centralized' ), '!', 'due', 'schedulers' );
				break;
			case 'recent-payments':
				self::render_list_card( $id, __( 'Latest collections', 'airfiber-centralized' ), __( 'Recently paid', 'airfiber-centralized' ), '✓', 'payments', 'operations' );
				break;
			case 'sent-sms':
				self::render_list_card( $id, __( 'Communication', 'airfiber-centralized' ), __( 'Recent SMS', 'airfiber-centralized' ), '↗', 'sms', 'sms' );
				break;
			case 'new-installs':
				self::render_list_card( $id, __( 'New customers', 'airfiber-centralized' ), __( 'Recent installs', 'airfiber-centralized' ), '⌂', 'installs', 'operations' );
				break;

			case 'network':
				?>
				<article class="afc-dashboard-card afc-dashboard-network-card" data-afc-dashboard-widget="network">
					<?php self::card_header( __( 'Live router snapshot', 'airfiber-centralized' ), __( 'ISP ports & router health', 'airfiber-centralized' ), '⌁' ); ?>
					<div class="afc-dashboard-router-metrics" data-afc-dashboard-router-metrics><div class="afc-dashboard-skeleton-line is-wide"></div></div>
					<div class="afc-dashboard-ports" data-afc-dashboard-ports></div>
					<footer class="afc-dashboard-card-footer"><span><?php esc_html_e( 'Mbps is a short live estimate, not a billing total.', 'airfiber-centralized' ); ?></span><button type="button" data-afc-dashboard-open-panel="mikrotik"><?php esc_html_e( 'Router settings', 'airfiber-centralized' ); ?> →</button></footer>
				</article>
				<?php
				break;
		}
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to use this dashboard.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function result_rows( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return array();
		}
		if ( isset( $result['name'] ) || isset( $result['version'] ) || isset( $result['cpu-load'] ) ) {
			return array( $result );
		}
		return $result;
	}

	private static function custom_value( $details, $key ) {
		$fields = isset( $details['custom_fields'] ) && is_array( $details['custom_fields'] ) ? $details['custom_fields'] : array();
		foreach ( $fields as $field_key => $value ) {
			if ( 0 === strcasecmp( $field_key, $key ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function parse_date( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date     = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		$errors   = DateTimeImmutable::getLastErrors();
		return $date && ( ! is_array( $errors ) || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) ? $date : null;
	}

	private static function day_difference( $from, $to ) {
		return (int) $from->diff( $to )->format( '%r%a' );
	}

	private static function scheduler_map( $schedulers ) {
		$map = array();
		foreach ( $schedulers as $scheduler ) {
			$name = isset( $scheduler['name'] ) ? strtolower( trim( (string) $scheduler['name'] ) ) : '';
			if ( $name ) {
				$map[ $name ] = $scheduler;
			}
		}
		return $map;
	}

	private static function account_item( $secret, $scheduler ) {
		$details = AFC_Comment_Fields::parse_comment( isset( $secret['comment'] ) ? (string) $secret['comment'] : '' );
		$name    = isset( $secret['name'] ) ? (string) $secret['name'] : '';
		return array(
			'name'          => $name,
			'customer'      => ! empty( $details['name'] ) ? (string) $details['name'] : $name,
			'profile'       => isset( $secret['profile'] ) ? (string) $secret['profile'] : '',
			'nextDue'       => self::custom_value( $details, 'nextDue' ),
			'cutoffDate'    => self::custom_value( $details, 'cutoffDate' ),
			'installed'     => isset( $details['installed'] ) ? trim( (string) $details['installed'] ) : '',
			'paymentDate'   => isset( $details['payment_date'] ) ? trim( (string) $details['payment_date'] ) : '',
			'paymentAmount' => isset( $details['payment_amount'] ) ? trim( (string) $details['payment_amount'] ) : '',
			'paymentMethod' => isset( $details['payment_method'] ) ? trim( (string) $details['payment_method'] ) : '',
			'schedulerRan'  => $scheduler && ! empty( $scheduler['run-count'] ),
		);
	}

	private static function account_groups( $secrets, $schedulers ) {
		$settings        = AFC_Schedulers::get_settings();
		$expired_profile = isset( $settings['expired_profile'] ) ? trim( (string) $settings['expired_profile'] ) : 'Expired';
		$scheduler_map   = self::scheduler_map( $schedulers );
		$timezone        = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today           = new DateTimeImmutable( current_time( 'Y-m-d' ), $timezone );
		$groups          = array( 'due' => array(), 'expired' => array(), 'payments' => array(), 'installs' => array() );

		foreach ( $secrets as $secret ) {
			$username  = isset( $secret['name'] ) ? trim( (string) $secret['name'] ) : '';
			$scheduler = $username && isset( $scheduler_map[ strtolower( $username ) ] ) ? $scheduler_map[ strtolower( $username ) ] : null;
			$item      = self::account_item( $secret, $scheduler );
			if ( ! $item['name'] ) {
				continue;
			}

			$due_date = self::parse_date( $item['nextDue'] );
			if ( $due_date && 0 !== strcasecmp( $item['profile'], $expired_profile ) ) {
				$days = self::day_difference( $today, $due_date );
				if ( $days >= 0 && $days <= 7 ) {
					$item['daysUntil'] = $days;
					$groups['due'][]   = $item;
				}
			}

			if ( 0 === strcasecmp( $item['profile'], $expired_profile ) ) {
				$cutoff              = self::parse_date( $item['cutoffDate'] );
				$item['daysAgo']     = $cutoff ? max( 0, - self::day_difference( $today, $cutoff ) ) : null;
				$groups['expired'][] = $item;
			}

			$payment_date = self::parse_date( $item['paymentDate'] );
			if ( $payment_date ) {
				$item['daysAgo']      = max( 0, - self::day_difference( $today, $payment_date ) );
				$groups['payments'][] = $item;
			}

			$installed_date = self::parse_date( $item['installed'] );
			if ( $installed_date ) {
				$item['daysAgo']      = max( 0, - self::day_difference( $today, $installed_date ) );
				$groups['installs'][] = $item;
			}
		}

		usort( $groups['due'], function ( $a, $b ) { return strcmp( $a['nextDue'], $b['nextDue'] ); } );
		usort( $groups['expired'], function ( $a, $b ) { return strcmp( $b['cutoffDate'], $a['cutoffDate'] ); } );
		usort( $groups['payments'], function ( $a, $b ) { return strcmp( $b['paymentDate'], $a['paymentDate'] ); } );
		usort( $groups['installs'], function ( $a, $b ) { return strcmp( $b['installed'], $a['installed'] ); } );

		$counts = array();
		foreach ( $groups as $key => $items ) {
			$counts[ $key ] = count( $items );
			$groups[ $key ] = array_slice( $items, 0, self::LIMIT );
		}
		return array( 'groups' => $groups, 'counts' => $counts );
	}

	private static function sms_data() {
		global $wpdb;
		$table  = $wpdb->prefix . 'afc_sms_jobs';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return array( 'items' => array(), 'count' => 0 );
		}

		$items = $wpdb->get_results(
			"SELECT id, customer_name, ppp_username, phone, message, status, created_at, sent_at, delivered_at FROM {$table} ORDER BY id DESC LIMIT " . self::LIMIT,
			ARRAY_A
		);
		$today = current_time( 'Y-m-d' ) . ' 00:00:00';
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status IN ('sent','delivered') AND created_at >= %s",
				$today
			)
		);
		return array( 'items' => is_array( $items ) ? $items : array(), 'count' => $count );
	}

	private static function interface_map( $rows ) {
		$map = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['name'] ) ) {
				$map[ strtolower( (string) $row['name'] ) ] = $row;
			}
		}
		return $map;
	}

	private static function router_bool( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( 'true', 'yes', '1' ), true );
	}

	private static function network_data( $resource ) {
		$command = array( '/interface/print', '=.proplist=name,type,running,disabled,rx-byte,tx-byte,link-downs,last-link-up-time,comment' );
		$start   = microtime( true );
		$first   = self::result_rows( AFC_MikroTik::run_command( $command ) );
		if ( is_wp_error( $first ) ) {
			return array( 'ports' => array(), 'error' => $first->get_error_message() );
		}

		usleep( 650000 );
		$second = self::result_rows( AFC_MikroTik::run_command( $command ) );
		if ( is_wp_error( $second ) ) {
			return array( 'ports' => array(), 'error' => $second->get_error_message() );
		}

		$elapsed = max( 0.2, microtime( true ) - $start );
		$before  = self::interface_map( $first );
		$after   = self::interface_map( $second );
		$ports   = array();

		for ( $index = 1; $index <= 8; $index++ ) {
			$key  = isset( $after[ 'ether' . $index ] ) ? 'ether' . $index : ( isset( $after[ 'eth' . $index ] ) ? 'eth' . $index : '' );
			$row  = $key ? $after[ $key ] : array();
			$prev = $key && isset( $before[ $key ] ) ? $before[ $key ] : array();
			$rx   = max( 0, (float) ( isset( $row['rx-byte'] ) ? $row['rx-byte'] : 0 ) - (float) ( isset( $prev['rx-byte'] ) ? $prev['rx-byte'] : 0 ) );
			$tx   = max( 0, (float) ( isset( $row['tx-byte'] ) ? $row['tx-byte'] : 0 ) - (float) ( isset( $prev['tx-byte'] ) ? $prev['tx-byte'] : 0 ) );

			$ports[] = array(
				'name'      => $key ? (string) $row['name'] : 'ether' . $index,
				'found'     => (bool) $key,
				'running'   => $key ? self::router_bool( isset( $row['running'] ) ? $row['running'] : '' ) : false,
				'disabled'  => $key ? self::router_bool( isset( $row['disabled'] ) ? $row['disabled'] : '' ) : false,
				'rxMbps'    => round( ( $rx * 8 ) / $elapsed / 1000000, 2 ),
				'txMbps'    => round( ( $tx * 8 ) / $elapsed / 1000000, 2 ),
				'linkDowns' => isset( $row['link-downs'] ) ? absint( $row['link-downs'] ) : 0,
				'comment'   => isset( $row['comment'] ) ? (string) $row['comment'] : '',
			);
		}

		$total_memory = isset( $resource['total-memory'] ) ? (float) $resource['total-memory'] : 0;
		$free_memory  = isset( $resource['free-memory'] ) ? (float) $resource['free-memory'] : 0;
		return array(
			'ports'   => $ports,
			'cpu'     => isset( $resource['cpu-load'] ) ? absint( $resource['cpu-load'] ) : 0,
			'memory'  => $total_memory > 0 ? round( ( ( $total_memory - $free_memory ) / $total_memory ) * 100 ) : 0,
			'uptime'  => isset( $resource['uptime'] ) ? (string) $resource['uptime'] : '',
			'version' => isset( $resource['version'] ) ? (string) $resource['version'] : '',
			'board'   => isset( $resource['board-name'] ) ? (string) $resource['board-name'] : '',
			'error'   => '',
		);
	}

	public static function ajax_data() {
		self::authorize();
		$sms      = self::sms_data();
		$settings = AFC_MikroTik::get_settings();
		$resource = AFC_MikroTik::run_command( array( '/system/resource/print' ) );

		if ( is_wp_error( $resource ) ) {
			wp_send_json_success(
				array(
					'connected' => false,
					'router'    => array( 'name' => $settings['name'], 'message' => $resource->get_error_message() ),
					'groups'    => array( 'due' => array(), 'expired' => array(), 'payments' => array(), 'installs' => array(), 'sms' => $sms['items'] ),
					'counts'    => array( 'due' => 0, 'expired' => 0, 'payments' => 0, 'installs' => 0, 'sms' => $sms['count'] ),
					'network'   => array( 'ports' => array(), 'error' => $resource->get_error_message() ),
				)
			);
		}

		$secrets    = self::result_rows( AFC_MikroTik::run_command( array( '/ppp/secret/print', '=.proplist=name,profile,comment,disabled' ) ) );
		$schedulers = self::result_rows( AFC_MikroTik::run_command( array( '/system/scheduler/print', '=.proplist=name,start-date,start-time,disabled,run-count,next-run' ) ) );
		$account_data = ( is_wp_error( $secrets ) || is_wp_error( $schedulers ) )
			? array( 'groups' => array( 'due' => array(), 'expired' => array(), 'payments' => array(), 'installs' => array() ), 'counts' => array( 'due' => 0, 'expired' => 0, 'payments' => 0, 'installs' => 0 ) )
			: self::account_groups( $secrets, $schedulers );

		$account_data['groups']['sms'] = $sms['items'];
		$account_data['counts']['sms'] = $sms['count'];

		wp_send_json_success(
			array(
				'connected' => true,
				'router'    => array(
					'name'    => $settings['name'],
					'version' => isset( $resource['version'] ) ? (string) $resource['version'] : '',
					'board'   => isset( $resource['board-name'] ) ? (string) $resource['board-name'] : '',
					'message' => __( 'MikroTik is connected and responding.', 'airfiber-centralized' ),
				),
				'groups'    => $account_data['groups'],
				'counts'    => $account_data['counts'],
				'network'   => self::network_data( $resource ),
				'generated' => current_time( 'mysql' ),
			)
		);
	}

	public static function ajax_save_layout() {
		self::authorize();
		$order   = isset( $_POST['order'] ) ? (array) wp_unslash( $_POST['order'] ) : array();
		$allowed = self::widget_ids();
		$order   = array_values( array_unique( array_intersect( array_map( 'sanitize_key', $order ), $allowed ) ) );
		$order   = array_values( array_unique( array_merge( $order, $allowed ) ) );
		update_user_meta( get_current_user_id(), self::LAYOUT_KEY, $order );
		wp_send_json_success( array( 'order' => $order ) );
	}
}
