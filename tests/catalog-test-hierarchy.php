<?php
/** Run the existing hierarchy integration suite against the guarded isolated DB. */
require __DIR__ . '/catalog-test-bootstrap.php';

// The older suite expects to require wp-load.php itself. Provide an empty loader
// after booting WordPress above, so it cannot load the normal site's wp-config.
$catalog_original_root = (string) getenv( 'TRADE_CRM_WP_ROOT' );
$catalog_loader_root = sys_get_temp_dir() . '/trade-crm-catalog-loader-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $catalog_loader_root ) || false === file_put_contents( $catalog_loader_root . '/wp-load.php', "<?php\n// Isolated WordPress was loaded by catalog-test-bootstrap.php.\n" ) ) {
	throw new RuntimeException( 'Could not prepare the isolated legacy-test loader.' );
}
putenv( 'TRADE_CRM_WP_ROOT=' . $catalog_loader_root );
try {
	require __DIR__ . '/wordpress-industry-integration.php';
} finally {
	putenv( 'TRADE_CRM_WP_ROOT=' . $catalog_original_root );
	unlink( $catalog_loader_root . '/wp-load.php' );
	rmdir( $catalog_loader_root );
}
