<?php

defined( 'ABSPATH' ) || exit;

/**
 * Editable SMS template library for the Airfiber SMS Center.
 */
class AFC_SMS_Templates {

	const DB_VERSION        = '1.0.0';
	const OPTION_DB_VERSION = 'afc_sms_templates_db_version';
	const OPTION_SETTINGS   = 'afc_sms_template_settings';
	const NONCE_ACTION      = 'afc_sms_templates';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 42 );
		add_action( 'afc_frontend_app_content', array( __CLASS__, 'render_library' ), 20 );

		add_action( 'wp_ajax_afc_sms_templates_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_afc_sms_template_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_afc_sms_template_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_afc_sms_template_toggle', array( __CLASS__, 'ajax_toggle' ) );
		add_action( 'wp_ajax_afc_sms_template_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_afc_sms_template_restore', array( __CLASS__, 'ajax_restore_defaults' ) );
		add_action( 'wp_ajax_afc_sms_template_used', array( __CLASS__, 'ajax_mark_used' ) );
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(191) NOT NULL,
			category varchar(40) NOT NULL DEFAULT 'due',
			title varchar(190) NOT NULL DEFAULT '',
			body text NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			use_count bigint(20) unsigned NOT NULL DEFAULT 0,
			last_used_at datetime NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY category_enabled (category,enabled),
			KEY use_count (use_count)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );

		if ( ! get_option( self::OPTION_SETTINGS ) ) {
			update_option( self::OPTION_SETTINGS, self::default_settings(), false );
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $total ) {
			self::seed_defaults();
		}
	}

	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			self::install();
		}
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'afc_sms_templates';
	}

	private static function can_render() {
		return class_exists( 'AFC_Frontend_Page' )
			&& AFC_Frontend_Page::is_app_request()
			&& current_user_can( 'manage_options' );
	}

	public static function enqueue_assets() {
		if ( ! self::can_render() ) {
			return;
		}

		wp_enqueue_style( 'afc-sms-templates', AFC_URL . 'assets/css/sms-templates.css', array( 'afc-sms-center' ), AFC_VERSION );
		wp_enqueue_script( 'afc-sms-templates', AFC_URL . 'assets/js/sms-templates.js', array( 'afc-sms-center' ), AFC_VERSION, true );
		wp_localize_script(
			'afc-sms-templates',
			'afcSmsTemplates',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'categories' => self::categories(),
				'settings'   => self::get_settings(),
			)
		);
	}

	public static function render_library() {
		if ( self::can_render() ) {
			include AFC_PATH . 'templates/admin/sms-template-library.php';
		}
	}

	private static function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage SMS templates.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	public static function ajax_list() {
		self::authorize_ajax();
		wp_send_json_success( array( 'templates' => self::all_templates(), 'categories' => self::categories(), 'settings' => self::get_settings() ) );
	}

	public static function ajax_save() {
		self::authorize_ajax();
		global $wpdb;
		$id       = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : 'due';
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$body     = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$enabled  = isset( $_POST['enabled'] ) && '1' === (string) $_POST['enabled'] ? 1 : 0;
		if ( ! isset( self::categories()[ $category ] ) ) wp_send_json_error( array( 'message' => __( 'Choose a valid template category.', 'airfiber-centralized' ) ) );
		if ( '' === trim( $title ) || '' === trim( $body ) ) wp_send_json_error( array( 'message' => __( 'Template title and message are required.', 'airfiber-centralized' ) ) );
		$now  = current_time( 'mysql' );
		$data = array( 'category' => $category, 'title' => $title, 'body' => $body, 'enabled' => $enabled, 'updated_at' => $now );
		if ( $id ) {
			if ( false === $wpdb->update( self::table(), $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%d', '%s' ), array( '%d' ) ) ) wp_send_json_error( array( 'message' => __( 'The SMS template could not be updated.', 'airfiber-centralized' ) ) );
		} else {
			$data['slug'] = 'custom-' . strtolower( wp_generate_uuid4() );
			$data['is_default'] = 0;
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = $now;
			if ( ! $wpdb->insert( self::table(), $data, array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' ) ) ) wp_send_json_error( array( 'message' => __( 'The SMS template could not be created.', 'airfiber-centralized' ) ) );
			$id = (int) $wpdb->insert_id;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		wp_send_json_success( array( 'message' => __( 'SMS template saved.', 'airfiber-centralized' ), 'template' => $row ? self::prepare_template( $row ) : array() ) );
	}

	public static function ajax_delete() {
		self::authorize_ajax();
		global $wpdb;
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		if ( ! $id || ! $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ) ) wp_send_json_error( array( 'message' => __( 'The SMS template could not be deleted.', 'airfiber-centralized' ) ) );
		wp_send_json_success( array( 'message' => __( 'SMS template deleted.', 'airfiber-centralized' ) ) );
	}

	public static function ajax_toggle() {
		self::authorize_ajax();
		global $wpdb;
		$id      = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$enabled = isset( $_POST['enabled'] ) && '1' === (string) $_POST['enabled'] ? 1 : 0;
		$updated = $id ? $wpdb->update( self::table(), array( 'enabled' => $enabled, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%d', '%s' ), array( '%d' ) ) : false;
		if ( false === $updated ) wp_send_json_error( array( 'message' => __( 'The template status could not be changed.', 'airfiber-centralized' ) ) );
		wp_send_json_success( array( 'message' => $enabled ? __( 'Template enabled.', 'airfiber-centralized' ) : __( 'Template disabled.', 'airfiber-centralized' ) ) );
	}

	public static function ajax_save_settings() {
		self::authorize_ajax();
		$category = isset( $_POST['default_category'] ) ? sanitize_key( wp_unslash( $_POST['default_category'] ) ) : 'due';
		$mode = isset( $_POST['default_mode'] ) ? sanitize_key( wp_unslash( $_POST['default_mode'] ) ) : 'random_category';
		if ( ! isset( self::categories()[ $category ] ) ) $category = 'due';
		if ( ! in_array( $mode, array( 'manual', 'fixed', 'random_category', 'random_all' ), true ) ) $mode = 'random_category';
		$settings = array(
			'payment_number' => isset( $_POST['payment_number'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_number'] ) ) : '',
			'default_category' => $category,
			'default_mode' => $mode,
			'default_template_id' => isset( $_POST['default_template_id'] ) ? absint( $_POST['default_template_id'] ) : 0,
		);
		update_option( self::OPTION_SETTINGS, $settings, false );
		wp_send_json_success( array( 'message' => __( 'Message library settings saved.', 'airfiber-centralized' ), 'settings' => $settings ) );
	}

	public static function ajax_restore_defaults() {
		self::authorize_ajax();
		$inserted = self::seed_defaults();
		wp_send_json_success( array( 'message' => sprintf( __( '%d missing starter templates were restored.', 'airfiber-centralized' ), $inserted ), 'inserted' => $inserted ) );
	}

	public static function ajax_mark_used() {
		self::authorize_ajax();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		if ( $id ) self::mark_used( $id );
		wp_send_json_success( array( 'marked' => (bool) $id ) );
	}

	private static function all_templates() {
		global $wpdb;
		$items = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY category ASC, enabled DESC, title ASC, id ASC' ) as $row ) $items[] = self::prepare_template( $row );
		return $items;
	}

	public static function enabled_template_options( $category = '' ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE enabled = 1';
		if ( $category && isset( self::categories()[ $category ] ) ) $sql .= $wpdb->prepare( ' AND category = %s', $category );
		$sql .= ' ORDER BY category ASC, title ASC, id ASC';
		$options = array();
		foreach ( (array) $wpdb->get_results( $sql ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$item = self::prepare_template( $row );
			$item['category_label'] = self::categories()[ $item['category'] ];
			$options[] = $item;
		}
		return $options;
	}

	public static function choose_template( $category = 'due', $mode = 'random_category', $fixed_id = 0 ) {
		global $wpdb;
		if ( 'fixed' === $mode && $fixed_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND enabled = 1 LIMIT 1', $fixed_id ) );
			if ( $row ) return self::prepare_template( $row );
		}
		if ( ! isset( self::categories()[ $category ] ) ) $category = 'due';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE enabled = 1 AND category = %s ORDER BY id ASC', $category ) );
		if ( ! $rows ) return null;
		$item = self::prepare_template( $rows[ array_rand( $rows ) ] );
		self::mark_used( $item['id'] );
		return $item;
	}

	public static function apply_tokens( $body, $values ) {
		$values = wp_parse_args( is_array( $values ) ? $values : array(), array( 'name' => '', 'ppp' => '', 'phone' => '', 'due_date' => '', 'amount' => '', 'payment_number' => self::get_settings()['payment_number'] ) );
		return strtr( (string) $body, array(
			'{name}' => (string) $values['name'], '{ppp}' => (string) $values['ppp'], '{phone}' => (string) $values['phone'],
			'{due_date}' => (string) $values['due_date'], '{amount}' => (string) $values['amount'], '{payment_number}' => (string) $values['payment_number'],
		) );
	}

	private static function mark_used( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET use_count = use_count + 1, last_used_at = %s WHERE id = %d', current_time( 'mysql' ), absint( $id ) ) );
	}

	private static function prepare_template( $row ) {
		return array( 'id' => (int) $row->id, 'slug' => (string) $row->slug, 'category' => (string) $row->category, 'title' => (string) $row->title, 'body' => (string) $row->body, 'enabled' => (bool) $row->enabled, 'is_default' => (bool) $row->is_default, 'use_count' => (int) $row->use_count, 'last_used_at' => (string) $row->last_used_at, 'updated_at' => (string) $row->updated_at );
	}

	public static function categories() {
		return array( 'due' => __( 'Due Reminder', 'airfiber-centralized' ), 'thank_you' => __( 'Thank You', 'airfiber-centralized' ), 'service' => __( 'Service Advisory', 'airfiber-centralized' ), 'inquiry' => __( 'Customer Inquiry', 'airfiber-centralized' ), 'general' => __( 'General', 'airfiber-centralized' ) );
	}

	private static function default_settings() {
		return array( 'payment_number' => '09978230630', 'default_category' => 'due', 'default_mode' => 'random_category', 'default_template_id' => 0 );
	}

	public static function get_settings() {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::default_settings() );
	}

	private static function seed_defaults() {
		$inserted = 0;
		foreach ( self::default_templates() as $template ) if ( self::insert_seed( $template ) ) $inserted++;
		return $inserted;
	}

	private static function insert_seed( $template ) {
		global $wpdb;
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE slug = %s LIMIT 1', $template['slug'] ) ) ) return false;
		$now = current_time( 'mysql' );
		return (bool) $wpdb->insert( self::table(), array( 'slug' => $template['slug'], 'category' => $template['category'], 'title' => $template['title'], 'body' => $template['body'], 'enabled' => 1, 'is_default' => 1, 'created_by' => 0, 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ) );
	}

	private static function default_templates() {
		$templates = self::due_templates();
		$groups = array(
			'thank_you' => array(
				'Thank you, {name}. Nadawat na namo inyong payment. Salamat kaayo sa pagsalig sa Airfiber.',
				'Salamat {name}, recorded na inyong internet payment. - Airfiber', 'Payment received, {name}. Daghang salamat. - Airfiber',
				'Hi {name}, okay na ug na-record na namo inyong bayad. Salamat kaayo. - Airfiber', 'Maayong adlaw {name}, received na inyong payment. Thank you from Airfiber.',
				'Salamat mam/sir {name}. Posted na ang payment sa inyong account. - Airfiber', 'Confirmed na inyong bayad, {name}. Salamat sa prompt payment. - Airfiber',
				'Nadawat na ang reference ug payment, {name}. Daghang salamat. - Airfiber',
			),
			'service' => array(
				'Hi {name}, pasensya medyo hinay ang internet karon. Gi-check na sa among team ang connection sa area. - Airfiber',
				'Maayong adlaw {name}, naa tay temporary service issue sa inyong area. Nagtrabaho na ang team para ma-restore dayon. - Airfiber',
				'Pasensya sa interruption, {name}. Ongoing ang checking ug restoration sa Airfiber network.',
				'Hello {name}, possible hinay o putol-putol ang net karon tungod sa maintenance. Salamat sa pagsabot. - Airfiber',
				'Update {name}: naa pay ongoing repair sa linya. Maghatag mi ug update kung stable na balik. - Airfiber',
				'Hi {name}, na-report na namo inyong connection issue. Mamalihug hulat sa technician update. - Airfiber',
				'Airfiber advisory: temporary interruption sa inyong area. Ang team nagtrabaho na para ma-restore ang service.',
				'Good day {name}, checking pa namo ang signal ug linya. Pasensya sa inconvenience. - Airfiber',
			),
			'inquiry' => array(
				"Mamalihug kog send ani mam/sir:\nName:\nCP:\nWiFi name:\nWiFi password:\nAddress:\nLandmark:\nSalamat. - Airfiber",
				"Para ma-check namo ang inyong concern, palihug send:\nName:\nContact number:\nPPP/account name:\nAddress:\nLandmark:\nIssue encountered:\n- Airfiber",
				"Hi {name}, mamalihug kog reply sa details:\nName:\nCP number:\nWiFi name:\nWiFi password:\nComplete address:\nNearest landmark:\n- Airfiber",
				"For installation inquiry, please send:\nFull name:\nMobile number:\nComplete address:\nLandmark:\nPreferred plan:\n- Airfiber",
				"For repair request, palihug send:\nName:\nCP:\nPPP username:\nAddress:\nLandmark:\nUnsay issue sa connection:\n- Airfiber",
			),
		);
		foreach ( $groups as $category => $messages ) for ( $round = 0; $round < 3; $round++ ) foreach ( $messages as $index => $message ) {
			$number = $round * count( $messages ) + $index + 1;
			$prefixes = array( '', 'Reminder: ', 'Airfiber: ' );
			$templates[] = array( 'slug' => $category . '-' . str_pad( (string) $number, 3, '0', STR_PAD_LEFT ), 'category' => $category, 'title' => self::categories()[ $category ] . ' ' . str_pad( (string) $number, 3, '0', STR_PAD_LEFT ), 'body' => $prefixes[ $round ] . $message );
		}
		return $templates;
	}

	private static function due_templates() {
		$greetings = array( 'Good morning {name},', 'Good afternoon {name},', 'Maayong buntag {name},', 'Maayong hapon {name},', 'Hi {name},', 'Hello mam/sir {name},', 'Gd am mam/sir {name},', 'Gd pm mam/sir {name},', 'Mam/Sir {name},', 'Kumusta {name},' );
		$intros = array( 'reminder lang sa bayad sa inyong Airfiber internet.', 'maningil lang mi sa inyong internet bill.', 'follow-up lang sa monthly internet payment.', 'mamalihug lang mi sa bayad sa net.', 'notice lang sa inyong Airfiber account.', 'pa-remind lang mi sa internet due.', 'collection reminder lang sa inyong net.', 'mamalihug mi sa inyong monthly internet payment.', 'follow up lang ko sa bayad sa internet.', 'reminder sa inyong current internet bill.' );
		$timings = array( 'Due na karon ang account.', 'Due date kay {due_date}.', 'Ang due date sa account kay {due_date}.' );
		$payments = array( 'Pwede ra i-GCash sa {payment_number}.', 'Diri lang ipadala ang payment: {payment_number}.', 'GCash payment number: {payment_number}.', 'Pwede ra mo bayad sa {payment_number}; amount: {amount}.', 'Ang payment pwede i-send sa {payment_number}.', 'For payment, use {payment_number}.' );
		$references = array( 'Palihug send ang reference number human bayad.', 'Mamalihug kog send sa ref number o screenshot.', 'I-send lang dayon ang GCash reference number.', 'Please send the payment reference para ma-record namo.', 'Human bayad, reply lang sa ref number.' );
		$closes = array( 'Salamat. - Airfiber', 'Daghang salamat. - Airfiber', 'Thank you. - Airfiber', 'Salamat kaayo mam/sir. - Airfiber', 'Mamalihug lang. Salamat, Airfiber.' );
		$templates = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$number = $i + 1;
			$templates[] = array( 'slug' => 'due-' . str_pad( (string) $number, 3, '0', STR_PAD_LEFT ), 'category' => 'due', 'title' => 'Due Reminder ' . str_pad( (string) $number, 3, '0', STR_PAD_LEFT ), 'body' => implode( ' ', array( $greetings[ $i % count( $greetings ) ], $intros[ (int) floor( $i / 10 ) % count( $intros ) ], $timings[ (int) floor( $i / 100 ) % count( $timings ) ], $payments[ ( $i * 7 ) % count( $payments ) ], $references[ ( $i * 11 ) % count( $references ) ], $closes[ ( $i * 13 ) % count( $closes ) ] ) ) );
		}
		return $templates;
	}
}
