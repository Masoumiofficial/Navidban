<?php
/**
 * Consent, double opt-in tokens and public URLs.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

defined( 'ABSPATH' ) || exit;

/**
 * Class Consent
 */
final class Consent {

	/**
	 * Query var used by the confirmation link.
	 *
	 * @var string
	 */
	const CONFIRM_VAR = 'mojoodyar_confirm';

	/**
	 * Query var used by the unsubscribe link.
	 *
	 * @var string
	 */
	const UNSUBSCRIBE_VAR = 'mojoodyar_unsubscribe';

	/**
	 * Generate a 64-char hex token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return bin2hex( random_bytes( 32 ) );
			} catch ( \Exception $e ) {
				// Fall through to the WordPress generator below.
				unset( $e );
			}
		}

		if ( function_exists( 'wp_generate_password' ) ) {
			return substr( hash( 'sha256', wp_generate_password( 64, true, true ) ), 0, 64 );
		}

		return substr( hash( 'sha256', uniqid( (string) mt_rand(), true ) ), 0, 64 );
	}

	/**
	 * Public confirmation URL for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function confirm_url( $token ) {
		return self::build_url( self::CONFIRM_VAR, $token );
	}

	/**
	 * Public unsubscribe URL for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function unsubscribe_url( $token ) {
		return self::build_url( self::UNSUBSCRIBE_VAR, $token );
	}

	/**
	 * Build a signed-looking public URL.
	 *
	 * @param string $var   Query var.
	 * @param string $token Token.
	 * @return string
	 */
	private static function build_url( $var, $token ) {
		$base = function_exists( 'home_url' ) ? home_url( '/' ) : '/';

		return add_query_arg_compat( $var, rawurlencode( (string) $token ), $base );
	}

	/**
	 * Hash the visitor IP. Raw IPs are never stored.
	 *
	 * @return string
	 */
	public static function ip_hash() {
		$ip = '';

		if ( function_exists( 'apply_filters' ) ) {
			// Respect reverse-proxy setups that expose the real client address.
			$ip = (string) apply_filters( 'mojoodyar_client_ip', self::detect_ip() );
		} else {
			$ip = self::detect_ip();
		}

		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : ( defined( 'NONCE_SALT' ) ? NONCE_SALT : 'mojoodyar' );

		return \Mojoodyar\Support\Masking::ip_hash( $ip, $salt );
	}

	/**
	 * Best-effort client IP from REMOTE_ADDR only. Proxy headers are ignored by
	 * default because they are trivially spoofable; sites behind Cloudflare can
	 * opt in through the mojoodyar_client_ip filter.
	 *
	 * @return string
	 */
	private static function detect_ip() {
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_server_value( $_SERVER['REMOTE_ADDR'] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Time remaining on a confirmation link, in hours.
	 *
	 * @param array $row Waitlist row.
	 * @return int
	 */
	public static function hours_left( array $row ) {
		if ( empty( $row['confirm_expires_at'] ) ) {
			return 0;
		}

		$expires = strtotime( $row['confirm_expires_at'] );
		if ( false === $expires ) {
			return 0;
		}

		return max( 0, (int) floor( ( $expires - time() ) / 3600 ) );
	}

	/**
	 * Whether the consent checkbox must be shown and enforced.
	 *
	 * @return bool
	 */
	public static function requires_checkbox() {
		return Settings::enabled( 'consent_required' );
	}

	/**
	 * Privacy policy URL to link from the checkbox.
	 *
	 * @return string
	 */
	public static function privacy_url() {
		$url = (string) Settings::get( 'privacy_url', '' );

		if ( '' !== $url ) {
			return $url;
		}

		if ( function_exists( 'get_privacy_policy_url' ) ) {
			return (string) get_privacy_policy_url();
		}

		return '';
	}
}

/**
 * add_query_arg() exists in WordPress; the plugin must also work in the bare
 * test harness, so a tiny compatible helper is provided.
 *
 * @param string $key   Query key.
 * @param string $value Query value.
 * @param string $url   Base URL.
 * @return string
 */
function add_query_arg_compat( $key, $value, $url ) {
	if ( function_exists( 'add_query_arg' ) ) {
		return add_query_arg( $key, $value, $url );
	}

	$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';

	return $url . $separator . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

/**
 * sanitize_text_field() exists in WordPress; fallback for the test harness.
 *
 * @param mixed $value Value.
 * @return string
 */
function sanitize_server_value( $value ) {
	if ( function_exists( 'sanitize_text_field' ) ) {
		return (string) sanitize_text_field( $value );
	}

	return trim( (string) $value );
}
