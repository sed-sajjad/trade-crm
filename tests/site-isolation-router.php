<?php
/** Local HTTP test adapter. Never enabled under Apache/FPM or without a test token. */
if ( PHP_SAPI !== 'cli-server' || ! getenv( 'TRADE_CRM_HTTP_TEST_TOKEN' ) || ! hash_equals( getenv( 'TRADE_CRM_HTTP_TEST_TOKEN' ), $_SERVER['HTTP_X_CRM_TEST_TOKEN'] ?? '' ) ) {
	http_response_code( 404 );
	exit;
}
if ( '/_crm_test_ping' === $_SERVER['REQUEST_URI'] ) {
	echo 'ready';
	exit;
}
$root = rtrim( getenv( 'TRADE_CRM_WP_ROOT' ), '/\\' );
$plugin = dirname( __DIR__ ) . '/trade-crm.php';
$mount = str_starts_with( $_SERVER['REQUEST_URI'], '/subsite/' ) ? '/subsite' : '';
$base = 'http://' . $_SERVER['HTTP_HOST'] . $mount;
define( 'WP_HOME', $base );
define( 'WP_SITEURL', $base );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_DEBUG_DISPLAY', false );
$enabled = 'on' === ( $_SERVER['HTTP_X_CRM_TEST_MODE'] ?? '' );
// Preinitialized filters let the same installation act as its own baseline.
$GLOBALS['wp_filter']['option_active_plugins'][PHP_INT_MAX][] = array(
	'function' => static function ( $plugins ) {
		return array_values( array_diff( $plugins, array( 'trade-crm/trade-crm.php' ) ) );
	},
	'accepted_args' => 1,
);
if ( $enabled ) {
	$GLOBALS['wp_filter']['plugins_loaded'][0][] = array(
		'function' => static function () use ( $plugin ) { require_once $plugin; },
		'accepted_args' => 0,
	);
}
$GLOBALS['wp_filter']['plugins_url'][PHP_INT_MAX][] = array(
	'function' => static function ( $url, $path, $file ) use ( $plugin, $base ) {
		return $file === $plugin ? $base . '/wp-content/plugins/trade-crm' . ( $path ? '/' . $path : '' ) : $url;
	},
	'accepted_args' => 3,
);
$GLOBALS['wp_filter']['wp_loaded'][99][] = array(
	'function' => static function () {
		$role = $_SERVER['HTTP_X_CRM_TEST_ROLE'] ?? '';
		if ( ! in_array( $role, array( 'administrator', 'subscriber' ), true ) ) {
			return;
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( ! $admins ) {
			throw new RuntimeException( 'An existing local administrator is required.' );
		}
		$user = wp_set_current_user( $admins[0]->ID );
		if ( 'subscriber' === $role ) {
			// Change only this in-memory user object, never the account in the database.
			$user->allcaps = array( 'read' => true );
			$user->caps = array( 'subscriber' => true );
			$user->roles = array( 'subscriber' );
		}
		add_filter( 'rest_authentication_errors', static fn () => true, 101 );
	},
	'accepted_args' => 0,
);
$path = substr( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), strlen( $mount ) );
$script = '/wp-login.php' === $path ? '/wp-login.php' : '/index.php';
$_SERVER['SCRIPT_NAME'] = $mount . $script;
$_SERVER['PHP_SELF'] = $mount . $script;
$_SERVER['SCRIPT_FILENAME'] = $root . $script;
unset( $_SERVER['PATH_INFO'] );
require $root . $script;
