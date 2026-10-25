<?php
/**
 * "Announce only as many units as came back" policy. Pure logic: unit-tested.
 *
 * The rule: available units = current stock − stock recorded at the last
 * announcement. When fair sending is enabled we notify at most that many
 * subscribers, oldest first. Everyone else keeps waiting for the next restock.
 *
 * @package Navidban
 */

namespace Navidban\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Class FairSending
 */
final class FairSending {

	/**
	 * Number of subscribers that may be notified for this restock.
	 *
	 * @param int  $current_qty     Current stock quantity (null/unmanaged => null).
	 * @param int  $last_notify_qty Stock quantity recorded when the previous announcement was made.
	 * @param int  $waiting         Number of confirmed subscribers waiting.
	 * @param bool $fair_enabled    Whether the fair-sending option is on.
	 * @return int Allowed notifications, never larger than $waiting.
	 */
	public static function allowance( $current_qty, $last_notify_qty, $waiting, $fair_enabled ) {
		$waiting = max( 0, (int) $waiting );

		if ( ! $fair_enabled ) {
			return $waiting;
		}

		// Unmanaged stock (quantity is null) means we cannot reason about units.
		if ( null === $current_qty ) {
			return $waiting;
		}

		$current_qty     = (int) $current_qty;
		$last_notify_qty = (int) $last_notify_qty;

		$available = $current_qty - $last_notify_qty;

		if ( $available <= 0 ) {
			// Stock status flipped but no units are actually free for these
			// subscribers (e.g. manual status change with backorders).
			return 0;
		}

		return (int) min( $waiting, $available );
	}

	/**
	 * Select the subscribers that may be notified.
	 *
	 * @param array $rows           Rows ordered by created_at ascending.
	 * @param int   $allowed        Value returned by allowance().
	 * @return array{selected:array,deferred:array}
	 */
	public static function split( array $rows, $allowed ) {
		$allowed = max( 0, (int) $allowed );

		if ( count( $rows ) <= $allowed ) {
			return array(
				'selected' => array_values( $rows ),
				'deferred' => array(),
			);
		}

		return array(
			'selected' => array_slice( array_values( $rows ), 0, $allowed ),
			'deferred' => array_slice( array_values( $rows ), $allowed ),
		);
	}

	/**
	 * The stock quantity to persist as "announced at" after a run.
	 *
	 * @param int|null $current_qty Current quantity.
	 * @param int      $notified    How many notifications were sent.
	 * @param int      $previous    Previously recorded value.
	 * @return int
	 */
	public static function next_notified_qty( $current_qty, $notified, $previous ) {
		if ( null === $current_qty ) {
			return (int) $previous;
		}

		// A restock that produced no notification keeps the baseline unchanged,
		// otherwise the next real restock would be measured from the wrong point.
		if ( (int) $notified <= 0 ) {
			return (int) $previous;
		}

		return (int) $current_qty;
	}
}
