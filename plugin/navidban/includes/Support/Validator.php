<?php
/**
 * Input validation and normalisation. Pure logic: no WordPress calls.
 *
 * Implemented from the public specifications:
 *  - ITU-T E.164 / Iranian national numbering plan for mobile prefixes.
 *  - RFC 1035/5321 subset for email syntax (deliberately conservative).
 *  - Iranian national ID (کد ملی) check-digit algorithm (ISO 7064 style modulus 11).
 *
 * @package Navidban
 */

namespace Navidban\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class Validator
 */
final class Validator {

	/**
	 * Allowed third digit of an Iranian mobile number after the leading "09".
	 *
	 * 090x, 091x, 092x, 093x, 099x are the allocated mobile blocks.
	 *
	 * @var string[]
	 */
	const MOBILE_BLOCKS = array( '0', '1', '2', '3', '9' );

	/**
	 * Convert Persian (۰-۹) and Arabic-Indic (٠-٩) digits to ASCII digits.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function to_latin_digits( $value ) {
		$value = (string) $value;
		$map   = array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		);

		return strtr( $value, $map );
	}

	/**
	 * Normalise an Iranian mobile number to the canonical 09XXXXXXXXX form.
	 *
	 * @param string $raw Raw user input.
	 * @return string Canonical number, or empty string when the input is not a mobile number.
	 */
	public static function normalize_phone( $raw ) {
		$raw = self::to_latin_digits( (string) $raw );
		// Keep digits only: strips spaces, dashes, parentheses, dots.
		$digits = preg_replace( '/\D+/', '', $raw );

		if ( null === $digits || '' === $digits ) {
			return '';
		}

		if ( 0 === strpos( $digits, '0098' ) ) {
			// 0098 912 123 4567 -> 09121234567
			$digits = '0' . substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '98' ) && 12 === strlen( $digits ) ) {
			$digits = '0' . substr( $digits, 2 );
		} elseif ( 0 === strpos( $digits, '9' ) && 10 === strlen( $digits ) ) {
			$digits = '0' . $digits;
		}

		if ( ! self::is_mobile( $digits ) ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Check whether a value already in canonical form is a valid Iranian mobile number.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	public static function is_mobile( $value ) {
		$value = self::to_latin_digits( (string) $value );
		if ( ! preg_match( '/^09\d{9}$/', $value ) ) {
			return false;
		}

		return in_array( $value[2], self::MOBILE_BLOCKS, true );
	}

	/**
	 * Normalise an email address. The whole address is lower-cased: mailbox providers
	 * treat local parts case-insensitively in practice, and the unique subscriber
	 * key must not let "Ali@" and "ali@" register twice.
	 *
	 * @param string $raw Raw input.
	 * @return string Normalised address, or empty string when invalid.
	 */
	public static function normalize_email( $raw ) {
		$raw = trim( (string) $raw );
		$raw = str_replace( array( "\r", "\n", "\t", ' ' ), '', $raw );

		if ( ! self::is_email( $raw ) ) {
			return '';
		}

		return strtolower( $raw );
	}

	/**
	 * Conservative email syntax check.
	 *
	 * @param string $email Candidate address.
	 * @return bool
	 */
	public static function is_email( $email ) {
		$email = (string) $email;

		if ( '' === $email || strlen( $email ) > 190 ) {
			return false;
		}

		if ( false === strpos( $email, '@' ) ) {
			return false;
		}

		$parts = explode( '@', $email );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		list( $local, $domain ) = $parts;

		if ( '' === $local || strlen( $local ) > 64 ) {
			return false;
		}

		if ( ! preg_match( '/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local ) ) {
			return false;
		}

		if ( '.' === $local[0] || '.' === substr( $local, -1 ) || false !== strpos( $local, '..' ) ) {
			return false;
		}

		if ( ! preg_match( '/^(?=.{1,190}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', $domain ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate an Iranian national ID (کد ملی) using its modulus-11 check digit.
	 *
	 * @param string $raw Raw input.
	 * @return bool
	 */
	public static function is_national_id( $raw ) {
		$code = self::to_latin_digits( (string) $raw );
		$code = preg_replace( '/\D+/', '', $code );

		if ( null === $code || 10 !== strlen( $code ) ) {
			return false;
		}

		// All identical digits are structurally invalid (e.g. 1111111111).
		if ( 1 === count( array_unique( str_split( $code ) ) ) ) {
			return false;
		}

		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += ( (int) $code[ $i ] ) * ( 10 - $i );
		}

		$remainder = $sum % 11;
		$check     = (int) $code[9];

		if ( $remainder < 2 ) {
			return $check === $remainder;
		}

		return $check === ( 11 - $remainder );
	}

	/**
	 * Sanitise a free-text value: strip control characters, tags and collapse whitespace.
	 *
	 * @param string $value   Raw value.
	 * @param int    $max_len Maximum length in characters.
	 * @return string
	 */
	public static function plain_text( $value, $max_len = 190 ) {
		$value = (string) $value;
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		if ( null === $value ) {
			$value = '';
		}
		$value = strip_tags( $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		if ( null === $value ) {
			$value = '';
		}
		$value = trim( $value );

		return Text::substring( $value, 0, $max_len );
	}
}
