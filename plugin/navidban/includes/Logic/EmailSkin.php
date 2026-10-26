<?php
/**
 * HTML email skin. Pure string building: no WordPress calls, unit-testable.
 *
 * Rules that shaped this file:
 *  - email clients are not browsers: table layout, inline styles only, no
 *    flexbox, no grid, no external CSS or fonts;
 *  - RTL is explicit (dir="rtl" on the wrapper and every cell that matters),
 *    because many clients get the direction of a Persian paragraph wrong;
 *  - the plain-text body always remains the source of truth. The HTML is a
 *    presentation of that text, never a replacement for it, so a client that
 *    strips HTML still shows a complete message.
 *
 * @package Navidban
 */

namespace Navidban\Logic;

defined( 'ABSPATH' ) || exit;

/**
 * Class EmailSkin
 */
final class EmailSkin {

	/**
	 * Brand teal (dark).
	 *
	 * @var string
	 */
	const INK = '#0E5C55';

	/**
	 * Brand teal (mid).
	 *
	 * @var string
	 */
	const TEAL = '#14857B';

	/**
	 * Brand amber.
	 *
	 * @var string
	 */
	const AMBER = '#F2A93B';

	/**
	 * Render a full HTML document.
	 *
	 * @param string $heading  Headline (usually the product name).
	 * @param string $body     Plain-text body.
	 * @param array  $context  Keys: site_name, product_name, product_url,
	 *                         price, unsubscribe_url, store_url, is_confirmation.
	 * @return string
	 */
	public static function render( $heading, $body, array $context = array() ) {
		$site     = self::text( isset( $context['site_name'] ) ? $context['site_name'] : '' );
		$product  = isset( $context['product_name'] ) ? (string) $context['product_name'] : '';
		$url      = isset( $context['product_url'] ) ? (string) $context['product_url'] : '';
		$store    = isset( $context['store_url'] ) ? (string) $context['store_url'] : '';
		$unsub    = isset( $context['unsubscribe_url'] ) ? (string) $context['unsubscribe_url'] : '';
		$price    = self::text( isset( $context['price'] ) ? $context['price'] : '' );
		$confirm  = ! empty( $context['is_confirmation'] ) && '0' !== (string) $context['is_confirmation'];
		$eyebrow  = $confirm ? 'تأیید اشتراک' : 'بازگشت موجودی';
		$cta_text = $confirm ? 'تأیید اشتراک' : 'مشاهدهٔ محصول و خرید';
		$cta_url  = $confirm && '' !== (string) self::raw( $context, 'confirm_url' ) ? (string) self::raw( $context, 'confirm_url' ) : $url;

		$paragraphs = self::paragraphs( $body );
		$head_title = '' !== $product ? $product : $heading;

		$html  = '<!DOCTYPE html><html lang="fa" dir="rtl"><head>';
		$html .= '<meta charset="utf-8" /><meta name="viewport" content="width=device-width,initial-scale=1" />';
		$html .= '<meta name="color-scheme" content="light only" />';
		$html .= '<title>' . self::text( $head_title ) . '</title></head>';
		$html .= '<body dir="rtl" style="margin:0;padding:0;background:#eef3f1;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3f1;padding:28px 12px;">';
		$html .= '<tr><td align="center">';

		// ---- Card -------------------------------------------------------
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="max-width:560px;width:100%;border-collapse:separate;border-spacing:0;background:#ffffff;border-radius:22px;overflow:hidden;box-shadow:0 18px 48px rgba(14,92,85,0.16);font-family:Vazirmatn,IRANSansX,Tahoma,Arial,sans-serif;">';

		// Header: deep teal gradient, amber rule, brand name.
		$html .= '<tr><td dir="rtl" style="background:' . self::INK . ';background-image:linear-gradient(135deg,' . self::INK . ' 0%,' . self::TEAL . ' 100%);padding:26px 30px 24px;">';
		$html .= '<div style="font-size:12px;letter-spacing:1px;color:' . self::AMBER . ';margin:0 0 10px;">' . self::text( $eyebrow ) . '</div>';
		$html .= '<div style="font-size:23px;line-height:1.5;font-weight:bold;color:#ffffff;margin:0;">' . self::text( $head_title ) . '</div>';
		if ( '' !== $site ) {
			$html .= '<div style="font-size:13px;color:rgba(255,255,255,0.78);margin:8px 0 0;">' . self::text( $site ) . '</div>';
		}
		$html .= '</td></tr>';

		// Body.
		$html .= '<tr><td dir="rtl" style="padding:30px 30px 8px;">';

		if ( '' !== $price ) {
			$html .= '<div style="display:inline-block;background:#fff8ec;border:1px solid #f6dcae;color:#8a5a10;font-size:13px;padding:6px 14px;border-radius:999px;margin:0 0 18px;">قیمت: ' . self::text( $price ) . '</div>';
		}

		foreach ( $paragraphs as $paragraph ) {
			$html .= '<div style="font-size:15px;line-height:2;color:#26332f;margin:0 0 12px;">' . $paragraph . '</div>';
		}

		if ( '' !== $cta_url ) {
			$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px;"><tr><td dir="rtl" style="border-radius:14px;background:' . self::TEAL . ';">';
			$html .= '<a href="' . self::attr( $cta_url ) . '" style="display:inline-block;padding:14px 30px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:14px;">' . self::text( $cta_text ) . '</a>';
			$html .= '</td></tr></table>';
		}

		$html .= '</td></tr>';

		// Footer.
		$html .= '<tr><td dir="rtl" style="padding:18px 30px 26px;">';
		$html .= '<div style="height:1px;background:#e8eeec;margin:0 0 16px;"></div>';
		if ( '' !== $store ) {
			$html .= '<div style="font-size:12px;line-height:1.9;color:#7d8a86;">این پیام از طرف <a href="' . self::attr( $store ) . '" style="color:' . self::TEAL . ';text-decoration:none;">' . self::text( '' !== $site ? $site : $store ) . '</a> فرستاده شده است.</div>';
		}
		if ( '' !== $unsub ) {
			$html .= '<div style="font-size:12px;line-height:1.9;color:#7d8a86;margin-top:6px;">اگر دیگر نمی‌خواهید اعلان بگیرید، <a href="' . self::attr( $unsub ) . '" style="color:' . self::TEAL . ';text-decoration:underline;">اشتراک را لغو کنید</a>.</div>';
		}
		$html .= '</td></tr>';

		$html .= '</table>';
		$html .= '</td></tr></table></body></html>';

		return $html;
	}

	/**
	 * Split a plain-text body into safe HTML paragraphs.
	 *
	 * A paragraph that is nothing but a URL is dropped: the button already
	 * carries it, and showing the raw link twice looks like a mistake.
	 *
	 * @param string $body Plain text.
	 * @return string[]
	 */
	public static function paragraphs( $body ) {
		$body  = str_replace( array( "\r\n", "\r" ), "\n", (string) $body );
		$lines = preg_split( "/\n\s*\n/", trim( $body ) );

		if ( ! is_array( $lines ) ) {
			return array();
		}

		$out = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '~^https?://\S+$~i', $line ) ) {
				continue;
			}

			$escaped = self::text( $line );

			// Linkify http(s) URLs.
			$escaped = (string) preg_replace(
				'~https?://[^\s<"]+~i',
				'<a href="$0" style="color:' . self::TEAL . ';text-decoration:none;word-break:break-all;">$0</a>',
				$escaped
			);

			$escaped = str_replace( "\n", '<br />', $escaped );

			$out[] = $escaped;
		}

		return $out;
	}

	/**
	 * Escape for HTML text context.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escape for an attribute value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function attr( $value ) {
		$value = (string) $value;

		// Only http(s) links are allowed in an email; anything else (javascript:,
		// data:, mailto: used for header injection) is dropped.
		if ( ! preg_match( '~^https?://~i', $value ) ) {
			return '';
		}

		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Read a raw context value.
	 *
	 * @param array  $context Context.
	 * @param string $key     Key.
	 * @return string
	 */
	private static function raw( array $context, $key ) {
		return isset( $context[ $key ] ) ? (string) $context[ $key ] : '';
	}
}
