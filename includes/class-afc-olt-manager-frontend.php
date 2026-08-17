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
		<style id="afc-olt-manager-frontend-fixes">
		/* The Add OLT card already has its own circular +. */
		.afc-olt-add-card::before,
		.afc-olt-add-card::after,
		.afc-olt-add-plus::before,
		.afc-olt-add-plus::after {
			content: none !important;
			display: none !important;
		}

		/* Keep the configuration readable without making the dialog feel oversized. */
		.afc-olt-dialog {
			max-height: min(92vh, 900px);
		}
		.afc-olt-dialog.is-help-open {
			grid-template-columns: minmax(0, 1fr) 390px;
			width: min(1180px, calc(100vw - 36px));
		}
		.afc-olt-dialog-kicker {
			font-size: 11px;
		}
		.afc-olt-dialog-header h3 {
			font-size: 25px;
		}
		.afc-olt-save-info {
			font-size: 12px;
			line-height: 1.45;
		}
		.afc-olt-field label {
			font-size: 12px;
			font-weight: 500;
			line-height: 1.4;
		}
		.afc-olt-field label.afc-olt-label-with-help {
			display: flex;
			align-items: center;
			gap: 6px;
		}
		.afc-olt-field input,
		.afc-olt-field select {
			min-height: 44px;
			padding: 9px 11px;
			font-size: 14px;
		}
		.afc-olt-field input.is-mono {
			font-size: 12px;
		}
		.afc-olt-field small {
			font-size: 11px;
			line-height: 1.5;
		}
		.afc-olt-advanced-fields summary {
			font-size: 12px;
		}
		.afc-olt-btn {
			min-height: 40px;
			padding: 8px 15px;
			font-size: 12px;
		}

		/* Small inline explanations for technical SNMP terms. */
		.afc-olt-term-help {
			position: relative;
			display: inline-flex;
			width: 18px;
			height: 18px;
			flex: 0 0 18px;
			align-items: center;
			justify-content: center;
			border: 1px solid #bfd5e8;
			border-radius: 50%;
			background: #fff;
			color: #2475c5;
			font-size: 10px;
			font-weight: 700;
			line-height: 1;
			cursor: help;
			outline: none;
		}
		.afc-olt-term-help:hover,
		.afc-olt-term-help:focus {
			border-color: #7fb4e2;
			background: #edf6ff;
		}
		.afc-olt-term-help::after {
			content: attr(data-help);
			position: absolute;
			top: calc(100% + 8px);
			left: 50%;
			z-index: 80;
			width: 260px;
			max-width: min(260px, 70vw);
			padding: 9px 10px;
			border: 1px solid #d7e3ed;
			border-radius: 9px;
			background: #172331;
			box-shadow: 0 10px 24px rgba(20, 35, 52, .18);
			color: #fff;
			font-size: 11px;
			font-weight: 400;
			line-height: 1.45;
			text-align: left;
			white-space: normal;
			opacity: 0;
			transform: translate(-50%, -3px);
			pointer-events: none;
			transition: opacity .15s ease, transform .15s ease;
		}
		.afc-olt-term-help:hover::after,
		.afc-olt-term-help:focus::after {
			opacity: 1;
			transform: translate(-50%, 0);
		}

		/* Help is documentation: larger text and its own vertical scrolling area. */
		.afc-olt-help {
			min-height: 0;
		}
		.afc-olt-help-inner {
			width: 390px;
			height: 100%;
			max-height: min(92vh, 900px);
			overflow-x: hidden;
			overflow-y: auto;
			overscroll-behavior: contain;
			scrollbar-gutter: stable;
			padding: 22px 21px 28px;
		}
		.afc-olt-help-header {
			margin-bottom: 20px;
		}
		.afc-olt-help-header span {
			font-size: 10px;
		}
		.afc-olt-help-header h4 {
			font-size: 22px;
			line-height: 1.2;
		}
		.afc-olt-help-steps li {
			padding: 0 0 20px 38px;
		}
		.afc-olt-help-steps li::before {
			width: 26px;
			height: 26px;
			font-size: 10px;
		}
		.afc-olt-help-steps li:not(:last-child)::after {
			top: 27px;
			left: 13px;
		}
		.afc-olt-help-steps strong {
			margin-bottom: 5px;
			font-size: 12.5px;
			line-height: 1.35;
		}
		.afc-olt-help-steps p,
		.afc-olt-help-note p {
			font-size: 11.5px;
			line-height: 1.58;
		}
		.afc-olt-help-note {
			padding: 13px 14px;
		}
		.afc-olt-help-note strong {
			font-size: 11px;
		}

		@media (max-width: 780px) {
			.afc-olt-dialog.is-help-open {
				grid-template-columns: 1fr;
			}
			.afc-olt-help-inner {
				width: 100%;
				max-height: 52vh;
			}
			.afc-olt-term-help::after {
				left: auto;
				right: -8px;
				transform: translateY(-3px);
			}
			.afc-olt-term-help:hover::after,
			.afc-olt-term-help:focus::after {
				transform: translateY(0);
			}
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

			/* Keep the modal directly under body so it is centered to the viewport. */
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
