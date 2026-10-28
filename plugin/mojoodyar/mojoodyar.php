<?php
/**
 * Plugin Name:       موجودیار | لیست انتظار و اعلان بازگشت موجودی
 * Plugin URI:        https://github.com/Masoumiofficial/Mojoodyar
 * Description:       لیست انتظار و اعلان بازگشت موجودی برای ووکامرس. فقط در صفحهٔ محصولِ ناموجود بارگذاری می‌شود. بدون IonCube، بدون Composer، بدون تماس اجباری با سرور بیرونی.
 * Version:           2.7.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            اتحاد وردپرس
 * Author URI:        https://github.com/Masoumiofficial/Mojoodyar
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mojoodyar
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:      10.4
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

define( 'MOJODYAR_VERSION', '2.7.1' );
define( 'MOJODYAR_DB_VERSION', '4' );
define( 'MOJODYAR_FILE', __FILE__ );
define( 'MOJODYAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOJODYAR_URL', plugin_dir_url( __FILE__ ) );
define( 'MOJODYAR_BASENAME', plugin_basename( __FILE__ ) );

require_once MOJODYAR_DIR . 'includes/Autoloader.php';

\Mojoodyar\Autoloader::register();

register_activation_hook( __FILE__, array( '\Mojoodyar\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Mojoodyar\Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded so WooCommerce can be detected.
 *
 * @return void
 */
function mojoodyar_bootstrap() {
	\Mojoodyar\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'mojoodyar_bootstrap', 20 );
