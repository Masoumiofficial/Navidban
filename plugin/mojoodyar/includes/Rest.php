<?php
/**
 * REST API (namespace mojoodyar/v1).
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rest
 */
final class Rest {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'mojoodyar/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE_V1,
			'/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => array( $this, 'can_subscribe' ),
				'args'                => array(
					'product_id'   => array( 'required' => true ),
					'variation_id' => array( 'required' => false ),
					'channel'      => array( 'required' => false ),
					'email'        => array( 'required' => false ),
					'phone'        => array( 'required' => false ),
					'consent'      => array( 'required' => false ),
				),
			)
		);
	}

	/**
	 * Permission callback: a valid REST nonce plus a same-origin referer.
	 *
	 * Guests are allowed (they are the majority of waitlist subscribers), but a
	 * nonce is still required so a third-party page cannot post on their behalf.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_subscribe( $request ) {
		$nonce = '';

		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		}

		if ( '' === $nonce && isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = sanitize_text_field_compat( wp_unslash_compat( $_SERVER['HTTP_X_WP_NONCE'] ) );
		}

		if ( '' === $nonce || ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			if ( class_exists( '\WP_Error' ) ) {
				return new \WP_Error( 'mojoodyar_nonce', 'نشست شما منقضی شده است؛ صفحه را دوباره بارگذاری کنید.', array( 'status' => 403 ) );
			}

			return false;
		}

		return true;
	}

	/**
	 * POST /subscribe
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function subscribe( $request ) {
		$params = array();
		if ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
			$json   = $request->get_json_params();
			$params = is_array( $json ) ? $json : (array) $request->get_params();
		}

		$result = Subscribe::handle( $params );

		if ( function_exists( 'rest_ensure_response' ) ) {
			$response = rest_ensure_response(
				array(
					'ok'      => $result['ok'],
					'code'    => $result['code'],
					'message' => $result['message'],
					'data'    => $result['data'],
				)
			);

			if ( is_object( $response ) && method_exists( $response, 'set_status' ) ) {
				$response->set_status( (int) $result['status'] );
			}

			return $response;
		}

		return $result;
	}
}
