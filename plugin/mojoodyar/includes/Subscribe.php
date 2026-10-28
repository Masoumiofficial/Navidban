<?php
/**
 * Subscribe use-case. Shared by the REST endpoint and the admin-ajax fallback so
 * both paths enforce exactly the same rules.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

use Mojoodyar\Support\Validator;

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
		$name         = isset( $input['name'] ) ? Validator::person_name( $input['name'], 120 ) : '';
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

		// The name field is what turns a row into something a human can call
		// back. When the store makes it mandatory, an unusable name is a form
		// error - not a silent empty column in the export.
		if ( (bool) Settings::get( 'form_name_field', true ) && (bool) Settings::get( 'form_name_required', true ) && '' === $name ) {
			return self::fail( 'invalid_name', 422, 'نام و نام خانوادگی را کامل بنویسید.', $ip_hash );
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

		// The form is only served for unavailable products, so a signup is
		// first-hand proof that this product is out of stock. Record that as the
		// baseline: without it, a product the plugin never watched (added before
		// activation, sold out through an import, or written directly in the
		// database) has no snapshot row, and the restock hook treats the first
		// "in stock" write as a first sighting and announces nothing - the exact
		// "the row is in the waitlist but no SMS arrived" report.
		if ( null === WaitlistRepository::snapshot_get( $product_id, $variation_id ) ) {
			$watched = $product;

			if ( $variation_id > 0 && function_exists( 'wc_get_product' ) ) {
				$maybe_variation = wc_get_product( $variation_id );
				if ( $maybe_variation ) {
					$watched = $maybe_variation;
				}
			}

			$watched_qty     = method_exists( $watched, 'get_stock_quantity' ) ? $watched->get_stock_quantity() : null;
			$watched_managed = method_exists( $watched, 'managing_stock' ) ? (bool) $watched->managing_stock() : true;

			WaitlistRepository::snapshot_put(
				$product_id,
				$variation_id,
				'outofstock',
				null === $watched_qty ? 0 : (int) $watched_qty,
				$watched_managed
			);
		}

		$result = WaitlistRepository::create(
			array(
				'product_id'      => $product_id,
				'variation_id'    => $variation_id,
				'event_type'      => $event_type,
				'channel'         => $channel,
				'email'           => $email,
				'phone'           => $phone,
				'full_name'       => $name,
				'user_id'         => $user_id,
				'stock_at_signup' => $stock_at_signup,
				'ip_hash'         => $ip_hash,
			)
		);

		if ( $result['duplicate'] ) {
			// A returning visitor is not an error. The unique key stops the same
			// person from filling the waitlist with duplicates, but answering
			// "insert failed" to someone who is already on the list reads as a
			// broken plugin.
			//
			// Worse: a row that was already notified stays "notified" forever,
			// so a shop that restocks the same product twice can never message
			// that customer again through the form. Signing up again is a real
			// expression of interest, so a row that is no longer waiting is
			// revived (a row that is still pending/confirmed is left alone).
			$revived = WaitlistRepository::reactivate( (int) $result['id'], $name, (bool) Settings::enabled( 'double_opt_in' ) );

			Logger::log( 'info', 'subscribe_duplicate', 'product=' . $product_id . '|id=' . (int) $result['id'] . '|revived=' . ( $revived ? 'yes' : 'no' ), $ip_hash );

			return array(
				'ok'      => true,
				'code'    => 'already_subscribed',
				'status'  => 200,
				'message' => $revived
					? 'دوباره در لیست انتظار این محصول قرار گرفتید. به‌محض بازگشت موجودی خبرتان می‌کنیم.'
					: 'شما از قبل در لیست انتظار این محصول هستید؛ به‌محض بازگشت موجودی خبرتان می‌کنیم.',
				'data'    => array(
					'id'            => (int) $result['id'],
					'needs_confirm' => false,
					'already'       => true,
					'revived'       => (bool) $revived,
				),
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
