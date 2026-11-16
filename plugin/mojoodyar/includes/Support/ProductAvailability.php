<?php
/**
 * Product and variation checks shared by the storefront and subscription API.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProductAvailability
 */
final class ProductAvailability {

	/**
	 * A public form must not expose unpublished or password-protected products.
	 *
	 * @param object|null $product Product.
	 * @return bool
	 */
	public static function public_product( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' )
			|| ! method_exists( $product, 'is_type' ) || ! method_exists( $product, 'is_in_stock' )
			|| (int) $product->get_id() <= 0 || $product->is_type( 'variation' ) ) {
			return false;
		}

		if ( method_exists( $product, 'get_status' ) && 'publish' !== $product->get_status() ) {
			return false;
		}

		return ! function_exists( 'post_password_required' ) || ! post_password_required( $product->get_id() );
	}

	/**
	 * Check ownership, publication and WooCommerce's invisible-variation
	 * policy. An inactive variation is never offered, even if a custom filter
	 * exposes otherwise invisible variations.
	 *
	 * @param object $parent    Variable product.
	 * @param object $variation Candidate variation.
	 * @return bool
	 */
	public static function selectable_variation( $parent, $variation ) {
		if ( ! self::public_product( $parent ) || ! $parent->is_type( 'variable' )
			|| ! is_object( $variation ) || ! method_exists( $variation, 'is_type' )
			|| ! $variation->is_type( 'variation' ) || ! method_exists( $variation, 'get_parent_id' )
			|| ! method_exists( $variation, 'is_in_stock' ) ) {
			return false;
		}

		if ( (int) $variation->get_parent_id() !== (int) $parent->get_id()
			|| ( method_exists( $variation, 'exists' ) && ! $variation->exists() )
			|| ( method_exists( $variation, 'get_status' ) && 'publish' !== $variation->get_status() )
			|| ( method_exists( $variation, 'variation_is_active' ) && ! $variation->variation_is_active() ) ) {
			return false;
		}

		$hide_invisible = apply_filters( 'woocommerce_hide_invisible_variations', true, (int) $parent->get_id(), $variation );
		if ( $hide_invisible && method_exists( $variation, 'variation_is_visible' ) && ! $variation->variation_is_visible() ) {
			return false;
		}

		return true;
	}

	/**
	 * A waitlist choice must be active, visible and currently out of stock.
	 * WooCommerce's catalog setting may hide it from the purchase selector;
	 * the separate waitlist selector is intentionally still allowed to offer it.
	 * This never changes the catalog setting or WooCommerce's purchase options.
	 *
	 * @param object $parent    Variable product.
	 * @param object $variation Candidate variation.
	 * @return bool
	 */
	public static function unavailable_variation( $parent, $variation ) {
		return self::selectable_variation( $parent, $variation ) && ! $variation->is_in_stock();
	}
}
