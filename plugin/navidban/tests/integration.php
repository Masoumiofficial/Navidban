<?php
/**
 * Integration harness: executes the real plugin classes against a WordPress
 * stub backed by SQLite. No web server, no MySQL, no Composer.
 *
 * Usage: php tests/integration.php [--json]
 *
 * @package Navidban
 */

require_once __DIR__ . '/stubs/wp.php';
require_once __DIR__ . '/stubs/woocommerce.php';

require_once NAVIDBAN_DIR . 'includes/Autoloader.php';
\Navidban\Autoloader::register();

use Navidban\Activator;
use Navidban\Capabilities;
use Navidban\Consent;
use Navidban\Database;
use Navidban\Frontend;
use Navidban\Queue;
use Navidban\Settings;
use Navidban\StockWatcher;
use Navidban\Subscribe;
use Navidban\WaitlistRepository;

/**
 * Assertion helper (same shape as tests/run.php).
 */
final class NvbInt {

	public static $assertions = 0;
	public static $failures = 0;
	public static $failed = array();
	public static $groups = array();
	public static $group = '';

	public static function group( $name ) {
		self::$group                   = $name;
		self::$groups[ $name ]         = array( 'assertions' => 0, 'failures' => 0 );
	}

	public static function same( $expected, $actual, $label ) {
		return self::record( $expected === $actual, $label, $expected, $actual );
	}

	public static function ok( $condition, $label ) {
		return self::record( (bool) $condition, $label, true, $condition );
	}

	public static function record( $passed, $label, $expected, $actual ) {
		self::$assertions++;
		self::$groups[ self::$group ]['assertions']++;

		if ( ! $passed ) {
			self::$failures++;
			self::$groups[ self::$group ]['failures']++;
			self::$failed[] = array(
				'group'    => self::$group,
				'label'    => $label,
				'expected' => self::stringify( $expected ),
				'actual'   => self::stringify( $actual ),
			);
		}

		return (bool) $passed;
	}

	public static function stringify( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_null( $value ) ) {
			return 'null';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return json_encode( $value, JSON_UNESCAPED_UNICODE );
	}

	public static function summary() {
		return array(
			'plugin_version' => NAVIDBAN_VERSION,
			'php_version'    => PHP_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'assertions'     => self::$assertions,
			'failures'       => self::$failures,
			'pass_rate'      => self::$assertions > 0 ? round( ( self::$assertions - self::$failures ) * 100 / self::$assertions, 2 ) : 0.0,
			'groups'         => self::$groups,
			'failed'         => self::$failed,
		);
	}
}

/**
 * Reset the harness state between scenarios.
 *
 * @return void
 */
function nvb_reset_state() {
	$GLOBALS['nvb_products'] = array();
	$GLOBALS['nvb_mails']    = array();
	$GLOBALS['nvb_enqueued'] = array();
	$GLOBALS['nvb_http_calls'] = array();
	$GLOBALS['nvb_http_response'] = null;
	$GLOBALS['nvb_capabilities'] = array();
	$GLOBALS['nvb_is_product'] = false;
	$GLOBALS['nvb_queried_id'] = 0;
	Settings::flush_cache();
	Queue::release_lock();
}

/**
 * Clear the security log so the per-IP rate limiter starts from zero.
 * A test suite is one "IP" hammering the endpoint; a real visitor is not.
 *
 * @return void
 */
function nvb_reset_rate_limit() {
	$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Navidban\Database::table( \Navidban\Database::LOGS ) );
}

/**
 * Create (or overwrite) a stub product.
 *
 * @param int   $id   ID.
 * @param array $data Data.
 * @return void
 */
function nvb_product( $id, array $data ) {
	$GLOBALS['nvb_products'][ (int) $id ] = array_merge(
		array(
			'type'     => 'simple',
			'parent'   => 0,
			'status'   => 'instock',
			'qty'      => 10,
			'managed'  => true,
			'children' => array(),
			'name'     => 'کفش دویدن',
		),
		$data
	);
}

/* -------------------------------------------------------------------------
 * Setup: activation
 * ---------------------------------------------------------------------- */
NvbInt::group( 'نصب و چیدمان پایگاه داده' );

Activator::activate( false );

$tables = Database::inspect();
NvbInt::same( 4, count( $tables ), 'چهار جدول ثبت شد' );
NvbInt::ok( isset( $GLOBALS['nvb_ddl'] ) && count( $GLOBALS['nvb_ddl'] ) >= 4, 'dbDelta برای هر جدول فراخوانی شد' );

$ddl = implode( "\n", $GLOBALS['nvb_ddl'] );
NvbInt::same( 4, substr_count( $ddl, 'ENGINE=InnoDB' ), 'همهٔ جدول‌ها InnoDB' );
NvbInt::ok( false === strpos( $ddl, 'MyISAM' ), 'هیچ جدول MyISAM نیست' );
NvbInt::ok( false !== strpos( $ddl, 'UNIQUE KEY uniq_subscriber' ), 'کلید یکتای مشترک وجود دارد' );
NvbInt::ok( false !== strpos( $ddl, 'UNIQUE KEY uniq_payload' ), 'کلید یکتای پیام صف وجود دارد' );
NvbInt::ok( false !== strpos( $ddl, 'email VARCHAR(190) NOT NULL' ), 'ایمیل NOT NULL است تا کلید یکتا کار کند' );

$settings = Settings::all();
NvbInt::ok( ! empty( $settings['email_enabled'] ), 'ایمیل به‌صورت پیش‌فرض فعال است' );
NvbInt::same( 50, $settings['queue_batch_size'], 'اندازهٔ دستهٔ پیش‌فرض' );
NvbInt::ok( empty( $settings['delete_data_on_uninstall'] ), 'حذف داده هنگام حذف، پیش‌فرض خاموش' );
NvbInt::ok( ! empty( $GLOBALS['nvb_cron']['navidban_process_queue'] ), 'cron صف زمان‌بندی شد' );
NvbInt::ok( ! empty( $GLOBALS['nvb_cron']['navidban_reconcile_stock'] ), 'cron پایش زمان‌بندی شد' );

/* -------------------------------------------------------------------------
 * Conditional asset loading
 * ---------------------------------------------------------------------- */
NvbInt::group( 'بارگذاری شرطی' );

// 1. Home page. A fresh instance per scenario: should_load() memoises per request.
nvb_reset_state();
$frontend = new Frontend();
nvb_product( 10, array( 'status' => 'outofstock', 'qty' => 0 ) );
$GLOBALS['nvb_is_product'] = false;
$GLOBALS['nvb_queried_id'] = 10;
$frontend->maybe_enqueue();
NvbInt::same( array(), $GLOBALS['nvb_enqueued'], 'صفحهٔ غیر محصول: هیچ فایلی صف نمی‌شود' );

// 2. Product page of an in-stock simple product.
nvb_reset_state();
$frontend = new Frontend();
nvb_product( 11, array( 'status' => 'instock', 'qty' => 5 ) );
$GLOBALS['nvb_is_product'] = true;
$GLOBALS['nvb_queried_id'] = 11;
$frontend->maybe_enqueue();
NvbInt::same( array(), $GLOBALS['nvb_enqueued'], 'محصول موجود: هیچ فایلی صف نمی‌شود' );

// 3. Product page of an out-of-stock product.
nvb_reset_state();
$frontend = new Frontend();
nvb_product( 12, array( 'status' => 'outofstock', 'qty' => 0 ) );
$GLOBALS['nvb_is_product'] = true;
$GLOBALS['nvb_queried_id'] = 12;
$frontend->maybe_enqueue();
NvbInt::same( 1, count( $GLOBALS['nvb_enqueued']['style'] ?? array() ), 'محصول ناموجود: یک استایل' );
NvbInt::same( 1, count( $GLOBALS['nvb_enqueued']['script'] ?? array() ), 'محصول ناموجود: یک اسکریپت' );
NvbInt::ok( isset( $GLOBALS['nvb_enqueued']['localized']['NavidbanData'] ), 'داده‌های محلی‌شده برای اسکریپت' );

// 4. Variable product: parent in stock, one variation sold out.
nvb_reset_state();
$frontend = new Frontend();
nvb_product( 20, array( 'type' => 'variable', 'status' => 'instock', 'qty' => 0, 'children' => array( 21, 22 ) ) );
nvb_product( 21, array( 'parent' => 20, 'status' => 'instock', 'qty' => 4, 'attributes' => 'سایز ۴۲' ) );
nvb_product( 22, array( 'parent' => 20, 'status' => 'outofstock', 'qty' => 0, 'attributes' => 'سایز ۴۳' ) );
$GLOBALS['nvb_is_product'] = true;
$GLOBALS['nvb_queried_id'] = 20;
$frontend->maybe_enqueue();
NvbInt::same( 1, count( $GLOBALS['nvb_enqueued']['script'] ?? array() ), 'محصول متغیر با تنوع ناموجود: اسکریپت بار می‌شود' );

// 5. Variable product where every variation is available.
nvb_reset_state();
$frontend = new Frontend();
nvb_product( 30, array( 'type' => 'variable', 'status' => 'instock', 'children' => array( 31 ) ) );
nvb_product( 31, array( 'parent' => 30, 'status' => 'instock', 'qty' => 3 ) );
$GLOBALS['nvb_is_product'] = true;
$GLOBALS['nvb_queried_id'] = 30;
$frontend->maybe_enqueue();
NvbInt::same( array(), $GLOBALS['nvb_enqueued'], 'همهٔ تنوع‌ها موجود: هیچ فایلی صف نمی‌شود' );

// 6. Feed / admin requests never load assets.
nvb_reset_state();
$frontend = new Frontend();
$GLOBALS['nvb_is_product'] = true;
$GLOBALS['nvb_queried_id'] = 12;
$GLOBALS['nvb_is_admin']   = true;
$frontend->maybe_enqueue();
NvbInt::same( array(), $GLOBALS['nvb_enqueued'], 'درخواست مدیریت: هیچ فایلی صف نمی‌شود' );
$GLOBALS['nvb_is_admin'] = false;

/* -------------------------------------------------------------------------
 * Subscribe flow
 * ---------------------------------------------------------------------- */
NvbInt::group( 'ثبت درخواست' );

nvb_reset_state();
nvb_reset_rate_limit();
nvb_product( 40, array( 'status' => 'outofstock', 'qty' => 0 ) );

$result = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'Maryam@Example.COM',
	)
);
NvbInt::ok( $result['ok'], 'ثبت ایمیل معتبر' );
NvbInt::same( 'confirmation_sent', $result['code'], 'تأیید دومرحله‌ای فعال است' );

$row = WaitlistRepository::get( (int) $result['data']['id'] );
NvbInt::same( 'pending', $row['status'], 'وضعیت اولیه pending است' );
NvbInt::same( 'maryam@example.com', $row['email'], 'ایمیل نرمال‌شده ذخیره شد' );
NvbInt::ok( 64 === strlen( $row['token'] ), 'توکن ۶۴ کاراکتری' );
NvbInt::ok( '' !== $row['ip_hash'] && false === strpos( $row['ip_hash'], '127.0.0.1' ), 'IP خام ذخیره نشده، فقط هش' );
NvbInt::ok( ! empty( $row['confirm_expires_at'] ), 'انقضای پیوند تأیید ثبت شد' );

$queue_rows = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() );
NvbInt::same( 1, count( $queue_rows ), 'یک پیام تأیید در صف قرار گرفت' );
NvbInt::same( 'confirm', $queue_rows[0]['event_key'], 'نوع پیام صف: confirm' );

// Duplicate.
$duplicate = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'maryam@example.com',
	)
);
NvbInt::ok( $duplicate['ok'], 'ثبت تکراری خطای سرور نمی‌دهد' );
NvbInt::same( 'duplicate', $duplicate['code'], 'ثبت تکراری تشخیص داده شد' );
NvbInt::same( 1, count( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . WaitlistRepository::table() ) ), 'ردیف تکراری ساخته نشد' );

// Invalid email.
nvb_reset_rate_limit();
$invalid = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'not-an-email',
	)
);
NvbInt::ok( ! $invalid['ok'], 'ایمیل نامعتبر رد شد' );
NvbInt::same( 'invalid_email', $invalid['code'], 'کد خطای درست' );

// Invalid phone.
nvb_reset_rate_limit();
$invalid_phone = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'sms',
		'phone'      => '12345',
	)
);
NvbInt::same( 'invalid_phone', $invalid_phone['code'], 'موبایل نامعتبر رد شد' );

// Available product cannot be subscribed to.
nvb_reset_rate_limit();
nvb_product( 41, array( 'status' => 'instock', 'qty' => 7 ) );
$available = Subscribe::handle(
	array(
		'product_id' => 41,
		'channel'    => 'email',
		'email'      => 'x@example.com',
	)
);
NvbInt::same( 'product_available', $available['code'], 'ثبت برای محصول موجود رد شد' );

// Variation of an available parent is allowed when that variation is sold out.
nvb_reset_rate_limit();
nvb_product( 42, array( 'type' => 'variable', 'status' => 'instock', 'children' => array( 43 ) ) );
nvb_product( 43, array( 'parent' => 42, 'status' => 'outofstock', 'qty' => 0 ) );
$variation = Subscribe::handle(
	array(
		'product_id'   => 42,
		'variation_id' => 43,
		'channel'      => 'email',
		'email'        => 'size43@example.com',
	)
);
NvbInt::ok( $variation['ok'], 'ثبت برای تنوع ناموجود مجاز است' );

// Consent enforcement.
nvb_reset_rate_limit();
Settings::update( array( 'consent_required' => true ) );
$no_consent = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'noconsent@example.com',
	)
);
NvbInt::same( 'consent_required', $no_consent['code'], 'بدون رضایت ثبت نمی‌شود' );
$with_consent = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'noconsent@example.com',
		'consent'    => 1,
	)
);
NvbInt::ok( $with_consent['ok'], 'با رضایت ثبت می‌شود' );
Settings::update( array( 'consent_required' => false ) );

// Rate limit: 5 attempts per hour per IP hash.
nvb_reset_rate_limit();
$limited = array();
for ( $i = 0; $i < 8; $i++ ) {
	$limited[] = Subscribe::handle(
		array(
			'product_id' => 40,
			'channel'    => 'email',
			'email'      => 'bulk' . $i . '@example.com',
		)
	);
}
$codes = array_column( $limited, 'code' );
NvbInt::ok( in_array( 'rate_limited', $codes, true ), 'محدودیت نرخ فعال شد' );

/* -------------------------------------------------------------------------
 * Consent tokens
 * ---------------------------------------------------------------------- */
NvbInt::group( 'تأیید و لغو اشتراک' );

$GLOBALS['nvb_options']['navidban_settings'] = array_merge(
	Settings::defaults(),
	array( 'consent_required' => false )
);
Settings::flush_cache();

$fresh = WaitlistRepository::create(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'token@example.com',
	)
);
$fresh_row = WaitlistRepository::get( (int) $fresh['id'] );

NvbInt::same( 'confirmed', WaitlistRepository::confirm_by_token( (string) $fresh_row['token'] ), 'تأیید با توکن' );
NvbInt::same( 'already', WaitlistRepository::confirm_by_token( (string) $fresh_row['token'] ), 'تأیید دوباره، بدون تغییر' );
NvbInt::same( 'invalid', WaitlistRepository::confirm_by_token( 'not-a-token' ), 'توکن نامعتبر رد می‌شود' );
NvbInt::same( 'invalid', WaitlistRepository::confirm_by_token( str_repeat( 'z', 64 ) ), 'توکن با قالب درست ولی ناشناس' );

$expired = WaitlistRepository::create(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'expired@example.com',
	)
);
$GLOBALS['wpdb']->query(
	$GLOBALS['wpdb']->prepare(
		'UPDATE ' . WaitlistRepository::table() . ' SET confirm_expires_at = %s WHERE id = %d',
		gmdate( 'Y-m-d H:i:s', time() - 3600 ),
		(int) $expired['id']
	)
);
$expired_row = WaitlistRepository::get( (int) $expired['id'] );
NvbInt::same( 'expired', WaitlistRepository::confirm_by_token( (string) $expired_row['token'] ), 'توکن منقضی رد می‌شود' );

NvbInt::same( 'unsubscribed', WaitlistRepository::unsubscribe_by_token( (string) $fresh_row['token'] ), 'لغو اشتراک با توکن' );
NvbInt::same( 'already', WaitlistRepository::unsubscribe_by_token( (string) $fresh_row['token'] ), 'لغو دوباره بی‌اثر است' );

/* -------------------------------------------------------------------------
 * Stock engine: hooks, dedupe, fair sending
 * ---------------------------------------------------------------------- */
NvbInt::group( 'موتور موجودی' );

nvb_reset_state();
Settings::update( array( 'fair_sending' => true, 'notify_backorders' => false ) );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::snapshots() );

// Product 100 is out of stock and has 200 confirmed subscribers.
nvb_product( 100, array( 'status' => 'outofstock', 'qty' => 0 ) );
$watcher = new StockWatcher();
$watcher->on_stock_status( 100, 'outofstock', wc_get_product( 100 ) );

for ( $i = 1; $i <= 200; $i++ ) {
	$created = WaitlistRepository::create(
		array(
			'product_id' => 100,
			'channel'    => 'email',
			'email'      => 'fan' . $i . '@example.com',
		)
	);
	WaitlistRepository::set_status( (int) $created['id'], 'confirmed' );
}

NvbInt::same( 200, WaitlistRepository::count_confirmed( 100, 0 ), '۲۰۰ مشترک تأییدشده ثبت شد' );

// First sighting of an unknown product must not announce.
nvb_product( 200, array( 'status' => 'instock', 'qty' => 9 ) );
$watcher->on_stock_status( 200, 'instock', wc_get_product( 200 ) );
NvbInt::same( 0, count( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" ) ), 'محصول نادیده‌گرفته‌شده، اعلان نمی‌سازد' );

// Restock with 5 units.
nvb_product( 100, array( 'status' => 'instock', 'qty' => 5 ) );
$watcher->on_stock_status( 100, 'instock', wc_get_product( 100 ) );

$restock_rows = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
NvbInt::same( 5, count( $restock_rows ), 'موجودی ۵ با ۲۰۰ مشترک: دقیقاً ۵ اعلان' );

// A duplicate hook (same transition seen twice) must not create more rows.
$watcher->on_stock_status( 100, 'instock', wc_get_product( 100 ) );
NvbInt::same( 5, count( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" ) ), 'کنترل تکرار: اعلان تکراری ساخته نشد' );

$snapshot = WaitlistRepository::snapshot_get( 100, 0 );
NvbInt::same( 5, (int) $snapshot['announced_qty'], 'مبنای اعلان روی ۵ ثبت شد' );

// Second restock after going out of stock again: 3 more units.
$watcher->on_stock_status( 100, 'outofstock', wc_get_product( 100 ) );
nvb_product( 100, array( 'status' => 'instock', 'qty' => 8 ) );
$watcher->on_stock_status( 100, 'instock', wc_get_product( 100 ) );
$all_restock = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
NvbInt::same( 8, count( $all_restock ), 'بازگشت دوم: فقط ۳ اعلان تازه' );

// Fair sending must pick the oldest subscribers, and the second wave must
// continue exactly where the first one stopped.
$wave_rows = $GLOBALS['wpdb']->get_results(
	'SELECT waitlist_id, event_key FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%' ORDER BY waitlist_id ASC"
);
$wave_keys = array();
foreach ( $wave_rows as $wave_row ) {
	$wave_keys[ $wave_row['event_key'] ][] = (int) $wave_row['waitlist_id'];
}
NvbInt::same( 2, count( $wave_keys ), 'دو موج اعلان ساخته شد' );

$waves      = array_values( $wave_keys );
$first_wave = $waves[0];
$second_wave = $waves[1];

NvbInt::same( 5, count( $first_wave ), 'موج اول دقیقاً ۵ نفر' );
NvbInt::same( 3, count( $second_wave ), 'موج دوم دقیقاً ۳ نفر' );
NvbInt::ok( min( $first_wave ) < min( $second_wave ), 'موج دوم بعد از موج اول شروع می‌شود' );
NvbInt::ok( max( $first_wave ) < min( $second_wave ), 'هیچ مشترکی دو بار اعلان نگرفت' );

// Fair sending off: everybody is notified.
Settings::update( array( 'fair_sending' => false ) );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$watcher->on_stock_status( 100, 'outofstock', wc_get_product( 100 ) );
nvb_product( 100, array( 'status' => 'instock', 'qty' => 2 ) );
$watcher->on_stock_status( 100, 'instock', wc_get_product( 100 ) );
$unfair = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
NvbInt::ok( count( $unfair ) > 5, 'با خاموش بودن گزینهٔ سهمیه، همه اعلان می‌گیرند: ' . count( $unfair ) );

Settings::update( array( 'fair_sending' => true ) );

/* -------------------------------------------------------------------------
 * Reconcile (layer 2)
 * ---------------------------------------------------------------------- */
NvbInt::group( 'پایش تطبیقی' );

nvb_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::snapshots() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );

nvb_product( 300, array( 'status' => 'outofstock', 'qty' => 0 ) );
nvb_product( 301, array( 'status' => 'instock', 'qty' => 6 ) );

$created = WaitlistRepository::create(
	array(
		'product_id' => 300,
		'channel'    => 'email',
		'email'      => 'reconcile@example.com',
	)
);
WaitlistRepository::set_status( (int) $created['id'], 'confirmed' );

$GLOBALS['nvb_options']['navidban_snapshot_seeded'] = 'no';
$watcher = new StockWatcher();
$first   = $watcher->reconcile( true );

NvbInt::ok( $first['first_run'], 'اجرای نخست پرچم first_run دارد' );
NvbInt::same( 0, $first['queued'], 'اجرای نخست هیچ اعلانی نمی‌فرستد' );
NvbInt::same( 'yes', $GLOBALS['nvb_options']['navidban_snapshot_seeded'], 'پرچم پس از اجرای نخست ست می‌شود' );
NvbInt::same( 2, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::snapshots() ), 'تصویر برای دو محصول ذخیره شد' );

// Nothing changed: no transition.
$second = $watcher->reconcile( true );
NvbInt::same( 0, $second['transitions'], 'بدون تغییر، گذاری نیست' );

// Direct database write (simulates CSV/ERP import which fires no hook).
nvb_product( 300, array( 'status' => 'instock', 'qty' => 4 ) );
$third = $watcher->reconcile( true );
NvbInt::same( 1, $third['transitions'], 'نوشتن مستقیم دیتابیس شناسایی شد' );
NvbInt::same( 1, $third['queued'], 'یک اعلان ساخته شد' );

$fourth = $watcher->reconcile( true );
NvbInt::same( 0, $fourth['transitions'], 'اجرای بعدی گذار تکراری نمی‌سازد' );

// Throttle: a run inside the interval is skipped unless forced.
$GLOBALS['nvb_options']['navidban_last_reconcile'] = time();
$throttled = $watcher->reconcile( false );
NvbInt::same( 0, $throttled['scanned'], 'اجرای داخل بازه نادیده گرفته می‌شود' );

/* -------------------------------------------------------------------------
 * Queue processing
 * ---------------------------------------------------------------------- */
NvbInt::group( 'صف ارسال' );

nvb_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
Settings::update(
	array(
		'queue_batch_size'    => 5,
		'queue_per_minute'    => 5,
		'send_window_enabled' => false,
		'email_enabled'       => true,
		'sms_enabled'         => false,
	)
);

nvb_product( 400, array( 'status' => 'outofstock', 'qty' => 0 ) );
$ids = array();
for ( $i = 0; $i < 12; $i++ ) {
	$created = WaitlistRepository::create(
		array(
			'product_id' => 400,
			'channel'    => 'email',
			'email'      => 'queue' . $i . '@example.com',
		)
	);
	WaitlistRepository::set_status( (int) $created['id'], 'confirmed' );
	$ids[] = (int) $created['id'];
	Queue::enqueue( (int) $created['id'], 'email', 'restock:test' );
}

$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() . " WHERE event_key = 'confirm'" );

$queued_before = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() );
NvbInt::same( 12, $queued_before, '۱۲ پیام در صف' );

$GLOBALS['nvb_mail_ok'] = true;
$run = Queue::process();

NvbInt::same( 5, $run['sent'], 'دستهٔ ۵تایی ارسال شد' );
NvbInt::same( 5, count( $GLOBALS['nvb_mails'] ), 'پنج ایمیل واقعاً ساخته شد' );
NvbInt::ok( false !== strpos( $GLOBALS['nvb_mails'][0]['message'], 'لغو' ) || false !== strpos( $GLOBALS['nvb_mails'][0]['message'], 'unsubscribe' ), 'پیام شامل راه لغو است' );
NvbInt::same( 7, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'queued'" ), 'هفت پیام در صف ماند' );

$notified = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::table() . " WHERE status = 'notified'" );
NvbInt::same( 5, $notified, 'وضعیت مشترک‌های ارسال‌شده notified شد' );

// Rate limit blocks the next run inside the same minute.
$rate_run = Queue::process();
NvbInt::same( 'rate', $rate_run['skipped'], 'محدودیت نرخ جلوی اجرای دوم را می‌گیرد' );

// Failure path + exponential backoff.
$GLOBALS['nvb_mail_ok'] = false;
$GLOBALS['wpdb']->query( 'UPDATE ' . Queue::table() . " SET next_run_at = '2000-01-01 00:00:00' WHERE status = 'queued'" );
$GLOBALS['wpdb']->query( 'UPDATE ' . Queue::table() . " SET sent_at = '2000-01-01 00:00:00' WHERE status = 'sent'" );

$failed_run = Queue::process( 1 );
NvbInt::same( 1, $failed_run['failed'], 'ارسال ناموفق ثبت شد' );

$failed_row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . " WHERE status = 'queued' ORDER BY attempts DESC LIMIT 1" );
NvbInt::same( 1, (int) $failed_row['attempts'], 'تعداد تلاش یک شد' );
NvbInt::ok( strtotime( (string) $failed_row['next_run_at'] ) > time() + 600, 'عقب‌نشینی ۱۵ دقیقه اعمال شد' );
NvbInt::ok( '' !== (string) $failed_row['last_error'], 'پیام خطا ذخیره شد' );

// Third failure moves the row to failed.
$GLOBALS['wpdb']->query(
	'UPDATE ' . Queue::table() . " SET attempts = 2, next_run_at = '2000-01-01 00:00:00' WHERE id = " . (int) $failed_row['id']
);
Queue::process( 1 );
$final = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . ' WHERE id = ' . (int) $failed_row['id'] );
NvbInt::same( 'failed', $final['status'], 'پس از سه تلاش، وضعیت failed' );

// Locking: a fresh lock blocks a concurrent run.
Queue::acquire_lock();
$locked_run = Queue::process();
NvbInt::same( 'locked', $locked_run['skipped'], 'قفل هم‌زمانی کار می‌کند' );
Queue::release_lock();

// Sending window.
Settings::update( array( 'send_window_enabled' => true, 'send_window_start' => (int) gmdate( 'G' ) + 3, 'send_window_end' => (int) gmdate( 'G' ) + 4 ) );
$window_run = Queue::process();
NvbInt::same( 'window', $window_run['skipped'], 'بیرون از بازهٔ مجاز، ارسال متوقف می‌شود' );
Settings::update( array( 'send_window_enabled' => false ) );

// Unsubscribed subscriber is skipped, not retried.
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$unsub = WaitlistRepository::create(
	array(
		'product_id' => 400,
		'channel'    => 'email',
		'email'      => 'unsub@example.com',
	)
);
WaitlistRepository::set_status( (int) $unsub['id'], 'confirmed' );
Queue::enqueue( (int) $unsub['id'], 'email', 'restock:x' );
WaitlistRepository::set_status( (int) $unsub['id'], 'unsubscribed' );
Queue::process( 5 );
$skipped = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . " WHERE last_error = 'unsubscribed' LIMIT 1" );
NvbInt::ok( is_array( $skipped ), 'ردیف مشترک لغوشده رده شد' );
NvbInt::same( 'skipped', $skipped['status'] ?? '', 'وضعیت ردیف لغوشده skipped است' );
NvbInt::same( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'sent'" ), 'هیچ پیامی برای مشترک لغوشده ارسال نشد' );

// Pending subscriber never receives a restock message.
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$pending = WaitlistRepository::create(
	array(
		'product_id' => 400,
		'channel'    => 'email',
		'email'      => 'pending@example.com',
	)
);
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
Queue::enqueue( (int) $pending['id'], 'email', 'restock:y' );
Queue::process( 5 );
$pending_row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . " WHERE last_error = 'not_confirmed' LIMIT 1" );
NvbInt::ok( is_array( $pending_row ), 'ردیف تأییدنشده رده شد' );
NvbInt::same( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'sent'" ), 'به مشترک تأییدنشده پیامی نرفت' );

/* -------------------------------------------------------------------------
 * SMS channel + SSRF
 * ---------------------------------------------------------------------- */
NvbInt::group( 'کانال پیامک' );

nvb_reset_rate_limit();
nvb_sms_state( 'generic' );
$post = new \Navidban\Channels\SmsGenericRest();
NvbInt::ok( $post->is_configured(), 'درگاه عمومی با نشانی https عمومی پیکربندی‌شده است' );

$payload = $post->render_payload( '{"to":"{to}","message":"{message}"}', '09121234567', 'سلام "دنیا"' );
$decoded = json_decode( $payload, true );
NvbInt::ok( is_array( $decoded ), 'قالب داده JSON معتبر می‌ماند' );
NvbInt::same( '09121234567', $decoded['to'], 'شماره درست جای‌گذاری شد' );
NvbInt::same( 'سلام "دنیا"', $decoded['message'], 'گیومه در متن، JSON را نمی‌شکند' );

Settings::update( array( 'generic_url' => 'https://10.0.0.1/send' ) );
$private = new \Navidban\Channels\SmsGenericRest();
NvbInt::ok( ! $private->is_configured(), 'نشانی خصوصی پذیرفته نمی‌شود' );
$blocked_result = $private->send( '09121234567', '', 'x', array() );
NvbInt::ok( true !== $blocked_result, 'ارسال به نشانی خصوصی انجام نمی‌شود' );

Settings::update( array( 'generic_url' => 'https://api.sms-provider.ir/send', 'generic_token' => 'tok' ) );
$GLOBALS['nvb_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"status":200}' );
$ok_post = new \Navidban\Channels\SmsGenericRest();
NvbInt::same( true, $ok_post->send( '09121234567', '', 'سلام', array() ), 'ارسال موفق از طریق درگاه عمومی' );
NvbInt::same( 1, count( $GLOBALS['nvb_http_calls'] ), 'یک تماس خروجی انجام شد' );
NvbInt::ok( 0 === strpos( $GLOBALS['nvb_http_calls'][0][0], 'https://' ), 'تماس خروجی https است' );

$GLOBALS['nvb_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"status":400,"message":"اعتبار کافی نیست"}' );
$gateway_error = $ok_post->send( '09121234567', '', 'سلام', array() );
NvbInt::ok( true !== $gateway_error, 'خطای درگاه تشخیص داده می‌شود' );
NvbInt::ok( false !== strpos( (string) ( is_object( $gateway_error ) ? $gateway_error->get_error_message() : $gateway_error ), 'اعتبار' ), 'پیام خطای درگاه منتقل می‌شود' );

$GLOBALS['nvb_http_response'] = null;
$transport_error = $ok_post->send( '09121234567', '', 'سلام', array() );
NvbInt::ok( true !== $transport_error, 'خطای انتقال، شکست ارسال محسوب می‌شود' );

/**
 * Configure the SMS channel for a generic gateway test.
 *
 * @param string $gateway Gateway key.
 * @return void
 */
function nvb_sms_state( $gateway ) {
	$GLOBALS['nvb_transients'] = array();
	Settings::update(
		array(
			'sms_enabled'  => true,
			'sms_gateway'  => $gateway,
			'generic_url'  => 'https://api.sms-provider.ir/send',
			'generic_token' => 'token',
		)
	);
}

/* -------------------------------------------------------------------------
 * Report
 * ---------------------------------------------------------------------- */
$summary = NvbInt::summary();
$arguments = isset( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();
$as_json   = in_array( '--json', $arguments, true );

if ( ! $as_json ) {
	echo "\n=== Navidban integration harness (WordPress stubs + SQLite) ===\n";
	foreach ( $summary['groups'] as $name => $data ) {
		$status = $data['failures'] > 0 ? 'FAIL' : 'ok  ';
		printf( "[%s] %-36s %4d assertions  %d failures\n", $status, $name, $data['assertions'], $data['failures'] );
	}

	if ( $summary['failures'] > 0 ) {
		echo "\n--- failures ---\n";
		foreach ( $summary['failed'] as $failure ) {
			printf( "%s | %s\n  expected: %s\n  actual:   %s\n", $failure['group'], $failure['label'], $failure['expected'], $failure['actual'] );
		}
	}

	printf(
		"\nPHP %s — %d assertions, %d failures, pass rate %.2f%%\n",
		$summary['php_version'],
		$summary['assertions'],
		$summary['failures'],
		$summary['pass_rate']
	);
}

$encoded = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( false !== $encoded ) {
	file_put_contents( __DIR__ . '/integration-results.json', $encoded . "\n" );
}

if ( $as_json ) {
	echo $encoded . "\n";
}

exit( $summary['failures'] > 0 ? 1 : 0 );
