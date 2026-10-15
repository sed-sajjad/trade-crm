<?php
/**
 * Repeatable synthetic UI fixtures in one disposable database only.
 * CLI: set TRADE_CRM_WP_ROOT and
 * TRADE_CRM_TEST_DB_NAME=trade_crm_catalog_test_ui_130, then run this file.
 * Does not load or write the normal site's database or change wp-config.php.
 */
$ui_database = 'trade_crm_catalog_test_ui_130';
$ui_root = rtrim( (string) getenv( 'TRADE_CRM_WP_ROOT' ), '/\\' );
if ( PHP_SAPI !== 'cli' || (string) getenv( 'TRADE_CRM_TEST_DB_NAME' ) !== $ui_database || ! is_file( $ui_root . '/wp-config.php' ) || defined( 'ABSPATH' ) || isset( $GLOBALS['wpdb'] ) ) {
	fwrite( STDERR, "CLI, a local WordPress root, and the exact isolated UI database are required.\n" );
	exit( 2 );
}

// Read only standard literal declarations from trusted local configuration.
// Never execute its WordPress loader before the isolated bootstrap replaces DB_NAME.
$ui_config = file_get_contents( $ui_root . '/wp-config.php' );
$ui_read_literal = static function ( string $name ) use ( $ui_config ): string {
	$pattern = '/define\s*\(\s*([\'\"])' . preg_quote( $name, '/' ) . '\1\s*,\s*((?:\'(?:\\\\.|[^\'\\\\])*\')|(?:"(?:\\\\.|[^"\\\\])*"))\s*\)\s*;/s';
	if ( 1 !== preg_match_all( $pattern, $ui_config, $matches ) ) {
		throw new RuntimeException( 'A single standard literal database declaration is required.' );
	}
	// The capture consists only of one quoted PHP string literal, never an expression.
	$value = eval( 'return ' . $matches[2][0] . ';' );
	if ( ! is_string( $value ) ) {
		throw new RuntimeException( 'Database declaration must be a string literal.' );
	}
	return $value;
};
try {
	if ( $ui_read_literal( 'DB_NAME' ) === $ui_database ) {
		throw new RuntimeException( 'The UI database must differ from the normal site database.' );
	}
	$ui_host = $ui_read_literal( 'DB_HOST' );
	$ui_port = 3306;
	if ( preg_match( '/^([^:]+):([0-9]+)$/D', $ui_host, $ui_host_parts ) ) {
		$ui_host = $ui_host_parts[1];
		$ui_port = (int) $ui_host_parts[2];
	}
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	$ui_connection = new mysqli( $ui_host, $ui_read_literal( 'DB_USER' ), $ui_read_literal( 'DB_PASSWORD' ), '', $ui_port );
	// This fixed, non-interpolated statement is the only pre-bootstrap mutation.
	$ui_connection->query( 'CREATE DATABASE IF NOT EXISTS `trade_crm_catalog_test_ui_130` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
	$ui_connection->close();
} catch ( Throwable $error ) {
	fwrite( STDERR, "Could not prepare the guarded UI test database; no normal-site configuration was changed.\n" );
	exit( 2 );
}
unset( $ui_config, $ui_read_literal, $ui_connection, $ui_host, $ui_host_parts );

require __DIR__ . '/catalog-test-bootstrap.php';
if ( DB_NAME !== $ui_database || $wpdb->get_var( 'SELECT DATABASE()' ) !== $ui_database ) {
	throw new RuntimeException( 'UI fixture isolation failed.' );
}
if ( ! \TradeCRM\Tables::install() || ! \TradeCRM\IndustryCatalog::maybe_upgrade() ) {
	throw new RuntimeException( 'Could not initialize the isolated UI schema and catalogue.' );
}
( new \TradeCRM\Plugin() )->maybe_upgrade();

$ui_repository = new \TradeCRM\Repository();
$ui_server = rest_get_server();
$ui_request = static function ( string $method, string $route, array $body, array $expected = array( 200 ) ) use ( $ui_server ): array {
	$request = new WP_REST_Request( $method, '/trade-crm/v1/' . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	$response = $ui_server->dispatch( $request );
	$data = $response->get_data();
	if ( ! in_array( $response->get_status(), $expected, true ) ) {
		throw new RuntimeException( 'Fixture REST request failed: ' . $route . ' HTTP ' . $response->get_status() . ' ' . ( $data['message'] ?? '' ) );
	}
	return $data;
};
$ui_industry_ids = array();
foreach ( array( 'کیوی', 'پسته', 'ماشین‌آلات' ) as $ui_query ) {
	$ui_matches = $ui_repository->list_industries( array( 'query' => $ui_query, 'selectable_only' => true, 'limit' => 30 ) )['items'];
	if ( ! $ui_matches ) {
		throw new RuntimeException( 'The UI fixtures need the seeded activity catalogue.' );
	}
	$ui_industry_ids[] = (int) $ui_matches[0]['id'];
}
$ui_website_prefix = 'https://example.test/';
$ui_website = $ui_website_prefix . str_repeat( 'a', 200 - strlen( $ui_website_prefix ) );
$ui_long_note = str_repeat( 'یادداشت مصنوعی برای بررسی خوانایی متن فارسی، شکست سطر و فاصلهٔ بین خطوط در پرونده و سابقهٔ ارتباط. ', 14 ) . "\n" . $ui_website;
$ui_today = new DateTimeImmutable( 'today 14:30', new DateTimeZone( 'Asia/Tehran' ) );
$ui_fixture_definitions = array(
	'long' => array(
		'first_name' => 'آزمون بازطراحی مخاطب با نام و نام خانوادگی',
		'last_name' => 'بسیار طولانی برای بررسی شکست طبیعی عنوان و نمایش کامل عملیات در نمایشگر کوچک آیفون سیزده',
		'company' => 'شرکت بازرگانی بین‌المللی توسعهٔ صنایع غذایی و ماشین‌آلات تخصصی تولید و بسته‌بندی محصولات کشاورزی و صادرات خشکبار و میوه‌های تازه',
		'phone' => '+12025550130',
		'city' => 'تهران، منطقهٔ صنعتی و مرکز توسعهٔ همکاری‌های بازرگانی و صادرات',
		'followup_at' => $ui_today->modify( '-1 day' )->format( DATE_ATOM ),
		'followup_topic' => 'دریافت فهرست قیمت و هماهنگی جلسهٔ بررسی سفارش ماشین‌آلات و ارسال نمونهٔ محصولات صادراتی',
	),
	'today' => array(
		'first_name' => 'آزمون',
		'last_name' => 'بازطراحی آقای امام وردی',
		'company' => 'مجموعهٔ تولید و بازرگانی نمونه',
		'phone' => '+12025550131',
		'city' => 'شیراز',
		'followup_at' => $ui_today->format( DATE_ATOM ),
		'followup_topic' => 'هماهنگی ارسال نمونه و دریافت پاسخ پیشنهاد همکاری',
	),
	'archived' => array(
		'first_name' => 'آزمون',
		'last_name' => 'بازطراحی پروندهٔ بایگانی با عنوان طولانی و شرکت دارای چند حوزهٔ فعالیت برای بررسی فهرست',
		'company' => 'شرکت توسعهٔ راهکارهای تجارت و تولید با نام بلند برای بررسی خوانایی کارت بایگانی در موبایل',
		'phone' => '+12025550132',
		'city' => 'اصفهان',
	),
);
$ui_summary = array();
foreach ( $ui_fixture_definitions as $ui_key => $ui_fields ) {
	$ui_fields += array(
		'country_code' => 'US', 'whatsapp_same_as_phone' => true,
		'industry_ids' => $ui_industry_ids, 'website' => $ui_website,
		'initial_note' => "توضیح اولیهٔ مصنوعی؛ آدرس آزمایشی: خیابان نمونه، مرکز توسعهٔ تجارت، ساختمان همکاری، طبقهٔ سوم.\n" . $ui_long_note,
		'email' => $ui_key . '@example.test', 'telegram' => 'ui_redesign_test',
		'instagram' => 'ui_redesign_test', 'source' => 'referral',
		'status' => 'following_up', 'followup_at' => '', 'followup_topic' => '',
	);
	$ui_contact = $ui_repository->find_duplicate( $ui_fields['phone'] );
	if ( $ui_contact ) {
		$ui_contact = $ui_repository->get_contact( (int) $ui_contact['id'] );
		if ( ! str_starts_with( $ui_contact['name'], 'آزمون بازطراحی ' ) ) {
			throw new RuntimeException( 'A fixture phone belongs to a different test contact; seed refused.' );
		}
		if ( $ui_contact['archived_at'] ) {
			$ui_contact = $ui_request( 'POST', 'contacts/' . $ui_contact['id'] . '/restore', array( 'version' => $ui_contact['version'] ) );
		}
		$ui_contact = $ui_request( 'PATCH', 'contacts/' . $ui_contact['id'], $ui_fields + array( 'version' => $ui_contact['version'] ) );
	} else {
		$ui_created = $ui_request( 'POST', 'contacts', $ui_fields + array( 'request_id' => wp_generate_uuid4() ), array( 201 ) );
		$ui_contact = $ui_created['contact'];
	}
	$ui_note_request = '13000000-0000-4000-8000-' . str_pad( (string) $ui_contact['id'], 12, '0', STR_PAD_LEFT );
	$ui_request( 'POST', 'contacts/' . $ui_contact['id'] . '/notes', array( 'text' => $ui_long_note, 'request_id' => $ui_note_request ), array( 200, 201 ) );
	if ( 'archived' === $ui_key ) {
		$ui_contact = $ui_request( 'POST', 'contacts/' . $ui_contact['id'] . '/archive', array( 'version' => $ui_contact['version'] ) );
	}
	$ui_summary[ $ui_key ] = array( 'id' => (int) $ui_contact['id'], 'name' => $ui_contact['name'], 'archived' => (bool) $ui_contact['archived_at'], 'followup_at' => $ui_contact['followup_at'] );
}
echo wp_json_encode( array( 'database' => $ui_database, 'administrator_id' => get_current_user_id(), 'fixtures' => $ui_summary, 'industry_ids' => $ui_industry_ids, 'website_length' => strlen( $ui_website ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
