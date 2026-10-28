<?php
/**
 * Jalali (Solar Hijri) calendar helpers. Pure logic: no WordPress calls.
 *
 * The conversion follows the arithmetic 33-year leap cycle algorithm that is the
 * de-facto standard for the Iranian civil calendar (2820-year Borkowski variant
 * is not used; the 33-year cycle matches the official calendar for 1178-1633 SH).
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class Jalali
 */
final class Jalali {

	/**
	 * Persian month names.
	 *
	 * @var string[]
	 */
	const MONTHS = array(
		'فروردین',
		'اردیبهشت',
		'خرداد',
		'تیر',
		'مرداد',
		'شهریور',
		'مهر',
		'آبان',
		'آذر',
		'دی',
		'بهمن',
		'اسفند',
	);

	/**
	 * Convert a Gregorian date to Jalali.
	 *
	 * @param int $gy Gregorian year.
	 * @param int $gm Gregorian month.
	 * @param int $gd Gregorian day.
	 * @return array{0:int,1:int,2:int} array( year, month, day )
	 */
	public static function from_gregorian( $gy, $gm, $gd ) {
		$g_days_in_month = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$j_days_in_month = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 );

		$gy = (int) $gy;
		$gm = (int) $gm;
		$gd = (int) $gd;

		$gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd;
		for ( $i = 0; $i < $gm - 1; $i++ ) {
			$days += $g_days_in_month[ $i ];
		}

		$jy = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;

		$jy += 4 * intdiv( $days, 1461 );
		$days %= 1461;

		if ( $days > 365 ) {
			$jy += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}

		$jm = 1;
		$jd = 0;
		for ( $i = 0; $i < 12; $i++ ) {
			if ( $days < $j_days_in_month[ $i ] ) {
				$jm = $i + 1;
				$jd = $days + 1;
				break;
			}
			$days -= $j_days_in_month[ $i ];
		}

		if ( 0 === $jd ) {
			// Leap year tail: day 366 belongs to Esfand 30.
			$jm = 12;
			$jd = 30;
		}

		return array( (int) $jy, (int) $jm, (int) $jd );
	}

	/**
	 * Convert a Jalali date to Gregorian.
	 *
	 * @param int $jy Jalali year.
	 * @param int $jm Jalali month.
	 * @param int $jd Jalali day.
	 * @return array{0:int,1:int,2:int} array( year, month, day )
	 */
	public static function to_gregorian( $jy, $jm, $jd ) {
		$jy = (int) $jy;
		$jm = (int) $jm;
		$jd = (int) $jd;

		$jy += 1595;

		$month_offset = ( $jm < 7 ) ? ( ( $jm - 1 ) * 31 ) : ( ( $jm - 7 ) * 30 + 186 );
		$days         = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + $month_offset;

		$gy   = 400 * intdiv( $days, 146097 );
		$days = $days % 146097;

		if ( $days > 36524 ) {
			$days--;
			$gy  += 100 * intdiv( $days, 36524 );
			$days = $days % 36524;

			if ( $days >= 365 ) {
				$days++;
			}
		}

		$gy  += 4 * intdiv( $days, 1461 );
		$days = $days % 1461;

		if ( $days > 365 ) {
			$gy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}

		$g_days_in_month = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$is_leap         = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || ( 0 === $gy % 400 );
		$gd              = $days + 1;
		$gm              = 1;

		for ( $i = 0; $i < 12; $i++ ) {
			$len = $g_days_in_month[ $i ];
			if ( 1 === $i && $is_leap ) {
				$len = 29;
			}
			if ( $gd <= $len ) {
				$gm = $i + 1;
				break;
			}
			$gd -= $len;
		}

		return array( (int) $gy, (int) $gm, (int) $gd );
	}

	/**
	 * Format a Jalali date using Persian digits.
	 *
	 * @param string $mysql_datetime MySQL DATETIME (assumed site timezone).
	 * @param string $format          "j F Y" (default), "Y/m/d" or "iso".
	 * @return string
	 */
	public static function format( $mysql_datetime, $format = 'j F Y' ) {
		$mysql_datetime = (string) $mysql_datetime;
		if ( '' === $mysql_datetime || 0 === strpos( $mysql_datetime, '0000-00-00' ) ) {
			return '';
		}

		$timestamp = strtotime( $mysql_datetime );
		if ( false === $timestamp ) {
			return '';
		}

		$parts = self::from_gregorian(
			(int) gmdate( 'Y', $timestamp ),
			(int) gmdate( 'n', $timestamp ),
			(int) gmdate( 'j', $timestamp )
		);

		$year  = $parts[0];
		$month = $parts[1];
		$day   = $parts[2];

		switch ( $format ) {
			case 'iso':
				$out = sprintf( '%04d-%02d-%02d', $year, $month, $day );
				break;
			case 'Y/m/d':
				$out = sprintf( '%04d/%02d/%02d', $year, $month, $day );
				break;
			case 'datetime':
				$out = sprintf(
					'%d %s %d — %02d:%02d',
					$day,
					self::MONTHS[ $month - 1 ],
					$year,
					(int) gmdate( 'G', $timestamp ),
					(int) gmdate( 'i', $timestamp )
				);
				break;
			case 'j F Y':
			default:
				$out = sprintf( '%d %s %d', $day, self::MONTHS[ $month - 1 ], $year );
				break;
		}

		return Persian::digits( $out );
	}
}
