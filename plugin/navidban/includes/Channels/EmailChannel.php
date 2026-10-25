<?php
/**
 * Email channel (wp_mail, no external service).
 *
 * @package Navidban
 */

namespace Navidban\Channels;

use Navidban\Settings;
use Navidban\Support\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Class EmailChannel
 */
final class EmailChannel implements ChannelInterface {

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'email';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'ایمیل';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return (bool) Settings::enabled( 'email_enabled' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $to      Address.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $context Context.
	 * @return true|\WP_Error|string
	 */
	public function send( $to, $subject, $body, array $context = array() ) {
		$to = Validator::normalize_email( $to );

		if ( '' === $to ) {
			return Http::error( 'navidban_email_invalid', 'نشانی ایمیل معتبر نیست.' );
		}

		if ( ! function_exists( 'wp_mail' ) ) {
			return Http::error( 'navidban_no_mail', 'تابع ایمیل در دسترس نیست.' );
		}

		$from_name  = (string) Settings::get( 'site_name', '' );
		$from_email = function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '';

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		if ( '' !== $from_email && Validator::is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $this->sanitize_header( $from_name ), $from_email );
			$headers[] = sprintf( 'Reply-To: %s', $from_email );
		}

		$subject = $this->sanitize_header( $subject );

		$plain = $this->convert_links( (string) $body );

		$sent = wp_mail( $to, $subject, $plain, $headers );

		if ( ! $sent ) {
			return Http::error( 'navidban_mail_failed', 'wp_mail ارسال را نپذیرفت (تنظیمات SMTP هاست را بررسی کنید).' );
		}

		return true;
	}

	/**
	 * Strip CR/LF so a value can never inject an extra header.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function sanitize_header( $value ) {
		$value = str_replace( array( "\r", "\n", '%0a', '%0d' ), ' ', (string) $value );

		return trim( $value );
	}

	/**
	 * Wrap bare URLs in angle brackets so long links do not break in mail clients.
	 *
	 * @param string $body Body.
	 * @return string
	 */
	private function convert_links( $body ) {
		return preg_replace_callback(
			'~(?<!<)(https?://[^\s<>"]+)~i',
			function ( $matches ) {
				return '<' . $matches[1] . '>';
			},
			$body
		);
	}
}
