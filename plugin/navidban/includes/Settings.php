<?php
/**
 * Settings storage with working defaults. Activating the plugin must be enough
 * to make it work: every default below is a usable value, not a placeholder.
 *
 * @package Navidban
 */

namespace Navidban;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
final class Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'navidban_settings';

	/**
	 * Cached settings for the request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';
		$admin     = function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '';

		return array(
			// General.
			'form_heading'          => 'خبرم کن',
			'form_intro'            => 'این کالا فعلاً موجود نیست. شمارهٔ خود را بگذارید تا به‌محض بازگشت موجودی خبرتان کنیم.',
			'button_label'          => 'خبرم کن',
			'success_message'       => 'ثبت شد. به‌محض بازگشت موجودی خبرتان می‌کنیم.',
			'consent_label'         => 'با دریافت اعلان از طریق پیامک/ایمیل موافقم و سیاست حریم خصوصی را خوانده‌ام.',
			'consent_required'      => false,
			'privacy_url'           => '',

			// Channels. Iran-first: SMS is the preferred channel, but it can only
			// be used once a gateway is configured, so the effective mode falls
			// back to email until then (see Frontend::channel_mode()).
			'email_enabled'         => true,
			'sms_enabled'           => false,
			'channel_choice'        => 'sms_first', // sms_first | email_first | both.
			'notify_backorders'     => false,

			// Consent / double opt-in.
			// Off by default: one step, no confirmation click. The trade-off is
			// documented in docs/SECURITY.md - with opt-in off, a stranger can
			// register somebody else's address, and for SMS that costs money.
			'double_opt_in'         => false,
			'confirm_ttl_hours'     => 48,

			// Messaging.
			// The From address is an explicit setting, not an afterthought: a
			// sender whose domain does not match the sending host is the most
			// common reason a "successful" wp_mail() lands in spam or is dropped.
			'from_email'            => $admin,
			'from_name'             => $site_name,
			'email_subject'         => 'بازگشت موجودی: {product_name}',
			'email_body'            => "سلام {customer_name}،\n\nخبر خوب: {product_name} دوباره موجود شد.\n{product_url}\n\nاگر دیگر نمی‌خواهید اعلان بگیرید: {unsubscribe_url}\n\n{site_name}",
			'sms_template'          => '{product_name} موجود شد: {product_url} | لغو: {unsubscribe_url}',

			// Fair sending and repeat control.
			'fair_sending'          => true,
			'cooldown_minutes'      => 0,

			// Queue.
			'queue_batch_size'      => 50,
			'queue_per_minute'      => 60,
			'send_window_enabled'   => false,
			'send_window_start'     => 8,
			'send_window_end'       => 22,
			'max_attempts'          => 3,

			// Scheduling.
			'reconcile_interval'    => 15,
			'retention_days'        => 180,

			// Gateways.
			'sms_gateway'           => 'melipayamak',
			'melipayamak_user'      => '',
			'melipayamak_pass'      => '',
			'melipayamak_from'      => '',
			'melipayamak_body_id'   => '',
			'ippanel_token'         => '',
			'ippanel_from'          => '',
			'ippanel_pattern'       => '',
			'kavenegar_api_key'     => '',
			'kavenegar_sender'      => '',
			'generic_url'           => '',
			'generic_token'         => '',
			'generic_method'        => 'POST',
			'generic_payload'       => '{"to":"{to}","message":"{message}"}',

			// Advanced.
			'delete_data_on_uninstall' => false,
			'debug_log'                => false,
			'site_name'                => $site_name,
		);
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
			$stored      = is_array( $stored ) ? $stored : array();
			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Boolean helper.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function enabled( $key ) {
		return (bool) self::get( $key, false );
	}

	/**
	 * Persist a partial update.
	 *
	 * @param array $values Values.
	 * @return array The stored settings.
	 */
	public static function update( array $values ) {
		$merged = array_merge( self::all(), $values );

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $merged, false );
		}

		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Reset the in-request cache (used by tests and after direct option writes).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
