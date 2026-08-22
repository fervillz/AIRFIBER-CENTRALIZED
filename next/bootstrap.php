<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AFCN_VERSION' ) ) {
	define( 'AFCN_VERSION', '0.1.0-beta' );
	define( 'AFCN_PATH', trailingslashit( AFC_PATH . 'next' ) );
	define( 'AFCN_URL', trailingslashit( AFC_URL . 'next' ) );
}

require_once AFCN_PATH . 'core/class-bootstrap.php';

\Airfiber\Next\Bootstrap::init();
