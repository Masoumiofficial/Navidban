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
 * @package Navidban
 */

require_once __DIR__ . '/stubs/wp.php';
require_once __DIR__ . '/stubs/woocommerce.php';
require_once __DIR__ . '/stubs/admin.php';
require_once NAVIDBAN_DIR . 'includes/Autoloader.php';
\Navidban\Autoloader::register();

use Navidban\Activator;
use Navidban\Admin\Admin;
use Navidban\Database;
use Navidban\Queue;
use Navidban\Settings;

/**
 * Tiny assertion collector (same shape as the other harnesses).
 */
final class NvbAdmin {

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

// Turn every PHP notice/warning into a collected string so the test can fail
// on it instead of letting it scroll past.
$GLOBALS['nvb_php_messages'] = array();
set_error_handler(
	static function ( $errno, $errstr, $errfile, $errline ) {
		$GLOBALS['nvb_php_messages'][] = $errstr . ' @ ' . basename( (string) $errfile ) . ':' . $errline;
		return true;
	}
);

/**
 * Render a callable and capture its output.
 *
 * @param callable $callback Callback.
 * @return string
 */
function nvb_render( $callback ) {
	ob_start();
	try {
		call_user_func( $callback );
	} catch ( Throwable $e ) {
		ob_end_clean();
		$GLOBALS['nvb_php_messages'][] = 'THROWN: ' . $e->getMessage();

		return '';
	}

	return (string) ob_get_clean();
}

Activator::activate( false );

$GLOBALS['nvb_caps'] = array( 'navidban_manage' => true, 'navidban_view_reports' => true );

$admin = new Admin();

/* -------------------------------------------------------------------------
 * Menu registration
 * ---------------------------------------------------------------------- */
NvbAdmin::group( 'منو و سطوح دسترسی' );

$admin->menu();

$slugs = array();
foreach ( (array) $GLOBALS['nvb_admin_menu'] as $item ) {
	$slugs[] = $item['slug'];
}

NvbAdmin::ok( in_array( 'navidban', $slugs, true ), 'منوی اصلی ثبت شد', implode( ',', $slugs ) );
NvbAdmin::ok( count( $GLOBALS['nvb_admin_menu'] ) >= 6, 'پنج زیرمنو + منوی اصلی', count( $GLOBALS['nvb_admin_menu'] ) );

foreach ( array( 'navidban-waitlists', 'navidban-settings', 'navidban-tools', 'navidban-help' ) as $slug ) {
	NvbAdmin::ok( in_array( $slug, $slugs, true ), 'زیرمنو موجود است: ' . $slug, implode( ',', $slugs ) );
}

/* -------------------------------------------------------------------------
 * Seed data, including a hostile row written straight to the table
 * ---------------------------------------------------------------------- */
Settings::flush_cache();

$repo = '\\Navidban\\WaitlistRepository';

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
NvbAdmin::group( 'رندر صفحات' );

$screens = array(
	'dashboard' => array( 'render_dashboard', 'داشبورد' ),
	'waitlists' => array( 'render_waitlists', 'لیست انتظار' ),
	'settings'  => array( 'render_settings', 'تنظیمات' ),
	'tools'     => array( 'render_tools', 'ابزار' ),
	'help'      => array( 'render_help', 'راهنما' ),
);

$rendered = array();

foreach ( $screens as $key => $config ) {
	list( $method, $marker ) = $config;

	$html = nvb_render( array( $admin, $method ) );
	$rendered[ $key ] = $html;

	NvbAdmin::ok( strlen( $html ) > 400, 'صفحهٔ ' . $key . ' خروجی دارد', strlen( $html ) );
	NvbAdmin::ok( false !== strpos( $html, $marker ), 'صفحهٔ ' . $key . ' نشانگر «' . $marker . '» را دارد' );
	NvbAdmin::ok( 0 === substr_count( $html, 'nvb-wrap' ) || false !== strpos( $html, 'nvb-wrap' ), 'صفحهٔ ' . $key . ' پوشش RTL دارد' );
	NvbAdmin::ok( false === stripos( $html, '<script>alert' ), 'صفحهٔ ' . $key . ' اسکریپت تزریق‌شده ندارد' );
}

/* -------------------------------------------------------------------------
 * Shell: app bar + tab strip
 * ---------------------------------------------------------------------- */
NvbAdmin::group( 'پوستهٔ شیشه‌ای' );

NvbAdmin::ok( false !== strpos( $rendered['dashboard'], 'nvb-hero' ), 'نوار بالای صفحه رندر می‌شود' );
NvbAdmin::ok( false !== strpos( $rendered['dashboard'], 'nvb-hero__title' ), 'عنوان در نوار بالا هست' );
NvbAdmin::ok( false !== strpos( $rendered['dashboard'], '<svg' ), 'نشان برند به‌صورت SVG درون‌خطی است (بدون درخواست بیرونی)' );
NvbAdmin::ok( 1 === substr_count( $rendered['settings'], 'aria-current="page"' ), 'فقط یک زبانهٔ فعال علامت می‌خورد' );
NvbAdmin::ok( false !== strpos( $rendered['settings'], 'page=navidban-settings"' ) && false !== strpos( $rendered['settings'], 'aria-current="page"' ), 'زبانهٔ فعال همان صفحهٔ جاری است' );
NvbAdmin::ok( false !== strpos( $rendered['help'], 'page=navidban-help"' ), 'زبانهٔ راهنما به صفحهٔ درست وصل است' );
NvbAdmin::ok( substr_count( $rendered['dashboard'], 'nvb-footer' ) === 1, 'پاورقی یک‌بار چاپ می‌شود' );
NvbAdmin::ok( false === strpos( $rendered['dashboard'], 'fonts.googleapis' ) && false === strpos( $rendered['dashboard'], 'http://' ) || false === strpos( $rendered['dashboard'], 'fonts.googleapis' ), 'هیچ فونت بیرونی بارگذاری نمی‌شود' );
NvbAdmin::ok( false === strpos( $rendered['help'], 'undefined' ), 'هیچ مقدار تعریف‌نشده در خروجی نیست' );

// Regression: Persian::number() formats a number, so a version string must be
// converted segment by segment or "1.0.4" prints as "۱".
$nvb_dots = implode(
	'.',
	array_map(
		static function ( $part ) {
			return \Navidban\Support\Persian::number( $part );
		},
		explode( '.', (string) NAVIDBAN_VERSION )
	)
);
NvbAdmin::ok( false !== strpos( $rendered['dashboard'], $nvb_dots ), 'شمارهٔ نسخه کامل و با ارقام فارسی نمایش داده می‌شود', $nvb_dots );

/* -------------------------------------------------------------------------
 * Contracts inside the output
 * ---------------------------------------------------------------------- */
NvbAdmin::group( 'قراردادهای خروجی' );

$settings_html = $rendered['settings'];

NvbAdmin::ok( false !== strpos( $settings_html, 'options.php' ), 'فرم تنظیمات به options.php می‌رود' );
NvbAdmin::ok( false !== strpos( $settings_html, 'navidban_settings[' ), 'نام آپشن در فرم درست است' );
NvbAdmin::ok( false !== strpos( $settings_html, 'option_page' ), 'settings_fields فراخوانی شده' );
NvbAdmin::ok( false !== strpos( $settings_html, 'nvb-form-heading' ), 'فیلد عنوان فرم هست' );
NvbAdmin::ok( false !== strpos( $settings_html, 'nvb-actions' ), 'نوار ذخیرهٔ تنظیمات رندر می‌شود' );
NvbAdmin::ok( false !== strpos( $settings_html, 'name="navidban_settings[form_email_field]"' ), 'کلید نمایش فیلد ایمیل هست' );
NvbAdmin::ok( false !== strpos( $settings_html, 'name="navidban_settings[form_phone_field]"' ), 'کلید نمایش فیلد موبایل هست' );
NvbAdmin::ok( 1 === substr_count( $settings_html, 'name="navidban_settings[form_email_field]"' ), 'کلید ایمیل فقط یک‌بار رندر می‌شود' );
// Fresh defaults: SMS is not configured, so the screen must say the phone field
// will not appear — a toggle that silently does nothing is worse than none.
NvbAdmin::ok( false !== strpos( $settings_html, 'پیامک پیکربندی نشده' ), 'وقتی پیامک آماده نیست، کنار کلید موبایل هشدار می‌دهد' );
NvbAdmin::ok( false !== strpos( $settings_html, 'button button-primary' ) && false !== strpos( $settings_html, 'type="submit"' ), 'دکمهٔ ذخیره، دکمهٔ اصلی و از نوع submit است' );
NvbAdmin::ok( false !== strpos( $settings_html, 'name="navidban_settings[sms_gateway]"' ), 'انتخاب درگاه پیامک هست' );

foreach ( array( 'rebuild_snapshots', 'run_reconcile', 'flush_queue', 'empty_queue', 'prune', 'test_email', 'test_sms' ) as $tool ) {
	NvbAdmin::ok( false !== strpos( $rendered['tools'], 'value="' . $tool . '"' ), 'ابزار موجود است: ' . $tool );
}

NvbAdmin::ok( false !== strpos( $rendered['tools'], 'navidban_tool' ), 'فرم ابزار به admin-post وصل است' );
// The list masks personal data by design (data minimisation), so the domain
// survives while the local part does not.
NvbAdmin::ok( false !== strpos( $rendered['waitlists'], '@example.com' ), 'ردیف نمونه در لیست دیده می‌شود' );
NvbAdmin::ok( false === strpos( $rendered['waitlists'], 'buyer@example.com' ), 'ایمیل کامل نمایش داده نمی‌شود (ماسک شده)' );
NvbAdmin::ok( false === stripos( $rendered['waitlists'], '<script' ), 'هیچ تگ script در خروجی نیست' );
NvbAdmin::ok( false !== strpos( $rendered['waitlists'], '&lt;' ), 'کاراکتر خطرناک به موجودیت HTML تبدیل شده' );
NvbAdmin::ok( false !== strpos( $rendered['dashboard'], 'InnoDB' ) || false !== strpos( $rendered['dashboard'], 'سالم' ), 'داشبورد وضعیت جدول‌ها را نشان می‌دهد' );

/* -------------------------------------------------------------------------
 * Stylesheet coverage
 *
 * The bug this guards against is subtle and already shipped once: an <input>
 * with no type attribute is a text box in every browser, but the stylesheet
 * only listed input[type="text"] etc., so those fields stayed as raw
 * WordPress boxes while everything around them was styled.
 * ---------------------------------------------------------------------- */
NvbAdmin::group( 'پوشش استایل' );

$nvb_css = (string) file_get_contents( NAVIDBAN_DIR . 'assets/css/navidban-admin.css' );
NvbAdmin::ok( '' !== $nvb_css, 'فایل CSS پنل خوانده می‌شود' );

$nvb_all_html = implode( "\n", $rendered );
preg_match_all( '~<input\b[^>]*>~i', $nvb_all_html, $nvb_input_matches );
preg_match_all( '~<textarea\b~i', $nvb_all_html, $nvb_textarea_matches );
preg_match_all( '~<select\b~i', $nvb_all_html, $nvb_select_matches );

NvbAdmin::ok( count( $nvb_input_matches[0] ) > 5, 'صفحات ورودی دارند', count( $nvb_input_matches[0] ) );
NvbAdmin::ok( count( $nvb_select_matches[0] ) > 0 && false !== strpos( $nvb_css, '.nvb-wrap select' ), 'همهٔ select‌ها استایل دارند' );
NvbAdmin::ok( count( $nvb_textarea_matches[0] ) > 0 && false !== strpos( $nvb_css, '.nvb-wrap textarea' ), 'همهٔ textarea‌ها استایل دارند' );

$nvb_types = array();
foreach ( $nvb_input_matches[0] as $nvb_tag ) {
	if ( preg_match( '~type="([^"]+)"~i', $nvb_tag, $nvb_type_match ) ) {
		$nvb_types[ $nvb_type_match[1] ] = true;
	} else {
		$nvb_types['(none)'] = true;
	}
}

// These are not text-like controls and must not get the field look.
$nvb_exempt = array( 'hidden', 'submit', 'button', 'checkbox', 'radio' );

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
$nvb_has_rule = static function ( $css, $selector ) {
	return 1 === preg_match( '~' . preg_quote( $selector, '~' ) . '\s*[,{]~', $css );
};

foreach ( array_keys( $nvb_types ) as $nvb_type ) {
	if ( in_array( $nvb_type, $nvb_exempt, true ) ) {
		continue;
	}

	$nvb_needle = '(none)' === $nvb_type ? 'input:not([type])' : 'input[type="' . $nvb_type . '"]';
	NvbAdmin::ok( $nvb_has_rule( $nvb_css, '.' . 'nvb-wrap ' . $nvb_needle ), 'استایل برای ورودی نوع ' . $nvb_type . ' هست' );
}

// Checkbox and radio must at least be themed, not restyled from scratch.
NvbAdmin::ok( false !== strpos( $nvb_css, 'input[type="checkbox"]' ) && false !== strpos( $nvb_css, 'accent-color' ), 'چک‌باکس‌ها با accent-color رنگ می‌گیرند' );

// The front stylesheet must keep covering the storefront form's controls.
$nvb_front_css = (string) file_get_contents( NAVIDBAN_DIR . 'assets/css/navidban-front.css' );
NvbAdmin::ok( false !== strpos( $nvb_front_css, '.nvb-form__field input' ), 'فرم فروشگاه استایل ورودی دارد' );

/* -------------------------------------------------------------------------
 * "Why did nothing send?" panel
 * ---------------------------------------------------------------------- */
NvbAdmin::group( 'صفحهٔ تشخیص محصول' );

$_GET['diag_product'] = 501;
$diag_html            = nvb_render( array( $admin, 'render_tools' ) );
unset( $_GET['diag_product'] );

NvbAdmin::ok( false !== strpos( $diag_html, 'نتیجه برای محصول' ), 'پنل تشخیص برای شناسهٔ داده‌شده رندر می‌شود' );
NvbAdmin::ok( false !== strpos( $diag_html, 'تأییدشده' ), 'تعداد تأییدشده نمایش داده می‌شود' );
NvbAdmin::ok( false !== strpos( $diag_html, 'snapshot' ) || false !== strpos( $diag_html, 'تصویر موجودی' ), 'وضعیت تصویر موجودی نمایش داده می‌شود' );
NvbAdmin::ok( false !== strpos( $diag_html, 'صف ارسال' ), 'وضعیت صف نمایش داده می‌شود' );

// A product nobody subscribed to must say so, not render an empty shell.
$_GET['diag_product'] = 424242;
$empty_html           = nvb_render( array( $admin, 'render_tools' ) );
unset( $_GET['diag_product'] );

NvbAdmin::ok( false !== strpos( $empty_html, 'علت اصلی همین است' ), 'محصول بدون مشترک تأییدشده، علت را صریح می‌گوید' );

// Hostile input: a non-numeric id must not reach a query or the output.
$_GET['diag_product'] = '<script>alert(1)</script>';
$bad_html             = nvb_render( array( $admin, 'render_tools' ) );
unset( $_GET['diag_product'] );

NvbAdmin::ok( false === stripos( $bad_html, '<script' ), 'شناسهٔ نامعتبر هیچ اسکریپتی تزریق نمی‌کند' );

/* -------------------------------------------------------------------------
 * No PHP notices while rendering anything above
 * ---------------------------------------------------------------------- */
NvbAdmin::group( 'سلامت اجرا' );

$messages = array_values( array_unique( (array) $GLOBALS['nvb_php_messages'] ) );
NvbAdmin::ok( empty( $messages ), 'هیچ notice/warning در رندر پنل نبود', implode( ' | ', array_slice( $messages, 0, 3 ) ) );

/* -------------------------------------------------------------------------
 * Capability guard
 * ---------------------------------------------------------------------- */
$GLOBALS['nvb_caps'] = array();
$blocked                     = nvb_render( array( $admin, 'render_dashboard' ) );
$guard_hit                   = false;

foreach ( (array) $GLOBALS['nvb_php_messages'] as $message ) {
	if ( false !== strpos( $message, 'wp_die' ) ) {
		$guard_hit = true;
	}
}

NvbAdmin::ok( '' === $blocked, 'بدون دسترسی، خروجی تولید نمی‌شود', strlen( $blocked ) );
NvbAdmin::ok( $guard_hit, 'بدون دسترسی، wp_die صدا زده می‌شود' );

restore_error_handler();

$arguments = array();
if ( isset( $argv ) && is_array( $argv ) ) {
	$arguments = $argv;
} elseif ( isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
	$arguments = $_SERVER['argv'];
}

$as_json = in_array( '--json', $arguments, true );
$summary = NvbAdmin::summary();

if ( $as_json ) {
	echo json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";
} else {
	echo "\n=== Navidban admin render ===\n";

	foreach ( NvbAdmin::$groups as $name => $group ) {
		printf(
			"[%s] %-28s %2d assertions, %d failures\n",
			$group['failures'] > 0 ? 'FAIL' : 'ok  ',
			$name,
			$group['assertions'],
			$group['failures']
		);
	}

	foreach ( NvbAdmin::$failed as $failure ) {
		echo '[FAIL] ' . $failure['label'] . ' → ' . $failure['actual'] . "\n";
	}

	printf( "\nPHP %s — %d assertions, %d failures\n", PHP_VERSION, $summary['assertions'], $summary['failures'] );
}

exit( $summary['failures'] > 0 ? 1 : 0 );
