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
 * @package Mojoodyar
 */

// The plugin classes guard against direct access with this constant.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'MOJODYAR_DIR' ) ) {
	define( 'MOJODYAR_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MOJODYAR_VERSION' ) ) {
	// Read the version from the plugin header: a test report must never claim a
	// version the shipped file does not declare.
	$mjy_header       = (string) @file_get_contents( dirname( __DIR__ ) . '/mojoodyar.php' );
	$mjy_header_match = array();
	define(
		'MOJODYAR_VERSION',
		preg_match( '~^[ \t/*#@]*Version:\s*([0-9][0-9.\-a-zA-Z]*)~mi', $mjy_header, $mjy_header_match ) ? trim( $mjy_header_match[1] ) : '0.0.0'
	);
}

require_once MOJODYAR_DIR . 'includes/Autoloader.php';
\Mojoodyar\Autoloader::register();

use Mojoodyar\Support\Validator;
use Mojoodyar\Support\Jalali;
use Mojoodyar\Support\Persian;
use Mojoodyar\Support\Csv;
use Mojoodyar\Support\Masking;
use Mojoodyar\Support\Text;
use Mojoodyar\Logic\FairSending;
use Mojoodyar\Logic\Reconcile;
use Mojoodyar\Logic\QueuePolicy;
use Mojoodyar\Logic\LogExplain;
use Mojoodyar\Logic\StatsMath;
use Mojoodyar\Logic\Template;
use Mojoodyar\Logic\HttpGuard;
use Mojoodyar\Logic\EmailSkin;
use Mojoodyar\Logic\SmsPattern;

/**
 * Extremely small assertion harness.
 */
final class MjyTest {

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

/* -------------------------------------------------------------------------
 * Validator — 40+ cases
 * ---------------------------------------------------------------------- */
MjyTest::group( 'Validator / موبایل' );

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
	MjyTest::same( $expected, Validator::normalize_phone( $raw ), 'normalize valid: ' . $raw );
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
	MjyTest::same( '', Validator::normalize_phone( $raw ), 'reject invalid mobile: [' . $raw . ']' );
}

MjyTest::ok( Validator::is_mobile( '09121234567' ), 'is_mobile accepts canonical' );
MjyTest::ok( ! Validator::is_mobile( '0912 1234567' ), 'is_mobile rejects unnormalised input' );

MjyTest::group( 'Validator / ایمیل' );

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
	MjyTest::ok( Validator::is_email( $email ), 'valid email: ' . $email );
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
	MjyTest::ok( ! Validator::is_email( $email ), 'invalid email: ' . ( strlen( $email ) > 40 ? substr( $email, 0, 20 ) . '...' : $email ) );
}

MjyTest::same( 'ali@example.com', Validator::normalize_email( ' Ali@Example.COM ' ), 'normalize email lowercases the whole address' );
MjyTest::same( '', Validator::normalize_email( 'bad@' ), 'normalize email rejects garbage' );

MjyTest::group( 'Validator / کد ملی' );

$valid_nids = array( '0499370899', '0084575948', '0013542419', '1600000002' );
foreach ( $valid_nids as $nid ) {
	MjyTest::ok( Validator::is_national_id( $nid ), 'valid national id: ' . $nid );
}

$invalid_nids = array( '0499370898', '0000000000', '1111111111', '12345', '12345678901', 'abcdefghij' );
foreach ( $invalid_nids as $nid ) {
	MjyTest::ok( ! Validator::is_national_id( $nid ), 'invalid national id: ' . $nid );
}

MjyTest::group( 'Validator / متن' );
MjyTest::same( 'hello world', Validator::plain_text( "  hello\n\tworld  " ), 'plain_text collapses whitespace' );
MjyTest::same( 'alert(1)', Validator::plain_text( '<script>alert(1)</script>' ), 'plain_text strips tags' );
MjyTest::same( 10, strlen( Validator::plain_text( 'abcdefghijklmnop', 10 ) ), 'plain_text respects max length' );

MjyTest::group( 'Validator / نام و نام خانوادگی' );

MjyTest::same( 'مریم احمدی', Validator::person_name( '  مریم   احمدی  ' ), 'person_name trims and collapses spaces' );
MjyTest::same( 'نیم‌فاصله', Validator::person_name( 'نیم‌فاصله' ), 'ZWNJ (نیم‌فاصله) survives' );
MjyTest::same( 'علی-رضا', Validator::person_name( 'علی-رضا' ), 'hyphen survives' );
MjyTest::same( 'Maryam Ahmadi', Validator::person_name( ' Maryam  Ahmadi ' ), 'latin names survive' );
MjyTest::same( 'مریم احمدی', Validator::person_name( '<b>مریم</b> احمدی' ), 'tags are stripped' );
MjyTest::same( 'مریم', Validator::person_name( 'مریم 09121234567' ), 'digits are dropped' );
$mjy_formula = Validator::person_name( '=cmd|calc' );
MjyTest::same( 'cmd calc', $mjy_formula, 'formula characters are dropped, letters kept' );
MjyTest::ok( ! in_array( Text::substring( $mjy_formula, 0, 1 ), array( '=', '+', '-', '@', '|' ), true ), 'name never starts with a formula trigger' );
MjyTest::same( '', Validator::person_name( '09121234567' ), 'a phone number is not a name' );
MjyTest::same( '', Validator::person_name( '   ' ), 'blank input is empty' );
MjyTest::same( '', Validator::person_name( 'x' ), 'a single character is rejected' );
MjyTest::same( '', Validator::person_name( '!!!' ), 'punctuation only is rejected' );
MjyTest::same( 120, Text::length( Validator::person_name( str_repeat( 'ا', 200 ) ) ), 'length is capped at 120' );
MjyTest::same( 'مریم احمدی', Validator::person_name( '«مریم» احمدی' ), 'quotes are dropped, letters kept' );

/* -------------------------------------------------------------------------
 * Jalali
 * ---------------------------------------------------------------------- */
MjyTest::group( 'Jalali / تبدیل' );

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
	MjyTest::same( $expected, Jalali::from_gregorian( $gy, $gm, $gd ), 'gregorian->jalali ' . $greg );
	MjyTest::same( array( $gy, $gm, $gd ), Jalali::to_gregorian( $expected[0], $expected[1], $expected[2] ), 'jalali->gregorian ' . $greg );
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
		MjyTest::ok( false, 'round trip failed at ' . gmdate( 'Y-m-d', $timestamp ) );
	}
	$probes++;
	$timestamp += 37 * 86400;
}
MjyTest::ok( $probes > 350, 'round trip probes executed: ' . $probes );

MjyTest::same( '۳ آبان ۱۴۰۵', Jalali::format( '2026-10-25 10:00:00' ), 'format j F Y with Persian digits' );
MjyTest::same( '۱۴۰۵/۰۸/۰۳', Jalali::format( '2026-10-25 10:00:00', 'Y/m/d' ), 'format Y/m/d' );
MjyTest::same( '', Jalali::format( '0000-00-00 00:00:00' ), 'format empty date' );

MjyTest::group( 'Persian / ارقام' );
MjyTest::same( '۱۲۳۴۵۶۷۸۹۰', Persian::digits( '1234567890' ), 'digit conversion' );
MjyTest::same( '۱,۲۳۴,۵۶۷', Persian::number( 1234567 ), 'thousands grouping' );
MjyTest::same( 'کتابها', Persian::normalize( 'كتابها' ), 'arabic letters normalised' );
MjyTest::same( '۵ دقیقه پیش', Persian::relative( 300 ), 'relative minutes' );

/* -------------------------------------------------------------------------
 * CSV injection
 * ---------------------------------------------------------------------- */
MjyTest::group( 'CSV / تزریق فرمول' );

$payloads = array(
	'=cmd|\'/c calc\'!A0',
	'+1+1',
	'-1+1',
	'@SUM(1+1)',
	"\t=1+1",
	"\r=1+1",
	'   =HYPERLINK("http://evil.test")',
	'=1+1; DROP TABLE wp_mjy_waitlists',
);

foreach ( $payloads as $payload ) {
	$out = Csv::cell( $payload );
	MjyTest::ok( "'" === $out[0], 'csv injection neutralised: ' . addcslashes( substr( $payload, 0, 12 ), "\t\r" ) );
	MjyTest::ok( strlen( $out ) === strlen( $payload ) + 1, 'csv cell keeps its content length' );
}

MjyTest::same( 'سلام', Csv::cell( 'سلام' ), 'safe text untouched' );
MjyTest::same( '09121234567', Csv::cell( '09121234567' ), 'phone untouched' );
MjyTest::same( '', Csv::cell( '' ), 'empty cell' );
MjyTest::same( "\xEF\xBB\xBF", Csv::bom(), 'BOM bytes' );

MjyTest::group( 'Masking / حداقل‌سازی داده' );
MjyTest::same( '0912***4567', Masking::phone( '09121234567' ), 'phone mask' );
MjyTest::same( 'a***@example.com', Masking::email( 'ali@example.com' ), 'email mask' );
MjyTest::same( 64, strlen( Masking::ip_hash( '192.168.1.1', 'salt' ) ), 'ip hash length' );
MjyTest::ok( Masking::ip_hash( '1.1.1.1', 'a' ) !== Masking::ip_hash( '1.1.1.1', 'b' ), 'ip hash depends on salt' );

/* -------------------------------------------------------------------------
 * Fair sending
 * ---------------------------------------------------------------------- */
MjyTest::group( 'FairSending / اعلام به تعداد موجودی' );

MjyTest::same( 5, FairSending::allowance( 5, 0, 200, true ), 'stock 5 with 200 waiting => 5 notifications' );
MjyTest::same( 200, FairSending::allowance( 5, 0, 200, false ), 'fair disabled => everyone' );
MjyTest::same( 0, FairSending::allowance( 0, 0, 200, true ), 'no units => no notification' );
MjyTest::same( 3, FairSending::allowance( 8, 5, 200, true ), 'second restock measures the delta' );
MjyTest::same( 0, FairSending::allowance( 4, 5, 200, true ), 'shrinking stock never notifies' );
MjyTest::same( 200, FairSending::allowance( null, 0, 200, true ), 'unmanaged stock (null qty) notifies all' );
MjyTest::same( 2, FairSending::allowance( 10, 0, 2, true ), 'never exceeds the waiting list' );

$rows = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$rows[] = array( 'id' => $i );
}
$split = FairSending::split( $rows, 5 );
MjyTest::same( 5, count( $split['selected'] ), 'split selects 5' );
MjyTest::same( 15, count( $split['deferred'] ), 'split defers 15' );
MjyTest::same( 1, $split['selected'][0]['id'], 'oldest first' );
MjyTest::same( 6, $split['deferred'][0]['id'], 'deferred starts at 6' );

$split_all = FairSending::split( $rows, 50 );
MjyTest::same( 20, count( $split_all['selected'] ), 'allowance larger than list' );
MjyTest::same( 0, count( $split_all['deferred'] ), 'nothing deferred' );

MjyTest::same( 5, FairSending::next_notified_qty( 5, 5, 0 ), 'baseline advances to current qty' );
MjyTest::same( 3, FairSending::next_notified_qty( 9, 0, 3 ), 'no notification keeps baseline' );
MjyTest::same( 7, FairSending::next_notified_qty( null, 10, 7 ), 'unmanaged stock keeps baseline' );

/* -------------------------------------------------------------------------
 * Reconcile
 * ---------------------------------------------------------------------- */
MjyTest::group( 'Reconcile / پایش تطبیقی' );

$current = array(
	Reconcile::key( 10 ) => array( 'product_id' => 10, 'variation_id' => 0, 'status' => 'instock', 'qty' => 4 ),
	Reconcile::key( 11 ) => array( 'product_id' => 11, 'variation_id' => 0, 'status' => 'outofstock', 'qty' => 0 ),
	Reconcile::key( 12, 120 ) => array( 'product_id' => 12, 'variation_id' => 120, 'status' => 'instock', 'qty' => 2 ),
);

$first = Reconcile::diff( array(), $current, true );
MjyTest::ok( $first['first_run'], 'first run flagged' );
MjyTest::same( 0, count( $first['transitions'] ), 'first run sends nothing' );
MjyTest::same( 3, count( $first['writes'] ), 'first run writes snapshots' );

$snapshots = array(
	Reconcile::key( 10 ) => array( 'status' => 'outofstock', 'qty' => 0 ),
	Reconcile::key( 11 ) => array( 'status' => 'outofstock', 'qty' => 0 ),
	Reconcile::key( 12, 120 ) => array( 'status' => 'instock', 'qty' => 2 ),
);

$diff = Reconcile::diff( $snapshots, $current, false );
MjyTest::same( 1, count( $diff['transitions'] ), 'only the real transition is reported' );
MjyTest::same( 10, $diff['transitions'][0]['product_id'], 'transition product id' );
MjyTest::same( 'outofstock', $diff['transitions'][0]['from_status'], 'transition from status' );
MjyTest::same( 'instock', $diff['transitions'][0]['to_status'], 'transition to status' );

// A product that is still out of stock must not re-notify.
$diff = Reconcile::diff( $snapshots, $current, false );
MjyTest::same( 1, count( $diff['transitions'] ), 'repeated runs never duplicate' );

// Unknown product appearing later is treated as baseline, not a restock.
$new_current = $current;
$new_current[ Reconcile::key( 13 ) ] = array( 'product_id' => 13, 'variation_id' => 0, 'status' => 'instock', 'qty' => 9 );
$diff = Reconcile::diff( $snapshots, $new_current, false );
MjyTest::same( 1, count( $diff['transitions'] ), 'unknown product is not a restock' );

// Backorder handling.
MjyTest::same( 0, count( Reconcile::diff( array( Reconcile::key( 20 ) => array( 'status' => 'outofstock', 'qty' => 0 ) ), array( Reconcile::key( 20 ) => array( 'product_id' => 20, 'variation_id' => 0, 'status' => 'onbackorder', 'qty' => 0 ) ), false )['transitions'] ), 'backorder is not instock' );
MjyTest::ok( Reconcile::is_out_of_stock( 'onbackorder' ), 'backorder counts as unavailable for the diff' );
MjyTest::ok( Reconcile::should_announce( array( 'to_status' => 'onbackorder' ), true ), 'backorder announces only when the option allows it' );
MjyTest::ok( ! Reconcile::should_announce( array( 'to_status' => 'onbackorder' ), false ), 'backorder silent by default' );

MjyTest::group( 'QueuePolicy / تلاش دوباره' );

MjyTest::same( 15, QueuePolicy::backoff_minutes( 1 ), 'first retry after 15 minutes' );
MjyTest::same( 60, QueuePolicy::backoff_minutes( 2 ), 'second retry after 1 hour' );
MjyTest::same( 0, QueuePolicy::backoff_minutes( 3 ), 'third failure exhausts retries' );
MjyTest::same( 60, QueuePolicy::backoff_minutes( 2 ), 'ladder tail uses the last rung before exhaustion' );
MjyTest::same( 'queued', QueuePolicy::status_after_failure( 1 ), 'still queued after first failure' );
MjyTest::same( 'queued', QueuePolicy::status_after_failure( 2 ), 'still queued after second failure' );
MjyTest::same( 'failed', QueuePolicy::status_after_failure( 3 ), 'failed after third' );
MjyTest::same( 1000 + 15 * 60, QueuePolicy::next_run_after_failure( 1000, 1 ), 'next run timestamp' );
MjyTest::same( 0, QueuePolicy::next_run_after_failure( 1000, 3 ), 'no next run when exhausted' );

MjyTest::group( 'QueuePolicy / نرخ و پنجرهٔ ارسال' );

MjyTest::same( 50, QueuePolicy::allowance( 50, 60, 0 ), 'batch size limits the run' );
MjyTest::same( 10, QueuePolicy::allowance( 50, 60, 50 ), 'rate limit limits the run' );
MjyTest::same( 0, QueuePolicy::allowance( 50, 60, 60 ), 'rate limit saturated' );
MjyTest::same( 0, QueuePolicy::allowance( 50, 60, 61 ), 'never negative' );
MjyTest::same( 500, QueuePolicy::allowance( 900, 900, 0 ), 'batch size is capped at 500' );
MjyTest::same( 1, QueuePolicy::allowance( 0, 60, 0 ), 'batch size floor' );

MjyTest::ok( QueuePolicy::inside_window( 9, 8, 22, true ), '09:00 inside 08-22' );
MjyTest::ok( ! QueuePolicy::inside_window( 22, 8, 22, true ), '22:00 outside (exclusive end)' );
MjyTest::ok( ! QueuePolicy::inside_window( 7, 8, 22, true ), '07:00 outside' );
MjyTest::ok( QueuePolicy::inside_window( 23, 22, 6, true ), 'overnight window includes 23:00' );
MjyTest::ok( QueuePolicy::inside_window( 3, 22, 6, true ), 'overnight window includes 03:00' );
MjyTest::ok( ! QueuePolicy::inside_window( 12, 22, 6, true ), 'overnight window excludes noon' );
MjyTest::ok( QueuePolicy::inside_window( 12, 8, 22, false ), 'disabled window always open' );

MjyTest::ok( QueuePolicy::is_locked( 1000, 1100, 300 ), 'fresh lock blocks a second run' );
MjyTest::ok( ! QueuePolicy::is_locked( 1000, 1400, 300 ), 'stale lock is ignored' );
MjyTest::ok( ! QueuePolicy::is_locked( null, 1400, 300 ), 'unlocked row is free' );
MjyTest::ok( QueuePolicy::budget_exhausted( 0, 21, 20 ), 'time budget respected' );
MjyTest::ok( ! QueuePolicy::budget_exhausted( 0, 19, 20 ), 'time budget not yet reached' );

MjyTest::same( 64, strlen( QueuePolicy::payload_hash( 5, 'email', '10:0:1' ) ), 'payload hash length' );
MjyTest::ok( QueuePolicy::payload_hash( 5, 'email', 'evt' ) !== QueuePolicy::payload_hash( 6, 'email', 'evt' ), 'payload hash is per subscriber' );
MjyTest::ok( QueuePolicy::payload_hash( 5, 'sms', 'evt' ) !== QueuePolicy::payload_hash( 5, 'email', 'evt' ), 'payload hash is per channel' );

/* -------------------------------------------------------------------------
 * Template
 * ---------------------------------------------------------------------- */
MjyTest::group( 'Template / جای‌نگهدارها' );

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
MjyTest::same( 'سلام مریم، کفش دویدن موجود شد: https://shop.test/p/10 | لغو: https://shop.test/cancel/abc', $out, 'render replaces known placeholders' );

MjyTest::same( 'x {nope} y', Template::render( 'x {nope} y', array( 'nope' => 'z' ) ), 'unsupported placeholder stays visible' );
MjyTest::same( array( 'product_name', 'site_name' ), Template::used( '{product_name} {site_name} {product_name}' ), 'used() dedupes' );
MjyTest::same( array( 'typo' ), Template::unsupported( '{typo}' ), 'unsupported() detects typos' );
MjyTest::same( 1, Template::sms_segments( 'سلام' ), 'short sms is one segment' );
MjyTest::same( 2, Template::sms_segments( str_repeat( 'ا', 71 ) ), '71 chars is two segments' );
MjyTest::ok( Template::sms_segments( str_repeat( 'ا', 137 ) ) === 2, 'boundary at 137 chars' );
MjyTest::same( 3, Template::sms_segments( str_repeat( 'ا', 138 ) ), 'third segment starts at 138' );

/* -------------------------------------------------------------------------
 * HttpGuard
 * ---------------------------------------------------------------------- */
MjyTest::group( 'Text / برش چندبایتی' );

// A byte-wise substr() on Persian text cuts characters in half. MySQL then
// rejects the row as invalid utf8mb4 - a queue error that would look like a
// random "row did not save" bug. These assertions pin the safe behaviour.
$long_persian = str_repeat( 'ارسال نشد: خطای درگاه پیامک. ', 40 );

MjyTest::same( true, preg_match( '//u', Text::substring( $long_persian, 0, 255 ) ) === 1, 'برش ۲۵۵ کاراکتری UTF-8 معتبر می‌ماند' );
MjyTest::same( 255, Text::length( Text::substring( $long_persian, 0, 255 ) ), 'طول برش دقیقاً ۲۵۵ کاراکتر است' );
MjyTest::same( true, strlen( Text::substring( $long_persian, 0, 255 ) ) > 255, 'طول بایتی از ۲۵۵ بیشتر است (چندبایتی بودن اثبات می‌شود)' );
// Not every byte offset lands mid-character; count how many do, to show the
// hazard is real rather than theoretical.
$mjy_split_offsets = 0;
for ( $mjy_i = 1; $mjy_i <= 255; $mjy_i++ ) {
	if ( 1 !== preg_match( '//u', substr( $long_persian, 0, $mjy_i ) ) ) {
		$mjy_split_offsets++;
	}
}
MjyTest::ok( $mjy_split_offsets > 0, 'substr بایتی در ' . $mjy_split_offsets . ' نقطه رشته را می‌شکند (شاهد اینکه این رفع لازم بود)' );
MjyTest::same( 'سلام', Text::substring( 'سلام دنیا', 0, 4 ), 'برش کاراکتری فارسی درست است' );
MjyTest::same( '', Text::substring( '', 0, 10 ), 'رشتهٔ خالی' );
MjyTest::same( 'abc', Text::substring( 'abc', 0, 255 ), 'رشتهٔ کوتاه دست‌نخورده می‌ماند' );

MjyTest::group( 'EmailChannel / تشخیص خطای ارسال' );

$mail_facts = \Mojoodyar\Channels\EmailChannel::diagnose();
MjyTest::ok( is_array( $mail_facts ), 'diagnose آرایه برمی‌گرداند' );

foreach ( array( 'mail_function', 'sendmail_path', 'sendmail_exists', 'smtp_host', 'smtp_port', 'mailer_plugin' ) as $key ) {
	MjyTest::ok( array_key_exists( $key, $mail_facts ), 'کلید تشخیص موجود است: ' . $key );
}

MjyTest::ok( in_array( $mail_facts['mail_function'], array( 'available', 'missing' ), true ), 'وضعیت mail() یکی از دو مقدار مجاز است' );
MjyTest::ok( is_bool( $mail_facts['sendmail_exists'] ), 'sendmail_exists بولی است' );
MjyTest::ok( is_string( \Mojoodyar\Channels\EmailChannel::hint() ), 'hint رشته برمی‌گرداند' );

// The From address decides whether a "successful" send survives spam filters,
// so its resolution order is pinned here.
foreach ( array( 'admin_email', 'from_email', 'from_domain', 'site_host', 'from_matches_site' ) as $key ) {
	MjyTest::ok( array_key_exists( $key, $mail_facts ), 'کلید تشخیص فرستنده موجود است: ' . $key );
}

MjyTest::ok( in_array( $mail_facts['from_matches_site'], array( true, false, null ), true ), 'تطابق دامنهٔ فرستنده سه‌حالتی است (نمی‌دانیم ≠ نادرست)' );
MjyTest::ok( is_string( \Mojoodyar\Channels\EmailChannel::from_address() ), 'from_address رشته برمی‌گرداند' );

// Regression (reported on a real host): php.ini holds
// "sendmail_path = /usr/sbin/sendmail -t -i". The arguments are not part of
// the path; treating them as one made a working server look broken.
MjyTest::ok( array_key_exists( 'sendmail_binary', $mail_facts ), 'کلید sendmail_binary موجود است' );
MjyTest::ok( array_key_exists( 'transport', $mail_facts ), 'کلید transport موجود است' );
MjyTest::ok( in_array( $mail_facts['transport'], array( 'sendmail', 'smtp', 'mail' ), true ), 'حالت انتقال یکی از سه مقدار مجاز است' );
MjyTest::ok( in_array( $mail_facts['sendmail_exists'], array( true, false, null ), true ), 'وجود sendmail سه‌حالتی است (نمی‌دانیم ≠ ناموجود)' );

if ( 'Windows' !== PHP_OS_FAMILY ) {
	MjyTest::same( '', (string) $mail_facts['smtp_host'], 'SMTP در php.ini روی لینوکس نادیده گرفته می‌شود (هشدار کاذب نمی‌دهیم)' );
}

// The parser is private; exercise it through a reflection so the exact string
// from the bug report is covered.
$mjy_reflect = new ReflectionMethod( '\\Mojoodyar\\Channels\\EmailChannel', 'sendmail_binary' );
$mjy_reflect->setAccessible( true );

$mjy_sendmail_cases = array(
	'/usr/sbin/sendmail -t -i'          => '/usr/sbin/sendmail',
	'/usr/sbin/sendmail'                => '/usr/sbin/sendmail',
	'"C:\\Program Files\\sendmail.exe" -t' => 'C:\\Program Files\\sendmail.exe',
	'/usr/sbin/exim4 -t -i -f x@y.z'    => '/usr/sbin/exim4',
	''                                  => '',
	'   '                               => '',
);

foreach ( $mjy_sendmail_cases as $input => $expected ) {
	MjyTest::same( $expected, $mjy_reflect->invoke( null, $input ), 'مسیر اجرایی از فرمان sendmail جدا می‌شود: ' . $input );
}

MjyTest::group( 'EmailSkin / پوستهٔ ایمیل' );

$skin = EmailSkin::render(
	'کفش دویدن نایک',
	"سلام مریم،\n\nخبر خوب: کفش دویدن نایک دوباره موجود شد.\nhttps://shop.example/product/42\n\nلغو: https://shop.example/?mojoodyar_unsubscribe=abc",
	array(
		'site_name'       => 'فروشگاه نمونه',
		'product_name'    => 'کفش دویدن نایک',
		'product_url'     => 'https://shop.example/product/42',
		'price'           => '۲٬۴۵۰٬۰۰۰ تومان',
		'unsubscribe_url' => 'https://shop.example/?mojoodyar_unsubscribe=abc',
		'store_url'       => 'https://shop.example/',
		'is_confirmation' => '0',
	)
);

MjyTest::ok( false !== strpos( $skin, '<!DOCTYPE html>' ), 'سند HTML کامل است' );
MjyTest::ok( false !== strpos( $skin, 'dir="rtl"' ), 'جهت راست‌چین صریح است' );
MjyTest::ok( false !== strpos( $skin, EmailSkin::TEAL ), 'رنگ برند در پوسته هست' );
MjyTest::ok( false !== strpos( $skin, 'کفش دویدن نایک' ), 'نام محصول نمایش داده می‌شود' );
MjyTest::ok( false !== strpos( $skin, '۲٬۴۵۰٬۰۰۰ تومان' ), 'قیمت نمایش داده می‌شود' );
MjyTest::ok( false !== strpos( $skin, 'https://shop.example/product/42' ), 'پیوند محصول در دکمهٔ اقدام هست' );
MjyTest::ok( false !== strpos( $skin, 'mojoodyar_unsubscribe' ), 'پیوند لغو اشتراک در هر پیام هست' );
MjyTest::ok( false === strpos( $skin, '<script' ), 'هیچ اسکریپتی در ایمیل نیست' );
MjyTest::ok( false === strpos( $skin, 'display:flex' ) && false === strpos( $skin, 'display:grid' ), 'فقط چیدمان جدولی (سازگار با کلاینت‌های ایمیل)' );

// The bare URL paragraph is dropped because the button already carries it.
MjyTest::same( 2, count( EmailSkin::paragraphs( "خط اول\n\nhttps://shop.example/x\n\nخط سوم" ) ), 'پاراگرافِ تنها-URL حذف می‌شود' );

// Injection attempts.
$evil = EmailSkin::render(
	'<script>alert(1)</script>',
	'متن',
	array(
		'product_name'    => '"><img src=x onerror=alert(1)>',
		'product_url'     => 'javascript:alert(1)',
		'unsubscribe_url' => 'data:text/html,<script>alert(1)</script>',
	)
);

MjyTest::ok( false === strpos( $evil, '<script' ), 'تزریق اسکریپت در عنوان خنثی می‌شود' );
MjyTest::ok( false === strpos( $evil, '<img src=x' ), 'تزریق در نام محصول escape می‌شود' );
MjyTest::ok( false === strpos( $evil, 'javascript:' ), 'پیوند javascript: صادر نمی‌شود' );
MjyTest::ok( false === strpos( $evil, 'data:text/html' ), 'پیوند data: صادر نمی‌شود' );
MjyTest::ok( false !== strpos( $evil, '&lt;' ), 'متن خطرناک به موجودیت &lt; تبدیل شده' );
MjyTest::ok( false === strpos( $evil, '<img' ), 'هیچ تگ img ساخته نمی‌شود' );

// Confirmation variant swaps the call to action.
$confirm_skin = EmailSkin::render(
	'تأیید اشتراک',
	'برای تکمیل ثبت روی پیوند زیر بزنید.',
	array(
		'is_confirmation' => '1',
		'confirm_url'     => 'https://shop.example/?mojoodyar_confirm=tok',
		'product_url'     => 'https://shop.example/product/42',
	)
);

MjyTest::ok( false !== strpos( $confirm_skin, 'mojoodyar_confirm' ), 'پیام تأیید، دکمه را به پیوند تأیید وصل می‌کند' );
MjyTest::ok( false !== strpos( $confirm_skin, 'تأیید اشتراک' ), 'متن دکمهٔ تأیید درست است' );

MjyTest::group( 'HttpGuard / SSRF' );

MjyTest::ok( HttpGuard::is_allowed( 'https://api.sms-gateway.ir/v1/send' ), 'public https endpoint allowed' );

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
	MjyTest::ok( ! HttpGuard::is_allowed( $url ), 'blocked endpoint: ' . ( '' === $url ? '(empty)' : $url ) );
}

MjyTest::same( 'scheme', HttpGuard::check( 'http://api.example.com/send' )['reason'], 'reason: scheme' );
MjyTest::same( 'private_ip', HttpGuard::check( 'https://10.1.2.3/x' )['reason'], 'reason: private ip' );
MjyTest::same( 'credentials', HttpGuard::check( 'https://a:b@api.example.com/x' )['reason'], 'reason: credentials' );

/* -------------------------------------------------------------------------
 * SmsPattern / الگوی positional پیامک
 * ---------------------------------------------------------------------- */
MjyTest::group( 'SmsPattern / الگوی پیامک' );

$pattern_ctx = array(
	'customer_name' => 'مریم',
	'product_name'  => 'کفش دویدن',
	'product_url'   => 'https://shop.test/p/10',
	'price'         => '۲٬۴۵۰٬۰۰۰ تومان',
	'site_name'     => 'فروشگاه نمونه',
);

MjyTest::same( array( 0, 1, 2 ), SmsPattern::indexes( '{0} عزیز، {1} آمد. {2} — فروشگاه' ), 'indexes() ascending' );
MjyTest::same( array( 0, 1 ), SmsPattern::indexes( '{1} {0} {1}' ), 'indexes() dedupes' );
MjyTest::same( array(), SmsPattern::indexes( 'بدون متغیر' ), 'indexes() empty' );

MjyTest::same( 'مریم', SmsPattern::value( 0, $pattern_ctx ), 'value {0} = customer name' );
MjyTest::same( 'کفش دویدن', SmsPattern::value( 1, $pattern_ctx ), 'value {1} = product name' );
MjyTest::same( '', SmsPattern::value( 9, $pattern_ctx ), 'unknown index yields empty' );

// A semicolon inside a value would shift every later value by one.
MjyTest::same( 'الف, ب', SmsPattern::value( 1, array( 'product_name' => "الف;\nب" ) ), 'semicolon and newline are neutralised' );

MjyTest::same(
	'مریم;کفش دویدن;https://shop.test/p/10',
	SmsPattern::payload( '{0} عزیز، {1} آمد: {2} — فروشگاه نمونه', $pattern_ctx ),
	'payload joins values in index order'
);
// The gateway fills {n} with the n-th value of the list, so the list is built
// by index: a template that mentions {1} before {0} still gets {0} first.
MjyTest::same(
	'مریم;کفش دویدن',
	SmsPattern::payload( 'مشتری {1} برای {0} — فروشگاه', $pattern_ctx ),
	'payload is ordered by index, not by position in the text'
);

$default_pattern = SmsPattern::default_template( 'فروشگاه نمونه' );
MjyTest::ok( false !== strpos( $default_pattern, 'فروشگاه نمونه' ), 'default template carries the store name' );
MjyTest::ok( ! preg_match( '/\{\d+\}\s*$/u', $default_pattern ), 'default template does not end with a variable' );
MjyTest::same( array(), SmsPattern::validate( $default_pattern, 'فروشگاه نمونه' ), 'default template has no findings' );
MjyTest::ok( false !== strpos( SmsPattern::default_template( '' ), 'فروشگاه شما' ), 'default template falls back to a generic name' );
MjyTest::same( $default_pattern, SmsPattern::resolve( '', 'فروشگاه نمونه' ), 'resolve() uses the default when empty' );
MjyTest::same( '{0} — فروشگاه', SmsPattern::resolve( '{0} — فروشگاه', 'فروشگاه نمونه' ), 'resolve() keeps a stored template' );

/**
 * Codes of the findings for a template.
 */
function mjy_pattern_codes( $template, $site = 'فروشگاه نمونه' ) {
	$codes = array();
	foreach ( SmsPattern::validate( $template, $site ) as $finding ) {
		$codes[] = $finding['level'] . ':' . $finding['code'];
	}

	return $codes;
}

MjyTest::same( array( 'error:empty' ), mjy_pattern_codes( '   ' ), 'empty template is an error' );
MjyTest::same( array( 'error:no_variables' ), mjy_pattern_codes( 'سلام، موجود شد — فروشگاه نمونه' ), 'template without variables is an error' );
MjyTest::ok( in_array( 'error:numbering', mjy_pattern_codes( 'کاربر {1} عزیز، {2} موجود شد — فروشگاه نمونه' ), true ), 'numbering that starts at 1 is rejected' );
MjyTest::ok( in_array( 'error:numbering', mjy_pattern_codes( '{0} و {2} موجود شد — فروشگاه نمونه' ), true ), 'a gap in numbering is rejected' );
MjyTest::ok( in_array( 'error:ends_with_variable', mjy_pattern_codes( '{0} عزیز، کالای شما موجود شد {1}' ), true ), 'trailing variable is rejected' );
MjyTest::ok( in_array( 'error:too_many', mjy_pattern_codes( '{0} {1} {2} {3} {4} موجود شد — فروشگاه نمونه' ), true ), 'index above 3 is rejected' );
MjyTest::ok( in_array( 'warning:too_short', mjy_pattern_codes( '{0} باتشکر', 'فروشگاه نمونه' ), true ), 'variable-only text warns (the «{0} باتشکر» case)' );
MjyTest::ok( in_array( 'warning:no_brand', mjy_pattern_codes( '{0} عزیز، {1} دوباره موجود شد. مشاهده و خرید: {2}', 'فروشگاه نمونه' ), true ), 'missing store name warns' );
MjyTest::ok( in_array( 'warning:too_long', mjy_pattern_codes( str_repeat( 'ا', 185 ) . ' {0} — فروشگاه نمونه' ), true ), 'over-long template warns' );

MjyTest::ok( SmsPattern::has_errors( SmsPattern::validate( '{0}', 'x' ) ), 'has_errors() sees a hard error' );
MjyTest::ok( ! SmsPattern::has_errors( SmsPattern::validate( '{0} عزیز، {1} دوباره موجود شد. مشاهده و خرید: {2} — فروشگاه نمونه', 'فروشگاه نمونه' ) ), 'a clean template has no hard errors' );

// The four variables this plugin actually documents must all exist.
MjyTest::same( 4, count( SmsPattern::labels() ), 'four documented variables' );
MjyTest::same( 4, count( SmsPattern::VARIABLES ), 'four mapped variables' );

/* -------------------------------------------------------------------------
 * Statistics math (the numbers behind the report screen)
 * ---------------------------------------------------------------------- */
MjyTest::group( 'ریاضیات آمار' );

$keys = StatsMath::day_keys( gmmktime( 0, 0, 0, 10, 1, 2026 ), 5 );

MjyTest::same( 5, count( $keys ), 'a five-day period produces five day keys' );
MjyTest::same( '2026-10-01', $keys[0], 'the first key is the requested day' );
MjyTest::same( '2026-10-05', $keys[4], 'the last key is four days later' );

// A partial timestamp must anchor to midnight, not shift the whole axis.
$partial = StatsMath::day_keys( gmmktime( 17, 45, 30, 10, 1, 2026 ), 3 );
MjyTest::same( $keys[0], $partial[0], 'a mid-day start still anchors to midnight' );

$filled = StatsMath::fill(
	array(
		array( 'd' => '2026-10-02', 'c' => 4 ),
		array( 'd' => '2026-10-05', 'c' => 2 ),
	),
	$keys
);
MjyTest::same( array( 0, 4, 0, 0, 2 ), $filled, 'quiet days stay in the axis as zeros' );
MjyTest::same( 6, StatsMath::total( $filled ), 'series total adds the real values only' );

// A DATETIME value must not create a second bucket for the same day.
$datetime = StatsMath::fill( array( array( 'd' => '2026-10-02 13:20:00', 'c' => 3 ) ), $keys );
MjyTest::same( array( 0, 3, 0, 0, 0 ), $datetime, 'a datetime value is bucketed by its date part' );

$merged = StatsMath::fill(
	array(
		array( 'd' => '2026-10-03', 'c' => 2 ),
		array( 'd' => '2026-10-03', 'c' => 5 ),
	),
	$keys
);
MjyTest::same( 7, $merged[2], 'two rows for the same day are added, not overwritten' );

MjyTest::same( 0, StatsMath::percent( 0, 0 ), 'no rows means 0%, not a division error' );
MjyTest::same( 0, StatsMath::percent( 5, 0 ), 'a part without a total is 0%' );
MjyTest::same( 50, StatsMath::percent( 1, 2 ), 'one of two is 50%' );
MjyTest::same( 100, StatsMath::percent( 7, 7 ), 'all of all is 100%' );
MjyTest::same( 100, StatsMath::percent( 9, 7 ), 'a part larger than the total is clamped to 100%' );
MjyTest::same( 33, StatsMath::percent( 1, 3 ), 'a third rounds to 33%' );

MjyTest::same( 0, StatsMath::peak( array() ), 'an empty series has no peak' );
MjyTest::same( 9, StatsMath::peak( array( 0, 9, 3 ) ), 'peak() finds the largest value' );
MjyTest::same( 0, StatsMath::height( 0, 9 ), 'a zero value has no bar' );
MjyTest::same( 100, StatsMath::height( 9, 9 ), 'the peak value fills the chart' );
MjyTest::same( 50, StatsMath::height( 3, 6 ), 'half the peak is half the height' );
MjyTest::same( 4, StatsMath::height( 1, 500 ), 'a small non-zero value keeps a visible bar' );

MjyTest::same( 1, StatsMath::label_step( 7 ), 'a week labels every day' );
MjyTest::same( 3, StatsMath::label_step( 30 ), 'a month labels every third day' );
MjyTest::same( 7, StatsMath::label_step( 90 ), 'a quarter labels once a week' );
MjyTest::same( 1, StatsMath::label_step( 0 ), 'a zero-length period does not divide by zero' );

/* -------------------------------------------------------------------------
 * Log translation (the Persian column of the log screen)
 * ---------------------------------------------------------------------- */
MjyTest::group( 'ترجمهٔ لاگ' );

// Every event key the plugin can write must have a Persian title. The list is
// duplicated here on purpose: silently adding a logger line without a
// translation should fail a test, not ship a raw key to the panel.
$known_events = array(
	'subscribe_ok',
	'subscribe_duplicate',
	'subscribe_failed',
	'subscribe_rejected',
	'sent',
	'delivery_failed',
	'queue_run',
	'restock_queued',
	'restock_no_units',
	'reconcile',
	'snapshot_seeded',
	'admin_tool',
	'admin_bulk',
	'admin_call',
	'public_confirm',
	'public_unsubscribe',
);

$missing = array();
foreach ( $known_events as $event_key ) {
	$title = LogExplain::event( $event_key );
	if ( '' === $title || false !== strpos( $title, 'ثبت‌نشده' ) ) {
		$missing[] = $event_key;
	}
}

MjyTest::same( array(), $missing, 'همهٔ رویدادهای شناخته‌شده عنوان فارسی دارند' );
MjyTest::ok( false !== strpos( LogExplain::event( 'make_me_a_sandwich' ), 'ثبت‌نشده' ), 'رویداد ناشناخته پنهان نمی‌شود' );
MjyTest::same( 'رویداد بدون نام', LogExplain::event( '' ), 'رویداد خالی هم برچسب دارد' );

MjyTest::same( 'اطلاع', LogExplain::level( 'info' ), 'سطح info فارسی است' );
MjyTest::same( 'هشدار', LogExplain::level( 'warning' ), 'سطح warning فارسی است' );
MjyTest::same( 'خطا', LogExplain::level( 'error' ), 'سطح error فارسی است' );
MjyTest::same( 'امنیتی', LogExplain::level( 'security' ), 'سطح security فارسی است' );
MjyTest::same( 'other', LogExplain::level( 'other' ), 'سطح ناشناخته دور ریخته نمی‌شود' );

MjyTest::ok( LogExplain::is_problem( 'security' ), 'رویداد امنیتی مشکل است' );
MjyTest::ok( LogExplain::is_problem( 'error' ), 'خطا مشکل است' );
MjyTest::ok( ! LogExplain::is_problem( 'info' ), 'اطلاع مشکل نیست' );

$parsed = LogExplain::parse( 'product=501|channel=sms' );
MjyTest::same( 2, count( $parsed ), 'دو بخش لاگ جدا می‌شود' );
MjyTest::same( 'محصول', $parsed[0]['label'], 'کلید product فارسی می‌شود' );
MjyTest::same( '501', $parsed[0]['value'], 'مقدار دست‌نخورده می‌ماند' );
MjyTest::same( 'کانال', $parsed[1]['label'], 'کلید channel فارسی می‌شود' );

MjyTest::same( array(), LogExplain::parse( '' ), 'لاگ بدون متن، بدون بخش است' );

$pair = LogExplain::parse( '502:7|units=3|queued=2' );
MjyTest::same( '502:7', $pair[0]['value'], 'جفت محصول:تنوع خوانده می‌شود' );

$detail = LogExplain::detail( 'restock_queued', '501:0|units=2|queued=2' );
MjyTest::ok( false !== strpos( $detail, 'محصول #501' ), 'جزئیات، شناسهٔ محصول را می‌نویسد' );
MjyTest::ok( false === strpos( $detail, 'تنوع' ), 'برای محصول ساده تنوع چاپ نمی‌شود' );
MjyTest::ok( false !== strpos( $detail, 'تعداد واحد' ), 'جزئیات، برچسب تعداد واحد را دارد' );

$variation = LogExplain::detail( 'restock_queued', '502:7|units=1' );
MjyTest::ok( false !== strpos( $variation, 'تنوع #7' ), 'برای تنوع، شناسهٔ تنوع چاپ می‌شود' );

$rejection = LogExplain::detail( 'subscribe_rejected', 'bad_nonce' );
MjyTest::ok( false !== strpos( $rejection, 'کد امنیتی' ), 'کد bad_nonce به فارسی توضیح داده می‌شود' );

$dup = LogExplain::detail( 'subscribe_rejected', 'duplicate' );
MjyTest::ok( false !== strpos( $dup, 'قبلاً' ), 'کد duplicate به فارسی توضیح داده می‌شود' );

MjyTest::ok( false !== strpos( LogExplain::reason( 'reason=email', 'subscribe_failed' ), 'ایمیل' ), 'شکل reason=... هم خوانده می‌شود' );
MjyTest::same( 'کد بی‌نام', LogExplain::reason( 'کد بی‌نام' ), 'کد ناشناخته دور ریخته نمی‌شود' );
MjyTest::same( 'بدون دلیل ثبت‌شده', LogExplain::reason( '' ), 'کد خالی برچسب دارد' );

MjyTest::ok( '' !== LogExplain::advice( 'subscribe_rejected', 'bad_nonce' ), 'برای جدی‌ترین خطا راهنمای اقدام هست' );
MjyTest::ok( '' !== LogExplain::advice( 'delivery_failed', 'sms|http' ), 'برای خطای ارسال راهنما هست' );
MjyTest::same( '', LogExplain::advice( 'sent', 'sms|restock' ), 'رویداد موفق راهنمای بی‌مورد ندارد' );

/* -------------------------------------------------------------------------
 * Report
 * ---------------------------------------------------------------------- */
$summary = MjyTest::summary();
$arguments = array();
if ( isset( $argv ) && is_array( $argv ) ) {
	$arguments = $argv;
} elseif ( isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
	$arguments = $_SERVER['argv'];
}
$as_json = in_array( '--json', $arguments, true );

if ( ! $as_json ) {
	echo "\n=== Mojoodyar unit tests ===\n";
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
