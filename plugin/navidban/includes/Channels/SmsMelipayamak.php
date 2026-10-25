<?php
/**
 * ملی‌پیامک (melipayamak.com) — SOAP-less REST endpoint.
 *
 * Endpoint and parameter names follow the public documentation of the gateway
 * (rest.payamak-panel.com/api/SendSMS/SendSMS, fields: username, password, to,
 * from, text). No code from the gateway's samples or from any competitor was
 * copied.
 *
 * @package Navidban
 */

namespace Navidban\Channels;

use Navidban\Settings;
use Navidban\Support\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SmsMelipayamak
 */
final class SmsMelipayamak implements ChannelInterface {

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'sms:melipayamak';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'پیامک — ملی‌پیامک';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		if ( ! Settings::enabled( 'sms_enabled' ) ) {
			return false;
		}

		return '' !== (string) Settings::get( 'melipayamak_user', '' )
			&& '' !== (string) Settings::get( 'melipayamak_pass', '' );
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
			return Http::error( 'navidban_phone_invalid', 'شمارهٔ موبایل معتبر نیست.' );
		}

		if ( ! $this->is_configured() ) {
			return Http::error( 'navidban_sms_config', 'ملی‌پیامک پیکربندی نشده است.' );
		}

		$payload = array(
			'username' => (string) Settings::get( 'melipayamak_user', '' ),
			'password' => (string) Settings::get( 'melipayamak_pass', '' ),
			'to'       => $phone,
			'from'     => (string) Settings::get( 'melipayamak_from', '' ),
			'text'     => (string) $body,
			'isflash'  => 'false',
		);

		$body_id = trim( (string) Settings::get( 'melipayamak_body_id', '' ) );

		if ( '' !== $body_id ) {
			// Pattern-based (خدماتی) sending is mandatory for many Iranian lines.
			$payload['bodyId'] = (int) $body_id;
			$url               = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
		} else {
			$url = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
		}

		$result = Http::post( $url, $payload, array(), 'form' );

		if ( ! $result['ok'] ) {
			return Http::error( 'navidban_sms_http', $result['message'] );
		}

		$gateway_error = Http::detect_error( $result['body'] );
		if ( '' !== $gateway_error ) {
			return Http::error( 'navidban_sms_gateway', $gateway_error );
		}

		return true;
	}
}
