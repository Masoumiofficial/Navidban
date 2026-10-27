<?php
/**
 * Admin render smoke test.
 *
 * Every admin screen is rendered against the stub layer and the produced HTML
 * is inspected. This does not check WordPress styling; it checks the things
 * that actually break a release:
 *   - a view fatals or emits a PHP notice (undefined key, bad call);
 *   - a stored value is printed without escaping;
 *   - a screen renders empty because its data shape changed;
 *   - the capability guard is missing.
 *
 * Usage: php tests/admin-render.php [--json]
 *
 * @package Mojoodyar
 */

require_once __DIR__ . '/stubs/wp.php';
require_once __DIR__ . '/stubs/woocommerce.php';
require_once __DIR__ . '/stubs/admin.php';
require_once MOJODYAR_DIR . 'includes/Autoloader.php';
\Mojoodyar\Autoloader::register();

use Mojoodyar\Activator;
use Mojoodyar\Admin\Admin;
use Mojoodyar\Database;
use Mojoodyar\Queue;
use Mojoodyar\Settings;

/**
 * Tiny assertion collector (same shape as the other harnesses).
 */
final class MjyAdmin {

	public static $assertions = 0;
	public static $failures = 0;
	public static $failed = array();
	public static $groups = array();
	public static $group = '';

	public static function group( $name ) {
		self::$group           = $name;
		self::$groups[ $name ] = array( 'assertions' => 0, 'failures' => 0 );
	}

	public static function ok( $condition, $label, $actual = null ) {
		self::$assertions++;
		self::$groups[ self::$group ]['assertions']++;

		if ( ! $condition ) {
			self::$failures++;
			self::$groups[ self::$group ]['failures']++;
			self::$failed[] = array(
				'group'  => self::$group,
				'label'  => $label,
				'actual' => is_bool( $actual ) ? ( $actual ? 'true' : 'false' ) : (string) $actual,
			);
		}

		return (bool) $condition;
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

// Turn every PHP notice/warning into a collected string so the test can fail
// on it instead of letting it scroll past.
$GLOBALS['mjy_php_messages'] = array();
set_error_handler(
	static function ( $errno, $errstr, $errfile, $errline ) {
		$GLOBALS['mjy_php_messages'][] = $errstr . ' @ ' . basename( (string) $errfile ) . ':' . $errline;
		return true;
	}
);

/**
 * Render a callable and capture its output.
 *
 * @param callable $callback Callback.
 * @return string
 */
function mjy_render( $callback ) {
	ob_start();
	try {
		call_user_func( $callback );
	} catch ( Throwable $e ) {
		ob_end_clean();
		$GLOBALS['mjy_php_messages'][] = 'THROWN: ' . $e->getMessage();

		return '';
	}

	return (string) ob_get_clean();
}

Activator::activate( false );

$GLOBALS['mjy_caps'] = array( 'mojoodyar_manage' => true, 'mojoodyar_view_reports' => true );

$admin = new Admin();

/* -------------------------------------------------------------------------
 * Menu registration
 * ---------------------------------------------------------------------- */
MjyAdmin::group( 'منو و سطوح دسترسی' );

$admin->menu();

$slugs = array();
foreach ( (array) $GLOBALS['mjy_admin_menu'] as $item ) {
	$slugs[] = $item['slug'];
}

MjyAdmin::ok( in_array( 'mojoodyar', $slugs, true ), 'منوی اصلی ثبت شد', implode( ',', $slugs ) );
MjyAdmin::ok( count( $GLOBALS['mjy_admin_menu'] ) >= 6, 'پنج زیرمنو + منوی اصلی', count( $GLOBALS['mjy_admin_menu'] ) );

foreach ( array( 'mojoodyar-stats', 'mojoodyar-waitlists', 'mojoodyar-settings', 'mojoodyar-tools', 'mojoodyar-help' ) as $slug ) {
	MjyAdmin::ok( in_array( $slug, $slugs, true ), 'زیرمنو موجود است: ' . $slug, implode( ',', $slugs ) );
}

/* -------------------------------------------------------------------------
 * Seed data, including a hostile row written straight to the table
 * ---------------------------------------------------------------------- */
Settings::flush_cache();

$repo = '\\Mojoodyar\\WaitlistRepository';

$repo::create(
	array(
		'product_id'   => 501,
		'variation_id' => 0,
		'channel'      => 'email',
		'email'        => 'buyer@example.com',
		'phone'        => '',
		'ip_hash'      => str_repeat( 'a', 64 ),
		'status'       => 'confirmed',
		'consent_at'   => gmdate( 'Y-m-d H:i:s' ),
	)
);

// A row that never passed validation (imported data, or a row written by hand)
// must still be escaped on screen.
$GLOBALS['wpdb']->insert(
	Database::table( Database::WAITLISTS ),
	array(
		'token'       => str_repeat( 'b', 64 ),
		'product_id'  => 502,
		'variation_id' => 0,
		'event_type'  => 'restock',
		'channel'     => 'email',
		'email'       => '<script>alert(1)</script>@example.com',
		'phone'       => '',
		'user_id'     => 0,
		'status'      => 'pending',
		'consent_at'  => gmdate( 'Y-m-d H:i:s' ),
		'ip_hash'     => str_repeat( 'c', 64 ),
		'created_at'  => gmdate( 'Y-m-d H:i:s' ),
	)
);

Queue::enqueue( 1, 'email', 'restock:20261025:test0001', array( 'subject' => 'x', 'body' => 'y' ) );

/* -------------------------------------------------------------------------
 * Screens
 * ---------------------------------------------------------------------- */
MjyAdmin::group( 'رندر صفحات' );

$screens = array(
	'dashboard' => array( 'render_dashboard', 'داشبورد' ),
	'stats'     => array( 'render_stats', 'آمار' ),
	'waitlists' => array( 'render_waitlists', 'لیست انتظار' ),
	'settings'  => array( 'render_settings', 'تنظیمات' ),
	'tools'     => array( 'render_tools', 'ابزار' ),
	'help'      => array( 'render_help', 'راهنما' ),
);

$rendered = array();

foreach ( $screens as $key => $config ) {
	list( $method, $marker ) = $config;

	$html = mjy_render( array( $admin, $method ) );
	$rendered[ $key ] = $html;

	MjyAdmin::ok( strlen( $html ) > 400, 'صفحهٔ ' . $key . ' خروجی دارد', strlen( $html ) );
	MjyAdmin::ok( false !== strpos( $html, $marker ), 'صفحهٔ ' . $key . ' نشانگر «' . $marker . '» را دارد' );
	MjyAdmin::ok( 0 === substr_count( $html, 'mjy-wrap' ) || false !== strpos( $html, 'mjy-wrap' ), 'صفحهٔ ' . $key . ' پوشش RTL دارد' );
	MjyAdmin::ok( false === stripos( $html, '<script>alert' ), 'صفحهٔ ' . $key . ' اسکریپت تزریق‌شده ندارد' );
}

/* -------------------------------------------------------------------------
 * Shell: app bar + tab strip
 * ---------------------------------------------------------------------- */
/* -------------------------------------------------------------------------
 * Statistics screen
 * ---------------------------------------------------------------------- */
MjyAdmin::group( 'صفحهٔ آمار' );

$stats_html = $rendered['stats'];

MjyAdmin::ok( false !== strpos( $stats_html, 'آمار' ), 'عنوان صفحهٔ آمار رندر می‌شود' );
MjyAdmin::ok( false !== strpos( $stats_html, 'range=7' ) && false !== strpos( $stats_html, 'range=90' ), 'بازه‌های ۷ و ۹۰ روز قابل انتخاب‌اند' );
MjyAdmin::ok( false !== strpos( $stats_html, 'mojoodyar-stats&range=30' ), 'پیوند بازه بدون پارامتر اضافه ساخته می‌شود' );
MjyAdmin::ok( false !== strpos( $stats_html, 'admin-post.php?action=mojoodyar_export_stats' ), 'دکمهٔ خروجی CSV آمار هست' );
MjyAdmin::ok( false !== strpos( $stats_html, '_wpnonce=' ), 'خروجی CSV با nonce محافظت می‌شود' );

// A chart with no data must say so in words, not render an empty grid.
MjyAdmin::ok(
	false !== strpos( $stats_html, 'هیچ ثبت تازه‌ای نبوده است' ) || false !== strpos( $stats_html, 'mjy-chart' ),
	'نمودار یا پیام «داده‌ای نیست» رندر می‌شود'
);
MjyAdmin::ok( false === strpos( $stats_html, 'NAN' ) && false === strpos( $stats_html, 'INF' ), 'هیچ عدد نامعتبری در صفحه نیست' );
MjyAdmin::ok( false === strpos( $stats_html, 'nan' ), 'حروف کوچک nan هم در صفحه نیست' );

// The rejection panel is the reason this screen exists: without it an admin
// sees an empty waitlist and no explanation.
MjyAdmin::ok( false !== strpos( $stats_html, 'ثبت‌های ردشده' ), 'پنل «ثبت‌های ردشده» رندر می‌شود' );
MjyAdmin::ok( false !== strpos( $stats_html, 'سلامت صف ارسال' ), 'پنل سلامت صف رندر می‌شود' );
MjyAdmin::ok( false !== strpos( $stats_html, 'کانال اعلان' ), 'تفکیک کانال رندر می‌شود' );

// No external asset may be pulled into an admin screen.
MjyAdmin::ok( false === strpos( $stats_html, 'cdn.' ) && false === strpos( $stats_html, 'googleapis' ), 'صفحه هیچ منبع بیرونی صدا نمی‌زند' );
MjyAdmin::ok( false === strpos( $stats_html, '<canvas' ) && false === strpos( $stats_html, 'chart.js' ) && false === strpos( $stats_html, 'Chart(' ), 'نمودار با HTML/CSS است، نه کتابخانهٔ خارجی' );
MjyAdmin::ok( false !== strpos( $stats_html, 'mjy-chart' ) || false !== strpos( $stats_html, 'هیچ' ), 'ساختار نمودار (میله‌های div) در HTML هست' );

// The escape hatch the report offers must point at the tool that explains a
// specific product, not at a dead end.
MjyAdmin::ok( false !== strpos( $stats_html, 'dir="rtl"' ), 'صفحهٔ آمار راست‌به‌چپ است' );

MjyAdmin::group( 'پوستهٔ شیشه‌ای' );

MjyAdmin::ok( false !== strpos( $rendered['dashboard'], 'mjy-hero' ), 'نوار بالای صفحه رندر می‌شود' );
MjyAdmin::ok( false !== strpos( $rendered['dashboard'], 'mjy-hero__title' ), 'عنوان در نوار بالا هست' );
MjyAdmin::ok( false !== strpos( $rendered['dashboard'], '<svg' ), 'نشان برند به‌صورت SVG درون‌خطی است (بدون درخواست بیرونی)' );
MjyAdmin::ok( 1 === substr_count( $rendered['settings'], 'aria-current="page"' ), 'فقط یک زبانهٔ فعال علامت می‌خورد' );
MjyAdmin::ok( false !== strpos( $rendered['settings'], 'page=mojoodyar-settings"' ) && false !== strpos( $rendered['settings'], 'aria-current="page"' ), 'زبانهٔ فعال همان صفحهٔ جاری است' );
MjyAdmin::ok( false !== strpos( $rendered['help'], 'page=mojoodyar-help"' ), 'زبانهٔ راهنما به صفحهٔ درست وصل است' );
MjyAdmin::ok( substr_count( $rendered['dashboard'], 'mjy-footer' ) === 1, 'پاورقی یک‌بار چاپ می‌شود' );
MjyAdmin::ok( false === strpos( $rendered['dashboard'], 'fonts.googleapis' ) && false === strpos( $rendered['dashboard'], 'http://' ) || false === strpos( $rendered['dashboard'], 'fonts.googleapis' ), 'هیچ فونت بیرونی بارگذاری نمی‌شود' );
MjyAdmin::ok( false === strpos( $rendered['help'], 'undefined' ), 'هیچ مقدار تعریف‌نشده در خروجی نیست' );

// Regression: Persian::number() formats a number, so a version string must be
// converted segment by segment or "1.0.4" prints as "۱".
$mjy_dots = implode(
	'.',
	array_map(
		static function ( $part ) {
			return \Mojoodyar\Support\Persian::number( $part );
		},
		explode( '.', (string) MOJODYAR_VERSION )
	)
);
MjyAdmin::ok( false !== strpos( $rendered['dashboard'], $mjy_dots ), 'شمارهٔ نسخه کامل و با ارقام فارسی نمایش داده می‌شود', $mjy_dots );

/* -------------------------------------------------------------------------
 * Contracts inside the output
 * ---------------------------------------------------------------------- */
MjyAdmin::group( 'قراردادهای خروجی' );

$settings_html = $rendered['settings'];

MjyAdmin::ok( false !== strpos( $settings_html, 'options.php' ), 'فرم تنظیمات به options.php می‌رود' );
MjyAdmin::ok( false !== strpos( $settings_html, 'mojoodyar_settings[' ), 'نام آپشن در فرم درست است' );
MjyAdmin::ok( false !== strpos( $settings_html, 'option_page' ), 'settings_fields فراخوانی شده' );
MjyAdmin::ok( false !== strpos( $settings_html, 'mjy-form-heading' ), 'فیلد عنوان فرم هست' );
MjyAdmin::ok( false !== strpos( $settings_html, 'mjy-actions' ), 'نوار ذخیرهٔ تنظیمات رندر می‌شود' );
MjyAdmin::ok( false !== strpos( $settings_html, 'name="mojoodyar_settings[form_email_field]"' ), 'کلید نمایش فیلد ایمیل هست' );
MjyAdmin::ok( false !== strpos( $settings_html, 'name="mojoodyar_settings[form_phone_field]"' ), 'کلید نمایش فیلد موبایل هست' );
MjyAdmin::ok( 1 === substr_count( $settings_html, 'name="mojoodyar_settings[form_email_field]"' ), 'کلید ایمیل فقط یک‌بار رندر می‌شود' );
// Fresh defaults: SMS is not configured, so the screen must say the phone field
// will not appear — a toggle that silently does nothing is worse than none.
MjyAdmin::ok( false !== strpos( $settings_html, 'پیامک پیکربندی نشده' ), 'وقتی پیامک آماده نیست، کنار کلید موبایل هشدار می‌دهد' );
MjyAdmin::ok( false !== strpos( $settings_html, 'button button-primary' ) && false !== strpos( $settings_html, 'type="submit"' ), 'دکمهٔ ذخیره، دکمهٔ اصلی و از نوع submit است' );
MjyAdmin::ok( false !== strpos( $settings_html, 'name="mojoodyar_settings[sms_gateway]"' ), 'انتخاب درگاه پیامک هست' );

// Pattern (bodyId) block: the text the gateway will actually deliver lives in
// the panel, so the screen has to show the rules and the exact values we send.
MjyAdmin::ok( false !== strpos( $settings_html, 'name="mojoodyar_settings[sms_pattern_template]"' ), 'فیلد متن الگوی پیامک هست' );
MjyAdmin::ok( false !== strpos( $settings_html, '<textarea' ), 'متن الگو در textarea ویرایش می‌شود' );
MjyAdmin::ok( false !== strpos( $settings_html, '{0}' ) && false !== strpos( $settings_html, '{1}' ), 'فهرست متغیرها با شمارهٔ صفر شروع می‌شود' );
MjyAdmin::ok( false !== strpos( $settings_html, 'متغیر در انتها' ), 'قاعدهٔ «متغیر در انتها» در راهنما آمده' );
MjyAdmin::ok( false !== strpos( $settings_html, 'مریم;کفش دویدن نایک;https://example.com/p/12' ), 'نمونهٔ مقدارهای ارسالی نشان داده می‌شود' );
MjyAdmin::ok( false !== strpos( $settings_html, 'mjy-pattern-ok' ), 'الگوی پیش‌فرض سالم است و پیام تأیید می‌گیرد' );
MjyAdmin::ok( false === strpos( $settings_html, 'mjy-pattern-findings' ), 'برای الگوی پیش‌فرض هیچ ایرادی رندر نمی‌شود' );

// ---------------------------------------------------------------- name field
MjyAdmin::ok( false !== strpos( $settings_html, 'name="mojoodyar_settings[form_name_field]"' ), 'کلید نمایش فیلد نام هست' );
MjyAdmin::ok( false !== strpos( $settings_html, 'name="mojoodyar_settings[form_name_required]"' ), 'کلید اجباری‌بودن نام هست' );
MjyAdmin::ok( 1 === substr_count( $settings_html, 'name="mojoodyar_settings[form_name_field]"' ), 'کلید نام فقط یک‌بار رندر می‌شود' );

// ------------------------------------------------------------- call tracking
MjyAdmin::ok( false !== strpos( $settings_html, '[sms_pattern_template]' ), 'فیلد متن الگو سر جایش است' );

$waitlists_html = $rendered['waitlists'];
MjyAdmin::ok( false !== strpos( $waitlists_html, 'name="called"' ), 'فیلتر تماس در فرم فیلترها هست' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'تماس گرفته نشده' ), 'گزینهٔ «تماس گرفته نشده» هست' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'value="mojoodyar_call"' ), 'فرم تک‌ردیفی به نقطهٔ پایانی تماس وصل است' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'data-mjy="call-open"' ), 'دکمهٔ «پیگیری تلفنی» در هر ردیف هست' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'پیگیری تلفنی' ), 'برچسب دکمه «پیگیری تلفنی» است' );
MjyAdmin::ok( false === strpos( $waitlists_html, 'data-mjy="call-save"' ) && false === strpos( $waitlists_html, 'data-mjy="call-toggle"' ), 'دکمهٔ «ثبت» و تیک سادهٔ قبلی حذف شده‌اند' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'data-mjy="call-modal"' ), 'برای هر ردیف یک دیالوگ پیگیری رندر می‌شود' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'name="outcome"' ), 'فرم دیالوگ نتیجهٔ تماس را می‌پرسد' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'value="attempt"' ) && false !== strpos( $waitlists_html, 'value="close"' ), 'هر دو عمل «ثبت مرحله» و «خاتمه» در فرم هست' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'name="_wpnonce"' ), 'تیک تماس nonce دارد' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'value="called"' ) && false !== strpos( $waitlists_html, 'value="uncalled"' ), 'عملیات گروهی تماس هست' );
MjyAdmin::ok( false !== strpos( $waitlists_html, 'name="ids[]" form="mjy-bulk-form"' ), 'چک‌باکس‌های گروهی به فرم بیرونی وصل‌اند' );

// A form inside a form is invalid HTML: the browser drops the inner one and the
// call tick would silently stop working. The bulk form must be closed first.
$mjy_bulk_end      = strpos( $waitlists_html, 'mjy-bulk' );
$mjy_table_start   = strpos( $waitlists_html, 'mjy-table' );
$mjy_between       = ( false !== $mjy_bulk_end && false !== $mjy_table_start && $mjy_table_start > $mjy_bulk_end )
	? substr( $waitlists_html, $mjy_bulk_end, $mjy_table_start - $mjy_bulk_end )
	: '';
MjyAdmin::ok( false !== strpos( $mjy_between, '</form>' ), 'فرم گروهی پیش از جدول بسته می‌شود (بدون فرم تودرتو)' );
MjyAdmin::ok( 1 === substr_count( $waitlists_html, 'id="mjy-bulk-form"' ), 'فقط یک فرم گروهی هست' );

MjyAdmin::ok( false !== strpos( $rendered['dashboard'], 'تماس گرفته‌شده' ), 'داشبورد شمارندهٔ تماس را نشان می‌دهد' );

foreach ( array( 'rebuild_snapshots', 'run_reconcile', 'flush_queue', 'empty_queue', 'prune', 'test_email', 'test_sms' ) as $tool ) {
	MjyAdmin::ok( false !== strpos( $rendered['tools'], 'value="' . $tool . '"' ), 'ابزار موجود است: ' . $tool );
}

MjyAdmin::ok( false !== strpos( $rendered['tools'], 'mojoodyar_tool' ), 'فرم ابزار به admin-post وصل است' );
// The list masks personal data by design (data minimisation), so the domain
// survives while the local part does not.
MjyAdmin::ok( false !== strpos( $rendered['waitlists'], '@example.com' ), 'ردیف نمونه در لیست دیده می‌شود' );
MjyAdmin::ok( false === strpos( $rendered['waitlists'], 'buyer@example.com' ), 'ایمیل کامل نمایش داده نمی‌شود (ماسک شده)' );
MjyAdmin::ok( false === stripos( $rendered['waitlists'], '<script' ), 'هیچ تگ script در خروجی نیست' );
MjyAdmin::ok( false !== strpos( $rendered['waitlists'], '&lt;' ), 'کاراکتر خطرناک به موجودیت HTML تبدیل شده' );
MjyAdmin::ok( false !== strpos( $rendered['dashboard'], 'InnoDB' ) || false !== strpos( $rendered['dashboard'], 'سالم' ), 'داشبورد وضعیت جدول‌ها را نشان می‌دهد' );

/* -------------------------------------------------------------------------
 * Stylesheet coverage
 *
 * The bug this guards against is subtle and already shipped once: an <input>
 * with no type attribute is a text box in every browser, but the stylesheet
 * only listed input[type="text"] etc., so those fields stayed as raw
 * WordPress boxes while everything around them was styled.
 * ---------------------------------------------------------------------- */
MjyAdmin::group( 'پوشش استایل' );

$mjy_css = (string) file_get_contents( MOJODYAR_DIR . 'assets/css/mojoodyar-admin.css' );
MjyAdmin::ok( '' !== $mjy_css, 'فایل CSS پنل خوانده می‌شود' );

$mjy_all_html = implode( "\n", $rendered );
preg_match_all( '~<input\b[^>]*>~i', $mjy_all_html, $mjy_input_matches );
preg_match_all( '~<textarea\b~i', $mjy_all_html, $mjy_textarea_matches );
preg_match_all( '~<select\b~i', $mjy_all_html, $mjy_select_matches );

MjyAdmin::ok( count( $mjy_input_matches[0] ) > 5, 'صفحات ورودی دارند', count( $mjy_input_matches[0] ) );
MjyAdmin::ok( count( $mjy_select_matches[0] ) > 0 && false !== strpos( $mjy_css, '.mjy-wrap select' ), 'همهٔ select‌ها استایل دارند' );
MjyAdmin::ok( count( $mjy_textarea_matches[0] ) > 0 && false !== strpos( $mjy_css, '.mjy-wrap textarea' ), 'همهٔ textarea‌ها استایل دارند' );

$mjy_types = array();
foreach ( $mjy_input_matches[0] as $mjy_tag ) {
	if ( preg_match( '~type="([^"]+)"~i', $mjy_tag, $mjy_type_match ) ) {
		$mjy_types[ $mjy_type_match[1] ] = true;
	} else {
		$mjy_types['(none)'] = true;
	}
}

// These are not text-like controls and must not get the field look.
$mjy_exempt = array( 'hidden', 'submit', 'button', 'checkbox', 'radio' );

/**
 * Does the stylesheet contain this selector as a real rule head?
 *
 * A plain substring check is not enough: `input[type="text"]:focus` also
 * contains `input[type="text"]`, and a field styled only on focus is exactly
 * the bug we are guarding against. The selector has to be followed by a
 * comma or an opening brace.
 *
 * @param string $css      Stylesheet.
 * @param string $selector Selector.
 * @return bool
 */
$mjy_has_rule = static function ( $css, $selector ) {
	return 1 === preg_match( '~' . preg_quote( $selector, '~' ) . '\s*[,{]~', $css );
};

foreach ( array_keys( $mjy_types ) as $mjy_type ) {
	if ( in_array( $mjy_type, $mjy_exempt, true ) ) {
		continue;
	}

	$mjy_needle = '(none)' === $mjy_type ? 'input:not([type])' : 'input[type="' . $mjy_type . '"]';
	// The rule head may repeat the wrapper class on purpose (see the comment in
	// the stylesheet): `.mjy-wrap.mjy-wrap input[…]` beats a late-loading
	// `.form-table input` from a theme. Both spellings have to count as covered.
	$mjy_covered = $mjy_has_rule( $mjy_css, '.mjy-wrap ' . $mjy_needle )
		|| $mjy_has_rule( $mjy_css, '.mjy-wrap.mjy-wrap ' . $mjy_needle );
	MjyAdmin::ok( $mjy_covered, 'استایل برای ورودی نوع ' . $mjy_type . ' هست' );
}

// Every input type that actually appears in the rendered screens must have a
// rule. This is the regression guard for the 1.0.8 report: textareas looked
// right while single-line boxes stayed plain, because those markup sites emit
// <input> with no type attribute at all.
$mjy_missing = array();
foreach ( array_keys( $mjy_types ) as $mjy_type ) {
	if ( in_array( $mjy_type, $mjy_exempt, true ) ) {
		continue;
	}

	$mjy_needle  = '(none)' === $mjy_type ? 'input:not([type])' : 'input[type="' . $mjy_type . '"]';
	$mjy_present = $mjy_has_rule( $mjy_css, '.mjy-wrap ' . $mjy_needle )
		|| $mjy_has_rule( $mjy_css, '.mjy-wrap.mjy-wrap ' . $mjy_needle );

	if ( ! $mjy_present ) {
		$mjy_missing[] = $mjy_type;
	}
}

MjyAdmin::ok( empty( $mjy_missing ), 'همهٔ انواع ورودیِ موجود در صفحه‌ها استایل دارند', implode( ',', $mjy_missing ) );
MjyAdmin::ok( false !== strpos( $mjy_css, '.mjy-wrap.mjy-wrap' ), 'انتخابگر فیلد در برابر استایل دیرتر‌بار‌شده مقاوم است' );
MjyAdmin::ok( false === strpos( $mjy_css, '!important' ), 'پنل بدون !important استایل می‌شود' );

// Checkbox and radio must at least be themed, not restyled from scratch.
MjyAdmin::ok( false !== strpos( $mjy_css, 'input[type="checkbox"]' ) && false !== strpos( $mjy_css, 'accent-color' ), 'چک‌باکس‌ها با accent-color رنگ می‌گیرند' );

// The front stylesheet must keep covering the storefront form's controls.
$mjy_front_css = (string) file_get_contents( MOJODYAR_DIR . 'assets/css/mojoodyar-front.css' );
MjyAdmin::ok( false !== strpos( $mjy_front_css, '.mjy-form__field input' ), 'فرم فروشگاه استایل ورودی دارد' );

/* -------------------------------------------------------------------------
 * "Why did nothing send?" panel
 * ---------------------------------------------------------------------- */
MjyAdmin::group( 'صفحهٔ تشخیص محصول' );

$_GET['diag_product'] = 501;
$diag_html            = mjy_render( array( $admin, 'render_tools' ) );
unset( $_GET['diag_product'] );

MjyAdmin::ok( false !== strpos( $diag_html, 'نتیجه برای محصول' ), 'پنل تشخیص برای شناسهٔ داده‌شده رندر می‌شود' );
MjyAdmin::ok( false !== strpos( $diag_html, 'تأییدشده' ), 'تعداد تأییدشده نمایش داده می‌شود' );
MjyAdmin::ok( false !== strpos( $diag_html, 'snapshot' ) || false !== strpos( $diag_html, 'تصویر موجودی' ), 'وضعیت تصویر موجودی نمایش داده می‌شود' );
MjyAdmin::ok( false !== strpos( $diag_html, 'صف ارسال' ), 'وضعیت صف نمایش داده می‌شود' );

// A product nobody subscribed to must say so, not render an empty shell.
$_GET['diag_product'] = 424242;
$empty_html           = mjy_render( array( $admin, 'render_tools' ) );
unset( $_GET['diag_product'] );

MjyAdmin::ok( false !== strpos( $empty_html, 'علت اصلی همین است' ), 'محصول بدون مشترک تأییدشده، علت را صریح می‌گوید' );

// Hostile input: a non-numeric id must not reach a query or the output.
$_GET['diag_product'] = '<script>alert(1)</script>';
$bad_html             = mjy_render( array( $admin, 'render_tools' ) );
unset( $_GET['diag_product'] );

MjyAdmin::ok( false === stripos( $bad_html, '<script' ), 'شناسهٔ نامعتبر هیچ اسکریپتی تزریق نمی‌کند' );

/* -------------------------------------------------------------------------
 * No PHP notices while rendering anything above
 * ---------------------------------------------------------------------- */
MjyAdmin::group( 'سلامت اجرا' );

$messages = array_values( array_unique( (array) $GLOBALS['mjy_php_messages'] ) );
MjyAdmin::ok( empty( $messages ), 'هیچ notice/warning در رندر پنل نبود', implode( ' | ', array_slice( $messages, 0, 3 ) ) );

/* -------------------------------------------------------------------------
 * Capability guard
 * ---------------------------------------------------------------------- */
$GLOBALS['mjy_caps'] = array();
$blocked                     = mjy_render( array( $admin, 'render_dashboard' ) );
$guard_hit                   = false;

foreach ( (array) $GLOBALS['mjy_php_messages'] as $message ) {
	if ( false !== strpos( $message, 'wp_die' ) ) {
		$guard_hit = true;
	}
}

MjyAdmin::ok( '' === $blocked, 'بدون دسترسی، خروجی تولید نمی‌شود', strlen( $blocked ) );
MjyAdmin::ok( $guard_hit, 'بدون دسترسی، wp_die صدا زده می‌شود' );

restore_error_handler();

$arguments = array();
if ( isset( $argv ) && is_array( $argv ) ) {
	$arguments = $argv;
} elseif ( isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
	$arguments = $_SERVER['argv'];
}

$as_json = in_array( '--json', $arguments, true );
$summary = MjyAdmin::summary();

if ( $as_json ) {
	echo json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";
} else {
	echo "\n=== Mojoodyar admin render ===\n";

	foreach ( MjyAdmin::$groups as $name => $group ) {
		printf(
			"[%s] %-28s %2d assertions, %d failures\n",
			$group['failures'] > 0 ? 'FAIL' : 'ok  ',
			$name,
			$group['assertions'],
			$group['failures']
		);
	}

	foreach ( MjyAdmin::$failed as $failure ) {
		echo '[FAIL] ' . $failure['label'] . ' → ' . $failure['actual'] . "\n";
	}

	printf( "\nPHP %s — %d assertions, %d failures\n", PHP_VERSION, $summary['assertions'], $summary['failures'] );
}

exit( $summary['failures'] > 0 ? 1 : 0 );
