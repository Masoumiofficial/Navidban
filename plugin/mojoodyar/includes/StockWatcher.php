<?php
/**
 * Stock detection engine (two layers).
 *
 * Layer 1 — WooCommerce hooks: instant reaction to the normal admin/checkout
 * flow (product save, stock status change, order stock reduction).
 *
 * Layer 2 — scheduled reconcile: catches writes that never fire a hook
 * (CSV/ERP import, direct SQL, inventory plugins using $wpdb directly).
 *
 * Repeat control is implemented with an atomic UPDATE on the snapshot row
 * (see WaitlistRepository::claim_transition), so the same restock can never be
 * announced twice even if both layers see it.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

use Mojoodyar\Logic\FairSending;
use Mojoodyar\Logic\Reconcile;

defined( 'ABSPATH' ) || exit;

/**
 * Class StockWatcher
 */
final class StockWatcher {

	/**
	 * Register WooCommerce hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_object' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_product_object' ), 10, 1 );
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'on_order_stock' ), 10, 1 );
		add_action( 'save_post_product', array( $this, 'on_product_saved' ), 20, 1 );
	}

	/**
	 * Stock status changed through WooCommerce (product or variation).
	 *
	 * @param int        $product_id Product or variation ID.
	 * @param string     $status     New status.
	 * @param \WC_Product $product   Product object (may be null in older versions).
	 * @return void
	 */
	public function on_stock_status( $product_id, $status, $product = null ) {
		$product_id = (int) $product_id;
		$status     = (string) $status;

		if ( $product_id <= 0 ) {
			return;
		}

		$variation_id = $this->resolve_variation_id( $product_id, $product );
		$parent_id    = ( $variation_id > 0 ) ? $this->resolve_parent_id( $variation_id ) : $product_id;

		$qty        = $this->read_qty( $product_id );
		$is_managed = $this->read_managed( $product_id );

		if ( ! $this->is_sellable_status( $status ) ) {
			// While unavailable we only record the state.
			$this->record( $parent_id, $variation_id, $status, $qty, $is_managed );

			return;
		}

		$this->handle_restock( $parent_id, $variation_id, $status, $qty, $is_managed );
	}

	/**
	 * Stock quantity changed through the CRUD layer.
	 *
	 * @param \WC_Product $product Product object.
	 * @return void
	 */
	public function on_product_object( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$id           = (int) $product->get_id();
		$variation_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		$parent_id    = ( $variation_id > 0 ) ? $variation_id : $id;
		$v_id         = ( $variation_id > 0 ) ? $id : 0;

		$status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : 'instock';
		$qty    = method_exists( $product, 'get_stock_quantity' ) ? (int) $product->get_stock_quantity() : 0;
		$managed = method_exists( $product, 'managing_stock' ) ? (bool) $product->managing_stock() : true;

		if ( $this->is_sellable_status( $status ) ) {
			$this->handle_restock( $parent_id, $v_id, $status, $qty, $managed );
		} else {
			$this->record( $parent_id, $v_id, $status, $qty, $managed );
		}
	}

	/**
	 * Order stock reduction can flip a product to out of stock.
	 *
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	public function on_order_stock( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$variation  = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;

			if ( $product_id <= 0 ) {
				continue;
			}

			$target = ( $variation > 0 ) ? $variation : $product_id;
			$status = $this->read_status( $target );

			if ( '' === $status ) {
				continue;
			}

			$this->record(
				$product_id,
				$variation,
				$status,
				$this->read_qty( $target ),
				$this->read_managed( $target )
			);
		}
	}

	/**
	 * Product saved in the admin (covers "in stock" toggles that skip the
	 * dedicated status hook in some CRUD paths).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_product_saved( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ) {
			// Variations are handled by their own hooks; only snapshot the parent.
			$this->record(
				$post_id,
				0,
				(string) $product->get_stock_status(),
				(int) $product->get_stock_quantity(),
				(bool) $product->managing_stock()
			);

			return;
		}

		$this->on_product_object( $product );
	}

	/**
	 * Announce a restock.
	 *
	 * @param int    $product_id   Parent product ID.
	 * @param int    $variation_id Variation ID (0 for simple products).
	 * @param string $status       New status.
	 * @param int    $qty          Current quantity.
	 * @param bool   $is_managed   Whether stock is managed.
	 * @return int Number of notifications queued.
	 */
	public function handle_restock( $product_id, $variation_id, $status, $qty, $is_managed ) {
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;

		if ( $product_id <= 0 ) {
			return 0;
		}

		$snapshot = WaitlistRepository::snapshot_get( $product_id, $variation_id );

		if ( null === $snapshot ) {
			// First sighting: baseline only. Never announce for a product we have
			// never observed as unavailable.
			WaitlistRepository::snapshot_put( $product_id, $variation_id, $status, $qty, $is_managed );

			return 0;
		}

		if ( ! Reconcile::is_out_of_stock( (string) $snapshot['stock_status'] ) ) {
			// Already sellable: keep the row fresh, announce nothing.
			WaitlistRepository::snapshot_put( $product_id, $variation_id, $status, $qty, $is_managed );

			return 0;
		}

		if ( ! Reconcile::should_announce(
			array( 'to_status' => $status ),
			Settings::enabled( 'notify_backorders' )
		) ) {
			WaitlistRepository::snapshot_put( $product_id, $variation_id, $status, $qty, $is_managed );

			return 0;
		}

		$claimed = WaitlistRepository::claim_transition( $product_id, $variation_id, $status, $qty, $is_managed );

		if ( ! $claimed ) {
			return 0;
		}

		$announced = $this->announce( $product_id, $variation_id, $is_managed ? $qty : null );

		WaitlistRepository::set_announced_qty( $product_id, $variation_id, $announced['baseline'] );

		return $announced['count'];
	}

	/**
	 * Queue messages for the confirmed subscribers.
	 *
	 * @param int      $product_id   Product ID.
	 * @param int      $variation_id Variation ID.
	 * @param int|null $qty          Quantity (null when unmanaged).
	 * @return array{count:int,baseline:int}
	 */
	public function announce( $product_id, $variation_id, $qty ) {
		$snapshot = WaitlistRepository::snapshot_get( $product_id, $variation_id );
		$previous = ( null !== $snapshot ) ? (int) $snapshot['announced_qty'] : 0;

		$waiting  = WaitlistRepository::count_confirmed( $product_id, $variation_id );
		$allowed  = FairSending::allowance( $qty, $previous, $waiting, Settings::enabled( 'fair_sending' ) );

		if ( 0 === $allowed ) {
			Logger::log( 'info', 'restock_no_units', $product_id . ':' . $variation_id );

			return array(
				'count'    => 0,
				'baseline' => FairSending::next_notified_qty( $qty, 0, $previous ),
			);
		}

		$rows  = WaitlistRepository::confirmed_for( $product_id, $variation_id, $allowed );
		$split = FairSending::split( $rows, $allowed );

		// A distinct key per restock event. The atomic claim in handle_restock() is
		// what prevents duplicates; the key only guarantees that two different
		// restocks never collide inside the queue's unique index.
		$event_key = 'restock:' . gmdate( 'YmdHis' ) . ':' . substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 8 );
		$count     = 0;

		foreach ( $split['selected'] as $row ) {
			$queued = Queue::enqueue(
				(int) $row['id'],
				(string) $row['channel'],
				$event_key,
				array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
				)
			);

			// Count what actually landed in the table, not what we asked for:
			// INSERT IGNORE answering 0 means the unique key already held this
			// exact message, and reporting it as "queued" is how a log ended up
			// promising two messages that were never written.
			if ( $queued && Queue::last_insert_count() > 0 ) {
				$count++;
			}
		}

		Logger::log(
			$count > 0 ? 'info' : 'warning',
			'restock_queued',
			$product_id . ':' . $variation_id . '|units=' . ( null === $qty ? 'unmanaged' : (int) $qty ) . '|queued=' . $count
				. '|waiting=' . $waiting
		);

		return array(
			'count'    => $count,
			'baseline' => FairSending::next_notified_qty( $qty, $count, $previous ),
		);
	}

	/**
	 * Store a snapshot row.
	 *
	 * @param int    $product_id   Product ID.
	 * @param int    $variation_id Variation ID.
	 * @param string $status       Status.
	 * @param int    $qty          Quantity.
	 * @param bool   $is_managed   Whether stock is managed.
	 * @return void
	 */
	private function record( $product_id, $variation_id, $status, $qty, $is_managed ) {
		if ( (int) $product_id <= 0 || '' === (string) $status ) {
			return;
		}

		WaitlistRepository::snapshot_put( (int) $product_id, (int) $variation_id, (string) $status, (int) $qty, (bool) $is_managed );
	}

	/**
	 * Run a reconcile pass.
	 *
	 * @param bool $force Ignore the interval throttle.
	 * @return array{transitions:int,queued:int,scanned:int,first_run:bool}
	 */
	public function reconcile( $force = false ) {
		$last_run = (int) get_option( 'mojoodyar_last_reconcile', 0 );

		if ( ! $force && ! Scheduler::reconcile_due( $last_run, time() ) ) {
			return array(
				'transitions' => 0,
				'queued'      => 0,
				'scanned'     => 0,
				'first_run'   => false,
			);
		}

		update_option( 'mojoodyar_last_reconcile', time(), false );

		$first_run = ( 'yes' !== get_option( 'mojoodyar_snapshot_seeded', 'no' ) );

		$scanned   = 0;
		$transitions = 0;
		$queued      = 0;

		$snapshots = WaitlistRepository::snapshots_all();
		$page      = 1;
		$pages     = 1;

		do {
			$products = $this->fetch_products( $page, 50 );
			if ( null === $products ) {
				break;
			}

			$pages = (int) $products['pages'];

			foreach ( $products['items'] as $item ) {
				$scanned++;

				$product_id   = (int) $item['product_id'];
				$variation_id = (int) $item['variation_id'];
				$key          = Reconcile::key( $product_id, $variation_id );
				$current_row  = $item;

				$diff = Reconcile::diff(
					array( $key => isset( $snapshots[ $key ] ) ? $snapshots[ $key ] : array( 'status' => '', 'qty' => 0 ) ),
					array( $key => $current_row ),
					$first_run
				);

				WaitlistRepository::snapshot_put(
					$product_id,
					$variation_id,
					(string) $current_row['status'],
					(int) $current_row['qty'],
					(bool) $current_row['is_managed']
				);

				foreach ( $diff['transitions'] as $transition ) {
					$transitions++;

					$announced = $this->announce(
						$product_id,
						$variation_id,
						$current_row['is_managed'] ? (int) $current_row['qty'] : null
					);

					$queued += $announced['count'];
					WaitlistRepository::set_announced_qty( $product_id, $variation_id, $announced['baseline'] );
				}
			}

			$page++;
		} while ( $page <= $pages && $page <= 200 );

		if ( $first_run ) {
			update_option( 'mojoodyar_snapshot_seeded', 'yes', false );
			Logger::log( 'info', 'snapshot_seeded', 'scanned=' . $scanned );
		}

		// A reconcile that found nothing is the normal case every five minutes;
		// logging it turned the log screen into five pages of noise that buried
		// the one row that mattered. The dashboard already shows the last run
		// time, which is what answers "is the cron alive?" - so only a run that
		// actually moved something is worth a row here.
		if ( $transitions > 0 || $queued > 0 ) {
			Logger::log( 'info', 'reconcile', 'scanned=' . $scanned . '|transitions=' . $transitions . '|queued=' . $queued );
		}

		return array(
			'transitions' => $transitions,
			'queued'      => $queued,
			'scanned'     => $scanned,
			'first_run'   => $first_run,
		);
	}

	/**
	 * Fetch one page of products with their stock state.
	 *
	 * Uses wc_get_products(), which is HPOS-safe: stock state lives in product
	 * meta/CPT for both storage modes, and no order query is involved.
	 *
	 * @param int $page  Page number.
	 * @param int $limit Page size.
	 * @return array{pages:int,items:array}|null Null when WooCommerce is unavailable.
	 */
	public function fetch_products( $page = 1, $limit = 50 ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return null;
		}

		$products = wc_get_products(
			array(
				'limit'   => (int) $limit,
				'page'    => (int) $page,
				'status'  => array( 'publish', 'private' ),
				'return'  => 'objects',
				'paginate' => true,
			)
		);

		if ( is_object( $products ) && isset( $products->products ) ) {
			$items = $products->products;
			$pages = isset( $products->max_num_pages ) ? (int) $products->max_num_pages : 1;
		} elseif ( is_array( $products ) ) {
			$items = $products;
			$pages = 1;
		} else {
			return array(
				'pages' => 1,
				'items' => array(),
			);
		}

		$rows = array();

		foreach ( $items as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				continue;
			}

			$rows[] = array(
				'product_id'   => (int) $product->get_id(),
				'variation_id' => 0,
				'status'       => (string) $product->get_stock_status(),
				'qty'          => (int) $product->get_stock_quantity(),
				'is_managed'   => (bool) $product->managing_stock(),
			);

			if ( ! method_exists( $product, 'get_children' ) ) {
				continue;
			}

			$children = $product->get_children();

			if ( empty( $children ) || ! function_exists( 'wc_get_product' ) ) {
				continue;
			}

			foreach ( $children as $child_id ) {
				$variation = wc_get_product( (int) $child_id );

				if ( ! $variation || ! method_exists( $variation, 'get_stock_status' ) ) {
					continue;
				}

				$rows[] = array(
					'product_id'   => (int) $product->get_id(),
					'variation_id' => (int) $child_id,
					'status'       => (string) $variation->get_stock_status(),
					'qty'          => (int) $variation->get_stock_quantity(),
					'is_managed'   => (bool) $variation->managing_stock(),
				);
			}
		}

		return array(
			'pages' => max( 1, $pages ),
			'items' => $rows,
		);
	}

	/**
	 * Rebuild every snapshot without announcing anything (admin tool).
	 *
	 * @return int Rows written.
	 */
	public function rebuild_snapshots() {
		global $wpdb;

		$table = WaitlistRepository::snapshots();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$count = 0;
		$page  = 1;
		$pages = 1;

		do {
			$batch = $this->fetch_products( $page, 50 );

			if ( null === $batch ) {
				break;
			}

			$pages = (int) $batch['pages'];

			foreach ( $batch['items'] as $item ) {
				WaitlistRepository::snapshot_put(
					(int) $item['product_id'],
					(int) $item['variation_id'],
					(string) $item['status'],
					(int) $item['qty'],
					(bool) $item['is_managed']
				);
				$count++;
			}

			$page++;
		} while ( $page <= $pages && $page <= 200 );

		update_option( 'mojoodyar_snapshot_seeded', 'yes', false );

		return $count;
	}

	/**
	 * Is this stock status sellable (and therefore a restock)?
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	private function is_sellable_status( $status ) {
		if ( 'instock' === $status ) {
			return true;
		}

		return Settings::enabled( 'notify_backorders' ) && 'onbackorder' === $status;
	}

	/**
	 * Work out whether the ID passed to a stock hook is a variation.
	 *
	 * @param int          $id      ID.
	 * @param \WC_Product  $product Product object.
	 * @return int
	 */
	private function resolve_variation_id( $id, $product = null ) {
		if ( is_object( $product ) && method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			return (int) $id;
		}

		if ( function_exists( 'get_post_type' ) && 'product_variation' === get_post_type( $id ) ) {
			return (int) $id;
		}

		return 0;
	}

	/**
	 * Parent product ID of a variation.
	 *
	 * @param int $variation_id Variation ID.
	 * @return int
	 */
	private function resolve_parent_id( $variation_id ) {
		if ( function_exists( 'wp_get_post_parent_id' ) ) {
			$parent = (int) wp_get_post_parent_id( (int) $variation_id );
			if ( $parent > 0 ) {
				return $parent;
			}
		}

		return (int) $variation_id;
	}

	/**
	 * Current quantity for a product or variation.
	 *
	 * @param int $id ID.
	 * @return int
	 */
	private function read_qty( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$product = wc_get_product( (int) $id );

		if ( ! $product || ! method_exists( $product, 'get_stock_quantity' ) ) {
			return 0;
		}

		$qty = $product->get_stock_quantity();

		return null === $qty ? 0 : (int) $qty;
	}

	/**
	 * Is stock managed for this product?
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	private function read_managed( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return true;
		}

		$product = wc_get_product( (int) $id );

		if ( ! $product || ! method_exists( $product, 'managing_stock' ) ) {
			return true;
		}

		return (bool) $product->managing_stock();
	}

	/**
	 * Stock status of a product or variation.
	 *
	 * @param int $id ID.
	 * @return string
	 */
	private function read_status( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( (int) $id );

		if ( ! $product || ! method_exists( $product, 'get_stock_status' ) ) {
			return '';
		}

		return (string) $product->get_stock_status();
	}
}
