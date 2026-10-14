<?php
use PHPUnit\Framework\TestCase;
use TradeCRM\Normalizer;
use TradeCRM\PhoneNumbers;

final class NormalizerTest extends TestCase {
	public function test_persian_arabic_digits_and_common_arabic_letters_are_normalized(): void {
		self::assertSame( 'علی ک ی 123', Normalizer::text( 'علی  ك ي ۱۲3' ) );
	}

	public function test_request_id_requires_a_uuid(): void {
		self::assertSame( '550e8400-e29b-41d4-a716-446655440000', Normalizer::request_id( '550E8400-E29B-41D4-A716-446655440000' ) );
		self::assertNull( Normalizer::request_id( 'not-a-request-id' ) );
	}

	public function test_iranian_number_with_persian_digits_normalizes_to_e164(): void {
		$number = PhoneNumbers::normalize( '۰۹۱۲ ۱۲۳ ۴۵۶۷', 'IR' );
		self::assertIsArray( $number );
		self::assertSame( '+989121234567', $number['number_e164'] );
		self::assertSame( '09121234567', $number['national_digits'] );
	}

	public function test_international_number_and_invalid_number_are_handled(): void {
		$number = PhoneNumbers::normalize( '+44 20 7946 0018', 'IR' );
		self::assertIsArray( $number );
		self::assertSame( '+442079460018', $number['number_e164'] );
		$invalid = PhoneNumbers::normalize( '123', 'IR' );
		self::assertInstanceOf( WP_Error::class, $invalid );
		self::assertSame( 'trade_crm_invalid_phone', $invalid->get_error_code() );
	}

	public function test_phone_country_selector_has_broad_region_coverage(): void {
		$regions = PhoneNumbers::supported_regions();
		self::assertContains( 'IR', $regions );
		self::assertContains( 'GB', $regions );
		self::assertGreaterThanOrEqual( 200, count( $regions ) );
	}
}
