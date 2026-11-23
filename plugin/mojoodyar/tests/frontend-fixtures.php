<?php
/**
 * Emit real storefront markup for the JavaScript DOM regression suite.
 * The WordPress/WooCommerce dependencies are stubs, not a live installation.
 *
 * @package Mojoodyar
 */

require_once __DIR__ . '/stubs/wp.php';
require_once __DIR__ . '/stubs/admin.php';
require_once __DIR__ . '/stubs/woocommerce.php';
require_once MOJODYAR_DIR . 'includes/Autoloader.php';
\Mojoodyar\Autoloader::register();

use Mojoodyar\Frontend;
use Mojoodyar\Settings;

Settings::update(
	array(
		'email_enabled'     => true,
		'sms_enabled'       => true,
		'sms_gateway'       => 'melipayamak',
		'melipayamak_user'  => 'test',
		'melipayamak_pass'  => 'test',
		'channel_choice'    => 'email_first',
		'consent_required'  => false,
	)
);
$GLOBALS['mjy_products'] = array(
	7000 => array( 'type' => 'variable', 'status' => 'instock', 'children' => array( 7001, 7002, 7003 ) ),
	7001 => array( 'type' => 'variation', 'parent' => 7000, 'status' => 'instock', 'attributes' => 'سایز: ۴۱' ),
	7002 => array( 'type' => 'variation', 'parent' => 7000, 'status' => 'outofstock', 'attributes' => 'سایز: ۴۲' ),
	7003 => array( 'type' => 'variation', 'parent' => 7000, 'status' => 'outofstock', 'attributes' => 'سایز: ۴۳' ),
	7100 => array( 'type' => 'variable', 'status' => 'instock', 'children' => array( 7101 ) ),
	7101 => array( 'type' => 'variation', 'parent' => 7100, 'status' => 'outofstock', 'attributes' => 'رنگ: آبی' ),
	8000 => array( 'type' => 'simple', 'status' => 'outofstock' ),
	8001 => array( 'type' => 'simple', 'status' => 'outofstock' ),
);
$GLOBALS['mjy_is_product'] = true;
$GLOBALS['mjy_queried_id'] = 7000;
$variable = new Frontend();
$variable->maybe_enqueue();
ob_start();
$variable->render_form();
$var_auto = (string) ob_get_clean();
$var_explicit = ( new Frontend() )->shortcode( array( 'product_id' => '7000' ) );
$var_other = ( new Frontend() )->shortcode( array( 'product_id' => '7100' ) );

$GLOBALS['mjy_queried_id'] = 8000;
$simple = new Frontend();
ob_start();
$simple->render_form();
$simple_auto = (string) ob_get_clean();
$simple_shortcodes = new Frontend();
$simple_first = $simple_shortcodes->shortcode( array( 'product_id' => '8000' ) );
$simple_second = $simple_shortcodes->shortcode( array( 'id' => '8000' ) );
$simple_other = $simple_shortcodes->shortcode( array( 'id' => '8001' ) );
Settings::update( array( 'consent_required' => true ) );
$consent = ( new Frontend() )->shortcode( array( 'id' => '8001' ) );

echo wp_json_encode(
	array(
		'varAuto'      => $var_auto,
		'varExplicit'  => $var_explicit,
		'varOther'     => $var_other,
		'simpleAuto'   => $simple_auto,
		'simpleFirst'  => $simple_first,
		'simpleSecond' => $simple_second,
		'simpleOther'  => $simple_other,
		'consent'      => $consent,
		'data'         => $GLOBALS['mjy_enqueued']['localized']['MojoodyarData'],
	)
);
