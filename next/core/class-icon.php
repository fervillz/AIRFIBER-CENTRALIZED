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
			'check'       => '<path d="M5 12l4 4L19 6"></path>',
			'x'           => '<path d="M6 6l12 12M18 6L6 18"></path>',
			'gear'        => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.1A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.1A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.1A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.14.37.36.7.64.98.28.28.62.5 1 .62h.1v4h-.1c-.4.12-.74.34-1.02.62-.28.28-.5.62-.62 1.02z"></path>',
			'trash'       => '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"></path>',
			'restore'     => '<path d="M4 10a8 8 0 1 1 2 7M4 10V4M4 10h6"></path>',
			'refresh'     => '<path d="M20 11a8 8 0 0 0-14.9-4M4 5v5h5M4 13a8 8 0 0 0 14.9 4M20 19v-5h-5"></path>',
			'search'      => '<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path>',
			'update'      => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"></path>',
			'plus'        => '<path d="M12 5v14M5 12h14"></path>',
			'edit'        => '<path d="M4 20h4l11-11-4-4L4 16v4zM13.5 6.5l4 4"></path>',
			'link'        => '<path d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1M14 11a5 5 0 0 0-7.1-.1l-2 2A5 5 0 0 0 12 20l1.1-1.1"></path>',
			'connections' => '<circle cx="6" cy="12" r="2.5"></circle><circle cx="18" cy="6" r="2.5"></circle><circle cx="18" cy="18" r="2.5"></circle><path d="M8.3 10.9l7.4-3.8M8.3 13.1l7.4 3.8"></path>',
			'router'      => '<rect x="3" y="9" width="18" height="9" rx="2"></rect><path d="M7 13h.01M11 13h.01M15 13h2M7 9V5M17 9V5M5 5h4M15 5h4"></path>',
			'server'      => '<rect x="4" y="3" width="16" height="7" rx="2"></rect><rect x="4" y="14" width="16" height="7" rx="2"></rect><path d="M8 6.5h.01M8 17.5h.01M12 6.5h5M12 17.5h5"></path>',
			'cloud'       => '<path d="M7 18h10a4 4 0 0 0 .6-8A6 6 0 0 0 6.2 8.5 4.5 4.5 0 0 0 7 18z"></path>',
			'plug'        => '<path d="M8 12h8M9 4v5M15 4v5M7 9h10v2a5 5 0 0 1-5 5v4"></path>',
			'activity'    => '<path d="M3 12h4l2-5 4 10 2-5h6"></path>',
		);

		if ( ! isset( $icons[ $name ] ) ) {
			$name = 'gear';
		}

		$class = trim( 'afcn-icon ' . sanitize_html_class( $class ) );

		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[ $name ] . '</svg>';
	}
}
