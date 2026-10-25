<?php
/**
 * Activation routine.
 *
 * @package Navidban
 */

namespace Navidban;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activator
 */
final class Activator {

	/**
	 * Runs once per site on activation.
	 *
	 * @param bool $network_wide Whether the plugin is activated network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			$sites = function_exists( 'get_sites' ) ? get_sites( array( 'fields' => 'ids' ) ) : array();
			foreach ( $sites as $site_id ) {
				if ( function_exists( 'switch_to_blog' ) ) {
					switch_to_blog( (int) $site_id );
					self::activate_single();
					restore_current_blog();
				}
			}

			return;
		}

		self::activate_single();
	}

	/**
	 * Activate on the current site.
	 *
	 * @return void
	 */
	private static function activate_single() {
		Database::install();
		self::seed_options();
		Capabilities::grant();
		Scheduler::schedule_all();

		// The first reconcile run must only take a snapshot. We flag it so the
		// watcher never announces a "restock" for products that were already
		// in stock before installation.
		if ( function_exists( 'get_option' ) && false === get_option( 'navidban_snapshot_seeded', false ) ) {
			update_option( 'navidban_snapshot_seeded', 'no', false );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( 'navidban_version', NAVIDBAN_VERSION, false );
		}

		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'navidban_activated', 1, 60 );
		}
	}

	/**
	 * Write default options without overwriting existing user values.
	 *
	 * @return void
	 */
	private static function seed_options() {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		$stored = get_option( Settings::OPTION, null );

		if ( ! is_array( $stored ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
			return;
		}

		$merged = array_merge( Settings::defaults(), $stored );
		update_option( Settings::OPTION, $merged, false );
	}

	/**
	 * Upgrade routine executed on every load when the stored version differs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		$stored_version = get_option( 'navidban_version', '' );
		$stored_db      = get_option( 'navidban_db_version', '' );

		if ( NAVIDBAN_VERSION === $stored_version && (string) NAVIDBAN_DB_VERSION === (string) $stored_db ) {
			return;
		}

		Database::install();
		Capabilities::grant();
		Scheduler::schedule_all();
		update_option( 'navidban_version', NAVIDBAN_VERSION, false );
	}
}
