<?php
/**
 * Read-only reporting queries for the statistics screen.
 *
 * Design notes:
 *  - every number on that screen comes from one of these methods; the view
 *    formats, it never counts;
 *  - all boundaries are computed in PHP and passed as strings, so nothing here
 *    depends on MySQL-only date arithmetic (the test harness runs SQLite);
 *  - no query touches the waitlist rows' contact columns, so a report can be
 *    generated without a single e-mail address entering memory;
 *  - results are cached per request: the same month is not counted twice
 *    because two panels ask for it.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

use Mojoodyar\Logic\StatsMath;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stats
 */
final class Stats {

	/**
	 * Periods offered by the screen, in days.
	 *
	 * @var int[]
	 */
	const RANGES = array( 7, 30, 90 );

	/**
	 * Default period.
	 *
	 * @var int
	 */
	const DEFAULT_RANGE = 30;

	/**
	 * Per-request cache of computed reports, keyed by period.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Validate a requested period against the offered list.
	 *
	 * @param mixed $raw Raw request value.
	 * @return int One of RANGES.
	 */
	public static function normalize_range( $raw ) {
		$raw = (int) $raw;

		return in_array( $raw, self::RANGES, true ) ? $raw : self::DEFAULT_RANGE;
	}

	/**
	 * Drop the per-request cache (used by the harness between scenarios).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = array();
	}

	/**
	 * Period requested on the current screen.
	 *
	 * @return int
	 */
	public static function current_range() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$raw = isset( $_GET['range'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['range'] ) ) : self::DEFAULT_RANGE;

		return self::normalize_range( $raw );
	}

	/**
	 * First second of the period, in the database's storage timezone (UTC).
	 *
	 * @param int $days Period length.
	 * @return string Y-m-d H:i:s.
	 */
	public static function since( $days ) {
		$days = max( 1, (int) $days );

		return gmdate( 'Y-m-d H:i:s', time() - ( ( $days - 1 ) * 86400 ) - ( time() % 86400 ) );
	}

	/**
	 * Counters for the period.
	 *
	 * @param int $days Period length.
	 * @return array
	 */
	public static function summary( $days ) {
		global $wpdb;

		$table = Database::table( Database::WAITLISTS );
		$since = self::since( $days );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
					SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
					SUM(CASE WHEN status = 'notified' THEN 1 ELSE 0 END) AS notified,
					SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) AS unsubscribed,
					SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired
				 FROM {$table} WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$row = is_array( $row ) ? $row : array();

		$total = (int) ( isset( $row['total'] ) ? $row['total'] : 0 );

		$queue_table = Database::table( Database::QUEUE );

		$sent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$queue_table} WHERE status = 'sent' AND sent_at >= %s",
				$since
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$failed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$queue_table} WHERE status = 'failed' AND created_at >= %s",
				$since
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'total'        => $total,
			'pending'      => (int) ( isset( $row['pending'] ) ? $row['pending'] : 0 ),
			'confirmed'    => (int) ( isset( $row['confirmed'] ) ? $row['confirmed'] : 0 ),
			'notified'     => (int) ( isset( $row['notified'] ) ? $row['notified'] : 0 ),
			'unsubscribed' => (int) ( isset( $row['unsubscribed'] ) ? $row['unsubscribed'] : 0 ),
			'expired'      => (int) ( isset( $row['expired'] ) ? $row['expired'] : 0 ),
			'sent'         => $sent,
			'failed'       => $failed,
			'per_day'      => $total > 0 ? round( $total / max( 1, (int) $days ), 1 ) : 0.0,
			'confirm_rate' => StatsMath::percent( (int) ( isset( $row['confirmed'] ) ? $row['confirmed'] : 0 ) + (int) ( isset( $row['notified'] ) ? $row['notified'] : 0 ), $total ),
		);
	}

	/**
	 * Sign-ups per day.
	 *
	 * @param int $days Period length.
	 * @return array{keys:string[],values:int[]}
	 */
	public static function signups_series( $days ) {
		global $wpdb;

		$table = Database::table( Database::WAITLISTS );
		$since = self::since( $days );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$table}
				 WHERE created_at >= %s GROUP BY DATE(created_at)",
				$since
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$keys = StatsMath::day_keys( self::midnight( $days ), (int) $days );

		return array(
			'keys'   => $keys,
			'values' => StatsMath::fill( is_array( $rows ) ? $rows : array(), $keys ),
		);
	}

	/**
	 * Notifications per day (from the waitlist rows, not the queue).
	 *
	 * @param int $days Period length.
	 * @return array{keys:string[],values:int[]}
	 */
	public static function notified_series( $days ) {
		global $wpdb;

		$table = Database::table( Database::WAITLISTS );
		$since = self::since( $days );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(notified_at) AS d, COUNT(*) AS c FROM {$table}
				 WHERE notified_at IS NOT NULL AND notified_at >= %s GROUP BY DATE(notified_at)",
				$since
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$keys = StatsMath::day_keys( self::midnight( $days ), (int) $days );

		return array(
			'keys'   => $keys,
			'values' => StatsMath::fill( is_array( $rows ) ? $rows : array(), $keys ),
		);
	}

	/**
	 * Split by delivery channel.
	 *
	 * @param int $days Period length.
	 * @return array<string,int> channel => count
	 */
	public static function by_channel( $days ) {
		global $wpdb;

		$table = Database::table( Database::WAITLISTS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT channel, COUNT(*) AS c FROM {$table}
				 WHERE created_at >= %s GROUP BY channel",
				self::since( $days )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array(
			'sms'   => 0,
			'email' => 0,
		);

		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			$key = isset( $row['channel'] ) ? (string) $row['channel'] : '';

			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = 0;
			}

			$out[ $key ] += (int) ( isset( $row['c'] ) ? $row['c'] : 0 );
		}

		return $out;
	}

	/**
	 * Split by current status (all time: a status is a state, not an event).
	 *
	 * @return array<string,int>
	 */
	public static function by_status() {
		global $wpdb;

		$table = Database::table( Database::WAITLISTS );

		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			$out[ isset( $row['status'] ) ? (string) $row['status'] : '' ] = (int) ( isset( $row['c'] ) ? $row['c'] : 0 );
		}

		return $out;
	}

	/**
	 * Most requested products in the period.
	 *
	 * @param int $days  Period length.
	 * @param int $limit How many rows.
	 * @return array[] array( product_id, variation_id, name, total, awaiting )
	 */
	public static function top_products( $days, $limit = 5 ) {
		global $wpdb;

		$table = Database::table( Database::WAITLISTS );
		$limit = max( 1, (int) $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, variation_id, COUNT(*) AS c,
					SUM(CASE WHEN status IN ('pending','confirmed') THEN 1 ELSE 0 END) AS awaiting
				 FROM {$table} WHERE created_at >= %s
				 GROUP BY product_id, variation_id ORDER BY c DESC LIMIT %d",
				self::since( $days ),
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			$pid = (int) ( isset( $row['product_id'] ) ? $row['product_id'] : 0 );
			$vid = (int) ( isset( $row['variation_id'] ) ? $row['variation_id'] : 0 );

			$out[] = array(
				'product_id'   => $pid,
				'variation_id' => $vid,
				'name'         => Notifier::product_name( $pid, $vid ),
				'total'        => (int) ( isset( $row['c'] ) ? $row['c'] : 0 ),
				'awaiting'     => (int) ( isset( $row['awaiting'] ) ? $row['awaiting'] : 0 ),
			);
		}

		return $out;
	}

	/**
	 * Follow-up call outcomes in the period.
	 *
	 * @param int $days Period length.
	 * @return array<string,int>
	 */
	public static function call_outcomes( $days ) {
		global $wpdb;

		$table = Database::table( Database::CALL_LOG );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT outcome, COUNT(*) AS c FROM {$table}
				 WHERE created_at >= %s GROUP BY outcome",
				self::since( $days )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			$out[ isset( $row['outcome'] ) ? (string) $row['outcome'] : '' ] = (int) ( isset( $row['c'] ) ? $row['c'] : 0 );
		}

		return $out;
	}

	/**
	 * Rejected and failed sign-ups, grouped by reason.
	 *
	 * This is the panel that answers "the form does nothing". A visitor whose
	 * request was refused never appears in the waitlist, so without this the
	 * admin sees an empty list and no explanation.
	 *
	 * @param int $days Period length.
	 * @return array[] array( event, code, label, count )
	 */
	public static function rejections( $days ) {
		global $wpdb;

		$table = Database::table( Database::LOGS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event, context, COUNT(*) AS c FROM {$table}
				 WHERE event IN ('subscribe_rejected','subscribe_failed') AND created_at >= %s
				 GROUP BY event, context ORDER BY c DESC LIMIT 12",
				self::since( $days )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$row   = (array) $row;
			$event = isset( $row['event'] ) ? (string) $row['event'] : '';
			$code  = isset( $row['context'] ) ? (string) $row['context'] : '';

			$out[] = array(
				'event' => $event,
				'code'  => $code,
				'label' => self::reason_label( $event, $code ),
				'count' => (int) ( isset( $row['c'] ) ? $row['c'] : 0 ),
			);
		}

		return $out;
	}

	/**
	 * Human reason for a stored rejection code.
	 *
	 * @param string $event Event name.
	 * @param string $code  Stored context.
	 * @return string Persian label.
	 */
	public static function reason_label( $event, $code ) {
		$code = (string) $code;

		if ( 'subscribe_failed' === $event ) {
			$code = preg_replace( '/^reason=/', '', $code );
		}

		$map = array(
			'duplicate'        => 'همین مخاطب قبلاً برای همین محصول ثبت شده است',
			'invalid_name'     => 'نام و نام خانوادگی خالی یا نامعتبر بوده',
			'invalid_phone'    => 'شمارهٔ موبایل معتبر نبوده',
			'invalid_email'    => 'ایمیل معتبر نبوده',
			'consent_required' => 'تیک رضایت زده نشده بوده',
			'product_available' => 'محصول در آن لحظه موجود بوده (فرم فقط برای ناموجود است)',
			'invalid_product'  => 'شناسهٔ محصول نامعتبر بوده',
			'no_woocommerce'   => 'ووکامرس فعال نبوده',
			'no_channel'       => 'هیچ کانال اعلانی تنظیم نشده بود',
			'rate_limited'     => 'تعداد تلاش از یک IP زیاد بوده (محدودیت نرخ)',
			'bad_nonce'        => 'کد امنیتی فرم منقضی شده بود (صفحه را از کش دوباره بارگذاری کنید)',
			'rest_nonce'       => 'نشست REST منقضی شده بود (کد امنیتی)',
			'honeypot'         => 'فیلد تلهٔ ضدربات پر شده بود',
			'subscribe_failed' => 'نوشتن ردیف در پایگاه داده انجام نشد',
			'email'            => 'ایمیل برای کانال ایمیل خالی بوده',
			'phone'            => 'شماره برای کانال پیامک خالی بوده',
			'product'          => 'شناسهٔ محصول صفر بوده',
		);

		return isset( $map[ $code ] ) ? $map[ $code ] : ( '' === $code ? 'بدون دلیل ثبت‌شده' : $code );
	}

	/**
	 * Everything the screen and the CSV need, computed once.
	 *
	 * @param int $days Period length.
	 * @return array
	 */
	public static function report( $days ) {
		$days = self::normalize_range( $days );

		if ( isset( self::$cache[ $days ] ) ) {
			return self::$cache[ $days ];
		}

		$signups = self::signups_series( $days );

		$report = array(
			'days'      => $days,
			'since'     => self::since( $days ),
			'summary'   => self::summary( $days ),
			'signups'   => $signups,
			'notified'  => self::notified_series( $days ),
			'channels'  => self::by_channel( $days ),
			'statuses'  => self::by_status(),
			'products'  => self::top_products( $days, 5 ),
			'calls'     => self::call_outcomes( $days ),
			'rejections' => self::rejections( $days ),
			'queue'     => Queue::stats(),
		);

		$report['peak'] = array(
			'signups'  => StatsMath::peak( $signups['values'] ),
			'notified' => StatsMath::peak( $report['notified']['values'] ),
		);

		self::$cache[ $days ] = $report;

		return $report;
	}

	/**
	 * Midnight (UTC) of the first day of the period.
	 *
	 * @param int $days Period length.
	 * @return int
	 */
	private static function midnight( $days ) {
		$days = max( 1, (int) $days );
		$now  = time();

		return $now - ( $now % 86400 ) - ( ( $days - 1 ) * 86400 );
	}
}
