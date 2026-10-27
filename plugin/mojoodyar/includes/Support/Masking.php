<?php
/**
 * Data minimisation helpers for admin screens and logs. Pure logic.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class Masking
 */
final class Masking {

	/**
	 * Mask a mobile number: 09121234567 -> 0912***4567
	 *
	 * @param string $phone Canonical phone.
	 * @return string
	 */
	public static function phone( $phone ) {
		$phone = (string) $phone;
		$len   = strlen( $phone );

		if ( $len < 8 ) {
			return str_repeat( '*', max( 0, $len ) );
		}

		return substr( $phone, 0, 4 ) . '***' . substr( $phone, -4 );
	}

	/**
	 * Mask an email address: ali@example.com -> a***@example.com
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	public static function email( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return str_repeat( '*', max( 0, strlen( $email ) ) );
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );

		$keep = ( strlen( $local ) > 2 ) ? 1 : 1;

		return substr( $local, 0, $keep ) . '***' . $domain;
	}

	/**
	 * Hash an IP address with the site salt. Raw IPs are never stored.
	 *
	 * @param string $ip   IP address.
	 * @param string $salt Site salt.
	 * @return string 64-char hex hash.
	 */
	public static function ip_hash( $ip, $salt ) {
		return hash( 'sha256', (string) $ip . '|' . (string) $salt );
	}
}
