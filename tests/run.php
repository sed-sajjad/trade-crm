<?php
use TradeCRM\Normalizer;
use TradeCRM\PhoneNumbers;

require_once __DIR__ . '/bootstrap.php';

$checks = array();
$checks['Persian and Arabic letters/digits'] = 'علی ک ی 123' === Normalizer::text( 'علی  ك ي ۱۲3' );
$checks['valid request UUID'] = '550e8400-e29b-41d4-a716-446655440000' === Normalizer::request_id( '550E8400-E29B-41D4-A716-446655440000' );
$iran = PhoneNumbers::normalize( '۰۹۱۲ ۱۲۳ ۴۵۶۷', 'IR' );
$checks['Iranian phone E.164'] = is_array( $iran ) && '+989121234567' === $iran['number_e164'] && '09121234567' === $iran['national_digits'];
$international = PhoneNumbers::normalize( '+44 20 7946 0018', 'IR' );
$checks['international phone E.164'] = is_array( $international ) && '+442079460018' === $international['number_e164'];
$invalid = PhoneNumbers::normalize( '123', 'IR' );
$checks['invalid phone rejected'] = $invalid instanceof WP_Error && 'trade_crm_invalid_phone' === $invalid->get_error_code();

$failed = array();
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $passed ) {
		$failed[] = $label;
	}
}
if ( $failed ) {
	exit( 1 );
}
echo 'All ' . count( $checks ) . ' PHP smoke tests passed.' . PHP_EOL;
