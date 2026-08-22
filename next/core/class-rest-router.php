<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class Rest_Router {
	const NAMESPACE_V1 = 'airfiber-next/v1';

	public static function register_routes() {
		register_rest_route( self::NAMESPACE_V1, '/bootstrap', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'bootstrap' ), 'permission_callback' => array( __CLASS__, 'can_access' ) ) );
		register_rest_route( self::NAMESPACE_V1, '/module/(?P<module>[a-z0-9-]+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'module' ), 'permission_callback' => array( __CLASS__, 'can_access' ) ) );
		register_rest_route( self::NAMESPACE_V1, '/module/(?P<module>[a-z0-9-]+)/chunk/(?P<chunk>[a-z0-9-]+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'module_chunk' ), 'permission_callback' => array( __CLASS__, 'can_access' ) ) );
		register_rest_route( self::NAMESPACE_V1, '/module/(?P<module>[a-z0-9-]+)/query/(?P<query>[a-z0-9-]+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'module_query' ), 'permission_callback' => array( __CLASS__, 'can_access' ) ) );
		register_rest_route( self::NAMESPACE_V1, '/module/(?P<module>[a-z0-9-]+)/action/(?P<action>[a-z0-9-]+)', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'module_action' ), 'permission_callback' => array( __CLASS__, 'can_access' ) ) );
		register_rest_route( self::NAMESPACE_V1, '/client-metric', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'client_metric' ), 'permission_callback' => array( __CLASS__, 'can_access' ) ) );
	}

	public static function can_access() { return User_Manager::can_access(); }
	public static function bootstrap() { return rest_ensure_response( Module_Manager::bootstrap_payload() ); }
	public static function module( $request ) { return self::response( Module_Manager::render( $request['module'] ) ); }
	public static function module_chunk( $request ) { return self::response( Module_Manager::render_chunk( $request['module'], $request['chunk'], $request->get_params() ) ); }
	public static function module_query( $request ) { return self::response( Module_Manager::handle_query( $request['module'], $request['query'], $request->get_params() ) ); }

	public static function module_action( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) { $payload = $request->get_params(); }
		return self::response( Module_Manager::handle_action( $request['module'], $request['action'], $payload ) );
	}

	public static function client_metric( $request ) {
		$payload  = $request->get_json_params();
		$module   = isset( $payload['module'] ) ? sanitize_key( $payload['module'] ) : 'core';
		$duration = isset( $payload['duration_ms'] ) ? (float) $payload['duration_ms'] : 0;
		if ( $duration > 0 && $duration < 60000 ) { Performance_Monitor::record_client( $module, $duration ); }
		return rest_ensure_response( array( 'accepted' => true ) );
	}

	private static function response( $result ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new \WP_REST_Response( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ), $status );
		}
		return rest_ensure_response( $result );
	}
}
