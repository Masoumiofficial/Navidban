<?php
/**
 * Capability mapping.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

defined( 'ABSPATH' ) || exit;

/**
 * Class Capabilities
 */
final class Capabilities {

	/**
	 * Full management capability.
	 *
	 * @var string
	 */
	const MANAGE = 'mojoodyar_manage';

	/**
	 * Reports-only capability.
	 *
	 * @var string
	 */
	const VIEW_REPORTS = 'mojoodyar_view_reports';

	/**
	 * Grant capabilities to the default roles.
	 *
	 * @return void
	 */
	public static function grant() {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::VIEW_REPORTS );
		}

		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( self::MANAGE );
			$shop_manager->add_cap( self::VIEW_REPORTS );
		}
	}

	/**
	 * Remove capabilities from every role (used on uninstall).
	 *
	 * @return void
	 */
	public static function revoke() {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}

		$roles = wp_roles();
		if ( ! $roles || empty( $roles->roles ) ) {
			return;
		}

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::VIEW_REPORTS );
		}
	}

	/**
	 * Permission check used by every admin operation.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return function_exists( 'current_user_can' ) && current_user_can( self::MANAGE );
	}

	/**
	 * Permission check for read-only screens.
	 *
	 * @return bool
	 */
	public static function can_view() {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( self::MANAGE ) || current_user_can( self::VIEW_REPORTS );
	}
}
