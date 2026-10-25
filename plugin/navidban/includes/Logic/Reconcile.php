<?php
/**
 * Snapshot diffing for the adaptive stock watcher. Pure logic: unit-tested.
 *
 * Layer 2 of the stock engine: ERP imports, CSV writers and direct SQL updates
 * never fire WooCommerce hooks, so we compare a stored snapshot with the live
 * state on a schedule and emit transitions for "out of stock -> in stock" only.
 *
 * The very first run after activation ONLY records a snapshot. Announcing on the
 * first run would flood every already-in-stock product (a documented bug in
 * competing plugins).
 *
 * @package Navidban
 */

namespace Navidban\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reconcile
 */
final class Reconcile {

	/**
	 * Build the composite key used for snapshot rows.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 for the parent/simple product).
	 * @return string
	 */
	public static function key( $product_id, $variation_id = 0 ) {
		return (int) $product_id . ':' . (int) $variation_id;
	}

	/**
	 * Compute transitions between the stored snapshot and the live state.
	 *
	 * @param array<string,array> $snapshots  Stored snapshots keyed by key(): array( status, qty ).
	 * @param array<string,array> $current    Live state keyed by key(): array( product_id, variation_id, status, qty ).
	 * @param bool                $first_run  True when the snapshot table is empty for this site.
	 * @return array{transitions:array,writes:array,first_run:bool}
	 *         transitions: rows that must be announced.
	 *         writes:      snapshot rows to upsert (always the full current state).
	 */
	public static function diff( array $snapshots, array $current, $first_run = false ) {
		$writes      = array();
		$transitions = array();

		foreach ( $current as $key => $row ) {
			$writes[ $key ] = array(
				'product_id'   => (int) $row['product_id'],
				'variation_id' => isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0,
				'status'       => (string) $row['status'],
				'qty'          => (int) $row['qty'],
				'is_managed'   => isset( $row['is_managed'] ) ? (bool) $row['is_managed'] : true,
			);
		}

		if ( $first_run ) {
			return array(
				'transitions' => array(),
				'writes'      => $writes,
				'first_run'   => true,
			);
		}

		foreach ( $writes as $key => $row ) {
			$previous = isset( $snapshots[ $key ] ) ? $snapshots[ $key ] : null;

			if ( null === $previous ) {
				// A product we have never seen. Treat as baseline, never as a restock.
				continue;
			}

			$was_out = self::is_out_of_stock( $previous['status'] );
			$is_in   = ! self::is_out_of_stock( $row['status'] );

			if ( $was_out && $is_in ) {
				$transitions[] = array(
					'product_id'   => $row['product_id'],
					'variation_id' => $row['variation_id'],
					'from_status'  => (string) $previous['status'],
					'to_status'    => $row['status'],
					'qty'          => $row['qty'],
					'is_managed'   => $row['is_managed'],
				);
			}
		}

		return array(
			'transitions' => $transitions,
			'writes'      => $writes,
			'first_run'   => false,
		);
	}

	/**
	 * Is a WooCommerce stock status an "unavailable" status?
	 *
	 * @param string $status Stock status.
	 * @return bool
	 */
	public static function is_out_of_stock( $status ) {
		return in_array( (string) $status, array( 'outofstock', 'onbackorder' ), true );
	}

	/**
	 * Does this transition deserve an announcement?
	 *
	 * @param array $transition Transition row from diff().
	 * @param bool  $notify_on_backorder Whether backorder counts as "available".
	 * @return bool
	 */
	public static function should_announce( array $transition, $notify_on_backorder = false ) {
		$to = isset( $transition['to_status'] ) ? (string) $transition['to_status'] : '';

		if ( 'instock' === $to ) {
			return true;
		}

		return $notify_on_backorder && 'onbackorder' === $to;
	}
}
