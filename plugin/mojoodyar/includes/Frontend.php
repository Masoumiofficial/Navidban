<?php
/**
 * Storefront: conditional asset loading, the "notify me" form and the public
 * confirm/unsubscribe handlers.
 *
 * Assets are loaded on eligible product pages or when an eligible form is
 * explicitly placed with the shortcode. Other pages stay untouched.
 * See docs/PERFORMANCE.md for the measurement method and its limits.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

use Mojoodyar\Support\ProductAvailability;
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

	/** @var bool Whether the automatic form has been output in this request. */
	private $automatic_rendered = false;

	/** @var bool Whether a fallback summary position has been output. */
	private $summary_marked = false;

	/** @var bool Whether the storefront assets have already been queued. */
	private $assets_enqueued = false;

	/** @var array<int,bool> Products already rendered by an explicit shortcode. */
	private $shortcode_products = array();

	/** @var array<int,array> Cached unavailable variations, keyed by parent ID. */
	private $unavailable_variations = array();

	/** @var int Unique suffix for forms, including across multiple instances. */
	private static $form_sequence = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
		add_action( 'template_redirect', array( $this, 'handle_public_actions' ), 1 );
		add_filter( 'woocommerce_get_stock_html', array( $this, 'mark_stock_position' ), 20, 2 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'mark_summary_position' ), 35 );
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'mark_summary_position' ), 1 );
		// Render outside the purchase form and after normal page content. The
		// script moves it to a safe position, and an explicit shortcode wins.
		add_action( 'wp_footer', array( $this, 'render_form' ), 5 );
		add_shortcode( 'mojoodyar_form', array( $this, 'shortcode' ) );
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
		if ( ! $this->is_frontend_request() || ! function_exists( 'is_product' ) || ! is_product()
			|| ! function_exists( 'wc_get_product' ) || ! function_exists( 'get_queried_object_id' ) ) {
			return false;
		}

		$product_id = (int) get_queried_object_id();
		return $product_id > 0 && $this->product_is_eligible( wc_get_product( $product_id ) );
	}

	/**
	 * Do not render into feeds, administration or background requests.
	 *
	 * @return bool
	 */
	private function is_frontend_request() {
		if ( is_admin() || wp_doing_ajax_compat() || is_feed() || is_robots()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * A form is only useful for a public product or an unavailable variation.
	 *
	 * @param object|null $product Product.
	 * @return bool
	 */
	private function product_is_eligible( $product ) {
		if ( ! ProductAvailability::public_product( $product ) ) {
			return false;
		}

		if ( $product->is_type( 'variable' ) ) {
			return $this->has_out_of_stock_variation( $product );
		}

		return ! $product->is_in_stock();
	}

	/**
	 * Does a variable product have any active, published sold-out variation?
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function has_out_of_stock_variation( $product ) {
		if ( ! ProductAvailability::public_product( $product ) || ! $product->is_type( 'variable' )
			|| ! method_exists( $product, 'get_children' ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$children = (array) $product->get_children();
		// Prime post/meta caches in bulk, as WooCommerce itself does when it
		// builds its variations. There is deliberately no 100-item cutoff:
		// an unavailable variation after that boundary must not be missed.
		if ( $children && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $children, false, true );
		}

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( (int) $child_id );
			if ( ProductAvailability::unavailable_variation( $product, $variation ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collect eligible choices for a standalone waitlist selector. This honors
	 * WooCommerce's variation visibility and activity filters, but can list an
	 * out-of-stock choice even when the purchase selector hides it.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array
	 */
	private function unavailable_variations_for( $product ) {
		$product_id = (int) $product->get_id();
		if ( array_key_exists( $product_id, $this->unavailable_variations ) ) {
			return $this->unavailable_variations[ $product_id ];
		}

		$choices  = array();
		$children = (array) $product->get_children();
		if ( $children && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $children, false, true );
		}

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( (int) $child_id );
			if ( ! ProductAvailability::unavailable_variation( $product, $variation ) ) {
				continue;
			}

			$label = function_exists( 'wc_get_formatted_variation' )
				? (string) wc_get_formatted_variation( $variation, true, true, false )
				: '';
			$label = trim( strip_tags( $label ) );
			if ( '' === $label && method_exists( $variation, 'get_name' ) ) {
				$label = trim( (string) $variation->get_name() );
			}
			$choices[] = array(
				'id'    => (int) $child_id,
				'label' => \Mojoodyar\Support\Persian::normalize( '' !== $label ? $label : ( 'تنوع #' . (int) $child_id ) ),
			);
		}

		$this->unavailable_variations[ $product_id ] = $choices;
		return $choices;
	}

	/**
	 * Enqueue assets, but only when they are needed.
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		if ( $this->should_load() ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Also used by shortcodes rendered after wp_enqueue_scripts. WordPress
	 * prints late styles and footer scripts at wp_footer on standard themes.
	 *
	 * @return void
	 */
	private function enqueue_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}
		$this->assets_enqueued = true;
		// The marketplace version remains 1.0.0; this revision invalidates a
		// browser's cached pre-shortcode storefront CSS/JS after replacement.
		$asset_version = MOJODYAR_VERSION . '-form1';

		wp_enqueue_style(
			'mojoodyar-front',
			MOJODYAR_URL . 'assets/css/mojoodyar-front.css',
			array(),
			$asset_version
		);

		wp_enqueue_script(
			'mojoodyar-front',
			MOJODYAR_URL . 'assets/js/mojoodyar-front.js',
			array(),
			$asset_version,
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
					'variation'   => 'لطفاً تنوع ناموجود مورد نظر را انتخاب کنید.',
					'consent'     => 'برای ادامه باید رضایت خود را تأیید کنید.',
					'network'     => 'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.',
					'success'     => (string) Settings::get( 'success_message', '' ),
					'confirmSent' => 'پیام تأیید در صف ارسال قرار گرفت. پس از دریافت، برای تکمیل ثبت پیوند داخل آن را باز کنید.',
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
	 * Mark a stock position, never insert a form into WooCommerce's HTML. In
	 * particular, variation stock HTML is also embedded in JSON and may later
	 * be displayed inside the purchase form.
	 *
	 * @param string      $html    Original stock markup.
	 * @param \WC_Product $product Product whose stock is being rendered.
	 * @return string
	 */
	public function mark_stock_position( $html, $product ) {
		if ( ! is_string( $html ) || '' === trim( $html ) || ! $this->is_frontend_request()
			|| ! function_exists( 'is_product' ) || ! is_product()
			|| ! function_exists( 'doing_action' ) || ! doing_action( 'woocommerce_single_product_summary' )
			|| ! is_object( $product ) || ! method_exists( $product, 'get_id' )
			|| ! method_exists( $product, 'is_in_stock' ) || $product->is_in_stock()
			|| (int) $product->get_id() !== (int) get_queried_object_id() || ! $this->should_load() ) {
			return $html;
		}

		return $html . '<span class="mjy-stock-anchor" data-mjy-product="' . esc_attr( (string) $product->get_id() ) . '" hidden></span>';
	}

	/**
	 * Place a fallback marker if the theme runs either summary hook. The form
	 * itself is printed in the footer, where it cannot nest in the cart form.
	 *
	 * @return void
	 */
	public function mark_summary_position() {
		if ( $this->summary_marked || ! $this->should_load() ) {
			return;
		}

		$this->summary_marked = true;
		echo '<span class="mjy-summary-anchor" data-mjy-product="' . esc_attr( (string) get_queried_object_id() ) . '" hidden></span>';
	}

	/**
	 * The shortcode uses the queried product on its own page, or a positive
	 * product_id (id is an alias) on another page.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts = array() ) {
		if ( ! $this->is_frontend_request() || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$atts   = is_array( $atts ) ? $atts : array();
		$has_id = array_key_exists( 'product_id', $atts ) || array_key_exists( 'id', $atts );
		$raw_id = isset( $atts['product_id'] ) ? $atts['product_id'] : ( $atts['id'] ?? '' );
		if ( isset( $atts['product_id'], $atts['id'] )
			&& ( ! is_scalar( $atts['product_id'] ) || ! is_scalar( $atts['id'] ) || (string) $atts['product_id'] !== (string) $atts['id'] ) ) {
			return '';
		}

		if ( $has_id ) {
			if ( ! is_scalar( $raw_id ) ) {
				return '';
			}
			$raw_id = trim( (string) $raw_id );
			if ( ! preg_match( '/^[1-9][0-9]*$/', $raw_id ) || (string) (int) $raw_id !== $raw_id ) {
				return '';
			}
			$product_id = (int) $raw_id;
		} else {
			$product_id = function_exists( 'is_product' ) && is_product() ? (int) get_queried_object_id() : 0;
		}

		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		$html    = $this->render_product_form( $product, 'shortcode' );
		if ( '' !== $html ) {
			$this->shortcode_products[ $product_id ] = true;
		}

		return $html;
	}

	/**
	 * Print one automatic form for the main product, unless an explicit form
	 * for that product was already rendered. The script also deduplicates a
	 * shortcode produced later in the same footer.
	 *
	 * @return void
	 */
	public function render_form() {
		if ( $this->automatic_rendered || ! $this->should_load() ) {
			return;
		}

		$product_id = (int) get_queried_object_id();
		if ( isset( $this->shortcode_products[ $product_id ] ) ) {
			return;
		}

		$html = $this->render_product_form( wc_get_product( $product_id ), 'automatic' );
		if ( '' !== $html ) {
			$this->automatic_rendered = true;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the view escapes its fields.
		}
	}

	/**
	 * Build a form for one validated product. Multiple shortcode instances are
	 * intentional and get separate element IDs and variation state.
	 *
	 * @param object|null $product   Product.
	 * @param string      $placement automatic|shortcode.
	 * @return string
	 */
	private function render_product_form( $product, $placement ) {
		if ( ! $this->product_is_eligible( $product ) ) {
			return '';
		}

		$view = MOJODYAR_DIR . 'admin/views/frontend-form.php';
		if ( ! is_readable( $view ) ) {
			return '';
		}

		$product_id  = (int) $product->get_id();
		$is_variable = $product->is_type( 'variable' );
		$variations  = $is_variable ? $this->unavailable_variations_for( $product ) : array();
		if ( $is_variable && empty( $variations ) ) {
			return '';
		}

		$this->enqueue_assets();
		$form_id           = 'mjy-form-' . ++self::$form_sequence;
		$sync_with_product = function_exists( 'is_product' ) && is_product() && $product_id === (int) get_queried_object_id();
		$default_channel   = $this->default_channel();
		$user_id           = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

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

		ob_start();
		include $view;
		return (string) ob_get_clean();
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
