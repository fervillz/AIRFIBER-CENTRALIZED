<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

interface Module_Contract {
	public static function render( $context = array() );
	public static function handle_action( $action, $payload = array() );
}
