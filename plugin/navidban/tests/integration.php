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
// The storefront view uses checked()/selected(), which live in the admin stub.
require_once __DIR__ . '/stubs/admin.php';
require_once __DIR__ . '/stubs/woocommerce.php';

require_once NAVIDBAN_DIR . 'includes/Autoloader.php';
\Navidban\Autoloader::register();

use Navidban\Activator;
use Navidban\Capabilities;
use Navidban\Consent;
use Navidban\Database;
use Navidban\Frontend;
use Navidban\Notifier;
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

// This group exercises the two-step (opt-in) path, so it turns the option on
// explicitly. The shipped default is one-step; see the next group.
Settings::update( array( 'double_opt_in' => true ) );

nvb_reset_state();
nvb_reset_rate_limit();
nvb_product( 40, array( 'status' => 'outofstock', 'qty' => 0 ) );

$result = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'Maryam@Example.COM',
		'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
	)
);
NvbInt::same( 'consent_required', $no_consent['code'], 'بدون رضایت ثبت نمی‌شود' );
$with_consent = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'noconsent@example.com',
		'name'       => 'مریم احمدی',
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
			'name'       => 'مریم احمدی',
		)
	);
}
$codes = array_column( $limited, 'code' );
NvbInt::ok( in_array( 'rate_limited', $codes, true ), 'محدودیت نرخ فعال شد' );

/* -------------------------------------------------------------------------
 * Consent tokens
 * ---------------------------------------------------------------------- */
NvbInt::group( 'ثبت یک‌مرحله‌ای (پیش‌فرض تازه)' );

// A fresh install must let a shopper subscribe with one click: no confirmation
// message, no pending row. This is the behaviour the market asked for.
$GLOBALS['nvb_options']['navidban_settings'] = Settings::defaults();
Settings::flush_cache();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );

NvbInt::ok( false === Settings::enabled( 'double_opt_in' ), 'پیش‌فرض: تأیید دومرحله‌ای خاموش است' );
NvbInt::same( 'sms_first', (string) Settings::get( 'channel_choice', '' ), 'پیش‌فرض: پیامک اولویت اول است' );

nvb_reset_rate_limit();
$one_step = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'onestep@example.com',
		'name'       => 'مریم احمدی',
	)
);

NvbInt::ok( $one_step['ok'], 'ثبت یک‌مرحله‌ای موفق است' );
NvbInt::same( 'subscribed', $one_step['code'], 'پاسخ subscribed است، نه confirmation_sent' );
NvbInt::same( false, $one_step['data']['needs_confirm'], 'نیاز به تأیید گزارش نمی‌شود' );

$one_row = WaitlistRepository::get( (int) $one_step['data']['id'] );
NvbInt::same( 'confirmed', $one_row['status'], 'ردیف مستقیماً تأییدشده است' );
NvbInt::ok( ! empty( $one_row['confirmed_at'] ), 'زمان تأیید ثبت شده است' );
NvbInt::ok( empty( $one_row['confirm_expires_at'] ), 'هیچ انقضای تأییدی ساخته نشد' );
NvbInt::same( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() ), 'هیچ پیام تأییدی در صف نیست' );

// Without the click, the subscriber is already eligible for a restock message.
nvb_reset_rate_limit();
$one_restock = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'eligible@example.com',
		'name'       => 'مریم احمدی',
	)
);
NvbInt::same( 'confirmed', WaitlistRepository::get( (int) $one_restock['data']['id'] )['status'], 'مشترک دوم هم مستقیماً تأیید شد' );

NvbInt::group( 'تأیید و لغو اشتراک' );

// Back to the opt-in path for the token tests.
$GLOBALS['nvb_options']['navidban_settings'] = array_merge(
	Settings::defaults(),
	array(
		'consent_required' => false,
		'double_opt_in'    => true,
	)
);
Settings::flush_cache();

$fresh = WaitlistRepository::create(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'token@example.com',
		'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
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
NvbInt::group( 'انتخاب کانال فرم' );

$front = new Frontend();

// Fresh install: only email can deliver, and both field switches are on, but a
// phone field with no gateway would collect numbers nobody can notify.
$GLOBALS['nvb_options']['navidban_settings'] = Settings::defaults();
Settings::flush_cache();
NvbInt::ok( Notifier::email_ready(), 'ایمیل آماده است' );
NvbInt::ok( ! Notifier::sms_ready(), 'پیامک آماده نیست (پیکربندی نشده)' );
NvbInt::same( 'email', $front->channel_mode(), 'ایمیل تنها: فقط فیلد ایمیل' );
NvbInt::same( 'email', $front->default_channel(), 'حالت تک‌کاناله: کانال پیش‌فرض ایمیل' );

// SMS switched on but no credentials: still not usable, and the form must not
// offer a field for it.
Settings::update( array( 'sms_enabled' => true ) );
NvbInt::ok( ! Notifier::sms_ready(), 'پیامکِ بدون کلید، آماده محسوب نمی‌شود' );
NvbInt::same( 'email', $front->channel_mode(), 'پیامک بی‌کلید، فیلد موبایل را باز نمی‌کند' );

// Properly configured gateway: both fields are on by default.
Settings::update(
	array(
		'sms_gateway'      => 'melipayamak',
		'melipayamak_user' => 'demo',
		'melipayamak_pass' => 'secret',
	)
);
NvbInt::ok( Notifier::sms_ready(), 'پیامک با کلید معتبر آماده است' );
NvbInt::same( 'both', $front->channel_mode(), 'هر دو فیلد روشن: حالت both' );
NvbInt::same( 'sms', $front->default_channel(), 'پیش‌فرض sms_first: پیامک از قبل انتخاب است' );

Settings::update( array( 'channel_choice' => 'email_first' ) );
NvbInt::same( 'email', $front->default_channel(), 'email_first: ایمیل از قبل انتخاب است' );
NvbInt::same( 'both', $front->channel_mode(), 'اولویت کانال، تعداد فیلدها را تغییر نمی‌دهد' );

// The switches the store actually flips.
Settings::update( array( 'channel_choice' => 'sms_first', 'form_email_field' => false ) );
NvbInt::same( 'sms', $front->channel_mode(), 'فیلد ایمیل خاموش: فقط موبایل' );

Settings::update( array( 'form_email_field' => true, 'form_phone_field' => false ) );
NvbInt::same( 'email', $front->channel_mode(), 'فیلد موبایل خاموش: فقط ایمیل' );

// A stored row with both switches off (the sanitiser refuses this, but an old
// option row could hold it) must not produce an empty form.
$GLOBALS['nvb_options']['navidban_settings'] = array_merge(
	Settings::defaults(),
	array(
		'form_email_field' => false,
		'form_phone_field' => false,
		'sms_enabled'      => true,
		'melipayamak_user' => 'demo',
		'melipayamak_pass' => 'secret',
	)
);
Settings::flush_cache();
NvbInt::same( 'sms', $front->channel_mode(), 'هر دو فیلد خاموش: به کانالِ در دسترس برمی‌گردد' );

// Nothing usable at all: email shell, so the page is never blank.
$GLOBALS['nvb_options']['navidban_settings'] = array_merge(
	Settings::defaults(),
	array( 'email_enabled' => false, 'sms_enabled' => false, 'form_email_field' => false, 'form_phone_field' => false )
);
Settings::flush_cache();
NvbInt::same( 'email', $front->channel_mode(), 'هیچ کانالی آماده نیست: پوستهٔ ایمیل' );

NvbInt::group( 'رندر فرم فروشگاه' );

/**
 * Render the storefront form view for one mode and return the HTML.
 *
 * @param string $mode email|sms|both.
 * @return string
 */
function nvb_render_form( $mode ) {
	$settings        = array(
		'mode'        => $mode,
		'heading'     => 'خبرم کن',
		'intro'       => 'موجود نیست.',
		'notice'      => '',
		'button'      => 'خبرم کن',
		'consent_req' => false,
		'consent'     => '',
		'privacy'     => '',
	);
	$product_id      = 40;
	$variations      = array();
	$default_channel = 'sms' === $mode ? 'sms' : 'email';
	$user_id         = 0;
	$prefill_email   = '';
	$prefill_phone   = '';

	ob_start();
	include NAVIDBAN_DIR . 'admin/views/frontend-form.php';

	return (string) ob_get_clean();
}

$form_sms = nvb_render_form( 'sms' );
NvbInt::ok( false !== strpos( $form_sms, 'id="nvb-phone"' ), 'حالت پیامک: کادر موبایل هست' );
NvbInt::ok( false === strpos( $form_sms, 'id="nvb-email"' ), 'حالت پیامک: کادر ایمیل اصلاً رندر نمی‌شود' );
NvbInt::ok( false !== strpos( $form_sms, 'name="channel" value="sms"' ), 'حالت پیامک: کانال پنهان sms است' );
NvbInt::same( 1, substr_count( $form_sms, 'required' ), 'حالت پیامک: فقط یک فیلد اجباری است' );

$form_email = nvb_render_form( 'email' );
NvbInt::ok( false !== strpos( $form_email, 'id="nvb-email"' ), 'حالت ایمیل: کادر ایمیل هست' );
NvbInt::ok( false === strpos( $form_email, 'id="nvb-phone"' ), 'حالت ایمیل: کادر موبایل رندر نمی‌شود' );
NvbInt::ok( false !== strpos( $form_email, 'name="channel" value="email"' ), 'حالت ایمیل: کانال پنهان email است' );

$form_both = nvb_render_form( 'both' );
NvbInt::ok( false !== strpos( $form_both, 'id="nvb-email"' ) && false !== strpos( $form_both, 'id="nvb-phone"' ), 'حالت both: هر دو کادر هستند' );
NvbInt::ok( false !== strpos( $form_both, 'type="radio"' ), 'حالت both: انتخاب کانال نمایش داده می‌شود' );

// The one-step default applies here too: nothing about the form asks for a
// confirmation click.
NvbInt::ok( false === strpos( $form_both, 'confirm' ), 'فرم هیچ اشاره‌ای به مرحلهٔ تأیید ندارد' );

NvbInt::group( 'موتور موجودی' );
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
			'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
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
			'name'       => 'مریم احمدی',
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
		'name'       => 'مریم احمدی',
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
Settings::update( array( 'double_opt_in' => true ) );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$pending = WaitlistRepository::create(
	array(
		'product_id' => 400,
		'channel'    => 'email',
		'email'      => 'pending@example.com',
		'name'       => 'مریم احمدی',
	)
);
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
Queue::enqueue( (int) $pending['id'], 'email', 'restock:y' );
Queue::process( 5 );
$pending_row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . " WHERE last_error = 'not_confirmed' LIMIT 1" );
NvbInt::ok( is_array( $pending_row ), 'ردیف تأییدنشده رده شد' );
NvbInt::same( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'sent'" ), 'به مشترک تأییدنشده پیامی نرفت' );
Settings::update( array( 'double_opt_in' => false ) );

/* -------------------------------------------------------------------------
 * SMS channel + SSRF
 * ---------------------------------------------------------------------- */
NvbInt::group( 'خطای ارسال ایمیل' );

// A bare `false` from wp_mail() is useless to an administrator. WordPress
// fires wp_mail_failed with the PHPMailer exception; the channel must surface
// it, and must not leave a listener behind.
$GLOBALS['nvb_mail_ok']    = false;
$GLOBALS['nvb_mail_error'] = 'Could not instantiate mail function.';
unset( $GLOBALS['nvb_actions']['wp_mail_failed'] );

$mail_channel = new \Navidban\Channels\EmailChannel();
$mail_failure = $mail_channel->send( 'buyer@example.com', 'موضوع', 'متن', array() );
$mail_message = is_object( $mail_failure ) && method_exists( $mail_failure, 'get_error_message' )
	? $mail_failure->get_error_message()
	: (string) $mail_failure;

NvbInt::ok( true !== $mail_failure, 'شکست ارسال به خطا تبدیل می‌شود' );
NvbInt::ok( false !== strpos( $mail_message, 'Could not instantiate mail function.' ), 'دلیل واقعی wp_mail منتقل می‌شود', $mail_message );
NvbInt::ok( false !== strpos( $mail_message, 'wp_mail' ), 'پیام به wp_mail اشاره می‌کند' );
$listener_count = 0;
foreach ( ( $GLOBALS['nvb_actions']['wp_mail_failed'] ?? array() ) as $bucket ) {
	$listener_count += is_array( $bucket ) ? count( $bucket ) : 0;
}
NvbInt::same( 0, $listener_count, 'شنونده پس از فراخوانی برداشته شد (بدون نشت)' );

$GLOBALS['nvb_mail_error'] = '';
$no_reason                 = $mail_channel->send( 'buyer@example.com', 'موضوع', 'متن', array() );
$no_reason_message         = is_object( $no_reason ) ? $no_reason->get_error_message() : (string) $no_reason;
NvbInt::ok( false !== strpos( $no_reason_message, 'دلیلی گزارش نکرد' ), 'نبود دلیل هم صریح گفته می‌شود', $no_reason_message );

// A very long Persian error must survive the column limit intact: a byte-wise
// substr() would split a character and MySQL would reject the row.
$GLOBALS['nvb_mail_ok']    = false;
$GLOBALS['nvb_mail_error'] = str_repeat( 'خطای سرویس ایمیل هاست؛ ', 40 );

$long_waitlist = WaitlistRepository::create(
	array(
		'product_id'   => 777,
		'variation_id' => 0,
		'channel'      => 'email',
		'email'        => 'long@example.com',
		'name'       => 'مریم احمدی',
		'phone'        => '',
		'ip_hash'      => str_repeat( 'd', 64 ),
		'status'       => 'confirmed',
		'consent_at'   => gmdate( 'Y-m-d H:i:s' ),
	)
);

Queue::enqueue( (int) $long_waitlist['id'], 'email', 'restock:20261026:longerr', array(), time() );

$long_id = (int) $GLOBALS['wpdb']->get_var(
	'SELECT id FROM ' . Queue::table() . " WHERE event_key = 'restock:20261026:longerr' LIMIT 1"
);

NvbInt::ok( $long_id > 0, 'ردیف صف برای آزمون خطای طولانی ساخته شد' );

if ( $long_id > 0 ) {
	Queue::deliver( $long_id );
	$stored = (string) $GLOBALS['wpdb']->get_var( 'SELECT last_error FROM ' . Queue::table() . ' WHERE id = ' . $long_id );
	NvbInt::ok( '' !== $stored, 'خطای طولانی در صف ذخیره شد' );
	NvbInt::same( 1, preg_match( '//u', $stored ), 'متن ذخیره‌شده UTF-8 معتبر است' );
	NvbInt::ok( \Navidban\Support\Text::length( $stored ) <= 255, 'طول متن از سقف ستون عبور نمی‌کند' );
}

$GLOBALS['nvb_mail_ok']    = true;
$GLOBALS['nvb_mail_error'] = '';
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() . " WHERE event_key = 'restock:20261026:longerr'" );

// From resolution: an explicit setting wins, an invalid one falls back to the
// site admin address, and the header always carries a syntactically valid value.
Settings::update( array( 'from_email' => 'noreply@shop.example' ) );
NvbInt::same( 'noreply@shop.example', \Navidban\Channels\EmailChannel::from_address(), 'فرستندهٔ تنظیم‌شده استفاده می‌شود' );

Settings::update( array( 'from_email' => 'not-an-email' ) );
NvbInt::same( strtolower( (string) get_option( 'admin_email', '' ) ), \Navidban\Channels\EmailChannel::from_address(), 'فرستندهٔ نامعتبر به ایمیل مدیر برمی‌گردد' );

Settings::update( array( 'from_email' => 'noreply@shop.example' ) );
$GLOBALS['nvb_mails'] = array();
$mail_channel->send( 'buyer@example.com', 'موضوع', 'متن', array() );
$sent_headers = implode( "\n", (array) ( $GLOBALS['nvb_mails'][0]['headers'] ?? array() ) );
NvbInt::ok( false !== strpos( $sent_headers, 'noreply@shop.example' ), 'هدر From نشانی تنظیم‌شده را دارد', $sent_headers );
NvbInt::ok( false !== strpos( $sent_headers, 'Reply-To' ), 'هدر Reply-To تنظیم می‌شود' );

Settings::update( array( 'from_email' => '' ) );

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

NvbInt::group( 'الگوی ملی‌پیامک (bodyId)' );

$GLOBALS['nvb_transients'] = array();
Settings::update(
	array(
		'sms_enabled'           => true,
		'sms_gateway'           => 'melipayamak',
		'melipayamak_user'      => '09120000000',
		'melipayamak_pass'      => 'secret',
		'melipayamak_from'      => '1000',
		'melipayamak_body_id'   => '',
		'sms_pattern_template'  => '',
		'site_name'             => 'فروشگاه نمونه',
	)
);

$restock_context = array(
	'context' => array(
		'customer_name' => 'مریم',
		'product_name'  => 'کفش دویدن',
		'product_url'   => 'https://shop.test/p/10',
		'price'         => '۲٬۴۵۰٬۰۰۰ تومان',
		'site_name'     => 'فروشگاه نمونه',
	),
);

$GLOBALS['nvb_http_calls']    = array();
$GLOBALS['nvb_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"Value":"1234","RetStatus":1,"StrRetStatus":"Ok"}' );
$plain_sms = new \Navidban\Channels\SmsMelipayamak();
NvbInt::same( true, $plain_sms->send( '09121234567', '', 'متن ساده', $restock_context ), 'بدون bodyId ارسال متنی انجام می‌شود' );
NvbInt::same( 'https://rest.payamak-panel.com/api/SendSMS/SendSMS', $GLOBALS['nvb_http_calls'][0][0], 'نشانی درست در حالت متنی' );
NvbInt::same( 'متن ساده', $GLOBALS['nvb_http_calls'][0][1]['body']['text'], 'متن خام در حالت متنی فرستاده می‌شود' );

// Pattern mode: the gateway holds the approved text, we send only the values.
Settings::update( array( 'melipayamak_body_id' => ' 98765 ' ) );
$GLOBALS['nvb_http_calls'] = array();
NvbInt::same( true, $plain_sms->send( '09121234567', '', 'متن ساده', $restock_context ), 'ارسال الگویی موفق' );
NvbInt::same( 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', $GLOBALS['nvb_http_calls'][0][0], 'نشانی درست در حالت الگو' );

$pattern_call = $GLOBALS['nvb_http_calls'][0][1]['body'];
NvbInt::same( 98765, $pattern_call['bodyId'], 'شناسهٔ الگو به عدد تبدیل شد' );
NvbInt::same( '09121234567', $pattern_call['to'], 'شماره در حالت الگو' );
NvbInt::ok( ! isset( $pattern_call['isflash'] ), 'پارامترهای حالت متنی در حالت الگو فرستاده نمی‌شوند' );
NvbInt::same(
	'مریم;کفش دویدن;https://shop.test/p/10',
	$pattern_call['text'],
	'مقدارها به ترتیب شماره با جداکنندهٔ نقطه‌ویرگول'
);
NvbInt::ok( false === strpos( $pattern_call['text'], 'متن ساده' ), 'متن کاملِ رندرشده جای مقدارها فرستاده نمی‌شود' );

// A stored template that reorders or drops variables changes the payload.
Settings::update( array( 'sms_pattern_template' => 'کالای {1} برای {0} موجود شد. {2} — فروشگاه نمونه' ) );
$GLOBALS['nvb_http_calls'] = array();
$plain_sms->send( '09121234567', '', 'x', $restock_context );
NvbInt::same( 'مریم;کفش دویدن;https://shop.test/p/10', $GLOBALS['nvb_http_calls'][0][1]['body']['text'], 'ترتیب مقدارها از روی شمارهٔ متغیر است' );

Settings::update( array( 'sms_pattern_template' => '{0} عزیز، موجود شد — فروشگاه نمونه' ) );
$GLOBALS['nvb_http_calls'] = array();
$plain_sms->send( '09121234567', '', 'x', $restock_context );
NvbInt::same( 'مریم', $GLOBALS['nvb_http_calls'][0][1]['body']['text'], 'الگوی تک‌متغیره یک مقدار می‌فرستد' );

// {1} alone is a numbering error: the gateway numbers from zero without gaps.
Settings::update( array( 'sms_pattern_template' => '{1} موجود شد — فروشگاه نمونه' ) );
$GLOBALS['nvb_http_calls'] = array();
NvbInt::ok( true !== $plain_sms->send( '09121234567', '', 'x', $restock_context ), 'الگوی تک‌متغیره با شمارهٔ ۱ رد می‌شود' );
NvbInt::ok( 0 === count( $GLOBALS['nvb_http_calls'] ), 'برای شماره‌گذاری نادرست تماسی انجام نشد' );

// A broken template is refused locally, so no money is spent on a send that
// the gateway would reject anyway.
Settings::update( array( 'sms_pattern_template' => '{0} عزیز، کالای شما موجود شد {1}' ) );
$GLOBALS['nvb_http_calls'] = array();
$broken = $plain_sms->send( '09121234567', '', 'x', $restock_context );
NvbInt::ok( true !== $broken, 'الگوی نامعتبر پیش از تماس با درگاه رد می‌شود' );
NvbInt::ok( 0 === count( $GLOBALS['nvb_http_calls'] ), 'برای الگوی نامعتبر هیچ تماسی با درگاه انجام نشد' );
NvbInt::ok( false !== strpos( (string) ( is_object( $broken ) ? $broken->get_error_message() : $broken ), 'متغیر' ), 'پیام خطا به متغیر اشاره می‌کند' );

// Missing values must not collapse the list.
Settings::update( array( 'sms_pattern_template' => '{0} عزیز، {1} موجود شد — فروشگاه نمونه' ) );
$GLOBALS['nvb_http_calls'] = array();
$plain_sms->send( '09121234567', '', 'x', array( 'context' => array( 'product_name' => 'کفش دویدن' ) ) );
$thin = explode( ';', (string) $GLOBALS['nvb_http_calls'][0][1]['body']['text'] );
NvbInt::same( 2, count( $thin ), 'تعداد مقدارها با تعداد متغیرها برابر می‌ماند' );
NvbInt::same( 'کفش دویدن', $thin[1], 'مقدار موجود در جای خودش می‌نشیند' );

Settings::update( array( 'melipayamak_body_id' => '', 'sms_pattern_template' => '' ) );
$GLOBALS['nvb_http_response'] = null;

NvbInt::group( 'نام مشتری و پیگیری تماس' );

$GLOBALS['nvb_options']['navidban_settings'] = Settings::defaults();
Settings::flush_cache();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
nvb_reset_rate_limit();
nvb_product( 70, array( 'status' => 'outofstock', 'qty' => 0 ) );

// 1. The name is stored exactly as the visitor typed it (normalised, not mangled).
$named = Subscribe::handle(
	array(
		'product_id' => 70,
		'channel'    => 'email',
		'email'      => 'named@example.com',
		'name'       => '  مریم   احمدی ',
	)
);
NvbInt::ok( $named['ok'], 'ثبت با نام موفق است' );
$named_row = WaitlistRepository::get( (int) $named['data']['id'] );
NvbInt::same( 'مریم احمدی', $named_row['full_name'], 'نام نرمال‌شده ذخیره شد' );

// 2. Required by default: no name, no row.
nvb_reset_rate_limit();
$no_name = Subscribe::handle(
	array(
		'product_id' => 70,
		'channel'    => 'email',
		'email'      => 'noname@example.com',
	)
);
NvbInt::same( 'invalid_name', $no_name['code'], 'بدون نام ثبت نمی‌شود (پیش‌فرض اجباری)' );
NvbInt::same( 422, $no_name['status'], 'کد وضعیت ۴۲۲ است' );
NvbInt::same( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::table() ), 'ردیف جدیدی ساخته نشد' );

// 3. A phone number is not a name.
nvb_reset_rate_limit();
$digits = Subscribe::handle(
	array(
		'product_id' => 70,
		'channel'    => 'email',
		'email'      => 'digits@example.com',
		'name'       => '09121234567',
	)
);
NvbInt::same( 'invalid_name', $digits['code'], 'شمارهٔ موبایل به‌جای نام پذیرفته نمی‌شود' );

// 4. Optional when the store says so.
Settings::update( array( 'form_name_required' => false ) );
nvb_reset_rate_limit();
$optional = Subscribe::handle(
	array(
		'product_id' => 70,
		'channel'    => 'email',
		'email'      => 'optional@example.com',
	)
);
NvbInt::ok( $optional['ok'], 'وقتی نام اجباری نیست، بدون نام هم ثبت می‌شود' );
NvbInt::same( '', WaitlistRepository::get( (int) $optional['data']['id'] )['full_name'], 'نام خالی می‌ماند، نه «۰» یا NULL' );
Settings::update( array( 'form_name_required' => true ) );

// 5. The name reaches the message context, which is what the SMS pattern uses.
$context = \Navidban\Notifier::build_context( $named_row, false );
NvbInt::same( 'مریم احمدی', $context['customer_name'], 'متغیر {customer_name} نام ثبت‌شده است' );
NvbInt::same( 'مریم احمدی', \Navidban\Logic\SmsPattern::value( 0, $context ), 'مقدار {0} الگوی پیامک همان نام است' );

// Without a stored name, the old behaviour is kept.
$context_fallback = \Navidban\Notifier::build_context(
	array(
		'id'           => 0,
		'user_id'      => 0,
		'full_name'    => '',
		'product_id'   => 70,
		'variation_id' => 0,
		'token'        => str_repeat( 'a', 64 ),
	),
	false
);
NvbInt::same( 'همراه گرامی', $context_fallback['customer_name'], 'نبود نام، متن پیش‌فرض را نگه می‌دارد' );

// 6. Call tracking.
$id = (int) $named['data']['id'];
NvbInt::ok( empty( $named_row['called_at'] ), 'ردیف تازه، تماس‌گرفته‌نشده است' );

\Navidban\WaitlistRepository::set_called( $id, true, 7 );
$called_row = WaitlistRepository::get( $id );
NvbInt::ok( ! empty( $called_row['called_at'] ), 'زمان تماس ثبت شد' );
NvbInt::same( 7, (int) $called_row['called_by'], 'کاربر ثبت‌کنندهٔ تماس ذخیره شد' );

$counts_after = WaitlistRepository::counts();
NvbInt::same( 1, (int) $counts_after['called'], 'شمارندهٔ تماس‌گرفته‌شده‌ها یک است' );

$not_called = WaitlistRepository::query( array( 'called' => 'no' ) );
$was_called = WaitlistRepository::query( array( 'called' => 'yes' ) );
NvbInt::same( 1, (int) $not_called['total'], 'فیلتر «تماس گرفته نشده» فقط ردیف بی‌تماس را می‌دهد' );
NvbInt::same( 1, (int) $was_called['total'], 'فیلتر «تماس گرفته شده» یک ردیف می‌دهد' );

// Search finds the row by name, which is how a shop looks somebody up.
$by_name = WaitlistRepository::query( array( 'search' => 'احمدی' ) );
NvbInt::same( 1, (int) $by_name['total'], 'جست‌وجو با نام کار می‌کند' );

// Unticking is a real operation, not a no-op.
\Navidban\WaitlistRepository::set_called( $id, false );
$uncalled_row = WaitlistRepository::get( $id );
NvbInt::ok( empty( $uncalled_row['called_at'] ), 'تیک تماس برداشته شد' );
NvbInt::same( 0, (int) $uncalled_row['called_by'], 'ثبت‌کننده هم پاک شد' );
NvbInt::same( 0, (int) WaitlistRepository::counts()['called'], 'شمارنده به صفر برگشت' );

// 7. The admin-ajax fallback must carry the name too.
//    Regression: this path mapped product_id/channel/email/phone but not
//    `name`, so every visitor who reached the fallback (REST blocked by a
//    security plugin, or a network error) got "invalid_name" for a name they
//    had just typed. The two paths must stay in sync.
$ajax_output = '';
$GLOBALS['nvb_options']['navidban_settings'] = Settings::defaults();
Settings::flush_cache();
nvb_reset_rate_limit();
$previous_post = $_POST;
$_POST         = array(
	'nonce'      => wp_create_nonce( \Navidban\Ajax::ACTION ),
	'product_id' => 70,
	'channel'    => 'email',
	'email'      => 'ajax@example.com',
	'name'       => 'زهرا کریمی',
	'consent'    => 1,
);
ob_start();
( new \Navidban\Ajax() )->subscribe();
$ajax_output = (string) ob_get_clean();
$_POST       = $previous_post;

$ajax_body = json_decode( $ajax_output, true );
NvbInt::ok( is_array( $ajax_body ), 'پاسخ مسیر اَجَکس JSON معتبر است' );
NvbInt::ok( ! empty( $ajax_body['ok'] ), 'مسیر اَجَکس نام تایپ‌شده را رد نمی‌کند' );
NvbInt::same( 'زهرا کریمی', (string) $GLOBALS['wpdb']->get_var( 'SELECT full_name FROM ' . WaitlistRepository::table() . " WHERE email = 'ajax@example.com'" ), 'نام در مسیر اَجَکس هم ذخیره می‌شود' );

// The two entry points must accept exactly the same field list.
$rest_source = (string) file_get_contents( NAVIDBAN_DIR . 'includes/Rest.php' );
$ajax_source = (string) file_get_contents( NAVIDBAN_DIR . 'includes/Ajax.php' );
NvbInt::ok( false !== strpos( $ajax_source, "'name'" ), 'Ajax::subscribe نام را می‌خواند' );
NvbInt::ok( false !== strpos( $rest_source, 'get_json_params' ), 'REST مسیر بدنهٔ JSON را می‌خواند (بدون فیلتر args)' );

// A row that does not exist must not blow up.
NvbInt::ok( false !== \Navidban\WaitlistRepository::set_called( 999999, true, 1 ), 'به‌روزرسانی ردیف ناموجود خطا نمی‌دهد' );

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
