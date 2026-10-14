<?php

/**
 * Read-only integration smoke test against an existing, activated WordPress site.
 * Set TRADE_CRM_WP_ROOT to the directory containing wp-load.php before running.
 */

$wordpress_root = getenv( 'TRADE_CRM_WP_ROOT' );
if ( ! $wordpress_root || ! is_file( rtrim( $wordpress_root, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php' ) ) {
	fwrite( STDERR, "Set TRADE_CRM_WP_ROOT to an installed WordPress directory.\n" );
	exit( 2 );
}

require_once rtrim( $wordpress_root, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( is_plugin_active( 'trade-crm/trade-crm.php' ), 'The CRM plugin must be active.' );
$assert( '1.1.0' === get_option( 'trade_crm_db_version' ), 'The CRM schema version was not installed.' );
foreach ( \TradeCRM\Tables::names() as $table ) {
	$found = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $GLOBALS['wpdb']->esc_like( $table ) ) );
	$assert( $found === $table, 'A CRM database table is missing.' );
}

$server = rest_get_server();
wp_set_current_user( 0 );
$guest_request = new WP_REST_Request( 'GET', '/trade-crm/v1/bootstrap' );
$guest_response = $server->dispatch( $guest_request );
$assert( 401 === $guest_response->get_status(), 'Guests must receive HTTP 401 from private routes.' );

$administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$assert( ! empty( $administrators ), 'An existing WordPress administrator is required for the authenticated smoke test.' );
wp_set_current_user( $administrators[0]->ID );
$bootstrap_request = new WP_REST_Request( 'GET', '/trade-crm/v1/bootstrap' );
$bootstrap_response = $server->dispatch( $bootstrap_request );
$assert( 200 === $bootstrap_response->get_status(), 'An administrator must be able to load CRM bootstrap data.' );
$bootstrap_data = $bootstrap_response->get_data();
$assert( in_array( 'IR', $bootstrap_data['phone_regions'] ?? array(), true ) && count( $bootstrap_data['phone_regions'] ?? array() ) >= 200, 'Bootstrap must provide the full international phone region list.' );
$industry_request = new WP_REST_Request( 'GET', '/trade-crm/v1/industries' );
$industry_request->set_param( 'q', 'پسته' );
$industry_request->set_param( 'scope', 'selectable' );
$industry_response = $server->dispatch( $industry_request );
$assert( 200 === $industry_response->get_status(), 'An administrator must be able to search the activity catalog.' );
$industry_items = $industry_response->get_data()['items'] ?? array();
$assert( count( $industry_items ) === 1 && 'مواد غذایی و نوشیدنی / خشکبار و آجیل / پسته' === $industry_items[0]['path_label'], 'Industry search must return the full three-level path.' );
$assert( ! array_key_exists( 'industries', $bootstrap_data ), 'The full activity catalog must be loaded through its paginated endpoint.' );
$contacts_request = new WP_REST_Request( 'GET', '/trade-crm/v1/contacts' );
$contacts_request->set_param( 'limit', 1 );
$contacts_response = $server->dispatch( $contacts_request );
$assert( 200 === $contacts_response->get_status(), 'An administrator must be able to read the contact list.' );
$private_response = apply_filters( 'rest_post_dispatch', $contacts_response, $server, $contacts_request );
$assert( str_contains( (string) ( $private_response->get_headers()['Cache-Control'] ?? '' ), 'no-store' ), 'Private CRM responses must disable caching.' );

$tables = \TradeCRM\Tables::names();
$contact_count = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$tables['contacts']}" );
printf( "PASS active plugin and schema version %s\n", get_option( 'trade_crm_db_version' ) );
printf( "PASS all %d CRM tables exist\n", count( $tables ) );
printf( "PASS guest denied; administrator bootstrap and contact list return HTTP 200\n" );
printf( "PASS bootstrap provides %d phone regions\n", count( $bootstrap_data['phone_regions'] ) );
printf( "PASS industry search returns the seeded three-level path\n" );
printf( "PASS private REST cache policy; existing contact count %d (read only)\n", $contact_count );
