<?php
/** Lifecycle regression tests. Creates/deletes local fixtures; never run on production. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'TRADE_CRM_ALLOW_LOCAL_FIXTURES' ) || ! getenv( 'TRADE_CRM_WP_ROOT' ) ) {
	fwrite( STDERR, "Set TRADE_CRM_WP_ROOT and TRADE_CRM_ALLOW_LOCAL_FIXTURES=1 for a local test installation.\n" );
	exit( 2 );
}
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
require rtrim( getenv( 'TRADE_CRM_WP_ROOT' ), '/\\' ) . '/wp-load.php';
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'This test only permits a localhost WordPress installation.' );
}

$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
	echo 'PASS ' . $message . PHP_EOL;
};
$snapshot_data = static function (): array {
	global $wpdb;
	$out = array();
	foreach ( \TradeCRM\Tables::names() as $name => $table ) {
		$rows = array_map( 'wp_json_encode', $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) );
		sort( $rows );
		$out[$name] = hash( 'sha256', implode( "\n", $rows ) );
	}
	return $out;
};
$options = array();
foreach ( array( 'home', 'siteurl', 'permalink_structure', 'blog_public', 'show_on_front', 'page_on_front', 'page_for_posts', 'active_plugins', 'rewrite_rules', 'trade_crm_plugin_version', 'trade_crm_db_version' ) as $name ) {
	$options[$name] = get_option( $name );
}
$before_data = $snapshot_data();
$htaccess = ABSPATH . '.htaccess';
$htaccess_hash = is_file( $htaccess ) ? hash_file( 'sha256', $htaccess ) : null;
$original_top = $wp_rewrite->extra_rules_top;
$original_extra = $wp_rewrite->extra_rules;
$fixtures = array();
$die_handler = static fn () => static function ( $message ) { throw new RuntimeException( (string) $message ); };

try {
	$assert( '' === \TradeCRM\Plugin::route_conflict(), 'existing local routes do not conflict' );
	// A page at /crm must stop activation before migrations or role changes.
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'CRM collision fixture', 'post_name' => 'crm', 'post_status' => 'publish' ), true );
	if ( is_wp_error( $page ) ) { throw new RuntimeException( $page->get_error_message() ); }
	$fixtures[] = $page;
	add_filter( 'wp_die_handler', $die_handler );
	$blocked = false;
	try { \TradeCRM\Plugin::activate(); } catch ( RuntimeException $error ) { $blocked = str_contains( $error->getMessage(), '/crm' ); }
	remove_filter( 'wp_die_handler', $die_handler );
	$assert( $blocked, 'activation refuses an existing CRM page' );
	$assert( $snapshot_data() === $before_data, 'blocked activation leaves all CRM data unchanged' );
	wp_delete_post( $page, true );
	$fixtures = array();

	$post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'CRM post collision fixture', 'post_name' => 'crm', 'post_status' => 'publish' ), true );
	if ( is_wp_error( $post ) ) { throw new RuntimeException( $post->get_error_message() ); }
	$fixtures[] = $post;
	$assert( '' !== \TradeCRM\Plugin::route_conflict(), 'activation detects a post hidden by an old CRM rewrite rule' );
	wp_delete_post( $post, true );
	$fixtures = array();

	$wp_rewrite->extra_rules_top['^crm/?$'] = 'index.php?another_plugin=1';
	\TradeCRM\App::add_rewrite_rules();
	$assert( 'index.php?another_plugin=1' === $wp_rewrite->extra_rules_top['^crm/?$'] && '' !== \TradeCRM\Plugin::route_conflict(), 'another plugin retains its matching route and activation detects the conflict' );
	$wp_rewrite->extra_rules_top = $original_top;
	add_rewrite_rule( '^unrelated-fixture/?$', 'index.php?unrelated_fixture=1', 'top' );
	\TradeCRM\Plugin::deactivate();
	$rules = get_option( 'rewrite_rules' );
	$assert( ! str_contains( wp_json_encode( $rules ), 'trade_crm_app' ) && ! str_contains( wp_json_encode( $rules ), 'trade_crm_manifest' ) && ! str_contains( wp_json_encode( $rules ), 'trade_crm_sw' ), 'deactivation removes only CRM routes' );
	$assert( isset( $rules['^unrelated-fixture/?$'] ), 'deactivation preserves unrelated plugin routes' );
	$assert( $snapshot_data() === $before_data, 'deactivation preserves CRM records' );
	\TradeCRM\Plugin::activate();
	$assert( isset( get_option( 'rewrite_rules' )['^crm/?$'] ), 'reactivation restores the CRM route' );
	$assert( $snapshot_data() === $before_data, 'reactivation preserves every CRM record and relationship' );
	foreach ( $options as $name => $value ) {
		if ( in_array( $name, array( 'rewrite_rules', 'trade_crm_plugin_version' ), true ) ) { continue; }
		$assert( $value === get_option( $name ), 'lifecycle preserves option ' . $name );
	}
	$assert( $htaccess_hash === ( is_file( $htaccess ) ? hash_file( 'sha256', $htaccess ) : null ), 'activation and deactivation do not edit .htaccess' );
	$plugin = new \TradeCRM\Plugin();
	$redirect = 'https://example.test/account/';
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$assert( $redirect === apply_filters( 'login_redirect', $redirect, $redirect, $admins[0] ), 'ordinary login destination stays unchanged' );
	$assert( 'Original login' === apply_filters( 'login_headertext', 'Original login' ), 'ordinary login branding stays unchanged' );
	$public = new WP_REST_Response( array( 'ok' => true ) );
	$public->header( 'Cache-Control', 'public, max-age=120' );
	$public->header( 'Vary', 'Origin' );
	$response = \TradeCRM\Rest::instance()->no_store_private_responses( $public, null, new WP_REST_Request( 'GET', '/wp/v2/types' ) );
	$assert( $response->get_headers() === array( 'Cache-Control' => 'public, max-age=120', 'Vary' => 'Origin' ), 'public REST headers are untouched' );
	$private = \TradeCRM\Rest::instance()->no_store_private_responses( $public, null, new WP_REST_Request( 'GET', '/trade-crm/v1/bootstrap' ) );
	$assert( str_contains( $private->get_headers()['Vary'], 'Origin' ) && str_contains( $private->get_headers()['Vary'], 'Cookie' ), 'private API preserves existing Vary headers' );
} finally {
	remove_filter( 'wp_die_handler', $die_handler );
	foreach ( $fixtures as $id ) { wp_delete_post( $id, true ); }
	$wp_rewrite->extra_rules_top = $original_top;
	$wp_rewrite->extra_rules = $original_extra;
	foreach ( array( 'rewrite_rules', 'trade_crm_plugin_version' ) as $name ) {
		if ( false === $options[$name] ) { delete_option( $name ); } else { update_option( $name, $options[$name] ); }
	}
}
echo "All {$checks} lifecycle/isolation checks passed; fixtures cleaned up.\n";
