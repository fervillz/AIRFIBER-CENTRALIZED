<?php

defined( 'ABSPATH' ) || exit;

show_admin_bar( false );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<title><?php echo esc_html( get_the_title() ); ?> — <?php bloginfo( 'name' ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'afcn-page' ); ?>>
<?php wp_body_open(); ?>
<?php echo do_shortcode( '[airfiber_next_app]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php wp_footer(); ?>
</body>
</html>
