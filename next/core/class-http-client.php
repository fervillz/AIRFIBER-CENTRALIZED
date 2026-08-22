<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

class HTTP_Client {
	public static function get( $module, $url, $args = array() ) {
		$args['method'] = 'GET';
		return self::request( $module, $url, $args );
	}

	public static function post( $module, $url, $args = array() ) {
		$args['method'] = 'POST';
		return self::request( $module, $url, $args );
	}

	public static function request( $module, $url, $args = array() ) {
		$start    = microtime( true );
		$response = wp_remote_request( $url, $args );
		$elapsed  = ( microtime( true ) - $start ) * 1000;
		Performance_Monitor::record_external( $module, $elapsed, wp_parse_url( $url, PHP_URL_HOST ) );
		return $response;
	}
}
