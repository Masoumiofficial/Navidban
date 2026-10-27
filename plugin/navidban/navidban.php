<?php
/**
 * Plugin Name:       نویدبان | لیست انتظار و اعلان بازگشت موجودی
 * Plugin URI:        https://github.com/Masoumiofficial/Navidban
 * Description:       لیست انتظار و اعلان بازگشت موجودی برای ووکامرس. فقط در صفحهٔ محصولِ ناموجود بارگذاری می‌شود. بدون IonCube، بدون Composer، بدون تماس اجباری با سرور بیرونی.
 * Version:           1.0.10
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            اتحاد وردپرس
 * Author URI:        https://github.com/Masoumiofficial/Navidban
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       navidban
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:      10.4
 *
 * @package Navidban
 */

defined( 'ABSPATH' ) || exit;

define( 'NAVIDBAN_VERSION', '1.0.10' );
define( 'NAVIDBAN_DB_VERSION', '2' );
define( 'NAVIDBAN_FILE', __FILE__ );
define( 'NAVIDBAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NAVIDBAN_URL', plugin_dir_url( __FILE__ ) );
define( 'NAVIDBAN_BASENAME', plugin_basename( __FILE__ ) );

require_once NAVIDBAN_DIR . 'includes/Autoloader.php';

\Navidban\Autoloader::register();

register_activation_hook( __FILE__, array( '\Navidban\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Navidban\Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded so WooCommerce can be detected.
 *
 * @return void
 */
function navidban_bootstrap() {
	\Navidban\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'navidban_bootstrap', 20 );
