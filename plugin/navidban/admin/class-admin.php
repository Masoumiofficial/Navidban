<?php
/**
 * Admin panel: dashboard, waitlist table, settings, tools and help.
 *
 * Every screen is RTL/Persian, uses Jalali dates and Persian digits, and every
 * write path checks a capability *and* a nonce.
 *
 * @package Navidban
 */

namespace Navidban\Admin;

use Navidban\Capabilities;
use Navidban\Consent;
use Navidban\Database;
use Navidban\Logger;
use Navidban\Notifier;
use Navidban\Queue;
use Navidban\Settings;
use Navidban\StockWatcher;
use Navidban\Support\Csv;
use Navidban\Support\Jalali;
use Navidban\Support\Masking;
use Navidban\Support\Persian;
use Navidban\Support\Validator;
use Navidban\WaitlistRepository;

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
	const SLUG = 'navidban';

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	const GROUP = 'navidban_settings_group';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_navidban_export', array( $this, 'export_csv' ) );
		add_action( 'admin_post_navidban_tool', array( $this, 'handle_tool' ) );
		add_action( 'admin_post_navidban_bulk', array( $this, 'handle_bulk' ) );
		add_filter( 'plugin_action_links_' . NAVIDBAN_BASENAME, array( $this, 'action_links' ) );
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
			'نویدبان',
			'نویدبان',
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-bell',
			56
		);

		add_submenu_page( self::SLUG, 'داشبورد', 'داشبورد', Capabilities::MANAGE, self::SLUG, array( $this, 'render_dashboard' ) );
		add_submenu_page( self::SLUG, 'لیست انتظارها', 'لیست انتظارها', Capabilities::MANAGE, self::SLUG . '-waitlists', array( $this, 'render_waitlists' ) );
		add_submenu_page( self::SLUG, 'تنظیمات', 'تنظیمات', Capabilities::MANAGE, self::SLUG . '-settings', array( $this, 'render_settings' ) );
		add_submenu_page( self::SLUG, 'ابزارها', 'ابزارها', Capabilities::MANAGE, self::SLUG . '-tools', array( $this, 'render_tools' ) );
		add_submenu_page( self::SLUG, 'راهنما', 'راهنما', Capabilities::MANAGE, self::SLUG . '-help', array( $this, 'render_help' ) );
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) && false === strpos( (string) $hook, 'navidban' ) ) {
			return;
		}

		wp_enqueue_style( 'navidban-admin', NAVIDBAN_URL . 'assets/css/navidban-admin.css', array(), NAVIDBAN_VERSION );
		wp_enqueue_script( 'navidban-admin', NAVIDBAN_URL . 'assets/js/navidban-admin.js', array(), NAVIDBAN_VERSION, true );
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
			'ippanel_from',
			'kavenegar_sender',
			'generic_payload',
		);

		foreach ( $text_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = Validator::plain_text( $input[ $key ], 500 );
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
			add_settings_error( 'navidban', 'no_channel', 'حداقل یک کانال اعلان باید فعال بماند؛ ایمیل دوباره فعال شد.', 'warning' );
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
			$check = \Navidban\Logic\HttpGuard::check( (string) $input['generic_url'] );
			if ( ! $check['ok'] && '' !== trim( (string) $input['generic_url'] ) ) {
				add_settings_error( 'navidban', 'generic_url', 'نشانی درگاه عمومی پذیرفته نشد: باید https و عمومی باشد (' . $check['reason'] . ').', 'error' );
				$out['generic_url'] = '';
			}
		}

		if ( isset( $input['site_name'] ) ) {
			$out['site_name'] = Validator::plain_text( $input['site_name'], 190 );
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
		$last_run    = (int) get_option( 'navidban_last_reconcile', 0 );
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
				'last_run' => (int) get_option( 'navidban_last_reconcile', 0 ),
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
		$file = NAVIDBAN_DIR . 'admin/views/' . basename( (string) $name ) . '.php';

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

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field_compat( wp_unslash_compat( $_GET['_wpnonce'] ) ), 'navidban_export' ) ) {
			wp_die_compat( 'درخواست معتبر نیست.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$args = array(
			'product_id' => isset( $_GET['product_id'] ) ? (int) $_GET['product_id'] : 0,
			'status'     => isset( $_GET['status'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['status'] ) ) : '',
			'channel'    => isset( $_GET['channel'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['channel'] ) ) : '',
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
		header( 'Content-Disposition: attachment; filename="navidban-waitlists-' . gmdate( 'Ymd-His' ) . '.csv"' );

		echo Csv::bom(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				'شناسه',
				'محصول',
				'تنوع',
				'کانال',
				'ایمیل',
				'موبایل',
				'وضعیت',
				'تاریخ ثبت (میلادی)',
				'تاریخ ثبت (شمسی)',
				'تاریخ تأیید (میلادی)',
				'تاریخ اعلان (میلادی)',
			)
		);

		foreach ( $result['rows'] as $row ) {
			$cells = array(
				$row['id'],
				Notifier::product_name( (int) $row['product_id'], (int) $row['variation_id'] ),
				$row['variation_id'],
				$row['channel'],
				$row['email'],
				$row['phone'],
				$row['status'],
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

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field_compat( wp_unslash_compat( $_POST['_wpnonce'] ) ), 'navidban_tool' ) ) {
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

		set_transient_compat( 'navidban_tool_notice', $message, 60 );

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
			'پیام آزمایشی نویدبان',
			'این یک پیام آزمایشی از افزونهٔ نویدبان است. اگر آن را دریافت کردید، درگاه درست تنظیم شده است.',
			array( 'context' => $context )
		);

		if ( true === $result ) {
			return 'پیام آزمایشی ارسال شد.';
		}

		$message = is_object( $result ) && method_exists( $result, 'get_error_message' )
			? $result->get_error_message()
			: (string) $result;

		$hint = ( 'email' === $channel && class_exists( '\\Navidban\\Channels\\EmailChannel' ) )
			? \Navidban\Channels\EmailChannel::hint()
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

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field_compat( wp_unslash_compat( $_POST['_wpnonce'] ) ), 'navidban_bulk' ) ) {
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

				case 'delete':
					$affected += $this->delete_row( $id );
					break;
			}
		}

		Logger::log( 'info', 'admin_bulk', $action . '|count=' . $affected );

		set_transient_compat(
			'navidban_tool_notice',
			sprintf( '%s ردیف پردازش شد.', Persian::number( $affected ) ),
			60
		);

		wp_safe_redirect_compat( admin_url( 'admin.php?page=' . self::SLUG . '-waitlists' ) );
		exit;
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
 * nocache_headers() fallback.
 *
 * @return void
 */
function nocache_headers_compat() {
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
}
