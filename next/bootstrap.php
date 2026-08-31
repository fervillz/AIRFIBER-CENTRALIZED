<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AFCN_VERSION' ) ) {
	define( 'AFCN_VERSION', '0.4.31' );
	define( 'AFCN_PATH', trailingslashit( AFC_PATH . 'next' ) );
	define( 'AFCN_URL', trailingslashit( AFC_URL . 'next' ) );
}

require_once AFCN_PATH . 'core/class-module-naming.php';
require_once AFCN_PATH . 'core/class-mu-module-autoloader.php';
\Airfiber\Next\MU_Module_Autoloader::register();

require_once AFCN_PATH . 'core/class-bootstrap.php';

\Airfiber\Next\Bootstrap::init();
