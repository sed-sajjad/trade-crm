<?php
/** Token-gated localhost CLI-server adapter for the isolated catalogue UI tests. */
if ( PHP_SAPI !== 'cli-server' || ! getenv( 'TRADE_CRM_HTTP_TEST_TOKEN' ) || ! hash_equals( getenv( 'TRADE_CRM_HTTP_TEST_TOKEN' ), $_SERVER['HTTP_X_CRM_TEST_TOKEN'] ?? '' ) ) {
	http_response_code( 404 );
	exit;
}
$catalog_request_uri = (string) $_SERVER['REQUEST_URI'];
$catalog_request_path = (string) parse_url( $catalog_request_uri, PHP_URL_PATH );
if ( '/_crm_test_ping' === $catalog_request_path ) {
	echo 'ready';
	exit;
}
$catalog_base_url = 'http://' . $_SERVER['HTTP_HOST'];
define( 'WP_HOME', $catalog_base_url );
define( 'WP_SITEURL', $catalog_base_url );
define( 'WP_DEBUG_DISPLAY', false );
// Exercise the production frontend against the standard pretty REST URL.
$GLOBALS['wp_filter']['option_permalink_structure'][PHP_INT_MAX][] = array(
	'function' => static fn () => '/%postname%/', 'accepted_args' => 1,
);
require __DIR__ . '/catalog-test-bootstrap.php';
$_SERVER['REQUEST_URI'] = $catalog_request_uri;
$_SERVER['HTTP_HOST'] = (string) parse_url( $catalog_base_url, PHP_URL_HOST ) . ':' . parse_url( $catalog_base_url, PHP_URL_PORT );
// Authentication is replaced only inside this token-gated isolated test adapter;
// production capability callbacks, validation, repository and SQL still execute.
add_filter( 'rest_authentication_errors', static fn () => true, 101 );

if ( str_starts_with( $catalog_request_path, '/wp-content/plugins/trade-crm/assets/' ) ) {
	$catalog_relative_asset = substr( $catalog_request_path, strlen( '/wp-content/plugins/trade-crm/' ) );
	$catalog_asset = realpath( dirname( __DIR__ ) . '/' . $catalog_relative_asset );
	$catalog_asset_root = realpath( dirname( __DIR__ ) . '/assets' );
	if ( $catalog_asset && str_starts_with( $catalog_asset, $catalog_asset_root . DIRECTORY_SEPARATOR ) && is_file( $catalog_asset ) ) {
		$catalog_mime = array( 'js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'svg' => 'image/svg+xml', 'png' => 'image/png' );
		header( 'Content-Type: ' . ( $catalog_mime[ pathinfo( $catalog_asset, PATHINFO_EXTENSION ) ] ?? 'application/octet-stream' ) );
		readfile( $catalog_asset );
		exit;
	}
}
if ( '/_crm_test_cleanup' === $catalog_request_path && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$catalog_cleanup_body = json_decode( file_get_contents( 'php://input' ), true );
	$catalog_cleanup_id = (int) ( $catalog_cleanup_body['id'] ?? 0 );
	$catalog_tables = \TradeCRM\Tables::names();
	$catalog_cleanup_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$catalog_tables['contacts']} WHERE id=%d", $catalog_cleanup_id ) );
	if ( ! is_string( $catalog_cleanup_name ) || ! str_starts_with( $catalog_cleanup_name, 'آزمون مرورگر حوزه ' ) ) {
		http_response_code( 400 );
		exit;
	}
	foreach ( array( 'activities', 'numbers', 'contact_industries' ) as $catalog_related_table ) {
		$wpdb->delete( $catalog_tables[ $catalog_related_table ], array( 'contact_id' => $catalog_cleanup_id ), array( '%d' ) );
	}
	$wpdb->delete( $catalog_tables['contacts'], array( 'id' => $catalog_cleanup_id ), array( '%d' ) );
	header( 'Content-Type: application/json' );
	echo '{"ok":true}';
	exit;
}
if ( '/crm' === $catalog_request_path || '/crm/' === $catalog_request_path ) {
	\TradeCRM\App::instance()->render();
}
if ( '/trade-crm-manifest.webmanifest' === $catalog_request_path ) {
	\TradeCRM\PWA::instance()->serve_manifest();
}
if ( '/crm/sw.js' === $catalog_request_path ) {
	\TradeCRM\PWA::instance()->serve_worker();
}
$catalog_rest_route = $_GET['rest_route'] ?? null;
if ( str_starts_with( $catalog_request_path, '/wp-json/' ) ) {
	$catalog_rest_route = substr( $catalog_request_path, strlen( '/wp-json' ) );
}
if ( is_string( $catalog_rest_route ) ) {
	rest_get_server()->serve_request( $catalog_rest_route );
	exit;
}
http_response_code( 404 );
