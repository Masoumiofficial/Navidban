<?php
/**
 * Statistics helpers that do not touch WordPress. Pure logic: unit-tested.
 *
 * The reports screen needs to answer four shapes of question and nothing more:
 *   - how many rows per day (a series that must not have holes);
 *   - what share of the whole is one slice (a percentage that never divides by
 *     zero and never prints "NAN");
 *   - how tall a bar should be, given the tallest bar in the same chart;
 *   - what the peak value was, so an empty period can be labelled instead of
 *     drawn as a blank grid.
 *
 * Everything here takes numbers and returns numbers. Formatting (Persian
 * digits, Jalali labels) belongs to the view.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Class StatsMath
 */
final class StatsMath {

	/**
	 * Build the ordered list of day keys for a period.
	 *
	 * A day with no rows is still a day. Filling the axis here (instead of
	 * letting the database skip empty days) is what stops the chart from
	 * silently compressing a quiet week into a single bar.
	 *
	 * @param int $from_ts Unix timestamp of the first day (midnight, UTC).
	 * @param int $days    Number of days, 1 or more.
	 * @return string[] Day keys, oldest first, in Y-m-d.
	 */
	public static function day_keys( $from_ts, $days ) {
		$days = max( 1, (int) $days );
		$keys = array();

		// Anchor to midnight so a partial first day cannot shift every bar.
		$start = (int) $from_ts - ( (int) $from_ts % 86400 );

		for ( $i = 0; $i < $days; $i++ ) {
			$keys[] = gmdate( 'Y-m-d', $start + ( $i * 86400 ) );
		}

		return $keys;
	}

	/**
	 * Turn rows from the database into a zero-filled series in axis order.
	 *
	 * @param array  $rows      Rows shaped like array( 'd' => '2026-10-01', 'c' => 3 ).
	 * @param string[] $keys    Day keys from day_keys().
	 * @return int[] Values aligned with $keys.
	 */
	public static function fill( array $rows, array $keys ) {
		$map = array();

		foreach ( $rows as $row ) {
			$row = (array) $row;
			$day = isset( $row['d'] ) ? (string) $row['d'] : '';

			if ( '' === $day ) {
				continue;
			}

			// Keep only the date part: MySQL and SQLite both return Y-m-d here,
			// but a stored DATETIME would otherwise create a second bucket.
			$day = substr( $day, 0, 10 );

			$map[ $day ] = isset( $map[ $day ] ) ? $map[ $day ] + (int) ( isset( $row['c'] ) ? $row['c'] : 0 ) : (int) ( isset( $row['c'] ) ? $row['c'] : 0 );
		}

		$series = array();

		foreach ( $keys as $key ) {
			$series[] = isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
		}

		return $series;
	}

	/**
	 * Integer percentage, safe when the total is zero.
	 *
	 * @param int $part  Slice.
	 * @param int $total Whole.
	 * @return int 0-100.
	 */
	public static function percent( $part, $total ) {
		$part  = max( 0, (int) $part );
		$total = (int) $total;

		if ( $total <= 0 || $part <= 0 ) {
			return 0;
		}

		if ( $part >= $total ) {
			return 100;
		}

		return (int) round( $part * 100 / $total );
	}

	/**
	 * Largest value in a series (0 for an empty series).
	 *
	 * @param int[] $series Values.
	 * @return int
	 */
	public static function peak( array $series ) {
		$peak = 0;

		foreach ( $series as $value ) {
			$value = (int) $value;
			if ( $value > $peak ) {
				$peak = $value;
			}
		}

		return $peak;
	}

	/**
	 * Bar height as a percentage of the chart's own peak.
	 *
	 * A non-zero value never renders as 0%: an invisible bar next to a real
	 * number would read as a bug. The floor is deliberately small (4%) so one
	 * busy day cannot flatten the rest of the chart.
	 *
	 * @param int $value Value.
	 * @param int $peak  Peak of the same series.
	 * @return int 0-100.
	 */
	public static function height( $value, $peak ) {
		$value = (int) $value;
		$peak  = (int) $peak;

		if ( $value <= 0 || $peak <= 0 ) {
			return 0;
		}

		if ( $value >= $peak ) {
			return 100;
		}

		return max( 4, (int) round( $value * 100 / $peak ) );
	}

	/**
	 * Sum of a series.
	 *
	 * @param int[] $series Values.
	 * @return int
	 */
	public static function total( array $series ) {
		$total = 0;

		foreach ( $series as $value ) {
			$total += (int) $value;
		}

		return $total;
	}

	/**
	 * How many days a label is printed for, so a 90-day chart stays readable.
	 *
	 * @param int $days Period length.
	 * @return int Every Nth day gets a label.
	 */
	public static function label_step( $days ) {
		$days = max( 1, (int) $days );

		if ( $days <= 10 ) {
			return 1;
		}

		if ( $days <= 31 ) {
			return 3;
		}

		return 7;
	}
}
