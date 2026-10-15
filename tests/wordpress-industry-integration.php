<?php

/**
 * Temporary-data integration test for hierarchy management and REST validation.
 * Set TRADE_CRM_WP_ROOT to the WordPress directory before running.
 */
$wordpress_root = getenv( 'TRADE_CRM_WP_ROOT' );
if ( ! $wordpress_root || ! is_file( rtrim( $wordpress_root, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php' ) ) {
	fwrite( STDERR, "Set TRADE_CRM_WP_ROOT to an installed WordPress directory.\n" );
	exit( 2 );
}

require_once rtrim( $wordpress_root, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php';
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $administrators ) {
	fwrite( STDERR, "An administrator account is required for this integration test.\n" );
	exit( 2 );
}
wp_set_current_user( $administrators[0]->ID );
$server = rest_get_server();
$request = static function ( string $method, string $route, array $json = array() ) use ( $server ) {
	$rest_request = new WP_REST_Request( $method, $route );
	if ( $json ) {
		$rest_request->set_header( 'Content-Type', 'application/json' );
		$rest_request->set_body( wp_json_encode( $json ) );
	}
	return $server->dispatch( $rest_request );
};
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$created = array();
$temporary_contact_id = 0;
$base_name = 'آزمون ' . wp_generate_uuid4();
try {
	$root_response = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => $base_name ) );
	$assert( 201 === $root_response->get_status(), 'A custom root industry must be created.' );
	$root = $root_response->get_data()['item'];
	$created[] = (int) $root['id'];
	$child_response = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => 'بخش آزمایش', 'parent_id' => $root['id'] ) );
	$assert( 201 === $child_response->get_status(), 'A child industry must be created.' );
	$child = $child_response->get_data()['item'];
	$created[] = (int) $child['id'];
	$duplicate = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => 'بخش آزمایش', 'parent_id' => $root['id'] ) );
	$assert( 409 === $duplicate->get_status(), 'Duplicate sibling names must be rejected.' );
	$grandchild_response = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => 'ریزآزمایش', 'parent_id' => $child['id'] ) );
	$assert( 201 === $grandchild_response->get_status(), 'A third-level industry must be allowed.' );
	$grandchild = $grandchild_response->get_data()['item'];
	$created[] = (int) $grandchild['id'];
	$too_deep = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => 'سطح چهارم', 'parent_id' => $grandchild['id'] ) );
	$assert( 400 === $too_deep->get_status(), 'A fourth level must be rejected.' );
	$other_root_response = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => 'ریشه دوم ' . wp_generate_uuid4() ) );
	$assert( 201 === $other_root_response->get_status(), 'A second root industry must be created.' );
	$other_root = $other_root_response->get_data()['item'];
	$created[] = (int) $other_root['id'];
	$moved = $request( 'PATCH', '/trade-crm/v1/industries/' . $child['id'], array( 'parent_id' => $other_root['id'] ) );
	$assert( 200 === $moved->get_status() && (int) $moved->get_data()['parent_id'] === (int) $other_root['id'], 'A valid subtree move must be accepted.' );
	$assert( str_starts_with( $moved->get_data()['path_label'], $other_root['name'] . ' / ' ), 'Moving a subtree must update its displayed path.' );
	$active_root_response = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => 'حوزه فعال ' . wp_generate_uuid4() ) );
	$assert( 201 === $active_root_response->get_status(), 'A separate active category must be created.' );
	$active_root = $active_root_response->get_data()['item'];
	$created[] = (int) $active_root['id'];
	$phone = '';
	for ( $attempt = 0; $attempt < 20 && '' === $phone; $attempt++ ) {
		$candidate = '+1202555' . str_pad( (string) random_int( 1000, 9999 ), 4, '0', STR_PAD_LEFT );
		$normalized = TradeCRM\PhoneNumbers::normalize( $candidate, 'US' );
		if ( ! is_wp_error( $normalized ) && ! ( new TradeCRM\Repository() )->find_duplicate( $normalized['number_e164'] ) ) {
			$phone = $candidate;
		}
	}
	$assert( '' !== $phone, 'A unique temporary test number must be available.' );
	$missing_industry = $request( 'POST', '/trade-crm/v1/contacts', array( 'first_name' => 'بدون', 'last_name' => 'حوزه ' . wp_generate_uuid4(), 'phone' => $phone, 'country_code' => 'US', 'request_id' => wp_generate_uuid4() ) );
	$assert( 400 === $missing_industry->get_status() && 'trade_crm_invalid_field' === $missing_industry->get_data()['code'], 'The API must reject a contact without an industry.' );
	$temp_contact = $request( 'POST', '/trade-crm/v1/contacts', array( 'first_name' => 'مخاطب', 'last_name' => 'آزمایشی ' . wp_generate_uuid4(), 'phone' => $phone, 'country_code' => 'US', 'industry_ids' => array( $child['id'], $active_root['id'] ), 'request_id' => wp_generate_uuid4() ) );
	$assert( 201 === $temp_contact->get_status(), 'A temporary contact with a child industry must be created.' );
	$temporary_contact_id = (int) $temp_contact->get_data()['contact']['id'];
	$filtered = ( new TradeCRM\Repository() )->list_contacts( array( 'archive' => 'active', 'limit' => 100, 'cursor' => '', 'industry_id' => (int) $other_root['id'] ) );
	$filtered_ids = array_map( 'intval', array_column( $filtered['items'], 'id' ) );
	$assert( 1 === count( array_keys( $filtered_ids, $temporary_contact_id, true ) ), 'Filtering a parent industry must include a descendant contact exactly once.' );
	$depth_root_response = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => 'ریشه عمق ' . wp_generate_uuid4() ) );
	$assert( 201 === $depth_root_response->get_status(), 'A temporary root for depth validation must be created.' );
	$depth_root = $depth_root_response->get_data()['item'];
	$created[] = (int) $depth_root['id'];
	$depth_parent_response = $request( 'POST', '/trade-crm/v1/industries', array( 'name' => 'والد عمق', 'parent_id' => $depth_root['id'] ) );
	$assert( 201 === $depth_parent_response->get_status(), 'A temporary second-level parent must be created.' );
	$depth_parent = $depth_parent_response->get_data()['item'];
	$created[] = (int) $depth_parent['id'];
	$overflow = $request( 'PATCH', '/trade-crm/v1/industries/' . $other_root['id'], array( 'parent_id' => $depth_parent['id'] ) );
	$assert( 400 === $overflow->get_status(), 'Moving a subtree beyond the depth limit must be rejected.' );
	$cycle = $request( 'PATCH', '/trade-crm/v1/industries/' . $other_root['id'], array( 'parent_id' => $grandchild['id'] ) );
	$assert( 400 === $cycle->get_status(), 'Moving an ancestor beneath its descendant must be rejected.' );
	$deactivated = $request( 'PATCH', '/trade-crm/v1/industries/' . $other_root['id'], array( 'is_active' => false ) );
	$assert( 200 === $deactivated->get_status(), 'A root category must be deactivated.' );
	$assert( 0 === (int) $deactivated->get_data()['parent_id'], 'Changing only active state must keep the parent unchanged.' );
	$child_state = $request( 'GET', '/trade-crm/v1/industries/' . $child['id'] );
	$assert( false === $child_state->get_data()['is_selectable'], 'A child beneath a disabled root must not be selectable.' );
	$saved_contact = $request( 'GET', '/trade-crm/v1/contacts/' . $temporary_contact_id )->get_data();
	$contact_update = array( 'first_name' => $saved_contact['first_name'], 'last_name' => $saved_contact['last_name'], 'phone' => $saved_contact['numbers'][0]['number_e164'], 'country_code' => 'US', 'status' => $saved_contact['status'], 'industry_ids' => array( $child['id'], $active_root['id'] ), 'version' => $saved_contact['version'] );
	$preserved = $request( 'PATCH', '/trade-crm/v1/contacts/' . $temporary_contact_id, $contact_update );
	$preserved_industries = 200 === $preserved->get_status() ? $preserved->get_data()['industries'] : array();
	$inactive_preserved = array_filter( $preserved_industries, static fn ( array $item ): bool => (int) $item['id'] === (int) $child['id'] && ! $item['is_selectable'] );
	$assert( 2 === count( $preserved_industries ) && 1 === count( $inactive_preserved ), 'An existing inactive industry association must be preserved alongside active choices.' );
	$contact_update['version'] = $preserved->get_data()['version'];
	$contact_update['industry_ids'] = array( $active_root['id'] );
	$removed = $request( 'PATCH', '/trade-crm/v1/contacts/' . $temporary_contact_id, $contact_update );
	$assert( 200 === $removed->get_status(), 'An inactive association may be removed.' );
	$contact_update['version'] = $removed->get_data()['version'];
	$contact_update['industry_ids'] = array();
	$empty_categories = $request( 'PATCH', '/trade-crm/v1/contacts/' . $temporary_contact_id, $contact_update );
	$assert( 400 === $empty_categories->get_status(), 'The API must reject removing the last industry from a contact.' );
	$contact_update['industry_ids'] = array( $active_root['id'], $child['id'] );
	$reassigned = $request( 'PATCH', '/trade-crm/v1/contacts/' . $temporary_contact_id, $contact_update );
	$assert( 400 === $reassigned->get_status(), 'A new assignment to an inactive industry must be rejected by the API.' );
	$blocked_delete = $request( 'DELETE', '/trade-crm/v1/industries/' . $other_root['id'] );
	$assert( 409 === $blocked_delete->get_status(), 'A category with children must not be permanently deleted.' );
	echo "PASS industry CRUD, sibling uniqueness, subtree moves, descendant filtering, inactive-assignment rules, depth and cycle prevention, and delete protection\n";
} finally {
	if ( $temporary_contact_id > 0 ) {
		global $wpdb;
		$tables = TradeCRM\Tables::names();
		foreach ( array( 'activities', 'numbers', 'contact_industries' ) as $table_key ) {
			$wpdb->delete( $tables[ $table_key ], array( 'contact_id' => $temporary_contact_id ), array( '%d' ) );
		}
		$wpdb->delete( $tables['contacts'], array( 'id' => $temporary_contact_id ), array( '%d' ) );
	}
	if ( isset( $child, $root ) ) {
		$request( 'PATCH', '/trade-crm/v1/industries/' . $child['id'], array( 'parent_id' => $root['id'] ) );
	}
	foreach ( array_reverse( $created ) as $industry_id ) {
		$request( 'DELETE', '/trade-crm/v1/industries/' . $industry_id );
	}
}
