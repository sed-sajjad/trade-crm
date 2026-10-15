<?php
/**
 * Destructive ONLY inside the guarded, disposable catalogue test database.
 * Run with TRADE_CRM_WP_ROOT and TRADE_CRM_TEST_DB_NAME (see bootstrap).
 * Covers fresh install, catalogue-only upgrades, customization, rollback and races.
 */
require __DIR__ . '/catalog-test-bootstrap.php';

use TradeCRM\IndustryCatalog;
use TradeCRM\Normalizer;
use TradeCRM\Repository;
use TradeCRM\Tables;

if ( in_array( '--worker', $argv ?? array(), true ) ) {
	echo "READY\n";
	fflush( STDOUT );
	$catalog_worker_result = IndustryCatalog::maybe_upgrade();
	echo $catalog_worker_result ? "PASS worker\n" : "FAIL worker\n";
	exit( $catalog_worker_result ? 0 : 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$assert( Tables::install(), 'Isolated schema installation failed.' );
$tables = Tables::names();
$catalog_fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/industry-catalog-v1.json' ), true, 512, JSON_THROW_ON_ERROR )['entries'];
$definitions = IndustryCatalog::definitions();
$keys_by_name = array();
foreach ( $definitions as $definition ) {
	$keys_by_name[ $definition['name'] ] = $definition['key'];
}
$reset = static function () use ( $tables, $wpdb ): void {
	foreach ( $tables as $table ) {
		$wpdb->query( "DELETE FROM {$table}" );
	}
	foreach ( array( 'trade_crm_industry_seeded_keys', 'trade_crm_industry_catalog_version', 'trade_crm_industry_catalog_report' ) as $option ) {
		delete_option( $option );
	}
	add_option( 'trade_crm_industry_catalog_lock', '1', '', false );
};
$baseline = static function () use ( $reset, $catalog_fixture, $wpdb, $tables, $assert ): array {
	$reset();
	$ids = array();
	foreach ( $catalog_fixture as $entry ) {
		$ok = $wpdb->insert( $tables['industries'], array(
			'parent_id' => $entry['parent_key'] ? $ids[ $entry['parent_key'] ] : 0,
			'name' => $entry['name'], 'name_normalized' => Normalizer::text( $entry['name'] ),
			'seed_key' => $entry['key'], 'is_active' => 1, 'created_at' => '2026-10-13 00:00:00',
		) );
		$assert( false !== $ok, 'Could not create v1 catalogue fixture.' );
		$ids[ $entry['key'] ] = (int) $wpdb->insert_id;
	}
	update_option( 'trade_crm_industry_seeded_keys', $ids, false );
	update_option( 'trade_crm_industry_catalog_version', '1', false );
	update_option( 'trade_crm_plugin_version', '1.1.4', false );
	update_option( 'trade_crm_db_version', '1.1.0', false );
	return $ids;
};
$row = static function ( string $key ) use ( $wpdb, $tables ): ?array {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['industries']} WHERE seed_key=%s", $key ), ARRAY_A );
};
$snapshot = static function ( bool $include_report = true ) use ( $wpdb, $tables ): string {
	$data = array();
	foreach ( $tables as $name => $table ) {
		$data[ $name ] = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY " . ( 'contact_industries' === $name ? 'contact_id,industry_id' : 'id' ), ARRAY_A );
	}
	foreach ( array( 'trade_crm_industry_seeded_keys', 'trade_crm_industry_catalog_version', 'trade_crm_industry_catalog_report' ) as $option ) {
		if ( ! $include_report && 'trade_crm_industry_catalog_report' === $option ) {
			continue;
		}
		// Read the database, independent of the process's option cache.
		$data[ $option ] = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $option ) );
	}
	return hash( 'sha256', wp_json_encode( $data ) );
};
$request = static function ( string $method, string $route, array $params = array() ): WP_REST_Response {
	$rest = new WP_REST_Request( $method, '/trade-crm/v1' . $route );
	if ( 'GET' === $method ) {
		$rest->set_query_params( $params );
	} else {
		$rest->set_header( 'Content-Type', 'application/json' );
		$rest->set_body( wp_json_encode( $params ) );
	}
	return rest_get_server()->dispatch( $rest );
};
$create_contact = static function ( array $industries, string $phone = '+12025550189' ) use ( $request, $assert ): int {
	$result = $request( 'POST', '/contacts', array( 'first_name' => 'آزمون', 'last_name' => 'کیوی', 'phone' => $phone, 'country_code' => 'US', 'industry_ids' => $industries, 'request_id' => wp_generate_uuid4() ) );
	$assert( 201 === $result->get_status(), 'Could not create isolated test contact.' );
	return (int) $result->get_data()['contact']['id'];
};
$check_tree = static function ( int $expected ) use ( $assert, $wpdb, $tables ): void {
	$rows = $wpdb->get_results( "SELECT id,parent_id,seed_key,name_normalized FROM {$tables['industries']}", ARRAY_A );
	$assert( $expected === count( $rows ), 'Unexpected catalogue size.' );
	$assert( 25 === count( array_filter( $rows, static fn ( array $row ): bool => 0 === (int) $row['parent_id'] ) ), 'Expected 25 catalogue roots.' );
	$by_id = array_column( $rows, null, 'id' );
	$seen_keys = array();
	$seen_names = array();
	foreach ( $rows as $item ) {
		$assert( ! isset( $seen_keys[ $item['seed_key'] ] ), 'Duplicate catalogue seed key.' );
		$seen_keys[ $item['seed_key'] ] = true;
		$sibling_key = $item['parent_id'] . ':' . $item['name_normalized'];
		$assert( ! isset( $seen_names[ $sibling_key ] ), 'Duplicate normalized sibling name.' );
		$seen_names[ $sibling_key ] = true;
		$depth = 1;
		$parent = (int) $item['parent_id'];
		while ( $parent ) {
			$assert( isset( $by_id[ $parent ] ), 'Missing catalogue parent.' );
			$depth++;
			$assert( $depth <= 3, 'Catalogue depth exceeds three or contains a cycle.' );
			$parent = (int) $by_id[ $parent ]['parent_id'];
		}
	}
};

try {
	$reset();
	$assert( IndustryCatalog::seed(), 'Fresh catalogue seed failed.' );
	$check_tree( 547 );
	$assert( '2' === (string) get_option( 'trade_crm_industry_catalog_version' ), 'Fresh seed must mark catalogue version 2.' );
	$before = $snapshot( false );
	$assert( IndustryCatalog::seed() && $before === $snapshot( false ), 'Forced reconciliation must be idempotent.' );
	echo "PASS fresh 547 nodes / 25 roots / depth <=3 / stable keys / forced rerun\n";

	$cursor = '';
	$paginated_ids = array();
	$pages = 0;
	do {
		$result = $request( 'GET', '/industries', array( 'limit' => 100, 'cursor' => $cursor ) );
		$assert( 200 === $result->get_status(), 'Catalogue pagination failed.' );
		$data = $result->get_data();
		$assert( count( $data['items'] ) <= 100, 'Catalogue page exceeded requested limit.' );
		$paginated_ids = array_merge( $paginated_ids, array_column( $data['items'], 'id' ) );
		$cursor = $data['next_cursor'];
		$pages++;
		$assert( $pages < 20, 'Catalogue pagination did not finish.' );
	} while ( $cursor );
	$assert( 547 === count( $paginated_ids ) && 547 === count( array_unique( $paginated_ids ) ) && 6 === $pages, 'Pagination must expose every catalogue row beyond 500 without duplicates.' );
	foreach ( array( 'کیوی', 'كيوي' ) as $query ) {
		$matches = $request( 'GET', '/industries', array( 'q' => $query ) )->get_data()['items'];
		$assert( 1 === count( $matches ) && 'مواد غذایی و نوشیدنی / میوه و سبزی تازه / کیوی' === $matches[0]['path_label'], 'Persian/Arabic Kiwi search or full path failed.' );
	}
	echo "PASS REST cursor pagination >500 and Persian/Arabic Kiwi path search\n";

	$ids = $baseline();
	$food_id = $ids['catalog-1:03'];
	$fruit_id = $ids['catalog-1:03:01'];
	$contact_id = $create_contact( array( $food_id, $fruit_id ) );
	$archived_contact_id = $create_contact( array( $fruit_id ), '+12025550190' );
	$wpdb->update( $tables['contacts'], array( 'archived_at' => '2026-10-14 00:00:00' ), array( 'id' => $archived_contact_id ) );
	$old_rows = $wpdb->get_results( "SELECT * FROM {$tables['industries']} ORDER BY id", ARRAY_A );
	$old_links = $wpdb->get_results( "SELECT * FROM {$tables['contact_industries']} ORDER BY contact_id,industry_id", ARRAY_A );
	$old_contacts = $wpdb->get_results( "SELECT * FROM {$tables['contacts']} ORDER BY id", ARRAY_A );
	$assert( false !== $wpdb->query( "ALTER TABLE {$tables['contacts']} DROP COLUMN website" ), 'Could not simulate the released schema without a website column.' );
	// Upgrade a released v1.1.4 installation to the current schema and catalogue.
	( new TradeCRM\Plugin() )->maybe_upgrade();
	$assert( '2' === (string) get_option( 'trade_crm_industry_catalog_version' ), 'The v1.1.4 catalogue did not upgrade.' );
	$assert( TRADE_CRM_DB_VERSION === get_option( 'trade_crm_db_version' ), 'The release must upgrade the database schema to the current version.' );
	foreach ( $old_rows as $old_row ) {
		$assert( $old_row === $row( $old_row['seed_key'] ), 'Upgrade changed a released node or identifier.' );
	}
	$assert( $old_links === $wpdb->get_results( "SELECT * FROM {$tables['contact_industries']} ORDER BY contact_id,industry_id", ARRAY_A ), 'Upgrade changed contact associations.' );
	$upgraded_contacts = $wpdb->get_results( "SELECT * FROM {$tables['contacts']} ORDER BY id", ARRAY_A );
	$assert( count( $old_contacts ) === count( $upgraded_contacts ), 'Upgrade changed the contact count.' );
	foreach ( $old_contacts as $index => $old_contact ) {
		foreach ( $old_contact as $field => $value ) {
			$assert( $value === $upgraded_contacts[ $index ][ $field ], 'Upgrade changed an existing contact field: ' . $field );
		}
	}
	$check_tree( 547 );
	$filtered = ( new Repository() )->list_contacts( array( 'archive' => 'active', 'limit' => 100, 'cursor' => '', 'industry_id' => $food_id ) );
	$assert( 1 === count( array_keys( array_map( 'intval', array_column( $filtered['items'], 'id' ) ), $contact_id, true ) ), 'Parent filtering must not duplicate a multi-associated contact.' );
	$archived = ( new Repository() )->list_contacts( array( 'archive' => 'archived', 'limit' => 100, 'cursor' => '', 'industry_id' => $food_id ) );
	$assert( 1 === count( array_keys( array_map( 'intval', array_column( $archived['items'], 'id' ) ), $archived_contact_id, true ) ), 'Archived contact associations must survive catalogue upgrade and remain filterable.' );
	echo "PASS v1 257-node upgrade preserves IDs, fields and associations; schema upgrades; parent filter deduplicates\n";

	$baseline();
	update_option( 'trade_crm_plugin_version', TRADE_CRM_VERSION, false );
	update_option( 'trade_crm_db_version', TRADE_CRM_DB_VERSION, false );
	( new TradeCRM\Plugin() )->maybe_upgrade();
	$assert( '2' === (string) get_option( 'trade_crm_industry_catalog_version' ), 'Stale catalogue must upgrade independently of current plugin/schema versions.' );
	$check_tree( 547 );
	echo "PASS catalogue-only admin upgrade with already-current plugin/schema versions\n";

	$ids = $baseline();
	$renamed_id = $ids['catalog-1:01:01'];
	$wpdb->update( $tables['industries'], array( 'name' => 'بذر ویژه کاربر', 'name_normalized' => Normalizer::text( 'بذر ویژه کاربر' ) ), array( 'id' => $renamed_id ) );
	$moved_id = $ids['catalog-1:01:02'];
	$wpdb->update( $tables['industries'], array( 'parent_id' => $ids['catalog-1:02'] ), array( 'id' => $moved_id ) );
	$inactive_id = $ids['catalog-1:03:01'];
	$wpdb->update( $tables['industries'], array( 'is_active' => 0 ), array( 'id' => $inactive_id ) );
	$deleted_parent_key = 'catalog-1:04:02';
	$wpdb->delete( $tables['industries'], array( 'id' => $ids[ $deleted_parent_key ] ) );
	$deleted_leaf_key = 'catalog-1:03:04:01';
	$wpdb->delete( $tables['industries'], array( 'id' => $ids[ $deleted_leaf_key ] ) );
	$deep_parent_key = 'catalog-1:05:01';
	$wpdb->update( $tables['industries'], array( 'parent_id' => $ids['catalog-1:06:01'] ), array( 'id' => $ids[ $deep_parent_key ] ) );
	$assert( IndustryCatalog::seed(), 'Customized catalogue upgrade failed.' );
	$assert( 'بذر ویژه کاربر' === $row( 'catalog-1:01:01' )['name'], 'Custom category rename was reset.' );
	$assert( $ids['catalog-1:02'] === (int) $row( 'catalog-1:01:02' )['parent_id'], 'Moved category parent was reset.' );
	$assert( $moved_id === (int) $row( $keys_by_name['کود آلی'] )['parent_id'], 'New child did not follow a moved parent identifier.' );
	$assert( $renamed_id === (int) $row( $keys_by_name['بذر گل'] )['parent_id'], 'New child did not follow a renamed parent identifier.' );
	$kiwi = $row( $keys_by_name['کیوی'] );
	$assert( $inactive_id === (int) $kiwi['parent_id'] && ! ( new Repository() )->get_industry( (int) $kiwi['id'] )['is_selectable'], 'Inactive parent must remain inactive and disable newly added descendants.' );
	$assert( null === $row( $deleted_parent_key ) && null === $row( $deleted_leaf_key ), 'Deleted released nodes were resurrected.' );
	$assert( null === $row( $keys_by_name['پارچه نخی'] ), 'Children of a deleted parent must be skipped.' );
	$assert( null === $row( $keys_by_name['یخچال و فریزر'] ), 'Children beneath an actual level-three parent must be skipped.' );
	$report = get_option( 'trade_crm_industry_catalog_report', array() );
	$assert( (int) ( $report['skipped_count'] ?? 0 ) >= 10 && isset( $report['skipped'][ $keys_by_name['پارچه نخی'] ], $report['skipped'][ $keys_by_name['یخچال و فریزر'] ] ), 'Skipped descendants must be recorded with count and key.' );
	$before = $snapshot( false );
	$assert( IndustryCatalog::seed() && $before === $snapshot( false ), 'Reconciliation must preserve customized nodes and tombstones.' );
	echo "PASS renamed/moved/inactive/deleted/depth-three parents; deleted leaf; skipped report; rerun\n";

	$ids = $baseline();
	$wpdb->insert( $tables['industries'], array( 'parent_id' => $ids['catalog-1:03:01'], 'name' => 'کیوی', 'name_normalized' => Normalizer::text( 'کیوی' ), 'is_active' => 0, 'created_at' => '2026-10-14 00:00:00' ) );
	$custom_kiwi_id = (int) $wpdb->insert_id;
	$assert( IndustryCatalog::seed(), 'Custom Kiwi adoption failed.' );
	$assert( $custom_kiwi_id === (int) $row( $keys_by_name['کیوی'] )['id'] && 0 === (int) $row( $keys_by_name['کیوی'] )['is_active'], 'Existing custom Kiwi must be reused with same ID and inactive state.' );
	$check_tree( 547 );
	echo "PASS existing custom Kiwi adoption retains ID and inactive state\n";

	foreach ( array( 'insert', 'adopt' ) as $failure_mode ) {
		$ids = $baseline();
		$custom_id = 0;
		if ( 'adopt' === $failure_mode ) {
			$wpdb->insert( $tables['industries'], array( 'parent_id' => $ids['catalog-1:03:01'], 'name' => 'کیوی', 'name_normalized' => Normalizer::text( 'کیوی' ), 'created_at' => '2026-10-14 00:00:00' ) );
			$custom_id = (int) $wpdb->insert_id;
		}
		$before = $snapshot();
		$injected = false;
		$fault = static function ( string $query ) use ( $failure_mode, $custom_id, $tables, &$injected ): string {
			$matches_table = str_contains( $query, $tables['industries'] );
			$matches_write = 'insert' === $failure_mode
				? (bool) preg_match( '/^INSERT\b/i', ltrim( $query ) ) && str_contains( $query, "'کود مایع'" )
				: (bool) preg_match( '/^UPDATE\b/i', ltrim( $query ) ) && str_contains( $query, 'seed_key' ) && (bool) preg_match( '/(?:`id`|\bid)\s*=\s*\'?' . $custom_id . '\b/', $query );
			if ( ! $injected && $matches_table && $matches_write ) {
				$injected = true;
				return 'INSERT INTO trade_crm_catalog_intentionally_missing_table VALUES (1)';
			}
			return $query;
		};
		$previous_suppression = $wpdb->suppress_errors( true );
		add_filter( 'query', $fault );
		try {
			$success = IndustryCatalog::seed();
		} finally {
			remove_filter( 'query', $fault );
			$wpdb->suppress_errors( $previous_suppression );
		}
		$assert( $injected && ! $success, 'Injected ' . $failure_mode . ' SQL failure must fail the upgrade.' );
		$assert( $before === $snapshot(), 'Injected ' . $failure_mode . ' failure must roll back all catalogue writes and version.' );
		$assert( '' !== IndustryCatalog::last_error(), 'SQL failure must leave an actionable error.' );
		$assert( IndustryCatalog::seed(), 'Retry after injected SQL failure must succeed.' );
		$check_tree( 547 );
	}
	echo "PASS injected INSERT and adoption UPDATE failures roll back fully; retries succeed\n";

	$baseline();
	$assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not begin concurrency lock.' );
	$wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", 'trade_crm_industry_catalog_lock' ) );
	$workers = array();
	try {
		for ( $index = 0; $index < 2; $index++ ) {
			$process = proc_open( array( PHP_BINARY, __FILE__, '--worker' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$assert( is_resource( $process ), 'Could not start concurrent PHP worker.' );
			fclose( $pipes[0] );
			stream_set_timeout( $pipes[1], 15 );
			$ready = fgets( $pipes[1] );
			$assert( "READY\n" === str_replace( "\r\n", "\n", (string) $ready ), 'Concurrent worker did not reach the catalogue upgrade.' );
			$workers[] = array( $process, $pipes );
		}
		$wpdb->query( 'COMMIT' );
		foreach ( $workers as [ $process, $pipes ] ) {
			$output = stream_get_contents( $pipes[1] );
			$errors = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$exit_code = proc_close( $process );
			$assert( 0 === $exit_code && str_contains( $output, 'PASS worker' ) && '' === trim( $errors ), 'Concurrent worker failed.' );
		}
		$workers = array();
	} finally {
		$wpdb->query( 'ROLLBACK' );
		foreach ( $workers as [ $process, $pipes ] ) {
			if ( is_resource( $process ) ) {
				proc_terminate( $process );
			}
		}
	}
	wp_cache_flush();
	$check_tree( 547 );
	$assert( '2' === (string) get_option( 'trade_crm_industry_catalog_version' ), 'Concurrent upgrades must commit catalogue version.' );
	echo "PASS two concurrent PHP upgrade processes serialize on shared Options lock without duplicates\n";
	echo "PASS catalogue integration complete in isolated database " . DB_NAME . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL ' . $error->getMessage() . "\n" );
	exit( 1 );
}
