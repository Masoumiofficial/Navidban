<?php
/**
 * Build a small disposable WooCommerce catalog inside WordPress Playground.
 * Invoked by playground-wordpress.cjs, never by the production plugin.
 *
 * @package Mojoodyar
 */

require '/wordpress/wp-load.php';

update_option( 'admin_email', 'owner@example.com' );
update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
switch_theme( 'twentytwentyone' );

$simple = new WC_Product_Simple();
$simple->set_name( 'Simple sold out' );
$simple->set_status( 'publish' );
$simple->set_regular_price( '25' );
$simple->set_stock_status( 'outofstock' );
$simple_id = $simple->save();

$available = new WC_Product_Simple();
$available->set_name( 'Simple in stock' );
$available->set_status( 'publish' );
$available->set_regular_price( '25' );
$available->set_stock_status( 'instock' );
$available_id = $available->save();

$no_price = new WC_Product_Simple();
$no_price->set_name( 'Sold out without price' );
$no_price->set_status( 'publish' );
$no_price->set_stock_status( 'outofstock' );
$no_price_id = $no_price->save();

$draft = new WC_Product_Simple();
$draft->set_name( 'Draft sold out' );
$draft->set_status( 'draft' );
$draft->set_stock_status( 'outofstock' );
$draft_id = $draft->save();

$variable = new WC_Product_Variable();
$variable->set_name( 'Shoe' );
$variable->set_status( 'publish' );
$attribute = new WC_Product_Attribute();
$attribute->set_id( 0 );
$attribute->set_name( 'Size' );
$attribute->set_options( array( '42', '43' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( array( $attribute ) );
$variable_id = $variable->save();

$variation_available = new WC_Product_Variation();
$variation_available->set_parent_id( $variable_id );
$variation_available->set_attributes( array( 'size' => '42' ) );
$variation_available->set_regular_price( '30' );
$variation_available->set_stock_status( 'instock' );
$variation_available_id = $variation_available->save();

$variation_unavailable = new WC_Product_Variation();
$variation_unavailable->set_parent_id( $variable_id );
$variation_unavailable->set_attributes( array( 'size' => '43' ) );
$variation_unavailable->set_regular_price( '30' );
$variation_unavailable->set_stock_status( 'outofstock' );
$variation_unavailable_id = $variation_unavailable->save();
WC_Product_Variable::sync( $variable_id );

$other = new WC_Product_Variable();
$other->set_name( 'Other product' );
$other->set_status( 'publish' );
$other_attribute = new WC_Product_Attribute();
$other_attribute->set_id( 0 );
$other_attribute->set_name( 'Color' );
$other_attribute->set_options( array( 'blue' ) );
$other_attribute->set_visible( true );
$other_attribute->set_variation( true );
$other->set_attributes( array( $other_attribute ) );
$other_id = $other->save();
$other_variation = new WC_Product_Variation();
$other_variation->set_parent_id( $other_id );
$other_variation->set_attributes( array( 'color' => 'blue' ) );
$other_variation->set_regular_price( '30' );
$other_variation->set_stock_status( 'outofstock' );
$other_variation_id = $other_variation->save();
WC_Product_Variable::sync( $other_id );

$page_id = wp_insert_post(
	array(
		'post_title'   => 'Shortcode Page',
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => '[mojoodyar_form product_id="' . $simple_id . '"]',
	)
);

$draft_page_id = wp_insert_post(
	array(
		'post_title'   => 'Draft Shortcode Page',
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => '[mojoodyar_form product_id="' . $draft_id . '"]',
	)
);

echo wp_json_encode(
	array(
		'simple'                => $simple_id,
		'available'             => $available_id,
		'no_price'              => $no_price_id,
		'draft'                 => $draft_id,
		'variable'              => $variable_id,
		'variation_available'   => $variation_available_id,
		'variation_unavailable' => $variation_unavailable_id,
		'other_variation'       => $other_variation_id,
		'page'                  => $page_id,
		'draft_page'            => $draft_page_id,
	)
);
