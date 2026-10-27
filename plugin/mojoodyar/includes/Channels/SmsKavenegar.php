<?php
/**
 * کاوه‌نگار (kavenegar.com) — REST API.
 *
 * Endpoint follows the public documentation:
 * GET https://api.kavenegar.com/v1/{API-KEY}/sms/send.json?receptor=&sender=&message=
 *
 * The API key travels in the path, so it is never logged: log rows contain only
 * the channel label and the outcome.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Channels;

use Mojoodyar\Settings;
use Mojoodyar\Support\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Class SmsKavenegar
 */
final class SmsKavenegar implements ChannelInterface {

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'sms:kavenegar';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'پیامک — کاوه‌نگار';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		if ( ! Settings::enabled( 'sms_enabled' ) ) {
			return false;
		}

		return '' !== (string) Settings::get( 'kavenegar_api_key', '' );
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

		if ( ! $this->is_configured() ) {
			return Http::error( 'mojoodyar_sms_config', 'کاوه‌نگار پیکربندی نشده است.' );
		}

		$url = sprintf(
			'https://api.kavenegar.com/v1/%s/sms/send.json',
			rawurlencode( (string) Settings::get( 'kavenegar_api_key', '' ) )
		);

		$payload = array(
			'receptor' => $phone,
			'message'  => (string) $body,
		);

		$sender = (string) Settings::get( 'kavenegar_sender', '' );
		if ( '' !== $sender ) {
			$payload['sender'] = $sender;
		}

		$result = Http::post( $url, $payload, array(), 'form' );

		if ( ! $result['ok'] ) {
			return Http::error( 'mojoodyar_sms_http', $result['message'] );
		}

		$gateway_error = Http::detect_error( $result['body'] );
		if ( '' !== $gateway_error ) {
			return Http::error( 'mojoodyar_sms_gateway', $gateway_error );
		}

		return true;
	}
}
