<?php
/**
 * Subscribe use-case. Shared by the REST endpoint and the admin-ajax fallback so
 * both paths enforce exactly the same rules.
 *
 * @package Navidban
 */

namespace Navidban;

use Navidban\Support\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Subscribe
 */
final class Subscribe {

	/**
	 * Maximum registrations per IP hash per hour.
	 *
	 * @var int
	 */
	const RATE_LIMIT = 5;

	/**
	 * Handle one subscribe request.
	 *
	 * Expected (already unslashed) input keys:
	 *  - product_id, variation_id, channel, email, phone, consent, event_type.
	 *
	 * @param array $input Raw input.
	 * @return array{ok:bool,code:string,status:int,message:string,data:array}
	 */
	public static function handle( array $input ) {
		$product_id   = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
		$variation_id = isset( $input['variation_id'] ) ? (int) $input['variation_id'] : 0;
		$channel      = isset( $input['channel'] ) ? (string) $input['channel'] : 'email';
		$email        = isset( $input['email'] ) ? Validator::normalize_email( $input['email'] ) : '';
		$phone        = isset( $input['phone'] ) ? Validator::normalize_phone( $input['phone'] ) : '';
		$consent      = ! empty( $input['consent'] );
		$event_type   = isset( $input['event_type'] ) ? (string) $input['event_type'] : 'restock';

		$ip_hash = Consent::ip_hash();

		if ( 'restock' !== $event_type ) {
			// Version 1.0 only ships the restock event.
			$event_type = 'restock';
		}

		if ( $product_id <= 0 ) {
			return self::fail( 'invalid_product', 400, 'محصول مشخص نیست.', $ip_hash );
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return self::fail( 'no_woocommerce', 500, 'ووکامرس فعال نیست.', $ip_hash );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return self::fail( 'invalid_product', 404, 'محصول یافت نشد.', $ip_hash );
		}

		// The form only exists for unavailable products; refuse anything else so
		// the endpoint cannot be used to harvest addresses on buyable products.
		if ( $product->is_in_stock() && ! self::variation_is_unavailable( $product_id, $variation_id ) ) {
			return self::fail( 'product_available', 409, 'این محصول موجود است.', $ip_hash );
		}

		if ( (int) Settings::enabled( 'consent_required' ) && ! $consent ) {
			return self::fail( 'consent_required', 422, 'رضایت لازم است.', $ip_hash );
		}

		// A visitor who asked for SMS with a broken number must get a phone error,
		// not a confusing "invalid email" after a silent channel switch.
		if ( 'sms' === $channel && '' === $phone ) {
			return self::fail( 'invalid_phone', 422, 'شمارهٔ موبایل معتبر نیست.', $ip_hash );
		}

		if ( 'email' === $channel && '' === $email && '' === $phone ) {
			return self::fail( 'invalid_email', 422, 'ایمیل معتبر نیست.', $ip_hash );
		}

		// Channel resolution honours what the store can actually deliver: an
		// enabled-but-unconfigured SMS gateway must never be chosen.
		$email_on = Notifier::email_ready();
		$sms_on   = Notifier::sms_ready();

		if ( ! $email_on && ! $sms_on ) {
			return self::fail( 'no_channel', 503, 'هیچ کانال اعلانی فعال نیست.', $ip_hash );
		}

		if ( 'sms' === $channel && ! $sms_on ) {
			$channel = 'email';
		}
		if ( 'email' === $channel && ! $email_on ) {
			$channel = 'sms';
		}

		if ( 'email' === $channel && '' === $email && '' !== $phone && $sms_on ) {
			$channel = 'sms';
		}
		if ( 'sms' === $channel && '' === $phone && '' !== $email && $email_on ) {
			$channel = 'email';
		}

		if ( 'email' === $channel && '' === $email ) {
			return self::fail( 'invalid_email', 422, 'ایمیل معتبر نیست.', $ip_hash );
		}

		if ( 'sms' === $channel && '' === $phone ) {
			return self::fail( 'invalid_phone', 422, 'شمارهٔ موبایل معتبر نیست.', $ip_hash );
		}

		if ( Logger::count_recent( $ip_hash, 60 ) >= self::RATE_LIMIT ) {
			return self::fail( 'rate_limited', 429, 'تعداد تلاش‌ها زیاد است. کمی بعد دوباره تلاش کنید.', $ip_hash );
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		$stock_at_signup = 0;
		if ( method_exists( $product, 'get_stock_quantity' ) ) {
			$qty             = $product->get_stock_quantity();
			$stock_at_signup = null === $qty ? 0 : (int) $qty;
		}

		$result = WaitlistRepository::create(
			array(
				'product_id'      => $product_id,
				'variation_id'    => $variation_id,
				'event_type'      => $event_type,
				'channel'         => $channel,
				'email'           => $email,
				'phone'           => $phone,
				'user_id'         => $user_id,
				'stock_at_signup' => $stock_at_signup,
				'ip_hash'         => $ip_hash,
			)
		);

		if ( $result['duplicate'] ) {
			Logger::log( 'info', 'subscribe_duplicate', 'product=' . $product_id, $ip_hash );

			return array(
				'ok'      => true,
				'code'    => 'duplicate',
				'status'  => 200,
				'message' => 'این شماره یا ایمیل پیش‌تر برای همین محصول ثبت شده است.',
				'data'    => array( 'id' => (int) $result['id'] ),
			);
		}

		if ( ! $result['ok'] ) {
			Logger::log( 'warning', 'subscribe_failed', 'reason=' . $result['reason'], $ip_hash );

			return self::fail( 'subscribe_failed', 500, 'ثبت انجام نشد. بعداً دوباره تلاش کنید.', $ip_hash );
		}

		Logger::log( 'info', 'subscribe_ok', 'product=' . $product_id . '|channel=' . $channel, $ip_hash );

		$double = Settings::enabled( 'double_opt_in' );

		return array(
			'ok'      => true,
			'code'    => $double ? 'confirmation_sent' : 'subscribed',
			'status'  => 201,
			'message' => $double
				? 'یک پیام تأیید برای شما ارسال شد. برای تکمیل ثبت، پیوند داخل آن را باز کنید.'
				: (string) Settings::get( 'success_message', '' ),
			'data'    => array(
				'id'          => (int) $result['id'],
				'needs_confirm' => $double,
			),
		);
	}

	/**
	 * Build a failure response (and log it for the rate limiter).
	 *
	 * @param string $code    Machine code.
	 * @param int    $status  HTTP status.
	 * @param string $message Persian message.
	 * @param string $ip_hash IP hash.
	 * @return array
	 */
	private static function fail( $code, $status, $message, $ip_hash ) {
		Logger::log( 'security', 'subscribe_rejected', $code, $ip_hash );

		return array(
			'ok'      => false,
			'code'    => $code,
			'status'  => (int) $status,
			'message' => $message,
			'data'    => array(),
		);
	}

	/**
	 * Is a specific variation unavailable?
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @return bool
	 */
	private static function variation_is_unavailable( $product_id, $variation_id ) {
		if ( (int) $variation_id <= 0 ) {
			return false;
		}

		$variation = wc_get_product( (int) $variation_id );

		if ( ! $variation ) {
			return false;
		}

		// The variation must belong to the product it claims.
		if ( method_exists( $variation, 'get_parent_id' ) && (int) $variation->get_parent_id() !== (int) $product_id ) {
			return false;
		}

		return ! $variation->is_in_stock();
	}
}
