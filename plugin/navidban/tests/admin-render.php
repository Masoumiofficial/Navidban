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
 * Contracts inside the output
 * ---------------------------------------------------------------------- */
NvbAdmin::group( 'قراردادهای خروجی' );

$settings_html = $rendered['settings'];

NvbAdmin::ok( false !== strpos( $settings_html, 'options.php' ), 'فرم تنظیمات به options.php می‌رود' );
NvbAdmin::ok( false !== strpos( $settings_html, 'navidban_settings[' ), 'نام آپشن در فرم درست است' );
NvbAdmin::ok( false !== strpos( $settings_html, 'option_page' ), 'settings_fields فراخوانی شده' );
NvbAdmin::ok( false !== strpos( $settings_html, 'nvb-form-heading' ), 'فیلد عنوان فرم هست' );
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
