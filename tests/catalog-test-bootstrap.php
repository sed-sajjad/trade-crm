<?php
/**
 * Isolated WordPress integration bootstrap. Never accepts the site's normal DB.
 * Required: TRADE_CRM_WP_ROOT and TRADE_CRM_TEST_DB_NAME=trade_crm_catalog_test_*.
 * Reads local credentials without printing them; does not edit WordPress config.
 */
$catalog_wp_root = rtrim( (string) getenv( 'TRADE_CRM_WP_ROOT' ), '/\\' );
$catalog_test_database = (string) getenv( 'TRADE_CRM_TEST_DB_NAME' );
if ( ! preg_match( '/^trade_crm_catalog_test_[a-z0-9_]+$/D', $catalog_test_database ) || ! is_file( $catalog_wp_root . '/wp-config.php' ) ) {
	fwrite( STDERR, "Set TRADE_CRM_WP_ROOT and a dedicated TRADE_CRM_TEST_DB_NAME beginning trade_crm_catalog_test_.\n" );
	exit( 2 );
}
$catalog_configuration = file_get_contents( $catalog_wp_root . '/wp-config.php' );
// Limit replacement to the single standard literal DB_NAME declaration.
$catalog_configuration = preg_replace(
	'/define\s*\(\s*([\'\"])DB_NAME\1\s*,\s*([\'\"])(?:\\\\.|(?!\2).)*\2\s*\)\s*;/s',
	'define( \'DB_NAME\', ' . var_export( $catalog_test_database, true ) . ' );',
	$catalog_configuration,
	-1,
	$catalog_replacements
);
if ( 1 !== $catalog_replacements || ! is_string( $catalog_configuration ) ) {
	fwrite( STDERR, "A single standard DB_NAME definition is required; bootstrap refused.\n" );
	exit( 2 );
}
define( 'ABSPATH', $catalog_wp_root . '/' );
define( 'WP_INSTALLING', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/trade-crm/';
$_SERVER['SCRIPT_NAME'] = '/trade-crm/index.php';
// The file is trusted local configuration. Its DB_NAME is replaced in memory only.
eval( '?>' . $catalog_configuration );
unset( $catalog_configuration );
if ( DB_NAME !== $catalog_test_database || $GLOBALS['wpdb']->get_var( 'SELECT DATABASE()' ) !== $catalog_test_database ) {
	fwrite( STDERR, "Isolation check failed; no tests were run.\n" );
	exit( 2 );
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) {
	$catalog_install_result = wp_install( 'CRM catalogue integration', 'catalog_test_admin', 'catalog-test@example.test', false, '', wp_generate_password( 32 ), 'fa_IR' );
	if ( is_wp_error( $catalog_install_result ) ) {
		throw new RuntimeException( 'Could not initialize isolated WordPress test database.' );
	}
}
if ( ! class_exists( '\TradeCRM\Plugin' ) ) {
	require dirname( __DIR__ ) . '/trade-crm.php';
	( new \TradeCRM\Plugin() )->boot();
}
$catalog_administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $catalog_administrators ) {
	throw new RuntimeException( 'Isolated test database needs a WordPress administrator.' );
}
get_role( 'administrator' )->add_cap( 'manage_trade_crm' );
wp_set_current_user( $catalog_administrators[0]->ID );
