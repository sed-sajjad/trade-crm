<?php
namespace TradeCRM;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

defined( 'ABSPATH' ) || exit;

final class PhoneNumbers {
	public static function supported_regions(): array {
		if ( ! class_exists( PhoneNumberUtil::class ) ) {
			return array( 'IR' );
		}
		$regions = array_values( array_filter( PhoneNumberUtil::getInstance()->getSupportedRegions(), static fn ( $region ): bool => is_string( $region ) && 1 === preg_match( '/^[A-Z]{2}$/', $region ) ) );
		sort( $regions );
		return $regions;
	}

	public static function normalize( string $raw, string $region ): array|\WP_Error {
		if ( ! class_exists( PhoneNumberUtil::class ) ) {
			return new \WP_Error( 'trade_crm_phone_library_missing', 'کتابخانه شماره تلفن نصب نشده است.', array( 'status' => 503 ) );
		}
		$region  = strtoupper( substr( $region, 0, 2 ) );
		$regions = PhoneNumberUtil::getInstance()->getSupportedRegions();
		if ( ! in_array( $region, $regions, true ) ) {
			$region = 'IR';
		}
		$normalized = trim( Normalizer::digits( $raw ) );
		if ( '' === $normalized || strlen( $normalized ) > 48 ) {
			return new \WP_Error( 'trade_crm_invalid_phone', 'شماره تماس معتبر وارد کنید.', array( 'status' => 400, 'field' => 'phone' ) );
		}
		try {
			$util   = PhoneNumberUtil::getInstance();
			$number = $util->parse( $normalized, $region );
			if ( $number->hasExtension() || ! $util->isValidNumber( $number ) ) {
				throw new NumberParseException( NumberParseException::NOT_A_NUMBER, 'Invalid CRM phone number.' );
			}
			$national = $util->format( $number, PhoneNumberFormat::NATIONAL );
			return array(
				'number_e164'      => $util->format( $number, PhoneNumberFormat::E164 ),
				'national_digits'  => preg_replace( '/\D+/', '', Normalizer::digits( $national ) ) ?? '',
				'national_display' => $national,
			);
		} catch ( NumberParseException $exception ) {
			return new \WP_Error( 'trade_crm_invalid_phone', 'شماره تماس معتبر وارد کنید.', array( 'status' => 400, 'field' => 'phone' ) );
		}
	}
}
