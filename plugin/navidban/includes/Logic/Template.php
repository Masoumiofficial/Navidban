<?php
/**
 * Message template rendering. Pure logic: unit-tested.
 *
 * Only an explicit allow-list of placeholders is replaced. Unknown placeholders
 * stay untouched so a typo never silently becomes an empty string.
 *
 * @package Navidban
 */

namespace Navidban\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template
 */
final class Template {

	/**
	 * Placeholders supported in every channel.
	 *
	 * @return string[]
	 */
	public static function placeholders() {
		return array(
			'site_name',
			'product_name',
			'variation_name',
			'product_url',
			'price',
			'customer_name',
			'unsubscribe_url',
			'confirm_url',
			'waitlist_id',
			'store_url',
		);
	}

	/**
	 * Render a template.
	 *
	 * @param string $template Raw template.
	 * @param array  $values   Map of placeholder => value.
	 * @return string
	 */
	public static function render( $template, array $values ) {
		$template = (string) $template;
		$allowed  = self::placeholders();

		$search  = array();
		$replace = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}

			$search[]  = '{' . $key . '}';
			$replace[] = is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
		}

		return str_replace( $search, $replace, $template );
	}

	/**
	 * Placeholders used in a template (for the admin "preview" panel).
	 *
	 * @param string $template Template.
	 * @return string[]
	 */
	public static function used( $template ) {
		$found = array();
		if ( preg_match_all( '/\{([a-z_]+)\}/', (string) $template, $matches ) ) {
			$found = array_values( array_unique( $matches[1] ) );
		}

		return $found;
	}

	/**
	 * Placeholders present in the template but not supported (admin warning).
	 *
	 * @param string $template Template.
	 * @return string[]
	 */
	public static function unsupported( $template ) {
		return array_values( array_diff( self::used( $template ), self::placeholders() ) );
	}

	/**
	 * SMS messages are billed per segment; this estimates segments at 70 chars
	 * (Persian messages are UCS-2 encoded by Iranian gateways).
	 *
	 * @param string $message Message.
	 * @return int
	 */
	public static function sms_segments( $message ) {
		$length = \Navidban\Support\Text::length( $message );

		if ( $length <= 70 ) {
			return 1;
		}

		return (int) ceil( ( $length - 70 ) / 67 ) + 1;
	}
}
