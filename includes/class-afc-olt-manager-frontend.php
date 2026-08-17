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
		<style id="afc-olt-manager-add-card-fix">
		/* Some global button/icon rules add their own generated + to the add card.
		 * The OLT card already has its own circular +, so suppress generated icons. */
		.afc-olt-add-card::before,
		.afc-olt-add-card::after,
		.afc-olt-add-plus::before,
		.afc-olt-add-plus::after {
			content: none !important;
			display: none !important;
		}
		</style>
		<template id="afc-olt-manager-panel-template">
			<section class="afc-frontend-panel afc-olt-manager-panel" data-afc-panel="olt" aria-hidden="true" hidden>
				<?php AFC_OLT_Manager::render_panel(); ?>
			</section>
		</template>
		<script>
		( function () {
			'use strict';
			var app = document.getElementById( 'afc-frontend-app' );
			var template = document.getElementById( 'afc-olt-manager-panel-template' );
			if ( ! app || ! template ) return;
			var nav = app.querySelector( '.afc-frontend-nav' );
			var main = app.querySelector( '.afc-frontend-content' );
			if ( nav && ! nav.querySelector( '[data-afc-app-panel="olt"]' ) ) {
				var link = document.createElement( 'a' );
				link.href = '#olt';
				link.className = 'afc-native-panel-link';
				link.setAttribute( 'data-afc-app-panel', 'olt' );
				link.setAttribute( 'aria-pressed', 'false' );
				link.textContent = 'OLT';
				var mikrotik = nav.querySelector( '[data-afc-app-panel="mikrotik"]' );
				if ( mikrotik ) nav.insertBefore( link, mikrotik );
				else nav.appendChild( link );
			}
			if ( main && ! main.querySelector( '[data-afc-panel="olt"]' ) ) main.appendChild( template.content.cloneNode( true ) );
			template.remove();

			/*
			 * The OLT panel can live inside app containers that establish their own
			 * positioning context. Keep the dialog itself directly under <body> so
			 * position:fixed + place-items:center always centers against the browser
			 * viewport, not against the OLT panel or a transformed ancestor.
			 * Run after the manager's DOMContentLoaded boot so its references and
			 * event listeners are already attached before the node is moved.
			 */
			document.addEventListener( 'DOMContentLoaded', function () {
				window.setTimeout( function () {
					var manager = document.getElementById( 'afc-olt-manager' );
					var modal = manager ? manager.querySelector( '[data-afc-olt-modal]' ) : null;
					if ( modal && modal.parentNode !== document.body ) document.body.appendChild( modal );
				}, 0 );
			} );
		}() );
		</script>
		<?php
	}
}
