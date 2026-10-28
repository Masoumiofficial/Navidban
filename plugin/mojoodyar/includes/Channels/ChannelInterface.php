<?php
/**
 * Channel contract.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Interface ChannelInterface
 */
interface ChannelInterface {

	/**
	 * Machine name: email|sms:<gateway>.
	 *
	 * @return string
	 */
	public function name();

	/**
	 * Human label for the settings screen.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Are the credentials needed by this channel present?
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Send one message.
	 *
	 * @param string $to      Email address or canonical phone number.
	 * @param string $subject Subject (email only).
	 * @param string $body    Rendered message.
	 * @param array  $context Extra context (waitlist row).
	 * @return true|\WP_Error
	 */
	public function send( $to, $subject, $body, array $context = array() );
}
