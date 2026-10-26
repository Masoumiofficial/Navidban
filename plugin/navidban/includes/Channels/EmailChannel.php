<?php
/**
 * Email channel (wp_mail, no external service).
 *
 * @package Navidban
 */

namespace Navidban\Channels;

use Navidban\Settings;
use Navidban\Support\Text;
use Navidban\Support\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Class EmailChannel
 */
final class EmailChannel implements ChannelInterface {

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'email';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'ایمیل';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return (bool) Settings::enabled( 'email_enabled' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $to      Address.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $context Context.
	 * @return true|\WP_Error|string
	 */
	public function send( $to, $subject, $body, array $context = array() ) {
		$to = Validator::normalize_email( $to );

		if ( '' === $to ) {
			return Http::error( 'navidban_email_invalid', 'نشانی ایمیل معتبر نیست.' );
		}

		if ( ! function_exists( 'wp_mail' ) ) {
			return Http::error( 'navidban_no_mail', 'تابع ایمیل در دسترس نیست.' );
		}

		$from_name  = (string) Settings::get( 'from_name', (string) Settings::get( 'site_name', '' ) );
		$from_email = self::from_address();

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		if ( '' !== $from_email && Validator::is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $this->sanitize_header( $from_name ), $from_email );
			$headers[] = sprintf( 'Reply-To: %s', $from_email );
		}

		$subject = $this->sanitize_header( $subject );

		$plain = $this->convert_links( (string) $body );

		// wp_mail() returns a bare false, which tells an administrator nothing.
		// WordPress fires `wp_mail_failed` with the underlying PHPMailer
		// exception, so listen for it during this one call and surface the real
		// reason. The listener is always removed, even on success.
		$reason   = '';
		$listener = static function ( $error ) use ( &$reason ) {
			if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
				$reason = (string) $error->get_error_message();
			} elseif ( is_string( $error ) ) {
				$reason = $error;
			}
		};

		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_mail_failed', $listener );
		}

		$sent = wp_mail( $to, $subject, $plain, $headers );

		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'wp_mail_failed', $listener );
		}

		if ( ! $sent ) {
			return Http::error( 'navidban_mail_failed', self::failure_message( $reason ) );
		}

		return true;
	}

	/**
	 * Build a human explanation for a failed wp_mail() call.
	 *
	 * @param string $reason Raw message reported by WordPress/PHPMailer.
	 * @return string
	 */
	private static function failure_message( $reason ) {
		$reason = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $reason ) ) );

		if ( '' !== $reason ) {
			return 'wp_mail ارسال را نپذیرفت: ' . Text::substring( $reason, 0, 140 );
		}

		return 'wp_mail ارسال را نپذیرفت و دلیلی گزارش نکرد (تنظیمات SMTP هاست را بررسی کنید).';
	}

	/**
	 * The address this plugin will actually put in the From header.
	 *
	 * Order: explicit setting, then the site admin address, then nothing (in
	 * which case WordPress falls back to wordpress@<host>, which many hosts
	 * treat as spam).
	 *
	 * @return string
	 */
	public static function from_address() {
		$configured = strtolower( trim( (string) Settings::get( 'from_email', '' ) ) );

		if ( '' !== $configured && Validator::is_email( $configured ) ) {
			return $configured;
		}

		$admin = function_exists( 'get_option' ) ? strtolower( trim( (string) get_option( 'admin_email', '' ) ) ) : '';

		return Validator::is_email( $admin ) ? $admin : '';
	}

	/**
	 * Facts about how this server would send mail. No secrets, no guessing.
	 *
	 * @return array<string,string|bool|null>
	 */
	public static function diagnose() {
		$sendmail = (string) ini_get( 'sendmail_path' );
		$binary   = self::sendmail_binary( $sendmail );
		$smtp     = (string) ini_get( 'SMTP' );
		// PHP only consults the SMTP/smtp_port ini values on Windows. On Linux
		// they are inherited from php.ini-development and ignored, so warning
		// about them there would be a false alarm.
		$is_windows = ( 'Windows' === PHP_OS_FAMILY );
		$plugin   = self::mailer_plugin();
		$from     = self::from_address();
		$admin    = function_exists( 'get_option' ) ? strtolower( trim( (string) get_option( 'admin_email', '' ) ) ) : '';
		$host     = self::site_host();

		$from_domain = ( false !== strpos( $from, '@' ) ) ? substr( $from, (int) strpos( $from, '@' ) + 1 ) : '';

		return array(
			'mail_function'   => function_exists( 'mail' ) ? 'available' : 'missing',
			'sendmail_path'   => $sendmail,
			'sendmail_binary' => $binary,
			// Only true/false when there is something to check; null otherwise.
			'sendmail_exists' => '' === $binary ? null : self::path_exists( $binary ),
			'os_family'       => PHP_OS_FAMILY,
			// Which transport PHP will actually use.
			'transport'       => ( '' !== $binary ) ? 'sendmail' : ( $is_windows && '' !== $smtp ? 'smtp' : 'mail' ),
			'smtp_host'       => $is_windows ? $smtp : '',
			'smtp_port'       => $is_windows ? (string) ini_get( 'smtp_port' ) : '',
			'mailer_plugin'   => $plugin,
			'admin_email'     => $admin,
			'from_email'      => $from,
			'from_domain'     => $from_domain,
			'site_host'       => $host,
			// null = cannot tell (no From address configured), not "false".
			'from_matches_site' => ( '' === $from_domain || '' === $host ) ? null : ( $from_domain === $host || substr( $host, -strlen( '.' . $from_domain ) ) === '.' . $from_domain ),
		);
	}

	/**
	 * Host of this WordPress site, without scheme or www.
	 *
	 * @return string
	 */
	private static function site_host() {
		$url = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';

		if ( '' === $url && function_exists( 'get_option' ) ) {
			$url = (string) get_option( 'siteurl', '' );
		}

		$host = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$host = strtolower( trim( $host ) );

		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * One Persian sentence that names the most likely cause on this server.
	 *
	 * @return string Empty string when nothing useful can be said.
	 */
	public static function hint() {
		$facts = self::diagnose();

		if ( 'missing' === $facts['mail_function'] ) {
			return 'تابع mail() در این نصب PHP در دسترس نیست؛ ارسال ایمیل بدون یک افزونهٔ SMTP ممکن نیست.';
		}

		if ( '' !== (string) $facts['mailer_plugin'] ) {
			return 'یک افزونهٔ SMTP فعال است (' . $facts['mailer_plugin'] . ')؛ تنظیمات همان افزونه را بررسی کنید.';
		}

		if ( false === $facts['from_matches_site'] ) {
			return 'نشانی فرستنده (' . $facts['from_email'] . ') با دامنهٔ سایت (' . $facts['site_host'] . ') یکی نیست؛ بسیاری از هاست‌ها چنین پیامی را بی‌صدا دور می‌ریزند یا به اسپم می‌فرستند. یک فرستنده با دامنهٔ خودِ سایت تنظیم کنید یا از افزونهٔ SMTP با SMTP واقعی استفاده کنید.';
		}

		if ( true === $facts['sendmail_exists'] ) {
			// The transport exists and the From domain matches. What is left is
			// delivery, which no PHP-side check can settle: say so instead of
			// inventing a cause.
			return 'مسیر sendmail روی سرور وجود دارد و از نظر PHP مانعی نیست. پیام تحویل میل سرور شده است؛ رسیدن آن به صندوق، SPF/DKIM دامنه، فیلتر ضداسپم هاست و اعتبار درگاه SMTP را درگیر می‌کند — این‌ها از داخل وردپرس قابل بررسی نیستند. برای دیدن سرنوشت پیام، یک افزونهٔ لاگ ایمیل (مثل WP Mail Logging) نصب کنید.';
		}

		if ( false === $facts['sendmail_exists'] ) {
			return 'مسیر sendmail در php.ini به فایلی اشاره می‌کند که وجود ندارد: ' . $facts['sendmail_binary'];
		}

		if ( '' !== (string) $facts['smtp_host'] ) {
			$target = $facts['smtp_host'] . ( '' !== (string) $facts['smtp_port'] ? ':' . $facts['smtp_port'] : '' );

			if ( self::is_local( $facts['smtp_host'] ) ) {
				return 'PHP پیکربندی شده که از SMTP محلی (' . $target . ') استفاده کند؛ حالت پیش‌فرض XAMPP. اگر سرور SMTP روی آن اجرا نشود، هر ارسال شکست می‌خورد.';
			}

			return 'PHP پیکربندی شده که از SMTP ' . $target . ' استفاده کند؛ دسترس‌پذیری و اعتبارنامهٔ آن را بررسی کنید.';
		}

		return 'نه مسیر sendmail و نه سرور SMTP برای PHP تنظیم نشده است؛ روی هاست محلی برای تست، Mailpit یا افزونهٔ SMTP نصب کنید.';
	}

	/**
	 * Is the configured SMTP host this machine?
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_local( $host ) {
		$host = strtolower( trim( (string) $host ) );

		return in_array( $host, array( 'localhost', '127.0.0.1', '::1', 'mail.localhost' ), true );
	}

	/**
	 * The executable part of a sendmail_path value.
	 *
	 * php.ini commonly holds "sendmail_path = /usr/sbin/sendmail -t -i"; the
	 * arguments belong to the command, not the path, and must not be fed to
	 * file_exists().
	 *
	 * @param string $command Raw ini value.
	 * @return string
	 */
	private static function sendmail_binary( $command ) {
		$command = trim( (string) $command );

		if ( '' === $command ) {
			return '';
		}

		// Quoted path first: "/path with space/sendmail" -t -i
		if ( '"' === $command[0] || "'" === $command[0] ) {
			$quote   = $command[0];
			$closing = strpos( $command, $quote, 1 );

			if ( false !== $closing ) {
				return substr( $command, 1, $closing - 1 );
			}
		}

		$parts = preg_split( '/\s+/', $command );

		return is_array( $parts ) && isset( $parts[0] ) ? (string) $parts[0] : '';
	}

	/**
	 * Does a php.ini path point at something real? Windows paths are checked
	 * only when they look absolute, so a shell command never becomes a path.
	 *
	 * @param string $path Raw ini value.
	 * @return bool
	 */
	private static function path_exists( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path || false !== strpos( $path, ' ' ) ) {
			// A command line with arguments is not a path; callers must pass the
			// binary only (see sendmail_binary()).
			return false;
		}

		if ( '/' !== substr( $path, 0, 1 ) && ! preg_match( '/^[A-Za-z]:[\\\\\/]/', $path ) ) {
			return false;
		}

		return @file_exists( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Name of a known SMTP plugin, when one is active.
	 *
	 * @return string
	 */
	private static function mailer_plugin() {
		$known = array(
			'WPMailSMTP\\Core'      => 'WP Mail SMTP',
			'EasyWPSMTP\\Plugin'    => 'Easy WP SMTP',
			'FluentMail\\App\\App' => 'FluentSMTP',
			'Postman\\Postman'      => 'Post SMTP',
			'SiteGround\\Mailer'    => 'SiteGround Mailer',
		);

		foreach ( $known as $class => $label ) {
			if ( class_exists( $class ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Strip CR/LF so a value can never inject an extra header.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function sanitize_header( $value ) {
		$value = str_replace( array( "\r", "\n", '%0a', '%0d' ), ' ', (string) $value );

		return trim( $value );
	}

	/**
	 * Wrap bare URLs in angle brackets so long links do not break in mail clients.
	 *
	 * @param string $body Body.
	 * @return string
	 */
	private function convert_links( $body ) {
		return preg_replace_callback(
			'~(?<!<)(https?://[^\s<>"]+)~i',
			function ( $matches ) {
				return '<' . $matches[1] . '>';
			},
			$body
		);
	}
}
