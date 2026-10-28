<?php
/**
 * ملی‌پیامک (melipayamak.com) — SOAP-less REST endpoint.
 *
 * Endpoint and parameter names follow the public documentation of the gateway
 * (rest.payamak-panel.com/api/SendSMS/SendSMS, fields: username, password, to,
 * from, text). No code from the gateway's samples or from any competitor was
 * copied.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Channels;

use Mojoodyar\Logic\SmsPattern;
use Mojoodyar\Settings;
use Mojoodyar\Support\Validator;

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
		unset( $subject );

		$phone = Validator::normalize_phone( $to );
		if ( '' === $phone ) {
			return Http::error( 'mojoodyar_phone_invalid', 'شمارهٔ موبایل معتبر نیست.' );
		}

		if ( ! $this->is_configured() ) {
			return Http::error( 'mojoodyar_sms_config', 'ملی‌پیامک پیکربندی نشده است.' );
		}

		$body_id = trim( (string) Settings::get( 'melipayamak_body_id', '' ) );

		if ( '' !== $body_id ) {
			// Pattern-based (خدماتی) sending is mandatory for many Iranian lines.
			//
			// The gateway already holds the approved text; what it wants from us
			// is only the values, in order, separated by semicolons. Sending the
			// whole rendered message here (which is what a naive implementation
			// does) fills {0} with the entire message and every later variable
			// with nothing.
			$map     = isset( $context['context'] ) && is_array( $context['context'] ) ? $context['context'] : array();
			$pattern = SmsPattern::resolve(
				(string) Settings::get( 'sms_pattern_template', '' ),
				isset( $map['site_name'] ) ? (string) $map['site_name'] : ''
			);


			// Checked here as well as on save: a settings row can be edited by a
			// migration, an importer or a direct DB write, and the gateway bills
			// nothing but still fails the send on a broken template.
			$findings = SmsPattern::validate( $pattern, isset( $map['site_name'] ) ? (string) $map['site_name'] : '' );

			if ( SmsPattern::has_errors( $findings ) ) {
				return Http::error( 'mojoodyar_sms_pattern', 'متن الگوی پیامک ایراد دارد: ' . $findings[0]['message'] );
			}

			// A value the operator will refuse is caught here, not after the
			// gateway has already answered "InvalidData" and burned an attempt.
			// The offending variable is named with its real length, because the
			// fix is almost always "drop {2} from the approved pattern".
			$overlong = SmsPattern::overlong( $pattern, $map );

			if ( ! empty( $overlong ) ) {
				$first = $overlong[0];

				return Http::error(
					'mojoodyar_sms_value_long',
					sprintf(
						'مقدار {%s} (%s) %s کاراکتر است و از سقف %s کاراکتری متغیرهای الگو بیشتر است؛ درگاه آن را با InvalidData رد می‌کند. این متغیر را از الگوی پنل پیامک بردارید (و همان را در تنظیمات) یا مقدار کوتاه‌تری بفرستید.',
						(string) $first['index'],
						(string) $first['label'],
						\Mojoodyar\Support\Persian::number( (int) $first['length'] ),
						\Mojoodyar\Support\Persian::number( (int) SmsPattern::MAX_VALUE_LENGTH )
					)
				);
			}

			$values = SmsPattern::payload( $pattern, $map );

			if ( '' === $values ) {
				return Http::error( 'mojoodyar_sms_pattern', 'مقدارهای الگوی پیامک خالی است؛ متن الگو را در تنظیمات بررسی کنید.' );
			}

			$payload = array(
				'username' => (string) Settings::get( 'melipayamak_user', '' ),
				'password' => (string) Settings::get( 'melipayamak_pass', '' ),
				'to'       => $phone,
				'text'     => $values,
				'bodyId'   => (int) $body_id,
			);

			$url = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
		} else {
			$payload = array(
				'username' => (string) Settings::get( 'melipayamak_user', '' ),
				'password' => (string) Settings::get( 'melipayamak_pass', '' ),
				'to'       => $phone,
				'from'     => (string) Settings::get( 'melipayamak_from', '' ),
				'text'     => (string) $body,
				'isflash'  => 'false',
			);

			$url = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
		}

		$result = Http::post( $url, $payload, array(), 'form' );

		if ( ! $result['ok'] ) {
			return Http::error( 'mojoodyar_sms_http', $result['message'] );
		}

		$gateway_error = Http::detect_error( $result['body'] );

		if ( '' !== $gateway_error ) {
			// "InvalidData" is all this gateway says when it dislikes the request,
			// and the shop cannot act on it. Attach the shape of what we sent:
			// how many values, how long each one, and whether the pattern mode was
			// used. The values themselves never go in the log - they carry the
			// customer's name.
			$note = '';

			if ( '' !== $body_id ) {
				$note = 'pattern|bodyId=' . (int) $body_id . '|'
					. \Mojoodyar\Logic\SmsPattern::shape(
						$pattern,
						isset( $map ) && is_array( $map ) ? $map : array()
					);
			} else {
				$note = 'plain|from=' . ( '' === (string) Settings::get( 'melipayamak_from', '' ) ? 'empty' : 'set' );
			}

			return Http::error( 'mojoodyar_sms_gateway', $gateway_error . ' (' . $note . ')' );
		}

		return true;
	}
}
