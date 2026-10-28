<?php
/**
 * One-way migration from the pre-2.0 brand (Navidban) to Mojoodyar.
 *
 * Why this file exists: the product shipped as "Navidban" up to version 1.0.10,
 * so a site that upgrades carries four tables named wp_nvb_*, one option named
 * navidban_settings and a handful of cron hooks with the old prefix. Renaming
 * the code without moving that data would look exactly like data loss.
 *
 * Rules this routine follows:
 *  - it never drops anything: tables are RENAMEd, options are copied only when
 *    the new key does not exist yet;
 *  - it is safe to run twice (every step is guarded);
 *  - if both the old and the new tables exist (a half-finished manual rename),
 *    it does nothing and leaves a notice instead of guessing which side is newer.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

defined( 'ABSPATH' ) || exit;

/**
 * Class Migration
 */
final class Migration {

	/**
	 * Legacy table names, without prefix.
	 *
	 * @var string[]
	 */
	const LEGACY_TABLES = array(
		'nvb_waitlists'         => Database::WAITLISTS,
		'nvb_stock_snapshots'   => Database::SNAPSHOTS,
		'nvb_queue'             => Database::QUEUE,
		'nvb_logs'              => Database::LOGS,
	);

	/**
	 * Legacy option name => new option name.
	 *
	 * @var string[]
	 */
	const LEGACY_OPTIONS = array(
		'navidban_settings'        => 'mojoodyar_settings',
		'navidban_version'         => 'mojoodyar_version',
		'navidban_db_version'      => 'mojoodyar_db_version',
		'navidban_snapshot_seeded' => 'mojoodyar_snapshot_seeded',
		'navidban_last_reconcile'  => 'mojoodyar_last_reconcile',
		'navidban_queue_lock'      => 'mojoodyar_queue_lock',
	);

	/**
	 * Legacy cron hooks that must not fire on the new code.
	 *
	 * @var string[]
	 */
	const LEGACY_HOOKS = array(
		'navidban_process_queue',
		'navidban_reconcile_stock',
		'navidban_prune_records',
	);

	/**
	 * Run every legacy step. Returns a short report for the tools screen.
	 *
	 * @return array{tables:string[],options:string[],conflict:bool}
	 */
	public static function from_legacy_brand() {
		$report = array(
			'tables'   => self::rename_tables(),
			'options'  => self::copy_options(),
			'conflict' => false,
		);

		$report['conflict'] = in_array( 'conflict', $report['tables'], true );

		self::clear_legacy_hooks();

		return $report;
	}

	/**
	 * RENAME the four legacy tables onto the new names.
	 *
	 * @return string[] Human readable outcome per table: "moved", "conflict",
	 *                  "missing" or "done" (already migrated).
	 */
	private static function rename_tables() {
		global $wpdb;

		$result = array();

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return $result;
		}

		foreach ( self::LEGACY_TABLES as $legacy => $current ) {
			$old_name = $wpdb->prefix . $legacy;
			$new_name = Database::table( $current );

			$has_old = self::table_exists( $old_name );
			$has_new = self::table_exists( $new_name );

			if ( ! $has_old ) {
				$result[ $legacy ] = 'missing';
				continue;
			}

			if ( $has_new ) {
				// Both exist. Deciding which rows win would be a guess, and a
				// wrong guess destroys waitlist entries, so we stop here.
				$result[ $legacy ] = 'conflict';
				continue;
			}

			// Table names are built from $wpdb->prefix plus a class constant;
			// no user input reaches this statement.
			$sql = 'RENAME TABLE ' . $old_name . ' TO ' . $new_name;

			$done = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$result[ $legacy ] = ( false === $done ) ? 'failed' : 'moved';
		}

		return $result;
	}

	/**
	 * Copy legacy option values when the new key is still empty.
	 *
	 * @return string[] Old option name => "copied" or "kept".
	 */
	private static function copy_options() {
		$result = array();

		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return $result;
		}

		foreach ( self::LEGACY_OPTIONS as $legacy => $current ) {
			$old_value = get_option( $legacy, null );

			if ( null === $old_value ) {
				continue;
			}

			$new_value = get_option( $current, null );

			if ( null !== $new_value && '' !== $new_value && array() !== $new_value ) {
				$result[ $legacy ] = 'kept';
				continue;
			}

			// autoload stays off for settings: the storefront must not pay for
			// an option read on pages that do not show the form.
			update_option( $current, $old_value, false );
			$result[ $legacy ] = 'copied';
		}

		return $result;
	}

	/**
	 * Remove the old cron events. The new ones are scheduled by Scheduler.
	 *
	 * @return void
	 */
	private static function clear_legacy_hooks() {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		foreach ( self::LEGACY_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Does this table exist?
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return ! empty( $found );
	}
}
