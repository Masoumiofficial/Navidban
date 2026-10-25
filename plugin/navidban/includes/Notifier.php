<?php
/**
 * Channel orchestration and message rendering.
 *
 * @package Navidban
 */

namespace Navidban;

use Navidban\Channels\ChannelInterface;
use Navidban\Channels\EmailChannel;
use Navidban\Channels\SmsGenericRest;
use Navidban\Channels\SmsIpPanel;
use Navidban\Channels\SmsKavenegar;
use Navidban\Channels\SmsMelipayamak;
use Navidban\Logic\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Class Notifier
 */
final class Notifier {

	/**
	 * Instantiate a channel by name.
	 *
	 * @param string $channel email|sms.
	 * @return ChannelInterface|null
	 */
	public static function channel( $channel ) {
		if ( 'email' === $channel ) {
			return new EmailChannel();
		}

		if ( 'sms' !== $channel ) {
			return null;
		}

		$gateway = (string) Settings::get( 'sms_gateway', 'melipayamak' );

		switch ( $gateway ) {
			case 'ippanel':
				return new SmsIpPanel();
			case 'kavenegar':
				return new SmsKavenegar();
			case 'generic':
				return new SmsGenericRest();
			case 'melipayamak':
			default:
				return new SmsMelipayamak();
		}
	}

	/**
	 * Dispatch one message.
	 *
	 * @param array  $waitlist Waitlist row.
	 * @param string $event_key "confirm" or "restock:<ts>".
	 * @param string $channel  email|sms.
	 * @return true|\WP_Error|string
	 */
	public static function dispatch( array $waitlist, $event_key, $channel ) {
		$instance = self::channel( $channel );

		if ( null === $instance ) {
			return new \WP_Error( 'navidban_channel', 'کانال پشتیبانی نمی‌شود.' );
		}

		if ( ! $instance->is_configured() ) {
			return new \WP_Error( 'navidban_channel_config', $instance->label() . ' پیکربندی نشده است.' );
		}

		$is_confirmation = ( 0 === strpos( (string) $event_key, 'confirm' ) );
		$context         = self::build_context( $waitlist, $is_confirmation );

		if ( $is_confirmation ) {
			$subject = 'تأیید درخواست اعلان — ' . $context['site_name'];
			$body    = "سلام،\n\nبرای تکمیل ثبت درخواست اعلان «{$context['product_name']}» روی پیوند زیر بزنید:\n{$context['confirm_url']}\n\nاین پیوند تا {$context['ttl_hours']} ساعت معتبر است.";
			if ( 'sms' === $channel ) {
				$body = "تایید اعلان {$context['product_name']}: {$context['confirm_url']}";
			}
		} else {
			$subject = Template::render( (string) Settings::get( 'email_subject', '' ), $context );
			$body    = Template::render( (string) Settings::get( 'email_body', '' ), $context );
			if ( 'sms' === $channel ) {
				$body = Template::render( (string) Settings::get( 'sms_template', '' ), $context );
			}
		}

		/**
		 * Filter the rendered message before it is handed to the channel.
		 *
		 * @param string $body     Message body.
		 * @param array  $waitlist Waitlist row.
		 * @param string $channel  Channel name.
		 */
		$body = (string) apply_filters( 'navidban_message_body', $body, $waitlist, $channel );

		$to = ( 'sms' === $channel ) ? (string) $waitlist['phone'] : (string) $waitlist['email'];

		$result = $instance->send(
			$to,
			$subject,
			$body,
			array(
				'waitlist' => $waitlist,
				'context'  => $context,
			)
		);

		// Only a restock message advances the subscriber to "notified".
		if ( true === $result && ! $is_confirmation ) {
			WaitlistRepository::set_status( (int) $waitlist['id'], 'notified' );
		}

		if ( true === $result ) {
			Logger::log( 'info', 'sent', $channel . '|' . ( $is_confirmation ? 'confirm' : 'restock' ) );
		}

		return $result;
	}

	/**
	 * Build the placeholder map for a subscriber row.
	 *
	 * @param array $waitlist        Waitlist row.
	 * @param bool  $is_confirmation Confirmation message?
	 * @return array
	 */
	public static function build_context( array $waitlist, $is_confirmation = false ) {
		$product_id   = (int) $waitlist['product_id'];
		$variation_id = (int) $waitlist['variation_id'];

		$product_name = self::product_name( $product_id, $variation_id );

		return array(
			'site_name'       => (string) Settings::get( 'site_name', '' ),
			'product_name'    => $product_name,
			'variation_name'  => ( $variation_id > 0 ) ? $product_name : '',
			'product_url'     => self::product_url( $product_id, $variation_id ),
			'price'           => self::product_price( $product_id ),
			'customer_name'   => self::customer_name( (int) $waitlist['user_id'] ),
			'unsubscribe_url' => Consent::unsubscribe_url( (string) $waitlist['token'] ),
			'confirm_url'     => Consent::confirm_url( (string) $waitlist['token'] ),
			'waitlist_id'     => (string) $waitlist['id'],
			'store_url'       => function_exists( 'home_url' ) ? home_url( '/' ) : '/',
			'ttl_hours'       => (string) Settings::get( 'confirm_ttl_hours', 48 ),
			'is_confirmation' => $is_confirmation ? '1' : '0',
		);
	}

	/**
	 * Human product name (with variation attributes when relevant).
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @return string
	 */
	public static function product_name( $product_id, $variation_id = 0 ) {
		$name = '';

		if ( function_exists( 'get_the_title' ) ) {
			$name = (string) get_the_title( $product_id );
		}

		$name = \Navidban\Support\Persian::normalize( $name );

		if ( '' === $name ) {
			$name = 'محصول #' . (int) $product_id;
		}

		if ( $variation_id > 0 && function_exists( 'wc_get_product' ) ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation && method_exists( $variation, 'get_attribute_summary' ) ) {
				$summary = (string) $variation->get_attribute_summary();
				if ( '' !== $summary ) {
					$name .= ' (' . \Navidban\Support\Persian::normalize( $summary ) . ')';
				}
			}
		}

		return $name;
	}

	/**
	 * Canonical product URL.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 * @return string
	 */
	public static function product_url( $product_id, $variation_id = 0 ) {
		if ( function_exists( 'get_permalink' ) ) {
			$url = (string) get_permalink( $product_id );
			if ( $variation_id > 0 && function_exists( 'add_query_arg' ) ) {
				$url = add_query_arg( 'variation_id', (int) $variation_id, $url );
			}

			return $url;
		}

		return '/?p=' . (int) $product_id;
	}

	/**
	 * Formatted price, or empty string when unavailable.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function product_price( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( (int) $product_id );
		if ( ! $product || ! method_exists( $product, 'get_price_html' ) ) {
			return '';
		}

		$html = (string) $product->get_price_html();

		return trim( wp_strip_all_tags_compat( $html ) );
	}

	/**
	 * Customer first name when the subscriber is logged in.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function customer_name( $user_id ) {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return 'همراه گرامی';
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return 'همراه گرامی';
		}

		$first = (string) get_user_meta( (int) $user_id, 'first_name', true );
		if ( '' !== $first ) {
			return \Navidban\Support\Persian::normalize( $first );
		}

		return \Navidban\Support\Persian::normalize( (string) $user->display_name );
	}
}

/**
 * wp_strip_all_tags() fallback for the harness.
 *
 * @param string $value Value.
 * @return string
 */
function wp_strip_all_tags_compat( $value ) {
	if ( function_exists( 'wp_strip_all_tags' ) ) {
		return (string) wp_strip_all_tags( $value );
	}

	return trim( strip_tags( (string) $value ) );
}
