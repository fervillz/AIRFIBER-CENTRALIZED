<?php

namespace Airfiber\Next;

defined( 'ABSPATH' ) || exit;

/**
 * Small dependency-free SVG icon library for shared Airfiber controls.
 */
class Icon {

	public static function svg( $name, $class = '' ) {
		$name = sanitize_key( $name );

		$icons = array(
			'check'   => '<path d="M5 12l4 4L19 6"></path>',
			'x'       => '<path d="M6 6l12 12M18 6L6 18"></path>',
			'gear'    => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.1A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.1A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.1A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.14.37.36.7.64.98.28.28.62.5 1 .62h.1v4h-.1c-.4.12-.74.34-1.02.62-.28.28-.5.62-.62 1.02z"></path>',
			'trash'   => '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"></path>',
			'restore' => '<path d="M4 10a8 8 0 1 1 2 7M4 10V4M4 10h6"></path>',
			'refresh' => '<path d="M20 11a8 8 0 0 0-14.9-4M4 5v5h5M4 13a8 8 0 0 0 14.9 4M20 19v-5h-5"></path>',
			'search'  => '<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path>',
			'update'  => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"></path>',
		);

		if ( ! isset( $icons[ $name ] ) ) {
			$name = 'gear';
		}

		$class = trim( 'afcn-icon ' . sanitize_html_class( $class ) );

		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[ $name ] . '</svg>';
	}
}
