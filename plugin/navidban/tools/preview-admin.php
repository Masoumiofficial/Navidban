<?php
/**
 * Render the five admin screens into one JSON document.
 *
 * Why this exists: this repository has no browser, so the glass redesign cannot
 * be screenshotted here. Instead the real markup produced by the real views is
 * dumped to JSON, and `tools/make-preview.py` wraps it in a standalone HTML
 * file with the real stylesheet inlined, so a human can open it and look.
 *
 * This is a *preview* tool: it is not part of the release (tools/ is excluded
 * from the ZIP) and it asserts nothing.
 *
 * Usage:
 *   node tools/smoke-php.mjs --engine=wasm tools/preview-admin.php --json-out=docs/dev/preview.json
 *
 * @package Navidban
 */

require_once __DIR__ . '/../tests/stubs/wp.php';
require_once __DIR__ . '/../tests/stubs/woocommerce.php';
require_once __DIR__ . '/../tests/stubs/admin.php';
require_once NAVIDBAN_DIR . 'includes/Autoloader.php';
\Navidban\Autoloader::register();

use Navidban\Activator;
use Navidban\Admin\Admin;
use Navidban\WaitlistRepository;

Activator::activate( false );

$GLOBALS['nvb_caps'] = array( 'navidban_manage' => true, 'navidban_view_reports' => true );

// A little sample data so the screens are not empty.
$GLOBALS['nvb_options']['navidban_settings'] = array_merge(
	\Navidban\Settings::defaults(),
	array( 'sms_enabled' => true, 'melipayamak_user' => 'demo', 'melipayamak_pass' => 'x' )
);
\Navidban\Settings::flush_cache();

foreach ( array( 'sara@example.com', 'mehdi@example.com', 'reza@example.com' ) as $index => $email ) {
	$row = WaitlistRepository::create(
		array(
			'product_id' => 501,
			'channel'    => 0 === $index ? 'sms' : 'email',
			'email'      => $email,
			'phone'      => 0 === $index ? '09121234567' : '',
		)
	);

	if ( 0 === $index ) {
		WaitlistRepository::set_status( (int) $row['id'], 'confirmed' );
	}
}

$admin   = new Admin();
$screens = array(
	'dashboard' => 'render_dashboard',
	'waitlists' => 'render_waitlists',
	'settings'  => 'render_settings',
	'tools'     => 'render_tools',
	'help'      => 'render_help',
);

$out = array();

foreach ( $screens as $key => $method ) {
	ob_start();
	$admin->$method();
	$out[ $key ] = (string) ob_get_clean();
}

// Storefront form, rendered with the same view the plugin uses.
$nvb_settings          = \Navidban\Settings::all();
$nvb_settings['mode']  = 'both';
$settings              = array(
	'mode'        => 'both',
	'heading'     => $nvb_settings['form_heading'],
	'intro'       => $nvb_settings['form_intro'],
	'notice'      => '',
	'button'      => $nvb_settings['button_label'],
	'consent_req' => ! empty( $nvb_settings['consent_required'] ),
	'consent'     => $nvb_settings['consent_label'],
	'privacy'     => '',
);
$product_id            = 501;
$variations            = array();
$default_channel       = 'sms';
$user_id               = 0;
$prefill_email         = '';
$prefill_phone         = '';

ob_start();
include NAVIDBAN_DIR . 'admin/views/frontend-form.php';
$out['form'] = (string) ob_get_clean();

// The email skin, with representative content.
$out['email'] = \Navidban\Logic\EmailSkin::render(
	'کفش دویدن نایک ایر زوم پگاسوس',
	"سلام مریم،\n\nخبر خوب: «کفش دویدن نایک ایر زوم پگاسوس» دوباره موجود شد و می‌توانید سفارش دهید.\n\nتعداد موجود محدود است؛ اگر می‌خواهید، زودتر اقدام کنید.",
	array(
		'site_name'       => 'فروشگاه نمونهٔ اتحاد',
		'product_name'    => 'کفش دویدن نایک ایر زوم پگاسوس',
		'product_url'     => 'https://shop.example/product/pegasus',
		'price'           => '۲٬۴۵۰٬۰۰۰ تومان',
		'unsubscribe_url' => 'https://shop.example/?navidban_unsubscribe=demo',
		'store_url'       => 'https://shop.example/',
		'is_confirmation' => '0',
	)
);

echo json_encode(
	array(
		'version'  => NAVIDBAN_VERSION,
		'css_path' => 'assets/css/navidban-admin.css',
		'screens'  => $out,
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
