<?php

/** Temporary-data integration test. Set TRADE_CRM_WP_ROOT before running. */
$wordpress_root = getenv( 'TRADE_CRM_WP_ROOT' );
if ( ! $wordpress_root || ! is_file( rtrim( $wordpress_root, '/\\' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set TRADE_CRM_WP_ROOT to an installed WordPress directory.\n" );
	exit( 2 );
}
require_once rtrim( $wordpress_root, '/\\' ) . '/wp-load.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$assert( ! empty( $admins ), 'An administrator is required.' );
wp_set_current_user( $admins[0]->ID );
$server = rest_get_server();
$request = static function ( string $method, string $route, array $body = array() ) use ( $server ) {
	$req = new WP_REST_Request( $method, '/trade-crm/v1/' . $route );
	if ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return $server->dispatch( $req );
};
$tables = TradeCRM\Tables::names();
$contact_id = 0;
try {
	$industry_id = (int) $wpdb->get_var( "SELECT id FROM {$tables['industries']} WHERE is_active=1 AND parent_id=0 LIMIT 1" );
	$assert( $industry_id > 0, 'An active industry is required.' );
	$phone = '';
	for ( $attempt = 0; $attempt < 20 && '' === $phone; $attempt++ ) {
		$candidate = '+1202555' . random_int( 1000, 9999 );
		if ( ! ( new TradeCRM\Repository() )->find_duplicate( $candidate ) ) {
			$phone = $candidate;
		}
	}
	$assert( '' !== $phone, 'A temporary unique phone number is required.' );
	$body = array( 'first_name' => 'Website', 'last_name' => 'integration test ' . wp_generate_uuid4(), 'name' => 'عنوانی که نباید ذخیره شود', 'whatsapp' => $phone, 'country_code' => 'US', 'industry_ids' => array( $industry_id ), 'website' => 'website-test.example.com/catalog?lang=fa', 'request_id' => wp_generate_uuid4() );
	$missing_first_name = $body;
	unset( $missing_first_name['first_name'] );
	$missing_first_name['request_id'] = wp_generate_uuid4();
	$missing_first_name_response = $request( 'POST', 'contacts', $missing_first_name );
	$assert( 400 === $missing_first_name_response->get_status() && 'first_name' === ( $missing_first_name_response->get_data()['data']['field'] ?? '' ), 'Contact creation must require a first name.' );
	$missing_last_name = $body;
	unset( $missing_last_name['last_name'] );
	$missing_last_name['request_id'] = wp_generate_uuid4();
	$missing_last_name_response = $request( 'POST', 'contacts', $missing_last_name );
	$assert( 400 === $missing_last_name_response->get_status() && 'last_name' === ( $missing_last_name_response->get_data()['data']['field'] ?? '' ), 'Contact creation must require a last name.' );
	$long_first_name = $body;
	$long_first_name['first_name'] = str_repeat( 'ا', 101 );
	$long_first_name['request_id'] = wp_generate_uuid4();
	$long_first_name_response = $request( 'POST', 'contacts', $long_first_name );
	$assert( 400 === $long_first_name_response->get_status() && 'first_name' === ( $long_first_name_response->get_data()['data']['field'] ?? '' ), 'A first name longer than 100 characters must be rejected.' );
	$long_combined_name = $body;
	$long_combined_name['first_name'] = str_repeat( 'ا', 100 );
	$long_combined_name['last_name'] = str_repeat( 'ب', 91 );
	$long_combined_name['request_id'] = wp_generate_uuid4();
	$long_combined_response = $request( 'POST', 'contacts', $long_combined_name );
	$assert( 400 === $long_combined_response->get_status() && 'last_name' === ( $long_combined_response->get_data()['data']['field'] ?? '' ), 'A combined name longer than 190 characters must be rejected on the last-name field.' );
	$created = $request( 'POST', 'contacts', $body );
	$assert( 201 === $created->get_status(), 'Contact creation failed.' );
	$contact = $created->get_data()['contact'];
	$contact_id = (int) $contact['id'];
	$expected_name = $body['first_name'] . ' ' . $body['last_name'];
	$assert( $expected_name === $contact['name'], 'The display name must be derived from first and last name, ignoring a supplied name.' );
	$assert( 1 === count( $contact['numbers'] ) && $contact['numbers'][0]['is_whatsapp'] && ! $contact['numbers'][0]['is_phone'], 'A WhatsApp number alone must be enough to create a contact.' );
	$assert( 'https://website-test.example.com/catalog?lang=fa' === $contact['website'], 'A bare domain must be saved with HTTPS.' );
	$loaded = $request( 'GET', 'contacts/' . $contact_id );
	$assert( $contact['website'] === $loaded->get_data()['website'], 'The website must persist when the contact is loaded again.' );
	$search = new WP_REST_Request( 'GET', '/trade-crm/v1/contacts' );
	$search->set_param( 'q', 'website-test.example.com' );
	$assert( in_array( $contact_id, array_column( $server->dispatch( $search )->get_data()['items'], 'id' ), true ), 'Website search must find the saved contact.' );
	$body['version'] = $contact['version'];
	unset( $body['website'] );
	$preserved = $request( 'PATCH', 'contacts/' . $contact_id, $body );
	$assert( 200 === $preserved->get_status() && $contact['website'] === $preserved->get_data()['website'], 'Older clients must preserve an omitted website.' );
	$missing_edit_name = $body;
	unset( $missing_edit_name['first_name'] );
	$missing_edit_name['version'] = $preserved->get_data()['version'];
	$missing_edit_response = $request( 'PATCH', 'contacts/' . $contact_id, $missing_edit_name );
	$assert( 400 === $missing_edit_response->get_status() && 'first_name' === ( $missing_edit_response->get_data()['data']['field'] ?? '' ), 'Contact edits must require a first name.' );
	$missing_edit_last_name = $body;
	unset( $missing_edit_last_name['last_name'] );
	$missing_edit_last_name['version'] = $preserved->get_data()['version'];
	$missing_edit_last_name_response = $request( 'PATCH', 'contacts/' . $contact_id, $missing_edit_last_name );
	$assert( 400 === $missing_edit_last_name_response->get_status() && 'last_name' === ( $missing_edit_last_name_response->get_data()['data']['field'] ?? '' ), 'Contact edits must require a last name.' );
	$body['version'] = $preserved->get_data()['version'];
	$body['first_name'] = 'علی';
	$body['last_name'] = 'رضایی';
	$body['name'] = 'عنوان قدیمی';
	$body['website'] = 'http://example.org/contact';
	$updated = $request( 'PATCH', 'contacts/' . $contact_id, $body );
	$assert( 200 === $updated->get_status() && $body['website'] === $updated->get_data()['website'] && 'علی رضایی' === $updated->get_data()['name'], 'Website and derived-name changes must be saved.' );
	$search->set_param( 'q', 'علی رضایی' );
	$assert( in_array( $contact_id, array_column( $server->dispatch( $search )->get_data()['items'], 'id' ), true ), 'Search must find the generated display name.' );
	$activities = $request( 'GET', 'contacts/' . $contact_id . '/activities' )->get_data()['items'];
	$assert( (bool) array_filter( $activities, static fn ( $activity ) => 'contact.updated' === $activity['type'] && isset( $activity['data']['website'] ) ), 'Website changes must appear in contact history.' );
	$body['version'] = $updated->get_data()['version'];
	foreach ( array( 'javascript:alert(1)', 'ftp://example.com', 'https://', 'not a website', 'https://user:pass@example.com', str_repeat( 'a', 2049 ) . '.com', array( 'example.com' ) ) as $invalid ) {
		$body['website'] = $invalid;
		$response = $request( 'PATCH', 'contacts/' . $contact_id, $body );
		$assert( 400 === $response->get_status() && 'website' === ( $response->get_data()['data']['field'] ?? '' ), 'Invalid websites must produce a website field error.' );
	}
	$body['website'] = '';
	$cleared = $request( 'PATCH', 'contacts/' . $contact_id, $body );
	$assert( 200 === $cleared->get_status() && '' === $cleared->get_data()['website'], 'An optional website must be removable.' );
	echo "PASS website creation, normalization, persistence, search, updates, history, omitted-field compatibility, validation, and clearing.\n";
} finally {
	if ( $contact_id > 0 ) {
		foreach ( array( 'activities', 'numbers', 'contact_industries' ) as $key ) {
			$wpdb->delete( $tables[ $key ], array( 'contact_id' => $contact_id ), array( '%d' ) );
		}
		$wpdb->delete( $tables['contacts'], array( 'id' => $contact_id ), array( '%d' ) );
	}
}
