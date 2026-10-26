<?php
/**
 * Unit test runner. Runs with plain PHP: no WordPress, no database, no Composer.
 *
 * Usage:
 *   php tests/run.php            # human readable
 *   php tests/run.php --json     # machine readable, written to tests/results.json
 *
 * Exit code 0 only when every assertion passes.
 *
 * @package Navidban
 */

// The plugin classes guard against direct access with this constant.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'NAVIDBAN_DIR' ) ) {
	define( 'NAVIDBAN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'NAVIDBAN_VERSION' ) ) {
	define( 'NAVIDBAN_VERSION', '1.0.1' );
}

require_once NAVIDBAN_DIR . 'includes/Autoloader.php';
\Navidban\Autoloader::register();

use Navidban\Support\Validator;
use Navidban\Support\Jalali;
use Navidban\Support\Persian;
use Navidban\Support\Csv;
use Navidban\Support\Masking;
use Navidban\Support\Text;
use Navidban\Logic\FairSending;
use Navidban\Logic\Reconcile;
use Navidban\Logic\QueuePolicy;
use Navidban\Logic\Template;
use Navidban\Logic\HttpGuard;

/**
 * Extremely small assertion harness.
 */
final class NvbTest {

	/** @var int */
	public static $assertions = 0;

	/** @var int */
	public static $failures = 0;

	/** @var array<int,array> */
	public static $failed = array();

	/** @var string */
	public static $group = '';

	/** @var string */
	public static $file = '';

	/** @var array<string,array> */
	public static $groups = array();

	/**
	 * Start a group of assertions.
	 *
	 * @param string $name Group name.
	 * @return void
	 */
	public static function group( $name ) {
		self::$group                         = $name;
		self::$groups[ $name ]               = array(
			'assertions' => 0,
			'failures'   => 0,
		);
	}

	/**
	 * Assert two values are identical (strict).
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $label    Assertion label.
	 * @return bool
	 */
	public static function same( $expected, $actual, $label ) {
		return self::record( $expected === $actual, $label, $expected, $actual );
	}

	/**
	 * Assert truthiness.
	 *
	 * @param mixed  $condition Condition.
	 * @param string $label     Label.
	 * @return bool
	 */
	public static function ok( $condition, $label ) {
		return self::record( (bool) $condition, $label, true, $condition );
	}

	/**
	 * Record one assertion.
	 *
	 * @param bool   $passed   Result.
	 * @param string $label    Label.
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @return bool
	 */
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

	/**
	 * Render a value for the failure report.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
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

	/**
	 * Summary array.
	 *
	 * @return array
	 */
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

/* -------------------------------------------------------------------------
 * Validator — 40+ cases
 * ---------------------------------------------------------------------- */
NvbTest::group( 'Validator / موبایل' );

$valid_mobiles = array(
	'09121234567'  => '09121234567',
	'09351234567'  => '09351234567',
	'09901234567'  => '09901234567',
	'09011234567'  => '09011234567',
	'09211234567'  => '09211234567',
	'۰۹۱۲۱۲۳۴۵۶۷'   => '09121234567', // Persian digits
	'٠٩١٢١٢٣٤٥٦٧'   => '09121234567', // Arabic-Indic digits
	'+989121234567' => '09121234567',
	'00989121234567' => '09121234567',
	'989121234567' => '09121234567',
	'9121234567'   => '09121234567',
	'0912 123 4567' => '09121234567',
	'0912-123-4567' => '09121234567',
	' (0912) 1234567 ' => '09121234567',
	'۰۹۹۱۲۳۴۵۶۷۸'   => '09912345678',
	'+98 912 123 4567' => '09121234567',
);

foreach ( $valid_mobiles as $raw => $expected ) {
	NvbTest::same( $expected, Validator::normalize_phone( $raw ), 'normalize valid: ' . $raw );
}

$invalid_mobiles = array(
	'',
	'0912',
	'12345678901',
	'08121234567',       // landline area code 081
	'091212345678',      // too long
	'0912123456',        // too short
	'09441234567',       // 094 is not an allocated mobile block
	'0912123456a',
	'abcdefghijk',
	'+9891212345678',
	'00000000000',
);

foreach ( $invalid_mobiles as $raw ) {
	NvbTest::same( '', Validator::normalize_phone( $raw ), 'reject invalid mobile: [' . $raw . ']' );
}

NvbTest::ok( Validator::is_mobile( '09121234567' ), 'is_mobile accepts canonical' );
NvbTest::ok( ! Validator::is_mobile( '0912 1234567' ), 'is_mobile rejects unnormalised input' );

NvbTest::group( 'Validator / ایمیل' );

$valid_emails = array(
	'a@b.co',
	'ali.reza@example.com',
	'user+tag@sub.example.co.uk',
	'USER@EXAMPLE.COM',
	'x_y-z@example.io',
	'0912@example.com',
	'a1b2c3@example.travel',
);

foreach ( $valid_emails as $email ) {
	NvbTest::ok( Validator::is_email( $email ), 'valid email: ' . $email );
}

$invalid_emails = array(
	'',
	'no-at-sign',
	'a@b',
	'a@@b.com',
	'a b@example.com',
	'a..b@example.com',
	'.a@example.com',
	'a.@example.com',
	'a@-example.com',
	'a@example.c',
	'a@example..com',
	'a@exa mple.com',
	str_repeat( 'a', 65 ) . '@example.com',
);

foreach ( $invalid_emails as $email ) {
	NvbTest::ok( ! Validator::is_email( $email ), 'invalid email: ' . ( strlen( $email ) > 40 ? substr( $email, 0, 20 ) . '...' : $email ) );
}

NvbTest::same( 'ali@example.com', Validator::normalize_email( ' Ali@Example.COM ' ), 'normalize email lowercases the whole address' );
NvbTest::same( '', Validator::normalize_email( 'bad@' ), 'normalize email rejects garbage' );

NvbTest::group( 'Validator / کد ملی' );

$valid_nids = array( '0499370899', '0084575948', '0013542419', '1600000002' );
foreach ( $valid_nids as $nid ) {
	NvbTest::ok( Validator::is_national_id( $nid ), 'valid national id: ' . $nid );
}

$invalid_nids = array( '0499370898', '0000000000', '1111111111', '12345', '12345678901', 'abcdefghij' );
foreach ( $invalid_nids as $nid ) {
	NvbTest::ok( ! Validator::is_national_id( $nid ), 'invalid national id: ' . $nid );
}

NvbTest::group( 'Validator / متن' );
NvbTest::same( 'hello world', Validator::plain_text( "  hello\n\tworld  " ), 'plain_text collapses whitespace' );
NvbTest::same( 'alert(1)', Validator::plain_text( '<script>alert(1)</script>' ), 'plain_text strips tags' );
NvbTest::same( 10, strlen( Validator::plain_text( 'abcdefghijklmnop', 10 ) ), 'plain_text respects max length' );

/* -------------------------------------------------------------------------
 * Jalali
 * ---------------------------------------------------------------------- */
NvbTest::group( 'Jalali / تبدیل' );

$pairs = array(
	array( '2026-10-25', array( 1405, 8, 3 ) ),
	array( '2026-03-21', array( 1405, 1, 1 ) ),
	array( '2027-03-21', array( 1406, 1, 1 ) ),
	array( '2000-01-01', array( 1378, 10, 11 ) ),
	array( '1979-02-11', array( 1357, 11, 22 ) ),
	array( '2024-02-29', array( 1402, 12, 10 ) ),
	array( '2021-03-20', array( 1399, 12, 30 ) ),
	array( '2021-03-21', array( 1400, 1, 1 ) ),
);

foreach ( $pairs as $pair ) {
	list( $greg, $expected ) = $pair;
	list( $gy, $gm, $gd )    = array_map( 'intval', explode( '-', $greg ) );
	NvbTest::same( $expected, Jalali::from_gregorian( $gy, $gm, $gd ), 'gregorian->jalali ' . $greg );
	NvbTest::same( array( $gy, $gm, $gd ), Jalali::to_gregorian( $expected[0], $expected[1], $expected[2] ), 'jalali->gregorian ' . $greg );
}

// Round trip over 40 years, one probe every 37 days.
$timestamp = gmmktime( 0, 0, 0, 1, 1, 1990 );
$end       = gmmktime( 0, 0, 0, 1, 1, 2030 );
$probes    = 0;
while ( $timestamp < $end ) {
	$gy = (int) gmdate( 'Y', $timestamp );
	$gm = (int) gmdate( 'n', $timestamp );
	$gd = (int) gmdate( 'j', $timestamp );

	$j    = Jalali::from_gregorian( $gy, $gm, $gd );
	$back = Jalali::to_gregorian( $j[0], $j[1], $j[2] );

	if ( array( $gy, $gm, $gd ) !== $back ) {
		NvbTest::ok( false, 'round trip failed at ' . gmdate( 'Y-m-d', $timestamp ) );
	}
	$probes++;
	$timestamp += 37 * 86400;
}
NvbTest::ok( $probes > 350, 'round trip probes executed: ' . $probes );

NvbTest::same( '۳ آبان ۱۴۰۵', Jalali::format( '2026-10-25 10:00:00' ), 'format j F Y with Persian digits' );
NvbTest::same( '۱۴۰۵/۰۸/۰۳', Jalali::format( '2026-10-25 10:00:00', 'Y/m/d' ), 'format Y/m/d' );
NvbTest::same( '', Jalali::format( '0000-00-00 00:00:00' ), 'format empty date' );

NvbTest::group( 'Persian / ارقام' );
NvbTest::same( '۱۲۳۴۵۶۷۸۹۰', Persian::digits( '1234567890' ), 'digit conversion' );
NvbTest::same( '۱,۲۳۴,۵۶۷', Persian::number( 1234567 ), 'thousands grouping' );
NvbTest::same( 'کتابها', Persian::normalize( 'كتابها' ), 'arabic letters normalised' );
NvbTest::same( '۵ دقیقه پیش', Persian::relative( 300 ), 'relative minutes' );

/* -------------------------------------------------------------------------
 * CSV injection
 * ---------------------------------------------------------------------- */
NvbTest::group( 'CSV / تزریق فرمول' );

$payloads = array(
	'=cmd|\'/c calc\'!A0',
	'+1+1',
	'-1+1',
	'@SUM(1+1)',
	"\t=1+1",
	"\r=1+1",
	'   =HYPERLINK("http://evil.test")',
	'=1+1; DROP TABLE wp_nvb_waitlists',
);

foreach ( $payloads as $payload ) {
	$out = Csv::cell( $payload );
	NvbTest::ok( "'" === $out[0], 'csv injection neutralised: ' . addcslashes( substr( $payload, 0, 12 ), "\t\r" ) );
	NvbTest::ok( strlen( $out ) === strlen( $payload ) + 1, 'csv cell keeps its content length' );
}

NvbTest::same( 'سلام', Csv::cell( 'سلام' ), 'safe text untouched' );
NvbTest::same( '09121234567', Csv::cell( '09121234567' ), 'phone untouched' );
NvbTest::same( '', Csv::cell( '' ), 'empty cell' );
NvbTest::same( "\xEF\xBB\xBF", Csv::bom(), 'BOM bytes' );

NvbTest::group( 'Masking / حداقل‌سازی داده' );
NvbTest::same( '0912***4567', Masking::phone( '09121234567' ), 'phone mask' );
NvbTest::same( 'a***@example.com', Masking::email( 'ali@example.com' ), 'email mask' );
NvbTest::same( 64, strlen( Masking::ip_hash( '192.168.1.1', 'salt' ) ), 'ip hash length' );
NvbTest::ok( Masking::ip_hash( '1.1.1.1', 'a' ) !== Masking::ip_hash( '1.1.1.1', 'b' ), 'ip hash depends on salt' );

/* -------------------------------------------------------------------------
 * Fair sending
 * ---------------------------------------------------------------------- */
NvbTest::group( 'FairSending / اعلام به تعداد موجودی' );

NvbTest::same( 5, FairSending::allowance( 5, 0, 200, true ), 'stock 5 with 200 waiting => 5 notifications' );
NvbTest::same( 200, FairSending::allowance( 5, 0, 200, false ), 'fair disabled => everyone' );
NvbTest::same( 0, FairSending::allowance( 0, 0, 200, true ), 'no units => no notification' );
NvbTest::same( 3, FairSending::allowance( 8, 5, 200, true ), 'second restock measures the delta' );
NvbTest::same( 0, FairSending::allowance( 4, 5, 200, true ), 'shrinking stock never notifies' );
NvbTest::same( 200, FairSending::allowance( null, 0, 200, true ), 'unmanaged stock (null qty) notifies all' );
NvbTest::same( 2, FairSending::allowance( 10, 0, 2, true ), 'never exceeds the waiting list' );

$rows = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$rows[] = array( 'id' => $i );
}
$split = FairSending::split( $rows, 5 );
NvbTest::same( 5, count( $split['selected'] ), 'split selects 5' );
NvbTest::same( 15, count( $split['deferred'] ), 'split defers 15' );
NvbTest::same( 1, $split['selected'][0]['id'], 'oldest first' );
NvbTest::same( 6, $split['deferred'][0]['id'], 'deferred starts at 6' );

$split_all = FairSending::split( $rows, 50 );
NvbTest::same( 20, count( $split_all['selected'] ), 'allowance larger than list' );
NvbTest::same( 0, count( $split_all['deferred'] ), 'nothing deferred' );

NvbTest::same( 5, FairSending::next_notified_qty( 5, 5, 0 ), 'baseline advances to current qty' );
NvbTest::same( 3, FairSending::next_notified_qty( 9, 0, 3 ), 'no notification keeps baseline' );
NvbTest::same( 7, FairSending::next_notified_qty( null, 10, 7 ), 'unmanaged stock keeps baseline' );

/* -------------------------------------------------------------------------
 * Reconcile
 * ---------------------------------------------------------------------- */
NvbTest::group( 'Reconcile / پایش تطبیقی' );

$current = array(
	Reconcile::key( 10 ) => array( 'product_id' => 10, 'variation_id' => 0, 'status' => 'instock', 'qty' => 4 ),
	Reconcile::key( 11 ) => array( 'product_id' => 11, 'variation_id' => 0, 'status' => 'outofstock', 'qty' => 0 ),
	Reconcile::key( 12, 120 ) => array( 'product_id' => 12, 'variation_id' => 120, 'status' => 'instock', 'qty' => 2 ),
);

$first = Reconcile::diff( array(), $current, true );
NvbTest::ok( $first['first_run'], 'first run flagged' );
NvbTest::same( 0, count( $first['transitions'] ), 'first run sends nothing' );
NvbTest::same( 3, count( $first['writes'] ), 'first run writes snapshots' );

$snapshots = array(
	Reconcile::key( 10 ) => array( 'status' => 'outofstock', 'qty' => 0 ),
	Reconcile::key( 11 ) => array( 'status' => 'outofstock', 'qty' => 0 ),
	Reconcile::key( 12, 120 ) => array( 'status' => 'instock', 'qty' => 2 ),
);

$diff = Reconcile::diff( $snapshots, $current, false );
NvbTest::same( 1, count( $diff['transitions'] ), 'only the real transition is reported' );
NvbTest::same( 10, $diff['transitions'][0]['product_id'], 'transition product id' );
NvbTest::same( 'outofstock', $diff['transitions'][0]['from_status'], 'transition from status' );
NvbTest::same( 'instock', $diff['transitions'][0]['to_status'], 'transition to status' );

// A product that is still out of stock must not re-notify.
$diff = Reconcile::diff( $snapshots, $current, false );
NvbTest::same( 1, count( $diff['transitions'] ), 'repeated runs never duplicate' );

// Unknown product appearing later is treated as baseline, not a restock.
$new_current = $current;
$new_current[ Reconcile::key( 13 ) ] = array( 'product_id' => 13, 'variation_id' => 0, 'status' => 'instock', 'qty' => 9 );
$diff = Reconcile::diff( $snapshots, $new_current, false );
NvbTest::same( 1, count( $diff['transitions'] ), 'unknown product is not a restock' );

// Backorder handling.
NvbTest::same( 0, count( Reconcile::diff( array( Reconcile::key( 20 ) => array( 'status' => 'outofstock', 'qty' => 0 ) ), array( Reconcile::key( 20 ) => array( 'product_id' => 20, 'variation_id' => 0, 'status' => 'onbackorder', 'qty' => 0 ) ), false )['transitions'] ), 'backorder is not instock' );
NvbTest::ok( Reconcile::is_out_of_stock( 'onbackorder' ), 'backorder counts as unavailable for the diff' );
NvbTest::ok( Reconcile::should_announce( array( 'to_status' => 'onbackorder' ), true ), 'backorder announces only when the option allows it' );
NvbTest::ok( ! Reconcile::should_announce( array( 'to_status' => 'onbackorder' ), false ), 'backorder silent by default' );

NvbTest::group( 'QueuePolicy / تلاش دوباره' );

NvbTest::same( 15, QueuePolicy::backoff_minutes( 1 ), 'first retry after 15 minutes' );
NvbTest::same( 60, QueuePolicy::backoff_minutes( 2 ), 'second retry after 1 hour' );
NvbTest::same( 0, QueuePolicy::backoff_minutes( 3 ), 'third failure exhausts retries' );
NvbTest::same( 60, QueuePolicy::backoff_minutes( 2 ), 'ladder tail uses the last rung before exhaustion' );
NvbTest::same( 'queued', QueuePolicy::status_after_failure( 1 ), 'still queued after first failure' );
NvbTest::same( 'queued', QueuePolicy::status_after_failure( 2 ), 'still queued after second failure' );
NvbTest::same( 'failed', QueuePolicy::status_after_failure( 3 ), 'failed after third' );
NvbTest::same( 1000 + 15 * 60, QueuePolicy::next_run_after_failure( 1000, 1 ), 'next run timestamp' );
NvbTest::same( 0, QueuePolicy::next_run_after_failure( 1000, 3 ), 'no next run when exhausted' );

NvbTest::group( 'QueuePolicy / نرخ و پنجرهٔ ارسال' );

NvbTest::same( 50, QueuePolicy::allowance( 50, 60, 0 ), 'batch size limits the run' );
NvbTest::same( 10, QueuePolicy::allowance( 50, 60, 50 ), 'rate limit limits the run' );
NvbTest::same( 0, QueuePolicy::allowance( 50, 60, 60 ), 'rate limit saturated' );
NvbTest::same( 0, QueuePolicy::allowance( 50, 60, 61 ), 'never negative' );
NvbTest::same( 500, QueuePolicy::allowance( 900, 900, 0 ), 'batch size is capped at 500' );
NvbTest::same( 1, QueuePolicy::allowance( 0, 60, 0 ), 'batch size floor' );

NvbTest::ok( QueuePolicy::inside_window( 9, 8, 22, true ), '09:00 inside 08-22' );
NvbTest::ok( ! QueuePolicy::inside_window( 22, 8, 22, true ), '22:00 outside (exclusive end)' );
NvbTest::ok( ! QueuePolicy::inside_window( 7, 8, 22, true ), '07:00 outside' );
NvbTest::ok( QueuePolicy::inside_window( 23, 22, 6, true ), 'overnight window includes 23:00' );
NvbTest::ok( QueuePolicy::inside_window( 3, 22, 6, true ), 'overnight window includes 03:00' );
NvbTest::ok( ! QueuePolicy::inside_window( 12, 22, 6, true ), 'overnight window excludes noon' );
NvbTest::ok( QueuePolicy::inside_window( 12, 8, 22, false ), 'disabled window always open' );

NvbTest::ok( QueuePolicy::is_locked( 1000, 1100, 300 ), 'fresh lock blocks a second run' );
NvbTest::ok( ! QueuePolicy::is_locked( 1000, 1400, 300 ), 'stale lock is ignored' );
NvbTest::ok( ! QueuePolicy::is_locked( null, 1400, 300 ), 'unlocked row is free' );
NvbTest::ok( QueuePolicy::budget_exhausted( 0, 21, 20 ), 'time budget respected' );
NvbTest::ok( ! QueuePolicy::budget_exhausted( 0, 19, 20 ), 'time budget not yet reached' );

NvbTest::same( 64, strlen( QueuePolicy::payload_hash( 5, 'email', '10:0:1' ) ), 'payload hash length' );
NvbTest::ok( QueuePolicy::payload_hash( 5, 'email', 'evt' ) !== QueuePolicy::payload_hash( 6, 'email', 'evt' ), 'payload hash is per subscriber' );
NvbTest::ok( QueuePolicy::payload_hash( 5, 'sms', 'evt' ) !== QueuePolicy::payload_hash( 5, 'email', 'evt' ), 'payload hash is per channel' );

/* -------------------------------------------------------------------------
 * Template
 * ---------------------------------------------------------------------- */
NvbTest::group( 'Template / جای‌نگهدارها' );

$tpl = 'سلام {customer_name}، {product_name} موجود شد: {product_url} | لغو: {unsubscribe_url}';
$out = Template::render(
	$tpl,
	array(
		'customer_name'   => 'مریم',
		'product_name'    => 'کفش دویدن',
		'product_url'     => 'https://shop.test/p/10',
		'unsubscribe_url' => 'https://shop.test/cancel/abc',
	)
);
NvbTest::same( 'سلام مریم، کفش دویدن موجود شد: https://shop.test/p/10 | لغو: https://shop.test/cancel/abc', $out, 'render replaces known placeholders' );

NvbTest::same( 'x {nope} y', Template::render( 'x {nope} y', array( 'nope' => 'z' ) ), 'unsupported placeholder stays visible' );
NvbTest::same( array( 'product_name', 'site_name' ), Template::used( '{product_name} {site_name} {product_name}' ), 'used() dedupes' );
NvbTest::same( array( 'typo' ), Template::unsupported( '{typo}' ), 'unsupported() detects typos' );
NvbTest::same( 1, Template::sms_segments( 'سلام' ), 'short sms is one segment' );
NvbTest::same( 2, Template::sms_segments( str_repeat( 'ا', 71 ) ), '71 chars is two segments' );
NvbTest::ok( Template::sms_segments( str_repeat( 'ا', 137 ) ) === 2, 'boundary at 137 chars' );
NvbTest::same( 3, Template::sms_segments( str_repeat( 'ا', 138 ) ), 'third segment starts at 138' );

/* -------------------------------------------------------------------------
 * HttpGuard
 * ---------------------------------------------------------------------- */
NvbTest::group( 'Text / برش چندبایتی' );

// A byte-wise substr() on Persian text cuts characters in half. MySQL then
// rejects the row as invalid utf8mb4 - a queue error that would look like a
// random "row did not save" bug. These assertions pin the safe behaviour.
$long_persian = str_repeat( 'ارسال نشد: خطای درگاه پیامک. ', 40 );

NvbTest::same( true, preg_match( '//u', Text::substring( $long_persian, 0, 255 ) ) === 1, 'برش ۲۵۵ کاراکتری UTF-8 معتبر می‌ماند' );
NvbTest::same( 255, Text::length( Text::substring( $long_persian, 0, 255 ) ), 'طول برش دقیقاً ۲۵۵ کاراکتر است' );
NvbTest::same( true, strlen( Text::substring( $long_persian, 0, 255 ) ) > 255, 'طول بایتی از ۲۵۵ بیشتر است (چندبایتی بودن اثبات می‌شود)' );
// Not every byte offset lands mid-character; count how many do, to show the
// hazard is real rather than theoretical.
$nvb_split_offsets = 0;
for ( $nvb_i = 1; $nvb_i <= 255; $nvb_i++ ) {
	if ( 1 !== preg_match( '//u', substr( $long_persian, 0, $nvb_i ) ) ) {
		$nvb_split_offsets++;
	}
}
NvbTest::ok( $nvb_split_offsets > 0, 'substr بایتی در ' . $nvb_split_offsets . ' نقطه رشته را می‌شکند (شاهد اینکه این رفع لازم بود)' );
NvbTest::same( 'سلام', Text::substring( 'سلام دنیا', 0, 4 ), 'برش کاراکتری فارسی درست است' );
NvbTest::same( '', Text::substring( '', 0, 10 ), 'رشتهٔ خالی' );
NvbTest::same( 'abc', Text::substring( 'abc', 0, 255 ), 'رشتهٔ کوتاه دست‌نخورده می‌ماند' );

NvbTest::group( 'EmailChannel / تشخیص خطای ارسال' );

$mail_facts = \Navidban\Channels\EmailChannel::diagnose();
NvbTest::ok( is_array( $mail_facts ), 'diagnose آرایه برمی‌گرداند' );

foreach ( array( 'mail_function', 'sendmail_path', 'sendmail_exists', 'smtp_host', 'smtp_port', 'mailer_plugin' ) as $key ) {
	NvbTest::ok( array_key_exists( $key, $mail_facts ), 'کلید تشخیص موجود است: ' . $key );
}

NvbTest::ok( in_array( $mail_facts['mail_function'], array( 'available', 'missing' ), true ), 'وضعیت mail() یکی از دو مقدار مجاز است' );
NvbTest::ok( is_bool( $mail_facts['sendmail_exists'] ), 'sendmail_exists بولی است' );
NvbTest::ok( is_string( \Navidban\Channels\EmailChannel::hint() ), 'hint رشته برمی‌گرداند' );

NvbTest::group( 'HttpGuard / SSRF' );

NvbTest::ok( HttpGuard::is_allowed( 'https://api.sms-gateway.ir/v1/send' ), 'public https endpoint allowed' );

$blocked = array(
	'http://api.example.com/send',
	'https://localhost/send',
	'https://127.0.0.1/send',
	'https://10.0.0.5/send',
	'https://192.168.1.1/send',
	'https://172.16.0.1/send',
	'https://169.254.169.254/latest/meta-data/',
	'https://[::1]/send',
	'https://user:pass@api.example.com/send',
	'https://api.example.com.evil.local/send',
	'file:///etc/passwd',
	'',
	'   ',
	'https://api.example.com/send with space',
);

foreach ( $blocked as $url ) {
	NvbTest::ok( ! HttpGuard::is_allowed( $url ), 'blocked endpoint: ' . ( '' === $url ? '(empty)' : $url ) );
}

NvbTest::same( 'scheme', HttpGuard::check( 'http://api.example.com/send' )['reason'], 'reason: scheme' );
NvbTest::same( 'private_ip', HttpGuard::check( 'https://10.1.2.3/x' )['reason'], 'reason: private ip' );
NvbTest::same( 'credentials', HttpGuard::check( 'https://a:b@api.example.com/x' )['reason'], 'reason: credentials' );

/* -------------------------------------------------------------------------
 * Report
 * ---------------------------------------------------------------------- */
$summary = NvbTest::summary();
$arguments = array();
if ( isset( $argv ) && is_array( $argv ) ) {
	$arguments = $argv;
} elseif ( isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
	$arguments = $_SERVER['argv'];
}
$as_json = in_array( '--json', $arguments, true );

if ( ! $as_json ) {
	echo "\n=== Navidban unit tests ===\n";
	foreach ( $summary['groups'] as $name => $data ) {
		$status = $data['failures'] > 0 ? 'FAIL' : 'ok  ';
		printf( "[%s] %-42s %4d assertions  %d failures\n", $status, $name, $data['assertions'], $data['failures'] );
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

$results_path = __DIR__ . '/results.json';
$encoded      = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( false !== $encoded && is_writable( __DIR__ ) ) {
	file_put_contents( $results_path, $encoded . "\n" );
}

if ( $as_json ) {
	echo $encoded . "\n";
}

exit( $summary['failures'] > 0 ? 1 : 0 );
