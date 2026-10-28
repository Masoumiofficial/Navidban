<?php
/**
 * Integration harness: executes the real plugin classes against a WordPress
 * stub backed by SQLite. No web server, no MySQL, no Composer.
 *
 * Usage: php tests/integration.php [--json]
 *
 * @package Mojoodyar
 */

require_once __DIR__ . '/stubs/wp.php';
// The storefront view uses checked()/selected(), which live in the admin stub.
require_once __DIR__ . '/stubs/admin.php';
require_once __DIR__ . '/stubs/woocommerce.php';

require_once MOJODYAR_DIR . 'includes/Autoloader.php';
\Mojoodyar\Autoloader::register();

use Mojoodyar\Activator;
use Mojoodyar\CallLog;
use Mojoodyar\Capabilities;
use Mojoodyar\Consent;
use Mojoodyar\Database;
use Mojoodyar\Frontend;
use Mojoodyar\Logger;
use Mojoodyar\Logic\LogExplain;
use Mojoodyar\Logic\StatsMath;
use Mojoodyar\Logs;
use Mojoodyar\Notifier;
use Mojoodyar\Queue;
use Mojoodyar\Settings;
use Mojoodyar\Stats;
use Mojoodyar\StockWatcher;
use Mojoodyar\Subscribe;
use Mojoodyar\WaitlistRepository;

/**
 * Assertion helper (same shape as tests/run.php).
 */
final class MjyInt {

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
			'plugin_version' => MOJODYAR_VERSION,
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
function mjy_reset_state() {
	$GLOBALS['mjy_products'] = array();
	$GLOBALS['mjy_mails']    = array();
	$GLOBALS['mjy_enqueued'] = array();
	$GLOBALS['mjy_http_calls'] = array();
	$GLOBALS['mjy_http_response'] = null;
	$GLOBALS['mjy_capabilities'] = array();
	$GLOBALS['mjy_is_product'] = false;
	$GLOBALS['mjy_queried_id'] = 0;
	Settings::flush_cache();
	Queue::release_lock();
}

/**
 * Clear the security log so the per-IP rate limiter starts from zero.
 * A test suite is one "IP" hammering the endpoint; a real visitor is not.
 *
 * @return void
 */
function mjy_reset_rate_limit() {
	$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ) );
}

/**
 * Create (or overwrite) a stub product.
 *
 * @param int   $id   ID.
 * @param array $data Data.
 * @return void
 */
function mjy_product( $id, array $data ) {
	$GLOBALS['mjy_products'][ (int) $id ] = array_merge(
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
MjyInt::group( 'نصب و چیدمان پایگاه داده' );

Activator::activate( false );

$tables = Database::inspect();
MjyInt::same( 5, count( $tables ), 'پنج جدول ثبت شد (چهار جدول اصلی + تاریخچهٔ تماس)' );
MjyInt::ok( isset( $GLOBALS['mjy_ddl'] ) && count( $GLOBALS['mjy_ddl'] ) >= 4, 'dbDelta برای هر جدول فراخوانی شد' );

$ddl = implode( "\n", $GLOBALS['mjy_ddl'] );
MjyInt::same( 5, substr_count( $ddl, 'ENGINE=InnoDB' ), 'همهٔ جدول‌ها InnoDB' );
MjyInt::ok( false === strpos( $ddl, 'MyISAM' ), 'هیچ جدول MyISAM نیست' );
MjyInt::ok( false !== strpos( $ddl, 'UNIQUE KEY uniq_subscriber' ), 'کلید یکتای مشترک وجود دارد' );
MjyInt::ok( false !== strpos( $ddl, 'UNIQUE KEY uniq_payload' ), 'کلید یکتای پیام صف وجود دارد' );
MjyInt::ok( false !== strpos( $ddl, 'email VARCHAR(190) NOT NULL' ), 'ایمیل NOT NULL است تا کلید یکتا کار کند' );

$settings = Settings::all();
MjyInt::ok( ! empty( $settings['email_enabled'] ), 'ایمیل به‌صورت پیش‌فرض فعال است' );
MjyInt::same( 50, $settings['queue_batch_size'], 'اندازهٔ دستهٔ پیش‌فرض' );
MjyInt::ok( empty( $settings['delete_data_on_uninstall'] ), 'حذف داده هنگام حذف، پیش‌فرض خاموش' );
MjyInt::ok( ! empty( $GLOBALS['mjy_cron']['mojoodyar_process_queue'] ), 'cron صف زمان‌بندی شد' );
MjyInt::ok( ! empty( $GLOBALS['mjy_cron']['mojoodyar_reconcile_stock'] ), 'cron پایش زمان‌بندی شد' );

/* -------------------------------------------------------------------------
 * Conditional asset loading
 * ---------------------------------------------------------------------- */
MjyInt::group( 'بارگذاری شرطی' );

// 1. Home page. A fresh instance per scenario: should_load() memoises per request.
mjy_reset_state();
$frontend = new Frontend();
mjy_product( 10, array( 'status' => 'outofstock', 'qty' => 0 ) );
$GLOBALS['mjy_is_product'] = false;
$GLOBALS['mjy_queried_id'] = 10;
$frontend->maybe_enqueue();
MjyInt::same( array(), $GLOBALS['mjy_enqueued'], 'صفحهٔ غیر محصول: هیچ فایلی صف نمی‌شود' );

// 2. Product page of an in-stock simple product.
mjy_reset_state();
$frontend = new Frontend();
mjy_product( 11, array( 'status' => 'instock', 'qty' => 5 ) );
$GLOBALS['mjy_is_product'] = true;
$GLOBALS['mjy_queried_id'] = 11;
$frontend->maybe_enqueue();
MjyInt::same( array(), $GLOBALS['mjy_enqueued'], 'محصول موجود: هیچ فایلی صف نمی‌شود' );

// 3. Product page of an out-of-stock product.
mjy_reset_state();
$frontend = new Frontend();
mjy_product( 12, array( 'status' => 'outofstock', 'qty' => 0 ) );
$GLOBALS['mjy_is_product'] = true;
$GLOBALS['mjy_queried_id'] = 12;
$frontend->maybe_enqueue();
MjyInt::same( 1, count( $GLOBALS['mjy_enqueued']['style'] ?? array() ), 'محصول ناموجود: یک استایل' );
MjyInt::same( 1, count( $GLOBALS['mjy_enqueued']['script'] ?? array() ), 'محصول ناموجود: یک اسکریپت' );
MjyInt::ok( isset( $GLOBALS['mjy_enqueued']['localized']['MojoodyarData'] ), 'داده‌های محلی‌شده برای اسکریپت' );

// 4. Variable product: parent in stock, one variation sold out.
mjy_reset_state();
$frontend = new Frontend();
mjy_product( 20, array( 'type' => 'variable', 'status' => 'instock', 'qty' => 0, 'children' => array( 21, 22 ) ) );
mjy_product( 21, array( 'parent' => 20, 'status' => 'instock', 'qty' => 4, 'attributes' => 'سایز ۴۲' ) );
mjy_product( 22, array( 'parent' => 20, 'status' => 'outofstock', 'qty' => 0, 'attributes' => 'سایز ۴۳' ) );
$GLOBALS['mjy_is_product'] = true;
$GLOBALS['mjy_queried_id'] = 20;
$frontend->maybe_enqueue();
MjyInt::same( 1, count( $GLOBALS['mjy_enqueued']['script'] ?? array() ), 'محصول متغیر با تنوع ناموجود: اسکریپت بار می‌شود' );

// 5. Variable product where every variation is available.
mjy_reset_state();
$frontend = new Frontend();
mjy_product( 30, array( 'type' => 'variable', 'status' => 'instock', 'children' => array( 31 ) ) );
mjy_product( 31, array( 'parent' => 30, 'status' => 'instock', 'qty' => 3 ) );
$GLOBALS['mjy_is_product'] = true;
$GLOBALS['mjy_queried_id'] = 30;
$frontend->maybe_enqueue();
MjyInt::same( array(), $GLOBALS['mjy_enqueued'], 'همهٔ تنوع‌ها موجود: هیچ فایلی صف نمی‌شود' );

// 6. Feed / admin requests never load assets.
mjy_reset_state();
$frontend = new Frontend();
$GLOBALS['mjy_is_product'] = true;
$GLOBALS['mjy_queried_id'] = 12;
$GLOBALS['mjy_is_admin']   = true;
$frontend->maybe_enqueue();
MjyInt::same( array(), $GLOBALS['mjy_enqueued'], 'درخواست مدیریت: هیچ فایلی صف نمی‌شود' );
$GLOBALS['mjy_is_admin'] = false;

/* -------------------------------------------------------------------------
 * Subscribe flow
 * ---------------------------------------------------------------------- */
MjyInt::group( 'ثبت درخواست' );

// This group exercises the two-step (opt-in) path, so it turns the option on
// explicitly. The shipped default is one-step; see the next group.
Settings::update( array( 'double_opt_in' => true ) );

mjy_reset_state();
mjy_reset_rate_limit();
mjy_product( 40, array( 'status' => 'outofstock', 'qty' => 0 ) );

$result = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'Maryam@Example.COM',
		'name'       => 'مریم احمدی',
	)
);
MjyInt::ok( $result['ok'], 'ثبت ایمیل معتبر' );
MjyInt::same( 'confirmation_sent', $result['code'], 'تأیید دومرحله‌ای فعال است' );

$row = WaitlistRepository::get( (int) $result['data']['id'] );
MjyInt::same( 'pending', $row['status'], 'وضعیت اولیه pending است' );
MjyInt::same( 'maryam@example.com', $row['email'], 'ایمیل نرمال‌شده ذخیره شد' );
MjyInt::ok( 64 === strlen( $row['token'] ), 'توکن ۶۴ کاراکتری' );
MjyInt::ok( '' !== $row['ip_hash'] && false === strpos( $row['ip_hash'], '127.0.0.1' ), 'IP خام ذخیره نشده، فقط هش' );
MjyInt::ok( ! empty( $row['confirm_expires_at'] ), 'انقضای پیوند تأیید ثبت شد' );

$queue_rows = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() );
MjyInt::same( 1, count( $queue_rows ), 'یک پیام تأیید در صف قرار گرفت' );
MjyInt::same( 'confirm', $queue_rows[0]['event_key'], 'نوع پیام صف: confirm' );

// Duplicate.
$duplicate = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'maryam@example.com',
		'name'       => 'مریم احمدی',
	)
);
MjyInt::ok( $duplicate['ok'], 'ثبت تکراری خطای سرور نمی‌دهد' );
MjyInt::same( 'already_subscribed', $duplicate['code'], 'ثبت تکراری تشخیص داده شد' );
MjyInt::same( 1, count( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . WaitlistRepository::table() ) ), 'ردیف تکراری ساخته نشد' );

// Invalid email.
mjy_reset_rate_limit();
$invalid = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'not-an-email',
		'name'       => 'مریم احمدی',
	)
);
MjyInt::ok( ! $invalid['ok'], 'ایمیل نامعتبر رد شد' );
MjyInt::same( 'invalid_email', $invalid['code'], 'کد خطای درست' );

// Invalid phone.
mjy_reset_rate_limit();
$invalid_phone = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'sms',
		'phone'      => '12345',
		'name'       => 'مریم احمدی',
	)
);
MjyInt::same( 'invalid_phone', $invalid_phone['code'], 'موبایل نامعتبر رد شد' );

// Available product cannot be subscribed to.
mjy_reset_rate_limit();
mjy_product( 41, array( 'status' => 'instock', 'qty' => 7 ) );
$available = Subscribe::handle(
	array(
		'product_id' => 41,
		'channel'    => 'email',
		'email'      => 'x@example.com',
		'name'       => 'مریم احمدی',
	)
);
MjyInt::same( 'product_available', $available['code'], 'ثبت برای محصول موجود رد شد' );

// Variation of an available parent is allowed when that variation is sold out.
mjy_reset_rate_limit();
mjy_product( 42, array( 'type' => 'variable', 'status' => 'instock', 'children' => array( 43 ) ) );
mjy_product( 43, array( 'parent' => 42, 'status' => 'outofstock', 'qty' => 0 ) );
$variation = Subscribe::handle(
	array(
		'product_id'   => 42,
		'variation_id' => 43,
		'channel'      => 'email',
		'email'        => 'size43@example.com',
		'name'       => 'مریم احمدی',
	)
);
MjyInt::ok( $variation['ok'], 'ثبت برای تنوع ناموجود مجاز است' );

// Consent enforcement.
mjy_reset_rate_limit();
Settings::update( array( 'consent_required' => true ) );
$no_consent = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'noconsent@example.com',
		'name'       => 'مریم احمدی',
	)
);
MjyInt::same( 'consent_required', $no_consent['code'], 'بدون رضایت ثبت نمی‌شود' );
$with_consent = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'noconsent@example.com',
		'name'       => 'مریم احمدی',
		'consent'    => 1,
	)
);
MjyInt::ok( $with_consent['ok'], 'با رضایت ثبت می‌شود' );
Settings::update( array( 'consent_required' => false ) );

// Rate limit: 5 attempts per hour per IP hash.
mjy_reset_rate_limit();
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
MjyInt::ok( in_array( 'rate_limited', $codes, true ), 'محدودیت نرخ فعال شد' );

/* -------------------------------------------------------------------------
 * Consent tokens
 * ---------------------------------------------------------------------- */
MjyInt::group( 'ثبت یک‌مرحله‌ای (پیش‌فرض تازه)' );

// A fresh install must let a shopper subscribe with one click: no confirmation
// message, no pending row. This is the behaviour the market asked for.
$GLOBALS['mjy_options']['mojoodyar_settings'] = Settings::defaults();
Settings::flush_cache();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );

MjyInt::ok( false === Settings::enabled( 'double_opt_in' ), 'پیش‌فرض: تأیید دومرحله‌ای خاموش است' );
MjyInt::same( 'sms_first', (string) Settings::get( 'channel_choice', '' ), 'پیش‌فرض: پیامک اولویت اول است' );

mjy_reset_rate_limit();
$one_step = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'onestep@example.com',
		'name'       => 'مریم احمدی',
	)
);

MjyInt::ok( $one_step['ok'], 'ثبت یک‌مرحله‌ای موفق است' );
MjyInt::same( 'subscribed', $one_step['code'], 'پاسخ subscribed است، نه confirmation_sent' );
MjyInt::same( false, $one_step['data']['needs_confirm'], 'نیاز به تأیید گزارش نمی‌شود' );

$one_row = WaitlistRepository::get( (int) $one_step['data']['id'] );
MjyInt::same( 'confirmed', $one_row['status'], 'ردیف مستقیماً تأییدشده است' );
MjyInt::ok( ! empty( $one_row['confirmed_at'] ), 'زمان تأیید ثبت شده است' );
MjyInt::ok( empty( $one_row['confirm_expires_at'] ), 'هیچ انقضای تأییدی ساخته نشد' );
MjyInt::same( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() ), 'هیچ پیام تأییدی در صف نیست' );

// Without the click, the subscriber is already eligible for a restock message.
mjy_reset_rate_limit();
$one_restock = Subscribe::handle(
	array(
		'product_id' => 40,
		'channel'    => 'email',
		'email'      => 'eligible@example.com',
		'name'       => 'مریم احمدی',
	)
);
MjyInt::same( 'confirmed', WaitlistRepository::get( (int) $one_restock['data']['id'] )['status'], 'مشترک دوم هم مستقیماً تأیید شد' );

MjyInt::group( 'تأیید و لغو اشتراک' );

// Back to the opt-in path for the token tests.
$GLOBALS['mjy_options']['mojoodyar_settings'] = array_merge(
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

MjyInt::same( 'confirmed', WaitlistRepository::confirm_by_token( (string) $fresh_row['token'] ), 'تأیید با توکن' );
MjyInt::same( 'already', WaitlistRepository::confirm_by_token( (string) $fresh_row['token'] ), 'تأیید دوباره، بدون تغییر' );
MjyInt::same( 'invalid', WaitlistRepository::confirm_by_token( 'not-a-token' ), 'توکن نامعتبر رد می‌شود' );
MjyInt::same( 'invalid', WaitlistRepository::confirm_by_token( str_repeat( 'z', 64 ) ), 'توکن با قالب درست ولی ناشناس' );

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
MjyInt::same( 'expired', WaitlistRepository::confirm_by_token( (string) $expired_row['token'] ), 'توکن منقضی رد می‌شود' );

MjyInt::same( 'unsubscribed', WaitlistRepository::unsubscribe_by_token( (string) $fresh_row['token'] ), 'لغو اشتراک با توکن' );
MjyInt::same( 'already', WaitlistRepository::unsubscribe_by_token( (string) $fresh_row['token'] ), 'لغو دوباره بی‌اثر است' );

/* -------------------------------------------------------------------------
 * Stock engine: hooks, dedupe, fair sending
 * ---------------------------------------------------------------------- */
MjyInt::group( 'انتخاب کانال فرم' );

$front = new Frontend();

// Fresh install: only email can deliver, and both field switches are on, but a
// phone field with no gateway would collect numbers nobody can notify.
$GLOBALS['mjy_options']['mojoodyar_settings'] = Settings::defaults();
Settings::flush_cache();
MjyInt::ok( Notifier::email_ready(), 'ایمیل آماده است' );
MjyInt::ok( ! Notifier::sms_ready(), 'پیامک آماده نیست (پیکربندی نشده)' );
MjyInt::same( 'email', $front->channel_mode(), 'ایمیل تنها: فقط فیلد ایمیل' );
MjyInt::same( 'email', $front->default_channel(), 'حالت تک‌کاناله: کانال پیش‌فرض ایمیل' );

// SMS switched on but no credentials: still not usable, and the form must not
// offer a field for it.
Settings::update( array( 'sms_enabled' => true ) );
MjyInt::ok( ! Notifier::sms_ready(), 'پیامکِ بدون کلید، آماده محسوب نمی‌شود' );
MjyInt::same( 'email', $front->channel_mode(), 'پیامک بی‌کلید، فیلد موبایل را باز نمی‌کند' );

// Properly configured gateway: both fields are on by default.
Settings::update(
	array(
		'sms_gateway'      => 'melipayamak',
		'melipayamak_user' => 'demo',
		'melipayamak_pass' => 'secret',
	)
);
MjyInt::ok( Notifier::sms_ready(), 'پیامک با کلید معتبر آماده است' );
MjyInt::same( 'both', $front->channel_mode(), 'هر دو فیلد روشن: حالت both' );
MjyInt::same( 'sms', $front->default_channel(), 'پیش‌فرض sms_first: پیامک از قبل انتخاب است' );

Settings::update( array( 'channel_choice' => 'email_first' ) );
MjyInt::same( 'email', $front->default_channel(), 'email_first: ایمیل از قبل انتخاب است' );
MjyInt::same( 'both', $front->channel_mode(), 'اولویت کانال، تعداد فیلدها را تغییر نمی‌دهد' );

// The switches the store actually flips.
Settings::update( array( 'channel_choice' => 'sms_first', 'form_email_field' => false ) );
MjyInt::same( 'sms', $front->channel_mode(), 'فیلد ایمیل خاموش: فقط موبایل' );

Settings::update( array( 'form_email_field' => true, 'form_phone_field' => false ) );
MjyInt::same( 'email', $front->channel_mode(), 'فیلد موبایل خاموش: فقط ایمیل' );

// A stored row with both switches off (the sanitiser refuses this, but an old
// option row could hold it) must not produce an empty form.
$GLOBALS['mjy_options']['mojoodyar_settings'] = array_merge(
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
MjyInt::same( 'sms', $front->channel_mode(), 'هر دو فیلد خاموش: به کانالِ در دسترس برمی‌گردد' );

// Nothing usable at all: email shell, so the page is never blank.
$GLOBALS['mjy_options']['mojoodyar_settings'] = array_merge(
	Settings::defaults(),
	array( 'email_enabled' => false, 'sms_enabled' => false, 'form_email_field' => false, 'form_phone_field' => false )
);
Settings::flush_cache();
MjyInt::same( 'email', $front->channel_mode(), 'هیچ کانالی آماده نیست: پوستهٔ ایمیل' );

MjyInt::group( 'رندر فرم فروشگاه' );

/**
 * Render the storefront form view for one mode and return the HTML.
 *
 * @param string $mode email|sms|both.
 * @return string
 */
function mjy_render_form( $mode ) {
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
	include MOJODYAR_DIR . 'admin/views/frontend-form.php';

	return (string) ob_get_clean();
}

$form_sms = mjy_render_form( 'sms' );
MjyInt::ok( false !== strpos( $form_sms, 'id="mjy-phone"' ), 'حالت پیامک: کادر موبایل هست' );
MjyInt::ok( false === strpos( $form_sms, 'id="mjy-email"' ), 'حالت پیامک: کادر ایمیل اصلاً رندر نمی‌شود' );
MjyInt::ok( false !== strpos( $form_sms, 'name="channel" value="sms"' ), 'حالت پیامک: کانال پنهان sms است' );
MjyInt::same( 1, substr_count( $form_sms, 'required' ), 'حالت پیامک: فقط یک فیلد اجباری است' );

$form_email = mjy_render_form( 'email' );
MjyInt::ok( false !== strpos( $form_email, 'id="mjy-email"' ), 'حالت ایمیل: کادر ایمیل هست' );
MjyInt::ok( false === strpos( $form_email, 'id="mjy-phone"' ), 'حالت ایمیل: کادر موبایل رندر نمی‌شود' );
MjyInt::ok( false !== strpos( $form_email, 'name="channel" value="email"' ), 'حالت ایمیل: کانال پنهان email است' );

$form_both = mjy_render_form( 'both' );
MjyInt::ok( false !== strpos( $form_both, 'id="mjy-email"' ) && false !== strpos( $form_both, 'id="mjy-phone"' ), 'حالت both: هر دو کادر هستند' );
MjyInt::ok( false !== strpos( $form_both, 'type="radio"' ), 'حالت both: انتخاب کانال نمایش داده می‌شود' );

// The one-step default applies here too: nothing about the form asks for a
// confirmation click.
MjyInt::ok( false === strpos( $form_both, 'confirm' ), 'فرم هیچ اشاره‌ای به مرحلهٔ تأیید ندارد' );

MjyInt::group( 'موتور موجودی' );
MjyInt::group( 'موتور موجودی' );

mjy_reset_state();
Settings::update( array( 'fair_sending' => true, 'notify_backorders' => false ) );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::snapshots() );

// Product 100 is out of stock and has 200 confirmed subscribers.
mjy_product( 100, array( 'status' => 'outofstock', 'qty' => 0 ) );
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

MjyInt::same( 200, WaitlistRepository::count_confirmed( 100, 0 ), '۲۰۰ مشترک تأییدشده ثبت شد' );

// First sighting of an unknown product must not announce.
mjy_product( 200, array( 'status' => 'instock', 'qty' => 9 ) );
$watcher->on_stock_status( 200, 'instock', wc_get_product( 200 ) );
MjyInt::same( 0, count( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" ) ), 'محصول نادیده‌گرفته‌شده، اعلان نمی‌سازد' );

// Restock with 5 units.
mjy_product( 100, array( 'status' => 'instock', 'qty' => 5 ) );
$watcher->on_stock_status( 100, 'instock', wc_get_product( 100 ) );

$restock_rows = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
MjyInt::same( 5, count( $restock_rows ), 'موجودی ۵ با ۲۰۰ مشترک: دقیقاً ۵ اعلان' );

// A duplicate hook (same transition seen twice) must not create more rows.
$watcher->on_stock_status( 100, 'instock', wc_get_product( 100 ) );
MjyInt::same( 5, count( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" ) ), 'کنترل تکرار: اعلان تکراری ساخته نشد' );

$snapshot = WaitlistRepository::snapshot_get( 100, 0 );
MjyInt::same( 5, (int) $snapshot['announced_qty'], 'مبنای اعلان روی ۵ ثبت شد' );

// Second restock after going out of stock again: 3 more units.
$watcher->on_stock_status( 100, 'outofstock', wc_get_product( 100 ) );
mjy_product( 100, array( 'status' => 'instock', 'qty' => 8 ) );
$watcher->on_stock_status( 100, 'instock', wc_get_product( 100 ) );
$all_restock = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
MjyInt::same( 8, count( $all_restock ), 'بازگشت دوم: فقط ۳ اعلان تازه' );

// Fair sending must pick the oldest subscribers, and the second wave must
// continue exactly where the first one stopped.
$wave_rows = $GLOBALS['wpdb']->get_results(
	'SELECT waitlist_id, event_key FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%' ORDER BY waitlist_id ASC"
);
$wave_keys = array();
foreach ( $wave_rows as $wave_row ) {
	$wave_keys[ $wave_row['event_key'] ][] = (int) $wave_row['waitlist_id'];
}
MjyInt::same( 2, count( $wave_keys ), 'دو موج اعلان ساخته شد' );

$waves      = array_values( $wave_keys );
$first_wave = $waves[0];
$second_wave = $waves[1];

MjyInt::same( 5, count( $first_wave ), 'موج اول دقیقاً ۵ نفر' );
MjyInt::same( 3, count( $second_wave ), 'موج دوم دقیقاً ۳ نفر' );
MjyInt::ok( min( $first_wave ) < min( $second_wave ), 'موج دوم بعد از موج اول شروع می‌شود' );
MjyInt::ok( max( $first_wave ) < min( $second_wave ), 'هیچ مشترکی دو بار اعلان نگرفت' );

// Fair sending off: everybody is notified.
Settings::update( array( 'fair_sending' => false ) );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$watcher->on_stock_status( 100, 'outofstock', wc_get_product( 100 ) );
mjy_product( 100, array( 'status' => 'instock', 'qty' => 2 ) );
$watcher->on_stock_status( 100, 'instock', wc_get_product( 100 ) );
$unfair = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
MjyInt::ok( count( $unfair ) > 5, 'با خاموش بودن گزینهٔ سهمیه، همه اعلان می‌گیرند: ' . count( $unfair ) );

Settings::update( array( 'fair_sending' => true ) );

/* -------------------------------------------------------------------------
 * Reconcile (layer 2)
 * ---------------------------------------------------------------------- */
MjyInt::group( 'پایش تطبیقی' );

mjy_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::snapshots() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );

mjy_product( 300, array( 'status' => 'outofstock', 'qty' => 0 ) );
mjy_product( 301, array( 'status' => 'instock', 'qty' => 6 ) );

$created = WaitlistRepository::create(
	array(
		'product_id' => 300,
		'channel'    => 'email',
		'email'      => 'reconcile@example.com',
		'name'       => 'مریم احمدی',
	)
);
WaitlistRepository::set_status( (int) $created['id'], 'confirmed' );

$GLOBALS['mjy_options']['mojoodyar_snapshot_seeded'] = 'no';
$watcher = new StockWatcher();
$first   = $watcher->reconcile( true );

MjyInt::ok( $first['first_run'], 'اجرای نخست پرچم first_run دارد' );
MjyInt::same( 0, $first['queued'], 'اجرای نخست هیچ اعلانی نمی‌فرستد' );
MjyInt::same( 'yes', $GLOBALS['mjy_options']['mojoodyar_snapshot_seeded'], 'پرچم پس از اجرای نخست ست می‌شود' );
MjyInt::same( 2, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::snapshots() ), 'تصویر برای دو محصول ذخیره شد' );

// Nothing changed: no transition.
$second = $watcher->reconcile( true );
MjyInt::same( 0, $second['transitions'], 'بدون تغییر، گذاری نیست' );

// Direct database write (simulates CSV/ERP import which fires no hook).
mjy_product( 300, array( 'status' => 'instock', 'qty' => 4 ) );
$third = $watcher->reconcile( true );
MjyInt::same( 1, $third['transitions'], 'نوشتن مستقیم دیتابیس شناسایی شد' );
MjyInt::same( 1, $third['queued'], 'یک اعلان ساخته شد' );

$fourth = $watcher->reconcile( true );
MjyInt::same( 0, $fourth['transitions'], 'اجرای بعدی گذار تکراری نمی‌سازد' );

// Throttle: a run inside the interval is skipped unless forced.
$GLOBALS['mjy_options']['mojoodyar_last_reconcile'] = time();
$throttled = $watcher->reconcile( false );
MjyInt::same( 0, $throttled['scanned'], 'اجرای داخل بازه نادیده گرفته می‌شود' );

/* -------------------------------------------------------------------------
 * Queue processing
 * ---------------------------------------------------------------------- */
MjyInt::group( 'صف ارسال' );

mjy_reset_state();
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

mjy_product( 400, array( 'status' => 'outofstock', 'qty' => 0 ) );
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
MjyInt::same( 12, $queued_before, '۱۲ پیام در صف' );

$GLOBALS['mjy_mail_ok'] = true;
$run = Queue::process();

MjyInt::same( 5, $run['sent'], 'دستهٔ ۵تایی ارسال شد' );
MjyInt::same( 5, count( $GLOBALS['mjy_mails'] ), 'پنج ایمیل واقعاً ساخته شد' );
MjyInt::ok( false !== strpos( $GLOBALS['mjy_mails'][0]['message'], 'لغو' ) || false !== strpos( $GLOBALS['mjy_mails'][0]['message'], 'unsubscribe' ), 'پیام شامل راه لغو است' );
MjyInt::same( 7, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'queued'" ), 'هفت پیام در صف ماند' );

$notified = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::table() . " WHERE status = 'notified'" );
MjyInt::same( 5, $notified, 'وضعیت مشترک‌های ارسال‌شده notified شد' );

// Rate limit blocks the next run inside the same minute.
$rate_run = Queue::process();
MjyInt::same( 'rate', $rate_run['skipped'], 'محدودیت نرخ جلوی اجرای دوم را می‌گیرد' );

// Failure path + exponential backoff.
$GLOBALS['mjy_mail_ok'] = false;
$GLOBALS['wpdb']->query( 'UPDATE ' . Queue::table() . " SET next_run_at = '2000-01-01 00:00:00' WHERE status = 'queued'" );
$GLOBALS['wpdb']->query( 'UPDATE ' . Queue::table() . " SET sent_at = '2000-01-01 00:00:00' WHERE status = 'sent'" );

$failed_run = Queue::process( 1 );
MjyInt::same( 1, $failed_run['failed'], 'ارسال ناموفق ثبت شد' );

$failed_row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . " WHERE status = 'queued' ORDER BY attempts DESC LIMIT 1" );
MjyInt::same( 1, (int) $failed_row['attempts'], 'تعداد تلاش یک شد' );
MjyInt::ok( strtotime( (string) $failed_row['next_run_at'] ) > time() + 600, 'عقب‌نشینی ۱۵ دقیقه اعمال شد' );
MjyInt::ok( '' !== (string) $failed_row['last_error'], 'پیام خطا ذخیره شد' );

// Third failure moves the row to failed.
$GLOBALS['wpdb']->query(
	'UPDATE ' . Queue::table() . " SET attempts = 2, next_run_at = '2000-01-01 00:00:00' WHERE id = " . (int) $failed_row['id']
);
Queue::process( 1 );
$final = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . ' WHERE id = ' . (int) $failed_row['id'] );
MjyInt::same( 'failed', $final['status'], 'پس از سه تلاش، وضعیت failed' );

// Locking: a fresh lock blocks a concurrent run.
Queue::acquire_lock();
$locked_run = Queue::process();
MjyInt::same( 'locked', $locked_run['skipped'], 'قفل هم‌زمانی کار می‌کند' );
Queue::release_lock();

// Sending window.
Settings::update( array( 'send_window_enabled' => true, 'send_window_start' => (int) gmdate( 'G' ) + 3, 'send_window_end' => (int) gmdate( 'G' ) + 4 ) );
$window_run = Queue::process();
MjyInt::same( 'window', $window_run['skipped'], 'بیرون از بازهٔ مجاز، ارسال متوقف می‌شود' );
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
MjyInt::ok( is_array( $skipped ), 'ردیف مشترک لغوشده رده شد' );
MjyInt::same( 'skipped', $skipped['status'] ?? '', 'وضعیت ردیف لغوشده skipped است' );
MjyInt::same( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'sent'" ), 'هیچ پیامی برای مشترک لغوشده ارسال نشد' );

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
MjyInt::ok( is_array( $pending_row ), 'ردیف تأییدنشده رده شد' );
MjyInt::same( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'sent'" ), 'به مشترک تأییدنشده پیامی نرفت' );
Settings::update( array( 'double_opt_in' => false ) );

/* -------------------------------------------------------------------------
 * SMS channel + SSRF
 * ---------------------------------------------------------------------- */
MjyInt::group( 'خطای ارسال ایمیل' );

// A bare `false` from wp_mail() is useless to an administrator. WordPress
// fires wp_mail_failed with the PHPMailer exception; the channel must surface
// it, and must not leave a listener behind.
$GLOBALS['mjy_mail_ok']    = false;
$GLOBALS['mjy_mail_error'] = 'Could not instantiate mail function.';
unset( $GLOBALS['mjy_actions']['wp_mail_failed'] );

$mail_channel = new \Mojoodyar\Channels\EmailChannel();
$mail_failure = $mail_channel->send( 'buyer@example.com', 'موضوع', 'متن', array() );
$mail_message = is_object( $mail_failure ) && method_exists( $mail_failure, 'get_error_message' )
	? $mail_failure->get_error_message()
	: (string) $mail_failure;

MjyInt::ok( true !== $mail_failure, 'شکست ارسال به خطا تبدیل می‌شود' );
MjyInt::ok( false !== strpos( $mail_message, 'Could not instantiate mail function.' ), 'دلیل واقعی wp_mail منتقل می‌شود', $mail_message );
MjyInt::ok( false !== strpos( $mail_message, 'wp_mail' ), 'پیام به wp_mail اشاره می‌کند' );
$listener_count = 0;
foreach ( ( $GLOBALS['mjy_actions']['wp_mail_failed'] ?? array() ) as $bucket ) {
	$listener_count += is_array( $bucket ) ? count( $bucket ) : 0;
}
MjyInt::same( 0, $listener_count, 'شنونده پس از فراخوانی برداشته شد (بدون نشت)' );

$GLOBALS['mjy_mail_error'] = '';
$no_reason                 = $mail_channel->send( 'buyer@example.com', 'موضوع', 'متن', array() );
$no_reason_message         = is_object( $no_reason ) ? $no_reason->get_error_message() : (string) $no_reason;
MjyInt::ok( false !== strpos( $no_reason_message, 'دلیلی گزارش نکرد' ), 'نبود دلیل هم صریح گفته می‌شود', $no_reason_message );

// A very long Persian error must survive the column limit intact: a byte-wise
// substr() would split a character and MySQL would reject the row.
$GLOBALS['mjy_mail_ok']    = false;
$GLOBALS['mjy_mail_error'] = str_repeat( 'خطای سرویس ایمیل هاست؛ ', 40 );

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

MjyInt::ok( $long_id > 0, 'ردیف صف برای آزمون خطای طولانی ساخته شد' );

if ( $long_id > 0 ) {
	Queue::deliver( $long_id );
	$stored = (string) $GLOBALS['wpdb']->get_var( 'SELECT last_error FROM ' . Queue::table() . ' WHERE id = ' . $long_id );
	MjyInt::ok( '' !== $stored, 'خطای طولانی در صف ذخیره شد' );
	MjyInt::same( 1, preg_match( '//u', $stored ), 'متن ذخیره‌شده UTF-8 معتبر است' );
	MjyInt::ok( \Mojoodyar\Support\Text::length( $stored ) <= 255, 'طول متن از سقف ستون عبور نمی‌کند' );
}

$GLOBALS['mjy_mail_ok']    = true;
$GLOBALS['mjy_mail_error'] = '';
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() . " WHERE event_key = 'restock:20261026:longerr'" );

// From resolution: an explicit setting wins, an invalid one falls back to the
// site admin address, and the header always carries a syntactically valid value.
Settings::update( array( 'from_email' => 'noreply@shop.example' ) );
MjyInt::same( 'noreply@shop.example', \Mojoodyar\Channels\EmailChannel::from_address(), 'فرستندهٔ تنظیم‌شده استفاده می‌شود' );

Settings::update( array( 'from_email' => 'not-an-email' ) );
MjyInt::same( strtolower( (string) get_option( 'admin_email', '' ) ), \Mojoodyar\Channels\EmailChannel::from_address(), 'فرستندهٔ نامعتبر به ایمیل مدیر برمی‌گردد' );

Settings::update( array( 'from_email' => 'noreply@shop.example' ) );
$GLOBALS['mjy_mails'] = array();
$mail_channel->send( 'buyer@example.com', 'موضوع', 'متن', array() );
$sent_headers = implode( "\n", (array) ( $GLOBALS['mjy_mails'][0]['headers'] ?? array() ) );
MjyInt::ok( false !== strpos( $sent_headers, 'noreply@shop.example' ), 'هدر From نشانی تنظیم‌شده را دارد', $sent_headers );
MjyInt::ok( false !== strpos( $sent_headers, 'Reply-To' ), 'هدر Reply-To تنظیم می‌شود' );

Settings::update( array( 'from_email' => '' ) );

MjyInt::group( 'کانال پیامک' );

mjy_reset_rate_limit();
mjy_sms_state( 'generic' );
$post = new \Mojoodyar\Channels\SmsGenericRest();
MjyInt::ok( $post->is_configured(), 'درگاه عمومی با نشانی https عمومی پیکربندی‌شده است' );

$payload = $post->render_payload( '{"to":"{to}","message":"{message}"}', '09121234567', 'سلام "دنیا"' );
$decoded = json_decode( $payload, true );
MjyInt::ok( is_array( $decoded ), 'قالب داده JSON معتبر می‌ماند' );
MjyInt::same( '09121234567', $decoded['to'], 'شماره درست جای‌گذاری شد' );
MjyInt::same( 'سلام "دنیا"', $decoded['message'], 'گیومه در متن، JSON را نمی‌شکند' );

Settings::update( array( 'generic_url' => 'https://10.0.0.1/send' ) );
$private = new \Mojoodyar\Channels\SmsGenericRest();
MjyInt::ok( ! $private->is_configured(), 'نشانی خصوصی پذیرفته نمی‌شود' );
$blocked_result = $private->send( '09121234567', '', 'x', array() );
MjyInt::ok( true !== $blocked_result, 'ارسال به نشانی خصوصی انجام نمی‌شود' );

Settings::update( array( 'generic_url' => 'https://api.sms-provider.ir/send', 'generic_token' => 'tok' ) );
$GLOBALS['mjy_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"status":200}' );
$ok_post = new \Mojoodyar\Channels\SmsGenericRest();
MjyInt::same( true, $ok_post->send( '09121234567', '', 'سلام', array() ), 'ارسال موفق از طریق درگاه عمومی' );
MjyInt::same( 1, count( $GLOBALS['mjy_http_calls'] ), 'یک تماس خروجی انجام شد' );
MjyInt::ok( 0 === strpos( $GLOBALS['mjy_http_calls'][0][0], 'https://' ), 'تماس خروجی https است' );

$GLOBALS['mjy_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"status":400,"message":"اعتبار کافی نیست"}' );
$gateway_error = $ok_post->send( '09121234567', '', 'سلام', array() );
MjyInt::ok( true !== $gateway_error, 'خطای درگاه تشخیص داده می‌شود' );
MjyInt::ok( false !== strpos( (string) ( is_object( $gateway_error ) ? $gateway_error->get_error_message() : $gateway_error ), 'اعتبار' ), 'پیام خطای درگاه منتقل می‌شود' );

$GLOBALS['mjy_http_response'] = null;
$transport_error = $ok_post->send( '09121234567', '', 'سلام', array() );
MjyInt::ok( true !== $transport_error, 'خطای انتقال، شکست ارسال محسوب می‌شود' );

MjyInt::group( 'الگوی ملی‌پیامک (bodyId)' );

$GLOBALS['mjy_transients'] = array();
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

$GLOBALS['mjy_http_calls']    = array();
$GLOBALS['mjy_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"Value":"1234","RetStatus":1,"StrRetStatus":"Ok"}' );
$plain_sms = new \Mojoodyar\Channels\SmsMelipayamak();
MjyInt::same( true, $plain_sms->send( '09121234567', '', 'متن ساده', $restock_context ), 'بدون bodyId ارسال متنی انجام می‌شود' );
MjyInt::same( 'https://rest.payamak-panel.com/api/SendSMS/SendSMS', $GLOBALS['mjy_http_calls'][0][0], 'نشانی درست در حالت متنی' );
MjyInt::same( 'متن ساده', $GLOBALS['mjy_http_calls'][0][1]['body']['text'], 'متن خام در حالت متنی فرستاده می‌شود' );

// Pattern mode: the gateway holds the approved text, we send only the values.
Settings::update( array( 'melipayamak_body_id' => ' 98765 ' ) );
$GLOBALS['mjy_http_calls'] = array();
MjyInt::same( true, $plain_sms->send( '09121234567', '', 'متن ساده', $restock_context ), 'ارسال الگویی موفق' );
MjyInt::same( 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', $GLOBALS['mjy_http_calls'][0][0], 'نشانی درست در حالت الگو' );

$pattern_call = $GLOBALS['mjy_http_calls'][0][1]['body'];
MjyInt::same( 98765, $pattern_call['bodyId'], 'شناسهٔ الگو به عدد تبدیل شد' );
MjyInt::same( '09121234567', $pattern_call['to'], 'شماره در حالت الگو' );
MjyInt::ok( ! isset( $pattern_call['isflash'] ), 'پارامترهای حالت متنی در حالت الگو فرستاده نمی‌شوند' );
MjyInt::same(
	'مریم;کفش دویدن;https://shop.test/p/10',
	$pattern_call['text'],
	'مقدارها به ترتیب شماره با جداکنندهٔ نقطه‌ویرگول'
);
MjyInt::ok( false === strpos( $pattern_call['text'], 'متن ساده' ), 'متن کاملِ رندرشده جای مقدارها فرستاده نمی‌شود' );

// A stored template that reorders or drops variables changes the payload.
Settings::update( array( 'sms_pattern_template' => 'کالای {1} برای {0} موجود شد. {2} — فروشگاه نمونه' ) );
$GLOBALS['mjy_http_calls'] = array();
$plain_sms->send( '09121234567', '', 'x', $restock_context );
MjyInt::same( 'مریم;کفش دویدن;https://shop.test/p/10', $GLOBALS['mjy_http_calls'][0][1]['body']['text'], 'ترتیب مقدارها از روی شمارهٔ متغیر است' );

Settings::update( array( 'sms_pattern_template' => '{0} عزیز، موجود شد — فروشگاه نمونه' ) );
$GLOBALS['mjy_http_calls'] = array();
$plain_sms->send( '09121234567', '', 'x', $restock_context );
MjyInt::same( 'مریم', $GLOBALS['mjy_http_calls'][0][1]['body']['text'], 'الگوی تک‌متغیره یک مقدار می‌فرستد' );

// {1} alone is a numbering error: the gateway numbers from zero without gaps.
Settings::update( array( 'sms_pattern_template' => '{1} موجود شد — فروشگاه نمونه' ) );
$GLOBALS['mjy_http_calls'] = array();
MjyInt::ok( true !== $plain_sms->send( '09121234567', '', 'x', $restock_context ), 'الگوی تک‌متغیره با شمارهٔ ۱ رد می‌شود' );
MjyInt::ok( 0 === count( $GLOBALS['mjy_http_calls'] ), 'برای شماره‌گذاری نادرست تماسی انجام نشد' );

// A broken template is refused locally, so no money is spent on a send that
// the gateway would reject anyway.
Settings::update( array( 'sms_pattern_template' => '{0} عزیز، کالای شما موجود شد {1}' ) );
$GLOBALS['mjy_http_calls'] = array();
$broken = $plain_sms->send( '09121234567', '', 'x', $restock_context );
MjyInt::ok( true !== $broken, 'الگوی نامعتبر پیش از تماس با درگاه رد می‌شود' );
MjyInt::ok( 0 === count( $GLOBALS['mjy_http_calls'] ), 'برای الگوی نامعتبر هیچ تماسی با درگاه انجام نشد' );
MjyInt::ok( false !== strpos( (string) ( is_object( $broken ) ? $broken->get_error_message() : $broken ), 'متغیر' ), 'پیام خطا به متغیر اشاره می‌کند' );

// Missing values must not collapse the list.
Settings::update( array( 'sms_pattern_template' => '{0} عزیز، {1} موجود شد — فروشگاه نمونه' ) );
$GLOBALS['mjy_http_calls'] = array();
$plain_sms->send( '09121234567', '', 'x', array( 'context' => array( 'product_name' => 'کفش دویدن' ) ) );
$thin = explode( ';', (string) $GLOBALS['mjy_http_calls'][0][1]['body']['text'] );
MjyInt::same( 2, count( $thin ), 'تعداد مقدارها با تعداد متغیرها برابر می‌ماند' );
MjyInt::same( 'کفش دویدن', $thin[1], 'مقدار موجود در جای خودش می‌نشیند' );

Settings::update( array( 'melipayamak_body_id' => '', 'sms_pattern_template' => '' ) );
$GLOBALS['mjy_http_response'] = null;

MjyInt::group( 'نام مشتری و پیگیری تماس' );

$GLOBALS['mjy_options']['mojoodyar_settings'] = Settings::defaults();
Settings::flush_cache();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
mjy_reset_rate_limit();
mjy_product( 70, array( 'status' => 'outofstock', 'qty' => 0 ) );

// 1. The name is stored exactly as the visitor typed it (normalised, not mangled).
$named = Subscribe::handle(
	array(
		'product_id' => 70,
		'channel'    => 'email',
		'email'      => 'named@example.com',
		'name'       => '  مریم   احمدی ',
	)
);
MjyInt::ok( $named['ok'], 'ثبت با نام موفق است' );
$named_row = WaitlistRepository::get( (int) $named['data']['id'] );
MjyInt::same( 'مریم احمدی', $named_row['full_name'], 'نام نرمال‌شده ذخیره شد' );

// 2. Required by default: no name, no row.
mjy_reset_rate_limit();
$no_name = Subscribe::handle(
	array(
		'product_id' => 70,
		'channel'    => 'email',
		'email'      => 'noname@example.com',
	)
);
MjyInt::same( 'invalid_name', $no_name['code'], 'بدون نام ثبت نمی‌شود (پیش‌فرض اجباری)' );
MjyInt::same( 422, $no_name['status'], 'کد وضعیت ۴۲۲ است' );
MjyInt::same( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::table() ), 'ردیف جدیدی ساخته نشد' );

// 3. A phone number is not a name.
mjy_reset_rate_limit();
$digits = Subscribe::handle(
	array(
		'product_id' => 70,
		'channel'    => 'email',
		'email'      => 'digits@example.com',
		'name'       => '09121234567',
	)
);
MjyInt::same( 'invalid_name', $digits['code'], 'شمارهٔ موبایل به‌جای نام پذیرفته نمی‌شود' );

// 4. Optional when the store says so.
Settings::update( array( 'form_name_required' => false ) );
mjy_reset_rate_limit();
$optional = Subscribe::handle(
	array(
		'product_id' => 70,
		'channel'    => 'email',
		'email'      => 'optional@example.com',
	)
);
MjyInt::ok( $optional['ok'], 'وقتی نام اجباری نیست، بدون نام هم ثبت می‌شود' );
MjyInt::same( '', WaitlistRepository::get( (int) $optional['data']['id'] )['full_name'], 'نام خالی می‌ماند، نه «۰» یا NULL' );
Settings::update( array( 'form_name_required' => true ) );

// 5. The name reaches the message context, which is what the SMS pattern uses.
$context = \Mojoodyar\Notifier::build_context( $named_row, false );
MjyInt::same( 'مریم احمدی', $context['customer_name'], 'متغیر {customer_name} نام ثبت‌شده است' );
MjyInt::same( 'مریم احمدی', \Mojoodyar\Logic\SmsPattern::value( 0, $context ), 'مقدار {0} الگوی پیامک همان نام است' );

// Without a stored name, the old behaviour is kept.
$context_fallback = \Mojoodyar\Notifier::build_context(
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
MjyInt::same( 'همراه گرامی', $context_fallback['customer_name'], 'نبود نام، متن پیش‌فرض را نگه می‌دارد' );

// 6. Call tracking.
$id = (int) $named['data']['id'];
MjyInt::ok( empty( $named_row['called_at'] ), 'ردیف تازه، تماس‌گرفته‌نشده است' );

\Mojoodyar\WaitlistRepository::set_called( $id, true, 7 );
$called_row = WaitlistRepository::get( $id );
MjyInt::ok( ! empty( $called_row['called_at'] ), 'زمان تماس ثبت شد' );
MjyInt::same( 7, (int) $called_row['called_by'], 'کاربر ثبت‌کنندهٔ تماس ذخیره شد' );

$counts_after = WaitlistRepository::counts();
MjyInt::same( 1, (int) $counts_after['called'], 'شمارندهٔ تماس‌گرفته‌شده‌ها یک است' );

$not_called = WaitlistRepository::query( array( 'called' => 'no' ) );
$was_called = WaitlistRepository::query( array( 'called' => 'yes' ) );
MjyInt::same( 1, (int) $not_called['total'], 'فیلتر «تماس گرفته نشده» فقط ردیف بی‌تماس را می‌دهد' );
MjyInt::same( 1, (int) $was_called['total'], 'فیلتر «تماس گرفته شده» یک ردیف می‌دهد' );

// Search finds the row by name, which is how a shop looks somebody up.
$by_name = WaitlistRepository::query( array( 'search' => 'احمدی' ) );
MjyInt::same( 1, (int) $by_name['total'], 'جست‌وجو با نام کار می‌کند' );

// Unticking is a real operation, not a no-op.
\Mojoodyar\WaitlistRepository::set_called( $id, false );
$uncalled_row = WaitlistRepository::get( $id );
MjyInt::ok( empty( $uncalled_row['called_at'] ), 'تیک تماس برداشته شد' );
MjyInt::same( 0, (int) $uncalled_row['called_by'], 'ثبت‌کننده هم پاک شد' );
MjyInt::same( 0, (int) WaitlistRepository::counts()['called'], 'شمارنده به صفر برگشت' );

// 7. The admin-ajax fallback must carry the name too.
//    Regression: this path mapped product_id/channel/email/phone but not
//    `name`, so every visitor who reached the fallback (REST blocked by a
//    security plugin, or a network error) got "invalid_name" for a name they
//    had just typed. The two paths must stay in sync.
$ajax_output = '';
$GLOBALS['mjy_options']['mojoodyar_settings'] = Settings::defaults();
Settings::flush_cache();
mjy_reset_rate_limit();
$previous_post = $_POST;
$_POST         = array(
	'nonce'      => wp_create_nonce( \Mojoodyar\Ajax::ACTION ),
	'product_id' => 70,
	'channel'    => 'email',
	'email'      => 'ajax@example.com',
	'name'       => 'زهرا کریمی',
	'consent'    => 1,
);
ob_start();
( new \Mojoodyar\Ajax() )->subscribe();
$ajax_output = (string) ob_get_clean();
$_POST       = $previous_post;

$ajax_body = json_decode( $ajax_output, true );
MjyInt::ok( is_array( $ajax_body ), 'پاسخ مسیر اَجَکس JSON معتبر است' );
MjyInt::ok( ! empty( $ajax_body['ok'] ), 'مسیر اَجَکس نام تایپ‌شده را رد نمی‌کند' );
MjyInt::same( 'زهرا کریمی', (string) $GLOBALS['wpdb']->get_var( 'SELECT full_name FROM ' . WaitlistRepository::table() . " WHERE email = 'ajax@example.com'" ), 'نام در مسیر اَجَکس هم ذخیره می‌شود' );

// The two entry points must accept exactly the same field list.
$rest_source = (string) file_get_contents( MOJODYAR_DIR . 'includes/Rest.php' );
$ajax_source = (string) file_get_contents( MOJODYAR_DIR . 'includes/Ajax.php' );
MjyInt::ok( false !== strpos( $ajax_source, "'name'" ), 'Ajax::subscribe نام را می‌خواند' );
MjyInt::ok( false !== strpos( $rest_source, 'get_json_params' ), 'REST مسیر بدنهٔ JSON را می‌خواند (بدون فیلتر args)' );

// A row that does not exist must not blow up.
MjyInt::ok( false !== \Mojoodyar\WaitlistRepository::set_called( 999999, true, 1 ), 'به‌روزرسانی ردیف ناموجود خطا نمی‌دهد' );

/**
 * Configure the SMS channel for a generic gateway test.
 *
 * @param string $gateway Gateway key.
 * @return void
 */
function mjy_sms_state( $gateway ) {
	$GLOBALS['mjy_transients'] = array();
	Settings::update(
		array(
			'sms_enabled'  => true,
			'sms_gateway'  => $gateway,
			'generic_url'  => 'https://api.sms-provider.ir/send',
			'generic_token' => 'token',
		)
	);
}

MjyInt::group( 'زنجیرهٔ اعلان پیامکی از فرم تا صف' );

// This is the exact complaint that opened 2.2.0: "the test SMS arrives, the
// restock SMS never does". A test message and a restock message travel
// different roads (one is synchronous, the other goes through the queue), so the
// whole road has to be walked in one test: form -> row -> hook -> queue -> send.
mjy_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::snapshots() );

Settings::update(
	array(
		'double_opt_in'  => false,
		'sms_enabled'    => true,
		'sms_gateway'    => 'generic',
		'generic_url'    => 'https://api.sms-provider.ir/send',
		'generic_token'  => 'tok',
		'fair_sending'   => true,
		'send_window_enabled' => false,
		'sms_template'   => '{customer_name} عزیز، {product_name} دوباره موجود شد. {site_name}',
	)
);

mjy_product( 120, array( 'status' => 'outofstock', 'qty' => 0 ) );
$watcher_sms = new StockWatcher();
$watcher_sms->on_stock_status( 120, 'outofstock', wc_get_product( 120 ) );

$sms_signup = Subscribe::handle(
	array(
		'product_id' => 120,
		'channel'    => 'sms',
		'phone'      => '09351234567',
		'name'       => 'مینا کاظمی',
	)
);
MjyInt::ok( $sms_signup['ok'], 'ثبت با شمارهٔ موبایل موفق است' );
$sms_row = WaitlistRepository::get( (int) $sms_signup['data']['id'] );
MjyInt::same( 'sms', (string) $sms_row['channel'], 'کانال ردیف پیامک است' );
MjyInt::same( 'confirmed', (string) $sms_row['status'], 'بدون تأیید دومرحله‌ای، ردیف مستقیماً تأییدشده است' );

// Restock: 3 units, one subscriber.
$GLOBALS['mjy_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"status":200}' );
mjy_product( 120, array( 'status' => 'instock', 'qty' => 3 ) );
$watcher_sms->on_stock_status( 120, 'instock', wc_get_product( 120 ) );

$queued_sms = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
MjyInt::same( 1, count( $queued_sms ), 'بازگشت موجودی یک پیام در صف گذاشت' );
MjyInt::same( 'sms', (string) $queued_sms[0]['channel'], 'پیام صف روی کانال پیامک است' );

// Now the part a shopkeeper cannot see: dispatching the queue.
$GLOBALS['mjy_http_calls'] = array();
Settings::flush_cache();
Queue::release_lock();
$run = Queue::process( 10 );
MjyInt::same( 1, (int) $run['sent'], 'پردازش صف یک پیام را فرستاد' );
MjyInt::same( 1, count( $GLOBALS['mjy_http_calls'] ), 'درگاه پیامک دقیقاً یک بار صدا زده شد' );

$sent_body = json_decode( (string) $GLOBALS['mjy_http_calls'][0][1]['body'], true );
$sent_text = is_array( $sent_body ) && isset( $sent_body['message'] ) ? (string) $sent_body['message'] : (string) $GLOBALS['mjy_http_calls'][0][1]['body'];
MjyInt::ok( false !== strpos( $sent_text, 'مینا کاظمی' ), 'نام مشتری در پیام آمده است' );
$sent_to = is_array( $sent_body ) && isset( $sent_body['to'] ) ? (string) $sent_body['to'] : '';
MjyInt::same( '09351234567', $sent_to, 'شمارهٔ درست مقصد پیام است' );
MjyInt::same( 'notified', (string) WaitlistRepository::get( (int) $sms_signup['data']['id'] )['status'], 'ردیف به «اعلان‌شده» رفت' );

// A gateway error must leave a visible reason on the queue row, not silence.
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
WaitlistRepository::set_status( (int) $sms_signup['data']['id'], 'confirmed' );
$watcher_sms->on_stock_status( 120, 'outofstock', wc_get_product( 120 ) );
mjy_product( 120, array( 'status' => 'instock', 'qty' => 6 ) );
$watcher_sms->on_stock_status( 120, 'instock', wc_get_product( 120 ) );

$GLOBALS['mjy_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"status":401,"message":"نام کاربری یا رمز اشتباه است"}' );
Queue::release_lock();
$failed_run = Queue::process( 10 );
MjyInt::ok( (int) $failed_run['failed'] >= 1, 'خطای درگاه به‌عنوان شکست ثبت می‌شود' );

$failed_rows = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . ' ORDER BY id DESC LIMIT 1' );
MjyInt::ok( ! empty( $failed_rows[0]['last_error'] ), 'دلیل خطا روی ردیف صف ذخیره می‌شود' );
MjyInt::ok( false !== strpos( (string) $failed_rows[0]['last_error'], 'رمز' ), 'پیام درگاه به فارسی و خوانا ذخیره شد' );
$GLOBALS['mjy_http_response'] = null;

MjyInt::group( 'حالت‌هایی که اعلان را بی‌صدا می‌خوردند' );

// Two real-world paths where a restock produced no message at all. Both were
// reported as "the test SMS arrives, the restock SMS never does".

// --- A. Sell-through: announce 5, sell them, restock 2 ---------------------
mjy_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::snapshots() );
Settings::update( array( 'fair_sending' => true, 'notify_backorders' => false ) );

mjy_product( 130, array( 'status' => 'outofstock', 'qty' => 0 ) );
$watcher_sell = new StockWatcher();
$watcher_sell->on_stock_status( 130, 'outofstock', wc_get_product( 130 ) );

for ( $i = 1; $i <= 7; $i++ ) {
	$created = WaitlistRepository::create(
		array(
			'product_id' => 130,
			'channel'    => 'email',
			'email'      => 'sell' . $i . '@example.com',
			'name'       => 'خریدار ' . $i,
		)
	);
	WaitlistRepository::set_status( (int) $created['id'], 'confirmed' );
}

// First restock: 5 units, 5 people told, baseline becomes 5.
mjy_product( 130, array( 'status' => 'instock', 'qty' => 5 ) );
$watcher_sell->on_stock_status( 130, 'instock', wc_get_product( 130 ) );
$wave_one = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
MjyInt::same( 5, count( $wave_one ), 'موج اول: ۵ اعلان' );

// The five units sell out, so the product is unavailable again.
mjy_product( 130, array( 'status' => 'outofstock', 'qty' => 0 ) );
$watcher_sell->on_stock_status( 130, 'outofstock', wc_get_product( 130 ) );

// Two new units arrive — fewer than the previous restock.
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
mjy_product( 130, array( 'status' => 'instock', 'qty' => 2 ) );
$watcher_sell->on_stock_status( 130, 'instock', wc_get_product( 130 ) );
$wave_two = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );

MjyInt::same( 2, count( $wave_two ), 'بعد از فروش کامل، ۲ واحد تازه هم اعلان می‌گیرد (نه صفر)' );

// --- B. Subscriber who signed up before the plugin ever saw the product ----
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::snapshots() );
mjy_reset_rate_limit();

mjy_product( 140, array( 'status' => 'outofstock', 'qty' => 0 ) );

// No snapshot row exists: nothing has observed this product yet.
MjyInt::ok( null === WaitlistRepository::snapshot_get( 140, 0 ), 'قبل از ثبت، هیچ تصویری از محصول نیست' );

$late = Subscribe::handle(
	array(
		'product_id' => 140,
		'channel'    => 'sms',
		'phone'      => '09121110000',
		'name'       => 'مشتری دیرآمده',
	)
);
MjyInt::ok( $late['ok'], 'ثبت روی محصولی که افزونه هنوز ندیده موفق است' );

// The form only exists for unavailable products, so subscribing is proof that
// the product was unavailable: the baseline must be recorded right there.
MjyInt::ok( null !== WaitlistRepository::snapshot_get( 140, 0 ), 'ثبت درخواست، تصویر «ناموجود» را می‌سازد' );

mjy_product( 140, array( 'status' => 'instock', 'qty' => 1 ) );
$watcher_sell->on_stock_status( 140, 'instock', wc_get_product( 140 ) );
$late_queue = $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
MjyInt::same( 1, count( $late_queue ), 'بازگشت موجودی برای مشتری دیرآمده هم پیام می‌سازد' );

// The old number must not poison a later, smaller restock here either.
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
mjy_product( 140, array( 'status' => 'outofstock', 'qty' => 0 ) );
$watcher_sell->on_stock_status( 140, 'outofstock', wc_get_product( 140 ) );
mjy_product( 140, array( 'status' => 'instock', 'qty' => 1 ) );
$watcher_sell->on_stock_status( 140, 'instock', wc_get_product( 140 ) );
MjyInt::same( 1, count( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" ) ), 'چرخهٔ دوم با ۱ واحد هم اعلان می‌سازد' );

Settings::update( array( 'fair_sending' => true ) );

MjyInt::group( 'آمار و گزارش' );

mjy_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ) );

$today     = gmdate( 'Y-m-d H:i:s' );
$three_ago = gmdate( 'Y-m-d H:i:s', time() - ( 3 * 86400 ) );
$nine_ago  = gmdate( 'Y-m-d H:i:s', time() - ( 9 * 86400 ) );

$stats_rows = array(
	// product, channel, email, phone, status, created_at, notified_at
	array( 701, 'sms', '', '09120000001', 'notified', $today, $today ),
	array( 701, 'sms', '', '09120000002', 'confirmed', $today, null ),
	array( 701, 'email', 'a@example.com', '', 'confirmed', $three_ago, null ),
	array( 702, 'email', 'b@example.com', '', 'pending', $three_ago, null ),
	array( 702, 'sms', '', '09120000003', 'unsubscribed', $three_ago, null ),
	// Outside every offered period: must never show up in the 7/30/90 reports.
	array( 703, 'email', 'old@example.com', '', 'notified', $nine_ago, $nine_ago ),
);

$stats_seq = 0;
foreach ( $stats_rows as $stats_row ) {
	$stats_seq++;
	$GLOBALS['wpdb']->insert(
		WaitlistRepository::table(),
		array(
			'token'             => str_pad( 'stats' . $stats_seq, 64, 'x' ),
			'product_id'        => $stats_row[0],
			'variation_id'      => 0,
			'event_type'        => 'restock',
			'channel'           => $stats_row[1],
			'email'             => $stats_row[2],
			'phone'             => $stats_row[3],
			'user_id'           => 0,
			'status'            => $stats_row[4],
			'stock_at_signup'   => 0,
			'ip_hash'           => str_repeat( 'd', 64 ),
			'created_at'        => $stats_row[5],
			'notified_at'       => $stats_row[6],
		)
	);
}

$stats_week = Stats::report( 7 );

MjyInt::same( 5, $stats_week['summary']['total'], 'آمار ۷ روز: ۵ ثبت داخل بازه، ردیف ۹ روز پیش بیرون می‌ماند' );
MjyInt::same( 2, $stats_week['summary']['confirmed'], 'آمار ۷ روز: دو ردیف تأییدشده' );
MjyInt::same( 1, $stats_week['summary']['notified'], 'آمار ۷ روز: یک ردیف اعلان‌شده' );
MjyInt::same( 1, $stats_week['summary']['pending'], 'آمار ۷ روز: یک ردیف در انتظار تأیید' );
MjyInt::same( 1, $stats_week['summary']['unsubscribed'], 'آمار ۷ روز: یک لغو اشتراک' );
MjyInt::same( 60, (int) $stats_week['summary']['confirm_rate'], 'درصد تأیید: ۳ از ۵ = ۶۰٪' );

$stats_month = Stats::report( 30 );
MjyInt::same( 6, $stats_month['summary']['total'], 'آمار ۳۰ روز: ردیف ۹ روز پیش هم حساب می‌شود' );

MjyInt::same( 7, count( $stats_week['signups']['keys'] ), 'سری روزانه: هفت روز، حتی روزهای خالی' );
MjyInt::same( 5, StatsMath::total( $stats_week['signups']['values'] ), 'سری روزانه: جمع میله‌ها با کل ثبت برابر است' );
MjyInt::same( 2, (int) end( $stats_week['signups']['values'] ), 'سری روزانه: امروز ۲ ثبت' );
MjyInt::same( 3, (int) $stats_week['signups']['values'][3], 'سری روزانه: سه روز پیش ۳ ثبت' );
MjyInt::same( 1, (int) array_sum( $stats_week['notified']['values'] ), 'سری اعلان: اعلان‌ها با تاریخ notified_at شمرده می‌شوند' );

MjyInt::same( 3, (int) $stats_week['channels']['sms'], 'تفکیک کانال: سه پیامکی' );
MjyInt::same( 2, (int) $stats_week['channels']['email'], 'تفکیک کانال: دو ایمیلی' );

$stats_statuses = Stats::by_status();
MjyInt::same( 6, (int) $stats_statuses['confirmed'] + (int) $stats_statuses['notified'] + (int) $stats_statuses['pending'] + (int) $stats_statuses['unsubscribed'], 'تفکیک وضعیت همهٔ ردیف‌ها را می‌شمارد (نه فقط بازه)' );

$stats_products = Stats::top_products( 7, 5 );
MjyInt::same( 701, (int) $stats_products[0]['product_id'], 'پرتقاضاترین محصول اول فهرست است' );
MjyInt::same( 3, (int) $stats_products[0]['total'], 'پرتقاضاترین محصول ۳ درخواست دارد' );
MjyInt::same( 2, (int) $stats_products[0]['awaiting'], 'از ۳ درخواست، ۲ تا هنوز منتظرند' );
MjyInt::same( 2, count( $stats_products ), 'فقط محصول‌های داخل بازه در فهرست‌اند' );

// Rejections: the panel that explains an empty waitlist.
mjy_reset_rate_limit();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ) );
Logger::log( 'security', 'subscribe_rejected', 'duplicate', str_repeat( 'e', 64 ) );
Logger::log( 'security', 'subscribe_rejected', 'duplicate', str_repeat( 'e', 64 ) );
Logger::log( 'security', 'subscribe_rejected', 'invalid_name', str_repeat( 'e', 64 ) );
Logger::log( 'warning', 'subscribe_failed', 'reason=email', str_repeat( 'e', 64 ) );

$stats_rej = Stats::rejections( 7 );
MjyInt::same( 3, count( $stats_rej ), 'سه نوع دلیل ردشده گروه‌بندی می‌شود' );
MjyInt::same( 2, (int) $stats_rej[0]['count'], 'پرتکرارترین دلیل اول می‌آید' );
MjyInt::ok( false !== strpos( (string) $stats_rej[0]['label'], 'قبلاً' ), 'دلیل duplicate به فارسی توضیح داده می‌شود' );
MjyInt::ok( false !== strpos( Stats::reason_label( 'subscribe_failed', 'reason=email' ), 'ایمیل' ), 'دلیل reason=email خوانده می‌شود' );
MjyInt::same( 'کد ناشناخته', Stats::reason_label( 'subscribe_rejected', 'کد ناشناخته' ), 'کد ناشناخته دور ریخته نمی‌شود' );

// The report is cached per request, so the page and the CSV share one read.
MjyInt::ok( Stats::report( 7 ) === $stats_week, 'گزارش یک بازه در همان درخواست دوباره محاسبه نمی‌شود' );
MjyInt::same( 30, Stats::normalize_range( 999 ), 'بازهٔ نامعتبر به پیش‌فرض برمی‌گردد' );
MjyInt::same( 90, Stats::normalize_range( '90' ), 'بازهٔ معتبر پذیرفته می‌شود' );

// The queue counters inside the report must be the live ones, not a copy.
Queue::enqueue( 1, 'email', 'restock:20261027:stats001', array( 'subject' => 's', 'body' => 'b' ) );
Stats::reset_cache();
MjyInt::same( 1, (int) Stats::report( 7 )['queue']['queued'], 'شمارندهٔ صف در گزارش تازه است' );

MjyInt::group( 'ثبت دوبارهٔ همان مشتری' );

mjy_reset_state();
mjy_reset_rate_limit();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
Settings::update( array( 'double_opt_in' => false, 'consent_required' => false, 'form_name_field' => true, 'form_name_required' => false ) );
Settings::flush_cache();
mjy_product( 150, array( 'status' => 'outofstock', 'qty' => 0 ) );

$first = Subscribe::handle(
	array(
		'product_id' => 150,
		'channel'    => 'sms',
		'phone'      => '09351112222',
		'name'       => 'سمیرا رضایی',
	)
);
MjyInt::ok( $first['ok'], 'ثبت اول موفق است' );

$again = Subscribe::handle(
	array(
		'product_id' => 150,
		'channel'    => 'sms',
		'phone'      => '09351112222',
		'name'       => 'سمیرا رضایی',
	)
);
MjyInt::ok( $again['ok'], 'ثبت دوبارهٔ همان شماره خطا نیست' );
MjyInt::same( 'already_subscribed', $again['code'], 'پاسخ «از قبل ثبت شده‌اید» است، نه خطای ۵۰۰' );
MjyInt::same( 200, (int) $again['status'], 'کد وضعیت ۲۰۰ است' );
MjyInt::same( (int) $first['data']['id'], (int) $again['data']['id'], 'همان ردیف قبلی برگردانده می‌شود' );
MjyInt::same( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::table() ), 'ردیف تکراری ساخته نمی‌شود' );

// A customer who was already notified should be put back in line, otherwise a
// second restock can never reach them.
$existing_id = (int) $first['data']['id'];
WaitlistRepository::set_status( $existing_id, 'notified' );

$revive = Subscribe::handle(
	array(
		'product_id' => 150,
		'channel'    => 'sms',
		'phone'      => '09351112222',
		'name'       => 'سمیرا ر.'
	)
);
MjyInt::ok( $revive['ok'], 'ثبت دوبارهٔ مشتری اعلان‌شده موفق است' );
$revived_row = WaitlistRepository::get( $existing_id );
MjyInt::same( 'confirmed', (string) $revived_row['status'], 'ردیف اعلان‌شده دوباره فعال می‌شود' );
MjyInt::ok( empty( $revived_row['notified_at'] ), 'تاریخ اعلان پاک می‌شود تا اعلان بعدی حساب شود' );
MjyInt::same( 'سمیرا ر', (string) $revived_row['full_name'], 'نام تازه جای نام قبلی را می‌گیرد' );

// An active subscriber must keep their state: a second submit is not consent.
WaitlistRepository::set_status( $existing_id, 'confirmed' );
$third = Subscribe::handle(
	array(
		'product_id' => 150,
		'channel'    => 'sms',
		'phone'      => '09351112222',
		'name'       => ''
	)
);
MjyInt::ok( $third['ok'], 'ثبت سوم هم بی‌خطاست' );
$third_row = WaitlistRepository::get( $existing_id );
MjyInt::same( 'confirmed', (string) $third_row['status'], 'ردیف فعال دست‌نخورده می‌ماند' );

Settings::update( array( 'double_opt_in' => false ) );

MjyInt::group( 'گزارش رویدادها' );

mjy_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ) );

$old_stamp = gmdate( 'Y-m-d H:i:s', time() - ( 10 * 86400 ) );
$old_id    = $GLOBALS['wpdb']->insert(
	\Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ),
	array(
		'level'      => 'info',
		'event'      => 'queue_run',
		'context'    => 'sent=3|failed=0',
		'ip_hash'    => '',
		'created_at' => $old_stamp,
	)
);

Logger::log( 'security', 'subscribe_rejected', 'bad_nonce', str_repeat( 'f', 64 ) );
Logger::log( 'security', 'subscribe_rejected', 'duplicate', str_repeat( 'f', 64 ) );
Logger::log( 'warning', 'delivery_failed', 'sms|http', '' );
Logger::log( 'info', 'subscribe_ok', 'product=501|channel=sms', str_repeat( 'f', 64 ) );
Logger::log( 'error', 'subscribe_failed', 'reason=email', '' );

$all = Logs::query( array() );
MjyInt::same( 6, (int) $all['total'], 'همهٔ ردیف‌ها شمرده می‌شوند' );
MjyInt::same( 6, count( $all['rows'] ), 'همهٔ ردیف‌ها برگردانده می‌شوند' );
MjyInt::same( 'subscribe_failed', (string) $all['rows'][0]['event'], 'جدیدترین ردیف اول است' );

$security = Logs::query( array( 'level' => 'security' ) );
MjyInt::same( 2, (int) $security['total'], 'فیلتر سطح امنیتی دو ردیف می‌دهد' );
MjyInt::same( 'security', (string) $security['rows'][0]['level'], 'ردیف برگشتی سطح درست دارد' );

MjyInt::same( 1, (int) Logs::query( array( 'event' => 'delivery_failed' ) )['total'], 'فیلتر رویداد یک ردیف می‌دهد' );
MjyInt::same( 2, (int) Logs::query( array( 'event' => 'subscribe_rejected' ) )['total'], 'فیلتر رویداد ردشده دو ردیف می‌دهد' );

// Search must be a literal substring, not a LIKE pattern.
MjyInt::same( 1, (int) Logs::query( array( 'search' => 'bad_nonce' ) )['total'], 'جست‌وجو در متن لاگ کار می‌کند' );
MjyInt::same( 1, (int) Logs::query( array( 'search' => '501' ) )['total'], 'جست‌وجوی عددی (شناسهٔ محصول) کار می‌کند' );
MjyInt::same( 0, (int) Logs::query( array( 'search' => '%' ) )['total'], 'درصد در جست‌وجو به‌عنوان الگوی LIKE تفسیر نمی‌شود' );
MjyInt::same( 0, (int) Logs::query( array( 'search' => 'هیچ‌چیز' ) )['total'], 'جست‌وجوی بی‌نتیجه صفر می‌دهد' );

MjyInt::same( 2, (int) Logs::query( array( 'level' => 'security', 'days' => 7 ) )['total'], 'بازهٔ ۷ روز ردیف‌های تازه را نگه می‌دارد' );
MjyInt::same( 0, (int) Logs::query( array( 'event' => 'queue_run', 'days' => 7 ) )['total'], 'ردیف ۱۰ روز پیش در بازهٔ ۷ روز نمی‌آید' );
MjyInt::same( 1, (int) Logs::query( array( 'event' => 'queue_run' ) )['total'], 'همان ردیف بدون فیلتر بازه دیده می‌شود' );

// Paging: 6 rows, 2 per page = 3 pages.
$paged = Logs::query( array( 'per_page' => 2, 'page' => 2 ) );
MjyInt::same( 3, (int) $paged['pages'], 'صفحه‌بندی سه صفحه می‌سازد' );
MjyInt::same( 2, count( $paged['rows'] ), 'هر صفحه دو ردیف دارد' );
MjyInt::same( 6, (int) $paged['total'], 'کل در حالت صفحه‌بندی درست است' );

$summary = Logs::summary();
MjyInt::same( 6, (int) $summary['total'], 'کارت کل درست است' );
MjyInt::same( 4, (int) $summary['problems'], 'هشدار (۱) + خطا (۱) + امنیتی (۲) = ۴' );
MjyInt::same( 2, (int) $summary['security'], 'دوی امنیتی شمرده می‌شود' );
MjyInt::same( 1, (int) $summary['errors'], 'یک خطا شمرده می‌شود' );
MjyInt::same( 5, (int) $summary['today'], 'پنج ردیف در ۲۴ ساعت گذشته' );

$events_present = Logs::events_present();
MjyInt::same( 5, count( $events_present ), 'پنج رویداد متفاوت در فهرست فیلتر است' );
MjyInt::ok( false !== strpos( (string) $events_present[0]['label'], 'رد' ) || (int) $events_present[0]['count'] >= 1, 'پرتکرارترین رویداد اول است' );
MjyInt::same( array(), Logs::untranslated_events(), 'همهٔ رویدادهای موجود ترجمهٔ فارسی دارند' );

$newest = Logs::recent_problems( 3 );
MjyInt::same( 3, count( $newest ), 'پنل داشبورد سه ردیف مشکل می‌گیرد' );
MjyInt::ok( ! in_array( 'info', array_column( $newest, 'level' ), true ), 'پنل داشبورد ردیف اطلاع را نشان نمی‌دهد' );

// The dashboard list and the log screen must describe a row identically.
$first = $newest[0];
MjyInt::ok(
	false !== strpos( LogExplain::detail( (string) $first['event'], (string) $first['context'] ), 'کد امنیتی' )
	|| false !== strpos( LogExplain::detail( (string) $first['event'], (string) $first['context'] ), 'دلیل' ),
	'توضیح فارسی ردیف از همان دیکشنری می‌آید'
);

// Cleanup is explicit and total.
MjyInt::same( 6, Logs::clear(), 'پاک کردن لاگ‌ها همهٔ ردیف‌ها را حذف می‌کند' );
MjyInt::same( 0, (int) Logs::summary()['total'], 'بعد از پاک کردن شمارنده صفر است' );

mjy_reset_rate_limit();

MjyInt::group( 'پیام ناموفق و تلاش دوباره' );

mjy_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ) );
mjy_reset_rate_limit();

Settings::update( array( 'sms_enabled' => true, 'sms_gateway' => 'generic', 'generic_url' => 'https://sms.example/send', 'generic_token' => 'tok' ) );
Settings::flush_cache();

$row = WaitlistRepository::create(
	array(
		'product_id' => 160,
		'channel'    => 'sms',
		'phone'      => '09121234567',
		'full_name'  => 'نگار موسوی',
		'status'     => 'confirmed',
	)
);
MjyInt::ok( $row['ok'], 'ردیف مشترک ساخته شد' );

Queue::enqueue( (int) $row['id'], 'sms', 'restock:20261028:retry001', array( 'product_id' => 160 ) );
$queue_id = (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(id) FROM ' . Queue::table() );
MjyInt::ok( $queue_id > 0, 'پیام در صف گذاشته شد' );

// A gateway that answers with a Persian error, quoting the recipient back.
$GLOBALS['mjy_http_response'] = array(
	'response' => array( 'code' => 400 ),
	'body'     => 'شماره 09121234567 مسدود است',
);
$GLOBALS['mjy_http_calls'] = array();

Queue::release_lock();
for ( $i = 0; $i < 3; $i++ ) {
	// Reset the schedule so each attempt runs now instead of after a backoff.
	$GLOBALS['wpdb']->query(
		'UPDATE ' . Queue::table() . " SET next_run_at = '" . gmdate( 'Y-m-d H:i:s' ) . "' WHERE id = " . (int) $queue_id
	);
	Queue::process( 5 );
}

$failed_row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . ' WHERE id = ' . (int) $queue_id, ARRAY_A );
MjyInt::same( 'failed', (string) $failed_row['status'], 'پس از سه تلاش، ردیف «ناموفق» می‌شود' );
MjyInt::ok( false !== strpos( (string) $failed_row['last_error'], 'مسدود' ), 'متن خطای درگاه در last_error ذخیره می‌شود' );

$fail_logs = $GLOBALS['wpdb']->get_results(
	'SELECT * FROM ' . \Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ) . " WHERE event = 'delivery_failed' ORDER BY id ASC",
	ARRAY_A
);
MjyInt::ok( count( $fail_logs ) >= 1, 'خطای ارسال در لاگ ثبت می‌شود' );

$fail_context = (string) $fail_logs[0]['context'];
MjyInt::ok( false !== strpos( $fail_context, 'err=' ), 'لاگ شامل متن خطای درگاه است' );
MjyInt::ok( false !== strpos( $fail_context, 'attempt=' ), 'لاگ شمارهٔ تلاش را دارد' );
MjyInt::ok( false === strpos( $fail_context, '09121234567' ), 'شمارهٔ مشتری در لاگ نیامده است' );
MjyInt::ok( false !== strpos( $fail_context, 'مسدود' ), 'دلیل خطا در لاگ هست' );

// What the admin actually reads on screen for that row.
$explained = \Mojoodyar\Logic\LogExplain::detail( 'delivery_failed', $fail_context );
MjyInt::ok( false !== strpos( $explained, 'خطای درگاه:' ), 'متن فارسی، خطای درگاه را نشان می‌دهد' );
MjyInt::ok( false === strpos( $explained, 'دلیل: sms' ), 'برچسب اشتباه «دلیل» روی کانال نیست' );

// The tool the panel now offers.
MjyInt::same( 1, Queue::retry_failed(), 'ابزار تلاش دوباره یک ردیف را برمی‌گرداند' );

$retried = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . ' WHERE id = ' . (int) $queue_id, ARRAY_A );
MjyInt::same( 'queued', (string) $retried['status'], 'ردیف دوباره در صف است' );
MjyInt::same( 0, (int) $retried['attempts'], 'شمارندهٔ تلاش صفر شده است' );
MjyInt::ok( empty( $retried['last_error'] ), 'خطای قبلی پاک شده است' );

MjyInt::same( 0, Queue::retry_failed(), 'وقتی پیام ناموفقی نیست، ابزار صفر برمی‌گرداند' );

// And it goes out once the gateway is healthy again.
$GLOBALS['mjy_http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"ok":true}',
);
Queue::release_lock();
$sent_again = Queue::process( 5 );
MjyInt::same( 1, (int) $sent_again['sent'], 'پیام پس از اصلاح درگاه ارسال می‌شود' );

Settings::update( array( 'sms_enabled' => false, 'sms_gateway' => 'melipayamak' ) );
Settings::flush_cache();

MjyInt::group( 'صف گیرکرده' );

mjy_reset_state();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ) );
mjy_reset_rate_limit();

Settings::update( array( 'sms_enabled' => true, 'sms_gateway' => 'generic', 'generic_url' => 'https://sms.example/send', 'generic_token' => 'tok', 'send_window_enabled' => false ) );
Settings::flush_cache();

$stuck_row = WaitlistRepository::create(
	array(
		'product_id' => 170,
		'channel'    => 'sms',
		'phone'      => '09125550000',
		'full_name'  => 'آرش نادری',
		'status'     => 'confirmed',
	)
);
Queue::enqueue( (int) $stuck_row['id'], 'sms', 'restock:20261028:stuck001', array( 'product_id' => 170 ) );
$stuck_id = (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(id) FROM ' . Queue::table() );

// Simulate a worker that claimed the row and then died: PHP fatal inside the
// gateway, max_execution_time, a 502 from the host, or the admin closing the
// tab. The row is left in "processing" with nobody working on it.
$GLOBALS['wpdb']->query(
	'UPDATE ' . Queue::table() . " SET status = 'processing', locked_at = '"
	. gmdate( 'Y-m-d H:i:s', time() - 3600 ) . "' WHERE id = " . $stuck_id
);

$stats_before = Queue::stats();
MjyInt::same( 1, (int) $stats_before['processing'], 'ردیف گیرکرده در شمارندهٔ «در حال ارسال» دیده می‌شود' );
MjyInt::same( 0, (int) $stats_before['queued'], 'و در شمارندهٔ صف نیست' );

$GLOBALS['mjy_http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"ok":true}',
);
$GLOBALS['mjy_http_calls'] = array();

// One run must both take the abandoned claim back and send it. Before 2.6.0
// this returned skipped='empty' and the message sat there for ever.
Queue::release_lock();
$healed = Queue::process( 10 );
MjyInt::same( 1, (int) $healed['reclaimed'], 'اجرای صف، ردیف رهاشده را پس می‌گیرد' );
MjyInt::same( 1, (int) $healed['sent'], 'و همان پیام ارسال می‌شود' );

$healed_row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Queue::table() . ' WHERE id = ' . $stuck_id, ARRAY_A );
MjyInt::same( 'sent', (string) $healed_row['status'], 'ردیف در پایان «ارسال‌شده» است' );
MjyInt::ok( empty( $healed_row['locked_at'] ), 'قفل رهاشده پاک می‌شود' );

$reclaim_log = $GLOBALS['wpdb']->get_var(
	'SELECT COUNT(*) FROM ' . \Mojoodyar\Database::table( \Mojoodyar\Database::LOGS ) . " WHERE event = 'queue_reclaimed'"
);
MjyInt::ok( (int) $reclaim_log >= 1, 'بازیابی ردیف رهاشده در لاگ ثبت می‌شود' );

// A fresh claim (a worker genuinely running right now) must NOT be stolen.
$GLOBALS['wpdb']->query(
	'UPDATE ' . Queue::table() . " SET status = 'processing', locked_at = '" . gmdate( 'Y-m-d H:i:s' ) . "' WHERE id = " . $stuck_id
);
Queue::release_lock();
MjyInt::same( 0, Queue::reclaim_stale(), 'قفل تازه دست‌نخورده می‌ماند' );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() . ' WHERE id = ' . $stuck_id );

// A row that is not due yet must say "scheduled", not "empty".
$blocked = WaitlistRepository::create(
	array(
		'product_id' => 171,
		'channel'    => 'sms',
		'phone'      => '09125550001',
		'status'     => 'confirmed',
	)
);
Queue::enqueue( (int) $blocked['id'], 'sms', 'restock:20261028:future001', array( 'product_id' => 171 ) );
$future_id = (int) $GLOBALS['wpdb']->get_var( 'SELECT MAX(id) FROM ' . Queue::table() );
$GLOBALS['wpdb']->query(
	'UPDATE ' . Queue::table() . " SET next_run_at = '" . gmdate( 'Y-m-d H:i:s', time() + 3600 ) . "' WHERE id = " . $future_id
);

Queue::release_lock();
$future_run = Queue::process( 10 );
MjyInt::same( 'scheduled', (string) $future_run['skipped'], 'ردیف با زمان اجرای آینده، «خالی» گزارش نمی‌شود' );
MjyInt::same( 0, (int) $future_run['sent'], 'و هنوز ارسال نمی‌شود' );

$diagnosis = Queue::diagnose();
MjyInt::same( 1, (int) $diagnosis['scheduled'], 'تشخیص، تعداد ردیف‌های زمان‌بندی‌شده را می‌دهد' );
MjyInt::same( 0, (int) $diagnosis['ready'], 'و تعداد آمادهٔ ارسال را صفر می‌دهد' );
MjyInt::ok( false !== strpos( (string) $diagnosis['hint'], 'زمان' ), 'تشخیص دلیل فارسی دارد' );

// The admin needs the wait in words, not a UTC string to compare by hand.
MjyInt::same( (string) $GLOBALS['wpdb']->get_var( 'SELECT next_run_at FROM ' . Queue::table() . ' WHERE id = ' . $future_id ), (string) $diagnosis['nearest'], 'زمان نزدیک‌ترین پیام در تشخیص هست' );
MjyInt::ok( (int) $diagnosis['nearest_wait'] > 3000, 'مدت انتظار بر حسب ثانیه گزارش می‌شود' );
MjyInt::same( 0, (int) $diagnosis['nearest_tries'], 'ردیفِ زمان‌بندی‌نشده تلاش ناموفق ندارد' );
MjyInt::ok( false !== strpos( (string) $diagnosis['hint'], 'ساعت دیگر' ), 'انتظار به فارسی و نه به عدد خام خوانده می‌شود' );

// A row waiting because its last attempt failed must say so, with the gateway's
// own sentence: otherwise "scheduled" looks identical to "not created yet".
$GLOBALS['wpdb']->query(
	'UPDATE ' . Queue::table() . " SET attempts = 1, last_error = 'شمارهٔ فرستنده تأیید نشده است' WHERE id = " . $future_id
);
$backoff = Queue::diagnose();
MjyInt::same( 1, (int) $backoff['nearest_tries'], 'تلاش ناموفق ردیف گزارش می‌شود' );
MjyInt::ok( false !== strpos( (string) $backoff['hint'], 'عقب‌نشینی' ), 'دلیل انتظار (عقب‌نشینی) گفته می‌شود' );
MjyInt::ok( false !== strpos( (string) $backoff['hint'], 'شمارهٔ فرستنده' ), 'متن خطای درگاه در تشخیص می‌آید' );

// The manual override: when the gateway is fixed, the admin should not have to
// wait out a backoff he already knows the outcome of.
MjyInt::same( 1, Queue::pull_forward(), 'ابزار ارسال فوری، ردیف زمان‌بندی‌شده را به «همین حالا» می‌آورد' );
$GLOBALS['mjy_http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"ok":true}',
);
$GLOBALS['mjy_http_calls'] = array();
Queue::release_lock();
$pulled_run = Queue::process( 10 );
MjyInt::same( 1, (int) $pulled_run['sent'], 'و همان اجرا آن را ارسال می‌کند' );
MjyInt::same( 0, Queue::pull_forward(), 'اجرای دوبارهٔ ابزار چیزی برای منتقل‌کردن ندارد' );

$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() . ' WHERE id = ' . $future_id );
Queue::release_lock();

MjyInt::group( 'پیگیری تلفنی مرحله‌ای' );

$GLOBALS['mjy_options']['mojoodyar_settings'] = Settings::defaults();
Settings::flush_cache();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . CallLog::table() );
$GLOBALS['wpdb']->query( 'DELETE FROM ' . WaitlistRepository::table() );
mjy_reset_rate_limit();
mjy_product( 95, array( 'status' => 'outofstock', 'qty' => 0 ) );

$follow = Subscribe::handle(
	array(
		'product_id' => 95,
		'channel'    => 'email',
		'email'      => 'follow@example.com',
		'phone'      => '09121234567',
		'name'       => 'سارا رضایی',
	)
);
$follow_id = (int) $follow['data']['id'];
$follow_row = WaitlistRepository::get( $follow_id );

MjyInt::ok( ! empty( $follow_row['call_state'] ) && 'open' === $follow_row['call_state'], 'ردیف تازه پیگیری باز دارد' );
MjyInt::same( 0, CallLog::attempt_count( $follow_id ), 'هیچ تماسی ثبت نشده است' );
MjyInt::same( array(), CallLog::history( $follow_id ), 'تاریخچهٔ خالی آرایه است، نه null' );

// 1. First attempt -> stage 1.
$first = CallLog::add_attempt( $follow_id, 'no_answer', 'زنگ خورد، جواب نداد', 5 );
MjyInt::ok( $first['ok'], 'ثبت تماس اول موفق است' );
MjyInt::same( 1, $first['stage'], 'تماس اول شمارهٔ مرحلهٔ ۱ می‌گیرد' );
MjyInt::same( 'تماس اول', CallLog::stage_label( 1 ), 'برچسب مرحلهٔ ۱ درست است' );

$row_after_first = WaitlistRepository::get( $follow_id );
MjyInt::ok( ! empty( $row_after_first['called_at'] ), 'ستون قدیمی called_at هم پر می‌شود تا فیلترها بشکنند' );
MjyInt::same( 5, (int) $row_after_first['called_by'], 'کاربر ثبت‌کننده ذخیره شد' );

// 2. Second attempt -> stage 2, history newest first.
$second = CallLog::add_attempt( $follow_id, 'callback', 'گفت فردا صبح زنگ بزنم', 5 );
MjyInt::same( 2, $second['stage'], 'تماس دوم شمارهٔ مرحلهٔ ۲ می‌گیرد' );
MjyInt::same( 'تماس دوم', CallLog::stage_label( 2 ), 'برچسب مرحلهٔ ۲ درست است' );
MjyInt::same( 3, CallLog::stage_label( 3 ) === 'تماس سوم' ? 3 : 0, 'برچسب مرحلهٔ ۳ درست است' );

$history = CallLog::history( $follow_id );
MjyInt::same( 2, count( $history ), 'هر دو تماس در تاریخچه هست' );
MjyInt::same( 2, (int) $history[0]['stage'], 'تاریخچه از تازه به قدیم مرتب است' );
MjyInt::same( 'گفت فردا صبح زنگ بزنم', (string) $history[0]['note'], 'متن تایپ‌شدهٔ مرحلهٔ دوم ذخیره شد' );
MjyInt::same( 'no_answer', (string) $history[1]['outcome'], 'نتیجهٔ مرحلهٔ اول ذخیره شد' );

// 3. Validation: unknown outcome, empty outcome, missing row.
$bad_outcome = CallLog::add_attempt( $follow_id, 'whatever', '', 5 );
MjyInt::same( 'invalid_outcome', $bad_outcome['reason'], 'نتیجهٔ ناشناخته رد می‌شود' );
MjyInt::same( 2, CallLog::attempt_count( $follow_id ), 'رد شدن، مرحله را جلو نمی‌برد' );

$missing = CallLog::add_attempt( 999999, 'answered', '', 5 );
MjyInt::same( 'not_found', $missing['reason'], 'ردیف ناموجود خطا می‌دهد، نه ردیف یتیم' );

// 4. A very long note is bounded, never fatal.
$long = CallLog::add_attempt( $follow_id, 'answered', str_repeat( 'ب', 900 ), 5 );
MjyInt::ok( $long['ok'], 'یادداشت بلند ثبت را نمی‌شکند' );
$long_row = CallLog::history( $follow_id );
MjyInt::ok( \Mojoodyar\Support\Text::length( (string) $long_row[0]['note'] ) <= 500, 'یادداشت به ۵۰۰ کاراکتر محدود می‌شود' );

// 5. Terminal outcomes close the follow-up by themselves.
$closed = CallLog::add_attempt( $follow_id, 'ordered', 'سفارش داد', 5 );
MjyInt::ok( $closed['closed'], 'نتیجهٔ «سفارش داد» پیگیری را می‌بندد' );
$closed_row = WaitlistRepository::get( $follow_id );
MjyInt::same( 'closed', (string) $closed_row['call_state'], 'وضعیت پیگیری بسته شد' );
MjyInt::same( 'ordered', (string) $closed_row['call_close_reason'], 'دلیل بسته‌شدن ثبت شد' );

// 6. A closed follow-up refuses new attempts until reopened.
$refused = CallLog::add_attempt( $follow_id, 'answered', '', 5 );
MjyInt::same( 'closed', $refused['reason'], 'روی پیگیری بسته، تماس تازه ثبت نمی‌شود' );

$reopen = CallLog::reopen( $follow_id );
MjyInt::ok( $reopen['ok'], 'بازکردن دوباره موفق است' );
$reopened = WaitlistRepository::get( $follow_id );
MjyInt::same( 'open', (string) $reopened['call_state'], 'وضعیت به باز برگشت' );
MjyInt::same( 4, CallLog::attempt_count( $follow_id ), 'بازکردن تاریخچه را پاک نمی‌کند' );

// 7. Manual close needs a known reason.
$no_reason = CallLog::close( $follow_id, '', '', 5 );
MjyInt::same( 'invalid_reason', $no_reason['reason'], 'خاتمهٔ بدون دلیل پذیرفته نمی‌شود' );

$manual = CallLog::close( $follow_id, 'unreachable', 'چند بار زنگ زدیم', 5 );
MjyInt::ok( $manual['ok'], 'خاتمه با دلیل درست ثبت می‌شود' );
$manual_row = WaitlistRepository::get( $follow_id );
MjyInt::same( 'unreachable', (string) $manual_row['call_close_reason'], 'دلیل دستی ذخیره شد' );
MjyInt::same( 'چند بار زنگ زدیم', (string) $manual_row['call_close_note'], 'توضیح خاتمه ذخیره شد' );
MjyInt::same( 'بسته: دیگر پاسخ نمی‌دهد', CallLog::state_label( $manual_row ), 'برچسب وضعیت برای جدول ساخته می‌شود' );

// 8. One query for the whole page.
$counts = CallLog::summaries( array( $follow_id, 0, -3 ) );
MjyInt::same( 4, (int) ( $counts[ $follow_id ] ?? 0 ), 'خلاصهٔ صفحه تعداد تماس‌ها را می‌دهد' );

// 9. The admin endpoint itself: nonce, action dispatch, dispatcher defaults.
$_POST = array(
	'_wpnonce'    => wp_create_nonce( 'mojoodyar_call' ),
	'id'          => $follow_id,
	'call_action' => 'reopen',
);
$GLOBALS['mjy_caps'][ \Mojoodyar\Capabilities::MANAGE ] = true;
$admin = new \Mojoodyar\Admin\Admin();

$opened = $admin->process_call(
	array(
		'id'          => $follow_id,
		'call_action' => 'reopen',
		'user_id'     => 5,
	)
);
MjyInt::ok( $opened['ok'], 'اکشن reopen موفق است' );
MjyInt::same( 'open', (string) WaitlistRepository::get( $follow_id )['call_state'], 'endpoint پیگیری را باز می‌کند' );

$logged = $admin->process_call(
	array(
		'id'          => $follow_id,
		'call_action' => 'attempt',
		'outcome'     => 'busy',
		'note'        => 'از طریق فرم پنل',
		'user_id'     => 5,
	)
);
MjyInt::ok( $logged['ok'], 'اکشن attempt از مسیر پنل موفق است' );
$after_admin = CallLog::history( $follow_id );
MjyInt::same( 'busy', (string) $after_admin[0]['outcome'], 'endpoint یک تماس تازه ثبت می‌کند' );
MjyInt::same( 'از طریق فرم پنل', (string) $after_admin[0]['note'], 'یادداشت فرم پنل ذخیره می‌شود' );
MjyInt::same( 5, CallLog::attempt_count( $follow_id ), 'شمارش مرحله‌ها بعد از ثبت پنل درست است' );

// A failure returns a Persian message instead of dying.
$failed = $admin->process_call(
	array(
		'id'          => $follow_id,
		'call_action' => 'attempt',
		'outcome'     => '',
		'user_id'     => 5,
	)
);
MjyInt::ok( ! $failed['ok'], 'بدون نتیجه، ثبت انجام نمی‌شود' );
MjyInt::ok( false !== strpos( $failed['notice'], 'نتیجهٔ تماس' ), 'پیام خطا فارسی و گویاست' );

$closed_admin = $admin->process_call(
	array(
		'id'           => $follow_id,
		'call_action'  => 'close',
		'close_reason' => 'other',
		'user_id'      => 5,
	)
);
MjyInt::ok( $closed_admin['ok'], 'اکشن close موفق است' );
MjyInt::same( 'other', (string) WaitlistRepository::get( $follow_id )['call_close_reason'], 'endpoint اکشن close را اجرا می‌کند' );

// A cached form from 1.0.x still posts `called=1` with no call_action.
$legacy = $admin->process_call(
	array(
		'id'      => $follow_id,
		'called'  => 1,
		'user_id' => 5,
	)
);
MjyInt::same( 'legacy', $legacy['action'], 'فرم قدیمی به مسیر سازگاری می‌رود' );
MjyInt::ok( ! empty( WaitlistRepository::get( $follow_id )['called_at'] ), 'فرم قدیمی (called=1) هنوز کار می‌کند' );

// 8b. Deleting the waitlist row takes its call history with it (no FK in the
// schema, so this is the only thing that prevents orphan rows).
$admin_for_delete = new \Mojoodyar\Admin\Admin();
$orphan_probe = new ReflectionMethod( '\\Mojoodyar\\Admin\\Admin', 'delete_row' );
$orphan_probe->setAccessible( true );
$before_delete = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . CallLog::table() );
MjyInt::ok( $before_delete > 0, 'قبل از حذف، تاریخچه وجود دارد' );
$orphan_probe->invoke( $admin_for_delete, $follow_id );
MjyInt::same( 0, CallLog::attempt_count( $follow_id ), 'حذف ردیف، تاریخچهٔ تماس را هم پاک می‌کند' );

$GLOBALS['wpdb']->query( 'DELETE FROM ' . CallLog::table() );

MjyInt::group( 'مهاجرت از برند قدیم (۱.۰.x)' );

// 2.0 renamed the product and with it the table prefix (nvb_ -> mjy_). An
// existing install must keep its rows: the migration renames tables and copies
// options, and must never drop or overwrite anything.
$db = $GLOBALS['wpdb'];

// Start from a known state with one real row inside the CURRENT tables.
$db->query( 'DELETE FROM ' . WaitlistRepository::table() );
$db->insert(
	WaitlistRepository::table(),
	array(
		'token'      => str_repeat( 'c', 64 ),
		'product_id' => 91,
		'email'      => 'legacy@example.com',
		'full_name'  => 'مشتری قدیمی',
		'status'     => 'confirmed',
		'created_at' => '2026-01-01 10:00:00',
	)
);

$legacy_waitlists = $db->prefix . 'nvb_waitlists';
$legacy_queue     = $db->prefix . 'nvb_queue';

// Pretend the site is still on 1.0.x: the four tables carry the old names.
foreach ( \Mojoodyar\Migration::LEGACY_TABLES as $legacy_short => $current ) {
	$db->query( 'RENAME TABLE ' . Database::table( $current ) . ' TO ' . $db->prefix . $legacy_short );
}

MjyInt::ok( \Mojoodyar\Migration::table_exists( $legacy_waitlists ), 'جدول نسخهٔ قدیم با نام nvb_ ساخته شد' );
MjyInt::ok( ! \Mojoodyar\Migration::table_exists( WaitlistRepository::table() ), 'جدول نام جدید هنوز وجود ندارد' );

// Legacy settings, written the way 1.0.x wrote them.
$legacy_settings               = Settings::defaults();
$legacy_settings['site_name']  = 'فروشگاه قدیمی';
delete_option( Settings::OPTION );
update_option( 'navidban_settings', $legacy_settings, false );
update_option( 'navidban_db_version', '2', false );

$report = \Mojoodyar\Migration::from_legacy_brand();

MjyInt::ok( ! $report['conflict'], 'وضعیت متناقض گزارش نشد' );
MjyInt::same( 'moved', $report['tables']['nvb_waitlists'], 'جدول لیست انتظار منتقل شد' );
MjyInt::same( 'moved', $report['tables']['nvb_queue'], 'جدول صف منتقل شد' );
MjyInt::ok( \Mojoodyar\Migration::table_exists( WaitlistRepository::table() ), 'جدول با نام جدید در دسترس است' );
MjyInt::ok( ! \Mojoodyar\Migration::table_exists( $legacy_waitlists ), 'نام قدیم باقی نماند' );
MjyInt::same( 1, (int) $db->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::table() ), 'ردیف مشتری گم نشد' );
MjyInt::same( 'مشتری قدیمی', (string) $db->get_var( 'SELECT full_name FROM ' . WaitlistRepository::table() ), 'دادهٔ ردیف دست‌نخورده ماند' );

Settings::flush_cache();
MjyInt::same( 'فروشگاه قدیمی', (string) Settings::get( 'site_name' ), 'تنظیمات قدیم به کلید جدید منتقل شد' );
MjyInt::same( 'copied', $report['options']['navidban_settings'], 'گزارش، کپی تنظیمات را ثبت کرد' );

// Safe to run twice: the second pass finds nothing to move.
$second = \Mojoodyar\Migration::from_legacy_brand();
MjyInt::same( 'missing', $second['tables']['nvb_waitlists'], 'اجرای دوم چیزی برای انتقال پیدا نمی‌کند' );
MjyInt::ok( ! $second['conflict'], 'اجرای دوم وضعیت متناقض نمی‌سازد' );

// A half-finished manual rename (both tables present) must stop, not guess.
$db->query( 'CREATE TABLE ' . $legacy_queue . ' (id BIGINT UNSIGNED NOT NULL)' );
$conflict = \Mojoodyar\Migration::from_legacy_brand();
MjyInt::same( 'conflict', $conflict['tables']['nvb_queue'], 'وجود هر دو جدول به‌عنوان تناقض گزارش می‌شود' );
MjyInt::ok( $conflict['conflict'], 'پرچم تناقض روشن است' );
MjyInt::ok( \Mojoodyar\Migration::table_exists( $legacy_queue ), 'در حالت تناقض هیچ جدولی حذف نمی‌شود' );
MjyInt::ok( \Mojoodyar\Migration::table_exists( Queue::table() ), 'جدول فعلی هم دست‌نخورده می‌ماند' );

// Clean up the scratch table so later groups see a normal schema.
$db->query( 'DROP TABLE ' . $legacy_queue );
/* -------------------------------------------------------------------------
 * Report
 * ---------------------------------------------------------------------- */
$summary = MjyInt::summary();
$arguments = isset( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();
$as_json   = in_array( '--json', $arguments, true );

if ( ! $as_json ) {
	echo "\n=== Mojoodyar integration harness (WordPress stubs + SQLite) ===\n";
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
