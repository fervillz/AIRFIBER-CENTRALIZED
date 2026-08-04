<?php

defined( 'ABSPATH' ) || exit;

show_admin_bar( false );
$afc_frontend_mode = class_exists( 'AFC_Ajaxify' ) ? AFC_Ajaxify::initial_mode() : 'basic';

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<title><?php echo esc_html( get_the_title() ); ?> — <?php bloginfo( 'name' ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'afc-frontend-page afc-admin-mode-' . $afc_frontend_mode ); ?>>
<?php wp_body_open(); ?>
<?php echo do_shortcode( '[' . AFC_Frontend_Page::SHORTCODE . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php wp_footer(); ?>
</body>
</html>
