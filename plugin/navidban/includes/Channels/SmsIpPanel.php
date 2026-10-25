<?php
/**
 * آی‌پی‌پنل (ippanel.com) — v1 REST API.
 *
 * Endpoint follows the public documentation:
 * POST https://api2.ippanel.com/api/v1/sms/send/webservice/single
 * header: apikey
 * body:   {"recipient":["09..."],"sender":"+98...","message":"..."}
 *
 * @package Navidban
 */

namespace Navidban\Channels;

use Navidban\Settings;
use Navidban\Support\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SmsIpPanel
 */
final class SmsIpPanel implements ChannelInterface {

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'sms:ippanel';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'پیامک — آی‌پی‌پنل';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		if ( ! Settings::enabled( 'sms_enabled' ) ) {
			return false;
		}

		return '' !== (string) Settings::get( 'ippanel_token', '' );
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
			return Http::error( 'navidban_sms_config', 'آی‌پی‌پنل پیکربندی نشده است.' );
		}

		$payload = array(
			'recipient' => array( $phone ),
			'sender'    => (string) Settings::get( 'ippanel_from', '' ),
			'message'   => (string) $body,
		);

		$result = Http::post(
			'https://api2.ippanel.com/api/v1/sms/send/webservice/single',
			$payload,
			array( 'apikey' => (string) Settings::get( 'ippanel_token', '' ) ),
			'json'
		);

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
