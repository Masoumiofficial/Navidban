<?php
/**
 * Turn a log row into a Persian sentence. Pure logic: no WordPress calls.
 *
 * Every row in mjy_logs is a machine key plus a pipe-separated context string,
 * which is right for storage and useless for a shopkeeper. This class is the
 * one place that knows what those keys mean, so:
 *   - the log screen, the CSV and the report screen cannot drift apart;
 *   - adding a new logger call without a translation here is caught by a test
 *     instead of shipping a raw English key into the panel.
 *
 * Nothing here reads the database or formats a date.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Logic;

use Mojoodyar\Support\Persian;

defined( 'ABSPATH' ) || exit;

/**
 * Class LogExplain
 */
final class LogExplain {

	/**
	 * Persian title per event key.
	 *
	 * @var array<string,string>
	 */
	const EVENTS = array(
		'subscribe_ok'        => 'درخواست با موفقیت ثبت شد',
		'subscribe_duplicate' => 'درخواست تکراری (رد نشد، همان ردیف برگشت)',
		'subscribe_failed'    => 'ثبت درخواست در پایگاه داده انجام نشد',
		'subscribe_rejected'  => 'درخواست رد شد و ردیفی ساخته نشد',
		'sent'                => 'پیام ارسال شد',
		'delivery_failed'     => 'ارسال پیام ناموفق بود',
		'queue_run'           => 'صف ارسال اجرا شد',
		'restock_queued'      => 'اعلان بازگشت موجودی در صف گذاشته شد',
		'restock_no_units'    => 'موجودی برگشت ولی واحدی برای اعلام نبود',
		'reconcile'           => 'پایش تطبیقی موجودی اجرا شد',
		'snapshot_seeded'     => 'تصویر اولیهٔ موجودی گرفته شد',
		'admin_tool'          => 'یک ابزار از پنل اجرا شد',
		'admin_bulk'          => 'عملیات گروهی روی لیست انتظار',
		'admin_call'          => 'پیگیری تلفنی ثبت شد',
		'public_confirm'      => 'مشتری اشتراک را تأیید کرد',
		'public_unsubscribe'  => 'مشتری اشتراک را لغو کرد',
	);

	/**
	 * Persian label per severity level.
	 *
	 * @var array<string,string>
	 */
	const LEVELS = array(
		'info'     => 'اطلاع',
		'warning'  => 'هشدار',
		'error'    => 'خطا',
		'security' => 'امنیتی',
	);

	/**
	 * Persian label per context key.
	 *
	 * @var array<string,string>
	 */
	const KEYS = array(
		'product'     => 'محصول',
		'id'          => 'ردیف',
		'channel'     => 'کانال',
		'reason'      => 'دلیل',
		'units'       => 'تعداد واحد',
		'queued'      => 'تعداد پیام در صف',
		'sent'        => 'ارسال‌شده',
		'failed'      => 'ناموفق',
		'status'      => 'کد وضعیت درگاه',
		'action'      => 'عملیات',
		'ok'          => 'نتیجه',
		'count'       => 'تعداد ردیف',
		'scanned'     => 'محصول بررسی‌شده',
		'transitions' => 'گذار موجودی',
		'revived'     => 'ردیف دوباره فعال شد',
		'tool'        => 'ابزار',
	);

	/**
	 * Persian label per known value, where a value is an enum rather than free text.
	 *
	 * @var array<string,string>
	 */
	const VALUES = array(
		'sms'          => 'پیامک',
		'email'        => 'ایمیل',
		'confirm'      => 'پیام تأیید',
		'restock'      => 'اعلان بازگشت موجودی',
		'unmanaged'    => 'نامشخص (موجودی مدیریت نمی‌شود)',
		'yes'          => 'بله',
		'no'           => 'خیر',
		'confirmed'    => 'تأیید شد',
		'already'      => 'از قبل انجام شده بود',
		'expired'      => 'منقضی',
		'unsubscribed' => 'لغو شد',
		'invalid'      => 'نامعتبر',
		// Queue row states. "failed" here means the plugin gave up after it ran
		// out of retries - not that the shop owner did something wrong.
		'queued'       => 'در صف',
		'processing'   => 'در حال ارسال',
		'sent'         => 'ارسال شد',
		'failed'       => 'ناموفق (تلاش‌ها تمام شد)',
		'skipped'      => 'رد شد',
	);

	/**
	 * Values that only mean something for one specific key.
	 *
	 * 0 and 1 are "ناموفق/موفق" when the key is ok=, and plain counts when the
	 * key is transitions= or queued=. Translating them globally printed
	 * "گذار موجودی: ناموفق" for transitions=0, which read as a failure that
	 * never happened.
	 *
	 * @var array<string,array<string,string>>
	 */
	const KEYED_VALUES = array(
		'ok' => array(
			'1' => 'موفق',
			'0' => 'ناموفق',
		),
	);

	/**
	 * Keys whose value is a plain count, so it can be shown in Persian digits.
	 *
	 * Identifiers (product, id, code) deliberately stay Latin: they are copied
	 * into SQL and support tickets, where Persian digits are useless.
	 *
	 * @var string[]
	 */
	const COUNT_KEYS = array( 'scanned', 'transitions', 'queued', 'sent', 'failed', 'count', 'units', 'attempt' );

	/**
	 * Tool keys, as used by the "ابزار" buttons in the panel.
	 *
	 * @var array<string,string>
	 */
	const TOOLS = array(
		'run_reconcile'      => 'اجرای پایش موجودی',
		'rebuild_snapshots'  => 'بازسازی تصویر موجودی',
		'process_queue'      => 'پردازش صف',
		'flush_queue'        => 'آزادکردن قفل صف',
		'empty_queue'        => 'پاک‌کردن صف',
		'retry_failed'       => 'تلاش دوباره برای پیام‌های ناموفق',
		'prune'              => 'پاک‌سازی ردیف‌های قدیمی',
		'test_email'         => 'ارسال ایمیل آزمایشی',
		'test_sms'           => 'ارسال پیامک آزمایشی',
		'clear_logs'         => 'پاک کردن لاگ‌ها',
		'rebuild_tables'     => 'بازسازی جدول‌ها',
	);

	/**
	 * Persian title for an event key.
	 *
	 * @param string $event Event key.
	 * @return string
	 */
	public static function event( $event ) {
		$event = (string) $event;

		if ( isset( self::EVENTS[ $event ] ) ) {
			return self::EVENTS[ $event ];
		}

		// public_confirm / public_unsubscribe are built with a prefix elsewhere;
		// anything genuinely unknown is shown as-is, never hidden.
		return '' === $event ? 'رویداد بدون نام' : 'رویداد ثبت‌نشده (' . $event . ')';
	}

	/**
	 * Persian name of a panel tool.
	 *
	 * @param string $tool Tool key.
	 * @return string
	 */
	public static function tool( $tool ) {
		$tool = (string) $tool;

		return isset( self::TOOLS[ $tool ] ) ? self::TOOLS[ $tool ] : 'ابزار ناشناخته (' . $tool . ')';
	}

	/**
	 * Persian label for a severity level.
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function level( $level ) {
		$level = (string) $level;

		return isset( self::LEVELS[ $level ] ) ? self::LEVELS[ $level ] : $level;
	}

	/**
	 * Is this level something an admin should look at?
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	public static function is_problem( $level ) {
		return in_array( (string) $level, array( 'warning', 'error', 'security' ), true );
	}

	/**
	 * Split a context string into labelled pieces.
	 *
	 * Context is stored as `a=1|b=2` or, for older calls, as bare codes such as
	 * `bad_nonce` or `5:0`. Both shapes must survive here: the raw string is
	 * always available on screen, this is only the friendly view.
	 *
	 * @param string $context Context.
	 * @return array[] array( key, value, label )
	 */
	public static function parse( $context ) {
		$context = trim( (string) $context );

		if ( '' === $context ) {
			return array();
		}

		$out  = array();
		$bits = explode( '|', $context );

		foreach ( $bits as $index => $bit ) {
			$bit = trim( (string) $bit );

			if ( '' === $bit ) {
				continue;
			}

			if ( false !== strpos( $bit, '=' ) ) {
				list( $key, $value ) = explode( '=', $bit, 2 );
				$key                 = trim( $key );

				$out[] = array(
					'key'   => $key,
					'value' => trim( $value ),
					'label' => isset( self::KEYS[ $key ] ) ? self::KEYS[ $key ] : $key,
				);

				continue;
			}

			// `5:0` is a product:variation pair written by the stock watcher.
			if ( preg_match( '/^(\d+):(\d+)$/', $bit, $match ) ) {
				$out[] = array(
					'key'   => 'product',
					'value' => (int) $match[1] . ':' . (int) $match[2],
					'label' => 'محصول',
				);

				continue;
			}

			$out[] = array(
				'key'   => 'code',
				'value' => $bit,
				'label' => 0 === $index ? 'کد' : 'مقدار',
			);
		}

		return $out;
	}

	/**
	 * A one-line Persian explanation of a log row.
	 *
	 * The point is that a shopkeeper reads one sentence and knows what happened;
	 * the raw context stays available underneath it on screen.
	 *
	 * @param string $event   Event key.
	 * @param string $context Context.
	 * @return string
	 */
	public static function detail( $event, $context ) {
		$event   = (string) $event;
		$context = trim( (string) $context );

		if ( '' === $context ) {
			return self::event( $event ) . ' — بدون جزئیات.';
		}

		// Two events carry a fixed, positional shape that a generic key=value
		// reading would mangle. Both are handled before the generic path.
		if ( 'delivery_failed' === $event ) {
			return self::delivery_detail( $context );
		}

		if ( 'admin_tool' === $event ) {
			// The context of this event is a bare tool key.
			$tool = trim( explode( '|', $context )[0] );

			return 'ابزار: ' . self::tool( $tool );
		}

		$pieces = array();

		foreach ( self::parse( $context ) as $part ) {
			$pieces[] = self::piece( $part, $event );
		}

		$pieces = array_filter( $pieces );

		return empty( $pieces ) ? self::event( $event ) . ' — بدون جزئیات.' : implode( '، ', $pieces );
	}

	/**
	 * Explain a failed delivery: `sms|failed|attempt=3|err=...`.
	 *
	 * The shape is positional (channel, then queue state) plus optional
	 * key=value extras, so it cannot go through parse() without turning the
	 * first two tokens into "کد".
	 *
	 * @param string $context Context.
	 * @return string
	 */
	private static function delivery_detail( $context ) {
		$tokens  = explode( '|', trim( $context ) );
		$channel = isset( $tokens[0] ) ? trim( $tokens[0] ) : '';
		$state   = isset( $tokens[1] ) ? trim( $tokens[1] ) : '';

		$pieces = array();

		if ( '' !== $channel ) {
			$pieces[] = 'کانال: ' . self::value( $channel );
		}

		if ( '' !== $state ) {
			$pieces[] = 'وضعیت ردیف: ' . self::value( $state );
		}

		for ( $i = 2; $i < count( $tokens ); $i++ ) {
			$token = trim( (string) $tokens[ $i ] );

			if ( '' === $token ) {
				continue;
			}

			if ( 0 === strpos( $token, 'err=' ) ) {
				$pieces[] = 'خطای درگاه: ' . substr( $token, 4 );
				continue;
			}

			if ( 0 === strpos( $token, 'attempt=' ) ) {
				$pieces[] = 'تلاش شمارهٔ ' . Persian::number( (int) substr( $token, 8 ) );
				continue;
			}

			$pieces[] = $token;
		}

		return implode( ' — ', $pieces );
	}

	/**
	 * Human form of a single parsed piece.
	 *
	 * @param array  $part Parsed piece.
	 * @param string $event Event key, used to interpret bare codes.
	 * @return string
	 */
	private static function piece( array $part, $event ) {
		$key   = (string) $part['key'];
		$value = (string) $part['value'];

		// A bare code on a rejection row is a reason, not an identifier.
		if ( 'code' === $key ) {
			return 'دلیل: ' . self::reason( $value, $event );
		}

		if ( 'product' === $key && false !== strpos( $value, ':' ) ) {
			list( $product_id, $variation_id ) = explode( ':', $value, 2 );

			$text = 'محصول #' . (int) $product_id;

			if ( (int) $variation_id > 0 ) {
				$text .= ' (تنوع #' . (int) $variation_id . ')';
			}

			return $text;
		}

		if ( 'reason' === $key ) {
			return 'دلیل: ' . self::reason( $value, $event );
		}

		$text = self::value( $value, $key );

		if ( in_array( $key, self::COUNT_KEYS, true ) && preg_match( '/^\d+$/', $text ) ) {
			$text = Persian::number( (int) $text );
		}

		return $part['label'] . ': ' . $text;
	}

	/**
	 * Persian text for a stored machine value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function value( $value, $key = '' ) {
		$value = (string) $value;
		$key   = (string) $key;

		if ( '' !== $key && isset( self::KEYED_VALUES[ $key ][ $value ] ) ) {
			return self::KEYED_VALUES[ $key ][ $value ];
		}

		return isset( self::VALUES[ $value ] ) ? self::VALUES[ $value ] : $value;
	}

	/**
	 * Persian explanation for a rejection / failure code.
	 *
	 * @param string $code  Code.
	 * @param string $event Event key (subscribe_rejected vs subscribe_failed).
	 * @return string
	 */
	public static function reason( $code, $event = 'subscribe_rejected' ) {
		$code = (string) $code;

		// subscribe_failed stores `reason=email`; subscribe_rejected stores the
		// bare code. Accept both shapes.
		$code = preg_replace( '/^reason=/', '', $code );

		$map = array(
			'duplicate'         => 'همین مخاطب قبلاً برای همین محصول ثبت شده است',
			'invalid_name'      => 'نام و نام خانوادگی خالی یا نامعتبر بوده',
			'invalid_phone'     => 'شمارهٔ موبایل معتبر نبوده',
			'invalid_email'     => 'ایمیل معتبر نبوده',
			'consent_required'  => 'تیک رضایت زده نشده بوده',
			'product_available' => 'محصول در آن لحظه موجود بوده (فرم فقط برای محصول ناموجود است)',
			'invalid_product'   => 'شناسهٔ محصول نامعتبر بوده',
			'no_woocommerce'    => 'ووکامرس فعال نبوده',
			'no_channel'        => 'هیچ کانال اعلانی تنظیم نشده بود',
			'rate_limited'      => 'تعداد تلاش از یک IP در یک ساعت زیاد بوده (محدودیت نرخ)',
			'subscribe_failed'  => 'نوشتن ردیف در پایگاه داده انجام نشد',
			'bad_nonce'         => 'کد امنیتی فرم منقضی شده بود (صفحه از کش بارگذاری شده یا نشست تمام شده)',
			'rest_nonce'        => 'نشست REST منقضی شده بود (کد امنیتی)',
			'honeypot'          => 'فیلد تلهٔ ضدربات پر شده بود',
			'email'             => 'ایمیل برای کانال ایمیل خالی بوده',
			'phone'             => 'شماره برای کانال پیامک خالی بوده',
			'product'           => 'شناسهٔ محصول صفر بوده',
			'timeout'           => 'درگاه به‌موقع پاسخ نداده است',
			'http'              => 'درگاه کد خطای HTTP برگردانده است',
		);

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		return '' === $code ? 'بدون دلیل ثبت‌شده' : $code;
	}

	/**
	 * What the admin should do about a row.
	 *
	 * A log that says "something failed" without saying "do this" is a riddle.
	 * Only the codes with a real, actionable answer get a hint; everything else
	 * returns an empty string rather than filler.
	 *
	 * @param string $event Event key.
	 * @param string $context Context.
	 * @return string
	 */
	public static function advice( $event, $context ) {
		$event = (string) $event;
		$parts = self::parse( $context );

		$code = '';

		foreach ( $parts as $part ) {
			if ( 'code' === $part['key'] || 'reason' === $part['key'] ) {
				$code = (string) $part['value'];
			}
		}

		$code = preg_replace( '/^reason=/', '', $code );

		$map = array(
			'bad_nonce'        => 'کش صفحه را برای صفحه‌های محصول خاموش کنید یا زمان اعتبار nonce را بالا ببرید.',
			'rest_nonce'       => 'اگر از کش تمام‌صفحه استفاده می‌کنید، فرم را از کش مستثنی کنید.',
			'rate_limited'     => 'محدودیت نرخ یک ساعت است؛ اگر این عدد زیاد است، احتمال حمله یا یک ربات را بررسی کنید.',
			'duplicate'        => 'کاری لازم نیست؛ مشتری از قبل در لیست است.',
			'invalid_name'     => 'اگر فیلد نام اجباری است، برچسب و راهنمای فرم را واضح‌تر کنید.',
			'invalid_phone'    => 'الگوی شمارهٔ موبایل ایران (۰۹…) را در فرم بررسی کنید.',
			'product_available' => 'فرم فقط روی محصول ناموجود نمایش داده می‌شود؛ اگر این عدد زیاد است، کش صفحهٔ محصول قدیمی است.',
			'no_channel'       => 'تنظیمات → کانال‌ها: یک درگاه پیامک را پیکربندی کنید.',
			'subscribe_failed' => 'ابزارها → «بازسازی جدول‌ها» و سپس بررسی دسترسی نوشتن پایگاه داده.',
		);

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		if ( 'delivery_failed' === $event ) {
			return 'ابزارها → «ارسال آزمایشی» را بزنید تا متن خطای واقعی درگاه را ببینید.';
		}

		if ( 'restock_no_units' === $event ) {
			return 'طبیعی است اگر موجودی برنگشته باشد؛ اگر واحد اضافه شده، تصویر موجودی آن محصول را در ابزارها بررسی کنید.';
		}

		return '';
	}

	/**
	 * All events that have a translation, for the filter dropdown.
	 *
	 * @return array<string,string>
	 */
	public static function events() {
		return self::EVENTS;
	}

	/**
	 * All levels, for the filter dropdown.
	 *
	 * @return array<string,string>
	 */
	public static function levels() {
		return self::LEVELS;
	}
}
