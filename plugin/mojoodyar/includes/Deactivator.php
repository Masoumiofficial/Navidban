<?php
/**
 * Deactivation routine. Data is always preserved here; deletion happens only in
 * uninstall.php and only when the site owner opted in.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deactivator
 */
final class Deactivator {

	/**
	 * Runs on deactivation.
	 *
	 * @param bool $network_wide Network-wide deactivation.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			$sites = function_exists( 'get_sites' ) ? get_sites( array( 'fields' => 'ids' ) ) : array();
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				Scheduler::unschedule_all();
				restore_current_blog();
			}

			return;
		}

		Scheduler::unschedule_all();
	}
}
