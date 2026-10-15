<?php
/** Read-only UI review adapter. CLI localhost only; excluded from install packages. */
$review_token = (string) getenv( 'TRADE_CRM_REVIEW_TOKEN' );
$review_host = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
$review_supplied = (string) ( $_SERVER['HTTP_X_CRM_REVIEW_TOKEN'] ?? $_GET['_review_token'] ?? $_COOKIE['crm_ui_review'] ?? '' );
if ( PHP_SAPI !== 'cli-server' || ! preg_match( '/^127\.0\.0\.1:\d+$/D', $review_host ) || strlen( $review_token ) < 32 || ! hash_equals( $review_token, $review_supplied ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
	http_response_code( 405 );
	header( 'Allow: GET, HEAD' );
	exit;
}
$review_path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/_crm_review_ping' === $review_path ) {
	echo 'ready';
	exit;
}
if ( isset( $_GET['_review_token'] ) ) {
	setcookie( 'crm_ui_review', $review_token, array( 'path' => '/', 'httponly' => true, 'samesite' => 'Strict' ) );
}
$review_root = rtrim( (string) getenv( 'TRADE_CRM_WP_ROOT' ), '/\\' );
if ( ! is_file( $review_root . '/wp-load.php' ) ) {
	http_response_code( 500 );
	exit( 'Missing local WordPress root.' );
}
if ( str_starts_with( $review_path, '/wp-content/plugins/trade-crm/assets/' ) ) {
	$review_relative = substr( $review_path, strlen( '/wp-content/plugins/trade-crm/assets/' ) );
	$review_asset_root = realpath( getenv( 'TRADE_CRM_REVIEW_ASSETS' ) ?: dirname( __DIR__ ) . '/assets' );
	$review_asset = realpath( $review_asset_root . '/' . $review_relative );
	if ( $review_asset && str_starts_with( $review_asset, $review_asset_root . DIRECTORY_SEPARATOR ) && is_file( $review_asset ) ) {
		$review_mimes = array( 'js' => 'text/javascript', 'css' => 'text/css', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'html' => 'text/html', 'json' => 'application/json' );
		header( 'Content-Type: ' . ( $review_mimes[pathinfo( $review_asset, PATHINFO_EXTENSION )] ?? 'application/octet-stream' ) );
		readfile( $review_asset );
		exit;
	}
}
$review_base = 'http://' . $review_host;
define( 'WP_HOME', $review_base );
define( 'WP_SITEURL', $review_base );
if ( ! getenv( 'TRADE_CRM_TEST_DB_NAME' ) ) {
	define( 'DISABLE_WP_CRON', true );
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}
define( 'WP_DEBUG_DISPLAY', false );
// Fail closed if any review request attempts to mutate a CRM table.
$GLOBALS['wp_filter']['query'][PHP_INT_MAX][] = array(
	'function' => static function ( $query ) {
		if ( preg_match( '/\b(?:INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE|RENAME)\b/i', $query ) && preg_match( '/\b\w*trade_crm_\w*\b/i', $query ) ) {
			throw new RuntimeException( 'CRM writes are forbidden during read-only UI review.' );
		}
		return $query;
	},
	'accepted_args' => 1,
);
$review_request_uri = $_SERVER['REQUEST_URI'];
if ( getenv( 'TRADE_CRM_TEST_DB_NAME' ) ) {
	// The existing bootstrap rejects every non-test database name.
	$GLOBALS['wp_filter']['option_permalink_structure'][PHP_INT_MAX][] = array(
		'function' => static fn () => '/%postname%/', 'accepted_args' => 1,
	);
	require __DIR__ . '/catalog-test-bootstrap.php';
	$_SERVER['HTTP_HOST'] = $review_host;
	$_SERVER['REQUEST_URI'] = $review_request_uri;
} else {
	require $review_root . '/wp-load.php';
}
if ( ! class_exists( '\TradeCRM\App' ) ) {
	require dirname( __DIR__ ) . '/trade-crm.php';
	( new \TradeCRM\Plugin() )->boot();
}
global $wpdb;
$wpdb->query( 'SET SESSION TRANSACTION READ ONLY' );
$review_admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $review_admins ) {
	http_response_code( 500 );
	exit( 'An existing local administrator is required.' );
}
$review_user = wp_set_current_user( $review_admins[0]->ID );
$review_user->allcaps['manage_trade_crm'] = true;
add_filter( 'rest_authentication_errors', static fn () => true, 101 );
add_filter( 'plugins_url', static function ( $url, $path, $file ) use ( $review_base ) {
	return str_ends_with( str_replace( '\\', '/', $file ), '/trade-crm/trade-crm.php' ) ? $review_base . '/wp-content/plugins/trade-crm' . ( $path ? '/' . $path : '' ) : $url;
}, PHP_INT_MAX, 3 );
if ( '/_crm_review_fingerprint' === $review_path ) {
	$review_fingerprint = array();
	foreach ( \TradeCRM\Tables::names() as $review_key => $review_table ) {
		$review_rows = $wpdb->get_results( "SELECT * FROM `{$review_table}` ORDER BY 1", ARRAY_A );
		$review_fingerprint[$review_key] = array( 'count' => count( $review_rows ), 'sha256' => hash( 'sha256', wp_json_encode( $review_rows ) ) );
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $review_fingerprint );
	exit;
}
if ( '/crm/' === $review_path || '/crm' === $review_path ) {
	\TradeCRM\App::instance()->render();
}
if ( str_starts_with( $review_path, '/wp-json/' ) ) {
	rest_get_server()->serve_request( substr( $review_path, strlen( '/wp-json' ) ) );
	exit;
}
if ( '/crm/sw.js' === $review_path ) {
	\TradeCRM\PWA::instance()->serve_worker();
}
if ( '/trade-crm-manifest.webmanifest' === $review_path ) {
	\TradeCRM\PWA::instance()->serve_manifest();
}
http_response_code( 404 );
