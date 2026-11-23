<?php
/**
 * Plugin Name:       موجودیار | لیست انتظار و اعلان بازگشت موجودی
 * Plugin URI:        https://github.com/Masoumiofficial/Navidban
 * Description:       لیست انتظار و اعلان بازگشت موجودی برای ووکامرس، با نمایش خودکار و شورت‌کد. بدون IonCube، بدون Composer، بدون تماس اجباری با سرور بیرونی.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            اتحاد وردپرس
 * Author URI:        https://demo.etehadwp.com/mojoodyar/landing/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mojoodyar
 * Domain Path:       /languages
 * Requires Plugins:   woocommerce
 * WC requires at least: 9.0
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'MOJODYAR_VERSION' ) || class_exists( '\Mojoodyar\Autoloader', false ) || function_exists( 'mojoodyar_bootstrap' ) ) {
	$mjy_existing_dir = defined( 'MOJODYAR_FILE' ) ? basename( dirname( MOJODYAR_FILE ) ) : 'دیگر';

	if ( function_exists( 'register_activation_hook' ) ) {
		register_activation_hook(
			__FILE__,
			static function () use ( $mjy_existing_dir ) {
				$message = sprintf(
					/* translators: %s: active plugin directory name */
					__( 'نسخهٔ دیگری از افزونهٔ موجودیار هم‌اکنون از پوشهٔ «%s» فعال است. لطفاً ابتدا آن نسخه را غیرفعال و حذف کنید و سپس این نسخه را فعال نمایید.', 'mojoodyar' ),
					$mjy_existing_dir
				);

				if ( function_exists( 'header_remove' ) && ! headers_sent() ) {
					header_remove( 'Location' );
				}

				if ( function_exists( 'wp_die' ) ) {
					wp_die(
						esc_html( $message ),
						esc_html__( 'تداخل دو نسخهٔ موجودیار', 'mojoodyar' ),
						array(
							'response'  => 200,
							'back_link' => true,
						)
					);
				}
			}
		);
	}

	if ( function_exists( 'add_action' ) ) {
		add_action(
			'admin_notices',
			static function () use ( $mjy_existing_dir ) {
				if ( function_exists( 'current_user_can' ) && ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				echo '<div class="notice notice-error"><p>';
				echo esc_html(
					sprintf(
						/* translators: %s: active plugin directory name */
						__( 'دو نسخه از افزونهٔ موجودیار هم‌زمان بارگذاری شده‌اند؛ نسخهٔ فعال کنونی در پوشهٔ «%s» است. برای جلوگیری از تداخل، یکی از دو پوشه را غیرفعال و حذف کنید.', 'mojoodyar' ),
						$mjy_existing_dir
					)
				);
				echo '</p></div>';
			}
		);
	}

	return;
}

define( 'MOJODYAR_VERSION', '1.0.0' );
define( 'MOJODYAR_DB_VERSION', '5' );
define( 'MOJODYAR_FILE', __FILE__ );
define( 'MOJODYAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOJODYAR_URL', plugin_dir_url( __FILE__ ) );
define( 'MOJODYAR_BASENAME', plugin_basename( __FILE__ ) );

require_once MOJODYAR_DIR . 'includes/Autoloader.php';

\Mojoodyar\Autoloader::register();

register_activation_hook( __FILE__, array( '\Mojoodyar\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Mojoodyar\Deactivator', 'deactivate' ) );

if ( ! function_exists( 'mojoodyar_bootstrap' ) ) {
	/**
	 * Boot the plugin once all plugins are loaded so WooCommerce can be detected.
	 *
	 * @return void
	 */
	function mojoodyar_bootstrap() {
		\Mojoodyar\Plugin::instance()->boot();
	}
}
add_action( 'plugins_loaded', 'mojoodyar_bootstrap', 20 );
