<?php
/**
 * Plugin bootstrap: wires services to WordPress, registers cron callbacks and
 * keeps the "zero cost when unused" rule for the admin side as well.
 *
 * @package Navidban
 */

namespace Navidban;

use Navidban\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Stock watcher.
	 *
	 * @var StockWatcher|null
	 */
	public $watcher = null;

	/**
	 * Frontend controller.
	 *
	 * @var Frontend|null
	 */
	public $frontend = null;

	/**
	 * REST controller.
	 *
	 * @var Rest|null
	 */
	public $rest = null;

	/**
	 * Ajax controller.
	 *
	 * @var Ajax|null
	 */
	public $ajax = null;

	/**
	 * Whether WooCommerce is available.
	 *
	 * @var bool
	 */
	private $woocommerce_ready = false;

	/**
	 * Instance accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot() {
		add_filter( 'cron_schedules', array( '\\Navidban\\Scheduler', 'schedules' ) );

		$this->woocommerce_ready = class_exists( 'WooCommerce' ) || function_exists( 'wc_get_product' );

		$this->load_textdomain();

		add_action( 'init', array( $this, 'on_init' ) );

		// Cron callbacks are registered even without WooCommerce so a queue
		// flushed after a WooCommerce deactivation does not fatal.
		add_action( Scheduler::HOOK_QUEUE, array( $this, 'run_queue' ) );
		add_action( Scheduler::HOOK_RECONCILE, array( $this, 'run_reconcile' ) );
		add_action( Scheduler::HOOK_PRUNE, array( $this, 'run_prune' ) );

		if ( $this->woocommerce_ready ) {
			$this->watcher = new StockWatcher();
			$this->watcher->register_hooks();

			$this->frontend = new Frontend();
			$this->frontend->register_hooks();

			$this->ajax = new Ajax();
			$this->ajax->register_hooks();
		}

		$this->rest = new Rest();
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );

		if ( is_admin() ) {
			$this->boot_admin();
		}

		// HPOS declaration (WooCommerce 7.1+).
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	private function load_textdomain() {
		if ( function_exists( 'load_plugin_textdomain' ) ) {
			load_plugin_textdomain( 'navidban', false, dirname( NAVIDBAN_BASENAME ) . '/languages' );
		}
	}

	/**
	 * init hook.
	 *
	 * @return void
	 */
	public function on_init() {
		Activator::maybe_upgrade();
		Scheduler::maybe_heal();

		if ( ! $this->woocommerce_ready && Capabilities::can_manage() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! function_exists( 'wp_admin_notice' ) && ! function_exists( 'printf' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html_compat( 'نویدبان برای کار کردن به ووکامرس نیاز دارد. تا فعال‌سازی ووکامرس، فرم لیست انتظار نمایش داده نمی‌شود.' )
		);
	}

	/**
	 * Boot the admin area (only on admin requests).
	 *
	 * @return void
	 */
	private function boot_admin() {
		$admin = new Admin();
		$admin->register_hooks();
	}

	/**
	 * Declare compatibility with High-Performance Order Storage.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NAVIDBAN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NAVIDBAN_FILE, false );
		}
	}

	/**
	 * Cron: dispatch the queue.
	 *
	 * @return void
	 */
	public function run_queue() {
		$stats = Queue::process();

		if ( ! empty( $stats['sent'] ) || ! empty( $stats['failed'] ) ) {
			Logger::log( 'info', 'queue_run', 'sent=' . (int) $stats['sent'] . '|failed=' . (int) $stats['failed'] );
		}
	}

	/**
	 * Cron: reconcile snapshots.
	 *
	 * @return void
	 */
	public function run_reconcile() {
		if ( ! $this->woocommerce_ready || null === $this->watcher ) {
			return;
		}

		$this->watcher->reconcile();
	}

	/**
	 * Cron: retention pruning.
	 *
	 * @return void
	 */
	public function run_prune() {
		$days = (int) Settings::get( 'retention_days', 180 );

		if ( $days > 0 ) {
			WaitlistRepository::prune( $days );
		}

		Logger::prune( 90 );
	}

	/**
	 * Is WooCommerce available for the stock engine?
	 *
	 * @return bool
	 */
	public function woocommerce_ready() {
		return $this->woocommerce_ready;
	}
}

/**
 * esc_html() fallback for the bare harness.
 *
 * @param string $value Value.
 * @return string
 */
function esc_html_compat( $value ) {
	if ( function_exists( 'esc_html' ) ) {
		return (string) esc_html( $value );
	}

	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
