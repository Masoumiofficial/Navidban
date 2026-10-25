<?php
/**
 * Query and asset probe.
 *
 * Measures, per page type, exactly two things the performance budget talks
 * about:
 *   1. how many front-end assets the plugin enqueues;
 *   2. how many SQL statements the plugin itself issues while the page is
 *      built (the WordPress core/WooCommerce queries are not ours and are not
 *      counted).
 *
 * The storage here is the harness SQLite stand-in. Query *counts* are
 * meaningful; query *durations* are not, and are not reported.
 *
 * Output: JSON on stdout, always. Written to docs/PERFORMANCE.md by
 * tools/har-audit.sh.
 *
 * Usage: php tests/queries.php [--json]
 *
 * @package Navidban
 */

require_once __DIR__ . '/stubs/wp.php';
require_once __DIR__ . '/stubs/woocommerce.php';
require_once NAVIDBAN_DIR . 'includes/Autoloader.php';
\Navidban\Autoloader::register();

use Navidban\Activator;
use Navidban\Frontend;
use Navidban\Settings;

/**
 * Reset the per-request stub state.
 *
 * @return void
 */
function nvb_probe_reset() {
	$GLOBALS['nvb_enqueued']   = array();
	$GLOBALS['nvb_products']   = array();
	$GLOBALS['nvb_is_product'] = false;
	$GLOBALS['nvb_queried_id'] = 0;
	$GLOBALS['nvb_is_admin']   = false;
	$GLOBALS['nvb_is_feed']    = false;
	$GLOBALS['wpdb']->queries  = array();
	Settings::flush_cache();
}

/**
 * Describe one page type.
 *
 * @param string $label    Human label.
 * @param array  $products Stub products.
 * @param bool   $is_product is_product().
 * @param int    $queried  Queried object id.
 * @param bool   $render   Also render the form markup?
 * @return array
 */
function nvb_probe_scenario( $label, array $products, $is_product, $queried, $render ) {
	nvb_probe_reset();

	foreach ( $products as $product ) {
		$id    = (int) $product['id'];
		$extra = $product;
		unset( $extra['id'] );
		$GLOBALS['nvb_products'][ $id ] = array_merge(
			array(
				'type'     => 'simple',
				'parent'   => 0,
				'status'   => 'instock',
				'qty'      => 10,
				'managed'  => true,
				'children' => array(),
				'name'     => 'کفش دویدن',
			),
			$extra
		);
	}

	$GLOBALS['nvb_is_product'] = (bool) $is_product;
	$GLOBALS['nvb_queried_id'] = (int) $queried;

	$frontend = new Frontend();
	$frontend->maybe_enqueue();

	$html = '';
	if ( $render ) {
		ob_start();
		$frontend->render_form();
		$html = (string) ob_get_clean();
	}

	$styles  = isset( $GLOBALS['nvb_enqueued']['style'] ) ? $GLOBALS['nvb_enqueued']['style'] : array();
	$scripts = isset( $GLOBALS['nvb_enqueued']['script'] ) ? $GLOBALS['nvb_enqueued']['script'] : array();

	$sql = array();
	foreach ( (array) $GLOBALS['wpdb']->queries as $statement ) {
		$sql[] = preg_replace( '/\s+/', ' ', trim( (string) $statement ) );
	}

	return array(
		'label'          => $label,
		'assets_enqueued' => count( $styles ) + count( $scripts ),
		'handles'         => array_values( array_unique( array_merge( array_values( $styles ), array_values( $scripts ) ) ) ),
		'styles'         => count( $styles ),
		'scripts'        => count( $scripts ),
		'queries'        => count( $sql ),
		'queries_sample' => array_slice( $sql, 0, 5 ),
		'form_bytes'     => strlen( $html ),
		'form_rendered'  => '' !== $html,
	);
}

Activator::activate( false );

$scenarios = array(
	nvb_probe_scenario( 'صفحهٔ خانه (غیرمحصول)', array(), false, 0, false ),
	nvb_probe_scenario(
		'صفحهٔ محصول موجود',
		array( array( 'id' => 11, 'status' => 'instock', 'qty' => 5 ) ),
		true,
		11,
		true
	),
	nvb_probe_scenario(
		'صفحهٔ محصول ناموجود',
		array( array( 'id' => 12, 'status' => 'outofstock', 'qty' => 0 ) ),
		true,
		12,
		true
	),
	nvb_probe_scenario(
		'صفحهٔ محصول متغیر با یک تنوع ناموجود',
		array(
			array( 'id' => 20, 'type' => 'variable', 'children' => array( 21, 22 ), 'qty' => 0 ),
			array( 'id' => 21, 'parent' => 20, 'status' => 'instock', 'qty' => 3 ),
			array( 'id' => 22, 'parent' => 20, 'status' => 'outofstock', 'qty' => 0 ),
		),
		true,
		20,
		true
	),
);

$summary = array(
	'php_version' => PHP_VERSION,
	'generated_at' => gmdate( 'c' ),
	'storage'     => 'SQLite (harness) — query counts valid, durations not reported',
	'scenarios'   => $scenarios,
);

echo json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";
