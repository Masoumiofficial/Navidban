<?php
/**
 * Minimal structured logger. Every entry is a row in nvb_logs, capped by the
 * retention policy so the table cannot grow without bound.
 *
 * We never log raw phone numbers, emails or IP addresses: only hashes and
 * event keys. A support engineer can still correlate events without the log
 * becoming a personal-data store.
 *
 * @package Navidban
 */

namespace Navidban;

use Navidban\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
final class Logger {

	/**
	 * Write a log entry.
	 *
	 * @param string $level   info|warning|error|security.
	 * @param string $event   Short machine key.
	 * @param string $context Extra context (never personal data).
	 * @param string $ip_hash Hashed IP when relevant.
	 * @return void
	 */
	public static function log( $level, $event, $context = '', $ip_hash = '' ) {
		global $wpdb;

		$level = in_array( $level, array( 'info', 'warning', 'error', 'security' ), true ) ? $level : 'info';

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Allow site owners to drop log entries, e.g. to reduce writes.
			 *
			 * @param bool   $keep    Whether to write the entry.
			 * @param string $event   Event key.
			 * @param string $level   Level.
			 * @param string $context Context.
			 */
			if ( ! apply_filters( 'navidban_should_log', true, $event, $level, $context ) ) {
				return;
			}
		}

		$table = Database::table( Database::LOGS );

		$wpdb->insert(
			$table,
			array(
				'level'      => $level,
				'event'      => substr( (string) $event, 0, 64 ),
				'context'    => Text::substring( (string) $context, 0, 255 ),
				'ip_hash'    => substr( (string) $ip_hash, 0, 64 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( Settings::enabled( 'debug_log' ) && function_exists( 'error_log' ) ) {
			error_log( sprintf( '[navidban] %s %s %s', $level, $event, $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * Count failed subscribe attempts for one IP hash inside a window.
	 *
	 * @param string $ip_hash Hash.
	 * @param int    $minutes Window length in minutes.
	 * @return int
	 */
	public static function count_recent( $ip_hash, $minutes = 60 ) {
		global $wpdb;

		if ( '' === (string) $ip_hash ) {
			return 0;
		}

		$table = Database::table( Database::LOGS );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $minutes ) * 60 ) );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND created_at >= %s",
				$ip_hash,
				$since
			)
		);

		return (int) $count;
	}

	/**
	 * Delete entries older than N days.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public static function prune( $days = 90 ) {
		global $wpdb;

		$table = Database::table( Database::LOGS );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * 86400 ) );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $since ) );
	}
}
