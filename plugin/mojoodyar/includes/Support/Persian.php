<?php
/**
 * Persian typography helpers. Pure logic: no WordPress calls.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class Persian
 */
final class Persian {

	/**
	 * Convert ASCII digits to Persian digits.
	 *
	 * @param string|int $value Input.
	 * @return string
	 */
	public static function digits( $value ) {
		$value = (string) $value;

		return strtr(
			$value,
			array(
				'0' => '۰',
				'1' => '۱',
				'2' => '۲',
				'3' => '۳',
				'4' => '۴',
				'5' => '۵',
				'6' => '۶',
				'7' => '۷',
				'8' => '۸',
				'9' => '۹',
			)
		);
	}

	/**
	 * Group thousands with the Persian thousands separator (٫ is decimal; use , for grouping).
	 *
	 * @param int $number Number.
	 * @return string
	 */
	public static function number( $number ) {
		return self::digits( number_format( (float) $number, 0, '.', ',' ) );
	}

	/**
	 * Replace ZWNJ-less broken plurals and normalise Arabic characters.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = (string) $text;
		$text = str_replace( array( 'ي', 'ك', 'ۀ', 'ؤ' ), array( 'ی', 'ک', 'ه', 'و' ), $text );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return null === $text ? '' : trim( $text );
	}

	/**
	 * A short, human relative-time label in Persian (used in the admin list).
	 *
	 * @param int $seconds_ago Age in seconds.
	 * @return string
	 */
	/**
	 * How far in the future something is, in words.
	 *
	 * The mirror of relative(): a queue row is often not broken but simply
	 * waiting for its next_run_at, and "in 41 seconds" is the difference between
	 * "the plugin is dead" and "it is about to send".
	 *
	 * @param int $seconds_until Seconds from now.
	 * @return string
	 */
	public static function until( $seconds_until ) {
		$seconds_until = (int) $seconds_until;

		if ( $seconds_until <= 0 ) {
			return 'همین حالا';
		}

		if ( $seconds_until < 60 ) {
			return self::digits( $seconds_until ) . ' ثانیهٔ دیگر';
		}

		if ( $seconds_until < 3600 ) {
			return self::digits( (int) ceil( $seconds_until / 60 ) ) . ' دقیقهٔ دیگر';
		}

		if ( $seconds_until < 86400 ) {
			return self::digits( (int) ceil( $seconds_until / 3600 ) ) . ' ساعت دیگر';
		}

		return self::digits( (int) ceil( $seconds_until / 86400 ) ) . ' روز دیگر';
	}

	public static function relative( $seconds_ago ) {
		$seconds_ago = (int) $seconds_ago;
		if ( $seconds_ago < 0 ) {
			$seconds_ago = 0;
		}

		if ( $seconds_ago < 60 ) {
			return 'همین حالا';
		}
		if ( $seconds_ago < 3600 ) {
			$minutes = (int) floor( $seconds_ago / 60 );
			return self::digits( $minutes ) . ' دقیقه پیش';
		}
		if ( $seconds_ago < 86400 ) {
			$hours = (int) floor( $seconds_ago / 3600 );
			return self::digits( $hours ) . ' ساعت پیش';
		}

		$days = (int) floor( $seconds_ago / 86400 );
		return self::digits( $days ) . ' روز پیش';
	}
}
