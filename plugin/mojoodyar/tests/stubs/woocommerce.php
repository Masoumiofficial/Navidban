<?php
/**
 * WooCommerce stand-in for the integration harness.
 *
 * Products live in $GLOBALS['mjy_products'] as arrays so a test can flip stock
 * the way a real admin screen, CSV import or ERP sync would.
 *
 * @package Mojoodyar
 */

// phpcs:disable

/**
 * Product object stand-in.
 */
class WC_Product {

	/** @var int */
	public $id;

	/** @var array */
	public $data;

	/**
	 * @param int   $id   Product or variation ID.
	 * @param array $data Data row.
	 */
	public function __construct( $id, array $data ) {
		$this->id   = (int) $id;
		$this->data = $data;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_parent_id() {
		return (int) ( $this->data['parent'] ?? 0 );
	}

	public function get_stock_status() {
		return (string) ( $this->data['status'] ?? 'instock' );
	}

	public function get_stock_quantity() {
		return isset( $this->data['qty'] ) ? (int) $this->data['qty'] : null;
	}

	public function managing_stock() {
		return (bool) ( $this->data['managed'] ?? true );
	}

	public function is_in_stock() {
		return 'instock' === $this->get_stock_status();
	}

	public function is_type( $type ) {
		return ( $this->data['type'] ?? 'simple' ) === $type;
	}

	public function get_children() {
		return $this->data['children'] ?? array();
	}

	public function get_name() {
		return (string) ( $this->data['name'] ?? ( 'محصول ' . $this->id ) );
	}

	public function get_price_html() {
		return '<span class="woocommerce-Price-amount">۲۵۰,۰۰۰&nbsp;تومان</span>';
	}

	public function variation_is_active() {
		return true;
	}

	public function get_attribute_summary() {
		return (string) ( $this->data['attributes'] ?? '' );
	}
}

/**
 * @param int $id Product ID.
 * @return WC_Product|null
 */
function wc_get_product( $id ) {
	$id = (int) $id;

	if ( empty( $GLOBALS['mjy_products'][ $id ] ) ) {
		return null;
	}

	return new WC_Product( $id, $GLOBALS['mjy_products'][ $id ] );
}

/**
 * Minimal wc_get_products() with pagination, matching what StockWatcher uses.
 *
 * @param array $args Query args.
 * @return object
 */
function wc_get_products( $args = array() ) {
	$limit  = (int) ( $args['limit'] ?? 10 );
	$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
	$ids    = array();

	foreach ( $GLOBALS['mjy_products'] as $id => $row ) {
		if ( ! empty( $row['parent'] ) ) {
			continue;
		}
		$ids[] = (int) $id;
	}

	sort( $ids );

	$total  = count( $ids );
	$offset = ( $page - 1 ) * $limit;
	$slice  = array_slice( $ids, $offset, $limit );

	$products = array();
	foreach ( $slice as $id ) {
		$products[] = wc_get_product( $id );
	}

	$result                 = new stdClass();
	$result->products       = $products;
	$result->total          = $total;
	$result->max_num_pages  = (int) ceil( $total / max( 1, $limit ) );

	return $result;
}

function wc_get_orders( $args = array() ) {
	return $GLOBALS['mjy_orders'] ?? array();
}

function get_post_type( $id ) {
	unset( $id );

	return 'product';
}
