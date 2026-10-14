<?php
namespace TradeCRM;

defined( 'ABSPATH' ) || exit;

final class Normalizer {
	public static function digits( string $value ): string {
		return strtr( $value, array(
			'۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
			'٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
		) );
	}

	public static function text( string $value ): string {
		$value = strtr( $value, array( 'ي'=>'ی', 'ى'=>'ی', 'ك'=>'ک', 'ۀ'=>'ه', 'ة'=>'ه', '‌'=>' ', 'ـ'=>'' ) );
		$value = self::digits( $value );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	public static function request_id( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value ) ) {
			return null;
		}
		return strtolower( $value );
	}
}
