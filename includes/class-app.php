<?php
namespace TradeCRM;

defined( 'ABSPATH' ) || exit;

final class App {
	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function url(): string {
		return home_url( '/crm/' );
	}

	public static function add_rewrite_rules(): void {
		Plugin::add_rule( '^crm/?$', 'index.php?trade_crm_app=1' );
	}

	public function register_rewrite_rules(): void {
		self::add_rewrite_rules();
	}

	/** Read-only renewal: the existing WordPress cookie must still be valid. */
	public function session(): void {
		$this->no_store();
		if ( ! is_user_logged_in() ) {
			wp_send_json( array( 'code' => 'trade_crm_auth_required', 'message' => 'برای استفاده از CRM وارد شوید.' ), 401 );
		}
		if ( ! current_user_can( 'manage_trade_crm' ) ) {
			wp_send_json( array( 'code' => 'trade_crm_forbidden', 'message' => 'به برنامه CRM دسترسی ندارید.' ), 403 );
		}
		wp_send_json( array(
			'user_id' => get_current_user_id(),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'logout_url' => wp_logout_url( self::url() ),
		), 200 );
	}

	public function render(): void {
		$this->no_store();
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		if ( ! current_user_can( 'manage_trade_crm' ) ) {
			wp_die( esc_html__( 'به برنامه CRM دسترسی ندارید.', 'trade-crm' ), '', array( 'response' => 403 ) );
		}
		$build_dir = TRADE_CRM_DIR . 'assets/build/';
		$manifest_path = $build_dir . 'manifest.json';
		if ( ! is_readable( $manifest_path ) ) {
			status_header( 503 );
			echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CRM بازرگان</title><body style="font-family:sans-serif;padding:2rem;direction:rtl"><h1>برنامه هنوز ساخته نشده است</h1><p>فایل‌های build افزونه پیدا نشد. راهنمای نصب را بررسی کنید.</p></body></html>';
			exit;
		}
		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		$entry = is_array( $manifest ) ? ( $manifest['index.html'] ?? null ) : null;
		if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
			status_header( 503 );
			echo 'CRM build manifest is invalid.';
			exit;
		}
		status_header( 200 );
		$settings = get_option( 'trade_crm_settings', Tables::default_settings() );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), Tables::default_settings() );
		$user = wp_get_current_user();
		$config = array(
			'apiRoot' => esc_url_raw( rest_url( 'trade-crm/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'sessionUrl' => add_query_arg( 'action', 'trade_crm_session', admin_url( 'admin-ajax.php' ) ),
			'appUrl' => self::url(),
			'loginUrl' => wp_login_url( self::url() ),
			'logoutUrl' => wp_logout_url( self::url() ),
			'user' => array( 'id' => (int) $user->ID, 'name' => $user->display_name ),
			'appName' => $settings['app_name'],
			'version' => TRADE_CRM_VERSION,
		);
		$asset_url = TRADE_CRM_URL . 'assets/build/';
		?><!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="utf-8">
	<meta name="robots" content="noindex, nofollow, noarchive">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<meta name="theme-color" content="<?php echo esc_attr( $settings['theme_color'] ); ?>">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
	<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( $settings['app_short_name'] ); ?>">
	<link rel="manifest" href="<?php echo esc_url( home_url( '/trade-crm-manifest.webmanifest' ) ); ?>">
	<link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( PWA::icon_url( 'apple-touch-icon.png' ) ); ?>">
	<link rel="icon" href="<?php echo esc_url( PWA::icon_url( 'icon.svg' ) ); ?>" type="image/svg+xml">
	<title><?php echo esc_html( $settings['app_name'] ); ?></title>
	<?php foreach ( (array) ( $entry['css'] ?? array() ) as $css ) : ?>
	<link rel="stylesheet" href="<?php echo esc_url( $asset_url . $css ); ?>">
	<?php endforeach; ?>
	<script>window.tradeCrmConfig=<?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
</head>
<body>
	<div id="trade-crm-root"><div class="trade-crm-loading">در حال آماده‌سازی CRM…</div></div>
	<script type="module" src="<?php echo esc_url( $asset_url . $entry['file'] ); ?>"></script>
	<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register(<?php echo wp_json_encode( home_url( '/crm/sw.js' ) ); ?>,{scope:<?php echo wp_json_encode( home_url( '/crm/' ) ); ?>}).catch(()=>{}));}</script>
</body>
</html><?php
		exit;
	}

	private function no_store(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Trade CRM private page' );
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Vary: Cookie', false );
		}
	}
}
