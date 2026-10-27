<?php
/**
 * SSRF guard for the generic REST adapter. Pure logic: unit-tested.
 *
 * The plugin never fetches a URL supplied by a visitor. The only outbound calls
 * go to an endpoint an administrator typed into the settings screen, and even
 * then we require https, a public hostname and no loopback/link-local/private
 * address. IP literals are rejected outright.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Class HttpGuard
 */
final class HttpGuard {

	/**
	 * Validate an administrator-supplied endpoint.
	 *
	 * @param string $url Candidate URL.
	 * @return array{ok:bool,reason:string} reason is a short machine key.
	 */
	public static function check( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return self::fail( 'empty' );
		}

		if ( strlen( $url ) > 500 ) {
			return self::fail( 'too_long' );
		}

		if ( preg_match( '/[\s\x00-\x1F]/', $url ) ) {
			return self::fail( 'whitespace' );
		}

		$parts = self::parse( $url );
		if ( null === $parts ) {
			return self::fail( 'unparsable' );
		}

		if ( 'https' !== $parts['scheme'] ) {
			return self::fail( 'scheme' );
		}

		if ( '' === $parts['host'] ) {
			return self::fail( 'no_host' );
		}

		if ( '' !== $parts['user'] || '' !== $parts['pass'] ) {
			// Credentials in the URL leak into logs and rewrite rules.
			return self::fail( 'credentials' );
		}

		if ( self::is_ip_literal( $parts['host'] ) ) {
			if ( ! self::is_public_ip( $parts['host'] ) ) {
				return self::fail( 'private_ip' );
			}
		}

		$host = strtolower( $parts['host'] );
		if ( self::is_blocked_hostname( $host ) ) {
			return self::fail( 'blocked_host' );
		}

		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * Convenience wrapper.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_allowed( $url ) {
		$result = self::check( $url );

		return $result['ok'];
	}

	/**
	 * Parse a URL into the small subset of parts we need.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	private static function parse( $url ) {
		if ( ! preg_match( '~^(?P<scheme>[a-z][a-z0-9+.-]*)://(?P<authority>[^/?#]*)(?P<rest>.*)$~i', $url, $m ) ) {
			return null;
		}

		$authority = $m['authority'];
		$user      = '';
		$pass      = '';

		if ( false !== strpos( $authority, '@' ) ) {
			$at         = strrpos( $authority, '@' );
			$credential = substr( $authority, 0, $at );
			$authority  = substr( $authority, $at + 1 );

			if ( false !== strpos( $credential, ':' ) ) {
				list( $user, $pass ) = explode( ':', $credential, 2 );
			} else {
				$user = $credential;
			}
		}

		$host = $authority;
		if ( '[' === substr( $authority, 0, 1 ) ) {
			$closing = strpos( $authority, ']' );
			if ( false !== $closing ) {
				$host = substr( $authority, 1, $closing - 1 );
			}
		} elseif ( false !== strpos( $authority, ':' ) ) {
			$host = substr( $authority, 0, (int) strrpos( $authority, ':' ) );
		}

		return array(
			'scheme' => strtolower( $m['scheme'] ),
			'host'   => trim( $host, '[]' ),
			'user'   => $user,
			'pass'   => $pass,
		);
	}

	/**
	 * Is the host an IP literal (IPv4 or IPv6)?
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_ip_literal( $host ) {
		return (bool) filter_var( $host, FILTER_VALIDATE_IP );
	}

	/**
	 * Is an IP public?
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Hostnames that always resolve to the local machine or a metadata service.
	 *
	 * @param string $host Lower-cased host.
	 * @return bool
	 */
	public static function is_blocked_hostname( $host ) {
		$host = strtolower( trim( $host, '.' ) );

		$blocked = array(
			'localhost',
			'localhost.localdomain',
			'ip6-localhost',
			'ip6-loopback',
			'metadata.google.internal',
			'instance-data',
		);

		if ( in_array( $host, $blocked, true ) ) {
			return true;
		}

		foreach ( array( '.localhost', '.internal', '.local', '.localdomain', '.home.arpa' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $reason Failure reason.
	 * @return array{ok:bool,reason:string}
	 */
	private static function fail( $reason ) {
		return array(
			'ok'     => false,
			'reason' => $reason,
		);
	}
}
