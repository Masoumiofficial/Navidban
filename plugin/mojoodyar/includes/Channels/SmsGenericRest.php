<?php
/**
 * Generic REST SMS adapter for every other Iranian gateway.
 *
 * The administrator supplies:
 *  - an https endpoint (validated by HttpGuard: no private/loopback hosts),
 *  - an optional bearer token,
 *  - a JSON body template with {to} and {message} placeholders.
 *
 * No visitor input ever reaches the endpoint URL. The template is escaped so a
 * message cannot inject JSON syntax and break the payload.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Channels;

use Mojoodyar\Logic\HttpGuard;
use Mojoodyar\Settings;
use Mojoodyar\Support\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SmsGenericRest
 */
final class SmsGenericRest implements ChannelInterface {

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'sms:generic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'پیامک — درگاه عمومی REST';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		if ( ! Settings::enabled( 'sms_enabled' ) ) {
			return false;
		}

		return HttpGuard::is_allowed( (string) Settings::get( 'generic_url', '' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $to      Canonical phone.
	 * @param string $subject Unused.
	 * @param string $body    Message.
	 * @param array  $context Context.
	 * @return true|\WP_Error|string
	 */
	public function send( $to, $subject, $body, array $context = array() ) {
		unset( $subject, $context );

		$phone = Validator::normalize_phone( $to );
		if ( '' === $phone ) {
			return Http::error( 'mojoodyar_phone_invalid', 'شمارهٔ موبایل معتبر نیست.' );
		}

		$url = (string) Settings::get( 'generic_url', '' );
		$check = HttpGuard::check( $url );

		if ( ! $check['ok'] ) {
			return Http::error( 'mojoodyar_endpoint_rejected', 'نشانی درگاه پذیرفته نشد (' . $check['reason'] . ').' );
		}

		$template = (string) Settings::get( 'generic_payload', '' );
		$payload  = $this->render_payload( $template, $phone, (string) $body );

		if ( '' === $payload ) {
			return Http::error( 'mojoodyar_payload', 'قالب دادهٔ درگاه خالی یا نامعتبر است.' );
		}

		$headers = array();
		$token   = (string) Settings::get( 'generic_token', '' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$result = Http::post( $url, $payload, $headers, 'json' );

		if ( ! $result['ok'] ) {
			return Http::error( 'mojoodyar_sms_http', $result['message'] );
		}

		$gateway_error = Http::detect_error( $result['body'] );
		if ( '' !== $gateway_error ) {
			return Http::error( 'mojoodyar_sms_gateway', $gateway_error );
		}

		return true;
	}

	/**
	 * Substitute {to} and {message} inside a JSON template without breaking it.
	 *
	 * @param string $template JSON template.
	 * @param string $to       Phone.
	 * @param string $message  Message.
	 * @return string
	 */
	public function render_payload( $template, $to, $message ) {
		$template = trim( (string) $template );

		if ( '' === $template ) {
			return '';
		}

		$to_json   = json_encode( $to, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$body_json = json_encode( $message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $to_json || false === $body_json ) {
			return '';
		}

		// Remove exactly the two outer quotes. trim( $x, '"' ) would also eat the
		// quote of a trailing \" escape and corrupt the payload.
		$to_value   = substr( $to_json, 1, -1 );
		$body_value = substr( $body_json, 1, -1 );

		$rendered = str_replace(
			array( '"{to}"', '"{message}"', '{to}', '{message}' ),
			array( '"' . $to_value . '"', '"' . $body_value . '"', $to_value, $body_value ),
			$template
		);

		$decoded = json_decode( $rendered, true );

		if ( ! is_array( $decoded ) ) {
			return '';
		}

		return $rendered;
	}
}
