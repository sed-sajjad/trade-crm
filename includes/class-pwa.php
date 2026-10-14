<?php
namespace TradeCRM;

defined( 'ABSPATH' ) || exit;

final class PWA {
	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function add_rewrite_rules(): void {
		Plugin::add_rule( '^trade-crm-manifest\.webmanifest$', 'index.php?trade_crm_manifest=1' );
		Plugin::add_rule( '^crm/sw\.js$', 'index.php?trade_crm_sw=1' );
	}

	public function register_rewrite_rules(): void {
		self::add_rewrite_rules();
	}

	public static function icon_url( string $filename ): string {
		return add_query_arg( 'v', TRADE_CRM_VERSION, TRADE_CRM_URL . 'assets/icons/' . $filename );
	}

	public function serve_manifest(): void {
		$settings = wp_parse_args( (array) get_option( 'trade_crm_settings', array() ), Tables::default_settings() );
		$manifest = array(
			'id' => 'trade-crm',
			'name' => $settings['app_name'],
			'short_name' => $settings['app_short_name'],
			'lang' => 'fa',
			'dir' => 'rtl',
			'start_url' => home_url( '/crm/' ),
			'scope' => App::url(),
			'display' => 'standalone',
			'display_override' => array( 'standalone', 'minimal-ui' ),
			'theme_color' => $settings['theme_color'],
			'background_color' => $settings['background_color'],
			'icons' => array(
				array( 'src' => self::icon_url( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::icon_url( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::icon_url( 'icon-maskable-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		);
		$this->public_headers( 'application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function serve_worker(): void {
		$manifest_path = TRADE_CRM_DIR . 'assets/build/manifest.json';
		$manifest = is_readable( $manifest_path ) ? json_decode( (string) file_get_contents( $manifest_path ), true ) : array();
		$assets = array(
			TRADE_CRM_URL . 'assets/offline.html',
			self::icon_url( 'icon.svg' ),
			self::icon_url( 'icon-192.png' ),
			self::icon_url( 'icon-512.png' ),
			self::icon_url( 'icon-maskable-512.png' ),
			self::icon_url( 'apple-touch-icon.png' ),
		);
		foreach ( is_array( $manifest ) ? $manifest : array() as $item ) {
			if ( ! empty( $item['file'] ) ) {
				$assets[] = TRADE_CRM_URL . 'assets/build/' . ltrim( $item['file'], '/' );
			}
			foreach ( (array) ( $item['css'] ?? array() ) as $css ) {
				$assets[] = TRADE_CRM_URL . 'assets/build/' . ltrim( $css, '/' );
			}
			foreach ( (array) ( $item['assets'] ?? array() ) as $asset ) {
				$assets[] = TRADE_CRM_URL . 'assets/build/' . ltrim( $asset, '/' );
			}
		}
		$assets = array_values( array_unique( array_filter( $assets, static fn ( $url ): bool => is_string( $url ) && str_starts_with( $url, TRADE_CRM_URL . 'assets/' ) ) ) );
		$scope = home_url( '/crm/' );
		$cache_prefix = 'trade-crm-' . md5( $scope ) . '-';
		$cache_version = $cache_prefix . preg_replace( '/[^a-zA-Z0-9.-]/', '', TRADE_CRM_VERSION );
		$offline = TRADE_CRM_URL . 'assets/offline.html';
		$this->public_headers( 'application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache, max-age=0, must-revalidate' );
		header( 'Service-Worker-Allowed: ' . $scope );
		$source = 'const CACHE=' . wp_json_encode( $cache_version ) . ';const CACHE_PREFIX=' . wp_json_encode( $cache_prefix ) . ';const SCOPE=' . wp_json_encode( $scope ) . ';const OFFLINE=' . wp_json_encode( $offline ) . ';const ASSETS=' . wp_json_encode( $assets ) . ";\n";
		$source .= <<<'JS'
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS)).catch(()=>{}));self.skipWaiting()});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith(CACHE_PREFIX)&&key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',event=>{const request=event.request;const url=new URL(request.url);if(request.method!=='GET'||url.origin!==self.location.origin)return;if(url.pathname.includes('/wp-json/')||url.pathname.includes('/wp-admin/')||url.pathname.endsWith('/wp-login.php'))return;if(request.mode==='navigate'){if(url.pathname.startsWith(new URL(SCOPE).pathname)){event.respondWith(fetch(request).catch(()=>caches.match(OFFLINE)))}return}if(ASSETS.includes(url.href)){event.respondWith(fetch(request).then(response=>{if(!response.ok)return response;return caches.open(CACHE).then(cache=>cache.put(request,response.clone()).then(()=>response))}).catch(()=>caches.match(request)))}});
JS;
		echo $source;
		exit;
	}

	private function public_headers( string $content_type ): void {
		status_header( 200 );
		header( 'Content-Type: ' . $content_type );
		header( 'Cache-Control: public, max-age=300, must-revalidate' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
	}
}
