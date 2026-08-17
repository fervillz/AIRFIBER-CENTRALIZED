<?php

defined( 'ABSPATH' ) || exit;

class AFC_OLT_Manager_Frontend {
	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'render_template' ), 30 );
	}

	public static function render_template() {
		if ( is_admin() || ! current_user_can( 'manage_options' ) || ! class_exists( 'AFC_Frontend_Page' ) || ! AFC_Frontend_Page::is_app_request() || ! class_exists( 'AFC_OLT_Manager' ) ) {
			return;
		}
		?>
		<template id="afc-olt-manager-panel-template">
			<section class="afc-frontend-panel afc-olt-manager-panel" data-afc-panel="olt" aria-hidden="true" hidden>
				<?php AFC_OLT_Manager::render_panel(); ?>
			</section>
		</template>
		<?php
	}
}
