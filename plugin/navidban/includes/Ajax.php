<?php
/**
 * admin-ajax.php fallback for the storefront form.
 *
 * Some sites (security plugins, aggressive caching) strip or block REST calls
 * for logged-out visitors. The form falls back to this endpoint, which applies
 * the same rules through the same use-case class.
 *
 * @package Navidban
 */

namespace Navidban;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax
 */
final class Ajax {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	const ACTION = 'navidban_subscribe';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'subscribe' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'subscribe' ) );
	}

	/**
	 * Handle the AJAX request.
	 *
	 * @return void
	 */
	public function subscribe() {
		if ( ! function_exists( 'check_ajax_referer' ) || ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_compat(
				array(
					'ok'      => false,
					'code'    => 'bad_nonce',
					'message' => 'نشست شما منقضی شده است؛ صفحه را دوباره بارگذاری کنید.',
				),
				403
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$input = array(
			'product_id'   => isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0,
			'variation_id' => isset( $_POST['variation_id'] ) ? (int) $_POST['variation_id'] : 0,
			'channel'      => isset( $_POST['channel'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['channel'] ) ) : 'email',
			'email'        => isset( $_POST['email'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['email'] ) ) : '',
			'phone'        => isset( $_POST['phone'] ) ? sanitize_text_field_compat( wp_unslash_compat( $_POST['phone'] ) ) : '',
			'consent'      => isset( $_POST['consent'] ) ? (int) $_POST['consent'] : 0,
		);
		// phpcs:enable

		$result = Subscribe::handle( $input );

		wp_send_json_compat(
			array(
				'ok'      => $result['ok'],
				'code'    => $result['code'],
				'message' => $result['message'],
				'data'    => $result['data'],
			),
			(int) $result['status']
		);
	}
}

/**
 * wp_send_json() fallback for the harness.
 *
 * @param mixed $data   Payload.
 * @param int   $status HTTP status.
 * @return void
 */
function wp_send_json_compat( $data, $status = 200 ) {
	if ( function_exists( 'wp_send_json' ) ) {
		wp_send_json( $data, $status );
		return;
	}

	if ( function_exists( 'status_header' ) ) {
		status_header( $status );
	}

	echo json_encode( $data, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
