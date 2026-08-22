<?php

defined( 'ABSPATH' ) || exit;

/**
 * Standalone GPON provisioning for new ONUs.
 *
 * This flow intentionally does not create or require an AFC customer/PPP link.
 * It reuses the guarded VSOL provisioning plan from AFC_GPON_Provisioning and
 * only stores a short-lived preview token plus a small audit trail.
 */
class AFC_GPON_Standalone {

	const NONCE        = 'afc_gpon_standalone';
	const PREVIEW_TTL  = 600;
	const AUDIT_OPTION = 'afc_gpon_standalone_audit_v1';

	public static function init() {
		add_action( 'wp_ajax_afc_gpon_standalone_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_afc_gpon_standalone_execute', array( __CLASS__, 'ajax_execute' ) );
	}

	public static function frontend_config() {
		$nodes = array();
		foreach ( AFC_OLT::monitoring_nodes() as $reference => $node ) {
			if ( 'GPON' !== strtoupper( isset( $node['technology'] ) ? $node['technology'] : '' ) ) {
				continue;
			}
			$nodes[] = array(
				'id'   => (string) $reference,
				'name' => isset( $node['name'] ) ? (string) $node['name'] : __( 'GPON OLT', 'airfiber-centralized' ),
				'host' => isset( $node['config']['host'] ) ? (string) $node['config']['host'] : '',
			);
		}

		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'nodes'   => $nodes,
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to provision GPON ONUs.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Call one of the existing guarded GPON helper methods without duplicating
	 * the VSOL session and line-building implementation.
	 */
	private static function core( $method, $args = array() ) {
		$reflection = new ReflectionMethod( 'AFC_GPON_Provisioning', $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	private static function sanitize_vlans( $value ) {
		$parts = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
		$vlans = array();
		foreach ( (array) $parts as $part ) {
			$vlan = absint( $part );
			if ( $vlan >= 1 && $vlan <= 4093 ) {
				$vlans[ $vlan ] = $vlan;
			}
		}
		return array_slice( array_values( $vlans ), 0, 12 );
	}

	private static function resolve_target( $input ) {
		$olt_id = isset( $input['olt_id'] ) ? AFC_OLT::normalize_olt_id( wp_unslash( $input['olt_id'] ) ) : '';
		$node   = AFC_OLT::monitoring_node( $olt_id );
		$pon    = isset( $input['pon'] ) ? absint( $input['pon'] ) : 0;
		$onu    = isset( $input['onu'] ) && '' !== (string) $input['onu'] ? absint( $input['onu'] ) : 0;

		if ( ! $node || 'GPON' !== strtoupper( isset( $node['technology'] ) ? $node['technology'] : '' ) ) {
			return new WP_Error( 'afc_gpon_node_missing', __( 'Choose a published GPON OLT connection.', 'airfiber-centralized' ) );
		}
		if ( $pon < 1 || $pon > 128 ) {
			return new WP_Error( 'afc_gpon_pon_invalid', __( 'Enter a valid GPON PON number.', 'airfiber-centralized' ) );
		}
		if ( $onu > 128 ) {
			return new WP_Error( 'afc_gpon_onu_invalid', __( 'ONU ID must be between 1 and 128, or leave it blank for Auto.', 'airfiber-centralized' ) );
		}

		return array(
			'customer'    => null,
			'customer_id' => 0,
			'olt_id'      => $olt_id,
			'node'        => $node,
			'pon'         => $pon,
			'onu'         => $onu,
			'mapped'      => false,
		);
	}

	private static function onu_name( $value ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		return substr( $value, 0, 64 );
	}

	private static function apply_onu_name( $plan, $name ) {
		$plan['onu_name'] = $name;
		foreach ( $plan['steps'] as &$step ) {
			if ( 'onu' !== $step['key'] || 'create' !== $step['action'] || empty( $step['data'] ) ) {
				continue;
			}
			/* Different V1600 firmware builds use one of these form keys. Unknown
			 * keys are ignored by the web action, so send both for compatibility. */
			$step['data']['onuname']    = $name;
			$step['data']['description'] = $name;
		}
		unset( $step );
		return $plan;
	}

	private static function public_plan( $plan ) {
		$copy = $plan;
		foreach ( $copy['steps'] as &$step ) {
			unset( $step['path'], $step['data'] );
		}
		unset( $step );
		return $copy;
	}

	private static function record_audit( $plan, $status, $results ) {
		$audit = get_option( self::AUDIT_OPTION, array() );
		$audit = is_array( $audit ) ? $audit : array();
		$audit[] = array(
			'time'     => current_time( 'mysql' ),
			'user_id'  => get_current_user_id(),
			'olt_id'   => $plan['olt_id'],
			'pon'      => $plan['pon'],
			'onu'      => $plan['onu'],
			'onu_name' => isset( $plan['onu_name'] ) ? $plan['onu_name'] : '',
			'serial'   => $plan['serial'],
			'vlans'    => $plan['vlans'],
			'status'   => $status,
			'results'  => $results,
		);
		update_option( self::AUDIT_OPTION, array_slice( $audit, -100 ), false );
	}

	public static function ajax_preview() {
		self::authorize();

		$target = self::resolve_target( $_POST );
		if ( is_wp_error( $target ) ) {
			wp_send_json_error( array( 'message' => $target->get_error_message() ), 400 );
		}

		$name   = self::onu_name( isset( $_POST['onu_name'] ) ? $_POST['onu_name'] : '' );
		$serial = AFC_GPON_Provisioning::normalize_serial( isset( $_POST['serial'] ) ? wp_unslash( $_POST['serial'] ) : '' );
		$vlans  = self::sanitize_vlans( isset( $_POST['vlans'] ) ? wp_unslash( $_POST['vlans'] ) : '' );

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Enter a name for this ONU.', 'airfiber-centralized' ) ), 400 );
		}
		if ( ! $serial ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid GPON serial number. The first four characters will be uppercase and the rest lowercase.', 'airfiber-centralized' ) ), 400 );
		}
		if ( empty( $vlans ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter at least one VLAN between 1 and 4093.', 'airfiber-centralized' ) ), 400 );
		}

		$session = self::core( 'open_session', array( $target['node'] ) );
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 502 );
		}

		$plan = self::core( 'build_plan', array( $target, $serial, $vlans, $session ) );
		if ( is_wp_error( $plan ) ) {
			wp_send_json_error( array( 'message' => $plan->get_error_message() ), 502 );
		}
		$plan = self::apply_onu_name( $plan, $name );

		$token = wp_generate_uuid4();
		$key   = 'afc_gpon_standalone_' . get_current_user_id() . '_' . $token;
		set_transient( $key, $plan, self::PREVIEW_TTL );

		$public          = self::public_plan( $plan );
		$public['token'] = $token;
		wp_send_json_success( array( 'plan' => $public ) );
	}

	public static function ajax_execute() {
		self::authorize();

		$token = isset( $_POST['token'] ) ? preg_replace( '/[^a-z0-9-]/i', '', wp_unslash( $_POST['token'] ) ) : '';
		$key   = 'afc_gpon_standalone_' . get_current_user_id() . '_' . $token;
		$plan  = $token ? get_transient( $key ) : false;
		if ( ! is_array( $plan ) ) {
			wp_send_json_error( array( 'message' => __( 'This ONU preview expired. Open Add ONU and preview it again.', 'airfiber-centralized' ) ), 409 );
		}

		$target = self::resolve_target(
			array(
				'olt_id' => $plan['olt_id'],
				'pon'    => $plan['pon'],
				'onu'    => $plan['onu'],
			)
		);
		if ( is_wp_error( $target ) ) {
			wp_send_json_error( array( 'message' => $target->get_error_message() ), 400 );
		}

		$session = self::core( 'open_session', array( $target['node'] ) );
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 502 );
		}

		$fresh_plan = self::core( 'build_plan', array( $target, $plan['serial'], $plan['vlans'], $session ) );
		if ( is_wp_error( $fresh_plan ) ) {
			wp_send_json_error( array( 'message' => $fresh_plan->get_error_message() ), 409 );
		}
		$fresh_plan = self::apply_onu_name( $fresh_plan, isset( $plan['onu_name'] ) ? $plan['onu_name'] : '' );

		$results = array();
		foreach ( $fresh_plan['steps'] as $step ) {
			if ( 'create' !== $step['action'] ) {
				$results[] = array( 'key' => $step['key'], 'status' => 'reused', 'message' => $step['summary'] );
				continue;
			}

			$response = self::core( 'request_json', array( $session, $step['path'], $step['data'] ) );
			$success  = self::core( 'vendor_success', array( $response ) );
			if ( is_wp_error( $response ) || ! $success ) {
				$message = self::core( 'vendor_message', array( $response ) );
				$results[] = array( 'key' => $step['key'], 'status' => 'failed', 'message' => $message );
				self::record_audit( $fresh_plan, 'failed', $results );
				wp_send_json_error(
					array(
						'message' => sprintf( __( 'Provisioning stopped at %1$s: %2$s', 'airfiber-centralized' ), $step['label'], $message ),
						'results' => $results,
					),
					502
				);
			}

			$results[] = array( 'key' => $step['key'], 'status' => 'created', 'message' => $step['summary'] );
			if ( 'onu' === $step['key'] ) {
				sleep( 1 );
			}
		}

		delete_transient( $key );
		self::record_audit( $fresh_plan, 'success', $results );

		if ( class_exists( 'AFC_OLT_Refresh_Manager' ) ) {
			AFC_OLT_Refresh_Manager::schedule_connection_inventory_refresh( $fresh_plan['olt_id'] );
		}

		wp_send_json_success(
			array(
				'message' => __( 'GPON ONU provisioning completed. No PPP customer mapping was created.', 'airfiber-centralized' ),
				'results' => $results,
				'target'  => array(
					'olt_id'   => $fresh_plan['olt_id'],
					'olt_name' => $fresh_plan['olt_name'],
					'pon'      => $fresh_plan['pon'],
					'onu'      => $fresh_plan['onu'],
					'onu_name' => isset( $fresh_plan['onu_name'] ) ? $fresh_plan['onu_name'] : '',
					'serial'   => $fresh_plan['serial'],
					'vlans'    => $fresh_plan['vlans'],
				),
			)
		);
	}
}
