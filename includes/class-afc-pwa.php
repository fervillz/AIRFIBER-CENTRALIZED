<?php

defined( 'ABSPATH' ) || exit;

/**
 * Progressive Web App support for the protected Airfiber frontend.
 *
 * The manifest, service worker and icons are served dynamically so every
 * WordPress installation receives a complete app package without requiring
 * files to be copied into the active theme or web root.
 */
class AFC_PWA {

	const ASSET_QUERY = 'afc_pwa_asset';
	const THEME_COLOR = '#206bc4';
	const BG_COLOR    = '#f4f7fb';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve_asset' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 30 );
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 1 );
	}

	public static function get_asset_url( $asset ) {
		return add_query_arg(
			array(
				self::ASSET_QUERY => sanitize_key( $asset ),
				'v'               => AFC_VERSION,
			),
			home_url( '/' )
		);
	}

	private static function requested_asset() {
		if ( empty( $_GET[ self::ASSET_QUERY ] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( $_GET[ self::ASSET_QUERY ] ) );
	}

	public static function serve_asset() {
		$asset = self::requested_asset();
		if ( ! $asset ) {
			return;
		}

		switch ( $asset ) {
			case 'manifest':
				self::serve_manifest();
				break;
			case 'service-worker':
				self::serve_service_worker();
				break;
			case 'icon-192':
				self::serve_icon( 192 );
				break;
			case 'icon-512':
				self::serve_icon( 512 );
				break;
		}
	}

	private static function app_path() {
		$path = wp_parse_url( AFC_Frontend_Page::get_url(), PHP_URL_PATH );
		return trailingslashit( $path ? $path : '/' . AFC_Frontend_Page::PAGE_SLUG . '/' );
	}

	private static function icon_type() {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' )
			? 'image/png'
			: 'image/svg+xml';
	}

	private static function manifest_icon( $asset, $sizes, $purpose = 'any' ) {
		return array(
			'src'     => self::get_asset_url( $asset ),
			'sizes'   => $sizes,
			'type'    => self::icon_type(),
			'purpose' => $purpose,
		);
	}

	private static function serve_manifest() {
		$start_url = AFC_Frontend_Page::get_url();
		$scope     = self::app_path();

		$manifest = array(
			'id'                          => $scope,
			'name'                        => 'Airfiber Centralized',
			'short_name'                  => 'Airfiber',
			'description'                 => 'Private Airfiber customer, billing, collection and MikroTik operations app.',
			'lang'                        => get_bloginfo( 'language' ),
			'scope'                       => $scope,
			'start_url'                   => add_query_arg( 'source', 'pwa', $start_url ),
			'display'                     => 'standalone',
			'display_override'            => array( 'window-controls-overlay', 'standalone', 'minimal-ui' ),
			'orientation'                 => 'any',
			'background_color'            => self::BG_COLOR,
			'theme_color'                 => self::THEME_COLOR,
			'categories'                  => array( 'business', 'productivity', 'utilities' ),
			'prefer_related_applications' => false,
			'launch_handler'              => array( 'client_mode' => array( 'navigate-existing', 'auto' ) ),
			'icons'                       => array(
				self::manifest_icon( 'icon-192', '192x192' ),
				self::manifest_icon( 'icon-512', '512x512', 'any maskable' ),
			),
			'shortcuts'                   => array(
				array(
					'name'       => 'Operations',
					'short_name' => 'Operations',
					'url'        => $start_url,
					'icons'      => array( self::manifest_icon( 'icon-192', '192x192' ) ),
				),
				array(
					'name'       => 'MikroTik',
					'short_name' => 'MikroTik',
					'url'        => $start_url . '#mikrotik',
					'icons'      => array( self::manifest_icon( 'icon-192', '192x192' ) ),
				),
			),
		);

		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private static function serve_service_worker() {
		$app_url  = AFC_Frontend_Page::get_url();
		$app_path = self::app_path();
		$icon_192 = self::get_asset_url( 'icon-192' );
		$icon_512 = self::get_asset_url( 'icon-512' );
		$cache    = 'airfiber-pwa-' . sanitize_key( AFC_VERSION );

		$offline_html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="' . self::THEME_COLOR . '"><title>Airfiber Offline</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:' . self::BG_COLOR . ';font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#182433}main{max-width:440px;padding:32px;border:1px solid #dce1e7;border-radius:20px;background:#fff;box-shadow:0 20px 60px rgba(24,36,51,.12);text-align:center}b{display:grid;width:64px;height:64px;margin:0 auto 18px;place-items:center;border-radius:18px;background:' . self::THEME_COLOR . ';color:#fff;font-size:32px}h1{margin:0 0 10px;font-size:28px}p{margin:0;color:#667382;line-height:1.6}button{margin-top:20px;padding:12px 18px;border:0;border-radius:10px;background:' . self::THEME_COLOR . ';color:#fff;font-weight:700}</style></head><body><main><b>A</b><h1>Airfiber is offline</h1><p>Reconnect to ZeroTier and the server, then try again. Customer and payment data is never stored in the offline page.</p><button onclick="location.reload()">Try again</button></main></body></html>';

		$script = sprintf(
			<<<JS
const AFC_CACHE = %s;
const AFC_APP_URL = %s;
const AFC_APP_PATH = %s;
const AFC_ICON_URLS = [%s, %s];
const AFC_OFFLINE_HTML = %s;

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches.open(AFC_CACHE)
			.then((cache) => cache.addAll(AFC_ICON_URLS))
			.catch(() => undefined)
	);
	self.skipWaiting();
});

self.addEventListener('message', (event) => {
	if (event.data && event.data.type === 'SKIP_WAITING') {
		self.skipWaiting();
	}
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys()
			.then((keys) => Promise.all(
				keys.filter((key) => key.startsWith('airfiber-pwa-') && key !== AFC_CACHE)
					.map((key) => caches.delete(key))
			))
			.then(() => self.clients.claim())
	);
});

self.addEventListener('fetch', (event) => {
	const request = event.request;
	if (request.method !== 'GET') {
		return;
	}

	const url = new URL(request.url);
	if (url.origin !== self.location.origin) {
		return;
	}

	if (request.mode === 'navigate' && url.pathname.startsWith(AFC_APP_PATH)) {
		event.respondWith(
			fetch(request).catch(() => new Response(AFC_OFFLINE_HTML, {
				status: 503,
				headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }
			}))
		);
		return;
	}

	if (AFC_ICON_URLS.includes(url.href)) {
		event.respondWith(
			caches.match(request).then((cached) => cached || fetch(request).then((response) => {
				const copy = response.clone();
				caches.open(AFC_CACHE).then((cache) => cache.put(request, copy));
				return response;
			}))
		);
	}
});
JS,
			wp_json_encode( $cache ),
			wp_json_encode( $app_url ),
			wp_json_encode( $app_path ),
			wp_json_encode( $icon_192 ),
			wp_json_encode( $icon_512 ),
			wp_json_encode( $offline_html )
		);

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::app_path() );
		header( 'X-Content-Type-Options: nosniff' );
		echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function serve_icon( $size ) {
		if ( ! in_array( $size, array( 192, 512 ), true ) ) {
			status_header( 404 );
			exit;
		}

		if ( 'image/png' === self::icon_type() ) {
			self::serve_png_icon( $size );
		}

		self::serve_svg_icon( $size );
	}

	private static function serve_png_icon( $size ) {
		$image = imagecreatetruecolor( $size, $size );
		if ( ! $image ) {
			self::serve_svg_icon( $size );
		}

		$blue  = imagecolorallocate( $image, 32, 107, 196 );
		$navy  = imagecolorallocate( $image, 15, 64, 130 );
		$white = imagecolorallocate( $image, 255, 255, 255 );
		$cyan  = imagecolorallocate( $image, 104, 200, 245 );
		imagefilledrectangle( $image, 0, 0, $size, $size, $blue );
		imagefilledellipse( $image, (int) ( $size * 0.5 ), (int) ( $size * 0.5 ), (int) ( $size * 0.70 ), (int) ( $size * 0.70 ), $navy );

		$a_points = array(
			(int) ( $size * 0.50 ), (int) ( $size * 0.20 ),
			(int) ( $size * 0.25 ), (int) ( $size * 0.72 ),
			(int) ( $size * 0.37 ), (int) ( $size * 0.72 ),
			(int) ( $size * 0.43 ), (int) ( $size * 0.58 ),
			(int) ( $size * 0.57 ), (int) ( $size * 0.58 ),
			(int) ( $size * 0.63 ), (int) ( $size * 0.72 ),
			(int) ( $size * 0.75 ), (int) ( $size * 0.72 ),
		);
		imagefilledpolygon( $image, $a_points, 7, $white );
		imagefilledpolygon(
			$image,
			array(
				(int) ( $size * 0.50 ), (int) ( $size * 0.38 ),
				(int) ( $size * 0.45 ), (int) ( $size * 0.51 ),
				(int) ( $size * 0.55 ), (int) ( $size * 0.51 ),
			),
			3,
			$navy
		);

		imagesetthickness( $image, max( 3, (int) ( $size * 0.024 ) ) );
		imagearc( $image, (int) ( $size * 0.50 ), (int) ( $size * 0.66 ), (int) ( $size * 0.43 ), (int) ( $size * 0.28 ), 205, 335, $cyan );
		imagearc( $image, (int) ( $size * 0.50 ), (int) ( $size * 0.70 ), (int) ( $size * 0.26 ), (int) ( $size * 0.18 ), 205, 335, $cyan );
		imagefilledellipse( $image, (int) ( $size * 0.50 ), (int) ( $size * 0.75 ), max( 6, (int) ( $size * 0.05 ) ), max( 6, (int) ( $size * 0.05 ) ), $cyan );

		status_header( 200 );
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'X-Content-Type-Options: nosniff' );
		imagepng( $image );
		imagedestroy( $image );
		exit;
	}

	private static function serve_svg_icon( $size ) {
		$icon_file = AFC_PATH . 'assets/images/airfiber-icon-' . $size . '.svg';
		if ( ! is_readable( $icon_file ) ) {
			status_header( 404 );
			exit;
		}

		$image = (string) file_get_contents( $icon_file );
		status_header( 200 );
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Length: ' . strlen( $image ) );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function render_head() {
		if ( ! AFC_Frontend_Page::is_app_request() ) {
			return;
		}

		$manifest = self::get_asset_url( 'manifest' );
		$icon_192 = self::get_asset_url( 'icon-192' );
		$icon_512 = self::get_asset_url( 'icon-512' );
		?>
		<link rel="manifest" href="<?php echo esc_url( $manifest ); ?>">
		<meta name="theme-color" content="<?php echo esc_attr( self::THEME_COLOR ); ?>">
		<meta name="application-name" content="Airfiber">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-status-bar-style" content="default">
		<meta name="apple-mobile-web-app-title" content="Airfiber">
		<link rel="apple-touch-icon" sizes="512x512" href="<?php echo esc_url( $icon_512 ); ?>">
		<link rel="icon" type="<?php echo esc_attr( self::icon_type() ); ?>" sizes="192x192" href="<?php echo esc_url( $icon_192 ); ?>">
		<?php
	}

	public static function enqueue_assets() {
		if ( ! AFC_Frontend_Page::is_app_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_style(
			'afc-pwa-install',
			AFC_URL . 'assets/css/pwa-install.css',
			array( 'afc-frontend-app' ),
			AFC_VERSION
		);

		wp_enqueue_script(
			'afc-pwa-install',
			AFC_URL . 'assets/js/pwa-install.js',
			array( 'afc-frontend-app' ),
			AFC_VERSION,
			true
		);

		wp_localize_script(
			'afc-pwa-install',
			'afcPWA',
			array(
				'appName'          => __( 'Airfiber', 'airfiber-centralized' ),
				'appUrl'           => AFC_Frontend_Page::get_url(),
				'serviceWorkerUrl' => self::get_asset_url( 'service-worker' ),
				'scope'            => self::app_path(),
				'install'          => __( 'Install App', 'airfiber-centralized' ),
				'installing'       => __( 'Installing...', 'airfiber-centralized' ),
				'installed'        => __( 'App installed', 'airfiber-centralized' ),
				'helpTitle'        => __( 'Install Airfiber', 'airfiber-centralized' ),
				'iosHelp'          => __( 'Tap the Share button, then choose Add to Home Screen.', 'airfiber-centralized' ),
				'httpHelp'         => __( 'Open the browser menu and choose Add to Home screen or Install app. Full automatic installation and offline support require HTTPS.', 'airfiber-centralized' ),
				'browserHelp'      => __( 'Use the browser menu and choose Install app or Add to Home screen.', 'airfiber-centralized' ),
				'close'            => __( 'Close', 'airfiber-centralized' ),
				'updateReady'      => __( 'A new Airfiber version is ready.', 'airfiber-centralized' ),
				'reload'           => __( 'Reload', 'airfiber-centralized' ),
			)
		);
	}
}
