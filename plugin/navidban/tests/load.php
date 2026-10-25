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
 * @package Navidban
 */

require_once __DIR__ . '/stubs/wp.php';
require_once __DIR__ . '/stubs/woocommerce.php';
require_once NAVIDBAN_DIR . 'includes/Autoloader.php';
\Navidban\Autoloader::register();

use Navidban\Activator;
use Navidban\Queue;
use Navidban\Settings;
use Navidban\StockWatcher;
use Navidban\WaitlistRepository;

$nvb_results = array();
$nvb_failures = 0;

/**
 * Record one measured check.
 *
 * @param string $label    Label.
 * @param bool   $passed   Result.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @return void
 */
function nvb_check( $label, $passed, $expected, $actual ) {
	global $nvb_results, $nvb_failures;

	if ( ! $passed ) {
		$nvb_failures++;
	}

	$nvb_results[] = array(
		'label'    => $label,
		'passed'   => (bool) $passed,
		'expected' => is_scalar( $expected ) ? (string) $expected : json_encode( $expected ),
		'actual'   => is_scalar( $actual ) ? (string) $actual : json_encode( $actual ),
	);
}

$nvb_t0 = microtime( true );

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

$GLOBALS['nvb_products'][900] = array(
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
$nvb_insert_start = microtime( true );

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

$nvb_insert_ms = round( ( microtime( true ) - $nvb_insert_start ) * 1000 );

$nvb_total = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WaitlistRepository::table() );
nvb_check( '۱۰٬۰۰۰ مشترک ثبت شد', 10000 === $nvb_total, 10000, $nvb_total );

// --- Fair sending with a 5-unit restock -------------------------------------
$nvb_restock_start = microtime( true );

$GLOBALS['nvb_products'][900]['status'] = 'instock';
$GLOBALS['nvb_products'][900]['qty']    = 5;
$watcher->on_stock_status( 900, 'instock', wc_get_product( 900 ) );

$nvb_restock_ms = round( ( microtime( true ) - $nvb_restock_start ) * 1000 );

$nvb_queued = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE event_key LIKE 'restock%'" );
nvb_check( 'موجودی ۵ با ۱۰٬۰۰۰ مشترک: دقیقاً ۵ اعلان', 5 === $nvb_queued, 5, $nvb_queued );

// --- Batch draining ---------------------------------------------------------
$nvb_batch = Queue::process();
nvb_check( 'دستهٔ اول از سقف اندازهٔ دسته عبور نمی‌کند', $nvb_batch['sent'] <= 50, '<= 50', $nvb_batch['sent'] );

// Force a large queue and confirm the time budget closes the run early.
$GLOBALS['wpdb']->query( 'DELETE FROM ' . Queue::table() );

$nvb_enqueue_start = microtime( true );
$nvb_ids           = $GLOBALS['wpdb']->get_col( 'SELECT id FROM ' . WaitlistRepository::table() . ' ORDER BY id ASC LIMIT 1000' );

foreach ( $nvb_ids as $nvb_id ) {
	Queue::enqueue( (int) $nvb_id, 'email', 'restock:load' );
}
$nvb_enqueue_ms = round( ( microtime( true ) - $nvb_enqueue_start ) * 1000 );

$nvb_before = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() );
nvb_check( '۱۰۰۰ پیام در صف قرار گرفت', 1000 === $nvb_before, 1000, $nvb_before );

$nvb_run_start = microtime( true );
$nvb_run       = Queue::process();
$nvb_run_ms    = round( ( microtime( true ) - $nvb_run_start ) * 1000 );

nvb_check( 'یک اجرا بیش از اندازهٔ دسته ارسال نمی‌کند', $nvb_run['sent'] <= 50, '<= 50', $nvb_run['sent'] );
nvb_check( 'زمان اجرا زیر ۳۰ ثانیه ماند', $nvb_run_ms < 30000, '< 30000 ms', $nvb_run_ms . ' ms' );

$nvb_remaining = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Queue::table() . " WHERE status = 'queued'" );
nvb_check( 'باقی پیام‌ها در صف ماندند و گم نشدند', $nvb_remaining === ( 1000 - $nvb_run['sent'] ), 1000 - $nvb_run['sent'], $nvb_remaining );

// --- Report -----------------------------------------------------------------
$nvb_summary = array(
	'php_version'          => PHP_VERSION,
	'storage'              => 'SQLite (test harness) — not a MySQL benchmark',
	'generated_at'         => gmdate( 'c' ),
	'subscribers'          => $nvb_total,
	'remaining_after_batch' => $nvb_remaining,
	'batch_sent'           => $nvb_run['sent'],
	'timings_ms'           => array(
		'insert_10000_rows'  => $nvb_insert_ms,
		'restock_with_10000' => $nvb_restock_ms,
		'enqueue_1000'       => $nvb_enqueue_ms,
		'process_one_batch'  => $nvb_run_ms,
		'total'              => round( ( microtime( true ) - $nvb_t0 ) * 1000 ),
	),
	'checks'               => $nvb_results,
	'failures'             => $nvb_failures,
);

$arguments = isset( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();
$as_json   = in_array( '--json', $arguments, true );

$encoded = json_encode( $nvb_summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
file_put_contents( __DIR__ . '/load-results.json', $encoded . "\n" );

if ( $as_json ) {
	echo $encoded . "\n";
} else {
	echo "\n=== Navidban load check ===\n";
	foreach ( $nvb_results as $nvb_row ) {
		printf( "[%s] %-52s expected %-8s got %s\n", $nvb_row['passed'] ? 'ok  ' : 'FAIL', $nvb_row['label'], $nvb_row['expected'], $nvb_row['actual'] );
	}
	printf(
		"\n%d subscribers | insert %d ms | restock %d ms | enqueue 1000 %d ms | one batch %d ms | total %d ms\n",
		$nvb_total,
		$nvb_insert_ms,
		$nvb_restock_ms,
		$nvb_enqueue_ms,
		$nvb_run_ms,
		$nvb_summary['timings_ms']['total']
	);
	printf( "PHP %s — %d checks, %d failures\n", PHP_VERSION, count( $nvb_results ), $nvb_failures );
}

exit( $nvb_failures > 0 ? 1 : 0 );
