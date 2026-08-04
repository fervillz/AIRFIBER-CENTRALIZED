<?php

defined( 'ABSPATH' ) || exit;

/**
 * Staged frontend loader for the Airfiber app.
 *
 * Basic mode remains server-rendered. Advanced panels and their assets are
 * grouped, deferred, and loaded only when needed. Desktop browsers warm the
 * Advanced dashboard after the Basic page has completely loaded; mobile
 * browsers remain Basic until Advanced is explicitly selected.
 */
class AFC_Ajaxify {

	const NONCE = 'afc_ajaxify_fragments';

	private static $fragment = '';

	public static function init() {
		add_action( 'wp_ajax_afc_ajaxify_fragment', array( __CLASS__, 'ajax_fragment' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'prepare_loader' ), 99999 );
	}

	public static function initial_mode() {
		if ( class_exists( 'AFC_Frontend_Page' ) && AFC_Frontend_Page::is_app_request() ) {
			return 'basic';
		}
		return class_exists( 'AFC_Admin_Mode' ) ? AFC_Admin_Mode::current_mode() : 'basic';
	}

	public static function saved_mode() {
		return class_exists( 'AFC_Admin_Mode' ) ? AFC_Admin_Mode::current_mode() : 'basic';
	}

	public static function is_fragment( $fragment = '' ) {
		return '' === $fragment ? '' !== self::$fragment : self::$fragment === $fragment;
	}

	public static function panels() {
		$defaults = array(
			'dashboard'    => array( 'group' => 'dashboard', 'server' => true ),
			'schedulers'   => array( 'group' => 'schedulers', 'server' => true ),
			'sms'          => array( 'group' => 'sms', 'server' => true ),
			'mikrotik'     => array( 'group' => 'mikrotik', 'server' => true ),
			'integrations' => array( 'group' => 'integrations', 'server' => false ),
		);
		return apply_filters( 'afc_ajaxify_panels', $defaults );
	}

	public static function render_panel_placeholders() {
		$workspace = class_exists( 'AFC_Advanced_Workspace' ) ? AFC_Advanced_Workspace::panel_registry() : array();
		foreach ( self::panels() as $panel => $settings ) {
			$meta    = isset( $workspace[ $panel ] ) ? $workspace[ $panel ] : array();
			$title   = isset( $meta['title'] ) ? $meta['title'] : ucfirst( $panel );
			$classes = array( 'afc-frontend-panel', 'afc-advanced-only', 'afc-ajaxify-panel' );
			if ( 'dashboard' === $panel ) {
				$classes[] = 'afc-dashboard-panel';
				$classes[] = 'afc-admin-page';
			}
			?>
			<section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
				data-afc-panel="<?php echo esc_attr( $panel ); ?>"
				data-afc-ajaxify-panel="<?php echo esc_attr( $panel ); ?>"
				data-afc-ajaxify-group="<?php echo esc_attr( $settings['group'] ); ?>"
				aria-hidden="true" hidden>
				<div class="afc-ajaxify-placeholder" role="status" aria-live="polite">
					<span class="afc-ajaxify-spinner" aria-hidden="true"></span>
					<strong><?php echo esc_html( $title ); ?></strong>
					<small><?php esc_html_e( 'Loads only when needed.', 'airfiber-centralized' ); ?></small>
				</div>
			</section>
			<?php
		}
	}

	private static function is_app_request() {
		return class_exists( 'AFC_Frontend_Page' )
			&& AFC_Frontend_Page::is_app_request()
			&& current_user_can( 'manage_options' );
	}

	private static function asset_group( $handle ) {
		$handle = (string) $handle;
		if ( 'afc-advanced-workspace' === $handle ) {
			return 'workspace';
		}
		if ( 'afc-sortablejs' === $handle
			|| 0 === strpos( $handle, 'afc-main-dashboard' )
			|| 0 === strpos( $handle, 'afc-dashboard-' )
			|| 0 === strpos( $handle, 'afc-customer-search' ) ) {
			return 'dashboard';
		}
		if ( 0 === strpos( $handle, 'afc-scheduler' ) ) {
			return 'schedulers';
		}
		if ( 0 === strpos( $handle, 'afc-sms-' ) ) {
			return 'sms';
		}
		if ( 'afc-mikrotik-settings' === $handle ) {
			return 'mikrotik';
		}
		if ( 0 === strpos( $handle, 'afc-integrations' )
			|| 0 === strpos( $handle, 'afc-google-' )
			|| 0 === strpos( $handle, 'afc-messenger' ) ) {
			return 'integrations';
		}
		return (string) apply_filters( 'afc_ajaxify_asset_group', '', $handle );
	}

	private static function ordered_handles( $handles, $registry ) {
		$handles = array_values( array_unique( array_filter( $handles ) ) );
		$wanted  = array_fill_keys( $handles, true );
		$visited = array();
		$order   = array();

		$visit = function ( $handle ) use ( &$visit, &$visited, &$order, $wanted, $registry ) {
			if ( isset( $visited[ $handle ] ) || ! isset( $wanted[ $handle ] ) ) {
				return;
			}
			$visited[ $handle ] = true;
			if ( isset( $registry->registered[ $handle ] ) ) {
				foreach ( (array) $registry->registered[ $handle ]->deps as $dependency ) {
					$visit( $dependency );
				}
			}
			$order[] = $handle;
		};

		foreach ( $handles as $handle ) {
			$visit( $handle );
		}
		return $order;
	}

	private static function absolute_src( $src, $registry, $version ) {
		$src = (string) $src;
		if ( '' === $src ) {
			return '';
		}
		if ( 0 === strpos( $src, '//' ) ) {
			$src = set_url_scheme( 'https:' . $src );
		} elseif ( ! preg_match( '#^https?://#i', $src ) ) {
			if ( '/' === substr( $src, 0, 1 ) ) {
				$src = site_url( $src );
			} else {
				$src = trailingslashit( $registry->base_url ) . ltrim( $src, '/' );
			}
		}
		if ( null !== $version && false !== $version && '' !== (string) $version ) {
			$src = add_query_arg( 'ver', rawurlencode( (string) $version ), $src );
		}
		return $src;
	}

	private static function manifest_for( $type, $handles ) {
		$registry = 'script' === $type ? wp_scripts() : wp_styles();
		$order    = self::ordered_handles( $handles, $registry );
		$manifest = array();

		foreach ( $order as $handle ) {
			if ( ! isset( $registry->registered[ $handle ] ) ) {
				continue;
			}
			$dependency = $registry->registered[ $handle ];
			$asset      = array(
				'handle' => $handle,
				'src'    => self::absolute_src( $dependency->src, $registry, $dependency->ver ),
			);

			if ( 'script' === $type ) {
				$asset['before'] = array_values( array_filter( (array) $registry->get_data( $handle, 'before' ), 'is_string' ) );
				$asset['data']   = (string) $registry->get_data( $handle, 'data' );
				$asset['after']  = array_values( array_filter( (array) $registry->get_data( $handle, 'after' ), 'is_string' ) );
			} else {
				$asset['media'] = $dependency->args ? (string) $dependency->args : 'all';
			}

			$group = self::asset_group( $handle );
			if ( '' === $group ) {
				continue;
			}
			if ( ! isset( $manifest[ $group ] ) ) {
				$manifest[ $group ] = array();
			}
			$manifest[ $group ][] = $asset;
		}
		return $manifest;
	}

	private static function merge_manifest( $scripts, $styles ) {
		$groups = array_values( array_unique( array_merge( array_keys( $scripts ), array_keys( $styles ) ) ) );
		$out    = array();
		foreach ( $groups as $group ) {
			$out[ $group ] = array(
				'scripts' => isset( $scripts[ $group ] ) ? $scripts[ $group ] : array(),
				'styles'  => isset( $styles[ $group ] ) ? $styles[ $group ] : array(),
			);
		}
		return $out;
	}

	public static function prepare_loader() {
		if ( ! self::is_app_request() ) {
			return;
		}

		$scripts = wp_scripts();
		$styles  = wp_styles();
		$script_handles = array();
		$style_handles  = array();

		foreach ( (array) $scripts->queue as $handle ) {
			if ( self::asset_group( $handle ) ) {
				$script_handles[] = $handle;
			}
		}
		foreach ( (array) $styles->queue as $handle ) {
			if ( self::asset_group( $handle ) ) {
				$style_handles[] = $handle;
			}
		}

		$manifest = self::merge_manifest(
			self::manifest_for( 'script', $script_handles ),
			self::manifest_for( 'style', $style_handles )
		);

		foreach ( $script_handles as $handle ) {
			wp_dequeue_script( $handle );
		}
		foreach ( $style_handles as $handle ) {
			wp_dequeue_style( $handle );
		}

		wp_enqueue_style(
			'afc-ajaxify-loader',
			AFC_URL . 'assets/css/ajaxify-loader.css',
			array( 'afc-frontend-app' ),
			AFC_VERSION
		);
		wp_enqueue_script(
			'afc-ajaxify-loader',
			AFC_URL . 'assets/js/ajaxify-loader.js',
			array( 'jquery', 'afc-admin-mode', 'afc-frontend-app' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-ajaxify-loader',
			'afcAjaxify',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'mobileHint'  => function_exists( 'wp_is_mobile' ) && wp_is_mobile(),
				'savedMode'   => self::saved_mode(),
				'initialMode' => self::initial_mode(),
				'panels'      => self::panels(),
				'assets'      => $manifest,
				'labels'      => array(
					'loading' => __( 'Loading this tool…', 'airfiber-centralized' ),
					'failed'  => __( 'This tool could not be loaded. Tap to try again.', 'airfiber-centralized' ),
				),
			)
		);
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to load this tool.', 'airfiber-centralized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function extract_panel( $markup, $panel ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return '';
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><div id="afc-fragment-root">' . $markup . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return '';
		}
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//*[@data-afc-panel="' . $panel . '"]' );
		return $nodes && $nodes->length ? $dom->saveHTML( $nodes->item( 0 ) ) : '';
	}

	private static function render_action_panel( $panel ) {
		ob_start();
		do_action( 'afc_frontend_app_content', 'advanced' );
		$markup = ob_get_clean();
		return self::extract_panel( $markup, $panel );
	}

	private static function render_dashboard_rest() {
		$layout = class_exists( 'AFC_Main_Dashboard' ) ? AFC_Main_Dashboard::layout() : array();
		ob_start();
		foreach ( $layout as $widget ) {
			if ( in_array( $widget, array( 'payment', 'new-ppp' ), true ) ) {
				continue;
			}
			AFC_Main_Dashboard::render_widget( $widget );
		}
		return ob_get_clean();
	}

	private static function render_fragment( $fragment ) {
		self::$fragment = $fragment;
		try {
			switch ( $fragment ) {
				case 'dashboard':
					ob_start();
					AFC_Main_Dashboard::render_panel();
					return ob_get_clean();

				case 'dashboard-rest':
					return self::render_dashboard_rest();

				case 'mikrotik':
					ob_start();
					?>
					<section class="afc-frontend-panel afc-advanced-only" data-afc-panel="mikrotik" aria-hidden="true" hidden>
						<?php AFC_Frontend_Page::render_mikrotik_panel(); ?>
					</section>
					<?php
					return ob_get_clean();

				case 'schedulers':
				case 'sms':
					return self::render_action_panel( $fragment );
			}
			return '';
		} finally {
			self::$fragment = '';
		}
	}

	public static function ajax_fragment() {
		self::authorize();
		$fragment = isset( $_POST['fragment'] ) ? sanitize_key( wp_unslash( $_POST['fragment'] ) ) : '';
		$allowed  = array( 'dashboard', 'dashboard-rest', 'mikrotik', 'schedulers', 'sms' );
		if ( ! in_array( $fragment, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown Airfiber fragment.', 'airfiber-centralized' ) ), 400 );
		}

		$html = self::render_fragment( $fragment );
		if ( '' === trim( $html ) ) {
			wp_send_json_error( array( 'message' => __( 'The requested Airfiber tool returned no content.', 'airfiber-centralized' ) ), 500 );
		}
		wp_send_json_success(
			array(
				'fragment'  => $fragment,
				'html'      => $html,
				'generated' => current_time( 'mysql' ),
			)
		);
	}
}
