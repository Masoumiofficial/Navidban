<?php
/**
 * Positional SMS pattern rules (the "{0}, {1}" format Iranian gateways use for
 * shared-service/textile lines).
 *
 * Background, because this is the part people get wrong: on a pattern line the
 * *message text lives in the gateway panel*, not in the plugin. The gateway
 * stores an approved template such as
 *
 *     {0} عزیز، {1} دوباره موجود شد. مشاهده و خرید: {2} — فروشگاه شما
 *
 * and the site sends only the **values**, in order, separated by semicolons.
 * The first value fills {0}, the second fills {1}, and so on. If the count or
 * the order is wrong, the gateway answers with an error and nothing is sent.
 *
 * So this class does two jobs, both pure and unit-testable:
 *   1. build the `value1;value2;value3` string for a given template;
 *   2. check a template against the rules the gateways publish, so a store can
 *      see the problem in the settings screen instead of in a failed send.
 *
 * The rules implemented here come from the gateway's own published guidance
 * (numbering starts at {0} and is consecutive; a variable may not be the last
 * thing in the message; the message must carry real content plus the store
 * name). Any check that is a *heuristic* rather than a hard rule is labelled as
 * such in the returned message, because this plugin does not claim to know the
 * reviewer's mind.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Logic;

use Mojoodyar\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Class SmsPattern
 */
final class SmsPattern {

	/**
	 * Variable index => context key, in the canonical order shown in the panel.
	 *
	 * @var array<string,string>
	 */
	const VARIABLES = array(
		'0' => 'customer_name',
		'1' => 'product_name',
		'2' => 'product_url',
		'3' => 'price',
	);

	/**
	 * Human labels for the settings screen.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'0' => 'نام مشتری (اگر نامی ثبت نشده باشد: «همراه گرامی»)',
			'1' => 'نام محصول',
			'2' => 'نشانی صفحهٔ محصول',
			'3' => 'قیمت محصول',
		);
	}

	/**
	 * Suggested template: real content, ends with the store name, starts with a
	 * variable that reads naturally in Persian.
	 *
	 * The store name is inserted as *literal text*, never as a variable: a
	 * template that ends in {n} is refused by the gateway.
	 *
	 * @param string $site_name Store name.
	 * @return string
	 */
	public static function default_template( $site_name = '' ) {
		$site_name = trim( (string) $site_name );

		if ( '' === $site_name ) {
			$site_name = 'فروشگاه شما';
		}

		return '{0} عزیز، {1} دوباره موجود شد. مشاهده و خرید: {2} — ' . $site_name;
	}

	/**
	 * Extract the variable indexes used by a template, in ascending order.
	 *
	 * Ascending order is what the gateway expects: the n-th value of the
	 * semicolon list fills the variable with index n.
	 *
	 * @param string $template Template text.
	 * @return int[]
	 */
	public static function indexes( $template ) {
		if ( ! preg_match_all( '/\{(\d{1,3})\}/', (string) $template, $matches ) ) {
			return array();
		}

		$indexes = array();

		foreach ( $matches[1] as $raw ) {
			$indexes[ (int) $raw ] = true;
		}

		$list = array_keys( $indexes );
		sort( $list, SORT_NUMERIC );

		return $list;
	}

	/**
	 * Value for one variable index.
	 *
	 * @param int   $index   Variable index.
	 * @param array $context Context map from Notifier::build_context().
	 * @return string
	 */
	public static function value( $index, array $context ) {
		$key = isset( self::VARIABLES[ (string) $index ] ) ? self::VARIABLES[ (string) $index ] : '';

		if ( '' === $key || ! isset( $context[ $key ] ) ) {
			return '';
		}

		$value = trim( (string) $context[ $key ] );

		// A semicolon is the gateway's field separator: a value containing one
		// would shift every later value by one. A newline breaks single-line
		// patterns the same way.
		$value = str_replace( array( ';', "\r", "\n", "\t" ), array( ',', ' ', ' ', ' ' ), $value );
		$value = preg_replace( '/\s{2,}/u', ' ', $value );

		if ( null === $value ) {
			$value = '';
		}

		$value = trim( $value );

		// An empty slot shifts nothing but reads as an error on most panels, so
		// send a neutral dash instead of an empty field.
		return '' === $value ? '-' : $value;
	}

	/**
	 * The string the gateway wants in its `text` field.
	 *
	 * @param string $template Template text (already validated/expected sequential).
	 * @param array  $context  Context map.
	 * @return string
	 */
	public static function payload( $template, array $context ) {
		$values = array();

		foreach ( self::indexes( $template ) as $index ) {
			$values[] = self::value( $index, $context );
		}

		return implode( ';', $values );
	}

	/**
	 * Check a template against the published rules.
	 *
	 * Returns a list of findings. `level` is either `error` (the gateway will
	 * almost certainly reject the pattern) or `warning` (a heuristic or a
	 * recommendation).
	 *
	 * @param string $template  Template text.
	 * @param string $site_name Store name, for the "end with your name" check.
	 * @return array<int,array{level:string,code:string,message:string}>
	 */
	public static function validate( $template, $site_name = '' ) {
		$template = trim( (string) $template );
		$findings = array();

		if ( '' === $template ) {
			$findings[] = self::finding( 'error', 'empty', 'متن الگو خالی است.' );

			return $findings;
		}

		$indexes = self::indexes( $template );

		if ( empty( $indexes ) ) {
			$findings[] = self::finding( 'error', 'no_variables', 'هیچ متغیری مثل {0} در متن نیست.' );
		} else {
			// Consecutive from zero: {0},{1},{2}… with no gap and no start at 1.
			$expected = range( 0, count( $indexes ) - 1 );

			if ( $indexes !== $expected ) {
				$findings[] = self::finding(
					'error',
					'numbering',
					'شماره‌گذاری متغیرها باید از {0} شروع شود و بدون پرش باشد؛ الان: ' . self::list_indexes( $indexes )
				);
			}

			$highest = max( $indexes );
			if ( $highest > 3 ) {
				$findings[] = self::finding(
					'error',
					'too_many',
					'این افزونه حداکثر چهار مقدار (۰ تا ۳) می‌فرستد؛ متغیر ' . '{' . $highest . '} پشتیبانی نمی‌شود.'
				);
			}
		}

		// A variable as the last thing in the message is refused by the gateway.
		if ( preg_match( '/\{\d+\}\s*$/u', $template ) ) {
			$findings[] = self::finding( 'error', 'ends_with_variable', 'متن نباید با متغیر تمام شود؛ نام فروشگاه یا یک جملهٔ ثابت را آخر آن بگذارید.' );
		}

		// Literal content, i.e. what remains when variables are removed.
		$literal = trim( (string) preg_replace( '/\{\d{1,3}\}/', '', $template ) );
		$literal = trim( $literal, " \t\n\r\0\x0B.,،;:-—" );

		if ( Text::length( $literal ) < 8 ) {
			$findings[] = self::finding( 'warning', 'too_short', 'متن تقریباً فقط متغیر است؛ الگوهایی مثل «{0} باتشکر» تأیید نمی‌شوند. یک جمله و نام فروشگاه اضافه کنید.' );
		}

		$site_name = trim( (string) $site_name );

		if ( '' !== $site_name && ! self::ends_with( $template, $site_name ) ) {
			$findings[] = self::finding( 'warning', 'no_brand', 'نام فروشگاه («' . $site_name . '») در انتهای متن دیده نمی‌شود؛ برای پیام‌های خدماتی معمولاً لازم است.' );
		}

		if ( Text::length( $template ) > 190 ) {
			$findings[] = self::finding( 'warning', 'too_long', 'متن الگو بیش از ۱۹۰ کاراکتر است؛ بیشتر درگاه‌ها چنین الگویی را نمی‌پذیرند.' );
		}

		return $findings;
	}

	/**
	 * True when the template has at least one hard error.
	 *
	 * @param array $findings Result of validate().
	 * @return bool
	 */
	public static function has_errors( array $findings ) {
		foreach ( $findings as $finding ) {
			if ( 'error' === $finding['level'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Effective template: what the store saved, or the suggested one.
	 *
	 * @param string $stored    Stored template.
	 * @param string $site_name Store name.
	 * @return string
	 */
	public static function resolve( $stored, $site_name = '' ) {
		$stored = trim( (string) $stored );

		return '' === $stored ? self::default_template( $site_name ) : $stored;
	}

	/**
	 * Build one finding row.
	 *
	 * @param string $level   error|warning.
	 * @param string $code    Machine code.
	 * @param string $message Human message (Persian, shown in the panel).
	 * @return array{level:string,code:string,message:string}
	 */
	private static function finding( $level, $code, $message ) {
		return array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Render an index list as "{0}، {1}".
	 *
	 * @param int[] $indexes Indexes.
	 * @return string
	 */
	private static function list_indexes( array $indexes ) {
		$parts = array();

		foreach ( $indexes as $index ) {
			$parts[] = '{' . (int) $index . '}';
		}

		return implode( '، ', $parts );
	}

	/**
	 * Does the text end with this literal string (ignoring trailing spaces)?
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Suffix.
	 * @return bool
	 */
	private static function ends_with( $haystack, $needle ) {
		$haystack = rtrim( (string) $haystack );
		$length   = Text::length( $needle );

		if ( 0 === $length ) {
			return true;
		}

		return Text::substring( $haystack, Text::length( $haystack ) - $length ) === $needle;
	}
}
