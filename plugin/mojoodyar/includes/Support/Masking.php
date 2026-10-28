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
	 * Redact contact data inside a free-text string (an error message).
	 *
	 * Gateways quote the recipient back in their error text, so a raw message
	 * cannot go into the log as-is: `شماره 09121234567 مسدود است` would put a
	 * customer's mobile number in a table the admin exports to CSV. This masks
	 * mobile-like digit runs and any e-mail address, and leaves the rest of the
	 * sentence intact - the reason is what makes the log useful.
	 *
	 * @param string $text   Free text.
	 * @param int    $length Maximum length to keep (characters).
	 * @return string
	 */
	public static function free_text( $text, $length = 160 ) {
		$text = (string) $text;

		// 09xxxxxxxxx / +989xxxxxxxxx / 9xxxxxxxxx inside a sentence.
		$text = (string) preg_replace( '/(\+?98|0)?9\d{9}/', '09*********', $text );

		// E-mail addresses.
		$text = (string) preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '***@***', $text );

		// Collapse whitespace so one log row stays one line.
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		if ( $length > 0 && \Mojoodyar\Support\Text::length( $text ) > $length ) {
			$text = \Mojoodyar\Support\Text::substring( $text, 0, $length ) . '…';
		}

		return $text;
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
