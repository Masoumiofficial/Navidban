<?php
/**
 * Load check: 10,000 subscribers and a queue that must be drained in batches.
 *
 * This runs against SQLite (the harness storage), not MySQL, so the absolute
 * numbers are NOT a MySQL benchmark. What it proves is the behaviour that the
 * performance budget depends on:
 *   - a batch never exceeds the configured size or the rate limit;
 *   - the run stops itself before the time budget is exhausted;
 *   - fair sending with 10,000 waiting subscribers still produces exactly the
 *     number of messages the stock allows.
 *
 * Usage: php tests/load.php [--json]
 *
 * @package Mojoodyar
 */

require_once __DIR__ . '/stubs/wp.php';
require_once __DIR__ . '/stubs/woocommerce.php';
require_once MOJODYAR_DIR . 'includes/Autoloader.php';
\Mojoodyar\Autoloader::register();

use Mojoodyar\Activator;
use Mojoodyar\Queue;
use Mojoodyar\Settings;
use Mojoodyar\StockWatcher;
use Mojoodyar\WaitlistRepository;

$mjy_results = array();
$mjy_failures = 0;

/**
 * Record one measured check.
 *
 * @param string $label    Label.
 * @param bool   $passed   Result.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @return void
 */
function mjy_check( $label, $passed, $expected, $actual ) {
	global $mjy_results, $mjy_failures;

	if ( ! $passed ) {
		$mjy_failures++;
	}

	$mjy_results[] = array(
		'label'    => $label,
		'passed'   => (bool) $passed,
		'expected' => is_scalar( $expected ) ? (string) $expected : json_encode( $expected ),
		'actual'   => is_scalar( $actual ) ? (string) $actual : json_encode( $actual ),
	);
}

$mjy_t0 = microtime( true );

Activator::activate( false );
Settings::update(
	array(
		'fair_sending'        => true,
		'email_enabled'       => true,
		'sms_enabled'         => false,
		'queue_batch_size'    => 50,
		'queue_per_minute'    => 60,
		'send_window_enabled' => false,
	)
);

$GLOBALS['mjy_products'][900] = array(
	'type'     => 'simple',
	'parent'   => 0,
	'status'   => 'outofstock',
	'qty'      => 0,
	'managed'  => true,
	'children' => array(),
	'name'     => 'محصول پرفروش',
);

$watcher = new StockWatcher();
$watcher->on_stock_status( 900, 'outofstock', wc_get_product( 900 ) );

// --- 10,000 confirmed subscribers -------------------------------------------
$mjy_insert_start = microtime( true );

$GLOBALS['wpdb']->query( 'START TRANSACTION' );
for ( $i = 1; $i <= 10000; $i++ ) {
	$GLOBALS['wpdb']->insert(
		WaitlistRepository::table(),
		array(
			'token'              => hash( 'sha256', 'seed-' . $i ),
			'product_id'         => 900,
			'variation_id'       => 0,
			'event_type'         => 'restock',
			'channel'            => 'email',
			'email'              => 'load' . $i . '@example.com',
			'phone'              => '',
			'user_id'            => 0,
			'status'             => 'confirmed',
			'consent_at'         => gmdate( 'Y-m-d H:i:s' ),
			'confirmed_at'       => gmdate( 'Y-m-d H:i:s' ),
			'notified_at'        => null,
			'confirm_expires_at' => null,
			'stock_at_signup'    => 0,
			'ip_hash'            => '',
			'created_at'         => gmdate( 'Y-m-d H:i:s', time() - ( 10000 - $i ) ),
		)
	);
}
$GLOBALS['wpdb']->query( 'COMMIT' );

$mjy_insert_ms = round( ( microtime( true ) - $mjy_insert_start ) * 1000 );

$mjy_total = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::table() );
mjy_check( '۱۰٬۰۰۰ مشترک ثبت شد', 10000 === $mjy_total, 10000, $mjy_total );

// --- Fair sending with a 5-unit restock -------------------------------------
$mjy_restock_start = microtime( true );

$GLOBALS['mjy_products'][900]['status'] = 'instock';
$GLOBALS['mjy_products'][900]['qty']    = 5;
$watcher->on_stock_status( 900, 'instock', wc_get_product( 900 ) );

$mjy_restock_ms = round( ( microtime( true ) - $mjy_restock_start ) * 1000 );

$mjy_queued = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
mjy_check( 'موجودی ۵ با ۱۰٬۰۰۰ مشترک: دقیقاً ۵ اعلان', 5 === $mjy_queued, 5, $mjy_queued );

// --- Batch draining ---------------------------------------------------------
$mjy_batch = Queue::process();
mjy_check( 'دستهٔ اول از سقف اندازهٔ دسته عبور نمی‌کند', $mjy_batch['sent'] <= 50, '<= 50', $mjy_batch['sent'] );

// Force a large queue and confirm the time budget closes the run early.
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );

$mjy_enqueue_start = microtime( true );
$mjy_ids           = $GLOBALS['wpdb']->get_col( 'SELECT id FROM ' . WaitlistRepository::table() . ' ORDER BY id ASC LIMIT 1000' );

foreach ( $mjy_ids as $mjy_id ) {
	Queue::enqueue( (int) $mjy_id, 'email', 'restock:load' );
}
$mjy_enqueue_ms = round( ( microtime( true ) - $mjy_enqueue_start ) * 1000 );

$mjy_before = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() );
mjy_check( '۱۰۰۰ پیام در صف قرار گرفت', 1000 === $mjy_before, 1000, $mjy_before );

$mjy_run_start = microtime( true );
$mjy_run       = Queue::process();
$mjy_run_ms    = round( ( microtime( true ) - $mjy_run_start ) * 1000 );

mjy_check( 'یک اجرا بیش از اندازهٔ دسته ارسال نمی‌کند', $mjy_run['sent'] <= 50, '<= 50', $mjy_run['sent'] );
mjy_check( 'زمان اجرا زیر ۳۰ ثانیه ماند', $mjy_run_ms < 30000, '< 30000 ms', $mjy_run_ms . ' ms' );

$mjy_remaining = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'queued'" );
mjy_check( 'باقی پیام‌ها در صف ماندند و گم نشدند', $mjy_remaining === ( 1000 - $mjy_run['sent'] ), 1000 - $mjy_run['sent'], $mjy_remaining );

// --- Report -----------------------------------------------------------------
$mjy_summary = array(
	'php_version'          => PHP_VERSION,
	'storage'              => 'SQLite (test harness) — not a MySQL benchmark',
	'generated_at'         => gmdate( 'c' ),
	'subscribers'          => $mjy_total,
	'remaining_after_batch' => $mjy_remaining,
	'batch_sent'           => $mjy_run['sent'],
	'timings_ms'           => array(
		'insert_10000_rows'  => $mjy_insert_ms,
		'restock_with_10000' => $mjy_restock_ms,
		'enqueue_1000'       => $mjy_enqueue_ms,
		'process_one_batch'  => $mjy_run_ms,
		'total'              => round( ( microtime( true ) - $mjy_t0 ) * 1000 ),
	),
	'checks'               => $mjy_results,
	'failures'             => $mjy_failures,
);

$arguments = isset( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();
$as_json   = in_array( '--json', $arguments, true );

$encoded = json_encode( $mjy_summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
file_put_contents( __DIR__ . '/load-results.json', $encoded . "\n" );

if ( $as_json ) {
	echo $encoded . "\n";
} else {
	echo "\n=== Mojoodyar load check ===\n";
	foreach ( $mjy_results as $mjy_row ) {
		printf( "[%s] %-52s expected %-8s got %s\n", $mjy_row['passed'] ? 'ok  ' : 'FAIL', $mjy_row['label'], $mjy_row['expected'], $mjy_row['actual'] );
	}
	printf(
		"\n%d subscribers | insert %d ms | restock %d ms | enqueue 1000 %d ms | one batch %d ms | total %d ms\n",
		$mjy_total,
		$mjy_insert_ms,
		$mjy_restock_ms,
		$mjy_enqueue_ms,
		$mjy_run_ms,
		$mjy_summary['timings_ms']['total']
	);
	printf( "PHP %s — %d checks, %d failures\n", PHP_VERSION, count( $mjy_results ), $mjy_failures );
}

exit( $mjy_failures > 0 ? 1 : 0 );
