<?php
/**
 * Storefront: conditional asset loading, the "notify me" form and the public
 * confirm/unsubscribe handlers.
 *
 * Core differentiator: nothing is enqueued unless the visitor is on a single
 * product page whose product (or a variation of it) cannot be purchased.
 * See docs/PERFORMANCE.md for the measurement method.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

use Mojoodyar\Support\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
final class Frontend {

	/**
	 * Cached decision for the current request.
	 *
	 * @var bool|null
	 */
	private $should_load = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
		add_action( 'template_redirect', array( $this, 'handle_public_actions' ), 1 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_form' ), 35 );
	}

	/**
	 * Should this request load any front-end asset at all?
	 *
	 * @return bool
	 */
	public function should_load() {
		if ( null !== $this->should_load ) {
			return $this->should_load;
		}

		$this->should_load = $this->evaluate_should_load();

		return $this->should_load;
	}

	/**
	 * Actual decision. Kept separate so the unit/integration harness can drive it.
	 *
	 * @return bool
	 */
	public function evaluate_should_load() {
		if ( is_admin() || wp_doing_ajax_compat() || is_feed() || is_robots() ) {
			return false;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}

		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'get_queried_object_id' ) ) {
			return false;
		}

		$product_id = (int) get_queried_object_id();
		if ( $product_id <= 0 ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		if ( ! $product->is_in_stock() ) {
			return true;
		}

		// A variable product can be "in stock" as a whole while single
		// variations are sold out. Only then do we need our assets.
		return $this->has_out_of_stock_variation( $product );
	}

	/**
	 * Does the product have at least one variation that cannot be bought?
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function has_out_of_stock_variation( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variable' ) ) {
			return false;
		}

		if ( ! method_exists( $product, 'get_children' ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$children = $product->get_children();

		if ( empty( $children ) ) {
			return false;
		}

		// Cap the loop: a product with hundreds of variations must not turn a
		// page view into hundreds of object loads.
		$children = array_slice( $children, 0, 100 );

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( (int) $child_id );

			if ( ! $variation ) {
				continue;
			}

			if ( method_exists( $variation, 'variation_is_active' ) && ! $variation->variation_is_active() ) {
				continue;
			}

			if ( ! $variation->is_in_stock() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue assets, but only when they are needed.
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'mojoodyar-front',
			MOJODYAR_URL . 'assets/css/mojoodyar-front.css',
			array(),
			MOJODYAR_VERSION
		);

		wp_enqueue_script(
			'mojoodyar-front',
			MOJODYAR_URL . 'assets/js/mojoodyar-front.js',
			array(),
			MOJODYAR_VERSION,
			true
		);

		wp_localize_script(
			'mojoodyar-front',
			'MojoodyarData',
			array(
				'restUrl'   => function_exists( 'rest_url' ) ? rest_url( 'mojoodyar/v1/subscribe' ) : '',
				'ajaxUrl'   => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'ajaxNonce' => wp_create_nonce( 'mojoodyar_subscribe' ),
				'channel'   => $this->channel_mode(),
				'strings'   => array(
					'duplicate'   => 'این شماره یا ایمیل پیش‌تر ثبت شده است. اگر پیام تأیید را دریافت نکردید، چند دقیقهٔ دیگر دوباره تلاش کنید.',
					'invalid'     => 'شمارهٔ موبایل یا ایمیل را درست وارد کنید.',
					'invalidName' => 'نام و نام خانوادگی را کامل بنویسید.',
					'consent'     => 'برای ادامه باید رضایت خود را تأیید کنید.',
					'network'     => 'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.',
					'success'     => (string) Settings::get( 'success_message', '' ),
					'confirmSent' => 'یک پیام تأیید برای شما ارسال شد. برای تکمیل ثبت، پیوند داخل آن را باز کنید.',
				),
			)
		);
	}

	/**
	 * Which input(s) the form shows.
	 *
	 * Two things decide it, in this order:
	 *
	 *  1. the per-field switches the store set (form_email_field /
	 *     form_phone_field) — a store that only wants phone numbers turns the
	 *     email field off and the form asks for nothing else;
	 *  2. whether that channel can actually deliver. A field for a channel with
	 *     no working gateway would collect addresses nobody can ever notify, so
	 *     it is not shown: the form falls back to the channel that works.
	 *
	 * Values returned: 'sms' (phone only), 'email' (email only) or 'both'
	 * (radio pair, default taken from channel_choice).
	 *
	 * @return string
	 */
	public function channel_mode() {
		$email_ready = Notifier::email_ready();
		$sms_ready   = Notifier::sms_ready();

		$want_email = (bool) Settings::get( 'form_email_field', true ) && $email_ready;
		$want_phone = (bool) Settings::get( 'form_phone_field', true ) && $sms_ready;

		if ( $want_email && $want_phone ) {
			return 'both';
		}

		if ( $want_phone ) {
			return 'sms';
		}

		if ( $want_email ) {
			return 'email';
		}

		// Neither switch produced a usable field: either both fields are off
		// (the sanitiser refuses that, but an old option row could hold it) or
		// the requested channel is not configured. Fall back to whatever can
		// deliver, and to email when nothing can, so the page never renders a
		// form with no input in it.
		if ( $sms_ready ) {
			return 'sms';
		}

		return 'email';
	}

	/**
	 * Default channel in "both" mode.
	 *
	 * @return string sms|email
	 */
	public function default_channel() {
		$mode = $this->channel_mode();

		if ( 'sms' === $mode ) {
			return 'sms';
		}

		if ( 'email' === $mode ) {
			return 'email';
		}

		// Both fields are shown: the stored preference decides which radio is
		// pre-selected. "sms_first" (and the legacy "both" value) mean SMS.
		$choice = (string) Settings::get( 'channel_choice', 'sms_first' );

		return ( 'email_first' === $choice ) ? 'email' : 'sms';
	}

	/**
	 * Render the waitlist form on the product page.
	 *
	 * @return void
	 */
	public function render_form() {
		if ( ! $this->should_load() ) {
			return;
		}

		$product_id = (int) get_queried_object_id();
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		$variations = array();
		if ( method_exists( $product, 'get_children' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( (int) $child_id );
				if ( ! $variation ) {
					continue;
				}
				$variations[] = array(
					'id'       => (int) $child_id,
					'label'    => \Mojoodyar\Support\Persian::normalize( (string) $variation->get_name() ),
					'in_stock' => (bool) $variation->is_in_stock(),
				);
			}
		}

		$mjy_mode        = $this->channel_mode();
		$default_channel = $this->default_channel();
		$user_id         = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		$prefill_email = '';
		$prefill_phone = '';
		$prefill_name  = '';

		if ( $user_id > 0 ) {
			$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
			if ( $user ) {
				$prefill_email = (string) $user->user_email;

				$mjy_first = (string) get_user_meta( $user_id, 'first_name', true );
				$mjy_last  = (string) get_user_meta( $user_id, 'last_name', true );
				$prefill_name = \Mojoodyar\Support\Validator::person_name( trim( $mjy_first . ' ' . $mjy_last ), 120 );
			}

			$billing_phone = (string) get_user_meta( $user_id, 'billing_phone', true );
			$normalized    = Validator::normalize_phone( $billing_phone );
			if ( '' !== $normalized ) {
				$prefill_phone = $normalized;
			}
		}

		$notice = $this->read_notice();

		$view = MOJODYAR_DIR . 'admin/views/frontend-form.php';

		if ( ! is_readable( $view ) ) {
			return;
		}

		$settings = array(
			'heading'     => (string) Settings::get( 'form_heading', '' ),
			'intro'       => (string) Settings::get( 'form_intro', '' ),
			'button'      => (string) Settings::get( 'button_label', '' ),
			'consent'     => (string) Settings::get( 'consent_label', '' ),
			'consent_req' => Consent::requires_checkbox(),
			'privacy'     => Consent::privacy_url(),
			'mode'        => $this->channel_mode(),
			'name_field'  => (bool) Settings::get( 'form_name_field', true ),
			'name_req'    => (bool) Settings::get( 'form_name_required', true ),
			'notice'      => $notice,
		);

		include $view;
	}

	/**
	 * Handle ?mojoodyar_confirm= / ?mojoodyar_unsubscribe= links.
	 *
	 * @return void
	 */
	public function handle_public_actions() {
		if ( is_admin() ) {
			return;
		}

		$token   = '';
		$action  = '';
		$product = 0;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public,
		// token-authenticated links; the token *is* the credential.
		if ( isset( $_GET[ Consent::CONFIRM_VAR ] ) ) {
			$token  = sanitize_text_field_compat( wp_unslash_compat( $_GET[ Consent::CONFIRM_VAR ] ) );
			$action = 'confirm';
		} elseif ( isset( $_GET[ Consent::UNSUBSCRIBE_VAR ] ) ) {
			$token  = sanitize_text_field_compat( wp_unslash_compat( $_GET[ Consent::UNSUBSCRIBE_VAR ] ) );
			$action = 'unsubscribe';
		}
		// phpcs:enable

		if ( '' === $action ) {
			return;
		}

		$row = WaitlistRepository::get_by_token( $token );

		if ( null !== $row ) {
			$product = (int) $row['product_id'];
		}

		if ( 'confirm' === $action ) {
			$result  = WaitlistRepository::confirm_by_token( $token );
			$notice  = ( 'confirmed' === $result || 'already' === $result ) ? 'confirmed' : $result;
		} else {
			$result = WaitlistRepository::unsubscribe_by_token( $token );
			$notice = ( 'unsubscribed' === $result || 'already' === $result ) ? 'unsubscribed' : $result;
		}

		Logger::log( 'info', 'public_' . $action, (string) $notice, Consent::ip_hash() );

		$target = ( $product > 0 && function_exists( 'get_permalink' ) ) ? (string) get_permalink( $product ) : '';

		if ( '' === $target ) {
			$target = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
		}

		$target = add_query_arg_compat( 'mojoodyar_notice', $notice, $target );

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target, 302 );
			exit;
		}
	}

	/**
	 * Read the one-shot notice code from the URL.
	 *
	 * @return string
	 */
	private function read_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['mojoodyar_notice'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_GET['mojoodyar_notice'] ) ) : '';

		$allowed = array( 'confirmed', 'already', 'expired', 'invalid', 'unsubscribed' );

		return in_array( $notice, $allowed, true ) ? $notice : '';
	}
}

/**
 * wp_doing_ajax() exists since WP 4.7; the harness needs a fallback.
 *
 * @return bool
 */
function wp_doing_ajax_compat() {
	if ( function_exists( 'wp_doing_ajax' ) ) {
		return (bool) wp_doing_ajax();
	}

	return false;
}

/**
 * sanitize_text_field() fallback.
 *
 * @param mixed $value Value.
 * @return string
 */
function sanitize_text_field_compat( $value ) {
	if ( function_exists( 'sanitize_text_field' ) ) {
		return (string) sanitize_text_field( $value );
	}

	return trim( strip_tags( (string) $value ) );
}

/**
 * wp_unslash() fallback.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash_compat( $value ) {
	if ( function_exists( 'wp_unslash' ) ) {
		return wp_unslash( $value );
	}

	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash_compat', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}
