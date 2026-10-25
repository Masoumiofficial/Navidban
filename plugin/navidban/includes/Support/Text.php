<?php
/**
 * UTF-8 helpers that do not depend on mbstring (shared hosts often ship without it).
 *
 * @package Navidban
 */

namespace Navidban\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class Text
 */
final class Text {

	/**
	 * Character length.
	 *
	 * @param string $value Input.
	 * @return int
	 */
	public static function length( $value ) {
		$value = (string) $value;

		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $value );

		return false === $count ? strlen( $value ) : (int) $count;
	}

	/**
	 * Character-aware substring.
	 *
	 * @param string   $value  Input.
	 * @param int      $start  Start offset in characters.
	 * @param int|null $length Length in characters (null = to the end).
	 * @return string
	 */
	public static function substring( $value, $start, $length = null ) {
		$value = (string) $value;

		if ( function_exists( 'mb_substr' ) ) {
			if ( null === $length ) {
				return (string) mb_substr( $value, $start, null, 'UTF-8' );
			}

			return (string) mb_substr( $value, $start, $length, 'UTF-8' );
		}

		$characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $characters ) {
			return substr( $value, $start, null === $length ? PHP_INT_MAX : $length );
		}

		$slice = array_slice( $characters, (int) $start, null === $length ? null : (int) $length );

		return implode( '', $slice );
	}
}
