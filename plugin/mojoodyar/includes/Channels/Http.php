<?php
/**
 * Thin HTTP wrapper used by every outbound channel.
 *
 * Rules:
 *  - only https endpoints (validated by HttpGuard for admin-supplied URLs);
 *  - 15 second timeout so a hanging gateway cannot exhaust max_execution_time;
 *  - no retries here: the queue owns the retry policy;
 *  - the response body is never echoed back to a visitor.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Class Http
 */
final class Http {

	/**
	 * POST a request and return a normalised result.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $body    Body (array for form encoding, string for raw).
	 * @param array  $headers Headers.
	 * @param string $format  "json" or "form".
	 * @return array{ok:bool,code:int,body:string,message:string}
	 */
	public static function post( $url, $body, array $headers = array(), $format = 'json' ) {
		$args = array(
			'timeout'     => 15,
			'redirection' => 2,
			'sslverify'   => true,
			'headers'     => $headers,
			'body'        => $body,
			'user-agent'  => 'Mojoodyar/' . ( defined( 'MOJODYAR_VERSION' ) ? MOJODYAR_VERSION : '2.8.1' ) . '; ' . ( function_exists( 'home_url' ) ? home_url( '/' ) : '' ),
		);

		if ( 'json' === $format && ! isset( $args['headers']['Content-Type'] ) ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			if ( is_array( $body ) ) {
				$args['body'] = json_encode( $body, JSON_UNESCAPED_UNICODE );
			}
		}

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'body'    => '',
				'message' => 'HTTP API در دسترس نیست.',
			);
		}

		$response = wp_remote_post( $url, $args );

		if ( is_object( $response ) && method_exists( $response, 'get_error_message' ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'body'    => '',
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		$ok = $code >= 200 && $code < 300;

		return array(
			'ok'      => $ok,
			'code'    => $code,
			'body'    => $raw,
			'message' => $ok ? '' : self::failure_message( $code, $raw ),
		);
	}

	/**
	 * Build the human reason for a non-2xx answer.
	 *
	 * The status code alone ("کد پاسخ درگاه: 400") is what the admin used to
	 * see in the log, and it says nothing: a 400 from an Iranian gateway is
	 * almost always a sentence in the body - wrong sender line, expired token,
	 * unapproved pattern. Dropping the body meant the log said "delivery
	 * failed" three times with no reason at all.
	 *
	 * The excerpt is stripped of markup, redacted (gateways quote the recipient
	 * back) and cut short, because this string ends up in a 255-character
	 * column and on screen.
	 *
	 * @param int    $code Status code.
	 * @param string $raw  Response body.
	 * @return string
	 */
	public static function failure_message( $code, $raw ) {
		$message = 'کد پاسخ درگاه: ' . (int) $code;

		$detail = trim( (string) $raw );

		if ( '' === $detail ) {
			return $message;
		}

		// JSON bodies are more useful decoded to their message field.
		$decoded = json_decode( $detail, true );

		if ( is_array( $decoded ) ) {
			foreach ( array( 'message', 'Message', 'error', 'Error', 'StrRetStatus', 'status' ) as $key ) {
				if ( isset( $decoded[ $key ] ) && is_scalar( $decoded[ $key ] ) && '' !== trim( (string) $decoded[ $key ] ) ) {
					$detail = trim( (string) $decoded[ $key ] );
					break;
				}
			}
		}

		$detail = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $detail ) ) );
		$detail = \Mojoodyar\Support\Masking::free_text( $detail, 120 );

		if ( '' === $detail ) {
			return $message;
		}

		return $message . ' — ' . $detail;
	}

	/**
	 * Build a WP_Error when available, otherwise a plain string.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return \WP_Error|string
	 */
	public static function error( $code, $message ) {
		if ( class_exists( '\WP_Error' ) ) {
			return new \WP_Error( $code, $message );
		}

		return $message;
	}

	/**
	 * Extract an error message from a gateway JSON response.
	 *
	 * Most Iranian gateways answer 200 with a body such as
	 * {"status":400,"message":"..."} or {"RetStatus":5,"StrRetStatus":"..."}.
	 *
	 * @param string $body Raw body.
	 * @return string Empty string when no error was found.
	 */
	public static function detect_error( $body ) {
		$body = trim( (string) $body );

		if ( '' === $body ) {
			return '';
		}

		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) ) {
			// ملی‌پیامک answers with {"Value":…,"RetStatus":1,"StrRetStatus":"Ok"}:
			// a numeric code plus a *text* status that says "Ok" on success. Any
			// generic scan for a non-empty string would read that as a failure.
			if ( isset( $decoded['RetStatus'] ) && is_numeric( $decoded['RetStatus'] ) ) {
				if ( 1 === (int) $decoded['RetStatus'] ) {
					return '';
				}

				if ( isset( $decoded['StrRetStatus'] ) && is_string( $decoded['StrRetStatus'] ) && '' !== $decoded['StrRetStatus'] ) {
					return $decoded['StrRetStatus'];
				}

				return 'کد خطای درگاه: ' . $decoded['RetStatus'];
			}

			foreach ( array( 'error', 'message', 'StrRetStatus', 'msg' ) as $key ) {
				if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) && '' !== $decoded[ $key ] ) {
					// "message" is also used for success in some gateways.
					if ( isset( $decoded['status'] ) && in_array( (string) $decoded['status'], array( '200', 'ok', 'success', 'true' ), true ) ) {
						continue;
					}

					return $decoded[ $key ];
				}
			}

			if ( isset( $decoded['status'] ) && is_numeric( $decoded['status'] ) && (int) $decoded['status'] >= 400 ) {
				return 'وضعیت درگاه: ' . $decoded['status'];
			}
		}

		return '';
	}
}
