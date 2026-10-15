<?php
/** Temporary-user authentication checks. Set TRADE_CRM_WP_ROOT before running. */
$wordpress_root = rtrim( (string) getenv( 'TRADE_CRM_WP_ROOT' ), '/\\' );
if ( ! is_file( $wordpress_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set TRADE_CRM_WP_ROOT to an installed WordPress directory.\n" );
	exit( 2 );
}
define( 'DOING_AJAX', true );
require $wordpress_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	$checks++;
};
class TradeCRM_Session_Test_Exit extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static fn () => static function (): void { throw new TradeCRM_Session_Test_Exit(); } );
$status = 0;
add_filter( 'status_header', static function ( $header, $code ) use ( &$status ) { $status = $code; return $header; }, 10, 2 );
$session_response = static function () use ( &$status ): array {
	$status = 0;
	ob_start();
	try {
		TradeCRM\App::instance()->session();
	} catch ( TradeCRM_Session_Test_Exit $exit ) {
		// Capture WordPress's JSON exit without ending the integration test.
	}
	return array( 'status' => $status, 'body' => json_decode( ob_get_clean(), true, 512, JSON_THROW_ON_ERROR ) );
};
$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
$login_name = 'crm_session_test_' . $suffix;
$test_user_id = wp_insert_user( array( 'user_login' => $login_name, 'user_pass' => wp_generate_password( 40 ), 'user_email' => $suffix . '@example.test', 'role' => 'trade_crm_supervisor' ) );
$assert( ! is_wp_error( $test_user_id ), 'Could not create a temporary CRM test user.' );
$original_post = $_POST;
$original_get = $_GET;
$original_cookie = $_COOKIE;
$original_user = get_current_user_id();
$failure = null;
try {
	$login = new TradeCRM\Login();
	$login->boot();
	$assert( user_can( $test_user_id, 'manage_trade_crm' ), 'Temporary user needs the CRM role.' );
	$_GET = array();
	$_POST = array( 'redirect_to' => TradeCRM\App::url() );
	$assert( 30 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $test_user_id, true ), 'Opted-in CRM logins must last 30 days.' );
	$assert( 2 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $test_user_id, false ), 'Unchecked Remember Me must keep WordPress defaults.' );
	$assert( 14 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, 0, true ), 'Guests must not receive extended sessions.' );
	foreach ( array( admin_url(), home_url( '/crm-other/' ), 'https://foreign.example/crm/', array( 'invalid' ) ) as $destination ) {
		$_POST['redirect_to'] = $destination;
		$assert( 14 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $test_user_id, true ), 'Ordinary, external and malformed login destinations must remain unchanged.' );
	}
	$_POST['redirect_to'] = TradeCRM\App::url() . '?view=contacts';
	$assert( 30 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $test_user_id, true ), 'CRM query routes must support Remember Me.' );

	wp_set_current_user( 0 );
	$response = $session_response();
	$assert( 401 === $response['status'] && ! isset( $response['body']['nonce'] ), 'Guests must not receive a fresh nonce.' );
	wp_set_current_user( $test_user_id );
	$deny = true;
	$deny_crm = static function ( $caps ) use ( &$deny ) { if ( $deny ) { $caps['manage_trade_crm'] = false; } return $caps; };
	add_filter( 'user_has_cap', $deny_crm );
	$response = $session_response();
	$assert( 403 === $response['status'] && ! isset( $response['body']['nonce'] ), 'Revoked CRM access must not receive a fresh nonce.' );
	$assert( 14 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $test_user_id, true ), 'Users without CRM access must not get a longer session.' );
	$deny = false;
	remove_filter( 'user_has_cap', $deny_crm );
	$response = $session_response();
	$assert( 200 === $response['status'] && $test_user_id === $response['body']['user_id'], 'A valid session must identify its actual user.' );
	$assert( false !== wp_verify_nonce( $response['body']['nonce'], 'wp_rest' ), 'Renewed REST nonce must validate.' );
	$assert( str_contains( $response['body']['logout_url'], '_wpnonce=' ), 'Renewal must also refresh the logout link.' );

	$auth = array(); $logged_in = array();
	add_action( 'set_auth_cookie', static function ( $cookie, $expire, $expiration, $id, $scheme, $token ) use ( &$auth ) { $auth = compact( 'cookie', 'expire', 'expiration', 'id', 'scheme', 'token' ); }, 10, 6 );
	add_action( 'set_logged_in_cookie', static function ( $cookie, $expire, $expiration, $id, $scheme, $token ) use ( &$logged_in ) { $logged_in = compact( 'cookie', 'expire', 'expiration', 'id', 'scheme', 'token' ); }, 10, 6 );
	add_filter( 'send_auth_cookies', '__return_false' );
	wp_set_auth_cookie( $test_user_id, true, true );
	$assert( abs( $auth['expiration'] - time() - 30 * DAY_IN_SECONDS ) <= 2, 'Actual remembered cookie must encode a 30-day expiration.' );
	$assert( $auth['expire'] > $auth['expiration'] && 'secure_auth' === $auth['scheme'], 'Persistent expiration and HTTPS authentication scheme must remain intact.' );
	$assert( $test_user_id === wp_validate_auth_cookie( $logged_in['cookie'], 'logged_in' ), 'The remembered login cookie must authenticate.' );
	$manager = WP_Session_Tokens::get_instance( $test_user_id );
	$before = $manager->get_all();
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in['cookie'];
	$session_response();
	$assert( $before === $manager->get_all(), 'CSRF renewal must not extend, replace or create login sessions.' );
	$expired_cookie = wp_generate_auth_cookie( $test_user_id, time() - 2 * HOUR_IN_SECONDS, 'logged_in', $logged_in['token'] );
	$assert( ! wp_validate_auth_cookie( $expired_cookie, 'logged_in' ), 'Expired cookies must remain invalid.' );
	wp_logout();
	$assert( ! $manager->verify( $logged_in['token'] ), 'Manual logout must revoke the remembered session token.' );
	$assert( ! wp_validate_auth_cookie( $logged_in['cookie'], 'logged_in' ), 'A remembered cookie must not work after logout.' );
	$response = $session_response();
	$assert( 401 === $response['status'], 'Logged-out users must not recover authentication through renewal.' );

	wp_set_current_user( $test_user_id );
	wp_set_auth_cookie( $test_user_id, false, true );
	$assert( 0 === $auth['expire'] && abs( $auth['expiration'] - time() - 2 * DAY_IN_SECONDS ) <= 2, 'Unchecked Remember Me must issue an ordinary browser session cookie.' );
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	$_POST = $original_post; $_GET = $original_get; $_COOKIE = $original_cookie;
	wp_set_current_user( $original_user );
	$temporary = get_userdata( $test_user_id );
	if ( $temporary && $temporary->user_login === $login_name && str_starts_with( $login_name, 'crm_session_test_' ) ) {
		wp_delete_user( $test_user_id );
	}
}
if ( $failure ) {
	fwrite( STDERR, 'FAIL ' . $failure->getMessage() . "\n" );
	exit( 1 );
}
echo "PASS $checks authentication checks: optional 30-day CRM login, unchanged ordinary logins, guest and capability checks, valid nonce renewal, bounded cookies, and logout revocation; temporary user removed.\n";
