<?php
/**
 * Admin panel: dashboard, waitlist table, settings, tools and help.
 *
 * Every screen is RTL/Persian, uses Jalali dates and Persian digits, and every
 * write path checks a capability *and* a nonce.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Admin;

use Mojoodyar\CallLog;
use Mojoodyar\Capabilities;
use Mojoodyar\Consent;
use Mojoodyar\Database;
use Mojoodyar\Logger;
use Mojoodyar\Notifier;
use Mojoodyar\Queue;
use Mojoodyar\Settings;
use Mojoodyar\Stats;
use Mojoodyar\StockWatcher;
use Mojoodyar\Support\Csv;
use Mojoodyar\Support\Jalali;
use Mojoodyar\Support\Masking;
use Mojoodyar\Support\Persian;
use Mojoodyar\Support\Validator;
use Mojoodyar\WaitlistRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
final class Admin {

	/**
	 * Menu slug prefix.
	 *
	 * @var string
	 */
	const SLUG = 'mojoodyar';

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	const GROUP = 'mojoodyar_settings_group';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_mojoodyar_export', array( $this, 'export_csv' ) );
		add_action( 'admin_post_mojoodyar_export_stats', array( $this, 'export_stats_csv' ) );
		add_action( 'admin_post_mojoodyar_tool', array( $this, 'handle_tool' ) );
		add_action( 'admin_post_mojoodyar_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_mojoodyar_call', array( $this, 'handle_call' ) );
		add_filter( 'plugin_action_links_' . MOJODYAR_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG . '-settings' );

		array_unshift( $links, '<a href="' . esc_url_compat( $url ) . '">تنظیمات</a>' );

		return $links;
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			'موجودیار',
			'موجودیار',
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-bell',
			56
		);

		add_submenu_page( self::SLUG, 'داشبورد', 'داشبورد', Capabilities::MANAGE, self::SLUG, array( $this, 'render_dashboard' ) );
		add_submenu_page( self::SLUG, 'آمار', 'آمار', Capabilities::MANAGE, self::SLUG . '-stats', array( $this, 'render_stats' ) );
		add_submenu_page( self::SLUG, 'لیست انتظارها', 'لیست انتظارها', Capabilities::MANAGE, self::SLUG . '-waitlists', array( $this, 'render_waitlists' ) );
		add_submenu_page( self::SLUG, 'تنظیمات', 'تنظیمات', Capabilities::MANAGE, self::SLUG . '-settings', array( $this, 'render_settings' ) );
		add_submenu_page( self::SLUG, 'ابزارها', 'ابزارها', Capabilities::MANAGE, self::SLUG . '-tools', array( $this, 'render_tools' ) );
		add_submenu_page( self::SLUG, 'راهنما', 'راهنما', Capabilities::MANAGE, self::SLUG . '-help', array( $this, 'render_help' ) );

		// Reachable by URL for the no-JavaScript path, but never shown in the menu.
		add_submenu_page( self::SLUG, 'پیگیری تلفنی', 'پیگیری تلفنی', Capabilities::MANAGE, self::SLUG . '-followup', array( $this, 'render_followup' ) );

		if ( function_exists( 'remove_submenu_page' ) ) {
			remove_submenu_page( self::SLUG, self::SLUG . '-followup' );
		}
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) && false === strpos( (string) $hook, 'mojoodyar' ) ) {
			return;
		}

		wp_enqueue_style( 'mojoodyar-admin', MOJODYAR_URL . 'assets/css/mojoodyar-admin.css', array(), MOJODYAR_VERSION );
		wp_enqueue_script( 'mojoodyar-admin', MOJODYAR_URL . 'assets/js/mojoodyar-admin.js', array(), MOJODYAR_VERSION, true );
	}

	/**
	 * Register the settings option with a strict sanitiser.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitise every settings field. Unknown keys are dropped.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$current  = Settings::all();
		$defaults = Settings::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = $current;

		$text_fields = array(
			'form_heading',
			'button_label',
			'success_message',
			'consent_label',
			'email_subject',
			'sms_template',
			'melipayamak_user',
			'melipayamak_from',
			'melipayamak_body_id',
			'sms_pattern_template',
			'ippanel_from',
			'kavenegar_sender',
			'generic_payload',
		);

		foreach ( $text_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = Validator::plain_text( $input[ $key ], 500 );
			}
		}

		if ( isset( $input['from_name'] ) ) {
			$out['from_name'] = Validator::plain_text( $input['from_name'], 120 );
		}

		if ( isset( $input['from_email'] ) ) {
			$candidate = strtolower( trim( (string) $input['from_email'] ) );

			if ( '' === $candidate ) {
				$out['from_email'] = '';
			} elseif ( Validator::is_email( $candidate ) ) {
				$out['from_email'] = $candidate;
			} else {
				add_settings_error( 'mojoodyar', 'from_email', 'نشانی فرستنده معتبر نیست؛ مقدار قبلی نگه داشته شد.', 'error' );
			}
		}

		$textarea_fields = array( 'form_intro', 'email_body' );
		foreach ( $textarea_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$value = (string) $input[ $key ];
				$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $value );
				$out[ $key ] = trim( (string) $value );
			}
		}

		$bool_fields = array(
			'consent_required',
			'email_enabled',
			'sms_enabled',
			'form_email_field',
			'form_phone_field',
			'form_name_field',
			'form_name_required',
			'notify_backorders',
			'double_opt_in',
			'fair_sending',
			'send_window_enabled',
			'delete_data_on_uninstall',
			'debug_log',
		);

		foreach ( $bool_fields as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}

		if ( ! $out['email_enabled'] && ! $out['sms_enabled'] ) {
			// Never let the site save itself into "no channel at all".
			$out['email_enabled'] = true;
			add_settings_error( 'mojoodyar', 'no_channel', 'حداقل یک کانال اعلان باید فعال بماند؛ ایمیل دوباره فعال شد.', 'warning' );
		}

		if ( ! $out['form_email_field'] && ! $out['form_phone_field'] ) {
			// A form with no input collects nothing. Keep the e-mail field so the
			// store notices a warning instead of a silently useless form.
			$out['form_email_field'] = true;
			add_settings_error( 'mojoodyar', 'no_form_field', 'فرم باید حداقل یک فیلد داشته باشد؛ فیلد ایمیل دوباره روشن شد.', 'warning' );
		}

		$int_ranges = array(
			'confirm_ttl_hours'   => array( 1, 168, 48 ),
			'queue_batch_size'    => array( 1, 500, 50 ),
			'queue_per_minute'    => array( 1, 600, 60 ),
			'send_window_start'   => array( 0, 23, 8 ),
			'send_window_end'     => array( 0, 23, 22 ),
			'max_attempts'        => array( 1, 3, 3 ),
			'reconcile_interval'  => array( 5, 1440, 15 ),
			'retention_days'      => array( 0, 3650, 180 ),
			'cooldown_minutes'    => array( 0, 1440, 0 ),
		);

		foreach ( $int_ranges as $key => $range ) {
			$value = isset( $input[ $key ] ) ? (int) $input[ $key ] : (int) $defaults[ $key ];
			$value = max( $range[0], min( $range[1], $value ) );
			$out[ $key ] = $value;
		}

		if ( isset( $input['channel_choice'] ) && in_array( $input['channel_choice'], array( 'email_first', 'sms_first', 'both' ), true ) ) {
			$out['channel_choice'] = $input['channel_choice'];
		}

		if ( isset( $input['sms_gateway'] ) && in_array( $input['sms_gateway'], array( 'melipayamak', 'ippanel', 'kavenegar', 'generic' ), true ) ) {
			$out['sms_gateway'] = $input['sms_gateway'];
		}

		if ( isset( $input['generic_method'] ) && in_array( strtoupper( $input['generic_method'] ), array( 'POST', 'GET' ), true ) ) {
			$out['generic_method'] = strtoupper( $input['generic_method'] );
		}

		if ( isset( $input['privacy_url'] ) ) {
			$out['privacy_url'] = esc_url_raw_compat( $input['privacy_url'] );
		}

		// Secrets: stored as typed, never echoed back in full.
		$secret_fields = array(
			'melipayamak_pass',
			'ippanel_token',
			'kavenegar_api_key',
			'generic_token',
			'generic_url',
			'melipayamak_body_id',
			'ippanel_pattern',
		);

		foreach ( $secret_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$value = trim( (string) $input[ $key ] );
				if ( '' !== $value ) {
					$out[ $key ] = preg_replace( '/\s+/', '', $value );
				}
			}
		}

		if ( isset( $input['generic_url'] ) ) {
			$check = \Mojoodyar\Logic\HttpGuard::check( (string) $input['generic_url'] );
			if ( ! $check['ok'] && '' !== trim( (string) $input['generic_url'] ) ) {
				add_settings_error( 'mojoodyar', 'generic_url', 'نشانی درگاه عمومی پذیرفته نشد: باید https و عمومی باشد (' . $check['reason'] . ').', 'error' );
				$out['generic_url'] = '';
			}
		}

		if ( isset( $input['site_name'] ) ) {
			$out['site_name'] = Validator::plain_text( $input['site_name'], 190 );
		}

		// Pattern text is advice-checked on save: the gateway rejects a badly
		// numbered template at send time, and a store that only learns about it
		// from a failed send wastes real money and real subscribers.
		if ( isset( $input['sms_pattern_template'] ) ) {
			$pattern = trim( (string) $out['sms_pattern_template'] );

			if ( '' !== $pattern ) {
				$findings = \Mojoodyar\Logic\SmsPattern::validate( $pattern, (string) $out['site_name'] );

				foreach ( $findings as $finding ) {
					add_settings_error(
						'mojoodyar',
						'sms_pattern_' . $finding['code'],
						( 'error' === $finding['level'] ? 'متن الگوی پیامک: ' : 'متن الگوی پیامک (هشدار): ' ) . $finding['message'],
						'error' === $finding['level'] ? 'error' : 'warning'
					);
				}
			}
		}

		Settings::flush_cache();

		return $out;
	}

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->guard();

		$counts      = WaitlistRepository::counts();
		$queue       = Queue::stats();
		$last_run    = (int) get_option( 'mojoodyar_last_reconcile', 0 );
		$tables      = Database::inspect();
		$conversion  = $this->conversion_rate();

		$data = array(
			'counts'     => $counts,
			'queue'      => $queue,
			'last_run'   => $last_run,
			'tables'     => $tables,
			'conversion' => $conversion,
		);

		$this->view( 'dashboard', $data );
	}

	/**
	 * Notified → converted ratio, computed from WooCommerce order lookup by phone/email.
	 *
	 * @return array{rate:float,notified:int,converted:int,available:bool}
	 */
	private function conversion_rate() {
		$notified = (int) ( WaitlistRepository::counts()['notified'] ?? 0 );

		if ( ! function_exists( 'wc_get_orders' ) || $notified <= 0 ) {
			return array(
				'rate'      => 0.0,
				'notified'  => $notified,
				'converted' => 0,
				'available' => false,
			);
		}

		$rows = WaitlistRepository::query(
			array(
				'status'   => 'notified',
				'per_page' => 200,
				'page'     => 1,
			)
		);

		$converted = 0;

		foreach ( $rows['rows'] as $row ) {
			$args = array( 'limit' => 1, 'return' => 'ids' );

			if ( '' !== (string) $row['email'] ) {
				$args['billing_email'] = (string) $row['email'];
			} else {
				// Searching by phone is not exposed by wc_get_orders; use meta query.
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_billing_phone',
						'value' => (string) $row['phone'],
					),
				);
			}

			$orders = wc_get_orders( $args );

			if ( ! empty( $orders ) ) {
				$converted++;
			}
		}

		$sample = count( $rows['rows'] );

		return array(
			'rate'      => $sample > 0 ? round( $converted * 100 / $sample, 1 ) : 0.0,
			'notified'  => $notified,
			'converted' => $converted,
			'available' => true,
		);
	}

	/**
	 * Render the statistics screen.
	 *
	 * @return void
	 */
	public function render_stats() {
		$this->guard();

		$range = Stats::current_range();

		$this->view(
			'stats',
			array(
				'report' => Stats::report( $range ),
				'range'  => $range,
			)
		);
	}

	/**
	 * Download the same report as CSV (one flat sheet, UTF-8 BOM).
	 *
	 * @return void
	 */
	public function export_stats_csv() {
		$this->guard();

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field_compat( wp_unslash_compat( $_GET['_wpnonce'] ) ), 'mojoodyar_export_stats' ) ) {
			wp_die_compat( 'درخواست معتبر نیست.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$range = Stats::normalize_range( isset( $_GET['range'] ) ? (int) $_GET['range'] : Stats::DEFAULT_RANGE );
		// phpcs:enable

		$report = Stats::report( $range );

		nocache_headers_compat();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="mojoodyar-stats-' . (int) $range . 'd-' . gmdate( 'Ymd-His' ) . '.csv"' );

		echo Csv::bom(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$out = fopen( 'php://output', 'w' );

		fputcsv( $out, array( 'بخش', 'شاخص', 'مقدار' ) );

		$rows = array(
			array( 'بازه', 'روز', (string) $range ),
			array( 'ثبت‌ها', 'ثبت تازه', (string) $report['summary']['total'] ),
			array( 'ثبت‌ها', 'در انتظار تأیید', (string) $report['summary']['pending'] ),
			array( 'ثبت‌ها', 'تأییدشده', (string) $report['summary']['confirmed'] ),
			array( 'ثبت‌ها', 'اعلان‌شده', (string) $report['summary']['notified'] ),
			array( 'ثبت‌ها', 'لغو اشتراک', (string) $report['summary']['unsubscribed'] ),
			array( 'ثبت‌ها', 'منقضی', (string) $report['summary']['expired'] ),
			array( 'ثبت‌ها', 'درصد تأیید', (string) $report['summary']['confirm_rate'] ),
			array( 'صف', 'ارسال‌شده در بازه', (string) $report['summary']['sent'] ),
			array( 'صف', 'ناموفق در بازه', (string) $report['summary']['failed'] ),
			array( 'کانال', 'پیامک', (string) ( isset( $report['channels']['sms'] ) ? $report['channels']['sms'] : 0 ) ),
			array( 'کانال', 'ایمیل', (string) ( isset( $report['channels']['email'] ) ? $report['channels']['email'] : 0 ) ),
		);

		foreach ( $report['signups']['values'] as $index => $value ) {
			$rows[] = array(
				'ثبت روزانه',
				(string) $report['signups']['keys'][ $index ],
				(string) (int) $value,
			);
		}

		foreach ( $report['notified']['values'] as $index => $value ) {
			$rows[] = array(
				'اعلان روزانه',
				(string) $report['notified']['keys'][ $index ],
				(string) (int) $value,
			);
		}

		foreach ( $report['products'] as $product ) {
			$rows[] = array(
				'محصول',
				(string) $product['name'],
				(string) (int) $product['total'],
			);
		}

		foreach ( $report['rejections'] as $rejection ) {
			$rows[] = array(
				'ردشده',
				(string) $rejection['label'],
				(string) (int) $rejection['count'],
			);
		}

		foreach ( $rows as $row ) {
			// CSV keeps Latin digits on purpose so spreadsheets keep the values numeric.
			fputcsv( $out, Csv::row( $row ) );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Render the waitlist screen.
	 *
	 * @return void
	 */
	public function render_waitlists() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$args = array(
			'product_id' => isset( $_GET['product_id'] ) ? (int) $_GET['product_id'] : 0,
			'status'     => isset( $_GET['status'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['status'] ) ) : '',
			'channel'    => isset( $_GET['channel'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['channel'] ) ) : '',
			'called'     => isset( $_GET['called'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['called'] ) ) : '',
			'from'       => isset( $_GET['from'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['from'] ) ) : '',
			'to'         => isset( $_GET['to'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['to'] ) ) : '',
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['s'] ) ) : '',
			'page'       => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1,
			'per_page'   => 20,
			'orderby'    => isset( $_GET['orderby'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['orderby'] ) ) : 'created_at',
			'order'      => isset( $_GET['order'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['order'] ) ) : 'DESC',
		);
		// phpcs:enable

		$result = WaitlistRepository::query( $args );

		$this->view(
			'waitlists',
			array(
				'rows'     => $result['rows'],
				'total'    => $result['total'],
				'args'     => $args,
				'per_page' => 20,
			)
		);
	}

	/**
	 * Render the standalone phone follow-up screen.
	 *
	 * @return void
	 */
	public function render_followup() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, ID only.
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		// phpcs:enable

		$row = $id > 0 ? WaitlistRepository::get( $id ) : null;

		if ( null === $row ) {
			wp_die_compat( 'این ردیف پیدا نشد؛ شاید حذف شده باشد.' );
		}

		$this->view(
			'followup',
			array(
				'row'     => $row,
				'history' => CallLog::history( $id ),
			)
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->guard();

		$this->view(
			'settings',
			array(
				'settings' => Settings::all(),
				'group'    => self::GROUP,
			)
		);
	}

	/**
	 * Render the tools screen.
	 *
	 * @return void
	 */
	public function render_tools() {
		$this->guard();

		$this->view(
			'tools',
			array(
				'tables'   => Database::inspect(),
				'queue'    => Queue::stats(),
				'last_run' => (int) get_option( 'mojoodyar_last_reconcile', 0 ),
			)
		);
	}

	/**
	 * Render the help screen.
	 *
	 * @return void
	 */
	public function render_help() {
		$this->guard();

		$this->view( 'help', array() );
	}

	/**
	 * Open a screen: the glass app bar, brand mark and tab strip.
	 *
	 * Views call this through `$this` (they are included inside a method of this
	 * class), which keeps the markup in one place instead of five.
	 *
	 * @param string $active   Active tab key.
	 * @param string $title    Screen title.
	 * @param string $subtitle One-line description.
	 * @return void
	 */
	private function open_screen( $active, $title, $subtitle ) {
		$tabs = array(
			'dashboard' => array( 'داشبورد', self::SLUG ),
			'stats'     => array( 'آمار', self::SLUG . '-stats' ),
			'waitlists' => array( 'لیست انتظارها', self::SLUG . '-waitlists' ),
			'settings'  => array( 'تنظیمات', self::SLUG . '-settings' ),
			'tools'     => array( 'ابزارها', self::SLUG . '-tools' ),
			'help'      => array( 'راهنما', self::SLUG . '-help' ),
		);

		echo '<div class="wrap mjy-wrap" dir="rtl">';

		echo '<header class="mjy-hero">';
		echo '<span class="mjy-hero__mark" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M12 3.5a5.5 5.5 0 0 0-5.5 5.5v3.2c0 .9-.3 1.6-.9 2.3L4.4 16h15.2l-1.2-1.5c-.6-.7-.9-1.4-.9-2.3V9A5.5 5.5 0 0 0 12 3.5Z" /><path d="M9.8 19a2.3 2.3 0 0 0 4.4 0" /></svg></span>';
		echo '<div class="mjy-hero__text">';
		echo '<h1 class="mjy-hero__title">' . esc_html( (string) $title ) . '<small>' . esc_html( (string) $subtitle ) . '</small></h1>';
		echo '</div>';
		echo '<div class="mjy-hero__meta">';
		// Persian::number() formats a number, so a version string has to be
		// converted segment by segment — otherwise "1.0.4" prints as "۱".
		$mjy_version = implode( '.', array_map( array( Persian::class, 'number' ), explode( '.', (string) MOJODYAR_VERSION ) ) );
		echo '<span class="mjy-chip">نسخهٔ ' . esc_html( $mjy_version ) . '</span>';
		echo '<span class="mjy-chip mjy-chip--accent">اتحاد وردپرس</span>';
		echo '</div>';
		echo '</header>';

		echo '<nav class="mjy-tabs-wrap" aria-label="بخش‌های موجودیار"><ul class="mjy-tabs">';
		foreach ( $tabs as $key => $tab ) {
			$current = ( $key === $active ) ? ' aria-current="page"' : '';
			echo '<li><a href="' . esc_url_compat( admin_url( 'admin.php?page=' . $tab[1] ) ) . '"' . $current . '>' . esc_html( $tab[0] ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute built above.
		}
		echo '</ul></nav>';
	}

	/**
	 * Close a screen.
	 *
	 * @return void
	 */
	private function close_screen() {
		echo '<p class="mjy-footer">موجودیار — محصولی از اتحاد وردپرس</p>';
		echo '</div>';
	}

	/**
	 * Capability guard for every screen and action.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! Capabilities::can_manage() ) {
			wp_die_compat( 'دسترسی به این بخش برای شما مجاز نیست.' );
		}
	}

	/**
	 * Include a view file.
	 *
	 * @param string $name View name.
	 * @param array  $data Variables exposed to the view.
	 * @return void
	 */
	private function view( $name, array $data ) {
		$file = MOJODYAR_DIR . 'admin/views/' . basename( (string) $name ) . '.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- view scope only.
		extract( $data, EXTR_SKIP );

		include $file;
	}

	/**
	 * CSV export (UTF-8 BOM, formula-injection hardened, Latin digits).
	 *
	 * @return void
	 */
	public function export_csv() {
		$this->guard();

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field_compat( wp_unslash_compat( $_GET['_wpnonce'] ) ), 'mojoodyar_export' ) ) {
			wp_die_compat( 'درخواست معتبر نیست.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$args = array(
			'product_id' => isset( $_GET['product_id'] ) ? (int) $_GET['product_id'] : 0,
			'status'     => isset( $_GET['status'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['status'] ) ) : '',
			'channel'    => isset( $_GET['channel'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['channel'] ) ) : '',
			'called'     => isset( $_GET['called'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['called'] ) ) : '',
			'from'       => isset( $_GET['from'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['from'] ) ) : '',
			'to'         => isset( $_GET['to'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['to'] ) ) : '',
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['s'] ) ) : '',
			'per_page'   => 200,
			'page'       => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1,
		);
		// phpcs:enable

		$result = WaitlistRepository::query( $args );

		nocache_headers_compat();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="mojoodyar-waitlists-' . gmdate( 'Ymd-His' ) . '.csv"' );

		echo Csv::bom(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				'شناسه',
				'نام و نام خانوادگی',
				'محصول',
				'تنوع',
				'کانال',
				'ایمیل',
				'موبایل',
				'وضعیت',
				'تماس گرفته شد',
				'تاریخ تماس (شمسی)',
				'تاریخ ثبت (میلادی)',
				'تاریخ ثبت (شمسی)',
				'تاریخ تأیید (میلادی)',
				'تاریخ اعلان (میلادی)',
			)
		);

		foreach ( $result['rows'] as $row ) {
			$cells = array(
				$row['id'],
				(string) ( isset( $row['full_name'] ) ? $row['full_name'] : '' ),
				Notifier::product_name( (int) $row['product_id'], (int) $row['variation_id'] ),
				$row['variation_id'],
				$row['channel'],
				$row['email'],
				$row['phone'],
				$row['status'],
				empty( $row['called_at'] ) ? 'خیر' : 'بله',
				empty( $row['called_at'] ) ? '' : Jalali::format( (string) $row['called_at'], 'Y/m/d H:i' ),
				$row['created_at'],
				Jalali::format( (string) $row['created_at'], 'Y/m/d' ),
				(string) $row['confirmed_at'],
				(string) $row['notified_at'],
			);

			// CSV keeps Latin digits on purpose so accounting tools keep working.
			fputcsv( $out, Csv::row( $cells ) );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Handle the one-click tools.
	 *
	 * @return void
	 */
	public function handle_tool() {
		$this->guard();

		$tool = isset( $_POST['tool'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['tool'] ) ) : '';

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field_compat( wp_unslash_compat( $_POST['_wpnonce'] ) ), 'mojoodyar_tool' ) ) {
			wp_die_compat( 'درخواست معتبر نیست.' );
		}

		$message = '';

		switch ( $tool ) {
			case 'rebuild_snapshots':
				$watcher = new StockWatcher();
				$count   = $watcher->rebuild_snapshots();
				$message = sprintf( 'تصویر موجودی برای %s ردیف بازسازی شد. هیچ اعلانی ارسال نشد.', Persian::number( $count ) );
				break;

			case 'run_reconcile':
				$watcher = new StockWatcher();
				$result  = $watcher->reconcile( true );
				$message = sprintf(
					'پایش انجام شد: %s محصول بررسی، %s گذار شناسایی و %s اعلان در صف قرار گرفت.%s',
					Persian::number( $result['scanned'] ),
					Persian::number( $result['transitions'] ),
					Persian::number( $result['queued'] ),
					$result['first_run'] ? ' (اجرای نخست: فقط تصویربرداری شد)' : ''
				);
				break;

			case 'flush_queue':
				$stats = Queue::process( 200 );
				$message = sprintf(
					'پردازش صف: %s ارسال، %s خطا%s',
					Persian::number( $stats['sent'] ),
					Persian::number( $stats['failed'] ),
					'' !== $stats['skipped'] ? ' (دلیل توقف: ' . $stats['skipped'] . ')' : ''
				);
				break;

			case 'empty_queue':
				$deleted = Queue::empty_queue();
				$message = sprintf( 'صف تخلیه شد: %s ردیف حذف شد.', Persian::number( $deleted ) );
				break;

			case 'prune':
				$days    = (int) Settings::get( 'retention_days', 180 );
				$deleted = WaitlistRepository::prune( $days );
				Logger::prune( 90 );
				$message = sprintf( 'پاک‌سازی انجام شد: %s ردیف قدیمی‌تر از %s روز حذف شد.', Persian::number( $deleted ), Persian::number( $days ) );
				break;

			case 'test_email':
			case 'test_sms':
				$message = $this->send_test( 'test_email' === $tool ? 'email' : 'sms' );
				break;

			default:
				$message = 'ابزار ناشناخته است.';
				break;
		}

		Logger::log( 'info', 'admin_tool', $tool );

		set_transient_compat( 'mojoodyar_tool_notice', $message, 60 );

		wp_safe_redirect_compat( admin_url( 'admin.php?page=' . self::SLUG . '-tools' ) );
		exit;
	}

	/**
	 * Send a test message to the shop admin.
	 *
	 * @param string $channel Channel.
	 * @return string Human message.
	 */
	private function send_test( $channel ) {
		$email = '';
		if ( function_exists( 'get_option' ) ) {
			$email = (string) get_option( 'admin_email', '' );
		}

		$phone = isset( $_POST['test_phone'] ) ? Validator::normalize_phone( sanitize_text_field_compat( wp_unslash_compat( $_POST['test_phone'] ) ) ) : '';

		$context = array(
			'id'           => 0,
			'token'        => str_repeat( 'a', 64 ),
			'product_id'   => 0,
			'variation_id' => 0,
			'user_id'      => 0,
			'email'        => $email,
			'phone'        => $phone,
		);

		$instance = Notifier::channel( $channel );

		if ( null === $instance ) {
			return 'کانال پشتیبانی نمی‌شود.';
		}

		if ( ! $instance->is_configured() ) {
			return 'این کانال پیکربندی نشده است. ابتدا تنظیمات درگاه را کامل کنید.';
		}

		$to = ( 'sms' === $channel ) ? $phone : $email;

		if ( '' === $to ) {
			return 'sms' === $channel ? 'شمارهٔ آزمایشی را وارد کنید.' : 'ایمیل مدیر در تنظیمات وردپرس خالی است.';
		}

		$result = $instance->send(
			$to,
			'پیام آزمایشی موجودیار',
			'این یک پیام آزمایشی از افزونهٔ موجودیار است. اگر آن را دریافت کردید، درگاه درست تنظیم شده است.',
			array( 'context' => $context )
		);

		if ( true === $result ) {
			if ( 'email' !== $channel ) {
				return 'پیام آزمایشی ارسال شد.';
			}

			// wp_mail() returning true means "the local mail transport accepted
			// the message", NOT "the message reached a mailbox". Saying "ارسال شد"
			// here is technically true and practically misleading, so the message
			// says exactly what was verified.
			$to_note = '' !== $email ? ' به ' . $email : '';

			return 'wp_mail پیام را پذیرفت' . $to_note . ' و به میل سرور تحویل داد. '
				. 'این یعنی وردپرس خطا نگرفت؛ ولی رسیدن به صندوق ورودی را تضمین نمی‌کند. '
				. 'اگر ایمیل نیامد: پوشهٔ اسپم را ببینید، و اگر افزونهٔ SMTP فعال است تنظیماتش را بررسی کنید.';
		}

		$message = is_object( $result ) && method_exists( $result, 'get_error_message' )
			? $result->get_error_message()
			: (string) $result;

		$hint = ( 'email' === $channel && class_exists( '\\Mojoodyar\\Channels\\EmailChannel' ) )
			? \Mojoodyar\Channels\EmailChannel::hint()
			: '';

		if ( '' !== $hint ) {
			$message .= ' — ' . $hint;
		}

		return 'ارسال نشد: ' . $message;
	}

	/**
	 * Bulk actions on the waitlist table.
	 *
	 * @return void
	 */
	public function handle_bulk() {
		$this->guard();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field_compat( wp_unslash_compat( $_POST['_wpnonce'] ) ), 'mojoodyar_bulk' ) ) {
			wp_die_compat( 'درخواست معتبر نیست.' );
		}

		$action = isset( $_POST['bulk_action'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : array();
		$ids    = array_values( array_filter( $ids ) );

		$affected = 0;

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'confirm':
					if ( WaitlistRepository::set_status( $id, 'confirmed' ) ) {
						$affected++;
					}
					break;

				case 'unsubscribe':
					if ( WaitlistRepository::set_status( $id, 'unsubscribed' ) ) {
						$affected++;
					}
					break;

				case 'called':
					if ( WaitlistRepository::set_called( $id, true, get_current_user_id_compat() ) ) {
						$affected++;
					}
					break;

				case 'uncalled':
					if ( WaitlistRepository::set_called( $id, false ) ) {
						$affected++;
					}
					break;

				case 'delete':
					$affected += $this->delete_row( $id );
					break;
			}
		}

		Logger::log( 'info', 'admin_bulk', $action . '|count=' . $affected );

		set_transient_compat(
			'mojoodyar_tool_notice',
			sprintf( '%s ردیف پردازش شد.', Persian::number( $affected ) ),
			60
		);

		wp_safe_redirect_compat( admin_url( 'admin.php?page=' . self::SLUG . '-waitlists' ) );
		exit;
	}

	/**
	 * Phone follow-up endpoint for one row.
	 *
	 * Three actions share this endpoint because they are three buttons inside
	 * the same dialog:
	 *  - `attempt`: log one call attempt (stage = attempts so far + 1);
	 *  - `close`:   end the follow-up with a reason;
	 *  - `reopen`:  put it back into the working list.
	 *
	 * The old checkbox (`called=1`) is still accepted so a bookmarked form or a
	 * cached admin page keeps working.
	 *
	 * @return void
	 */
	public function handle_call() {
		$this->guard();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field_compat( wp_unslash_compat( $_POST['_wpnonce'] ) ), 'mojoodyar_call' ) ) {
			wp_die_compat( 'درخواست معتبر نیست.' );
		}

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$called = isset( $_POST['called'] ) && '1' === (string) $_POST['called'];

		if ( $id <= 0 ) {
			wp_die_compat( 'ردیف مشخص نیست.' );
		}

		$result = $this->process_call(
			array(
				'id'           => $id,
				'call_action'  => isset( $_POST['call_action'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['call_action'] ) ) : '',
				'outcome'      => isset( $_POST['outcome'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['outcome'] ) ) : '',
				'close_reason' => isset( $_POST['close_reason'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['close_reason'] ) ) : '',
				'note'         => isset( $_POST['note'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['note'] ) ) : '',
				'called'       => $called,
				'user_id'      => get_current_user_id_compat(),
			)
		);

		Logger::log( 'info', 'admin_call', 'id=' . $id . '|action=' . $result['action'] . '|ok=' . ( $result['ok'] ? '1' : '0' ) );

		set_transient_compat(
			'mojoodyar_tool_notice',
			$result['notice'],
			60
		);

		// Back to exactly the page and filters the admin was looking at, so a
		// person working through "not called yet" does not lose their place.
		$return = isset( $_POST['return'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['return'] ) ) : '';
		$return = ltrim( $return, '&' );

		// Only a query string is accepted: the redirect must stay on our screen.
		if ( '' !== $return && ! preg_match( '/^[A-Za-z0-9_%\-&=+.]+$/', $return ) ) {
			$return = '';
		}

		$target = admin_url( 'admin.php?page=' . self::SLUG . '-waitlists' );
		if ( '' !== $return ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $return;
		}

		wp_safe_redirect_compat( $target );
		exit;
	}

	/**
	 * Do the work of the follow-up endpoint and return what happened.
	 *
	 * Split out of handle_call() so the logic is testable without the redirect
	 * and exit that a real request needs.
	 *
	 * @param array $input Unslashed input: id, call_action, outcome, close_reason,
	 *                     note, called, user_id.
	 * @return array{ok:bool,notice:string,action:string}
	 */
	public function process_call( array $input ) {
		$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$action = isset( $input['call_action'] ) ? (string) $input['call_action'] : '';
		$outcome = isset( $input['outcome'] ) ? (string) $input['outcome'] : '';
		$note    = isset( $input['note'] ) ? (string) $input['note'] : '';
		$userId  = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

		if ( ! in_array( $action, array( 'attempt', 'close', 'reopen' ), true ) ) {
			$action = 'legacy';
		}

		$ok = true;

		if ( 'attempt' === $action ) {
			$result = CallLog::add_attempt( $id, $outcome, $note, $userId );
			$ok     = (bool) $result['ok'];

			if ( $ok ) {
				$notice = CallLog::stage_label( $result['stage'] ) . ' ثبت شد: ' . CallLog::outcome_label( $outcome ) . '.';

				if ( $result['closed'] ) {
					$notice .= ' چون نتیجه «' . CallLog::outcome_label( $outcome ) . '» است، پیگیری بسته شد.';
				}
			} else {
				$notice = $this->call_error_message( (string) $result['reason'] );
			}
		} elseif ( 'close' === $action ) {
			$reason = isset( $input['close_reason'] ) ? (string) $input['close_reason'] : '';
			$result = CallLog::close( $id, $reason, $note, $userId );
			$ok     = (bool) $result['ok'];
			$notice = $ok
				? 'پیگیری بسته شد: ' . CallLog::reason_label( $reason ) . '.'
				: $this->call_error_message( (string) $result['reason'] );
		} elseif ( 'reopen' === $action ) {
			$result = CallLog::reopen( $id );
			$ok     = (bool) $result['ok'];
			$notice = $ok ? 'پیگیری دوباره باز شد؛ در لیست تماس برمی‌گردد.' : $this->call_error_message( (string) $result['reason'] );
		} else {
			$called = ! empty( $input['called'] );
			$ok     = WaitlistRepository::set_called( $id, $called, $userId );
			$notice = $called ? 'ردیف به «تماس گرفته شد» تغییر کرد.' : 'تیک تماس از این ردیف برداشته شد.';
		}

		return array(
			'ok'     => (bool) $ok,
			'notice' => (string) $notice,
			'action' => $action,
		);
	}

	/**
	 * Persian message for a follow-up failure.
	 *
	 * @param string $reason Machine reason code.
	 * @return string
	 */
	private function call_error_message( $reason ) {
		$map = array(
			'invalid_outcome' => 'نتیجهٔ تماس را انتخاب کنید.',
			'invalid_reason'  => 'دلیل خاتمه را انتخاب کنید.',
			'not_found'       => 'این ردیف پیدا نشد؛ شاید همزمان حذف شده باشد.',
			'closed'          => 'این پیگیری بسته شده است. اول دوباره بازش کنید.',
			'db_error'        => 'ذخیره نشد. دوباره تلاش کنید.',
		);

		return isset( $map[ (string) $reason ] ) ? $map[ (string) $reason ] : 'ثبت انجام نشد.';
	}

	/**
	 * Delete one waitlist row and its queue entries.
	 *
	 * @param int $id Row ID.
	 * @return int
	 */
	private function delete_row( $id ) {
		global $wpdb;

		Queue::delete_for_waitlists( array( (int) $id ) );

		// No foreign keys in this schema (some hosts and older engines choke on
		// them), so the call history has to be cleaned up here.
		$calls = CallLog::table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$calls} WHERE waitlist_id = %d", (int) $id ) );

		$table = WaitlistRepository::table();

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", (int) $id ) );
	}
}

/**
 * Escaping/redirect helpers with harness fallbacks.
 *
 * @param string $value Value.
 * @return string
 */
function esc_url_compat( $value ) {
	return function_exists( 'esc_url' ) ? (string) esc_url( $value ) : htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

/**
 * wp_nonce_url() fallback (the harness has no nonce plumbing).
 *
 * @param string $action_url Target URL.
 * @param string $action     Nonce action.
 * @return string
 */
function wp_nonce_url_compat( $action_url, $action ) {
	if ( function_exists( 'wp_nonce_url' ) ) {
		return (string) wp_nonce_url( $action_url, $action );
	}

	return (string) $action_url . '&_wpnonce=stub-nonce';
}

/**
 * esc_url_raw() fallback.
 *
 * @param string $value Value.
 * @return string
 */
function esc_url_raw_compat( $value ) {
	return function_exists( 'esc_url_raw' ) ? (string) esc_url_raw( $value ) : filter_var( (string) $value, FILTER_SANITIZE_URL );
}

/**
 * sanitize_text_field() fallback.
 *
 * @param mixed $value Value.
 * @return string
 */
function sanitize_text_field_compat( $value ) {
	return function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( $value ) : trim( strip_tags( (string) $value ) );
}

/**
 * wp_unslash() fallback.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash_compat( $value ) {
	return function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

/**
 * wp_die() fallback.
 *
 * @param string $message Message.
 * @return void
 */
function wp_die_compat( $message ) {
	if ( function_exists( 'wp_die' ) ) {
		wp_die( esc_html( $message ) );
	}

	echo htmlspecialchars( (string) $message, ENT_QUOTES, 'UTF-8' );
	exit;
}

/**
 * set_transient() fallback.
 *
 * @param string $key   Key.
 * @param mixed  $value Value.
 * @param int    $ttl   TTL.
 * @return void
 */
function set_transient_compat( $key, $value, $ttl ) {
	if ( function_exists( 'set_transient' ) ) {
		set_transient( $key, $value, $ttl );
	}
}

/**
 * wp_safe_redirect() fallback.
 *
 * @param string $url URL.
 * @return void
 */
function wp_safe_redirect_compat( $url ) {
	if ( function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( $url );
	}
}

/**
 * get_current_user_id() fallback.
 *
 * @return int
 */
function get_current_user_id_compat() {
	return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
}

/**
 * nocache_headers() fallback.
 *
 * @return void
 */
function nocache_headers_compat() {
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
}
